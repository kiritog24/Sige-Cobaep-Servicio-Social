<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../asignacion_masiva.php");
    exit();
}

$id_grupo = isset($_POST['id_grupo']) ? (int)$_POST['id_grupo'] : 0;
$maestros = array_values(array_unique(array_filter(array_map('intval', $_POST['maestros_seleccionados'] ?? []))));

if ($id_grupo <= 0 || count($maestros) === 0) {
    header("Location: ../asignacion_masiva.php?msg=vacio");
    exit();
}

try {
    $pdo->beginTransaction();
    // UNIQUE(id_grupo, id_maestro) + INSERT IGNORE: si ya estaba asignado no se duplica
    $stmt = $pdo->prepare("INSERT IGNORE INTO GRUPO_MAESTRO (id_grupo, id_maestro, fecha_asignacion) VALUES (?, ?, CURDATE())");
    $nuevos = 0;
    foreach ($maestros as $id_maestro) {
        $stmt->execute([$id_grupo, $id_maestro]);
        $nuevos += $stmt->rowCount();
    }
    $pdo->commit();

    $duplicados = count($maestros) - $nuevos;
    if ($nuevos) bitacora($pdo, 'docentes', 'asignacion', "Asignó $nuevos docente(s) al grupo " . bitacora_quien($pdo, 'grupo', $id_grupo), 'grupo', $id_grupo,
                          ['docentes' => array_map(fn($i) => bitacora_quien($pdo, 'maestros', $i), $maestros)]);
    header("Location: ../asignacion_masiva.php?msg=exito&nuevos=$nuevos&duplicados=$duplicados&grupo=$id_grupo");
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("asignacion_masiva: " . $e->getMessage());
    header("Location: ../asignacion_masiva.php?msg=error");
    exit();
}
