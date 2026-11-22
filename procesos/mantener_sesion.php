<?php
/**
 * procesos/mantener_sesion.php — "Seguir conectado": el navegador lo llama cuando el usuario sigue
 * trabajando en la misma página (escribiendo, leyendo) para que su sesión no se cierre por inactividad.
 * Al cargar config/database.php se actualiza la última actividad (o se responde 401 si ya expiró).
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'redirect' => '../login.php']);
    exit();
}
require_once '../config/database.php';
echo json_encode(['ok' => true, 'minutos' => SESION_INACTIVIDAD_MIN]);
