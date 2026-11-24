<?php
/**
 * Prefectura/procesos/reportes_justificables.php — Reportes de conducta que se pueden quitar al justificar.
 * GET id_asistencia (retardo o falta registrados) o id_alumno + fecha (día sin registro = falta).
 * Responde JSON: tipo ('retardo'|'falta'), fecha y la lista de reportes de ese día o de días cercanos.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit();
}

try {
    $id_asistencia = (int)($_GET['id_asistencia'] ?? 0);
    if ($id_asistencia) {
        $st = $pdo->prepare("SELECT id_alumno, estatus, DATE(fecha_hora_escaneo) AS fecha FROM asistencias WHERE id_asistencia = ?");
        $st->execute([$id_asistencia]);
        $reg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$reg) { echo json_encode(['ok' => false]); exit(); }
        $id_alumno = (int)$reg['id_alumno'];
        $fecha = $reg['fecha'];
        $tipo = $reg['estatus'] === 'Retardo' ? 'retardo' : 'falta';
    } else {
        $id_alumno = (int)($_GET['id_alumno'] ?? 0);
        $fecha = (string)($_GET['fecha'] ?? '');
        $tipo = 'falta';
        if (!$id_alumno || !fecha_valida($fecha)) { echo json_encode(['ok' => false]); exit(); }
    }
    $lista = array_map(fn($r) => [
        'id'        => (int)$r['id_reporte'],
        'fecha'     => date('d/m/Y h:i a', strtotime($r['fecha_hora'])),
        'articulo'  => (int)$r['numero_reglamento'],
        'falta'     => $r['descripcion_falta'],
        'puntos'    => (int)$r['puntos_descontados'],
        'mismo_dia' => (int)$r['mismo_dia'] === 1,
    ], reportes_justificables($pdo, $id_alumno, $fecha, $tipo));
    echo json_encode(['ok' => true, 'tipo' => $tipo, 'fecha' => date('d/m/Y', strtotime($fecha)), 'dias' => REPORTES_JUSTIFICABLES_DIAS, 'reportes' => $lista], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('reportes_justificables: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
