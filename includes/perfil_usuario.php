<?php
/**
 * includes/perfil_usuario.php
 * Contenido de "Mi Perfil" para Administración y Prefectura (datos, foto y contraseña).
 * Requiere includes/topbar_usuario.php.
 */
require_once __DIR__ . '/topbar_usuario.php';

function perfil_usuario_contenido(PDO $pdo): void {
    $c = usuario_rol_config();
    $u = usuario_actual($pdo);
    $f = $u['fila'];
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $iniciales = mb_strtoupper(mb_substr($f['nombre'] ?? 'U', 0, 1) . mb_substr($f['apellido_paterno'] ?? '', 0, 1));
    ?>
    <link rel="stylesheet" href="../css/perfil_usuario.css?v=1">

    <?php if (isset($_GET['exito'])): ?><div class="pu-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="pu-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

    <div class="pu-grid">
        <div class="pu-card pu-foto" id="foto">
            <div class="pu-avatar"><?php if ($u['foto_url']): ?><img src="<?php echo $h($u['foto_url']); ?>" alt="Foto de perfil"><?php else: echo $h($iniciales); endif; ?></div>
            <h2><?php echo $h(trim(($f['nombre'] ?? '') . ' ' . ($f['apellido_paterno'] ?? '') . ' ' . ($f['apellido_materno'] ?? ''))); ?></h2>
            <span class="pu-rol"><?php echo $c['etiqueta']; ?></span>
            <div class="pu-dato"><i class="fas fa-user"></i> Usuario: <b><?php echo $h($f['usuario'] ?? ''); ?></b></div>
            <?php
                require_once __DIR__ . '/credencial_personal.php';
                $tipo_cred = ($_SESSION['rol'] ?? '') === 'prefectos' ? 'prefectos' : 'admin';
                $id_cred = (int)($_SESSION['id_usuario'] ?? 0);
                $qr_cred = credencial_qr_texto($tipo_cred, ['usuario' => $f['usuario'] ?? '']);
            ?>
            <button type="button" class="pu-btn pu-cred" data-credencial="<?php echo $tipo_cred . ':' . $id_cred; ?>" data-qr="<?php echo $h($qr_cred); ?>" data-modo="ver"><i class="fas fa-id-card"></i> Mi credencial (PDF)</button>

            <form action="../procesos/perfil_usuario.php" method="POST" enctype="multipart/form-data" style="margin-top:1.2rem;" id="formFoto">
                <input type="hidden" name="accion" value="foto">
                <label class="pu-upload"><i class="fas fa-cloud-arrow-up"></i> <span id="puFotoNombre">Elegir foto (JPG, PNG o WEBP · máx. 2 MB · mín. 400×400 px)</span>
                    <input type="file" name="foto_perfil" id="puFoto" accept="image/jpeg,image/png,image/webp" required>
                </label>
                <button type="submit" class="pu-btn"><i class="fas fa-camera"></i> Subir foto</button>
            </form>
            <?php if ($u['foto_url']): ?>
                <form action="../procesos/perfil_usuario.php" method="POST" style="margin-top:8px;" onsubmit="return confirm('¿Quitar tu foto de perfil?');">
                    <input type="hidden" name="accion" value="quitar_foto">
                    <button type="submit" class="pu-btn sec"><i class="fas fa-trash"></i> Quitar foto</button>
                </form>
            <?php endif; ?>
        </div>

        <div>
            <div class="pu-card">
                <h3><i class="fas fa-id-card"></i> Datos personales y de contacto</h3>
                <form action="../procesos/perfil_usuario.php" method="POST">
                    <input type="hidden" name="accion" value="datos">
                    <div class="pu-form-grid">
                        <div class="pu-field"><label>Nombre(s) *</label><input type="text" name="nombre" maxlength="100" required value="<?php echo $h($f['nombre'] ?? ''); ?>"></div>
                        <div class="pu-field"><label>Apellido paterno *</label><input type="text" name="apellido_paterno" maxlength="100" required value="<?php echo $h($f['apellido_paterno'] ?? ''); ?>"></div>
                        <div class="pu-field"><label>Apellido materno</label><input type="text" name="apellido_materno" maxlength="100" value="<?php echo $h($f['apellido_materno'] ?? ''); ?>"></div>
                        <div class="pu-field"><label>Usuario de acceso</label><input type="text" value="<?php echo $h($f['usuario'] ?? ''); ?>" disabled></div>
                        <div class="pu-field"><label>Teléfono</label><input type="tel" name="telefono" pattern="[0-9]{10}" maxlength="10" placeholder="10 dígitos" value="<?php echo $h($f['telefono'] ?? ''); ?>"></div>
                        <div class="pu-field"><label>Correo electrónico</label><input type="email" name="correo" maxlength="150" value="<?php echo $h($f['correo'] ?? ''); ?>"></div>
                    </div>
                    <div class="pu-acciones"><button type="submit" class="pu-btn"><i class="fas fa-save"></i> Guardar datos</button></div>
                </form>
            </div>

            <div class="pu-card" id="seguridad" style="margin-top:1.5rem;">
                <h3><i class="fas fa-key"></i> Cambiar contraseña</h3>
                <form action="../procesos/perfil_usuario.php" method="POST" autocomplete="off">
                    <input type="hidden" name="accion" value="password">
                    <div class="pu-form-grid">
                        <div class="pu-field" style="grid-column:1/-1;"><label>Contraseña actual *</label><input type="password" name="actual" required autocomplete="current-password"></div>
                        <div class="pu-field"><label>Nueva contraseña * (mín. 8 caracteres)</label><input type="password" name="nueva" minlength="8" required autocomplete="new-password"></div>
                        <div class="pu-field"><label>Confirmar nueva contraseña *</label><input type="password" name="confirmar" minlength="8" required autocomplete="new-password"></div>
                    </div>
                    <div class="pu-acciones"><button type="submit" class="pu-btn"><i class="fas fa-lock"></i> Actualizar contraseña</button></div>
                </form>
            </div>
        </div>
    </div>
    <script src="../js/credencial_personal.js"></script>
    <script>
        document.getElementById('puFoto').addEventListener('change', function () {
            const f = this.files[0];
            if (f && f.size > 2 * 1024 * 1024) {
                alert('La foto pesa ' + (f.size / 1048576).toFixed(1) + ' MB. El máximo es 2 MB.');
                this.value = ''; document.getElementById('puFotoNombre').textContent = 'Elegir foto (JPG, PNG o WEBP · máx. 2 MB · mín. 400×400 px)'; return;
            }
            if (f) document.getElementById('puFotoNombre').textContent = f.name;
        });
    </script>
    <?php
}
