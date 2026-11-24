<?php
/**
 * includes/pdf_documento.php — Genera PDFs reales con Dompdf (vendor/dompdf) con el encabezado
 * institucional de COBAEP Plantel 27. Así los reportes se descargan como archivo .pdf,
 * no como impresión de la página.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/** Logotipo como data URI (Dompdf no necesita acceso remoto). */
function pdf_logo(): string {
    foreach (['img/logo-repor.png', 'img/LogoCobaep.png'] as $r) {
        $abs = __DIR__ . '/../' . $r;
        if (is_file($abs)) return 'data:image/png;base64,' . base64_encode(file_get_contents($abs));
    }
    return '';
}

/**
 * Descarga un PDF.
 * $cuerpo: HTML del contenido. $orientacion: 'portrait' | 'landscape'.
 * $en_linea: true = se abre en el navegador (para imprimirlo); false = se descarga como archivo.
 */
function pdf_descargar(string $nombre, string $titulo, string $subtitulo, string $cuerpo, string $orientacion = 'portrait', bool $en_linea = false): void {
    $logo = pdf_logo();
    $fecha = date('d/m/Y h:i a');
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 22mm 12mm 16mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1e293b; }
        header { position: fixed; top: -17mm; left: 0; right: 0; height: 14mm; border-bottom: 2px solid #16a05d; }
        header img { height: 12mm; float: left; margin-right: 8px; }
        header .t1 { font-size: 13px; font-weight: bold; color: #063f2b; padding-top: 1mm; }
        header .t2 { font-size: 9px; color: #475569; }
        footer { position: fixed; bottom: -11mm; left: 0; right: 0; height: 8mm; font-size: 8px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 2px; }
        h1 { font-size: 14px; color: #063f2b; margin: 0 0 2px; }
        .sub { color: #475569; margin: 0 0 10px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #16a05d; color: #fff; font-size: 8.5px; padding: 5px 4px; text-transform: uppercase; border: 1px solid #0f7a45; }
        td { padding: 4px; border: 1px solid #d5dfda; }
        tr:nth-child(even) td { background: #f6faf8; }
        .c { text-align: center; } .r { text-align: right; }
        .resumen td { border: none; background: #f1f5f9; text-align: center; padding: 6px; }
        .resumen b { display: block; font-size: 13px; color: #063f2b; }
        .ok { color: #047857; font-weight: bold; } .medio { color: #b45309; font-weight: bold; } .bajo { color: #b91c1c; font-weight: bold; }
        .nota { margin-top: 10px; font-size: 8px; color: #64748b; }
    </style></head><body>
        <header>' . ($logo ? '<img src="' . $logo . '">' : '') . '<div class="t1">COBAEP Plantel 27 · SIGE</div><div class="t2">Sistema de Información y Gestión Educativa · Zaragoza, Puebla</div></header>
        <footer>Generado el ' . $fecha . '</footer>
        <h1>' . htmlspecialchars($titulo) . '</h1><p class="sub">' . htmlspecialchars($subtitulo) . '</p>' . $cuerpo . '
    </body></html>';

    $opt = new Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isPhpEnabled', false);
    $dompdf = new Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', $orientacion);
    $dompdf->render();
    // "Página X de Y" en el pie (CSS counter(pages) no funciona en Dompdf)
    $canvas = $dompdf->getCanvas();
    $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
    $mm = 72 / 25.4;
    $ancho_txt = $dompdf->getFontMetrics()->getTextWidth('Página 99 de 99', $fuente, 6);
    $canvas->page_text($canvas->get_width() - 12 * $mm - $ancho_txt, $canvas->get_height() - 13.1 * $mm, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 6, [0.39, 0.45, 0.55]);
    // Nombre de archivo sin acentos ni ñ (Español -> Espanol) para que se vea bien en cualquier equipo
    $nombre = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                              'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', ' ' => '_']);
    $nombre = preg_replace('/_+/', '_', preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre));
    if (!str_ends_with(strtolower($nombre), '.pdf')) $nombre .= '.pdf';
    $dompdf->stream($nombre, ['Attachment' => !$en_linea]);
    exit();
}
