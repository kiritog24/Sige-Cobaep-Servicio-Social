<?php
/**
 * estudiante/procesos/credencial_pdf.php — Credencial escolar en PDF real, generada con Dompdf (vendor).
 *
 * Tamaño estándar de credencial CR80 (85.6 × 54 mm, horizontal). Todos los datos (nombre, matrícula,
 * generación, foto) se toman de la base de datos del alumno en sesión. El código QR lo dibuja la página
 * con qrcode.js (no hay librería de QR en vendor) y se recibe como imagen PNG; se valida que lo sea.
 *
 * POST: qr (data:image/png;base64,...), modo = descargar | ver
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'estudiante') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../perfil.php");
    exit();
}

$id_alumno = (int)$_SESSION['id_usuario'];
$st = $pdo->prepare("SELECT a.*, g.semestre AS g_semestre, g.nombre_grupo, g.turno AS g_turno
                     FROM ALUMNOS a
                     LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
                     LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                     WHERE a.id_alumno = ? LIMIT 1");
$st->execute([$id_alumno]);
$al = $st->fetch(PDO::FETCH_ASSOC);
if (!$al) {
    header("Location: ../perfil.php");
    exit();
}

/** Archivo local → data URI (solo imágenes dentro del proyecto). */
function data_uri_local(?string $ruta): ?string {
    if (!$ruta) return null;
    $r = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $ruta));
    if (strpos($r, '..') !== false) return null;
    $abs = __DIR__ . '/../../' . $r;
    if (!is_file($abs)) return null;
    $info = @getimagesize($abs);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        // WEBP u otro: se convierte a JPEG para Dompdf
        if (!$info || !function_exists('imagecreatefromwebp') || $info['mime'] !== 'image/webp') return null;
        $im = imagecreatefromwebp($abs);
        ob_start(); imagejpeg($im, null, 90); $bin = ob_get_clean(); imagedestroy($im);
        return 'data:image/jpeg;base64,' . base64_encode($bin);
    }
    return 'data:' . $info['mime'] . ';base64,' . base64_encode(file_get_contents($abs));
}

/** Foto del alumno reducida a 400 px (cuadrada) para que el PDF pese poco. */
function foto_credencial(?string $ruta): ?string {
    if (!$ruta) return null;
    $r = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $ruta));
    $abs = __DIR__ . '/../../' . $r;
    if (strpos($r, '..') !== false || !is_file($abs)) return null;
    $info = @getimagesize($abs);
    $crear = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$info['mime'] ?? ''] ?? null;
    if (!$crear || !function_exists($crear) || !($src = @$crear($abs))) return data_uri_local($ruta);
    $w = imagesx($src); $hh = imagesy($src); $lado = min($w, $hh); $dest = min(400, $lado);
    $dst = imagecreatetruecolor($dest, $dest);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $lado) / 2), (int)(($hh - $lado) / 2), $dest, $dest, $lado, $lado);
    ob_start(); imagejpeg($dst, null, 88); $bin = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

/** Logotipo PNG reducido (conserva la transparencia); el original pesa varios MB. */
function logo_pequeno(string $ruta, int $alto): ?string {
    $abs = __DIR__ . '/../../' . $ruta;
    if (!is_file($abs) || !($src = @imagecreatefrompng($abs))) return null;
    $w = imagesx($src); $h0 = imagesy($src);
    $nw = max(1, (int)round($w * $alto / $h0));
    $dst = imagecreatetruecolor($nw, $alto);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $alto, $w, $h0);
    ob_start(); imagepng($dst, null, 9); $bin = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return 'data:image/png;base64,' . base64_encode($bin);
}

