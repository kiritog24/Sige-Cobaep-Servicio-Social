<?php
/**
 * Plantilla compartida del panel del estudiante (barra lateral + encabezado).
 * Uso:
 *   $alumno = estudiante_requerir_sesion($pdo);
 *   estudiante_layout_inicio('Avisos', 'Descripción', 'avisos.php', $alumno, 'css/avisos.css');  // hoja de estilos propia de la página (opcional)
 *   ... contenido ...
 *   estudiante_layout_fin();
 */

require_once __DIR__ . '/campana.php';

function estudiante_requerir_sesion(PDO $pdo): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
        header("Location: ../login.php");
        exit();
    }
    date_default_timezone_set('America/Mexico_City');
    $st = $pdo->prepare("SELECT * FROM ALUMNOS WHERE id_alumno = ?");
    $st->execute([$_SESSION['id_usuario']]);
    $alumno = $st->fetch(PDO::FETCH_ASSOC);
    if (!$alumno) die("Error: Datos del alumno no encontrados.");
    return $alumno;
}

function estudiante_layout_inicio(string $titulo, string $subtitulo, string $activo, array $alumno, string $css_archivo = ''): void {
    $nombre_pila = htmlspecialchars($alumno['nombre']);
    $iniciales = htmlspecialchars(mb_substr($alumno['nombre'], 0, 1) . mb_substr($alumno['apellido_paterno'], 0, 1));
    $menu = [
        'Menú principal' => [
            ['dashboard_estud.php', 'fas fa-home', 'Inicio'],
            ['mis_asistencias.php', 'far fa-calendar-check', 'Mis Asistencias'],
            ['horario_clases.php', 'far fa-calendar-alt', 'Horario de Clases'],
            ['horarios_plantel.php', 'fas fa-calendar-week', 'Horarios del Plantel'],
        ],
        'Académico' => [
            ['actividades_escolares.php', 'fas fa-book-open', 'Actividades Escolares'],
            ['actividades.php', 'fas fa-star', 'Regularizaciones'],
            ['mis_maestros.php', 'fas fa-chalkboard-user', 'Mis Maestros'],
        ],
        'Información' => [
            ['avisos.php', 'far fa-bell', 'Avisos'],
            ['documentos.php', 'far fa-file-alt', 'Documentos'],
            ['reglamentos.php', 'far fa-clipboard', 'Reglamentos'],
            ['../informacion.html', 'fas fa-circle-info', 'Privacidad y reglas'],
        ],
    ];
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
    <title><?php echo htmlspecialchars($titulo); ?> · Panel Estudiante</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_estud.css">
    <link rel="stylesheet" href="css/layout.css?v=1">
    <?php if ($css_archivo !== ''): ?><link rel="stylesheet" href="<?php echo htmlspecialchars($css_archivo, ENT_QUOTES, 'UTF-8'); ?>?v=1"><?php endif; ?>
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
                <?php foreach ($menu as $seccion => $items): ?>
                    <span class="nav-heading"><?php echo $seccion; ?></span>
                    <?php foreach ($items as [$href, $icono, $texto]): ?>
                        <a href="<?php echo $href; ?>" class="nav-item <?php echo $href === $activo ? 'active' : ''; ?>"><i class="<?php echo $icono; ?>"></i> <span><?php echo $texto; ?></span></a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
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
    <?php include __DIR__ . '/../../includes/menu_estado.php'; ?>

    <main class="main-content">
        <div class="dashboard-container">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                    <div>
                        <h1><?php echo htmlspecialchars($titulo); ?></h1>
                        <p><?php echo htmlspecialchars($subtitulo); ?></p>
                    </div>
                </div>
                <div class="topbar-right">
                    <div class="date-pill"><i class="far fa-calendar"></i><span id="fechaTexto"><?php echo date('d/m/Y, h:i a'); ?></span></div>
                    <?php estudiante_campana($GLOBALS['pdo']); ?>
                    <div class="profile-container">
                        <div class="profile-pill" id="profileBtn">
                            <?php echo avatar_estudiante(html_entity_decode($iniciales)); ?>
                            <div class="profile-info">
                                <strong><?php echo $nombre_pila; ?></strong>
                                <span>Matrícula: <?php echo htmlspecialchars($alumno['matricula']); ?></span>
                            </div>
                            <i class="fas fa-chevron-down" style="color:#94a3b8;margin-left:5px;font-size:.8rem;"></i>
                        </div>
                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="perfil.php" class="profile-dropdown-item"><i class="far fa-user-circle"></i> Mi Perfil</a>
                            <a href="cambiar_password.php" class="profile-dropdown-item"><i class="fas fa-key"></i> Cambiar contraseña</a>
                            <a href="../logout.php" class="profile-dropdown-item"><i class="fas fa-arrow-right-from-bracket"></i> Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </header>
    <?php
}

function estudiante_layout_fin(string $js_extra = ''): void {
    ?>
        </div>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay'),
                  mobileMenu = document.getElementById('mobileMenu'), collapseToggle = document.getElementById('collapseToggle'), body = document.body;
            if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 768) body.classList.add('sidebar-collapsed');
            if (collapseToggle) collapseToggle.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); });
            if (mobileMenu) mobileMenu.addEventListener('click', () => { sidebar.classList.add('mobile-open'); overlay.classList.add('visible'); body.classList.add('menu-open'); });
            if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('visible'); body.classList.remove('menu-open'); });
            const profileBtn = document.getElementById('profileBtn'), profileDropdown = document.getElementById('profileDropdown');
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('active'); });
                document.addEventListener('click', (e) => { if (!profileDropdown.contains(e.target)) profileDropdown.classList.remove('active'); });
            }
        });
        <?php echo $js_extra; ?>
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
    <?php
}
