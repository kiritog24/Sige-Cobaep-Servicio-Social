<?php
/**
 * includes/archivo_ciclo.php — Cierre de ciclo escolar.
 *
 *  - respaldo_generar(): copia completa de la base de datos (.sql) en la carpeta respaldos/ (bloqueada al navegador).
 *  - archivo_ejecutar(): mueve las asistencias anteriores a una fecha de corte (ciclos ya terminados) a las tablas
 *    ASISTENCIAS_ARCHIVO y ASISTENCIAS_CLASES_ARCHIVO. Así las tablas de uso diario se mantienen pequeñas y
 *    rápidas, pero nada se borra: el concentrado de Prefectura sigue pudiendo consultar ciclos anteriores.
 */

const RESPALDOS_DIR = __DIR__ . '/../respaldos';

/** Periodo escolar (semestre) de una fecha: Ago–Ene o Feb–Jul. [inicio, fin, etiqueta] */
function ciclo_de(string $fecha): array {
    $m = (int)date('n', strtotime($fecha)); $y = (int)date('Y', strtotime($fecha));
    if ($m >= 8)  return ["$y-08-01", ($y + 1) . "-01-31", "Ago $y – Ene " . ($y + 1)];
    if ($m === 1) return [($y - 1) . "-08-01", "$y-01-31", "Ago " . ($y - 1) . " – Ene $y"];
    return ["$y-02-01", "$y-07-31", "Feb – Jul $y"];
}

/** Expresión SQL que calcula la etiqueta del ciclo de una columna de fecha. */
function ciclo_sql(string $col): string {
    return "CASE WHEN MONTH($col) >= 8 THEN CONCAT('Ago ', YEAR($col), ' – Ene ', YEAR($col) + 1)
                 WHEN MONTH($col) = 1 THEN CONCAT('Ago ', YEAR($col) - 1, ' – Ene ', YEAR($col))
                 ELSE CONCAT('Feb – Jul ', YEAR($col)) END";
}

function archivo_existe(PDO $pdo): bool {
    static $r = null;
    if ($r === null) {
        $r = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'asistencias_archivo'")->fetchColumn() > 0;
    }
    return $r;
}

