<?php
/**
 * includes/credencial_personal.php — Credencial con foto para el personal (docentes, prefectos y administrativos).
 * Mismo formato que la credencial del alumno: tamaño CR80 (85.6 × 54 mm), una credencial por página
 * (sirve para imprimir en impresora de credenciales PVC o en hoja y recortar).
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/archivo_ciclo.php';   // ciclo_de()

use Dompdf\Dompdf;
use Dompdf\Options;

const CRED_PERSONAL = [
    'maestros'  => ['tabla' => 'MAESTROS',        'id' => 'id_maestro',  'puesto' => 'Docente',        'clave' => 'DOC',  'color' => '#0e7490', 'rol' => 'docente'],
    'prefectos' => ['tabla' => 'PREFECTOS',       'id' => 'id_prefecto', 'puesto' => 'Prefectura',     'clave' => 'PREF', 'color' => '#b45309', 'rol' => 'prefectos'],
    'admin'     => ['tabla' => 'ADMINISTRATIVOS', 'id' => 'id_admin',    'puesto' => 'Administrativo', 'clave' => 'ADM',  'color' => '#6d28d9', 'rol' => 'administrativo'],
];

/** Texto que lleva el QR de la credencial (identifica a la persona sin datos sensibles). */
function credencial_qr_texto(string $tipo, array $p): string {
    return 'SIGE-COBAEP|P27|' . CRED_PERSONAL[$tipo]['clave'] . '|' . $p['usuario'];
}

function credencial_persona(PDO $pdo, string $tipo, int $id): ?array {
    if (!isset(CRED_PERSONAL[$tipo])) return null;
    $c = CRED_PERSONAL[$tipo];
    $extra = $tipo === 'maestros' ? ', titulo_academico' : ", NULL AS titulo_academico";
    $st = $pdo->prepare("SELECT {$c['id']} AS id, nombre, apellido_paterno, apellido_materno, usuario, correo, foto_perfil $extra FROM {$c['tabla']} WHERE {$c['id']} = ?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    $p['tipo'] = $tipo;
    $p['folio'] = '27-' . $c['clave'] . '-' . str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT);
    $p['qr_texto'] = credencial_qr_texto($tipo, $p);
    return $p;
}

function cred_ruta_abs(?string $ruta): ?string {
    if (!$ruta) return null;
    $r = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $ruta));
    $r = preg_replace('#\?.*$#', '', $r);
    if (strpos($r, '..') !== false) return null;
    $abs = __DIR__ . '/../' . $r;
    return is_file($abs) ? $abs : null;
}

/** Foto cuadrada reducida a 400 px (JPEG) para que el PDF pese poco. */
function cred_foto(?string $ruta): ?string {
    $abs = cred_ruta_abs($ruta);
    if (!$abs) return null;
    $info = @getimagesize($abs);
    $crear = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$info['mime'] ?? ''] ?? null;
    if (!$crear || !function_exists($crear) || !($src = @$crear($abs))) return null;
    $w = imagesx($src); $h = imagesy($src); $lado = min($w, $h); $dest = min(400, $lado);
    $dst = imagecreatetruecolor($dest, $dest);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $lado) / 2), (int)(($h - $lado) / 2), $dest, $dest, $lado, $lado);
    ob_start(); imagejpeg($dst, null, 88); $bin = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

/** Logotipo PNG reducido (el original pesa varios MB). */
function cred_logo(string $ruta, int $alto): ?string {
    static $cache = [];
    if (isset($cache[$ruta])) return $cache[$ruta];
    $abs = __DIR__ . '/../' . $ruta;
    if (!is_file($abs) || !($src = @imagecreatefrompng($abs))) return null;
    $w = imagesx($src); $h0 = imagesy($src); $nw = max(1, (int)round($w * $alto / $h0));
    $dst = imagecreatetruecolor($nw, $alto);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $alto, $w, $h0);
    ob_start(); imagepng($dst, null, 9); $bin = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return $cache[$ruta] = 'data:image/png;base64,' . base64_encode($bin);
}

/** QR recibido de la página: debe ser un PNG cuadrado y pequeño. */
function cred_qr_valido(?string $dato): ?string {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string)$dato, $m)) return null;
    $bin = base64_decode($m[1], true);
    $dim = $bin !== false && strlen($bin) < 300000 ? @getimagesizefromstring($bin) : false;
    return ($dim && $dim['mime'] === 'image/png' && abs($dim[0] - $dim[1]) <= 2) ? 'data:image/png;base64,' . base64_encode($bin) : null;
}

