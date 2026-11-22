<?php
/**
 * admin/importar_personal.php?tipo=maestros|prefectos|admin — Importación masiva del personal.
 *
 * El personal no tiene matrícula: inicia sesión con un USUARIO. El usuario se toma, en este orden, de la
 * columna "Usuario", "Número de empleado" o "RFC" del archivo; si no viene, el sistema lo genera
 * (DOC001, PREF001, ADM001…). Cada persona recibe una contraseña provisional única (vence en
 * IMPORTACION_DIAS días) y las fichas se descargan desde el Directorio, igual que con los alumnos.
 *
 * Flujo: subir Excel/CSV → vista previa (nuevos / ya registrados / con problema) → confirmar.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/leer_tabla.php';
require_once __DIR__ . '/../includes/credenciales_lote.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

const PERSONAL_MAX_MB = 5;
$TIPOS = [
    'maestros'  => ['tabla' => 'MAESTROS',        'id' => 'id_maestro',  'plural' => 'Docentes',        'singular' => 'Docente',        'prefijo' => 'DOC',  'icono' => 'fa-chalkboard-user'],
    'prefectos' => ['tabla' => 'PREFECTOS',       'id' => 'id_prefecto', 'plural' => 'Prefectos',       'singular' => 'Prefecto',       'prefijo' => 'PREF', 'icono' => 'fa-shield-halved'],
    'admin'     => ['tabla' => 'ADMINISTRATIVOS', 'id' => 'id_admin',    'plural' => 'Administrativos', 'singular' => 'Administrativo', 'prefijo' => 'ADM',  'icono' => 'fa-briefcase'],
];
$tipo = $_GET['tipo'] ?? ($_POST['tipo'] ?? 'maestros');
if (!isset($TIPOS[$tipo])) $tipo = 'maestros';
$T = $TIPOS[$tipo];
$clave_prev = 'personal_preview_' . $tipo;

function personal_volver(string $tipo, string $clave = '', string $msg = ''): void {
    header("Location: importar_personal.php?tipo=$tipo" . ($clave ? "&$clave=" . urlencode($msg) : ''));
    exit();
}

/** ¿El usuario ya existe en cualquier tipo de cuenta? (los alumnos entran con su matrícula) */
function usuario_ocupado(PDO $pdo, string $u): bool {
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM ALUMNOS WHERE matricula = ?) + (SELECT COUNT(*) FROM MAESTROS WHERE usuario = ?)
                              + (SELECT COUNT(*) FROM ADMINISTRATIVOS WHERE usuario = ?) + (SELECT COUNT(*) FROM PREFECTOS WHERE usuario = ?)");
    $st->execute([$u, $u, $u, $u]);
    return (int)$st->fetchColumn() > 0;
}

/** Separa "PEREZ DE LA CRUZ ANA MARIA" (apellidos primero) o "ANA MARIA PEREZ DE LA CRUZ" (nombre primero). */
function separar_nombre(string $completo, string $orden): array {
    $s = preg_replace('/\s+/', ' ', mb_strtoupper(trim($completo), 'UTF-8'));
    $s = preg_replace_callback('/\b(DE LA|DE LOS|DE LAS|DEL|DE|SAN|SANTA|VAN|VON|MC|LA)\s+/u', fn($m) => str_replace(' ', '_', $m[1]) . '_', $s);
    $p = array_values(array_filter(explode(' ', $s), 'strlen'));
    $limpia = fn($x) => str_replace('_', ' ', (string)$x);
    if (count($p) === 1) return [$limpia($p[0]), '', ''];
    if ($orden === 'nombre') {
        if (count($p) === 2) return [$limpia($p[0]), $limpia($p[1]), ''];
        $am = array_pop($p); $ap = array_pop($p);
        return [$limpia(implode(' ', $p)), $limpia($ap), $limpia($am)];
    }
    if (count($p) === 2) return [$limpia($p[1]), $limpia($p[0]), ''];
    return [$limpia(implode(' ', array_slice($p, 2))), $limpia($p[0]), $limpia($p[1])];
}

