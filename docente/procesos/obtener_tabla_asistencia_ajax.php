<?php
/**
 * docente/procesos/obtener_tabla_asistencia_ajax.php — Reporte de asistencia filtrado (para lista_alumnos.php).
 * Responde JSON: tbody (HTML de la tabla), periodo (texto) y resumen (tarjetas).
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../includes/reporte_asistencia.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') { http_response_code(403); echo json_encode(['tbody' => '']); exit(); }

$id_clase = isset($_GET['id_clase']) ? (int)$_GET['id_clase'] : 0;
require_once __DIR__ . '/permisos.php';
if ($id_clase <= 0 || !docente_dueno_clase($pdo, $id_clase)) { http_response_code(403); echo json_encode(['tbody' => '<tr><td colspan="6">Acceso denegado.</td></tr>']); exit(); }

try {
    $periodo = reporte_asistencia_periodo($_GET);
    $filas = reporte_asistencia_filas($pdo, $id_clase, $periodo);
    echo json_encode([
        'tbody'   => reporte_asistencia_tbody($filas, $periodo['texto']),
        'periodo' => $periodo['texto'],
        'resumen' => reporte_asistencia_resumen($filas),
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('obtener_tabla_asistencia_ajax: ' . $e->getMessage());
    echo json_encode(['tbody' => '<tr><td colspan="6" class="vacio">No se pudo cargar el reporte. Intenta de nuevo.</td></tr>', 'periodo' => '', 'resumen' => []]);
}
