<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../perfil.php");
    exit();
}

$id_maestro = (int)$_SESSION['id_usuario'];
$telefono = trim($_POST['telefono'] ?? '');
$correo = trim($_POST['correo'] ?? '');
$titulo_academico = trim($_POST['titulo_academico'] ?? '');
$biografia = trim($_POST['biografia'] ?? '');

function volver_perfil(string $msg, string $texto = ''): void {
    header("Location: ../perfil.php?msg=$msg" . ($texto ? '&texto=' . urlencode($texto) : ''));
    exit();
}

if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) volver_perfil('error', 'El correo no es válido.');
if ($telefono !== '' && !preg_match('/^\d{10}$/', preg_replace('/\D/', '', $telefono))) volver_perfil('error', 'El teléfono debe tener 10 dígitos.');

try {
    $nueva_foto = null;
    if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] !== UPLOAD_ERR_NO_FILE) {
        // JPG/PNG/WEBP, máx. 2 MB, mínimo 400×400 px; se recorta en cuadrado y se reduce a 1080×1080 px
        $resultado = procesar_foto_perfil($_FILES['foto_perfil'], 'perfiles', 'perfil_' . $id_maestro);
        if (!$resultado['ok']) volver_perfil('error', $resultado['error']);
        $nueva_foto = $resultado['ruta'];

        // Borrar la foto anterior para no acumular archivos
        $st = $pdo->prepare("SELECT foto_perfil FROM MAESTROS WHERE id_maestro = ?");
        $st->execute([$id_maestro]);
        reemplazar_foto($st->fetchColumn() ?: null, $nueva_foto);   // la foto anterior se elimina del servidor
    }

    if ($nueva_foto) {
        $st = $pdo->prepare("UPDATE MAESTROS SET telefono = ?, correo = ?, titulo_academico = ?, biografia = ?, foto_perfil = ? WHERE id_maestro = ?");
        $st->execute([$telefono, $correo, $titulo_academico, $biografia, $nueva_foto, $id_maestro]);
    } else {
        $st = $pdo->prepare("UPDATE MAESTROS SET telefono = ?, correo = ?, titulo_academico = ?, biografia = ? WHERE id_maestro = ?");
        $st->execute([$telefono, $correo, $titulo_academico, $biografia, $id_maestro]);
    }
    volver_perfil('success');
} catch (PDOException $e) {
    error_log("Error al actualizar perfil: " . $e->getMessage());
    volver_perfil('error', 'No se pudieron guardar los cambios.');
}