/** "MARÍA JOSÉ" → "María José" */
function nombre_propio(string $s): string {
    return mb_convert_case(mb_strtolower(trim($s), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

// ---------- Plantilla ----------
if (isset($_GET['plantilla'])) {
    require_once __DIR__ . '/../includes/xlsx_simple.php';
    $enc = ['Apellido paterno', 'Apellido materno', 'Nombre(s)', 'Número de empleado', 'Teléfono', 'Correo'];
    $ej  = ['López', 'García', 'María Fernanda', '', '2221234567', 'maria.lopez@ejemplo.com'];
    if ($tipo === 'maestros') { $enc[] = 'Título académico'; $ej[] = 'Lic. en Matemáticas'; }
    xlsx_descargar('Plantilla_' . $T['plural'] . '.xlsx', $T['plural'],
        ['COBAEP Plantel 27 · Importar ' . mb_strtolower($T['plural']), 'Si "Número de empleado" queda vacío, el sistema genera el usuario (' . $T['prefijo'] . '001, ' . $T['prefijo'] . '002…). Borra esta fila de ejemplo.'],
        $enc, [$ej], [20, 20, 24, 20, 16, 32, 26]);
}

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'cancelar') {
        unset($_SESSION[$clave_prev]);
        personal_volver($tipo);
    }

    if ($accion === 'analizar') {
        if (empty($_POST) || !isset($_FILES['archivo'])) personal_volver($tipo, 'error', 'El archivo es demasiado grande (máx. ' . PERSONAL_MAX_MB . ' MB).');
        $file = $_FILES['archivo'];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) personal_volver($tipo, 'error', 'Selecciona un archivo de Excel o CSV.');
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > PERSONAL_MAX_MB * 1048576) personal_volver($tipo, 'error', 'No se pudo subir el archivo (máx. ' . PERSONAL_MAX_MB . ' MB).');
        $orden = ($_POST['orden'] ?? '') === 'nombre' ? 'nombre' : 'apellidos';
        try {
            $tabla = leer_tabla($file['tmp_name'], $file['name']);
        } catch (RuntimeException $e) {
            personal_volver($tipo, 'error', $e->getMessage());
        }

        // Encabezados (en las primeras 15 filas)
        $fila_enc = -1; $col = [];
        foreach (array_slice($tabla, 0, 15, true) as $i => $fila) {
            $c = ['usuario' => -1, 'empleado' => -1, 'rfc' => -1, 'paterno' => -1, 'materno' => -1, 'nombres' => -1, 'completo' => -1,
                  'telefono' => -1, 'correo' => -1, 'titulo' => -1, 'pwd' => -1];
            foreach ($fila as $j => $v) {
                $hd = norm_texto($v);
                if ($hd === '') continue;
                $poner = function (string $k) use (&$c, $j) { if ($c[$k] < 0) $c[$k] = $j; };
                if (preg_match('/(CONTRASE|PASSWORD|CLAVE DE ACCESO)/', $hd)) $poner('pwd');
                elseif (preg_match('/^(USUARIO|LOGIN|USER)\b/', $hd)) $poner('usuario');
                elseif (preg_match('/^RFC\b/', $hd)) $poner('rfc');
                elseif (preg_match('/(EMPLEADO|NOMINA|^CLAVE\b)/', $hd) && !preg_match('/(NOMBRE|TELEFONO|CELULAR)/', $hd)) $poner('empleado');
                elseif (preg_match('/(APELLIDO PATERNO|PRIMER APELLIDO|^PATERNO$|^AP PATERNO)/', $hd)) $poner('paterno');
                elseif (preg_match('/(APELLIDO MATERNO|SEGUNDO APELLIDO|^MATERNO$|^AP MATERNO)/', $hd)) $poner('materno');
                elseif (preg_match('/^(NOMBRE|NOMBRES|NOMBRE S)$/', $hd)) $poner('nombres');
                elseif (preg_match('/(NOMBRE|DOCENTE|MAESTRO|PREFECTO|PERSONAL|TRABAJADOR|EMPLEADO)/', $hd)) $poner('completo');
                elseif (preg_match('/(TELEFONO|CELULAR|^TEL\b|MOVIL)/', $hd)) $poner('telefono');
                elseif (preg_match('/(CORREO|EMAIL|E MAIL|\bMAIL\b)/', $hd)) $poner('correo');
                elseif (preg_match('/(TITULO|GRADO ACADEMICO|^GRADO\b|PROFESION|ESPECIALIDAD)/', $hd)) $poner('titulo');
            }
            // Sin apellidos separados, la columna "Nombre" se toma como nombre completo
            if ($c['nombres'] >= 0 && $c['paterno'] < 0 && $c['completo'] < 0) { $c['completo'] = $c['nombres']; $c['nombres'] = -1; }
            $tiene_nombre = ($c['paterno'] >= 0 && $c['nombres'] >= 0) || $c['completo'] >= 0;
            $reconocidas = count(array_filter($c, fn($x) => $x >= 0));
            // Un título como "LISTA DE DOCENTES" reconoce una sola columna: el encabezado real tiene al menos dos
            if ($tiene_nombre && $reconocidas >= 2) { $fila_enc = $i; $col = $c; break; }
            if ($tiene_nombre && !isset($respaldo)) $respaldo = [$i, $c];
        }
        if ($fila_enc < 0 && isset($respaldo)) [$fila_enc, $col] = $respaldo;
        if ($fila_enc < 0) {
            personal_volver($tipo, 'error', 'No se encontraron los encabezados. El archivo debe tener "Nombre completo", o bien "Apellido paterno" y "Nombre(s)". Descarga la plantilla para ver el formato.');
        }

        // Personas ya registradas en esta categoría (por nombre)
        $existentes = [];
        foreach ($pdo->query("SELECT {$T['id']} AS id, usuario, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS n FROM {$T['tabla']}")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $existentes[llave_nombre($e['n'])] = $e['usuario'];
        }

        $val = fn($fila, $k) => $col[$k] >= 0 ? trim((string)($fila[$col[$k]] ?? '')) : '';
        $filas = []; $usuarios_archivo = []; $nombres_archivo = [];
        foreach ($tabla as $i => $fila) {
            if ($i <= $fila_enc) continue;
            if ($col['paterno'] >= 0) {
                $nom = $val($fila, 'nombres'); $ap = $val($fila, 'paterno'); $am = $val($fila, 'materno');
            } else {
                [$nom, $ap, $am] = separar_nombre($val($fila, 'completo'), $orden);
            }
            if ($nom === '' && $ap === '') continue;
            $nom = nombre_propio($nom); $ap = nombre_propio($ap); $am = nombre_propio($am);

            $tel_raw = $val($fila, 'telefono');
            $tel = preg_replace('/\D/', '', $tel_raw);
            if (strlen($tel) === 12 && str_starts_with($tel, '52')) $tel = substr($tel, 2);
            $correo = mb_strtolower($val($fila, 'correo'));
            $avisos = [];
            if ($tel !== '' && strlen($tel) !== 10) { $avisos[] = 'teléfono omitido (no tiene 10 dígitos)'; $tel = ''; }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) { $avisos[] = 'correo omitido (no es válido)'; $correo = ''; }

            // Usuario de acceso: Usuario > Núm. de empleado > RFC > (se genera al guardar)
            $usuario = ''; $origen = '';
            foreach (['usuario' => 'Usuario', 'empleado' => 'Núm. de empleado', 'rfc' => 'RFC'] as $k => $txt) {
                $v = preg_replace('/[^A-Za-z0-9._-]/', '', $val($fila, $k));
                if ($v !== '') { $usuario = $k === 'rfc' ? mb_strtoupper($v) : $v; $origen = $txt; break; }
            }

            $r = ['fila' => $i + 1, 'nombre' => $nom, 'ap' => $ap, 'am' => $am, 'telefono' => $tel, 'correo' => $correo,
                  'titulo' => mb_substr($val($fila, 'titulo'), 0, 100), 'usuario' => $usuario, 'origen' => $origen,
                  'estado' => 'nuevo', 'motivo' => implode('; ', $avisos)];
            $llave = llave_nombre("$nom $ap $am");

            if ($nom === '' || $ap === '') { $r['estado'] = 'error'; $r['motivo'] = 'Falta el nombre o el apellido paterno.'; }
            elseif (isset($existentes[$llave])) { $r['estado'] = 'existe'; $r['motivo'] = 'Ya está registrado (usuario ' . $existentes[$llave] . ').'; }
            elseif (isset($nombres_archivo[$llave])) { $r['estado'] = 'error'; $r['motivo'] = 'Persona repetida en el archivo (fila ' . $nombres_archivo[$llave] . ').'; }
            elseif ($usuario !== '' && (strlen($usuario) < 3 || strlen($usuario) > 60)) { $r['estado'] = 'error'; $r['motivo'] = 'El usuario debe tener de 3 a 60 caracteres.'; }
            elseif ($usuario !== '' && isset($usuarios_archivo[mb_strtolower($usuario)])) { $r['estado'] = 'error'; $r['motivo'] = "El usuario $usuario está repetido en el archivo."; }
            elseif ($usuario !== '' && usuario_ocupado($pdo, $usuario)) { $r['estado'] = 'error'; $r['motivo'] = "El usuario $usuario ya pertenece a otra cuenta."; }

            if ($r['estado'] === 'nuevo') {
                $nombres_archivo[$llave] = $r['fila'];
                if ($usuario !== '') $usuarios_archivo[mb_strtolower($usuario)] = true;
            }
            $filas[] = $r;
        }
        if (!$filas) personal_volver($tipo, 'error', 'El archivo no tiene filas con datos debajo de los encabezados.');
        $_SESSION[$clave_prev] = ['archivo' => $file['name'], 'filas' => $filas, 'ignoro_pwd' => $col['pwd'] >= 0];
        personal_volver($tipo);
    }

    if ($accion === 'aplicar') {
        $prev = $_SESSION[$clave_prev] ?? null;
        if (!$prev) personal_volver($tipo, 'error', 'La vista previa expiró. Vuelve a subir el archivo.');
        $nuevos = array_values(array_filter($prev['filas'], fn($f) => $f['estado'] === 'nuevo'));
        if (!$nuevos) personal_volver($tipo, 'error', 'No hay registros nuevos para guardar.');
        @set_time_limit(0);

        // Siguiente consecutivo libre para los usuarios generados (DOC001, DOC002…)
        $pref = $T['prefijo'];
        $st = $pdo->prepare("SELECT usuario FROM {$T['tabla']} WHERE usuario REGEXP ?");
        $st->execute(['^' . $pref . '[0-9]+$']);
        $consec = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $consec = max($consec, (int)substr($u, strlen($pref)));

        $cols = "nombre, apellido_paterno, apellido_materno, telefono, correo, usuario, contrasena_hash, debe_cambiar_password, password_provisional_expira";
        $vals = "?, ?, ?, ?, ?, ?, ?, 1, NOW() + INTERVAL " . IMPORTACION_DIAS . " DAY";
        if ($tipo !== 'admin') { $cols .= ', id_admin_registro'; $vals .= ', ?'; }
        if ($tipo === 'maestros') { $cols .= ', titulo_academico'; $vals .= ', ?'; }
        $ins = $pdo->prepare("INSERT INTO {$T['tabla']} ($cols) VALUES ($vals)");

        $credenciales = []; $omitidos = 0;
        try {
            $pdo->beginTransaction();
            foreach ($nuevos as $f) {
                $usuario = $f['usuario'];
                if ($usuario === '') {
                    do { $usuario = $pref . str_pad((string)++$consec, 3, '0', STR_PAD_LEFT); } while (usuario_ocupado($pdo, $usuario));
                } elseif (usuario_ocupado($pdo, $usuario)) { $omitidos++; continue; }   // lo tomó alguien después de la vista previa
                $provisional = generar_password_provisional();
                $p = [$f['nombre'], $f['ap'], $f['am'] !== '' ? $f['am'] : null, $f['telefono'] ?: null, $f['correo'] ?: null,
                      $usuario, password_hash($provisional, PASSWORD_DEFAULT)];
                if ($tipo !== 'admin') $p[] = (int)$_SESSION['id_usuario'];
                if ($tipo === 'maestros') $p[] = $f['titulo'] !== '' ? $f['titulo'] : null;
                $ins->execute($p);
                $credenciales[] = ['matricula' => $usuario, 'nombre' => trim("{$f['ap']} {$f['am']} {$f['nombre']}"),
                                   'grupo' => $T['singular'], 'password' => $provisional, 'etiqueta' => 'USUARIO'];
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('importar_personal: ' . $e->getMessage());
            personal_volver($tipo, 'error', 'No se pudo guardar la importación. No se registró a nadie.');
        }
        unset($_SESSION[$clave_prev]);
        bitacora($pdo, 'usuarios', 'importacion', 'Importó lista de ' . mb_strtolower($T['plural']) . ': ' . count($credenciales) . ' registrados',
                 $tipo, null, ['usuarios' => array_column($credenciales, 'matricula')]);
        credenciales_guardar_lote($credenciales, 'Importación de ' . mb_strtolower($T['plural']), credenciales_expira_texto($pdo));
        $msg = 'Se registraron ' . count($credenciales) . ' ' . mb_strtolower($T['plural']) . '. Descarga sus fichas de acceso.' . ($omitidos ? " ($omitidos omitidos: su usuario ya existía)" : '');
        header("Location: gestion_usuarios.php?tipo=$tipo&exito=" . urlencode($msg) . '#credenciales');
        exit();
    }
    personal_volver($tipo);
}

