<?php
/**
 * Genera los avisos del estudiante a partir de información real del sistema:
 * actividades nuevas, entregas revisadas, reportes de conducta, asistencia del
 * mes, regularizaciones asignadas y suspensiones de clases.
 *
 * Cada aviso: ['fecha' => 'Y-m-d H:i:s', 'icono', 'color', 'titulo', 'texto', 'enlace']
 */
/** ¿Al alumno le falta registrar el correo de su padre, madre o tutor? */
function alumno_falta_correo_tutor(PDO $pdo, int $id_alumno): bool {
    try {
        $st = $pdo->prepare("SELECT correo_tutor FROM TUTOR_ALUMNO WHERE id_alumno = ?");
        $st->execute([$id_alumno]);
        return trim((string)$st->fetchColumn()) === '';
    } catch (PDOException $e) {
        return false;
    }
}

function obtener_avisos_estudiante(PDO $pdo, int $id_alumno, int $limite = 30): array {
    asegurar_columnas_actividades($pdo);
    $avisos = [];
    // $extra: datos opcionales del aviso (estado de la actividad, si acepta retardo, etc.)
    $agregar = function ($fecha, $icono, $color, $titulo, $texto, $enlace = null, array $extra = []) use (&$avisos) {
        $avisos[] = array_merge(['estado' => null, 'acepta_retardo' => null, 'fecha_limite' => null, 'clave' => null],
                                compact('fecha', 'icono', 'color', 'titulo', 'texto', 'enlace'), $extra);
    };

    // Cada consulta va por separado: si una falla, las demás se siguen mostrando.
    try {
        // 1. Actividades publicadas en mis materias (últimos 30 días)
        $st = $pdo->prepare("
            SELECT ac.id_actividad, ac.id_clase, ac.titulo, ac.fecha_publicacion, ac.fecha_apertura,
                   COALESCE(va.fecha_limite_excepcion, ac.fecha_limite) AS fecha_limite,
                   ac.permite_entregas, ac.acepta_retardo, m.nombre_materia,
                   (SELECT COUNT(*) FROM ENTREGAS_ACTIVIDAD ea WHERE ea.id_actividad = ac.id_actividad AND ea.id_alumno = cra.id_alumno) AS entregada
            FROM ACTIVIDADES_CLASE ac
            INNER JOIN CLASE_REGULAR_ALUMNO cra ON cra.id_clase = ac.id_clase AND cra.id_alumno = ? AND cra.acceso = 1
            LEFT JOIN VISIBILIDAD_ACTIVIDAD va ON va.id_actividad = ac.id_actividad AND va.id_alumno = cra.id_alumno
            INNER JOIN CLASES_REGULARES c ON c.id_clase = ac.id_clase AND c.estatus = 'Activa'
            INNER JOIN MATERIAS m ON m.id_materia = c.id_materia
            WHERE ac.visible = 1
              AND (va.visible IS NULL OR va.visible = 1)
              AND (ac.fecha_apertura IS NULL OR ac.fecha_apertura <= NOW())
              AND COALESCE(ac.fecha_apertura, ac.fecha_publicacion) >= NOW() - INTERVAL 30 DAY
        ");
        $st->execute([$id_alumno]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $limite_txt = $r['fecha_limite'] ? ' Fecha límite: ' . date('d/m/Y h:i a', strtotime($r['fecha_limite'])) . '.' : '';
            $vencida = $r['fecha_limite'] && strtotime($r['fecha_limite']) < time();
            $acepta  = (int)$r['acepta_retardo'] === 1;
            if ((int)$r['entregada'] > 0)        $estado = 'entregada';
            elseif (!$vencida)                   $estado = 'abierta';
            elseif ($acepta)                     $estado = 'retardo';   // venció pero aún recibe con retardo
            else                                 $estado = 'cerrada';   // venció y no acepta retardos
            $color = $estado === 'cerrada' ? 'red' : ($estado === 'retardo' ? 'amber' : ($estado === 'entregada' ? 'green' : 'blue'));
            $titulo = ($estado === 'cerrada' ? 'Actividad cerrada en ' : 'Nueva actividad en ') . $r['nombre_materia'];
            $agregar($r['fecha_apertura'] ?: $r['fecha_publicacion'], $estado === 'cerrada' ? 'fas fa-lock' : 'fas fa-book-open', $color,
                $titulo, '"' . $r['titulo'] . '".' . $limite_txt,
                'aula_virtual.php?id=' . (int)$r['id_clase'],
                ['estado' => $estado, 'acepta_retardo' => (int)$r['permite_entregas'] === 1 ? $acepta : null, 'fecha_limite' => $r['fecha_limite'],
                 'clave' => 'act:' . (int)$r['id_actividad']]);
        }
    } catch (PDOException $e) { error_log('Avisos/actividades: ' . $e->getMessage()); }

    try {
        // 2. Entregas revisadas por el docente
        $st = $pdo->prepare("
            SELECT ea.id_entrega, ea.fecha_entrega, ea.estatus_entrega, ea.retroalimentacion, ea.calificacion, ea.fecha_revision, ac.titulo, ac.id_clase
            FROM ENTREGAS_ACTIVIDAD ea
            INNER JOIN ACTIVIDADES_CLASE ac ON ac.id_actividad = ea.id_actividad
            WHERE ea.id_alumno = ? AND (ea.revisada = 1 OR (ea.retroalimentacion IS NOT NULL AND ea.retroalimentacion <> ''))
            ORDER BY COALESCE(ea.fecha_revision, ea.fecha_entrega) DESC LIMIT 10
        ");
        $st->execute([$id_alumno]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $texto = $r['calificacion'] !== null ? 'Calificación: ' . calificacion_texto($r['calificacion']) . ' / 10.' : 'Entrega ' . mb_strtolower($r['estatus_entrega']) . '.';
            if (!empty($r['retroalimentacion'])) $texto .= ' Comentario: ' . $r['retroalimentacion'];
            $agregar($r['fecha_revision'] ?: $r['fecha_entrega'], 'fas fa-comment-dots', 'green', 'Tu docente revisó "' . $r['titulo'] . '"',
                $texto, 'aula_virtual.php?id=' . (int)$r['id_clase'], ['clave' => 'rev:' . (int)$r['id_entrega']]);
        }
    } catch (PDOException $e) { error_log('Avisos/entregas: ' . $e->getMessage()); }

    try {
        // 3. Reportes de conducta (últimos 60 días)
        $st = $pdo->prepare("
            SELECT r.id_reporte, r.fecha_hora, r.puntos_descontados, r.puntos_restantes, tf.numero_reglamento, tf.descripcion_falta,
                   CASE r.tipo_emisor WHEN 'maestro' THEN (SELECT CONCAT('el docente ', m.nombre, ' ', m.apellido_paterno) FROM MAESTROS m WHERE m.id_maestro = r.id_emisor)
                        WHEN 'prefecto' THEN (SELECT CONCAT('el prefecto ', p.nombre, ' ', p.apellido_paterno) FROM PREFECTOS p WHERE p.id_prefecto = r.id_emisor)
                        ELSE 'Administración' END AS emisor
            FROM REPORTES r INNER JOIN TIPOS_FALTA tf ON tf.id_tipo_falta = r.id_tipo_falta
            WHERE r.id_alumno = ? AND r.fecha_hora >= NOW() - INTERVAL 60 DAY
        ");
        $st->execute([$id_alumno]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $agregar($r['fecha_hora'], 'fas fa-triangle-exclamation', 'red', 'Reporte de conducta (Art. ' . (int)$r['numero_reglamento'] . ')',
                mb_strimwidth($r['descripcion_falta'], 0, 140, '…') . ($r['emisor'] ? ' Reportado por ' . $r['emisor'] . '.' : '') . ' Se descontaron ' . (int)$r['puntos_descontados'] . ' puntos; te quedan ' . (int)$r['puntos_restantes'] . '.',
                'reglamentos.php', ['clave' => 'rep:' . (int)$r['id_reporte']]);
        }
    } catch (PDOException $e) { error_log('Avisos/reportes: ' . $e->getMessage()); }

    try {
        // 4. Resumen de asistencia del mes (solo si hay retardos o faltas)
        $st = $pdo->prepare("
            SELECT SUM(estatus = 'Retardo') AS retardos, SUM(estatus = 'Falta') AS faltas, MAX(fecha_hora_escaneo) AS ultima
            FROM ASISTENCIAS
            WHERE id_alumno = ? AND tipo_registro = 'Entrada'
              AND YEAR(fecha_hora_escaneo) = YEAR(CURDATE()) AND MONTH(fecha_hora_escaneo) = MONTH(CURDATE())
        ");
        $st->execute([$id_alumno]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        // Los días de clase sin ningún registro de entrada también son faltas (misma regla que Mis Asistencias)
        require_once __DIR__ . '/../../includes/asistencia.php';
        $dias_sin = dias_sin_entrada_alumno($pdo, (int)$id_alumno, date('Y-m-01'), date('Y-m-d'));
        if ($r && $dias_sin) {
            $r['faltas'] = (int)$r['faltas'] + count($dias_sin);
            $r['ultima'] = max((string)$r['ultima'], end($dias_sin) . ' 08:00:00');
        }
        if ($r && ((int)$r['retardos'] > 0 || (int)$r['faltas'] > 0)) {
            $agregar($r['ultima'], 'far fa-calendar-xmark', 'amber', 'Tu asistencia de este mes',
                'Llevas ' . (int)$r['retardos'] . ' retardo(s) y ' . (int)$r['faltas'] . ' falta(s) sin justificar este mes.', 'mis_asistencias.php',
                ['clave' => 'asis:' . date('Y-m') . ':' . (int)$r['retardos'] . ':' . (int)$r['faltas']]);
        }
    } catch (PDOException $e) { error_log('Avisos/asistencia: ' . $e->getMessage()); }

    try {
        // 5. Regularizaciones asignadas y activas
        $st = $pdo->prepare("
            SELECT c.id_clase, c.nombre_materia, c.fecha_inicio, c.fecha_fin, ca.fecha_asignacion
            FROM CLASE_ALUMNO ca INNER JOIN CLASES_REGULARIZACION c ON c.id_clase = ca.id_clase
            WHERE ca.id_alumno = ? AND c.estatus = 'Activa' AND c.visible = 1
        ");
        $st->execute([$id_alumno]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $periodo = ($r['fecha_inicio'] && $r['fecha_fin']) ? ' del ' . date('d/m/Y', strtotime($r['fecha_inicio'])) . ' al ' . date('d/m/Y', strtotime($r['fecha_fin'])) : '';
            $agregar($r['fecha_asignacion'] . ' 08:00:00', 'fas fa-star', 'amber', 'Regularización asignada: ' . $r['nombre_materia'],
                'Fuiste inscrito(a) al curso de regularización' . $periodo . '. Revisa los materiales y entregas.', 'espacio_regularizacion.php?id=' . (int)$r['id_clase'],
                ['clave' => 'reg:' . (int)$r['id_clase']]);
        }
    } catch (PDOException $e) { error_log('Avisos/regularizaciones: ' . $e->getMessage()); }

    try {
        // 5b. Justificantes de Prefectura (últimos 30 días)
        $st = $pdo->prepare("
            SELECT id_asistencia, DATE(fecha_hora_escaneo) AS dia, estatus, motivo_justificacion
            FROM ASISTENCIAS
            WHERE id_alumno = ? AND tipo_registro = 'Entrada' AND estatus IN ('Falta Justificada', 'Retardo Justificado')
              AND fecha_hora_escaneo >= CURDATE() - INTERVAL 30 DAY");
        $st->execute([$id_alumno]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $es_falta = $r['estatus'] === 'Falta Justificada';
            $agregar($r['dia'] . ' 08:00:00', 'fas fa-file-signature', 'blue',
                'Prefectura justificó tu ' . ($es_falta ? 'falta' : 'retardo') . ' del ' . date('d/m/Y', strtotime($r['dia'])),
                ($r['motivo_justificacion'] ? 'Motivo: ' . $r['motivo_justificacion'] . '. ' : '')
                    . ($es_falta ? 'Ya no cuenta como falta, y tus faltas en las clases de ese día también quedaron justificadas.' : 'Ya no cuenta como retardo.'),
                'mis_asistencias.php?periodo=' . substr($r['dia'], 0, 7), ['clave' => 'just:' . (int)$r['id_asistencia']]);
        }
    } catch (PDOException $e) { error_log('Avisos/justificantes: ' . $e->getMessage()); }

    try {
        // 6. Suspensiones de clases próximas o en curso
        $st = $pdo->query("SELECT id_suspension, motivo, fecha_inicio, fecha_fin FROM SUSPENSIONES_CLASES WHERE fecha_fin >= CURDATE() ORDER BY fecha_inicio LIMIT 5");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rango = $r['fecha_inicio'] === $r['fecha_fin'] ? 'el ' . date('d/m/Y', strtotime($r['fecha_inicio']))
                   : 'del ' . date('d/m/Y', strtotime($r['fecha_inicio'])) . ' al ' . date('d/m/Y', strtotime($r['fecha_fin']));
            $agregar(date('Y-m-d H:i:s'), 'fas fa-school-circle-xmark', 'gray', 'Suspensión de clases: ' . $r['motivo'], 'No habrá clases ' . $rango . '.', null,
                ['clave' => 'susp:' . (int)$r['id_suspension']]);
        }
    } catch (PDOException $e) { error_log('Avisos/suspensiones: ' . $e->getMessage()); }

    usort($avisos, fn($a, $b) => strcmp((string)$b['fecha'], (string)$a['fecha']));

    // 7. Dato pendiente: correo del tutor (siempre al principio hasta que lo registre)
    if (alumno_falta_correo_tutor($pdo, $id_alumno)) {
        array_unshift($avisos, array_merge(['estado' => null, 'acepta_retardo' => null, 'fecha_limite' => null, 'clave' => null, 'fijo' => true], [
            'fecha' => date('Y-m-d H:i:s'), 'icono' => 'fas fa-envelope', 'color' => 'amber',
            'titulo' => 'Registra el correo de tu padre, madre o tutor',
            'texto' => 'La escuela lo usará para avisarle de faltas y reportes de conducta. Solo toma un minuto.',
            'enlace' => 'perfil.php?completar=tutor',
        ]));
    }
    return array_slice($avisos, 0, $limite);
}

/** Etiquetas (HTML) del estado de una actividad: cerrada / con retardo / entregada / abierta y si acepta retardos. */
function aviso_etiquetas(array $a): string {
    if (empty($a['estado'])) return '';
    $map = [
        'cerrada'   => ['#fef2f2', '#b91c1c', 'fas fa-lock', 'Cerrada: pasó la fecha límite'],
        'retardo'   => ['#fffbeb', '#b45309', 'fas fa-hourglass-half', 'Fecha vencida: se entrega con retardo'],
        'entregada' => ['#ecfdf5', '#047857', 'fas fa-check', 'Ya entregaste'],
        'abierta'   => ['#eff6ff', '#1d4ed8', 'far fa-clock', 'Abierta'],
    ];
    [$bg, $fg, $ic, $txt] = $map[$a['estado']];
    $html = '<span style="display:inline-flex;align-items:center;gap:5px;background:' . $bg . ';color:' . $fg . ';font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:20px;margin-right:6px;"><i class="' . $ic . '"></i> ' . $txt . '</span>';
    if ($a['acepta_retardo'] !== null && $a['estado'] !== 'entregada') {
        $html .= $a['acepta_retardo']
            ? '<span style="display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;color:#334155;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:20px;"><i class="fas fa-circle-check"></i> Acepta retardos</span>'
            : '<span style="display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;color:#334155;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:20px;"><i class="fas fa-ban"></i> No acepta retardos</span>';
    }
    return '<div style="margin:2px 0 6px;">' . $html . '</div>';
}

/** "Hace 5 minutos", "Hace 2 días", etc. */
function tiempo_relativo(?string $fecha): string {
    if (!$fecha) return '';
    $seg = time() - strtotime($fecha);
    if ($seg < 0) return 'Próximamente';
    if ($seg < 60) return 'Hace un momento';
    if ($seg < 3600) return 'Hace ' . floor($seg / 60) . ' min';
    if ($seg < 86400) return 'Hace ' . floor($seg / 3600) . ' h';
    $d = floor($seg / 86400);
    return $d == 1 ? 'Hace 1 día' : ($d < 30 ? "Hace $d días" : date('d/m/Y', strtotime($fecha)));
}


/* =====================================================================
 * Avisos guardados: limpiar la vista sin borrar nada.
 * - El alumno puede ocultar un aviso o limpiar todos; se guardan en AVISOS_GUARDADOS.
 * - Los avisos con más de AVISOS_DIAS_RECIENTES días que ya no piden nada se guardan solos.
 * - Desde "Guardados" se pueden consultar y regresar a la vista principal.
 * ===================================================================== */
const AVISOS_DIAS_RECIENTES = 7;

function avisos_tabla(PDO $pdo): void {
    static $lista = false;
    if ($lista) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS AVISOS_GUARDADOS (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_alumno INT NOT NULL,
        clave VARCHAR(80) NOT NULL,
        oculto TINYINT(1) NOT NULL DEFAULT 1,
        automatico TINYINT(1) NOT NULL DEFAULT 0,
        titulo VARCHAR(255) NOT NULL,
        texto TEXT NULL,
        icono VARCHAR(60) NULL,
        color VARCHAR(20) NULL,
        enlace VARCHAR(255) NULL,
        fecha_aviso DATETIME NULL,
        fecha_guardado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_alumno_clave (id_alumno, clave),
        KEY idx_alumno_oculto (id_alumno, oculto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $lista = true;
}

/** ¿El aviso todavía le pide algo al alumno? (actividad por entregar). Esos no se guardan solos. */
function aviso_pendiente(array $a): bool {
    return !empty($a['fijo']) || in_array($a['estado'] ?? null, ['abierta', 'retardo'], true);
}

/** Guarda uno o varios avisos (ocultos). $automatico = 1 cuando el sistema los guarda por antigüedad. */
function avisos_guardar(PDO $pdo, int $id_alumno, array $avisos, int $automatico = 0): int {
    avisos_tabla($pdo);
    $st = $pdo->prepare("INSERT INTO AVISOS_GUARDADOS (id_alumno, clave, oculto, automatico, titulo, texto, icono, color, enlace, fecha_aviso)
                         VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE " . ($automatico ? "clave = clave" : "oculto = 1, automatico = 0, fecha_guardado = NOW()"));
    $n = 0;
    foreach ($avisos as $a) {
        if (empty($a['clave'])) continue;
        $st->execute([$id_alumno, $a['clave'], $automatico, mb_substr((string)$a['titulo'], 0, 255), $a['texto'], $a['icono'], $a['color'], $a['enlace'], $a['fecha'] ?: null]);
        $n += $st->rowCount() > 0 ? 1 : 0;
    }
    return $n;
}

/**
 * Avisos para la vista principal: guarda solos los viejos que ya no piden nada
 * y quita los que el alumno ocultó.
 */
function avisos_visibles(PDO $pdo, int $id_alumno, array $avisos): array {
    try {
        avisos_tabla($pdo);
        $limite = date('Y-m-d H:i:s', strtotime('-' . AVISOS_DIAS_RECIENTES . ' days'));
        $viejos = array_filter($avisos, fn($a) => !empty($a['clave']) && !aviso_pendiente($a) && $a['fecha'] && $a['fecha'] < $limite);
        if ($viejos) avisos_guardar($pdo, $id_alumno, $viejos, 1);
        $st = $pdo->prepare("SELECT clave FROM AVISOS_GUARDADOS WHERE id_alumno = ? AND oculto = 1");
        $st->execute([$id_alumno]);
        $ocultas = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter($avisos, fn($a) => empty($a['clave']) || !isset($ocultas[$a['clave']])));
    } catch (PDOException $e) {
        error_log('avisos_visibles: ' . $e->getMessage());
        return $avisos;
    }
}

/** Avisos guardados del alumno (los más recientes primero). */
function avisos_guardados(PDO $pdo, int $id_alumno, int $limite = 200): array {
    try {
        avisos_tabla($pdo);
        $st = $pdo->prepare("SELECT clave, automatico, titulo, texto, icono, color, enlace, fecha_aviso AS fecha, fecha_guardado
                             FROM AVISOS_GUARDADOS WHERE id_alumno = ? AND oculto = 1
                             ORDER BY COALESCE(fecha_aviso, fecha_guardado) DESC LIMIT " . (int)$limite);
        $st->execute([$id_alumno]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('avisos_guardados: ' . $e->getMessage());
        return [];
    }
}

/** Regresa un aviso guardado a la vista principal (no se vuelve a guardar solo). */
function avisos_restaurar(PDO $pdo, int $id_alumno, string $clave): void {
    avisos_tabla($pdo);
    $pdo->prepare("UPDATE AVISOS_GUARDADOS SET oculto = 0 WHERE id_alumno = ? AND clave = ?")->execute([$id_alumno, $clave]);
}
