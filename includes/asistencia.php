<?php
/**
 * includes/asistencia.php — Une la asistencia de Prefectura (entrada al plantel) con la del docente (pase de lista).
 *
 *  - Prefectura registra la ENTRADA/SALIDA del plantel en ASISTENCIAS (QR o registro manual).
 *  - El docente confirma en ASISTENCIAS_CLASES que el alumno sí estuvo en SU clase.
 *  - Cruzando ambas se detecta a quien entró al plantel pero faltó a una clase ("posible pinta")
 *    y a quien estuvo en clase sin haber registrado su entrada en Prefectura.
 *
 * Estatus del pase de lista del docente: Presente, Retardo, Falta y Justificada
 * (Justificada = falta con justificante de Prefectura; no cuenta como falta).
 */
require_once __DIR__ . '/calendario.php';

const ESTATUS_CLASE = ['Presente', 'Retardo', 'Falta', 'Justificada'];
const DIAS_SEMANA = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

/** ¿Es una fecha real con formato Y-m-d? */
function fecha_valida(?string $f): bool {
    if (!is_string($f) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return false;
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

/** Estatus que guardaban versiones anteriores ("Asistencia", "Ausente") llevados a los actuales. */
function estatus_clase_normal(?string $e): ?string {
    $e = trim((string)$e);
    if ($e === '') return null;
    $mapa = ['asistencia' => 'Presente', 'presente' => 'Presente', 'retardo' => 'Retardo', 'falta' => 'Falta', 'ausente' => 'Falta', 'justificada' => 'Justificada'];
    return $mapa[mb_strtolower($e, 'UTF-8')] ?? null;
}

/**
 * Entrada al plantel de cada alumno en una fecha.
 * @return array<int, array{estatus:string, entrada:?string, salida:?string, motivo:?string}>  (horas en H:i)
 */
function entradas_plantel(PDO $pdo, array $ids, string $fecha): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !fecha_valida($fecha)) return [];
    $in = implode(',', $ids);
    $st = $pdo->prepare("
        SELECT id_alumno,
               SUBSTRING_INDEX(GROUP_CONCAT(estatus ORDER BY fecha_hora_escaneo ASC SEPARATOR '|'), '|', 1) AS estatus,
               MIN(fecha_hora_escaneo) AS entrada, MAX(fecha_hora_salida) AS salida,
               SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(motivo_justificacion, '') ORDER BY fecha_hora_escaneo ASC SEPARATOR '|'), '|', 1) AS motivo
        FROM ASISTENCIAS
        WHERE id_alumno IN ($in) AND tipo_registro = 'Entrada' AND DATE(fecha_hora_escaneo) = ?
        GROUP BY id_alumno");
    $st->execute([$fecha]);
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sin_hora = in_array($r['estatus'], ['Falta Justificada', 'Suspendido'], true) && substr($r['entrada'], 11, 5) === '00:00';
        $res[(int)$r['id_alumno']] = [
            'estatus' => $r['estatus'],
            'entrada' => $sin_hora ? null : substr($r['entrada'], 11, 5),
            'salida'  => $r['salida'] ? substr($r['salida'], 11, 5) : null,
            'motivo'  => $r['motivo'] !== '' ? $r['motivo'] : null,
        ];
    }
    return $res;
}

/** ¿El registro de Prefectura indica que el alumno sí está (o estuvo) en el plantel? */
function entro_al_plantel(?array $e): bool {
    return $e !== null && in_array($e['estatus'], ['Puntual', 'Retardo', 'Retardo Justificado'], true);
}

/** Alumnos con suspensión disciplinaria vigente en esa fecha. @return array<int, string> id => fecha fin */
function suspendidos_disciplina(PDO $pdo, array $ids, string $fecha): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $st = $pdo->prepare("SELECT id_alumno, fecha_fin_suspension FROM ALUMNOS
                         WHERE id_alumno IN (" . implode(',', $ids) . ") AND estado_disciplinario = 'Suspendido'
                           AND fecha_fin_suspension IS NOT NULL AND fecha_fin_suspension >= ?");
    $st->execute([$fecha]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'fecha_fin_suspension', 'id_alumno');
}

