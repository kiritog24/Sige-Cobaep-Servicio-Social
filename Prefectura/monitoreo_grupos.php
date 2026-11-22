<?php
session_start();
require_once '../config/database.php'; 
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/calendario.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}

$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Prefecto';
date_default_timezone_set('America/Mexico_City');

// Consulta inteligente: Trae todos los grupos y calcula los asistentes de HOY
$query_grupos = "
    SELECT 
        g.id_grupo, 
        g.semestre, 
        g.nombre_grupo,
        g.turno,
        (SELECT COUNT(*) FROM grupo_alumno gx INNER JOIN alumnos ax ON ax.id_alumno = gx.id_alumno
         WHERE gx.id_grupo = g.id_grupo AND gx.fecha_baja IS NULL AND ax.estatus = 'Alta') AS total_alumnos,
        (SELECT COUNT(DISTINCT a.id_alumno) 
         FROM asistencias a 
         INNER JOIN grupo_alumno ga2 ON a.id_alumno = ga2.id_alumno 
         WHERE ga2.id_grupo = g.id_grupo AND ga2.fecha_baja IS NULL
         AND DATE(a.fecha_hora_escaneo) = CURDATE() 
         AND a.estatus NOT IN ('Falta', 'Suspendido', '')
        ) AS asistencias_hoy
    FROM grupos g
    ORDER BY g.semestre ASC, g.nombre_grupo ASC;
";

try {
    $stmt = $pdo->query($query_grupos);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $grupos = [];
}
$sin_clases_hoy = dia_sin_clases($pdo, date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoreo de Grupos · SIGE COBAEP 27</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link rel="stylesheet" href="../admin/style_dashboard_admin.css">
    <link rel="stylesheet" href="../admin/style_sidebar.css">
    <link rel="stylesheet" href="../Prefectura/style_dashboard_prefectura.css">

    <link rel="stylesheet" href="css/monitoreo_grupos.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>

<body>
    <?php include __DIR__ . '/includes/sidebar_prefectura.php'; ?>


    <main class="main-content">
        <header class="topbar">
         <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <div>
                    <span class="eyebrow">OPERACIONES DE PREFECTURA</span>
                    <h1>Monitoreo de Grupos en Vivo</h1>
                    <p>Vista general de asistencia y operaciones por salón (Datos del día de hoy).</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <?php if ($sin_clases_hoy): ?>
            <div style="margin-top:20px; padding:14px 18px; border-radius:12px; background:#f1f5f9; border:1px solid #e2e8f0; color:#334155; font-weight:600;">
                <i class="fas fa-school-circle-xmark"></i> Hoy no hay clases (<?php echo htmlspecialchars($sin_clases_hoy); ?>): no se cuentan faltas.
            </div>
        <?php endif; ?>
        <section class="groups-grid">
            <?php if (empty($grupos)): ?>
                <div style="grid-column: 1 / -1; padding: 2rem; text-align: center; background: white; border-radius: 12px;">
                    <i class="fas fa-exclamation-circle" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No hay grupos registrados en el sistema actualmente.</p>
                </div>
            <?php else: ?>
                <?php foreach ($grupos as $g): 
                    $total = (int)$g['total_alumnos'];
                    $asistencias = (int)$g['asistencias_hoy'];
                    $faltas = $sin_clases_hoy ? 0 : max(0, $total - $asistencias);
                    
                    $nombre_completo = $g['semestre'] . '° "' . $g['nombre_grupo'] . '"';
                    
                    // Lógica para detectar el turno y asignar los colores
                    $turno_str = strtolower($g['turno']);
                    $clase_tarjeta = 'card-matutino'; // Por defecto verde
                    $clase_badge = 'badge-matutino';
                    
                    if (strpos($turno_str, 'intermedio') !== false) {
                        $clase_tarjeta = 'card-intermedio'; // Naranja
                        $clase_badge = 'badge-intermedio';
                    } elseif (strpos($turno_str, 'vespertino') !== false) {
                        $clase_tarjeta = 'card-vespertino'; // Azul
                        $clase_badge = 'badge-vespertino';
                    }
                ?>
                <article class="group-card <?php echo $clase_tarjeta; ?>">
                    <div class="group-header">
                        <h2><?php echo htmlspecialchars($nombre_completo); ?></h2>
                        <span class="badge-turno <?php echo $clase_badge; ?>"><?php echo htmlspecialchars($g['turno']); ?></span>
                    </div>
                    
                    <div class="group-stats">
                        <div class="stat-item stat-present">
                            <i class="fas fa-user-check"></i>
                            <span><?php echo $asistencias; ?></span>
                            <small>Asistencias</small>
                        </div>
                        <div class="stat-item stat-absent">
                            <i class="fas fa-user-times"></i>
                            <span><?php echo $faltas; ?></span>
                            <small>Faltas / Ausentes</small>
                        </div>
                        <div class="stat-item" style="border-left: 1px solid #e2e8f0;">
                            <i class="fas fa-users" style="color: #94a3b8;"></i>
                            <span><?php echo $total; ?></span>
                            <small>Inscritos</small>
                        </div>
                    </div>

                    <div class="group-actions">
                        <button onclick="abrirModalLista(<?php echo (int)$g['id_grupo']; ?>, <?php echo htmlspecialchars(json_encode($g['semestre'] . '° ' . $g['nombre_grupo']), ENT_QUOTES); ?>)" class="btn-group-action btn-list">
                            <i class="fas fa-list-ol"></i> Generar Lista de Asistencia
                        </button>
                        
                        <a href="expediente_alumnos.php?grupo=<?php echo $g['id_grupo']; ?>" class="btn-group-action btn-folder">
                            <i class="fas fa-folder-open"></i> Ver Expedientes
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <!-- MODAL PEQUEÑO PARA ELEGIR LA FECHA -->
    <div class="modal-overlay" id="modalFechaLista" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(3, 43, 30, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; z-index: 2000;">
        <div class="modal" style="background: #ffffff; padding: 2.5rem; border-radius: var(--radius-lg); width: 90%; max-width: 400px; box-shadow: var(--shadow-lg);">
            <h3 style="margin-bottom: 0.5rem; color: var(--ink); font-weight: 800;"><i class="fas fa-calendar-day" style="color: var(--green);"></i> Lista <span id="nombreGrupoModal"></span></h3>
            <p style="font-size: 0.8rem; color: var(--muted); margin-bottom: 1.5rem;">Selecciona el día que deseas exportar.</p>
            
            <form action="vista_previa_lista.php" method="GET">
                <input type="hidden" name="id_grupo" id="idGrupoModalInput">
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem;">Fecha de la lista</label>
                    <input type="date" name="fecha_lista" class="form-control" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 0.85rem; border: 1px solid var(--line); border-radius: 6px;">
                </div>
                
                <div class="modal-actions" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-cancel" onclick="document.getElementById('modalFechaLista').style.display='none'" style="padding: 0.75rem 1.2rem; border-radius: 6px; border: none; cursor: pointer; background: #e2e8f0;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.2rem; border-radius: 6px; border: none; cursor: pointer; background: var(--green); color: white; font-weight: bold;">Generar Vista Previa</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../js/dashboard_prefectura.js"></script>
    <script>
        function abrirModalLista(idGrupo, nombreGrupo) {
            document.getElementById('idGrupoModalInput').value = idGrupo;
            document.getElementById('nombreGrupoModal').innerText = nombreGrupo;
            document.getElementById('modalFechaLista').style.display = 'flex';
        }
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>