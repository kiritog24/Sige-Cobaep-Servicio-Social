<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/campana.php';

// 1. Validación para el ESTUDIANTE
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_alumno = $_SESSION['id_usuario'];

// Valores por defecto para evitar errores visuales (Warnings) en caso de fallo
$nombre_pila = 'Alumno';
$apellido_p = '';
$nombre_completo = 'Alumno';
$iniciales = 'AL';
$generacion = '';
$alumno = ['matricula' => 'N/A'];
$mi_horario = null;

try {
    // 2. Obtener datos básicos del alumno
    $stmt = $pdo->prepare("SELECT nombre, apellido_paterno, matricula, periodo_ingreso FROM ALUMNOS WHERE id_alumno = ?");
    $stmt->execute([$id_alumno]);
    $datos_alumno = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($datos_alumno) {
        $alumno = $datos_alumno;
        $nombre_pila = htmlspecialchars($alumno['nombre']);
        $apellido_p = htmlspecialchars($alumno['apellido_paterno']);
        $nombre_completo = $nombre_pila . ' ' . $apellido_p;
        $iniciales = mb_substr($nombre_pila, 0, 1) . mb_substr($apellido_p, 0, 1);
        
        $anio_ingreso = !empty($alumno['periodo_ingreso']) ? substr($alumno['periodo_ingreso'], 0, 4) : date('Y');
        $generacion = $anio_ingreso . ' - ' . ($anio_ingreso + 3);
    }

    // 3. Obtener el archivo de horario de forma segura (Sólo lectura)
    try {
        // El administrador sube el horario por grupo (HORARIOS_MAESTRO); tomamos el más reciente del grupo activo del alumno
        $stmtHorario = $pdo->prepare("
            SELECT hm.ruta_pdf
            FROM GRUPO_ALUMNO ga
            INNER JOIN HORARIOS_MAESTRO hm ON hm.id_grupo = ga.id_grupo
            WHERE ga.id_alumno = ? AND ga.fecha_baja IS NULL AND hm.ruta_pdf IS NOT NULL
            ORDER BY hm.anio DESC, hm.fecha_subida DESC
            LIMIT 1
        ");
        $stmtHorario->execute([$id_alumno]);
        $horario_data = $stmtHorario->fetch(PDO::FETCH_ASSOC);
        if ($horario_data) {
            $mi_horario = $horario_data['ruta_pdf'];
        }
    } catch (PDOException $e) {
        $mi_horario = null;
    }

} catch (PDOException $e) {
    error_log("Error cargando horario: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Mi Horario · Panel Estudiante</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- CSS Base unificado -->
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/horario_clases.css?v=1">
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
                
                <a href="horario_clases.php" class="nav-item active"><i class="far fa-calendar-alt"></i> <span>Horario de Clases</span></a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i> <span>Horarios del Plantel</span></a>
                
                <span class="nav-heading">Académico</span>
                <a href="actividades_escolares.php" class="nav-item"><i class="fas fa-book-open"></i> <span>Actividades Escolares</span></a>
                <a href="actividades.php" class="nav-item"><i class="fas fa-star"></i> <span>Regularizaciones</span></a>
                <a href="mis_maestros.php" class="nav-item"><i class="fas fa-chalkboard-user"></i> <span>Mis Maestros</span></a>

                <span class="nav-heading">Información</span>
                <a href="avisos.php" class="nav-item"><i class="far fa-bell"></i> <span>Avisos</span></a>
                <a href="documentos.php" class="nav-item"><i class="far fa-file-alt"></i> <span>Documentos</span></a>
                <a href="reglamentos.php" class="nav-item"><i class="far fa-clipboard"></i> <span>Reglamentos</span></a>
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
                    <div>
                        <h1>Horario de Clases</h1>
                        <p>Consulta tu carga académica asignada para este semestre.</p>
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
                                <span>Matrícula: <?php echo htmlspecialchars($alumno['matricula']); ?></span>
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

            <div class="layout-horario">
                
                <!-- VISOR DEL HORARIO (Única columna) -->
                <div class="viewer-card">
                    <div class="viewer-header">
                        <h3><i class="far fa-calendar-check icon-green"></i> Tu Horario Actual</h3>
                        <?php if (!empty($mi_horario)): ?>
                            <a href="../<?php echo htmlspecialchars($mi_horario); ?>" download class="btn-outline-small">
                                <i class="fas fa-download"></i> Descargar PDF
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="viewer-body">
                        <?php if (empty($mi_horario)): ?>
                            <div class="empty-state-horario">
                                <i class="far fa-calendar-times"></i>
                                <span>El área administrativa aún no ha subido tu horario.</span>
                                <p>Por favor, vuelve a revisar más tarde.</p>
                            </div>
                        <?php else: ?>
                            <?php 
                                $ext = strtolower(pathinfo($mi_horario, PATHINFO_EXTENSION)); 
                                if ($ext === 'pdf'):
                            ?>
                                <iframe src="../<?php echo htmlspecialchars($mi_horario); ?>" class="horario-iframe" frameborder="0"></iframe>
                            <?php else: ?>
                                <img src="../<?php echo htmlspecialchars($mi_horario); ?>" alt="Mi Horario" class="horario-img">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
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