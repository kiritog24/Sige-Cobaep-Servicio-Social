<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_alumno = $_SESSION['id_usuario'];
    $id_actividad = isset($_POST['id_actividad']) ? (int)$_POST['id_actividad'] : 0;
    $id_clase = isset($_POST['id_clase']) ? (int)$_POST['id_clase'] : 0;

    if ($id_actividad === 0 || $id_clase === 0) {
        header("Location: ../actividades_escolares.php");
        exit();
    }

    try {
        // 1. La entrega debe ser del alumno, de esa clase, sin revisar y dentro del plazo
        asegurar_columnas_actividades($pdo);
        $stmt_archivo = $pdo->prepare("
            SELECT e.id_entrega, e.archivo_ruta, e.revisada, ac.fecha_limite, ac.acepta_retardo, va.fecha_limite_excepcion
            FROM entregas_actividad e
            INNER JOIN actividades_clase ac ON ac.id_actividad = e.id_actividad AND ac.id_clase = ?
            LEFT JOIN visibilidad_actividad va ON va.id_actividad = e.id_actividad AND va.id_alumno = e.id_alumno
            WHERE e.id_actividad = ? AND e.id_alumno = ?");
        $stmt_archivo->execute([$id_clase, $id_actividad, $id_alumno]);
        $entrega = $stmt_archivo->fetch(PDO::FETCH_ASSOC);
        if (!$entrega) {
            header("Location: ../aula_virtual.php?id=" . $id_clase . "&error=" . urlencode('No encontramos tu entrega.'));
            exit();
        }
        if ((int)$entrega['revisada'] === 1) {
            header("Location: ../aula_virtual.php?id=" . $id_clase . "&error=" . urlencode('Tu profesor ya revisó esta entrega; ya no se puede quitar.'));
            exit();
        }
        $limite = $entrega['fecha_limite_excepcion'] ?: $entrega['fecha_limite'];
        if ($limite && strtotime($limite) < time() && (int)$entrega['acepta_retardo'] !== 1) {
            header("Location: ../aula_virtual.php?id=" . $id_clase . "&error=" . urlencode('La fecha límite ya pasó; ya no puedes quitar tu envío.'));
            exit();
        }

        // 2. Quitar la entrega (sus archivos se borran en cascada en la BD) y luego los archivos del servidor
        $rutas = rutas_archivos_entrega($pdo, [(int)$entrega['id_entrega']]);
        $pdo->prepare("DELETE FROM entregas_actividad WHERE id_entrega = ?")->execute([(int)$entrega['id_entrega']]);
        borrar_archivos_fisicos($rutas);

        header("Location: ../aula_virtual.php?id=" . $id_clase . "&exito=Envío eliminado correctamente.");
        exit();

    } catch (PDOException $e) {
        header("Location: ../aula_virtual.php?id=" . $id_clase . "&error=Error al eliminar el envío.");
        exit();
    }
}
?>