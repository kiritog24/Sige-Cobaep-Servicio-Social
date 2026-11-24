<?php
/**
 * includes/reportes_sugeridos.php — Reportes de conducta sugeridos por acumulación.
 *
 * El sistema NO levanta reportes solo: arma una lista de alumnos que juntaron en el mes
 * 3 retardos (Art. 1) o 3 faltas (Art. 11) sin justificar, y Prefectura decide con un clic
 * si levanta el reporte o lo descarta. Cada bloque de 3 se atiende una sola vez.
 *
 *  - Retardos: el primer escaneo del día con estatus "Retardo" (los justificados no cuentan).
 *  - Faltas: días de clases sin entrada o con registro "Falta", con 2 días de espera para que
 *    traigan justificante. Los días sin escaneo solo cuentan si ese día Prefectura sí estuvo
 *    registrando entradas (así un día sin escáner no le pone falta a todo el plantel).
 */
require_once __DIR__ . '/asistencia.php';
require_once __DIR__ . '/avisos_tutor.php';   // faltas_periodo(), retardos_periodo()

const SUGERIDOS_RETARDOS = 3;            // retardos sin justificar en el mes para sugerir un reporte
const SUGERIDOS_FALTAS = 3;              // faltas sin justificar en el mes para sugerir un reporte
const SUGERIDOS_ESPERA_FALTAS_DIAS = 2;  // días de espera para que traigan justificante
const SUGERIDOS_DIA_OPERADO_PCT = 10;    // % de alumnos con entrada registrada para considerar que ese día se usó el escáner

