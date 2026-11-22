<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_clase = (int)$_POST['id_clase'];

    require_once __DIR__ . "/../docente/procesos/permisos.php";
    require_once __DIR__ . "/../includes/regula_entregas.php";
    if (!docente_dueno_regularizacion($pdo, $id_clase)) {
        header("Location: ../docente/regularizaciones.php?error=" . urlencode("No tienes permiso para modificar este espacio."));
        exit();
    }
    $id_bloque = !empty($_POST['id_bloque']) ? (int)$_POST['id_bloque'] : null; // NUEVO: Recibe el bloque
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    if (empty($titulo) || empty($id_clase)) {
        header("Location: ../docente/detalle_regularizacion.php?id=$id_clase&error=El título es obligatorio.");
        exit();
    }

    $ruta_archivo = null;

    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $directorio_destino = '../uploads/materiales/';
        if (!is_dir($directorio_destino)) { mkdir($directorio_destino, 0777, true); }

        $archivo_tmp = $_FILES['archivo']['tmp_name'];
        $nombre_original = str_replace(' ', '_', $_FILES['archivo']['name']);
        $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        
        $permitidas = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'zip', 'txt', 'mp4', 'mp3'];

        if (in_array($extension, $permitidas, true)) {
            $nombre_nuevo = uniqid('mat_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre_original);
            $ruta_final = $directorio_destino . $nombre_nuevo;

            if (move_uploaded_file($archivo_tmp, $ruta_final)) {
                $ruta_archivo = 'uploads/materiales/' . $nombre_nuevo; 
            }
        } else {
            header("Location: ../docente/detalle_regularizacion.php?id=$id_clase&error=Este tipo de archivo no está permitido.");
            exit();
        }
    }

    try {
        // Bloque válido solo si pertenece a este espacio
        if ($id_bloque) {
            $chk = $pdo->prepare("SELECT 1 FROM BLOQUES_REGULARIZACION WHERE id_bloque = ? AND id_clase = ?");
            $chk->execute([$id_bloque, $id_clase]);
            if (!$chk->fetchColumn()) $id_bloque = null;
        }
        // Configuración del espacio de entrega propio de esta actividad
        $cfg = regula_config_desde_post();
        $stmt = $pdo->prepare("INSERT INTO ACTIVIDADES_REGULARIZACION
            (id_clase, id_bloque, titulo, descripcion, archivo_ruta, permite_entregas, fecha_limite, acepta_retardo, tipos_archivo, tamano_maximo_mb, escala_calificacion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_clase, $id_bloque, $titulo, $descripcion, $ruta_archivo,
            $cfg['permite_entregas'], $cfg['fecha_limite'], $cfg['acepta_retardo'], $cfg['tipos_archivo'], $cfg['tamano_maximo_mb'], $cfg['escala_calificacion']]);

        $msg = $cfg['permite_entregas'] ? 'Actividad publicada con su propio espacio de entrega.' : 'Material publicado correctamente.';
        header("Location: ../docente/detalle_regularizacion.php?id=$id_clase&exito=" . urlencode($msg));
        exit();
    } catch (PDOException $e) {
        error_log("Error al subir material: " . $e->getMessage());
        header("Location: ../docente/detalle_regularizacion.php?id=$id_clase&error=Hubo un error al guardar el material.");
        exit();
    }
} else {
    header("Location: ../docente/regularizaciones.php");
    exit();
}