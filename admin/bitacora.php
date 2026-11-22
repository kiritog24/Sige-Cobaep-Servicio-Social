<?php
/**
 * admin/bitacora.php — Consulta de la bitácora: quién hizo qué y cuándo.
 * Solo lectura (nadie puede borrar ni editar registros desde el sistema). Filtros y descarga a Excel.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

$valida = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f) && strtotime($f);
$desde  = $valida($_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-d', strtotime('-30 days'));
$hasta  = $valida($_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
if ($hasta < $desde) [$desde, $hasta] = [$hasta, $desde];
$modulo = array_key_exists($_GET['modulo'] ?? '', BITACORA_MODULOS) ? $_GET['modulo'] : '';
$roles  = ['administrativo' => 'Administración', 'prefectos' => 'Prefectura', 'docente' => 'Docente', 'estudiante' => 'Alumno', 'sistema' => 'Sin sesión'];
$rol    = array_key_exists($_GET['rol'] ?? '', $roles) ? $_GET['rol'] : '';
$buscar = trim(mb_substr($_GET['q'] ?? '', 0, 80));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$por_pagina = 50;

$where = ["b.fecha >= ?", "b.fecha < DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$desde, $hasta];
if ($modulo !== '') { $where[] = "b.modulo = ?"; $params[] = $modulo; }
if ($rol !== '')    { $where[] = "b.rol = ?"; $params[] = $rol; }
if ($buscar !== '') {
    $where[] = "(b.descripcion LIKE ? OR b.usuario LIKE ? OR b.nombre_usuario LIKE ? OR b.detalle LIKE ?)";
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $buscar) . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

// ---------- Excel ----------
if (($_GET['formato'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/xlsx_simple.php';
    $st = $pdo->prepare("SELECT b.* FROM BITACORA b $sql_where ORDER BY b.fecha DESC, b.id_bitacora DESC LIMIT 20000");
    $st->execute($params);
    $filas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $filas[] = [date('d/m/Y H:i:s', strtotime($r['fecha'])), $roles[$r['rol']] ?? $r['rol'], trim(($r['usuario'] ?? '') . ' · ' . ($r['nombre_usuario'] ?? ''), ' ·'),
                    BITACORA_MODULOS[$r['modulo']] ?? $r['modulo'], $r['descripcion'], $r['ip']];
    }
    xlsx_descargar('Bitacora_' . $desde . '_a_' . $hasta . '.xlsx', 'Bitácora',
        ['COBAEP Plantel 27 · Bitácora del sistema', 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)) . ($modulo ? ' · ' . BITACORA_MODULOS[$modulo] : '')],
        ['Fecha y hora', 'Rol', 'Usuario', 'Módulo', 'Acción realizada', 'IP'], $filas, [20, 16, 34, 22, 80, 16]);
}

$st = $pdo->prepare("SELECT COUNT(*) FROM BITACORA b $sql_where");
$st->execute($params);
$total = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($total / $por_pagina));
$pagina = min($pagina, $paginas);
$st = $pdo->prepare("SELECT b.* FROM BITACORA b $sql_where ORDER BY b.fecha DESC, b.id_bitacora DESC LIMIT $por_pagina OFFSET " . (($pagina - 1) * $por_pagina));
$st->execute($params);
$registros = $st->fetchAll(PDO::FETCH_ASSOC);

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$qs = fn(array $cambios) => '?' . http_build_query(array_merge(['desde' => $desde, 'hasta' => $hasta, 'modulo' => $modulo, 'rol' => $rol, 'q' => $buscar], $cambios));
$iconos = ['sesion' => 'fa-right-to-bracket', 'usuarios' => 'fa-user-pen', 'password' => 'fa-key', 'grupos' => 'fa-users-rectangle', 'docentes' => 'fa-chalkboard-user',
           'horarios' => 'fa-calendar-days', 'asistencia' => 'fa-clipboard-check', 'conducta' => 'fa-file-lines', 'correos' => 'fa-envelope-open-text', 'avisos' => 'fa-paper-plane', 'perfil' => 'fa-id-card', 'sistema' => 'fa-box-archive'];
$colores_accion = ['eliminacion' => 'rojo', 'fallido' => 'rojo', 'baja_grupo' => 'rojo', 'alta' => 'verde', 'importacion' => 'verde', 'inscripcion' => 'verde', 'restablecer' => 'ambar', 'credenciales' => 'ambar', 'cambio_grupo' => 'azul'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Bitácora · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .bt-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .bt-title { display: flex; align-items: center; gap: 14px; }
        .bt-title .ico { width: 52px; height: 52px; border-radius: 14px; background: #ede9fe; color: #6d28d9; display: grid; place-items: center; font-size: 1.35rem; flex-shrink: 0; }
        .bt-title h1 { margin: 0; font-size: 1.55rem; color: #0f172a; }
        .bt-title p { margin: 3px 0 0; color: #64748b; font-size: .9rem; }
        .bt-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 18px; box-shadow: 0 4px 18px rgba(15, 23, 42, .04); margin-bottom: 18px; }
        .bt-filtros { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 10px; align-items: end; }
        .bt-filtros label { display: block; font-size: .74rem; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .03em; }
        .bt-filtros input, .bt-filtros select { width: 100%; box-sizing: border-box; padding: 10px 11px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: .88rem; background: #fff; }
        .bt-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 15px; border-radius: 10px; border: none; background: #16a05d; color: #fff; font-weight: 700; font-family: inherit; font-size: .86rem; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .bt-btn.sec { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
        .bt-resumen { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; font-size: .88rem; color: #475569; }
        .bt-lista { list-style: none; margin: 0; padding: 0 6px 0 0; max-height: min(70vh, 720px); overflow-y: auto; overscroll-behavior: contain; }
        @media print { .bt-lista { max-height: none; overflow: visible; } }
        .bt-item { display: grid; grid-template-columns: 44px 1fr auto; gap: 12px; padding: 13px 4px; border-bottom: 1px solid #f1f5f9; align-items: start; }
        .bt-item:last-child { border-bottom: none; }
        .bt-ico { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; font-size: .95rem; background: #f1f5f9; color: #475569; }
        .bt-ico.verde { background: #ecfdf5; color: #047857; } .bt-ico.rojo { background: #fef2f2; color: #b91c1c; }
        .bt-ico.ambar { background: #fffbeb; color: #b45309; } .bt-ico.azul { background: #eff6ff; color: #1d4ed8; }
        .bt-desc { font-size: .92rem; color: #0f172a; font-weight: 600; line-height: 1.4; overflow-wrap: anywhere; }
        .bt-meta { font-size: .78rem; color: #64748b; margin-top: 3px; display: flex; flex-wrap: wrap; gap: 4px 12px; }
        .bt-meta b { color: #334155; font-weight: 600; }
        .bt-chip { display: inline-block; padding: 2px 8px; border-radius: 20px; background: #f1f5f9; font-size: .7rem; font-weight: 700; color: #475569; }
        .bt-fecha { text-align: right; font-size: .8rem; color: #475569; white-space: nowrap; }
        .bt-fecha small { display: block; color: #94a3b8; }
        .bt-detalle { margin-top: 6px; }
        .bt-detalle summary { cursor: pointer; font-size: .78rem; color: #0f766e; font-weight: 600; }
        .bt-detalle ul { margin: 6px 0 0; padding-left: 18px; font-size: .8rem; color: #475569; }
        .bt-vacio { text-align: center; padding: 50px 10px; color: #94a3b8; }
        .bt-pag { display: flex; justify-content: center; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
        .bt-pag a, .bt-pag span { padding: 7px 12px; border-radius: 9px; border: 1px solid #e2e8f0; text-decoration: none; color: #334155; font-size: .85rem; font-weight: 600; }
        .bt-pag span.actual { background: #16a05d; color: #fff; border-color: #16a05d; }
        @media (max-width: 1100px) { .bt-filtros { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 420px) { .bt-filtros { grid-template-columns: minmax(0, 1fr) !important; } .bt-filtros input, .bt-filtros select { min-width: 0; } }
        @media (max-width: 640px) {
            .bt-filtros { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
            .bt-filtros .bt-buscar, .bt-filtros .bt-acc { grid-column: 1 / -1; }
            .bt-item { grid-template-columns: 36px 1fr; }
            .bt-ico { width: 34px; height: 34px; }
            .bt-fecha { grid-column: 2; text-align: left; }
            .bt-fecha small { display: inline; margin-left: 6px; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="bt-head">
            <div class="bt-title">
                <div class="ico"><i class="fas fa-clock-rotate-left"></i></div>
                <div>
                    <h1>Bitácora</h1>
                    <p>Quién hizo qué y cuándo en el sistema. Los registros no se pueden modificar.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </div>

        <form class="bt-card bt-filtros" method="GET">
            <div><label for="fDesde">Desde</label><input type="date" id="fDesde" name="desde" value="<?php echo $h($desde); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
            <div><label for="fHasta">Hasta</label><input type="date" id="fHasta" name="hasta" value="<?php echo $h($hasta); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
            <div><label for="fModulo">Módulo</label>
                <select id="fModulo" name="modulo"><option value="">Todos</option>
                    <?php foreach (BITACORA_MODULOS as $k => $t): ?><option value="<?php echo $k; ?>" <?php echo $modulo === $k ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
                </select></div>
            <div><label for="fRol">Quién</label>
                <select id="fRol" name="rol"><option value="">Todos</option>
                    <?php foreach ($roles as $k => $t): ?><option value="<?php echo $k; ?>" <?php echo $rol === $k ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
                </select></div>
            <div class="bt-buscar"><label for="fQ">Buscar</label><input type="search" id="fQ" name="q" value="<?php echo $h($buscar); ?>" placeholder="Matrícula, usuario, nombre…"></div>
            <div class="bt-acc" style="display:flex; gap:8px;">
                <button type="submit" class="bt-btn"><i class="fas fa-filter"></i> Filtrar</button>
                <a class="bt-btn sec" href="bitacora.php" title="Quitar filtros"><i class="fas fa-rotate-left"></i></a>
            </div>
        </form>

        <section class="bt-card">
            <div class="bt-resumen">
                <span><b><?php echo number_format($total); ?></b> movimiento(s) del <?php echo date('d/m/Y', strtotime($desde)); ?> al <?php echo date('d/m/Y', strtotime($hasta)); ?></span>
                <?php if ($total): ?><a class="bt-btn sec" href="<?php echo $h($qs(['formato' => 'xlsx'])); ?>"><i class="fas fa-file-excel"></i> Descargar Excel</a><?php endif; ?>
            </div>
            <?php if (!$registros): ?>
                <div class="bt-vacio"><i class="fas fa-clock-rotate-left" style="font-size:2rem; display:block; margin-bottom:10px;"></i>No hay movimientos con esos filtros.</div>
            <?php else: ?>
                <ul class="bt-lista">
                    <?php foreach ($registros as $r):
                        $detalle = $r['detalle'] ? json_decode($r['detalle'], true) : null; ?>
                        <li class="bt-item">
                            <div class="bt-ico <?php echo $colores_accion[$r['accion']] ?? ''; ?>"><i class="fas <?php echo $iconos[$r['modulo']] ?? 'fa-circle-info'; ?>"></i></div>
                            <div>
                                <div class="bt-desc"><?php echo $h($r['descripcion']); ?></div>
                                <div class="bt-meta">
                                    <span><i class="fas fa-user"></i> <b><?php echo $h(trim(($r['nombre_usuario'] ?: 'Sin sesión'))); ?></b><?php echo $r['usuario'] ? ' (' . $h($r['usuario']) . ')' : ''; ?></span>
                                    <span class="bt-chip"><?php echo $h($roles[$r['rol']] ?? $r['rol']); ?></span>
                                    <span class="bt-chip"><?php echo $h(BITACORA_MODULOS[$r['modulo']] ?? $r['modulo']); ?></span>
                                    <?php if ($r['ip']): ?><span><i class="fas fa-network-wired"></i> <?php echo $h($r['ip']); ?></span><?php endif; ?>
                                </div>
                                <?php if (is_array($detalle) && $detalle): ?>
                                    <details class="bt-detalle"><summary>Ver detalle</summary><ul>
                                        <?php foreach ($detalle as $k => $v): ?>
                                            <?php if (is_array($v)): ?>
                                                <li><b><?php echo $h(ucfirst($k)); ?>:</b> <?php echo $v ? $h(implode(', ', array_map('strval', array_slice($v, 0, 60)))) . (count($v) > 60 ? ' … y ' . (count($v) - 60) . ' más' : '') : '—'; ?></li>
                                            <?php else: ?>
                                                <li><b><?php echo $h(ucfirst($k)); ?>:</b> <?php echo $h($v); ?></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul></details>
                                <?php endif; ?>
                            </div>
                            <div class="bt-fecha"><?php echo date('d/m/Y', strtotime($r['fecha'])); ?><small><?php echo date('h:i:s a', strtotime($r['fecha'])); ?></small></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($paginas > 1): ?>
                    <nav class="bt-pag" aria-label="Páginas">
                        <?php if ($pagina > 1): ?><a href="<?php echo $h($qs(['p' => $pagina - 1])); ?>">‹ Anterior</a><?php endif; ?>
                        <?php for ($i = max(1, $pagina - 2); $i <= min($paginas, $pagina + 2); $i++): ?>
                            <?php if ($i === $pagina): ?><span class="actual"><?php echo $i; ?></span><?php else: ?><a href="<?php echo $h($qs(['p' => $i])); ?>"><?php echo $i; ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagina < $paginas): ?><a href="<?php echo $h($qs(['p' => $pagina + 1])); ?>">Siguiente ›</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