/**
 * Horario de una clase para el día de la semana de la fecha.
 * @return array{tiene_horario:bool, toca:bool, inicio:?string, fin:?string}
 */
function horario_clase_dia(PDO $pdo, int $id_clase, string $fecha): array {
    $st = $pdo->prepare("SELECT dia_semana, hora_inicio, hora_fin FROM HORARIOS_CLASES WHERE id_clase = ? ORDER BY hora_inicio");
    $st->execute([$id_clase]);
    $bloques = $st->fetchAll(PDO::FETCH_ASSOC);
    $dia = (int)date('N', strtotime($fecha));
    $hoy = array_values(array_filter($bloques, fn($b) => (int)$b['dia_semana'] === $dia));
    return [
        'tiene_horario' => (bool)$bloques,
        'toca'          => (bool)$hoy,
        'inicio'        => $hoy ? substr($hoy[0]['hora_inicio'], 0, 5) : null,
        'fin'           => $hoy ? substr(end($hoy)['hora_fin'], 0, 5) : null,
    ];
}

/** Texto corto de la situación del alumno en Prefectura para mostrar al docente. */
function texto_entrada_plantel(?array $e, ?string $susp_hasta = null): array {
    if ($susp_hasta) return ['suspendido', 'fa-ban', 'Suspendido por disciplina hasta el ' . date('d/m/Y', strtotime($susp_hasta))];
    if ($e === null) return ['sin', 'fa-door-closed', 'No registró entrada al plantel'];
    $sal = $e['salida'] ? ' · salió ' . $e['salida'] : '';
    switch ($e['estatus']) {
        case 'Puntual':             return ['ok', 'fa-door-open', 'Entró al plantel ' . $e['entrada'] . $sal];
        case 'Retardo':             return ['retardo', 'fa-door-open', 'Entró con retardo ' . $e['entrada'] . $sal];
        case 'Retardo Justificado': return ['ok', 'fa-door-open', 'Entró ' . $e['entrada'] . ' (retardo justificado)' . $sal];
        case 'Falta Justificada':   return ['justificada', 'fa-file-signature', 'Falta justificada por Prefectura' . ($e['motivo'] ? ': ' . $e['motivo'] : '')];
        case 'Suspendido':          return ['suspendido', 'fa-ban', 'Suspendido (registrado en Prefectura)'];
        default:                    return ['falta', 'fa-door-closed', 'Llegó después de la hora de corte' . ($e['entrada'] ? ' (' . $e['entrada'] . ')' : '') . ': falta en Prefectura'];
    }
}

/**
 * Posibles pintas: el alumno entró al plantel pero el docente le puso falta en una clase.
 * Si salió del plantel antes de que empezara la clase, se marca como "salió antes" (no es pinta).
 * @return array<int, array> agrupado por alumno, con la lista de clases a las que faltó
 */
