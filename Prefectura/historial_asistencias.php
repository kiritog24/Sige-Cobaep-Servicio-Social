<?php
session_start();
require_once '../config/database.php'; 
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}

$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Prefecto';

$filtro_matricula = trim($_GET['matricula'] ?? '');
$filtro_fecha = trim($_GET['fecha'] ?? '');

// 1. SE AGREGÓ 'a.motivo_justificacion' A LA CONSULTA SQL
$query = "
    SELECT a.id_asistencia, a.fecha_hora_escaneo, a.fecha_hora_salida, a.tipo_registro, 
           al.matricula, al.nombre, al.apellido_paterno, a.estatus, a.motivo_justificacion,
           g.semestre, g.nombre_grupo, g.turno 
    FROM asistencias a
    INNER JOIN alumnos al ON a.id_alumno = al.id_alumno
    LEFT JOIN grupo_alumno ga ON al.id_alumno = ga.id_alumno AND ga.fecha_baja IS NULL
    LEFT JOIN grupos g ON ga.id_grupo = g.id_grupo
    WHERE 1=1
";
$params = [];

if (empty($filtro_matricula) && empty($filtro_fecha)) {
    $filtro_fecha = date('Y-m-d'); 
}

if (!empty($filtro_matricula)) {
    $query .= " AND al.matricula LIKE :matricula";
    $params[':matricula'] = '%' . $filtro_matricula . '%';
}

if (!empty($filtro_fecha)) {
    $query .= " AND DATE(a.fecha_hora_escaneo) = :fecha";
    $params[':fecha'] = $filtro_fecha;
}

$query .= " ORDER BY a.fecha_hora_escaneo DESC LIMIT 200";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historial = [];
}

