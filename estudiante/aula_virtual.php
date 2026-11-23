<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/campana.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_alumno = $_SESSION['id_usuario'];
date_default_timezone_set('America/Mexico_City');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: actividades_escolares.php");
    exit();
}
$id_clase = (int)$_GET['id'];

$nombre_pila = 'Alumno';
$apellido_p = '';
$nombre_completo = 'Alumno';
$iniciales = 'AL';
$matricula = 'Sin Matrícula';

try {
    // 1. Obtener datos del alumno
    $stmt_alumno = $pdo->prepare("SELECT nombre, apellido_paterno, matricula FROM alumnos WHERE id_alumno = ?");
    $stmt_alumno->execute([$id_alumno]);
    $datos_alumno = $stmt_alumno->fetch(PDO::FETCH_ASSOC);

    if ($datos_alumno) {
        $nombre_pila = htmlspecialchars($datos_alumno['nombre']);
        $apellido_p = htmlspecialchars($datos_alumno['apellido_paterno']);
        $nombre_completo = $nombre_pila . ' ' . $apellido_p;
        $iniciales = mb_substr($nombre_pila, 0, 1) . mb_substr($apellido_p, 0, 1);
        $matricula = htmlspecialchars($datos_alumno['matricula']);
    }

    // 2. Verificar acceso a la clase
    $stmt_clase = $pdo->prepare("
        SELECT c.id_clase, c.enlace_clase_virtual, m.nombre_materia, m.imagen_portada, 
               prof.nombre AS maestro_nombre, prof.apellido_paterno AS maestro_apellido 
        FROM clase_regular_alumno ca
        INNER JOIN clases_regulares c ON ca.id_clase = c.id_clase
        INNER JOIN materias m ON c.id_materia = m.id_materia
        LEFT JOIN maestros prof ON c.id_maestro = prof.id_maestro
        WHERE ca.id_alumno = ? AND c.id_clase = ? AND ca.acceso = 1
    ");
    $stmt_clase->execute([$id_alumno, $id_clase]);
    $clase = $stmt_clase->fetch(PDO::FETCH_ASSOC);

    if (!$clase) {
        header("Location: actividades_escolares.php?error=El profesor ha restringido el acceso a esta clase.");
        exit();
    }

    // 3. Actividades que este alumno puede ver: publicadas, ya abiertas (fecha de apertura cumplida),
    //    y sin bloqueo individual ni del filtro de asistencia
    asegurar_columnas_actividades($pdo);
    $stmt_act = $pdo->prepare("
        SELECT ac.*, va.fecha_limite_excepcion
        FROM actividades_clase ac
        LEFT JOIN visibilidad_actividad va ON ac.id_actividad = va.id_actividad AND va.id_alumno = ?
        WHERE ac.id_clase = ? AND ac.visible = 1 AND (ac.fecha_apertura IS NULL OR ac.fecha_apertura <= ?)
          AND " . sql_actividad_visible((int)$id_alumno) . "
        ORDER BY COALESCE(ac.fecha_apertura, ac.fecha_publicacion) DESC
    ");
    $stmt_act->execute([$id_alumno, $id_clase, date('Y-m-d H:i:s')]);
    $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

    // 4. Obtener Entregas (con la calificación y los comentarios del docente)
    $stmt_entregas = $pdo->prepare("SELECT id_entrega, id_actividad, fecha_entrega, estatus_entrega, archivo_ruta, retroalimentacion, calificacion, revisada, fecha_revision FROM entregas_actividad WHERE id_alumno = ?");
    $stmt_entregas->execute([$id_alumno]);
    $filas_entregas = $stmt_entregas->fetchAll(PDO::FETCH_ASSOC);
    $archivos_entregas = archivos_de_entregas($pdo, $filas_entregas);   // una entrega puede tener varios archivos
    $mis_entregas = [];
    foreach ($filas_entregas as $row) {
        $row['archivos'] = $archivos_entregas[(int)$row['id_entrega']] ?? [];
        $mis_entregas[$row['id_actividad']] = $row;
    }

    /** Selector de archivos de una entrega: uno o varios, con las reglas de la actividad. */
    function campo_archivos_entrega(array $act): void {
        $n = numero_archivos_valido($act['numero_archivos'] ?? 1);
        $mb = tamano_archivo_valido($act['tamano_maximo'] ?? 10);
        $acc = tipos_archivo_accept($act['tipo_archivo'] ?? '*');
        $lim = limites_servidor(); ?>
        <div class="selector-archivos" data-max-n="<?php echo $n; ?>" data-max-mb="<?php echo $mb; ?>" data-total-mb="<?php echo $lim['total_mb']; ?>">
            <label class="sel-boton">
                <i class="fas fa-cloud-arrow-up"></i>
                <span><?php echo $n > 1 ? "Elegir archivos (hasta $n)" : 'Elegir archivo'; ?></span>
                <input type="file" name="archivo_tarea[]"<?php echo $n > 1 ? ' multiple' : ''; ?><?php if ($acc): ?> accept="<?php echo htmlspecialchars($acc); ?>"<?php endif; ?>>
            </label>
            <ul class="sel-lista"></ul>
            <p class="sel-ayuda"><?php echo $n > 1 ? "Puedes subir hasta <b>$n archivos</b>" : 'Sube <b>1 archivo</b>'; ?> de máximo <b><?php echo $mb; ?> MB</b> cada uno<?php echo $n > 1 ? " (puedes elegirlos en varias veces)" : ''; ?>. Tipos: <?php echo htmlspecialchars(tipos_archivo_texto($act['tipo_archivo'] ?? '*')); ?>.</p>
        </div>
    <?php }

} catch (PDOException $e) {
    header("Location: actividades_escolares.php?error=Error BD: " . urlencode($e->getMessage()));
    exit();
}

$asistencias_clase = 0; $faltas_clase = 0;
try {
    $stmt_asist = $pdo->prepare("SELECT estatus, COUNT(*) as total FROM asistencias_clases WHERE id_alumno = ? AND id_clase = ? GROUP BY estatus");
    $stmt_asist->execute([$id_alumno, $id_clase]);
    while($row = $stmt_asist->fetch(PDO::FETCH_ASSOC)) {
        $estatus = strtolower(trim($row['estatus']));
        if ($estatus == 'presente' || $estatus == 'asistencia' || $estatus == 'retardo') $asistencias_clase += $row['total'];
        else if ($estatus == 'falta' || $estatus == 'ausente') $faltas_clase += $row['total'];
    }
} catch (PDOException $e) {}

$bg_image = "linear-gradient(145deg, #0f172a, #063f2b)";
if (!empty($clase['imagen_portada'])) {
    $ruta_img = '../' . $clase['imagen_portada'];
    $bg_image = "linear-gradient(rgba(15, 23, 42, 0.6), rgba(15, 23, 42, 0.85)), url('" . htmlspecialchars($ruta_img) . "')";
}
$clase_en_vivo_activa = !empty($clase['enlace_clase_virtual']);
$fecha_actual = new DateTime();
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
    <title><?php echo htmlspecialchars($clase['nombre_materia']); ?> · Aula Virtual</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/aula_virtual.css?v=3">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>

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
                <a href="dashboard_estud.php" class="nav-item"><i class="fas fa-home"></i> <span>Inicio</span></a>
                <a href="mis_asistencias.php" class="nav-item"><i class="far fa-calendar-check"></i> <span>Mis Asistencias</span></a>
                <a href="horario_clases.php" class="nav-item"><i class="far fa-calendar-alt"></i> <span>Horario de Clases</span></a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i> <span>Horarios del Plantel</span></a>

                <span class="nav-heading">Académico</span>
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
                    <button class="mobile-menu" id="mobileMenu"><i class="fas fa-bars"></i></button>
                    <div class="header-icon"><i class="fas fa-laptop-code"></i></div>
                    <div>
                        <h1>Aula Virtual</h1>
                        <p>Trabajos, material de apoyo y seguimiento.</p>
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

            <div class="layout-aula">
                
                <div class="hero-materia" style="background-image: <?php echo $bg_image; ?>;">
                    <a href="actividades_escolares.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver a Mis Materias</a>
                    <div>
                        <h1 class="hero-title"><?php echo htmlspecialchars($clase['nombre_materia']); ?></h1>
                        <div class="hero-prof"><i class="fas fa-chalkboard-user"></i> Prof. <?php echo htmlspecialchars($clase['maestro_nombre'] . ' ' . $clase['maestro_apellido']); ?></div>
                    </div>
                </div>

                <div class="col-main">
                    <?php if (isset($_GET['exito'])): ?>
                        <div style="background: #ecfdf5; color: #065f46; padding: 12px; border-radius: 8px; border: 1px solid #a7f3d0; font-weight: 600;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['exito']); ?></div>
                    <?php endif; ?>
                    <?php if (isset($_GET['error'])): ?>
                        <div style="background: #fef2f2; color: #991b1b; padding: 12px; border-radius: 8px; border: 1px solid #fecaca; font-weight: 600;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
                    <?php endif; ?>

                    <h2 class="section-title"><i class="fas fa-tasks"></i> Tareas y Material de Apoyo</h2>

                    <?php if (empty($actividades)): ?>
                        <div style="text-align:center; padding: 3rem; background:white; border-radius:12px; border: 1px dashed #cbd5e1; color:#64748b;">
                            <i class="fas fa-folder-open" style="font-size:3rem; color:#cbd5e1; margin-bottom:10px;"></i>
                            <p>El profesor aún no ha publicado actividades en esta materia.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($actividades as $act): 
                            $es_tarea = ($act['permite_entregas'] == 1);
                            $icon_class = $es_tarea ? 'bg-tarea' : 'bg-material';
                            $icon_fa = $es_tarea ? 'fa-file-signature' : 'fa-file-pdf';
                            
                            $entregado = isset($mis_entregas[$act['id_actividad']]);
                            $info_entrega = $entregado ? $mis_entregas[$act['id_actividad']] : null;
                            
                            $fecha_a_usar = !empty($act['fecha_limite_excepcion']) ? $act['fecha_limite_excepcion'] : $act['fecha_limite'];
                            $tiene_limite = !empty($fecha_a_usar);
                            $vencida = false;
                            
                            if($tiene_limite) {
                                $fecha_limite_dt = new DateTime($fecha_a_usar);
                                $vencida = ($fecha_actual > $fecha_limite_dt);
                            }
                            $acepta_retardo = ($act['acepta_retardo'] == 1);
                            $bloqueo_fecha = ($vencida && !$acepta_retardo);
                        ?>
                            <div class="actividad-card">
                                <div class="act-icon <?php echo $icon_class; ?>"><i class="fas <?php echo $icon_fa; ?>"></i></div>
                                
                                <div class="act-content">
                                    <h4 class="act-title"><?php echo htmlspecialchars($act['titulo']); ?></h4>
                                    <p class="act-desc"><?php echo nl2br(htmlspecialchars($act['descripcion'])); ?></p>
                                    
                                    <div class="act-meta">
                                        <span class="meta-tag"><i class="far fa-clock"></i> Publicado: <?php echo date('d/m/Y', strtotime($act['fecha_apertura'] ?: $act['fecha_publicacion'])); ?></span>
                                        <?php if ($es_tarea): ?>
                                            <?php $n_arch = numero_archivos_valido($act['numero_archivos'] ?? 1); ?>
                                            <span class="meta-tag"><i class="fas fa-paperclip"></i> <?php echo $n_arch > 1 ? "Hasta $n_arch archivos" : '1 archivo'; ?> · <?php echo htmlspecialchars(tipos_archivo_texto($act['tipo_archivo'] ?? '*')); ?> · máx. <?php echo tamano_archivo_valido($act['tamano_maximo'] ?? 10); ?> MB c/u</span>
                                        <?php endif; ?>
                                        <?php if($tiene_limite): ?>
                                            <?php if(!empty($act['fecha_limite_excepcion'])): ?>
                                                 <span class="meta-tag tag-green"><i class="fas fa-calendar-check"></i> Extensión: <?php echo date('d M Y, h:i a', strtotime($fecha_a_usar)); ?></span>
                                            <?php elseif($vencida && !$entregado): ?>
                                                <span class="meta-tag tag-red"><i class="fas fa-exclamation-circle"></i> Vencida: <?php echo date('d M Y, h:i a', strtotime($fecha_a_usar)); ?></span>
                                            <?php else: ?>
                                                <span class="meta-tag tag-orange"><i class="far fa-calendar-times"></i> Límite: <?php echo date('d M Y, h:i a', strtotime($fecha_a_usar)); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if(!empty($act['archivo_ruta'])): 
                                        $archivo_bd = basename($act['archivo_ruta']);
                                        $nombre_material_limpio = preg_replace('/^mat_.*?_/', '', $archivo_bd);
                                    ?>
                                        <div style="margin-bottom: 15px;">
                                            <a href="../uploads/materiales_clase/<?php echo htmlspecialchars($archivo_bd); ?>" download="<?php echo htmlspecialchars($nombre_material_limpio); ?>" target="_blank" class="btn-action btn-outline">
                                                <i class="fas fa-download"></i> <?php echo htmlspecialchars($nombre_material_limpio); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <?php if($es_tarea): ?>
                                        <div class="box-entrega">
                                            <h5><i class="fas fa-user-graduate"></i> Sección de Entrega</h5>

                                            <?php if($entregado): ?>
                                                <div style="display:flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
                                                    <?php if($info_entrega['estatus_entrega'] === 'Con Retardo'): ?>
                                                        <span class="status-entregado status-rojo" style="margin:0;"><i class="fas fa-clock"></i> Entregada (Retardo)</span>
                                                    <?php else: ?>
                                                        <span class="status-entregado status-verde" style="margin:0;"><i class="fas fa-check-double"></i> Entregada a Tiempo</span>
                                                    <?php endif; ?>
                                                    
                                                </div>
                                                <?php if (!empty($info_entrega['archivos'])): ?>
                                                    <div class="mis-archivos">
                                                        <span class="mis-archivos-tit"><i class="fas fa-paperclip"></i> <?php echo count($info_entrega['archivos']) === 1 ? 'Mi archivo' : 'Mis archivos (' . count($info_entrega['archivos']) . ')'; ?></span>
                                                        <?php foreach ($info_entrega['archivos'] as $arch): ?>
                                                            <a href="../uploads/entregas_alumnos/<?php echo htmlspecialchars(rawurlencode($arch['ruta'])); ?>" download="<?php echo htmlspecialchars($arch['nombre']); ?>" target="_blank" class="archivo-chip">
                                                                <i class="fas fa-file-arrow-down"></i> <span><?php echo htmlspecialchars($arch['nombre']); ?></span>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                </div>

                                                <?php $revisada_ent = (int)($info_entrega['revisada'] ?? 0) === 1; ?>
                                                <?php if ($revisada_ent || $info_entrega['calificacion'] !== null || !empty($info_entrega['retroalimentacion'])): ?>
                                                    <div class="eval-docente">
                                                        <div class="eval-cab">
                                                            <strong><i class="fas fa-clipboard-check"></i> Revisión del profesor</strong>
                                                            <?php if ($info_entrega['calificacion'] !== null): ?>
                                                                <span class="eval-calif <?php echo (float)$info_entrega['calificacion'] >= 6 ? 'ok' : 'baja'; ?>">Calificación: <b><?php echo calificacion_texto($info_entrega['calificacion']); ?></b> / 10</span>
                                                            <?php elseif ($revisada_ent): ?>
                                                                <span class="eval-calif ok">Revisada</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($info_entrega['retroalimentacion'])): ?>
                                                            <p class="eval-coment"><i class="fas fa-comment-dots"></i> <?php echo nl2br(htmlspecialchars($info_entrega['retroalimentacion'])); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($info_entrega['fecha_revision'])): ?>
                                                            <small>Revisada el <?php echo date('d/m/Y, H:i', strtotime($info_entrega['fecha_revision'])); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="eval-espera"><i class="fas fa-hourglass-half"></i> Tu profesor aún no revisa esta entrega.</div>
                                                <?php endif; ?>

                                                <?php if ($revisada_ent): ?>
                                                     <div class="eval-bloqueo"><i class="fas fa-lock"></i> Tu profesor ya revisó esta entrega, por eso ya no puedes cambiarla ni quitarla. Si necesitas corregir algo, habla con tu docente.</div>
                                                <?php elseif (!$vencida || $acepta_retardo): ?>
                                                    <div id="acciones-entrega-<?php echo $act['id_actividad']; ?>" style="margin-top: 15px; border-top: 1px dashed #cbd5e1; padding-top: 15px; display: flex; gap: 10px;">
                                                        <button type="button" class="btn-action btn-outline" onclick="document.getElementById('form-editar-<?php echo $act['id_actividad']; ?>').style.display = 'block'; document.getElementById('acciones-entrega-<?php echo $act['id_actividad']; ?>').style.display = 'none';">
                                                            <i class="fas fa-edit"></i> Editar envío
                                                        </button>
                                                        
                                                        <form action="procesos/eliminar_entrega.php" method="POST" onsubmit="return confirm('¿Estás seguro de que deseas quitar tu envío? Esta acción no se puede deshacer.');" style="margin:0;">
                                                            <input type="hidden" name="id_actividad" value="<?php echo $act['id_actividad']; ?>">
                                                            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
                                                            <button type="submit" class="btn-action" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;">
                                                                <i class="fas fa-trash-alt"></i> Quitar envío
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <div id="form-editar-<?php echo $act['id_actividad']; ?>" style="display: none; margin-top: 10px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #cbd5e1;">
                                                        <p style="font-size: 0.85rem; color: #64748b; margin: 0 0 10px 0;"><i class="fas fa-info-circle"></i> Sube de nuevo tu entrega. Los archivos que elijas reemplazan a <b>todos</b> los que enviaste antes.</p>
                                                        <form action="procesos/editar_entrega.php?id_clase=<?php echo (int)$id_clase; ?>" method="POST" enctype="multipart/form-data">
                                                            <input type="hidden" name="id_actividad" value="<?php echo $act['id_actividad']; ?>">
                                                            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
                                                            
                                                            <?php if($vencida && $acepta_retardo): ?>
                                                                <div style="color: #dc2626; font-size: 0.8rem; font-weight: 700; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Nota: Esta tarea está vencida. La nueva edición se registrará como "Entrega con retardo".</div>
                                                            <?php endif; ?>
                                                            
                                                            <div class="fila-entrega">
                                                                <?php campo_archivos_entrega($act); ?>
                                                                <button type="submit" class="btn-action btn-primary" style="padding: 8px 16px;">Guardar cambios</button>
                                                                <button type="button" class="btn-action btn-outline" onclick="document.getElementById('form-editar-<?php echo $act['id_actividad']; ?>').style.display = 'none'; document.getElementById('acciones-entrega-<?php echo $act['id_actividad']; ?>').style.display = 'flex';">Cancelar</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                     <div style="margin-top: 15px; color: #64748b; font-size: 0.8rem; border-top: 1px dashed #cbd5e1; padding-top: 15px;">
                                                         <i class="fas fa-lock"></i> La fecha límite ha pasado. Ya no puedes modificar ni quitar tu envío.
                                                     </div>
                                                <?php endif; ?>

                                            <?php else: ?>
                                                <?php if($bloqueo_fecha): ?>
                                                    <div style="color: #991b1b; background:#fef2f2; border:1px solid #fecaca; padding:10px; border-radius:6px; font-size: 0.85rem; font-weight: 600;">
                                                        <i class="fas fa-lock"></i> La fecha límite ha pasado y esta tarea no acepta entregas con retardo.
                                                    </div>
                                                <?php else: ?>
                                                    <form action="procesos/subir_tarea_clase.php?id_clase=<?php echo (int)$id_clase; ?>" method="POST" enctype="multipart/form-data">
                                                        <input type="hidden" name="id_actividad" value="<?php echo $act['id_actividad']; ?>">
                                                        <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
                                                        
                                                        <?php if($vencida && $acepta_retardo): ?>
                                                            <div style="color: #dc2626; font-size: 0.8rem; font-weight: 700; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Nota: Esta tarea está vencida. Se registrará como "Entrega con retardo".</div>
                                                        <?php endif; ?>

                                                        <div class="fila-entrega">
                                                            <?php campo_archivos_entrega($act); ?>
                                                            <button type="submit" class="btn-action btn-primary" style="padding: 8px 16px;">Confirmar Envío</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- COLUMNA LATERAL: clase en vivo, asistencia, horario y progreso -->
                <?php
                $horario_materia = [];
                try {
                    $sth = $pdo->prepare("SELECT dia_semana, hora_inicio, hora_fin FROM HORARIOS_CLASES WHERE id_clase = ? ORDER BY dia_semana, hora_inicio");
                    $sth->execute([$id_clase]);
                    $horario_materia = $sth->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) { error_log('Horario aula alumno: ' . $e->getMessage()); }
                $dias_semana_txt = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
                $total_act = count($actividades);
                $con_entrega = 0; $entregadas = 0;
                foreach ($actividades as $a) {
                    if ((int)($a['permite_entregas'] ?? 0) === 1) {
                        $con_entrega++;
                        if (isset($mis_entregas[$a['id_actividad']])) $entregadas++;
                    }
                }
                $enlace_vivo = $clase['enlace_clase_virtual'] ?? '';
                $enlace_valido = $clase_en_vivo_activa && preg_match('#^https?://#i', $enlace_vivo);
                ?>
                <div class="col-side">
                    <div class="side-widget">
                        <h3 class="widget-title"><i class="fas fa-video" style="color:#dc2626;"></i> Clase en vivo</h3>
                        <?php if ($enlace_valido): ?>
                            <a href="<?php echo htmlspecialchars($enlace_vivo); ?>" target="_blank" rel="noopener noreferrer" class="btn-live-class">
                                <i class="fas fa-circle-dot"></i>
                                <strong>Unirme a la clase</strong>
                                <span>Tu docente abrió la sesión en línea</span>
                            </a>
                        <?php else: ?>
                            <div style="text-align:center; color:#64748b; font-size:.85rem; padding:1rem; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:12px;">
                                <i class="fas fa-video-slash" style="font-size:1.6rem; color:#94a3b8; display:block; margin-bottom:6px;"></i>
                                No hay una clase en línea activa en este momento.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="side-widget">
                        <h3 class="widget-title"><i class="fas fa-clipboard-check" style="color:#059669;"></i> Mi asistencia en la materia</h3>
                        <div class="attendance-stats">
                            <div class="att-box att-green"><strong><?php echo (int)$asistencias_clase; ?></strong><span>Asistencias</span></div>
                            <div class="att-box att-red"><strong><?php echo (int)$faltas_clase; ?></strong><span>Faltas</span></div>
                        </div>
                    </div>

                    <div class="side-widget">
                        <h3 class="widget-title"><i class="far fa-calendar-alt" style="color:#2563eb;"></i> Horario de la materia</h3>
                        <?php if (!$horario_materia): ?>
                            <p style="margin:0; color:#64748b; font-size:.85rem;">Tu docente aún no ha capturado el horario.</p>
                        <?php else: foreach ($horario_materia as $hm): ?>
                            <div style="display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:.86rem;">
                                <strong style="color:#0f172a;"><?php echo $dias_semana_txt[(int)$hm['dia_semana']] ?? ''; ?></strong>
                                <span style="color:#475569;"><?php echo date('h:i a', strtotime($hm['hora_inicio'])); ?> – <?php echo date('h:i a', strtotime($hm['hora_fin'])); ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <div class="side-widget">
                        <h3 class="widget-title"><i class="fas fa-list-check" style="color:#7c3aed;"></i> Mi progreso</h3>
                        <p style="margin:0 0 8px; font-size:.85rem; color:#475569;"><?php echo $total_act; ?> actividad(es) publicadas · <?php echo $entregadas; ?> de <?php echo $con_entrega; ?> entregas realizadas</p>
                        <div style="height:10px; background:#f1f5f9; border-radius:99px; overflow:hidden;">
                            <div style="height:100%; width:<?php echo $con_entrega ? round($entregadas / $con_entrega * 100) : 0; ?>%; background:linear-gradient(90deg,#10b981,#059669); border-radius:99px;"></div>
                        </div>
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
            if (collapseToggle) collapseToggle.addEventListener('click', () => {
                body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
            });
            if (mobileMenu && sidebar) mobileMenu.addEventListener('click', () => { sidebar.classList.add('mobile-open'); overlay && overlay.classList.add('visible'); body.classList.add('menu-open'); });
            if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('visible'); body.classList.remove('menu-open'); });

            // Menú desplegable del perfil
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('active'); });
                document.addEventListener('click', e => {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) profileDropdown.classList.remove('active');
                });
            }

            // Reloj
            const fechaTexto = document.getElementById('fechaTexto');
            function actualizarHora() {
                if (!fechaTexto) return;
                fechaTexto.textContent = new Date().toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            }
            actualizarHora(); setInterval(actualizarHora, 60000);
        });
    </script>
    <script>
        // Selector de archivos: acumula, muestra la lista y revisa cantidad, tipo y peso antes de enviar
        (function () {
            const puedeAcumular = (() => { try { return !!new DataTransfer(); } catch (e) { return false; } })();
            const mbTxt = b => b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB';
            document.querySelectorAll('.selector-archivos').forEach(box => {
                const input = box.querySelector('input[type="file"]');
                const lista = box.querySelector('.sel-lista');
                const maxN = +box.dataset.maxN, maxMb = +box.dataset.maxMb, totalMb = +box.dataset.totalMb;
                const acc = (input.getAttribute('accept') || '').split(',').filter(Boolean);
                let elegidos = [];
                const problema = f => {
                    const ext = '.' + (f.name.split('.').pop() || '').toLowerCase();
                    if (acc.length && !acc.includes(ext)) return 'Tipo no permitido (' + ext + ')';
                    if (f.size > maxMb * 1048576) return 'Pesa más de ' + maxMb + ' MB';
                    return '';
                };
                const pintar = () => {
                    lista.innerHTML = '';
                    elegidos.forEach((f, i) => {
                        const li = document.createElement('li');
                        const err = problema(f);
                        if (err) li.className = 'con-error';
                        li.innerHTML = '<i class="fas ' + (err ? 'fa-circle-exclamation' : 'fa-file') + '"></i><span class="sel-nombre"></span><small></small>';
                        li.querySelector('.sel-nombre').textContent = f.name;
                        li.querySelector('small').textContent = err || mbTxt(f.size);
                        if (puedeAcumular) {
                            const x = document.createElement('button');
                            x.type = 'button'; x.className = 'sel-quitar'; x.title = 'Quitar'; x.innerHTML = '<i class="fas fa-xmark"></i>';
                            x.onclick = () => { elegidos.splice(i, 1); sincronizar(); };
                            li.appendChild(x);
                        }
                        lista.appendChild(li);
                    });
                    box.querySelector('.sel-boton span').textContent = elegidos.length
                        ? (maxN > 1 ? 'Agregar más (' + elegidos.length + ' de ' + maxN + ')' : 'Cambiar archivo')
                        : (maxN > 1 ? 'Elegir archivos (hasta ' + maxN + ')' : 'Elegir archivo');
                };
                const sincronizar = () => {
                    if (puedeAcumular) { const dt = new DataTransfer(); elegidos.forEach(f => dt.items.add(f)); input.files = dt.files; }
                    pintar();
                };
                input.addEventListener('change', () => {
                    const nuevos = [...input.files];
                    if (puedeAcumular && maxN > 1) {
                        nuevos.forEach(f => { if (!elegidos.some(e => e.name === f.name && e.size === f.size)) elegidos.push(f); });
                        if (elegidos.length > maxN) { alert('Esta actividad acepta máximo ' + maxN + ' archivos. Se quedaron los primeros ' + maxN + '.'); elegidos = elegidos.slice(0, maxN); }
                    } else {
                        elegidos = nuevos;
                    }
                    sincronizar();
                });
                box.closest('form').addEventListener('submit', e => {
                    let msg = '';
                    if (!elegidos.length) msg = 'Elige al menos un archivo.';
                    else if (elegidos.length > maxN) msg = 'Esta actividad acepta máximo ' + maxN + ' archivos.';
                    else if (elegidos.some(problema)) msg = 'Hay archivos marcados en rojo que no se pueden enviar. Quítalos o cámbialos.';
                    else if (elegidos.reduce((t, f) => t + f.size, 0) > totalMb * 1048576) msg = 'Entre todos tus archivos pesan más de ' + totalMb + ' MB, lo máximo que acepta el servidor en un envío.';
                    if (msg) { e.preventDefault(); alert(msg); return; }
                    const btn = box.closest('form').querySelector('button[type="submit"]');
                    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }
                });
            });
        })();
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
