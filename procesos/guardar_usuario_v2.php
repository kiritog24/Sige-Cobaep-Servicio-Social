<?php
/**
 * procesos/guardar_usuario_v2.php — "Nuevo Registro" de Administración (alumno, docente, administrativo, prefecto).
 *
 * La contraseña la GENERA el sistema (provisional única, vence en IMPORTACION_DIAS días) y el usuario
 * debe cambiarla en su primer acceso. Se muestra una sola vez al administrativo (con ficha PDF para imprimir);
 * en la base de datos solo se guarda el hash.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/credenciales_lote.php';

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/agregar_usuario.php");
    exit();
}

function volver_alta(string $error): void {
    header("Location: ../admin/agregar_usuario.php?error=" . urlencode($error));
    exit();
}

$tipos = [
    'alumno'  => ['tabla' => 'ALUMNOS',         'etiqueta' => 'Alumno'],
    'maestro' => ['tabla' => 'MAESTROS',        'etiqueta' => 'Docente'],
    'admin'   => ['tabla' => 'ADMINISTRATIVOS', 'etiqueta' => 'Administrativo'],
    'prefect' => ['tabla' => 'PREFECTOS',       'etiqueta' => 'Prefecto'],
];
$tipo = (string)($_POST['tipo_usuario'] ?? '');
if (!isset($tipos[$tipo])) volver_alta('Selecciona un tipo de usuario válido.');

$nombre     = trim($_POST['nombre'] ?? '');
$apellido_p = trim($_POST['apellido_p'] ?? '');
$apellido_m = trim($_POST['apellido_m'] ?? '');
$telefono   = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
$correo     = trim($_POST['correo'] ?? '');

if ($nombre === '' || $apellido_p === '') volver_alta('El nombre y el apellido paterno son obligatorios.');
if ($telefono !== '' && strlen($telefono) !== 10) volver_alta('El teléfono debe tener 10 dígitos.');
if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) volver_alta('El correo electrónico no es válido.');

$nombre     = mb_substr($nombre, 0, 100);
$apellido_p = mb_substr($apellido_p, 0, 100);
$apellido_m = $apellido_m !== '' ? mb_substr($apellido_m, 0, 100) : null;
$telefono   = $telefono !== '' ? $telefono : null;
$correo     = $correo !== '' ? mb_substr($correo, 0, 150) : null;

$provisional = generar_password_provisional();
$hash = password_hash($provisional, PASSWORD_DEFAULT);
$vence = "NOW() + INTERVAL " . IMPORTACION_DIAS . " DAY";
$id_admin = (int)$_SESSION['id_usuario'];

/** ¿El nombre de acceso ya lo usa alguien (en cualquier tipo de cuenta)? */
function acceso_ocupado(PDO $pdo, string $valor): bool {
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM ALUMNOS WHERE matricula = ?) + (SELECT COUNT(*) FROM MAESTROS WHERE usuario = ?)
                              + (SELECT COUNT(*) FROM ADMINISTRATIVOS WHERE usuario = ?) + (SELECT COUNT(*) FROM PREFECTOS WHERE usuario = ?)");
    $st->execute([$valor, $valor, $valor, $valor]);
    return (int)$st->fetchColumn() > 0;
}

