<?php
/**
 * procesos/ficha_usuario.php?tipo=alumnos|maestros|prefectos|admin&id=N
 * Devuelve en JSON los datos para la ventana emergente de perfil.
 *  - Administración: cualquier usuario.
 *  - Prefectura: alumnos.
 *  - Estudiante: solo docentes que tiene asignados (datos básicos, sin teléfono).
 */
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

function responder(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit(); }
function foto_ok(?string $ruta): ?string {
    if (!$ruta) return null;
    $r = preg_replace('#^(\.\./)+#', '', $ruta);
    if (strpos($r, 'uploads/') !== 0 || !is_file(__DIR__ . '/../' . $r)) return null;
    return '../' . $r . '?v=' . filemtime(__DIR__ . '/../' . $r);
}

$rol = $_SESSION['rol'] ?? '';
$tipo = $_GET['tipo'] ?? '';
$id = (int)($_GET['id'] ?? 0);
if (!isset($_SESSION['id_usuario']) || $id <= 0) responder(['ok' => false, 'error' => 'Sesión no válida.'], 401);

$permitido = $rol === 'administrativo'
    || ($rol === 'prefectos' && $tipo === 'alumnos')
    || ($rol === 'estudiante' && $tipo === 'maestros');
if (!$permitido) responder(['ok' => false, 'error' => 'Sin permiso.'], 403);

