<?php
/**
 * cambiar_password.php — Cambio de contraseña para cualquier usuario (alumno, docente,
 * administración o prefectura). Es obligatorio cuando la cuenta tiene una contraseña provisional
 * (la genera Administración al restablecerla) o la contraseña temporal inicial del alumno.
 * Al cambiarla, la provisional deja de existir: no se puede volver a usar.
 */
session_start();
require_once 'config/database.php';

$c = cuenta_config($_SESSION['rol'] ?? '');
if (!isset($_SESSION['id_usuario']) || !$c) {
    header("Location: login.php");
    exit();
}
$id_usuario = (int)$_SESSION['id_usuario'];
$obligatorio = !empty($_SESSION['debe_cambiar_password']);
$error = '';

$st = $pdo->prepare("SELECT nombre, apellido_paterno, {$c['usuario']} AS usuario, contrasena_hash FROM {$c['tabla']} WHERE {$c['id']} = ?");
$st->execute([$id_usuario]);
$alumno = $st->fetch(PDO::FETCH_ASSOC);   // (datos del usuario en sesión, sea del rol que sea)
if (!$alumno) {
    header("Location: logout.php");
    exit();
}
$alumno['matricula'] = $alumno['usuario'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $confirmar = (string)($_POST['confirmar'] ?? '');

    if (!password_verify($actual, $alumno['contrasena_hash'])) {
        $error = 'La contraseña actual es incorrecta.';
    } elseif (strlen($nueva) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif (!preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $nueva) || !preg_match('/\d/', $nueva)) {
        $error = 'La nueva contraseña debe incluir letras y números.';
    } elseif ($nueva !== $confirmar) {
        $error = 'Las contraseñas nuevas no coinciden.';
    } elseif (password_verify($nueva, $alumno['contrasena_hash'])) {
        $error = 'La nueva contraseña debe ser diferente a la actual.';
    } elseif (strcasecmp($nueva, 'Cobaep2026!') === 0 || stripos($nueva, str_replace('/', '', $alumno['matricula'])) !== false || stripos($nueva, $alumno['matricula']) !== false) {
        $error = 'No uses la contraseña inicial ni tu matrícula o usuario como contraseña.';
    } else {
        try {
            // La provisional queda reemplazada y su vencimiento se borra: ya no sirve para entrar
            $pdo->prepare("UPDATE {$c['tabla']} SET contrasena_hash = ?, debe_cambiar_password = 0, password_provisional_expira = NULL WHERE {$c['id']} = ?")
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $id_usuario]);
            unset($_SESSION['debe_cambiar_password']);
            session_regenerate_id(true);
            header("Location: " . $c['inicio'] . "?exito=" . urlencode('Tu contraseña se actualizó correctamente.'));
            exit();
        } catch (PDOException $e) {
            error_log('cambiar_password: ' . $e->getMessage());
            $error = 'No se pudo guardar la contraseña. Intenta de nuevo.';
        }
    }
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Cambiar contraseña · SIGE COBAEP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root { --green: #16a05d; --green-900: #063f2b; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: 'Inter', system-ui, sans-serif; background: radial-gradient(circle at top left, #e9f8f0, #f6faf8 45%, #eef4f1); color: var(--ink); display: flex; align-items: center; justify-content: center; padding: 20px 16px; }
        .cp-card { width: 100%; max-width: 460px; background: #fff; border: 1px solid #e5ece8; border-radius: 24px; box-shadow: 0 24px 60px rgba(6, 63, 43, .12); overflow: hidden; }
        .cp-head { background: linear-gradient(160deg, var(--green-900), #0f7a45 65%, var(--green)); color: #fff; padding: 26px 26px 22px; text-align: center; }
        .cp-head img { width: 58px; margin-bottom: 8px; }
        .cp-head h1 { margin: 0; font-size: 1.3rem; font-weight: 800; }
        .cp-head p { margin: 6px 0 0; font-size: .86rem; color: #d1fae5; line-height: 1.45; }
        .cp-body { padding: 22px 26px 26px; }
        .cp-aviso { display: flex; gap: 10px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 14px; padding: 12px 14px; font-size: .84rem; line-height: 1.45; margin-bottom: 18px; }
        .cp-error { display: flex; gap: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 14px; padding: 12px 14px; font-size: .86rem; font-weight: 600; margin-bottom: 16px; }
        .cp-field { margin-bottom: 14px; }
        .cp-field label { display: block; font-size: .8rem; font-weight: 700; color: #475569; margin-bottom: 6px; }
        .cp-input { position: relative; }
        .cp-input input { width: 100%; padding: 12px 44px 12px 14px; border: 1px solid #cbd5e1; border-radius: 12px; font-family: inherit; font-size: 16px; transition: .15s; }
        .cp-input input:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(22, 160, 93, .15); }
        .cp-ver { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: none; background: none; color: #94a3b8; cursor: pointer; border-radius: 8px; }
        .cp-ver:hover { color: var(--green); background: #f1f5f9; }
        .cp-reglas { list-style: none; margin: 4px 0 18px; padding: 0; display: grid; gap: 6px; font-size: .8rem; color: var(--muted); }
        .cp-reglas li { display: flex; align-items: center; gap: 8px; }
        .cp-reglas li i { width: 16px; text-align: center; color: #cbd5e1; }
        .cp-reglas li.ok { color: #047857; } .cp-reglas li.ok i { color: var(--green); }
        .cp-barra { height: 6px; border-radius: 99px; background: #f1f5f9; overflow: hidden; margin: -4px 0 14px; }
        .cp-barra span { display: block; height: 100%; width: 0; border-radius: 99px; transition: .25s; }
        .cp-btn { width: 100%; padding: 13px; border: none; border-radius: 12px; background: var(--green); color: #fff; font-weight: 800; font-size: .95rem; font-family: inherit; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .cp-btn:disabled { background: #a7d7bf; cursor: not-allowed; }
        .cp-pie { display: flex; justify-content: space-between; gap: 10px; margin-top: 16px; font-size: .82rem; }
        .cp-pie a { color: var(--muted); text-decoration: none; font-weight: 600; }
        .cp-pie a:hover { color: var(--green); }
    </style>
</head>
<body>
    <main class="cp-card">
        <div class="cp-head">
            <img src="img/LogoCobaep.png" alt="COBAEP">
            <h1><?php echo $obligatorio ? 'Crea tu nueva contraseña' : 'Cambiar contraseña'; ?></h1>
            <p>Hola, <?php echo $h($alumno['nombre']); ?> · <?php echo $c['etiqueta']; ?> · <?php echo $_SESSION['rol'] === 'estudiante' ? 'Matrícula' : 'Usuario'; ?> <?php echo $h($alumno['usuario']); ?></p>
        </div>
        <div class="cp-body">
            <?php if ($obligatorio): ?>
                <div class="cp-aviso"><i class="fas fa-shield-halved" style="margin-top:2px;"></i>
                    <span>Estás usando una <b>contraseña provisional</b>. Por tu seguridad, antes de entrar al sistema crea una nueva que solo tú conozcas. La provisional dejará de funcionar en cuanto la cambies.</span></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="cp-error"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i><span><?php echo $h($error); ?></span></div>
            <?php endif; ?>

            <form method="POST" id="formCambio" autocomplete="off">
                <div class="cp-field">
                    <label for="actual"><?php echo $obligatorio ? 'Contraseña provisional' : 'Contraseña actual'; ?></label>
                    <div class="cp-input"><input type="password" name="actual" id="actual" required autocomplete="current-password">
                        <button type="button" class="cp-ver" data-ver="actual" aria-label="Mostrar contraseña"><i class="far fa-eye"></i></button></div>
                </div>
                <div class="cp-field">
                    <label for="nueva">Nueva contraseña</label>
                    <div class="cp-input"><input type="password" name="nueva" id="nueva" required minlength="8" autocomplete="new-password">
                        <button type="button" class="cp-ver" data-ver="nueva" aria-label="Mostrar contraseña"><i class="far fa-eye"></i></button></div>
                </div>
                <div class="cp-barra"><span id="barra"></span></div>
                <ul class="cp-reglas">
                    <li id="r-largo"><i class="fas fa-circle-check"></i> Al menos 8 caracteres</li>
                    <li id="r-mix"><i class="fas fa-circle-check"></i> Letras y números</li>
                    <li id="r-igual"><i class="fas fa-circle-check"></i> Ambas contraseñas coinciden</li>
                </ul>
                <div class="cp-field">
                    <label for="confirmar">Confirma la nueva contraseña</label>
                    <div class="cp-input"><input type="password" name="confirmar" id="confirmar" required minlength="8" autocomplete="new-password">
                        <button type="button" class="cp-ver" data-ver="confirmar" aria-label="Mostrar contraseña"><i class="far fa-eye"></i></button></div>
                </div>
                <button type="submit" class="cp-btn" id="btnGuardar" disabled><i class="fas fa-lock"></i> Guardar contraseña</button>
            </form>

            <div class="cp-pie">
                <?php if (!$obligatorio): ?><a href="<?php echo $h($c['inicio']); ?>"><i class="fas fa-arrow-left"></i> Volver al inicio</a><?php else: ?><span></span><?php endif; ?>
                <a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Cerrar sesión</a>
            </div>
        </div>
    </main>
    <script>
        const nueva = document.getElementById('nueva'), conf = document.getElementById('confirmar'), btn = document.getElementById('btnGuardar'), barra = document.getElementById('barra');
        function revisar() {
            const v = nueva.value;
            const largo = v.length >= 8, mix = /[A-Za-zÁÉÍÓÚáéíóúÑñ]/.test(v) && /\d/.test(v), igual = v !== '' && v === conf.value;
            document.getElementById('r-largo').classList.toggle('ok', largo);
            document.getElementById('r-mix').classList.toggle('ok', mix);
            document.getElementById('r-igual').classList.toggle('ok', igual);
            let fuerza = (largo ? 1 : 0) + (mix ? 1 : 0) + (v.length >= 12 ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
            barra.style.width = (fuerza * 25) + '%';
            barra.style.background = ['#ef4444', '#ef4444', '#f59e0b', '#16a05d', '#047857'][fuerza];
            btn.disabled = !(largo && mix && igual && document.getElementById('actual').value);
        }
        ['input', 'change'].forEach(ev => document.getElementById('formCambio').addEventListener(ev, revisar));
        document.querySelectorAll('[data-ver]').forEach(b => b.addEventListener('click', () => {
            const i = document.getElementById(b.dataset.ver); const ver = i.type === 'password';
            i.type = ver ? 'text' : 'password'; b.innerHTML = ver ? '<i class="far fa-eye-slash"></i>' : '<i class="far fa-eye"></i>';
        }));
        revisar();
    </script>
</body>
</html>
