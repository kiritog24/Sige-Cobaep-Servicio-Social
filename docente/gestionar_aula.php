<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/topbar_docente.php';
require_once __DIR__ . '/../includes/aulas.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

$id_docente = $_SESSION['id_usuario'];
$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Docente';
date_default_timezone_set('America/Mexico_City');
$partes_nombre = explode(" ", $nombre_usuario);
$iniciales = strtoupper(substr($partes_nombre[0], 0, 1) . (isset($partes_nombre[1]) ? substr($partes_nombre[1], 0, 1) : ''));

$id_clase = isset($_GET['id_clase']) ? (int)$_GET['id_clase'] : 0;
$id_grupo = isset($_GET['id_grupo']) ? (int)$_GET['id_grupo'] : 0;

if ($id_clase === 0 && $id_grupo === 0) {
    header("Location: mis_grupos.php?error=No se especificó el aula.");
    exit();
}

$vista_actual = ($id_clase > 0) ? 'materia' : 'grupo';

$info_cabecera = [];
$lista_alumnos = [];
$actividades = [];
$materias_del_grupo = [];
$materias_inactivas = [];
$link_actual = "";
$total_alumnos = 0;
$alumnos_activos_count = 0;

try {
    if ($vista_actual === 'materia') {
        $stmt = $pdo->prepare("SELECT c.*, m.nombre_materia, g.nombre_grupo, g.semestre, g.id_grupo FROM clases_regulares c INNER JOIN materias m ON c.id_materia = m.id_materia LEFT JOIN grupos g ON c.id_grupo = g.id_grupo WHERE c.id_clase = ? AND c.id_maestro = ?");
        $stmt->execute([$id_clase, $id_docente]);
        $info_cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$info_cabecera) { header("Location: mis_grupos.php?error=Acceso denegado."); exit(); }
        $link_actual = $info_cabecera['enlace_clase_virtual'] ?? '';
        sincronizar_aulas_grupo($pdo, (int)$info_cabecera['id_grupo']);   // alumnos nuevos o dados de baja del grupo

        $stmtAlumnos = $pdo->prepare("SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno, a.fotografia, cra.acceso FROM clase_regular_alumno cra INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno WHERE cra.id_clase = ? ORDER BY a.apellido_paterno ASC");
        $stmtAlumnos->execute([$id_clase]);
        $lista_alumnos = $stmtAlumnos->fetchAll(PDO::FETCH_ASSOC);
        
        $total_alumnos = count($lista_alumnos);
        foreach($lista_alumnos as $al) { if($al['acceso'] == 1) $alumnos_activos_count++; }

        $stmtAct = $pdo->prepare("
            SELECT ac.*, 
                   (SELECT COUNT(*) FROM entregas_actividad ea WHERE ea.id_actividad = ac.id_actividad) AS total_entregas
            FROM actividades_clase ac 
            WHERE ac.id_clase = ? 
            ORDER BY ac.fecha_publicacion DESC
        ");
        $stmtAct->execute([$id_clase]);
        $actividades = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

        // Extraer los horarios de esta materia específica
        $stmtHorarios = $pdo->prepare("SELECT * FROM horarios_clases WHERE id_clase = ? ORDER BY dia_semana ASC, hora_inicio ASC");
        $stmtHorarios->execute([$id_clase]);
        $horarios_materia = $stmtHorarios->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $stmt = $pdo->prepare("SELECT id_grupo, nombre_grupo, semestre FROM grupos WHERE id_grupo = ?");
        $stmt->execute([$id_grupo]);
        $info_cabecera = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$info_cabecera) { header("Location: mis_grupos.php?error=Grupo no encontrado."); exit(); }
        if (!docente_tiene_grupo($pdo, (int)$id_docente, $id_grupo)) { header("Location: mis_grupos.php?error=" . urlencode('Ese grupo no está asignado a ti.')); exit(); }
        sincronizar_aulas_grupo($pdo, $id_grupo);

        // Materias Activas (AHORA TRAE EL id_materia Y LA imagen_portada)
        $stmtMat = $pdo->prepare("SELECT c.id_clase, m.id_materia, m.nombre_materia, m.imagen_portada, c.enlace_clase_virtual FROM clases_regulares c INNER JOIN materias m ON c.id_materia = m.id_materia WHERE c.id_grupo = ? AND c.id_maestro = ? AND c.estatus = 'Activa'");
        $stmtMat->execute([$id_grupo, $id_docente]);
        $materias_del_grupo = $stmtMat->fetchAll(PDO::FETCH_ASSOC);

        // Materias Inactivas (Papelera)
        $stmtMatInactivas = $pdo->prepare("SELECT c.id_clase, m.nombre_materia FROM clases_regulares c INNER JOIN materias m ON c.id_materia = m.id_materia WHERE c.id_grupo = ? AND c.id_maestro = ? AND c.estatus = 'Inactiva'");
        $stmtMatInactivas->execute([$id_grupo, $id_docente]);
        $materias_inactivas = $stmtMatInactivas->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Aula · Portal Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_docente.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/gestionar_aula.css?v=1">
    <link rel="stylesheet" href="../css/asistencia.css?v=2">
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
                <a href="dashboard_docente.php" class="nav-item">
                    <i class="fas fa-house"></i><span>Inicio</span>
                </a>
                <span class="nav-heading">Académico</span>
                <a href="mis_grupos.php" class="nav-item active">
                    <i class="fas fa-laptop-code"></i><span>Mis Aulas (Grupos)</span>
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
                    <span class="eyebrow">AULA VIRTUAL</span>
                    <h1>Panel de Control</h1>
                </div>
            </div>
            <?php docente_topbar($pdo); ?>
        </header>

        <div class="header-aula">
            <div>
                <?php if ($vista_actual === 'grupo'): ?>
                    <h2>Grupo <?php echo htmlspecialchars($info_cabecera['semestre']) . '° "' . htmlspecialchars($info_cabecera['nombre_grupo']) . '"'; ?></h2>
                    <p><i class="fas fa-folder-open"></i> Gestión de Materias</p>
                <?php else: ?>
                    <h2><?php echo htmlspecialchars($info_cabecera['nombre_materia']); ?></h2>
                    <p><i class="fas fa-layer-group"></i> Grupo <?php echo htmlspecialchars($info_cabecera['semestre']) . '° "' . htmlspecialchars($info_cabecera['nombre_grupo']) . '"'; ?></p>
                <?php endif; ?>
            </div>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if ($vista_actual === 'materia'): ?>
                    <a href="lista_alumnos.php?id_clase=<?php echo $id_clase; ?>" class="btn-volver" style="background: rgba(255,255,255,0.25);"><i class="fas fa-users"></i> Ver Alumnos</a>
                <?php endif; ?>
                <a href="<?php echo ($vista_actual === 'grupo') ? 'mis_grupos.php' : 'gestionar_aula.php?id_grupo='.$info_cabecera['id_grupo']; ?>" class="btn-volver"><i class="fas fa-arrow-left"></i> <?php echo ($vista_actual === 'grupo') ? 'Regresar a Mis Grupos' : 'Volver a Materias'; ?></a>
            </div>
        </div>

        <?php if(isset($_GET['exito'])): ?>
            <div style="background: #ecfdf5; color: #065f46; padding: 12px 16px; border-radius: 12px; border: 1px solid #a7f3d0; margin-bottom:1.5rem; font-weight:600;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['exito']); ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div style="background: #fef2f2; color: #991b1b; padding: 12px 16px; border-radius: 12px; border: 1px solid #fecaca; margin-bottom:1.5rem; font-weight:600;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <?php if ($vista_actual === 'grupo'): ?>
           <!-- NUEVO FORMULARIO DE CREACIÓN DE MATERIA (MÁS LIMPIO) -->
            <div class="add-materia-bar" style="display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 20px 25px; background: #ffffff; border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow-sm); margin-bottom: 2.5rem;">
                <div class="add-materia-info" style="display: flex; align-items: center; gap: 15px; flex: 1;">
                    <div class="icon-box" style="width: 45px; height: 45px; background: var(--orange-soft); color: var(--orange); border-radius: 12px; display: grid; place-items: center; font-size: 1.2rem; flex-shrink: 0;"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <h4 style="margin: 0 0 4px 0; color: var(--ink); font-size: 1.1rem; font-weight: 800;">Activar Nueva Materia</h4>
                        <p style="margin: 0; color: var(--muted); font-size: 0.85rem;">Ingresa el nombre para crear el aula virtual.</p>
                    </div>
                </div>
                
                <form action="procesos/docente_activar_aula.php" method="POST" style="flex: 2; max-width: 500px;">
                    <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                    
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <input type="text" name="nombre_materia" class="form-control" placeholder="Ej. Taller de Lectura y Redacción I" required style="width: 100%; height: 45px; border-radius: 12px; padding: 0 15px; font-size: 0.95rem;">
                        
                        <button type="submit" class="btn-primary" style="height: 45px; background: var(--orange); padding: 0 25px; font-size: 0.95rem; border: none; border-radius: 12px; color: white; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: 0.2s; white-space: nowrap;"><i class="fas fa-plus"></i> Crear</button>
                    </div>
                </form>
            </div>
            <h3 style="color:var(--ink); font-size:1.1rem; margin-bottom:1rem; font-weight:800;">Materias Activas</h3>
            <?php if(empty($materias_del_grupo)): ?>
                <div style="padding: 3rem 2rem; border: 2px dashed #cbd5e1; border-radius: 20px; text-align: center; color: var(--muted); background: white;">
                    <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom:15px; color: #cbd5e1;"></i>
                    <p style="margin:0; font-size: 1.1rem; font-weight: 600;">No has activado ninguna materia.</p>
                </div>
            <?php else: ?>
                <div class="grid-materias">
                    <?php foreach($materias_del_grupo as $mat): ?>
                        <?php
                            $bg_banner = !empty($mat['imagen_portada']) ? '../' . $mat['imagen_portada'] : '';
                            // Si hay imagen, la ponemos de fondo en TODA la tarjeta
                            $style_card = !empty($bg_banner) ? "background: url('".htmlspecialchars($bg_banner)."') center/cover;" : "background: white;";
                        ?>
                        <div class="materia-card" style="<?php echo $style_card; ?>">
                            
                            <!-- BANNER SUPERIOR -->
                            <div style="height: 100px; position: relative; <?php echo empty($bg_banner) ? 'background: linear-gradient(145deg, var(--green-900), var(--green-700));' : ''; ?>">
                                
                                <!-- Botón de tres puntos (Diseño cristalino) -->
                                <button type="button" class="btn-dots" style="position: absolute; top: 12px; right: 12px; background: rgba(255, 255, 255, 0.7); color: var(--ink); border: 1px solid rgba(255,255,255,0.5); padding: 6px 12px; border-radius: 8px; cursor: pointer; transition: 0.2s; backdrop-filter: blur(4px);" onclick="toggleDropdownMat(<?php echo $mat['id_clase']; ?>, event)" onmouseover="this.style.background='rgba(255,255,255,0.95)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.7)'">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                
                                <!-- Menú desplegable -->
                                <div id="drop-mat-<?php echo $mat['id_clase']; ?>" class="dropdown-materia" style="top: 48px; right: 12px;">
                                    <button type="button" onclick="abrirModalEditarMateria(<?php echo $mat['id_clase']; ?>, '<?php echo htmlspecialchars(addslashes($mat['nombre_materia'])); ?>')">
                                        <i class="fas fa-edit" style="color:var(--blue);"></i> Editar Nombre
                                    </button>
                                    
                                    <button type="button" onclick="abrirModalPortada(<?php echo $mat['id_clase']; ?>, <?php echo $mat['id_materia']; ?>)">
                                        <i class="fas fa-image" style="color:var(--green);"></i> Cambiar Portada
                                    </button>

                                    <button type="button" class="text-danger" onclick="abrirModalEliminarMateria(<?php echo $mat['id_clase']; ?>)">
                                        <i class="fas fa-trash-alt"></i> Eliminar Aula
                                    </button>
                                </div>
                            </div>
                            
                            <!-- CONTENIDO INFERIOR DE LA TARJETA (Verdadero Glassmorphism) -->
                            <div style="padding: 1.2rem 1.5rem 1.5rem 1.5rem; display: flex; flex-direction: column; flex: 1; justify-content: space-between; background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-top: 1px solid rgba(255, 255, 255, 0.6);">
                                <div>
                                    <h3 style="margin: 0 0 8px 0; color: var(--ink); font-size: 1.3rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 3px rgba(255,255,255,0.9);"><?php echo htmlspecialchars($mat['nombre_materia']); ?></h3>
                                    <div class="meta" style="color: #334155; font-size: 0.85rem; margin-bottom: 15px; display:flex; align-items:center; gap:8px; font-weight: 600;">
                                        <?php if(empty($mat['enlace_clase_virtual'])): ?>
                                            <i class="fas fa-video-slash" style="color:#64748b;"></i> Sin videollamada
                                        <?php else: ?>
                                            <i class="fas fa-video" style="color:var(--green);"></i> <span style="color: #166534;">Videollamada activa</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div style="display:flex; gap: 8px; margin-top: auto;">
                                    <button type="button" onclick="abrirModalAsistencia(<?php echo $mat['id_clase']; ?>, '<?php echo htmlspecialchars(addslashes($mat['nombre_materia'])); ?>')" class="btn-outline" style="flex:1; justify-content:center; color: #059669; border-color: #a7f3d0; background: rgba(236, 253, 245, 0.9); cursor:pointer; font-weight: 700; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" title="Pasar Lista">
                                        <i class="fas fa-user-check"></i> Lista
                                    </button>
                                    <a href="gestionar_aula.php?id_clase=<?php echo $mat['id_clase']; ?>" class="btn-entrar" style="flex:1.5; font-size: 0.9rem; padding: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.15);">
                                        Tareas <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- SECCIÓN DE PAPELERA DE RECICLAJE -->
            <?php if(!empty($materias_inactivas)): ?>
                <div style="margin-top: 3rem; border-top: 2px dashed #cbd5e1; padding-top: 2rem;">
                    <button type="button" onclick="document.getElementById('papelera-container').style.display = (document.getElementById('papelera-container').style.display === 'none' ? 'block' : 'none');" style="background: none; border: none; color: #64748b; font-size: 1rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-trash-restore"></i> Ver Materias Eliminadas (<?php echo count($materias_inactivas); ?>)
                    </button>
                    
                    <div id="papelera-container" style="display: none; margin-top: 1.5rem;">
                        <div class="grid-materias" style="opacity: 0.8;">
                            <?php foreach($materias_inactivas as $mat_inactiva): ?>
                                <div class="materia-card" style="background: #f8fafc; border-color: #cbd5e1;">
                                    <div style="padding: 1.5rem;">
                                        <h3 style="color: #64748b; margin: 0 0 10px 0; font-size: 1.3rem; font-weight: 800;"><del><?php echo htmlspecialchars($mat_inactiva['nombre_materia']); ?></del></h3>
                                        <div class="meta" style="color: #ef4444; font-size: 0.85rem;"><i class="fas fa-ban"></i> Aula Inactiva</div>
                                    
                                        <div style="margin-top: 15px;">
                                            <form action="procesos/docente_restaurar_materia.php" method="POST" onsubmit="return confirm('¿Estás seguro de que deseas restaurar esta aula? Los alumnos volverán a ver las tareas.');">
                                                <input type="hidden" name="id_clase" value="<?php echo $mat_inactiva['id_clase']; ?>">
                                                <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                                                <button type="button" class="btn-primary" style="background: #3b82f6; width: 100%;" onclick="abrirModalRestaurarMateria(<?php echo $mat_inactiva['id_clase']; ?>)">
                                                  <i class="fas fa-undo-alt"></i> Restaurar Aula
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- TODO EL CÓDIGO DE LAS TAREAS SE MANTIENE INTACTO -->
            <div class="layout-materia">
                <div>
                    <div class="card">
                        <div class="card-title">
                            <div><i class="fas fa-bullhorn"></i> Publicar Nueva Actividad</div>
                        </div>
                        <form action="procesos/docente_publicar_actividad.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
                            
                            <!-- 1. CAMPOS BÁSICOS (Siempre visibles) -->
                            <div class="form-group">
                                <label>Título de la Tarea</label>
                                <input type="text" name="titulo" class="form-control" placeholder="Ej. Ensayo sobre la Revolución" required>
                            </div>
                            <div class="form-group">
                                <label>Instrucciones detalladas</label>
                                <textarea name="descripcion" class="form-control" placeholder="Escribe aquí las instrucciones..."></textarea>
                            </div>
                            
                            <div style="display:flex; gap:15px; margin-bottom: 1.2rem; flex-wrap: wrap;">
                                <div class="form-group" style="flex:1; margin:0;">
                                    <label>Fecha y Hora Límite (Cierre)</label>
                                    <input type="datetime-local" name="fecha_limite" class="form-control">
                                </div>
                                <div class="form-group" style="flex:1; margin:0;">
                                    <label>Archivo Adjunto (Opcional)</label>
                                    <input type="file" name="archivo" class="form-control" style="padding: 9px 14px;">
                                </div>
                            </div>

                            <div style="margin-top: 15px; margin-bottom: 15px; text-align: center;">
                                <button type="button" id="btn-toggle-advanced" style="background: none; border: 1px dashed #cbd5e1; color: var(--blue); padding: 8px 20px; border-radius: 50px; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: 0.2s; width: 100%;">
                                    <i class="fas fa-sliders-h"></i> Mostrar Opciones Avanzadas y Programación
                                </button>
                            </div>
                            
                            <div id="advanced-options-container" style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; margin-bottom: 15px;">
    <!-- Lo que ya tenías de Reglas de Entrega -->
    <div class="form-group" style="margin-bottom: 15px;">
        <label style="color:var(--ink);"><i class="fas fa-cog" style="color:var(--muted);"></i> Reglas de Entrega</label>
        <div class="check-options">
            <label class="check-item"><input type="checkbox" name="permite_entregas" value="1" checked> Requiere subir archivo</label>
            <label class="check-item"><input type="checkbox" name="acepta_retardo" value="1"> Aceptar entregas con retardo</label>
        </div>
    </div>
    <!-- NUEVO: LÍMITES DE ARCHIVOS -->
<div class="form-group" style="margin-bottom: 15px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
    <label style="color:var(--ink);"><i class="fas fa-file-upload" style="color:var(--blue);"></i> Configuración de Archivos (Para los alumnos)</label>
    
    <div style="display:flex; gap:15px; margin-top: 10px; flex-wrap: wrap;">
        <!-- Tipo de archivo -->
        <div style="flex: 1.5; min-width: 200px;">
            <label style="font-size: 0.8rem; color: var(--muted); font-weight: 700; display:block; margin-bottom:5px;">Tipo Permitido:</label>
            <select name="tipo_archivo" class="form-control" style="background: white; padding: 8px 12px; height: 42px;">
                <option value="*">Cualquier tipo de archivo</option>
                <optgroup label="📸 Imágenes (Abre la cámara en celular)">
                    <option value="image/*">Solo Imágenes / Fotos</option>
                </optgroup>
                <optgroup label="📄 Documentos">
                    <option value=".pdf">Solo documentos PDF</option>
                    <option value=".pdf,.doc,.docx">Documentos (PDF, Word)</option>
                    <option value=".xls,.xlsx">Hojas de cálculo (Excel)</option>
                    <option value=".zip,.rar">Comprimidos (ZIP, RAR)</option>
                </optgroup>
            </select>
        </div>
        
        <!-- Tamaño máximo -->
        <div style="flex: 1; min-width: 120px;">
            <label style="font-size: 0.8rem; color: var(--muted); font-weight: 700; display:block; margin-bottom:5px;">Peso Máx:</label>
            <select name="tamano_maximo" class="form-control" style="background: white; padding: 8px 12px; height: 42px;">
                <option value="2">2 MB</option>
                <option value="5">5 MB</option>
                <option value="10" selected>10 MB</option>
                <option value="20">20 MB</option>
                <option value="50">50 MB</option>
            </select>
        </div>

        <!-- Cantidad de archivos -->
        <div style="flex: 1; min-width: 120px;">
            <label style="font-size: 0.8rem; color: var(--muted); font-weight: 700; display:block; margin-bottom:5px;">Cant. Archivos:</label>
            <input type="number" name="numero_archivos" class="form-control" value="1" min="1" max="10" style="background: white; padding: 8px 12px; height: 42px;">
        </div>
    </div>
        <p style="margin: 5px 0 0 0; font-size: 0.75rem; color: var(--muted);"><i class="fas fa-info-circle"></i> Ayuda a evitar que el servidor se sature con archivos muy pesados.</p>
    </div>

    <!-- ... (Sigue lo que ya tenías de Programación y Privacidad) ... -->
                                <div class="form-group" style="margin-bottom: 15px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                                    <label style="color:var(--ink);"><i class="fas fa-clock" style="color:var(--orange);"></i> Programación (Automatización)</label>
                                    <div style="margin-top: 5px;">
                                        <label style="font-size: 0.8rem; font-weight: 600; color: var(--muted); display:block; margin-bottom: 5px;">Fecha de Apertura (El sistema la mostrará sola en esta fecha):</label>
                                        <input type="datetime-local" name="fecha_apertura" class="form-control" style="max-width: 250px; background: white;">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 15px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                                    <label style="color:var(--ink);"><i class="fas fa-user-shield" style="color:var(--blue);"></i> Privacidad y Visibilidad</label>
                                    <div class="check-options" style="flex-direction: column; gap: 10px; margin-top: 5px;">
                                        <label class="check-item" style="width: 100%;">
                                            <input type="checkbox" name="visible" value="1" checked> 
                                            <b>Publicar en el muro </b> (Desmárcalo para guardar como borrador)
                                        </label>
                                        <label class="check-item" style="width: 100%;">
                                            <input type="checkbox" name="solo_asistentes" value="1"> 
                                            <b>Filtro de Asistencia:</b> Ocultar a los alumnos que faltaron hoy.
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn-primary" style="margin-top: 10px;"><i class="fas fa-paper-plane"></i> Crear y Guardar Actividad</button>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-title">
                            <div><i class="fas fa-list-check"></i> Actividades Publicadas</div>
                        </div>
                        <?php if(empty($actividades)): ?>
                            <div style="padding: 2rem; text-align: center; border: 1px dashed #cbd5e1; border-radius: 12px; background: #f8fafc;">
                                <i class="fas fa-clipboard" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                                <p style="color:var(--muted); font-size:0.9rem; margin:0;">Aún no hay actividades en esta materia.</p>
                            </div>
                        <?php else: ?>
                            <div class="act-list">
                                <?php foreach($actividades as $act): 
                                    $es_visible = (isset($act['visible']) && $act['visible'] == 1);
                                ?>
                                    <div class="act-item" style="<?php echo !$es_visible ? 'opacity: 0.7; border-color: #fecaca;' : ''; ?>">
                                        <div class="act-header">
                                            <div class="act-info">
                                                <h4>
                                                    <?php echo htmlspecialchars($act['titulo']); ?>
                                                    <?php if(!$es_visible): ?>
                                                        <span class="badge-oculta" style="color:#ef4444; font-size:0.75rem; margin-left:5px;"><i class="fas fa-eye-slash"></i> Oculta</span>
                                                    <?php endif; ?>
                                                </h4>
                                                <p><i class="far fa-calendar-alt"></i> Publicado: <?php echo date('d/m/Y', strtotime($act['fecha_publicacion'])); ?></p>
                                            </div>
                                        </div>
                                        <div class="act-badges">
                                            <?php if(isset($act['fecha_limite']) && !empty($act['fecha_limite'])): ?>
                                                <span class="badge badge-orange"><i class="far fa-clock"></i> Límite: <?php echo date('d/m/Y H:i', strtotime($act['fecha_limite'])); ?></span>
                                            <?php endif; ?>
                                            <?php if(isset($act['permite_entregas']) && $act['permite_entregas'] == 1): ?>
                                                <span class="badge badge-green"><i class="fas fa-upload"></i> Requiere Entrega</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                       <div class="act-actions" style="display:flex; justify-content:space-between; align-items:center; border-top: 1px solid var(--line); padding-top: 10px; margin-top: 5px; flex-wrap:wrap; gap:10px;">
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span style="font-size:0.75rem; color:var(--muted); font-weight:700;"><i class="fas fa-eye"></i> Visible a todos</span>
                                                <div class="switch" title="Ocultar/Mostrar actividad a todo el grupo">
                                                    <input type="checkbox" id="vis_act_<?php echo $act['id_actividad']; ?>" <?php echo $es_visible ? 'checked' : ''; ?> onchange="toggleVisibilidadActividad(<?php echo $act['id_actividad']; ?>, this)">
                                                    <label class="slider" for="vis_act_<?php echo $act['id_actividad']; ?>"></label>
                                                </div>
                                            </div>
                                            <div style="display:flex; gap: 8px; flex-wrap:wrap;">
                                              <!-- NUEVO BOTÓN DE VISTA PREVIA (MODAL) -->
                                             <button type="button" class="btn-outline" style="color:var(--orange); border-color:#fed7aa; background: #fff7ed;" onclick="abrirVistaPrevia(<?php echo $act['id_actividad']; ?>)" title="Ver como alumno">
                                                   <i class="fas fa-laptop-code"></i> Vista Previa
                                              </button>
    
                                             <button type="button" class="btn-outline" style="color:var(--blue); border-color:#bfdbfe;" onclick="abrirModalAlumnosActividad(<?php echo $act['id_actividad']; ?>)">
                                              <i class="fas fa-users-cog"></i> Permisos
                                             </button>
                                              <!-- ... (El resto de tus botones siguen igual) ... -->
                                            <button type="button" class="btn-outline" onclick="abrirModalEditar(<?php echo $act['id_actividad']; ?>, '<?php echo htmlspecialchars(addslashes($act['titulo'])); ?>', '<?php echo htmlspecialchars(addslashes($act['descripcion'])); ?>', '<?php echo $act['fecha_limite']; ?>', <?php echo $act['permite_entregas'] ?? 0; ?>, <?php echo $act['acepta_retardo'] ?? 0; ?>, '<?php echo $act['fecha_apertura']; ?>', <?php echo $act['tamano_maximo'] ?? 10; ?>, <?php echo $act['numero_archivos'] ?? 1; ?>, '<?php echo $act['tipo_archivo'] ?? '*'; ?>')">
                                                  <i class="fas fa-edit"></i> Editar
                                              </button>
                                              <a href="ver_entregas.php?id_actividad=<?php echo $act['id_actividad']; ?>&id_clase=<?php echo $id_clase; ?>" class="btn-outline" style="color:var(--blue); border-color:var(--blue); text-decoration:none;">
                                                <i class="fas fa-inbox"></i> Entregas (<?php echo $act['total_entregas']; ?>)
                                                 </a>
                                               <button type="button" class="btn-outline" style="color:red; border-color:red;" onclick="abrirModalEliminar(<?php echo $act['id_actividad']; ?>, <?php echo $id_clase; ?>)">
                                              <i class="fas fa-trash"></i> Eliminar
                                               </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="card">
                        <div class="card-title"><div><i class="fas fa-video"></i> Clase en Vivo</div></div>
                        <form action="procesos/docente_activar_clase.php" method="POST">
                            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
                            <div class="form-group">
                                <label>Enlace de Zoom/Meet</label>
                                <input type="url" name="enlace_clase_virtual" class="form-control" value="<?php echo htmlspecialchars($link_actual); ?>" placeholder="https://meet.google.com/...">
                            </div>
                            <button type="submit" class="btn-primary btn-blue"><i class="fas fa-save"></i> Guardar Enlace</button>
                            <?php if(!empty($link_actual)): ?>
                                <button type="submit" name="apagar" value="1" class="btn-danger"><i class="fas fa-power-off"></i> Finalizar Clase</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- NUEVA TARJETA: GESTIÓN DE HORARIOS COMPACTA -->
                    <div class="card" style="margin-top: 1.5rem;">
                        <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); padding-bottom: 12px; margin-bottom: 15px;">
                            <div style="margin: 0;"><i class="far fa-clock" style="color: var(--orange);"></i> Horario Semanal</div>
                            <button type="button" onclick="abrirModalHorario()" class="btn-outline" style="color: var(--orange); border-color: rgba(245, 158, 11, 0.3); background: var(--orange-soft); font-size: 0.75rem; padding: 6px 12px; margin: 0;">
                                <i class="fas fa-plus"></i> Añadir Clase
                            </button>
                        </div>
                        
                        <!-- Lista de horarios guardados -->
                        <div>
                            <?php 
                            $dias_str = ['1'=>'Lunes', '2'=>'Martes', '3'=>'Miércoles', '4'=>'Jueves', '5'=>'Viernes', '6'=>'Sábado', '7'=>'Domingo'];
                            if(empty($horarios_materia)): 
                            ?>
                                <p style="font-size: 0.85rem; color: var(--muted); font-style: italic; text-align: center; margin: 15px 0;">No has configurado ningún horario.</p>
                            <?php else: ?>
                                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px;">
                                    <?php foreach($horarios_materia as $h): ?>
                                        <li style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 10px 15px; border: 1px solid var(--line); border-radius: 8px; font-size: 0.85rem;">
                                            <div>
                                                <strong style="color: var(--ink); display: inline-block; width: 75px;"><?php echo $dias_str[$h['dia_semana']]; ?></strong>
                                                <span style="color: var(--muted);"><i class="fas fa-circle" style="font-size: 6px; color: var(--green); margin-right: 4px;"></i> <?php echo date('h:i a', strtotime($h['hora_inicio'])) . ' - ' . date('h:i a', strtotime($h['hora_fin'])); ?></span>
                                            </div>
                                            <button type="button" onclick="abrirModalEliminarHorario(<?php echo $h['id_horario']; ?>, <?php echo $id_clase; ?>)" style="color: #ef4444; background: #fef2f2; border: none; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s;" title="Eliminar">
                                             <i class="fas fa-trash-alt"></i>
                                            </button>

                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                        <!-- Modal Agregar Horario -->
