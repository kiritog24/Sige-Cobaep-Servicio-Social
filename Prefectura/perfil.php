<?php
session_start();
require_once '../config/database.php';
require_once '../includes/perfil_usuario.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');
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
    <title>Mi Perfil · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css?v=3">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">CUENTA · PREFECTURA</span>
                    <h1>Mi Perfil</h1>
                    <p>Actualiza tus datos, tu foto y tu contraseña.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>
        <?php perfil_usuario_contenido($pdo); ?>
    </main>

    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
