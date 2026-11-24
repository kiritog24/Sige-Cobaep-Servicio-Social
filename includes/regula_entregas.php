<?php
/**
 * includes/regula_entregas.php
 * Configuración de entregas por actividad en los espacios de regularización:
 * tipos de archivo permitidos, peso máximo, fecha límite, retardos y escala.
 */

/** Grupos de tipos de archivo que el docente puede habilitar. */
const REGULA_TIPOS = [
    'pdf'    => ['PDF',              ['pdf'],                       'fa-file-pdf'],
    'word'   => ['Word',             ['doc', 'docx'],               'fa-file-word'],
    'excel'  => ['Excel',            ['xls', 'xlsx', 'csv'],        'fa-file-excel'],
    'ppt'    => ['PowerPoint',       ['ppt', 'pptx'],               'fa-file-powerpoint'],
    'imagen' => ['Imágenes',         ['jpg', 'jpeg', 'png', 'webp'], 'fa-file-image'],
    'audio'  => ['Audio',            ['mp3', 'm4a', 'ogg'],         'fa-file-audio'],
    'zip'    => ['Comprimidos',      ['zip', 'rar'],                'fa-file-zipper'],
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
    // Solo las extensiones del catálogo actual (las retiradas, como gif o 7z, ya no se aceptan)
    $catalogo = array_merge(...array_map(fn($t) => $t[1], array_values(REGULA_TIPOS)));
    $ext = array_values(array_intersect(array_filter(array_map('trim', explode(',', strtolower((string)$tipos)))), $catalogo));
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

/* ---------- Color del título de cada actividad (lo elige el docente) ---------- */
const REGULA_COLORES = [
    '#0f172a' => 'Negro', '#047857' => 'Verde', '#1d4ed8' => 'Azul', '#7c3aed' => 'Morado',
    '#be123c' => 'Rojo', '#c2410c' => 'Naranja', '#0e7490' => 'Turquesa', '#a16207' => 'Dorado',
];
const REGULA_COLOR_DEFECTO = '#0f172a';

/** Color válido (#rrggbb) o el de por defecto. */
function regula_color_valido($c): string {
    $c = strtolower(trim((string)$c));
    if (!preg_match('/^#[0-9a-f]{6}$/', $c)) return REGULA_COLOR_DEFECTO;
    return regula_color_legible($c) ? $c : REGULA_COLOR_DEFECTO;
}

/** ¿Se lee bien sobre fondo blanco? (contraste mínimo 3:1, el de los títulos grandes) */
function regula_color_legible(string $hex): bool {
    $lin = function (int $v): float { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
    [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));
    $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    return (1.05 / ($l + 0.05)) >= 3;
}

/** Crea la columna color_titulo si la base aún no la tiene. */
function regula_asegurar_columnas(PDO $pdo): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $cols = array_map('strtolower', $pdo->query("SHOW COLUMNS FROM ACTIVIDADES_REGULARIZACION")->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array('color_titulo', $cols, true)) {
            $pdo->exec("ALTER TABLE ACTIVIDADES_REGULARIZACION ADD COLUMN color_titulo VARCHAR(7) NULL DEFAULT NULL AFTER titulo");
        }
    } catch (PDOException $e) { error_log('regula_asegurar_columnas: ' . $e->getMessage()); }
}

/** Selector de color (paleta + color libre) para los formularios del docente. */
function regula_campo_color(string $prefijo, string $valor = REGULA_COLOR_DEFECTO): void {
    $valor = regula_color_valido($valor);
    $en_paleta = isset(REGULA_COLORES[$valor]); ?>
    <div class="color-titulo" data-prefijo="<?php echo $prefijo; ?>">
        <?php foreach (REGULA_COLORES as $hex => $nombre): ?>
            <label class="color-chip" title="<?php echo $nombre; ?>">
                <input type="radio" name="color_titulo" value="<?php echo $hex; ?>" <?php echo $hex === $valor ? 'checked' : ''; ?>>
                <span style="background:<?php echo $hex; ?>;"></span>
            </label>
        <?php endforeach; ?>
        <label class="color-chip color-libre" title="Otro color">
            <input type="radio" name="color_titulo" value="<?php echo $en_paleta ? '#334155' : $valor; ?>" class="color-libre-radio" <?php echo $en_paleta ? '' : 'checked'; ?>>
            <input type="color" class="color-libre-input" value="<?php echo $en_paleta ? '#334155' : $valor; ?>" aria-label="Elegir otro color">
        </label>
        <span class="color-muestra" style="color:<?php echo $valor; ?>;">Así se verá el título</span>
    </div>
<?php }
