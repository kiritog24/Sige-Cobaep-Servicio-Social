<?php
/**
 * includes/actividades.php — Reglas comunes de las actividades del aula virtual.
 *
 *  - Catálogo de tipos de archivo que el docente puede aceptar (coincide con extension_permitida()).
 *  - Calificación y revisión de entregas (columnas que se crean solas si faltan).
 *  - Filtro de asistencia: oculta la actividad a quien tuvo Falta en el pase de lista de ese día.
 */

/** Grupos de tipos de archivo: extensión => nombre para mostrar. */
// Solo los formatos más usados. Sin video (pesa demasiado para el servidor) ni formatos poco comunes.
const TIPOS_ARCHIVO = [
    'Documentos'       => ['pdf' => 'PDF', 'doc' => 'Word (.doc)', 'docx' => 'Word (.docx)', 'txt' => 'Texto (.txt)'],
    'Hojas de cálculo' => ['xls' => 'Excel (.xls)', 'xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV'],
    'Presentaciones'   => ['ppt' => 'PowerPoint (.ppt)', 'pptx' => 'PowerPoint (.pptx)'],
    'Imágenes'         => ['jpg' => 'JPG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WEBP'],
    'Audio'            => ['mp3' => 'MP3', 'm4a' => 'M4A (notas de voz de iPhone)', 'ogg' => 'OGG (notas de voz de WhatsApp)'],
    'Comprimidos'      => ['zip' => 'ZIP', 'rar' => 'RAR'],
];

const GRUPO_ICONO_ARCHIVO = [
    'Documentos' => 'fa-file-lines', 'Hojas de cálculo' => 'fa-file-excel', 'Presentaciones' => 'fa-file-powerpoint',
    'Imágenes' => 'fa-file-image', 'Audio' => 'fa-file-audio', 'Comprimidos' => 'fa-file-zipper',
];

const TAMANOS_ARCHIVO_MB = [2, 5, 10, 20, 50];
const MAX_ARCHIVOS_ENTREGA = 10;
const CARPETA_ENTREGAS = __DIR__ . '/../uploads/entregas_alumnos/';

