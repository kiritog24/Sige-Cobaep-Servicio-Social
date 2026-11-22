<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/topbar_docente.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

$id_docente = $_SESSION['id_usuario'];
$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Docente';
date_default_timezone_set('America/Mexico_City');

$partes_nombre = explode(" ", $nombre_usuario);
$iniciales = strtoupper(substr($partes_nombre[0], 0, 1) . (isset($partes_nombre[1]) ? substr($partes_nombre[1], 0, 1) : ''));

$mis_grupos = [];

try {
    // SOLO TRAEMOS LOS GRUPOS PUROS ASIGNADOS AL DOCENTE
    $stmtGrupos = $pdo->prepare("
        SELECT 
            g.id_grupo,
            g.nombre_grupo,
            g.semestre,
            g.turno,
            (SELECT COUNT(id_alumno) FROM grupo_alumno WHERE id_grupo = g.id_grupo AND fecha_baja IS NULL) AS total_alumnos
        FROM grupos g
        WHERE g.id_grupo IN (
            SELECT id_grupo FROM grupo_maestro WHERE id_maestro = ?
            UNION
            SELECT id_grupo FROM horarios_maestro WHERE id_maestro = ?
        )
        ORDER BY g.semestre, g.turno, g.nombre_grupo
    ");
    $stmtGrupos->execute([$id_docente, $id_docente]);
    $mis_grupos = $stmtGrupos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error cargando grupos del docente: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Grupos · Portal Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_docente.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/mis_grupos.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>

<body>
    <!-- SCRIPT ANTI-PARPADEO: Debe ir exactamente aquí, antes de cualquier HTML -->
    <script>
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.body.classList.add('sidebar-collapsed', 'preload-transitions');
        }
    </script>
    <!-- BARRA LATERAL RESTAURADA A SU DISEÑO ORIGINAL PERFECTO -->
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
                <a href="mis_grupos.php" class="nav-item active">
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
                    <span class="eyebrow">ACADÉMICO · DOCENTE</span>
                    <h1>Mis Grupos Asignados</h1>
                    <p>Selecciona un grupo para gestionar sus materias y alumnos.</p>
                </div>
            </div>
            <?php docente_topbar($pdo); ?>
        </header>

        <section>
            <?php if (empty($mis_grupos)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>Aún no tienes grupos asignados</h3>
                    <p>El área administrativa debe asignarte horarios y grupos para este semestre.</p>
                </div>
            <?php else: ?>
                <div class="grid-aulas">
                    <?php foreach ($mis_grupos as $grupo): ?>
                        <div class="aula-card">
                            <div class="aula-header">
                                <span class="aula-badge">Grupo Asignado</span>
                                <div>
                                    <h3>Grupo <?php echo htmlspecialchars($grupo['semestre'] ?? '') . '° "' . htmlspecialchars($grupo['nombre_grupo'] ?? '') . '"'; ?></h3>
                                    <p>Turno <?php echo htmlspecialchars($grupo['turno']); ?></p>
                                </div>
                            </div>
                            <div class="aula-body">
                                <div class="meta">
                                    <i class="fas fa-users"></i> 
                                    <span><strong><?php echo $grupo['total_alumnos']; ?></strong> Alumnos inscritos</span>
                                </div>
                            </div>
                            <div class="aula-footer">
                                <a href="gestionar_aula.php?id_grupo=<?php echo $grupo['id_grupo']; ?>" class="btn-manage">
                                    Abrir Grupo <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script src="../js/dashboard_docente.js"></script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>