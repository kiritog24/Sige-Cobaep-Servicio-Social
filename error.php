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
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; color: #10251d;
            background: radial-gradient(circle at 12% 10%, rgba(22, 160, 93, .14), transparent 32rem),
                        radial-gradient(circle at 90% 90%, rgba(6, 63, 43, .12), transparent 30rem), #f4f8f6;
        }
        header { display: flex; align-items: center; gap: 12px; padding: 18px 28px; }
        header img { height: 40px; width: auto; object-fit: contain; }
        header strong { font-size: 1.05rem; color: #063f2b; }
        header strong span { color: #16a05d; }
        header small { display: block; color: #6d7f77; font-size: .75rem; }
        main { flex: 1; display: grid; place-items: center; padding: 16px 16px 40px; }
        .tarjeta {
            width: 100%; max-width: 620px; background: #fff; border: 1px solid #e4ece8; border-radius: 28px;
            padding: 44px 40px 36px; text-align: center; box-shadow: 0 24px 60px rgba(9, 48, 32, .08);
        }
        .ilustracion { position: relative; width: 132px; height: 132px; margin: 0 auto 18px; }
        .ilustracion .circulo { position: absolute; inset: 0; border-radius: 50%; background: linear-gradient(145deg, #063f2b, #16a05d); display: grid; place-items: center; color: #fff; font-size: 3rem; box-shadow: 0 16px 34px rgba(22, 160, 93, .3); }
        .ilustracion .anillo { position: absolute; inset: -12px; border-radius: 50%; border: 2px dashed rgba(22, 160, 93, .35); animation: girar 18s linear infinite; }
        @keyframes girar { to { transform: rotate(360deg); } }
        .codigo { font-size: clamp(3.2rem, 12vw, 5.2rem); font-weight: 900; line-height: 1; letter-spacing: -.04em; margin: 4px 0 6px;
                  background: linear-gradient(135deg, #063f2b, #16a05d); -webkit-background-clip: text; background-clip: text; color: transparent; }
        h1 { margin: 0 0 10px; font-size: clamp(1.25rem, 4vw, 1.6rem); }
        p { margin: 0 auto; max-width: 440px; color: #5b6b64; line-height: 1.6; font-size: .97rem; }
        .ruta { display: inline-block; max-width: 100%; margin-top: 14px; padding: 6px 12px; border-radius: 10px; background: #f1f5f3; color: #64748b; font-size: .78rem; font-family: Consolas, monospace; overflow-wrap: anywhere; }
        .acciones { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 28px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 9px; padding: 13px 22px; border-radius: 14px; font-weight: 700; font-size: .95rem; text-decoration: none; border: none; cursor: pointer; font-family: inherit; transition: transform .15s, box-shadow .15s, background .15s; }
        .btn:hover { transform: translateY(-1px); }
        .btn.principal { background: #16a05d; color: #fff; box-shadow: 0 10px 22px rgba(22, 160, 93, .28); }
        .btn.principal:hover { background: #12804b; }
        .btn.sec { background: #fff; color: #10251d; border: 1px solid #d5e2dc; }
        .btn.sec:hover { background: #f4f8f6; }
        .ayuda { margin-top: 26px; padding-top: 20px; border-top: 1px solid #edf2ef; font-size: .82rem; color: #7b8b84; }
        footer { text-align: center; padding: 16px; font-size: .78rem; color: #8a9a93; }
        @media (max-width: 520px) {
            header { padding: 14px 16px; }
            .tarjeta { padding: 32px 20px 26px; border-radius: 22px; }
            .ilustracion { width: 104px; height: 104px; }
            .ilustracion .circulo { font-size: 2.4rem; }
            .btn { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) { .ilustracion .anillo { animation: none; } }
    </style>
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
