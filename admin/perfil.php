<?php
session_start();
require_once '../config/database.php';
require_once '../includes/perfil_usuario.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Mi Perfil · Administración</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_admin.css">
    <link rel="stylesheet" href="style_sidebar.css">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">CUENTA · ADMINISTRACIÓN</span>
                    <h1>Mi Perfil</h1>
                    <p>Actualiza tus datos, tu foto y tu contraseña.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>
        <?php perfil_usuario_contenido($pdo); ?>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay'), mobileMenu = document.getElementById('mobileMenu'), collapseToggle = document.getElementById('collapseToggle'), body = document.body;
            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');
            if (collapseToggle) collapseToggle.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); });
            const abrir = () => { sidebar.classList.add('mobile-open'); overlay.classList.add('visible'); body.classList.add('menu-open'); };
            const cerrar = () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('visible'); body.classList.remove('menu-open'); };
            if (mobileMenu) mobileMenu.addEventListener('click', abrir);
            if (overlay) overlay.addEventListener('click', cerrar);
        });
    </script>
    <script src="../js/responsive.js?v=8"></script>
</body>
</html>
