<?php
/**
 * admin/Procesos/inscribir_multiples.php — Inscribe alumnos existentes a un grupo (desde Ver Grupo).
 * Si el alumno estaba en otro grupo activo, se le da de baja de ese grupo (cambio de grupo).
 * Si ya había estado antes en este grupo, se reactiva su inscripción.
 */
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../gestion_grupos.php");
    exit();
}

$id_grupo = (int)($_POST['id_grupo'] ?? 0);
$alumnos = array_values(array_unique(array_filter(array_map('intval', $_POST['alumnos_seleccionados'] ?? []))));
if ($id_grupo <= 0) {
    header("Location: ../gestion_grupos.php");
    exit();
}
if (!$alumnos) {
    header("Location: ../ver_grupo.php?id=$id_grupo");
    exit();
}

try {
    $st = $pdo->prepare("SELECT semestre, turno FROM GRUPOS WHERE id_grupo = ?");
    $st->execute([$id_grupo]);
    $grupo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) {
        header("Location: ../gestion_grupos.php");
        exit();
    }

    $pdo->beginTransaction();
    $baja_otro = $pdo->prepare("UPDATE GRUPO_ALUMNO SET fecha_baja = CURDATE() WHERE id_alumno = ? AND id_grupo <> ? AND fecha_baja IS NULL");
    $inscribir = $pdo->prepare("INSERT INTO GRUPO_ALUMNO (id_grupo, id_alumno, fecha_asignacion) VALUES (?, ?, CURDATE())
                                ON DUPLICATE KEY UPDATE fecha_baja = NULL, fecha_asignacion = CURDATE()");
    $sync = $pdo->prepare("UPDATE ALUMNOS SET semestre = ?, turno = ? WHERE id_alumno = ?");
    $cambiados = 0;
    foreach ($alumnos as $id_alumno) {
        $baja_otro->execute([$id_alumno, $id_grupo]);
        $cambiados += $baja_otro->rowCount() > 0 ? 1 : 0;
        $inscribir->execute([$id_grupo, $id_alumno]);
        $sync->execute([$grupo['semestre'], $grupo['turno'], $id_alumno]);
    }
    $pdo->commit();
    header("Location: ../ver_grupo.php?id=$id_grupo&msg=inscritos_multiples&cantidad=" . count($alumnos) . "&cambiados=$cambiados");
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('inscribir_multiples: ' . $e->getMessage());
    header("Location: ../ver_grupo.php?id=$id_grupo&msg=error_inscribir");
    exit();
}
