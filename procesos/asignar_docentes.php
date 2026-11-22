<?php
/**
 * Asignación de docentes a un grupo desde "Ver Grupo".
 * - modo "sync" (por defecto): los docentes marcados quedan asignados y los desmarcados se quitan.
 * - accion "quitar": elimina un solo docente del grupo.
 * La tabla GRUPO_MAESTRO tiene UNIQUE(id_grupo, id_maestro), así que nunca se duplican.
 */
session_start();
require_once '../config/database.php';
exigir_mismo_origen(); // evita que un enlace externo dispare la acción

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

$id_grupo = isset($_POST['id_grupo']) ? (int)$_POST['id_grupo'] : (isset($_GET['id_grupo']) ? (int)$_GET['id_grupo'] : 0);
if ($id_grupo <= 0) {
    header("Location: ../admin/gestion_grupos.php");
    exit();
}

try {
    // Quitar un docente puntual
    if (($_POST['accion'] ?? '') === 'quitar') {
        $id_maestro = (int)($_POST['id_maestro'] ?? 0);
        $pdo->prepare("DELETE FROM GRUPO_MAESTRO WHERE id_grupo = ? AND id_maestro = ?")->execute([$id_grupo, $id_maestro]);
        bitacora($pdo, 'docentes', 'quitar', 'Quitó a ' . bitacora_quien($pdo, 'maestros', $id_maestro) . ' del grupo ' . bitacora_quien($pdo, 'grupo', $id_grupo), 'grupo', $id_grupo);
        header("Location: ../admin/ver_grupo.php?id=$id_grupo&msg=docente_quitado");
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../admin/ver_grupo.php?id=$id_grupo");
        exit();
    }

    $seleccion = array_values(array_unique(array_filter(array_map('intval', $_POST['maestros_seleccionados'] ?? []))));

    $pdo->beginTransaction();

    $stmtAct = $pdo->prepare("SELECT id_maestro FROM GRUPO_MAESTRO WHERE id_grupo = ?");
    $stmtAct->execute([$id_grupo]);
    $actuales = array_map('intval', $stmtAct->fetchAll(PDO::FETCH_COLUMN));

    $nuevos   = array_diff($seleccion, $actuales);
    $quitados = array_diff($actuales, $seleccion);

    if ($nuevos) {
        $ins = $pdo->prepare("INSERT IGNORE INTO GRUPO_MAESTRO (id_grupo, id_maestro, fecha_asignacion) VALUES (?, ?, CURDATE())");
        foreach ($nuevos as $id_m) $ins->execute([$id_grupo, $id_m]);
    }
    if ($quitados) {
        $del = $pdo->prepare("DELETE FROM GRUPO_MAESTRO WHERE id_grupo = ? AND id_maestro = ?");
        foreach ($quitados as $id_m) $del->execute([$id_grupo, $id_m]);
    }

    $pdo->commit();
    if ($nuevos || $quitados) {
        bitacora($pdo, 'docentes', 'asignacion', 'Actualizó los docentes del grupo ' . bitacora_quien($pdo, 'grupo', $id_grupo) . ': ' . count($nuevos) . ' asignados, ' . count($quitados) . ' quitados', 'grupo', $id_grupo,
                 ['asignados' => array_map(fn($i) => bitacora_quien($pdo, 'maestros', $i), array_values($nuevos)), 'quitados' => array_map(fn($i) => bitacora_quien($pdo, 'maestros', $i), array_values($quitados))]);
    }
    header("Location: ../admin/ver_grupo.php?id=$id_grupo&msg=docentes_asignados&nuevos=" . count($nuevos) . "&quitados=" . count($quitados));
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("asignar_docentes: " . $e->getMessage());
    header("Location: ../admin/ver_grupo.php?id=$id_grupo&msg=error_docentes");
    exit();
}