/** Archivos de cada entrega (una entrega puede tener varios). */
const SQL_TABLA_ARCHIVOS_ENTREGA = "CREATE TABLE IF NOT EXISTS `ENTREGAS_ACTIVIDAD_ARCHIVOS` (
    `id_archivo` INT NOT NULL AUTO_INCREMENT,
    `id_entrega` INT NOT NULL,
    `archivo_ruta` VARCHAR(255) NOT NULL,
    `nombre_original` VARCHAR(255) DEFAULT NULL,
    `tamano` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_archivo`),
    KEY `idx_entrega` (`id_entrega`),
    CONSTRAINT `fk_archivo_entrega` FOREIGN KEY (`id_entrega`) REFERENCES `ENTREGAS_ACTIVIDAD` (`id_entrega`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

/** Bytes de un valor de php.ini ("40M", "1G"). */
function ini_a_bytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1073741824;
    elseif ($u === 'm') $n *= 1048576;
    elseif ($u === 'k') $n *= 1024;
    return $n;
}

/** Lo máximo que el servidor deja subir: por archivo, en total por envío y cuántos archivos. */
function limites_servidor(): array {
    return [
        'archivo_mb' => (int)floor(ini_a_bytes((string)ini_get('upload_max_filesize')) / 1048576),
        'total_mb'   => (int)floor(ini_a_bytes((string)ini_get('post_max_size')) / 1048576),
        'archivos'   => max(1, (int)ini_get('max_file_uploads')),
    ];
}

/** Tamaños que se ofrecen al docente (no más de lo que acepta el servidor por archivo). */
function tamanos_disponibles(): array {
    $lim = limites_servidor()['archivo_mb'];
    $t = array_values(array_filter(TAMANOS_ARCHIVO_MB, fn($mb) => $mb <= $lim));
    return $t ?: [min(TAMANOS_ARCHIVO_MB)];
}

function numero_archivos_valido($n): int {
    return max(1, min(MAX_ARCHIVOS_ENTREGA, limites_servidor()['archivos'], (int)$n));
}

/** Nombre visible de un archivo de entrega (sin el prefijo al_ID_TIEMPO_N_). */
function nombre_archivo_entrega(string $ruta): string {
    return preg_replace('/^al_\d+_\d+_(\d{1,2}_)?/', '', basename($ruta));
}

/**
 * Archivos de varias entregas: [id_entrega => [['ruta' => ..., 'nombre' => ...], ...]].
 * Las entregas de antes (un solo archivo en archivo_ruta) también se incluyen.
 */
function archivos_de_entregas(PDO $pdo, array $entregas): array {
    $res = [];
    foreach ($entregas as $e) if (!empty($e['id_entrega'])) $res[(int)$e['id_entrega']] = [];
    if ($res) {
        $st = $pdo->query("SELECT id_entrega, archivo_ruta, nombre_original FROM ENTREGAS_ACTIVIDAD_ARCHIVOS
                           WHERE id_entrega IN (" . implode(',', array_keys($res)) . ") ORDER BY id_archivo");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $res[(int)$r['id_entrega']][] = ['ruta' => basename($r['archivo_ruta']), 'nombre' => $r['nombre_original'] ?: nombre_archivo_entrega($r['archivo_ruta'])];
        }
    }
    foreach ($entregas as $e) {
        $id = (int)($e['id_entrega'] ?? 0);
        if ($id && !$res[$id] && !empty($e['archivo_ruta'])) {
            $res[$id][] = ['ruta' => basename($e['archivo_ruta']), 'nombre' => nombre_archivo_entrega($e['archivo_ruta'])];
        }
    }
    return $res;
}

/** Normaliza $_FILES['campo'] (uno o varios) a una lista, ignorando los campos vacíos. */
function lista_archivos_subidos(string $campo): array {
    $f = $_FILES[$campo] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) $f = array_map(fn($v) => [$v], $f);
    $lista = [];
    foreach ($f['name'] as $i => $nombre) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $lista[] = ['name' => (string)$nombre, 'tmp_name' => $f['tmp_name'][$i], 'size' => (int)$f['size'][$i], 'error' => (int)$f['error'][$i]];
    }
    return $lista;
}

/** ¿El envío se perdió por superar post_max_size? (PHP deja $_POST y $_FILES vacíos) */
function envio_demasiado_grande(): bool {
    return empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/**
 * Revisa los archivos de una entrega contra las reglas de la actividad.
 * @return string|null  mensaje para el alumno, o null si todo está bien
 */
function validar_archivos_entrega(array $archivos, array $actividad): ?string {
    $max_n = numero_archivos_valido($actividad['numero_archivos'] ?? 1);
    $max_mb = tamano_archivo_valido($actividad['tamano_maximo'] ?? 10);
    $lim = limites_servidor();
    if (!$archivos) return 'Debes adjuntar al menos un archivo.';
    if (count($archivos) > $max_n) return "Esta actividad acepta máximo $max_n archivo(s) y enviaste " . count($archivos) . '.';
    $total = 0;
    foreach ($archivos as $a) {
        $n = $a['name'];
        if (in_array($a['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $a['size'] > $max_mb * 1048576) {
            return "«{$n}» pesa más de $max_mb MB, que es lo máximo por archivo en esta actividad.";
        }
        if ($a['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp_name'])) return "«{$n}» no se pudo subir. Vuelve a intentarlo.";
        $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        if ($ext === '' || !tipo_archivo_permitido($actividad['tipo_archivo'] ?? '*', $ext)) {
            return "«{$n}»: esta actividad no acepta archivos ." . ($ext ?: '?') . '. Tipos permitidos: ' . tipos_archivo_texto($actividad['tipo_archivo'] ?? '*') . '.';
        }
        $total += $a['size'];
    }
    if ($total > $lim['total_mb'] * 1048576) return "Entre todos tus archivos pesan más de {$lim['total_mb']} MB, lo máximo que acepta el servidor en un solo envío.";
    return null;
}

/** Mueve los archivos ya validados a uploads/entregas_alumnos. @return array lista de ['ruta','nombre','tamano'], o [] si falló */
function guardar_archivos_entrega(array $archivos, int $id_alumno): array {
    if (!is_dir(CARPETA_ENTREGAS)) mkdir(CARPETA_ENTREGAS, 0775, true);
    $guardados = [];
    $t = time();
    foreach ($archivos as $i => $a) {
        $ext = strtolower(pathinfo($a['name'], PATHINFO_EXTENSION));
        $base = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($a['name'], PATHINFO_FILENAME)), 0, 120);
        $ruta = 'al_' . $id_alumno . '_' . $t . '_' . ($i + 1) . '_' . $base . '.' . $ext;
        if (!move_uploaded_file($a['tmp_name'], CARPETA_ENTREGAS . $ruta)) {
            foreach ($guardados as $g) @unlink(CARPETA_ENTREGAS . $g['ruta']);
            return [];
        }
        $guardados[] = ['ruta' => $ruta, 'nombre' => mb_substr(basename(str_replace('\\', '/', $a['name'])), 0, 250), 'tamano' => $a['size']];
    }
    return $guardados;
}

function registrar_archivos_entrega(PDO $pdo, int $id_entrega, array $guardados): void {
    $st = $pdo->prepare("INSERT INTO ENTREGAS_ACTIVIDAD_ARCHIVOS (id_entrega, archivo_ruta, nombre_original, tamano) VALUES (?, ?, ?, ?)");
    foreach ($guardados as $g) $st->execute([$id_entrega, $g['ruta'], $g['nombre'], $g['tamano']]);
}

/** Rutas físicas de los archivos de una o varias entregas (se borran después de quitar los registros). */
function rutas_archivos_entrega(PDO $pdo, array $ids_entrega): array {
    $ids = array_values(array_filter(array_map('intval', $ids_entrega)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $st = $pdo->query("SELECT archivo_ruta FROM ENTREGAS_ACTIVIDAD_ARCHIVOS WHERE id_entrega IN ($in)
                       UNION SELECT archivo_ruta FROM ENTREGAS_ACTIVIDAD WHERE id_entrega IN ($in)");
    return array_map(fn($r) => CARPETA_ENTREGAS . basename($r), array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
}

function borrar_archivos_fisicos(array $rutas): void {
    foreach ($rutas as $r) if (is_file($r)) @unlink($r);
}

/** Todas las extensiones del catálogo. */
function tipos_archivo_todas(): array {
    $t = [];
    foreach (TIPOS_ARCHIVO as $exts) $t = array_merge($t, array_keys($exts));
    return $t;
}

/**
 * Convierte lo guardado en actividades_clase.tipo_archivo en una lista de extensiones.
 * [] significa "cualquier tipo permitido". Acepta los valores antiguos ('image/*', '.pdf,.doc').
 */
function tipos_archivo_lista(?string $guardado): array {
    $guardado = trim((string)$guardado);
    if ($guardado === '' || $guardado === '*') return [];
    $todas = tipos_archivo_todas();
    $res = [];
    foreach (explode(',', strtolower($guardado)) as $p) {
        $p = trim($p);
        if ($p === 'image/*') { $res = array_merge($res, array_keys(TIPOS_ARCHIVO['Imágenes'])); continue; }
        $p = ltrim($p, '.');
        if (in_array($p, $todas, true)) $res[] = $p;
    }
    $res = array_values(array_unique($res));
    if (!$res) return ['pdf'];   // solo tenía tipos que ya no se aceptan (video, gif...): se queda en PDF
    return count($res) >= count($todas) ? [] : $res;
}

/** Lo que llega del formulario (tipos[] o tipo_archivo) → texto para guardar ('*' o '.pdf,.docx'). */
function tipos_archivo_desde_post(array $post): string {
    $sel = $post['tipos'] ?? ($post['tipo_archivo'] ?? '*');
    if (is_array($sel)) $sel = implode(',', $sel);
    $lista = tipos_archivo_lista((string)$sel);
    return $lista ? '.' . implode(',.', $lista) : '*';
}

/** Atributo accept del <input type="file">. */
function tipos_archivo_accept(?string $guardado): string {
    $l = tipos_archivo_lista($guardado);
    return $l ? '.' . implode(',.', $l) : '';
}

/** Texto corto para mostrar: "PDF, Word (.docx)" o "Cualquier tipo". */
function tipos_archivo_texto(?string $guardado): string {
    $l = tipos_archivo_lista($guardado);
    if (!$l) return 'Cualquier tipo de la lista';
    $nombres = [];
    foreach (TIPOS_ARCHIVO as $grupo => $exts) {
        $del_grupo = array_intersect(array_keys($exts), $l);
        if (!$del_grupo) continue;
        if (count($del_grupo) === count($exts) && count($exts) > 1) { $nombres[] = $grupo . ' (todos)'; continue; }
        foreach ($del_grupo as $e) $nombres[] = $exts[$e];
    }
    return implode(', ', $nombres);
}

function tipo_archivo_permitido(?string $guardado, string $ext): bool {
    $ext = strtolower($ext);
    if (!extension_permitida($ext)) return false;
    $l = tipos_archivo_lista($guardado) ?: tipos_archivo_todas();   // "cualquiera" = cualquiera de la lista (sin video)
    return in_array($ext, $l, true);
}

/** Atributo accept del selector del alumno: con "cualquier tipo" también limita a la lista. */
function tipos_archivo_accept_input(?string $guardado): string {
    return '.' . implode(',.', tipos_archivo_lista($guardado) ?: tipos_archivo_todas());
}

function tamano_archivo_valido($mb): int {
    $mb = (int)$mb;
    $ok = tamanos_disponibles();   // nunca más de lo que el servidor deja subir por archivo
    if (in_array($mb, $ok, true)) return $mb;
    return in_array(10, $ok, true) ? 10 : max($ok);
}

/** Crea las columnas nuevas si la base todavía no las tiene (por si no se corrió actualizar_bd.php). */
function asegurar_columnas_actividades(PDO $pdo): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $cols = [
        'ENTREGAS_ACTIVIDAD' => [
            'calificacion'   => 'DECIMAL(4,1) NULL DEFAULT NULL',
            'revisada'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'fecha_revision' => 'DATETIME NULL DEFAULT NULL',
        ],
        'ACTIVIDADES_CLASE' => [
            'solo_asistentes' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'fecha_filtro'    => 'DATE NULL DEFAULT NULL',
        ],
    ];
    try {
        foreach ($cols as $tabla => $defs) {
            $existentes = $pdo->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_COLUMN);
            $existentes = array_map('strtolower', $existentes);
            foreach ($defs as $col => $def) {
                if (!in_array($col, $existentes, true)) $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$col` $def");
            }
        }
        $pdo->exec(SQL_TABLA_ARCHIVOS_ENTREGA);
        $tipo = $pdo->query("SHOW COLUMNS FROM `ACTIVIDADES_CLASE` LIKE 'tipo_archivo'")->fetch(PDO::FETCH_ASSOC);
        if ($tipo && stripos($tipo['Type'], 'varchar(100)') !== false) {
            $pdo->exec("ALTER TABLE `ACTIVIDADES_CLASE` MODIFY `tipo_archivo` VARCHAR(255) DEFAULT '*'");
        }
        // Entregas marcadas con el sistema anterior ("Revisada" reemplazaba "A Tiempo"/"Con Retardo")
        $pdo->exec("UPDATE `ENTREGAS_ACTIVIDAD` e INNER JOIN `ACTIVIDADES_CLASE` ac ON ac.id_actividad = e.id_actividad
                    SET e.revisada = 1,
                        e.estatus_entrega = IF(ac.fecha_limite IS NOT NULL AND e.fecha_entrega > ac.fecha_limite, 'Con Retardo', 'A Tiempo')
                    WHERE e.estatus_entrega = 'Revisada'");
    } catch (PDOException $e) {
        error_log('asegurar_columnas_actividades: ' . $e->getMessage());
    }
}

