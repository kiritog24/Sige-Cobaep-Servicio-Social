<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/ficha_alumno.php';
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
$id_alumno = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_alumno < 1) {
    header("Location: gestion_usuarios.php");
    exit();
}

$d = ficha_alumno_cargar($pdo, $id_alumno);
if (!$d) {
    header("Location: gestion_usuarios.php?tipo=alumnos&error=" . urlencode("El alumno no existe."));
    exit();
}
$al = $d['al'];
$nombre_completo = trim($al['nombre'] . ' ' . $al['apellido_paterno'] . ' ' . $al['apellido_materno']);
$volver = $al['id_grupo'] ? 'ver_grupo.php?id=' . (int)$al['id_grupo'] : 'gestion_usuarios.php?tipo=alumnos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title><?php echo htmlspecialchars($nombre_completo); ?> · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_admin.css">
    <link rel="stylesheet" href="style_sidebar.css">
    <style>
        .btn-back{display:inline-flex;align-items:center;gap:8px;color:#6d7f77;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:10px;}
        .btn-back i{background:#f1f5f9;width:32px;height:32px;display:grid;place-items:center;border-radius:50%;}
    </style>
    <link rel="stylesheet" href="../css/responsive.css?v=3">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <a href="<?php echo $volver; ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Volver</a>
                    <h1>Expediente del alumno</h1>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <section class="section">
            <?php ficha_alumno_render($d, [
                'editar' => 'editar_usuario.php?id=' . $id_alumno . '&tipo=alumnos',
                'lista'  => $al['id_grupo'] ? 'reportes.php?id_grupo=' . (int)$al['id_grupo'] : null,
            ]); ?>
        </section>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay'), mobileMenu = document.getElementById('mobileMenu'), collapseToggle = document.getElementById('collapseToggle'), body = document.body;
            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');
            if (collapseToggle) collapseToggle.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); });
            if (mobileMenu) mobileMenu.addEventListener('click', () => { sidebar.classList.add('mobile-open'); overlay.classList.add('visible'); body.classList.add('menu-open'); });
            if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('visible'); body.classList.remove('menu-open'); });
        });
    </script>
    <script src="../js/responsive.js?v=3"></script>
</body>
</html>
