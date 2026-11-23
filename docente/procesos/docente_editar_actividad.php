<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recepción de datos básicos
    $id_actividad = (int)$_POST['id_actividad'];
    $id_clase = (int)$_POST['id_clase'];
    require_once __DIR__ . '/permisos.php';
    if (!docente_dueno_actividad($pdo, $id_actividad) || !docente_dueno_clase($pdo, $id_clase)) docente_denegar(false);
    $titulo = trim($_POST['titulo']);
    $descripcion = trim($_POST['descripcion']);
    
    // Recepción de Fechas y Checkboxes
    $fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
    $fecha_apertura = !empty($_POST['fecha_apertura']) ? $_POST['fecha_apertura'] : null;
    $permite_entregas = isset($_POST['permite_entregas']) ? 1 : 0;
    $acepta_retardo = isset($_POST['acepta_retardo']) ? 1 : 0;

    $tamano = tamano_archivo_valido($_POST['tamano_maximo'] ?? 10);
    $archivos = numero_archivos_valido($_POST['numero_archivos'] ?? 1);
    $tipo = tipos_archivo_desde_post($_POST);
    $solo_asistentes = isset($_POST['solo_asistentes']) ? 1 : 0;

    $volver_error = function (string $msg) use ($id_clase) {
        header("Location: ../gestionar_aula.php?id_clase=$id_clase&error=" . urlencode($msg));
        exit();
    };
    if ($titulo === '') $volver_error('El título es obligatorio.');
    $ts_apertura = $fecha_apertura ? strtotime($fecha_apertura) : null;
    $ts_limite = $fecha_limite ? strtotime($fecha_limite) : null;
    if (($fecha_apertura && !$ts_apertura) || ($fecha_limite && !$ts_limite)) $volver_error('Revisa las fechas: alguna no es válida.');
    if ($ts_apertura && $ts_limite && $ts_limite <= $ts_apertura) $volver_error('La fecha límite debe ser después de la fecha de apertura.');
    $fecha_apertura = $ts_apertura ? date('Y-m-d H:i:s', $ts_apertura) : null;
    $fecha_limite = $ts_limite ? date('Y-m-d H:i:s', $ts_limite) : null;

    asegurar_columnas_actividades($pdo);
    // Fecha del pase de lista que revisa el filtro: si ya estaba activo se conserva, salvo que cambie la apertura
    $previa = $pdo->prepare("SELECT solo_asistentes, fecha_filtro, fecha_apertura, archivo_ruta FROM actividades_clase WHERE id_actividad = ?");
    $previa->execute([$id_actividad]);
    $previa = $previa->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$solo_asistentes) $fecha_filtro = null;
    elseif (!empty($previa['solo_asistentes']) && !empty($previa['fecha_filtro']) && ($previa['fecha_apertura'] ?? null) == $fecha_apertura) $fecha_filtro = $previa['fecha_filtro'];
    else $fecha_filtro = date('Y-m-d', $ts_apertura ?: time());

    $ruta_bd = null;

    try {
        // Validación y subida de archivo (Se queda igual)
        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $archivo_prof = $_FILES['archivo'];
            if ($archivo_prof['size'] > 10485760) $volver_error('El archivo adjunto pesa más de 10 MB.');
            
            // Ruta centralizada para materiales de clase
            $carpeta_destino = '../../uploads/materiales_clase/';   // la misma carpeta que usa el alumno para descargar
            if (!is_dir($carpeta_destino)) {
                mkdir($carpeta_destino, 0777, true);
            }

            // Limpieza de nombre y extensión
            $extension = strtolower(pathinfo($archivo_prof['name'], PATHINFO_EXTENSION));
        if (!extension_permitida($extension)) {
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../mis_grupos.php') . (strpos($_SERVER['HTTP_REFERER'] ?? '', '?') !== false ? '&' : '?') . 'error=' . urlencode('Tipo de archivo no permitido. Sube documentos, imágenes, ZIP, audio o video.'));
            exit();
        }
            $nombre_original = pathinfo($archivo_prof['name'], PATHINFO_FILENAME);
            $nombre_limpio = preg_replace("/[^a-zA-Z0-9_-]/", "_", $nombre_original);
            
            // Prefijo "mat_" (material) + id_actividad para que sea único
            $nuevo_nombre = "mat_act" . $id_actividad . "_" . time() . "_" . $nombre_limpio . "." . $extension;
            $ruta_final = $carpeta_destino . $nuevo_nombre;

            if (move_uploaded_file($archivo_prof['tmp_name'], $ruta_final)) {
                $ruta_bd = $nuevo_nombre;
            }
        }

        $campos = "titulo = ?, descripcion = ?, fecha_limite = ?, fecha_apertura = ?, permite_entregas = ?, acepta_retardo = ?, tamano_maximo = ?, numero_archivos = ?, tipo_archivo = ?, solo_asistentes = ?, fecha_filtro = ?";
        $valores = [$titulo, $descripcion, $fecha_limite, $fecha_apertura, $permite_entregas, $acepta_retardo, $tamano, $archivos, $tipo, $solo_asistentes, $fecha_filtro];
        if ($ruta_bd) { $campos .= ", archivo_ruta = ?"; $valores[] = $ruta_bd; }
        $valores[] = $id_actividad; $valores[] = $id_clase;
        $pdo->prepare("UPDATE actividades_clase SET $campos WHERE id_actividad = ? AND id_clase = ?")->execute($valores);
        // El material anterior se borra del servidor al reemplazarlo
        if ($ruta_bd && !empty($previa['archivo_ruta'])) {
            foreach (['materiales_clase', 'materiales'] as $dir) {
                $viejo = __DIR__ . '/../../uploads/' . $dir . '/' . basename($previa['archivo_ruta']);
                if (is_file($viejo)) @unlink($viejo);
            }
        }

        header("Location: ../gestionar_aula.php?id_clase=$id_clase&exito=" . urlencode("Actividad actualizada correctamente."));
        exit();
    } catch (PDOException $e) {
        error_log('docente_editar_actividad: ' . $e->getMessage());
        header("Location: ../gestionar_aula.php?id_clase=$id_clase&error=" . urlencode("No se pudo actualizar la actividad."));
        exit();
    }
}
?>