/**
 * Condición SQL: ¿el alumno puede ver la actividad? (usa los alias ac = ACTIVIDADES_CLASE, va = VISIBILIDAD_ACTIVIDAD)
 * 1) Lo que el docente decidió para ese alumno (va.visible) manda.
 * 2) Si no decidió nada y la actividad tiene filtro de asistencia, se oculta a quien tuvo Falta ese día.
 */
function sql_actividad_visible(int $id_alumno): string {
    return "COALESCE(va.visible, CASE WHEN ac.solo_asistentes = 1 AND EXISTS (
                SELECT 1 FROM ASISTENCIAS_CLASES fx
                WHERE fx.id_clase = ac.id_clase AND fx.id_alumno = " . (int)$id_alumno . "
                  AND fx.fecha = ac.fecha_filtro AND fx.estatus = 'Falta') THEN 0 ELSE 1 END) = 1";
}

/** Calificación válida (0 a 10, un decimal) o null si viene vacía. false si no es válida. */
function calificacion_valida($v) {
    $v = trim(str_replace(',', '.', (string)$v));
    if ($v === '') return null;
    if (!is_numeric($v)) return false;
    $n = round((float)$v, 1);
    return ($n < 0 || $n > 10) ? false : $n;
}

function calificacion_texto($v): string {
    if ($v === null || $v === '') return '';
    $n = (float)$v;
    return (floor($n) == $n) ? (string)(int)$n : number_format($n, 1);
}
