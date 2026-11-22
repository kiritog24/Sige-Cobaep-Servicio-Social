<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/campana.php';

// Validar que el usuario haya iniciado sesión y sea estudiante
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../login.php");
    exit();
}

$id_alumno = $_SESSION['id_usuario'];
date_default_timezone_set('America/Mexico_City');

try {
    // 1. Obtener datos principales del alumno
    $stmt = $pdo->prepare("SELECT * FROM ALUMNOS WHERE id_alumno = ?");
    $stmt->execute([$id_alumno]);
    $alumno = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alumno) {
        die("Error: Datos del alumno no encontrados.");
    }

    // 2. Obtener grupo actual del alumno
    $stmtGpo = $pdo->prepare("
        SELECT g.nombre_grupo, g.semestre, g.turno 
        FROM GRUPOS g 
        JOIN GRUPO_ALUMNO ga ON g.id_grupo = ga.id_grupo 
        WHERE ga.id_alumno = ? AND ga.fecha_baja IS NULL
        LIMIT 1
    ");
    $stmtGpo->execute([$id_alumno]);
    $grupo = $stmtGpo->fetch(PDO::FETCH_ASSOC);

    // 3. Obtener domicilio
    $stmtDom = $pdo->prepare("SELECT * FROM DOMICILIO_ALUMNO WHERE id_alumno = ?");
    $stmtDom->execute([$id_alumno]);
    $domicilio = $stmtDom->fetch(PDO::FETCH_ASSOC);

    // 3b. Tutor
    $stmtTut = $pdo->prepare("SELECT * FROM TUTOR_ALUMNO WHERE id_alumno = ?");
    $stmtTut->execute([$id_alumno]);
    $tutor = $stmtTut->fetch(PDO::FETCH_ASSOC) ?: [];
    $domicilio = $domicilio ?: [];

    // Formatear variables para la vista
    $nombre_pila = htmlspecialchars($alumno['nombre']);
    $apellido_p = htmlspecialchars($alumno['apellido_paterno']);
    $nombre_completo = $nombre_pila . ' ' . $apellido_p;
    $iniciales = mb_substr($nombre_pila, 0, 1) . mb_substr($apellido_p, 0, 1);
    
    // Generación y Carrera
    $anio_ingreso = !empty($alumno['periodo_ingreso']) ? substr($alumno['periodo_ingreso'], 0, 4) : date('Y');
    $anio_fin = $anio_ingreso + 3;
    $generacion = $anio_ingreso . ' - ' . $anio_fin;
    $carrera = !empty($alumno['capacitacion']) ? htmlspecialchars($alumno['capacitacion']) : 'Bachillerato General';
    
    // Matrícula, Folio y Status
    $matricula = htmlspecialchars($alumno['matricula']);
    $folio = "27-" . $matricula;
    $estatus = htmlspecialchars($alumno['estatus']);
    $turno = $grupo ? htmlspecialchars($grupo['turno']) : htmlspecialchars($alumno['turno']);
    $grupo_str = $grupo ? htmlspecialchars($grupo['semestre'] . ' "' . $grupo['nombre_grupo'] . '"') : '-- Sin grupo asignado --';
    
    // Datos Personales
    $fecha_nac = !empty($alumno['fecha_nacimiento']) ? date('d/m/Y', strtotime($alumno['fecha_nacimiento'])) : '--/--/----';
    $correo = !empty($alumno['correo_institucional']) ? htmlspecialchars($alumno['correo_institucional']) : (!empty($alumno['correo_personal']) ? htmlspecialchars($alumno['correo_personal']) : '-- Sin registro --');
    $telefono = !empty($alumno['telefono']) ? htmlspecialchars($alumno['telefono']) : '-- Sin registro --';
    
    $domicilio_str = '-- Sin registro --';
    $tutor_str = !empty($tutor['nombre_tutor']) ? htmlspecialchars($tutor['nombre_tutor'] . (!empty($tutor['telefono_1']) ? ' · ' . $tutor['telefono_1'] : '') . (!empty($tutor['correo_tutor']) ? ' · ' . $tutor['correo_tutor'] : '')) : '-- Sin registro --';
    if ($domicilio) {
        $calle = htmlspecialchars($domicilio['calle'] ?? '');
        $num = htmlspecialchars($domicilio['num_exterior'] ?? '');
        $colonia = htmlspecialchars($domicilio['colonia'] ?? '');
        $municipio = htmlspecialchars($domicilio['municipio'] ?? '');
        $domicilio_str = trim("$calle $num, $colonia, $municipio", ", ");
        if (empty($domicilio_str)) $domicilio_str = '-- Sin registro --';
    }

    // 4. LÓGICA DE FOTOGRAFÍA
    if (!empty($alumno['fotografia']) && file_exists("../" . $alumno['fotografia'])) {
        $avatar_url = "../" . htmlspecialchars($alumno['fotografia']) . '?v=' . filemtime("../" . $alumno['fotografia']);
    } else {
        $ini_svg = htmlspecialchars(mb_strtoupper(mb_substr($alumno['nombre'], 0, 1) . mb_substr($alumno['apellido_paterno'], 0, 1)), ENT_XML1);
        $avatar_url = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="#e2e8f0"/><text x="50%" y="54%" font-family="Arial, sans-serif" font-size="96" font-weight="700" fill="#0f172a" text-anchor="middle" dominant-baseline="middle">' . $ini_svg . '</text></svg>');
    }

} catch (PDOException $e) {
    die("Error al cargar datos del perfil: " . $e->getMessage());
}
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
    <title>Mi Perfil · Panel Estudiante</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- Librería para generar el QR -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    
    <!-- ÚNICO CSS EXTERNO: Base del sistema -->
    <link rel="stylesheet" href="style_dashboard_estud.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style_perfil.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/perfil.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>

    <aside class="sidebar" id="sidebar">
        <button id="collapseToggle" class="collapse-toggle" aria-label="Colapsar menú">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="sidebar-inner">
            <div class="brand">
                <img src="../img/LogoCobaep.png" alt="Logo COBAEP" class="brand-logo">
                <div class="brand-copy">
                    <strong>SIGE<span>-Cobaep</span></strong>
                    <small>Plantel 27</small>
                </div>
            </div>

            <nav class="navigation" aria-label="Navegación principal">
                <span class="nav-heading">Menú principal</span>
                <a href="dashboard_estud.php" class="nav-item"><i class="fas fa-home"></i> <span>Inicio</span></a>
                <a href="mis_asistencias.php" class="nav-item"><i class="far fa-calendar-check"></i> <span>Mis Asistencias</span></a>
                <a href="horario_clases.php" class="nav-item"><i class="far fa-calendar-alt"></i> <span>Horario de Clases</span></a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i> <span>Horarios del Plantel</span></a>
                
                <span class="nav-heading">Académico</span>
                <a href="actividades_escolares.php" class="nav-item"><i class="fas fa-book-open"></i> <span>Actividades Escolares</span></a>
                 <a href="actividades.php" class="nav-item"><i class="fas fa-star"></i> <span>Regularizaciones</span></a>
                 <a href="mis_maestros.php" class="nav-item"><i class="fas fa-chalkboard-user"></i> <span>Mis Maestros</span></a>

                <span class="nav-heading">Información</span>
                <a href="avisos.php" class="nav-item"><i class="far fa-bell"></i> <span>Avisos</span></a>
                <a href="documentos.php" class="nav-item"><i class="far fa-file-alt"></i> <span>Documentos</span></a>
                <a href="reglamentos.php" class="nav-item"><i class="far fa-clipboard"></i> <span>Reglamentos</span></a>
            </nav>

            <div class="sidebar-bottom">
                <div class="institution">
                    <div class="institution-icon"><i class="fas fa-building-columns"></i></div>
                    <div><strong>COBAEP Plantel 27</strong><span>Zaragoza, Puebla</span></div>
                </div>
                <a href="../logout.php" class="logout"><i class="fas fa-arrow-right-from-bracket"></i><span>Cerrar Sesión</span></a>
            </div>
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php include __DIR__ . '/../includes/menu_estado.php'; ?>

    <main class="main-content">
        <div class="dashboard-container">
            
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu" id="mobileMenu" type="button"><i class="fas fa-bars"></i></button>
                    <div>
                        <h1>Mi Perfil</h1>
                        <p>Consulta y administra tu información personal.</p>
                    </div>
                </div>

                <div class="topbar-right">
                    <div class="date-pill">
                        <i class="far fa-calendar"></i>
                        <span id="fechaTexto"><?php echo date('d/m/Y, h:i a'); ?></span>
                    </div>
                    
                    <?php estudiante_campana($pdo); ?>
                    <div class="profile-container">
                        <div class="profile-pill" id="profileBtn">
                            <img src="<?php echo $avatar_url; ?>" alt="Avatar" class="avatar-small">
                            <div class="profile-info">
                                <strong><?php echo $nombre_pila; ?></strong>
                                <span>Matrícula: <?php echo $matricula; ?></span>
                            </div>
                            <i class="fas fa-chevron-down" style="color: #94a3b8; margin-left: 5px; font-size: 0.8rem;"></i>
                        </div>
                        
                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="perfil.php" class="profile-dropdown-item"><i class="far fa-user-circle"></i> Mi Perfil</a>
                            <a href="cambiar_password.php" class="profile-dropdown-item"><i class="fas fa-key"></i> Cambiar contraseña</a>
                            <a href="../logout.php" class="profile-dropdown-item"><i class="fas fa-arrow-right-from-bracket"></i> Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if (isset($_GET['exito'])): ?>
                <div style="background: #ecfdf5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 5px; border: 1px solid #a7f3d0; font-size: 0.85rem; font-weight: 600;">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['exito']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div style="background: #fef2f2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 5px; border: 1px solid #fecaca; font-size: 0.85rem; font-weight: 600;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <div class="profile-grid">
                
                <div class="col-left">
                    <div class="card">
                        <button class="btn-change-photo" onclick="abrirModal('modalFoto')"><i class="fas fa-camera"></i> Cambiar foto</button>
                        
                        <div class="profile-main-info">
                            <div class="avatar-large-wrapper" onclick="abrirModal('modalFoto')" title="Actualizar foto">
                                <img src="<?php echo $avatar_url; ?>" alt="Avatar">
                                <button class="edit-avatar-btn"><i class="fas fa-pen"></i></button>
                            </div>
                            
                            <div class="profile-name-badges">
                                <h2><?php echo $nombre_completo; ?></h2>
                                <div class="badges-row">
                                    <span class="badge badge-alumno">Alumno</span>
                                    <span class="badge badge-activo"><?php echo strtoupper($estatus); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="info-grid-3x2">
                            <div class="info-item">
                                <div class="icon-box"><i class="far fa-id-card"></i></div>
                                <div class="info-data"><label>Matrícula</label><span><?php echo $matricula; ?></span></div>
                            </div>
                            <div class="info-item">
                                <div class="icon-box"><i class="fas fa-graduation-cap"></i></div>
                                <div class="info-data"><label>Generación</label><span><?php echo $generacion; ?></span></div>
                            </div>
                            <div class="info-item">
                                <div class="icon-box"><i class="fas fa-book"></i></div>
                                <div class="info-data"><label>Carrera</label><span><?php echo $carrera; ?></span></div>
                            </div>
                            <div class="info-item">
                                <div class="icon-box"><i class="fas fa-building"></i></div>
                                <div class="info-data"><label>Plantel</label><span>COBAEP Plantel 27</span></div>
                            </div>
                            <div class="info-item">
                                <div class="icon-box"><i class="far fa-clock"></i></div>
                                <div class="info-data"><label>Turno</label><span><?php echo $turno; ?></span></div>
                            </div>
                            <div class="info-item">
                                <div class="icon-box"><i class="fas fa-users"></i></div>
                                <div class="info-data"><label>Grupo</label><span><?php echo $grupo_str; ?></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="header-title"><i class="fas fa-user"></i> Información de Contacto</div>
                            <button class="btn-outline-small" onclick="abrirModal('modalContacto')"><i class="fas fa-pen"></i> Completar / Editar datos</button>
                        </div>

                        <div class="personal-grid">
                            <div class="info-item border-box">
                                <div class="icon-box"><i class="far fa-calendar-alt"></i></div>
                                <div class="info-data"><label>Fecha de Nacimiento</label><span><?php echo $fecha_nac; ?></span></div>
                            </div>
                            <div class="info-item border-box">
                                <div class="icon-box"><i class="far fa-envelope"></i></div>
                                <div class="info-data"><label>Correo Electrónico</label><span><?php echo $correo; ?></span></div>
                            </div>
                            <div class="info-item border-box">
                                <div class="icon-box"><i class="fas fa-phone-alt"></i></div>
                                <div class="info-data"><label>Teléfono</label><span><?php echo $telefono; ?></span></div>
                            </div>
                            <div class="info-item border-box">
                                <div class="icon-box"><i class="fas fa-home"></i></div>
                                <div class="info-data"><label>Domicilio</label><span><?php echo $domicilio_str; ?></span></div>
                            </div>
                            <div class="info-item border-box">
                                <div class="icon-box"><i class="fas fa-user-shield"></i></div>
                                <div class="info-data"><label>Padre, madre o tutor</label><span><?php echo $tutor_str; ?></span></div>
                            </div>
                            <div class="info-item border-box">
                                <div class="icon-box"><i class="fas fa-venus-mars"></i></div>
                                <div class="info-data"><label>Sexo</label><span><?php echo $alumno['sexo'] === 'F' ? 'Femenino' : ($alumno['sexo'] === 'M' ? 'Masculino' : '-- Sin registro --'); ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-right">
                    
                    <div class="card">
                        <div class="card-header borderless">
                            <div class="header-title"><i class="far fa-id-card"></i> Credencial Virtual</div>
                            <span class="badge-status-green"><i class="fas fa-check"></i> Vigente</span>
                        </div>

                        <div class="credencial-box" id="credencialImprimible">
                            <div class="cred-header">
                                <div class="logo-area">
                                    <img src="../img/logo-blanco.png" alt="COBAEP">
                                    <strong>COBAEP</strong>
                                </div>
                                <div class="cred-plantel">
                                    <strong>PLANTEL 27</strong>
                                    <span>Zaragoza, Puebla</span>
                                </div>
                            </div>
                            <div class="cred-body">
                                <div class="cred-photo-col">
                                    <img src="<?php echo $avatar_url; ?>" alt="Foto Alumno">
                                </div>
                                <div class="cred-data-col">
                                    <h2><?php echo $nombre_completo; ?></h2>
                                    <div class="status-row">
                                        <strong>Alumno</strong>
                                        <span class="badge-activo-small">ACTIVO</span>
                                    </div>
                                    <div class="info-list">
                                        <p><label>Matrícula:</label> <?php echo $matricula; ?></p>
                                        <p><label>Generación:</label> <?php echo $generacion; ?></p>
                                        <p><label>Carrera:</label> <strong><?php echo $carrera; ?></strong></p>
                                    </div>
                                </div>
                                <div class="cred-qr-col">
                                    <div id="credencial-qr-img" style="margin-bottom: 8px; display: flex; justify-content: center;"></div>
                                    <div class="folio-text"><label>Folio</label><span><?php echo $folio; ?></span></div>
                                </div>
                            </div>
                            <div class="cred-footer">
                                Vigencia: AGO <?php echo (int)$anio_ingreso; ?> - JUL <?php echo (int)$anio_fin; ?>
                            </div>
                        </div>

                        <div class="cred-actions">
                            <button class="btn-action-primary" type="button" onclick="credencialPDF(this, 'descargar')"><i class="fas fa-file-pdf"></i> Descargar Credencial (PDF)</button>
                            <button class="btn-action-primary" type="button" onclick="credencialPDF(this, 'imprimir')" style="margin-top:8px;"><i class="fas fa-print"></i> Imprimir Credencial</button>
                            <button class="btn-action-primary" type="button" onclick="descargarCredencial(this)" style="margin-top:8px;"><i class="far fa-image"></i> Descargar como imagen (PNG)</button>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header borderless">
                            <div class="header-title"><i class="fas fa-bolt"></i> Acciones Rápidas</div>
                        </div>

                        <div class="actions-grid">
                            <a href="javascript:void(0)" onclick="abrirModal('modalFoto')" class="action-btn">
                                <div class="icon bg-green-light"><i class="fas fa-camera"></i></div>
                                <strong>Actualizar Foto</strong>
                            </a>
                            <a href="horario_clases.php" class="action-btn">
                                <div class="icon bg-blue-light"><i class="far fa-calendar-alt"></i></div>
                                <strong>Ver Horario</strong>
                            </a>
                            <a href="mis_asistencias.php" class="action-btn">
                                <div class="icon bg-orange-light"><i class="far fa-check-circle"></i></div>
                                <strong>Asistencias</strong>
                            </a>
                            <a href="documentos.php" class="action-btn">
                                <div class="icon bg-purple-light"><i class="far fa-file-alt"></i></div>
                                <strong>Documentos</strong>
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Cambiar Foto -->
    <div class="modal-overlay" id="modalFoto">
        <div class="modal-box">
            <div class="modal-header">
                <h3><i class="fas fa-camera"></i> Actualizar Fotografía</h3>
                <button class="btn-close-modal" type="button" onclick="cerrarModal('modalFoto')"><i class="fas fa-times"></i></button>
            </div>
            <form action="../procesos/actualizar_foto_perfil.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 15px;">Selecciona una foto clara de tu rostro. Se actualizará en tu credencial virtual y en todo tu portal. <strong>Máximo 2 MB · mínimo 400 × 400 px</strong> (se recorta en cuadrado y se ajusta a 1080 × 1080 px).</p>
                    <label class="file-upload-wrapper">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span id="file-name">Haz clic para buscar tu foto (JPG, PNG, WEBP · máx. 2 MB)</span>
                        <input type="file" name="foto_perfil" id="foto_perfil" accept="image/jpeg, image/png, image/webp" required>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalFoto')">Cancelar</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Subir Foto</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Datos personales y de contacto -->
    <div class="modal-overlay" id="modalContacto">
        <div class="modal-box" style="max-width: 760px; max-height: 92vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <h3><i class="fas fa-address-book"></i> Mis datos personales y de contacto</h3>
                <button class="btn-close-modal" type="button" onclick="cerrarModal('modalContacto')"><i class="fas fa-times"></i></button>
            </div>
            <form action="../procesos/actualizar_contacto.php" method="POST" style="display:flex; flex-direction:column; min-height:0;">
                <div class="modal-body" style="overflow-y:auto;">
                    <h4 class="form-section"><i class="fas fa-user"></i> Datos personales</h4>
                    <div class="form-grid">
                        <div class="form-group"><label>Nombre(s)</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($alumno['nombre'] ?? ''); ?>" disabled></div>
                        <div class="form-group"><label>Apellido paterno</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($alumno['apellido_paterno'] ?? ''); ?>" disabled></div>
                        <div class="form-group"><label>Apellido materno</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($alumno['apellido_materno'] ?? ''); ?>" disabled></div>
                        <div class="form-group"><label>Matrícula</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($alumno['matricula'] ?? ''); ?>" disabled></div>
                        <div class="form-group"><label>Fecha de nacimiento *</label><input type="date" name="fecha_nacimiento" class="form-control" value="<?php echo htmlspecialchars($alumno['fecha_nacimiento'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
                        <div class="form-group"><label>Sexo *</label>
                            <select name="sexo" class="form-control" required>
                                <option value="">Selecciona…</option>
                                <option value="F" <?php echo ($alumno['sexo'] ?? '') === 'F' ? 'selected' : ''; ?>>Femenino</option>
                                <option value="M" <?php echo ($alumno['sexo'] ?? '') === 'M' ? 'selected' : ''; ?>>Masculino</option>
                            </select>
                        </div>
                    </div>

                    <h4 class="form-section"><i class="fas fa-phone"></i> Contacto</h4>
                    <div class="form-grid">
                        <div class="form-group"><label>Teléfono celular *</label><input type="tel" name="telefono" class="form-control" value="<?php echo htmlspecialchars($alumno['telefono'] ?? ''); ?>" placeholder="10 dígitos" pattern="[0-9]{10}" maxlength="10" required></div>
                        <div class="form-group"><label>Correo personal *</label><input type="email" name="correo_personal" class="form-control" value="<?php echo htmlspecialchars($alumno['correo_personal'] ?? ''); ?>" placeholder="ejemplo@correo.com" required></div>
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Correo institucional</label><input type="email" class="form-control" value="<?php echo htmlspecialchars($alumno['correo_institucional'] ?? ''); ?>" placeholder="Lo asigna control escolar" disabled></div>
                    </div>

                    <h4 class="form-section"><i class="fas fa-home"></i> Domicilio</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Calle *</label><input type="text" name="calle" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($domicilio['calle'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Núm. exterior *</label><input type="text" name="num_exterior" class="form-control" maxlength="20" value="<?php echo htmlspecialchars($domicilio['num_exterior'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Núm. interior</label><input type="text" name="num_interior" class="form-control" maxlength="20" value="<?php echo htmlspecialchars($domicilio['num_interior'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Colonia *</label><input type="text" name="colonia" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($domicilio['colonia'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Municipio *</label><input type="text" name="municipio" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($domicilio['municipio'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Código postal *</label><input type="text" name="codigo_postal" class="form-control" pattern="[0-9]{5}" maxlength="5" value="<?php echo htmlspecialchars($domicilio['codigo_postal'] ?? ''); ?>" required></div>
                    </div>

                    <h4 class="form-section"><i class="fas fa-user-shield"></i> Padre, madre o tutor</h4>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Nombre completo del tutor *</label><input type="text" name="nombre_tutor" class="form-control" maxlength="200" value="<?php echo htmlspecialchars($tutor['nombre_tutor'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Teléfono del tutor *</label><input type="tel" name="telefono_tutor_1" class="form-control" pattern="[0-9]{10}" maxlength="10" value="<?php echo htmlspecialchars($tutor['telefono_1'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Teléfono alterno</label><input type="tel" name="telefono_tutor_2" class="form-control" pattern="[0-9]{10}" maxlength="10" value="<?php echo htmlspecialchars($tutor['telefono_2'] ?? ''); ?>"></div>
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Correo electrónico del tutor *</label><input type="email" name="correo_tutor" class="form-control" maxlength="150" placeholder="correo@ejemplo.com" value="<?php echo htmlspecialchars($tutor['correo_tutor'] ?? ''); ?>" required>
                            <small style="display:block; font-size:.75rem; color:#64748b; margin-top:4px;"><i class="fas fa-envelope"></i> La escuela le enviará avisos de faltas y reportes de conducta a este correo.</small></div>
                    </div>
                    <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 10px;"><i class="fas fa-info-circle"></i> Para corregir tu nombre, matrícula o correo institucional acude a control escolar. Los campos con * son obligatorios.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalContacto')">Cancelar</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Guardar mis datos</button>
                </div>
            </form>
        </div>
    </div>

    <link rel="stylesheet" href="css/perfil_formulario.css?v=1">

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mobileMenu = document.getElementById('mobileMenu');
            const collapseToggle = document.getElementById('collapseToggle');
            const body = document.body;

            // Sidebar PC
            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');

            if (collapseToggle) {
                collapseToggle.addEventListener('click', () => {
                    body.classList.toggle('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
                });
            }

            // Sidebar Móvil
            window.addEventListener('resize', () => {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('visible');
                    body.classList.remove('menu-open');
                }
            });

            if (mobileMenu) {
                mobileMenu.addEventListener('click', () => {
                    sidebar.classList.add('mobile-open');
                    overlay.classList.add('visible');
                    body.classList.add('menu-open');
                });
            }
            if (overlay) {
                overlay.addEventListener('click', () => {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('visible');
                    body.classList.remove('menu-open');
                });
            }

            // Dropdown Perfil
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');

            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('active');
                });
                document.addEventListener('click', (e) => {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.remove('active');
                    }
                });
            }

            // Reloj
            const fechaTexto = document.getElementById('fechaTexto');
            function actualizarHora() {
                if (!fechaTexto) return;
                const now = new Date();
                fechaTexto.textContent = now.toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            }
            actualizarHora(); setInterval(actualizarHora, 60000);

            // Código QR
            const qrData = "<?php echo htmlspecialchars($alumno['codigo_qr'] ?? ''); ?>";
            if (qrData && qrData !== '') {
                new QRCode(document.getElementById("credencial-qr-img"), {
                    text: qrData, width: 90, height: 90, colorDark : "#000000", colorLight : "#ffffff", correctLevel : QRCode.CorrectLevel.H
                });
            }

            // Input File Dinámico (límite 10 MB)
            document.getElementById('foto_perfil').addEventListener('change', function(e) {
                if (this.files && this.files.length > 0 && this.files[0].size > 2 * 1024 * 1024) {
                    alert('La foto pesa ' + (this.files[0].size / 1048576).toFixed(1) + ' MB. El máximo permitido es 2 MB.');
                    this.value = '';
                    document.getElementById('file-name').textContent = 'Haz clic para buscar tu foto (JPG, PNG, WEBP · máx. 2 MB)';
                    return;
                }
                if (this.files && this.files.length > 0) {
                    document.getElementById('file-name').innerHTML = `<strong style="color:#059669;">${this.files[0].name}</strong>`;
                }
            });
        });

        // Captura SOLO la credencial (una copia con ancho fijo, igual en PC y en teléfono)
        async function capturarCredencial() {
            const original = document.getElementById('credencialImprimible');
            const caja = document.createElement('div');
            caja.style.cssText = 'position:fixed; left:-10000px; top:0; width:462px; background:#fff; padding:0; z-index:-1;';
            const copia = original.cloneNode(true);
            copia.removeAttribute('id');
            copia.style.margin = '0';
            caja.appendChild(copia);
            document.body.appendChild(caja);
            // El QR es un <canvas>: se copia su contenido a la copia
            const qrO = original.querySelector('#credencial-qr-img canvas'), qrC = copia.querySelector('canvas');
            if (qrO && qrC) { qrC.getContext('2d').drawImage(qrO, 0, 0); }
            try {
                return await html2canvas(copia, { scale: 3, useCORS: true, backgroundColor: '#ffffff', logging: false });
            } finally { caja.remove(); }
        }

        const NOMBRE_CREDENCIAL = 'Credencial_<?php echo preg_replace('/[^A-Za-z0-9_-]/', '_', $alumno['matricula']); ?>';

        // PDF real de la credencial, generado en el servidor con Dompdf (vendor).
        // La página solo aporta el código QR en alta resolución; los demás datos los toma el servidor.
        function qrAltaResolucion() {
            return new Promise(resolve => {
                const texto = <?php echo json_encode($alumno['codigo_qr'] ?? ''); ?>;
                if (!texto || typeof QRCode === 'undefined') return resolve('');
                const temp = document.createElement('div');
                temp.style.cssText = 'position:fixed; left:-10000px; top:0;';
                document.body.appendChild(temp);
                new QRCode(temp, { text: texto, width: 600, height: 600, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
                setTimeout(() => {
                    const c = temp.querySelector('canvas');
                    const url = c ? c.toDataURL('image/png') : '';
                    temp.remove();
                    resolve(url);
                }, 60);
            });
        }

        async function credencialPDF(btn, modo) {
            const txt = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando PDF…';
            try {
                const qr = await qrAltaResolucion();
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'procesos/credencial_pdf.php';
                form.target = modo === 'imprimir' ? '_blank' : '_self';
                [['qr', qr], ['modo', modo === 'imprimir' ? 'ver' : 'descargar']].forEach(([n, v]) => {
                    const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i);
                });
                document.body.appendChild(form);
                form.submit();
                form.remove();
            } finally {
                setTimeout(() => { btn.disabled = false; btn.innerHTML = txt; }, 1200);
            }
        }

        // Descarga la credencial como imagen PNG
        function descargarCredencial(btn) {
            if (typeof html2canvas === 'undefined') { alert('No se pudo cargar el generador. Revisa tu conexión a internet.'); return; }
            const txt = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando…';
            capturarCredencial().then(canvas => {
                const a = document.createElement('a');
                a.download = NOMBRE_CREDENCIAL + '.png';
                a.href = canvas.toDataURL('image/png');
                a.click();
            }).catch(() => alert('No se pudo generar la imagen. Intenta con "Descargar Credencial (PDF)".'))
              .finally(() => { btn.disabled = false; btn.innerHTML = txt; });
        }

        // Funciones Modal
        function abrirModal(id) {
            document.getElementById(id).classList.add('active');
        }
        // Desde el aviso "Registra el correo de tu tutor": abre el formulario directamente en ese campo
        if (new URLSearchParams(location.search).get('completar') === 'tutor') {
            window.addEventListener('load', () => {
                abrirModal('modalContacto');
                const c = document.querySelector('#modalContacto [name="correo_tutor"]');
                if (c) { setTimeout(() => { c.scrollIntoView({ block: 'center', behavior: 'smooth' }); c.focus(); }, 250); }
            });
        }
        function cerrarModal(id) {
            document.getElementById(id).classList.remove('active');
        }
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>