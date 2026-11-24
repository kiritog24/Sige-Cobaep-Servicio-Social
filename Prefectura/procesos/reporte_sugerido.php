<?php
/**
 * Prefectura/procesos/reporte_sugerido.php — Atender un reporte sugerido.
 *  accion=levantar  → levanta el reporte (Art. 1 retardos / Art. 11 faltas), descuenta puntos y avisa al tutor.
 *  accion=descartar → lo quita de la lista sin sancionar (con motivo).
 * Antes de actuar se vuelve a calcular la lista: solo se atiende un bloque que siga pendiente.
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/reportes_sugeridos.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header('Location: ../../login.php');
    exit();
}
$mes = sugeridos_mes($_POST['mes'] ?? '');
$volver = function (string $tipo, string $msg) use ($mes) {
    header('Location: ../reportes_sugeridos.php?mes=' . $mes . '&' . $tipo . '=' . urlencode($msg));
    exit();
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $volver('error', 'Solicitud no válida.');

$accion = $_POST['accion'] ?? '';
$id_alumno = (int)($_POST['id_alumno'] ?? 0);
$tipo = ($_POST['tipo'] ?? '') === 'falta' ? 'falta' : 'retardo';
$bloque = (int)($_POST['bloque'] ?? 0);
$motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 250);

try {
    $sug = null;
    foreach (reportes_sugeridos($pdo, $mes)['pendientes'] as $p) {
        if ((int)$p['id_alumno'] === $id_alumno && $p['tipo'] === $tipo && (int)$p['bloque'] === $bloque) { $sug = $p; break; }
    }
    if (!$sug) $volver('error', 'Ese reporte sugerido ya no está pendiente (quizá se justificó o alguien más ya lo atendió).');

    $palabra = $tipo === 'retardo' ? 'retardos' : 'faltas';
    $fechas_txt = sugeridos_fechas_texto($sug['bloque_fechas']);
    $mes_txt = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $mes_txt = $mes_txt[(int)substr($mes, 5, 2) - 1] . ' de ' . substr($mes, 0, 4);

    if ($accion === 'levantar') {
        $art = $tipo === 'retardo' ? 1 : 11;
        $st = $pdo->prepare("SELECT id_tipo_falta FROM TIPOS_FALTA WHERE numero_reglamento = ? LIMIT 1");
        $st->execute([$art]);
        $id_tipo = (int)$st->fetchColumn();
        if (!$id_tipo) $volver('error', "No existe la regla $art en el reglamento.");
        $obs = "Reporte por acumulación: " . count($sug['bloque_fechas']) . " $palabra sin justificar en $mes_txt ($fechas_txt)." . ($motivo !== '' ? ' ' . $motivo : '');

        $pdo->beginTransaction();
        // El trigger de la base descuenta los puntos del alumno al insertar el reporte
        $pdo->prepare("INSERT INTO REPORTES (id_alumno, id_tipo_falta, id_emisor, tipo_emisor, puntos_descontados, observaciones) VALUES (?, ?, ?, 'prefecto', 0, ?)")
            ->execute([$id_alumno, $id_tipo, (int)$_SESSION['id_usuario'], $obs]);
        $id_reporte = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO REPORTES_SUGERIDOS (id_alumno, tipo, mes, bloque, accion, id_reporte, fechas, motivo, id_prefecto) VALUES (?, ?, ?, ?, 'reporte', ?, ?, ?, ?)")
            ->execute([$id_alumno, $tipo, $mes, $bloque, $id_reporte, implode(',', $sug['bloque_fechas']), $motivo ?: null, (int)$_SESSION['id_usuario']]);
        $pdo->commit();

        bitacora($pdo, 'conducta', 'reporte', "Levantó reporte por acumulación de $palabra ($fechas_txt) a " . bitacora_quien($pdo, 'alumnos', $id_alumno), 'reporte', $id_reporte);
        $aviso = aviso_tutor_conducta($pdo, $id_reporte);
        $txt_aviso = ['enviado' => 'se envió el aviso a su tutor', 'pendiente' => 'el aviso a su tutor quedó pendiente de enviar', 'sin_correo' => 'su tutor no tiene correo registrado (el aviso quedó guardado)'][$aviso] ?? 'no se pudo generar el aviso al tutor';
        reportes_sugeridos_pendientes($pdo, true);
        $volver('exito', "Reporte levantado a {$sug['nombre']} (Art. $art); $txt_aviso.");
    }

    if ($accion === 'descartar') {
        if ($motivo === '') $volver('error', 'Escribe por qué se descarta (por ejemplo: falló el escáner, ya se habló con el alumno).');
        $pdo->prepare("INSERT INTO REPORTES_SUGERIDOS (id_alumno, tipo, mes, bloque, accion, fechas, motivo, id_prefecto) VALUES (?, ?, ?, ?, 'descartado', ?, ?, ?)")
            ->execute([$id_alumno, $tipo, $mes, $bloque, implode(',', $sug['bloque_fechas']), $motivo, (int)$_SESSION['id_usuario']]);
        bitacora($pdo, 'conducta', 'descarte', "Descartó el reporte sugerido por $palabra ($fechas_txt) de " . bitacora_quien($pdo, 'alumnos', $id_alumno) . ": $motivo", 'alumno', $id_alumno);
        reportes_sugeridos_pendientes($pdo, true);
        $volver('exito', "Se descartó el reporte sugerido de {$sug['nombre']}. No se le descontaron puntos.");
    }
    $volver('error', 'Acción no válida.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('reporte_sugerido: ' . $e->getMessage());
    $volver('error', 'No se pudo guardar. Intenta de nuevo.');
}
