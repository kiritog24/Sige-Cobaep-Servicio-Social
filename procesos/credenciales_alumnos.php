<?php
/**
 * procesos/credenciales_alumnos.php — Contraseñas provisionales de alumnos en bloque (solo Administración).
 *
 * accion=generar   → genera una contraseña provisional única para cada alumno de un grupo (o de todos)
 *                    que AÚN NO ha cambiado su contraseña. Quien ya la cambió nunca se toca.
 * accion=descartar → borra de la sesión el lote de contraseñas (ya no se podrá volver a descargar).
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/credenciales_lote.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
$volver = '../admin/gestion_usuarios.php?tipo=alumnos';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $volver");
    exit();
}

$accion = $_POST['accion'] ?? '';

if ($accion === 'descartar') {
    unset($_SESSION['credenciales_lote']);
    header("Location: $volver&exito=" . urlencode('Se borraron de la sesión las contraseñas provisionales.'));
    exit();
}

if ($accion === 'descartar_alta') {
    unset($_SESSION['credenciales_alta']);
    header("Location: ../admin/agregar_usuario.php");
    exit();
}

if ($accion !== 'generar') {
    header("Location: $volver");
    exit();
}

$id_grupo = (int)($_POST['id_grupo'] ?? 0);   // 0 = todos los grupos
@set_time_limit(0);

try {
    $sql = "SELECT a.id_alumno, a.matricula,
                   CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) AS nombre,
                   g.semestre, g.nombre_grupo, g.turno
            FROM ALUMNOS a
            LEFT JOIN GRUPO_ALUMNO ga ON ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL
            LEFT JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
            WHERE a.debe_cambiar_password = 1 AND a.estatus <> 'Baja'";
    $params = [];
    if ($id_grupo > 0) {
        $sql .= " AND ga.id_grupo = ?";
        $params[] = $id_grupo;
    }
    $sql .= " GROUP BY a.id_alumno";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $alumnos = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$alumnos) {
        header("Location: $volver&error=" . urlencode('No hay alumnos pendientes de cambiar su contraseña en ese grupo.'));
        exit();
    }

    $upd = $pdo->prepare("UPDATE ALUMNOS SET contrasena_hash = ?, debe_cambiar_password = 1,
                                 password_provisional_expira = NOW() + INTERVAL " . IMPORTACION_DIAS . " DAY
                          WHERE id_alumno = ? AND debe_cambiar_password = 1");
    $credenciales = [];
    $pdo->beginTransaction();
    foreach ($alumnos as $a) {
        $provisional = generar_password_provisional();
        $upd->execute([password_hash($provisional, PASSWORD_DEFAULT), (int)$a['id_alumno']]);
        if ($upd->rowCount() === 0) continue;
        $credenciales[] = [
            'matricula' => $a['matricula'],
            'nombre'    => $a['nombre'],
            'grupo'     => credenciales_grupo_txt($a['semestre'] !== null ? (int)$a['semestre'] : null, $a['nombre_grupo'], $a['turno']),
            'password'  => $provisional,
        ];
    }
    $pdo->commit();
    // Se desbloquea a quien tenía intentos fallidos (fuera de la transacción: login_tabla() puede crear la tabla)
    foreach ($credenciales as $cr) login_limpiar($pdo, (string)$cr['matricula']);

    bitacora($pdo, 'password', 'credenciales', 'Generó ' . count($credenciales) . ' contraseñas provisionales de alumnos (' . ($id_grupo > 0 ? bitacora_quien($pdo, 'grupo', $id_grupo) : 'todos los grupos') . ')',
             'alumno', null, ['matriculas' => array_column($credenciales, 'matricula')]);
    credenciales_guardar_lote($credenciales, $id_grupo > 0 ? 'Regeneradas por grupo' : 'Regeneradas (todos los grupos)', credenciales_expira_texto($pdo));
    header("Location: $volver&exito=" . urlencode('Se generaron ' . count($credenciales) . ' contraseñas provisionales nuevas. Descárgalas ahora.') . '#credenciales');
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('credenciales_alumnos: ' . $e->getMessage());
    header("Location: $volver&error=" . urlencode('No se pudieron generar las contraseñas provisionales.'));
    exit();
}
