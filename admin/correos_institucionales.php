<?php
/**
 * admin/correos_institucionales.php — Control Escolar asigna los correos institucionales en bloque.
 *
 *  1. Sube el Excel (.xlsx) o CSV que envía Dirección General (matrícula, nombre y correo; puede traer más columnas).
 *  2. El sistema identifica a cada alumno por su MATRÍCULA (o por su nombre completo si no hay matrícula)
 *     y muestra una vista previa: nuevos, cambios, sin cambio y filas con problema.
 *  3. Al confirmar, se guardan solo los correos nuevos o cambiados.
 *
 * Si el archivo trae una columna de contraseña, se IGNORA: la contraseña del correo es de la cuenta de
 * Google/Microsoft de Dirección General y no se guarda en el SIGE.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/leer_tabla.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

const CORREOS_MAX_MB = 5;

function correos_volver(string $tipo = '', string $msg = ''): void {
    header('Location: correos_institucionales.php' . ($tipo ? "?$tipo=" . urlencode($msg) : ''));
    exit();
}

// ---------- Plantilla con los alumnos actuales ----------
if (isset($_GET['plantilla'])) {
    require_once __DIR__ . '/../includes/xlsx_simple.php';
    $rows = $pdo->query("SELECT a.matricula, CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS nombre,
                                IF(g.id_grupo IS NULL, '', CONCAT(g.semestre, '° ', g.nombre_grupo, ' · ', g.turno)) AS grupo,
                                COALESCE(a.correo_institucional, '') AS correo
                         FROM ALUMNOS a
                         LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                         LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                         WHERE a.estatus <> 'Baja'
                         GROUP BY a.id_alumno
                         ORDER BY g.semestre, g.turno, g.nombre_grupo, a.apellido_paterno, a.apellido_materno, a.nombre")->fetchAll(PDO::FETCH_NUM);
    xlsx_descargar('Plantilla_correos_institucionales.xlsx', 'Correos', [], ['Matrícula', 'Nombre del alumno', 'Grupo', 'Correo institucional'], $rows, [18, 42, 22, 38]);
}

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'cancelar') {
        unset($_SESSION['correos_preview']);
        correos_volver();
    }

    if ($accion === 'aplicar') {
        $prev = $_SESSION['correos_preview'] ?? null;
        if (!$prev) correos_volver('error', 'La vista previa expiró. Vuelve a subir el archivo.');
        $cambios = array_values(array_filter($prev['filas'], fn($f) => in_array($f['estado'], ['nuevo', 'cambio'], true)));
        if (!$cambios) correos_volver('error', 'No hay correos nuevos ni cambios para guardar.');
        try {
            $pdo->beginTransaction();
            // Primero se liberan los correos que cambian de dueño dentro del mismo archivo
            $upd = $pdo->prepare("UPDATE ALUMNOS SET correo_institucional = ? WHERE id_alumno = ?");
            foreach ($cambios as $f) $upd->execute([null, $f['id_alumno']]);
            foreach ($cambios as $f) $upd->execute([$f['correo'], $f['id_alumno']]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('correos_institucionales: ' . $e->getMessage());
            correos_volver('error', 'No se pudieron guardar los correos.');
        }
        unset($_SESSION['correos_preview']);
        $n_nuevo = count(array_filter($cambios, fn($f) => $f['estado'] === 'nuevo'));
        bitacora($pdo, 'correos', 'asignacion', 'Asignó correos institucionales desde "' . mb_substr($prev['archivo'], 0, 80) . '": ' . $n_nuevo . ' nuevos, ' . (count($cambios) - $n_nuevo) . ' actualizados', 'alumno', null,
                 ['cambios' => array_map(fn($f) => $f['matricula'] . ': ' . ($f['anterior'] ?: '—') . ' → ' . $f['correo'], $cambios)]);
        correos_volver('exito', "Se guardaron los correos institucionales: $n_nuevo nuevos y " . (count($cambios) - $n_nuevo) . ' actualizados.');
    }

    if ($accion === 'analizar') {
        if (empty($_POST) || !isset($_FILES['archivo'])) correos_volver('error', 'El archivo es demasiado grande (máx. ' . CORREOS_MAX_MB . ' MB).');
        $file = $_FILES['archivo'];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) correos_volver('error', 'Selecciona un archivo de Excel o CSV.');
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > CORREOS_MAX_MB * 1048576) correos_volver('error', 'No se pudo subir el archivo (máx. ' . CORREOS_MAX_MB . ' MB).');

        try {
            $tabla = leer_tabla($file['tmp_name'], $file['name']);
        } catch (RuntimeException $e) {
            correos_volver('error', $e->getMessage());
        }

        // Encabezados: se buscan en las primeras 15 filas
        $fila_enc = -1; $col = ['mat' => -1, 'correo' => -1, 'nombre' => [], 'pwd' => -1];
        foreach (array_slice($tabla, 0, 15, true) as $i => $fila) {
            $c = ['mat' => -1, 'correo' => -1, 'nombre' => [], 'pwd' => -1];
            foreach ($fila as $j => $v) {
                $h = norm_texto($v);
                if ($h === '') continue;
                if ($c['correo'] < 0 && preg_match('/\b(CORREO|EMAIL|E MAIL|MAIL)\b/', $h)) $c['correo'] = $j;
                elseif ($c['mat'] < 0 && preg_match('/^(MATRICULA|NO MATRICULA|NUM MATRICULA|MAT)\b/', $h)) $c['mat'] = $j;
                elseif (preg_match('/(CONTRASE|PASSWORD|CLAVE)/', $h)) $c['pwd'] = $j;
                elseif (preg_match('/^(NOMBRE|NOMBRES|ALUMNO|NOMBRE DEL ALUMNO|NOMBRE COMPLETO|APELLIDO|APELLIDOS|PRIMER APELLIDO|SEGUNDO APELLIDO|APELLIDO PATERNO|APELLIDO MATERNO|PATERNO|MATERNO)\b/', $h)) $c['nombre'][] = $j;
            }
            if ($c['correo'] >= 0 && ($c['mat'] >= 0 || $c['nombre'])) { $fila_enc = $i; $col = $c; break; }
        }
        if ($fila_enc < 0) {
            correos_volver('error', 'No se encontraron los encabezados. El archivo debe tener una columna "Correo" y otra "Matrícula" o "Nombre".');
        }

        // Alumnos del sistema
        $alumnos = $pdo->query("SELECT a.id_alumno, a.matricula, a.correo_institucional,
                                       CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS nombre,
                                       IF(g.id_grupo IS NULL, 'Sin grupo', CONCAT(g.semestre, '° ', g.nombre_grupo, ' · ', g.turno)) AS grupo
                                FROM ALUMNOS a
                                LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                                LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                                GROUP BY a.id_alumno")->fetchAll(PDO::FETCH_ASSOC);
        $por_mat = []; $por_nombre = []; $por_correo = [];
        foreach ($alumnos as $a) {
            $por_mat[llave_matricula($a['matricula'])] = $a;
            $por_nombre[llave_nombre($a['nombre'])][] = $a;
            if ($a['correo_institucional']) $por_correo[mb_strtolower($a['correo_institucional'])] = (int)$a['id_alumno'];
        }

        $filas = []; $vistos_correo = []; $vistos_alumno = [];
        foreach ($tabla as $i => $fila) {
            if ($i <= $fila_enc) continue;
            $mat = $col['mat'] >= 0 ? trim($fila[$col['mat']] ?? '') : '';
            $nom = trim(implode(' ', array_map(fn($j) => $fila[$j] ?? '', $col['nombre'])));
            $correo = mb_strtolower(trim($fila[$col['correo']] ?? ''));
            if ($mat === '' && $nom === '' && $correo === '') continue;

            $r = ['fila' => $i + 1, 'matricula' => $mat, 'nombre_archivo' => $nom, 'correo' => $correo,
                  'id_alumno' => null, 'alumno' => '', 'grupo' => '', 'anterior' => '', 'por' => '', 'estado' => '', 'motivo' => ''];

            // 1) Identificar al alumno: por matrícula; si no hay, por nombre completo (solo si es único)
            $a = null;
            if ($mat !== '') {
                $a = $por_mat[llave_matricula($mat)] ?? null;
                if ($a) $r['por'] = 'Matrícula';
            }
            if (!$a && $nom !== '') {
                $cand = $por_nombre[llave_nombre($nom)] ?? [];
                if (count($cand) === 1) { $a = $cand[0]; $r['por'] = 'Nombre'; }
                elseif (count($cand) > 1) { $r['estado'] = 'error'; $r['motivo'] = 'Hay ' . count($cand) . ' alumnos con ese nombre; agrega la matrícula.'; }
            }

            if (!$a && !$r['estado']) { $r['estado'] = 'error'; $r['motivo'] = 'No se encontró al alumno en el sistema.'; }
            elseif ($a) {
                $r['id_alumno'] = (int)$a['id_alumno'];
                $r['alumno'] = $a['nombre'];
                $r['matricula'] = $a['matricula'];
                $r['grupo'] = $a['grupo'];
                $r['anterior'] = (string)$a['correo_institucional'];

                if ($correo === '') { $r['estado'] = 'error'; $r['motivo'] = 'La fila no tiene correo.'; }
                elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { $r['estado'] = 'error'; $r['motivo'] = 'El correo no es válido.'; }
                elseif (isset($vistos_alumno[$r['id_alumno']])) { $r['estado'] = 'error'; $r['motivo'] = 'El alumno aparece repetido (fila ' . $vistos_alumno[$r['id_alumno']] . ').'; }
                elseif (isset($vistos_correo[$correo])) { $r['estado'] = 'error'; $r['motivo'] = 'El correo está repetido en el archivo (fila ' . $vistos_correo[$correo] . ').'; }
                elseif (mb_strtolower($r['anterior']) === $correo) { $r['estado'] = 'igual'; }
                else { $r['estado'] = $r['anterior'] === '' ? 'nuevo' : 'cambio'; }

                $vistos_alumno[$r['id_alumno']] ??= $r['fila'];
                if ($correo !== '' && $r['estado'] !== 'error') $vistos_correo[$correo] = $r['fila'];
            }
            $filas[] = $r;
        }

        // 2) Correo que ya tiene OTRO alumno y que ese alumno no cambia en este archivo
        $nuevo_de = [];
        foreach ($filas as $f) if (in_array($f['estado'], ['nuevo', 'cambio', 'igual'], true)) $nuevo_de[$f['id_alumno']] = $f['correo'];
        foreach ($filas as &$f) {
            if (!in_array($f['estado'], ['nuevo', 'cambio'], true)) continue;
            $dueno = $por_correo[$f['correo']] ?? null;
            if ($dueno && $dueno !== $f['id_alumno'] && (($nuevo_de[$dueno] ?? null) === null || $nuevo_de[$dueno] === $f['correo'])) {
                $f['estado'] = 'error';
                $f['motivo'] = 'Ese correo ya lo tiene otro alumno.';
            }
        }
        unset($f);

        if (!$filas) correos_volver('error', 'El archivo no tiene filas con datos debajo de los encabezados.');
        $_SESSION['correos_preview'] = ['archivo' => $file['name'], 'filas' => $filas, 'ignoro_pwd' => $col['pwd'] >= 0,
                                        'por' => $col['mat'] >= 0 ? 'matrícula' : 'nombre'];
        correos_volver();
    }
    correos_volver();
}

$prev = $_SESSION['correos_preview'] ?? null;
$cuenta = ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'error' => 0];
if ($prev) foreach ($prev['filas'] as $f) $cuenta[$f['estado']]++;
$sin_correo = (int)$pdo->query("SELECT COUNT(*) FROM ALUMNOS WHERE estatus <> 'Baja' AND (correo_institucional IS NULL OR correo_institucional = '')")->fetchColumn();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$estados = [
    'nuevo'  => ['Nuevo', 'fa-circle-plus'],
    'cambio' => ['Cambia', 'fa-arrows-rotate'],
    'igual'  => ['Sin cambio', 'fa-equals'],
    'error'  => ['Revisar', 'fa-triangle-exclamation'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Correos Institucionales · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
    <link rel="stylesheet" href="css/correos_institucionales.css?v=1">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="ci-wrap">
            <a class="ci-back" href="gestion_usuarios.php?tipo=alumnos"><i class="fas fa-arrow-left"></i> Directorio de alumnos</a>
            <div class="ci-head">
                <div class="ci-title">
                    <div class="ico"><i class="fas fa-envelope-open-text"></i></div>
                    <div>
                        <h1>Correos institucionales</h1>
                        <p>Asigna en bloque los correos que envía Dirección General.</p>
                    </div>
                </div>
                <?php topbar_usuario($pdo); ?>
            </div>

            <?php if (isset($_GET['exito'])): ?><div class="ci-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
            <?php if (isset($_GET['error'])): ?><div class="ci-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

            <?php if (!$prev): ?>
            <div class="ci-card">
                <h2><i class="fas fa-file-arrow-up"></i> Subir lista de correos</h2>
                <div class="ci-pasos">
                    <div class="ci-paso"><b class="n">1</b> Usa el archivo de <strong>Dirección General</strong> tal cual (Excel .xlsx o CSV). Debe tener una columna <strong>Correo</strong> y otra <strong>Matrícula</strong> o <strong>Nombre</strong>.</div>
                    <div class="ci-paso"><b class="n">2</b> El sistema identifica a cada alumno por su <strong>matrícula</strong>. Si no viene, lo busca por su <strong>nombre completo</strong> (solo si no hay otro igual).</div>
                    <div class="ci-paso"><b class="n">3</b> Revisa la <strong>vista previa</strong> y confirma. Solo se guardan los correos nuevos o que cambiaron.</div>
                </div>
                <form class="ci-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="analizar">
                    <input class="ci-file" type="file" name="archivo" accept=".xlsx,.csv" required>
                    <button type="submit" class="ci-btn"><i class="fas fa-magnifying-glass"></i> Analizar archivo</button>
                    <a class="ci-btn sec" href="?plantilla=1"><i class="fas fa-file-excel"></i> Descargar plantilla</a>
                </form>
                <p class="ci-nota"><i class="fas fa-circle-info"></i> Hay <b><?php echo $sin_correo; ?></b> alumnos activos sin correo institucional. La plantilla trae a todos los alumnos con su matrícula, grupo y correo actual, por si prefieres llenarla a mano.<br>
                <i class="fas fa-lock"></i> Si el archivo trae una columna de <b>contraseña</b>, se ignora: la contraseña del correo es de la cuenta de Dirección General y no se guarda en el SIGE.</p>
            </div>
            <?php else: ?>
            <div class="ci-card">
                <h2><i class="fas fa-list-check"></i> Vista previa · <?php echo $h($prev['archivo']); ?></h2>
                <?php if ($prev['ignoro_pwd']): ?><div class="ci-alerta info"><i class="fas fa-lock"></i> El archivo trae una columna de contraseña: se ignoró y no se guardará.</div><?php endif; ?>
                <div class="ci-res">
                    <div class="r-nuevo"><b><?php echo $cuenta['nuevo']; ?></b><span>Correos nuevos</span></div>
                    <div class="r-cambio"><b><?php echo $cuenta['cambio']; ?></b><span>Cambian de correo</span></div>
                    <div class="r-igual"><b><?php echo $cuenta['igual']; ?></b><span>Sin cambio</span></div>
                    <div class="r-error"><b><?php echo $cuenta['error']; ?></b><span>Por revisar (no se guardan)</span></div>
                </div>
                <div class="ci-filtros">
                    <button type="button" class="ci-filtro activo" data-f="todos">Todos (<?php echo count($prev['filas']); ?>)</button>
                    <?php foreach ($estados as $k => [$t, $i]): if (!$cuenta[$k]) continue; ?>
                        <button type="button" class="ci-filtro" data-f="<?php echo $k; ?>"><?php echo $t; ?> (<?php echo $cuenta[$k]; ?>)</button>
                    <?php endforeach; ?>
                </div>
                <div class="ci-tabla-wrap">
                    <table class="ci-tabla">
                        <thead><tr><th>Fila</th><th>Estado</th><th>Alumno</th><th>Correo institucional</th><th>Detalle</th></tr></thead>
                        <tbody>
                        <?php foreach ($prev['filas'] as $f): [$t, $i] = $estados[$f['estado']]; ?>
                            <tr data-e="<?php echo $f['estado']; ?>">
                                <td><?php echo (int)$f['fila']; ?></td>
                                <td><span class="ci-chip r-<?php echo $f['estado']; ?>"><i class="fas <?php echo $i; ?>"></i> <?php echo $t; ?></span></td>
                                <td>
                                    <?php if ($f['id_alumno']): ?>
                                        <b><?php echo $h($f['alumno']); ?></b><small><?php echo $h($f['matricula']); ?> · <?php echo $h($f['grupo']); ?></small>
                                    <?php else: ?>
                                        <b><?php echo $h($f['nombre_archivo'] ?: '—'); ?></b><small><?php echo $h($f['matricula'] ?: 'Sin matrícula'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $h($f['correo'] ?: '—'); ?>
                                    <?php if ($f['estado'] === 'cambio'): ?><small class="ci-old"><?php echo $h($f['anterior']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo $f['motivo'] ? $h($f['motivo']) : ($f['por'] ? 'Identificado por ' . $h(mb_strtolower($f['por'])) : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ci-acciones">
                    <form method="POST" style="margin:0"><input type="hidden" name="accion" value="cancelar"><button type="submit" class="ci-btn sec"><i class="fas fa-xmark"></i> Cancelar</button></form>
                    <form method="POST" style="margin:0" onsubmit="return confirm('¿Guardar <?php echo $cuenta['nuevo'] + $cuenta['cambio']; ?> correos institucionales?');">
                        <input type="hidden" name="accion" value="aplicar">
                        <button type="submit" class="ci-btn" <?php echo ($cuenta['nuevo'] + $cuenta['cambio']) ? '' : 'disabled'; ?>><i class="fas fa-floppy-disk"></i> Guardar <?php echo $cuenta['nuevo'] + $cuenta['cambio']; ?> correos</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <script>
        document.querySelectorAll('.ci-filtro').forEach(b => b.addEventListener('click', () => {
            document.querySelectorAll('.ci-filtro').forEach(x => x.classList.toggle('activo', x === b));
            document.querySelectorAll('.ci-tabla tbody tr').forEach(tr => { tr.style.display = (b.dataset.f === 'todos' || tr.dataset.e === b.dataset.f) ? '' : 'none'; });
        }));
    </script>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
