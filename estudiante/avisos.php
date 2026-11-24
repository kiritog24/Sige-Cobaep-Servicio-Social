<?php
require_once '../config/database.php';
require_once 'includes/layout.php';
require_once 'includes/avisos_data.php';

$alumno = estudiante_requerir_sesion($pdo);
$id_alumno = (int)$alumno['id_alumno'];
$vista = ($_GET['vista'] ?? '') === 'guardados' ? 'guardados' : 'recientes';

// Recientes: lo nuevo y lo que aún pide algo. Los avisos viejos se guardan solos; nada se borra.
$avisos = avisos_visibles($pdo, $id_alumno, obtener_avisos_estudiante($pdo, $id_alumno, 50));
$guardados = avisos_guardados($pdo, $id_alumno);
$lista = $vista === 'guardados' ? $guardados : $avisos;
$limpiables = count(array_filter($avisos, fn($a) => !aviso_pendiente($a) && !empty($a['clave'])));

estudiante_layout_inicio('Avisos', 'Novedades de tus materias, asistencia y conducta.', 'avisos.php', $alumno, 'css/avisos.css?v=2');
?>
<div class="info-card">
    <div class="avisos-cabecera">
        <div>
            <h2><i class="fas fa-bullhorn"></i> Avisos</h2>
            <p class="desc">Se generan con la información de tus clases, entregas, asistencia y reportes. Los avisos de más de <?php echo AVISOS_DIAS_RECIENTES; ?> días se guardan solos para no llenar tu pantalla; no se borra ninguno.</p>
        </div>
        <?php if ($vista === 'recientes' && $limpiables > 0): ?>
            <form action="procesos/avisos_accion.php" method="POST" onsubmit="return confirm('¿Guardar los <?php echo $limpiables; ?> avisos que ya no te piden nada? Los podrás ver en la pestaña Guardados.');">
                <input type="hidden" name="accion" value="limpiar">
                <button type="submit" class="btn-limpiar"><i class="fas fa-broom"></i> Limpiar vista</button>
            </form>
        <?php endif; ?>
    </div>

    <nav class="avisos-tabs" aria-label="Tipo de avisos">
        <a href="avisos.php" class="<?php echo $vista === 'recientes' ? 'activa' : ''; ?>"><i class="fas fa-bell"></i> Recientes <span><?php echo count($avisos); ?></span></a>
        <a href="avisos.php?vista=guardados" class="<?php echo $vista === 'guardados' ? 'activa' : ''; ?>"><i class="fas fa-box-archive"></i> Guardados <span><?php echo count($guardados); ?></span></a>
    </nav>

    <?php if (isset($_GET['exito'])): ?>
        <div class="avisos-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['exito']); ?></div>
    <?php endif; ?>

    <?php if (empty($lista)): ?>
        <div class="info-empty">
            <?php if ($vista === 'guardados'): ?>
                <i class="fas fa-box-open"></i>No tienes avisos guardados. Cuando limpies tu vista o un aviso cumpla <?php echo AVISOS_DIAS_RECIENTES; ?> días, aparecerá aquí.
            <?php else: ?>
                <i class="far fa-bell-slash"></i>No tienes avisos recientes. Los anteriores están en la pestaña <a href="avisos.php?vista=guardados">Guardados</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($lista as $a):
            $enlace = $a['enlace'] ?? null; ?>
            <div class="aviso <?php echo htmlspecialchars($a['estado'] ?? ''); ?>">
                <div class="aviso-icon <?php echo htmlspecialchars($a['color'] ?? 'gray'); ?>"><i class="<?php echo htmlspecialchars($a['icono'] ?? 'fas fa-bell'); ?>"></i></div>
                <div class="aviso-cuerpo">
                    <h3><?php if ($enlace): ?><a href="<?php echo htmlspecialchars($enlace); ?>"><?php echo htmlspecialchars($a['titulo']); ?></a><?php else: echo htmlspecialchars($a['titulo']); endif; ?></h3>
                    <p><?php echo htmlspecialchars((string)$a['texto']); ?></p>
                    <?php if ($vista === 'recientes') echo aviso_etiquetas($a); ?>
                    <small><?php echo tiempo_relativo($a['fecha']); ?><?php if ($vista === 'guardados'): ?> · <?php echo (int)$a['automatico'] === 1 ? 'Guardado automáticamente' : 'Lo guardaste'; ?> el <?php echo date('d/m/Y', strtotime($a['fecha_guardado'])); ?><?php endif; ?></small>
                </div>
                <?php if (!empty($a['clave'])): ?>
                    <form action="procesos/avisos_accion.php" method="POST" class="aviso-accion">
                        <input type="hidden" name="accion" value="<?php echo $vista === 'guardados' ? 'restaurar' : 'ocultar'; ?>">
                        <input type="hidden" name="clave" value="<?php echo htmlspecialchars($a['clave']); ?>">
                        <?php if ($vista === 'guardados'): ?>
                            <button type="submit" title="Regresar a recientes"><i class="fas fa-rotate-left"></i><span>Regresar</span></button>
                        <?php else: ?>
                            <button type="submit" title="Guardar y quitar de la vista"><i class="fas fa-box-archive"></i><span>Guardar</span></button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php estudiante_layout_fin(); ?>
