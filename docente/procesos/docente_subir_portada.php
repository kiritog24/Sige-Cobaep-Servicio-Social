<?php
/**
 * docente/procesos/docente_subir_portada.php — Portada de una materia (aula virtual).
 * Imagen JPG/PNG/WEBP de máximo 2 MB. La portada anterior se borra del servidor.
 */
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../mis_grupos.php");
    exit();
}

$id_clase = (int)($_POST['id_clase'] ?? 0);
$id_grupo = (int)($_POST['id_grupo'] ?? 0);

function volver_aula(int $id_grupo, string $tipo, string $msg): void {
    header("Location: ../gestionar_aula.php?id_grupo=$id_grupo&$tipo=" . urlencode($msg));
    exit();
}

// El archivo superó el límite de PHP (post_max_size): PHP vacía $_POST
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header("Location: ../mis_grupos.php?error=" . urlencode('La imagen es demasiado pesada. El límite es de ' . PORTADA_MAX_MB . ' MB.'));
    exit();
}

require_once __DIR__ . '/permisos.php';
if ($id_clase <= 0 || !docente_dueno_clase($pdo, $id_clase)) docente_denegar(false);

// La materia se toma de la clase del docente (no del formulario)
$st = $pdo->prepare("SELECT m.id_materia, m.imagen_portada FROM clases_regulares c INNER JOIN materias m ON m.id_materia = c.id_materia WHERE c.id_clase = ?");
$st->execute([$id_clase]);
$materia = $st->fetch(PDO::FETCH_ASSOC);
if (!$materia) volver_aula($id_grupo, 'error', 'La materia no existe.');

$f = $_FILES['imagen_portada'] ?? null;
if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) volver_aula($id_grupo, 'error', 'No se seleccionó ninguna imagen.');
if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > PORTADA_MAX_MB * 1024 * 1024) {
    volver_aula($id_grupo, 'error', 'La imagen es muy pesada. El límite es de ' . PORTADA_MAX_MB . ' MB.');
}
if ($f['error'] !== UPLOAD_ERR_OK) volver_aula($id_grupo, 'error', 'No se pudo subir la imagen. Intenta de nuevo.');

// Validar que realmente sea una imagen (por su contenido, no por el nombre)
$tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$info = @getimagesize($f['tmp_name']);
if (!$info || !isset($tipos[$info['mime']])) volver_aula($id_grupo, 'error', 'Formato no válido. Solo se permiten imágenes JPG, PNG o WEBP.');

$dir = __DIR__ . '/../../uploads/portadas_materias/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$nombre = 'portada_mat_' . (int)$materia['id_materia'] . '_' . time() . '.' . $tipos[$info['mime']];
$ruta_bd = 'uploads/portadas_materias/' . $nombre;

if (!move_uploaded_file($f['tmp_name'], $dir . $nombre)) volver_aula($id_grupo, 'error', 'Error al guardar la imagen en el servidor.');

try {
    $pdo->prepare("UPDATE materias SET imagen_portada = ? WHERE id_materia = ?")->execute([$ruta_bd, (int)$materia['id_materia']]);
    reemplazar_foto($materia['imagen_portada'], $ruta_bd);   // la portada anterior se elimina del servidor
    volver_aula($id_grupo, 'exito', 'Portada actualizada correctamente.');
} catch (PDOException $e) {
    @unlink($dir . $nombre);
    error_log('docente_subir_portada: ' . $e->getMessage());
    volver_aula($id_grupo, 'error', 'No se pudo guardar la portada.');
}
