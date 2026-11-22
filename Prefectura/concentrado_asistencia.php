<?php
/**
 * Prefectura/concentrado_asistencia.php — Concentrado de asistencia de un grupo por periodo.
 *
 * Los registros diarios nunca se borran: se guardan por fecha en ASISTENCIAS y aquí se resumen
 * por semana, mes, semestre escolar (Ago–Ene / Feb–Jul) o rango libre.
 *  - Solo cuentan días hábiles: lunes a viernes, sin las fechas de SUSPENSIONES_CLASES.
 *  - Un día hábil sin registro de entrada cuenta como falta.
 *  - Cada alumno se cuenta solo desde que entró al grupo (y hasta su baja, si la tuvo).
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/** Periodo escolar (semestre) que contiene una fecha: Ago–Ene o Feb–Jul. */
function semestre_de(DateTime $f): array {
    $m = (int)$f->format('n'); $y = (int)$f->format('Y');
    if ($m >= 8)  return ["$y-08-01", ($y + 1) . "-01-31", "Ago $y – Ene " . ($y + 1)];
    if ($m === 1) return [($y - 1) . "-08-01", "$y-01-31", "Ago " . ($y - 1) . " – Ene $y"];
    return ["$y-02-01", "$y-07-31", "Feb – Jul $y"];
}

