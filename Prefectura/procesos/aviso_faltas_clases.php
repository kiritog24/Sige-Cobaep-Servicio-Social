<?php
/**
 * Prefectura/procesos/aviso_faltas_clases.php — Avisa al tutor que el alumno entró al plantel pero faltó a clases.
 * Las clases se recalculan aquí (no se confía en lo que mande el formulario).
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';
require_once __DIR__ . '/../../includes/avisos_tutor.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../../login.php");
    exit();
}

$id_alumno = (int)($_POST['id_alumno'] ?? 0);
$fecha = (string)($_POST['fecha'] ?? '');
$id_grupo = (int)($_POST['id_grupo'] ?? 0);
$volver = function (string $tipo, string $texto) use ($fecha, $id_grupo) {
    header('Location: ../asistencia_clases.php?' . http_build_query(['fecha' => $fecha, 'id_grupo' => $id_grupo, $tipo => $texto]));
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id_alumno <= 0 || !fecha_valida($fecha)) $volver('error', 'Datos incompletos.');

$p = posibles_pintas($pdo, $fecha)[$id_alumno] ?? null;
$clases = $p ? array_values(array_filter($p['clases'], fn($c) => !$c['salio_antes'])) : [];
if (!$clases) $volver('error', 'Ese alumno ya no aparece con faltas a clases estando en el plantel.');

$r = aviso_tutor_clases($pdo, $id_alumno, $fecha, $p['entrada'], $clases);
$textos = [
    'enviado'    => 'Aviso enviado al tutor.',
    'pendiente'  => 'Aviso guardado; se enviará cuando el correo del sistema esté configurado.',
    'sin_correo' => 'Aviso guardado, pero el alumno no tiene correo de tutor registrado.',
    'duplicado'  => 'Ya se había generado este aviso para ese día.',
    'fallido'    => 'El aviso se guardó, pero el correo no se pudo enviar. Puedes reintentarlo en Avisos a tutores.',
];
if ($r === 'error') $volver('error', 'No se pudo generar el aviso.');
if ($r !== 'duplicado') {
    bitacora($pdo, 'avisos', 'aviso_tutor', 'Generó aviso al tutor por faltas a clases del ' . date('d/m/Y', strtotime($fecha)) . ' de ' . bitacora_quien($pdo, 'alumnos', $id_alumno), 'alumno', $id_alumno);
}
$volver(in_array($r, ['enviado', 'pendiente', 'duplicado'], true) ? 'exito' : 'error', $textos[$r] ?? 'Aviso generado.');
