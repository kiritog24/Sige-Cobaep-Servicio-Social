<?php
/**
 * procesos/perfil_usuario.php — "Mi Perfil" de Administración y Prefectura.
 * Acciones: datos | foto | quitar_foto | password
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';

$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($rol, ['administrativo', 'prefectos'], true)) {
    header("Location: ../login.php");
    exit();
}
$c = usuario_rol_config($rol);
$id = (int)$_SESSION['id_usuario'];
$carpeta_rol = $rol === 'administrativo' ? 'admin' : 'Prefectura';

function volver_perfil_u(string $tipo, string $msg, string $ancla = ''): void {
    global $carpeta_rol;
    header("Location: ../$carpeta_rol/perfil.php?$tipo=" . urlencode($msg) . $ancla);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') volver_perfil_u('error', 'Solicitud no válida.');
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) volver_perfil_u('error', 'El archivo es demasiado grande (máx. 2 MB).', '#foto');

$tabla = $c['tabla'];
$idcol = $c['id'];

try {
    switch ($_POST['accion'] ?? '') {
        case 'datos':
            $nombre = trim($_POST['nombre'] ?? '');
            $ap     = trim($_POST['apellido_paterno'] ?? '');
            $am     = trim($_POST['apellido_materno'] ?? '');
            $tel    = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            if ($nombre === '' || $ap === '') volver_perfil_u('error', 'El nombre y el apellido paterno son obligatorios.');
            if ($tel !== '' && strlen($tel) !== 10) volver_perfil_u('error', 'El teléfono debe tener 10 dígitos.');
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) volver_perfil_u('error', 'El correo no es válido.');
            $pdo->prepare("UPDATE $tabla SET nombre = ?, apellido_paterno = ?, apellido_materno = ?, telefono = ?, correo = ? WHERE $idcol = ?")
                ->execute([mb_substr($nombre, 0, 100), mb_substr($ap, 0, 100), $am !== '' ? mb_substr($am, 0, 100) : null,
                           $tel !== '' ? $tel : null, $correo !== '' ? $correo : null, $id]);
            $_SESSION['nombre'] = trim("$nombre $ap $am");
            volver_perfil_u('exito', 'Tus datos se guardaron correctamente.');

        case 'foto':
            // JPG/PNG/WEBP, máx. 2 MB, mínimo 400×400 px; se recorta en cuadrado y se reduce a 1080×1080 px
            $resultado = procesar_foto_perfil($_FILES['foto_perfil'] ?? null, 'perfiles', ($rol === 'administrativo' ? 'admin_' : 'prefecto_') . $id);
            if (!$resultado['ok']) volver_perfil_u('error', $resultado['error'], '#foto');
            $st = $pdo->prepare("SELECT foto_perfil FROM $tabla WHERE $idcol = ?");
            $st->execute([$id]);
            $anterior = $st->fetchColumn();
            $pdo->prepare("UPDATE $tabla SET foto_perfil = ? WHERE $idcol = ?")->execute([$resultado['ruta'], $id]);
            reemplazar_foto($anterior ?: null, $resultado['ruta']);   // la foto anterior se elimina del servidor
            volver_perfil_u('exito', 'Tu foto de perfil se actualizó.', '#foto');

        case 'quitar_foto':
            $st = $pdo->prepare("SELECT foto_perfil FROM $tabla WHERE $idcol = ?");
            $st->execute([$id]);
            $anterior = $st->fetchColumn();
            $pdo->prepare("UPDATE $tabla SET foto_perfil = NULL WHERE $idcol = ?")->execute([$id]);
            reemplazar_foto($anterior ?: null);
            volver_perfil_u('exito', 'Se quitó tu foto de perfil.', '#foto');

        case 'password':
            $actual = $_POST['actual'] ?? '';
            $nueva = $_POST['nueva'] ?? '';
            if (strlen($nueva) < 8) volver_perfil_u('error', 'La nueva contraseña debe tener al menos 8 caracteres.', '#seguridad');
            if ($nueva !== ($_POST['confirmar'] ?? '')) volver_perfil_u('error', 'Las contraseñas nuevas no coinciden.', '#seguridad');
            $st = $pdo->prepare("SELECT contrasena_hash FROM $tabla WHERE $idcol = ?");
            $st->execute([$id]);
            if (!password_verify($actual, (string)$st->fetchColumn())) volver_perfil_u('error', 'La contraseña actual es incorrecta.', '#seguridad');
            $pdo->prepare("UPDATE $tabla SET contrasena_hash = ? WHERE $idcol = ?")->execute([password_hash($nueva, PASSWORD_DEFAULT), $id]);
            volver_perfil_u('exito', 'Tu contraseña se actualizó.', '#seguridad');

        default:
            volver_perfil_u('error', 'Acción no reconocida.');
    }
} catch (PDOException $e) {
    error_log('perfil_usuario: ' . $e->getMessage());
    volver_perfil_u('error', 'Ocurrió un error al guardar los cambios.');
}
