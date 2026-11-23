<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_clase = isset($_POST['id_clase']) ? (int)$_POST['id_clase'] : 0;
    require_once __DIR__ . '/permisos.php';
    if (!docente_dueno_clase($pdo, $id_clase)) docente_denegar(false);
    $titulo = isset($_POST['titulo']) ? trim($_POST['titulo']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    
    $fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
    $permite_entregas = isset($_POST['permite_entregas']) ? 1 : 0;
    $acepta_retardo = isset($_POST['acepta_retardo']) ? 1 : 0;
    $solo_asistentes = isset($_POST['solo_asistentes']) ? 1 : 0;

    // --- VARIABLES DE VISIBILIDAD Y PROGRAMACIÓN ---
    $visible = isset($_POST['visible']) ? 1 : 0;
    $fecha_apertura = !empty($_POST['fecha_apertura']) ? $_POST['fecha_apertura'] : null;

    // --- LÍMITES DE ARCHIVOS ---
    $tamano = tamano_archivo_valido($_POST['tamano_maximo'] ?? 10);
    $archivos = numero_archivos_valido($_POST['numero_archivos'] ?? 1);
    $tipo = tipos_archivo_desde_post($_POST);

    $volver_error = function (string $msg) use ($id_clase) {
        header("Location: ../gestionar_aula.php?id_clase=" . $id_clase . "&error=" . urlencode($msg));
        exit();
    };
    $ts_apertura = $fecha_apertura ? strtotime($fecha_apertura) : null;
    $ts_limite = $fecha_limite ? strtotime($fecha_limite) : null;
    if (($fecha_apertura && !$ts_apertura) || ($fecha_limite && !$ts_limite)) $volver_error('Revisa las fechas: alguna no es válida.');
    if ($ts_apertura && $ts_limite && $ts_limite <= $ts_apertura) $volver_error('La fecha límite debe ser después de la fecha de apertura.');
    $fecha_apertura = $ts_apertura ? date('Y-m-d H:i:s', $ts_apertura) : null;
    $fecha_limite = $ts_limite ? date('Y-m-d H:i:s', $ts_limite) : null;
    // El filtro de asistencia revisa el pase de lista del día en que la actividad se abre
    $fecha_filtro = $solo_asistentes ? date('Y-m-d', $ts_apertura ?: time()) : null;
    asegurar_columnas_actividades($pdo);

    if ($id_clase === 0 || empty($titulo)) {
        header("Location: ../gestionar_aula.php?id_clase=" . $id_clase . "&error=El título es obligatorio.");
        exit();
    }

    $ruta_bd = null;

    // Guardado del archivo adjunto del profesor (si envió uno)
    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $archivo_prof = $_FILES['archivo'];
        
        if ($archivo_prof['size'] > 10485760) {
            header("Location: ../gestionar_aula.php?id_clase=$id_clase&error=Archivo muy pesado (Max 10MB).");
            exit();
        }
        
        $carpeta_destino = '../../uploads/materiales_clase/';
        if (!is_dir($carpeta_destino)) { mkdir($carpeta_destino, 0777, true); }
        
        $extension = strtolower(pathinfo($archivo_prof['name'], PATHINFO_EXTENSION));
        if (!extension_permitida($extension)) {
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../mis_grupos.php') . (strpos($_SERVER['HTTP_REFERER'] ?? '', '?') !== false ? '&' : '?') . 'error=' . urlencode('Tipo de archivo no permitido. Sube documentos, imágenes, ZIP, audio o video.'));
            exit();
        }
        $nombre_original = pathinfo($archivo_prof['name'], PATHINFO_FILENAME);
        $nombre_limpio = preg_replace("/[^a-zA-Z0-9_-]/", "_", $nombre_original);
        
        $nuevo_nombre = "mat_" . time() . "_" . $nombre_limpio . "." . $extension;
        $ruta_final = $carpeta_destino . $nuevo_nombre;

        if (move_uploaded_file($archivo_prof['tmp_name'], $ruta_final)) {
            $ruta_bd = $nuevo_nombre;
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO actividades_clase (id_clase, titulo, descripcion, archivo_ruta, fecha_limite, permite_entregas, acepta_retardo, visible, fecha_apertura, tamano_maximo, numero_archivos, tipo_archivo, solo_asistentes, fecha_filtro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_clase, $titulo, $descripcion, $ruta_bd, $fecha_limite, $permite_entregas, $acepta_retardo, $visible, $fecha_apertura, $tamano, $archivos, $tipo, $solo_asistentes, $fecha_filtro]);
        // El filtro de asistencia se aplica al momento en que el alumno abre su aula (sql_actividad_visible),
        // así cuenta aunque el pase de lista se tome después de publicar.

        $pdo->commit();
        header("Location: ../gestionar_aula.php?id_clase=" . $id_clase . "&exito=Actividad guardada correctamente.");
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('docente_publicar_actividad: ' . $e->getMessage());
        header("Location: ../gestionar_aula.php?id_clase=" . $id_clase . "&error=" . urlencode("No se pudo guardar la actividad."));
        exit();
    }
}
?>