<div class="modal-overlay" id="modalAgregarHorario">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3><i class="far fa-clock" style="color:var(--orange);"></i> Configurar Horario</h3>
            <button type="button" class="btn-close" onclick="cerrarModal('modalAgregarHorario')"><i class="fas fa-times"></i></button>
        </div>
        <form action="procesos/docente_agregar_horario.php" method="POST">
            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
            <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 15px;">Agrega un día y bloque de horas en los que impartes esta materia.</p>
            
            <div class="form-group">
                <label>Día de Clase</label>
                <select name="dia_semana" class="form-control" required style="padding: 10px 14px;">
                    <option value="1">Lunes</option>
                    <option value="2">Martes</option>
                    <option value="3">Miércoles</option>
                    <option value="4">Jueves</option>
                    <option value="5">Viernes</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 15px; margin-bottom: 1.5rem;">
                <div class="form-group" style="margin: 0; flex: 1;">
                    <label>Hora de Inicio</label>
                    <input type="time" name="hora_inicio" class="form-control" required>
                </div>
                <div class="form-group" style="margin: 0; flex: 1;">
                    <label>Hora de Fin</label>
                    <input type="time" name="hora_fin" class="form-control" required>
                </div>
            </div>
            
            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalAgregarHorario')">Cancelar</button>
                <button type="submit" class="btn-primary" style="margin:0; width:auto; background: var(--orange);"><i class="fas fa-plus"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Eliminar Horario (Día de Clase) -->
