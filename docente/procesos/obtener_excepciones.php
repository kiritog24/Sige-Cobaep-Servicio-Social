<?php
/**
 * docente/procesos/obtener_excepciones.php — Lista "Visibilidad por alumno" de una actividad (se carga por AJAX).
 * El buscador y los switches se manejan desde gestionar_aula.php (los <script> que llegan por AJAX no se ejecutan).
 */
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    echo "Acceso denegado.";
    exit();
}

$id_actividad = isset($_GET['id_actividad']) ? (int)$_GET['id_actividad'] : 0;
require_once __DIR__ . '/permisos.php';
if (!docente_dueno_actividad($pdo, $id_actividad)) docente_denegar(true);
asegurar_columnas_actividades($pdo);

try {
    $act = $pdo->prepare("SELECT id_clase, solo_asistentes, fecha_filtro FROM actividades_clase WHERE id_actividad = ?");
    $act->execute([$id_actividad]);
    $act = $act->fetch(PDO::FETCH_ASSOC);
    $id_clase = (int)$act['id_clase'];
    $filtro = (int)$act['solo_asistentes'] === 1;

    $stmt = $pdo->prepare("
        SELECT a.id_alumno, a.nombre, a.apellido_paterno, a.apellido_materno, a.matricula, a.fotografia,
               va.visible AS decision, va.fecha_limite_excepcion,
               EXISTS (SELECT 1 FROM asistencias_clases fx WHERE fx.id_clase = ? AND fx.id_alumno = a.id_alumno
                       AND fx.fecha = ? AND fx.estatus = 'Falta') AS falto
        FROM clase_regular_alumno cra
        INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno
        LEFT JOIN visibilidad_actividad va ON a.id_alumno = va.id_alumno AND va.id_actividad = ?
        WHERE cra.id_clase = ?
        ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre
    ");
    $stmt->execute([$id_clase, $act['fecha_filtro'] ?: '1000-01-01', $id_actividad, $id_clase]);
    $alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('obtener_excepciones: ' . $e->getMessage());
    echo "<div style='text-align:center; padding:15px; color:#b91c1c;'>No se pudo cargar la lista.</div>";
    exit;
}

if (!$alumnos) {
    echo "<div style='text-align:center; padding:15px; color:#64748b;'>No hay alumnos registrados en esta materia.</div>";
    exit;
}
?>
<div class="vis-acciones">
    <button type="button" class="btn-outline vis-btn-si" onclick="accionMasivaExcepciones(<?php echo $id_actividad; ?>, 1, <?php echo $id_clase; ?>)"><i class="fas fa-check-double"></i> Permitir a todos</button>
    <button type="button" class="btn-outline vis-btn-no" onclick="accionMasivaExcepciones(<?php echo $id_actividad; ?>, 0, <?php echo $id_clase; ?>)"><i class="fas fa-ban"></i> Ocultar a todos</button>
</div>
<?php if ($filtro): ?>
    <p class="vis-filtro"><i class="fas fa-user-slash"></i> Esta actividad tiene filtro de asistencia: se oculta a quien tenga <b>Falta</b> en tu pase de lista del <?php echo date('d/m/Y', strtotime($act['fecha_filtro'])); ?>. Si enciendes el switch de un alumno, lo verá aunque haya faltado.</p>
<?php endif; ?>
<div class="search-box vis-buscador">
    <i class="fas fa-search"></i>
    <input type="search" id="buscadorExcepciones" placeholder="Buscar por nombre o matrícula..." autocomplete="off" onkeydown="return event.key !== 'Enter';">
</div>
<p class="vis-cuenta" id="excCuenta"></p>
<div id="listaExcScroll" class="lista-scroll vis-lista">
<?php foreach ($alumnos as $alum):
    $nombre = trim($alum['apellido_paterno'] . ' ' . $alum['apellido_materno'] . ' ' . $alum['nombre']);
    $oculto_filtro = $alum['decision'] === null && $filtro && (int)$alum['falto'] === 1;
    $puede_ver = $alum['decision'] !== null ? (int)$alum['decision'] === 1 : !$oculto_filtro;
    $fecha_val = $alum['fecha_limite_excepcion'] ? date('Y-m-d\TH:i', strtotime($alum['fecha_limite_excepcion'])) : '';
    $buscar = mb_strtolower($alum['matricula'] . ' ' . $nombre . ' ' . $alum['nombre'] . ' ' . $alum['apellido_paterno'], 'UTF-8');
    $id = (int)$alum['id_alumno'];
?>
    <div class="alumno-exc-item" data-buscar="<?php echo htmlspecialchars($buscar); ?>">
        <div class="exc-alumno">
            <?php echo avatar_html($alum['fotografia'] ?? null, $alum['nombre'] . ' ' . $alum['apellido_paterno'], 'alumno-avatar exc-avatar', 'overflow:hidden;'); ?>
            <div class="alumno-info">
                <strong class="exc-nombre"><?php echo htmlspecialchars($nombre); ?></strong>
                <span class="exc-mat">Mat: <?php echo htmlspecialchars($alum['matricula']); ?></span>
                <?php if ($oculto_filtro): ?><span class="exc-filtro"><i class="fas fa-user-slash"></i> Oculta por falta</span><?php endif; ?>
            </div>
        </div>
        <div class="exc-controles">
            <label class="exc-fecha">
                <span>Fecha límite especial</span>
                <input type="datetime-local" class="form-control" value="<?php echo $fecha_val; ?>" onchange="actualizarFechaExcepcion(<?php echo $id; ?>, <?php echo $id_actividad; ?>, this.value)">
            </label>
            <div class="exc-switch">
                <span>Puede verla</span>
                <div class="switch" title="Mostrar u ocultar la actividad a este alumno">
                    <input type="checkbox" class="rojo exc-toggle" id="vis_alum_<?php echo $id; ?>" <?php echo $puede_ver ? 'checked' : ''; ?> onchange="toggleVisibilidadAlumno(<?php echo $id; ?>, <?php echo $id_actividad; ?>, this)">
                    <label class="slider" for="vis_alum_<?php echo $id; ?>"></label>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
    <p id="excSinResultados" class="vis-vacio" style="display:none;"><i class="fas fa-search"></i> Ningún alumno coincide con la búsqueda.</p>
</div>