try {
    if ($tipo === 'alumno') {
        $matricula = mb_strtoupper(trim($_POST['matricula'] ?? ''));
        if ($matricula === '') volver_alta('La matrícula es obligatoria para los alumnos.');
        if (!preg_match('~^[A-Z0-9/_-]{4,20}$~', $matricula)) volver_alta('La matrícula solo puede tener letras, números, "/" o "-" (máx. 20).');
        if (acceso_ocupado($pdo, $matricula)) volver_alta("La matrícula $matricula ya está registrada.");

        $codigo_qr = trim($_POST['codigo_qr'] ?? '');
        if ($codigo_qr === '') $codigo_qr = 'QR' . str_replace('/', '', $matricula);
        $st = $pdo->prepare("SELECT COUNT(*) FROM ALUMNOS WHERE codigo_qr = ?");
        $st->execute([$codigo_qr]);
        if ($st->fetchColumn() > 0) volver_alta('Ese código QR ya pertenece a otro alumno.');

        $sexo = in_array($_POST['sexo'] ?? '', ['M', 'F'], true) ? $_POST['sexo'] : null;
        $fecha_nac = ($_POST['fecha_nacimiento'] ?? '') !== '' && strtotime($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
        $turno = in_array($_POST['turno'] ?? '', ['Matutino', 'Intermedio'], true) ? $_POST['turno'] : 'Matutino';
        $semestre = 1;
        $grupo_txt = 'Sin grupo';

        // Si se elige grupo, el semestre y el turno se toman del grupo
        $id_grupo = (int)($_POST['id_grupo'] ?? 0);
        if ($id_grupo > 0) {
            $st = $pdo->prepare("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE id_grupo = ?");
            $st->execute([$id_grupo]);
            $g = $st->fetch(PDO::FETCH_ASSOC);
            if (!$g) volver_alta('El grupo seleccionado no existe.');
            $semestre = (int)$g['semestre'];
            if (in_array($g['turno'], ['Matutino', 'Intermedio'], true)) $turno = $g['turno'];
            $grupo_txt = credenciales_grupo_txt($semestre, $g['nombre_grupo'], $g['turno']);
        }
        $periodo = date('Y') . (date('n') >= 7 ? '-B' : '-A');

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO ALUMNOS
                (matricula, codigo_qr, turno, sexo, nombre, apellido_paterno, apellido_materno, fecha_nacimiento,
                 telefono, correo_personal, contrasena_hash, debe_cambiar_password, password_provisional_expira, semestre, periodo_ingreso)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, $vence, ?, ?)")
            ->execute([$matricula, $codigo_qr, $turno, $sexo, $nombre, $apellido_p, $apellido_m, $fecha_nac,
                       $telefono, $correo, $hash, $semestre, $periodo]);
        $id_nuevo = (int)$pdo->lastInsertId();
        if ($id_grupo > 0) {
            $pdo->prepare("INSERT INTO GRUPO_ALUMNO (id_alumno, id_grupo, fecha_asignacion) VALUES (?, ?, CURDATE())")->execute([$id_nuevo, $id_grupo]);
        }
        $pdo->commit();

        $acceso = $matricula;
        $detalle = $grupo_txt;
        $etiqueta_usuario = null;   // ficha de alumno: "USUARIO (MATRÍCULA)" y "Grupo: …"
    } else {
        $usuario = trim($_POST['usuario'] ?? '');
        if ($usuario === '') volver_alta('El nombre de usuario es obligatorio para el personal.');
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $usuario)) volver_alta('El usuario solo puede tener letras, números, punto, guion o guion bajo (3 a 60).');
        if (acceso_ocupado($pdo, $usuario)) volver_alta("El usuario \"$usuario\" ya existe. Elige otro.");

        $tabla = $tipos[$tipo]['tabla'];
        $extra_col = in_array($tipo, ['maestro', 'prefect'], true) ? ', id_admin_registro' : '';
        $extra_val = $extra_col ? ', ?' : '';
        $params = [$nombre, $apellido_p, $apellido_m, $telefono, $correo, $usuario, $hash];
        if ($extra_col) $params[] = $id_admin;
        $pdo->prepare("INSERT INTO $tabla (nombre, apellido_paterno, apellido_materno, telefono, correo, usuario, contrasena_hash,
                                           debe_cambiar_password, password_provisional_expira$extra_col)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1, $vence$extra_val)")
            ->execute($params);

        $acceso = $usuario;
        $detalle = $tipos[$tipo]['etiqueta'];
        $etiqueta_usuario = 'USUARIO';
    }

    bitacora($pdo, 'usuarios', 'alta', 'Registró ' . mb_strtolower($tipos[$tipo]['etiqueta']) . ': ' . $acceso . ' · ' . trim("$nombre $apellido_p " . ($apellido_m ?? '')),
             ['alumno' => 'alumno', 'maestro' => 'maestro', 'admin' => 'admin', 'prefect' => 'prefecto'][$tipo], null, ['detalle' => $detalle]);

    // Se muestra una sola vez en "Nuevo Registro" (y se puede descargar como ficha PDF)
    $ficha = [
        'matricula' => $acceso,
        'nombre'    => preg_replace('/\s+/', ' ', trim("$apellido_p " . ($apellido_m ?? '') . " $nombre")),
        'grupo'     => $detalle,
        'password'  => $provisional,
    ];
    if ($etiqueta_usuario) $ficha['etiqueta'] = $etiqueta_usuario;
    credenciales_guardar_lote([$ficha], 'Nuevo registro · ' . $tipos[$tipo]['etiqueta'], credenciales_expira_texto($pdo), 'credenciales_alta');

    header("Location: ../admin/agregar_usuario.php?exito=1");
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('guardar_usuario_v2: ' . $e->getMessage());
    volver_alta('No se pudo guardar el usuario. Revisa que la matrícula, el código QR o el usuario no estén repetidos.');
}