function posibles_pintas(PDO $pdo, string $fecha, int $id_grupo = 0, int $id_maestro = 0): array {
    if (!fecha_valida($fecha)) return [];
    $sql = "SELECT ac.id_alumno, ac.id_clase, a.matricula, CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS alumno,
                   m.nombre_materia, CONCAT_WS(' ', ma.nombre, ma.apellido_paterno) AS docente,
                   g.id_grupo, g.semestre, g.nombre_grupo, g.turno
            FROM ASISTENCIAS_CLASES ac
            INNER JOIN ALUMNOS a ON a.id_alumno = ac.id_alumno AND a.estatus = 'Alta'
            INNER JOIN CLASES_REGULARES c ON c.id_clase = ac.id_clase
            INNER JOIN MATERIAS m ON m.id_materia = c.id_materia
            LEFT JOIN MAESTROS ma ON ma.id_maestro = c.id_maestro
            LEFT JOIN GRUPOS g ON g.id_grupo = c.id_grupo
            WHERE ac.fecha = ? AND ac.estatus IN ('Falta', 'Ausente')";
    $p = [$fecha];
    if ($id_grupo > 0)   { $sql .= " AND c.id_grupo = ?";   $p[] = $id_grupo; }
    if ($id_maestro > 0) { $sql .= " AND c.id_maestro = ?"; $p[] = $id_maestro; }
    $st = $pdo->prepare($sql . " ORDER BY a.apellido_paterno, a.nombre");
    $st->execute($p);
    $faltas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$faltas) return [];

    $entradas = entradas_plantel($pdo, array_column($faltas, 'id_alumno'), $fecha);
    $res = [];
    foreach ($faltas as $f) {
        $id = (int)$f['id_alumno'];
        $e = $entradas[$id] ?? null;
        if (!entro_al_plantel($e)) continue;          // no entró al plantel: es una falta normal, no pinta
        $h = horario_clase_dia($pdo, (int)$f['id_clase'], $fecha);
        $salio_antes = $e['salida'] && $h['inicio'] && $e['salida'] <= $h['inicio'];
        if (!isset($res[$id])) {
            $res[$id] = ['id_alumno' => $id, 'matricula' => $f['matricula'], 'alumno' => $f['alumno'],
                         'grupo' => $f['semestre'] ? $f['semestre'] . '° "' . $f['nombre_grupo'] . '"' : 'Sin grupo', 'id_grupo' => (int)$f['id_grupo'],
                         'turno' => $f['turno'], 'entrada' => $e['entrada'], 'salida' => $e['salida'], 'clases' => [], 'pinta' => false];
        }
        $res[$id]['clases'][] = ['materia' => $f['nombre_materia'], 'docente' => $f['docente'], 'id_clase' => (int)$f['id_clase'],
                                 'hora' => $h['inicio'] ? $h['inicio'] . '–' . $h['fin'] : null, 'salio_antes' => $salio_antes];
        if (!$salio_antes) $res[$id]['pinta'] = true;
    }
    return $res;
}

/**
 * Alumnos que el docente marcó presentes (o con retardo) pero que no registraron su entrada en Prefectura.
 * Sirve para que Prefectura corrija el registro (entraron sin pasar su credencial).
 */
function presentes_sin_entrada(PDO $pdo, string $fecha, int $id_grupo = 0): array {
    if (!fecha_valida($fecha)) return [];
    $sql = "SELECT ac.id_alumno, a.matricula, CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS alumno,
                   GROUP_CONCAT(DISTINCT m.nombre_materia ORDER BY m.nombre_materia SEPARATOR ', ') AS materias,
                   MIN(g.semestre) AS semestre, MIN(g.nombre_grupo) AS nombre_grupo
            FROM ASISTENCIAS_CLASES ac
            INNER JOIN ALUMNOS a ON a.id_alumno = ac.id_alumno AND a.estatus = 'Alta'
            INNER JOIN CLASES_REGULARES c ON c.id_clase = ac.id_clase
            INNER JOIN MATERIAS m ON m.id_materia = c.id_materia
            LEFT JOIN GRUPOS g ON g.id_grupo = c.id_grupo
            WHERE ac.fecha = ? AND ac.estatus IN ('Presente', 'Asistencia', 'Retardo')";
    $p = [$fecha];
    if ($id_grupo > 0) { $sql .= " AND c.id_grupo = ?"; $p[] = $id_grupo; }
    $st = $pdo->prepare($sql . " GROUP BY ac.id_alumno, a.matricula, alumno ORDER BY alumno");
    $st->execute($p);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    $entradas = entradas_plantel($pdo, array_column($filas, 'id_alumno'), $fecha);
    return array_values(array_filter($filas, fn($f) => !entro_al_plantel($entradas[(int)$f['id_alumno']] ?? null)));
}

/**
 * Resumen de asistencia a clases de un alumno, por materia.
 * @return array<int, array{materia:string, docente:?string, presentes:int, retardos:int, faltas:int, justificadas:int, total:int}>
 */
