<?php
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/calendario.php';

// 1. SEGURIDAD: Verificar que sea un usuario autorizado (Prefecto)
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Usamos trim() para limpiar espacios en blanco accidentales
    $motivo = trim($_POST['motivo'] ?? '');
    $fecha_inicio = trim($_POST['fecha_inicio'] ?? '');
    $fecha_fin = trim($_POST['fecha_fin'] ?? '');

    // 2. VALIDACIÓN DE CAMPOS VACÍOS
    if (empty($motivo) || empty($fecha_inicio) || empty($fecha_fin)) {
        header("Location: ../dashboard_prefectura.php?error=campos_vacios");
        exit();
    }

    // 3. VALIDACIÓN DEL MOTIVO Y DE LAS FECHAS
    if (!in_array($motivo, MOTIVOS_SUSPENSION, true)) {
        header("Location: ../dashboard_prefectura.php?error=motivo_invalido");
        exit();
    }
    $di = DateTime::createFromFormat('!Y-m-d', $fecha_inicio);
    $df = DateTime::createFromFormat('!Y-m-d', $fecha_fin);
    if (!$di || !$df || $di->format('Y-m-d') !== $fecha_inicio || $df->format('Y-m-d') !== $fecha_fin || $df < $di) {
        header("Location: ../dashboard_prefectura.php?error=fechas_invalidas");
        exit();
    }
    if ($di->diff($df)->days > 60) {
        header("Location: ../dashboard_prefectura.php?error=rango_largo");
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO SUSPENSIONES_CLASES (motivo, fecha_inicio, fecha_fin) VALUES (?, ?, ?)");
        $stmt->execute([$motivo, $fecha_inicio, $fecha_fin]);
        bitacora($pdo, 'asistencia', 'suspension_clases', 'Registró suspensión de clases del ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . ': ' . mb_substr($motivo, 0, 150));
        
        header("Location: ../dashboard_prefectura.php?msg=suspension_registrada");
        exit();
    } catch (PDOException $e) {
        // 4. MANEJO DE ERRORES SEGURO (Registramos el error internamente y redirigimos sin romper la web)
        error_log("Error en SUSPENSIONES_CLASES: " . $e->getMessage());
        header("Location: ../dashboard_prefectura.php?error=error_bd");
        exit();
    }
}

// Si alguien intenta entrar directamente por la URL (GET), lo regresamos
header("Location: ../dashboard_prefectura.php");
exit();
?>