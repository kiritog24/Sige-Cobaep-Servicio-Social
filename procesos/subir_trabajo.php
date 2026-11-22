<?php
// procesos/subir_trabajo.php — Entrega del alumno en una ACTIVIDAD de un espacio de regularización.
// Cada actividad tiene su propio espacio de entrega con tipos de archivo, peso máximo,
// fecha límite y si acepta retardos (lo configura el docente).
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/regula_entregas.php';
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_clase     = (int)($_POST['id_clase'] ?? 0);
$id_actividad = (int)($_POST['id_actividad'] ?? 0);
$id_alumno    = (int)$_SESSION['id_usuario'];

function volver_espacio(int $id_clase, string $tipo, string $msg, int $id_act = 0): void {
    $ancla = $id_act ? "#act-$id_act" : '';
    header("Location: ../estudiante/espacio_regularizacion.php?id=$id_clase&$tipo=" . urlencode($msg) . $ancla);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../estudiante/actividades.php");
    exit();
}
// Archivo más grande que post_max_size: PHP vacía $_POST
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header("Location: ../estudiante/actividades.php?error=" . urlencode('El archivo es demasiado grande.'));
    exit();
}

// El alumno debe estar inscrito al espacio
$st = $pdo->prepare("
    SELECT c.permitir_entregas, c.estatus, c.visible
    FROM CLASE_ALUMNO ca INNER JOIN CLASES_REGULARIZACION c ON c.id_clase = ca.id_clase
    WHERE ca.id_clase = ? AND ca.id_alumno = ?");
$st->execute([$id_clase, $id_alumno]);
$clase = $st->fetch(PDO::FETCH_ASSOC);
if (!$clase) {
    header("Location: ../estudiante/actividades.php?error=" . urlencode('No tienes acceso a este espacio.'));
    exit();
}
if ((int)$clase['visible'] !== 1) {
    header("Location: ../estudiante/actividades.php?error=" . urlencode('Este curso de regularización ya no está disponible.'));
    exit();
}

// La actividad debe pertenecer al espacio
$st = $pdo->prepare("SELECT * FROM ACTIVIDADES_REGULARIZACION WHERE id_actividad = ? AND id_clase = ?");
$st->execute([$id_actividad, $id_clase]);
$act = $st->fetch(PDO::FETCH_ASSOC);
if (!$act) volver_espacio($id_clase, 'error', 'La actividad no existe.');

if ((int)$clase['permitir_entregas'] !== 1 || $clase['estatus'] !== 'Activa' || (int)$act['permite_entregas'] !== 1) {
    volver_espacio($id_clase, 'error', 'El docente no está recibiendo trabajos para esta actividad.', $id_actividad);
}

// Fecha límite y retardos
$estatus = 'A tiempo';
if (!empty($act['fecha_limite']) && strtotime($act['fecha_limite']) < time()) {
    if ((int)$act['acepta_retardo'] !== 1) {
        volver_espacio($id_clase, 'error', 'La actividad ya cerró: pasó la fecha límite y no acepta retardos.', $id_actividad);
    }
    $estatus = 'Con retardo';
}

$f = $_FILES['archivo_trabajo'] ?? null;
$max_mb = (int)$act['tamano_maximo_mb'];
if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) volver_espacio($id_clase, 'error', 'No se adjuntó ningún archivo.', $id_actividad);
if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $max_mb * 1024 * 1024) {
    volver_espacio($id_clase, 'error', "El archivo no debe pesar más de $max_mb MB.", $id_actividad);
}
if ($f['error'] !== UPLOAD_ERR_OK) volver_espacio($id_clase, 'error', 'No se pudo subir el archivo. Intenta de nuevo.', $id_actividad);

$nombre_original = basename($f['name']);
$nombre_seguro   = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre_original);
$extension = strtolower(pathinfo($nombre_seguro, PATHINFO_EXTENSION));
$permitidas = regula_extensiones($act['tipos_archivo']);
if (!in_array($extension, $permitidas, true)) {
    volver_espacio($id_clase, 'error', 'Formato no permitido. Esta actividad solo acepta: ' . regula_tipos_texto($act['tipos_archivo']) . '.', $id_actividad);
}

$directorio = __DIR__ . '/../uploads/entregas/';
if (!is_dir($directorio)) mkdir($directorio, 0755, true);
$nombre_nuevo = uniqid('entrega_') . '_' . $id_alumno . '_' . $nombre_seguro;
if (!move_uploaded_file($f['tmp_name'], $directorio . $nombre_nuevo)) {
    volver_espacio($id_clase, 'error', 'No se pudo guardar el archivo en el servidor.', $id_actividad);
}
$ruta = 'uploads/entregas/' . $nombre_nuevo;

try {
    $st = $pdo->prepare("SELECT id_entrega, archivo_ruta, calificacion FROM ENTREGAS_REGULARIZACION WHERE id_actividad = ? AND id_alumno = ? LIMIT 1");
    $st->execute([$id_actividad, $id_alumno]);
    $previa = $st->fetch(PDO::FETCH_ASSOC);

    if ($previa) {
        if ($previa['calificacion'] !== null) {
            @unlink($directorio . $nombre_nuevo);
            volver_espacio($id_clase, 'error', 'Tu trabajo ya fue calificado; ya no se puede reemplazar.', $id_actividad);
        }
        $pdo->prepare("UPDATE ENTREGAS_REGULARIZACION SET archivo_ruta = ?, nombre_original = ?, estatus_entrega = ?, fecha_entrega = NOW() WHERE id_entrega = ?")
            ->execute([$ruta, $nombre_original, $estatus, $previa['id_entrega']]);
        if (strpos($previa['archivo_ruta'], 'uploads/entregas/') === 0 && is_file(__DIR__ . '/../' . $previa['archivo_ruta'])) {
            unlink(__DIR__ . '/../' . $previa['archivo_ruta']);
        }
        volver_espacio($id_clase, 'exito', 'Tu entrega de «' . $act['titulo'] . '» se reemplazó correctamente.', $id_actividad);
    }

    $pdo->prepare("INSERT INTO ENTREGAS_REGULARIZACION (id_clase, id_actividad, id_alumno, archivo_ruta, nombre_original, estatus_entrega) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$id_clase, $id_actividad, $id_alumno, $ruta, $nombre_original, $estatus]);
    volver_espacio($id_clase, 'exito', 'Entregaste «' . $act['titulo'] . '»' . ($estatus === 'Con retardo' ? ' (con retardo).' : '.'), $id_actividad);
} catch (PDOException $e) {
    @unlink($directorio . $nombre_nuevo);
    error_log("Error al subir entrega: " . $e->getMessage());
    volver_espacio($id_clase, 'error', 'Hubo un error al guardar tu trabajo.', $id_actividad);
}
