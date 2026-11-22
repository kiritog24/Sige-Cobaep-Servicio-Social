<?php
/**
 * includes/xlsx_simple.php — Genera archivos Excel reales (.xlsx) sin librerías externas.
 * Usa la extensión zip de PHP. Soporta: título, fila de encabezados en negritas con fondo,
 * números como números, anchos de columna y fila congelada.
 *
 *   xlsx_descargar('Lista.xlsx', 'Hoja', ['Título', 'Subtítulo'], ['Col1', 'Col2'], [[...], [...]], [10, 30]);
 */

function xlsx_col(int $i): string {           // 0 → A, 25 → Z, 26 → AA
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function xlsx_esc(string $v): string {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Construye el .xlsx y devuelve su contenido binario. */
function xlsx_generar(string $hoja, array $titulos, array $encabezados, array $filas, array $anchos = []): string {
    $hoja = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '', $hoja) ?: 'Hoja1', 0, 31);
    $xml_filas = '';
    $r = 1;
    foreach ($titulos as $i => $t) {        // estilo 1 = título (negritas grandes), 3 = subtítulo
        $xml_filas .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="' . ($i === 0 ? 1 : 3) . '"><is><t>' . xlsx_esc((string)$t) . '</t></is></c></row>';
        $r++;
    }
    if ($titulos) $r++;                      // fila en blanco
    $fila_encabezado = $r;
    $celdas = '';
    foreach ($encabezados as $c => $e) $celdas .= '<c r="' . xlsx_col($c) . $r . '" t="inlineStr" s="2"><is><t>' . xlsx_esc((string)$e) . '</t></is></c>';
    $xml_filas .= '<row r="' . $r . '">' . $celdas . '</row>';
    foreach ($filas as $fila) {
        $r++;
        $celdas = '';
        foreach (array_values($fila) as $c => $v) {
            $ref = xlsx_col($c) . $r;
            if ($v === null || $v === '') continue;
            if (is_int($v) || is_float($v)) $celdas .= '<c r="' . $ref . '" s="4"><v>' . $v . '</v></c>';
            else $celdas .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>' . xlsx_esc((string)$v) . '</t></is></c>';
        }
        $xml_filas .= '<row r="' . $r . '">' . $celdas . '</row>';
    }
    $cols = '';
    foreach ($encabezados as $c => $_) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float)($anchos[$c] ?? 14) . '" customWidth="1"/>';
    $ultima = xlsx_col(max(0, count($encabezados) - 1));

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $fila_encabezado . '" topLeftCell="A' . ($fila_encabezado + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols><sheetData>' . $xml_filas . '</sheetData>'
        . '<autoFilter ref="A' . $fila_encabezado . ':' . $ultima . max($fila_encabezado, $r) . '"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF063F2B"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF16A05D"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFCCCCCC"/></left><right style="thin"><color rgb="FFCCCCCC"/></right><top style="thin"><color rgb="FFCCCCCC"/></top><bottom style="thin"><color rgb="FFCCCCCC"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . xlsx_esc((string)($titulos[0] ?? $hoja)) . '</dc:title><dc:creator>SIGE COBAEP Plantel 27</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_esc($hoja) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", xlsx_esc($hoja)) . '\'!$A$' . $fila_encabezado . ':$' . $ultima . '$' . max($fila_encabezado, $r) . '</definedName></definedNames></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Envía el .xlsx al navegador como descarga y termina. */
function xlsx_descargar(string $nombre, string $hoja, array $titulos, array $encabezados, array $filas, array $anchos = []): void {
    $bin = xlsx_generar($hoja, $titulos, $encabezados, $filas, $anchos);
    $nombre = preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre);
    if (!str_ends_with(strtolower($nombre), '.xlsx')) $nombre .= '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit();
}
