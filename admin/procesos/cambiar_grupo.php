<?php
/**
 * admin/procesos/cambiar_grupo.php — Cambia a un alumno de su grupo actual a otro grupo activo.
 * El grupo anterior queda con fecha de baja (se conserva el historial y su asistencia).
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/grupos.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../../login.php");
    exit();
}
$id_alumno = (int)($_POST['id_alumno'] ?? 0);
$origen = (int)($_POST['id_grupo_origen'] ?? 0);
$destino = (int)($_POST['id_grupo_destino'] ?? 0);
$volver = function (string $param) use ($origen) {
    header('Location: ../ver_grupo.php?id=' . $origen . '&' . $param);
    exit();
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id_alumno || !$origen) {
    header("Location: ../gestion_grupos.php");
    exit();
}
if (!$destino || $destino === $origen) $volver('error_carga=' . urlencode('Elige un grupo distinto al actual.'));

try {
    $st = $pdo->prepare("SELECT id_grupo, semestre, nombre_grupo, turno, estatus FROM GRUPOS WHERE id_grupo = ?");
    $st->execute([$destino]);
    $grupo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$grupo || $grupo['estatus'] !== 'Activo') $volver('error_carga=' . urlencode('El grupo elegido no existe o no está activo.'));

    $st = $pdo->prepare("SELECT estatus FROM ALUMNOS WHERE id_alumno = ?");
    $st->execute([$id_alumno]);
    if ($st->fetchColumn() !== 'Alta') $volver('error_carga=' . urlencode('Solo se puede cambiar de grupo a alumnos en estatus Alta.'));

    $st = $pdo->prepare("SELECT 1 FROM GRUPO_ALUMNO WHERE id_alumno = ? AND id_grupo = ? AND fecha_baja IS NULL");
    $st->execute([$id_alumno, $origen]);
    if (!$st->fetchColumn()) $volver('error_carga=' . urlencode('El alumno ya no está en este grupo.'));

    $pdo->beginTransaction();
    asignar_alumno_grupo($pdo, $id_alumno, $grupo);
    $pdo->commit();
    grupos_sincronizar_aulas($pdo, [$origen, $destino]);

    $quien = bitacora_quien($pdo, 'alumnos', $id_alumno);
    $de = bitacora_quien($pdo, 'grupo', $origen);
    $a = bitacora_quien($pdo, 'grupo', $destino);
    bitacora($pdo, 'grupos', 'cambio_grupo', "Cambió a $quien del grupo $de al grupo $a", 'alumno', $id_alumno, ['de' => $de, 'a' => $a]);
    $volver('cambio=' . urlencode($quien . '|' . grupo_etiqueta($grupo) . '|' . $destino));
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('cambiar_grupo: ' . $e->getMessage());
    $volver('error_carga=' . urlencode('No se pudo cambiar de grupo. Intenta de nuevo.'));
}
