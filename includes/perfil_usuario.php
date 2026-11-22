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
    <style>
        .pu-grid { display: grid; grid-template-columns: 320px 1fr; gap: 1.5rem; align-items: start; }
        .pu-card { background: #fff; border: 1px solid #e8efeb; border-radius: 20px; padding: 1.5rem; box-shadow: 0 4px 18px rgba(9, 48, 32, .05); }
        .pu-card h3 { margin: 0 0 1rem; font-size: 1.05rem; color: #10251d; display: flex; align-items: center; gap: 10px; }
        .pu-card h3 i { color: #16a05d; }
        .pu-foto { text-align: center; }
        .pu-avatar { width: 140px; height: 140px; border-radius: 50%; margin: 0 auto 1rem; background: linear-gradient(145deg, #063f2b, #16a05d); color: #fff; display: grid; place-items: center; font-size: 2.6rem; font-weight: 800; overflow: hidden; border: 4px solid #ecfdf5; }
        .pu-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .pu-foto h2 { margin: 0; font-size: 1.2rem; color: #0f172a; }
        .pu-rol { display: inline-block; margin-top: 6px; background: #ecfdf5; color: #047857; font-size: .75rem; font-weight: 800; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; }
        .pu-dato { display: flex; gap: 10px; align-items: center; font-size: .85rem; color: #475569; margin-top: 10px; justify-content: center; }
        .pu-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .pu-field { display: flex; flex-direction: column; gap: 5px; }
        .pu-field label { font-size: .8rem; font-weight: 700; color: #475569; }
        .pu-field input { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: .92rem; }
        .pu-field input:disabled { background: #f1f5f9; color: #64748b; }
        .pu-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border: none; border-radius: 10px; background: #16a05d; color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; }
        .pu-btn.sec { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
        .pu-acciones { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .pu-upload { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 14px; cursor: pointer; display: block; color: #64748b; font-size: .85rem; margin-bottom: 10px; }
        .pu-upload input { display: none; }
        .pu-alerta { padding: 12px 16px; border-radius: 12px; margin-bottom: 1rem; font-weight: 600; font-size: .9rem; }
        .pu-alerta.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .pu-alerta.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        @media (max-width: 900px) { .pu-grid { grid-template-columns: 1fr; } .pu-form-grid { grid-template-columns: 1fr; } }
    </style>

    <?php if (isset($_GET['exito'])): ?><div class="pu-alerta ok"><i class="fas fa-check-circle"></i> <?php echo $h($_GET['exito']); ?></div><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="pu-alerta err"><i class="fas fa-exclamation-circle"></i> <?php echo $h($_GET['error']); ?></div><?php endif; ?>

    <div class="pu-grid">
        <div class="pu-card pu-foto" id="foto">
            <div class="pu-avatar"><?php if ($u['foto_url']): ?><img src="<?php echo $h($u['foto_url']); ?>" alt="Foto de perfil"><?php else: echo $h($iniciales); endif; ?></div>
            <h2><?php echo $h(trim(($f['nombre'] ?? '') . ' ' . ($f['apellido_paterno'] ?? '') . ' ' . ($f['apellido_materno'] ?? ''))); ?></h2>
            <span class="pu-rol"><?php echo $c['etiqueta']; ?></span>
            <div class="pu-dato"><i class="fas fa-user"></i> Usuario: <b><?php echo $h($f['usuario'] ?? ''); ?></b></div>

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