/** HTML de una credencial (una página CR80). */
function credencial_html(array $p, ?string $qr): string {
    $c = CRED_PERSONAL[$p['tipo']];
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    [, $fin] = ciclo_de(date('Y-m-d'));
    $meses = [1 => 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
    $vigencia = 'Hasta ' . $meses[(int)date('n', strtotime($fin))] . ' ' . date('Y', strtotime($fin));
    $nombre = trim($p['nombre'] . ' ' . $p['apellido_paterno'] . ' ' . ($p['apellido_materno'] ?? ''));
    $iniciales = mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['apellido_paterno'], 0, 1));
    $foto = cred_foto($p['foto_perfil'] ?? null);
    $logo = cred_logo('img/logo-blanco.png', 120);
    $filas = '<tr><td class="l">Usuario:</td><td class="v">' . $h($p['usuario']) . '</td></tr>';
    if (!empty($p['titulo_academico'])) $filas .= '<tr><td class="l">Título:</td><td class="v">' . $h(mb_substr($p['titulo_academico'], 0, 40)) . '</td></tr>';
    if (!empty($p['correo'])) $filas .= '<tr><td class="l">Correo:</td><td class="v">' . $h(mb_substr($p['correo'], 0, 38)) . '</td></tr>';
    return '<div class="tarjeta">
        <div class="cab">' . ($logo ? '<img src="' . $logo . '">' : '') . '<div class="marca">COBAEP</div>
            <div class="plantel">PLANTEL 27<span>Zaragoza, Puebla</span></div></div>
        <div class="franja" style="background:' . $c['color'] . '">PERSONAL · ' . $h(mb_strtoupper($c['puesto'])) . '</div>
        <div class="foto" style="border-color:' . $c['color'] . '">' . ($foto ? '<img src="' . $foto . '">' : '<div class="ini">' . $h($iniciales) . '</div>') . '</div>
        <div class="datos">
            <div class="nombre">' . $h($nombre) . '</div>
            <div class="puesto" style="color:' . $c['color'] . '">' . $h($c['puesto']) . '</div>
            <table class="info">' . $filas . '</table>
        </div>
        <div class="qr">' . ($qr ? '<img src="' . $qr . '">' : '<div class="sinqr">QR</div>') . '
            <div class="ftit">FOLIO</div><div class="folio">' . $h($p['folio']) . '</div></div>
        <div class="pie"><span>Vigencia: ' . $h($vigencia) . '</span><em>Credencial de personal</em></div>
    </div>';
}

/** Genera y envía el PDF (una credencial por página). $personas: [['persona' => [...], 'qr' => data-uri|null], …] */
function credencial_pdf_enviar(array $personas, string $nombre_archivo, bool $descargar = true): void {
    $paginas = [];
    foreach ($personas as $x) $paginas[] = credencial_html($x['persona'], $x['qr']);
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; }
        .tarjeta { position: relative; width: 85.6mm; height: 54mm; background: #fff; overflow: hidden; page-break-after: always; }
        .tarjeta:last-child { page-break-after: auto; }
        .cab { position: absolute; left: 0; top: 0; width: 85.6mm; height: 10.5mm; background: #063f2b; }
        .cab img { position: absolute; left: 3.2mm; top: 2.6mm; height: 5.4mm; }
        .cab .marca { position: absolute; left: 9.6mm; top: 3.1mm; color: #fff; font-weight: bold; font-size: 9pt; letter-spacing: .3pt; }
        .cab .plantel { position: absolute; right: 3.2mm; top: 1.9mm; text-align: right; color: #fff; font-weight: bold; font-size: 7pt; letter-spacing: .4pt; }
        .cab .plantel span { display: block; color: #a7f3d0; font-weight: normal; font-size: 5.6pt; letter-spacing: 0; margin-top: .4mm; }
        .franja { position: absolute; left: 0; top: 10.5mm; width: 85.6mm; height: 3.4mm; color: #fff; font-size: 4.8pt; font-weight: bold; letter-spacing: .8pt; text-align: center; line-height: 1; padding-top: .7mm; box-sizing: border-box; }
        .foto { position: absolute; left: 3.2mm; top: 17.2mm; width: 16mm; height: 16mm; border-radius: 8mm; border: .6mm solid #e2e8f0; overflow: hidden; background: #e2e8f0; }
        .foto img { width: 16mm; height: 16mm; border-radius: 8mm; }
        .ini { width: 16mm; height: 16mm; line-height: 16mm; text-align: center; font-weight: bold; font-size: 15pt; color: #0f172a; }
        .datos { position: absolute; left: 22.4mm; top: 16mm; width: 37mm; }
        .nombre { font-weight: bold; font-size: 8.4pt; line-height: 1.12; text-transform: uppercase; color: #0f172a; }
        .puesto { margin-top: 1.2mm; font-size: 6.4pt; font-weight: bold; }
        table.info { margin-top: 1.4mm; border-collapse: collapse; }
        table.info td { font-size: 5.4pt; padding: .3mm 0; vertical-align: top; }
        table.info td.l { color: #64748b; width: 10.5mm; }
        table.info td.v { color: #0f172a; }
        .qr { position: absolute; right: 2.6mm; top: 15.4mm; width: 21mm; text-align: center; }
        .qr img { width: 17mm; height: 17mm; margin: 0 auto; }
        .sinqr { width: 17mm; height: 17mm; margin: 0 auto; border: .3mm dashed #cbd5e1; font-size: 5pt; color: #94a3b8; line-height: 17mm; }
        .qr .ftit { margin-top: .8mm; font-size: 4.6pt; color: #64748b; letter-spacing: .3pt; }
        .qr .folio { font-size: 5pt; font-weight: bold; color: #0f172a; margin-top: .3mm; white-space: nowrap; }
        .pie { position: absolute; left: 0; bottom: 0; width: 85.6mm; height: 6.2mm; background: #063f2b; color: #fff; font-weight: bold; font-size: 6.4pt; }
        .pie span { position: absolute; left: 3.2mm; top: 1.7mm; }
        .pie em { position: absolute; right: 3.2mm; top: 1.9mm; font-style: normal; font-weight: normal; font-size: 5pt; color: #a7f3d0; }
    </style></head><body>' . implode('', $paginas) . '</body></html>';

    $opt = new Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('isPhpEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('dpi', 300);
    $opt->set('isFontSubsettingEnabled', true);
    $dompdf = new Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 85.6 * 72 / 25.4, 54 * 72 / 25.4]);
    $dompdf->render();
    $dompdf->addInfo('Title', 'Credencial de personal · COBAEP Plantel 27');
    $dompdf->addInfo('Author', 'SIGE COBAEP Plantel 27');
    $dompdf->stream(preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre_archivo), ['Attachment' => $descargar]);
    exit();
}
