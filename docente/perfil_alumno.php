<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/topbar_docente.php';
require_once __DIR__ . '/../includes/ficha_alumno.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');
$id_docente = (int)$_SESSION['id_usuario'];
$id_alumno = (int)($_GET['id'] ?? 0);

// Solo alumnos de sus grupos, de sus aulas virtuales o de sus regularizaciones
$st = $pdo->prepare("
    SELECT 1 FROM GRUPO_ALUMNO ga
     WHERE ga.id_alumno = :a AND ga.fecha_baja IS NULL
       AND ga.id_grupo IN (SELECT id_grupo FROM GRUPO_MAESTRO WHERE id_maestro = :d1 UNION SELECT id_grupo FROM HORARIOS_MAESTRO WHERE id_maestro = :d2)
    UNION SELECT 1 FROM CLASE_REGULAR_ALUMNO cra INNER JOIN CLASES_REGULARES c ON c.id_clase = cra.id_clase WHERE cra.id_alumno = :a2 AND c.id_maestro = :d3
    UNION SELECT 1 FROM CLASE_ALUMNO ca INNER JOIN CLASES_REGULARIZACION c ON c.id_clase = ca.id_clase WHERE ca.id_alumno = :a3 AND c.id_maestro = :d4
    LIMIT 1");
$st->execute([':a' => $id_alumno, ':d1' => $id_docente, ':d2' => $id_docente, ':a2' => $id_alumno, ':d3' => $id_docente, ':a3' => $id_alumno, ':d4' => $id_docente]);
$d = $st->fetchColumn() ? ficha_alumno_cargar($pdo, $id_alumno) : null;
if (!$d) {
    header("Location: mis_grupos.php?error=" . urlencode('No tienes acceso a ese alumno.'));
    exit();
}
$volver = $_SERVER['HTTP_REFERER'] ?? '';
if (!preg_match('#^https?://' . preg_quote($_SERVER['HTTP_HOST'] ?? 'localhost', '#') . '/#', $volver)) $volver = 'mis_grupos.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil del alumno · Portal Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_docente.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/perfil_alumno.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <script>if (localStorage.getItem('sidebarCollapsed') === 'true') document.body.classList.add('sidebar-collapsed', 'preload-transitions');</script>
    <aside class="sidebar" id="sidebar">
        <button id="collapseToggle" class="collapse-toggle" aria-label="Colapsar menú">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="sidebar-inner">
            <div class="brand">
                <img src="../img/LogoCobaep.png" alt="Logo COBAEP" class="brand-logo">
                <div class="brand-copy">
                    <strong>SIGE<span>-Cobaep</span></strong>
                    <small>Plantel 27</small>
                </div>
            </div>

            <nav class="navigation" aria-label="Navegación principal">
                <span class="nav-heading">Menú principal</span>
                <a href="dashboard_docente.php" class="nav-item">
                    <i class="fas fa-house"></i><span>Inicio</span>
                </a>

                <span class="nav-heading">Académico</span>
                <a href="mis_grupos.php" class="nav-item">
                    <i class="fas fa-laptop-code"></i><span>Mis Grupos</span>
                </a>
                <a href="horario.php" class="nav-item">
                    <i class="far fa-calendar-alt"></i><span>Mi Horario</span>
                </a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i><span>Horarios del Plantel</span></a>

                <span class="nav-heading">Evaluación</span>
                <a href="regularizaciones.php" class="nav-item">
                    <i class="fas fa-book-open-reader"></i><span>Regularizaciones</span>
                </a>
            </nav>

            <div class="sidebar-bottom">
                <div class="institution">
                    <div class="institution-icon"><i class="fas fa-building-columns"></i></div>
                    <div><strong>COBAEP Plantel 27</strong><span>Portal Docente</span></div>
                </div>
                <a href="../logout.php" class="logout"><i class="fas fa-arrow-right-from-bracket"></i><span>Cerrar Sesión</span></a>
            </div>
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php include __DIR__ . '/../includes/menu_estado.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu"><i class="fas fa-bars"></i></button>
                <div>
                    <a href="<?php echo htmlspecialchars($volver); ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Volver</a>
                    <h1>Perfil del alumno</h1>
                    <p>Información que capturaron control escolar y el propio alumno.</p>
                </div>
            </div>
            <?php docente_topbar($pdo); ?>
        </header>
        <section>
            <?php ficha_alumno_render($d); ?>
        </section>
    </main>
    <script src="../js/dashboard_docente.js"></script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