function sugeridos_tabla(PDO $pdo): void {
    static $lista = false;
    if ($lista) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS REPORTES_SUGERIDOS (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_alumno INT NOT NULL,
        tipo ENUM('retardo','falta') NOT NULL,
        mes CHAR(7) NOT NULL,
        bloque SMALLINT NOT NULL,
        accion ENUM('reporte','descartado') NOT NULL,
        id_reporte INT NULL,
        fechas VARCHAR(255) NULL,
        motivo VARCHAR(255) NULL,
        id_prefecto INT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bloque (id_alumno, tipo, mes, bloque),
        KEY idx_mes (mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $lista = true;
}

/** Mes válido 'YYYY-MM' (por defecto el actual). */
function sugeridos_mes(?string $mes): string {
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$mes) ? $mes : date('Y-m');
}

/** Días en que Prefectura sí registró entradas (para no contar como falta un día sin escáner). */
function sugeridos_dias_operados(PDO $pdo, string $desde, string $hasta): array {
    $total = max(1, (int)$pdo->query("SELECT COUNT(*) FROM ALUMNOS WHERE estatus = 'Alta'")->fetchColumn());
    $minimo = max(5, (int)ceil($total * SUGERIDOS_DIA_OPERADO_PCT / 100));
    $st = $pdo->prepare("SELECT DATE(fecha_hora_escaneo) AS dia FROM ASISTENCIAS
                         WHERE tipo_registro = 'Entrada' AND estatus <> 'Falta' AND DATE(fecha_hora_escaneo) BETWEEN ? AND ?
                         GROUP BY DATE(fecha_hora_escaneo) HAVING COUNT(DISTINCT id_alumno) >= ?");
    $st->execute([$desde, $hasta, $minimo]);
    return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Lista de reportes sugeridos del mes.
 * @return array{pendientes: array, atendidos: array, desde: string, hasta_r: string, hasta_f: string}
 */
function reportes_sugeridos(PDO $pdo, string $mes): array {
    sugeridos_tabla($pdo);
    $desde = $mes . '-01';
    $fin = date('Y-m-t', strtotime($desde));
    $hasta_r = min($fin, date('Y-m-d'));
    $hasta_f = min($fin, date('Y-m-d', strtotime('-' . SUGERIDOS_ESPERA_FALTAS_DIAS . ' days')));
    $out = ['pendientes' => [], 'atendidos' => [], 'desde' => $desde, 'hasta_r' => $hasta_r, 'hasta_f' => $hasta_f];
    if ($desde > $hasta_r) return $out;

    $eventos = [];   // [id_alumno]['retardo'|'falta'] = [fechas]
    foreach (retardos_periodo($pdo, $desde, $hasta_r) as $id => $r) $eventos[$id]['retardo'] = $r['fechas'];

    if ($desde <= $hasta_f) {
        $operados = sugeridos_dias_operados($pdo, $desde, $hasta_f);
        $st = $pdo->prepare("SELECT id_alumno, DATE(fecha_hora_escaneo) AS dia FROM ASISTENCIAS
                             WHERE tipo_registro = 'Entrada' AND estatus = 'Falta' AND DATE(fecha_hora_escaneo) BETWEEN ? AND ?");
        $st->execute([$desde, $hasta_f]);
        $registradas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $registradas[(int)$r['id_alumno']][$r['dia']] = true;
        foreach (faltas_periodo($pdo, $desde, $hasta_f) as $id => $f) {
            $fechas = array_values(array_filter($f['fechas'], fn($d) => isset($operados[$d]) || isset($registradas[$id][$d])));
            if ($fechas) $eventos[$id]['falta'] = $fechas;
        }
    }

    $st = $pdo->prepare("SELECT * FROM REPORTES_SUGERIDOS WHERE mes = ? ORDER BY fecha DESC");
    $st->execute([$mes]);
    $hechos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) { $hechos[(int)$h['id_alumno']][$h['tipo']][] = $h; $out['atendidos'][] = $h; }

    $ids = array_unique(array_merge(array_keys($eventos), array_map(fn($h) => (int)$h['id_alumno'], $out['atendidos'])));
    $info = [];
    if ($ids) {
        $st = $pdo->query("SELECT a.id_alumno, a.matricula, a.puntos_conducta,
                                  TRIM(CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre)) AS nombre,
                                  IF(g.id_grupo IS NULL, 'Sin grupo', CONCAT(g.semestre, '° \"', g.nombre_grupo, '\" - ', g.turno)) AS grupo
                           FROM ALUMNOS a
                           LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                           LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                           WHERE a.id_alumno IN (" . implode(',', array_map('intval', $ids)) . ")");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $info[(int)$r['id_alumno']] = $r;
    }
    foreach ($out['atendidos'] as &$h) $h += $info[(int)$h['id_alumno']] ?? ['matricula' => '', 'nombre' => '', 'grupo' => ''];
    unset($h);

    foreach ($eventos as $id => $tipos) {
        foreach ($tipos as $tipo => $fechas) {
            $umbral = $tipo === 'retardo' ? SUGERIDOS_RETARDOS : SUGERIDOS_FALTAS;
            $bloques = intdiv(count($fechas), $umbral);
            $atendidos = count($hechos[$id][$tipo] ?? []);
            if ($bloques <= $atendidos || !isset($info[$id])) continue;
            $bloque = $atendidos + 1;   // el siguiente bloque de 3 que falta atender
            $out['pendientes'][] = $info[$id] + [
                'tipo' => $tipo, 'total' => count($fechas), 'fechas' => $fechas, 'bloque' => $bloque,
                'bloque_fechas' => array_slice($fechas, ($bloque - 1) * $umbral, $umbral),
                'por_atender' => $bloques - $atendidos,
            ];
        }
    }
    usort($out['pendientes'], fn($a, $b) => [$a['grupo'], $a['nombre'], $a['tipo']] <=> [$b['grupo'], $b['nombre'], $b['tipo']]);
    return $out;
}

/** Cuántos reportes sugeridos hay pendientes este mes (se guarda 5 minutos en la sesión para el menú). */
function reportes_sugeridos_pendientes(PDO $pdo, bool $recalcular = false): int {
    $mes = date('Y-m');
    $c = $_SESSION['sugeridos_cache'] ?? null;
    if (!$recalcular && $c && $c['mes'] === $mes && time() - $c['t'] < 300) return (int)$c['n'];
    try { $n = count(reportes_sugeridos($pdo, $mes)['pendientes']); }
    catch (Throwable $e) { error_log('reportes_sugeridos_pendientes: ' . $e->getMessage()); $n = 0; }
    $_SESSION['sugeridos_cache'] = ['mes' => $mes, 't' => time(), 'n' => $n];
    return $n;
}

/** Fechas "03/11, 05/11 y 10/11". */
function sugeridos_fechas_texto(array $fechas): string {
    $f = array_map(fn($d) => date('d/m', strtotime($d)), $fechas);
    return count($f) > 1 ? implode(', ', array_slice($f, 0, -1)) . ' y ' . end($f) : implode('', $f);
}