<div class="modal-overlay" id="modalEliminarHorario">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Eliminar Día de Clase</h3>
            <button type="button" class="btn-close" onclick="cerrarModal('modalEliminarHorario')"><i class="fas fa-times"></i></button>
        </div>
        
        <p style="color:var(--muted); font-size:0.95rem; margin-bottom: 15px;">¿Estás seguro de que deseas eliminar este día y horario de la clase? Se dejará de mostrar en tu panel principal.</p>

        <!-- Usamos GET porque tu archivo docente_eliminar_horario.php lee variables por URL -->
        <form action="procesos/docente_eliminar_horario.php" method="GET">
            <input type="hidden" name="id_horario" id="del_horario_id">
            <input type="hidden" name="id_clase" id="del_horario_id_clase">

            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminarHorario')">Cancelar</button>
                <button type="submit" class="btn-danger" style="margin:0; width:auto; padding:10px 16px;">
                    <i class="fas fa-trash"></i> Sí, Eliminar
                </button>
            </div>
        </form>
    </div>
</div>

                    <div class="card">
                        <div class="card-title" style="justify-content:space-between;">
                            <div><i class="fas fa-users"></i> Alumnos (<?php echo $total_alumnos; ?>)</div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:0.75rem; color:var(--muted);">Acceso Gral.</span>
                                <div class="switch" title="Dar/Quitar acceso a todos">
                                    <input type="checkbox" id="toggleAll" <?php echo ($alumnos_activos_count == $total_alumnos && $total_alumnos > 0) ? 'checked' : ''; ?> onchange="toggleAllAccesos(<?php echo $id_clase; ?>, this)">
                                    <label class="slider" for="toggleAll"></label>
                                </div>
                            </div>
                        </div>

                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="buscadorAlumnos" placeholder="Buscar alumno...">
                        </div>
                        <div class="lista-scroll" style="max-height: 480px;">
                            <?php foreach($lista_alumnos as $alum): 
                                $ini_alum = mb_substr($alum['nombre'], 0, 1) . mb_substr($alum['apellido_paterno'], 0, 1);
                                $tiene_acceso = isset($alum['acceso']) ? $alum['acceso'] : 1;
                            ?>
                                <div class="alumno-item">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <?php echo avatar_html($alum['fotografia'] ?? null, $alum['nombre'] . ' ' . $alum['apellido_paterno'], 'alumno-avatar', 'overflow:hidden;'); ?>
                                        <div class="alumno-info">
                                            <strong><a href="perfil_alumno.php?id=<?php echo (int)$alum['id_alumno']; ?>" style="color:inherit; text-decoration:none;" title="Ver perfil del alumno"><?php echo htmlspecialchars($alum['apellido_paterno'] . ' ' . $alum['nombre']); ?></a></strong>
                                            <span>Mat: <?php echo htmlspecialchars($alum['matricula']); ?></span>
                                        </div>
                                    </div>
                                    <div class="switch" title="Permitir acceso individual">
                                        <input type="checkbox" class="alum-toggle" id="toggle_<?php echo $alum['id_alumno']; ?>" <?php echo ($tiene_acceso == 1) ? 'checked' : ''; ?> onchange="toggleAcceso(<?php echo $alum['id_alumno']; ?>, <?php echo $id_clase; ?>, this)">
                                        <label class="slider" for="toggle_<?php echo $alum['id_alumno']; ?>"></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
