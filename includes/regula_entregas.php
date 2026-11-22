<?php
/**
 * includes/regula_entregas.php
 * Configuración de entregas por actividad en los espacios de regularización:
 * tipos de archivo permitidos, peso máximo, fecha límite, retardos y escala.
 */

/** Grupos de tipos de archivo que el docente puede habilitar. */
const REGULA_TIPOS = [
    'pdf'    => ['PDF',              ['pdf'],                       'fa-file-pdf'],
    'word'   => ['Word',             ['doc', 'docx', 'odt', 'rtf'], 'fa-file-word'],
    'excel'  => ['Excel',            ['xls', 'xlsx', 'csv', 'ods'], 'fa-file-excel'],
    'ppt'    => ['PowerPoint',       ['ppt', 'pptx', 'odp'],        'fa-file-powerpoint'],
    'imagen' => ['Imágenes',         ['jpg', 'jpeg', 'png', 'webp', 'gif'], 'fa-file-image'],
    'zip'    => ['Comprimidos (ZIP)', ['zip', 'rar', '7z'],         'fa-file-zipper'],
    'txt'    => ['Texto (TXT)',      ['txt'],                       'fa-file-lines'],
];

/** Peso máximo que el docente puede configurar (limitado por upload_max_filesize de PHP). */
function regula_mb_maximo_servidor(): int {
    $conv = function ($v) {
        $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n * 1024 : ($u === 'm' ? $n : ($u === 'k' ? $n / 1024 : $n / 1048576));
    };
    $mb = (int)floor(min($conv(ini_get('upload_max_filesize')), $conv(ini_get('post_max_size'))));
    return max(1, min($mb, 100));
}

/** Convierte la lista guardada ("pdf,doc,docx") en arreglo de extensiones. */
function regula_extensiones(?string $tipos): array {
    $ext = array_filter(array_map('trim', explode(',', strtolower((string)$tipos))));
    return $ext ?: ['pdf'];
}

/** Grupos marcados (claves de REGULA_TIPOS) a partir de las extensiones guardadas. */
function regula_grupos_marcados(?string $tipos): array {
    $ext = regula_extensiones($tipos);
    $out = [];
    foreach (REGULA_TIPOS as $clave => [, $lista]) {
        if (array_intersect($lista, $ext)) $out[] = $clave;
    }
    return $out;
}

/** Arma la lista de extensiones a partir de los grupos enviados por el formulario. */
function regula_tipos_desde_post(array $grupos): string {
    $ext = [];
    foreach ($grupos as $g) {
        if (isset(REGULA_TIPOS[$g])) $ext = array_merge($ext, REGULA_TIPOS[$g][1]);
    }
    return $ext ? implode(',', array_unique($ext)) : 'pdf';
}

/** Texto corto: "PDF, Word, Imágenes". */
function regula_tipos_texto(?string $tipos): string {
    $nombres = [];
    foreach (regula_grupos_marcados($tipos) as $g) $nombres[] = REGULA_TIPOS[$g][0];
    return implode(', ', $nombres);
}

/** Valor para el atributo accept del input file: ".pdf,.doc,.docx". */
function regula_accept(?string $tipos): string {
    return implode(',', array_map(fn($e) => '.' . $e, regula_extensiones($tipos)));
}

/** Lee del POST la configuración de entrega de una actividad (crear / editar). */
function regula_config_desde_post(): array {
    $fecha = trim($_POST['fecha_limite'] ?? '');
    $fecha = $fecha !== '' ? date('Y-m-d H:i:s', strtotime($fecha)) : null;
    $mb = (int)($_POST['tamano_maximo_mb'] ?? 10);
    $mb = max(1, min($mb, regula_mb_maximo_servidor()));
    $escala = (int)($_POST['escala_calificacion'] ?? 10) === 100 ? 100 : 10;
    return [
        'permite_entregas'    => !empty($_POST['permite_entregas']) ? 1 : 0,
        'fecha_limite'        => $fecha,
        'acepta_retardo'      => !empty($_POST['acepta_retardo']) ? 1 : 0,
        'tipos_archivo'       => regula_tipos_desde_post((array)($_POST['tipos'] ?? [])),
        'tamano_maximo_mb'    => $mb,
        'escala_calificacion' => $escala,
    ];
}

/**
 * Estado de la entrega para un alumno:
 *  'entregada' | 'abierta' | 'retardo' (venció pero acepta) | 'cerrada' | 'deshabilitada'
 */
function regula_estado_actividad(array $act, ?array $entrega, bool $clase_recibe): string {
    if ($entrega) return 'entregada';
    if (!$clase_recibe || (int)$act['permite_entregas'] !== 1) return 'deshabilitada';
    if (!empty($act['fecha_limite']) && strtotime($act['fecha_limite']) < time()) {
        return (int)$act['acepta_retardo'] === 1 ? 'retardo' : 'cerrada';
    }
    return 'abierta';
}

/** Formatea una calificación: 8.50 -> "8.5" */
function regula_num($v): string {
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}
