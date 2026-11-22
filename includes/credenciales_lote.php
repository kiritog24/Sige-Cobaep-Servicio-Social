<?php
/**
 * includes/credenciales_lote.php — Contraseñas provisionales de alumnos generadas en bloque
 * (al importar una lista o al regenerarlas por grupo desde el Directorio).
 *
 *  - Cada alumno recibe una contraseña provisional ÚNICA generada por el sistema.
 *  - En la base de datos solo se guarda el hash; el texto plano vive temporalmente en la sesión
 *    del administrativo para que pueda descargar las fichas (PDF) o la lista (Excel).
 *  - Al descartar el lote, cerrar sesión o pasar CREDENCIALES_LOTE_HORAS, ya no se puede volver a descargar:
 *    si se pierde, se regeneran (solo para los alumnos que aún no cambian su contraseña).
 */

const IMPORTACION_DIAS = 30;          // vigencia de la contraseña provisional entregada en las listas
const CREDENCIALES_LOTE_HORAS = 8;    // tiempo que el lote queda disponible para descargar en la sesión

/**
 * Guarda en la sesión el lote recién generado. $alumnos: [matricula, nombre, grupo, password]
 * (opcional 'etiqueta': texto del campo usuario en la ficha, p. ej. "USUARIO" para el personal).
 * $clave: 'credenciales_lote' (importación / regeneración) o 'credenciales_alta' (Nuevo Registro).
 */
function credenciales_guardar_lote(array $alumnos, string $origen, string $expira, string $clave = 'credenciales_lote'): void {
    usort($alumnos, fn($a, $b) => [$a['grupo'], $a['nombre']] <=> [$b['grupo'], $b['nombre']]);
    $_SESSION[$clave] = [
        'origen'  => $origen,
        'creado'  => time(),
        'expira'  => $expira,
        'alumnos' => array_values($alumnos),
    ];
}

/** Devuelve el lote vigente de la sesión o null. */
function credenciales_lote(string $clave = 'credenciales_lote'): ?array {
    $l = $_SESSION[$clave] ?? null;
    if (!$l || empty($l['alumnos'])) return null;
    if (time() - (int)$l['creado'] > CREDENCIALES_LOTE_HORAS * 3600) {
        unset($_SESSION[$clave]);
        return null;
    }
    return $l;
}

/** Fecha de vencimiento (texto) y valor SQL para una contraseña de importación. */
function credenciales_expira_texto(PDO $pdo): string {
    return (string)$pdo->query("SELECT DATE_FORMAT(NOW() + INTERVAL " . IMPORTACION_DIAS . " DAY, '%d/%m/%Y')")->fetchColumn();
}

/** Nombre del grupo como se muestra al alumno, p. ej. "1° A · Matutino". */
function credenciales_grupo_txt(?int $semestre, ?string $grupo, ?string $turno): string {
    if (!$grupo) return 'Sin grupo';
    return trim(($semestre ? $semestre . '° ' : '') . $grupo . ($turno ? ' · ' . $turno : ''));
}

/** Dirección de inicio de sesión que se imprime en las fichas. */
function credenciales_url_login(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http') . '://' . $host . seguridad_base_url() . '/login.php';
}