<!-- NUEVO: Modal Cambiar Portada -->
<div class="modal-overlay" id="modalPortadaMateria">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-image" style="color:var(--green);"></i> Cambiar Portada de Materia</h3>
            <button class="btn-close" onclick="cerrarModal('modalPortadaMateria')"><i class="fas fa-times"></i></button>
        </div>
        <form action="procesos/docente_subir_portada.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id_clase" id="portada_id_clase">
            <input type="hidden" name="id_materia" id="portada_id_materia">
            <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
            
            <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 15px;">Sube una imagen para personalizar la tarjeta de esta materia. Formatos: JPG, PNG o WEBP horizontales. <b>Tamaño máximo: 2 MB.</b> La portada anterior se reemplaza.</p>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <input type="file" name="imagen_portada" class="form-control" accept="image/jpeg, image/png, image/webp" required style="background: white; padding: 10px;"
                       onchange="if (this.files[0] && this.files[0].size > 2 * 1024 * 1024) { alert('La imagen pesa ' + (this.files[0].size / 1048576).toFixed(1) + ' MB. El máximo es 2 MB.'); this.value = ''; }">
            </div>

            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalPortadaMateria')">Cancelar</button>
                <button type="submit" class="btn-primary" style="margin:0; width:auto;"><i class="fas fa-upload"></i> Subir Portada</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Materia -->
