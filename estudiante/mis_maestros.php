<?php
require_once '../config/database.php';
require_once 'includes/layout.php';

$alumno = estudiante_requerir_sesion($pdo);
$id = (int)$alumno['id_alumno'];
$maestros = [];
try {
    // Docentes del grupo del alumno, de sus aulas virtuales y de sus regularizaciones
    $st = $pdo->prepare("
        SELECT m.id_maestro, m.nombre, m.apellido_paterno, m.apellido_materno, m.correo, m.foto_perfil, m.titulo_academico, m.biografia,
               GROUP_CONCAT(DISTINCT x.materia ORDER BY x.materia SEPARATOR '||') AS materias
        FROM (
            SELECT gm.id_maestro, NULL AS materia
              FROM GRUPO_ALUMNO ga INNER JOIN GRUPO_MAESTRO gm ON gm.id_grupo = ga.id_grupo
             WHERE ga.id_alumno = :a1 AND ga.fecha_baja IS NULL
            UNION ALL
            SELECT c.id_maestro, mt.nombre_materia
              FROM CLASE_REGULAR_ALUMNO cra INNER JOIN CLASES_REGULARES c ON c.id_clase = cra.id_clase AND c.estatus = 'Activa'
              INNER JOIN MATERIAS mt ON mt.id_materia = c.id_materia
             WHERE cra.id_alumno = :a2
            UNION ALL
            SELECT c.id_maestro, CONCAT(c.nombre_materia, ' (regularización)')
              FROM CLASE_ALUMNO ca INNER JOIN CLASES_REGULARIZACION c ON c.id_clase = ca.id_clase AND c.visible = 1
             WHERE ca.id_alumno = :a3
        ) x
        INNER JOIN MAESTROS m ON m.id_maestro = x.id_maestro
        GROUP BY m.id_maestro
        ORDER BY m.nombre, m.apellido_paterno");
    $st->execute([':a1' => $id, ':a2' => $id, ':a3' => $id]);
    $maestros = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Mis maestros: ' . $e->getMessage());
}

function foto_maestro(?string $ruta): ?string {
    if (!$ruta) return null;
    $limpia = preg_replace('#^(\.\./)+#', '', $ruta);
    return (strpos($limpia, 'uploads/') === 0 && is_file(__DIR__ . '/../' . $limpia)) ? '../' . $limpia : null;
}

$css = '
.mm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1rem;}
.mm-card{border:1px solid #e2e8f0;border-radius:16px;padding:1.2rem;background:#fff;display:flex;flex-direction:column;gap:.6rem;}
.mm-top{display:flex;gap:.9rem;align-items:center;}
.mm-foto{width:58px;height:58px;border-radius:50%;background:linear-gradient(145deg,#063f2b,#10b981);color:#fff;display:grid;place-items:center;font-weight:800;font-size:1.1rem;overflow:hidden;flex-shrink:0;}
.mm-foto img{width:100%;height:100%;object-fit:cover;}
.mm-top strong{display:block;color:#0f172a;font-size:.98rem;}
.mm-top small{color:#64748b;font-size:.78rem;}
.mm-mat{display:flex;flex-wrap:wrap;gap:6px;}
.mm-mat span{background:#ecfdf5;color:#047857;font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:20px;}
.mm-card p{margin:0;font-size:.83rem;color:#475569;line-height:1.45;}
.mm-card{cursor:pointer;transition:.2s;}
.mm-card:hover{border-color:#10b981;box-shadow:0 10px 24px rgba(16,185,129,.12);transform:translateY(-2px);}
.mm-ver{font-size:.78rem;font-weight:700;color:#0f766e;}
.mm-card a{color:#047857;font-weight:600;font-size:.83rem;text-decoration:none;word-break:break-all;}
';
estudiante_layout_inicio('Mis Maestros', 'Docentes de tu grupo, de tus materias y de tus regularizaciones.', 'mis_maestros.php', $alumno, $css);
?>
<div class="info-card">
    <h2><i class="fas fa-chalkboard-user"></i> Mis maestros</h2>
    <p class="desc">Información básica de contacto de los docentes que tienes asignados.</p>
    <?php if (!$maestros): ?>
        <div class="info-empty"><i class="fas fa-user-slash"></i>Aún no tienes docentes asignados. Cuando control escolar asigne profesores a tu grupo aparecerán aquí.</div>
    <?php else: ?>
        <div class="mm-grid">
            <?php foreach ($maestros as $m):
                $foto = foto_maestro($m['foto_perfil']);
                $materias = array_filter(explode('||', (string)$m['materias'])); ?>
                <div class="mm-card" data-ficha-tipo="maestros" data-ficha-id="<?php echo (int)$m['id_maestro']; ?>" tabindex="0" role="button" aria-label="Ver perfil de <?php echo htmlspecialchars($m['nombre']); ?>">
                    <div class="mm-top">
                        <div class="mm-foto"><?php if ($foto): ?><img src="<?php echo htmlspecialchars($foto); ?>" alt="Foto"><?php else: echo htmlspecialchars(mb_substr($m['nombre'], 0, 1) . mb_substr($m['apellido_paterno'], 0, 1)); endif; ?></div>
                        <div>
                            <strong><?php echo htmlspecialchars(trim($m['nombre'] . ' ' . $m['apellido_paterno'] . ' ' . $m['apellido_materno'])); ?></strong>
                            <small><?php echo htmlspecialchars($m['titulo_academico'] ?: 'Docente'); ?></small>
                        </div>
                    </div>
                    <?php if ($materias): ?>
                        <div class="mm-mat"><?php foreach ($materias as $mt): ?><span><?php echo htmlspecialchars($mt); ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($m['biografia'])): ?><p><?php echo nl2br(htmlspecialchars(mb_strimwidth($m['biografia'], 0, 220, '…'))); ?></p><?php endif; ?>
                    <span class="mm-ver"><i class="fas fa-circle-info"></i> Ver perfil</span>
                    <?php if (!empty($m['correo'])): ?><a href="mailto:<?php echo htmlspecialchars($m['correo']); ?>"><i class="far fa-envelope"></i> <?php echo htmlspecialchars($m['correo']); ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script src="../js/ficha_modal.js"></script>
<?php estudiante_layout_fin(); ?>
