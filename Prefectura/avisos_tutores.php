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
$motivo = ($_REQUEST['motivo'] ?? '') === 'retardos' ? 'retardos' : 'faltas';
$palabra = $motivo === 'retardos' ? 'retardos' : 'faltas';
$volver = 'avisos_tutores.php?' . http_build_query(['motivo' => $motivo, 'periodo' => $periodo, 'desde' => $desde, 'hasta' => $hasta, 'umbral' => $umbral, 'id_grupo' => $id_grupo, 'sin_registro' => $sin_registro ? 1 : 0]);
$conteo_periodo = fn() => $motivo === 'retardos' ? retardos_periodo($pdo, $desde, $hasta_calc, $id_grupo) : faltas_periodo($pdo, $desde, $hasta_calc, $id_grupo, $sin_registro);

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    @set_time_limit(0);
    if ($accion === 'enviar_faltas') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['alumnos'] ?? []))));
        if (!$ids) { header("Location: $volver&error=" . urlencode('Selecciona al menos un alumno.')); exit(); }
        $faltas = $conteo_periodo();
        $cuenta = ['enviado' => 0, 'pendiente' => 0, 'sin_correo' => 0, 'fallido' => 0, 'duplicado' => 0, 'error' => 0];
        foreach ($ids as $id) {
            if (empty($faltas[$id])) continue;
            $r = $motivo === 'retardos'
                ? aviso_tutor_retardos($pdo, $id, $desde, $hasta_calc, $faltas[$id]['faltas'], $faltas[$id]['fechas'])
                : aviso_tutor_faltas($pdo, $id, $desde, $hasta_calc, $faltas[$id]['faltas'], $faltas[$id]['fechas']);
            $cuenta[$r] = ($cuenta[$r] ?? 0) + 1;
        }
        bitacora($pdo, 'avisos', $motivo, "Generó avisos de $palabra (" . date('d/m', strtotime($desde)) . '–' . date('d/m/Y', strtotime($hasta_calc)) . "): {$cuenta['enviado']} enviados, {$cuenta['pendiente']} pendientes, {$cuenta['sin_correo']} sin correo del tutor, {$cuenta['fallido']} fallidos");
        $msg = "Avisos procesados: {$cuenta['enviado']} enviados" . ($cuenta['pendiente'] ? ", {$cuenta['pendiente']} pendientes (correo del plantel sin configurar)" : '')
             . ($cuenta['sin_correo'] ? ", {$cuenta['sin_correo']} sin correo del tutor" : '') . ($cuenta['fallido'] ? ", {$cuenta['fallido']} fallidos" : '')
             . ($cuenta['duplicado'] ? ", {$cuenta['duplicado']} ya se habían avisado en este periodo" : '') . '.';
        header("Location: $volver&exito=" . urlencode($msg) . '#historial');
        exit();
    }
    if ($accion === 'enviar_mensaje') {
        $tipo_msg = ($_POST['tipo_msg'] ?? '') === 'citatorio' ? 'citatorio' : 'general';
        $asunto = mb_substr(trim($_POST['asunto'] ?? ''), 0, 90);
        $mensaje = mb_substr(trim($_POST['mensaje'] ?? ''), 0, 2000);
        $cita = ['fecha' => $_POST['fecha_cita'] ?? '', 'hora' => $_POST['hora_cita'] ?? '', 'lugar' => mb_substr(trim($_POST['lugar_cita'] ?? ''), 0, 120)];
        $volver_msg = $volver . '#mensaje';
        if ($mensaje === '') { header("Location: $volver&error=" . urlencode('Escribe el mensaje o motivo del aviso.') . '#mensaje'); exit(); }
        if ($tipo_msg === 'citatorio') {
            if (!$valida($cita['fecha']) || $cita['fecha'] < $hoy) { header("Location: $volver&error=" . urlencode('Elige la fecha de la cita (hoy o después).') . '#mensaje'); exit(); }
            if ($cita['hora'] !== '' && !preg_match('/^\d{2}:\d{2}$/', $cita['hora'])) $cita['hora'] = '';
        }
        // Destinatarios: uno o varios alumnos por matrícula, o un grupo completo
        $ids = [];
        if (($_POST['destino'] ?? '') === 'grupo') {
            $g = (int)($_POST['id_grupo_msg'] ?? 0);
            $st = $pdo->prepare("SELECT ga.id_alumno FROM GRUPO_ALUMNO ga INNER JOIN ALUMNOS a ON a.id_alumno = ga.id_alumno AND a.estatus = 'Alta' WHERE ga.id_grupo = ? AND ga.fecha_baja IS NULL");
            $st->execute([$g]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $destino_txt = 'del grupo ' . bitacora_quien($pdo, 'grupo', $g);
        } else {
            $mats = array_filter(array_map('trim', preg_split('/[\s,;]+/', mb_strtoupper($_POST['matriculas'] ?? ''))));
            $no_encontradas = [];
            $st = $pdo->prepare("SELECT id_alumno FROM ALUMNOS WHERE UPPER(matricula) = ? OR REPLACE(UPPER(matricula), '/', '') = REPLACE(?, '/', '')");
            foreach ($mats as $m) {
                $st->execute([$m, $m]);
                $id = (int)$st->fetchColumn();
                if ($id) $ids[] = $id; else $no_encontradas[] = $m;
            }
            if ($no_encontradas) { header("Location: $volver&error=" . urlencode('No se encontraron estas matrículas: ' . implode(', ', array_slice($no_encontradas, 0, 10)) . '.') . '#mensaje'); exit(); }
            $destino_txt = 'de ' . (count($ids) === 1 ? bitacora_quien($pdo, 'alumnos', $ids[0]) : count($ids) . ' alumnos');
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) { header("Location: $volver&error=" . urlencode('Indica al menos un alumno o elige un grupo con alumnos.') . '#mensaje'); exit(); }
        $ref = $tipo_msg . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $cuenta = ['enviado' => 0, 'pendiente' => 0, 'sin_correo' => 0, 'fallido' => 0, 'duplicado' => 0, 'error' => 0];
        foreach ($ids as $id) { $r = aviso_tutor_mensaje($pdo, $id, $tipo_msg, $asunto, $mensaje, $cita, $ref); $cuenta[$r] = ($cuenta[$r] ?? 0) + 1; }
        bitacora($pdo, 'avisos', $tipo_msg, ($tipo_msg === 'citatorio' ? 'Envió citatorio' : 'Envió aviso general') . ' a los tutores ' . $destino_txt
                 . ($asunto !== '' ? ' ("' . $asunto . '")' : '') . ": {$cuenta['enviado']} enviados, {$cuenta['pendiente']} pendientes, {$cuenta['sin_correo']} sin correo");
        $msg = ($tipo_msg === 'citatorio' ? 'Citatorio' : 'Aviso') . " generado para " . count($ids) . " alumno(s): {$cuenta['enviado']} enviados"
             . ($cuenta['pendiente'] ? ", {$cuenta['pendiente']} pendientes" : '') . ($cuenta['sin_correo'] ? ", {$cuenta['sin_correo']} sin correo del tutor" : '') . ($cuenta['fallido'] ? ", {$cuenta['fallido']} fallidos" : '') . '.';
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
$faltas = $conteo_periodo();
$faltas = array_filter($faltas, fn($f) => $f['faltas'] >= $umbral);
$lista = [];
if ($faltas) {
    $ids = implode(',', array_map('intval', array_keys($faltas)));
    $ref = "$motivo-$desde-$hasta_calc";
    $st = $pdo->prepare("SELECT a.id_alumno, a.matricula, CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS alumno,
                                t.nombre_tutor, t.correo_tutor,
                                IF(g.id_grupo IS NULL, '—', CONCAT(g.semestre, '° ', g.nombre_grupo, ' · ', g.turno)) AS grupo,
                                (SELECT estatus FROM AVISOS_TUTOR av WHERE av.id_alumno = a.id_alumno AND av.tipo = ? AND av.referencia = ?) AS aviso_periodo
                         FROM ALUMNOS a LEFT JOIN TUTOR_ALUMNO t ON t.id_alumno = a.id_alumno
                         LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                         LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                         WHERE a.id_alumno IN ($ids) GROUP BY a.id_alumno ORDER BY g.semestre, g.turno, g.nombre_grupo, a.apellido_paterno, a.nombre");
    $st->execute([$motivo, $ref]);
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
$tipos_aviso = ['conducta' => ['fa-file-lines', '#b45309', 'Conducta'], 'faltas' => ['fa-user-clock', '#b91c1c', 'Faltas'], 'retardos' => ['fa-clock', '#c2410c', 'Retardos'],
                'citatorio' => ['fa-handshake', '#1d4ed8', 'Citatorio'], 'general' => ['fa-bullhorn', '#0f766e', 'Aviso general'],
                'clases' => ['fa-person-walking-arrow-right', '#9333ea', 'Faltó a clases']];
$chips = ['enviado' => ['Enviado', 'ok'], 'pendiente' => ['Pendiente', 'pend'], 'fallido' => ['Falló', 'err'], 'sin_correo' => ['Sin correo', 'warn']];
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
    <title>Avisos a tutores · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css?v=3">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
    <link rel="stylesheet" href="css/avisos_tutores.css?v=1">
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
                    <p>Correos al padre, madre o tutor: faltas, retardos, reportes de conducta, citatorios y avisos generales.</p>
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
            <h2><i class="fas fa-user-clock"></i> Avisos por faltas o retardos</h2>
            <p class="sub">Alumnos con <b><?php echo $umbral; ?> o más</b> <?php echo $palabra; ?> sin justificar del <?php echo date('d/m/Y', strtotime($desde)); ?> al <?php echo date('d/m/Y', strtotime($hasta_calc)); ?>. Revisa la lista y elige a quién avisar.</p>
            <form method="GET" class="at-filtros" id="formFiltros">
                <div class="at-motivo">
                    <label class="<?php echo $motivo === 'faltas' ? 'activo' : ''; ?>"><input type="radio" name="motivo" value="faltas" <?php echo $motivo === 'faltas' ? 'checked' : ''; ?>><i class="fas fa-user-xmark"></i> Faltas</label>
                    <label class="<?php echo $motivo === 'retardos' ? 'activo' : ''; ?>"><input type="radio" name="motivo" value="retardos" <?php echo $motivo === 'retardos' ? 'checked' : ''; ?>><i class="fas fa-clock"></i> Retardos</label>
                </div>
                <div><label for="fPeriodo">Periodo</label>
                    <select id="fPeriodo" name="periodo">
                        <option value="semana" <?php echo $periodo === 'semana' ? 'selected' : ''; ?>>Esta semana</option>
                        <option value="semana_anterior" <?php echo $periodo === 'semana_anterior' ? 'selected' : ''; ?>>Semana anterior</option>
                        <option value="mes" <?php echo $periodo === 'mes' ? 'selected' : ''; ?>>Este mes</option>
                        <option value="personalizado" <?php echo $periodo === 'personalizado' ? 'selected' : ''; ?>>Otras fechas</option>
                    </select></div>
                <div><label for="fUmbral"><?php echo ucfirst($palabra); ?> mínimas</label><input type="number" id="fUmbral" name="umbral" min="1" max="30" value="<?php echo $umbral; ?>"></div>
                <div><label for="fGrupo">Grupo</label>
                    <select id="fGrupo" name="id_grupo"><option value="0">Todos</option>
                        <?php foreach ($grupos as $g): ?><option value="<?php echo (int)$g['id_grupo']; ?>" <?php echo $id_grupo === (int)$g['id_grupo'] ? 'selected' : ''; ?>><?php echo $h($g['semestre'] . '° ' . $g['nombre_grupo'] . ' · ' . $g['turno']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="at-acc"><button type="submit" class="at-btn sec" style="width:100%"><i class="fas fa-magnifying-glass"></i> Buscar</button></div>
                <div class="at-fechas <?php echo $periodo === 'personalizado' ? 'visible' : ''; ?>" id="fFechas">
                    <div><label>Desde</label><input type="date" name="desde" value="<?php echo $h($desde); ?>" max="<?php echo $hoy; ?>"></div>
                    <div><label>Hasta</label><input type="date" name="hasta" value="<?php echo $h($hasta); ?>" max="<?php echo $hoy; ?>"></div>
                </div>
                <label class="at-check" <?php echo $motivo === 'retardos' ? 'hidden' : ''; ?>><input type="hidden" name="sin_registro" value="0"><input type="checkbox" name="sin_registro" value="1" <?php echo $sin_registro ? 'checked' : ''; ?>> Contar como falta los días sin registro de entrada (igual que el concentrado)</label>
            </form>

            <?php if (!$lista): ?>
                <div class="at-vacio"><i class="fas fa-face-smile" style="font-size:1.8rem; display:block; margin-bottom:8px;"></i>Ningún alumno llega a <?php echo $umbral; ?> <?php echo $palabra; ?> en este periodo.</div>
            <?php else: ?>
                <form method="POST" action="<?php echo $h($volver); ?>" onsubmit="return confirmarEnvio(this);">
                    <input type="hidden" name="accion" value="enviar_faltas">
                    <div class="at-tabla-wrap">
                        <table class="at-tabla">
                            <thead><tr><th style="width:34px"><input type="checkbox" id="todos" aria-label="Seleccionar todos"></th><th>Alumno</th><th>Grupo</th><th><?php echo ucfirst($palabra); ?></th><th>Padre, madre o tutor</th><th>Aviso de este periodo</th></tr></thead>
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

        <section class="at-card" id="mensaje">
            <h2><i class="fas fa-handshake"></i> Citatorio o aviso a padres</h2>
            <p class="sub">Para cualquier otro asunto: citar al tutor (por ejemplo, a una reunión por bajo rendimiento) o enviar un aviso general a uno o varios alumnos, o a todo un grupo.</p>
            <form method="POST" action="<?php echo $h($volver); ?>" onsubmit="return confirmarMensaje(this);" class="at-msg">
                <input type="hidden" name="accion" value="enviar_mensaje">
                <div class="at-motivo ancho">
                    <label class="activo"><input type="radio" name="tipo_msg" value="citatorio" checked><i class="fas fa-handshake"></i> Citatorio (cita en el plantel)</label>
                    <label><input type="radio" name="tipo_msg" value="general"><i class="fas fa-bullhorn"></i> Aviso general</label>
                </div>
                <div class="at-campo">
                    <label>Para</label>
                    <select name="destino" id="mDestino">
                        <option value="alumnos">Uno o varios alumnos (por matrícula)</option>
                        <option value="grupo">Todo un grupo</option>
                    </select>
                </div>
                <div class="at-campo" id="mMatriculas">
                    <label for="mMat">Matrícula(s)</label>
                    <input type="text" name="matriculas" id="mMat" placeholder="27/2026B0001, 27/2026B0002…">
                </div>
                <div class="at-campo" id="mGrupo" hidden>
                    <label for="mGrupoSel">Grupo</label>
                    <select name="id_grupo_msg" id="mGrupoSel">
                        <?php foreach ($grupos as $g): ?><option value="<?php echo (int)$g['id_grupo']; ?>"><?php echo $h($g['semestre'] . '° ' . $g['nombre_grupo'] . ' · ' . $g['turno']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="at-campo">
                    <label for="mAsunto">Asunto (opcional)</label>
                    <input type="text" name="asunto" id="mAsunto" maxlength="90" placeholder="Ej. Reunión por bajo rendimiento">
                </div>
                <div class="at-cita">
                    <div class="at-campo"><label for="mFecha">Fecha de la cita</label><input type="date" name="fecha_cita" id="mFecha" min="<?php echo $hoy; ?>" value="<?php echo date('Y-m-d', strtotime('+1 weekday')); ?>"></div>
                    <div class="at-campo"><label for="mHora">Hora</label><input type="time" name="hora_cita" id="mHora" value="09:00"></div>
                    <div class="at-campo"><label for="mLugar">Lugar</label><input type="text" name="lugar_cita" id="mLugar" maxlength="120" value="Prefectura del plantel"></div>
                </div>
                <div class="at-campo ancho">
                    <label for="mMensaje" id="mMensajeLbl">Motivo de la cita</label>
                    <textarea name="mensaje" id="mMensaje" rows="4" maxlength="2000" required placeholder="Escribe el motivo o el mensaje para el padre, madre o tutor…"></textarea>
                </div>
                <div class="at-pie ancho" style="margin-top:4px">
                    <span><i class="fas fa-circle-info"></i> Los alumnos sin correo del tutor quedan registrados como "Sin correo".</span>
                    <button type="submit" class="at-btn" id="btnMensaje"><i class="fas fa-paper-plane"></i> <?php echo correo_activo() ? 'Enviar' : 'Guardar (pendiente)'; ?></button>
                </div>
            </form>
        </section>

        <section class="at-card" id="historial">
            <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start;">
                <div><h2><i class="fas fa-paper-plane"></i> Historial de avisos</h2><p class="sub" style="margin:0 0 14px; color:#64748b; font-size:.86rem;">Todos los avisos: faltas, retardos, citatorios, avisos generales y reportes de conducta (estos se generan solos al levantar el reporte).</p></div>
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
                                <td><?php [$ti, $tc, $tt] = $tipos_aviso[$a['tipo']] ?? ['fa-envelope', '#475569', $a['tipo']]; echo '<i class="fas ' . $ti . '" style="color:' . $tc . '"></i> ' . $tt; ?></td>
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
        // Solo los alumnos visibles (si se filtró con el buscador, los ocultos no se marcan)
        if (todos) todos.addEventListener('change', () => document.querySelectorAll('.sel:not(:disabled)').forEach(c => { const tr = c.closest('tr'); if (!tr || tr.style.display !== 'none') c.checked = todos.checked; }));
        document.querySelectorAll('.at-motivo input').forEach(r => r.addEventListener('change', () => {
            r.closest('.at-motivo').querySelectorAll('label').forEach(l => l.classList.toggle('activo', l.querySelector('input').checked));
            if (r.name === 'motivo') document.getElementById('formFiltros').submit();
            if (r.name === 'tipo_msg') {
                const cit = r.value === 'citatorio';
                document.querySelector('.at-cita').hidden = !cit;
                document.getElementById('mMensajeLbl').textContent = cit ? 'Motivo de la cita' : 'Mensaje';
            }
        }));
        document.getElementById('mDestino').addEventListener('change', function () {
            document.getElementById('mMatriculas').hidden = this.value !== 'alumnos';
            document.getElementById('mGrupo').hidden = this.value !== 'grupo';
        });
        function confirmarMensaje(form) {
            if (form.dataset.ok) return true;
            const grupo = form.destino.value === 'grupo';
            if (!grupo && !form.matriculas.value.trim()) { (window.sigeAlert || alert)('Escribe al menos una matrícula.', 'aviso'); return false; }
            const quien = grupo ? 'todos los alumnos del grupo ' + form.id_grupo_msg.selectedOptions[0].textContent : 'los alumnos indicados';
            const tipo = form.tipo_msg.value === 'citatorio' ? 'el citatorio' : 'el aviso';
            const msg = 'Se enviará ' + tipo + ' a los tutores de ' + quien + '.' + <?php echo json_encode(correo_activo() ? '' : ' Quedará pendiente hasta que se configure el correo del plantel.'); ?>;
            const seguir = () => { form.dataset.ok = '1'; document.getElementById('btnMensaje').disabled = true; form.submit(); };
            if (window.sigeConfirm) { window.sigeConfirm(msg, { titulo: 'Avisos a tutores', aceptar: 'Enviar' }).then(ok => { if (ok) seguir(); }); return false; }
            return confirm(msg);
        }
        function confirmarEnvio(form) {
            if (form.dataset.ok) return true;
            const n = form.querySelectorAll('.sel:checked').length;
            if (!n) { (window.sigeAlert || alert)('Selecciona al menos un alumno.', 'aviso'); return false; }
            const msg = 'Se generará el aviso de <?php echo $palabra; ?> para ' + n + ' alumno(s).' + <?php echo json_encode(correo_activo() ? ' Se enviará por correo a sus tutores.' : ' Quedarán pendientes hasta que se configure el correo del plantel.'); ?>;
            const seguir = () => { form.dataset.ok = '1'; document.getElementById('btnEnviar').disabled = true; form.submit(); };
            if (window.sigeConfirm) { window.sigeConfirm(msg, { titulo: 'Avisos a tutores', aceptar: 'Continuar' }).then(ok => { if (ok) seguir(); }); return false; }
            return confirm(msg);
        }
    </script>
    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
