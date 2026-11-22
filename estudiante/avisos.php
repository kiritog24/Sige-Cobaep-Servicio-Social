<?php
require_once '../config/database.php';
require_once 'includes/layout.php';
require_once 'includes/avisos_data.php';

$alumno = estudiante_requerir_sesion($pdo);
$avisos = obtener_avisos_estudiante($pdo, (int)$alumno['id_alumno'], 50);

estudiante_layout_inicio('Avisos', 'Novedades de tus materias, asistencia y conducta.', 'avisos.php', $alumno, 'css/avisos.css');
?>
<div class="info-card">
    <h2><i class="fas fa-bullhorn"></i> Avisos recientes</h2>
    <p class="desc">Se generan automáticamente con la información de tus clases, entregas, asistencia y reportes.</p>
    <?php if (empty($avisos)): ?>
        <div class="info-empty"><i class="far fa-bell-slash"></i>No tienes avisos por ahora. Cuando tus docentes publiquen actividades o haya novedades, aparecerán aquí.</div>
    <?php else: ?>
        <?php foreach ($avisos as $a):
            $tag = $a['enlace'] ? 'a href="' . htmlspecialchars($a['enlace']) . '"' : 'div'; ?>
            <<?php echo $tag; ?> class="aviso <?php echo htmlspecialchars($a['estado'] ?? ''); ?>">
                <div class="aviso-icon <?php echo $a['color']; ?>"><i class="<?php echo $a['icono']; ?>"></i></div>
                <div>
                    <h3><?php echo htmlspecialchars($a['titulo']); ?></h3>
                    <p><?php echo htmlspecialchars($a['texto']); ?></p>
                    <?php echo aviso_etiquetas($a); ?>
                    <small><?php echo tiempo_relativo($a['fecha']); ?></small>
                </div>
            </<?php echo $a['enlace'] ? 'a' : 'div'; ?>>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php estudiante_layout_fin(); ?>
