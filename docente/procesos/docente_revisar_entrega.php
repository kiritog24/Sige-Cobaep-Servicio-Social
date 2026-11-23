<?php
/**
 * docente/procesos/docente_revisar_entrega.php — Calificación y comentarios de una entrega.
 * Lo que se guarda aquí lo ve el alumno en su aula virtual, dentro de la actividad que entregó.
 * "A Tiempo" / "Con Retardo" no se toca: la revisión se guarda aparte.
 */
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../mis_grupos.php'); exit(); }

$id_entrega   = (int)($_POST['id_entrega'] ?? 0);
$id_actividad = (int)($_POST['id_actividad'] ?? 0);
require_once __DIR__ . '/permisos.php';
if (!docente_dueno_entrega($pdo, $id_entrega) || !docente_dueno_actividad($pdo, $id_actividad)) docente_denegar(false);

$volver = function (string $tipo, string $msg) use ($id_actividad) {
    header("Location: ../ver_entregas.php?id_actividad=$id_actividad&$tipo=" . urlencode($msg));
    exit();
};

asegurar_columnas_actividades($pdo);
$calificacion = calificacion_valida($_POST['calificacion'] ?? '');
if ($calificacion === false) $volver('error', 'La calificación debe ser un número del 0 al 10 (puede llevar un decimal, por ejemplo 8.5).');
$revisada = ($_POST['estatus_entrega'] ?? '') === 'Revisada' || $calificacion !== null ? 1 : 0;
$retro = trim((string)($_POST['retroalimentacion'] ?? ''));
if (mb_strlen($retro) > 2000) $retro = mb_substr($retro, 0, 2000);

try {
    $st = $pdo->prepare("UPDATE ENTREGAS_ACTIVIDAD
                         SET calificacion = ?, retroalimentacion = ?, revisada = ?, fecha_revision = IF(? = 1, NOW(), NULL)
                         WHERE id_entrega = ? AND id_actividad = ?");
    $st->execute([$calificacion, $retro !== '' ? $retro : null, $revisada, $revisada, $id_entrega, $id_actividad]);
    $volver('exito', $revisada ? 'Evaluación guardada. El alumno ya puede ver su calificación y tus comentarios.' : 'Comentario guardado. La entrega sigue pendiente de revisión.');
} catch (PDOException $e) {
    error_log('docente_revisar_entrega: ' . $e->getMessage());
    $volver('error', 'No se pudo guardar la evaluación.');
}
