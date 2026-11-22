<?php
/**
 * docente/procesos/docente_guardar_asistencia.php — Guarda el pase de lista del docente.
 *
 * Reglas:
 *  - Solo el docente dueño de la materia, solo alumnos inscritos en ella.
 *  - Solo los estatus válidos (Presente, Retardo, Falta, Justificada).
 *  - Nunca días futuros ni días sin clases (fines de semana y suspensiones de Prefectura).
 *  - Solo se guardan los alumnos que el docente marcó; los demás quedan pendientes.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') { header("Location: ../../login.php"); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: ../mis_grupos.php"); exit(); }

$id_clase = (int)($_POST['id_clase'] ?? 0);
require_once __DIR__ . '/permisos.php';
if (!docente_dueno_clase($pdo, $id_clase)) docente_denegar(false);

// Regresa a donde estaba trabajando el docente (solo páginas propias del portal docente)
$volver = "../gestionar_aula.php?id_clase=$id_clase";
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '') && strpos((string)parse_url($ref, PHP_URL_PATH), '/docente/') !== false) {
    $volver = preg_replace('/([?&])(exito|error)=[^&]*/', '$1', $ref);
    $volver = rtrim($volver, '?&');
}
$ir = function (string $tipo, string $texto) use ($volver) {
    header('Location: ' . $volver . (strpos($volver, '?') !== false ? '&' : '?') . $tipo . '=' . urlencode($texto));
    exit();
};

$fecha = $_POST['fecha_asistencia'] ?? '';
if (!fecha_valida($fecha)) $ir('error', 'La fecha del pase de lista no es válida.');
if ($fecha > date('Y-m-d')) $ir('error', 'No se puede pasar lista de un día que todavía no llega.');
if ($motivo = dia_sin_clases($pdo, $fecha)) $ir('error', "Ese día no hay clases ($motivo). No se guardó la asistencia.");

$marcados = is_array($_POST['asistencia'] ?? null) ? $_POST['asistencia'] : [];
if (!$marcados) $ir('error', 'No marcaste a ningún alumno.');

try {
    $st = $pdo->prepare("SELECT cra.id_alumno FROM clase_regular_alumno cra INNER JOIN alumnos a ON a.id_alumno = cra.id_alumno AND a.estatus = 'Alta' WHERE cra.id_clase = ?");
    $st->execute([$id_clase]);
    $inscritos = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO asistencias_clases (id_alumno, id_clase, fecha, estatus) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE estatus = VALUES(estatus)");
    $cuenta = array_fill_keys(ESTATUS_CLASE, 0);
    foreach ($marcados as $id_alumno => $estatus) {
        $id_alumno = (int)$id_alumno;
        if (!isset($inscritos[$id_alumno]) || !in_array($estatus, ESTATUS_CLASE, true)) continue;
        $ins->execute([$id_alumno, $id_clase, $fecha, $estatus]);
        $cuenta[$estatus]++;
    }
    $pdo->commit();

    $total = array_sum($cuenta);
    if ($total === 0) $ir('error', 'No se guardó nada: ningún alumno marcado pertenece a esta materia.');

    $mat = $pdo->prepare("SELECT CONCAT(m.nombre_materia, ' (', COALESCE(CONCAT(g.semestre, '° \"', g.nombre_grupo, '\"'), 'sin grupo'), ')')
                          FROM clases_regulares c INNER JOIN materias m ON m.id_materia = c.id_materia LEFT JOIN grupos g ON g.id_grupo = c.id_grupo WHERE c.id_clase = ?");
    $mat->execute([$id_clase]);
    bitacora($pdo, 'asistencia', 'pase_lista', 'Pasó lista de ' . $mat->fetchColumn() . ' del ' . date('d/m/Y', strtotime($fecha)) . ": {$cuenta['Presente']} presentes, {$cuenta['Retardo']} retardos, {$cuenta['Falta']} faltas, {$cuenta['Justificada']} justificadas");

    $pendientes = count($inscritos) - $total;
    $ir('exito', 'Asistencia guardada: ' . $total . ' alumno(s)' . ($pendientes > 0 ? " · $pendientes sin marcar" : '') . '.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('docente_guardar_asistencia: ' . $e->getMessage());
    $ir('error', 'No se pudo guardar la asistencia. Intenta de nuevo.');
}
