<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/avisos_tutor.php';   // faltas_periodo(): misma regla que el concentrado
require_once __DIR__ . '/../includes/asistencia.php';
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');

$id_grupo    = isset($_GET['id_grupo']) ? (int)$_GET['id_grupo'] : 0;
$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = date('Y-m-d');

try {
    $grupos = $pdo->query("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE estatus = 'Activo' ORDER BY semestre, turno, nombre_grupo")->fetchAll();

    // Lista de alumnos del grupo seleccionado, con su resumen de asistencia en el periodo
    $alumnos = [];
    $grupo_sel = null;
    if ($id_grupo > 0) {
        $st = $pdo->prepare("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE id_grupo = ?");
        $st->execute([$id_grupo]);
        $grupo_sel = $st->fetch();

        $st = $pdo->prepare("
            SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno, a.estatus, a.puntos_conducta,
                   SUM(asi.estatus = 'Puntual') AS puntuales,
                   SUM(asi.estatus IN ('Retardo', 'Retardo Justificado')) AS retardos,
                   SUM(asi.estatus = 'Falta Justificada') AS justificadas
            FROM GRUPO_ALUMNO ga
            INNER JOIN ALUMNOS a ON a.id_alumno = ga.id_alumno
            LEFT JOIN ASISTENCIAS asi ON asi.id_alumno = a.id_alumno AND DATE(asi.fecha_hora_escaneo) BETWEEN ? AND ?
            WHERE ga.id_grupo = ? AND ga.fecha_baja IS NULL
            GROUP BY a.id_alumno
            ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre
        ");
        $st->execute([$fecha_desde, $fecha_hasta, $id_grupo]);
        $alumnos = $st->fetchAll();

        // Faltas sin justificar: registros "Falta" + días de clase sin entrada (sin fines de semana ni suspensiones)
        $faltas_reales = faltas_periodo($pdo, $fecha_desde, $fecha_hasta, $id_grupo);
        $clases_periodo = faltas_clases_periodo($pdo, array_column($alumnos, 'id_alumno'), $fecha_desde, min($fecha_hasta, date('Y-m-d')));
        foreach ($alumnos as &$a_rep) {
            $a_rep['faltas'] = $faltas_reales[(int)$a_rep['id_alumno']]['faltas'] ?? 0;
            $a_rep += $clases_periodo[(int)$a_rep['id_alumno']] ?? ['faltas_clase' => 0, 'dias_pinta' => 0];
        }
        unset($a_rep);
    }

    // Descargar la lista en Excel (.xlsx real) o PDF (Dompdf)
    $formato_rep = $_GET['exportar'] ?? '';
    if ($grupo_sel && in_array($formato_rep, ['csv', 'xlsx', 'pdf'], true)) {
        $titulo_rep = 'Lista del grupo ' . $grupo_sel['semestre'] . '° "' . $grupo_sel['nombre_grupo'] . '" · Turno ' . $grupo_sel['turno'];
        $periodo_rep = 'Asistencia del ' . date('d/m/Y', strtotime($fecha_desde)) . ' al ' . date('d/m/Y', strtotime($fecha_hasta));
        $archivo_rep = 'Lista_' . $grupo_sel['semestre'] . $grupo_sel['nombre_grupo'] . '_' . $grupo_sel['turno'];
        if ($formato_rep !== 'pdf') {
            require_once __DIR__ . '/../includes/xlsx_simple.php';
            $filas_x = [];
            foreach ($alumnos as $i => $a) $filas_x[] = [$i + 1, $a['matricula'], $a['apellido_paterno'], $a['apellido_materno'], $a['nombre'], $a['estatus'], (int)$a['puntos_conducta'], (int)$a['puntuales'], (int)$a['retardos'], (int)$a['faltas'], (int)$a['justificadas'], (int)$a['faltas_clase'], (int)$a['dias_pinta']];
            xlsx_descargar($archivo_rep . '.xlsx', 'Lista', ['COBAEP Plantel 27 · ' . $titulo_rep, $periodo_rep],
                ['No.', 'Matrícula', 'Apellido paterno', 'Apellido materno', 'Nombre(s)', 'Estatus', 'Puntos de conducta', 'Puntuales', 'Retardos', 'Faltas', 'Justificadas', 'Faltas a clases', 'Días con pinta'],
                $filas_x, [6, 16, 18, 18, 24, 11, 12, 11, 10, 9, 12, 14, 14]);
        }
        require_once __DIR__ . '/../includes/pdf_documento.php';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $cuerpo = '<table><thead><tr><th>No.</th><th>Matrícula</th><th>Alumno</th><th>Estatus</th><th>Puntos</th><th>Puntuales</th><th>Retardos</th><th>Faltas</th><th>Just.</th><th>Faltas a clases</th><th>Días con pinta</th></tr></thead><tbody>';
        foreach ($alumnos as $i => $a) {
            $cuerpo .= '<tr><td class="c">' . ($i + 1) . '</td><td class="c">' . $e($a['matricula']) . '</td><td>' . $e(trim($a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ' ' . $a['nombre'])) . '</td>'
                . '<td class="c">' . $e($a['estatus']) . '</td><td class="c">' . (int)$a['puntos_conducta'] . '</td><td class="c">' . (int)$a['puntuales'] . '</td><td class="c">' . (int)$a['retardos'] . '</td><td class="c">' . (int)$a['faltas'] . '</td><td class="c">' . (int)$a['justificadas'] . '</td><td class="c">' . (int)$a['faltas_clase'] . '</td><td class="c">' . (int)$a['dias_pinta'] . '</td></tr>';
        }
        if (!$alumnos) $cuerpo .= '<tr><td colspan="11" class="c">Este grupo no tiene alumnos inscritos.</td></tr>';
        $cuerpo .= '</tbody></table>';
        pdf_descargar($archivo_rep . '.pdf', $titulo_rep, $periodo_rep . ' · ' . count($alumnos) . ' alumnos', $cuerpo, 'portrait');
    }

    // Resumen general de asistencia por grupo en el periodo
    $st = $pdo->prepare("
        SELECT g.id_grupo, g.semestre, g.nombre_grupo, g.turno,
               COUNT(DISTINCT ga.id_alumno) AS alumnos,
               SUM(asi.estatus = 'Puntual') AS puntuales,
               SUM(asi.estatus IN ('Retardo', 'Retardo Justificado')) AS retardos
        FROM GRUPOS g
        LEFT JOIN GRUPO_ALUMNO ga ON ga.id_grupo = g.id_grupo AND ga.fecha_baja IS NULL
        LEFT JOIN ASISTENCIAS asi ON asi.id_alumno = ga.id_alumno AND DATE(asi.fecha_hora_escaneo) BETWEEN ? AND ?
        WHERE g.estatus = 'Activo'
        GROUP BY g.id_grupo
        ORDER BY g.semestre, g.turno, g.nombre_grupo
    ");
    $st->execute([$fecha_desde, $fecha_hasta]);
    $resumen = $st->fetchAll();

    // Faltas (misma regla que el concentrado) y asistencia a clases, sumadas por grupo
    $faltas_todos = faltas_periodo($pdo, $fecha_desde, $fecha_hasta);
    $miembros = $pdo->query("SELECT id_alumno, id_grupo FROM GRUPO_ALUMNO WHERE fecha_baja IS NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
    $clases_todos = faltas_clases_periodo($pdo, array_keys($miembros), $fecha_desde, min($fecha_hasta, date('Y-m-d')));
    $por_grupo = [];
    foreach ($miembros as $id_al => $id_gr) {
        $por_grupo[$id_gr]['faltas'] = ($por_grupo[$id_gr]['faltas'] ?? 0) + ($faltas_todos[$id_al]['faltas'] ?? 0);
        $por_grupo[$id_gr]['faltas_clase'] = ($por_grupo[$id_gr]['faltas_clase'] ?? 0) + ($clases_todos[$id_al]['faltas_clase'] ?? 0);
        $por_grupo[$id_gr]['dias_pinta'] = ($por_grupo[$id_gr]['dias_pinta'] ?? 0) + ($clases_todos[$id_al]['dias_pinta'] ?? 0);
    }
    foreach ($resumen as &$r_rep) $r_rep += $por_grupo[(int)$r_rep['id_grupo']] ?? ['faltas' => 0, 'faltas_clase' => 0, 'dias_pinta' => 0];
    unset($r_rep);

    // Reportes de conducta del periodo
    $st = $pdo->prepare("
        SELECT r.fecha_hora, r.puntos_descontados, r.puntos_restantes, r.observaciones,
               a.matricula, CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) AS alumno,
               tf.numero_reglamento, tf.descripcion_falta
        FROM REPORTES r
        INNER JOIN ALUMNOS a ON a.id_alumno = r.id_alumno
        INNER JOIN TIPOS_FALTA tf ON tf.id_tipo_falta = r.id_tipo_falta
        WHERE DATE(r.fecha_hora) BETWEEN ? AND ?
        ORDER BY r.fecha_hora DESC
        LIMIT 50
    ");
    $st->execute([$fecha_desde, $fecha_hasta]);
    $reportes = $st->fetchAll();

    $total_activos = (int)$pdo->query("SELECT COUNT(*) FROM ALUMNOS WHERE estatus = 'Alta'")->fetchColumn();
    $total_bajo_puntos = (int)$pdo->query("SELECT COUNT(*) FROM ALUMNOS WHERE estatus = 'Alta' AND puntos_conducta < 75")->fetchColumn();
} catch (PDOException $e) {
    die("Error al generar reportes: " . $e->getMessage());
}

$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Administrador';
$qs_periodo = 'desde=' . urlencode($fecha_desde) . '&hasta=' . urlencode($fecha_hasta);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Reportes y Listas · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_admin.css">
    <link rel="stylesheet" href="style-ver_grupo.css">
    <link rel="stylesheet" href="style_sidebar.css">
    <link rel="stylesheet" href="css/reportes.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">INFORMACIÓN · SIGE</span>
                    <h1>Reportes y Listas</h1>
                    <p>Listas de grupo, asistencia y conducta para la administración escolar.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <section class="stats-grid" aria-label="Resumen">
            <article class="stat-card students">
                <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-content"><span>Alumnos activos</span><strong><?php echo $total_activos; ?></strong><small>Con estatus de alta</small></div>
            </article>
            <article class="stat-card teachers">
                <div class="stat-icon"><i class="fas fa-users-rectangle"></i></div>
                <div class="stat-content"><span>Grupos activos</span><strong><?php echo count($grupos); ?></strong><small>En todos los turnos</small></div>
            </article>
            <article class="stat-card attendance">
                <div class="stat-icon"><i class="fas fa-triangle-exclamation"></i></div>
                <div class="stat-content"><span>Alumnos en riesgo</span><strong><?php echo $total_bajo_puntos; ?></strong><small>Con menos de 75 puntos de conducta</small></div>
            </article>
        </section>

        <section class="section">
            <!-- Filtros -->
            <div class="rep-card no-print">
                <h2><i class="fas fa-filter"></i> Periodo y grupo</h2>
                <p class="desc">El periodo se aplica a la asistencia y a los reportes de conducta.</p>
                <form class="rep-filters" method="GET">
                    <label>Grupo
                        <select name="id_grupo">
                            <option value="0">— Selecciona un grupo —</option>
                            <?php foreach ($grupos as $g): ?>
                                <option value="<?php echo $g['id_grupo']; ?>" <?php echo $g['id_grupo'] == $id_grupo ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($g['semestre'] . '° "' . $g['nombre_grupo'] . '" · ' . $g['turno']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Desde <input type="date" name="desde" value="<?php echo htmlspecialchars($fecha_desde); ?>"></label>
                    <label>Hasta <input type="date" name="hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>"></label>
                    <button type="submit" class="rep-btn primary"><i class="fas fa-magnifying-glass"></i> Generar</button>
                </form>
            </div>

            <!-- Lista del grupo -->
            <div class="rep-card" id="cardLista">
                <h2><i class="fas fa-list-ol"></i> Lista de alumnos por grupo</h2>
                <?php if (!$grupo_sel): ?>
                    <p class="rep-empty">Selecciona un grupo para ver, imprimir o descargar su lista.</p>
                <?php else: ?>
                    <div class="print-title">
                        <strong>COBAEP Plantel 27 · Lista del grupo <?php echo htmlspecialchars($grupo_sel['semestre'] . '° "' . $grupo_sel['nombre_grupo'] . '" (' . $grupo_sel['turno'] . ')'); ?></strong><br>
                        <small>Asistencia del <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> al <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?></small>
                    </div>
                    <p class="desc"><?php echo htmlspecialchars($grupo_sel['semestre'] . '° "' . $grupo_sel['nombre_grupo'] . '" · Turno ' . $grupo_sel['turno']); ?> — <?php echo count($alumnos); ?> alumnos inscritos.</p>
                    <div class="rep-actions no-print">
                        <a class="rep-btn light" href="?id_grupo=<?php echo $id_grupo; ?>&<?php echo $qs_periodo; ?>&exportar=xlsx"><i class="fas fa-file-excel"></i> Descargar Excel</a>
                        <a class="rep-btn light" style="background:#fef2f2; color:#b91c1c;" href="?id_grupo=<?php echo $id_grupo; ?>&<?php echo $qs_periodo; ?>&exportar=pdf"><i class="fas fa-file-pdf"></i> Descargar PDF</a>
                        <button type="button" class="rep-btn gray" onclick="imprimirLista()"><i class="fas fa-print"></i> Imprimir</button>
                        <a class="rep-btn gray" href="ver_grupo.php?id=<?php echo $id_grupo; ?>"><i class="fas fa-users-cog"></i> Gestionar grupo</a>
                    </div>
                    <?php if (empty($alumnos)): ?>
                        <p class="rep-empty">Este grupo todavía no tiene alumnos inscritos.</p>
                    <?php else: ?>
                        <div class="rep-wrap">
                            <table class="rep-table">
                                <thead><tr><th>No.</th><th>Matrícula</th><th>Alumno</th><th>Estatus</th><th class="num">Conducta</th><th class="num">Puntuales</th><th class="num">Retardos</th><th class="num">Faltas</th><th class="num" title="Faltas con justificante de Prefectura">Just.</th><th class="num" title="Faltas sin justificar en el pase de lista de los docentes">Faltas a clases</th><th class="num" title="Días que entró al plantel y faltó a alguna clase">Días con pinta</th></tr></thead>
                                <tbody>
                                <?php foreach ($alumnos as $i => $a):
                                    $pts = (int)$a['puntos_conducta'];
                                    $cls = $pts >= 75 ? 'ok' : ($pts >= 60 ? 'warn' : 'bad'); ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($a['matricula']); ?></td>
                                        <td><a href="perfil_alumno.php?id=<?php echo $a['id_alumno']; ?>" style="color:inherit;"><?php echo htmlspecialchars(trim($a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ' ' . $a['nombre'])); ?></a></td>
                                        <td><?php echo htmlspecialchars($a['estatus']); ?></td>
                                        <td class="num"><span class="pill <?php echo $cls; ?>"><?php echo $pts; ?></span></td>
                                        <td class="num"><?php echo (int)$a['puntuales']; ?></td>
                                        <td class="num"><?php echo (int)$a['retardos']; ?></td>
                                        <td class="num"><?php echo (int)$a['faltas']; ?></td>
                                        <td class="num"><?php echo (int)$a['justificadas']; ?></td>
                                        <td class="num"><?php echo (int)$a['faltas_clase']; ?></td>
                                        <td class="num"><?php echo (int)$a['dias_pinta'] ? '<span class="pill bad">' . (int)$a['dias_pinta'] . '</span>' : '0'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Resumen de asistencia -->
            <div class="rep-card">
                <h2><i class="fas fa-chart-column"></i> Asistencia por grupo</h2>
                <p class="desc">Entrada al plantel y pase de lista de los docentes del <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> al <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?>. Un día de clases sin registro de entrada cuenta como falta (sin fines de semana ni suspensiones).</p>
                <?php if (empty($resumen)): ?>
                    <p class="rep-empty">No hay grupos activos.</p>
                <?php else: ?>
                    <div class="rep-wrap">
                        <table class="rep-table">
                            <thead><tr><th>Grupo</th><th>Turno</th><th class="num">Alumnos</th><th class="num">Puntuales</th><th class="num">Retardos</th><th class="num">Faltas</th><th class="num">Puntualidad</th><th class="num">Faltas a clases</th><th class="num">Días con pinta</th></tr></thead>
                            <tbody>
                            <?php foreach ($resumen as $r):
                                $total = (int)$r['puntuales'] + (int)$r['retardos'] + (int)$r['faltas'];
                                $pct = $total > 0 ? round($r['puntuales'] * 100 / $total) : null; ?>
                                <tr>
                                    <td><a href="?id_grupo=<?php echo $r['id_grupo']; ?>&<?php echo $qs_periodo; ?>" style="color:inherit;font-weight:600;"><?php echo htmlspecialchars($r['semestre'] . '° "' . $r['nombre_grupo'] . '"'); ?></a></td>
                                    <td><?php echo htmlspecialchars($r['turno']); ?></td>
                                    <td class="num"><?php echo (int)$r['alumnos']; ?></td>
                                    <td class="num"><?php echo (int)$r['puntuales']; ?></td>
                                    <td class="num"><?php echo (int)$r['retardos']; ?></td>
                                    <td class="num"><?php echo (int)$r['faltas']; ?></td>
                                    <td class="num"><?php echo $pct === null ? '<span style="color:#94a3b8">—</span>' : '<span class="pill ' . ($pct >= 85 ? 'ok' : ($pct >= 70 ? 'warn' : 'bad')) . '">' . $pct . '%</span>'; ?></td>
                                    <td class="num"><?php echo (int)$r['faltas_clase']; ?></td>
                                    <td class="num"><?php echo (int)$r['dias_pinta'] ? '<span class="pill bad">' . (int)$r['dias_pinta'] . '</span>' : '0'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Reportes de conducta -->
            <div class="rep-card">
                <h2><i class="fas fa-file-shield"></i> Reportes de conducta</h2>
                <p class="desc">Últimos 50 reportes levantados por Prefectura en el periodo.</p>
                <?php if (empty($reportes)): ?>
                    <p class="rep-empty">No hay reportes de conducta en este periodo.</p>
                <?php else: ?>
                    <div class="rep-wrap">
                        <table class="rep-table">
                            <thead><tr><th>Fecha</th><th>Matrícula</th><th>Alumno</th><th>Falta</th><th class="num">Puntos</th><th class="num">Restantes</th></tr></thead>
                            <tbody>
                            <?php foreach ($reportes as $r): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($r['fecha_hora'])); ?></td>
                                    <td><?php echo htmlspecialchars($r['matricula']); ?></td>
                                    <td><?php echo htmlspecialchars($r['alumno']); ?></td>
                                    <td><strong>Art. <?php echo (int)$r['numero_reglamento']; ?></strong> · <?php echo htmlspecialchars(mb_strimwidth($r['descripcion_falta'], 0, 90, '…')); ?></td>
                                    <td class="num">-<?php echo (int)$r['puntos_descontados']; ?></td>
                                    <td class="num"><?php echo (int)$r['puntos_restantes']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <script>
        function imprimirLista() {
            document.body.classList.add('imprimir-lista');
            window.print();
            setTimeout(() => document.body.classList.remove('imprimir-lista'), 500);
        }
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay'), mobileMenu = document.getElementById('mobileMenu'), collapseToggle = document.getElementById('collapseToggle'), body = document.body;
            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');
            if (collapseToggle) collapseToggle.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); });
            if (mobileMenu) mobileMenu.addEventListener('click', () => { sidebar.classList.add('mobile-open'); overlay.classList.add('visible'); body.classList.add('menu-open'); });
            if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('visible'); body.classList.remove('menu-open'); });
        });
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
