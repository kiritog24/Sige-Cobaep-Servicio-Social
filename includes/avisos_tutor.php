<?php
/**
 * includes/avisos_tutor.php — Avisos por correo al padre, madre o tutor.
 *
 *  - Conducta: se genera solo al levantar un reporte (Prefectura › Reportes de conducta).
 *  - Faltas: Prefectura revisa la lista de alumnos que superan el límite de faltas en un periodo
 *    y elige a quién avisar (Prefectura › Avisos a tutores).
 *
 * Cada aviso queda guardado en AVISOS_TUTOR (enviado, pendiente, fallido o sin correo). Si el correo
 * del plantel no está configurado, el aviso queda "pendiente" y se puede enviar después.
 * Un mismo aviso nunca se manda dos veces (llave única tipo + alumno + referencia).
 */
require_once __DIR__ . '/correo.php';

/** Datos del alumno y su tutor para el aviso. */
function aviso_datos_alumno(PDO $pdo, int $id_alumno): ?array {
    $st = $pdo->prepare("SELECT a.id_alumno, a.matricula, CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) AS alumno,
                                t.nombre_tutor, t.correo_tutor,
                                IF(g.id_grupo IS NULL, 'Sin grupo', CONCAT(g.semestre, '° \"', g.nombre_grupo, '\" ', g.turno)) AS grupo
                         FROM ALUMNOS a
                         LEFT JOIN TUTOR_ALUMNO t ON t.id_alumno = a.id_alumno
                         LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                         LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                         WHERE a.id_alumno = ? LIMIT 1");
    $st->execute([$id_alumno]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Correo con el diseño institucional. $filas: [['Etiqueta', 'Valor'], …] */
function aviso_html(string $saludo, string $intro, array $filas, string $cierre): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tabla = '';
    foreach ($filas as [$k, $v]) {
        $tabla .= '<tr><td style="padding:8px 12px;background:#f1f5f3;color:#475569;font-size:13px;width:38%;border-bottom:1px solid #e2e8f0;">' . $e($k) . '</td>'
                . '<td style="padding:8px 12px;color:#0f172a;font-size:14px;border-bottom:1px solid #e2e8f0;">' . nl2br($e($v)) . '</td></tr>';
    }
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f8f6;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f8f6;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<tr><td style="background:#063f2b;padding:18px 24px;color:#ffffff;"><div style="font-size:18px;font-weight:bold;">COBAEP Plantel 27</div>'
        . '<div style="font-size:12px;color:#bbf7d0;">Colegio de Bachilleres del Estado de Puebla · Zaragoza, Puebla</div></td></tr>'
        . '<tr><td style="padding:24px;"><p style="margin:0 0 12px;font-size:15px;color:#0f172a;">' . $e($saludo) . '</p>'
        . '<p style="margin:0 0 18px;font-size:14px;color:#334155;line-height:1.6;">' . $e($intro) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;">' . $tabla . '</table>'
        . '<p style="margin:18px 0 0;font-size:14px;color:#334155;line-height:1.6;">' . $e($cierre) . '</p></td></tr>'
        . '<tr><td style="padding:14px 24px;background:#f8fafc;color:#94a3b8;font-size:11px;line-height:1.5;">Este es un aviso automático del Sistema de Información y Gestión Educativa (SIGE). '
        . 'Para cualquier aclaración comuníquese a Prefectura o a Control Escolar del plantel.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Guarda el aviso y, si el correo está configurado y hay destinatario, lo envía. Devuelve el estatus. */
function aviso_registrar(PDO $pdo, int $id_alumno, string $tipo, string $referencia, string $asunto, string $html, ?string $correo): string {
    $estatus = $correo ? 'pendiente' : 'sin_correo';
    try {
        $pdo->prepare("INSERT INTO AVISOS_TUTOR (id_alumno, tipo, referencia, correo_destino, asunto, cuerpo, estatus, rol_genero, id_usuario_genero)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$id_alumno, $tipo, $referencia, $correo, $asunto, $html, $estatus, $_SESSION['rol'] ?? null, isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : null]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) return 'duplicado';   // ese aviso ya existía
        error_log('aviso_registrar: ' . $e->getMessage());
        return 'error';
    }
    return $correo && correo_activo() ? aviso_enviar($pdo, $id) : $estatus;
}

/** Envía (o reintenta) un aviso guardado. */
function aviso_enviar(PDO $pdo, int $id_aviso): string {
    $st = $pdo->prepare("SELECT a.*, t.correo_tutor FROM AVISOS_TUTOR a LEFT JOIN TUTOR_ALUMNO t ON t.id_alumno = a.id_alumno WHERE a.id_aviso = ?");
    $st->execute([$id_aviso]);
    $av = $st->fetch(PDO::FETCH_ASSOC);
    if (!$av || $av['estatus'] === 'enviado') return $av['estatus'] ?? 'error';
    $correo = $av['correo_tutor'] ?: $av['correo_destino'];   // si el tutor ya registró su correo, se usa el actual
    if (!$correo) {
        $pdo->prepare("UPDATE AVISOS_TUTOR SET estatus = 'sin_correo' WHERE id_aviso = ?")->execute([$id_aviso]);
        return 'sin_correo';
    }
    if (!correo_activo()) return 'pendiente';
    $r = correo_enviar($correo, $av['asunto'], $av['cuerpo']);
    $pdo->prepare("UPDATE AVISOS_TUTOR SET correo_destino = ?, estatus = ?, error = ?, intentos = intentos + 1, fecha_envio = IF(? = 'enviado', NOW(), fecha_envio) WHERE id_aviso = ?")
        ->execute([$correo, $r['ok'] ? 'enviado' : 'fallido', $r['ok'] ? null : $r['error'], $r['ok'] ? 'enviado' : 'fallido', $id_aviso]);
    return $r['ok'] ? 'enviado' : 'fallido';
}

/** Aviso de reporte de conducta (se llama al levantar el reporte). */
function aviso_tutor_conducta(PDO $pdo, int $id_reporte): string {
    try {
        $st = $pdo->prepare("SELECT r.id_alumno, r.fecha_hora, r.observaciones, tf.numero_reglamento, tf.descripcion_falta, tf.tipo_sancion, tf.detalle_sancion,
                                    tf.requiere_citacion_tutor, a.estado_disciplinario, a.fecha_fin_suspension
                             FROM REPORTES r INNER JOIN TIPOS_FALTA tf ON tf.id_tipo_falta = r.id_tipo_falta INNER JOIN ALUMNOS a ON a.id_alumno = r.id_alumno
                             WHERE r.id_reporte = ?");
        $st->execute([$id_reporte]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 'error';
        $al = aviso_datos_alumno($pdo, (int)$r['id_alumno']);
        $filas = [
            ['Alumno(a)', $al['alumno']],
            ['Matrícula', $al['matricula']],
            ['Grupo', $al['grupo']],
            ['Fecha del reporte', date('d/m/Y h:i a', strtotime($r['fecha_hora']))],
            ['Falta (art. ' . $r['numero_reglamento'] . ' del reglamento)', $r['descripcion_falta']],
        ];
        if ($r['detalle_sancion']) $filas[] = ['Sanción', $r['detalle_sancion']];
        if ($r['estado_disciplinario'] === 'Suspendido' && $r['fecha_fin_suspension']) $filas[] = ['Suspensión', 'Hasta el ' . date('d/m/Y', strtotime($r['fecha_fin_suspension'])) . '. Puede regresar el ' . date('d/m/Y', strtotime($r['fecha_fin_suspension'] . ' +1 day')) . '.'];
        if ($r['estado_disciplinario'] === 'Baja') $filas[] = ['Situación', 'Baja definitiva'];
        if (trim((string)$r['observaciones']) !== '') $filas[] = ['Observaciones', $r['observaciones']];
        $cierre = (int)$r['requiere_citacion_tutor'] === 1
            ? 'Esta falta requiere que el padre, madre o tutor se presente en Prefectura del plantel a la brevedad.'
            : 'Le pedimos platicar con su hijo(a) sobre este reporte. Si tiene dudas, puede acudir a Prefectura del plantel.';
        $html = aviso_html('Estimado(a) ' . ($al['nombre_tutor'] ?: 'padre, madre o tutor') . ':',
                           'Le informamos que su hijo(a) recibió un reporte de conducta en el plantel:', $filas, $cierre);
        $estatus = aviso_registrar($pdo, (int)$r['id_alumno'], 'conducta', 'reporte-' . $id_reporte, 'Reporte de conducta · ' . $al['alumno'], $html, $al['correo_tutor'] ?: null);
        if (in_array($estatus, ['enviado', 'pendiente', 'sin_correo', 'fallido'], true)) {
            $txt = ['enviado' => 'Se envió', 'pendiente' => 'Quedó pendiente', 'sin_correo' => 'No se pudo enviar (sin correo del tutor)', 'fallido' => 'Falló el envío de'][$estatus];
            bitacora($pdo, 'avisos', 'conducta', "$txt el aviso de reporte de conducta al tutor de " . $al['matricula'] . ' · ' . $al['alumno'], 'alumno', (int)$r['id_alumno']);
        }
        return $estatus;
    } catch (Throwable $e) {
        error_log('aviso_tutor_conducta: ' . $e->getMessage());
        return 'error';
    }
}

/** Aviso de faltas acumuladas en un periodo. */
function aviso_tutor_faltas(PDO $pdo, int $id_alumno, string $desde, string $hasta, int $faltas, array $fechas): string {
    $al = aviso_datos_alumno($pdo, $id_alumno);
    if (!$al) return 'error';
    $lista = implode(', ', array_map(fn($f) => date('d/m', strtotime($f)), array_slice($fechas, 0, 40))) . (count($fechas) > 40 ? '…' : '');
    $html = aviso_html('Estimado(a) ' . ($al['nombre_tutor'] ?: 'padre, madre o tutor') . ':',
        'Le informamos que su hijo(a) acumula inasistencias sin justificar en el plantel:',
        [['Alumno(a)', $al['alumno']], ['Matrícula', $al['matricula']], ['Grupo', $al['grupo']],
         ['Periodo', 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta))],
         ['Faltas sin justificar', (string)$faltas], ['Días', $lista]],
        'Si alguna falta tiene justificación (por ejemplo, receta médica), preséntela en Prefectura. Las faltas acumuladas pueden afectar la situación escolar de su hijo(a).');
    return aviso_registrar($pdo, $id_alumno, 'faltas', "faltas-$desde-$hasta", 'Aviso de faltas · ' . $al['alumno'], $html, $al['correo_tutor'] ?: null);
}

/**
 * Faltas sin justificar de los alumnos activos en un periodo (lunes a viernes, sin suspensiones de clases,
 * solo mientras pertenecen a su grupo). Igual que el concentrado de Prefectura.
 * $contar_sin_registro: si true, un día hábil sin entrada también cuenta como falta.
 * @return array<int, array{faltas:int, fechas:array}>
 */
function faltas_periodo(PDO $pdo, string $desde, string $hasta, int $id_grupo = 0, bool $contar_sin_registro = true): array {
    $hasta = min($hasta, date('Y-m-d'));
    if ($desde > $hasta) return [];
    $sql = "SELECT a.id_alumno, ga.fecha_asignacion, ga.fecha_baja FROM GRUPO_ALUMNO ga INNER JOIN ALUMNOS a ON a.id_alumno = ga.id_alumno
            INNER JOIN GRUPOS g ON g.id_grupo = ga.id_grupo AND g.estatus = 'Activo'
            WHERE a.estatus = 'Alta' AND ga.fecha_asignacion <= ? AND (ga.fecha_baja IS NULL OR ga.fecha_baja > ?)";
    $p = [$hasta, $desde];
    if ($id_grupo > 0) { $sql .= " AND ga.id_grupo = ?"; $p[] = $id_grupo; }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $alumnos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$alumnos) return [];

    $ids = implode(',', array_map(fn($a) => (int)$a['id_alumno'], $alumnos));
    $st = $pdo->prepare("SELECT id_alumno, DATE(fecha_hora_escaneo) AS dia, SUBSTRING_INDEX(GROUP_CONCAT(estatus ORDER BY fecha_hora_escaneo ASC), ',', 1) AS estatus
                         FROM ASISTENCIAS WHERE id_alumno IN ($ids) AND tipo_registro = 'Entrada' AND DATE(fecha_hora_escaneo) BETWEEN ? AND ?
                         GROUP BY id_alumno, DATE(fecha_hora_escaneo)");
    $st->execute([$desde, $hasta]);
    $reg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $reg[(int)$r['id_alumno']][$r['dia']] = $r['estatus'];

    $susp = $pdo->prepare("SELECT fecha_inicio, fecha_fin FROM SUSPENSIONES_CLASES WHERE fecha_fin >= ? AND fecha_inicio <= ?");
    $susp->execute([$desde, $hasta]);
    $rangos = $susp->fetchAll(PDO::FETCH_ASSOC);
    $dias = [];
    for ($d = new DateTime($desde); $d->format('Y-m-d') <= $hasta; $d->modify('+1 day')) {
        if ((int)$d->format('N') > 5) continue;
        $f = $d->format('Y-m-d');
        foreach ($rangos as $r) if ($f >= $r['fecha_inicio'] && $f <= $r['fecha_fin']) continue 2;
        $dias[] = $f;
    }

    $res = [];
    foreach ($alumnos as $a) {
        $id = (int)$a['id_alumno'];
        $fechas = [];
        foreach ($dias as $dia) {
            if ($dia < $a['fecha_asignacion'] || ($a['fecha_baja'] && $dia >= $a['fecha_baja'])) continue;
            $e = $reg[$id][$dia] ?? null;
            if ($e === 'Falta' || ($e === null && $contar_sin_registro)) $fechas[] = $dia;
        }
        if ($fechas) $res[$id] = ['faltas' => count($fechas), 'fechas' => $fechas];
    }
    return $res;
}