$hoy = new DateTime('today');
$periodo = in_array($_GET['periodo'] ?? '', ['semana', 'mes', 'semestre', 'semestre_anterior', 'personalizado'], true) ? $_GET['periodo'] : 'semestre';
$valida = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f) && strtotime($f);
switch ($periodo) {
    case 'semana':
        $desde = (clone $hoy)->modify('monday this week')->format('Y-m-d');
        $hasta = (clone $hoy)->modify('friday this week')->format('Y-m-d');
        $etiqueta = 'Semana del ' . date('d/m', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
        break;
    case 'mes':
        $desde = $hoy->format('Y-m-01'); $hasta = $hoy->format('Y-m-t');
        $meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $etiqueta = $meses[(int)$hoy->format('n')] . ' ' . $hoy->format('Y');
        break;
    case 'semestre_anterior':
        [$d0] = semestre_de($hoy);
        [$desde, $hasta, $etiqueta] = semestre_de((new DateTime($d0))->modify('-1 day'));
        $etiqueta = 'Semestre ' . $etiqueta;
        break;
    case 'personalizado':
        $desde = $valida($_GET['desde'] ?? '') ? $_GET['desde'] : $hoy->format('Y-m-01');
        $hasta = $valida($_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy->format('Y-m-d');
        if ($hasta < $desde) [$desde, $hasta] = [$hasta, $desde];
        $etiqueta = 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
        break;
    default:
        [$desde, $hasta, $etiqueta] = semestre_de($hoy);
        $etiqueta = 'Semestre ' . $etiqueta;
}
// No se cuentan días futuros
$hasta_calculo = min($hasta, $hoy->format('Y-m-d'));

$grupos = $pdo->query("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE estatus = 'Activo' ORDER BY semestre, nombre_grupo, turno")->fetchAll(PDO::FETCH_ASSOC);
$id_grupo = (int)($_GET['id_grupo'] ?? 0);
$grupo = null;
foreach ($grupos as $g) if ((int)$g['id_grupo'] === $id_grupo) $grupo = $g;

$filas = []; $totales = ['dias' => 0, 'puntual' => 0, 'retardo' => 0, 'justif' => 0, 'falta' => 0, 'susp' => 0];
$dias_habiles = [];
if ($grupo && $desde <= $hasta_calculo) {
    $st = $pdo->prepare("
        SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno, ga.fecha_asignacion, ga.fecha_baja
        FROM GRUPO_ALUMNO ga INNER JOIN ALUMNOS a ON a.id_alumno = ga.id_alumno
        WHERE ga.id_grupo = ? AND ga.fecha_asignacion <= ? AND (ga.fecha_baja IS NULL OR ga.fecha_baja > ?)
        ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre");
    $st->execute([$id_grupo, $hasta_calculo, $desde]);
    $alumnos = $st->fetchAll(PDO::FETCH_ASSOC);

    // Primera entrada de cada alumno por día dentro del periodo
    $registros = [];
    if ($alumnos) {
        $ids = implode(',', array_map(fn($a) => (int)$a['id_alumno'], $alumnos));
        $st = $pdo->prepare("SELECT id_alumno, DATE(fecha_hora_escaneo) AS dia,
                                    SUBSTRING_INDEX(GROUP_CONCAT(estatus ORDER BY fecha_hora_escaneo ASC), ',', 1) AS estatus
                             FROM ASISTENCIAS
                             WHERE id_alumno IN ($ids) AND tipo_registro = 'Entrada' AND DATE(fecha_hora_escaneo) BETWEEN ? AND ?
                             GROUP BY id_alumno, DATE(fecha_hora_escaneo)");
        $st->execute([$desde, $hasta_calculo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $registros[(int)$r['id_alumno']][$r['dia']] = $r['estatus'];
    }

    // Días hábiles del periodo (lunes a viernes, sin suspensiones)
    $susp = $pdo->prepare("SELECT fecha_inicio, fecha_fin FROM SUSPENSIONES_CLASES WHERE fecha_fin >= ? AND fecha_inicio <= ?");
    $susp->execute([$desde, $hasta_calculo]);
    $rangos = $susp->fetchAll(PDO::FETCH_ASSOC);
    for ($d = new DateTime($desde); $d->format('Y-m-d') <= $hasta_calculo; $d->modify('+1 day')) {
        if ((int)$d->format('N') > 5) continue;
        $f = $d->format('Y-m-d');
        foreach ($rangos as $r) if ($f >= $r['fecha_inicio'] && $f <= $r['fecha_fin']) continue 2;
        $dias_habiles[] = $f;
    }


    foreach ($alumnos as $a) {
        $c = ['dias' => 0, 'puntual' => 0, 'retardo' => 0, 'justif' => 0, 'falta' => 0, 'susp' => 0];
        foreach ($dias_habiles as $dia) {
            // Solo cuenta mientras el alumno pertenece al grupo
            if ($dia < $a['fecha_asignacion'] || ($a['fecha_baja'] && $dia >= $a['fecha_baja'])) continue;
            $c['dias']++;
            $e = $registros[(int)$a['id_alumno']][$dia] ?? null;
            if ($e === 'Puntual') $c['puntual']++;
            elseif ($e === 'Retardo') $c['retardo']++;
            elseif ($e === 'Retardo Justificado' || $e === 'Falta Justificada') $c['justif']++;
            elseif ($e === 'Suspendido') $c['susp']++;
            else $c['falta']++;   // sin registro o "Falta"
        }
        $c['porcentaje'] = $c['dias'] ? round(($c['puntual'] + $c['retardo'] + $c['justif']) / $c['dias'] * 100, 1) : null;
        foreach ($totales as $k => $_) $totales[$k] += $c[$k];
        $filas[] = $a + $c;
    }
}
$pct_grupo = $totales['dias'] ? round(($totales['puntual'] + $totales['retardo'] + $totales['justif']) / $totales['dias'] * 100, 1) : null;
$nombre_archivo = $grupo ? 'Concentrado_' . preg_replace('/[^A-Za-z0-9]/', '', $grupo['semestre'] . $grupo['nombre_grupo'] . $grupo['turno']) . '_' . $desde . '_' . $hasta_calculo : 'Concentrado';

// ---------- Descargas: PDF (Dompdf) y Excel (.xlsx) reales ----------
$formato = $_GET['formato'] ?? '';
if ($grupo && in_array($formato, ['pdf', 'xlsx'], true)) {
    $titulo_grupo = $grupo['semestre'] . '° "' . $grupo['nombre_grupo'] . '" · Turno ' . $grupo['turno'];
    $dias_texto = count($dias_habiles) . ' día(s) hábil(es)' . ($hasta > $hasta_calculo ? ' (hasta hoy)' : '');
    if ($formato === 'xlsx') {
        require_once __DIR__ . '/../includes/xlsx_simple.php';
        $datos = [];
        foreach ($filas as $i => $f) {
            $datos[] = [$i + 1, trim($f['apellido_paterno'] . ' ' . $f['apellido_materno'] . ' ' . $f['nombre']), $f['matricula'],
                        (int)$f['dias'], (int)$f['puntual'], (int)$f['retardo'], (int)$f['justif'], (int)$f['falta'], (int)$f['susp'],
                        $f['porcentaje'] !== null ? (float)$f['porcentaje'] : ''];
        }
        $datos[] = ['', 'TOTAL DEL GRUPO', '', (int)$totales['dias'], (int)$totales['puntual'], (int)$totales['retardo'], (int)$totales['justif'], (int)$totales['falta'], (int)$totales['susp'], $pct_grupo !== null ? (float)$pct_grupo : ''];
        xlsx_descargar($nombre_archivo . '.xlsx', 'Concentrado',
            ['COBAEP Plantel 27 · Concentrado de asistencia', $titulo_grupo . ' · ' . $etiqueta . ' · ' . $dias_texto],
            ['#', 'Alumno', 'Matrícula', 'Días', 'Puntual', 'Retardos', 'Justificadas', 'Faltas', 'Suspendido', '% Asistencia'],
            $datos, [5, 38, 16, 8, 9, 10, 12, 8, 11, 13]);
    }
    require_once __DIR__ . '/../includes/pdf_documento.php';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $cuerpo = '<table class="resumen"><tr>'
        . '<td><b>' . count($filas) . '</b>Alumnos</td><td><b>' . $totales['puntual'] . '</b>Puntuales</td><td><b>' . $totales['retardo'] . '</b>Retardos</td>'
        . '<td><b>' . $totales['justif'] . '</b>Justificadas</td><td><b>' . $totales['falta'] . '</b>Faltas</td><td><b>' . ($pct_grupo !== null ? $pct_grupo . '%' : '—') . '</b>Asistencia del grupo</td>'
        . '</tr></table><br><table><thead><tr><th>#</th><th>Alumno</th><th>Matrícula</th><th>Días</th><th>Puntual</th><th>Retardos</th><th>Justif.</th><th>Faltas</th><th>Susp.</th><th>% Asist.</th></tr></thead><tbody>';
    foreach ($filas as $i => $f) {
        $cls = $f['porcentaje'] === null ? '' : ($f['porcentaje'] >= 90 ? 'ok' : ($f['porcentaje'] >= 80 ? 'medio' : 'bajo'));
        $cuerpo .= '<tr><td class="c">' . ($i + 1) . '</td><td>' . $e(trim($f['apellido_paterno'] . ' ' . $f['apellido_materno'] . ' ' . $f['nombre'])) . '</td><td class="c">' . $e($f['matricula']) . '</td>'
            . '<td class="c">' . (int)$f['dias'] . '</td><td class="c">' . (int)$f['puntual'] . '</td><td class="c">' . (int)$f['retardo'] . '</td><td class="c">' . (int)$f['justif'] . '</td>'
            . '<td class="c">' . (int)$f['falta'] . '</td><td class="c">' . (int)$f['susp'] . '</td><td class="c ' . $cls . '">' . ($f['porcentaje'] !== null ? $f['porcentaje'] . '%' : '—') . '</td></tr>';
    }
    if (!$filas) $cuerpo .= '<tr><td colspan="10" class="c">No hay alumnos en este grupo para el periodo seleccionado.</td></tr>';
    $cuerpo .= '</tbody></table><p class="nota">Se cuentan los días hábiles (lunes a viernes, sin suspensiones de clases). Un día hábil sin registro de entrada cuenta como falta. Los alumnos se cuentan desde su fecha de inscripción al grupo. Verde: 90% o más · Amarillo: 80–89% · Rojo: menos de 80%.</p>'
        . '<br><br><table style="width:60%;margin:0 auto"><tr><td style="border:none;border-top:1px solid #333;text-align:center;padding-top:4px">Prefectura · Firma y sello</td></tr></table>';
    pdf_descargar($nombre_archivo . '.pdf', 'Concentrado de asistencia · ' . $titulo_grupo, $etiqueta . ' · ' . $dias_texto, $cuerpo, 'landscape');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Concentrado de Asistencia · COBAEP 27</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">
    <style>
        .ca-card { background: #fff; border: 1px solid #e5ece8; border-radius: 18px; padding: 20px; box-shadow: 0 4px 14px rgba(6, 63, 43, .04); margin-bottom: 18px; }
        .ca-filtros { display: grid; grid-template-columns: 1.4fr 1.2fr 1fr 1fr auto; gap: 12px; align-items: end; }
        .ca-filtros label { display: block; font-size: .78rem; font-weight: 700; color: #475569; margin-bottom: 6px; }
        .ca-filtros select, .ca-filtros input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: .9rem; background: #fff; box-sizing: border-box; }
        .ca-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 10px; border: none; font-weight: 700; font-family: inherit; cursor: pointer; text-decoration: none; font-size: .88rem; }
        .ca-btn.verde { background: #16a05d; color: #fff; } .ca-btn.excel { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; } .ca-btn.pdf { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .ca-resumen { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 16px; }
        .ca-resumen div { background: #f6faf8; border-radius: 12px; padding: 12px; text-align: center; }
        .ca-resumen strong { display: block; font-size: 1.4rem; color: #10231a; }
        .ca-resumen span { font-size: .72rem; color: #64748b; font-weight: 600; }
        .ca-encabezado { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .ca-encabezado h2 { margin: 0; font-size: 1.1rem; color: #10231a; }
        .ca-encabezado p { margin: 2px 0 0; color: #64748b; font-size: .85rem; }
        .ca-tabla { width: 100%; border-collapse: collapse; font-size: .86rem; }
        .ca-tabla th { background: #f8fafc; color: #475569; font-weight: 700; text-align: center; padding: 9px 8px; border-bottom: 2px solid #e2e8f0; font-size: .74rem; text-transform: uppercase; }
        .ca-tabla th:nth-child(2), .ca-tabla td:nth-child(2) { text-align: left; }
        .ca-tabla td { padding: 8px; border-bottom: 1px solid #f1f5f9; text-align: center; }
        .ca-pct { font-weight: 800; border-radius: 8px; padding: 3px 8px; display: inline-block; }
        .ca-pct.ok { background: #ecfdf5; color: #047857; } .ca-pct.medio { background: #fffbeb; color: #b45309; } .ca-pct.bajo { background: #fef2f2; color: #b91c1c; }
        .ca-nota { font-size: .78rem; color: #64748b; margin-top: 12px; line-height: 1.5; }
        .ca-solo-print { display: none; }
        @media (max-width: 1000px) { .ca-filtros { grid-template-columns: 1fr 1fr; } .ca-resumen { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 560px) { .ca-filtros { grid-template-columns: 1fr; } .ca-resumen { grid-template-columns: repeat(2, 1fr); } }
        @media print {
            @page { size: landscape; margin: 10mm; }
            .sidebar, .sidebar-overlay, .topbar, .ca-filtros-card, .ca-acciones, .collapse-toggle { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .ca-card { box-shadow: none; border: none; padding: 0; }
            .ca-solo-print { display: block; }
            .ca-tabla { font-size: 10px; } .ca-tabla th, .ca-tabla td { padding: 4px; border: 1px solid #ccc; }
            body { background: #fff !important; }
        }
    </style>
    <link rel="stylesheet" href="../css/responsive.css?v=4">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">ASISTENCIA · PREFECTURA</span>
                    <h1>Concentrado por periodo</h1>
                    <p>Resumen de asistencia por grupo: semana, mes o semestre escolar.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <section class="ca-card ca-filtros-card">
            <form method="GET" class="ca-filtros" id="formFiltros">
                <div>
                    <label for="id_grupo">Grupo</label>
                    <select name="id_grupo" id="id_grupo" required>
                        <option value="">Selecciona un grupo…</option>
                        <?php foreach ($grupos as $g): ?>
                            <option value="<?php echo (int)$g['id_grupo']; ?>" <?php echo (int)$g['id_grupo'] === $id_grupo ? 'selected' : ''; ?>><?php echo $h($g['semestre'] . '° "' . $g['nombre_grupo'] . '" · ' . $g['turno']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="periodo">Periodo</label>
                    <select name="periodo" id="periodo">
                        <?php foreach (['semana' => 'Esta semana', 'mes' => 'Este mes', 'semestre' => 'Semestre actual', 'semestre_anterior' => 'Semestre anterior', 'personalizado' => 'Fechas personalizadas'] as $k => $t): ?>
                            <option value="<?php echo $k; ?>" <?php echo $periodo === $k ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ca-fecha"><label for="desde">Desde</label><input type="date" name="desde" id="desde" value="<?php echo $h($desde); ?>"></div>
                <div class="ca-fecha"><label for="hasta">Hasta</label><input type="date" name="hasta" id="hasta" value="<?php echo $h($hasta); ?>"></div>
                <button type="submit" class="ca-btn verde"><i class="fas fa-magnifying-glass-chart"></i> Consultar</button>
            </form>
        </section>

        <?php if (!$grupo): ?>
            <section class="ca-card" style="text-align:center; color:#64748b;">
                <i class="fas fa-chart-column" style="font-size:2rem; color:#94a3b8;"></i>
                <p>Selecciona un grupo y un periodo para ver el concentrado de asistencia.</p>
            </section>
        <?php else: ?>
            <section class="ca-card">
                <div class="ca-solo-print">
                    <h2 style="margin:0;">COBAEP Plantel 27 · Concentrado de asistencia</h2>
                </div>
                <div class="ca-encabezado">
                    <div>
                        <h2><?php echo $h($grupo['semestre'] . '° "' . $grupo['nombre_grupo'] . '" · Turno ' . $grupo['turno']); ?></h2>
                        <p><?php echo $h($etiqueta); ?> · <?php echo count($dias_habiles); ?> día(s) hábil(es)<?php echo $hasta > $hasta_calculo ? ' (hasta hoy)' : ''; ?></p>
                    </div>
                    <div class="ca-acciones" style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?php $qs_desc = http_build_query(['id_grupo' => $id_grupo, 'periodo' => $periodo, 'desde' => $desde, 'hasta' => $hasta]); ?>
                        <a class="ca-btn excel" href="?<?php echo $h($qs_desc); ?>&amp;formato=xlsx"><i class="fas fa-file-excel"></i> Descargar Excel</a>
                        <a class="ca-btn pdf" href="?<?php echo $h($qs_desc); ?>&amp;formato=pdf"><i class="fas fa-file-pdf"></i> Descargar PDF</a>
                    </div>
                </div>

                <div class="ca-resumen">
                    <div><strong><?php echo count($filas); ?></strong><span>Alumnos</span></div>
                    <div><strong><?php echo $totales['puntual']; ?></strong><span>Asistencias puntuales</span></div>
                    <div><strong><?php echo $totales['retardo']; ?></strong><span>Retardos</span></div>
                    <div><strong><?php echo $totales['justif']; ?></strong><span>Justificadas</span></div>
                    <div><strong><?php echo $totales['falta']; ?></strong><span>Faltas</span></div>
                    <div><strong><?php echo $pct_grupo !== null ? $pct_grupo . '%' : '—'; ?></strong><span>Asistencia del grupo</span></div>
                </div>

                <div class="table-responsive">
                    <table class="ca-tabla" id="tablaConcentrado">
                        <thead>
                            <tr><th>#</th><th>Alumno</th><th>Matrícula</th><th>Días hábiles</th><th>Puntual</th><th>Retardos</th><th>Justificadas</th><th>Faltas</th><th>Suspendido</th><th>% Asistencia</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$filas): ?>
                                <tr><td colspan="10" style="padding:24px; color:#64748b;">No hay alumnos en este grupo para el periodo seleccionado.</td></tr>
                            <?php else: foreach ($filas as $i => $f):
                                $cls = $f['porcentaje'] === null ? '' : ($f['porcentaje'] >= 90 ? 'ok' : ($f['porcentaje'] >= 80 ? 'medio' : 'bajo')); ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo $h(trim($f['apellido_paterno'] . ' ' . $f['apellido_materno'] . ' ' . $f['nombre'])); ?></td>
                                    <td><?php echo $h($f['matricula']); ?></td>
                                    <td><?php echo (int)$f['dias']; ?></td>
                                    <td><?php echo (int)$f['puntual']; ?></td>
                                    <td><?php echo (int)$f['retardo']; ?></td>
                                    <td><?php echo (int)$f['justif']; ?></td>
                                    <td><?php echo (int)$f['falta']; ?></td>
                                    <td><?php echo (int)$f['susp']; ?></td>
                                    <td><span class="ca-pct <?php echo $cls; ?>"><?php echo $f['porcentaje'] !== null ? $f['porcentaje'] . '%' : '—'; ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="ca-nota"><i class="fas fa-circle-info"></i> Se cuentan solo días hábiles (lunes a viernes) y se excluyen las suspensiones de clases registradas. Un día hábil sin registro de entrada cuenta como falta. Los alumnos se cuentan desde su fecha de inscripción al grupo. Verde: 90% o más · Amarillo: 80–89% · Rojo: menos de 80%.</p>
            </section>
        <?php endif; ?>
    </main>
    <script>
        // Mostrar las fechas solo en "personalizado"
        (function () {
            const sel = document.getElementById('periodo');
            const toggle = () => document.querySelectorAll('.ca-fecha').forEach(e => e.style.display = sel.value === 'personalizado' ? '' : 'none');
            sel.addEventListener('change', toggle); toggle();
        })();
    </script>
    <script src="../Prefectura/procesos/dashboard_prefecturas.js"></script>
    <script src="../js/responsive.js?v=6"></script>
</body>
</html>
