<?php
/**
 * estudiante/procesos/avisos_accion.php — Limpiar la vista de avisos sin borrar nada.
 *  accion=ocultar  (clave)  → guarda un aviso y lo quita de la vista principal
 *  accion=limpiar           → guarda todos los avisos que ya no piden nada al alumno
 *  accion=restaurar (clave) → lo regresa a la vista principal
 */
session_start();
require_once '../../config/database.php';
require_once '../includes/avisos_data.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header('Location: ../../login.php');
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../avisos.php'); exit(); }

$id_alumno = (int)$_SESSION['id_usuario'];
$accion = $_POST['accion'] ?? '';
$clave = substr(trim((string)($_POST['clave'] ?? '')), 0, 80);
$volver = function (string $msg, string $vista = '') {
    header('Location: ../avisos.php?' . ($vista ? "vista=$vista&" : '') . 'exito=' . urlencode($msg));
    exit();
};

try {
    if ($accion === 'restaurar' && $clave !== '') {
        avisos_restaurar($pdo, $id_alumno, $clave);
        $volver('El aviso regresó a tus avisos recientes.', 'guardados');
    }

    $actuales = avisos_visibles($pdo, $id_alumno, obtener_avisos_estudiante($pdo, $id_alumno, 50));
    if ($accion === 'ocultar' && $clave !== '') {
        $uno = array_values(array_filter($actuales, fn($a) => ($a['clave'] ?? '') === $clave));
        if ($uno) avisos_guardar($pdo, $id_alumno, $uno);
        $volver('Aviso guardado. Lo encuentras en la pestaña "Guardados".');
    }
    if ($accion === 'limpiar') {
        $n = avisos_guardar($pdo, $id_alumno, array_filter($actuales, fn($a) => !aviso_pendiente($a)));
        $volver($n ? "Listo: $n aviso(s) guardado(s). Los que todavía te piden algo se quedaron a la vista." : 'No había avisos para limpiar.');
    }
} catch (PDOException $e) {
    error_log('avisos_accion: ' . $e->getMessage());
}
header('Location: ../avisos.php');
