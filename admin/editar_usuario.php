<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
if (!isset($_GET['id']) || !isset($_GET['tipo'])) {
    header("Location: gestion_usuarios.php");
    exit();
}
$id_editar = intval($_GET['id']);
$tipo = $_GET['tipo'];
$usuario = null;
$grupos = [];
$turnos_catalogo = [];
$etiquetas_rol = ['alumnos' => 'Alumno / Estudiante', 'maestros' => 'Personal Docente', 'admin' => 'Administrativo', 'prefectos' => 'Prefectura'];
$nombre_rol_visual = $etiquetas_rol[$tipo] ?? strtoupper($tipo);
try {
    if ($tipo === 'alumnos') {
        $sql = "SELECT a.id_alumno AS id, a.matricula AS identificador, a.codigo_qr, a.turno, a.nombre, a.apellido_paterno, a.apellido_materno, a.correo_institucional, a.estatus, a.puntos_conducta, ga.id_grupo, t.nombre_tutor, t.correo_tutor FROM ALUMNOS a LEFT JOIN TUTOR_ALUMNO t ON t.id_alumno = a.id_alumno LEFT JOIN GRUPO_ALUMNO ga ON a.id_alumno = ga.id_alumno AND ga.fecha_baja IS NULL WHERE a.id_alumno = ?";
        $stmt = $pdo->prepare($sql);
        $grupos = $pdo->query("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS ORDER BY semestre, nombre_grupo ASC")->fetchAll();
        $turnos_catalogo = $pdo->query("SELECT turno, hora_entrada, hora_corte FROM HORARIOS_TURNO")->fetchAll();
    } elseif ($tipo === 'maestros') {
        $stmt = $pdo->prepare("SELECT id_maestro AS id, usuario AS identificador, nombre, apellido_paterno, apellido_materno FROM MAESTROS WHERE id_maestro = ?");
    } elseif ($tipo === 'admin') {
        $stmt = $pdo->prepare("SELECT id_admin AS id, usuario AS identificador, nombre, apellido_paterno, apellido_materno FROM ADMINISTRATIVOS WHERE id_admin = ?");
    } elseif ($tipo === 'prefectos') {
        $stmt = $pdo->prepare("SELECT id_prefecto AS id, usuario AS identificador, nombre, apellido_paterno, apellido_materno FROM PREFECTOS WHERE id_prefecto = ?");
    } else {
        header("Location: gestion_usuarios.php");
        exit();
    }
    $stmt->execute([$id_editar]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        header("Location: gestion_usuarios.php?tipo=" . urlencode($tipo) . "&error=" . urlencode("El usuario solicitado no existe."));
        exit();
    }
} catch (PDOException $e) {
    die("Error de BD: " . $e->getMessage());
}
$iniciales = strtoupper(mb_substr($usuario['nombre'], 0, 1, 'UTF-8') . mb_substr($usuario['apellido_paterno'], 0, 1, 'UTF-8'));
$nombre_admin = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Administrador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Editar Usuario · SIGE-Cobaep</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style-editar_usuario.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style_sidebar.css">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <header class="edit-topbar">
            <div class="edit-topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="gestion_usuarios.php?tipo=<?php echo urlencode($tipo); ?>">Directorio</a><i class="fas fa-chevron-right"></i>
                    <span><?php echo htmlspecialchars($nombre_rol_visual); ?></span><i class="fas fa-chevron-right"></i><strong>Editar Perfil</strong>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>
        <section class="edit-header">
            <div class="edit-header-left">
                <div class="edit-header-icon"><i class="fas fa-user-pen"></i></div>
                <div class="edit-header-copy"><span>GESTIÓN DE CUENTAS</span><h1>Edición de Usuario</h1></div>
            </div>
            <a href="gestion_usuarios.php?tipo=<?php echo urlencode($tipo); ?>" class="btn-back-directory"><i class="fas fa-arrow-left"></i> Volver al Directorio</a>
        </section>
        <?php if (isset($_GET['exito'])): ?>
            <div class="banner-status banner-success"><i class="fas fa-circle-check"></i><div>
                <?php if ($_GET['exito'] === 'password'): ?> ¡Contraseña restablecida correctamente! El usuario ya puede ingresar con su clave provisional.
                <?php else: ?> ¡Usuario editado correctamente! Los cambios se guardaron y sincronizaron en la base de datos.
                <?php endif; ?>
            </div></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="banner-status banner-error"><i class="fas fa-triangle-exclamation"></i><div><strong>Error al procesar la solicitud:</strong><br><?php echo htmlspecialchars($_GET['error']); ?></div></div>
        <?php endif; ?>
        <div class="edit-workspace">
            <section class="edit-card">
                <div class="edit-card-header">
                    <h2><i class="fas fa-id-card-clip"></i> Datos Generales y Académicos</h2>
                    <span class="role-pill <?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars($nombre_rol_visual); ?></span>
                </div>
                <form action="../procesos/actualizar_usuario.php" method="POST" id="formEditarUsuario" class="edit-card-body">
                    <input type="hidden" name="id_usuario" value="<?php echo $usuario['id']; ?>">
                    <input type="hidden" name="tipo_usuario" value="<?php echo htmlspecialchars($tipo); ?>">
                    <div class="field-group">
                        <label for="nombre">Nombre(s)</label>
                        <div class="input-icon-wrap"><i class="fas fa-user"></i><input type="text" name="nombre" id="nombre" value="<?php echo htmlspecialchars($usuario['nombre']); ?>" required></div>
                    </div>
                    <div class="fields-grid-2">
                        <div class="field-group">
                            <label for="apellido_p">Apellido Paterno</label>
                            <div class="input-icon-wrap"><i class="fas fa-signature"></i><input type="text" name="apellido_p" id="apellido_p" value="<?php echo htmlspecialchars($usuario['apellido_paterno']); ?>" required></div>
                        </div>
                        <div class="field-group">
                            <label for="apellido_m">Apellido Materno</label>
                            <div class="input-icon-wrap"><i class="fas fa-signature"></i><input type="text" name="apellido_m" id="apellido_m" value="<?php echo htmlspecialchars($usuario['apellido_materno'] ?? ''); ?>" placeholder="Opcional"></div>
                        </div>
                    </div>
                    <?php if ($tipo === 'alumnos'): ?>
                        <div class="fields-grid-2">
                            <div class="field-group">
                                <label for="identificador">Matrícula Oficial</label>
                                <div class="input-icon-wrap"><i class="fas fa-id-badge"></i><input type="text" name="identificador" id="identificador" value="<?php echo htmlspecialchars($usuario['identificador']); ?>" required></div>
                            </div>
                            <div class="field-group">
                                <label for="codigo_qr">Código QR de Acceso</label>
                                <div class="input-icon-wrap"><i class="fas fa-qrcode"></i><input type="text" name="codigo_qr" id="codigo_qr" value="<?php echo htmlspecialchars($usuario['codigo_qr']); ?>" required></div>
                            </div>
                        </div>
                        <div class="field-group">
                            <label for="correo_institucional">Correo institucional</label>
                            <div class="input-icon-wrap"><i class="fas fa-envelope-open-text"></i><input type="email" name="correo_institucional" id="correo_institucional" maxlength="150" value="<?php echo htmlspecialchars($usuario['correo_institucional'] ?? ''); ?>" placeholder="Sin asignar"></div>
                            <small class="field-hint">Para asignarlos a muchos alumnos a la vez usa <b>Directorio › Correos institucionales</b> (Excel o CSV).</small>
                        </div>
                        <div class="fields-grid-2">
                            <div class="field-group">
                                <label for="nombre_tutor">Padre, madre o tutor</label>
                                <div class="input-icon-wrap"><i class="fas fa-user-shield"></i><input type="text" name="nombre_tutor" id="nombre_tutor" maxlength="200" value="<?php echo htmlspecialchars($usuario['nombre_tutor'] ?? ''); ?>" placeholder="Nombre completo"></div>
                            </div>
                            <div class="field-group">
                                <label for="correo_tutor">Correo del tutor</label>
                                <div class="input-icon-wrap"><i class="fas fa-envelope"></i><input type="email" name="correo_tutor" id="correo_tutor" maxlength="150" value="<?php echo htmlspecialchars($usuario['correo_tutor'] ?? ''); ?>" placeholder="Para avisos de faltas y conducta"></div>
                            </div>
                        </div>
                        <div class="fields-grid-2">
                            <div class="field-group">
                                <label for="selectGrupoEditar">Grupo Académico</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-users-rectangle"></i>
                                    <select name="id_grupo" id="selectGrupoEditar" onchange="sincronizarTurnoGrupo()">
                                        <option value="0">-- Sin grupo asignado --</option>
                                        <?php foreach ($grupos as $g): ?>
                                            <option value="<?php echo $g['id_grupo']; ?>" data-turno="<?php echo htmlspecialchars($g['turno']); ?>" <?php if (($usuario['id_grupo'] ?? 0) == $g['id_grupo']) echo 'selected'; ?>><?php echo htmlspecialchars($g['semestre'] . '° "' . $g['nombre_grupo'] . '" · ' . $g['turno']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <small class="field-hint">Al elegir un grupo, su Clase de Turno se vincula automáticamente.</small>
                            </div>
                            <div class="field-group">
                                <label for="selectTurnoEditar">Clase de Turno</label>
                                <div class="input-icon-wrap">
                                    <i class="far fa-clock"></i>
                                    <select name="turno" id="selectTurnoEditar">
                                        <?php if (count($turnos_catalogo) > 0): ?>
                                            <?php foreach ($turnos_catalogo as $tc): ?>
                                                <option value="<?php echo htmlspecialchars($tc['turno']); ?>" <?php if ($usuario['turno'] == $tc['turno']) echo 'selected'; ?>><?php echo htmlspecialchars($tc['turno'] . ' (' . substr($tc['hora_entrada'], 0, 5) . ' hrs)'); ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="Matutino" <?php if($usuario['turno'] == 'Matutino') echo 'selected'; ?>>Matutino</option>
                                            <option value="Intermedio" <?php if($usuario['turno'] == 'Intermedio') echo 'selected'; ?>>Intermedio</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <small class="field-hint">Vinculado al catálogo oficial HORARIOS_TURNO.</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="field-group">
                            <label for="identificador">Nombre de Usuario (Login de Acceso)</label>
                            <div class="input-icon-wrap"><i class="fas fa-at"></i><input type="text" name="identificador" id="identificador" value="<?php echo htmlspecialchars($usuario['identificador']); ?>" required></div>
                            <small class="field-hint">Identificador único con el que inicia sesión en el sistema.</small>
                        </div>
                    <?php endif; ?>
                    <div class="form-actions-bar" id="contenedorBotonGuardar">
                        <button type="button" class="btn-save-main" onclick="mostrarBannerConfirmacion()"><i class="fas fa-floppy-disk"></i> Guardar Cambios</button>
                    </div>
                    <div class="confirm-banner" id="bannerConfirmacion">
                        <div class="confirm-banner-head"><i class="fas fa-circle-question"></i><span>¿Confirmas la actualización de este usuario?</span></div>
                        <p>Estás a punto de modificar los datos de <strong id="nombreConfirmacion"><?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido_paterno']); ?></strong>. Verifica que la información sea correcta antes de guardar.</p>
                        <div class="confirm-btn-group">
                            <button type="button" class="btn-confirm-no" onclick="ocultarBannerConfirmacion()">Cancelar y revisar</button>
                            <button type="submit" class="btn-confirm-yes"><i class="fas fa-check"></i> Sí, aplicar cambios</button>
                        </div>
                    </div>
                </form>
            </section>
            <aside class="side-panel">
                <div class="profile-preview-card">
                    <div class="preview-avatar" id="previewAvatar"><?php echo htmlspecialchars($iniciales); ?></div>
                    <h3 id="previewNombreCompleto"><?php echo htmlspecialchars(trim($usuario['nombre'] . ' ' . $usuario['apellido_paterno'] . ' ' . ($usuario['apellido_materno'] ?? ''))); ?></h3>
                    <div class="preview-sub" id="previewIdentificador"><?php echo ($tipo === 'alumnos' ? 'Matrícula: ' : 'Usuario: ') . htmlspecialchars($usuario['identificador']); ?></div>
                    <div class="preview-meta-list">
                        <div class="preview-meta-item"><span>Categoría</span><strong><?php echo htmlspecialchars($nombre_rol_visual); ?></strong></div>
                        <?php if ($tipo === 'alumnos'): ?>
                            <div class="preview-meta-item"><span>Clase de Turno</span><strong id="previewTurnoTexto"><?php echo htmlspecialchars($usuario['turno']); ?></strong></div>
                            <div class="preview-meta-item"><span>Conducta</span><strong><?php echo intval($usuario['puntos_conducta'] ?? 100); ?> / 100 pts</strong></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="security-card">
                    <div class="security-card-head">
                        <div class="security-icon"><i class="fas fa-key"></i></div>
                        <div><h3>Seguridad de Acceso</h3><small>Restablecimiento de clave</small></div>
                    </div>
                    <?php
                    // Estado de la contraseña del usuario y contraseña recién generada (se muestra una sola vez)
                    $cfg_cuenta = cuenta_config(cuenta_rol_desde_tipo($tipo) ?? '');
                    $estado_pwd = null;
                    if ($cfg_cuenta) {
                        $stp = $pdo->prepare("SELECT debe_cambiar_password, password_provisional_expira, (password_provisional_expira < NOW()) AS vencida FROM {$cfg_cuenta['tabla']} WHERE {$cfg_cuenta['id']} = ?");
                        $stp->execute([(int)$usuario['id']]);
                        $estado_pwd = $stp->fetch(PDO::FETCH_ASSOC);
                    }
                    $recien = $_SESSION['pwd_provisional_generada'] ?? null;
                    if ($recien && ((int)$recien['id'] !== (int)$usuario['id'] || $recien['tipo'] !== $tipo)) $recien = null;
                    if ($recien) unset($_SESSION['pwd_provisional_generada']);
                    ?>
                    <?php if ($recien): ?>
                        <div class="pwd-generada" id="pwdGenerada">
                            <div class="pwd-generada-head"><i class="fas fa-circle-check"></i> Contraseña provisional generada</div>
                            <p>Entrégala a <b><?php echo htmlspecialchars($recien['nombre']); ?></b>. Solo se muestra <b>esta vez</b>.</p>
                            <div class="pwd-caja">
                                <code id="pwdTexto"><?php echo htmlspecialchars($recien['password']); ?></code>
                                <button type="button" id="btnCopiarPwd" title="Copiar"><i class="far fa-copy"></i></button>
                            </div>
                            <ul>
                                <li><i class="fas fa-user"></i> Usuario: <b><?php echo htmlspecialchars($recien['usuario']); ?></b></li>
                                <li><i class="far fa-clock"></i> Vence: <b><?php echo htmlspecialchars($recien['expira']); ?></b></li>
                                <li><i class="fas fa-key"></i> Al entrar con ella deberá crear su propia contraseña; después la provisional deja de funcionar.</li>
                            </ul>
                            <button type="button" class="btn-imprimir-pwd" onclick="imprimirPwd()"><i class="fas fa-print"></i> Imprimir comprobante</button>
                        </div>
                    <?php elseif ($estado_pwd && (int)$estado_pwd['debe_cambiar_password'] === 1): ?>
                        <div class="pwd-estado <?php echo $estado_pwd['vencida'] ? 'vencida' : ''; ?>">
                            <i class="fas <?php echo $estado_pwd['vencida'] ? 'fa-hourglass-end' : 'fa-hourglass-half'; ?>"></i>
                            <?php if (!$estado_pwd['password_provisional_expira']): ?>
                                Tiene una contraseña temporal inicial pendiente de cambiar.
                            <?php elseif ($estado_pwd['vencida']): ?>
                                Su contraseña provisional <b>venció</b> el <?php echo date('d/m/Y h:i a', strtotime($estado_pwd['password_provisional_expira'])); ?>. Genera una nueva.
                            <?php else: ?>
                                Tiene una contraseña provisional sin usar; vence el <?php echo date('d/m/Y h:i a', strtotime($estado_pwd['password_provisional_expira'])); ?>.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <p class="security-desc">Si el usuario olvidó su contraseña, el sistema genera una <b>contraseña provisional nueva y única</b>. La anterior deja de funcionar de inmediato; la provisional vence en <?php echo PROVISIONAL_HORAS / 24; ?> días y solo sirve para entrar a crear una nueva.</p>
                    <form action="../procesos/restablecer_password.php" method="POST" onsubmit="return confirm('¿Generar una contraseña provisional? La contraseña actual del usuario dejará de funcionar.');">
                        <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($usuario['id']); ?>">
                        <input type="hidden" name="tipo_usuario" value="<?php echo htmlspecialchars($tipo); ?>">
                        <button type="submit" class="btn-reset-password"><i class="fas fa-wand-magic-sparkles"></i> Generar contraseña provisional</button>
                    </form>
                    <link rel="stylesheet" href="css/editar_usuario.css?v=1">
                    <?php if ($recien): ?>
                    <script>
                        document.getElementById('btnCopiarPwd').addEventListener('click', async function () {
                            try { await navigator.clipboard.writeText(document.getElementById('pwdTexto').textContent); this.innerHTML = '<i class="fas fa-check"></i>'; }
                            catch (e) { const r = document.createRange(); r.selectNode(document.getElementById('pwdTexto')); getSelection().removeAllRanges(); getSelection().addRange(r); }
                        });
                        function imprimirPwd() {
                            const w = window.open('', '_blank', 'width=480,height=600');
                            w.document.write('<html><head><meta charset="utf-8"><title>Contraseña provisional</title></head><body style="font-family:Arial,sans-serif;padding:24px">'
                                + '<h2 style="margin:0 0 6px">COBAEP Plantel 27 · SIGE</h2><p style="color:#555;margin:0 0 18px">Contraseña provisional</p>'
                                + '<p><b>Nombre:</b> <?php echo htmlspecialchars($recien['nombre'], ENT_QUOTES); ?></p>'
                                + '<p><b>Usuario:</b> <?php echo htmlspecialchars($recien['usuario'], ENT_QUOTES); ?></p>'
                                + '<p style="font-size:24px;font-weight:bold;letter-spacing:2px;border:2px dashed #333;padding:12px;text-align:center"><?php echo htmlspecialchars($recien['password'], ENT_QUOTES); ?></p>'
                                + '<p><b>Vence:</b> <?php echo htmlspecialchars($recien['expira'], ENT_QUOTES); ?></p>'
                                + '<p style="font-size:13px;color:#444">Al iniciar sesión el sistema te pedirá crear una contraseña nueva. Después de cambiarla, esta provisional dejará de funcionar. No la compartas.</p>'
                                + '</body></html>');
                            w.document.close(); w.focus(); w.print();
                        }
                    </script>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </main>
    <script>
        function sincronizarTurnoGrupo() {
            const selGrupo = document.getElementById('selectGrupoEditar'), selTurno = document.getElementById('selectTurnoEditar'), previewTurno = document.getElementById('previewTurnoTexto');
            if (selGrupo && selTurno) {
                const opt = selGrupo.options[selGrupo.selectedIndex], turnoGrupo = opt.getAttribute('data-turno');
                if (turnoGrupo) { selTurno.value = turnoGrupo; if (previewTurno) previewTurno.textContent = turnoGrupo; }
            }
        }
        function mostrarBannerConfirmacion() {
            const form = document.getElementById('formEditarUsuario');
            if (!form.checkValidity()) { form.reportValidity(); return; }
            document.getElementById('nombreConfirmacion').textContent = document.getElementById('nombre').value.trim() + ' ' + document.getElementById('apellido_p').value.trim();
            document.getElementById('contenedorBotonGuardar').style.display = 'none';
            document.getElementById('bannerConfirmacion').classList.add('active');
        }
        function ocultarBannerConfirmacion() {
            document.getElementById('bannerConfirmacion').classList.remove('active');
            document.getElementById('contenedorBotonGuardar').style.display = 'flex';
        }
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay'), mobileMenu = document.getElementById('mobileMenu'), collapseToggle = document.getElementById('collapseToggle'), body = document.body;
            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');
            if (collapseToggle) collapseToggle.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); });
            if (mobileMenu) mobileMenu.addEventListener('click', () => { if(sidebar) sidebar.classList.add('mobile-open'); if(overlay) overlay.classList.add('visible'); body.classList.add('menu-open'); });
            if (overlay) overlay.addEventListener('click', () => { if(sidebar) sidebar.classList.remove('mobile-open'); if(overlay) overlay.classList.remove('visible'); body.classList.remove('menu-open'); });
            
            const inputNom = document.getElementById('nombre'), inputApP = document.getElementById('apellido_p'), inputApM = document.getElementById('apellido_m'), inputId = document.getElementById('identificador'), selTurno = document.getElementById('selectTurnoEditar');
            function actualizarTarjetaViva() {
                const n = inputNom.value.trim(), p = inputApP.value.trim(), m = inputApM ? inputApM.value.trim() : '';
                document.getElementById('previewNombreCompleto').textContent = (n + ' ' + p + ' ' + m).trim() || 'Usuario';
                const ini = (n.charAt(0) + p.charAt(0)).toUpperCase();
                if (ini) document.getElementById('previewAvatar').textContent = ini;
                if (inputId) document.getElementById('previewIdentificador').textContent = '<?php echo $tipo === "alumnos" ? "Matrícula: " : "Usuario: "; ?>' + inputId.value.trim();
                if (selTurno && document.getElementById('previewTurnoTexto')) document.getElementById('previewTurnoTexto').textContent = selTurno.value;
            }
            [inputNom, inputApP, inputApM, inputId].forEach(el => { if (el) el.addEventListener('input', actualizarTarjetaViva); });
            if (selTurno) selTurno.addEventListener('change', actualizarTarjetaViva);
        });
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>