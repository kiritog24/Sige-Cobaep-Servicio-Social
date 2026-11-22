<?php
// procesos/actualizar_foto_perfil.php — El estudiante cambia su fotografía (credencial virtual)
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

function volver_perfil(string $tipo, string $mensaje) {
    header("Location: ../estudiante/perfil.php?$tipo=" . urlencode($mensaje));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['foto_perfil'])) {
    volver_perfil('error', 'No se recibió ninguna imagen.');
}

$id_alumno = (int)$_SESSION['id_usuario'];
// Se valida (JPG/PNG/WEBP, máx. 2 MB, mínimo 400×400 px), se recorta en cuadrado y se reduce a 1080×1080 px
$resultado = procesar_foto_perfil($_FILES['foto_perfil'], 'fotos_alumnos', 'alumno_' . $id_alumno);
if (!$resultado['ok']) volver_perfil('error', $resultado['error']);
$nueva = $resultado['ruta'];

try {
    // Borrar la foto anterior para no acumular archivos
    $st = $pdo->prepare("SELECT fotografia FROM ALUMNOS WHERE id_alumno = ?");
    $st->execute([$id_alumno]);
    $anterior = $st->fetchColumn();

    $st = $pdo->prepare("UPDATE ALUMNOS SET fotografia = ? WHERE id_alumno = ?");
    $st->execute([$nueva, $id_alumno]);
    reemplazar_foto($anterior ?: null, $nueva);   // la foto anterior se elimina del servidor
    volver_perfil('exito', 'Tu fotografía se actualizó correctamente.');
} catch (PDOException $e) {
    reemplazar_foto($nueva);
    error_log('Foto alumno: ' . $e->getMessage());
    volver_perfil('error', 'Error al guardar la fotografía.');
}
