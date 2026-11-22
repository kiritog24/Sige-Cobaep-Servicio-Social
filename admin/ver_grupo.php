<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo' || !isset($_GET['id'])) {
    header("Location: gestion_grupos.php");
    exit();
}
$id_grupo = (int)$_GET['id'];
try {
    $stmtG = $pdo->prepare("SELECT semestre, nombre_grupo, turno FROM GRUPOS WHERE id_grupo = ?");
    $stmtG->execute([$id_grupo]);
    $grupo = $stmtG->fetch();
    if (!$grupo) die("El grupo no existe.");
    $sql = "SELECT a.id_alumno, a.matricula, a.fotografia, CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) AS nombre_completo, a.estatus, a.periodo_ingreso FROM ALUMNOS a INNER JOIN GRUPO_ALUMNO ga ON a.id_alumno = ga.id_alumno WHERE ga.id_grupo = ? AND ga.fecha_baja IS NULL ORDER BY a.apellido_paterno ASC";
    $stmtA = $pdo->prepare($sql);
    $stmtA->execute([$id_grupo]);
    $alumnos = $stmtA->fetchAll();
    $sqlDisponibles = "SELECT a.id_alumno, a.matricula, CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) AS nombre_completo, a.periodo_ingreso, (SELECT CONCAT(g2.semestre, '° ', g2.nombre_grupo, ' · ', g2.turno) FROM GRUPO_ALUMNO ga2 INNER JOIN GRUPOS g2 ON g2.id_grupo = ga2.id_grupo WHERE ga2.id_alumno = a.id_alumno AND ga2.fecha_baja IS NULL LIMIT 1) AS grupo_actual FROM ALUMNOS a WHERE a.estatus = 'Alta' AND NOT EXISTS (SELECT 1 FROM GRUPO_ALUMNO ga WHERE ga.id_alumno = a.id_alumno AND ga.id_grupo = ? AND ga.fecha_baja IS NULL) ORDER BY a.apellido_paterno ASC";
    $stmtDisp = $pdo->prepare($sqlDisponibles);
    $stmtDisp->execute([$id_grupo]);
    $alumnos_disponibles = $stmtDisp->fetchAll();
    $maestros = $pdo->query("SELECT id_maestro, nombre, apellido_paterno, apellido_materno FROM MAESTROS ORDER BY nombre ASC")->fetchAll();
    $stmtAsig = $pdo->prepare("SELECT m.id_maestro, m.foto_perfil, CONCAT_WS(' ', m.nombre, m.apellido_paterno, m.apellido_materno) AS nombre_completo, gm.fecha_asignacion,
                                      (SELECT GROUP_CONCAT(DISTINCT mt.nombre_materia SEPARATOR ', ') FROM CLASES_REGULARES cr INNER JOIN MATERIAS mt ON mt.id_materia = cr.id_materia WHERE cr.id_grupo = gm.id_grupo AND cr.id_maestro = m.id_maestro AND cr.estatus = 'Activa') AS materias
                               FROM GRUPO_MAESTRO gm INNER JOIN MAESTROS m ON m.id_maestro = gm.id_maestro
                               WHERE gm.id_grupo = ? ORDER BY m.nombre ASC");
    $stmtAsig->execute([$id_grupo]);
    $docentes_asignados = $stmtAsig->fetchAll();
    $ids_asignados = array_map('intval', array_column($docentes_asignados, 'id_maestro'));
} catch (PDOException $e) { die("Error de BD: " . $e->getMessage()); }
$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Administrador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de Grupo · SIGE COBAEP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_admin.css">
    <link rel="stylesheet" href="style-ver_grupo.css">
    <link rel="stylesheet" href="style_sidebar.css">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content layout-detalle" id="contenedor-principal">
        <header class="top-header">
            <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú" style="margin-right: 15px; display: none;"><i class="fas fa-bars"></i></button>
            <div class="page-title-container">
                <div class="icon-box"><i class="fas fa-users"></i></div>
                <div><h1>Detalle de Grupo</h1><div class="subtitle-date" id="fechaHora"><i class="far fa-calendar-alt"></i> <span id="fechaTexto"><?php echo date('d/m/Y, h:i a'); ?></span></div></div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>
        <div class="group-info-card">
            <div class="group-details">
                <div class="group-icon"><i class="fas fa-users-viewfinder"></i></div>
                <div><h2><?php echo htmlspecialchars($grupo['semestre']); ?>° Grupo "<?php echo htmlspecialchars($grupo['nombre_grupo']); ?>"</h2><p>Turno <?php echo htmlspecialchars($grupo['turno']); ?> <span class="divider">|</span> Total inscritos: <?php echo count($alumnos); ?></p></div>
            </div>
            <div class="group-actions">
                <a href="gestion_grupos.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Volver</a>
                <button type="button" class="btn btn-primary" id="btnAbrirModalExcel" style="background-color: #0d9488;"><i class="fas fa-file-import"></i> Cargar Excel</button>
                <button type="button" class="btn btn-primary" id="btnAbrirModal"><i class="fas fa-user-plus"></i> Inscribir Alumnos</button>
                <button type="button" class="btn btn-primary" id="btnAbrirModalDocentes" style="background-color: #0d9488; border: none;"><i class="fas fa-chalkboard-user"></i> Asignar Profesores</button>
            </div>
        </div>
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'inscritos_multiples'): ?>
            <div class="alert alert-success" style="background:#ecfdf5; color:#065f46; padding:15px; border-radius:12px; margin-bottom:20px; border:1px solid #a7f3d0;"><i class="fas fa-check-circle"></i> Se inscribieron <?php echo (int)($_GET['cantidad'] ?? 0); ?> alumno(s)<?php echo (int)($_GET['cambiados'] ?? 0) > 0 ? ' (' . (int)$_GET['cambiados'] . ' cambiaron desde otro grupo)' : ''; ?>.</div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'error_inscribir'): ?>
            <div class="alert" style="background:#fef2f2; color:#991b1b; padding:15px; border-radius:12px; margin-bottom:20px; border:1px solid #fecaca;"><i class="fas fa-exclamation-circle"></i> No se pudieron inscribir los alumnos.</div>
        <?php endif; ?>
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'docentes_asignados'): ?>
            <div class="alert alert-success" style="background:#f0fdf4; color:#166534; padding:15px; border-radius:12px; margin-bottom:20px; border:1px solid #bbf7d0;"><i class="fas fa-check-circle"></i> Asignación guardada: <?php echo (int)($_GET['nuevos'] ?? 0); ?> profesor(es) agregado(s), <?php echo (int)($_GET['quitados'] ?? 0); ?> retirado(s).</div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'docente_quitado'): ?>
            <div class="alert alert-success" style="background:#fff7ed; color:#9a3412; padding:15px; border-radius:12px; margin-bottom:20px; border:1px solid #fed7aa;"><i class="fas fa-user-minus"></i> El profesor fue retirado del grupo.</div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'error_docentes'): ?>
            <div class="alert" style="background:#fef2f2; color:#991b1b; padding:15px; border-radius:12px; margin-bottom:20px; border:1px solid #fecaca;"><i class="fas fa-exclamation-circle"></i> No se pudo guardar la asignación de profesores.</div>
        <?php endif; ?>

        <div class="content-card" style="margin-bottom:20px;">
            <details <?php echo count($docentes_asignados) ? 'open' : ''; ?>>
                <summary style="cursor:pointer; font-weight:700; color:#10251d; font-size:1rem; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-chalkboard-user" style="color:#0d9488;"></i> Profesores asignados a este grupo
                    <span class="badge status-active" style="margin-left:auto;"><?php echo count($docentes_asignados); ?></span>
                </summary>
                <?php if (count($docentes_asignados) > 0): ?>
                    <div style="display:flex; flex-direction:column; gap:8px; margin-top:14px;">
                        <?php foreach ($docentes_asignados as $da): ?>
                            <div style="display:flex; align-items:center; gap:12px; padding:10px 14px; border:1px solid #e8efeb; border-radius:10px; background:#f8faf9;">
                                <?php echo avatar_html($da['foto_perfil'], $da['nombre_completo'], '', 'width:38px; height:38px; flex:0 0 38px; border-radius:50%; background:#ccfbf1; color:#0f766e; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; overflow:hidden;'); ?>
                                <div style="flex:1;">
                                    <strong style="color:#10251d;" data-ficha-tipo="maestros" data-ficha-id="<?php echo (int)$da['id_maestro']; ?>" title="Ver perfil"><?php echo htmlspecialchars($da['nombre_completo']); ?> <i class="fas fa-circle-info" style="color:#0d9488; font-size:.8rem;"></i></strong>
                                    <div style="font-size:0.8rem; color:#6b7280;">
                                        Asignado el <?php echo date('d/m/Y', strtotime($da['fecha_asignacion'])); ?>
                                        <?php if (!empty($da['materias'])): ?> · Materias: <?php echo htmlspecialchars($da['materias']); ?><?php endif; ?>
                                    </div>
                                </div>
                                <form action="../procesos/asignar_docentes.php" method="POST" onsubmit="return confirm('¿Quitar a este profesor del grupo?');" style="margin:0;">
                                    <input type="hidden" name="accion" value="quitar">
                                    <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                                    <input type="hidden" name="id_maestro" value="<?php echo (int)$da['id_maestro']; ?>">
                                    <button type="submit" class="btn-icon btn-delete" title="Quitar del grupo" style="border:none; cursor:pointer;"><i class="fas fa-user-minus"></i></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#6b7280; margin:12px 0 0;">Aún no hay profesores asignados. Usa el botón <b>Asignar Profesores</b>.</p>
                <?php endif; ?>
            </details>
        </div>
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'excel_procesado'): ?>
            <div class="alert alert-success" style="background:#ecfdf5; color:#065f46; padding:15px; border-radius:12px; margin-bottom:20px; border:1px solid #a7f3d0;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div><i class="fas fa-check-circle"></i> Archivo procesado. Se inscribieron <strong><?php echo htmlspecialchars($_GET['cantidad']); ?></strong> alumnos.
                        <?php if(isset($_GET['omitidos']) && $_GET['omitidos'] > 0): ?><div style="margin-top:5px; color:#b45309; font-size:0.95rem;"><i class="fas fa-exclamation-triangle"></i> Se omitieron <b><?php echo htmlspecialchars($_GET['omitidos']); ?> alumno(s)</b> porque ya se encuentran inscritos en otro grupo activo.</div><?php endif; ?>
                    </div>
                    <?php if(isset($_GET['omitidos']) && $_GET['omitidos'] > 0): ?><button type="button" id="btnVerOmitidos" class="btn btn-primary" style="background:#b45309; border:none; font-size:0.9rem; padding:8px 15px;"><i class="fas fa-list"></i> Ver Detalles</button><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if(isset($_SESSION['detalles_omitidos'])): ?>
        <div id="modalOmitidos" class="modal-overlay">
            <div class="modal-content" style="max-width:600px;">
                <div class="modal-header"><h2><i class="fas fa-exclamation-triangle" style="color:#b45309;"></i> Detalles de Omisión</h2><button class="close-modal" id="btnCerrarOmitidos">&times;</button></div>
                <div class="modal-body" style="max-height:400px; overflow-y:auto;">
                    <table class="data-table">
                        <thead><tr><th>MATRÍCULA</th><th>MOTIVO</th><th>GRUPO ACTUAL</th></tr></thead>
                        <tbody>
                            <?php foreach($_SESSION['detalles_omitidos'] as $omitido): ?><tr><td><strong><?php echo htmlspecialchars($omitido['matricula']); ?></strong></td><td style="color:#b45309;">Inscrito en otro grupo</td><td><span class="badge status-active"><?php echo htmlspecialchars($omitido['grupo']); ?></span></td></tr><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['detalles_omitidos']); endif; ?>
        <div class="content-card">
            <div class="search-box"><i class="fas fa-search"></i><input type="text" id="buscadorAlumnos" placeholder="Buscar por matrícula, nombre o periodo de ingreso..."></div>
            <div class="table-responsive">
                <table class="data-table" id="tablaAlumnos">
                    <thead><tr><th>MATRÍCULA</th><th>NOMBRE DEL ALUMNO</th><th>PERIODO</th><th>ESTATUS</th><th>ACCIONES</th></tr></thead>
                    <tbody>
                        <?php if(count($alumnos) > 0): ?>
                            <?php foreach($alumnos as $al): ?>
                            <tr class="fila-alumno">
                                <td class="font-medium"><?php echo htmlspecialchars($al['matricula']); ?></td><td><div style="display:flex; align-items:center; gap:10px;"><?php echo avatar_html($al['fotografia'], $al['nombre_completo'], '', 'width:32px; height:32px; flex:0 0 32px; border-radius:50%; background:#e9f8f0; color:#0f7a45; display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:700; overflow:hidden;'); ?><span><?php echo htmlspecialchars($al['nombre_completo']); ?></span></div></td><td class="text-muted"><?php echo htmlspecialchars($al['periodo_ingreso'] ?? 'N/D'); ?></td><td><span class="badge status-active"><?php echo htmlspecialchars($al['estatus']); ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="perfil_alumno.php?id=<?php echo $al['id_alumno']; ?>" class="btn-icon btn-view" title="Ver perfil" data-ficha-tipo="alumnos" data-ficha-id="<?php echo (int)$al['id_alumno']; ?>"><i class="fas fa-eye"></i></a>
                                        <a href="editar_usuario.php?id=<?php echo $al['id_alumno']; ?>&tipo=alumnos" class="btn-icon btn-edit" title="Editar"><i class="fas fa-pen"></i></a>
                                        <a href="../procesos/baja_alumno.php?id=<?php echo $al['id_alumno']; ?>&grupo=<?php echo $id_grupo; ?>" class="btn-icon btn-delete" title="Baja" onclick="return confirm('¿Estás seguro de quitar a este alumno del grupo?');"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><div class="empty-state"><div class="empty-icon"><i class="fas fa-user-slash"></i></div><h3>Sin alumnos registrados</h3><p>Aún no hay alumnos inscritos en este grupo.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <div id="modalInscribir" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header"><h2><i class="fas fa-user-plus"></i> Inscribir Alumnos Existentes</h2><button class="close-modal" id="btnCerrarModal">&times;</button></div>
            <form action="Procesos/inscribir_multiples.php" method="POST">
                <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                <div class="modal-body">
                    <div class="search-box"><i class="fas fa-search"></i><input type="text" id="buscadorDisponibles" placeholder="Buscar por matrícula o nombre..."></div>
                    <div class="disponibles-list">
                        <table class="data-table">
                            <thead><tr><th style="width:40px; text-align:center;"><input type="checkbox" id="selectAll" title="Seleccionar todos"></th><th>MATRÍCULA</th><th>NOMBRE</th></tr></thead>
                            <tbody>
                                <?php if(count($alumnos_disponibles) > 0): ?>
                                    <?php foreach($alumnos_disponibles as $disp): ?>
                                    <tr class="fila-disponible">
                                        <td style="text-align:center;"><input type="checkbox" name="alumnos_seleccionados[]" value="<?php echo $disp['id_alumno']; ?>" class="chk-alumno"></td>
                                        <td><strong><?php echo htmlspecialchars($disp['matricula']); ?></strong><br><span class="text-muted" style="font-size:0.75rem;">Ingreso: <?php echo htmlspecialchars($disp['periodo_ingreso'] ?? 'N/A'); ?></span></td>
                                        <td style="font-size:0.9rem; font-weight:500;"><?php echo htmlspecialchars($disp['nombre_completo']); ?><?php if (!empty($disp['grupo_actual'])): ?><br><span style="font-size:0.72rem; color:#b45309; font-weight:600;">Actualmente en <?php echo htmlspecialchars($disp['grupo_actual']); ?> (se cambiará)</span><?php endif; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="text-align:center; color:#6b7280; padding:30px;">No hay alumnos en estatus de "Alta" disponibles para agregar.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                    <span id="contadorSeleccionados" style="font-weight:600; color:#16a05d;">0 seleccionados</span>
                    <button type="submit" class="btn btn-primary" style="padding:10px 20px;"><i class="fas fa-save"></i> Inscribir Seleccionados</button>
                </div>
            </form>
        </div>
    </div>
    <div id="modalExcel" class="modal-overlay">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header"><h2><i class="fas fa-file-excel"></i> Inscribir por Excel/CSV</h2><button class="close-modal" id="btnCerrarModalExcel">&times;</button></div>
            <form action="Procesos/inscribirAlumnos_excel.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                <div class="modal-body">
                    <p style="font-size:0.9rem; color:#6b7280; margin-bottom:15px;">Sube un archivo <b>CSV (delimitado por comas)</b> que contenga una columna con las <b>Matrículas</b> de los alumnos que deseas inscribir a este grupo (sin encabezados u otra información ajena).</p>
                    <input type="file" name="archivo_csv" accept=".csv" required style="width:100%; padding:12px; border:2px dashed #cbd5e1; border-radius:8px; cursor:pointer;">
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary" style="width:100%; background-color:#0d9488;"><i class="fas fa-upload"></i> Procesar Archivo</button></div>
            </form>
        </div>
    </div>
    <div id="modalDocentes" class="modal-overlay">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header"><h2><i class="fas fa-chalkboard-user" style="color:#0d9488;"></i> Asignar Profesores</h2><button class="close-modal" id="btnCerrarModalDocentes">&times;</button></div>
            <form action="../procesos/asignar_docentes.php" method="POST">
                <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
                <div class="modal-body">
                    <p style="font-size:0.9rem; color:#6b7280; margin-bottom:15px;">Marca los profesores que impartirán clases en este grupo. Los que ya están asignados aparecen marcados; si desmarcas uno, se retirará del grupo.</p>
                    <div style="max-height:280px; overflow-y:auto; border:1px solid #e8efeb; border-radius:8px; background:#f8faf9;">
                        <?php if(count($maestros) > 0): ?>
                            <?php foreach($maestros as $maestro): ?>
                            <label style="display:flex; align-items:center; padding:12px; border-bottom:1px solid #e8efeb; cursor:pointer; transition:background 0.2s;">
                                <?php $ya = in_array((int)$maestro['id_maestro'], $ids_asignados, true); ?>
                                <input type="checkbox" name="maestros_seleccionados[]" value="<?php echo $maestro['id_maestro']; ?>" <?php echo $ya ? 'checked' : ''; ?> style="margin-right:12px; width:18px; height:18px; accent-color:#0d9488; cursor:pointer;">
                                <span style="font-size:0.95rem; font-weight:500; color:#10251d; flex:1;"><?php echo htmlspecialchars($maestro['nombre'] . ' ' . $maestro['apellido_paterno'] . ' ' . $maestro['apellido_materno']); ?></span>
                                <?php if ($ya): ?><span style="font-size:0.7rem; font-weight:700; color:#0f766e; background:#ccfbf1; padding:3px 8px; border-radius:20px;">YA ASIGNADO</span><?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align:center; color:#6b7280; padding:20px; margin:0;">No hay profesores registrados en el sistema.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary" style="width:100%; background-color:#0d9488;"><i class="fas fa-save"></i> Guardar Asignación</button></div>
            </form>
        </div>
    </div>
    <script src="../js/ficha_modal.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay'), mobileMenu = document.getElementById('mobileMenu'), collapseToggle = document.getElementById('collapseToggle'), body = document.body;
            if (localStorage.getItem('sidebarCollapsed') === 'true') body.classList.add('sidebar-collapsed');
            if (collapseToggle) collapseToggle.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); });
            function abrirMenu() { if(sidebar) sidebar.classList.add('mobile-open'); if(overlay) overlay.classList.add('visible'); body.classList.add('menu-open'); }
            function cerrarMenu() { if(sidebar) sidebar.classList.remove('mobile-open'); if(overlay) overlay.classList.remove('visible'); body.classList.remove('menu-open'); }
            if(mobileMenu) mobileMenu.addEventListener('click', abrirMenu);
            if(overlay) overlay.addEventListener('click', cerrarMenu);
            window.addEventListener('resize', () => { if (window.innerWidth > 900) cerrarMenu(); });

            const buscador = document.getElementById('buscadorAlumnos'), filas = document.querySelectorAll('.fila-alumno');
            if (buscador) buscador.addEventListener('keyup', function() {
                const texto = this.value.toLowerCase();
                filas.forEach(fila => {
                    const txt = fila.children[0].textContent.toLowerCase() + fila.children[1].textContent.toLowerCase() + fila.children[2].textContent.toLowerCase();
                    fila.style.display = txt.includes(texto) ? '' : 'none';
                });
            });

            function setupModal(modalId, btnAbrirId, btnCerrarId) {
                const modal = document.getElementById(modalId), btnAbrir = document.getElementById(btnAbrirId), btnCerrar = document.getElementById(btnCerrarId);
                if (modal && btnAbrir && btnCerrar) {
                    btnAbrir.addEventListener('click', () => modal.classList.add('active'));
                    btnCerrar.addEventListener('click', () => modal.classList.remove('active'));
                    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('active'); });
                }
            }
            setupModal('modalInscribir', 'btnAbrirModal', 'btnCerrarModal');
            setupModal('modalExcel', 'btnAbrirModalExcel', 'btnCerrarModalExcel');
            setupModal('modalDocentes', 'btnAbrirModalDocentes', 'btnCerrarModalDocentes');
            setupModal('modalOmitidos', 'btnVerOmitidos', 'btnCerrarOmitidos');

            const buscadorModal = document.getElementById('buscadorDisponibles'), filasDisponibles = document.querySelectorAll('.fila-disponible');
            if(buscadorModal) buscadorModal.addEventListener('keyup', function() {
                const texto = this.value.toLowerCase();
                filasDisponibles.forEach(fila => {
                    const txt = fila.children[1].textContent.toLowerCase() + fila.children[2].textContent.toLowerCase();
                    fila.style.display = txt.includes(texto) ? '' : 'none';
                });
            });

            const selectAll = document.getElementById('selectAll'), checkboxes = document.querySelectorAll('.chk-alumno'), contadorText = document.getElementById('contadorSeleccionados');
            function actualizarContador() { if(contadorText) contadorText.textContent = document.querySelectorAll('.chk-alumno:checked').length + ' seleccionado(s)'; }
            if (selectAll) selectAll.addEventListener('change', function() {
                checkboxes.forEach(chk => { if (chk.closest('tr').style.display !== 'none') chk.checked = this.checked; });
                actualizarContador();
            });
            checkboxes.forEach(chk => chk.addEventListener('change', actualizarContador));
        });
    </script>
    <script src="../js/responsive.js?v=8"></script>
</body>
</html>