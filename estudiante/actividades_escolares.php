<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/campana.php';
require_once __DIR__ . '/../includes/aulas.php';

// Validar que el usuario haya iniciado sesión y sea estudiante
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_alumno = $_SESSION['id_usuario'];
sincronizar_aulas_alumno($pdo, (int)$id_alumno);   // materias al día si cambió de grupo
date_default_timezone_set('America/Mexico_City');

// Valores por defecto
$nombre_pila = 'Alumno';
$apellido_p = '';
$nombre_completo = 'Alumno';
$iniciales = 'AL';
$matricula = 'Sin Matrícula';
$mis_clases = [];

try {
    // 1. Obtener datos básicos del alumno
    $stmt_alumno = $pdo->prepare("SELECT nombre, apellido_paterno, matricula FROM ALUMNOS WHERE id_alumno = ?");
    $stmt_alumno->execute([$id_alumno]);
    $datos_alumno = $stmt_alumno->fetch(PDO::FETCH_ASSOC);

    if ($datos_alumno) {
        $nombre_pila = htmlspecialchars($datos_alumno['nombre']);
        $apellido_p = htmlspecialchars($datos_alumno['apellido_paterno']);
        $nombre_completo = $nombre_pila . ' ' . $apellido_p;
        $iniciales = mb_substr($nombre_pila, 0, 1) . mb_substr($apellido_p, 0, 1);
        $matricula = htmlspecialchars($datos_alumno['matricula']);
    }

    // 2. Obtener las Materias Regulares a las que está inscrito el alumno
    $stmtClases = $pdo->prepare("
        SELECT c.id_clase, m.nombre_materia, m.imagen_portada, prof.nombre AS maestro_nombre, prof.apellido_paterno AS maestro_apellido,
               c.enlace_clase_virtual, g.semestre, g.nombre_grupo
        FROM clase_regular_alumno ca
        INNER JOIN clases_regulares c ON ca.id_clase = c.id_clase
        INNER JOIN materias m ON c.id_materia = m.id_materia
        LEFT JOIN maestros prof ON c.id_maestro = prof.id_maestro
        LEFT JOIN grupos g ON c.id_grupo = g.id_grupo
        WHERE ca.id_alumno = ? AND c.estatus = 'Activa' AND ca.acceso = 1
    ");
    $stmtClases->execute([$id_alumno]);
    $mis_clases = $stmtClases->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error cargando materias del alumno: " . $e->getMessage());
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
    <meta name="theme-color" content="#063f2b">
    <title>Actividades Escolares · Panel Estudiante</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/actividades_escolares.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>

    <aside class="sidebar" id="sidebar">
        <button id="collapseToggle" class="collapse-toggle" aria-label="Colapsar menú"><i class="fas fa-chevron-left"></i></button>
        <div class="sidebar-inner">
            <div class="brand">
                <img src="../img/LogoCobaep.png" alt="Logo COBAEP" class="brand-logo">
                <div class="brand-copy"><strong>SIGE<span>-Cobaep</span></strong><small>Plantel 27</small></div>
            </div>

            <nav class="navigation">
                <span class="nav-heading">Menú principal</span>
                <a href="dashboard_estud.php" class="nav-item"><i class="fas fa-home"></i> <span>Inicio</span></a>
                <a href="mis_asistencias.php" class="nav-item"><i class="far fa-calendar-check"></i> <span>Mis Asistencias</span></a>
                <a href="horario_clases.php" class="nav-item"><i class="far fa-calendar-alt"></i> <span>Horario de Clases</span></a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i> <span>Horarios del Plantel</span></a>
                
                <span class="nav-heading">Académico</span>
                <!-- ESTA ES LA OPCIÓN EN LA QUE ESTAMOS[cite: 33] -->
                <a href="actividades_escolares.php" class="nav-item active"><i class="fas fa-book-open"></i> <span>Actividades Escolares</span></a>
                <a href="actividades.php" class="nav-item"><i class="fas fa-star"></i> <span>Regularizaciones</span></a>
                <a href="mis_maestros.php" class="nav-item"><i class="fas fa-chalkboard-user"></i> <span>Mis Maestros</span></a>

                <span class="nav-heading">Información</span>
                <a href="avisos.php" class="nav-item"><i class="far fa-bell"></i> <span>Avisos</span></a>
                <a href="documentos.php" class="nav-item"><i class="far fa-file-alt"></i> <span>Documentos</span></a>
                <a href="reglamentos.php" class="nav-item"><i class="far fa-clipboard"></i> <span>Reglamentos</span></a>
                <a href="../informacion.html" class="nav-item"><i class="fas fa-circle-info"></i> <span>Privacidad y reglas</span></a>
            </nav>

            <div class="sidebar-bottom">
                <div class="institution">
                    <div class="institution-icon"><i class="fas fa-building-columns"></i></div>
                    <div><strong>COBAEP Plantel 27</strong><span>Zaragoza, Puebla</span></div>
                </div>
                <a href="../logout.php" class="logout"><i class="fas fa-arrow-right-from-bracket"></i><span>Cerrar Sesión</span></a>
            </div>
        </div>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php include __DIR__ . '/../includes/menu_estado.php'; ?>

    <main class="main-content">
        <div class="dashboard-container">
            
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu" id="mobileMenu" type="button"><i class="fas fa-bars"></i></button>
                    <div class="header-icon"><i class="fas fa-book-open"></i></div>
                    <div>
                        <h1>Actividades Escolares</h1>
                        <p>Tus materias, tareas, asistencia y clases virtuales.</p>
                    </div>
                </div>

                <div class="topbar-right">
                    <div class="date-pill">
                        <i class="far fa-calendar"></i>
                        <span id="fechaTexto"><?php echo date('d/m/Y, h:i a'); ?></span>
                    </div>
                    
                    <?php estudiante_campana($pdo); ?>
                    <div class="profile-container">
                        <div class="profile-pill" id="profileBtn">
                            <?php echo avatar_estudiante($iniciales); ?>
                            <div class="profile-info">
                                <strong><?php echo $nombre_pila; ?></strong>
                                <span>Matrícula: <?php echo htmlspecialchars($matricula); ?></span>
                            </div>
                            <i class="fas fa-chevron-down" style="color: #94a3b8; margin-left: 5px; font-size: 0.8rem;"></i>
                        </div>
                        
                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="perfil.php" class="profile-dropdown-item"><i class="far fa-user-circle"></i> Mi Perfil</a>
                            <a href="cambiar_password.php" class="profile-dropdown-item"><i class="fas fa-key"></i> Cambiar contraseña</a>
                            <a href="../logout.php" class="profile-dropdown-item"><i class="fas fa-arrow-right-from-bracket"></i> Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="clases-section">
                <?php if (empty($mis_clases)): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h3>Sin materias asignadas</h3>
                        <p>Aún no has sido inscrito en ninguna clase para este ciclo escolar.</p>
                    </div>
                <?php else: ?>
                    
                    <div class="activities-grid">
                        <?php foreach ($mis_clases as $clase): ?>
                            
                            <?php 
                                $bg_banner = !empty($clase['imagen_portada']) ? '../' . $clase['imagen_portada'] : '';
                                // Si hay imagen, la ponemos de fondo en TODA la tarjeta
                                $style_card = !empty($bg_banner) ? "background: url('".htmlspecialchars($bg_banner)."') center/cover;" : "background: white;";
                                
                                $clase_en_vivo_activa = !empty($clase['enlace_clase_virtual']);
                            ?>

                            <!-- Tarjeta Principal con fondo completo -->
                            <div class="activity-card" style="<?php echo $style_card; ?> min-height: 250px; display: flex; flex-direction: column;">
                                
                                <!-- BANNER SUPERIOR TRANSPARENTE -->
                                <div style="height: 90px; position: relative; <?php echo empty($bg_banner) ? 'background: linear-gradient(145deg, var(--green-900), var(--green-700));' : ''; ?>">
                                    <div class="badges-container">
                                        <span class="activity-badge"><i class="fas fa-users"></i> Grupo <?php echo htmlspecialchars($clase['semestre'] . '° "' . $clase['nombre_grupo'] . '"'); ?></span>
                                        <?php if($clase_en_vivo_activa): ?>
                                            <span class="activity-badge live-badge"><span class="live-dot"></span> EN VIVO</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- CONTENIDO INFERIOR (Glassmorphism) -->
                                <div style="padding: 1.2rem 1.5rem 1.5rem 1.5rem; display: flex; flex-direction: column; flex: 1; justify-content: space-between; background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-top: 1px solid rgba(255, 255, 255, 0.6);">
                                    
                                    <div>
                                        <h3 style="margin: 0 0 1rem 0; color: #0f172a; font-size: 1.3rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 3px rgba(255,255,255,0.9);">
                                            <?php echo htmlspecialchars($clase['nombre_materia']); ?>
                                        </h3>
                                        
                                        <div style="background: rgba(248, 250, 252, 0.8); border-radius: 10px; padding: 12px; border: 1px solid rgba(226, 232, 240, 0.7); margin-bottom: 1rem;">
                                            <div class="meta-item">
                                                <i class="fas fa-chalkboard-user"></i> 
                                                <span>Prof: <?php echo htmlspecialchars($clase['maestro_nombre'] . ' ' . $clase['maestro_apellido']); ?></span>
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-tasks"></i> 
                                                <span>Actividades y Trabajos</span>
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-user-check"></i> 
                                                <span>Asistencia de la materia</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: auto;">
                                        <?php if($clase_en_vivo_activa): ?>
                                            <a href="aula_virtual.php?id=<?php echo $clase['id_clase']; ?>" class="btn-enter btn-live" style="box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);">
                                                Entrar a Clase en Línea <i class="fas fa-video"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="aula_virtual.php?id=<?php echo $clase['id_clase']; ?>" class="btn-enter" style="box-shadow: 0 2px 6px rgba(5, 150, 105, 0.3);">
                                                Ver Actividades <i class="fas fa-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </div>
                            
                        <?php endforeach; ?>
                    </div>
                    
                <?php endif; ?>
            </section>
            
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mobileMenu = document.getElementById('mobileMenu');
            const collapseToggle = document.getElementById('collapseToggle');
            const body = document.body;

            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');

            if (collapseToggle) {
                collapseToggle.addEventListener('click', () => {
                    body.classList.toggle('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
                });
            }

            window.addEventListener('resize', () => {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('visible');
                    body.classList.remove('menu-open');
                }
            });

            if (mobileMenu) {
                mobileMenu.addEventListener('click', () => {
                    sidebar.classList.add('mobile-open');
                    overlay.classList.add('visible');
                    body.classList.add('menu-open');
                });
            }
            if (overlay) {
                overlay.addEventListener('click', () => {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('visible');
                    body.classList.remove('menu-open');
                });
            }

            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');

            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('active');
                });
                document.addEventListener('click', (e) => {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.remove('active');
                    }
                });
            }
        });
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>