<?php
// procesos/actualizar_contacto.php — El estudiante completa/actualiza sus datos personales,
// de contacto, domicilio y tutor (mismos datos que captura control escolar).
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

function volver_perfil(string $tipo, string $mensaje) {
    header("Location: ../estudiante/perfil.php?$tipo=" . urlencode($mensaje));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    volver_perfil('error', 'Solicitud no válida.');
}

$id_alumno = (int)$_SESSION['id_usuario'];
$txt = fn($k, $max) => mb_substr(trim(strip_tags($_POST[$k] ?? '')), 0, $max);
$dig = fn($k) => preg_replace('/\D/', '', $_POST[$k] ?? '');

$correo    = trim($_POST['correo_personal'] ?? '');
$telefono  = $dig('telefono');
$fecha_nac = trim($_POST['fecha_nacimiento'] ?? '');
// El sexo solo se registra la primera vez (si estaba vacío); para corregirlo, Control Escolar
$sexo      = in_array($_POST['sexo'] ?? '', ['F', 'M'], true) ? $_POST['sexo'] : null;

$dom = [
    'calle'         => $txt('calle', 150),
    'num_exterior'  => $txt('num_exterior', 20),
    'num_interior'  => $txt('num_interior', 20),
    'colonia'       => $txt('colonia', 100),
    'municipio'     => $txt('municipio', 100),
    'codigo_postal' => $dig('codigo_postal'),
];
$nombre_tutor = $txt('nombre_tutor', 200);
$tel_tutor_1  = $dig('telefono_tutor_1');
$tel_tutor_2  = $dig('telefono_tutor_2');
$correo_tutor = mb_strtolower($txt('correo_tutor', 150));

// Validaciones
if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) volver_perfil('error', 'El correo electrónico no es válido.');
if ($telefono !== '' && strlen($telefono) !== 10)                  volver_perfil('error', 'Tu teléfono debe tener 10 dígitos.');
if ($tel_tutor_1 !== '' && strlen($tel_tutor_1) !== 10)            volver_perfil('error', 'El teléfono del tutor debe tener 10 dígitos.');
if ($correo_tutor !== '' && !filter_var($correo_tutor, FILTER_VALIDATE_EMAIL)) volver_perfil('error', 'El correo del tutor no es válido.');
if ($nombre_tutor !== '' && $correo_tutor === '')                  volver_perfil('error', 'Escribe el correo electrónico de tu padre, madre o tutor.');
if ($tel_tutor_2 !== '' && strlen($tel_tutor_2) !== 10)            volver_perfil('error', 'El teléfono alterno del tutor debe tener 10 dígitos.');
if ($dom['codigo_postal'] !== '' && strlen($dom['codigo_postal']) !== 5) volver_perfil('error', 'El código postal debe tener 5 dígitos.');

$edad = null;
if ($fecha_nac !== '') {
    $f = DateTime::createFromFormat('Y-m-d', $fecha_nac);
    if (!$f || $f->format('Y-m-d') !== $fecha_nac || $f > new DateTime()) volver_perfil('error', 'La fecha de nacimiento no es válida.');
    $edad = $f->diff(new DateTime())->y;
}
$nul = fn($v) => $v !== '' ? $v : null;

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE ALUMNOS SET correo_personal = ?, telefono = ?, fecha_nacimiento = ?, edad = ?, sexo = IF(sexo IN ('F','M'), sexo, ?) WHERE id_alumno = ?")
        ->execute([$nul($correo), $nul($telefono), $nul($fecha_nac), $edad, $sexo, $id_alumno]);

    // Domicilio (UNIQUE id_alumno)
    $pdo->prepare("INSERT INTO DOMICILIO_ALUMNO (id_alumno, calle, num_exterior, num_interior, colonia, municipio, codigo_postal)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE calle = VALUES(calle), num_exterior = VALUES(num_exterior), num_interior = VALUES(num_interior),
                                           colonia = VALUES(colonia), municipio = VALUES(municipio), codigo_postal = VALUES(codigo_postal)")
        ->execute([$id_alumno, $nul($dom['calle']), $nul($dom['num_exterior']), $nul($dom['num_interior']),
                   $nul($dom['colonia']), $nul($dom['municipio']), $nul($dom['codigo_postal'])]);

    // Tutor (UNIQUE id_alumno; nombre_tutor es obligatorio en la tabla)
    if ($nombre_tutor !== '') {
        $pdo->prepare("INSERT INTO TUTOR_ALUMNO (id_alumno, nombre_tutor, telefono_1, telefono_2, correo_tutor) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE nombre_tutor = VALUES(nombre_tutor), telefono_1 = VALUES(telefono_1), telefono_2 = VALUES(telefono_2), correo_tutor = VALUES(correo_tutor)")
            ->execute([$id_alumno, $nombre_tutor, $nul($tel_tutor_1), $nul($tel_tutor_2), $nul($correo_tutor)]);
    }

    $pdo->commit();
    bitacora($pdo, 'perfil', 'datos_contacto', 'Actualizó sus datos de contacto y los de su tutor', 'alumno', $id_alumno);
    volver_perfil('exito', 'Tus datos se guardaron correctamente.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Contacto alumno: ' . $e->getMessage());
    volver_perfil('error', 'Error al guardar tus datos.');
}
