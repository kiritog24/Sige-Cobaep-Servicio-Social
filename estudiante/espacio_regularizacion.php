<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/regula_entregas.php';
require_once __DIR__ . '/includes/campana.php';

// Validar que el usuario haya iniciado sesión y sea estudiante
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_alumno = $_SESSION['id_usuario'];
date_default_timezone_set('America/Mexico_City');

// Variables por defecto para evitar errores visuales (Warnings) en caso de fallo
$nombre_pila = 'Alumno';
$apellido_p = '';
$nombre_completo = 'Alumno';
$iniciales = 'AL';
$matricula = 'Sin Matrícula';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: actividades.php");
    exit();
}
$id_clase = (int)$_GET['id'];

try {
    // 1. Obtener datos básicos del alumno para el Header
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

    // 2. Verificar que el alumno tenga acceso a esta clase
    $stmt_check = $pdo->prepare("SELECT * FROM CLASE_ALUMNO WHERE id_clase = ? AND id_alumno = ?");
    $stmt_check->execute([$id_clase, $id_alumno]);
    if (!$stmt_check->fetch()) {
        header("Location: actividades.php?error=No tienes acceso a este espacio.");
        exit();
    }

    // 3. Cargar información de la clase y el maestro
    $stmt_clase = $pdo->prepare("
        SELECT c.*, m.nombre AS maestro_nombre, m.apellido_paterno AS maestro_apellido 
        FROM CLASES_REGULARIZACION c
        LEFT JOIN MAESTROS m ON c.id_maestro = m.id_maestro
        WHERE c.id_clase = ?
    ");
    $stmt_clase->execute([$id_clase]);
    $clase = $stmt_clase->fetch(PDO::FETCH_ASSOC);
    if (!$clase || (int)$clase['visible'] !== 1) {
        header("Location: actividades.php?error=" . urlencode('Este curso de regularización ya no está disponible.'));
        exit();
    }

    // 4. Cargar material: Bloques y Actividades
    $stmt_bloques = $pdo->prepare("SELECT * FROM BLOQUES_REGULARIZACION WHERE id_clase = ? ORDER BY id_bloque ASC");
    $stmt_bloques->execute([$id_clase]);
    $bloques = $stmt_bloques->fetchAll(PDO::FETCH_ASSOC);

    $stmt_act = $pdo->prepare("SELECT * FROM ACTIVIDADES_REGULARIZACION WHERE id_clase = ? ORDER BY fecha_publicacion DESC");
    $stmt_act->execute([$id_clase]);
    $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

    // 5. Mis entregas: una por actividad (id_actividad NULL = entrega general anterior)
    $stmt_entrega = $pdo->prepare("SELECT * FROM ENTREGAS_REGULARIZACION WHERE id_clase = ? AND id_alumno = ? ORDER BY fecha_entrega DESC");
    $stmt_entrega->execute([$id_clase, $id_alumno]);
    $mis_entregas = [];
    $entrega_general = null;
    foreach ($stmt_entrega->fetchAll(PDO::FETCH_ASSOC) as $en) {
        if ($en['id_actividad'] === null) { $entrega_general = $entrega_general ?: $en; }
        else { $mis_entregas[(int)$en['id_actividad']] = $en; }
    }
    $clase_recibe = (int)$clase['permitir_entregas'] === 1 && $clase['estatus'] === 'Activa';

} catch (PDOException $e) {
    error_log("Error cargando el espacio del alumno: " . $e->getMessage());
    header("Location: actividades.php?error=Error de conexión con la base de datos.");
    exit();
}

// Configurar imagen de fondo (Hero)
$bg_image = "linear-gradient(145deg, #0f172a, #063f2b)";
if (!empty($clase['imagen_portada'])) {
    $ruta_img = '../' . $clase['imagen_portada'];
    $bg_image = "linear-gradient(rgba(15, 23, 42, 0.5), rgba(15, 23, 42, 0.8)), url('" . htmlspecialchars($ruta_img) . "')";
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
    <title><?php echo htmlspecialchars($clase['nombre_materia']); ?> · Panel Estudiante</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- CSS Base unificado -->
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/espacio_regularizacion.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
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
                    <div>
                        <h1>Aula Virtual</h1>
                        <p>Espacio de trabajo y recepción de tareas.</p>
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

            <div class="layout-espacio">
                
                <div class="hero-estudiante" style="background-image: <?php echo $bg_image; ?>;">
                    <div class="hero-content">
                        <a href="actividades.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver a mis materias</a>
                        <h1 class="hero-title"><?php echo htmlspecialchars($clase['nombre_materia']); ?></h1>
                        <p class="hero-desc"><?php echo nl2br(htmlspecialchars($clase['descripcion'])); ?></p>
                    </div>
                    <div class="hero-footer">
                        <span class="badge-profesor"><i class="fas fa-chalkboard-user"></i> Prof. <?php echo htmlspecialchars($clase['maestro_apellido'] ?? 'No Asignado'); ?></span>
                    </div>
                </div>

                <div class="col-main">
                    
                    <?php if(isset($_GET['exito'])): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['exito']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(isset($_GET['error'])): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                        </div>
                    <?php endif; ?>

                    <h2 class="section-title"><i class="fas fa-folder-open icon-green"></i> Contenido de la materia</h2>

                    <?php if(empty($bloques) && empty($actividades)): ?>
                        <div class="empty-state-materiales">
                            <i class="fas fa-folder-open"></i>
                            <span>El maestro aún no ha subido material.</span>
                        </div>
                    <?php endif; ?>

<?php
/** Tarjeta de material/actividad con su propio espacio de entrega */
$render_act = function (array $act) use ($mis_entregas, $clase_recibe, $id_clase) {
    $nombre_archivo = !empty($act['archivo_ruta']) ? preg_replace('/^mat_[a-zA-Z0-9]+_/', '', basename($act['archivo_ruta'])) : '';
    $ent = $mis_entregas[(int)$act['id_actividad']] ?? null;
    $tiene_entrega = (int)$act['permite_entregas'] === 1 || $ent;
    $estado = regula_estado_actividad($act, $ent, $clase_recibe);
    $escala = (int)$act['escala_calificacion'];
    $id_a = (int)$act['id_actividad'];
?>
                                        <div class="mat-item" id="act-<?php echo $id_a; ?>">
                                            <div class="mat-icon"><i class="fas <?php echo $tiene_entrega ? 'fa-file-signature' : 'fa-file-pdf'; ?>"></i></div>
                                            <div class="mat-info">
                                                <h4 class="mat-title"><?php echo htmlspecialchars($act['titulo']); ?></h4>
                                                <?php if (!empty($act['descripcion'])): ?>
                                                    <p class="mat-desc"><?php echo nl2br(htmlspecialchars($act['descripcion'])); ?></p>
                                                <?php endif; ?>
                                                <?php if ($nombre_archivo !== ''): ?>
                                                    <a class="mat-filename" href="../<?php echo htmlspecialchars($act['archivo_ruta']); ?>" target="_blank"><i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($nombre_archivo); ?> <i class="fas fa-download" style="margin-left:4px;"></i></a>
                                                <?php endif; ?>

                                                <?php if ($tiene_entrega): ?>
                                                <div class="act-entrega estado-<?php echo $estado; ?>">
                                                    <div class="act-entrega-head">
                                                        <?php
                                                        $etq = [
                                                            'entregada'     => ['fa-check-circle', 'Entregada'],
                                                            'abierta'       => ['fa-hourglass-start', 'Pendiente de entregar'],
                                                            'retardo'       => ['fa-hourglass-half', 'Fecha vencida: puedes entregar con retardo'],
                                                            'cerrada'       => ['fa-lock', 'Cerrada: pasó la fecha límite'],
                                                            'deshabilitada' => ['fa-ban', 'Entregas deshabilitadas por el docente'],
                                                        ][$estado];
                                                        ?>
                                                        <strong><i class="fas <?php echo $etq[0]; ?>"></i> <?php echo $etq[1]; ?></strong>
                                                        <?php if (!empty($act['fecha_limite'])): ?>
                                                            <span><i class="far fa-clock"></i> Límite: <?php echo date('d/m/Y h:i a', strtotime($act['fecha_limite'])); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="act-reglas">
                                                        <span><i class="fas fa-file"></i> <?php echo htmlspecialchars(regula_tipos_texto($act['tipos_archivo'])); ?></span>
                                                        <span><i class="fas fa-weight-hanging"></i> Máx. <?php echo (int)$act['tamano_maximo_mb']; ?> MB</span>
                                                        <span><i class="fas fa-star"></i> Calificación de 0 a <?php echo $escala; ?></span>
                                                        <span><i class="fas fa-<?php echo $act['acepta_retardo'] ? 'circle-check' : 'ban'; ?>"></i> <?php echo $act['acepta_retardo'] ? 'Acepta retardos' : 'No acepta retardos'; ?></span>
                                                    </div>

                                                    <?php if ($ent): ?>
                                                        <p class="act-enviado">
                                                            <i class="fas fa-paperclip"></i>
                                                            <a href="../<?php echo htmlspecialchars($ent['archivo_ruta']); ?>" target="_blank"><?php echo htmlspecialchars($ent['nombre_original'] ?: preg_replace('/^entrega_[a-z0-9]+_\d+_/i', '', basename($ent['archivo_ruta']))); ?></a>
                                                            · <?php echo date('d/m/Y h:i a', strtotime($ent['fecha_entrega'])); ?>
                                                            <?php if (($ent['estatus_entrega'] ?? '') === 'Con retardo'): ?><span class="tag-retardo">Con retardo</span><?php endif; ?>
                                                        </p>
                                                        <?php if ($ent['calificacion'] !== null): ?>
                                                            <div class="act-calif"><i class="fas fa-star"></i> Calificación: <strong><?php echo regula_num($ent['calificacion']); ?> / <?php echo $escala; ?></strong></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($ent['retroalimentacion'])): ?>
                                                            <div class="act-retro"><i class="fas fa-comment-dots"></i> <?php echo nl2br(htmlspecialchars($ent['retroalimentacion'])); ?></div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <?php
                                                    $puede_subir = $clase_recibe && (int)$act['permite_entregas'] === 1
                                                        && (!$ent || $ent['calificacion'] === null)
                                                        && !(!empty($act['fecha_limite']) && strtotime($act['fecha_limite']) < time() && !$act['acepta_retardo']);
                                                    if ($puede_subir): ?>
                                                        <form action="../procesos/subir_trabajo.php" method="POST" enctype="multipart/form-data" class="form-entrega" data-max="<?php echo (int)$act['tamano_maximo_mb']; ?>">
                                                            <input type="hidden" name="id_clase" value="<?php echo (int)$id_clase; ?>">
                                                            <input type="hidden" name="id_actividad" value="<?php echo $id_a; ?>">
                                                            <input type="file" name="archivo_trabajo" required class="input-file" accept="<?php echo htmlspecialchars(regula_accept($act['tipos_archivo'])); ?>">
                                                            <button type="submit" class="btn-subir <?php echo $ent ? 'outline' : 'primary'; ?>">
                                                                <i class="fas <?php echo $ent ? 'fa-rotate' : 'fa-paper-plane'; ?>"></i> <?php echo $ent ? 'Reemplazar entrega' : ($estado === 'retardo' ? 'Entregar con retardo' : 'Entregar actividad'); ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
<?php }; ?>

                    <!-- BLOQUES -->
                    <?php foreach($bloques as $bloque): ?>
                        <div class="bloque-box">
                            <div class="bloque-header">
                                <i class="fas fa-bookmark icon-green"></i> <?php echo htmlspecialchars($bloque['titulo_bloque']); ?>
                            </div>
                            <div class="bloque-body">
                                <?php
                                $acts_bloque = array_filter($actividades, fn($a) => $a['id_bloque'] == $bloque['id_bloque']);
                                if (empty($acts_bloque)): ?>
                                    <p style="color:#94a3b8; font-style:italic; margin:0; font-size:0.9rem;">No hay archivos en este bloque.</p>
                                <?php else: foreach ($acts_bloque as $act) $render_act($act); endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- MATERIAL GENERAL -->
                    <?php $acts_generales = array_filter($actividades, fn($a) => empty($a['id_bloque'])); ?>
                    <?php if (!empty($acts_generales)): ?>
                        <h2 class="section-title" style="margin-top: 1rem;"><i class="fas fa-copy icon-green"></i> Material General</h2>
                        <div class="bloque-box" style="border-top: 4px solid #10b981;">
                            <div class="bloque-body">
                                <?php foreach ($acts_generales as $act) $render_act($act); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="col-side">
                    <div class="side-panel">
                        <h3 class="panel-title"><i class="fas fa-list-check icon-green"></i> Mis entregas</h3>
                        <?php $acts_con_entrega = array_filter($actividades, fn($a) => (int)$a['permite_entregas'] === 1 || isset($mis_entregas[(int)$a['id_actividad']])); ?>
                        <?php if (!$clase_recibe): ?>
                            <div class="status-entrega status-bloqueado"><i class="fas fa-lock"></i> Recepción de trabajos cerrada</div>
                        <?php endif; ?>
                        <?php if (empty($acts_con_entrega) && !$entrega_general): ?>
                            <p style="text-align:center; color:#64748b; font-size:0.85rem;">Tu docente aún no ha publicado actividades con entrega.</p>
                        <?php else: ?>
                            <ul class="resumen-entregas">
                                <?php foreach ($acts_con_entrega as $a):
                                    $en = $mis_entregas[(int)$a['id_actividad']] ?? null;
                                    $est = regula_estado_actividad($a, $en, $clase_recibe); ?>
                                    <li class="estado-<?php echo $est; ?>">
                                        <a href="#act-<?php echo (int)$a['id_actividad']; ?>"><?php echo htmlspecialchars($a['titulo']); ?></a>
                                        <span>
                                            <?php if ($en && $en['calificacion'] !== null): ?>
                                                <strong><?php echo regula_num($en['calificacion']); ?>/<?php echo (int)$a['escala_calificacion']; ?></strong>
                                            <?php else: ?>
                                                <?php echo ['entregada' => 'Entregada', 'abierta' => 'Pendiente', 'retardo' => 'Con retardo', 'cerrada' => 'Cerrada', 'deshabilitada' => 'No disponible'][$est]; ?>
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                                <?php if ($entrega_general): ?>
                                    <li class="estado-entregada">
                                        <a href="../<?php echo htmlspecialchars($entrega_general['archivo_ruta']); ?>" target="_blank">Entrega general (anterior)</a>
                                        <span><?php echo $entrega_general['calificacion'] !== null ? '<strong>' . regula_num($entrega_general['calificacion']) . '/10</strong>' : 'Entregada'; ?></span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        document.querySelectorAll('.form-entrega').forEach(f => f.addEventListener('submit', e => {
            const inp = f.querySelector('input[type=file]');
            const max = parseInt(f.dataset.max, 10) || 10;
            if (inp.files[0] && inp.files[0].size > max * 1048576) {
                e.preventDefault();
                alert('El archivo pesa ' + (inp.files[0].size / 1048576).toFixed(1) + ' MB. Esta actividad acepta máximo ' + max + ' MB.');
            }
        }));
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mobileMenu = document.getElementById('mobileMenu');
            const collapseToggle = document.getElementById('collapseToggle');
            const body = document.body;

            // Lógica Menú Lateral
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

            // Lógica Dropdown
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

            // Reloj
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