<?php
/**
 * Prefectura/procesos/eliminar_suspension.php — Cancela una suspensión de clases registrada por error.
 * Esos días vuelven a contar como días de clase.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/calendario.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../dashboard_prefectura.php");
    exit();
}

$id = (int)($_POST['id_suspension'] ?? 0);
try {
    $st = $pdo->prepare("SELECT motivo, fecha_inicio, fecha_fin FROM SUSPENSIONES_CLASES WHERE id_suspension = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        $pdo->prepare("DELETE FROM SUSPENSIONES_CLASES WHERE id_suspension = ?")->execute([$id]);
        bitacora($pdo, 'asistencia', 'suspension_cancelada', 'Canceló la suspensión de clases (' . $s['motivo'] . ') del ' . rango_fechas_texto($s['fecha_inicio'], $s['fecha_fin']));
    }
    header("Location: ../dashboard_prefectura.php?msg=suspension_cancelada");
} catch (PDOException $e) {
    error_log('eliminar_suspension: ' . $e->getMessage());
    header("Location: ../dashboard_prefectura.php?error=error_bd");
}
exit();
