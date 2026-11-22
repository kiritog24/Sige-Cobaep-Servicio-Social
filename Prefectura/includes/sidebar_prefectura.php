<?php
/** Barra lateral de Prefectura, igual en todas sus páginas y organizada por secciones. */
$pp = basename($_SERVER['PHP_SELF']);
$act = fn(array $paginas) => in_array($pp, $paginas, true) ? ' active' : '';
?>
    <aside class="sidebar" id="sidebar">
        <button id="collapseToggle" class="collapse-toggle" aria-label="Colapsar menú"><i class="fas fa-chevron-left"></i></button>
        <div class="sidebar-inner">
            <div class="brand">
                <img src="../img/LogoCobaep.png" alt="Logo COBAEP" class="brand-logo">
                <div class="brand-copy"><strong>SIGE<span>-Cobaep</span></strong><small>Plantel 27</small></div>
            </div>

            <nav class="navigation" aria-label="Navegación principal">
                <span class="nav-heading">Menú principal</span>
                <a href="dashboard_prefectura.php" class="nav-item<?php echo $act(['dashboard_prefectura.php']); ?>"><i class="fas fa-shield-halved"></i><span>Control de Acceso</span></a>

                <span class="nav-heading">Asistencia</span>
                <a href="escaner_qr.php" class="nav-item<?php echo $act(['escaner_qr.php']); ?>"><i class="fas fa-qrcode"></i><span>Escáner QR</span></a>
                <a href="monitoreo_grupos.php" class="nav-item<?php echo $act(['monitoreo_grupos.php', 'vista_previa_lista.php']); ?>"><i class="fas fa-users-viewfinder"></i><span>Monitoreo de Grupos</span></a>
                <a href="historial_asistencias.php" class="nav-item<?php echo $act(['historial_asistencias.php']); ?>"><i class="fas fa-history"></i><span>Historial de Asistencias</span></a>
                <a href="concentrado_asistencia.php" class="nav-item<?php echo $act(['concentrado_asistencia.php']); ?>"><i class="fas fa-chart-column"></i><span>Concentrado por periodo</span></a>

                <span class="nav-heading">Conducta</span>
                <a href="creacion_reportes.php" class="nav-item<?php echo $act(['creacion_reportes.php', 'editar_reporte.php']); ?>"><i class="fa-solid fa-file-lines"></i><span>Reportes de Conducta</span></a>
                <a href="expediente_alumnos.php" class="nav-item<?php echo $act(['expediente_alumnos.php', 'perfil_alumno.php']); ?>"><i class="fas fa-folder-open"></i><span>Expediente de Alumnos</span></a>
                <a href="avisos_tutores.php" class="nav-item<?php echo $act(['avisos_tutores.php']); ?>"><i class="fas fa-paper-plane"></i><span>Avisos a tutores</span></a>

                <span class="nav-heading">Información</span>
                <a href="horarios_plantel.php" class="nav-item<?php echo $act(['horarios_plantel.php']); ?>"><i class="fas fa-calendar-week"></i><span>Horarios del Plantel</span></a>

                <span class="nav-heading">Cuenta</span>
                <a href="perfil.php" class="nav-item<?php echo $act(['perfil.php']); ?>"><i class="fas fa-user-gear"></i><span>Mi Perfil</span></a>
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
    <?php include __DIR__ . '/../../includes/menu_estado.php'; ?>
