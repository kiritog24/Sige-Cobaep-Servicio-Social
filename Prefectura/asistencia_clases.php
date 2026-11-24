<?php
/**
 * Prefectura/asistencia_clases.php — Cruce de la entrada al plantel con el pase de lista de cada docente.
 *
 *  1. Posibles pintas: entraron al plantel pero el docente les puso falta en una clase.
 *  2. Salieron del plantel antes de su clase (informativo, no es pinta).
 *  3. Estuvieron en clase sin registrar su entrada en Prefectura (para corregir el registro).
 *  4. Qué materias ya pasaron lista y cuáles no.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}

$hoy = date('Y-m-d');
$fecha = $_GET['fecha'] ?? $hoy;
if (!fecha_valida($fecha) || $fecha > $hoy) $fecha = $hoy;
$id_grupo = (int)($_GET['id_grupo'] ?? 0);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$sin_clases = dia_sin_clases($pdo, $fecha);
$pintas_todas = posibles_pintas($pdo, $fecha, $id_grupo);
$pintas = array_filter($pintas_todas, fn($p) => $p['pinta']);
$salieron = array_filter($pintas_todas, fn($p) => !$p['pinta']);
$sin_entrada = presentes_sin_entrada($pdo, $fecha, $id_grupo);
$pases = pases_de_lista_del_dia($pdo, $fecha, $id_grupo);
$pendientes = array_filter($pases, fn($c) => (int)$c['marcados'] === 0);
$grupos = $pdo->query("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE estatus = 'Activo' ORDER BY semestre, nombre_grupo")->fetchAll(PDO::FETCH_ASSOC);

// Avisos ya generados ese día (para no duplicar)
$avisados = [];
if ($pintas) {
    $st = $pdo->prepare("SELECT id_alumno FROM AVISOS_TUTOR WHERE tipo = 'clases' AND referencia = ?");
    $st->execute(['clases-' . $fecha]);
    $avisados = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
}
$es_hoy = $fecha === $hoy;
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
    <title>Asistencia a Clases · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">
    <link rel="stylesheet" href="css/asistencia_clases.css?v=2">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <div>
                    <span class="eyebrow">PREFECTURA · ASISTENCIA</span>
                    <h1>Asistencia a Clases</h1>
                    <p>Cruce de la entrada al plantel con el pase de lista de cada docente.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <?php if (isset($_GET['exito'])): ?><div class="ac-msg ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="ac-msg err"><i class="fas fa-circle-exclamation"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

        <form class="ac-filtros" method="GET">
            <label>Fecha<input type="date" name="fecha" value="<?php echo $h($fecha); ?>" max="<?php echo $hoy; ?>"></label>
            <label>Grupo
                <select name="id_grupo">
                    <option value="0">Todos los grupos</option>
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?php echo (int)$g['id_grupo']; ?>" <?php echo $id_grupo === (int)$g['id_grupo'] ? 'selected' : ''; ?>><?php echo $h($g['semestre'] . '° "' . $g['nombre_grupo'] . '" · ' . $g['turno']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit"><i class="fas fa-magnifying-glass"></i> Ver</button>
        </form>

        <?php if ($sin_clases): ?>
            <div class="ac-banner"><i class="fas fa-school-circle-xmark"></i> El <?php echo date('d/m/Y', strtotime($fecha)); ?> no hubo clases (<?php echo $h($sin_clases); ?>).</div>
        <?php endif; ?>

        <section class="ac-resumen" style="margin-top:18px;">
            <div class="ac-num rojo"><i class="fas fa-person-walking-arrow-right"></i><div><strong><?php echo count($pintas); ?></strong><span>Posibles pintas</span></div></div>
            <div class="ac-num azul"><i class="fas fa-id-card"></i><div><strong><?php echo count($sin_entrada); ?></strong><span>En clase sin registrar entrada</span></div></div>
            <div class="ac-num ambar"><i class="fas fa-list-check"></i><div><strong><?php echo count($pendientes); ?></strong><span>Materias sin pase de lista</span></div></div>
            <div class="ac-num gris"><i class="fas fa-door-open"></i><div><strong><?php echo count($salieron); ?></strong><span>Salieron antes de su clase</span></div></div>
        </section>

        <!-- 1. Posibles pintas -->
        <section class="ac-card">
            <h2><i class="fas fa-person-walking-arrow-right" style="color:#dc2626;"></i> Posibles pintas</h2>
            <p>Registraron su entrada al plantel, pero el docente les puso falta en su clase y no tienen salida registrada antes de esa hora.</p>
            <?php if (!$pintas): ?>
                <div class="ac-vacio"><i class="fas fa-circle-check" style="color:#10b981;"></i> Sin posibles pintas<?php echo $es_hoy ? ' por ahora' : ''; ?>. Se actualiza conforme los docentes pasan lista.</div>
            <?php else: foreach ($pintas as $p): ?>
                <div class="ac-alumno">
                    <div class="quien">
                        <strong><?php echo $h($p['alumno']); ?></strong>
                        <small><?php echo $h($p['matricula'] . ' · ' . $p['grupo']); ?></small>
                        <div>
                            <span class="ac-chip verde"><i class="fas fa-door-open"></i> Entró <?php echo $h($p['entrada']); ?></span>
                            <?php if ($p['salida']): ?><span class="ac-chip gris"><i class="fas fa-door-closed"></i> Salió <?php echo $h($p['salida']); ?></span><?php endif; ?>
                            <?php foreach ($p['clases'] as $c): if ($c['salio_antes']) continue; ?>
                                <span class="ac-chip rojo"><i class="fas fa-xmark"></i> <?php echo $h($c['materia'] . ($c['hora'] ? ' · ' . $c['hora'] : '') . ($c['docente'] ? ' · ' . $c['docente'] : '')); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="ac-acciones">
                        <a class="ac-btn" href="perfil_alumno.php?id=<?php echo (int)$p['id_alumno']; ?>"><i class="fas fa-folder-open"></i> Expediente</a>
                        <a class="ac-btn" href="creacion_reportes.php?buscar=<?php echo urlencode($p['matricula']); ?>"><i class="fas fa-file-pen"></i> Reporte de conducta</a>
                        <?php if (isset($avisados[(int)$p['id_alumno']])): ?>
                            <button class="ac-btn morado" type="button" disabled><i class="fas fa-check"></i> Tutor avisado</button>
                        <?php else: ?>
                            <form method="POST" action="procesos/aviso_faltas_clases.php" style="margin:0;">
                                <input type="hidden" name="id_alumno" value="<?php echo (int)$p['id_alumno']; ?>">
                                <input type="hidden" name="fecha" value="<?php echo $h($fecha); ?>">
                                <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                                <button class="ac-btn morado" type="submit" data-confirm="¿Enviar aviso al padre, madre o tutor de <?php echo $h($p['alumno']); ?>?"><i class="fas fa-paper-plane"></i> Avisar al tutor</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <!-- 2. En clase sin registrar entrada -->
        <section class="ac-card">
            <h2><i class="fas fa-id-card" style="color:#2563eb;"></i> Estuvieron en clase sin registrar su entrada</h2>
            <p>El docente los marcó presentes, pero no pasaron su credencial en Prefectura (o llegaron después de la hora de corte). Revisa y corrige su registro.</p>
            <?php if (!$sin_entrada): ?>
                <div class="ac-vacio">Todos los alumnos presentes en clase registraron su entrada.</div>
            <?php else: foreach ($sin_entrada as $s): ?>
                <div class="ac-alumno">
                    <div class="quien">
                        <strong><?php echo $h($s['alumno']); ?></strong>
                        <small><?php echo $h($s['matricula'] . ($s['semestre'] ? ' · ' . $s['semestre'] . '° "' . $s['nombre_grupo'] . '"' : '')); ?></small>
                        <div><span class="ac-chip verde"><i class="fas fa-chalkboard-user"></i> Presente en: <?php echo $h($s['materias']); ?></span></div>
                    </div>
                    <div class="ac-acciones">
                        <a class="ac-btn" href="historial_asistencias.php?matricula=<?php echo urlencode($s['matricula']); ?>&amp;fecha=<?php echo $h($fecha); ?>"><i class="fas fa-clock-rotate-left"></i> Ver historial</a>
                        <?php if ($es_hoy && !$sin_clases): ?>
                            <form method="POST" action="procesos/registro_manual.php" style="margin:0;">
                                <input type="hidden" name="matricula" value="<?php echo $h($s['matricula']); ?>">
                                <input type="hidden" name="estado" value="asistencia">
                                <input type="hidden" name="tipo_movimiento" value="entrada">
                                <button class="ac-btn" type="submit" data-confirm="¿Registrar ahora la entrada de <?php echo $h($s['alumno']); ?>?"><i class="fas fa-keyboard"></i> Registrar entrada</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <!-- 3. Pase de lista por materia -->
        <section class="ac-card">
            <h2><i class="fas fa-list-check" style="color:#d97706;"></i> Pase de lista por materia</h2>
            <p>Materias con clase ese día según su horario, y si el docente ya pasó lista.</p>
            <?php if (!$pases): ?>
                <div class="ac-vacio">No hay materias con horario para ese día<?php echo $id_grupo ? ' en este grupo' : ''; ?>.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="ac-tabla no-tarjetas" data-sin-buscador>
                    <thead><tr><th>Hora</th><th>Materia</th><th>Grupo</th><th>Docente</th><th>Pase de lista</th></tr></thead>
                    <tbody>
                    <?php foreach ($pases as $c): $ok = (int)$c['marcados'] > 0; ?>
                        <tr>
                            <td><?php echo $c['inicio'] ? substr($c['inicio'], 0, 5) . '–' . substr($c['fin'], 0, 5) : '<span style="color:#94a3b8">Extra</span>'; ?></td>
                            <td><?php echo $h($c['nombre_materia']); ?></td>
                            <td><?php echo $c['semestre'] ? $h($c['semestre'] . '° "' . $c['nombre_grupo'] . '"') : '—'; ?></td>
                            <td><?php echo $h($c['docente'] ?: 'Sin docente'); ?></td>
                            <td class="ac-estado <?php echo $ok ? 'ok' : 'no'; ?>">
                                <?php if ($ok): ?><i class="fas fa-check"></i> <?php echo (int)$c['marcados']; ?>/<?php echo (int)$c['inscritos']; ?> marcados<?php echo (int)$c['faltas'] ? ' · ' . (int)$c['faltas'] . ' faltas' : ''; ?>
                                <?php else: ?><i class="fas fa-hourglass-half"></i> Pendiente<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- 4. Salieron antes -->
        <?php if ($salieron): ?>
        <section class="ac-card">
            <h2><i class="fas fa-door-open" style="color:#475569;"></i> Salieron del plantel antes de su clase</h2>
            <p>Tienen falta en clase, pero su salida quedó registrada antes de la hora de inicio. No se cuentan como pinta.</p>
            <?php foreach ($salieron as $p): ?>
                <div class="ac-alumno">
                    <div class="quien">
                        <strong><?php echo $h($p['alumno']); ?></strong>
                        <small><?php echo $h($p['matricula'] . ' · ' . $p['grupo']); ?></small>
                        <div>
                            <span class="ac-chip verde">Entró <?php echo $h($p['entrada']); ?></span>
                            <span class="ac-chip gris">Salió <?php echo $h($p['salida']); ?></span>
                            <?php foreach ($p['clases'] as $c): ?><span class="ac-chip gris"><?php echo $h($c['materia'] . ($c['hora'] ? ' · ' . $c['hora'] : '')); ?></span><?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </main>

    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