// QR enviado por la página: debe ser un PNG válido y pequeño
$qr = null;
if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string)($_POST['qr'] ?? ''), $m)) {
    $bin = base64_decode($m[1], true);
    $dim = $bin !== false && strlen($bin) < 300000 ? @getimagesizefromstring($bin) : false;
    if ($dim && $dim['mime'] === 'image/png' && abs($dim[0] - $dim[1]) <= 2) $qr = 'data:image/png;base64,' . base64_encode($bin);
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$anio_ingreso = !empty($al['periodo_ingreso']) && preg_match('/(20\d{2})/', $al['periodo_ingreso'], $mm) ? (int)$mm[1] : (int)date('Y');
$generacion = $anio_ingreso . ' - ' . ($anio_ingreso + 3);
$vigencia = 'AGO ' . $anio_ingreso . ' - JUL ' . ($anio_ingreso + 3);
$carrera = !empty($al['capacitacion']) ? $al['capacitacion'] : 'Bachillerato General';
$nombre = trim($al['nombre'] . ' ' . $al['apellido_paterno']);
$iniciales = mb_strtoupper(mb_substr($al['nombre'], 0, 1) . mb_substr($al['apellido_paterno'], 0, 1));
$foto = foto_credencial($al['fotografia'] ?? null);
$logo = logo_pequeno('img/logo-blanco.png', 120);
$folio = '27-' . $al['matricula'];
$grupo = $al['nombre_grupo'] ? $al['g_semestre'] . '° "' . $al['nombre_grupo'] . '" · ' . $al['g_turno'] : $al['turno'];
$activo = $al['estatus'] === 'Alta';

$html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
    @page { margin: 0; }
    * { margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; color: #0f172a; width: 85.6mm; height: 54mm; }
    .tarjeta { position: absolute; left: 0; top: 0; width: 85.6mm; height: 54mm; background: #ffffff; }
    .cab { position: absolute; left: 0; top: 0; width: 85.6mm; height: 10.5mm; background: #063f2b; }
    .cab img { position: absolute; left: 3.2mm; top: 2.6mm; height: 5.4mm; }
    .cab .marca { position: absolute; left: 9.6mm; top: 3.1mm; color: #fff; font-weight: bold; font-size: 9pt; letter-spacing: .3pt; }
    .cab .plantel { position: absolute; right: 3.2mm; top: 1.9mm; text-align: right; color: #fff; font-weight: bold; font-size: 7pt; letter-spacing: .4pt; }
    .cab .plantel span { display: block; color: #a7f3d0; font-weight: normal; font-size: 5.6pt; letter-spacing: 0; margin-top: .4mm; }
    .foto { position: absolute; left: 3.2mm; top: 17mm; width: 16mm; height: 16mm; border-radius: 8mm; border: .6mm solid #e2e8f0; overflow: hidden; background: #e2e8f0; }
    .foto img { width: 16mm; height: 16mm; border-radius: 8mm; }
    .ini { width: 16mm; height: 16mm; line-height: 16mm; text-align: center; font-weight: bold; font-size: 15pt; color: #0f172a; }
    .datos { position: absolute; left: 22.4mm; top: 14.2mm; width: 37mm; }
    .nombre { font-weight: bold; font-size: 9.2pt; line-height: 1.12; text-transform: uppercase; color: #0f172a; }
    .rol { margin-top: 1.4mm; font-size: 6.2pt; font-weight: bold; }
    .badge { display: inline-block; font-size: 4.8pt; font-weight: bold; padding: .45mm 1.3mm; border-radius: .8mm; margin-left: 1mm; }
    .badge.ok { background: #ecfdf5; color: #10b981; } .badge.no { background: #fef2f2; color: #dc2626; }
    table.info { margin-top: 1.6mm; border-collapse: collapse; }
    table.info td { font-size: 5.6pt; padding: .35mm 0; vertical-align: top; }
    table.info td.l { color: #64748b; width: 13mm; }
    table.info td.v { color: #0f172a; }
    table.info td.b { font-weight: bold; }
    .qr { position: absolute; right: 2.6mm; top: 13.6mm; width: 21mm; text-align: center; }
    .qr img { width: 18mm; height: 18mm; margin: 0 auto; }
    .qr .ftit { margin-top: .8mm; font-size: 4.6pt; color: #64748b; letter-spacing: .3pt; }
    .qr .folio { font-size: 5pt; font-weight: bold; color: #0f172a; margin-top: .3mm; white-space: nowrap; }
    .pie { position: absolute; left: 0; bottom: 0; width: 85.6mm; height: 6.2mm; background: #063f2b; color: #fff; font-weight: bold; font-size: 6.4pt; }
    .pie span { position: absolute; left: 3.2mm; top: 1.7mm; }
    .pie em { position: absolute; right: 3.2mm; top: 1.9mm; font-style: normal; font-weight: normal; font-size: 5pt; color: #a7f3d0; }
</style></head><body><div class="tarjeta">
    <div class="cab">' . ($logo ? '<img src="' . $logo . '">' : '') . '<div class="marca">COBAEP</div>
        <div class="plantel">PLANTEL 27<span>Zaragoza, Puebla</span></div></div>
    <div class="foto">' . ($foto ? '<img src="' . $foto . '">' : '<div class="ini">' . $h($iniciales) . '</div>') . '</div>
    <div class="datos">
        <div class="nombre">' . $h($nombre) . '</div>
        <div class="rol">Alumno <span class="badge ' . ($activo ? 'ok' : 'no') . '">' . ($activo ? 'ACTIVO' : $h(mb_strtoupper($al['estatus']))) . '</span></div>
        <table class="info">
            <tr><td class="l">Matrícula:</td><td class="v">' . $h($al['matricula']) . '</td></tr>
            <tr><td class="l">Generación:</td><td class="v">' . $h($generacion) . '</td></tr>
            <tr><td class="l">Grupo:</td><td class="v">' . $h($grupo) . '</td></tr>
            <tr><td class="l">Carrera:</td><td class="v b">' . $h($carrera) . '</td></tr>
        </table>
    </div>
    <div class="qr">' . ($qr ? '<img src="' . $qr . '">' : '<div style="width:18mm;height:18mm;border:.3mm dashed #cbd5e1;font-size:5pt;color:#94a3b8;line-height:18mm">QR</div>') . '
        <div class="ftit">FOLIO</div><div class="folio">' . $h($folio) . '</div></div>
    <div class="pie"><span>Vigencia: ' . $h($vigencia) . '</span><em>Credencial escolar</em></div>
</div></body></html>';

$opt = new Options();
$opt->set('isRemoteEnabled', false);
$opt->set('isPhpEnabled', false);
$opt->set('defaultFont', 'DejaVu Sans');
$opt->set('dpi', 300);
$opt->set('isFontSubsettingEnabled', true);   // solo los caracteres usados: el PDF pesa mucho menos
$dompdf = new Dompdf($opt);
$dompdf->loadHtml($html, 'UTF-8');
// 85.6 × 54 mm en puntos (1 mm = 2.8346 pt), horizontal
$dompdf->setPaper([0, 0, 85.6 * 72 / 25.4, 54 * 72 / 25.4]);
$dompdf->render();
$dompdf->addInfo('Title', 'Credencial escolar · COBAEP Plantel 27');
$dompdf->addInfo('Author', 'SIGE COBAEP Plantel 27');
$dompdf->stream('Credencial_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $al['matricula']) . '.pdf',
    ['Attachment' => ($_POST['modo'] ?? 'descargar') !== 'ver']);
exit();
