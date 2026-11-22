<?php
/**
 * procesos/promover_semestre.php — Cierre de ciclo desde "Gestión de Grupos".
 * Requiere POST con confirmacion = "PROMOVER".
 */
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

function volver_grupos(string $qs): void {
    header("Location: ../admin/gestion_grupos.php?$qs");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strtoupper(trim($_POST['confirmacion'] ?? '')) !== 'PROMOVER') {
    volver_grupos('msg=promover_error&detalle=' . urlencode('Debes escribir PROMOVER para confirmar.'));
}

try {
    $pdo->beginTransaction();

    // 1. Alumnos de 6° semestre: egresan (Finalizado) y se cierra su inscripción en el grupo
    $egresados = $pdo->exec("
        UPDATE ALUMNOS a
        INNER JOIN GRUPO_ALUMNO ga ON a.id_alumno = ga.id_alumno AND ga.fecha_baja IS NULL
        INNER JOIN GRUPOS g ON ga.id_grupo = g.id_grupo AND g.semestre >= 6 AND g.estatus = 'Activo'
        SET a.estatus = 'Finalizado', ga.fecha_baja = CURDATE()
    ");

    // 2. Desactivar los grupos de 6°
    $pdo->exec("UPDATE GRUPOS SET estatus = 'Inactivo' WHERE semestre >= 6 AND estatus = 'Activo'");

    // 3. Avanzar el semestre de los grupos activos restantes
    $promovidos = $pdo->exec("UPDATE GRUPOS SET semestre = semestre + 1 WHERE estatus = 'Activo' AND semestre < 6");

    // 4. Avanzar el semestre individual de los alumnos en Alta
    $pdo->exec("UPDATE ALUMNOS SET semestre = semestre + 1 WHERE estatus = 'Alta' AND semestre IS NOT NULL AND semestre < 6");

    $pdo->commit();
    bitacora($pdo, 'grupos', 'cierre_ciclo', 'Cerró el ciclo: ' . (int)$promovidos . ' grupos promovidos al siguiente semestre y ' . (int)ceil($egresados / 2) . ' alumnos egresados');
    // Nota: el UPDATE multitabla cuenta filas de ALUMNOS y GRUPO_ALUMNO; cada egresado modifica 2 filas
    volver_grupos('msg=ciclo_cerrado&promovidos=' . (int)$promovidos . '&egresados=' . (int)ceil($egresados / 2));
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al promover semestre: " . $e->getMessage());
    volver_grupos('msg=promover_error&detalle=' . urlencode('Error de base de datos al promover el semestre.'));
}
