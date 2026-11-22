<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/ficha_alumno.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');

$id_alumno = (int)($_GET['id'] ?? 0);
if ($id_alumno < 1 && !empty($_GET['matricula'])) {
    $st = $pdo->prepare("SELECT id_alumno FROM ALUMNOS WHERE matricula = ?");
    $st->execute([trim($_GET['matricula'])]);
    $id_alumno = (int)$st->fetchColumn();
}
$d = $id_alumno > 0 ? ficha_alumno_cargar($pdo, $id_alumno) : null;
if (!$d) {
    header("Location: expediente_alumnos.php?error=" . urlencode('El alumno no existe.'));
    exit();
}
$al = $d['al'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title><?php echo htmlspecialchars($al['nombre'] . ' ' . $al['apellido_paterno']); ?> · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">
    <link rel="stylesheet" href="css/perfil_alumno.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <a href="expediente_alumnos.php?matricula=<?php echo urlencode($al['matricula']); ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Volver al expediente</a>
                    <h1>Perfil del alumno</h1>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>
        <section class="section">
            <?php ficha_alumno_render($d, [
                'acciones' => [['creacion_reportes.php?matricula=' . urlencode($al['matricula']), 'fa-file-circle-plus', 'Levantar reporte']],
            ]); ?>
        </section>
    </main>
    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
