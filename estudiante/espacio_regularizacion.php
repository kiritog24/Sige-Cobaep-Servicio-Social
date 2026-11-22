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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title><?php echo htmlspecialchars($clase['nombre_materia']); ?> · Panel Estudiante</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- CSS Base unificado -->
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">

    <style>
        /* ==============================================================
           CORE LAYOUT ESTUDIANTE (Estándar para todos los módulos)
        ============================================================== */
        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 80px;
            --bg-main: #f8fafc;
        }
        body { background-color: var(--bg-main); margin: 0; padding: 0; overflow: hidden; box-sizing: border-box; }
        *, *::before, *::after { box-sizing: border-box; }

        .main-content {
            margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width));
            padding: 1.2rem 2rem; height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease, width 0.3s ease;
        }
        body.sidebar-collapsed .main-content { margin-left: var(--sidebar-collapsed-width); width: calc(100% - var(--sidebar-collapsed-width)); }
        .dashboard-container { max-width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem; }

        /* HEADER Y PERFIL */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .topbar-left { display: flex; align-items: center; gap: 15px; }
        .topbar-left h1 { font-size: 1.4rem; font-weight: 800; color: #0f172a; margin: 0 0 0.1rem 0; letter-spacing: -0.5px; }
        .topbar-left p { margin: 0; color: #64748b; font-size: 0.85rem; }
        
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .date-pill { background: white; padding: 0.5rem 1rem; border-radius: 99px; font-size: 0.8rem; font-weight: 600; color: #475569; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; }
        .date-pill i { color: #059669; }

        .profile-container { position: relative; }
        .profile-pill { background: white; padding: 0.3rem 0.8rem 0.3rem 0.3rem; border-radius: 99px; display: flex; align-items: center; gap: 10px; border: 1px solid #e2e8f0; cursor: pointer; transition: background 0.2s; }
        .profile-pill:hover { background: #f8fafc; }
        .profile-pill .avatar { width: 34px; height: 34px; background: #063f2b; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; }
        .profile-info strong { display: block; font-size: 0.85rem; color: #0f172a; }
        .profile-info span { display: block; font-size: 0.7rem; color: #64748b; }
        
        .profile-dropdown { position: absolute; top: calc(100% + 10px); right: 0; background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); width: 220px; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.2s; z-index: 100; }
        .profile-dropdown.active { opacity: 1; visibility: visible; transform: translateY(0); }
        .profile-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: #475569; text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: all 0.2s; }
        .profile-dropdown-item i { width: 16px; text-align: center; }
        .profile-dropdown-item:first-child { border-radius: 12px 12px 0 0; }
        .profile-dropdown-item:last-child { border-radius: 0 0 12px 12px; border-top: 1px solid #f1f5f9; color: #ef4444; }
        .profile-dropdown-item:hover { background: #f8fafc; color: #0f172a; padding-left: 20px; }
        .mobile-menu { display: none; background: none; border: none; padding: 0; font-size: 1.6rem; color: #0f172a; cursor: pointer; }

        /* ==============================================================
           DISEÑO DEL ESPACIO DE REGULARIZACIÓN
        ============================================================== */
        .layout-espacio {
            display: grid;
            grid-template-columns: 2fr 1fr; /* Columna Izquierda Principal, Columna Derecha Entregas */
            grid-template-areas: "hero hero" "main side";
            gap: 1.5rem;
            align-items: start;
        }

        /* Hero Header (Portada de la Materia) */
        .hero-estudiante {
            grid-area: hero;
            height: 200px;
            border-radius: 16px;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 1.5rem 2rem;
            box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .btn-back { display: inline-flex; align-items: center; gap: 8px; color: #f8fafc; text-decoration: none; font-size: 0.85rem; font-weight: 600; background: rgba(0,0,0,0.4); padding: 6px 12px; border-radius: 50px; backdrop-filter: blur(4px); transition: 0.2s; width: max-content;}
        .btn-back:hover { background: rgba(0,0,0,0.6); }

        .hero-title { margin: 0.5rem 0; font-size: 2rem; font-weight: 800; color: white; line-height: 1.2; text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        .hero-desc { margin: 0; font-size: 0.95rem; color: #cbd5e1; max-width: 800px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-shadow: 0 1px 2px rgba(0,0,0,0.5); }
        
        .badge-profesor { background: rgba(16, 185, 129, 0.9); color: white; padding: 6px 14px; border-radius: 50px; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; backdrop-filter: blur(4px); position: absolute; bottom: 1.5rem; right: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }

        /* Columna Izquierda (Contenido y Bloques) */
        .col-main { grid-area: main; display: flex; flex-direction: column; gap: 1.5rem; }
        
        .section-title { font-size: 1.2rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
        .icon-green { color: #059669; }

        .empty-state-materiales { background: white; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 3rem 2rem; text-align: center; color: #64748b; font-weight: 500; font-size: 0.95rem; }
        .empty-state-materiales i { font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; display: block; }

        .bloque-box { background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); overflow: hidden; }
        .bloque-header { background: #f8fafc; padding: 1rem 1.5rem; font-weight: 700; color: #0f172a; border-bottom: 1px solid #e2e8f0; font-size: 1.05rem; display: flex; align-items: center; gap: 10px;}
        .bloque-body { padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; }

        .mat-item { display: flex; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #f1f5f9; }
        .mat-item:last-child { padding-bottom: 0; border-bottom: none; }
        .mat-icon { width: 40px; height: 40px; background: #ecfdf5; color: #10b981; border-radius: 10px; display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0; }
        
        .mat-info { flex: 1; }
        .mat-title { margin: 0 0 4px 0; font-size: 1rem; font-weight: 700; color: #0f172a; }
        .mat-desc { margin: 0 0 8px 0; font-size: 0.85rem; color: #64748b; line-height: 1.5; }
        .mat-filename { display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #475569; font-size: 0.75rem; padding: 4px 10px; border-radius: 6px; font-weight: 600; border: 1px solid #e2e8f0; }

        .btn-download { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid #cbd5e1; background: white; color: #0f172a; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; transition: 0.2s; height: max-content; margin-top: 5px;}
        .btn-download:hover { background: #f8fafc; border-color: #94a3b8; }

        /* Columna Derecha (Panel de Entregas) */
        .col-side { grid-area: side; }
        .side-panel { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .panel-title { margin: 0 0 1.5rem 0; font-size: 1.1rem; color: #0f172a; font-weight: 800; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;}
        
        .status-entrega { padding: 12px; border-radius: 8px; font-weight: 700; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; margin-bottom: 1rem; text-align: center; justify-content: center; }
        .status-entregado { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .status-faltante { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
        .status-bloqueado { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .entrega-date { font-size: 0.8rem; color: #64748b; text-align: center; margin-bottom: 1rem; font-weight: 600; }
        
        .form-group-upload { margin-bottom: 1rem; }
        .upload-label { display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 8px; }
        .input-file { width: 100%; border: 1px dashed #cbd5e1; padding: 10px; border-radius: 8px; font-size: 0.85rem; color: #64748b; background: #f8fafc; }
        
        .btn-subir { width: 100%; padding: 12px; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; border: none; }
        .btn-subir.primary { background: #059669; color: white; }
        /* Entrega por actividad */
        .act-entrega { margin-top: 10px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; background: #f8fafc; }
        .act-entrega.estado-entregada { border-color: #a7f3d0; background: #f0fdf4; }
        .act-entrega.estado-retardo { border-color: #fde68a; background: #fffbeb; }
        .act-entrega.estado-cerrada { border-color: #fecaca; background: #fef2f2; }
        .act-entrega.estado-cerrada .act-entrega-head strong { color: #b91c1c; }
        .act-entrega.estado-deshabilitada { opacity: .8; }
        .act-entrega-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; font-size: .82rem; color: #475569; }
        .act-entrega-head strong { color: #0f172a; }
        .estado-entregada .act-entrega-head strong { color: #047857; }
        .estado-retardo .act-entrega-head strong { color: #b45309; }
        .act-reglas { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
        .act-reglas span { font-size: .7rem; font-weight: 700; background: #fff; border: 1px solid #e2e8f0; color: #334155; padding: 3px 8px; border-radius: 20px; }
        .act-enviado { font-size: .8rem; color: #475569; margin: 6px 0; }
        .act-enviado a { color: #047857; font-weight: 700; }
        .tag-retardo { background: #fef3c7; color: #b45309; font-size: .68rem; font-weight: 800; padding: 2px 6px; border-radius: 6px; margin-left: 4px; }
        .act-calif { font-size: .85rem; color: #047857; margin: 6px 0; }
        .act-retro { font-size: .8rem; color: #334155; background: #fff; border-left: 3px solid #10b981; padding: 6px 10px; border-radius: 6px; margin: 6px 0; }
        .form-entrega { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
        a.mat-filename { text-decoration: none; }
        .resumen-entregas { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
        .resumen-entregas li { display: flex; justify-content: space-between; gap: 10px; font-size: .82rem; padding: 8px 10px; border-radius: 8px; border: 1px solid #e2e8f0; border-left-width: 4px; }
        .resumen-entregas li a { color: #0f172a; font-weight: 600; text-decoration: none; }
        .resumen-entregas li span { color: #64748b; font-weight: 700; white-space: nowrap; }
        .resumen-entregas li.estado-entregada { border-left-color: #10b981; }
        .resumen-entregas li.estado-abierta { border-left-color: #3b82f6; }
        .resumen-entregas li.estado-retardo { border-left-color: #f59e0b; }
        .resumen-entregas li.estado-cerrada { border-left-color: #dc2626; background: #fef2f2; }
        .resumen-entregas li.estado-deshabilitada { border-left-color: #94a3b8; }
        .btn-subir.primary:hover { background: #047857; }
        .btn-subir.outline { border: 1px solid #cbd5e1; background: white; color: #0f172a; }
        .btn-subir.outline:hover { background: #f8fafc; border-color: #94a3b8; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* ==============================================================
           RESPONSIVE MOBILE
        ============================================================== */
        @media (max-width: 900px) {
            .layout-espacio { grid-template-columns: 1fr; grid-template-areas: "hero" "side" "main"; }
        }
        @media (max-width: 768px) {
            body { overflow: auto; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 1rem; height: auto; overflow-y: visible; }
            .topbar { flex-direction: column; align-items: stretch; gap: 0.8rem; }
            .topbar-left { align-items: flex-start; }
            .topbar-left h1 { font-size: 1.25rem; }
            .mobile-menu { display: block; margin-top: 3px; }
            .topbar-right { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 100%; }
            .date-pill, .profile-pill { width: 100%; justify-content: center; padding: 0.5rem; }
            
            .hero-estudiante { padding: 1.25rem; height: auto; min-height: 180px; }
            .hero-title { font-size: 1.6rem; }
            .badge-profesor { position: static; margin-top: 1rem; width: max-content;}
            .mat-item { flex-direction: column; }
            .btn-download { width: 100%; }
        }
        @media (max-width: 400px) { .topbar-right { grid-template-columns: 1fr; } }
    </style>
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