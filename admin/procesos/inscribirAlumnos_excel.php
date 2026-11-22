<?php
/**
 * admin/procesos/inscribirAlumnos_excel.php — Inscribir a un grupo los alumnos de una lista (Excel .xlsx o CSV).
 *
 *  Paso 1 (paso=analizar): lee el archivo, identifica a cada alumno y guarda el análisis en la sesión.
 *                          No cambia nada: lleva a admin/carga_grupo.php para revisar.
 *  Paso 2 (paso=aplicar):  inscribe a los nuevos y cambia de grupo solo a los que el administrador marcó.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/grupos.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../gestion_grupos.php");
    exit();
}

$id_grupo = (int)($_POST['id_grupo'] ?? 0);
$st = $pdo->prepare("SELECT id_grupo, semestre, nombre_grupo, turno, estatus FROM GRUPOS WHERE id_grupo = ?");
$st->execute([$id_grupo]);
$grupo = $st->fetch(PDO::FETCH_ASSOC);
if (!$grupo) {
    header("Location: ../gestion_grupos.php");
    exit();
}
$volver = function (string $param) use ($id_grupo) {
    header("Location: ../ver_grupo.php?id=$id_grupo&" . $param);
    exit();
};

// ---------------- Paso 1: analizar ----------------
if (($_POST['paso'] ?? 'analizar') === 'analizar') {
    $arch = $_FILES['archivo'] ?? $_FILES['archivo_csv'] ?? null;
    if (!$arch || $arch['error'] !== UPLOAD_ERR_OK) $volver('error_carga=' . urlencode('Selecciona un archivo de Excel (.xlsx) o CSV.'));
    if ($arch['size'] > 5 * 1024 * 1024) $volver('error_carga=' . urlencode('El archivo pesa más de 5 MB.'));
    try {
        $filas = leer_tabla($arch['tmp_name'], $arch['name']);
    } catch (RuntimeException $e) {
        $volver('error_carga=' . urlencode($e->getMessage()));
    }
    if (count($filas) > 3000) $volver('error_carga=' . urlencode('La lista tiene demasiados renglones (máximo 3000).'));
    $analisis = analizar_lista_grupo($pdo, $filas, $grupo);
    if ($analisis['modo'] === 'ninguno' || !$analisis['renglones']) {
        $volver('error_carga=' . urlencode('No se encontró ninguna matrícula ni nombre de alumno registrado en el archivo. Revisa que la lista tenga una columna con las matrículas (ej. 27/2026B0001).'));
    }
    $token = bin2hex(random_bytes(8));
    $_SESSION['carga_grupo'] = ['token' => $token, 'id_grupo' => $id_grupo, 'archivo' => mb_substr($arch['name'], 0, 120), 'creado' => time(), 'analisis' => $analisis];
    header("Location: ../carga_grupo.php?id=$id_grupo&t=$token");
    exit();
}

// ---------------- Paso 2: aplicar ----------------
$carga = $_SESSION['carga_grupo'] ?? null;
if (!$carga || !hash_equals($carga['token'], (string)($_POST['token'] ?? '')) || (int)$carga['id_grupo'] !== $id_grupo || time() - $carga['creado'] > 3600) {
    $volver('error_carga=' . urlencode('La revisión de la lista ya no es válida. Vuelve a subir el archivo.'));
}
if ($grupo['estatus'] !== 'Activo') $volver('error_carga=' . urlencode('El grupo no está activo.'));

$mover = array_flip(array_map('intval', (array)($_POST['mover'] ?? [])));
$candidatos = [];
foreach ($carga['analisis']['renglones'] as $r) {
    if (!$r['alumno']) continue;
    $id = (int)$r['alumno']['id_alumno'];
    if ($r['categoria'] === 'nuevo' || ($r['categoria'] === 'otro_grupo' && isset($mover[$id]))) $candidatos[$id] = $r['categoria'];
}
if (!$candidatos) $volver('carga=' . urlencode('0|0|0'));

try {
    // Se vuelve a revisar la situación real de cada alumno al momento de guardar
    $st = $pdo->query("SELECT id_alumno, estatus FROM ALUMNOS WHERE id_alumno IN (" . implode(',', array_keys($candidatos)) . ")");
    $estatus = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $actuales = grupos_actuales($pdo, array_keys($candidatos));

    $pdo->beginTransaction();
    $inscritos = $cambiados = $omitidos = 0;
    $grupos_tocados = [$id_grupo];
    $detalle = [];
    foreach ($candidatos as $id => $cat) {
        if (($estatus[$id] ?? '') !== 'Alta') { $omitidos++; continue; }
        $tenia = $actuales[$id] ?? null;
        // Si al revisar era "nuevo" pero ya lo inscribieron en otro grupo, no se mueve sin permiso
        if ($tenia && (int)$tenia['id_grupo'] !== $id_grupo && !isset($mover[$id])) { $omitidos++; continue; }
        $anterior = asignar_alumno_grupo($pdo, $id, $grupo);
        if ($anterior === null) { $omitidos++; continue; }
        if ($anterior) { $cambiados++; $grupos_tocados[] = $anterior; $detalle[] = bitacora_quien($pdo, 'alumnos', $id) . ' (venía de ' . grupo_etiqueta($tenia) . ')'; }
        else { $inscritos++; $detalle[] = bitacora_quien($pdo, 'alumnos', $id); }
    }
    $pdo->commit();
    grupos_sincronizar_aulas($pdo, $grupos_tocados);

    if ($inscritos || $cambiados) {
        bitacora($pdo, 'grupos', 'inscripcion', 'Cargó una lista (' . $carga['archivo'] . ') al grupo ' . bitacora_quien($pdo, 'grupo', $id_grupo)
            . ": $inscritos inscrito(s)" . ($cambiados ? ", $cambiados cambiado(s) de grupo" : ''), 'grupo', $id_grupo, ['alumnos' => array_slice($detalle, 0, 200)]);
    }
    unset($_SESSION['carga_grupo']);
    $volver('carga=' . urlencode("$inscritos|$cambiados|$omitidos"));
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('inscribirAlumnos_excel: ' . $e->getMessage());
    $volver('error_carga=' . urlencode('No se pudo guardar la inscripción. Intenta de nuevo.'));
}
