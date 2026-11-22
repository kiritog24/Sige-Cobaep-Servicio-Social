<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once '../includes/directorio_horarios.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');
$nombre_usuario = htmlspecialchars($_SESSION['nombre'] ?? 'Prefecto', ENT_QUOTES, 'UTF-8');
try {
    $datos = dh_cargar($pdo);
} catch (PDOException $e) {
    error_log('Horarios del plantel: ' . $e->getMessage());
    $datos = ['maestros' => [], 'grupos' => [], 'turnos' => []];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Horarios del Plantel · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button"><i class="fas fa-bars"></i></button>
                <div><span class="eyebrow">PREFECTURA · CONSULTA</span><h1>Horarios del Plantel</h1><p>Ubica qué grupo tiene clase y con qué docente en cada momento.</p></div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>
        <?php dh_render($datos); ?>
    </main>
    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