function resumen_clases_alumno(PDO $pdo, int $id_alumno, ?string $desde = null, ?string $hasta = null): array {
    $sql = "SELECT c.id_clase, m.nombre_materia AS materia, CONCAT_WS(' ', ma.nombre, ma.apellido_paterno) AS docente,
                   SUM(ac.estatus IN ('Presente', 'Asistencia')) AS presentes, SUM(ac.estatus = 'Retardo') AS retardos,
                   SUM(ac.estatus IN ('Falta', 'Ausente')) AS faltas, SUM(ac.estatus = 'Justificada') AS justificadas, COUNT(*) AS total
            FROM ASISTENCIAS_CLASES ac
            INNER JOIN CLASES_REGULARES c ON c.id_clase = ac.id_clase
            INNER JOIN MATERIAS m ON m.id_materia = c.id_materia
            LEFT JOIN MAESTROS ma ON ma.id_maestro = c.id_maestro
            WHERE ac.id_alumno = ?";
    $p = [$id_alumno];
    if ($desde && fecha_valida($desde)) { $sql .= " AND ac.fecha >= ?"; $p[] = $desde; }
    if ($hasta && fecha_valida($hasta)) { $sql .= " AND ac.fecha <= ?"; $p[] = $hasta; }
    $st = $pdo->prepare($sql . " GROUP BY c.id_clase, m.nombre_materia, docente ORDER BY m.nombre_materia");
    $st->execute($p);
    return array_map(function ($r) {
        foreach (['presentes', 'retardos', 'faltas', 'justificadas', 'total'] as $k) $r[$k] = (int)$r[$k];
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Cuando Prefectura justifica la falta de un día, las faltas a clases de ese día quedan justificadas también. */
function justificar_clases_del_dia(PDO $pdo, int $id_alumno, string $fecha): int {
    if (!fecha_valida($fecha)) return 0;
    $st = $pdo->prepare("UPDATE ASISTENCIAS_CLASES SET estatus = 'Justificada' WHERE id_alumno = ? AND fecha = ? AND estatus IN ('Falta', 'Ausente')");
    $st->execute([$id_alumno, $fecha]);
    return $st->rowCount();
}

/**
 * Pase de lista de las materias en una fecha: las que tienen clase ese día según su horario
 * (o a las que ya se les pasó lista), con cuántos alumnos marcó el docente.
 */
function pases_de_lista_del_dia(PDO $pdo, string $fecha, int $id_grupo = 0): array {
    if (!fecha_valida($fecha)) return [];
    $dia = (int)date('N', strtotime($fecha));
    $sql = "SELECT c.id_clase, m.nombre_materia, CONCAT_WS(' ', ma.nombre, ma.apellido_paterno) AS docente,
                   g.id_grupo, g.semestre, g.nombre_grupo, g.turno,
                   (SELECT MIN(hc.hora_inicio) FROM HORARIOS_CLASES hc WHERE hc.id_clase = c.id_clase AND hc.dia_semana = ?) AS inicio,
                   (SELECT MAX(hc.hora_fin) FROM HORARIOS_CLASES hc WHERE hc.id_clase = c.id_clase AND hc.dia_semana = ?) AS fin,
                   (SELECT COUNT(*) FROM CLASE_REGULAR_ALUMNO cra INNER JOIN ALUMNOS a ON a.id_alumno = cra.id_alumno AND a.estatus = 'Alta' WHERE cra.id_clase = c.id_clase) AS inscritos,
                   (SELECT COUNT(*) FROM ASISTENCIAS_CLASES ac WHERE ac.id_clase = c.id_clase AND ac.fecha = ?) AS marcados,
                   (SELECT COUNT(*) FROM ASISTENCIAS_CLASES ac WHERE ac.id_clase = c.id_clase AND ac.fecha = ? AND ac.estatus IN ('Falta', 'Ausente')) AS faltas
            FROM CLASES_REGULARES c
            INNER JOIN MATERIAS m ON m.id_materia = c.id_materia
            LEFT JOIN MAESTROS ma ON ma.id_maestro = c.id_maestro
            LEFT JOIN GRUPOS g ON g.id_grupo = c.id_grupo
            WHERE c.estatus = 'Activa'
              AND (EXISTS (SELECT 1 FROM HORARIOS_CLASES hc WHERE hc.id_clase = c.id_clase AND hc.dia_semana = ?)
                   OR EXISTS (SELECT 1 FROM ASISTENCIAS_CLASES ac WHERE ac.id_clase = c.id_clase AND ac.fecha = ?))";
    $p = [$dia, $dia, $fecha, $fecha, $dia, $fecha];
    if ($id_grupo > 0) { $sql .= " AND c.id_grupo = ?"; $p[] = $id_grupo; }
    $st = $pdo->prepare($sql . " ORDER BY inicio IS NULL, inicio, g.semestre, g.nombre_grupo, m.nombre_materia");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Días de clase en que el alumno no tiene NINGÚN registro de entrada (faltó todo el día).
 * Misma regla que el concentrado: lunes a viernes, sin suspensiones, hasta hoy y solo mientras pertenecía a un grupo.
 */
function dias_sin_entrada_alumno(PDO $pdo, int $id_alumno, string $desde, string $hasta): array {
    $hasta = min($hasta, date('Y-m-d'));
    // Lo que ya se archivó en un cierre de ciclo no está en ASISTENCIAS: esos días no se pueden contar como falta
    if (($archivado = asistencia_archivada_hasta($pdo)) && $desde <= $archivado) {
        $desde = date('Y-m-d', strtotime($archivado . ' +1 day'));
    }
    if (!fecha_valida($desde) || !fecha_valida($hasta) || $desde > $hasta) return [];
    $st = $pdo->prepare("SELECT ga.fecha_asignacion, ga.fecha_baja FROM GRUPO_ALUMNO ga INNER JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                         WHERE ga.id_alumno = ? AND ga.fecha_asignacion <= ? AND (ga.fecha_baja IS NULL OR ga.fecha_baja > ?)");
    $st->execute([$id_alumno, $hasta, $desde]);
    $periodos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$periodos) return [];
    $st = $pdo->prepare("SELECT DISTINCT DATE(fecha_hora_escaneo) FROM ASISTENCIAS WHERE id_alumno = ? AND tipo_registro = 'Entrada' AND DATE(fecha_hora_escaneo) BETWEEN ? AND ?");
    $st->execute([$id_alumno, $desde, $hasta]);
    $con_registro = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare("SELECT fecha_inicio, fecha_fin FROM SUSPENSIONES_CLASES WHERE fecha_fin >= ? AND fecha_inicio <= ?");
    $st->execute([$desde, $hasta]);
    $susp = $st->fetchAll(PDO::FETCH_ASSOC);
    $dias = [];
    for ($d = new DateTime($desde); $d->format('Y-m-d') <= $hasta; $d->modify('+1 day')) {
        $f = $d->format('Y-m-d');
        if ((int)$d->format('N') > 5 || isset($con_registro[$f])) continue;
        foreach ($susp as $s) if ($f >= $s['fecha_inicio'] && $f <= $s['fecha_fin']) continue 2;
        foreach ($periodos as $p) {
            if ($f >= $p['fecha_asignacion'] && (!$p['fecha_baja'] || $f < $p['fecha_baja'])) { $dias[] = $f; break; }
        }
    }
    return $dias;
}

/** Último día cuyas asistencias de plantel se movieron al archivo en un cierre de ciclo (null si nunca se ha archivado). */
function asistencia_archivada_hasta(PDO $pdo): ?string {
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    try {
        $existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'asistencias_archivo'")->fetchColumn();
        if ($existe) {
            $f = $pdo->query("SELECT DATE(MAX(fecha_hora_escaneo)) FROM ASISTENCIAS_ARCHIVO")->fetchColumn();
            $cache = $f ?: null;
        }
    } catch (PDOException $e) {
        error_log('asistencia_archivada_hasta: ' . $e->getMessage());
    }
    return $cache;
}
