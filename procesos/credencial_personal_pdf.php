<?php
/**
 * procesos/credencial_personal_pdf.php — PDF de credenciales del personal.
 *  - Administración: puede generar la de cualquier docente, prefecto o administrativo (una o varias).
 *  - Docente / Prefecto / Administrativo: solo la suya.
 * POST: personas[] = "tipo:id", qr[tipo:id] = data:image/png;base64,… (lo dibuja la página con qrcode.js), modo = descargar|ver
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/credencial_personal.php';

$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($rol, ['administrativo', 'docente', 'prefectos'], true)) {
    header("Location: ../login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.html");
    exit();
}

$propio = ['docente' => 'maestros', 'prefectos' => 'prefectos', 'administrativo' => 'admin'][$rol];
$pedidas = array_slice(array_values(array_unique(array_map('strval', (array)($_POST['personas'] ?? [])))), 0, 300);
$qrs = (array)($_POST['qr'] ?? []);
$lista = [];
foreach ($pedidas as $clave) {
    if (!preg_match('/^(maestros|prefectos|admin):(\d+)$/', $clave, $m)) continue;
    [$_, $tipo, $id] = $m;
    // Solo Administración puede generar credenciales de otras personas
    if ($rol !== 'administrativo' && !($tipo === $propio && (int)$id === (int)$_SESSION['id_usuario'])) continue;
    $p = credencial_persona($pdo, $tipo, (int)$id);
    if ($p) $lista[] = ['persona' => $p, 'qr' => cred_qr_valido($qrs[$clave] ?? null)];
}
if (!$lista) {
    http_response_code(400);
    exit('No se indicó ninguna credencial válida.');
}
@set_time_limit(0);

if (count($lista) === 1) {
    $p = $lista[0]['persona'];
    $nombre = 'Credencial_' . $p['usuario'] . '.pdf';
    $desc = 'Generó la credencial de ' . $p['usuario'] . ' · ' . trim($p['nombre'] . ' ' . $p['apellido_paterno']);
} else {
    $nombre = 'Credenciales_personal_' . date('Y-m-d') . '.pdf';
    $desc = 'Generó ' . count($lista) . ' credenciales del personal';
}
bitacora($pdo, 'usuarios', 'credencial', $desc, null, null, count($lista) > 1 ? ['personas' => array_map(fn($x) => $x['persona']['usuario'], $lista)] : null);
credencial_pdf_enviar($lista, $nombre, ($_POST['modo'] ?? 'descargar') !== 'ver');
