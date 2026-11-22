<?php
// logout.php — Cierra la sesión. Con ?inactividad=1 (cierre automático) vuelve al inicio de sesión con un aviso.
session_start();
$rol = $_SESSION['rol'] ?? '';
session_unset();
session_destroy();
$raiz = str_replace(chr(92), '/', realpath(__DIR__));
$doc = str_replace(chr(92), '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$base = ($doc !== '' && stripos($raiz, $doc) === 0) ? rtrim(substr($raiz, strlen($doc)), '/') : '';
setcookie('sige_inactividad', '', ['expires' => time() - 3600, 'path' => $base . '/', 'samesite' => 'Lax']);
if (isset($_GET['inactividad'])) {
    $r = ['administrativo' => 'admin', 'docente' => 'docente', 'prefectos' => 'prefectos'][$rol] ?? 'estudiante';
    header("Location: login.php?rol=$r&inactividad=1");
    exit();
}
header("Location: index.html");
exit();
