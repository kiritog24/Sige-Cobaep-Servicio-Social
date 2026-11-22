<?php
/**
 * estudiante/includes/campana.php
 * Campana del encabezado del alumno: lleva a "Avisos" y muestra cuántos avisos
 * nuevos hay (de los últimos 3 días).
 */
require_once __DIR__ . '/avisos_data.php';
require_once __DIR__ . '/avatar.php';

function estudiante_campana(PDO $pdo): void {
    $nuevos = 0;
    try {
        $desde = date('Y-m-d H:i:s', strtotime('-3 days'));
        $ahora = date('Y-m-d H:i:s');
        foreach (obtener_avisos_estudiante($pdo, (int)($_SESSION['id_usuario'] ?? 0), 50) as $a) {
            if ($a['fecha'] && $a['fecha'] >= $desde && $a['fecha'] <= $ahora) $nuevos++;
        }
    } catch (PDOException $e) {
        error_log('Campana alumno: ' . $e->getMessage());
    }
    $titulo = $nuevos ? "Tienes $nuevos avisos nuevos" : 'Ver avisos';
    $activa = basename($_SERVER['PHP_SELF']) === 'avisos.php';
    static $estilos = false;
    if (!$estilos) { $estilos = true; ?>
    <link rel="stylesheet" href="css/campana.css?v=1">
    <?php } ?>
                    <a class="campana-est<?php echo $activa ? ' activa' : ''; ?>" href="avisos.php" title="<?php echo $titulo; ?>" aria-label="<?php echo $titulo; ?>">
                        <i class="far fa-bell"></i><?php if ($nuevos): ?><span class="campana-num"><?php echo $nuevos > 9 ? '9+' : $nuevos; ?></span><?php endif; ?>
                    </a>
<?php
}
