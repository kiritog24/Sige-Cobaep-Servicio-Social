<?php
/**
 * includes/bitacora.php — Registro de quién hizo qué y cuándo (altas, bajas, cambios de grupo,
 * contraseñas, reportes…). Se carga desde config/database.php, así que está disponible en todo el sistema.
 *
 *   bitacora($pdo, 'usuarios', 'alta', 'Registró al alumno 27/2026B0001 · Ana López', 'alumno', 549, ['grupo' => '1° A']);
 *
 * Nunca interrumpe la operación: si el registro falla, solo se anota en el log de PHP.
 * No se registran contraseñas ni calificaciones.
 */

const BITACORA_MODULOS = [
    'sesion'     => 'Inicio de sesión',
    'usuarios'   => 'Usuarios',
    'password'   => 'Contraseñas',
    'grupos'     => 'Grupos',
    'docentes'   => 'Asignación de docentes',
    'horarios'   => 'Horarios',
    'asistencia' => 'Asistencia',
    'conducta'   => 'Conducta',
    'correos'    => 'Correos institucionales',
    'avisos'     => 'Avisos a tutores',
    'perfil'     => 'Perfil',
    'sistema'    => 'Respaldos y cierre de ciclo',
];

function bitacora(PDO $pdo, string $modulo, string $accion, string $descripcion, ?string $entidad = null, ?int $id_entidad = null, ?array $detalle = null): void {
    try {
        $rol = (string)($_SESSION['rol'] ?? 'sistema');
        $pdo->prepare("INSERT INTO BITACORA (rol, id_usuario, usuario, nombre_usuario, modulo, accion, descripcion, entidad, id_entidad, detalle, ip)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $rol,
                isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : null,
                isset($_SESSION['matricula']) ? mb_substr((string)$_SESSION['matricula'], 0, 100) : null,
                isset($_SESSION['nombre']) ? mb_substr((string)$_SESSION['nombre'], 0, 200) : null,
                mb_substr($modulo, 0, 40),
                mb_substr($accion, 0, 40),
                mb_substr($descripcion, 0, 500),
                $entidad,
                $id_entidad,
                $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
                mb_substr(ip_cliente(), 0, 45),   // IP real aunque entre por un túnel o proxy
            ]);
    } catch (Throwable $e) {
        error_log('bitacora: ' . $e->getMessage());
    }
}

/** "27/2026B0001 · Ana López Pérez" para un alumno; "maestro01 · Carlos López" para el personal. */
function bitacora_quien(PDO $pdo, string $tipo, int $id): string {
    $cfg = [
        'alumnos'   => ["SELECT matricula AS u, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS n FROM ALUMNOS WHERE id_alumno = ?"],
        'maestros'  => ["SELECT usuario AS u, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS n FROM MAESTROS WHERE id_maestro = ?"],
        'admin'     => ["SELECT usuario AS u, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS n FROM ADMINISTRATIVOS WHERE id_admin = ?"],
        'prefectos' => ["SELECT usuario AS u, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS n FROM PREFECTOS WHERE id_prefecto = ?"],
        'grupo'     => ["SELECT '' AS u, CONCAT(semestre, '° \"', nombre_grupo, '\" ', turno) AS n FROM GRUPOS WHERE id_grupo = ?"],
    ];
    if (!isset($cfg[$tipo])) return "#$id";
    try {
        $st = $pdo->prepare($cfg[$tipo][0]);
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return "#$id";
        return trim(($r['u'] !== '' ? $r['u'] . ' · ' : '') . $r['n']);
    } catch (Throwable $e) {
        return "#$id";
    }
}
