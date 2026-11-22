<?php
/**
 * procesos/restablecer_password.php — Administración restablece la contraseña de un usuario que la olvidó.
 *
 *  - El sistema GENERA una contraseña provisional nueva y única cada vez (nadie la escribe a mano).
 *  - La contraseña anterior del usuario se reemplaza y deja de funcionar de inmediato.
 *  - La provisional vence en PROVISIONAL_HORAS y sirve solo para entrar a crear una nueva:
 *    al iniciar sesión con ella, el usuario es enviado a cambiar_password.php y, al cambiarla, desaparece.
 *  - Se muestra una sola vez a Administración (no viaja en la URL ni se guarda en texto plano).
 */
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/gestion_usuarios.php");
    exit();
}

$id_usuario = (int)($_POST['id_usuario'] ?? 0);
$tipo = (string)($_POST['tipo_usuario'] ?? '');
$rol = cuenta_rol_desde_tipo($tipo);
$c = $rol ? cuenta_config($rol) : null;
if (!$c || $id_usuario <= 0) {
    header("Location: ../admin/gestion_usuarios.php");
    exit();
}
$volver = "../admin/editar_usuario.php?id=$id_usuario&tipo=" . urlencode($tipo);

try {
    $st = $pdo->prepare("SELECT {$c['usuario']} AS usuario, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre FROM {$c['tabla']} WHERE {$c['id']} = ?");
    $st->execute([$id_usuario]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        header("Location: ../admin/gestion_usuarios.php?tipo=" . urlencode($tipo));
        exit();
    }

    $provisional = generar_password_provisional();
    $pdo->prepare("UPDATE {$c['tabla']}
                   SET contrasena_hash = ?, debe_cambiar_password = 1,
                       password_provisional_expira = NOW() + INTERVAL " . PROVISIONAL_HORAS . " HOUR
                   WHERE {$c['id']} = ?")
        ->execute([password_hash($provisional, PASSWORD_DEFAULT), $id_usuario]);
    $expira = $pdo->query("SELECT DATE_FORMAT(NOW() + INTERVAL " . PROVISIONAL_HORAS . " HOUR, '%d/%m/%Y %h:%i %p')")->fetchColumn();

    bitacora($pdo, 'password', 'restablecer', 'Generó contraseña provisional para ' . $u['usuario'] . ' · ' . $u['nombre'] . ' (' . $c['etiqueta'] . ')', $tipo, $id_usuario);

    // Si estaba bloqueado por intentos fallidos, se le desbloquea
    login_limpiar($pdo, (string)$u['usuario']);

    // Se muestra una sola vez en la pantalla de edición
    $_SESSION['pwd_provisional_generada'] = [
        'id' => $id_usuario, 'tipo' => $tipo, 'usuario' => $u['usuario'], 'nombre' => $u['nombre'],
        'password' => $provisional, 'expira' => $expira, 'rol' => $c['etiqueta'],
    ];
    header("Location: $volver&msg=pwd_reset");
    exit();
} catch (PDOException $e) {
    error_log("restablecer_password: " . $e->getMessage());
    header("Location: $volver&error=" . urlencode('No se pudo restablecer la contraseña.'));
    exit();
}