$prev = $_SESSION[$clave_prev] ?? null;
$cuenta = ['nuevo' => 0, 'existe' => 0, 'error' => 0];
if ($prev) foreach ($prev['filas'] as $f) $cuenta[$f['estado']]++;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$estados = ['nuevo' => ['Nuevo', 'fa-circle-plus'], 'existe' => ['Ya registrado', 'fa-user-check'], 'error' => ['Revisar', 'fa-triangle-exclamation']];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Importar <?php echo $T['plural']; ?> · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
    <link rel="stylesheet" href="css/importar_personal.css?v=1">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="ip-wrap">
            <a class="ip-back" href="gestion_usuarios.php?tipo=<?php echo $tipo; ?>"><i class="fas fa-arrow-left"></i> Directorio de <?php echo mb_strtolower($T['plural']); ?></a>
            <div class="ip-head">
                <div class="ip-title">
                    <div class="ico"><i class="fas fa-file-import"></i></div>
                    <div>
                        <h1>Importar <?php echo $T['plural']; ?></h1>
                        <p>Registra a todo el personal de una vez desde Excel o CSV.</p>
                    </div>
                </div>
                <?php topbar_usuario($pdo); ?>
            </div>

            <div class="ip-tabs">
                <?php foreach ($TIPOS as $k => $t): ?>
                    <a class="ip-tab <?php echo $k === $tipo ? 'activo' : ''; ?>" href="?tipo=<?php echo $k; ?>"><i class="fas <?php echo $t['icono']; ?>"></i> <?php echo $t['plural']; ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (isset($_GET['error'])): ?><div class="ip-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

            <?php if (!$prev): ?>
            <div class="ip-card">
                <h2><i class="fas <?php echo $T['icono']; ?>"></i> Subir lista de <?php echo mb_strtolower($T['plural']); ?></h2>
                <div class="ip-pasos">
                    <div class="ip-paso"><b class="n">1</b> Prepara un Excel (.xlsx) o CSV con el <strong>nombre</strong> (completo, o apellidos y nombre en columnas) y, si los tienes, <strong>número de empleado</strong>, teléfono y correo<?php echo $tipo === 'maestros' ? ' y título académico' : ''; ?>.</div>
                    <div class="ip-paso"><b class="n">2</b> <strong>Usuario de acceso:</strong> se usa la columna "Usuario", "Número de empleado" o "RFC". Si no viene ninguna, el sistema lo genera: <strong><?php echo $T['prefijo']; ?>001, <?php echo $T['prefijo']; ?>002…</strong></div>
                    <div class="ip-paso"><b class="n">3</b> Revisa la <strong>vista previa</strong> y confirma. Cada persona recibe una <strong>contraseña provisional única</strong> y descargas sus fichas para entregarlas.</div>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="analizar">
                    <input type="hidden" name="tipo" value="<?php echo $tipo; ?>">
                    <div class="ip-form">
                        <div>
                            <label>Archivo (Excel .xlsx o CSV)</label>
                            <input class="ip-file" type="file" name="archivo" accept=".xlsx,.csv" required>
                        </div>
                        <div>
                            <label>Si el nombre viene en una sola columna, está escrito:</label>
                            <select class="ip-select" name="orden">
                                <option value="apellidos">Apellidos primero (LÓPEZ GARCÍA MARÍA)</option>
                                <option value="nombre">Nombre primero (MARÍA LÓPEZ GARCÍA)</option>
                            </select>
                        </div>
                    </div>
                    <div class="ip-acciones" style="justify-content:flex-start">
                        <button type="submit" class="ip-btn"><i class="fas fa-magnifying-glass"></i> Analizar archivo</button>
                        <a class="ip-btn sec" href="?tipo=<?php echo $tipo; ?>&amp;plantilla=1"><i class="fas fa-file-excel"></i> Descargar plantilla</a>
                    </div>
                </form>
                <p class="ip-nota"><i class="fas fa-user-shield"></i> Las personas que ya están registradas (mismo nombre) se omiten automáticamente, así que puedes volver a subir la lista completa sin duplicar a nadie.<br>
                <i class="fas fa-lock"></i> Si el archivo trae una columna de contraseña, se ignora: el sistema siempre genera la suya y la persona la cambia en su primer acceso.</p>
            </div>
            <?php else: ?>
            <div class="ip-card">
                <h2><i class="fas fa-list-check"></i> Vista previa · <?php echo $h($prev['archivo']); ?></h2>
                <?php if ($prev['ignoro_pwd']): ?><div class="ip-alerta info"><i class="fas fa-lock"></i> El archivo trae una columna de contraseña: se ignoró. El sistema generará una contraseña provisional para cada persona.</div><?php endif; ?>
                <div class="ip-res">
                    <div class="r-nuevo"><b><?php echo $cuenta['nuevo']; ?></b><span>Se registrarán</span></div>
                    <div class="r-existe"><b><?php echo $cuenta['existe']; ?></b><span>Ya estaban registrados</span></div>
                    <div class="r-error"><b><?php echo $cuenta['error']; ?></b><span>Por revisar (no se guardan)</span></div>
                </div>
                <div class="ip-tabla-wrap">
                    <table class="ip-tabla">
                        <thead><tr><th>Fila</th><th>Estado</th><th>Nombre</th><th>Usuario</th><th>Contacto</th><th>Detalle</th></tr></thead>
                        <tbody>
                        <?php foreach ($prev['filas'] as $f): [$t, $i] = $estados[$f['estado']]; ?>
                            <tr>
                                <td><?php echo (int)$f['fila']; ?></td>
                                <td><span class="ip-chip r-<?php echo $f['estado']; ?>"><i class="fas <?php echo $i; ?>"></i> <?php echo $t; ?></span></td>
                                <td><b><?php echo $h(trim("{$f['nombre']} {$f['ap']} {$f['am']}")); ?></b><?php if ($f['titulo']): ?><small><?php echo $h($f['titulo']); ?></small><?php endif; ?></td>
                                <td><?php if ($f['usuario'] !== ''): ?><span class="ip-user"><?php echo $h($f['usuario']); ?></span><small><?php echo $h($f['origen']); ?></small><?php elseif ($f['estado'] === 'nuevo'): ?><span class="ip-auto">Automático (<?php echo $T['prefijo']; ?>…)</span><?php else: ?>—<?php endif; ?></td>
                                <td><?php echo $h($f['telefono'] ?: '—'); ?><small><?php echo $h($f['correo']); ?></small></td>
                                <td><?php echo $h($f['motivo']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ip-acciones">
                    <form method="POST" style="margin:0"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="tipo" value="<?php echo $tipo; ?>"><button type="submit" class="ip-btn sec"><i class="fas fa-xmark"></i> Cancelar</button></form>
                    <form method="POST" style="margin:0" onsubmit="return confirm('¿Registrar <?php echo $cuenta['nuevo']; ?> <?php echo mb_strtolower($cuenta['nuevo'] === 1 ? $T['singular'] : $T['plural']); ?> con contraseña provisional?');">
                        <input type="hidden" name="accion" value="aplicar"><input type="hidden" name="tipo" value="<?php echo $tipo; ?>">
                        <button type="submit" class="ip-btn" <?php echo $cuenta['nuevo'] ? '' : 'disabled'; ?>><i class="fas fa-user-plus"></i> Registrar <?php echo $cuenta['nuevo']; ?> y generar contraseñas</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
