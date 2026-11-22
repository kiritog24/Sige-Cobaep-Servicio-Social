<?php
/**
 * docente/regularizacion_alumnos.php
 * Lista de alumnos inscritos en un curso de regularización, con quién ya entregó,
 * a qué hora, si fue a tiempo y su calificación. Permite filtrar, calificar,
 * imprimir y exportar a Excel.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/topbar_docente.php';
require_once __DIR__ . '/../includes/regula_entregas.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');
$id_docente = (int)$_SESSION['id_usuario'];
$id_clase = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM CLASES_REGULARIZACION WHERE id_clase = ? AND id_maestro = ?");
$st->execute([$id_clase, $id_docente]);
$clase = $st->fetch(PDO::FETCH_ASSOC);
if (!$clase) {
    header("Location: regularizaciones.php?error=" . urlencode('No tienes permiso para ver ese curso.'));
    exit();
}

// Actividades con espacio de entrega propio (o con entregas ya recibidas)
$st = $pdo->prepare("SELECT ar.*, (SELECT COUNT(*) FROM ENTREGAS_REGULARIZACION e WHERE e.id_actividad = ar.id_actividad) AS total_entregas
                     FROM ACTIVIDADES_REGULARIZACION ar
                     WHERE ar.id_clase = ? ORDER BY ar.fecha_publicacion DESC");
$st->execute([$id_clase]);
$acts_entrega = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($x) => (int)$x['permite_entregas'] === 1 || (int)$x['total_entregas'] > 0));
$st = $pdo->prepare("SELECT COUNT(*) FROM ENTREGAS_REGULARIZACION WHERE id_clase = ? AND id_actividad IS NULL");
$st->execute([$id_clase]);
$hay_generales = (int)$st->fetchColumn() > 0;

// Actividad seleccionada (0 = entregas generales anteriores)
$id_act = isset($_GET['act']) ? (int)$_GET['act'] : ($acts_entrega ? (int)$acts_entrega[0]['id_actividad'] : 0);
$act_sel = null;
foreach ($acts_entrega as $x) if ((int)$x['id_actividad'] === $id_act) $act_sel = $x;
if (!$act_sel) $id_act = 0;
$escala = $act_sel ? (int)$act_sel['escala_calificacion'] : 10;

$st = $pdo->prepare("
    SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno, ca.fecha_asignacion,
           (SELECT CONCAT(g.semestre, '° ', g.nombre_grupo, ' · ', g.turno) FROM GRUPO_ALUMNO ga
              INNER JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
             WHERE ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL LIMIT 1) AS grupo,
           e.id_entrega, e.archivo_ruta, e.nombre_original, e.fecha_entrega, e.calificacion, e.estatus_entrega
    FROM CLASE_ALUMNO ca
    INNER JOIN ALUMNOS a ON a.id_alumno = ca.id_alumno
    LEFT JOIN ENTREGAS_REGULARIZACION e ON e.id_clase = ca.id_clase AND e.id_alumno = a.id_alumno
         AND " . ($id_act ? "e.id_actividad = ?" : "e.id_actividad IS NULL") . "
    WHERE ca.id_clase = ?
    ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre
");
$st->execute($id_act ? [$id_act, $id_clase] : [$id_clase]);
$alumnos = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT COUNT(*) FROM ACTIVIDADES_REGULARIZACION WHERE id_clase = ?");
$st->execute([$id_clase]);
$total_materiales = (int)$st->fetchColumn();

// "A tiempo": hasta la fecha límite de la actividad (o, en entregas generales, el día límite del curso a las 23:59)
if ($act_sel) {
    $limite_ts = $act_sel['fecha_limite'] ? strtotime($act_sel['fecha_limite']) : null;
} else {
    $limite_ts = $clase['fecha_fin'] ? strtotime($clase['fecha_fin'] . ' 23:59:59') : null;
}
$dias = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
foreach ($alumnos as &$a) {
    $a['nombre_completo'] = trim($a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ' ' . $a['nombre']);
    if ($a['id_entrega']) {
        $ts = strtotime($a['fecha_entrega']);
        $a['estado'] = ($limite_ts && $ts > $limite_ts) ? 'tarde' : 'tiempo';
        $a['cuando'] = $dias[(int)date('N', $ts)] . ' ' . date('d/m/Y', $ts);
        $a['hora'] = date('h:i a', $ts);
    } else {
        $a['estado'] = 'pendiente';
    }
}
unset($a);

$total = count($alumnos);
$entregaron = count(array_filter($alumnos, fn($a) => $a['estado'] !== 'pendiente'));
$tarde = count(array_filter($alumnos, fn($a) => $a['estado'] === 'tarde'));
$calificadas = array_values(array_filter(array_column($alumnos, 'calificacion'), fn($c) => $c !== null));
$promedio = $calificadas ? round(array_sum($calificadas) / count($calificadas), 1) : null;
$grupos = array_values(array_unique(array_filter(array_column($alumnos, 'grupo'))));
sort($grupos);
$etiquetas = ['tiempo' => 'Entregó a tiempo', 'tarde' => 'Entregó tarde', 'pendiente' => 'Sin entregar'];

// ---------- Exportar a Excel (.xlsx real) ----------
if (in_array($_GET['exportar'] ?? '', ['csv', 'xlsx'], true)) {
    require_once __DIR__ . '/../includes/xlsx_simple.php';
    $filas_x = [];
    foreach ($alumnos as $n => $a) {
        $filas_x[] = [$n + 1, $a['matricula'], $a['nombre_completo'], $a['grupo'] ?? '', $etiquetas[$a['estado']],
            $a['id_entrega'] ? date('d/m/Y', strtotime($a['fecha_entrega'])) : '', $a['id_entrega'] ? date('h:i a', strtotime($a['fecha_entrega'])) : '',
            $a['calificacion'] !== null ? (float)$a['calificacion'] : ''];
    }
    xlsx_descargar('Entregas_' . $clase['nombre_materia'] . ($act_sel ? '_' . $act_sel['titulo'] : '') . '_' . date('Ymd') . '.xlsx', 'Entregas',
        ['COBAEP Plantel 27 · Regularización: ' . $clase['nombre_materia'], ($act_sel ? 'Actividad: ' . $act_sel['titulo'] . ' · ' : '') . 'Generado el ' . date('d/m/Y h:i a')],
        ['#', 'Matrícula', 'Alumno', 'Grupo', 'Estado', 'Fecha de entrega', 'Hora', "Calificación (0-$escala)"], $filas_x, [5, 16, 36, 18, 18, 15, 10, 16]);
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Alumnos y entregas · <?php echo $h($clase['nombre_materia']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_docente.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/regularizacion_alumnos.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <button id="collapseToggle" class="collapse-toggle"><i class="fas fa-chevron-left"></i></button>
        <div class="sidebar-inner">
            <div class="brand">
                <img src="../img/LogoCobaep.png" alt="Logo" class="brand-logo">
                <div class="brand-copy"><strong>SIGE<span>-Cobaep</span></strong><small>Plantel 27</small></div>
            </div>
            <nav class="navigation">
                <span class="nav-heading">Menú principal</span>
                <a href="dashboard_docente.php" class="nav-item"><i class="fas fa-house"></i><span>Inicio</span></a>
                <span class="nav-heading">Académico</span>
                <a href="mis_grupos.php" class="nav-item"><i class="fas fa-users-rectangle"></i><span>Mis Grupos</span></a>
                <a href="horario.php" class="nav-item"><i class="far fa-calendar-alt"></i><span>Mi Horario</span></a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i><span>Horarios del Plantel</span></a>
                <span class="nav-heading">Evaluación</span>
                <a href="regularizaciones.php" class="nav-item active"><i class="fas fa-book-open-reader"></i><span>Regularizaciones</span></a>
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
                    <span class="eyebrow">REGULARIZACIÓN · DOCENTE</span>
                    <h1>Alumnos y entregas</h1>
                    <p>Quién está inscrito, quién ya entregó y a qué hora.</p>
                </div>
            </div>
            <?php docente_topbar($pdo); ?>
        </header>

        <?php if (isset($_GET['exito'])): ?><div class="ra-alerta exito"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="ra-alerta error"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

        <section class="ra-cabecera">
            <div class="ra-icono"><i class="fas fa-book-open-reader"></i></div>
            <div>
                <h2><?php echo $h($clase['nombre_materia']); ?></h2>
                <div class="ra-meta">
                    <?php if ($clase['fecha_fin']): ?><span class="ra-chip"><i class="far fa-calendar"></i> Fecha límite: <?php echo date('d/m/Y', strtotime($clase['fecha_fin'])); ?> 11:59 pm</span><?php endif; ?>
                    <span class="ra-chip <?php echo (int)$clase['permitir_entregas'] === 1 ? 'ok' : 'no'; ?>"><i class="fas fa-inbox"></i> Recepción <?php echo (int)$clase['permitir_entregas'] === 1 ? 'abierta' : 'cerrada'; ?></span>
                    <?php if ((int)($clase['visible'] ?? 1) === 0): ?><span class="ra-chip warn"><i class="fas fa-eye-slash"></i> Oculto para los alumnos</span><?php endif; ?>
                    <span class="ra-chip"><i class="fas fa-layer-group"></i> <?php echo $total_materiales; ?> materiales publicados</span>
                </div>
            </div>
            <div class="ra-acciones">
                <a href="detalle_regularizacion.php?id=<?php echo $id_clase; ?>" class="ra-btn"><i class="fas fa-arrow-left"></i> Volver al curso</a>
                <button type="button" class="ra-btn" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                <a href="?id=<?php echo $id_clase; ?>&act=<?php echo $id_act; ?>&exportar=xlsx" class="ra-btn primario"><i class="fas fa-file-excel"></i> Exportar Excel</a>
            </div>
        </section>

        <section class="ra-card" style="margin-bottom:16px;">
            <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="id" value="<?php echo $id_clase; ?>">
                <label for="raAct" style="font-weight:700; color:#10251d;"><i class="fas fa-file-signature"></i> Actividad:</label>
                <select name="act" id="raAct" class="ra-select" onchange="this.form.submit()" style="min-width:260px;">
                    <?php foreach ($acts_entrega as $x): ?>
                        <option value="<?php echo (int)$x['id_actividad']; ?>" <?php echo (int)$x['id_actividad'] === $id_act ? 'selected' : ''; ?>>
                            <?php echo $h($x['titulo']); ?> (<?php echo (int)$x['total_entregas']; ?> entregas)
                        </option>
                    <?php endforeach; ?>
                    <?php if ($hay_generales || !$acts_entrega): ?>
                        <option value="0" <?php echo $id_act === 0 ? 'selected' : ''; ?>>Entregas generales (anteriores)</option>
                    <?php endif; ?>
                </select>
                <?php if ($act_sel): ?>
                    <span class="ra-chip"><i class="far fa-clock"></i> <?php echo $act_sel['fecha_limite'] ? 'Límite: ' . date('d/m/Y h:i a', strtotime($act_sel['fecha_limite'])) : 'Sin fecha límite'; ?></span>
                    <span class="ra-chip"><i class="fas fa-file"></i> <?php echo $h(regula_tipos_texto($act_sel['tipos_archivo'])); ?> · máx. <?php echo (int)$act_sel['tamano_maximo_mb']; ?> MB</span>
                    <span class="ra-chip"><i class="fas fa-star"></i> Escala 0-<?php echo $escala; ?></span>
                    <span class="ra-chip <?php echo $act_sel['acepta_retardo'] ? 'ok' : 'no'; ?>"><?php echo $act_sel['acepta_retardo'] ? 'Acepta retardos' : 'No acepta retardos'; ?></span>
                <?php endif; ?>
            </form>
        </section>

        <section class="ra-stats" aria-label="Resumen">
            <div class="ra-stat"><strong><?php echo $total; ?></strong><span>Alumnos inscritos</span></div>
            <div class="ra-stat"><strong><?php echo $entregaron; ?></strong><span>Ya entregaron</span>
                <div class="ra-barra"><span style="width: <?php echo $total ? round($entregaron / $total * 100) : 0; ?>%"></span></div></div>
            <div class="ra-stat"><strong><?php echo $total - $entregaron; ?></strong><span>Sin entregar</span></div>
            <div class="ra-stat"><strong><?php echo $tarde; ?></strong><span>Entregaron tarde</span></div>
            <div class="ra-stat"><strong><?php echo $promedio !== null ? $promedio : '—'; ?></strong><span>Promedio (<?php echo count($calificadas); ?> calificados)</span></div>
        </section>

        <section class="ra-card">
            <p class="ra-solo-print">Lista generada el <?php echo date('d/m/Y h:i a'); ?> · <?php echo $h($clase['nombre_materia']); ?></p>
            <?php if (!$alumnos): ?>
                <div class="ra-vacio"><i class="fas fa-user-plus"></i>Todavía no hay alumnos inscritos. Inscríbelos desde <a href="detalle_regularizacion.php?id=<?php echo $id_clase; ?>">el curso</a> con <b>Inscribir Alumno</b> o <b>Vincular Grupo</b>.</div>
            <?php else: ?>
                <div class="ra-filtros">
                    <label class="ra-buscar"><i class="fas fa-search"></i><input type="search" id="raBuscar" placeholder="Buscar por nombre o matrícula..." aria-label="Buscar alumno"></label>
                    <?php if (count($grupos) > 1): ?>
                        <select class="ra-select" id="raGrupo" aria-label="Filtrar por grupo">
                            <option value="">Todos los grupos</option>
                            <?php foreach ($grupos as $g): ?><option value="<?php echo $h($g); ?>"><?php echo $h($g); ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <div class="ra-segmento" role="group" aria-label="Filtrar por estado">
                        <button type="button" class="activo" data-estado="">Todos (<?php echo $total; ?>)</button>
                        <button type="button" data-estado="entrego">Entregaron (<?php echo $entregaron; ?>)</button>
                        <button type="button" data-estado="pendiente">Sin entregar (<?php echo $total - $entregaron; ?>)</button>
                        <?php if ($tarde): ?><button type="button" data-estado="tarde">Tarde (<?php echo $tarde; ?>)</button><?php endif; ?>
                    </div>
                </div>

                <div class="ra-tabla-wrap">
                    <table class="ra-tabla">
                        <thead>
                            <tr><th>#</th><th>Alumno</th><th>Grupo</th><th>Estado</th><th>Fecha y hora de entrega</th><th>Archivo</th><th>Calificación (0-<?php echo $escala; ?>)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alumnos as $n => $a): ?>
                                <tr class="<?php echo $a['estado'] === 'pendiente' ? 'pendiente' : ''; ?>"
                                    data-estado="<?php echo $a['estado']; ?>" data-grupo="<?php echo $h($a['grupo']); ?>"
                                    data-texto="<?php echo $h(mb_strtolower($a['nombre_completo'] . ' ' . $a['matricula'])); ?>">
                                    <td class="ra-num"><?php echo $n + 1; ?></td>
                                    <td><strong><a href="perfil_alumno.php?id=<?php echo (int)$a['id_alumno']; ?>" style="color:inherit;"><?php echo $h($a['nombre_completo']); ?></a></strong><small><?php echo $h($a['matricula']); ?></small></td>
                                    <td><?php echo $h($a['grupo'] ?? '—'); ?></td>
                                    <td>
                                        <span class="ra-estado <?php echo $a['estado']; ?>">
                                            <i class="fas <?php echo ['tiempo' => 'fa-check', 'tarde' => 'fa-clock', 'pendiente' => 'fa-xmark'][$a['estado']]; ?>"></i>
                                            <?php echo $etiquetas[$a['estado']]; ?>
                                        </span>
                                    </td>
                                    <td class="ra-hora">
                                        <?php if ($a['id_entrega']): ?><strong><?php echo $a['hora']; ?></strong><small><?php echo $a['cuando']; ?></small>
                                        <?php else: ?><span style="color:#94a3b8;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($a['id_entrega']): ?>
                                            <a class="ra-archivo" href="../<?php echo $h($a['archivo_ruta']); ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?php echo $h($a['nombre_original'] ?: preg_replace('/^entrega_[a-z0-9]+_\d+_/i', '', basename($a['archivo_ruta']))); ?></a>
                                        <?php else: ?><span style="color:#94a3b8;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($a['id_entrega']): ?>
                                            <form class="ra-calif" action="procesos/regularizacion_acciones.php" method="POST">
                                                <input type="hidden" name="accion" value="calificar_entrega">
                                                <input type="hidden" name="volver" value="alumnos">
                                                <input type="hidden" name="act" value="<?php echo $id_act; ?>">
                                                <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
                                                <input type="hidden" name="id_entrega" value="<?php echo (int)$a['id_entrega']; ?>">
                                                <input type="number" name="calificacion" min="0" max="<?php echo $escala; ?>" step="0.1" placeholder="—" aria-label="Calificación de <?php echo $h($a['nombre_completo']); ?>" title="De 0 a <?php echo $escala; ?>"
                                                       value="<?php echo $a['calificacion'] !== null ? $h(regula_num($a['calificacion'])) : ''; ?>">
                                                <span style="align-self:center; font-size:.75rem; color:#64748b; font-weight:700;">/<?php echo $escala; ?></span>
                                                <button type="submit" title="Guardar calificación"><i class="fas fa-check"></i></button>
                                            </form>
                                        <?php else: ?><span style="color:#94a3b8;">—</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ra-vacio" id="raSinResultados" hidden><i class="fas fa-magnifying-glass"></i>Ningún alumno coincide con los filtros.</div>
            <?php endif; ?>
        </section>
    </main>

    <script src="../js/dashboard_docente.js"></script>
    <script>
    (function () {
        const buscar = document.getElementById('raBuscar');
        if (!buscar) return;
        const grupo = document.getElementById('raGrupo');
        const filas = [...document.querySelectorAll('.ra-tabla tbody tr')];
        let estado = '';
        function filtrar() {
            const q = buscar.value.trim().toLowerCase(), g = grupo ? grupo.value : '';
            let n = 0;
            filas.forEach(f => {
                const okEstado = !estado || (estado === 'entrego' ? f.dataset.estado !== 'pendiente' : f.dataset.estado === estado);
                const ok = okEstado && (!q || f.dataset.texto.includes(q)) && (!g || f.dataset.grupo === g);
                f.hidden = !ok; if (ok) n++;
            });
            document.getElementById('raSinResultados').hidden = n > 0;
        }
        buscar.addEventListener('input', filtrar);
        if (grupo) grupo.addEventListener('change', filtrar);
        document.querySelectorAll('.ra-segmento button').forEach(b => b.addEventListener('click', () => {
            document.querySelectorAll('.ra-segmento button').forEach(x => x.classList.toggle('activo', x === b));
            estado = b.dataset.estado; filtrar();
        }));
    })();
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
