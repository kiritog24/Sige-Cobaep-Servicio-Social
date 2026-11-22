<?php
/**
 * admin/cierre_ciclo.php — Cierre de ciclo escolar en 3 pasos:
 *   1. Respaldo completo de la base de datos (descargable).
 *   2. Archivar las asistencias de los ciclos terminados (se mueven a tablas de archivo; nada se borra).
 *   3. Promover a los grupos al siguiente semestre (egresan los de 6°).
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/archivo_ciclo.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

function volver_cierre(string $tipo, string $msg, string $ancla = ''): void {
    header('Location: cierre_ciclo.php?' . $tipo . '=' . urlencode($msg) . $ancla);
    exit();
}

$valida = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f) && strtotime($f);
$hoy = date('Y-m-d');
[$ciclo_ini, $ciclo_fin, $ciclo_txt] = ciclo_de($hoy);
$corte = $valida($_REQUEST['corte'] ?? '') ? $_REQUEST['corte'] : $ciclo_ini;
if ($corte > $hoy) $corte = $hoy;

// ---------- Descargas ----------
if (isset($_GET['descargar'])) {
    $n = basename((string)$_GET['descargar']);
    $ruta = RESPALDOS_DIR . '/' . $n;
    if (!preg_match('/^respaldo_[0-9_\-]+[a-z0-9]*\.sql$/', $n) || !is_file($ruta)) volver_cierre('error', 'El respaldo no existe.', '#paso1');
    bitacora($pdo, 'sistema', 'descarga_respaldo', 'Descargó el respaldo ' . $n);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $n . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit();
}
if (isset($_GET['excel_ciclo']) && archivo_existe($pdo)) {
    require_once __DIR__ . '/../includes/xlsx_simple.php';
    $ciclo = mb_substr((string)$_GET['excel_ciclo'], 0, 30);
    $st = $pdo->prepare("SELECT DATE_FORMAT(x.fecha_hora_escaneo, '%d/%m/%Y'), DATE_FORMAT(x.fecha_hora_escaneo, '%H:%i'), a.matricula,
                                CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre), x.tipo_registro, x.estatus, COALESCE(x.motivo_justificacion, '')
                         FROM ASISTENCIAS_ARCHIVO x LEFT JOIN ALUMNOS a ON a.id_alumno = x.id_alumno
                         WHERE x.ciclo = ? ORDER BY x.fecha_hora_escaneo, a.apellido_paterno");
    $st->execute([$ciclo]);
    xlsx_descargar('Asistencias_archivadas_' . preg_replace('/[^A-Za-z0-9]+/', '_', $ciclo) . '.xlsx', 'Asistencias',
        ['COBAEP Plantel 27 · Asistencias archivadas', 'Ciclo ' . $ciclo], ['Fecha', 'Hora', 'Matrícula', 'Alumno', 'Registro', 'Estatus', 'Justificación'],
        $st->fetchAll(PDO::FETCH_NUM), [12, 8, 16, 40, 10, 18, 40]);
}

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'respaldo') {
            $n = respaldo_generar($pdo, 'manual');
            bitacora($pdo, 'sistema', 'respaldo', 'Creó un respaldo de la base de datos (' . $n . ')');
            volver_cierre('exito', 'Respaldo creado. Descárgalo y guárdalo fuera de esta computadora (USB, Drive).', '#paso1');
        }
        if ($accion === 'archivar') {
            if (strtoupper(trim($_POST['confirmacion'] ?? '')) !== 'ARCHIVAR') volver_cierre('error', 'Escribe ARCHIVAR para confirmar.', '#paso2');
            if ($corte > $ciclo_ini) volver_cierre('error', 'Solo se pueden archivar ciclos terminados (antes del ' . date('d/m/Y', strtotime($ciclo_ini)) . ').', '#paso2');
            $previo = archivo_contar($pdo, $corte);
            if (!$previo['total']) volver_cierre('error', 'No hay asistencias anteriores al ' . date('d/m/Y', strtotime($corte)) . ' para archivar.', '#paso2');
            $n = respaldo_generar($pdo, 'antes_archivar');   // siempre se respalda antes de mover datos
            $r = archivo_ejecutar($pdo, $corte);
            bitacora($pdo, 'sistema', 'archivar', 'Archivó las asistencias anteriores al ' . date('d/m/Y', strtotime($corte)) . ": {$r['plantel']} del plantel y {$r['clases']} de clases (respaldo previo: $n)",
                     null, null, ['ciclos' => array_keys($previo['plantel'] + $previo['clases'])]);
            volver_cierre('exito', "Listo: se archivaron {$r['plantel']} registros de entrada al plantel y {$r['clases']} de asistencia a clases. Antes se creó el respaldo $n.", '#paso2');
        }
    } catch (Throwable $e) {
        error_log('cierre_ciclo: ' . $e->getMessage());
        volver_cierre('error', 'No se pudo completar la operación. No se movió ningún dato.');
    }
    volver_cierre('error', 'Acción no reconocida.');
}

// ---------- Datos ----------
$respaldos = respaldos_lista();
$ultimo_respaldo = $respaldos[0]['fecha'] ?? null;
$previo = archivo_contar($pdo, $corte);
$ciclos = archivo_ciclos($pdo);
$diario = (int)$pdo->query("SELECT COUNT(*) FROM ASISTENCIAS")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM ASISTENCIAS_CLASES")->fetchColumn();
$grupos = $pdo->query("SELECT semestre, COUNT(*) n FROM GRUPOS WHERE estatus = 'Activo' GROUP BY semestre ORDER BY semestre")->fetchAll(PDO::FETCH_KEY_PAIR);
$egresan = (int)$pdo->query("SELECT COUNT(DISTINCT ga.id_alumno) FROM GRUPO_ALUMNO ga INNER JOIN GRUPOS g ON g.id_grupo = ga.id_grupo AND g.estatus = 'Activo' AND g.semestre >= 6 WHERE ga.fecha_baja IS NULL")->fetchColumn();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tam = fn($b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Cierre de ciclo · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=4">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .ce-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .ce-title { display: flex; align-items: center; gap: 14px; }
        .ce-title .ico { width: 52px; height: 52px; border-radius: 14px; background: #fef3c7; color: #b45309; display: grid; place-items: center; font-size: 1.35rem; flex-shrink: 0; }
        .ce-title h1 { margin: 0; font-size: 1.55rem; color: #0f172a; }
        .ce-title p { margin: 3px 0 0; color: #64748b; font-size: .9rem; }
        .ce-resumen { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .ce-resumen div { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 16px; }
        .ce-resumen b { display: block; font-size: 1.25rem; color: #063f2b; }
        .ce-resumen span { font-size: .8rem; color: #64748b; font-weight: 600; }
        .ce-paso { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 22px; box-shadow: 0 4px 18px rgba(15, 23, 42, .04); margin-bottom: 20px; display: grid; grid-template-columns: 48px 1fr; gap: 16px; }
        .ce-num { width: 44px; height: 44px; border-radius: 50%; background: #16a05d; color: #fff; display: grid; place-items: center; font-weight: 800; font-size: 1.1rem; }
        .ce-paso h2 { margin: 6px 0 4px; font-size: 1.1rem; color: #0f172a; }
        .ce-paso p.sub { margin: 0 0 14px; color: #64748b; font-size: .88rem; line-height: 1.5; }
        .ce-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 18px; border-radius: 11px; border: none; background: #16a05d; color: #fff; font-weight: 700; font-family: inherit; font-size: .9rem; cursor: pointer; text-decoration: none; }
        .ce-btn:hover { background: #12804b; }
        .ce-btn.sec { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
        .ce-btn.ambar { background: #d97706; } .ce-btn.ambar:hover { background: #b45309; }
        .ce-btn:disabled { opacity: .5; cursor: not-allowed; }
        .ce-tabla { width: 100%; border-collapse: collapse; font-size: .86rem; margin-top: 12px; }
        .ce-tabla th { text-align: left; padding: 9px 10px; background: #f8fafc; color: #475569; font-size: .73rem; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .ce-tabla td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; }
        .ce-fila { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .ce-campo { display: flex; flex-direction: column; gap: 5px; }
        .ce-campo label { font-size: .74rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .ce-campo input { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: .92rem; }
        .ce-alerta { padding: 13px 16px; border-radius: 12px; margin-bottom: 18px; font-weight: 600; font-size: .9rem; }
        .ce-alerta.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .ce-alerta.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .ce-nota { font-size: .82rem; color: #475569; background: #f8fafc; border-radius: 10px; padding: 10px 12px; margin-top: 12px; line-height: 1.5; }
        .ce-nota.ambar { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .ce-chips { display: flex; gap: 8px; flex-wrap: wrap; margin: 6px 0 4px; }
        .ce-chips span { background: #f1f5f9; border-radius: 20px; padding: 5px 11px; font-size: .8rem; font-weight: 600; color: #334155; }
        .ce-tabla-wrap { overflow-x: auto; }
        @media (max-width: 900px) { .ce-resumen { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 560px) { .ce-paso { grid-template-columns: 1fr; } .ce-btn { width: 100%; } .ce-resumen { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="ce-head">
            <div class="ce-title">
                <div class="ico"><i class="fas fa-box-archive"></i></div>
                <div>
                    <h1>Cierre de ciclo escolar</h1>
                    <p>Respalda la información, archiva las asistencias del ciclo terminado y promueve a los grupos.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </div>

        <?php if (isset($_GET['exito'])): ?><div class="ce-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="ce-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

        <div class="ce-resumen">
            <div><b><?php echo $h($ciclo_txt); ?></b><span>Ciclo actual (desde el <?php echo date('d/m/Y', strtotime($ciclo_ini)); ?>)</span></div>
            <div><b><?php echo number_format($diario); ?></b><span>Registros de asistencia en uso diario</span></div>
            <div><b><?php echo count($ciclos); ?></b><span>Ciclos archivados</span></div>
            <div><b><?php echo $ultimo_respaldo ? date('d/m/Y', $ultimo_respaldo) : 'Nunca'; ?></b><span>Último respaldo</span></div>
        </div>

        <!-- PASO 1 -->
        <section class="ce-paso" id="paso1">
            <div class="ce-num">1</div>
            <div>
                <h2>Respaldo de la base de datos</h2>
                <p class="sub">Una copia completa de toda la información del sistema (alumnos, grupos, asistencias, reportes…). Hazla antes de cerrar el ciclo y <b>guárdala fuera de esta computadora</b> (USB o Google Drive).</p>
                <form method="POST" style="margin:0"><input type="hidden" name="accion" value="respaldo"><button type="submit" class="ce-btn"><i class="fas fa-database"></i> Crear respaldo ahora</button></form>
                <?php if ($respaldos): ?>
                    <div class="ce-tabla-wrap"><table class="ce-tabla">
                        <thead><tr><th>Respaldo</th><th>Fecha</th><th>Tamaño</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($respaldos, 0, 8) as $r): ?>
                            <tr><td><?php echo $h($r['nombre']); ?></td><td><?php echo date('d/m/Y h:i a', $r['fecha']); ?></td><td><?php echo $tam($r['tamano']); ?></td>
                                <td style="text-align:right"><a class="ce-btn sec" style="padding:7px 12px" href="?descargar=<?php echo urlencode($r['nombre']); ?>"><i class="fas fa-download"></i> Descargar</a></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
                <div class="ce-nota"><i class="fas fa-circle-info"></i> Para restaurar un respaldo: phpMyAdmin › base <b>sistema_sige_cobaep</b> › Importar › elige el archivo .sql.</div>
            </div>
        </section>

        <!-- PASO 2 -->
        <section class="ce-paso" id="paso2">
            <div class="ce-num">2</div>
            <div>
                <h2>Archivar asistencias de ciclos terminados</h2>
                <p class="sub">Mueve los registros de asistencia anteriores a la fecha de corte a un archivo histórico. Las pantallas de uso diario se mantienen rápidas y <b>nada se borra</b>: el concentrado de Prefectura sigue consultando ciclos anteriores y aquí puedes descargarlos en Excel.</p>
                <form method="GET" class="ce-fila" action="#paso2">
                    <div class="ce-campo"><label for="corte">Archivar todo lo anterior al</label><input type="date" id="corte" name="corte" value="<?php echo $h($corte); ?>" max="<?php echo $h($ciclo_ini); ?>"></div>
                    <button type="submit" class="ce-btn sec"><i class="fas fa-magnifying-glass"></i> Revisar</button>
                </form>
                <?php if ($previo['total']): ?>
                    <p style="margin:14px 0 4px; font-size:.88rem; color:#334155;">Se archivarán <b><?php echo number_format($previo['total']); ?></b> registros:</p>
                    <div class="ce-chips">
                        <?php foreach ($previo['plantel'] as $c => $n): ?><span><i class="fas fa-door-open"></i> <?php echo $h($c); ?>: <?php echo number_format($n); ?> entradas al plantel</span><?php endforeach; ?>
                        <?php foreach ($previo['clases'] as $c => $n): ?><span><i class="fas fa-chalkboard"></i> <?php echo $h($c); ?>: <?php echo number_format($n); ?> asistencias a clase</span><?php endforeach; ?>
                    </div>
                    <form method="POST" class="ce-fila" style="margin-top:12px" onsubmit="return confirm('Se moverán <?php echo (int)$previo['total']; ?> registros al archivo histórico (antes se creará un respaldo automático). ¿Continuar?');">
                        <input type="hidden" name="accion" value="archivar"><input type="hidden" name="corte" value="<?php echo $h($corte); ?>">
                        <div class="ce-campo"><label for="conf">Para confirmar escribe ARCHIVAR</label><input type="text" id="conf" name="confirmacion" autocomplete="off" placeholder="ARCHIVAR" style="text-transform:uppercase"></div>
                        <button type="submit" class="ce-btn ambar" id="btnArchivar" disabled><i class="fas fa-box-archive"></i> Archivar</button>
                    </form>
                <?php else: ?>
                    <div class="ce-nota"><i class="fas fa-circle-check" style="color:#16a05d"></i> No hay asistencias anteriores al <?php echo date('d/m/Y', strtotime($corte)); ?>: el ciclo anterior ya está archivado o todavía no hay ciclos terminados.</div>
                <?php endif; ?>
                <?php if ($ciclos): ?>
                    <div class="ce-tabla-wrap"><table class="ce-tabla">
                        <thead><tr><th>Ciclo archivado</th><th>Del</th><th>Al</th><th>Registros</th><th>Archivado</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($ciclos as $c): ?>
                            <tr><td><b><?php echo $h($c['ciclo']); ?></b></td><td><?php echo date('d/m/Y', strtotime($c['desde'])); ?></td><td><?php echo date('d/m/Y', strtotime($c['hasta'])); ?></td>
                                <td><?php echo number_format($c['registros']); ?></td><td><?php echo date('d/m/Y', strtotime($c['archivado'])); ?></td>
                                <td style="text-align:right"><a class="ce-btn sec" style="padding:7px 12px" href="?excel_ciclo=<?php echo urlencode($c['ciclo']); ?>"><i class="fas fa-file-excel"></i> Excel</a></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- PASO 3 -->
        <section class="ce-paso" id="paso3">
            <div class="ce-num">3</div>
            <div>
                <h2>Promover al siguiente semestre</h2>
                <p class="sub">Al iniciar el nuevo ciclo: los grupos de 1° a 5° pasan al siguiente semestre y los alumnos de 6° egresan (quedan como "Finalizado" y su grupo se cierra).</p>
                <div class="ce-chips">
                    <?php foreach ($grupos as $sem => $n): ?><span><?php echo (int)$sem; ?>° semestre: <?php echo (int)$n; ?> grupo(s)</span><?php endforeach; ?>
                    <span><i class="fas fa-user-graduate"></i> Egresarían: <?php echo $egresan; ?> alumno(s)</span>
                </div>
                <form action="../procesos/promover_semestre.php" method="POST" class="ce-fila" style="margin-top:12px" onsubmit="return confirm('¿Promover a todos los grupos al siguiente semestre? Esta acción no se puede deshacer.');">
                    <div class="ce-campo"><label for="confP">Para confirmar escribe PROMOVER</label><input type="text" id="confP" name="confirmacion" autocomplete="off" placeholder="PROMOVER" style="text-transform:uppercase"></div>
                    <button type="submit" class="ce-btn ambar" id="btnPromover" disabled><i class="fas fa-arrow-up-wide-short"></i> Promover semestre</button>
                </form>
                <div class="ce-nota ambar"><i class="fas fa-triangle-exclamation"></i> Hazlo una sola vez por ciclo, después del respaldo. Queda registrado en la Bitácora.</div>
            </div>
        </section>
    </main>
    <script>
        [['conf', 'btnArchivar', 'ARCHIVAR'], ['confP', 'btnPromover', 'PROMOVER']].forEach(([i, b, t]) => {
            const inp = document.getElementById(i), btn = document.getElementById(b);
            if (inp && btn) inp.addEventListener('input', () => { btn.disabled = inp.value.trim().toUpperCase() !== t; });
        });
    </script>
    <script src="../js/responsive.js?v=6"></script>
</body>
</html>