<div class="modal-overlay" id="modalEditarMateria">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit" style="color:var(--blue);"></i> Editar Nombre de Materia</h3>
            <button class="btn-close" onclick="cerrarModal('modalEditarMateria')"><i class="fas fa-times"></i></button>
        </div>
        <form action="procesos/docente_editar_materia.php" method="POST">
            <input type="hidden" name="id_clase" id="edit_mat_id">
            <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
            <div class="form-group">
                <label>Nombre de la Materia</label>
                <input type="text" name="nombre_materia" id="edit_mat_nombre" class="form-control" required>
            </div>
            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditarMateria')">Cancelar</button>
                <button type="submit" class="btn-primary" style="margin:0; width:auto;"><i class="fas fa-save"></i> Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Eliminar Materia con Contraseña y Ojito -->
<div class="modal-overlay" id="modalEliminarMateria">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Eliminar Aula Virtual</h3>
            <button class="btn-close" onclick="cerrarModal('modalEliminarMateria')"><i class="fas fa-times"></i></button>
        </div>
        
        <p style="color:var(--muted); font-size:0.95rem; margin-bottom: 15px;">¿Estás seguro de que deseas eliminar esta materia? <strong>Se ocultarán permanentemente todas las tareas y entregas de los alumnos vinculadas a ella.</strong></p>
        
        <div style="background: #fef2f2; border-left: 4px solid #ef4444; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin: 0; color: #991b1b; font-size: 0.85rem; font-weight: 700;"><i class="fas fa-lock"></i> Por seguridad, ingresa tu contraseña para confirmar:</p>
        </div>

        <form action="procesos/docente_eliminar_materia.php" method="POST">
            <input type="hidden" name="id_clase" id="del_mat_id">
            <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
            
            <div class="form-group" style="margin-bottom: 1.5rem; position: relative;">
                <input type="password" id="input_password_eliminar" name="password_confirmacion" class="form-control" placeholder="Escribe tu contraseña..." required style="border-color: #fecaca; background: #fff; padding-right: 40px;">
                <button type="button" onclick="togglePasswordVisibility('input_password_eliminar', 'icon_eye_eliminar')" style="position: absolute; right: 10px; top: 12px; background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1.1rem; padding: 0;">
                    <i class="fas fa-eye" id="icon_eye_eliminar"></i>
                </button>
            </div>

            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminarMateria')">Cancelar</button>
                <button type="submit" class="btn-danger" style="margin:0; width:auto; padding:10px 16px;"><i class="fas fa-trash"></i> Confirmar Eliminación</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Restaurar Materia con Contraseña -->
<div class="modal-overlay" id="modalRestaurarMateria">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-undo-alt" style="color:var(--blue);"></i> Restaurar Aula Virtual</h3>
            <button class="btn-close" onclick="cerrarModal('modalRestaurarMateria')"><i class="fas fa-times"></i></button>
        </div>
        
        <p style="color:var(--muted); font-size:0.95rem; margin-bottom: 15px;">¿Estás seguro de que deseas restaurar esta aula? <strong>Los alumnos volverán a ver las tareas y entregas vinculadas a ella.</strong></p>
        
        <div style="background: #eff6ff; border-left: 4px solid var(--blue); padding: 12px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin: 0; color: #1e3a8a; font-size: 0.85rem; font-weight: 700;"><i class="fas fa-lock"></i> Por seguridad, ingresa tu contraseña para confirmar:</p>
        </div>

        <form action="procesos/docente_restaurar_materia.php" method="POST">
            <input type="hidden" name="id_clase" id="rest_mat_id">
            <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
            
            <div class="form-group" style="margin-bottom: 1.5rem; position: relative;">
                <input type="password" id="input_password_restaurar" name="password_confirmacion" class="form-control" placeholder="Escribe tu contraseña..." required style="border-color: #bfdbfe; background: #fff; padding-right: 40px;">
                <button type="button" onclick="togglePasswordVisibility('input_password_restaurar', 'icon_eye_restaurar')" style="position: absolute; right: 10px; top: 12px; background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1.1rem; padding: 0;">
                    <i class="fas fa-eye" id="icon_eye_restaurar"></i>
                </button>
            </div>

            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalRestaurarMateria')">Cancelar</button>
                <button type="submit" class="btn-primary" style="margin:0; width:auto; padding:10px 16px; background: var(--blue);"><i class="fas fa-undo-alt"></i> Confirmar Restauración</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Eliminar Actividad -->
<div class="modal-overlay" id="modalEliminar">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Eliminar Actividad</h3>
            <button class="btn-close" onclick="cerrarModal('modalEliminar')"><i class="fas fa-times"></i></button>
        </div>
        <p style="color:var(--muted); font-size:0.95rem;">¿Estás seguro de que deseas eliminar esta actividad? Se borrarán también las entregas.</p>
        <form action="procesos/docente_eliminar_actividad.php" method="POST">
            <input type="hidden" name="id_actividad" id="del_id_actividad">
            <input type="hidden" name="id_clase" id="del_id_clase">
            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
                <button type="submit" class="btn-danger" style="margin:0; width:auto; padding:10px 16px;"><i class="fas fa-trash"></i> Sí, Eliminar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Actividad -->
<div class="modal-overlay" id="modalEditar">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Editar Actividad</h3>
            <button class="btn-close" onclick="cerrarModal('modalEditar')"><i class="fas fa-times"></i></button>
        </div>
        <form action="procesos/docente_editar_actividad.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id_actividad" id="edit_id_actividad">
            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="titulo" id="edit_titulo" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Instrucciones</label>
                <textarea name="descripcion" id="edit_descripcion" class="form-control"></textarea>
            </div>
            <div style="display:flex; gap:15px; margin-bottom: 1.2rem; flex-wrap: wrap;">
                <div class="form-group" style="flex:1; margin:0;">
                    <label>Fecha Apertura (Programar)</label>
                    <input type="datetime-local" name="fecha_apertura" id="edit_fecha_apertura" class="form-control">
                </div>
                <div class="form-group" style="flex:1; margin:0;">
                    <label>Fecha Límite (Cierre)</label>
                    <input type="datetime-local" name="fecha_limite" id="edit_fecha_limite" class="form-control">
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Reemplazar Archivo Adjunto</label>
                <input type="file" name="archivo" class="form-control" style="padding: 9px 14px;">
            </div>
            <div class="form-group check-options" style="margin-bottom: 1.5rem; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <label class="check-item"><input type="checkbox" name="permite_entregas" id="edit_permite_entregas" value="1"> Requiere subir archivo</label>
                <label class="check-item"><input type="checkbox" name="acepta_retardo" id="edit_acepta_retardo" value="1"> Aceptar retardo</label>
            </div>
            <!-- NUEVO EN EDITAR: Límites de Archivos -->
