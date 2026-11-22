<?php
/**
 * includes/fotos.php — Fotos de perfil en todo el sistema.
 *  - foto_publica(): URL lista para usar desde cualquier carpeta de primer nivel (admin/, docente/…).
 *  - avatar_html(): la foto si existe; si no, las iniciales (con las clases CSS de cada pantalla).
 *  - reemplazar_foto(): borra del servidor la foto anterior cuando se sube una nueva.
 * Se carga automáticamente desde config/database.php.
 */

/** "uploads/perfiles/x.png" o "../uploads/..." → "../uploads/perfiles/x.png?v=fecha" (o null si no existe). */
function foto_publica(?string $ruta): ?string {
    if (!$ruta) return null;
    $limpia = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $ruta));
    if (strpos($limpia, 'uploads/') !== 0 || strpos($limpia, '..') !== false) return null;
    $abs = __DIR__ . '/../' . $limpia;
    return is_file($abs) ? '../' . $limpia . '?v=' . filemtime($abs) : null;
}

/** Iniciales de un nombre: "Rodrigo Martinez" → "RM". */
function iniciales(string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) $ini .= mb_substr($p, 0, 1, 'UTF-8');
    return mb_strtoupper($ini ?: '?', 'UTF-8');
}

/** Avatar: foto si la hay, iniciales si no. $clases: clases CSS del contenedor de la pantalla. */
function avatar_html(?string $ruta_foto, string $nombre, string $clases = '', string $estilo = ''): string {
    $url = foto_publica($ruta_foto);
    $attr = ' class="' . htmlspecialchars($clases) . '"' . ($estilo !== '' ? ' style="' . htmlspecialchars($estilo) . '"' : '');
    if ($url) {
        return '<div' . $attr . ' data-con-foto="1"><img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($nombre) . '" loading="lazy" '
             . 'style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;"></div>';
    }
    return '<div' . $attr . '>' . htmlspecialchars(iniciales($nombre)) . '</div>';
}

/** Borra la foto anterior del servidor (solo si está dentro de uploads/ y no es la nueva). */
function reemplazar_foto(?string $anterior, ?string $nueva = null): void {
    if (!$anterior) return;
    $a = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $anterior));
    $n = $nueva ? preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $nueva)) : null;
    if ($a === $n || strpos($a, 'uploads/') !== 0 || strpos($a, '..') !== false) return;
    $abs = __DIR__ . '/../' . $a;
    if (is_file($abs)) @unlink($abs);
}

/* ---------- Fotos de perfil normalizadas (todos los usuarios) ---------- */
const FOTO_PERFIL_MAX_MB  = 2;      // peso máximo del archivo que se sube
const FOTO_PERFIL_MIN_PX  = 400;    // lado mínimo aceptado
const FOTO_PERFIL_MAX_PX  = 1080;   // lado máximo guardado (las más grandes se reducen)
const PORTADA_MAX_MB      = 2;      // portadas de materias y regularizaciones

/**
 * Valida y guarda una foto de perfil: JPG/PNG/WEBP, máx. 2 MB, mínimo 400×400 px.
 * Se recorta a un cuadrado centrado y se reduce a 1080×1080 px como máximo (JPEG optimizado).
 * Devuelve ['ok' => true, 'ruta' => 'uploads/…'] o ['ok' => false, 'error' => 'mensaje'].
 */
function procesar_foto_perfil(?array $f, string $carpeta, string $prefijo): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => 'Selecciona una imagen.'];
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > FOTO_PERFIL_MAX_MB * 1024 * 1024) {
        return ['ok' => false, 'error' => 'La foto no debe pesar más de ' . FOTO_PERFIL_MAX_MB . ' MB.'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'No se pudo subir la imagen. Intenta de nuevo.'];
    $info = @getimagesize($f['tmp_name']);
    $tipos = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($tipos[$info['mime']])) return ['ok' => false, 'error' => 'Formato no válido. Usa una imagen JPG, PNG o WEBP.'];
    [$w, $h] = $info;
    if (min($w, $h) < FOTO_PERFIL_MIN_PX) {
        return ['ok' => false, 'error' => 'La foto es muy pequeña (' . $w . ' × ' . $h . ' px). Debe medir al menos ' . FOTO_PERFIL_MIN_PX . ' × ' . FOTO_PERFIL_MIN_PX . ' px.'];
    }
    $crear = 'imagecreatefrom' . $tipos[$info['mime']];
    if (!function_exists($crear) || !($src = @$crear($f['tmp_name']))) return ['ok' => false, 'error' => 'No se pudo leer la imagen.'];

    // Respetar la orientación de fotos tomadas con el celular
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($f['tmp_name']);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) { $src = imagerotate($src, $rot, 0); $w = imagesx($src); $h = imagesy($src); }
    }

    // Recorte cuadrado centrado y redimensión a máximo 1080 px
    $lado = min($w, $h);
    $destino = min($lado, FOTO_PERFIL_MAX_PX);
    $dst = imagecreatetruecolor($destino, $destino);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // fondo blanco para PNG con transparencia
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $lado) / 2), (int)(($h - $lado) / 2), $destino, $destino, $lado, $lado);
    imagedestroy($src);

    $dir = __DIR__ . '/../uploads/' . trim($carpeta, '/') . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nombre = $prefijo . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg';
    // Calidad alta; si por algún motivo quedara grande, se baja la calidad
    $calidad = 88;
    do {
        imagejpeg($dst, $dir . $nombre, $calidad);
        clearstatcache(true, $dir . $nombre);
        $calidad -= 8;
    } while (filesize($dir . $nombre) > FOTO_PERFIL_MAX_MB * 1024 * 1024 && $calidad >= 50);
    imagedestroy($dst);
    return ['ok' => true, 'ruta' => 'uploads/' . trim($carpeta, '/') . '/' . $nombre, 'lado' => $destino];
}
