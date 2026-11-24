<?php
session_start();
require_once '../config/database.php'; 
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/calendario.php';
require_once __DIR__ . '/../includes/asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set('America/Mexico_City');
$fecha_actual = date('d/m/Y');
$sin_clases_hoy = dia_sin_clases($pdo, date('Y-m-d'));   // null = hoy sí hay clases
$suspensiones = suspensiones_proximas($pdo);
$pintas_hoy = $sin_clases_hoy ? [] : array_filter(posibles_pintas($pdo, date('Y-m-d')), fn($p) => $p['pinta']);
$pases_hoy = $sin_clases_hoy ? [] : pases_de_lista_del_dia($pdo, date('Y-m-d'));
$pases_pendientes = count(array_filter($pases_hoy, fn($c) => (int)$c['marcados'] === 0));

try {
    // 1. Contar alumnos activos (sin bajas)
    $stmt_alumnos = $pdo->query("SELECT COUNT(*) FROM alumnos WHERE estatus = 'Alta'");
    $total_alumnos = $stmt_alumnos->fetchColumn();

    // 2. Contar puntuales (y retardos justificados)
    $stmt_puntuales = $pdo->query("SELECT COUNT(*) FROM asistencias 
                                   WHERE DATE(fecha_hora_escaneo) = CURDATE() 
                                   AND estatus IN ('Puntual', 'Retardo Justificado')");
    $puntuales = $stmt_puntuales->fetchColumn();

    // 3. Contar retardos
    $stmt_retardos = $pdo->query("SELECT COUNT(*) FROM asistencias 
                                  WHERE DATE(fecha_hora_escaneo) = CURDATE() 
                                  AND estatus = 'Retardo'");
    $retardos = $stmt_retardos->fetchColumn();

    // 4. Calcular inasistencias (en un día sin clases nadie tiene falta)
    $inasistencias = $sin_clases_hoy ? 0 : max(0, $total_alumnos - ($puntuales + $retardos));

    // 5. Obtener últimos registros
    $stmt_ultimos = $pdo->query("
        SELECT a.fecha_hora_escaneo, a.fecha_hora_salida, al.matricula, al.nombre, al.apellido_paterno, a.estatus 
        FROM asistencias a
        INNER JOIN alumnos al ON a.id_alumno = al.id_alumno
        WHERE DATE(a.fecha_hora_escaneo) = CURDATE()
        ORDER BY a.fecha_hora_escaneo DESC
        LIMIT 15
    ");
    $ultimos_registros = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Si hay error en la BD, ponemos todo en cero para que no salgan errores en pantalla
    $total_alumnos = 0;
    $puntuales = 0;
    $retardos = 0;
    $inasistencias = 0;
    $ultimos_registros = [];
}

$nombre_usuario = isset($_SESSION['nombre']) 
    ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') 
    : 'Prefecto';
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
    <title>Panel de Prefectura · COBAEP Plantel 27</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css?v=3">

    <link rel="stylesheet" href="css/dashboard_prefectura.css?v=3">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">PREFECTURA · SIGE</span>
                    <h1>¡Bienvenido, <?php echo $nombre_usuario; ?>!</h1>
                    <p>Panel de control de asistencias diarias.</p>
                </div>
            </div>

            <?php topbar_usuario($pdo); ?>
        </header>

        <?php if (!empty($n_sugeridos)): ?>
            <div class="aviso-sugeridos"><i class="fas fa-list-check"></i> Hay <b><?php echo $n_sugeridos; ?></b> reporte(s) sugerido(s) por atender: alumnos con <?php echo SUGERIDOS_RETARDOS; ?> retardos o <?php echo SUGERIDOS_FALTAS; ?> faltas sin justificar este mes. <a href="reportes_sugeridos.php">Revisar</a></div>
        <?php endif; ?>

        <section class="stats-grid" aria-label="Resumen">
            <article class="stat-card teachers">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-content"><span>Total Alumnos</span><strong><?php echo $total_alumnos; ?></strong><small>Activos en el sistema</small></div>
            </article>

            <article class="stat-card students">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content"><span>Puntuales</span><strong><?php echo $puntuales; ?></strong><small>Ingresos a tiempo</small></div>
            </article>

            <article class="stat-card attendance">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-content"><span>Retardos</span><strong><?php echo $retardos; ?></strong><small>Ingresos con demora</small></div>
            </article>

            <article class="stat-card absent">
                <div class="stat-icon"><i class="fas fa-user-xmark"></i></div>
                <?php if ($sin_clases_hoy): ?>
                <div class="stat-content"><span>Pendientes / Faltas</span><strong>0</strong><small>Hoy no hay clases · <?php echo htmlspecialchars($sin_clases_hoy); ?></small></div>
                <?php else: ?>
                <div class="stat-content"><span>Pendientes / Faltas</span><strong><?php echo $inasistencias; ?></strong><small>Aún no ingresan</small></div>
                <?php endif; ?>
            </article>
        </section>

        <?php if (!$sin_clases_hoy): ?>
        <a href="asistencia_clases.php" class="cruce-banner <?php echo $pintas_hoy ? 'hay' : ''; ?>">
            <i class="fas fa-person-walking-arrow-right"></i>
            <span><b><?php echo count($pintas_hoy); ?> posible(s) pinta(s) hoy</b> · alumnos que entraron al plantel y faltaron a una clase.
            <?php if ($pases_hoy): ?><small><?php echo count($pases_hoy) - $pases_pendientes; ?> de <?php echo count($pases_hoy); ?> materias de hoy ya pasaron lista.</small><?php endif; ?></span>
            <i class="fas fa-arrow-right"></i>
        </a>
        <?php endif; ?>

        <section class="section">
            <div class="section-heading">
                <div><span class="section-kicker">HERRAMIENTAS</span><h2>Acciones de registro</h2></div>
            </div>

            <div class="action-grid">
                <a href="escaner_qr.php" class="action-card scanner-btn">
                    <div class="action-icon green"><i class="fas fa-qrcode"></i></div>
                    <div class="action-text"><h3>Escáner QR</h3><p>Captura credenciales rápidamente.</p></div>
                    <i class="fas fa-arrow-right action-arrow"></i>
                </a>
                
                <a href="#" class="action-card suspension-btn" onclick="document.getElementById('modalSuspension').style.display='flex'; return false;">
                    <div class="action-icon red"><i class="fas fa-calendar-times"></i></div>
                    <div class="action-text"><h3>Suspender Faltas</h3><p>Configura días sin clases.</p></div>
                    <i class="fas fa-arrow-right action-arrow"></i>
                </a>

                <a href="#" class="action-card manual-btn" onclick="abrirModal()">
                    <div class="action-icon orange"><i class="fas fa-keyboard"></i></div>
                    <div class="action-text"><h3>Registro Manual</h3><p>Ingreso en casos excepcionales.</p></div>
                    <i class="fas fa-arrow-right action-arrow"></i>
                </a>
            </div>
        </section>

        <section class="dashboard-bottom" style="grid-template-columns: 1fr;">
            <article class="overview-card" style="padding: 0; overflow: hidden;">
                <div class="card-heading" style="padding: 24px 24px 0;">
                    <div><span class="section-kicker">MONITOREO</span><h2>Últimos Ingresos Detectados</h2></div>
                    <button class="btn btn-cancel" onclick="location.reload();" style="padding: 0.5rem 1rem; font-size: 0.75rem;"><i class="fas fa-sync-alt"></i> Actualizar</button>
                </div>

                <div class="table-responsive" style="margin-bottom: 0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Matrícula</th>
                                <th>Nombre del Alumno</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ultimos_registros)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--muted); padding: 3rem;">Aún no hay registros de asistencia para el día de hoy.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ultimos_registros as $registro): 
                                    $hora_formateada = date('h:i A', strtotime($registro['fecha_hora_escaneo']));
                                    $estatus_min = strtolower($registro['estatus'] ?? '');
    
                                    $clase_badge = 'status-puntual';
                                    $clase_fila = '';
                                    $texto_estatus = !empty($registro['estatus']) ? $registro['estatus'] : 'Falta';

                                    if ($estatus_min === 'retardo') {
                                       $clase_badge = 'status-retardo';
                                    } elseif ($estatus_min === 'falta' || empty($estatus_min)) {
                                       $clase_badge = 'status-falta';
                                       $clase_fila = 'fila-falta';
                                       $texto_estatus = 'Falta';
                                    } elseif ($estatus_min === 'suspendido') {
                                       $clase_badge = 'status-suspendido'; 
                                       $texto_estatus = 'Suspendido'; 
                                    } elseif ($estatus_min === 'retardo justificado') {
                                       $clase_badge = 'status-retardo-just'; 
                                       $texto_estatus = 'Retardo justificado'; 
                                    } elseif ($estatus_min === 'falta justificada') {
                                       $clase_badge = 'status-justificada';
                                    }
    
                                    $nombre_completo = htmlspecialchars($registro['apellido_paterno'] . ', ' . $registro['nombre']);
                                ?>
                                <tr class="<?php echo $clase_fila; ?>">
                                    <td><strong><?php echo $hora_formateada; ?></strong></td>
                                    
                                    <td>
                                        <?php 
                                        if (!empty($registro['fecha_hora_salida'])) {
                                            echo '<strong>' . date('h:i A', strtotime($registro['fecha_hora_salida'])) . '</strong>';
                                        } else {
                                            echo '<span style="color: var(--muted);">-</span>';
                                        }
                                        ?>
                                    </td>
                                    
                                    <td><?php echo htmlspecialchars($registro['matricula']); ?></td>
                                    <td><?php echo $nombre_completo; ?></td>
                                    <td><span class="status-badge-table <?php echo $clase_badge; ?>"><?php echo htmlspecialchars($texto_estatus); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <footer class="footer" style="margin-top: 2rem;">
            <span>© 2026 COBAEP Plantel 27</span><span>·</span><span>SIGE · Sistema de Información y Gestión Educativa</span>
        </footer>
    </main>

    <!-- MODAL DE SUSPENSIÓN DE CLASES -->
    <div class="modal-overlay" id="modalSuspension">
        <div class="modal">
            <h3><i class="fas fa-calendar-times" style="color: #ef4444;"></i> Programar Suspensión</h3>
            <p>En esos días nadie tendrá falta (panel, monitoreo, listas, concentrado y avisos a tutores). Los sábados y domingos ya se omiten solos.</p>
            <form action="procesos/registrar_suspension.php" method="POST">
                <div class="form-group">
                    <label>Motivo de la suspensión</label>
                    <select name="motivo" class="form-control" required>
                        <option value="Consejo Técnico">Consejo Técnico</option>
                        <option value="Suspensión oficial">Día Festivo / Suspensión oficial</option>
                        <option value="Vacaciones">Vacaciones</option>
                        <option value="Fuerza Mayor">Causa de Fuerza Mayor</option>
                    </select>
                </div>
                <div class="form-group susp-fechas">
                    <div><label>Fecha de Inicio</label><input type="date" name="fecha_inicio" id="suspInicio" class="form-control" required></div>
                    <div><label>Fecha de Fin</label><input type="date" name="fecha_fin" id="suspFin" class="form-control" required></div>
                </div>
                <?php if ($suspensiones): ?>
                <div class="susp-lista">
                    <strong>Suspensiones programadas</strong>
                    <?php foreach ($suspensiones as $s): ?>
                    <div class="susp-item">
                        <span><b><?php echo htmlspecialchars($s['motivo']); ?></b> · <?php echo rango_fechas_texto($s['fecha_inicio'], $s['fecha_fin']); ?></span>
                        <button type="submit" form="formCancelarSusp" name="id_suspension" value="<?php echo (int)$s['id_suspension']; ?>" class="susp-cancelar" onclick="return confirm('¿Cancelar esta suspensión? Esos días volverán a contar como días de clase.');"><i class="fas fa-xmark"></i> Cancelar</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="document.getElementById('modalSuspension').style.display='none'">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="background: #ef4444;">Confirmar Suspensión</button>
                </div>
            </form>
        </div>
    </div>

    <form id="formCancelarSusp" action="procesos/eliminar_suspension.php" method="POST" hidden></form>
    <script>
        // La fecha fin nunca puede ser antes que la de inicio
        (function () {
            const i = document.getElementById('suspInicio'), f = document.getElementById('suspFin');
            if (!i || !f) return;
            i.addEventListener('change', () => { f.min = i.value; if (!f.value || f.value < i.value) f.value = i.value; });
        })();
    </script>

    <!-- MODAL DE REGISTRO MANUAL -->
    <div class="modal-overlay" id="modalManual">
        <div class="modal">
            <h3>Registro Manual</h3>
            <p>Utiliza esta función únicamente en casos excepcionales (credencial dañada o sistema caído).</p>
            <form action="procesos/registro_manual.php" method="POST">
                <div class="form-group">
                    <label for="matricula_manual"><i class="fas fa-id-card"></i> Número de Matrícula</label>
                    <input type="text" id="matricula_manual" name="matricula" class="form-control" placeholder="Ej. 27/2026B0001" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label for="estado_asistencia"><i class="fas fa-check-circle"></i> Estado a registrar</label>
                    <select id="estado_asistencia" name="estado" class="form-control" required>
                      <option value="asistencia">Asistencia (Puntual)</option>
                      <option value="retardo">Retardo</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tipo_movimiento"><i class="fas fa-exchange-alt"></i> Acción</label>
                    <select id="tipo_movimiento" name="tipo_movimiento" class="form-control" required>
                        <option value="entrada">Registrar Entrada</option>
                        <option value="salida">Registrar Salida</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Registro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE ALERTA DE SUSPENSIÓN -->
    <div class="alert-modal-overlay" id="alertModal">
        <div class="alert-modal-box">
            <div class="alert-modal-title"><i class="fas fa-minus-circle"></i><span>ACCESO DENEGADO</span></div>
            <div class="alert-modal-text">El alumno se encuentra SUSPENDIDO y no puede registrar asistencia en el plantel.<span class="alert-modal-date" id="alertReturnDate"></span></div>
            <div class="alert-modal-footer"><button type="button" class="alert-modal-btn" onclick="document.getElementById('alertModal').style.display='none'">Aceptar</button></div>
        </div>
    </div>

    <!-- MODAL DE ÉXITO -->
    <div class="alert-modal-overlay" id="modalExito">
        <div class="alert-modal-box" style="border-top: 4px solid #10b981;">
            <div class="alert-modal-title">
                <i class="fas fa-check-circle" style="color: #10b981;"></i>
                <span style="color: #10b981;">¡REGISTRO EXITOSO!</span>
            </div>
            <div class="alert-modal-text" id="exitoText">La asistencia se ha guardado correctamente en el sistema.</div>
            <div class="alert-modal-footer">
                <button type="button" class="alert-modal-btn" onclick="document.getElementById('modalExito').style.display='none'">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DE ERROR -->
    <div class="alert-modal-overlay" id="modalError">
        <div class="alert-modal-box" style="border-top: 4px solid #ef4444;">
            <div class="alert-modal-title">
                <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                <span style="color: #ef4444;">ERROR EN EL REGISTRO</span>
            </div>
            <div class="alert-modal-text" id="errorText">No se pudo registrar la asistencia. Verifica la matrícula e intenta nuevamente.</div>
            <div class="alert-modal-footer">
                <button type="button" class="alert-modal-btn" onclick="document.getElementById('modalError').style.display='none'">Aceptar</button>
            </div>
        </div>
    </div>

    <script src="../js/dashboard_prefectura.js"></script>

    <script src="../js/responsive.js?v=11"></script>
</body>
</html>