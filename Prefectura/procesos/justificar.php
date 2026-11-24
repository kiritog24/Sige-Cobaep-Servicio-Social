<?php
/**
 * Prefectura/procesos/justificar.php — Justifica una falta o un retardo.
 *
 *  - Con id_asistencia: justifica ese registro (Falta → Falta Justificada, Retardo → Retardo Justificado).
 *  - Con id_alumno + fecha: el alumno no tiene registro ese día (no vino); se crea su "Falta Justificada".
 *
 * En ambos casos, las faltas que los docentes le pusieron en sus clases ese día quedan como "Justificada",
 * y el reporte de conducta por ese retardo o esa falta (levantado ese mismo día) se quita y se regresan sus puntos.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../../login.php");
    exit();
}

$volver_fecha = $_POST['volver_fecha'] ?? '';
$volver_mat = trim((string)($_POST['volver_matricula'] ?? ''));
$ir = function (string $param) use ($volver_fecha, $volver_mat) {
    $q = array_filter(['fecha' => fecha_valida($volver_fecha) ? $volver_fecha : null, 'matricula' => $volver_mat !== '' ? $volver_mat : null]);
    // Tras justificar se regresa al día justificado para que el registro se vea en la tabla
    if (strpos($param, 'resaltar=') !== false && !empty($GLOBALS['fecha'])) $q['fecha'] = $GLOBALS['fecha'];
    header('Location: ../historial_asistencias.php?' . http_build_query($q) . ($q ? '&' : '') . $param);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $ir('');

$id_asistencia = (int)($_POST['id_asistencia'] ?? 0);
$id_alumno_post = (int)($_POST['id_alumno'] ?? 0);
$fecha_post = (string)($_POST['fecha'] ?? '');
$motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 120);
$detalles = mb_substr(trim((string)($_POST['detalles'] ?? '')), 0, 120);
if ($motivo === '' || (!$id_asistencia && (!$id_alumno_post || !fecha_valida($fecha_post)))) $ir('error=id_invalido');
$motivo_final = $motivo . ($detalles !== '' ? ' - ' . $detalles : '');

try {
    if ($id_asistencia) {
        $st = $pdo->prepare("SELECT id_alumno, estatus, DATE(fecha_hora_escaneo) AS fecha FROM asistencias WHERE id_asistencia = ?");
        $st->execute([$id_asistencia]);
        $reg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$reg) $ir('error=registro_no_encontrado');
        $nuevo = ['Falta' => 'Falta Justificada', 'Retardo' => 'Retardo Justificado'][$reg['estatus']] ?? '';
        if ($nuevo === '') $ir('error=no_aplica');
        $pdo->prepare("UPDATE asistencias SET estatus = ?, motivo_justificacion = ? WHERE id_asistencia = ?")->execute([$nuevo, $motivo_final, $id_asistencia]);
        $id_alumno = (int)$reg['id_alumno'];
        $fecha = $reg['fecha'];
    } else {
        // Falta de todo el día sin registro de entrada
        $id_alumno = $id_alumno_post;
        $fecha = $fecha_post;
        if ($fecha > date('Y-m-d')) $ir('error=no_aplica');
        $st = $pdo->prepare("SELECT 1 FROM alumnos WHERE id_alumno = ? AND estatus = 'Alta'");
        $st->execute([$id_alumno]);
        if (!$st->fetchColumn()) $ir('error=registro_no_encontrado');
        $st = $pdo->prepare("SELECT 1 FROM asistencias WHERE id_alumno = ? AND tipo_registro = 'Entrada' AND DATE(fecha_hora_escaneo) = ?");
        $st->execute([$id_alumno, $fecha]);
        if ($st->fetchColumn()) $ir('error=ya_tiene_registro');
        $pdo->prepare("INSERT INTO asistencias (id_alumno, fecha_hora_escaneo, tipo_registro, estatus, id_prefecto, motivo_justificacion)
                       VALUES (?, ?, 'Entrada', 'Falta Justificada', ?, ?)")
            ->execute([$id_alumno, $fecha . ' 00:00:00', (int)$_SESSION['id_usuario'], $motivo_final]);
        $nuevo = 'Falta Justificada';
        $id_asistencia = (int)$pdo->lastInsertId();
    }

    $clases = $nuevo === 'Falta Justificada' ? justificar_clases_del_dia($pdo, $id_alumno, $fecha) : 0;
    // El reporte de conducta por ese retardo o esa falta (mismo día) se quita y se regresan sus puntos
    $tipo_just = $nuevo === 'Falta Justificada' ? 'falta' : 'retardo';
    // reportes_enviados = 1: la ventana mostró la lista y Prefectura eligió cuáles quitar (puede ser ninguno)
    $ids_rep = !empty($_POST['reportes_enviados']) ? array_map('intval', (array)($_POST['reportes'] ?? [])) : null;
    $quitados = quitar_reportes_justificados($pdo, $id_alumno, $fecha, $tipo_just, $ids_rep);
    if ($quitados['reportes']) {
        require_once __DIR__ . '/../../includes/avisos_tutor.php';
        aviso_tutor_reporte_anulado($pdo, $id_alumno, $tipo_just, $fecha, $quitados['detalle'], $quitados['puntos']);
    }
    bitacora($pdo, 'asistencia', 'justificacion', "Justificó ($nuevo) el " . date('d/m/Y', strtotime($fecha)) . ' a ' . bitacora_quien($pdo, 'alumnos', $id_alumno)
        . ': ' . mb_substr($motivo_final, 0, 150) . ($clases ? " · $clases falta(s) a clases justificadas" : ''), 'alumno', $id_alumno);
    $ir('msg=exito_justificante&clases=' . $clases . '&reportes=' . $quitados['reportes'] . '&puntos=' . $quitados['puntos'] . '&resaltar=' . $id_asistencia);
} catch (PDOException $e) {
    error_log('Error al justificar asistencia: ' . $e->getMessage());
    $ir('error=error_bd');
}
