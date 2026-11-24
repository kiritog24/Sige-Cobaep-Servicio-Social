<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/campana.php';

// Validar que el usuario haya iniciado sesión y sea estudiante
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_alumno = $_SESSION['id_usuario'];
date_default_timezone_set('America/Mexico_City');

try {
    // 1. Obtener datos básicos del alumno para el Header
    $stmt = $pdo->prepare("SELECT nombre, apellido_paterno, matricula FROM ALUMNOS WHERE id_alumno = ?");
    $stmt->execute([$id_alumno]);
    $alumno = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alumno) die("Error: Datos del alumno no encontrados.");

    $nombre_pila = htmlspecialchars($alumno['nombre']);
    $apellido_p = htmlspecialchars($alumno['apellido_paterno']);
    $nombre_completo = $nombre_pila . ' ' . $apellido_p;
    $iniciales = mb_substr($nombre_pila, 0, 1) . mb_substr($apellido_p, 0, 1);
    $matricula = htmlspecialchars($alumno['matricula']);

    // 2. Obtener las clases de regularización a las que está inscrito el alumno
    $stmtClases = $pdo->prepare("
        SELECT cr.*, m.nombre AS maestro_nombre, m.apellido_paterno AS maestro_apellido 
        FROM CLASES_REGULARIZACION cr
        INNER JOIN CLASE_ALUMNO ca ON cr.id_clase = ca.id_clase
        LEFT JOIN MAESTROS m ON cr.id_maestro = m.id_maestro
        WHERE ca.id_alumno = ? AND cr.estatus = 'Activa' AND cr.visible = 1
        ORDER BY cr.fecha_inicio DESC
    ");
    $stmtClases->execute([$id_alumno]);
    $mis_regularizaciones = $stmtClases->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error cargando regularizaciones del alumno: " . $e->getMessage());
    $mis_regularizaciones = [];
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
    <title>Mis Actividades · Panel Estudiante</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- CSS Base -->
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/actividades.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>

    <!-- ================= BARRA LATERAL ================= -->
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
                <a href="actividades_escolares.php" class="nav-item"><i class="fas fa-book-open"></i> <span>Actividades Escolares</span></a>
                <a href="actividades.php" class="nav-item active"><i class="fas fa-star"></i> <span>Regularizaciones</span></a>
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

    <!-- ================= CONTENIDO PRINCIPAL ================= -->
    <main class="main-content">
        <div class="dashboard-container">
            
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu" id="mobileMenu" type="button"><i class="fas fa-bars"></i></button>
                    <div style="display:flex; align-items:center; gap: 15px;">
                        <div class="header-icon"><i class="fas fa-book-open"></i></div>
                        <div>
                            <h1>Mis Regularizaciones</h1>
                            <p>Consulta tus actividades asignadas para recuperar calificación.</p>
                        </div>
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

            <?php if (isset($_GET['error'])): ?>
                <div style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:12px 16px;border-radius:12px;font-weight:600;font-size:.9rem;">
                    <i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <!-- GRID DE CLASES INSCRITAS -->
            <section class="clases-section">
                <?php if (empty($mis_regularizaciones)): ?>
                    
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>Todo al corriente</h3>
                        <p>No tienes materias en proceso de regularización en este momento.</p>
                    </div>
                    
                <?php else: ?>
                    
                    <div class="activities-grid">
                        <?php foreach ($mis_regularizaciones as $clase): ?>
                            
                            <?php 
                                // Degradado por defecto si no hay imagen
                                $bg_image = "linear-gradient(145deg, #0f172a, #063f2b)";
                                
                                // Si la base de datos SÍ tiene una imagen registrada
                                if (!empty($clase['imagen_portada'])) {
                                    $ruta_img = '../' . $clase['imagen_portada'];
                                    $bg_image = "linear-gradient(rgba(15, 23, 42, 0.4), rgba(15, 23, 42, 0.8)), url('" . htmlspecialchars($ruta_img) . "')";
                                }
                            ?>

                            <div class="activity-card">
                                
                                <div class="activity-header" style="background-image: <?php echo $bg_image; ?>;">
                                    <span class="activity-badge">Regularización</span>
                                </div>

                                <div class="activity-body">
                                    <h3><?php echo htmlspecialchars($clase['nombre_materia']); ?></h3>
                                    <p><?php echo htmlspecialchars($clase['descripcion']); ?></p>
                                    
                                    <div class="activity-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-chalkboard-user"></i> 
                                            <span>Docente: <?php echo htmlspecialchars($clase['maestro_nombre'] . ' ' . $clase['maestro_apellido']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <i class="far fa-calendar-check"></i> 
                                            <span>Límite: <?php echo date('d/m/Y', strtotime($clase['fecha_fin'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="activity-footer">
                                    <a href="espacio_regularizacion.php?id=<?php echo $clase['id_clase']; ?>" class="btn-enter">
                                        Entrar al espacio <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                            
                        <?php endforeach; ?>
                    </div>
                    
                <?php endif; ?>
            </section>
            
        </div>
    </main>

    <!-- SCRIPTS MANTENIDOS PARA FUNCIONAMIENTO DE MENÚ Y RELOJ -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mobileMenu = document.getElementById('mobileMenu');
            const collapseToggle = document.getElementById('collapseToggle');
            const body = document.body;

            // --- LÓGICA MENÚ LATERAL ---
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

            // --- LÓGICA DROPDOWN DEL PERFIL ---
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

            // --- RELOJ ---
            const fechaTexto = document.getElementById('fechaTexto');
            function actualizarHora() {
                if (!fechaTexto) return;
                const now = new Date();
                fechaTexto.textContent = now.toLocaleString('es-MX', {
                    day: '2-digit', month: '2-digit', year: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
            }
            actualizarHora();
            setInterval(actualizarHora, 60000);
        });
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>