/** Crea las tablas de archivo (misma estructura + ciclo y fecha de archivado). Fuera de transacciones. */
function archivo_tablas_crear(PDO $pdo): void {
    foreach (['ASISTENCIAS' => 'ASISTENCIAS_ARCHIVO', 'ASISTENCIAS_CLASES' => 'ASISTENCIAS_CLASES_ARCHIVO'] as $origen => $destino) {
        $existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = '" . strtolower($destino) . "'")->fetchColumn();
        if ($existe) continue;
        $pdo->exec("CREATE TABLE `$destino` LIKE `$origen`");
        $pdo->exec("ALTER TABLE `$destino` MODIFY " . ($origen === 'ASISTENCIAS' ? '`id_asistencia`' : '`id_asistencia`') . " INT NOT NULL,
                    ADD COLUMN `ciclo` VARCHAR(30) NOT NULL DEFAULT '', ADD COLUMN `archivado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    ADD KEY `idx_ciclo` (`ciclo`)");
    }
}

/** Registros que se archivarían con esa fecha de corte (todo lo anterior a la fecha). */
function archivo_contar(PDO $pdo, string $corte): array {
    $st = $pdo->prepare("SELECT " . ciclo_sql('fecha_hora_escaneo') . " AS ciclo, COUNT(*) AS n FROM ASISTENCIAS WHERE fecha_hora_escaneo < ? GROUP BY ciclo ORDER BY MIN(fecha_hora_escaneo)");
    $st->execute([$corte]);
    $gral = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $pdo->prepare("SELECT " . ciclo_sql('fecha') . " AS ciclo, COUNT(*) AS n FROM ASISTENCIAS_CLASES WHERE fecha < ? GROUP BY ciclo ORDER BY MIN(fecha)");
    $st->execute([$corte]);
    $clases = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['plantel' => $gral, 'clases' => $clases, 'total' => array_sum($gral) + array_sum($clases)];
}

/** Mueve al archivo todo lo anterior a $corte. Devuelve cuántos registros se movieron. */
function archivo_ejecutar(PDO $pdo, string $corte): array {
    archivo_tablas_crear($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO ASISTENCIAS_ARCHIVO SELECT a.*, " . ciclo_sql('a.fecha_hora_escaneo') . ", NOW() FROM ASISTENCIAS a WHERE a.fecha_hora_escaneo < ?");
        $st->execute([$corte]);
        $n1 = $st->rowCount();
        $pdo->prepare("DELETE FROM ASISTENCIAS WHERE fecha_hora_escaneo < ?")->execute([$corte]);
        $st = $pdo->prepare("INSERT INTO ASISTENCIAS_CLASES_ARCHIVO SELECT a.*, " . ciclo_sql('a.fecha') . ", NOW() FROM ASISTENCIAS_CLASES a WHERE a.fecha < ?");
        $st->execute([$corte]);
        $n2 = $st->rowCount();
        $pdo->prepare("DELETE FROM ASISTENCIAS_CLASES WHERE fecha < ?")->execute([$corte]);
        $pdo->commit();
        return ['plantel' => $n1, 'clases' => $n2];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Ciclos que ya están en el archivo. */
function archivo_ciclos(PDO $pdo): array {
    if (!archivo_existe($pdo)) return [];
    return $pdo->query("SELECT ciclo, COUNT(*) AS registros, MIN(DATE(fecha_hora_escaneo)) AS desde, MAX(DATE(fecha_hora_escaneo)) AS hasta, MAX(archivado_en) AS archivado
                        FROM ASISTENCIAS_ARCHIVO GROUP BY ciclo ORDER BY MIN(fecha_hora_escaneo) DESC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fuente de asistencias del plantel para consultas de periodos que pueden estar archivados
 * (concentrado): une la tabla diaria con el archivo si existe.
 */
function asistencias_fuente_sql(PDO $pdo): string {
    if (!archivo_existe($pdo)) return 'ASISTENCIAS';
    return "(SELECT id_alumno, fecha_hora_escaneo, tipo_registro, estatus FROM ASISTENCIAS
             UNION ALL SELECT id_alumno, fecha_hora_escaneo, tipo_registro, estatus FROM ASISTENCIAS_ARCHIVO)";
}

// ---------------------------------------------------------------------------------------------
// Respaldo de la base de datos
// ---------------------------------------------------------------------------------------------

function respaldos_preparar(): void {
    if (!is_dir(RESPALDOS_DIR)) mkdir(RESPALDOS_DIR, 0750, true);
    $ht = RESPALDOS_DIR . '/.htaccess';
    if (!is_file($ht)) file_put_contents($ht, "# Los respaldos solo se descargan desde Administración\nRequire all denied\n");
}

/** Genera un respaldo completo (.sql) y devuelve el nombre del archivo. */
function respaldo_generar(PDO $pdo, string $motivo = 'manual'): string {
    respaldos_preparar();
    @set_time_limit(0);
    $nombre = 'respaldo_' . date('Y-m-d_His') . '_' . preg_replace('/[^a-z0-9]/', '', strtolower($motivo)) . '.sql';
    $f = fopen(RESPALDOS_DIR . '/' . $nombre, 'w');
    $bd = $pdo->query('SELECT DATABASE()')->fetchColumn();
    fwrite($f, "-- Respaldo de SIGE-COBAEP Plantel 27\n-- Base de datos: $bd\n-- Fecha: " . date('d/m/Y H:i:s') . "\n-- Motivo: $motivo\n\n"
             . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tablas = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablas as $t) {
        $crear = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($f, "-- --------------------------------------------\n-- Tabla `$t`\n-- --------------------------------------------\nDROP TABLE IF EXISTS `$t`;\n$crear;\n\n");
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $lote = [];
        while ($fila = $st->fetch()) {
            $lote[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $fila)) . ')';
            if (count($lote) >= 200) { fwrite($f, "INSERT INTO `$t` VALUES\n" . implode(",\n", $lote) . ";\n"); $lote = []; }
        }
        if ($lote) fwrite($f, "INSERT INTO `$t` VALUES\n" . implode(",\n", $lote) . ";\n");
        fwrite($f, "\n");
    }
    fwrite($f, "SET FOREIGN_KEY_CHECKS = 1;\n-- Fin del respaldo\n");
    fclose($f);
    return $nombre;
}

/** Respaldos guardados (más recientes primero). */
function respaldos_lista(): array {
    if (!is_dir(RESPALDOS_DIR)) return [];
    $r = [];
    foreach (glob(RESPALDOS_DIR . '/respaldo_*.sql') ?: [] as $a) $r[] = ['nombre' => basename($a), 'tamano' => filesize($a), 'fecha' => filemtime($a)];
    usort($r, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
    return $r;
}