try {
    $out = ['ok' => true, 'tipo' => $tipo, 'secciones' => []];
    if ($tipo === 'maestros') {
        if ($rol === 'estudiante') {
            $a = (int)$_SESSION['id_usuario'];
            $st = $pdo->prepare("
                SELECT 1 FROM GRUPO_ALUMNO ga INNER JOIN GRUPO_MAESTRO gm ON gm.id_grupo = ga.id_grupo WHERE ga.id_alumno = ? AND ga.fecha_baja IS NULL AND gm.id_maestro = ?
                UNION SELECT 1 FROM CLASE_REGULAR_ALUMNO cra INNER JOIN CLASES_REGULARES c ON c.id_clase = cra.id_clase WHERE cra.id_alumno = ? AND c.id_maestro = ?
                UNION SELECT 1 FROM CLASE_ALUMNO ca INNER JOIN CLASES_REGULARIZACION c ON c.id_clase = ca.id_clase WHERE ca.id_alumno = ? AND c.id_maestro = ?");
            $st->execute([$a, $id, $a, $id, $a, $id]);
            if (!$st->fetchColumn()) responder(['ok' => false, 'error' => 'Ese docente no está asignado a ti.'], 403);
        }
        $st = $pdo->prepare("SELECT * FROM MAESTROS WHERE id_maestro = ?");
        $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) responder(['ok' => false, 'error' => 'No existe.'], 404);

        $st = $pdo->prepare("SELECT CONCAT(g.semestre, '° \"', g.nombre_grupo, '\" · ', g.turno) FROM GRUPO_MAESTRO gm INNER JOIN GRUPOS g ON g.id_grupo = gm.id_grupo AND g.estatus = 'Activo' WHERE gm.id_maestro = ? ORDER BY g.semestre, g.nombre_grupo");
        $st->execute([$id]);
        $grupos = $st->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare("SELECT DISTINCT mt.nombre_materia FROM CLASES_REGULARES c INNER JOIN MATERIAS mt ON mt.id_materia = c.id_materia WHERE c.id_maestro = ? AND c.estatus = 'Activa'
                             UNION SELECT CONCAT(nombre_materia, ' (regularización)') FROM CLASES_REGULARIZACION WHERE id_maestro = ? AND estatus = 'Activa' AND visible = 1");
        $st->execute([$id, $id]);
        $materias = $st->fetchAll(PDO::FETCH_COLUMN);

        $out += [
            'nombre' => trim($m['nombre'] . ' ' . $m['apellido_paterno'] . ' ' . $m['apellido_materno']),
            'subtitulo' => $m['titulo_academico'] ?: 'Docente',
            'rol' => 'Docente', 'foto' => foto_ok($m['foto_perfil']),
            'biografia' => $m['biografia'] ?: null,
            'etiquetas' => $materias,
        ];
        $contacto = [['fa-envelope', 'Correo', $m['correo'] ?: null]];
        if ($rol === 'administrativo') {
            $contacto[] = ['fa-phone', 'Teléfono', $m['telefono'] ?: null];
            $contacto[] = ['fa-user', 'Usuario', $m['usuario']];
        }
        $out['secciones'][] = ['titulo' => 'Contacto', 'datos' => $contacto];
        if ($rol === 'administrativo') {
            $out['secciones'][] = ['titulo' => 'Grupos asignados', 'lista' => $grupos ?: ['Sin grupos asignados']];
            $out['acciones'] = [['editar_usuario.php?id=' . $id . '&tipo=maestros', 'fa-pen', 'Editar']];
        }
    } elseif ($tipo === 'prefectos' || $tipo === 'admin') {
        [$tabla, $col, $etq] = $tipo === 'admin' ? ['ADMINISTRATIVOS', 'id_admin', 'Administrativo'] : ['PREFECTOS', 'id_prefecto', 'Prefecto'];
        $st = $pdo->prepare("SELECT * FROM $tabla WHERE $col = ?");
        $st->execute([$id]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) responder(['ok' => false, 'error' => 'No existe.'], 404);
        $out += ['nombre' => trim($u['nombre'] . ' ' . $u['apellido_paterno'] . ' ' . $u['apellido_materno']),
                 'subtitulo' => $etq, 'rol' => $etq, 'foto' => foto_ok($u['foto_perfil'] ?? null), 'etiquetas' => []];
        $out['secciones'][] = ['titulo' => 'Contacto y acceso', 'datos' => [
            ['fa-envelope', 'Correo', $u['correo'] ?: null], ['fa-phone', 'Teléfono', $u['telefono'] ?: null], ['fa-user', 'Usuario', $u['usuario']]]];
        if ($tipo === 'prefectos') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM REPORTES WHERE tipo_emisor = 'prefecto' AND id_emisor = ?");
            $st->execute([$id]);
            $out['secciones'][] = ['titulo' => 'Actividad', 'datos' => [['fa-file-lines', 'Reportes levantados', (string)$st->fetchColumn()]]];
        }
        $out['acciones'] = [['editar_usuario.php?id=' . $id . '&tipo=' . $tipo, 'fa-pen', 'Editar']];
    } elseif ($tipo === 'alumnos') {
        require_once __DIR__ . '/../includes/ficha_alumno.php';
        $d = ficha_alumno_cargar($pdo, $id);
        if (!$d) responder(['ok' => false, 'error' => 'No existe.'], 404);
        $al = $d['al']; $dom = $d['dom']; $t = $d['tutor'];
        $domicilio = $dom ? trim(($dom['calle'] ?? '') . ' ' . ($dom['num_exterior'] ?? '') . ', ' . ($dom['colonia'] ?? '') . ', ' . ($dom['municipio'] ?? ''), ' ,') : null;
        $out += [
            'nombre' => trim($al['nombre'] . ' ' . $al['apellido_paterno'] . ' ' . $al['apellido_materno']),
            'subtitulo' => $al['matricula'] . ' · ' . ($al['id_grupo'] ? $al['grupo_semestre'] . '° "' . $al['nombre_grupo'] . '" ' . $al['grupo_turno'] : 'Sin grupo'),
            'rol' => 'Alumno', 'foto' => foto_ok($al['fotografia']),
            'etiquetas' => [$al['estatus'], (int)$al['puntos_conducta'] . ' pts de conducta'],
        ];
        $out['secciones'][] = ['titulo' => 'Contacto', 'datos' => [
            ['fa-phone', 'Teléfono', $al['telefono'] ?: null], ['fa-envelope', 'Correo', $al['correo_personal'] ?: ($al['correo_institucional'] ?: null)],
            ['fa-cake-candles', 'Nacimiento', $al['fecha_nacimiento'] ? date('d/m/Y', strtotime($al['fecha_nacimiento'])) : null],
            ['fa-house', 'Domicilio', $domicilio ?: null]]];
        $out['secciones'][] = ['titulo' => 'Tutor', 'datos' => [
            ['fa-user-shield', 'Nombre', $t['nombre_tutor'] ?? null], ['fa-phone', 'Teléfono', $t['telefono_1'] ?? null]]];
        $out['acciones'] = [['perfil_alumno.php?id=' . $id, 'fa-id-card', 'Expediente completo']];
        if ($rol === 'administrativo') $out['acciones'][] = ['editar_usuario.php?id=' . $id . '&tipo=alumnos', 'fa-pen', 'Editar'];
    } else {
        responder(['ok' => false, 'error' => 'Tipo no válido.'], 400);
    }
    responder($out);
} catch (PDOException $e) {
    error_log('ficha_usuario: ' . $e->getMessage());
    responder(['ok' => false, 'error' => 'Error de base de datos.'], 500);
}
