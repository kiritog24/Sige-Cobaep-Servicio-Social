<?php
/**
 * includes/calendario.php — Días sin clases (fines de semana y suspensiones de Prefectura).
 *
 * En un día sin clases nadie debe aparecer con falta: lo usan el panel de Prefectura,
 * el monitoreo de grupos y la lista diaria. El concentrado y los avisos a tutores
 * aplican la misma regla en sus propios cálculos por periodo.
 */

/** Motivos permitidos (los mismos valores que acepta la columna SUSPENSIONES_CLASES.motivo). */
const MOTIVOS_SUSPENSION = ['Consejo Técnico', 'Suspensión oficial', 'Vacaciones', 'Fuerza Mayor'];

/**
 * Si la fecha (Y-m-d) no tiene clases, devuelve el motivo ("Fin de semana", "Vacaciones", ...).
 * Si es un día de clases normal, devuelve null.
 */
function dia_sin_clases(PDO $pdo, string $fecha): ?string {
    $ts = strtotime($fecha);
    if ($ts === false) return null;
    if ((int)date('N', $ts) > 5) return 'Fin de semana';
    try {
        $st = $pdo->prepare("SELECT motivo FROM SUSPENSIONES_CLASES WHERE ? BETWEEN fecha_inicio AND fecha_fin ORDER BY id_suspension DESC LIMIT 1");
        $st->execute([date('Y-m-d', $ts)]);
        $m = $st->fetchColumn();
        return $m === false ? null : (string)$m;
    } catch (PDOException $e) {
        error_log('dia_sin_clases: ' . $e->getMessage());
        return null;
    }
}

/** Suspensiones vigentes o próximas (para mostrarlas y poder cancelarlas). */
function suspensiones_proximas(PDO $pdo, int $limite = 10): array {
    try {
        $st = $pdo->prepare("SELECT id_suspension, motivo, fecha_inicio, fecha_fin FROM SUSPENSIONES_CLASES
                             WHERE fecha_fin >= CURDATE() ORDER BY fecha_inicio ASC LIMIT " . max(1, $limite));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/** "22/11/2026" o "22/11/2026 al 24/11/2026". */
function rango_fechas_texto(string $inicio, string $fin): string {
    $a = date('d/m/Y', strtotime($inicio));
    return $inicio === $fin ? $a : $a . ' al ' . date('d/m/Y', strtotime($fin));
}

/**
 * Vigencia de la credencial del alumno: el semestre en curso (Ago–Ene o Feb–Jul).
 * Se renueva sola cada semestre, en lugar de quedarse fija en el año de ingreso.
 */
function vigencia_credencial_alumno(?string $fecha = null): string {
    $t = $fecha ? strtotime($fecha) : time();
    $m = (int)date('n', $t);
    $y = (int)date('Y', $t);
    if ($m >= 8)  return "AGO $y – ENE " . ($y + 1);
    if ($m === 1) return 'AGO ' . ($y - 1) . " – ENE $y";
    return "FEB – JUL $y";
}

/** Nombre completo del alumno: nombre(s) y ambos apellidos. */
function nombre_completo_alumno(array $a): string {
    return trim(preg_replace('/\s+/', ' ', ($a['nombre'] ?? '') . ' ' . ($a['apellido_paterno'] ?? '') . ' ' . ($a['apellido_materno'] ?? '')));
}


/**
 * Tramos de la entrada al plantel de un turno (HORARIOS_TURNO), para mostrarlos igual en todo el sistema:
 * puntual de la hora de entrada al fin de la tolerancia, retardo hasta la hora de corte y después falta.
 */
function tramos_entrada_turno(array $t): array {
    $ent = strtotime($t['hora_entrada']); $tol = strtotime($t['fin_tolerancia']); $corte = strtotime($t['hora_corte']);
    $hm = fn($ts) => date('h:i a', $ts);
    return [
        'tolerancia_min' => (int)round((strtotime(date('H:i', $tol)) - strtotime(date('H:i', $ent))) / 60),
        'puntual' => $hm($ent) . ' a ' . $hm($tol),
        'retardo' => $hm($tol + 1) . ' a ' . $hm($corte),
        'falta'   => $hm(strtotime(date('H:i', $corte)) + 60),
    ];
}
