<?php
/**
 * includes/ficha_alumno.php
 * Expediente del alumno compartido por Administración, Prefectura y Docentes:
 * datos escolares, personales (los que el alumno captura en su perfil), domicilio,
 * tutor, asistencia, conducta (con quién levantó cada reporte) y docentes asignados.
 */

/** Nombre de quien emitió un reporte (admin / maestro / prefecto). Úsese como subconsulta con alias r. */
const SQL_EMISOR_REPORTE = "CASE r.tipo_emisor
        WHEN 'maestro'  THEN (SELECT CONCAT('Docente: ', m.nombre, ' ', m.apellido_paterno) FROM MAESTROS m WHERE m.id_maestro = r.id_emisor)
        WHEN 'prefecto' THEN (SELECT CONCAT('Prefecto: ', p.nombre, ' ', p.apellido_paterno) FROM PREFECTOS p WHERE p.id_prefecto = r.id_emisor)
        WHEN 'admin'    THEN (SELECT CONCAT('Administración: ', ad.nombre, ' ', ad.apellido_paterno) FROM ADMINISTRATIVOS ad WHERE ad.id_admin = r.id_emisor)
     END";

function ficha_alumno_cargar(PDO $pdo, int $id_alumno): ?array {
    $st = $pdo->prepare("
        SELECT a.*, g.id_grupo, g.semestre AS grupo_semestre, g.nombre_grupo, g.turno AS grupo_turno
        FROM ALUMNOS a
        LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
        LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
        WHERE a.id_alumno = ? LIMIT 1");
    $st->execute([$id_alumno]);
    $al = $st->fetch(PDO::FETCH_ASSOC);
    if (!$al) return null;

    $d = ['al' => $al];
    $st = $pdo->prepare("SELECT * FROM TUTOR_ALUMNO WHERE id_alumno = ?");
    $st->execute([$id_alumno]);
    $d['tutor'] = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT * FROM DOMICILIO_ALUMNO WHERE id_alumno = ?");
    $st->execute([$id_alumno]);
    $d['dom'] = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT SUM(estatus = 'Puntual') AS puntuales,
                                SUM(estatus IN ('Retardo', 'Retardo Justificado')) AS retardos,
                                SUM(estatus IN ('Falta', 'Falta Justificada')) AS faltas
                         FROM ASISTENCIAS WHERE id_alumno = ?");
    $st->execute([$id_alumno]);
    $d['asis'] = $st->fetch(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT fecha_hora_escaneo, fecha_hora_salida, estatus FROM ASISTENCIAS WHERE id_alumno = ? ORDER BY fecha_hora_escaneo DESC LIMIT 10");
    $st->execute([$id_alumno]);
    $d['ultimas'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT r.fecha_hora, r.puntos_descontados, r.observaciones, tf.numero_reglamento, tf.descripcion_falta,
                                " . SQL_EMISOR_REPORTE . " AS emisor
                         FROM REPORTES r INNER JOIN TIPOS_FALTA tf ON tf.id_tipo_falta = r.id_tipo_falta
                         WHERE r.id_alumno = ? ORDER BY r.fecha_hora DESC");
    $st->execute([$id_alumno]);
    $d['reportes'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Docentes del grupo actual y materias que le imparten
    $d['docentes'] = [];
    if ($al['id_grupo']) {
        $st = $pdo->prepare("SELECT m.id_maestro, CONCAT_WS(' ', m.nombre, m.apellido_paterno, m.apellido_materno) AS nombre, m.correo,
                                    (SELECT GROUP_CONCAT(DISTINCT mt.nombre_materia SEPARATOR ', ') FROM CLASES_REGULARES cr
                                       INNER JOIN MATERIAS mt ON mt.id_materia = cr.id_materia
                                      WHERE cr.id_grupo = gm.id_grupo AND cr.id_maestro = m.id_maestro AND cr.estatus = 'Activa') AS materias
                             FROM GRUPO_MAESTRO gm INNER JOIN MAESTROS m ON m.id_maestro = gm.id_maestro
                             WHERE gm.id_grupo = ? ORDER BY m.nombre");
        $st->execute([$al['id_grupo']]);
        $d['docentes'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $d;
}

/** Imprime el expediente. $opt: editar (url|null), lista (url|null) */
function ficha_alumno_render(array $d, array $opt = []): void {
    $al = $d['al']; $tutor = $d['tutor']; $dom = $d['dom']; $asis = $d['asis'];
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $v = fn($x) => ($x === null || $x === '') ? '<span style="color:#94a3b8">Sin registro</span>' : htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $nombre = trim($al['nombre'] . ' ' . $al['apellido_paterno'] . ' ' . $al['apellido_materno']);
    $pts = (int)$al['puntos_conducta'];
    $domicilio = null;
    if ($dom) {
        $domicilio = trim(($dom['calle'] ?? '') . ' ' . ($dom['num_exterior'] ?? '') . (!empty($dom['num_interior']) ? ' int. ' . $dom['num_interior'] : ''));
        $domicilio = trim($domicilio . ', ' . ($dom['colonia'] ?? '') . ', ' . ($dom['municipio'] ?? '') . (!empty($dom['codigo_postal']) ? ', C.P. ' . $dom['codigo_postal'] : ''), ' ,');
    }
    $faltan = [];
    foreach (['telefono' => 'teléfono', 'correo_personal' => 'correo', 'fecha_nacimiento' => 'fecha de nacimiento'] as $k => $txt) if (empty($al[$k])) $faltan[] = $txt;
    if (empty($dom['calle'])) $faltan[] = 'domicilio';
    if (empty($tutor['nombre_tutor'])) $faltan[] = 'tutor';
    ?>
    <style>
        .pa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;}
        .pa-card{background:#fff;border:1px solid #e5ece8;border-radius:16px;padding:22px;box-shadow:0 4px 14px rgba(6,63,43,.04);}
        .pa-card h2{font-size:1rem;margin:0 0 14px;color:#10231a;display:flex;align-items:center;gap:10px;}
        .pa-card h2 i{color:#16a05d;}
        .pa-head{display:flex;align-items:center;gap:18px;flex-wrap:wrap;}
        .pa-avatar{width:84px;height:84px;border-radius:50%;background:#e9f8f0;color:#0f7a45;display:grid;place-items:center;font-size:1.7rem;font-weight:700;overflow:hidden;flex-shrink:0;}
        .pa-avatar img{width:100%;height:100%;object-fit:cover;}
        .pa-head h3{margin:0;font-size:1.25rem;color:#10231a;}
        .pa-head p{margin:4px 0 0;color:#6d7f77;font-size:.88rem;}
        .pa-dl{display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:.88rem;margin:0;}
        .pa-dl dt{color:#6d7f77;font-weight:500;}
        .pa-dl dd{margin:0;color:#1e293b;font-weight:600;word-break:break-word;}
        .pa-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;text-align:center;}
        .pa-stats div{background:#f6faf8;border-radius:12px;padding:12px 6px;}
        .pa-stats strong{display:block;font-size:1.35rem;color:#10231a;}
        .pa-stats span{font-size:.72rem;color:#6d7f77;}
        .pa-table{width:100%;border-collapse:collapse;font-size:.85rem;}
        .pa-table th{text-align:left;color:#475569;font-weight:600;padding:8px;border-bottom:1px solid #e5ece8;}
        .pa-table td{padding:8px;border-bottom:1px solid #f0f4f2;vertical-align:top;}
        .pa-table small{display:block;color:#64748b;}
        .pa-empty{color:#6d7f77;font-size:.88rem;margin:0;}
        .pa-actions{display:flex;gap:10px;flex-wrap:wrap;margin-left:auto;}
        .pa-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;font-weight:600;font-size:.85rem;text-decoration:none;}
        .pa-btn.edit{background:#fff6e6;color:#b45309;} .pa-btn.list{background:#e9f8f0;color:#0f7a45;}
        .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:600;}
        .pill.ok{background:#e9f8f0;color:#0f7a45;} .pill.warn{background:#fff6e6;color:#b45309;} .pill.bad{background:#fef2f2;color:#b91c1c;}
        .pa-aviso{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:10px 14px;font-size:.85rem;margin-top:14px;}
        .pa-doc{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #f0f4f2;font-size:.88rem;}
        .pa-doc:last-child{border-bottom:none;}
        .pa-doc i{color:#0d9488;}
    </style>

    <div class="pa-card" style="margin-bottom:20px;">
        <div class="pa-head">
            <div class="pa-avatar">
                <?php if (!empty($al['fotografia']) && is_file(__DIR__ . '/../' . $al['fotografia'])): ?>
                    <img src="../<?php echo $h($al['fotografia']); ?>?v=<?php echo filemtime(__DIR__ . '/../' . $al['fotografia']); ?>" alt="Foto">
                <?php else: echo $h(mb_substr($al['nombre'], 0, 1) . mb_substr($al['apellido_paterno'], 0, 1)); endif; ?>
            </div>
            <div>
                <h3><?php echo $h($nombre); ?></h3>
                <p><?php echo $h($al['matricula']); ?> ·
                   <?php echo $al['id_grupo'] ? $h($al['grupo_semestre'] . '° "' . $al['nombre_grupo'] . '" · ' . $al['grupo_turno']) : 'Sin grupo asignado'; ?>
                   · <span class="pill <?php echo $al['estatus'] === 'Alta' ? 'ok' : 'bad'; ?>"><?php echo $h($al['estatus']); ?></span>
                   <?php if ($al['estado_disciplinario'] !== 'Activo'): ?><span class="pill bad"><?php echo $h($al['estado_disciplinario']); ?><?php echo $al['fecha_fin_suspension'] ? ' hasta ' . date('d/m/Y', strtotime($al['fecha_fin_suspension'])) : ''; ?></span><?php endif; ?></p>
            </div>
            <div class="pa-actions">
                <?php if (!empty($opt['lista'])): ?><a class="pa-btn list" href="<?php echo $h($opt['lista']); ?>"><i class="fas fa-list-ol"></i> Lista del grupo</a><?php endif; ?>
                <?php foreach ($opt['acciones'] ?? [] as [$url, $ico, $txt]): ?><a class="pa-btn list" href="<?php echo $h($url); ?>"><i class="fas <?php echo $h($ico); ?>"></i> <?php echo $h($txt); ?></a><?php endforeach; ?>
                <?php if (!empty($opt['editar'])): ?><a class="pa-btn edit" href="<?php echo $h($opt['editar']); ?>"><i class="fas fa-pen"></i> Editar</a><?php endif; ?>
            </div>
        </div>
        <?php if ($faltan): ?>
            <div class="pa-aviso"><i class="fas fa-circle-info"></i> El alumno aún no ha capturado en su perfil: <b><?php echo $h(implode(', ', $faltan)); ?></b>.</div>
        <?php endif; ?>
    </div>

    <div class="pa-grid">
        <div class="pa-card">
            <h2><i class="fas fa-id-card"></i> Datos escolares</h2>
            <dl class="pa-dl">
                <dt>Estatus</dt><dd><?php echo $v($al['estatus']); ?></dd>
                <dt>Turno</dt><dd><?php echo $v($al['turno']); ?></dd>
                <dt>Semestre</dt><dd><?php echo $v($al['semestre'] ?? $al['grupo_semestre']); ?></dd>
                <dt>Capacitación</dt><dd><?php echo $v($al['capacitacion']); ?></dd>
                <dt>Periodo de ingreso</dt><dd><?php echo $v($al['periodo_ingreso']); ?></dd>
                <dt>Código QR</dt><dd><?php echo $v($al['codigo_qr']); ?></dd>
            </dl>
        </div>
        <div class="pa-card">
            <h2><i class="fas fa-user"></i> Datos personales y contacto</h2>
            <dl class="pa-dl">
                <dt>Sexo</dt><dd><?php echo $v($al['sexo'] === 'F' ? 'Femenino' : ($al['sexo'] === 'M' ? 'Masculino' : null)); ?></dd>
                <dt>Nacimiento</dt><dd><?php echo $v($al['fecha_nacimiento'] ? date('d/m/Y', strtotime($al['fecha_nacimiento'])) . ($al['edad'] ? ' (' . (int)$al['edad'] . ' años)' : '') : null); ?></dd>
                <dt>Teléfono</dt><dd><?php echo $v($al['telefono']); ?></dd>
                <dt>Correo personal</dt><dd><?php echo $v($al['correo_personal']); ?></dd>
                <dt>Correo institucional</dt><dd><?php echo $v($al['correo_institucional']); ?></dd>
                <dt>Domicilio</dt><dd><?php echo $v($domicilio); ?></dd>
            </dl>
        </div>
        <div class="pa-card">
            <h2><i class="fas fa-people-roof"></i> Padre, madre o tutor</h2>
            <dl class="pa-dl">
                <dt>Nombre</dt><dd><?php echo $v($tutor['nombre_tutor'] ?? null); ?></dd>
                <dt>Teléfono</dt><dd><?php echo $v($tutor['telefono_1'] ?? null); ?></dd>
                <dt>Teléfono alterno</dt><dd><?php echo $v($tutor['telefono_2'] ?? null); ?></dd>
            </dl>
        </div>
        <div class="pa-card">
            <h2><i class="fas fa-chart-pie"></i> Asistencia y conducta</h2>
            <div class="pa-stats">
                <div><strong><?php echo (int)$asis['puntuales']; ?></strong><span>Puntuales</span></div>
                <div><strong><?php echo (int)$asis['retardos']; ?></strong><span>Retardos</span></div>
                <div><strong><?php echo (int)$asis['faltas']; ?></strong><span>Faltas</span></div>
                <div><strong><span class="pill <?php echo $pts >= 75 ? 'ok' : ($pts >= 60 ? 'warn' : 'bad'); ?>"><?php echo $pts; ?></span></strong><span>Puntos conducta</span></div>
            </div>
        </div>
        <div class="pa-card">
            <h2><i class="fas fa-chalkboard-user"></i> Docentes asignados</h2>
            <?php if (!$d['docentes']): ?>
                <p class="pa-empty">El grupo del alumno aún no tiene docentes asignados.</p>
            <?php else: foreach ($d['docentes'] as $doc): ?>
                <div class="pa-doc"><i class="fas fa-user-tie"></i><div><strong><?php echo $h($doc['nombre']); ?></strong>
                    <?php if ($doc['materias']): ?><br><small style="color:#64748b;"><?php echo $h($doc['materias']); ?></small><?php endif; ?></div></div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="pa-grid" style="margin-top:20px;">
        <div class="pa-card">
            <h2><i class="fas fa-clock-rotate-left"></i> Últimas asistencias</h2>
            <?php if (empty($d['ultimas'])): ?>
                <p class="pa-empty">Sin registros de asistencia.</p>
            <?php else: ?>
                <table class="pa-table">
                    <thead><tr><th>Fecha</th><th>Entrada</th><th>Salida</th><th>Estatus</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['ultimas'] as $u): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($u['fecha_hora_escaneo'])); ?></td>
                            <td><?php echo date('H:i', strtotime($u['fecha_hora_escaneo'])); ?></td>
                            <td><?php echo $u['fecha_hora_salida'] ? date('H:i', strtotime($u['fecha_hora_salida'])) : '—'; ?></td>
                            <td><?php echo $h($u['estatus']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div class="pa-card">
            <h2><i class="fas fa-file-shield"></i> Reportes de conducta</h2>
            <?php if (empty($d['reportes'])): ?>
                <p class="pa-empty">El alumno no tiene reportes.</p>
            <?php else: ?>
                <table class="pa-table">
                    <thead><tr><th>Fecha</th><th>Falta</th><th>Reportó</th><th>Puntos</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['reportes'] as $r): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo date('d/m/Y', strtotime($r['fecha_hora'])); ?></td>
                            <td><strong>Art. <?php echo (int)$r['numero_reglamento']; ?></strong> · <?php echo $h(mb_strimwidth($r['descripcion_falta'], 0, 80, '…')); ?>
                                <?php if (!empty($r['observaciones'])): ?><small><?php echo $h($r['observaciones']); ?></small><?php endif; ?></td>
                            <td><?php echo $v($r['emisor']); ?></td>
                            <td>-<?php echo (int)$r['puntos_descontados']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
