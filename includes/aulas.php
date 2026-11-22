<?php
/**
 * includes/aulas.php — Mantiene las aulas virtuales (materias) al día con los grupos.
 *
 * Cuando Control Escolar inscribe, cambia o da de baja a un alumno en un grupo, sus materias
 * deben cambiar también. Estas funciones se llaman al abrir el aula (docente) o las materias
 * (alumno), así nunca quedan desfasadas sin importar desde dónde se modificó el grupo.
 */

/** Inscribe en las materias activas del grupo a los alumnos activos que falten y quita a los que ya no pertenecen. */
function sincronizar_aulas_grupo(PDO $pdo, int $id_grupo): void {
    if ($id_grupo <= 0) return;
    try {
        $pdo->prepare("
            INSERT INTO CLASE_REGULAR_ALUMNO (id_clase, id_alumno, acceso)
            SELECT c.id_clase, ga.id_alumno, 1
            FROM CLASES_REGULARES c
            INNER JOIN GRUPO_ALUMNO ga ON ga.id_grupo = c.id_grupo AND ga.fecha_baja IS NULL
            INNER JOIN ALUMNOS a ON a.id_alumno = ga.id_alumno AND a.estatus = 'Alta'
            WHERE c.id_grupo = ? AND c.estatus = 'Activa'
              AND NOT EXISTS (SELECT 1 FROM CLASE_REGULAR_ALUMNO cra WHERE cra.id_clase = c.id_clase AND cra.id_alumno = ga.id_alumno)
        ")->execute([$id_grupo]);

        // Quien ya no está activo en el grupo deja de ver sus materias (sus entregas se conservan)
        $pdo->prepare("
            DELETE cra FROM CLASE_REGULAR_ALUMNO cra
            INNER JOIN CLASES_REGULARES c ON c.id_clase = cra.id_clase
            WHERE c.id_grupo = ?
              AND NOT EXISTS (SELECT 1 FROM GRUPO_ALUMNO ga WHERE ga.id_grupo = c.id_grupo AND ga.id_alumno = cra.id_alumno AND ga.fecha_baja IS NULL)
        ")->execute([$id_grupo]);
    } catch (PDOException $e) {
        error_log('sincronizar_aulas_grupo: ' . $e->getMessage());
    }
}

/** Sincroniza las materias de los grupos actuales de un alumno (y lo retira de grupos anteriores). */
function sincronizar_aulas_alumno(PDO $pdo, int $id_alumno): void {
    if ($id_alumno <= 0) return;
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT c.id_grupo FROM CLASES_REGULARES c
            WHERE c.id_grupo IN (SELECT id_grupo FROM GRUPO_ALUMNO WHERE id_alumno = ?)
               OR c.id_clase IN (SELECT id_clase FROM CLASE_REGULAR_ALUMNO WHERE id_alumno = ?)");
        $st->execute([$id_alumno, $id_alumno]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $g) sincronizar_aulas_grupo($pdo, (int)$g);
    } catch (PDOException $e) {
        error_log('sincronizar_aulas_alumno: ' . $e->getMessage());
    }
}

/** ¿El docente tiene asignado este grupo (por Control Escolar o por horario)? */
function docente_tiene_grupo(PDO $pdo, int $id_maestro, int $id_grupo): bool {
    $st = $pdo->prepare("SELECT 1 FROM GRUPO_MAESTRO WHERE id_grupo = ? AND id_maestro = ?
                         UNION SELECT 1 FROM HORARIOS_MAESTRO WHERE id_grupo = ? AND id_maestro = ? LIMIT 1");
    $st->execute([$id_grupo, $id_maestro, $id_grupo, $id_maestro]);
    return (bool)$st->fetchColumn();
}
