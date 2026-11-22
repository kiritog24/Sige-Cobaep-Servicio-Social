<?php
/**
 * admin/config_correo.php — Cuenta de correo del plantel para los avisos a padres/tutores.
 * Administración la configura aquí (sin editar archivos) cuando el instituto entregue la cuenta.
 * La contraseña se guarda cifrada y nunca se vuelve a mostrar.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/correo.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

const PROVEEDORES = [
    'gmail'   => ['Gmail / Google Workspace', 'smtp.gmail.com', 587, 'tls'],
    'outlook' => ['Outlook / Microsoft 365', 'smtp.office365.com', 587, 'tls'],
    'otro'    => ['Otro servidor', '', 587, 'tls'],
];

function volver_cc(string $tipo, string $msg): void {
    header('Location: config_correo.php?' . $tipo . '=' . urlencode($msg));
    exit();
}

$guardado = config_obtener($pdo, 'correo_avisos');
$guardado = is_array($guardado) ? $guardado : [];
$tiene_pwd = !empty($guardado['contrasena']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $prov = array_key_exists($_POST['proveedor'] ?? '', PROVEEDORES) ? $_POST['proveedor'] : 'gmail';
        $usuario = mb_strtolower(trim($_POST['usuario'] ?? ''));
        $host = trim($_POST['host'] ?? '');
        $puerto = (int)($_POST['puerto'] ?? 587);
        $seguridad = in_array($_POST['seguridad'] ?? '', ['tls', 'ssl'], true) ? $_POST['seguridad'] : 'tls';
        if ($prov !== 'otro') [, $host, $puerto, $seguridad] = PROVEEDORES[$prov];
        $nombre = mb_substr(trim($_POST['nombre_remitente'] ?? ''), 0, 80) ?: 'COBAEP Plantel 27 · Prefectura';
        $responder = mb_strtolower(trim($_POST['responder_a'] ?? ''));
        $pwd_nueva = (string)($_POST['contrasena'] ?? '');
        $activo = !empty($_POST['activo']);

        if ($usuario !== '' && !filter_var($usuario, FILTER_VALIDATE_EMAIL)) volver_cc('error', 'La cuenta de correo no es válida.');
        if ($responder !== '' && !filter_var($responder, FILTER_VALIDATE_EMAIL)) volver_cc('error', 'El correo para respuestas no es válido.');
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) volver_cc('error', 'Escribe el servidor SMTP (por ejemplo smtp.gmail.com).');
        if ($puerto < 1 || $puerto > 65535) volver_cc('error', 'El puerto no es válido.');
        if ($activo && ($usuario === '' || ($pwd_nueva === '' && !$tiene_pwd))) volver_cc('error', 'Para activar el envío escribe la cuenta y su contraseña de aplicación.');

        $cfg = [
            'activo' => $activo, 'proveedor' => $prov, 'host' => $host, 'puerto' => $puerto, 'seguridad' => $seguridad,
            'usuario' => $usuario, 'remitente' => $usuario, 'nombre_remitente' => $nombre, 'responder_a' => $responder,
            // Contraseña vacía = conservar la que ya estaba
            'contrasena' => $pwd_nueva !== '' ? config_cifrar(str_replace(' ', '', $pwd_nueva)) : ($guardado['contrasena'] ?? ''),
        ];
        config_guardar($pdo, 'correo_avisos', $cfg);
        bitacora($pdo, 'avisos', 'config_correo', 'Actualizó la cuenta de correo para avisos (' . ($usuario ?: 'sin cuenta') . ', envío ' . ($activo ? 'activado' : 'desactivado') . ')' . ($pwd_nueva !== '' ? ' y cambió la contraseña' : ''));
        volver_cc('exito', $activo ? 'Configuración guardada. El envío de avisos quedó activado; te recomendamos mandar un correo de prueba.' : 'Configuración guardada. El envío sigue desactivado: los avisos se guardan como pendientes.');
    }

    if ($accion === 'quitar') {
        config_guardar($pdo, 'correo_avisos', ['activo' => false]);
        bitacora($pdo, 'avisos', 'config_correo', 'Quitó la cuenta de correo para avisos');
        volver_cc('exito', 'Se quitó la cuenta de correo. Los avisos se guardarán como pendientes.');
    }

    if ($accion === 'probar') {
        $para = mb_strtolower(trim($_POST['para'] ?? ''));
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) volver_cc('error', 'Escribe un correo válido para la prueba.');
        correo_config(true);
        if (!correo_activo()) volver_cc('error', 'Primero guarda la cuenta, su contraseña y activa el envío.');
        require_once __DIR__ . '/../includes/avisos_tutor.php';
        $html = aviso_html('Hola:', 'Este es un correo de prueba del sistema SIGE-COBAEP. Si lo recibiste, los avisos a padres y tutores ya pueden enviarse.',
                           [['Enviado desde', correo_config()['usuario']], ['Fecha', date('d/m/Y h:i a')]], 'No es necesario responder este mensaje.');
        $r = correo_enviar($para, 'Correo de prueba · SIGE-COBAEP Plantel 27', $html);
        bitacora($pdo, 'avisos', 'prueba_correo', 'Envió un correo de prueba a ' . $para . ($r['ok'] ? '' : ' (falló)'));
        $r['ok'] ? volver_cc('exito', "Correo de prueba enviado a $para. Revisa también la carpeta de spam.")
                 : volver_cc('error', 'No se pudo enviar la prueba: ' . $r['error']);
    }
    volver_cc('error', 'Acción no reconocida.');
}

$c = array_merge(['activo' => false, 'proveedor' => 'gmail', 'host' => 'smtp.gmail.com', 'puerto' => 587, 'seguridad' => 'tls',
                  'usuario' => '', 'nombre_remitente' => 'COBAEP Plantel 27 · Prefectura', 'responder_a' => ''], $guardado);
$activo = correo_activo();
$pendientes = 0;
try { $pendientes = (int)$pdo->query("SELECT COUNT(*) FROM AVISOS_TUTOR WHERE estatus IN ('pendiente','fallido','sin_correo')")->fetchColumn(); } catch (PDOException $e) {}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Correo de avisos · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=4">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .cc-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .cc-title { display: flex; align-items: center; gap: 14px; }
        .cc-title .ico { width: 52px; height: 52px; border-radius: 14px; background: #e0f2fe; color: #0369a1; display: grid; place-items: center; font-size: 1.35rem; flex-shrink: 0; }
        .cc-title h1 { margin: 0; font-size: 1.55rem; color: #0f172a; }
        .cc-title p { margin: 3px 0 0; color: #64748b; font-size: .9rem; }
        .cc-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 20px; align-items: start; }
        .cc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 22px; box-shadow: 0 4px 18px rgba(15, 23, 42, .04); margin-bottom: 20px; }
        .cc-card h2 { margin: 0 0 14px; font-size: 1.05rem; color: #0f172a; display: flex; gap: 10px; align-items: center; }
        .cc-card h2 i { color: #16a05d; }
        .cc-estado { display: flex; align-items: center; gap: 14px; padding: 16px; border-radius: 14px; margin-bottom: 20px; }
        .cc-estado.on { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .cc-estado.off { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .cc-estado i { font-size: 1.6rem; }
        .cc-estado b { display: block; font-size: 1rem; }
        .cc-estado span { font-size: .86rem; }
        .cc-campos { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .cc-campo { display: flex; flex-direction: column; gap: 5px; }
        .cc-campo.ancho { grid-column: 1 / -1; }
        .cc-campo label { font-size: .8rem; font-weight: 700; color: #475569; }
        .cc-campo input, .cc-campo select { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: .92rem; background: #fff; }
        .cc-campo small { color: #64748b; font-size: .76rem; line-height: 1.4; }
        .cc-avanzado { display: none; grid-column: 1 / -1; grid-template-columns: 2fr 1fr 1fr; gap: 14px; }
        .cc-avanzado.visible { display: grid; }
        .cc-switch { display: flex; align-items: center; gap: 12px; grid-column: 1 / -1; padding: 12px 14px; background: #f8fafc; border-radius: 12px; cursor: pointer; }
        .cc-switch input { width: 20px; height: 20px; accent-color: #16a05d; }
        .cc-switch b { color: #0f172a; font-size: .92rem; }
        .cc-switch span { display: block; color: #64748b; font-size: .8rem; }
        .cc-acciones { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; margin-top: 18px; }
        .cc-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 18px; border-radius: 11px; border: none; background: #16a05d; color: #fff; font-weight: 700; font-family: inherit; font-size: .9rem; cursor: pointer; text-decoration: none; }
        .cc-btn:hover { background: #12804b; }
        .cc-btn.sec { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
        .cc-btn.peligro { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
        .cc-btn:disabled { opacity: .5; cursor: not-allowed; }
        .cc-alerta { padding: 13px 16px; border-radius: 12px; margin-bottom: 18px; font-weight: 600; font-size: .9rem; }
        .cc-alerta.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .cc-alerta.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .cc-pasos { margin: 0; padding-left: 20px; color: #475569; font-size: .86rem; line-height: 1.6; }
        .cc-pasos li { margin-bottom: 8px; }
        .cc-pasos b { color: #0f172a; }
        .cc-nota { font-size: .8rem; color: #64748b; background: #f8fafc; border-radius: 10px; padding: 10px 12px; margin-top: 12px; line-height: 1.5; }
        .cc-pwd { position: relative; }
        .cc-pwd input { width: 100%; box-sizing: border-box; padding-right: 44px; }
        .cc-pwd button { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: none; background: none; color: #64748b; cursor: pointer; padding: 6px; }
        @media (max-width: 1000px) { .cc-grid { grid-template-columns: 1fr; } }
        @media (max-width: 560px) { .cc-campos, .cc-avanzado.visible { grid-template-columns: 1fr; } .cc-btn { width: 100%; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="cc-head">
            <div class="cc-title">
                <div class="ico"><i class="fas fa-envelope-circle-check"></i></div>
                <div>
                    <h1>Correo de avisos</h1>
                    <p>Cuenta del plantel que envía los avisos de faltas y conducta a padres y tutores.</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </div>

        <?php if (isset($_GET['exito'])): ?><div class="cc-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="cc-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

        <div class="cc-estado <?php echo $activo ? 'on' : 'off'; ?>">
            <i class="fas <?php echo $activo ? 'fa-circle-check' : 'fa-clock'; ?>"></i>
            <div>
                <b><?php echo $activo ? 'Envío de avisos activado' : 'Envío de avisos desactivado'; ?></b>
                <span><?php echo $activo ? 'Los avisos se envían desde ' . $h(correo_config()['usuario']) . '.'
                    : 'Mientras no se configure, los avisos se guardan como pendientes' . ($pendientes ? " (hay $pendientes)" : '') . ' y se envían después desde Prefectura › Avisos a tutores.'; ?></span>
            </div>
        </div>

        <div class="cc-grid">
            <div>
                <form class="cc-card" method="POST" autocomplete="off">
                    <input type="hidden" name="accion" value="guardar">
                    <h2><i class="fas fa-gear"></i> Cuenta de correo</h2>
                    <div class="cc-campos">
                        <div class="cc-campo">
                            <label for="proveedor">Servicio de correo</label>
                            <select name="proveedor" id="proveedor">
                                <?php foreach (PROVEEDORES as $k => $p): ?><option value="<?php echo $k; ?>" <?php echo $c['proveedor'] === $k ? 'selected' : ''; ?>><?php echo $p[0]; ?></option><?php endforeach; ?>
                            </select>
                            <small>Si la cuenta termina en @gmail.com o es de Google del instituto, elige Gmail.</small>
                        </div>
                        <div class="cc-campo">
                            <label for="usuario">Cuenta que envía los avisos</label>
                            <input type="email" name="usuario" id="usuario" maxlength="150" value="<?php echo $h($c['usuario']); ?>" placeholder="avisos.plantel27@...">
                            <small>La que entregue el instituto.</small>
                        </div>
                        <div class="cc-campo ancho">
                            <label for="contrasena">Contraseña de aplicación</label>
                            <div class="cc-pwd">
                                <input type="password" name="contrasena" id="contrasena" maxlength="100" autocomplete="new-password" placeholder="<?php echo $tiene_pwd ? '•••••••• guardada · déjala vacía para conservarla' : 'Ej. abcd efgh ijkl mnop'; ?>">
                                <button type="button" onclick="const i=document.getElementById('contrasena'); i.type = i.type === 'password' ? 'text' : 'password'; this.firstElementChild.classList.toggle('fa-eye-slash');" aria-label="Mostrar contraseña"><i class="fas fa-eye"></i></button>
                            </div>
                            <small><?php echo $tiene_pwd ? '<i class="fas fa-lock" style="color:#16a05d"></i> Hay una contraseña guardada (cifrada). Solo escribe aquí si quieres cambiarla.' : 'No es la contraseña normal de la cuenta: es una clave especial de 16 letras (ver los pasos a la derecha).'; ?></small>
                        </div>
                        <div class="cc-avanzado <?php echo $c['proveedor'] === 'otro' ? 'visible' : ''; ?>" id="avanzado">
                            <div class="cc-campo"><label for="host">Servidor SMTP</label><input type="text" name="host" id="host" value="<?php echo $h($c['host']); ?>" placeholder="smtp.ejemplo.com"></div>
                            <div class="cc-campo"><label for="puerto">Puerto</label><input type="number" name="puerto" id="puerto" value="<?php echo (int)$c['puerto']; ?>"></div>
                            <div class="cc-campo"><label for="seguridad">Seguridad</label>
                                <select name="seguridad" id="seguridad"><option value="tls" <?php echo $c['seguridad'] === 'tls' ? 'selected' : ''; ?>>TLS (587)</option><option value="ssl" <?php echo $c['seguridad'] === 'ssl' ? 'selected' : ''; ?>>SSL (465)</option></select></div>
                        </div>
                        <div class="cc-campo">
                            <label for="nombre_remitente">Nombre que verán los padres</label>
                            <input type="text" name="nombre_remitente" id="nombre_remitente" maxlength="80" value="<?php echo $h($c['nombre_remitente']); ?>">
                        </div>
                        <div class="cc-campo">
                            <label for="responder_a">Responder a (opcional)</label>
                            <input type="email" name="responder_a" id="responder_a" maxlength="150" value="<?php echo $h($c['responder_a']); ?>" placeholder="prefectura@...">
                            <small>Si un padre responde el aviso, le llegará a este correo.</small>
                        </div>
                        <label class="cc-switch">
                            <input type="checkbox" name="activo" value="1" <?php echo !empty($c['activo']) ? 'checked' : ''; ?>>
                            <div><b>Activar el envío de avisos</b><span>Déjalo apagado hasta tener la cuenta. Puedes guardar los datos y activarlo después.</span></div>
                        </label>
                    </div>
                    <div class="cc-acciones">
                        <button type="submit" class="cc-btn"><i class="fas fa-floppy-disk"></i> Guardar configuración</button>
                    </div>
                </form>

                <form class="cc-card" method="POST">
                    <input type="hidden" name="accion" value="probar">
                    <h2><i class="fas fa-paper-plane"></i> Enviar correo de prueba</h2>
                    <div class="cc-campos">
                        <div class="cc-campo ancho">
                            <label for="para">Enviar la prueba a</label>
                            <input type="email" name="para" id="para" required placeholder="tu.correo@ejemplo.com">
                            <small>Úsalo después de guardar y activar, para confirmar que la cuenta funciona.</small>
                        </div>
                    </div>
                    <div class="cc-acciones">
                        <button type="submit" class="cc-btn sec" <?php echo $activo ? '' : 'disabled title="Primero guarda y activa la cuenta"'; ?>><i class="fas fa-paper-plane"></i> Enviar prueba</button>
                    </div>
                </form>
            </div>

            <div>
                <div class="cc-card">
                    <h2><i class="fas fa-list-ol"></i> Cuando el instituto entregue la cuenta</h2>
                    <ol class="cc-pasos">
                        <li>Entra a la cuenta de correo (de preferencia una exclusiva para avisos del plantel).</li>
                        <li><b>Gmail:</b> activa la <b>verificación en dos pasos</b> en <i>Cuenta de Google › Seguridad</i>.</li>
                        <li>En la misma sección busca <b>Contraseñas de aplicación</b> (myaccount.google.com/apppasswords), escribe un nombre como "SIGE" y copia la clave de 16 letras.</li>
                        <li>Pégala aquí en <b>Contraseña de aplicación</b>, escribe la cuenta, activa el envío y guarda.</li>
                        <li>Manda un <b>correo de prueba</b> a tu propio correo.</li>
                        <li>En <b>Prefectura › Avisos a tutores</b> pulsa <b>Enviar pendientes</b> para mandar los avisos que se guardaron.</li>
                    </ol>
                    <div class="cc-nota"><i class="fas fa-circle-info"></i> <b>Microsoft 365 / Outlook del instituto:</b> el área de sistemas del instituto debe tener habilitado el envío por SMTP para esa cuenta. Si usan otro servidor, elige "Otro servidor" y escribe los datos que te den.</div>
                    <div class="cc-nota"><i class="fas fa-shield-halved"></i> La contraseña se guarda <b>cifrada</b> y nunca se vuelve a mostrar. Cada cambio queda registrado en la Bitácora.</div>
                </div>
                <?php if (!empty($guardado['usuario']) || $tiene_pwd): ?>
                    <form class="cc-card" method="POST" onsubmit="return confirm('¿Quitar la cuenta de correo? Los avisos se guardarán como pendientes hasta que se configure otra.');">
                        <input type="hidden" name="accion" value="quitar">
                        <h2><i class="fas fa-trash-can" style="color:#b91c1c"></i> Quitar cuenta</h2>
                        <p style="margin:0 0 12px; color:#64748b; font-size:.86rem;">Borra la cuenta y su contraseña del sistema (por ejemplo, si el instituto la cambia).</p>
                        <button type="submit" class="cc-btn peligro"><i class="fas fa-trash-can"></i> Quitar cuenta de correo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script>
        document.getElementById('proveedor').addEventListener('change', function () {
            document.getElementById('avanzado').classList.toggle('visible', this.value === 'otro');
        });
    </script>
    <script src="../js/responsive.js?v=6"></script>
</body>
</html>
