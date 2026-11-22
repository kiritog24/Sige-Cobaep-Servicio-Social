<?php
/**
 * Prefectura/avisos_tutores.php — Avisos por correo al padre, madre o tutor.
 *  1. Faltas: lista de alumnos que superan el límite de faltas sin justificar en un periodo; Prefectura elige a quién avisar.
 *  2. Historial de todos los avisos (también los de reportes de conducta, que se generan solos) y reenvío de pendientes.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/avisos_tutor.php';

$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || $rol !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Mexico_City');

$valida = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f) && strtotime($f);
$hoy = date('Y-m-d');
$periodo = in_array($_REQUEST['periodo'] ?? '', ['semana', 'semana_anterior', 'mes', 'personalizado'], true) ? $_REQUEST['periodo'] : 'semana';
switch ($periodo) {
    case 'semana_anterior': $desde = date('Y-m-d', strtotime('monday last week')); $hasta = date('Y-m-d', strtotime('friday last week')); break;
    case 'mes':             $desde = date('Y-m-01'); $hasta = date('Y-m-t'); break;
    case 'personalizado':
        $desde = $valida($_REQUEST['desde'] ?? '') ? $_REQUEST['desde'] : date('Y-m-01');
        $hasta = $valida($_REQUEST['hasta'] ?? '') ? $_REQUEST['hasta'] : $hoy;
        if ($hasta < $desde) [$desde, $hasta] = [$hasta, $desde];
        break;
    default:                $desde = date('Y-m-d', strtotime('monday this week')); $hasta = date('Y-m-d', strtotime('friday this week'));
}
$hasta_calc = min($hasta, $hoy);
$umbral = max(1, min(30, (int)($_REQUEST['umbral'] ?? 3)));
$id_grupo = (int)($_REQUEST['id_grupo'] ?? 0);
$sin_registro = ($_REQUEST['sin_registro'] ?? '1') === '1';
$volver = 'avisos_tutores.php?' . http_build_query(['periodo' => $periodo, 'desde' => $desde, 'hasta' => $hasta, 'umbral' => $umbral, 'id_grupo' => $id_grupo, 'sin_registro' => $sin_registro ? 1 : 0]);

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    @set_time_limit(0);
    if ($accion === 'enviar_faltas') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['alumnos'] ?? []))));
        if (!$ids) { header("Location: $volver&error=" . urlencode('Selecciona al menos un alumno.')); exit(); }
        $faltas = faltas_periodo($pdo, $desde, $hasta_calc, $id_grupo, $sin_registro);
        $cuenta = ['enviado' => 0, 'pendiente' => 0, 'sin_correo' => 0, 'fallido' => 0, 'duplicado' => 0, 'error' => 0];
        foreach ($ids as $id) {
            if (empty($faltas[$id])) continue;
            $r = aviso_tutor_faltas($pdo, $id, $desde, $hasta_calc, $faltas[$id]['faltas'], $faltas[$id]['fechas']);
            $cuenta[$r] = ($cuenta[$r] ?? 0) + 1;
        }
        bitacora($pdo, 'avisos', 'faltas', 'Generó avisos de faltas (' . date('d/m', strtotime($desde)) . '–' . date('d/m/Y', strtotime($hasta_calc)) . "): {$cuenta['enviado']} enviados, {$cuenta['pendiente']} pendientes, {$cuenta['sin_correo']} sin correo del tutor, {$cuenta['fallido']} fallidos");
        $msg = "Avisos procesados: {$cuenta['enviado']} enviados" . ($cuenta['pendiente'] ? ", {$cuenta['pendiente']} pendientes (correo del plantel sin configurar)" : '')
             . ($cuenta['sin_correo'] ? ", {$cuenta['sin_correo']} sin correo del tutor" : '') . ($cuenta['fallido'] ? ", {$cuenta['fallido']} fallidos" : '')
             . ($cuenta['duplicado'] ? ", {$cuenta['duplicado']} ya se habían avisado en este periodo" : '') . '.';
        header("Location: $volver&exito=" . urlencode($msg) . '#historial');
        exit();
    }
    if ($accion === 'reenviar') {
        if (!correo_activo()) { header("Location: $volver&error=" . urlencode('Primero Administración debe registrar la cuenta en Correo de avisos.') . '#historial'); exit(); }
        $ids = $pdo->query("SELECT id_aviso FROM AVISOS_TUTOR WHERE estatus IN ('pendiente','fallido','sin_correo') AND intentos < 5 ORDER BY id_aviso LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0;
        foreach ($ids as $id) $ok += aviso_enviar($pdo, (int)$id) === 'enviado' ? 1 : 0;
        bitacora($pdo, 'avisos', 'reenvio', "Reenvió avisos pendientes: $ok de " . count($ids) . ' enviados');
        header("Location: $volver&exito=" . urlencode("Se enviaron $ok de " . count($ids) . ' avisos pendientes.') . '#historial');
        exit();
    }
}

// ---------- Datos ----------
$grupos = $pdo->query("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE estatus = 'Activo' ORDER BY semestre, turno, nombre_grupo")->fetchAll(PDO::FETCH_ASSOC);
$faltas = faltas_periodo($pdo, $desde, $hasta_calc, $id_grupo, $sin_registro);
$faltas = array_filter($faltas, fn($f) => $f['faltas'] >= $umbral);
$lista = [];
if ($faltas) {
    $ids = implode(',', array_map('intval', array_keys($faltas)));
    $ref = "faltas-$desde-$hasta_calc";
    $st = $pdo->prepare("SELECT a.id_alumno, a.matricula, CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS alumno,
                                t.nombre_tutor, t.correo_tutor,
                                IF(g.id_grupo IS NULL, '—', CONCAT(g.semestre, '° ', g.nombre_grupo, ' · ', g.turno)) AS grupo,
                                (SELECT estatus FROM AVISOS_TUTOR av WHERE av.id_alumno = a.id_alumno AND av.tipo = 'faltas' AND av.referencia = ?) AS aviso_periodo
                         FROM ALUMNOS a LEFT JOIN TUTOR_ALUMNO t ON t.id_alumno = a.id_alumno
                         LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                         LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                         WHERE a.id_alumno IN ($ids) GROUP BY a.id_alumno ORDER BY g.semestre, g.turno, g.nombre_grupo, a.apellido_paterno, a.nombre");
    $st->execute([$ref]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $lista[] = $r + $faltas[(int)$r['id_alumno']];
    usort($lista, fn($a, $b) => $b['faltas'] <=> $a['faltas']);
}
$con_correo = count(array_filter($lista, fn($r) => !empty($r['correo_tutor'])));
$historial = $pdo->query("SELECT av.id_aviso, av.tipo, av.estatus, av.correo_destino, av.asunto, av.error, av.fecha_creacion, av.fecha_envio, a.matricula
                          FROM AVISOS_TUTOR av INNER JOIN ALUMNOS a ON a.id_alumno = av.id_alumno ORDER BY av.id_aviso DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
$pendientes = (int)$pdo->query("SELECT COUNT(*) FROM AVISOS_TUTOR WHERE estatus IN ('pendiente','fallido','sin_correo') AND intentos < 5")->fetchColumn();
$total_alumnos = (int)$pdo->query("SELECT COUNT(*) FROM ALUMNOS WHERE estatus = 'Alta'")->fetchColumn();
$tutores_con_correo = (int)$pdo->query("SELECT COUNT(*) FROM ALUMNOS a INNER JOIN TUTOR_ALUMNO t ON t.id_alumno = a.id_alumno WHERE a.estatus = 'Alta' AND t.correo_tutor IS NOT NULL AND t.correo_tutor <> ''")->fetchColumn();

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$chips = ['enviado' => ['Enviado', 'ok'], 'pendiente' => ['Pendiente', 'pend'], 'fallido' => ['Falló', 'err'], 'sin_correo' => ['Sin correo', 'warn']];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Avisos a tutores · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">
    <link rel="stylesheet" href="../css/responsive.css?v=4">
    <style>
        .at-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; box-shadow: 0 4px 18px rgba(15, 23, 42, .04); margin-bottom: 20px; }
        .at-card h2 { margin: 0 0 4px; font-size: 1.08rem; color: #0f172a; display: flex; gap: 10px; align-items: center; }
        .at-card h2 i { color: #16a05d; }
        .at-card > p.sub { margin: 0 0 16px; color: #64748b; font-size: .86rem; }
        .at-alerta { padding: 13px 16px; border-radius: 12px; margin-bottom: 16px; font-size: .88rem; line-height: 1.5; }
        .at-alerta.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-weight: 600; }
        .at-alerta.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-weight: 600; }
        .at-alerta.aviso { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .at-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
        .at-stats div { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 16px; }
        .at-stats b { display: block; font-size: 1.45rem; color: #063f2b; }
        .at-stats span { font-size: .8rem; color: #64748b; font-weight: 600; }
        .at-filtros { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto; gap: 10px; align-items: end; margin-bottom: 14px; }
        .at-filtros label { display: block; font-size: .74rem; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .03em; }
        .at-filtros input, .at-filtros select { width: 100%; box-sizing: border-box; padding: 10px 11px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: .88rem; background: #fff; }
        .at-fechas { display: none; grid-column: 1 / -1; grid-template-columns: 1fr 1fr; gap: 10px; }
        .at-fechas.visible { display: grid; }
        .at-check { display: flex; gap: 8px; align-items: center; font-size: .84rem; color: #475569; grid-column: 1 / -1; text-transform: none; letter-spacing: 0; font-weight: 500; }
        .at-filtros .at-check input { width: auto; flex: 0 0 auto; margin: 0; }
        .at-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 16px; border-radius: 10px; border: none; background: #16a05d; color: #fff; font-weight: 700; font-family: inherit; font-size: .86rem; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .at-btn.sec { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
        .at-btn:disabled { opacity: .5; cursor: not-allowed; }
        .at-tabla-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; }
        table.at-tabla { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .at-tabla th { background: #f8fafc; text-align: left; padding: 10px; color: #475569; font-size: .73rem; text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid #e2e8f0; }
        .at-tabla td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .at-tabla td small { display: block; color: #64748b; }
        .at-faltas { display: inline-grid; place-items: center; min-width: 34px; height: 30px; padding: 0 6px; border-radius: 9px; background: #fef2f2; color: #b91c1c; font-weight: 800; }
        .at-chip { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
        .at-chip.ok { background: #ecfdf5; color: #047857; } .at-chip.pend { background: #eff6ff; color: #1d4ed8; }
        .at-chip.err { background: #fef2f2; color: #b91c1c; } .at-chip.warn { background: #fffbeb; color: #b45309; }
        .at-pie { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; font-size: .85rem; color: #475569; }
        .at-vacio { text-align: center; padding: 34px 10px; color: #94a3b8; }
        @media (max-width: 1000px) { .at-filtros { grid-template-columns: 1fr 1fr; } .at-filtros .at-acc { grid-column: 1 / -1; } }
        @media (max-width: 560px) { .at-stats { grid-template-columns: 1fr; } .at-btn { width: 100%; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">CONDUCTA · PREFECTURA</span>
                    <h1>Avisos a tutores</h1>
                    <p>Correos al padre, madre o tutor por faltas acumuladas y reportes de conducta.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <?php if (isset($_GET['exito'])): ?><div class="at-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="at-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>
        <?php if (!correo_activo()): ?>
            <div class="at-alerta aviso"><i class="fas fa-envelope-circle-check"></i> <b>El correo del plantel aún no está configurado.</b> Los avisos se guardan como <b>pendientes</b> y podrás enviarlos con un clic cuando <b>Administración</b> registre la cuenta que entregue el instituto (menú <b>Correo de avisos</b>).</div>
        <?php endif; ?>

        <div class="at-stats">
            <div><b><?php echo $tutores_con_correo; ?> / <?php echo $total_alumnos; ?></b><span>Alumnos con correo del tutor registrado</span></div>
            <div><b><?php echo $pendientes; ?></b><span>Avisos por enviar</span></div>
            <div><b><?php echo correo_activo() ? 'Activo' : 'Sin configurar'; ?></b><span>Correo del plantel</span></div>
        </div>

        <section class="at-card">
            <h2><i class="fas fa-user-clock"></i> Avisos por faltas</h2>
            <p class="sub">Alumnos con <b><?php echo $umbral; ?> o más</b> faltas sin justificar del <?php echo date('d/m/Y', strtotime($desde)); ?> al <?php echo date('d/m/Y', strtotime($hasta_calc)); ?>. Revisa la lista y elige a quién avisar.</p>
            <form method="GET" class="at-filtros" id="formFiltros">
                <div><label for="fPeriodo">Periodo</label>
                    <select id="fPeriodo" name="periodo">
                        <option value="semana" <?php echo $periodo === 'semana' ? 'selected' : ''; ?>>Esta semana</option>
                        <option value="semana_anterior" <?php echo $periodo === 'semana_anterior' ? 'selected' : ''; ?>>Semana anterior</option>
                        <option value="mes" <?php echo $periodo === 'mes' ? 'selected' : ''; ?>>Este mes</option>
                        <option value="personalizado" <?php echo $periodo === 'personalizado' ? 'selected' : ''; ?>>Otras fechas</option>
                    </select></div>
                <div><label for="fUmbral">Faltas mínimas</label><input type="number" id="fUmbral" name="umbral" min="1" max="30" value="<?php echo $umbral; ?>"></div>
                <div><label for="fGrupo">Grupo</label>
                    <select id="fGrupo" name="id_grupo"><option value="0">Todos</option>
                        <?php foreach ($grupos as $g): ?><option value="<?php echo (int)$g['id_grupo']; ?>" <?php echo $id_grupo === (int)$g['id_grupo'] ? 'selected' : ''; ?>><?php echo $h($g['semestre'] . '° ' . $g['nombre_grupo'] . ' · ' . $g['turno']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="at-acc"><button type="submit" class="at-btn sec" style="width:100%"><i class="fas fa-magnifying-glass"></i> Buscar</button></div>
                <div class="at-fechas <?php echo $periodo === 'personalizado' ? 'visible' : ''; ?>" id="fFechas">
                    <div><label>Desde</label><input type="date" name="desde" value="<?php echo $h($desde); ?>" max="<?php echo $hoy; ?>"></div>
                    <div><label>Hasta</label><input type="date" name="hasta" value="<?php echo $h($hasta); ?>" max="<?php echo $hoy; ?>"></div>
                </div>
                <label class="at-check"><input type="hidden" name="sin_registro" value="0"><input type="checkbox" name="sin_registro" value="1" <?php echo $sin_registro ? 'checked' : ''; ?>> Contar como falta los días sin registro de entrada (igual que el concentrado)</label>
            </form>

            <?php if (!$lista): ?>
                <div class="at-vacio"><i class="fas fa-face-smile" style="font-size:1.8rem; display:block; margin-bottom:8px;"></i>Ningún alumno llega a <?php echo $umbral; ?> faltas en este periodo.</div>
            <?php else: ?>
                <form method="POST" action="<?php echo $h($volver); ?>" onsubmit="return confirmarEnvio(this);">
                    <input type="hidden" name="accion" value="enviar_faltas">
                    <div class="at-tabla-wrap">
                        <table class="at-tabla">
                            <thead><tr><th style="width:34px"><input type="checkbox" id="todos" aria-label="Seleccionar todos"></th><th>Alumno</th><th>Grupo</th><th>Faltas</th><th>Padre, madre o tutor</th><th>Aviso de este periodo</th></tr></thead>
                            <tbody>
                            <?php foreach ($lista as $r): $ya = $r['aviso_periodo']; ?>
                                <tr>
                                    <td><input type="checkbox" class="sel" name="alumnos[]" value="<?php echo (int)$r['id_alumno']; ?>" <?php echo $ya ? 'disabled' : ''; ?>></td>
                                    <td><b><?php echo $h($r['alumno']); ?></b><small><?php echo $h($r['matricula']); ?></small></td>
                                    <td><?php echo $h($r['grupo']); ?></td>
                                    <td><span class="at-faltas" title="<?php echo $h(implode(', ', array_map(fn($f) => date('d/m', strtotime($f)), $r['fechas']))); ?>"><?php echo (int)$r['faltas']; ?></span></td>
                                    <td><?php if ($r['correo_tutor']): ?><?php echo $h($r['nombre_tutor'] ?: 'Tutor'); ?><small><?php echo $h($r['correo_tutor']); ?></small><?php else: ?><span class="at-chip warn">Sin correo registrado</span><?php endif; ?></td>
                                    <td><?php echo $ya ? '<span class="at-chip ' . $chips[$ya][1] . '">' . $chips[$ya][0] . '</span>' : '<span style="color:#94a3b8">No enviado</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="at-pie">
                        <span><b><?php echo count($lista); ?></b> alumno(s) · <?php echo $con_correo; ?> con correo del tutor. Los que no tienen correo deben registrarlo en su perfil (o Control Escolar en "Editar usuario").</span>
                        <button type="submit" class="at-btn" id="btnEnviar"><i class="fas fa-paper-plane"></i> <?php echo correo_activo() ? 'Enviar avisos' : 'Guardar avisos (pendientes)'; ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="at-card" id="historial">
            <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start;">
                <div><h2><i class="fas fa-paper-plane"></i> Historial de avisos</h2><p class="sub" style="margin:0 0 14px; color:#64748b; font-size:.86rem;">Incluye los avisos de reportes de conducta, que se generan solos al levantar el reporte.</p></div>
                <?php if ($pendientes): ?>
                    <form method="POST" action="<?php echo $h($volver); ?>"><input type="hidden" name="accion" value="reenviar"><button type="submit" class="at-btn" <?php echo correo_activo() ? '' : 'disabled title="Administración debe registrar primero la cuenta de correo"'; ?>><i class="fas fa-rotate"></i> Enviar <?php echo $pendientes; ?> pendiente(s)</button></form>
                <?php endif; ?>
            </div>
            <?php if (!$historial): ?>
                <div class="at-vacio">Todavía no se ha generado ningún aviso.</div>
            <?php else: ?>
                <div class="at-tabla-wrap">
                    <table class="at-tabla">
                        <thead><tr><th>Fecha</th><th>Tipo</th><th>Aviso</th><th>Para</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($historial as $a): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($a['fecha_creacion'])); ?><small><?php echo date('h:i a', strtotime($a['fecha_creacion'])); ?></small></td>
                                <td><?php echo $a['tipo'] === 'conducta' ? '<i class="fas fa-file-lines" style="color:#b45309"></i> Conducta' : '<i class="fas fa-user-clock" style="color:#b91c1c"></i> Faltas'; ?></td>
                                <td><?php echo $h($a['asunto']); ?><small><?php echo $h($a['matricula']); ?></small></td>
                                <td><?php echo $h($a['correo_destino'] ?: '—'); ?></td>
                                <td><span class="at-chip <?php echo $chips[$a['estatus']][1]; ?>"><?php echo $chips[$a['estatus']][0]; ?></span><?php if ($a['estatus'] === 'fallido' && $a['error']): ?><small><?php echo $h(mb_substr($a['error'], 0, 90)); ?></small><?php endif; ?><?php if ($a['fecha_envio']): ?><small><?php echo date('d/m h:i a', strtotime($a['fecha_envio'])); ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        document.getElementById('fPeriodo').addEventListener('change', function () {
            document.getElementById('fFechas').classList.toggle('visible', this.value === 'personalizado');
            if (this.value !== 'personalizado') document.getElementById('formFiltros').submit();
        });
        const todos = document.getElementById('todos');
        if (todos) todos.addEventListener('change', () => document.querySelectorAll('.sel:not(:disabled)').forEach(c => c.checked = todos.checked));
        function confirmarEnvio(form) {
            if (form.dataset.ok) return true;
            const n = form.querySelectorAll('.sel:checked').length;
            if (!n) { (window.sigeAlert || alert)('Selecciona al menos un alumno.', 'aviso'); return false; }
            const msg = 'Se generará el aviso de faltas para ' + n + ' alumno(s).' + <?php echo json_encode(correo_activo() ? ' Se enviará por correo a sus tutores.' : ' Quedarán pendientes hasta que se configure el correo del plantel.'); ?>;
            const seguir = () => { form.dataset.ok = '1'; document.getElementById('btnEnviar').disabled = true; form.submit(); };
            if (window.sigeConfirm) { window.sigeConfirm(msg, { titulo: 'Avisos a tutores', aceptar: 'Continuar' }).then(ok => { if (ok) seguir(); }); return false; }
            return confirm(msg);
        }
    </script>
    <script src="../Prefectura/procesos/dashboard_prefecturas.js"></script>
    <script src="../js/responsive.js?v=6"></script>
</body>
</html>