<div style="display:flex; gap:15px; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <div class="form-group" style="margin: 0; flex: 1.5; min-width: 200px;">
        <label>Tipo Permitido</label>
        <select name="tipo_archivo" id="edit_tipo_archivo" class="form-control">
            <option value="*">Cualquier tipo de archivo</option>
            <optgroup label="📸 Imágenes">
                <option value="image/*">Solo Imágenes / Fotos</option>
            </optgroup>
            <optgroup label="📄 Documentos">
                <option value=".pdf">Solo documentos PDF</option>
                <option value=".pdf,.doc,.docx">Documentos (PDF, Word)</option>
                <option value=".xls,.xlsx">Hojas de cálculo (Excel)</option>
                <option value=".zip,.rar">Comprimidos (ZIP, RAR)</option>
            </optgroup>
        </select>
    </div>
    <div class="form-group" style="margin: 0; flex: 1; min-width: 100px;">
        <label>Peso Máx.</label>
        <select name="tamano_maximo" id="edit_tamano_maximo" class="form-control">
            <option value="2">2 MB</option>
            <option value="5">5 MB</option>
            <option value="10">10 MB</option>
            <option value="20">20 MB</option>
            <option value="50">50 MB</option>
        </select>
    </div>
    <div class="form-group" style="margin: 0; flex: 1; min-width: 100px;">
        <label>Máx. Archivos</label>
        <input type="number" name="numero_archivos" id="edit_numero_archivos" class="form-control" min="1" max="10">
    </div>
</div>

            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
                <button type="submit" class="btn-primary" style="margin:0; width:auto;"><i class="fas fa-save"></i> Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Excepciones de Visibilidad -->
<div class="modal-overlay" id="modalAlumnosActividad">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3><i class="fas fa-users-cog" style="color:var(--blue);"></i> Visibilidad por Alumno</h3>
            <button class="btn-close" onclick="cerrarModal('modalAlumnosActividad')"><i class="fas fa-times"></i></button>
        </div>
        <p style="font-size:0.85rem; color:var(--muted); margin-bottom:15px;">Bloquea o permite ver esta actividad a alumnos en específico (ej. si pidieron permiso o faltaron).</p>
        <div id="contenedorListaVisibilidad" class="lista-scroll" style="max-height: 300px; border: 1px solid var(--line); border-radius: 8px; padding: 10px;">
            <div style="text-align:center; padding:20px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Cargando alumnos...</div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn-cancel" onclick="cerrarModal('modalAlumnosActividad')" style="width:100%;">Cerrar Ventana</button>
        </div>
    </div>
</div>

<!-- Modal Asistencia Emergente -->
<div class="modal-overlay" id="modalAsistencia">
    <div class="modal-content" style="max-width: 600px; padding: 0;">
        <div style="padding: 1.5rem; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-radius: 16px 16px 0 0;">
            <div>
                <h3 style="margin:0; font-size:1.2rem; color:var(--ink);"><i class="fas fa-list-check" style="color:var(--green);"></i> Pase de Lista: <span id="asist_nombre_materia"></span></h3>
            </div>
            <button type="button" class="btn-close" onclick="cerrarModal('modalAsistencia')"><i class="fas fa-times"></i></button>
        </div>
        <form action="procesos/docente_guardar_asistencia.php" method="POST" id="formAsistencia" style="padding: 1.5rem;">
            <input type="hidden" name="id_clase" id="asist_id_clase">
            
            <!-- CONTENEDOR FECHA Y BOTÓN QR -->
            <div style="margin-bottom: 1rem; display: flex; align-items: flex-end; gap: 15px; flex-wrap: wrap;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; color:var(--muted); margin-bottom:5px;">Fecha del Pase:</label>
                    <input type="date" name="fecha_asistencia" id="asist_fecha_input" class="form-control" style="width: auto; padding: 8px 12px;" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="cargarListaAsistencia()" required>
                </div>
                <button type="button" class="btn-primary" style="background: var(--orange); padding: 8px 16px; font-size: 0.85rem; height: 38px; margin: 0; width: auto;" onclick="abrirScannerQR()">
                    <i class="fas fa-qrcode"></i> TOMAR LISTA DE ASISTENCIA POR QR
                </button>
            </div>

            <!-- CONTENEDOR DEL ESCÁNER (Oculto por defecto) -->
            <div id="qr-scanner-container" style="display: none; margin-bottom: 1rem; border: 2px dashed #cbd5e1; border-radius: 8px; padding: 15px; text-align: center; background: #f8fafc;">
                <div id="qr-reader" style="width: 100%; max-width: 350px; margin: 0 auto; overflow: hidden; border-radius: 8px;"></div>
                
                <!-- CAJA DE MENSAJES DINÁMICOS -->
                <div id="qr-message" style="margin-top: 15px; font-weight: bold; padding: 10px; border-radius: 8px; display: none; font-size: 0.9rem;"></div>
                
                <button type="button" class="btn-cancel" style="margin-top: 15px; width: 100%; font-weight: 700;" onclick="cerrarScannerQR()">
                    <i class="fas fa-times"></i> Cerrar Cámara
                </button>
            </div>
            
            <!-- BUSCADOR -->
            <div style="margin-bottom: 1rem;">
                <div class="search-box" style="margin-bottom:0; background: #f1f5f9; border-radius: 8px; border: 1px solid #cbd5e1;">
                    <i class="fas fa-search" style="color:#94a3b8;"></i>
                    <input type="text" id="buscadorAsistenciaList" placeholder="Buscar por nombre o matrícula..." style="width:100%; border:none; background:transparent; outline:none; font-family:Inter; font-size:0.9rem;" onkeydown="return event.key !== 'Enter';">
                </div>
            </div>
            
            <p style="font-size:.78rem; color:var(--muted); margin:0 0 8px;"><b>P</b> presente · <b>R</b> retardo · <b>F</b> falta · <b>J</b> justificada (con justificante de Prefectura o permiso).</p>

            <!-- CONTENEDOR AJAX DE LA LISTA -->
            <div id="contenedorListaAsistencia" class="lista-scroll" style="max-height: 420px; overflow-y: auto; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 1.5rem; padding-right: 0;">
                <!-- Cargando por AJAX -->
            </div>
            
            <!-- BOTONES DE GUARDAR/CANCELAR -->
            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="cerrarModal('modalAsistencia')">Cancelar</button>
                <button type="submit" id="btnGuardarAsistencia" class="btn-primary" style="margin:0; width:auto; padding:10px 20px;"><i class="fas fa-save"></i> Guardar Asistencia</button>
            </div>
        </form> 
    </div>
</div>

<!-- NUEVO: Modal Vista Previa (Separado e independiente) -->
<div class="modal-overlay" id="modalVistaPrevia">
    <div class="modal-content" style="max-width: 850px; width: 95%; padding: 0; overflow: hidden; display: flex; flex-direction: column; height: 90vh;">
        <div class="modal-header" style="padding: 1.5rem; border-bottom: 1px solid var(--line); margin-bottom: 0; background: #f8fafc; border-radius: 16px 16px 0 0;">
            <h3 style="margin: 0; color: var(--ink);"><i class="fas fa-laptop-code" style="color:var(--orange);"></i> Vista Previa de la Actividad</h3>
            <button class="btn-close" onclick="cerrarModal('modalVistaPrevia')"><i class="fas fa-times"></i></button>
        </div>
        
        <!-- Aquí adentro cargará la vista del alumno sin recargar la página -->
        <div style="flex: 1; background: #f1f5f9; position: relative;">
            <iframe id="iframeVistaPrevia" src="" style="width: 100%; height: 100%; border: none;"></iframe>
        </div>
    </div>