// Alumnos sin ningún registro de entrada ese día (no vinieron o aún no llegan): se pueden justificar desde aquí
$sin_registro = [];
$sin_clases_fecha = null;
if (fecha_valida($filtro_fecha) && $filtro_fecha <= date('Y-m-d')) {
    $sin_clases_fecha = dia_sin_clases($pdo, $filtro_fecha);
    if (!$sin_clases_fecha) {
        $sql_sr = "SELECT al.id_alumno, al.matricula, al.nombre, al.apellido_paterno, al.apellido_materno, g.semestre, g.nombre_grupo, g.turno
                   FROM alumnos al
                   INNER JOIN grupo_alumno ga ON ga.id_alumno = al.id_alumno AND ga.fecha_asignacion <= :f1 AND (ga.fecha_baja IS NULL OR ga.fecha_baja > :f2)
                   INNER JOIN grupos g ON g.id_grupo = ga.id_grupo AND g.estatus = 'Activo'
                   WHERE al.estatus = 'Alta'
                     AND NOT EXISTS (SELECT 1 FROM asistencias x WHERE x.id_alumno = al.id_alumno AND x.tipo_registro = 'Entrada' AND DATE(x.fecha_hora_escaneo) = :f3)";
        $p_sr = [':f1' => $filtro_fecha, ':f2' => $filtro_fecha, ':f3' => $filtro_fecha];
        if ($filtro_matricula !== '') { $sql_sr .= " AND al.matricula LIKE :m"; $p_sr[':m'] = '%' . $filtro_matricula . '%'; }
        try {
            $st_sr = $pdo->prepare($sql_sr . " GROUP BY al.id_alumno ORDER BY g.semestre, g.nombre_grupo, al.apellido_paterno, al.nombre");
            $st_sr->execute($p_sr);
            $sin_registro = $st_sr->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { error_log('historial sin registro: ' . $e->getMessage()); }
    }
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
    <title>Historial de Asistencias · COBAEP 27</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">
    <link rel="stylesheet" href="../Prefectura/style_historial_asistencias.css">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    
    <?php
    $mostrar_modal = '';
    if (isset($_GET['msg']) && $_GET['msg'] === 'exito_justificante') {
        $n_cl = (int)($_GET['clases'] ?? 0);
        $txt_ok = 'El justificante se ha guardado correctamente con su motivo.' . ($n_cl ? " También se justificaron $n_cl falta(s) que tenía en sus clases ese día." : '');
        $mostrar_modal = "document.getElementById('modalExito').style.display = 'flex';
                          if(document.getElementById('exitoText')) document.getElementById('exitoText').innerText = " . json_encode($txt_ok, JSON_UNESCAPED_UNICODE) . ";";
    } elseif (isset($_GET['error'])) {
        $mensaje_error = "Ocurrió un error al procesar el justificante.";
        if ($_GET['error'] === 'no_aplica') $mensaje_error = "No se puede justificar este registro (ya está justificado, puntual o suspendido).";
        if ($_GET['error'] === 'id_invalido' || $_GET['error'] === 'registro_no_encontrado') $mensaje_error = "El registro seleccionado no es válido o ya no existe.";
        if ($_GET['error'] === 'ya_tiene_registro') $mensaje_error = "El alumno ya tiene un registro ese día; justifícalo desde la tabla.";
        
        $mostrar_modal = "document.getElementById('modalError').style.display = 'flex';
                          if(document.getElementById('errorText')) document.getElementById('errorText').innerText = '$mensaje_error';";
    }
    
    if (!empty($mostrar_modal)) {
        echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    $mostrar_modal
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
              </script>";
    }
    ?>

    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>

     <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div><span class="eyebrow">PREFECTURA · REPORTES</span><h1>Historial de Asistencias</h1><p>Busca y gestiona el registro de entradas y salidas por matrícula o fecha.</p></div>
            </div>

            <?php topbar_usuario($pdo); ?>
        </header>

        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>Buscar por Matrícula</label>
                <input type="text" name="matricula" class="filter-input" placeholder="Ej. 27/2026B0001" value="<?php echo htmlspecialchars($filtro_matricula); ?>">
            </div>
            <div class="filter-group">
                <label>Filtrar por Fecha</label>
                <input type="date" name="fecha" class="filter-input" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Buscar</button>
            <?php if (!empty($filtro_matricula) || (isset($_GET['fecha']) && !empty($_GET['fecha']))): ?>
                <a href="historial_asistencias.php" class="btn-clear">Limpiar</a>
            <?php endif; ?>
            
            <button type="button" class="btn-filter" style="background-color: #0f172a; margin-left: auto;" onclick="document.getElementById('modalListaGrupo').style.display='flex'">
                <i class="fas fa-list-ol"></i> Generar Lista por Grupo
            </button>
        </form>

        <section class="overview-card" style="padding: 0; overflow: hidden;">
            <div class="table-responsive" style="margin-top: 0;">
                <table>
                    <thead>
                        <tr>
                            <th>Entrada</th>
                            <th>Salida</th>
                            <th>Matrícula</th>
                            <th>Alumno</th>
                            <th>Grado y Grupo</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historial)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--muted); padding: 3rem;">No se encontraron registros que coincidan con la búsqueda.</td></tr>
                        <?php else: 
                            $fecha_actual_agrupacion = ''; 
                            
                            foreach ($historial as $row): 
                                $fecha_fila_pura = date('Y-m-d', strtotime($row['fecha_hora_escaneo']));
                                
                                if ($fecha_actual_agrupacion !== $fecha_fila_pura) {
                                    $fecha_actual_agrupacion = $fecha_fila_pura;
                                    $fecha_formateada_titulo = date('d/m/Y', strtotime($row['fecha_hora_escaneo']));
                                    
                                    echo '<tr class="date-divider">';
                                    echo '<td colspan="7"><i class="far fa-calendar-alt" style="margin-right: 8px;"></i> Asistencias del día: ' . $fecha_formateada_titulo . '</td>';
                                    echo '</tr>';
                                }
                                $hora_entrada = date('h:i A', strtotime($row['fecha_hora_escaneo']));
                                if ($row['estatus'] === 'Falta Justificada' && date('H:i', strtotime($row['fecha_hora_escaneo'])) === '00:00') $hora_entrada = '—';

                                $estado_str = strtolower($row['estatus'] ?? '');
                                $clase_badge = 'status-puntual'; 
                                $clase_fila = '';
                                $texto_estatus = !empty($row['estatus']) ? $row['estatus'] : 'Falta';

                                if ($estado_str === 'retardo') {
                                    $clase_badge = 'status-retardo';
                                } elseif ($estado_str === 'falta' || empty($estado_str)) {
                                    $clase_badge = 'status-falta';
                                    $clase_fila = 'fila-falta';
                                    $texto_estatus = 'Falta';
                                } elseif ($estado_str === 'suspendido') {
                                    $clase_badge = 'status-suspendido';
                                    $clase_fila = 'fila-suspendido'; 
                                    $texto_estatus = 'Suspendido';
                                } elseif ($estado_str === 'retardo justificado') {
                                    $clase_badge = 'status-puntual';
                                    $texto_estatus = 'Puntual (Just.)';
                                } elseif ($estado_str === 'falta justificada') {
                                    $clase_badge = 'status-justificada';
                                }
                        ?>
                            <tr class="<?php echo $clase_fila; ?>">
                                <td><strong><?php echo $hora_entrada; ?></strong></td>
                                
                                <td>
                                    <?php 
                                    if (!empty($row['fecha_hora_salida'])) {
                                        echo '<strong>' . date('h:i A', strtotime($row['fecha_hora_salida'])) . '</strong>';
                                    } else {
                                        echo '<span style="color: var(--muted);">-</span>';
                                    }
                                    ?>
                                </td>
                                
                                <td><?php echo htmlspecialchars($row['matricula']); ?></td>
                                <td><?php echo htmlspecialchars($row['apellido_paterno'] . ', ' . $row['nombre']); ?></td>
                                <td>
                                    <?php 
                                        if (!empty($row['semestre']) && !empty($row['nombre_grupo'])) {
                                            echo htmlspecialchars($row['semestre'] . '° "' . $row['nombre_grupo'] . '" - ' . $row['turno']);
                                        } else {
                                            echo '<span style="color: var(--muted); font-style: italic;">Sin asignar</span>';
                                        }
                                    ?>
                                </td>
                                <td><span class="status-badge-table <?php echo $clase_badge; ?>"><?php echo htmlspecialchars($texto_estatus); ?></span></td>
                                
                                <!-- 2. SE ACTUALIZÓ LA LÓGICA DE LA COLUMNA ACCIONES PARA MOSTRAR EL MOTIVO -->
                                <td>
                                    <?php if ($estado_str === 'falta' || $estado_str === 'retardo' || empty($estado_str)): ?>
                                        <button type="button" class="btn-justificar" onclick="abrirModalJustificar(<?php echo $row['id_asistencia']; ?>, '<?php echo htmlspecialchars($row['apellido_paterno'] . ' ' . $row['nombre'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-file-signature"></i> Justificar
                                        </button>
                                    
                                    <?php elseif ($estado_str === 'falta justificada' || $estado_str === 'retardo justificado'): ?>
                                        <div style="font-size: 0.75rem; color: #475569; max-width: 180px; line-height: 1.3;">
                                            <i class="fas fa-info-circle" style="color: var(--green); margin-right: 3px;"></i> 
                                            <?php 
                                                // Muestra el motivo si existe en la BD, si no, muestra un texto por defecto
                                                echo !empty($row['motivo_justificacion']) 
                                                    ? htmlspecialchars($row['motivo_justificacion']) 
                                                    : 'Justificado en el sistema.'; 
                                            ?>
                                        </div>
                                        
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- MODAL PARA SELECCIONAR GRUPO Y FECHA -->
            <div class="modal-overlay" id="modalListaGrupo" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(3, 43, 30, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; z-index: 2000;">
                <div class="modal" style="background: #ffffff; padding: 2.5rem; border-radius: var(--radius-lg); width: 90%; max-width: 450px; box-shadow: var(--shadow-lg);">
                    <h3 style="margin-bottom: 0.5rem; color: var(--ink); font-weight: 800;"><i class="fas fa-users" style="color: var(--green);"></i> Listas de Asistencia</h3>
                    <p style="font-size: 0.8rem; color: var(--muted); margin-bottom: 1.5rem;">Selecciona la fecha y el grupo registrado en el sistema para visualizar la lista.</p>
                    
                    <?php
                    try {
                        $stmt_grupos = $pdo->query("SELECT id_grupo, semestre, nombre_grupo FROM grupos ORDER BY semestre ASC, nombre_grupo ASC");
                        $grupos_disponibles = $stmt_grupos->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $grupos_disponibles = [];
                    }
                    ?>
                    
                    <form action="vista_previa_lista.php" method="GET">
                        <div class="form-group" style="margin-bottom: 1.2rem;">
                            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--ink); margin-bottom: 0.5rem;">Fecha de la lista</label>
                            <input type="date" name="fecha_lista" class="form-control" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 0.85rem; border: 1px solid var(--line); border-radius: var(--radius-sm);">
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 1.2rem;">
                            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--ink); margin-bottom: 0.5rem;">Selecciona el Grupo</label>
                            <select name="id_grupo" class="form-control" required style="width: 100%; padding: 0.85rem; border: 1px solid var(--line); border-radius: var(--radius-sm);">
                                <option value="">-- Seleccionar Grupo --</option>
                                <?php foreach ($grupos_disponibles as $g): ?>
                                    <option value="<?php echo $g['id_grupo']; ?>">
                                        <?php echo htmlspecialchars($g['semestre'] . '° "' . $g['nombre_grupo'] . '"'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                            <button type="button" class="btn btn-cancel" onclick="document.getElementById('modalListaGrupo').style.display='none'" style="padding: 0.75rem 1.5rem; border: 1px solid var(--line); border-radius: var(--radius-sm); cursor: pointer; background: #f1f5f9;">Cancelar</button>
                            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem; background: var(--green); color: white; border: none; border-radius: var(--radius-sm); cursor: pointer;">Generar Vista Previa</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <?php if ($sin_clases_fecha): ?>
            <div style="margin-top:16px; padding:12px 16px; border-radius:12px; background:#f1f5f9; color:#334155; font-weight:600; font-size:.88rem;"><i class="fas fa-school-circle-xmark"></i> Ese día no hubo clases (<?php echo htmlspecialchars($sin_clases_fecha); ?>): nadie tiene falta.</div>
        <?php elseif ($sin_registro): ?>
        <details class="overview-card sin-registro" style="margin-top:16px; padding:0; overflow:hidden;">
            <summary style="padding:14px 18px; cursor:pointer; font-weight:700; color:#0f172a; display:flex; flex-wrap:wrap; align-items:center; gap:6px 10px;">
                <i class="fas fa-user-clock" style="color:#b91c1c;"></i> <?php echo count($sin_registro); ?> alumno(s) sin registro de entrada el <?php echo date('d/m/Y', strtotime($filtro_fecha)); ?>
                <small style="font-weight:500; color:var(--muted);"><?php echo $filtro_fecha === date('Y-m-d') ? '(aún no llegan o faltaron)' : '(cuentan como falta)'; ?> · clic para ver y justificar</small>
            </summary>
            <div class="table-responsive" style="margin-top:0;">
                <table>
                    <thead><tr><th>Matrícula</th><th>Alumno</th><th>Grado y Grupo</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($sin_registro as $sr): $nom_sr = trim($sr['apellido_paterno'] . ' ' . $sr['apellido_materno'] . ' ' . $sr['nombre']); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sr['matricula']); ?></td>
                            <td><?php echo htmlspecialchars($nom_sr); ?></td>
                            <td><?php echo htmlspecialchars($sr['semestre'] . '° "' . $sr['nombre_grupo'] . '" - ' . $sr['turno']); ?></td>
                            <td><button type="button" class="btn-justificar" onclick="abrirModalJustificar(0, <?php echo htmlspecialchars(json_encode($nom_sr, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo (int)$sr['id_alumno']; ?>, '<?php echo htmlspecialchars($filtro_fecha); ?>')"><i class="fas fa-file-signature"></i> Justificar falta</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
    </main>

    <!-- 3. MODAL DE FORMULARIO PARA JUSTIFICAR ASISTENCIA -->
    <div class="modal-overlay" id="modalJustificar" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(3, 43, 30, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; z-index: 2000;">
        <div class="modal" style="background: #ffffff; padding: 2.5rem; border-radius: var(--radius-lg); width: 90%; max-width: 450px; box-shadow: var(--shadow-lg);">
            <h3 style="margin-bottom: 0.5rem; color: var(--ink); font-weight: 800;"><i class="fas fa-file-signature" style="color: var(--green);"></i> Justificar Asistencia</h3>
            <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 1.5rem;">Alumno: <strong id="nombreAlumnoJustificar" style="color: var(--ink);"></strong></p>
            
            <form action="procesos/justificar.php" method="POST">
                <input type="hidden" name="id_asistencia" id="idAsistenciaModal">
                <input type="hidden" name="id_alumno" id="idAlumnoModal">
                <input type="hidden" name="fecha" id="fechaModal">
                <input type="hidden" name="volver_fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                <input type="hidden" name="volver_matricula" value="<?php echo htmlspecialchars($filtro_matricula); ?>">
                <p id="avisoAusencia" style="display:none; font-size:.8rem; color:#1d4ed8; background:#eff6ff; padding:8px 10px; border-radius:8px; margin:0 0 1rem;"><i class="fas fa-circle-info"></i> El alumno no tiene registro ese día: se guardará como falta justificada de todo el día y sus faltas en clases quedarán justificadas.</p>
                
                <div class="form-group" style="margin-bottom: 1.2rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--ink); margin-bottom: 0.5rem;">Motivo principal</label>
                    <select name="motivo" class="form-control" required style="width: 100%; padding: 0.85rem; border: 1px solid var(--line); border-radius: var(--radius-sm);">
                        <option value="">-- Seleccionar un motivo --</option>
                        <option value="Retraso por transporte público">Retraso por transporte público</option>
                        <option value="Cita médica / Problema de salud">Cita médica / Problema de salud</option>
                        <option value="Problema familiar / Asunto personal">Problema familiar / Asunto personal</option>
                        <option value="Trámite administrativo escolar">Trámite administrativo escolar</option>
                        <option value="Clima / Inclemencias del tiempo">Clima / Inclemencias del tiempo</option>
                        <option value="Otro">Otro (Especificar en detalles)</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--ink); margin-bottom: 0.5rem;">Detalles adicionales (Opcional)</label>
                    <textarea name="detalles" class="form-control" rows="2" style="width: 100%; padding: 0.85rem; border: 1px solid var(--line); border-radius: var(--radius-sm); font-family: 'Inter', sans-serif;" placeholder="Ej. Entregó receta médica sellada..."></textarea>
                </div>
                
                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 1rem;">
                    <button type="button" class="btn btn-cancel" onclick="document.getElementById('modalJustificar').style.display='none'" style="padding: 0.75rem 1.5rem; border: 1px solid var(--line); border-radius: var(--radius-sm); cursor: pointer; background: #f1f5f9;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem; background: var(--green); color: white; border: none; border-radius: var(--radius-sm); cursor: pointer; font-weight: bold;">Guardar Justificante</button>
                </div>
            </form>
        </div>
    </div>

    <!-- INCLUSIÓN DE LOS MODALES DE ALERTA AL FINAL DE LA PÁGINA -->
    <div class="alert-modal-overlay" id="modalExito" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(4px); z-index: 9999; justify-content: center; align-items: center;">
        <div class="alert-modal-box" style="background: #252525; color: #f1f5f9; width: 90%; max-width: 480px; padding: 2.5rem; border-radius: 12px; border-top: 4px solid #10b981;">
            <div class="alert-modal-title" style="display: flex; align-items: center; gap: 12px; margin-bottom: 1.2rem; font-size: 1.15rem; font-weight: 700;">
                <i class="fas fa-check-circle" style="color: #10b981; font-size: 1.4rem;"></i>
                <span style="color: #10b981;">¡ACCIÓN EXITOSA!</span>
            </div>
            <div class="alert-modal-text" id="exitoText" style="font-size: 0.95rem; line-height: 1.6; color: #cbd5e1; margin-bottom: 1.5rem;">Operación realizada correctamente.</div>
            <div class="alert-modal-footer" style="display: flex; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" class="alert-modal-btn" onclick="document.getElementById('modalExito').style.display='none'" style="background: #cbd5e1; color: #0f172a; border: none; padding: 0.75rem 1.8rem; border-radius: 99px; font-weight: 600; cursor: pointer;">Aceptar</button>
            </div>
        </div>
    </div>

    <div class="alert-modal-overlay" id="modalError" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(4px); z-index: 9999; justify-content: center; align-items: center;">
        <div class="alert-modal-box" style="background: #252525; color: #f1f5f9; width: 90%; max-width: 480px; padding: 2.5rem; border-radius: 12px; border-top: 4px solid #ef4444;">
            <div class="alert-modal-title" style="display: flex; align-items: center; gap: 12px; margin-bottom: 1.2rem; font-size: 1.15rem; font-weight: 700;">
                <i class="fas fa-exclamation-triangle" style="color: #ef4444; font-size: 1.4rem;"></i>
                <span style="color: #ef4444;">ERROR DE SISTEMA</span>
            </div>
            <div class="alert-modal-text" id="errorText" style="font-size: 0.95rem; line-height: 1.6; color: #cbd5e1; margin-bottom: 1.5rem;">No se pudo procesar la solicitud.</div>
            <div class="alert-modal-footer" style="display: flex; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" class="alert-modal-btn" onclick="document.getElementById('modalError').style.display='none'" style="background: #cbd5e1; color: #0f172a; border: none; padding: 0.75rem 1.8rem; border-radius: 99px; font-weight: 600; cursor: pointer;">Aceptar</button>
            </div>
        </div>
    </div>

    
    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/modal_justificacion.js"></script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>

    