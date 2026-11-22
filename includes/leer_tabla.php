<?php
/**
 * includes/leer_tabla.php — Lee la primera hoja de un Excel (.xlsx) o un CSV y devuelve sus filas
 * como arreglos de texto. Sin librerías externas (usa ZipArchive para .xlsx).
 *
 *   $filas = leer_tabla($_FILES['archivo']['tmp_name'], $_FILES['archivo']['name']);
 */

/** "AB" → 27 (base 1). */
function leer_tabla_col(string $letras): int {
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $c) $n = $n * 26 + (ord($c) - 64);
    return $n;
}

/** @return array<int, array<int, string>>  (llave = número de fila − 1). Lanza RuntimeException si el archivo no se puede leer. */
function leer_tabla(string $ruta, string $nombre_original): array {
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return leer_tabla_xlsx($ruta);
    if ($ext === 'csv' || $ext === 'txt') return leer_tabla_csv($ruta);
    if ($ext === 'xls') throw new RuntimeException('El formato .xls (Excel 97-2003) no es compatible. En Excel usa "Guardar como" → "Libro de Excel (.xlsx)" o "CSV".');
    throw new RuntimeException('Formato no válido. Sube un archivo de Excel (.xlsx) o CSV.');
}

function leer_tabla_csv(string $ruta): array {
    $texto = (string)file_get_contents($ruta);
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);                       // BOM
    if (!mb_check_encoding($texto, 'UTF-8')) $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    $primera = strtok($texto, "\n") ?: '';
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : (substr_count($primera, "\t") > substr_count($primera, ',') ? "\t" : ',');
    $f = fopen('php://temp', 'r+');
    fwrite($f, $texto);
    rewind($f);
    $filas = [];
    while (($d = fgetcsv($f, 0, $sep, '"', '')) !== false) {
        if ($d === [null]) continue;
        $filas[] = array_map(fn($v) => trim((string)$v), $d);
    }
    fclose($f);
    return $filas;
}

function leer_tabla_xlsx(string $ruta): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no tiene habilitada la extensión zip de PHP.');
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) throw new RuntimeException('No se pudo abrir el archivo de Excel. ¿Está dañado?');

    // Textos compartidos
    $compartidos = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($xml);
        if ($sx) foreach ($sx->si as $si) {
            if (isset($si->t)) { $compartidos[] = (string)$si->t; continue; }
            $t = '';
            foreach ($si->r as $r) $t .= (string)$r->t;    // texto con formato (varios "runs")
            $compartidos[] = $t;
        }
    }

    // Primera hoja según el libro
    $hoja = 'xl/worksheets/sheet1.xml';
    $wb = @simplexml_load_string((string)$zip->getFromName('xl/workbook.xml'));
    $rels = @simplexml_load_string((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string)$wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $destino = ltrim((string)$rel['Target'], '/');
                $hoja = str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
                break;
            }
        }
    }
    $xml = $zip->getFromName($hoja);
    $zip->close();
    if ($xml === false) throw new RuntimeException('El archivo de Excel no tiene hojas con datos.');

    $sx = @simplexml_load_string($xml);
    if (!$sx || !isset($sx->sheetData)) throw new RuntimeException('No se pudo leer la hoja de Excel.');
    $filas = [];
    foreach ($sx->sheetData->row as $row) {
        $fila = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = isset($m[1]) ? leer_tabla_col($m[1]) - 1 : count($fila);
            $tipo = (string)$c['t'];
            if ($tipo === 's') $v = $compartidos[(int)$c->v] ?? '';
            elseif ($tipo === 'inlineStr') $v = (string)$c->is->t;
            else $v = (string)$c->v;
            // Números enteros grandes que Excel guarda como 2.7E+10, etc.
            if ($tipo === '' && is_numeric($v) && stripos($v, 'E') !== false) $v = number_format((float)$v, 0, '', '');
            $fila[$col] = trim($v);
        }
        if (!$fila) continue;
        $max = max(array_keys($fila));
        $completa = [];
        for ($i = 0; $i <= $max; $i++) $completa[] = $fila[$i] ?? '';
        $num = (int)$row['r'];                      // se conserva el número de fila real de Excel
        if ($num > 0) $filas[$num - 1] = $completa; else $filas[] = $completa;
    }
    return $filas;
}

/** Texto en mayúsculas, sin acentos ni signos y con espacios simples. */
if (!function_exists('norm_texto')) {
function norm_texto(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U']);
    $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
/** Llave de nombre independiente del orden ("ANA PEREZ LOPEZ" = "PEREZ LOPEZ ANA"). */
function llave_nombre(string $s): string {
    $p = explode(' ', norm_texto($s));
    sort($p);
    return implode(' ', array_filter($p));
}
/** Matrícula sin espacios ni signos: "27/2026B0001" → "272026B0001". */
function llave_matricula(string $s): string {
    return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($s));
}
}