</div>
</div>

    </main>
    
    <script src="../js/dashboard_docente.js"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
   <script>
    // =======================================================
    // FUNCIONES DE LA CABECERA Y PORTADAS
    // =======================================================
    function toggleDropdownMat(id, event) {
        event.stopPropagation();
        document.querySelectorAll('.dropdown-materia.show').forEach(el => {
            if(el.id !== 'drop-mat-'+id) el.classList.remove('show');
        });
        document.getElementById('drop-mat-'+id).classList.toggle('show');
    }

    document.addEventListener('click', function(e) {
        if(!e.target.closest('.dropdown-materia') && !e.target.closest('.btn-dots')) {
            document.querySelectorAll('.dropdown-materia.show').forEach(el => el.classList.remove('show'));
        }
    });

    function abrirModalPortada(id_clase, id_materia) {
        document.getElementById('portada_id_clase').value = id_clase;
        document.getElementById('portada_id_materia').value = id_materia;
        document.getElementById('modalPortadaMateria').classList.add('active');
        document.getElementById('drop-mat-'+id_clase).classList.remove('show');
    }

    function abrirModalEditarMateria(id, nombre) {
        document.getElementById('edit_mat_id').value = id;
        document.getElementById('edit_mat_nombre').value = nombre;
        document.getElementById('modalEditarMateria').classList.add('active');
        document.getElementById('drop-mat-'+id).classList.remove('show');
    }

    function abrirModalEliminarMateria(id) {
        document.getElementById('del_mat_id').value = id;
        document.getElementById('modalEliminarMateria').classList.add('active');
        document.getElementById('drop-mat-'+id).classList.remove('show');
    }

    function abrirModalEliminarHorario(id_horario, id_clase) {
        document.getElementById('del_horario_id').value = id_horario;
        document.getElementById('del_horario_id_clase').value = id_clase;
        document.getElementById('modalEliminarHorario').classList.add('active');
    }

    function togglePasswordVisibility(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
            icon.style.color = "#059669";
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
            icon.style.color = "#94a3b8";
        }
    }

    // =======================================================
    // GESTIÓN DE TAREAS Y ACTIVIDADES
    // =======================================================
    function abrirModalEliminar(id, idc) { document.getElementById('del_id_actividad').value = id; document.getElementById('del_id_clase').value = idc; document.getElementById('modalEliminar').classList.add('active'); }
    function abrirModalHorario() { document.getElementById('modalAgregarHorario').classList.add('active'); }
    function abrirModalEditar(id, t, d, f_limite, p, acepta_ret, f_apertura, tamano, num_arch, tipo) { 
        document.getElementById('edit_id_actividad').value = id; 
        document.getElementById('edit_titulo').value = t; 
        document.getElementById('edit_descripcion').value = d; 
        document.getElementById('edit_fecha_limite').value = f_limite ? f_limite.replace(' ', 'T') : ''; 
        document.getElementById('edit_fecha_apertura').value = f_apertura ? f_apertura.replace(' ', 'T') : ''; 
        document.getElementById('edit_permite_entregas').checked = (p == 1); 
        document.getElementById('edit_acepta_retardo').checked = (acepta_ret == 1); 
        
        // Poner los nuevos valores
        document.getElementById('edit_tamano_maximo').value = tamano || 10;
        document.getElementById('edit_numero_archivos').value = num_arch || 1;
        document.getElementById('edit_tipo_archivo').value = tipo || '*';

        document.getElementById('modalEditar').classList.add('active'); 
    }

    // CIERRE SEGURO DE MODALES Y CÁMARA
    function cerrarModal(id) { 
        document.getElementById(id).classList.remove('active'); 
        if (id === 'modalAsistencia') {
            cerrarScannerQR();
        }
    }

    document.getElementById('buscadorAlumnos')?.addEventListener('input', function(e) {
        let term = e.target.value.toLowerCase();
        document.querySelectorAll('.alumno-item').forEach(item => {
            item.style.display = item.querySelector('.alumno-info').textContent.toLowerCase().includes(term) ? 'flex' : 'none';
        });
    });

    function toggleAcceso(id_a, id_c, check) {
        fetch('procesos/docente_toggle_acceso.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `id_alumno=${id_a}&id_clase=${id_c}&acceso=${check.checked?1:0}` })
        .then(r => r.json()).then(d => { if(!d.success) check.checked = !check.checked; }).catch(() => check.checked = !check.checked);
    }
    
    function toggleAllAccesos(id_c, check) {
        fetch('procesos/docente_toggle_acceso_general.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `id_clase=${id_c}&acceso=${check.checked?1:0}` })
        .then(r => r.json()).then(d => { if(d.success) document.querySelectorAll('.alum-toggle').forEach(t => t.checked = check.checked); else check.checked = !check.checked; }).catch(() => check.checked = !check.checked);
    }

    function toggleVisibilidadActividad(id_actividad, checkbox) {
        let estado = checkbox.checked ? 1 : 0;
        fetch('procesos/docente_toggle_actividad.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id_actividad=${id_actividad}&visible=${estado}`
        })
        .then(r => r.json()).then(data => {
            if(!data.success) {
                alert("Error al cambiar visibilidad"); checkbox.checked = !checkbox.checked;
            } else {
                const card = checkbox.closest('.act-item');
                const tituloActividad = card.querySelector('h4');
                let badgeOculto = tituloActividad.querySelector('.badge-oculta');
                if(estado === 0) {
                    card.style.opacity = '0.7'; card.style.borderColor = '#fecaca';
                    if(!badgeOculto) tituloActividad.innerHTML += ' <span class="badge-oculta" style="color:#ef4444; font-size:0.75rem; margin-left:5px;"><i class="fas fa-eye-slash"></i> Oculta</span>';
                } else {
                    card.style.opacity = '1'; card.style.borderColor = 'var(--line)';
                    if(badgeOculto) badgeOculto.remove();
                }
            }
        }).catch(() => checkbox.checked = !checkbox.checked);
    }

    function abrirModalAlumnosActividad(id_actividad) {
        document.getElementById('modalAlumnosActividad').classList.add('active');
        const contenedor = document.getElementById('contenedorListaVisibilidad');
        contenedor.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Cargando alumnos...</div>';
        let id_clase = document.querySelector('input[name="id_clase"]').value;
        fetch(`procesos/obtener_excepciones.php?id_actividad=${id_actividad}&id_clase=${id_clase}`)
        .then(response => response.text()).then(html => { contenedor.innerHTML = html; })
        .catch(error => { contenedor.innerHTML = '<div style="color:red; text-align:center; padding:20px;">Error de conexión.</div>'; });
    }

    function toggleVisibilidadAlumno(id_alumno, id_actividad, checkbox) {
        let estado = checkbox.checked ? 1 : 0;
        fetch('procesos/docente_toggle_excepcion.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id_actividad=${id_actividad}&id_alumno=${id_alumno}&visible=${estado}`
        }).then(r => r.json()).then(data => { if(!data.success) { alert("Error al guardar permiso."); checkbox.checked = !checkbox.checked; } })
        .catch(() => checkbox.checked = !checkbox.checked);
    }

    function actualizarFechaExcepcion(id_alumno, id_actividad, fecha) {
        fetch('procesos/docente_fecha_excepcion.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id_actividad=${id_actividad}&id_alumno=${id_alumno}&fecha=${encodeURIComponent(fecha)}`
        }).then(r => r.json()).then(data => { if(!data.success) alert("Error al guardar la fecha límite especial."); });
    }

    async function accionMasivaExcepciones(id_actividad, estado, id_clase) {
        const mensajeConf = estado === 1 ? '¿Permitir entregas a TODOS los alumnos?' : '¿Bloquear entregas a TODOS los alumnos?';
        if (!(window.sigeConfirm ? await window.sigeConfirm(mensajeConf) : confirm(mensajeConf))) return;
        document.querySelectorAll('.exc-toggle').forEach(chk => chk.checked = (estado === 1));
        fetch('procesos/docente_toggle_excepcion_masiva.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id_actividad=${id_actividad}&visible=${estado}&id_clase=${id_clase}`
        }).then(r => r.json()).then(data => { if(!data.success) alert("Hubo un error procesando a todo el grupo."); });
    }

    // =======================================================
    // GESTIÓN DE ASISTENCIA (PASE DE LISTA)
    // =======================================================
    function cargarListaAsistencia() {
        const id_clase = document.getElementById('asist_id_clase').value;
        const fecha = document.getElementById('asist_fecha_input').value;
        const contenedor = document.getElementById('contenedorListaAsistencia');
        contenedor.innerHTML = '<div style="text-align:center; padding:30px; color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Cargando lista de asistencia...</div>';
        fetch(`procesos/obtener_lista_asistencia.php?id_clase=${id_clase}&fecha=${encodeURIComponent(fecha)}`)
        .then(res => res.text()).then(html => { contenedor.innerHTML = html; prepararListaAsistencia(); })
        .catch(() => { contenedor.innerHTML = '<div style="color:red; text-align:center; padding:20px;">Error al cargar la lista.</div>'; });
    }

    function abrirModalAsistencia(id_clase, nombre_materia) {
        document.getElementById('asist_id_clase').value = id_clase;
        document.getElementById('asist_nombre_materia').innerText = nombre_materia;
        document.getElementById('modalAsistencia').classList.add('active');
        cargarListaAsistencia();
    }

    const escHtml = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // Contadores, atajos y bloqueo del pase de lista
    function contarAsistencia() {
        const cont = document.getElementById('contenedorListaAsistencia');
        const cuentas = { Presente: 0, Retardo: 0, Falta: 0, Justificada: 0, '': 0 };
        cont.querySelectorAll('.alumno-asist-row').forEach(fila => {
            const marcado = fila.querySelector('input[type="radio"]:checked');
            cuentas[marcado ? marcado.value : '']++;
            if (marcado) fila.classList.remove('sin-marcar-resaltado');
        });
        cont.querySelectorAll('[data-cuenta]').forEach(b => { b.textContent = cuentas[b.dataset.cuenta] ?? 0; });
        return cuentas;
    }
    function prepararListaAsistencia() {
        const meta = document.getElementById('asistMeta');
        const bloqueada = meta && meta.dataset.bloqueo === '1';
        const btn = document.getElementById('btnGuardarAsistencia');
        if (btn) { btn.disabled = bloqueada; btn.style.opacity = bloqueada ? '.5' : ''; btn.style.cursor = bloqueada ? 'not-allowed' : ''; }
        contarAsistencia();
    }
    document.getElementById('contenedorListaAsistencia')?.addEventListener('change', e => {
        if (e.target.matches('input[type="radio"]')) contarAsistencia();
    });
    document.getElementById('contenedorListaAsistencia')?.addEventListener('click', e => {
        const b = e.target.closest('[data-atajo]');
        if (!b) return;
        const tipo = b.dataset.atajo;
        document.querySelectorAll('#contenedorListaAsistencia .alumno-asist-row').forEach(fila => {
            if (fila.querySelector('input[type="radio"]:checked')) return;      // nunca cambia lo que ya marcaste
            const entro = fila.dataset.plantel === 'entro';
            let valor = null;
            if (tipo === 'entraron' && entro) valor = 'Presente';
            if (tipo === 'no_entraron' && !entro) valor = 'Falta';
            if (tipo === 'todos') valor = 'Presente';
            if (valor) { const r = fila.querySelector(`input[value="${valor}"]`); if (r) r.checked = true; }
        });
        contarAsistencia();
    });
    document.getElementById('formAsistencia')?.addEventListener('submit', async function (e) {
        if (this.dataset.confirmado === '1') { this.dataset.confirmado = ''; return; }
        const meta = document.getElementById('asistMeta');
        if (meta && meta.dataset.bloqueo === '1') { e.preventDefault(); return; }
        const sin = contarAsistencia()[''];
        if (sin > 0) {
            e.preventDefault();
            document.querySelectorAll('#contenedorListaAsistencia .alumno-asist-row').forEach(f => {
                if (!f.querySelector('input[type="radio"]:checked')) f.classList.add('sin-marcar-resaltado');
            });
            const msg = `Hay ${sin} alumno(s) sin marcar (resaltados en rojo). ¿Guardar solo los que sí marcaste?`;
            const ok = window.sigeConfirm ? await window.sigeConfirm(msg, { titulo: 'Pase de lista incompleto', aceptar: 'Guardar así' }) : confirm(msg);
            if (ok) { this.dataset.confirmado = '1'; this.requestSubmit ? this.requestSubmit() : this.submit(); }
        }
    });

    document.getElementById('buscadorAsistenciaList')?.addEventListener('input', function(e) {
        let term = e.target.value.toLowerCase();
        document.querySelectorAll('.alumno-asist-row, .alum-row').forEach(item => {
            let texto = item.textContent.toLowerCase();
            item.style.display = texto.includes(term) ? 'flex' : 'none';
        });
    });

    const btnToggleAdvanced = document.getElementById('btn-toggle-advanced');
    const advancedContainer = document.getElementById('advanced-options-container');
    if(btnToggleAdvanced && advancedContainer) {
        btnToggleAdvanced.addEventListener('click', function() {
            if (advancedContainer.style.display === 'none') {
                advancedContainer.style.display = 'block';
                btnToggleAdvanced.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar Opciones Avanzadas';
                btnToggleAdvanced.style.background = '#e2e8f0';
            } else {
                advancedContainer.style.display = 'none';
                btnToggleAdvanced.innerHTML = '<i class="fas fa-sliders-h"></i> Mostrar Opciones Avanzadas y Programación';
                btnToggleAdvanced.style.background = 'none';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const idClaseAbrir = urlParams.get('abrir_lista');
        if (idClaseAbrir) {
            const botonLista = document.querySelector(`button[onclick*="abrirModalAsistencia(${idClaseAbrir},"]`);
            if (botonLista) setTimeout(() => { botonLista.click(); }, 400); 
        }
    });

    // =======================================================
    // ESCÁNER QR PARA ASISTENCIA (PC y Móvil + Cierre Seguro)
    // =======================================================
    let html5QrCode = null;
    let isScanning = true; 

    function abrirScannerQR() {
        document.getElementById('qr-scanner-container').style.display = 'block';
        let msgBox = document.getElementById('qr-message');
        msgBox.style.display = 'block';
        msgBox.style.backgroundColor = '#f1f5f9';
        msgBox.style.color = '#475569';
        msgBox.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Encendiendo cámara... (Acepta los permisos)';

        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader");
        }

        const config = { fps: 10, qrbox: { width: 200, height: 200 } };

        html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure)
        .then(() => { msgBox.style.display = 'none'; })
        .catch(err => {
            html5QrCode.start({ facingMode: "user" }, config, onScanSuccess, onScanFailure)
            .then(() => { msgBox.style.display = 'none'; })
            .catch(err2 => {
                msgBox.style.backgroundColor = '#fef2f2';
                msgBox.style.color = '#991b1b';
                msgBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error: No se pudo acceder a la cámara. Revisa los permisos o asegúrate de usar HTTPS/localhost.';
            });
        });
    }

    function cerrarScannerQR() {
        document.getElementById('qr-scanner-container').style.display = 'none';
        if (html5QrCode) {
            try {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    html5QrCode = null;
                }).catch(err => {
                    html5QrCode.clear();
                    html5QrCode = null;
                });
            } catch(e) {
                html5QrCode.clear();
                html5QrCode = null;
            }
        }
    }

    function onScanSuccess(decodedText, decodedResult) {
        if (!isScanning) return;
        isScanning = false; 

        let id_alumno_qr = decodedText.trim();
        let id_clase = document.getElementById('asist_id_clase').value;
        let fecha = document.getElementById('asist_fecha_input').value;

        let msgBox = document.getElementById('qr-message');
        msgBox.style.display = 'block';
        msgBox.style.backgroundColor = '#f1f5f9';
        msgBox.style.color = '#475569';
        msgBox.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

        // encodeURIComponent previene que si el QR tiene slashes ej: 27/2024 rompa la petición HTTP
        fetch('procesos/docente_guardar_asistencia_qr.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id_alumno=${encodeURIComponent(id_alumno_qr)}&id_clase=${id_clase}&fecha=${fecha}`
        })
        .then(response => response.json())
        .then(data => {
            // Actualización visual en la lista de abajo
            if (data.status === 'success' || data.status === 'warning') {
                if (data.id_alumno) {
                    let radioPresente = document.querySelector(`input[name="asistencia[${data.id_alumno}]"][value="Presente"]`);
                    if (radioPresente) {
                        radioPresente.checked = true;
                        contarAsistencia();
                        let filaAlumno = radioPresente.closest('.alum-row, .alumno-asist-row, div[style*="display: flex"]');
                        if (filaAlumno) {
                            let fondoOriginal = filaAlumno.style.backgroundColor;
                            filaAlumno.style.backgroundColor = '#dcfce7'; 
                            setTimeout(() => { filaAlumno.style.backgroundColor = fondoOriginal; }, 1500);
                        }
                    }
                }
            }

            if (data.status === 'success') {
                msgBox.style.backgroundColor = '#dcfce7'; 
                msgBox.style.color = '#166534'; 
                msgBox.innerHTML = '<i class="fas fa-check-circle"></i> ' + escHtml(data.message || 'Asistencia tomada.');
            } else if (data.status === 'warning') {
                msgBox.style.backgroundColor = '#fef3c7'; 
                msgBox.style.color = '#b45309'; 
                msgBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + escHtml(data.message || 'El alumno ya fue capturado.');
            } else {
                msgBox.style.backgroundColor = '#fef2f2'; 
                msgBox.style.color = '#991b1b'; 
                msgBox.innerHTML = `<i class="fas fa-times-circle"></i> ${escHtml(data.message || 'No se pudo, vuelve a intentar.')}`;
            }
            
            setTimeout(() => {
                msgBox.style.display = 'none';
                isScanning = true;
            }, 2000);
        })
        .catch(error => {
            msgBox.style.backgroundColor = '#fef2f2';
            msgBox.style.color = '#991b1b';
            msgBox.innerHTML = '<i class="fas fa-times-circle"></i> Error de red.';
            setTimeout(() => { isScanning = true; msgBox.style.display = 'none'; }, 2000);
        });
    }

    function onScanFailure(error) {
        // Vacío para no saturar la consola
    }

    // =======================================================
    // VISTA PREVIA (MODAL IFRAME)
    // =======================================================
    function abrirVistaPrevia(id_actividad) {
        // Le pasamos el ID al archivo y lo metemos en el iframe
        document.getElementById('iframeVistaPrevia').src = 'vista_previa_actividad.php?id_actividad=' + id_actividad;
        document.getElementById('modalVistaPrevia').classList.add('active');
    }

    function abrirModalRestaurarMateria(id) {
        document.getElementById('rest_mat_id').value = id;
        document.getElementById('modalRestaurarMateria').classList.add('active');
    }
</script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>