<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/reportes_sugeridos.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$mes = sugeridos_mes($_GET['mes'] ?? '');
$datos = ['pendientes' => [], 'atendidos' => [], 'desde' => $mes . '-01', 'hasta_r' => $mes . '-01', 'hasta_f' => $mes . '-01'];
try { $datos = reportes_sugeridos($pdo, $mes); } catch (PDOException $e) { error_log('reportes_sugeridos.php: ' . $e->getMessage()); }
if ($mes === date('Y-m')) $_SESSION['sugeridos_cache'] = ['mes' => $mes, 't' => time(), 'n' => count($datos['pendientes'])];

$pend = $datos['pendientes'];
$n_ret = count(array_filter($pend, fn($p) => $p['tipo'] === 'retardo'));
$n_fal = count($pend) - $n_ret;
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mes_txt = $meses[(int)substr($mes, 5, 2) - 1] . ' de ' . substr($mes, 0, 4);
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
    <title>Reportes sugeridos · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css?v=3">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
    <link rel="stylesheet" href="css/reportes_sugeridos.css?v=1">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">CONDUCTA · PREFECTURA</span>
                    <h1>Reportes sugeridos</h1>
                    <p>Alumnos que juntaron <?php echo SUGERIDOS_RETARDOS; ?> retardos o <?php echo SUGERIDOS_FALTAS; ?> faltas sin justificar en el mes. Tú decides si se levanta el reporte.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <?php if (isset($_GET['exito'])): ?><div class="rs-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="rs-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

        <div class="rs-stats">
            <div class="s-total"><b><?php echo count($pend); ?></b><span>Por atender en <?php echo $h($mes_txt); ?></span></div>
            <div class="s-ret"><b><?php echo $n_ret; ?></b><span>Por <?php echo SUGERIDOS_RETARDOS; ?> retardos (Art. 1)</span></div>
            <div class="s-fal"><b><?php echo $n_fal; ?></b><span>Por <?php echo SUGERIDOS_FALTAS; ?> faltas (Art. 11)</span></div>
            <div><b><?php echo count($datos['atendidos']); ?></b><span>Ya atendidos este mes</span></div>
        </div>

        <section class="rs-card">
            <form method="GET" class="rs-filtros" id="rsFiltros">
                <label><span>Mes</span><input type="month" name="mes" value="<?php echo $h($mes); ?>" max="<?php echo date('Y-m'); ?>" onchange="this.form.submit()"></label>
                <label><span>Tipo</span>
                    <select id="rsTipo"><option value="">Retardos y faltas</option><option value="retardo">Solo retardos</option><option value="falta">Solo faltas</option></select>
                </label>
                <label class="rs-buscar"><span>Buscar</span><input type="search" id="rsBuscar" placeholder="Nombre, matrícula o grupo..." autocomplete="off"></label>
            </form>
            <p class="rs-nota"><i class="fas fa-circle-info"></i> Los retardos y faltas <b>justificados no cuentan</b>. Las faltas aparecen hasta <?php echo SUGERIDOS_ESPERA_FALTAS_DIAS; ?> días después, para dar tiempo a que traigan su justificante, y solo se cuentan días en que Prefectura registró entradas con el escáner. Cada bloque de <?php echo SUGERIDOS_RETARDOS; ?> se atiende una sola vez.</p>

            <?php if (!$pend): ?>
                <div class="rs-vacio"><i class="fas fa-circle-check"></i><b>No hay reportes por atender</b><span>Ningún alumno juntó <?php echo SUGERIDOS_RETARDOS; ?> retardos o <?php echo SUGERIDOS_FALTAS; ?> faltas sin justificar en <?php echo $h($mes_txt); ?>, o ya se atendieron todos.</span></div>
            <?php else: ?>
                <div class="rs-lista" id="rsLista">
                    <?php foreach ($pend as $p): $es_r = $p['tipo'] === 'retardo'; ?>
                        <article class="rs-item <?php echo $es_r ? 'i-ret' : 'i-fal'; ?>" data-tipo="<?php echo $p['tipo']; ?>" data-buscar="<?php echo $h(mb_strtolower($p['nombre'] . ' ' . $p['matricula'] . ' ' . $p['grupo'], 'UTF-8')); ?>">
                            <div class="rs-alumno">
                                <span class="rs-tipo"><i class="fas <?php echo $es_r ? 'fa-clock' : 'fa-user-xmark'; ?>"></i> <?php echo $es_r ? 'Retardos · Art. 1' : 'Faltas · Art. 11'; ?></span>
                                <strong><a href="perfil_alumno.php?id=<?php echo (int)$p['id_alumno']; ?>"><?php echo $h($p['nombre']); ?></a></strong>
                                <small><?php echo $h($p['matricula']); ?> · <?php echo $h($p['grupo']); ?> · <?php echo (int)$p['puntos_conducta']; ?> puntos</small>
                            </div>
                            <div class="rs-detalle">
                                <span class="rs-cuenta"><b><?php echo (int)$p['total']; ?></b> <?php echo $es_r ? 'retardos' : 'faltas'; ?> sin justificar en el mes<?php if ($p['por_atender'] > 1): ?> · <?php echo (int)$p['por_atender']; ?> reportes por atender<?php endif; ?></span>
                                <span class="rs-fechas"><?php foreach ($p['bloque_fechas'] as $f): ?><em><?php echo date('d/m', strtotime($f)); ?></em><?php endforeach; ?></span>
                            </div>
                            <div class="rs-acciones">
                                <form action="procesos/reporte_sugerido.php" method="POST" class="js-levantar" data-nombre="<?php echo $h($p['nombre']); ?>" data-art="<?php echo $es_r ? 1 : 11; ?>">
                                    <input type="hidden" name="accion" value="levantar">
                                    <input type="hidden" name="mes" value="<?php echo $h($mes); ?>">
                                    <input type="hidden" name="id_alumno" value="<?php echo (int)$p['id_alumno']; ?>">
                                    <input type="hidden" name="tipo" value="<?php echo $p['tipo']; ?>">
                                    <input type="hidden" name="bloque" value="<?php echo (int)$p['bloque']; ?>">
                                    <button type="submit" class="rs-btn levantar"><i class="fas fa-file-circle-plus"></i> Levantar reporte</button>
                                </form>
                                <button type="button" class="rs-btn descartar" onclick="abrirDescartar(<?php echo (int)$p['id_alumno']; ?>, '<?php echo $p['tipo']; ?>', <?php echo (int)$p['bloque']; ?>, <?php echo $h(json_encode($p['nombre'], JSON_UNESCAPED_UNICODE)); ?>)"><i class="fas fa-ban"></i> Descartar</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <p class="rs-vacio-filtro" id="rsVacio" hidden>Ningún alumno coincide con el filtro.</p>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($datos['atendidos']): ?>
        <details class="rs-card rs-hechos">
            <summary><i class="fas fa-clipboard-check"></i> Ya atendidos en <?php echo $h($mes_txt); ?> (<?php echo count($datos['atendidos']); ?>)</summary>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Fecha</th><th>Alumno</th><th>Tipo</th><th>Días</th><th>Decisión</th></tr></thead>
                    <tbody>
                        <?php foreach ($datos['atendidos'] as $a): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($a['fecha'])); ?></td>
                                <td><?php echo $h($a['nombre']); ?><br><small style="color:var(--muted);"><?php echo $h($a['matricula']); ?></small></td>
                                <td><?php echo $a['tipo'] === 'retardo' ? 'Retardos' : 'Faltas'; ?></td>
                                <td><?php echo $h(sugeridos_fechas_texto(array_filter(explode(',', (string)$a['fechas'])))); ?></td>
                                <td><?php if ($a['accion'] === 'reporte'): ?><span class="rs-dec rep">Reporte levantado</span><?php else: ?><span class="rs-dec desc">Descartado</span> <small><?php echo $h($a['motivo']); ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
    </main>

    <!-- Ventana para descartar -->
    <div class="rs-modal" id="modalDescartar" hidden>
        <form action="procesos/reporte_sugerido.php" method="POST" class="rs-modal-box">
            <h3><i class="fas fa-ban"></i> Descartar reporte sugerido</h3>
            <p>Alumno: <b id="descNombre"></b>. No se le descontarán puntos y ya no aparecerá en la lista por estos días.</p>
            <input type="hidden" name="accion" value="descartar">
            <input type="hidden" name="mes" value="<?php echo $h($mes); ?>">
            <input type="hidden" name="id_alumno" id="descAlumno">
            <input type="hidden" name="tipo" id="descTipo">
            <input type="hidden" name="bloque" id="descBloque">
            <label for="descMotivo">¿Por qué se descarta?</label>
            <select id="descMotivoSel">
                <option value="">-- Elige un motivo --</option>
                <option>Falló el escáner o no se registró su entrada</option>
                <option>Ya se habló con el alumno y su tutor</option>
                <option>Traerá justificante</option>
                <option value="otro">Otro (escríbelo)</option>
            </select>
            <textarea name="motivo" id="descMotivo" rows="2" maxlength="250" placeholder="Detalle del motivo..." required></textarea>
            <div class="rs-modal-acc">
                <button type="button" class="rs-btn gris" onclick="document.getElementById('modalDescartar').hidden = true">Cancelar</button>
                <button type="submit" class="rs-btn descartar-ok"><i class="fas fa-ban"></i> Descartar</button>
            </div>
        </form>
    </div>

    <script>
        function abrirDescartar(id, tipo, bloque, nombre) {
            document.getElementById('descAlumno').value = id;
            document.getElementById('descTipo').value = tipo;
            document.getElementById('descBloque').value = bloque;
            document.getElementById('descNombre').textContent = nombre;
            document.getElementById('descMotivoSel').value = '';
            document.getElementById('descMotivo').value = '';
            document.getElementById('modalDescartar').hidden = false;
        }
        document.getElementById('descMotivoSel').addEventListener('change', function () {
            const t = document.getElementById('descMotivo');
            if (this.value && this.value !== 'otro') t.value = this.value;
            else { t.value = ''; t.focus(); }
        });
        document.getElementById('modalDescartar').addEventListener('click', e => { if (e.target.id === 'modalDescartar') e.target.hidden = true; });

        // Confirmación antes de levantar el reporte
        document.querySelectorAll('.js-levantar').forEach(f => f.addEventListener('submit', async e => {
            if (f.dataset.ok) return;
            e.preventDefault();
            const msg = `¿Levantar reporte (Art. ${f.dataset.art}) a ${f.dataset.nombre}? Se le descontarán los puntos de la regla y se enviará aviso a su tutor.`;
            const ok = window.sigeConfirm ? await window.sigeConfirm(msg, { titulo: 'Levantar reporte', aceptar: 'Sí, levantar' }) : confirm(msg);
            if (ok) { f.dataset.ok = '1'; f.querySelector('button').disabled = true; f.submit(); }
        }));

        // Filtros en pantalla
        (function () {
            const tipo = document.getElementById('rsTipo'), buscar = document.getElementById('rsBuscar');
            const items = [...document.querySelectorAll('.rs-item')];
            const norm = t => String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            function filtrar() {
                const partes = norm(buscar.value).trim().split(/\s+/).filter(Boolean);
                let n = 0;
                items.forEach(it => {
                    const ok = (!tipo.value || it.dataset.tipo === tipo.value) && partes.every(p => norm(it.dataset.buscar).includes(p));
                    it.hidden = !ok; if (ok) n++;
                });
                const v = document.getElementById('rsVacio'); if (v) v.hidden = n > 0 || !items.length;
            }
            tipo.addEventListener('change', filtrar);
            buscar.addEventListener('input', filtrar);
            buscar.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
        })();
    </script>
    <script src="../js/dashboard_prefectura.js"></script>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
