<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/credenciales_lote.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

try {
    $stmtG = $pdo->query("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS ORDER BY semestre, nombre_grupo ASC");
    $grupos = $stmtG->fetchAll();
} catch (PDOException $e) {
    die("Error al cargar los grupos: " . $e->getMessage());
}

$alta = credenciales_lote('credenciales_alta');   // cuenta recién creada (contraseña generada por el sistema)
$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Administrador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Registrar Usuario · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link href="style_dashboard_admin.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="style_sidebar.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="style_agregar_usuario.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>

    <!-- BARRA LATERAL CENTRALIZADA -->
    <?php include 'sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content layout-formulario">
        <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú" style="margin-bottom: 20px;">
            <i class="fas fa-bars"></i>
        </button>

        <div class="page-header-pills">
            <div class="header-left-title">
                <div class="icon-bg-light"><i class="fas fa-user-plus"></i></div>
                <div>
                    <h1>Registrar Nuevo Usuario</h1>
                    <p>Ingresa los datos del estudiante, docente, prefectos o administrativo</p>
                </div>
            </div>
            
            <?php topbar_usuario($pdo); ?>
        </div>

        <?php if ($alta): $cu = $alta['alumnos'][0]; ?>
            <div class="alta-cred" id="altaCred">
                <div class="alta-cred-head">
                    <div class="alta-cred-ico"><i class="fas fa-user-check"></i></div>
                    <div>
                        <h3><?php echo isset($_GET['exito']) ? '¡Usuario registrado correctamente!' : 'Última cuenta registrada'; ?></h3>
                        <p><?php echo htmlspecialchars($cu['nombre']); ?> · <?php echo htmlspecialchars($cu['grupo']); ?></p>
                    </div>
                </div>
                <div class="alta-cred-datos">
                    <div><span>Usuario</span><b id="altaUsuario"><?php echo htmlspecialchars($cu['matricula']); ?></b></div>
                    <div class="pwd"><span>Contraseña provisional</span><b id="altaPwd"><?php echo htmlspecialchars($cu['password']); ?></b></div>
                </div>
                <p class="alta-cred-nota"><i class="fas fa-shield-halved"></i> Generada por el sistema. Vence el <b><?php echo htmlspecialchars($alta['expira']); ?></b> y en su primer acceso deberá crear su propia contraseña. Entrégala ahora: por seguridad no se vuelve a mostrar.</p>
                <div class="alta-cred-acciones">
                    <button type="button" class="alta-btn" onclick="copiarAcceso(this)"><i class="fas fa-copy"></i> Copiar datos</button>
                    <a class="alta-btn" href="../procesos/descargar_credenciales.php?lote=alta&amp;formato=fichas"><i class="fas fa-file-pdf"></i> Ficha para imprimir (PDF)</a>
                    <form action="../procesos/credenciales_alumnos.php" method="POST" style="margin:0">
                        <input type="hidden" name="accion" value="descartar_alta">
                        <button type="submit" class="alta-btn sec"><i class="fas fa-check"></i> Ya la entregué</button>
                    </form>
                </div>
            </div>
        <?php elseif (isset($_GET['exito'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> ¡Usuario guardado y asignado correctamente!
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div class="form-wrapper">
            <form action="../procesos/guardar_usuario_v2.php" method="POST" id="formRegistro">
                
                <div class="form-group highlight-select">
                    <label for="tipo_usuario"><i class="fas fa-user-tag"></i> Tipo de Usuario</label>
                    <select name="tipo_usuario" id="tipo_usuario" required onchange="toggleCampos()">
                        <option value="alumno">🎓 Alumno (Estudiante)</option>
                        <option value="maestro">👨‍🏫 Maestro (Docente)</option>
                        <option value="admin">👤 Personal Administrativo</option>
                        <option value="prefect">🎖 Prefecto</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="nombre"><i class="fas fa-user"></i> Nombre(s)</label>
                    <input type="text" name="nombre" id="nombre" placeholder="Ej. María" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="apellido_p"><i class="fas fa-user"></i> Apellido Paterno</label>
                        <input type="text" name="apellido_p" id="apellido_p" placeholder="Ej. López" required>
                    </div>
                    <div class="form-group">
                        <label for="apellido_m"><i class="fas fa-user"></i> Apellido Materno</label>
                        <input type="text" name="apellido_m" id="apellido_m" placeholder="Ej. García (opcional)">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="telefono"><i class="fas fa-phone"></i> Teléfono</label>
                        <input type="tel" name="telefono" id="telefono" placeholder="Ej. 2221234567 (opcional)">
                    </div>
                    <div class="form-group">
                        <label for="correo"><i class="fas fa-envelope"></i> Correo Electrónico</label>
                        <input type="email" name="correo" id="correo" placeholder="ejemplo@cobaep.edu.mx (opcional)">
                    </div>
                </div>

                <!-- SECCIÓN ALUMNO -->
                <div id="campos_alumno">
                    <div class="form-group" style="margin-top: 15px;">
                        <label for="id_grupo"><i class="fas fa-users"></i> Asignar a Grupo</label>
                        <select name="id_grupo" id="id_grupo">
                            <option value="">-- Sin grupo asignado (Pendiente) --</option>
                            <?php foreach($grupos as $g): ?>
                                <option value="<?php echo $g['id_grupo']; ?>">
                                    <?php echo htmlspecialchars($g['semestre'] . ' "' . $g['nombre_grupo'] . '" - Turno ' . $g['turno']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Puedes asignar el grupo ahora o dejarlo pendiente.</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="matricula"><i class="fas fa-id-card"></i> Matrícula</label>
                            <input type="text" name="matricula" id="matricula" placeholder="Ej. 24B0012">
                            <div class="help-text">Obligatorio para alumnos. Debe ser única.</div>
                        </div>
                        <div class="form-group">
                            <label for="codigo_qr"><i class="fas fa-qrcode"></i> Código QR</label>
                            <input type="text" name="codigo_qr" id="codigo_qr" placeholder="Ej. QR-24B0012">
                            <div class="help-text">Opcional. Si lo dejas vacío se genera con la matrícula.</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="fecha_nacimiento"><i class="far fa-calendar"></i> Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" id="fecha_nacimiento">
                        </div>
                        <div class="form-group">
                            <label for="sexo"><i class="fas fa-venus-mars"></i> Sexo</label>
                            <select name="sexo" id="sexo">
                                <option value="">Selecciona...</option>
                                <option value="M">Masculino</option>
                                <option value="F">Femenino</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="turno"><i class="far fa-clock"></i> Turno</label>
                        <select name="turno" id="turno">
                            <option value="Matutino">Matutino</option>
                            <option value="Intermedio">Intermedio</option>
                        </select>
                    </div>
                </div>

                <!-- SECCIÓN STAFF -->
                <div id="campos_staff" style="display: none; margin-top: 15px;">
                    <div class="form-group">
                        <label for="usuario"><i class="far fa-user-circle"></i> Nombre de Usuario (Login)</label>
                        <input type="text" name="usuario" id="usuario" placeholder="Ej. DOC025">
                        <div class="help-text">Nombre único para iniciar sesión (ej. DOC001, ADM002, PREF001).</div>
                    </div>
                </div>

                <!-- CONTRASEÑA -->
                <div class="alta-info-pwd">
                    <i class="fas fa-key"></i>
                    <div><b>Contraseña provisional automática.</b> Al guardar, el sistema genera una contraseña única para este usuario (vence en <?php echo IMPORTACION_DIAS; ?> días) y te la muestra para que se la entregues. En su primer acceso deberá crear la suya.</div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Guardar Usuario
                </button>
                <div class="form-footer">
                    <a href="dashboard_admin.php" class="btn-cancel">
                        <i class="fas fa-arrow-left"></i> Cancelar y Volver
                    </a>
                </div>
            </form>
        </div>
    </main>

    <style>
        .alta-cred { background:#fff; border:1px solid #a7f3d0; border-radius:16px; padding:20px; margin-bottom:22px; box-shadow:0 6px 18px rgba(22,160,93,.08); }
        .alta-cred-head { display:flex; gap:14px; align-items:center; }
        .alta-cred-ico { width:46px; height:46px; border-radius:12px; background:#dcfce7; color:#15803d; display:grid; place-items:center; font-size:1.2rem; flex-shrink:0; }
        .alta-cred h3 { margin:0; font-size:1.05rem; color:#14532d; }
        .alta-cred-head p { margin:3px 0 0; font-size:.86rem; color:#475569; }
        .alta-cred-datos { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:16px 0 12px; }
        .alta-cred-datos div { background:#f1f5f9; border-radius:12px; padding:10px 14px; }
        .alta-cred-datos .pwd { background:#ecfdf5; }
        .alta-cred-datos span { display:block; font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .alta-cred-datos b { font-size:1.15rem; color:#0f172a; font-family:Consolas, 'Courier New', monospace; letter-spacing:.04em; word-break:break-all; }
        .alta-cred-nota { font-size:.84rem; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:10px 12px; margin:0 0 14px; line-height:1.5; }
        .alta-cred-acciones { display:flex; gap:10px; flex-wrap:wrap; }
        .alta-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:10px; background:#16a05d; color:#fff; text-decoration:none; font-weight:600; font-size:.88rem; border:none; cursor:pointer; font-family:inherit; }
        .alta-btn:hover { background:#12804b; }
        .alta-btn.sec { background:#fff; color:#334155; border:1px solid #cbd5e1; }
        .alta-info-pwd { display:flex; gap:12px; align-items:flex-start; margin-top:15px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:12px 14px; font-size:.86rem; color:#166534; line-height:1.5; }
        .alta-info-pwd i { color:#16a05d; margin-top:3px; }
        @media (max-width:600px) { .alta-cred-datos { grid-template-columns:1fr; } .alta-btn { width:100%; justify-content:center; } .alta-cred-acciones form { width:100%; } }
    </style>
    <!-- SCRIPTS -->
    <script>
        function copiarAcceso(btn) {
            const u = document.getElementById('altaUsuario').textContent, p = document.getElementById('altaPwd').textContent;
            const texto = 'SIGE-COBAEP Plantel 27\nUsuario: ' + u + '\nContraseña provisional: ' + p + '\nAl entrar, el sistema te pedirá crear tu propia contraseña.';
            const ok = () => { btn.innerHTML = '<i class="fas fa-check"></i> Copiado'; setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i> Copiar datos', 2000); };
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(ok);
            else { const t = document.createElement('textarea'); t.value = texto; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); ok(); }
        }
        function toggleCampos() {
            const tipo = document.getElementById("tipo_usuario").value;
            const camposAlumno = document.getElementById("campos_alumno");
            const camposStaff = document.getElementById("campos_staff");
            
            if (tipo === "alumno") {
                camposAlumno.style.display = "block";
                camposStaff.style.display = "none";
            } else {
                camposAlumno.style.display = "none";
                camposStaff.style.display = "block";
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleCampos();

            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mobileMenu = document.getElementById('mobileMenu');
            const collapseToggle = document.getElementById('collapseToggle');
            const body = document.body;
            const fechaTexto = document.getElementById('fechaTexto');

            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                body.classList.add('sidebar-collapsed');
            }

            if (collapseToggle) {
                collapseToggle.addEventListener('click', () => {
                    body.classList.toggle('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
                });
            }

            function abrirMenu() {
                sidebar.classList.add('mobile-open');
                if(overlay) overlay.classList.add('visible');
                body.classList.add('menu-open');
            }

            function cerrarMenu() {
                sidebar.classList.remove('mobile-open');
                if(overlay) overlay.classList.remove('visible');
                body.classList.remove('menu-open');
            }

            if(mobileMenu) mobileMenu.addEventListener('click', abrirMenu);
            if(overlay) overlay.addEventListener('click', cerrarMenu);

            window.addEventListener('resize', () => {
                if (window.innerWidth > 900) cerrarMenu();
            });

            function actualizarHora() {
                if (!fechaTexto) return;
                const now = new Date();
                fechaTexto.textContent = now.toLocaleString('es-MX', {
                    day: '2-digit', month: '2-digit', year: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
            }
            actualizarHora();
            setInterval(actualizarHora, 60000);
        });
    </script>
    <script src="../js/responsive.js?v=8"></script>
</body>
</html>