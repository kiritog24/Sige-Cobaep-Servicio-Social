<?php
/**
 * estudiante/procesos/subir_tarea_clase.php — Entrega de una actividad del aula virtual.
 * Acepta uno o varios archivos, según lo que configuró el docente (cantidad, tipos y peso por archivo).
 */
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: ../actividades_escolares.php"); exit(); }

$id_alumno = (int)$_SESSION['id_usuario'];
$id_clase = (int)($_POST['id_clase'] ?? ($_GET['id_clase'] ?? 0));
$volver = function (string $tipo, string $msg) use ($id_clase) {
    header("Location: " . ($id_clase ? "../aula_virtual.php?id=$id_clase&" : "../actividades_escolares.php?") . "$tipo=" . urlencode($msg));
    exit();
};

if (envio_demasiado_grande()) {
    $volver('error', 'Tus archivos pesan demasiado para enviarlos juntos (máximo ' . limites_servidor()['total_mb'] . ' MB en total). Reduce su tamaño, por ejemplo convirtiéndolos a PDF.');
}
$id_actividad = (int)($_POST['id_actividad'] ?? 0);
if ($id_actividad === 0 || $id_clase === 0) { header("Location: ../actividades_escolares.php"); exit(); }

asegurar_columnas_actividades($pdo);
try {
    // La actividad debe estar publicada, abierta y visible para este alumno
    $stmt_act = $pdo->prepare("
        SELECT ac.fecha_limite, ac.permite_entregas, ac.acepta_retardo, ac.tipo_archivo, ac.tamano_maximo, ac.numero_archivos,
               va.fecha_limite_excepcion
        FROM actividades_clase ac
        INNER JOIN clase_regular_alumno cra ON cra.id_clase = ac.id_clase AND cra.id_alumno = ? AND cra.acceso = 1
        LEFT JOIN visibilidad_actividad va ON ac.id_actividad = va.id_actividad AND va.id_alumno = ?
        WHERE ac.id_actividad = ? AND ac.id_clase = ? AND ac.visible = 1
          AND (ac.fecha_apertura IS NULL OR ac.fecha_apertura <= ?)
          AND " . sql_actividad_visible($id_alumno));
    $stmt_act->execute([$id_alumno, $id_alumno, $id_actividad, $id_clase, date('Y-m-d H:i:s')]);
    $actividad = $stmt_act->fetch(PDO::FETCH_ASSOC);
    if (!$actividad || (int)$actividad['permite_entregas'] === 0) $volver('error', 'Esta actividad no acepta entregas.');

    // Una sola entrega por actividad: para cambiarla se usa "Editar envío"
    $ya = $pdo->prepare("SELECT 1 FROM entregas_actividad WHERE id_actividad = ? AND id_alumno = ?");
    $ya->execute([$id_actividad, $id_alumno]);
    if ($ya->fetchColumn()) $volver('error', 'Ya habías entregado esta actividad. Si quieres cambiar tus archivos usa "Editar envío".');

    // ¿A tiempo o con retardo? (considera la extensión individual de fecha)
    $estatus_entrega = 'A Tiempo';
    $limite = $actividad['fecha_limite_excepcion'] ?: $actividad['fecha_limite'];
    if ($limite && time() > strtotime($limite)) {
        if ((int)$actividad['acepta_retardo'] === 0) $volver('error', 'La fecha límite ya pasó.');
        $estatus_entrega = 'Con Retardo';
    }

    $archivos = lista_archivos_subidos('archivo_tarea');
    if ($error = validar_archivos_entrega($archivos, $actividad)) $volver('error', $error);
    $guardados = guardar_archivos_entrega($archivos, $id_alumno);
    if (!$guardados) $volver('error', 'No se pudieron guardar tus archivos en el servidor. Vuelve a intentarlo.');

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO entregas_actividad (id_actividad, id_alumno, archivo_ruta, fecha_entrega, estatus_entrega) VALUES (?, ?, ?, ?, ?)")
            ->execute([$id_actividad, $id_alumno, $guardados[0]['ruta'], date('Y-m-d H:i:s'), $estatus_entrega]);
        registrar_archivos_entrega($pdo, (int)$pdo->lastInsertId(), $guardados);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        borrar_archivos_fisicos(array_map(fn($g) => CARPETA_ENTREGAS . $g['ruta'], $guardados));
        throw $e;
    }
    $n = count($guardados);
    $volver('exito', $n === 1 ? 'Tarea enviada correctamente.' : "Tarea enviada correctamente con $n archivos.");
} catch (PDOException $e) {
    error_log('subir_tarea_clase: ' . $e->getMessage());
    $volver('error', 'Error al procesar la entrega.');
}
