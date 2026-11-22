<?php
/**
 * docente/procesos/docente_guardar_asistencia_qr.php — Pase de lista con la credencial (QR) del alumno.
 * Marca "Presente" en la materia; avisa si el alumno no es de la materia o si no registró su entrada en Prefectura.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';

header('Content-Type: application/json; charset=utf-8');
$responder = function (string $status, string $message, array $extra = []) {
    echo json_encode(['status' => $status, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit();
};

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') $responder('error', 'No autorizado');

$qr_crudo = trim((string)($_POST['id_alumno'] ?? ''));
$id_clase = (int)($_POST['id_clase'] ?? 0);
require_once __DIR__ . '/permisos.php';
if (!docente_dueno_clase($pdo, $id_clase)) docente_denegar(true);

$fecha = trim((string)($_POST['fecha'] ?? date('Y-m-d')));
if (!fecha_valida($fecha) || $fecha > date('Y-m-d')) $responder('error', 'La fecha del pase de lista no es válida.');
if ($motivo = dia_sin_clases($pdo, $fecha)) $responder('error', "Ese día no hay clases ($motivo).");

// La credencial trae la matrícula (ej. 27/2026B0001)
$matricula = preg_replace('/[^a-zA-Z0-9\/]/', '', $qr_crudo);
if ($matricula === '') $responder('error', 'Código QR vacío o ilegible');

try {
    $st = $pdo->prepare("SELECT id_alumno, CONCAT_WS(' ', nombre, apellido_paterno) AS nombre FROM alumnos WHERE matricula = ? AND estatus = 'Alta'");
    $st->execute([$matricula]);
    $al = $st->fetch(PDO::FETCH_ASSOC);
    if (!$al) $responder('error', 'Matrícula no registrada: ' . $matricula);
    $id_alumno = (int)$al['id_alumno'];

    $st = $pdo->prepare("SELECT 1 FROM clase_regular_alumno WHERE id_clase = ? AND id_alumno = ?");
    $st->execute([$id_clase, $id_alumno]);
    if (!$st->fetchColumn()) $responder('error', trim($al['nombre']) . ' no está inscrito en esta materia.');

    $st = $pdo->prepare("SELECT estatus FROM asistencias_clases WHERE id_clase = ? AND id_alumno = ? AND fecha = ?");
    $st->execute([$id_clase, $id_alumno, $fecha]);
    $previo = $st->fetchColumn();
    if ($previo !== false && estatus_clase_normal($previo) !== 'Falta') {
        $responder('warning', $al['nombre'] . ' ya estaba capturado.', ['id_alumno' => $id_alumno]);
    }

    // Presente (si antes tenía falta, se corrige: el alumno sí llegó)
    $pdo->prepare("INSERT INTO asistencias_clases (id_clase, id_alumno, fecha, estatus) VALUES (?, ?, ?, 'Presente')
                   ON DUPLICATE KEY UPDATE estatus = 'Presente'")->execute([$id_clase, $id_alumno, $fecha]);

    $e = entradas_plantel($pdo, [$id_alumno], $fecha)[$id_alumno] ?? null;
    $aviso = entro_al_plantel($e) ? '' : ' Ojo: no registró su entrada en Prefectura.';
    $responder($aviso ? 'warning' : 'success', $al['nombre'] . ': presente.' . $aviso, ['id_alumno' => $id_alumno, 'guardado' => true]);
} catch (PDOException $e) {
    error_log('docente_guardar_asistencia_qr: ' . $e->getMessage());
    $responder('error', 'No se pudo guardar. Intenta de nuevo.');
}
