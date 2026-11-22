<?php
/**
 * error.php — Página de error del sistema (404, 403, 500…).
 * Apache la muestra mediante ErrorDocument (.htaccess). No usa la base de datos, así funciona
 * incluso si MySQL está apagado. Si hay sesión, el botón principal lleva al panel del usuario.
 */
$codigo = (int)($_SERVER['REDIRECT_STATUS'] ?? ($_GET['c'] ?? 404));
if (!in_array($codigo, [400, 401, 403, 404, 405, 408, 500, 502, 503], true)) $codigo = 404;
http_response_code($codigo);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

// URL base del sistema (p. ej. "/sige_cobaep1"), para que funcione desde cualquier carpeta
$raiz = str_replace('\\', '/', realpath(__DIR__));
$doc = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$base = ($doc !== '' && stripos($raiz, $doc) === 0) ? rtrim(substr($raiz, strlen($doc)), '/') : '';

// Panel según la sesión (sin consultar la base de datos)
if (session_status() === PHP_SESSION_NONE) @session_start();
$paneles = [
    'estudiante'     => ['estudiante/dashboard_estud.php', 'Ir a mi panel'],
    'docente'        => ['docente/dashboard_docente.php', 'Ir a mi panel'],
    'administrativo' => ['admin/dashboard_admin.php', 'Ir al Panel de Control'],
    'prefectos'      => ['Prefectura/dashboard_prefectura.php', 'Ir a Prefectura'],
];
$rol = $_SESSION['rol'] ?? '';
$con_sesion = !empty($_SESSION['id_usuario']) && isset($paneles[$rol]);
[$destino, $texto_destino] = $con_sesion ? $paneles[$rol] : ['login.php', 'Iniciar sesión'];
$destino = $base . '/' . $destino;

$mensajes = [
    400 => ['Solicitud no válida', 'El navegador envió una solicitud que el sistema no pudo entender.', 'fa-circle-exclamation'],
    401 => ['Necesitas iniciar sesión', 'Para ver esta página primero inicia sesión con tu cuenta.', 'fa-user-lock'],
    403 => ['Acceso restringido', 'No tienes permiso para ver esta sección. Si crees que es un error, acude a Control Escolar.', 'fa-lock'],
    404 => ['Página no encontrada', 'La página que buscas no existe, cambió de lugar o el enlace está incompleto.', 'fa-map-location-dot'],
    405 => ['Acción no permitida', 'Esta página no acepta ese tipo de solicitud.', 'fa-ban'],
    408 => ['Tiempo de espera agotado', 'La solicitud tardó demasiado. Revisa tu conexión e inténtalo de nuevo.', 'fa-hourglass-end'],
    500 => ['Error interno del sistema', 'Ocurrió un problema al procesar tu solicitud. Inténtalo de nuevo en unos minutos.', 'fa-screwdriver-wrench'],
    502 => ['Servicio no disponible', 'El servidor no respondió correctamente. Inténtalo de nuevo en unos minutos.', 'fa-plug-circle-xmark'],
    503 => ['Sistema en mantenimiento', 'Estamos realizando ajustes. Vuelve a intentarlo en unos minutos.', 'fa-screwdriver-wrench'],
];
[$titulo, $detalle, $icono] = $mensajes[$codigo];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$solicitada = $_SERVER['REDIRECT_URL'] ?? ($_SERVER['REQUEST_URI'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#063f2b">
    <title><?php echo $codigo . ' · ' . $h($titulo); ?> · SIGE-COBAEP</title>
    <link rel="icon" href="<?php echo $h($base); ?>/img/LogoCobaep.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $h($base); ?>/css/error.css?v=1">
</head>
<body>
    <header>
        <img src="<?php echo $h($base); ?>/img/logo-repor.png" alt="COBAEP">
        <div><strong>SIGE<span>-Cobaep</span></strong><small>Plantel 27 · Zaragoza, Puebla</small></div>
    </header>
    <main>
        <section class="tarjeta" role="alert">
            <div class="ilustracion"><div class="anillo"></div><div class="circulo"><i class="fas <?php echo $icono; ?>"></i></div></div>
            <div class="codigo"><?php echo $codigo; ?></div>
            <h1><?php echo $h($titulo); ?></h1>
            <p><?php echo $h($detalle); ?></p>
            <?php if ($codigo === 404 && $solicitada !== ''): ?><span class="ruta"><?php echo $h($solicitada); ?></span><?php endif; ?>
            <div class="acciones">
                <a class="btn principal" href="<?php echo $h($destino); ?>"><i class="fas <?php echo $con_sesion ? 'fa-house' : 'fa-right-to-bracket'; ?>"></i> <?php echo $h($texto_destino); ?></a>
                <button type="button" class="btn sec" onclick="if (history.length > 1) history.back(); else location.href='<?php echo $h($destino); ?>';"><i class="fas fa-arrow-left"></i> Regresar</button>
                <?php if ($codigo >= 500 || $codigo === 408): ?><button type="button" class="btn sec" onclick="location.reload()"><i class="fas fa-rotate-right"></i> Reintentar</button><?php endif; ?>
            </div>
            <div class="ayuda"><i class="fas fa-circle-info"></i> Si el problema continúa, comunícalo a Control Escolar del plantel (código <?php echo $codigo; ?>).</div>
        </section>
    </main>
    <footer>© <?php echo date('Y'); ?> COBAEP Plantel 27 · Sistema de Información y Gestión Educativa</footer>
</body>
</html>
