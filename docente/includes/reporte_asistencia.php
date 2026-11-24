<?php
/**
 * docente/includes/reporte_asistencia.php — Reporte de asistencia de una materia (pantalla y AJAX).
 * Usa el mismo periodo que el Excel (descargar_asistencia_excel.php): semestre en curso,
 * un mes de ese semestre o un rango de fechas.
 */

require_once __DIR__ . "/../../includes/asistencia.php";   // fecha_valida()

/** Periodo pedido: ['where' => SQL sobre ac.fecha, 'params' => [...], 'texto' => '...'] */
function reporte_asistencia_periodo(array $get): array {
    $m = (int)date('n'); $y = (int)date('Y');
    if ($m >= 8)      { $ini = "$y-08-01"; $fin = ($y + 1) . '-01-31'; $txt = "Ago $y – Ene " . ($y + 1); }
    elseif ($m === 1) { $ini = ($y - 1) . '-08-01'; $fin = "$y-01-31"; $txt = 'Ago ' . ($y - 1) . " – Ene $y"; }
    else              { $ini = "$y-02-01"; $fin = "$y-07-31"; $txt = "Feb – Jul $y"; }

    $tipo = $get['tipo_filtro'] ?? 'semestre';
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $mes = (int)($get['mes'] ?? 0);
    if ($tipo === 'mes' && $mes >= 1 && $mes <= 12) {
        return ['where' => ' AND MONTH(ac.fecha) = ? AND ac.fecha BETWEEN ? AND ?', 'params' => [$mes, $ini, $fin], 'texto' => 'Mes de ' . $meses[$mes] . " ($txt)"];
    }
    $fi = $get['fecha_inicio'] ?? ''; $ff = $get['fecha_fin'] ?? '';
    if ($tipo === 'parcial' && fecha_valida($fi) && fecha_valida($ff)) {
        if ($fi > $ff) [$fi, $ff] = [$ff, $fi];
        return ['where' => ' AND ac.fecha BETWEEN ? AND ?', 'params' => [$fi, $ff], 'texto' => 'Del ' . date('d/m/Y', strtotime($fi)) . ' al ' . date('d/m/Y', strtotime($ff))];
    }
    return ['where' => ' AND ac.fecha BETWEEN ? AND ?', 'params' => [$ini, $fin], 'texto' => "Semestre $txt"];
}

/** Filas del reporte: un renglón por alumno con presentes, retardos, faltas, justificadas y %. */
function reporte_asistencia_filas(PDO $pdo, int $id_clase, array $periodo): array {
    $st = $pdo->prepare("
        SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno,
               SUM(ac.estatus IN ('Presente', 'Asistencia')) AS presentes,
               SUM(ac.estatus = 'Retardo') AS retardos,
               SUM(ac.estatus IN ('Falta', 'Ausente')) AS faltas,
               SUM(ac.estatus = 'Justificada') AS justificadas
        FROM clase_regular_alumno cra
        INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno
        LEFT JOIN asistencias_clases ac ON ac.id_alumno = a.id_alumno AND ac.id_clase = cra.id_clase {$periodo['where']}
        WHERE cra.id_clase = ?
        GROUP BY a.id_alumno
        ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre");
    $st->execute(array_merge($periodo['params'], [$id_clase]));
    $filas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = (int)$r['presentes']; $re = (int)$r['retardos']; $f = (int)$r['faltas']; $j = (int)$r['justificadas'];
        $total = $p + $re + $f + $j;
        $r['presentes'] = $p; $r['retardos'] = $re; $r['faltas'] = $f; $r['justificadas'] = $j;
        $r['total'] = $total;
        $r['pct'] = $total ? (int)round(($p + $re + $j) * 100 / $total) : null;
        $r['nombre_completo'] = trim($r['apellido_paterno'] . ' ' . $r['apellido_materno'] . ' ' . $r['nombre']);
        $filas[] = $r;
    }
    return $filas;
}

/** Totales para las tarjetas de resumen. */
function reporte_asistencia_resumen(array $filas): array {
    $res = ['alumnos' => count($filas), 'pases' => 0, 'promedio' => null, 'en_riesgo' => 0];
    $suma = 0; $con = 0;
    foreach ($filas as $f) {
        $res['pases'] = max($res['pases'], $f['total']);
        if ($f['pct'] !== null) { $suma += $f['pct']; $con++; if ($f['pct'] < 80) $res['en_riesgo']++; }
    }
    $res['promedio'] = $con ? (int)round($suma / $con) : null;
    return $res;
}

/** HTML del <tbody>. */
function reporte_asistencia_tbody(array $filas, string $texto_periodo): string {
    if (!$filas) return '<tr><td colspan="6" class="vacio" data-label=""><i class="fas fa-users-slash"></i> No hay alumnos inscritos en esta materia.</td></tr>';
    $h = '';
    if (!array_sum(array_column($filas, 'total'))) {
        $h .= '<tr class="fila-aviso"><td colspan="6" data-label=""><i class="fas fa-circle-info"></i> Todavía no hay pases de lista en: ' . htmlspecialchars($texto_periodo) . '. Cuando pases lista, aquí verás las cuentas de cada alumno.</td></tr>';
    }
    foreach ($filas as $f) {
        $cls = $f['pct'] === null ? 'pct-na' : ($f['pct'] >= 80 ? 'pct-ok' : ($f['pct'] >= 60 ? 'pct-medio' : 'pct-bajo'));
        $h .= '<tr>'
            . '<td data-label="" class="td-alumno"><a href="perfil_alumno.php?id=' . (int)$f['id_alumno'] . '" title="Ver perfil"><strong>' . htmlspecialchars($f['nombre_completo']) . '</strong></a><span>Mat: ' . htmlspecialchars($f['matricula']) . '</span></td>'
            . '<td data-label="Asistencias" class="num"><span class="cifra c-p">' . $f['presentes'] . '</span></td>'
            . '<td data-label="Retardos" class="num"><span class="cifra c-r">' . $f['retardos'] . '</span></td>'
            . '<td data-label="Faltas" class="num"><span class="cifra c-f">' . $f['faltas'] . '</span></td>'
            . '<td data-label="Justificadas" class="num"><span class="cifra c-j">' . $f['justificadas'] . '</span></td>'
            . '<td data-label="Asistencia" class="num"><span class="pct ' . $cls . '">' . ($f['pct'] === null ? '—' : $f['pct'] . '%') . '</span></td>'
            . '</tr>';
    }
    return $h;
}
