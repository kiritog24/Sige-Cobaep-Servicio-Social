<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

$id_actividad = isset($_GET['id_actividad']) ? (int)$_GET['id_actividad'] : 0;
if ($id_actividad === 0) {
    header("Location: mis_grupos.php?error=Actividad no especificada.");
    exit();
}

asegurar_columnas_actividades($pdo);
try {
    // La actividad debe pertenecer a una clase del docente que la consulta
    $stmtAct = $pdo->prepare("
        SELECT ac.titulo, ac.fecha_limite, ac.id_clase, ac.permite_entregas, m.nombre_materia, m.imagen_portada, g.semestre, g.nombre_grupo
        FROM actividades_clase ac
        INNER JOIN clases_regulares c ON c.id_clase = ac.id_clase
        INNER JOIN materias m ON m.id_materia = c.id_materia
        LEFT JOIN grupos g ON g.id_grupo = c.id_grupo
        WHERE ac.id_actividad = ? AND c.id_maestro = ?
    ");
    $stmtAct->execute([$id_actividad, $_SESSION['id_usuario']]);
    $actividad = $stmtAct->fetch(PDO::FETCH_ASSOC);

    if (!$actividad) {
        header("Location: mis_grupos.php?error=" . urlencode("La actividad no existe o no pertenece a tus clases."));
        exit();
    }
    $id_clase = (int)$actividad['id_clase'];

    $stmtEntregas = $pdo->prepare("
        SELECT a.id_alumno, a.nombre, a.apellido_paterno, a.apellido_materno, a.matricula,
               e.id_entrega, e.archivo_ruta, e.fecha_entrega, e.estatus_entrega, e.retroalimentacion,
               e.calificacion, e.revisada, e.fecha_revision
        FROM clase_regular_alumno cra
        INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno
        LEFT JOIN entregas_actividad e ON a.id_alumno = e.id_alumno AND e.id_actividad = ?
        WHERE cra.id_clase = ?
        ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre
    ");
    $stmtEntregas->execute([$id_actividad, $id_clase]);
    $alumnos_entregas = $stmtEntregas->fetchAll(PDO::FETCH_ASSOC);
    $archivos_por_entrega = archivos_de_entregas($pdo, $alumnos_entregas);
} catch (PDOException $e) {
    error_log('ver_entregas: ' . $e->getMessage());
    header("Location: mis_grupos.php?error=" . urlencode("No se pudieron cargar las entregas."));
    exit();
}

$total = count($alumnos_entregas);
$entregaron = $revisadas = 0; $suma = 0; $con_calif = 0;
foreach ($alumnos_entregas as $a) {
    if ($a['id_entrega']) $entregaron++;
    if ($a['id_entrega'] && (int)$a['revisada'] === 1) $revisadas++;
    if ($a['calificacion'] !== null) { $suma += (float)$a['calificacion']; $con_calif++; }
}
$promedio = $con_calif ? round($suma / $con_calif, 1) : null;
$portada = (!empty($actividad['imagen_portada']) && is_file(__DIR__ . '/../' . $actividad['imagen_portada'])) ? '../' . $actividad['imagen_portada'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entregas de Actividad · Portal Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_docente.css">
    <link rel="stylesheet" href="css/ver_entregas.css?v=4">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body class="pagina-entregas">
    <main class="main-content entregas-main">
        <div class="header-entregas<?php echo $portada ? ' con-portada' : ''; ?>"<?php if ($portada): ?> style="background-image: url('<?php echo htmlspecialchars($portada, ENT_QUOTES); ?>');"<?php endif; ?>>
            <div class="he-texto">
                <span class="he-eyebrow"><i class="fas fa-book"></i> <?php echo htmlspecialchars($actividad['nombre_materia']); ?><?php if ($actividad['nombre_grupo']): ?> · Grupo <?php echo htmlspecialchars($actividad['semestre'] . '° "' . $actividad['nombre_grupo'] . '"'); ?><?php endif; ?></span>
                <h2><?php echo htmlspecialchars($actividad['titulo']); ?></h2>
                <span class="he-sub"><i class="fas fa-inbox"></i> Entregas y evaluación<?php if ($actividad['fecha_limite']): ?> · Límite: <?php echo date('d/m/Y H:i', strtotime($actividad['fecha_limite'])); ?><?php endif; ?></span>
            </div>
            <a href="gestionar_aula.php?id_clase=<?php echo $id_clase; ?>" class="he-volver"><i class="fas fa-arrow-left"></i> Volver a la clase</a>
        </div>

        <div class="resumen-entregas">
            <div><strong><?php echo $entregaron; ?>/<?php echo $total; ?></strong><span>Entregaron</span></div>
            <div><strong><?php echo $revisadas; ?></strong><span>Revisadas</span></div>
            <div><strong><?php echo max(0, $entregaron - $revisadas); ?></strong><span>Por revisar</span></div>
            <div><strong><?php echo $promedio !== null ? calificacion_texto($promedio) : '—'; ?></strong><span>Promedio</span></div>
        </div>

        <?php if (isset($_GET['exito'])): ?>
            <div class="msg msg-ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['exito']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="msg msg-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <?php if ((int)$actividad['permite_entregas'] !== 1): ?>
            <div class="msg msg-info"><i class="fas fa-circle-info"></i> Esta actividad no pide subir archivo (es material de apoyo), por eso los alumnos no tienen entregas.</div>
        <?php endif; ?>

        <div class="tabla-wrap">
        <table class="table-entregas">
            <thead>
                <tr>
                    <th>Alumno</th>
                    <th>Estado</th>
                    <th>Fecha de subida</th>
                    <th>Archivos</th>
                    <th>Calificación</th>
                    <th>Evaluación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$alumnos_entregas): ?>
                    <tr><td colspan="6" style="text-align:center; color:var(--muted);">No hay alumnos en esta materia.</td></tr>
                <?php endif; ?>
                <?php foreach ($alumnos_entregas as $alumno):
                    $estatus = (string)$alumno['estatus_entrega'];
                    $revisada = (int)$alumno['revisada'] === 1;
                    $nombre = trim($alumno['apellido_paterno'] . ' ' . $alumno['apellido_materno'] . ' ' . $alumno['nombre']);
                ?>
                <tr>
                    <td data-label="" class="td-alumno">
                        <strong><?php echo htmlspecialchars($nombre); ?></strong><br>
                        <span style="font-size:0.75rem; color:var(--muted);">Mat: <?php echo htmlspecialchars($alumno['matricula']); ?></span>
                    </td>
                    <td data-label="Estado">
                        <?php if (!$alumno['id_entrega']): ?>
                            <span class="badge-status badge-faltante"><i class="fas fa-times"></i> Sin entregar</span>
                        <?php else: ?>
                            <?php if ($estatus === 'Con Retardo'): ?>
                                <span class="badge-status badge-retardo"><i class="fas fa-clock"></i> Con retardo</span>
                            <?php else: ?>
                                <span class="badge-status badge-tiempo"><i class="fas fa-check"></i> A tiempo</span>
                            <?php endif; ?>
                            <?php if ($revisada): ?>
                                <span class="badge-status badge-revisada"><i class="fas fa-check-double"></i> Revisada</span>
                            <?php else: ?>
                                <span class="badge-status badge-pendiente"><i class="fas fa-hourglass-half"></i> Por revisar</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Fecha de subida"><?php echo $alumno['fecha_entrega'] ? date('d/m/Y H:i', strtotime($alumno['fecha_entrega'])) : '<span style="color:#94a3b8;">—</span>'; ?></td>
                    <td data-label="Archivos">
                        <?php $archs = $archivos_por_entrega[(int)$alumno['id_entrega']] ?? []; ?>
                        <?php if ($archs): ?>
                            <div class="lista-descargas">
                            <?php foreach ($archs as $arch): ?>
                                <a href="../uploads/entregas_alumnos/<?php echo htmlspecialchars(rawurlencode($arch['ruta'])); ?>" class="btn-descargar" target="_blank" download="<?php echo htmlspecialchars($arch['nombre']); ?>" title="<?php echo htmlspecialchars($arch['nombre']); ?>">
                                    <i class="fas fa-download"></i> <?php echo htmlspecialchars($arch['nombre']); ?>
                                </a>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--muted); font-size:0.8rem;">Ninguno</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Calificación">
                        <?php if ($alumno['calificacion'] !== null): ?>
                            <span class="calif <?php echo (float)$alumno['calificacion'] >= 6 ? 'calif-ok' : 'calif-baja'; ?>"><?php echo calificacion_texto($alumno['calificacion']); ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                        <?php if (!empty($alumno['retroalimentacion'])): ?>
                            <i class="fas fa-comment-dots" style="color:#3b82f6; margin-left:6px;" title="Tiene comentario"></i>
                        <?php endif; ?>
                    </td>
                    <td data-label="Evaluación">
                        <?php if ($alumno['id_entrega']): ?>
                            <button type="button" class="btn-revisar"
                                data-id="<?php echo (int)$alumno['id_entrega']; ?>"
                                data-nombre="<?php echo htmlspecialchars($nombre); ?>"
                                data-revisada="<?php echo $revisada ? '1' : '0'; ?>"
                                data-calif="<?php echo $alumno['calificacion'] !== null ? htmlspecialchars(calificacion_texto($alumno['calificacion'])) : ''; ?>"
                                data-retro="<?php echo htmlspecialchars((string)$alumno['retroalimentacion']); ?>"
                                onclick="abrirModalRevisar(this)">
                                <i class="fas fa-<?php echo $revisada ? 'pen' : 'comment-dots'; ?>"></i> <?php echo $revisada ? 'Editar' : 'Evaluar'; ?>
                            </button>
                        <?php else: ?>
                            <span style="color:#cbd5e1; font-size: 0.85rem;"><i class="fas fa-ban"></i> No disponible</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </main>

    <!-- Modal para calificar y comentar -->
    <div class="modal-overlay" id="modalRevisar">
        <div class="modal-content">
            <h3 class="modal-titulo"><i class="fas fa-clipboard-check"></i> Evaluar entrega</h3>
            <p class="modal-alumno" id="rev_nombre"></p>
            <form action="procesos/docente_revisar_entrega.php" method="POST" id="formRevisar">
                <input type="hidden" name="id_entrega" id="rev_id_entrega">
                <input type="hidden" name="id_actividad" value="<?php echo $id_actividad; ?>">

                <div class="rev-fila">
                    <div class="form-group">
                        <label for="rev_calif">Calificación (0 a 10)</label>
                        <input type="number" name="calificacion" id="rev_calif" class="form-control" min="0" max="10" step="0.1" inputmode="decimal" placeholder="Ej. 8.5">
                    </div>
                    <div class="form-group">
                        <label for="rev_estatus">Estado de la revisión</label>
                        <select name="estatus_entrega" id="rev_estatus" class="form-control">
                            <option value="Pendiente">Pendiente de revisión</option>
                            <option value="Revisada">Revisada</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="rev_retro">Comentarios para el alumno (opcional)</label>
                    <textarea name="retroalimentacion" id="rev_retro" class="form-control" style="min-height: 120px;" maxlength="2000" placeholder="Qué le faltó, qué estuvo bien, qué puede mejorar..."></textarea>
                </div>
                <p class="rev-nota"><i class="fas fa-eye"></i> El alumno verá la calificación y los comentarios en esta actividad, dentro de su aula virtual. Al poner calificación la entrega queda como revisada.</p>

                <div class="btn-group">
                    <button type="button" class="btn-revisar" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-guardar"><i class="fas fa-save"></i> Guardar evaluación</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalRevisar(btn) {
            document.getElementById('rev_id_entrega').value = btn.dataset.id;
            document.getElementById('rev_nombre').textContent = btn.dataset.nombre;
            document.getElementById('rev_calif').value = btn.dataset.calif;
            document.getElementById('rev_estatus').value = btn.dataset.revisada === '1' ? 'Revisada' : 'Pendiente';
            document.getElementById('rev_retro').value = btn.dataset.retro;
            document.getElementById('modalRevisar').classList.add('active');
            setTimeout(() => document.getElementById('rev_calif').focus(), 50);
        }
        // Al escribir una calificación la revisión pasa a "Revisada"
        document.getElementById('rev_calif').addEventListener('input', function () {
            if (this.value !== '') document.getElementById('rev_estatus').value = 'Revisada';
        });
        document.getElementById('formRevisar').addEventListener('submit', function (e) {
            const c = document.getElementById('rev_calif');
            if (c.value !== '' && (isNaN(+c.value) || +c.value < 0 || +c.value > 10)) {
                e.preventDefault(); alert('La calificación debe estar entre 0 y 10.'); c.focus();
            }
        });
        function cerrarModal() { document.getElementById('modalRevisar').classList.remove('active'); }
        document.getElementById('modalRevisar').addEventListener('click', e => { if (e.target.id === 'modalRevisar') cerrarModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
    </script>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
