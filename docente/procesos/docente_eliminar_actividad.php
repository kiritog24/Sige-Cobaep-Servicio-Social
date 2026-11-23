<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_actividad = isset($_POST['id_actividad']) ? (int)$_POST['id_actividad'] : 0;
    $id_clase = isset($_POST['id_clase']) ? (int)$_POST['id_clase'] : 0;
    require_once __DIR__ . '/permisos.php';
    if (!docente_dueno_actividad($pdo, $id_actividad) || !docente_dueno_clase($pdo, $id_clase)) docente_denegar(false);

    if ($id_actividad === 0 || $id_clase === 0) {
        header("Location: ../mis_grupos.php");
        exit();
    }

    try {
        $stmt_check = $pdo->prepare("SELECT archivo_ruta FROM actividades_clase WHERE id_actividad = ? AND id_clase = ?");
        $stmt_check->execute([$id_actividad, $id_clase]);
        $actividad = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($actividad) {
            // Archivos en el servidor: el material del docente y lo que entregaron los alumnos
            $borrar = [];
            if (!empty($actividad['archivo_ruta'])) {
                foreach (['materiales_clase', 'materiales'] as $dir) $borrar[] = __DIR__ . '/../../uploads/' . $dir . '/' . basename($actividad['archivo_ruta']);
            }
            asegurar_columnas_actividades($pdo);
            $st = $pdo->prepare("SELECT id_entrega FROM entregas_actividad WHERE id_actividad = ?");
            $st->execute([$id_actividad]);
            $borrar = array_merge($borrar, rutas_archivos_entrega($pdo, $st->fetchAll(PDO::FETCH_COLUMN)));

            $pdo->prepare("DELETE FROM visibilidad_actividad WHERE id_actividad = ?")->execute([$id_actividad]);
            $stmt_delete = $pdo->prepare("DELETE FROM actividades_clase WHERE id_actividad = ?");
            $stmt_delete->execute([$id_actividad]);   // las entregas se borran en cascada
            foreach ($borrar as $f) if (is_file($f)) @unlink($f);

            header("Location: ../gestionar_aula.php?id_clase=" . $id_clase . "&exito=Actividad eliminada.");
            exit();
        }
    } catch (PDOException $e) {
        header("Location: ../gestionar_aula.php?id_clase=" . $id_clase . "&error=Error interno.");
        exit();
    }
}
?>