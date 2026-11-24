<?php
require_once '../config/database.php';
require_once 'includes/layout.php';
require_once __DIR__ . '/../includes/ficha_alumno.php';

$alumno = estudiante_requerir_sesion($pdo);
$faltas = $sanciones = $turnos = $mis_reportes = [];
try {
    $faltas = $pdo->query("SELECT numero_reglamento, descripcion_falta, tipo_sancion, puntos_descuento, detalle_sancion, requiere_citacion_tutor, puede_ser_definitiva FROM TIPOS_FALTA ORDER BY numero_reglamento")->fetchAll(PDO::FETCH_ASSOC);
    $sanciones = $pdo->query("SELECT umbral_puntos_menos, accion, descripcion FROM SANCIONES_ACUMULATIVAS ORDER BY umbral_puntos_menos")->fetchAll(PDO::FETCH_ASSOC);
    $turnos = $pdo->query("SELECT turno, hora_entrada, fin_tolerancia, hora_corte FROM HORARIOS_TURNO ORDER BY hora_entrada")->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT r.fecha_hora, r.puntos_descontados, r.observaciones, tf.numero_reglamento, tf.descripcion_falta, " . SQL_EMISOR_REPORTE . " AS emisor FROM REPORTES r INNER JOIN TIPOS_FALTA tf ON tf.id_tipo_falta = r.id_tipo_falta WHERE r.id_alumno = ? ORDER BY r.fecha_hora DESC");
    $st->execute([$alumno['id_alumno']]);
    $mis_reportes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Reglamentos: ' . $e->getMessage());
}
$puntos = (int)$alumno['puntos_conducta'];
$color = $puntos >= 75 ? '#059669' : ($puntos >= 60 ? '#d97706' : '#dc2626');

estudiante_layout_inicio('Reglamento escolar', 'Normas de conducta, sanciones y horarios del plantel.', 'reglamentos.php', $alumno, 'css/reglamentos.css');
?>
<div class="info-card">
    <h2><i class="fas fa-shield-halved"></i> Mis puntos de conducta</h2>
    <p class="desc">Todos inician el semestre con 100 puntos. Cada falta al reglamento descuenta puntos.</p>
    <div class="pts-wrap">
        <div class="pts-num" style="color:<?php echo $color; ?>"><?php echo $puntos; ?><small style="font-size:1rem;color:#94a3b8;"> / 100</small></div>
        <div class="pts-bar"><div style="width:<?php echo max(0, min(100, $puntos)); ?>%;background:<?php echo $color; ?>;"></div></div>
    </div>
    <?php if (!empty($mis_reportes)): ?>
        <div class="info-wrap" style="margin-top:1rem;">
            <table class="info-table">
                <thead><tr><th>Fecha</th><th>Falta</th><th>Reportado por</th><th>Puntos</th></tr></thead>
                <tbody>
                <?php foreach ($mis_reportes as $r): ?>
                    <tr><td style="white-space:nowrap;"><?php echo date('d/m/Y', strtotime($r['fecha_hora'])); ?></td><td><strong>Art. <?php echo (int)$r['numero_reglamento']; ?></strong> · <?php echo htmlspecialchars($r['descripcion_falta']); ?><?php if (!empty($r['observaciones'])): ?><br><small style="color:#64748b;"><?php echo htmlspecialchars($r['observaciones']); ?></small><?php endif; ?></td><td><?php echo htmlspecialchars($r['emisor'] ?: 'No registrado'); ?></td><td>-<?php echo (int)$r['puntos_descontados']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="margin:1rem 0 0;color:#047857;font-size:.88rem;font-weight:600;"><i class="fas fa-circle-check"></i> No tienes reportes de conducta. ¡Sigue así!</p>
    <?php endif; ?>
</div>

<?php if (!empty($turnos)): ?>
<div class="info-card">
    <h2><i class="far fa-clock"></i> Horarios de entrada</h2>
    <p class="desc">Al escanear tu credencial: dentro de la tolerancia es puntual, después es retardo y pasada la hora de corte se registra falta.</p>
    <div class="turnos">
        <?php foreach ($turnos as $t): ?>
            <div class="turno">
                <strong>Turno <?php echo htmlspecialchars($t['turno']); ?></strong>
                <p><i class="fas fa-door-open" style="width:18px;color:#059669;"></i> Entrada: <?php echo date('h:i a', strtotime($t['hora_entrada'])); ?></p>
                <?php require_once __DIR__ . '/../includes/calendario.php'; $tr = tramos_entrada_turno($t); ?>
                <p><i class="fas fa-circle-check" style="width:18px;color:#059669;"></i> Puntual: <?php echo $tr['puntual']; ?> (<?php echo $tr['tolerancia_min']; ?> min de tolerancia)</p>
                <p><i class="fas fa-clock" style="width:18px;color:#d97706;"></i> Retardo: <?php echo $tr['retardo']; ?></p>
                <p><i class="fas fa-ban" style="width:18px;color:#dc2626;"></i> Falta: desde <?php echo $tr['falta']; ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($sanciones)): ?>
<div class="info-card">
    <h2><i class="fas fa-layer-group"></i> Sanciones acumulativas</h2>
    <p class="desc">Se activan automáticamente al acumular puntos descontados.</p>
    <table class="info-table">
        <thead><tr><th>Puntos descontados</th><th>Acción</th><th>Descripción</th></tr></thead>
        <tbody>
        <?php foreach ($sanciones as $s): ?>
            <tr><td><span class="tag red"><?php echo (int)$s['umbral_puntos_menos']; ?> o más</span></td><td><strong><?php echo htmlspecialchars($s['accion']); ?></strong></td><td><?php echo htmlspecialchars($s['descripcion'] ?? ''); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="info-card">
    <h2><i class="fas fa-book"></i> Faltas al reglamento</h2>
    <p class="desc"><?php echo count($faltas); ?> artículos del reglamento interno del plantel.</p>
    <input type="search" class="buscar" id="buscarFalta" placeholder="Buscar en el reglamento (ej. celular, uniforme, puntualidad)...">
    <div class="info-wrap">
        <table class="info-table" id="tablaFaltas">
            <thead><tr><th>Art.</th><th>Falta</th><th>Sanción</th><th>Puntos</th></tr></thead>
            <tbody>
            <?php foreach ($faltas as $f): ?>
                <tr>
                    <td><strong><?php echo (int)$f['numero_reglamento']; ?></strong></td>
                    <td><?php echo htmlspecialchars($f['descripcion_falta']); ?>
                        <?php if ($f['requiere_citacion_tutor']): ?><br><span class="tag amber" style="margin-top:4px;">Citatorio al tutor</span><?php endif; ?>
                        <?php if ($f['puede_ser_definitiva']): ?> <span class="tag red" style="margin-top:4px;">Puede ser baja definitiva</span><?php endif; ?>
                    </td>
                    <td><span class="tag blue"><?php echo htmlspecialchars($f['tipo_sancion']); ?></span><?php if (!empty($f['detalle_sancion'])): ?><br><small style="color:#64748b;"><?php echo htmlspecialchars($f['detalle_sancion']); ?></small><?php endif; ?></td>
                    <td><?php echo $f['puntos_descuento'] !== null ? '-' . (int)$f['puntos_descuento'] : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php estudiante_layout_fin("
    document.getElementById('buscarFalta').addEventListener('input', function () {
        const q = this.value.toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g, '');
        document.querySelectorAll('#tablaFaltas tbody tr').forEach(tr => {
            const t = tr.textContent.toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g, '');
            tr.style.display = t.includes(q) ? '' : 'none';
        });
    });
"); ?>
