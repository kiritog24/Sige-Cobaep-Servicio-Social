<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/reporte_asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

$id_clase = isset($_GET['id_clase']) ? (int)$_GET['id_clase'] : 0;
if ($id_clase === 0) { header("Location: mis_grupos.php"); exit(); }

try {
    $stmt = $pdo->prepare("SELECT m.nombre_materia, m.imagen_portada, g.nombre_grupo, g.semestre, g.turno
                           FROM clases_regulares c INNER JOIN materias m ON c.id_materia = m.id_materia
                           LEFT JOIN grupos g ON c.id_grupo = g.id_grupo
                           WHERE c.id_clase = ? AND c.id_maestro = ?");
    $stmt->execute([$id_clase, (int)$_SESSION['id_usuario']]);
    $clase = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$clase) { header("Location: mis_grupos.php?error=" . urlencode('Esa materia no es tuya.')); exit(); }

    $periodo = reporte_asistencia_periodo($_GET);
    $filas = reporte_asistencia_filas($pdo, $id_clase, $periodo);
    $resumen = reporte_asistencia_resumen($filas);
} catch (PDOException $e) {
    error_log('lista_alumnos: ' . $e->getMessage());
    header("Location: gestionar_aula.php?id_clase=$id_clase&error=" . urlencode('No se pudo cargar el reporte de asistencia.'));
    exit();
}
$portada = (!empty($clase['imagen_portada']) && is_file(__DIR__ . '/../' . $clase['imagen_portada'])) ? '../' . $clase['imagen_portada'] : '';
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$tipo = $_GET['tipo_filtro'] ?? 'semestre';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Asistencia · Portal Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_docente.css">
    <link rel="stylesheet" href="css/ver_entregas.css?v=4">
    <link rel="stylesheet" href="css/lista_alumnos.css?v=2">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body class="pagina-entregas">
    <main class="main-content entregas-main">
        <div class="header-entregas<?php echo $portada ? ' con-portada' : ''; ?>"<?php if ($portada): ?> style="background-image: url('<?php echo htmlspecialchars($portada, ENT_QUOTES); ?>');"<?php endif; ?>>
            <div class="he-texto">
                <span class="he-eyebrow"><i class="fas fa-book"></i> <?php echo htmlspecialchars($clase['nombre_materia']); ?><?php if ($clase['nombre_grupo']): ?> · Grupo <?php echo htmlspecialchars($clase['semestre'] . '° "' . $clase['nombre_grupo'] . '"'); ?><?php endif; ?></span>
                <h2>Reporte de asistencia</h2>
                <span class="he-sub"><i class="fas fa-calendar-check"></i> <span id="textoPeriodo"><?php echo htmlspecialchars($periodo['texto']); ?></span></span>
            </div>
            <a href="gestionar_aula.php?id_clase=<?php echo $id_clase; ?>" class="he-volver"><i class="fas fa-arrow-left"></i> Volver al aula</a>
        </div>

        <div class="resumen-entregas" id="resumenAsistencia">
            <div><strong data-r="alumnos"><?php echo $resumen['alumnos']; ?></strong><span>Alumnos</span></div>
            <div><strong data-r="pases"><?php echo $resumen['pases']; ?></strong><span>Pases de lista</span></div>
            <div><strong data-r="promedio"><?php echo $resumen['promedio'] !== null ? $resumen['promedio'] . '%' : '—'; ?></strong><span>Asistencia promedio</span></div>
            <div><strong data-r="en_riesgo"><?php echo $resumen['en_riesgo']; ?></strong><span>Con menos de 80%</span></div>
        </div>

        <form id="form_filtros" class="panel-filtros" action="procesos/descargar_asistencia_excel.php" method="GET">
            <input type="hidden" name="id_clase" value="<?php echo $id_clase; ?>">
            <div class="pf-titulo"><i class="fas fa-filter"></i> Periodo del reporte</div>
            <div class="pf-campos">
                <label class="pf-campo">
                    <span>Tipo de corte</span>
                    <select name="tipo_filtro" id="tipo_filtro">
                        <option value="semestre" <?php echo $tipo === 'semestre' ? 'selected' : ''; ?>>Semestre completo</option>
                        <option value="parcial" <?php echo $tipo === 'parcial' ? 'selected' : ''; ?>>Por parcial (fechas)</option>
                        <option value="mes" <?php echo $tipo === 'mes' ? 'selected' : ''; ?>>Por mes</option>
                    </select>
                </label>
                <label class="pf-campo" id="div_fechas_ini" hidden>
                    <span>Desde</span>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo htmlspecialchars($_GET['fecha_inicio'] ?? ''); ?>">
                </label>
                <label class="pf-campo" id="div_fechas_fin" hidden>
                    <span>Hasta</span>
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo htmlspecialchars($_GET['fecha_fin'] ?? ''); ?>">
                </label>
                <label class="pf-campo" id="div_mes" hidden>
                    <span>Mes</span>
                    <select name="mes" id="mes">
                        <?php foreach ($meses as $n => $nom): ?>
                            <option value="<?php echo sprintf('%02d', $n); ?>" <?php echo (int)($_GET['mes'] ?? date('n')) === $n ? 'selected' : ''; ?>><?php echo $nom; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="pf-botones">
                <button type="button" class="pf-btn pf-actualizar" onclick="actualizarVistaPrevia()"><i class="fas fa-rotate"></i> Actualizar tabla</button>
                <button type="submit" class="pf-btn pf-excel"><i class="fas fa-file-excel"></i> Descargar Excel</button>
            </div>
        </form>

        <p class="leyenda-asist">
            <span><i class="fas fa-circle c-p"></i> Asistencias</span>
            <span><i class="fas fa-circle c-r"></i> Retardos</span>
            <span><i class="fas fa-circle c-f"></i> Faltas</span>
            <span><i class="fas fa-circle c-j"></i> Justificadas (con justificante de Prefectura)</span>
            <span><b>%</b> = asistencias + retardos + justificadas entre los pases de lista</span>
        </p>

        <div class="tabla-wrap">
        <table class="table-entregas tabla-asistencia">
            <thead>
                <tr>
                    <th>Alumno</th>
                    <th class="num">Asist.</th>
                    <th class="num">Retardos</th>
                    <th class="num">Faltas</th>
                    <th class="num" title="Faltas con justificante de Prefectura">Just.</th>
                    <th class="num" title="Asistencia (presente + retardo + justificada)">%</th>
                </tr>
            </thead>
            <tbody id="cuerpo_tabla">
                <?php echo reporte_asistencia_tbody($filas, $periodo['texto']); ?>
            </tbody>
        </table>
        </div>
    </main>

    <script>
        function toggleFiltros() {
            const tipo = document.getElementById('tipo_filtro').value;
            document.getElementById('div_fechas_ini').hidden = tipo !== 'parcial';
            document.getElementById('div_fechas_fin').hidden = tipo !== 'parcial';
            document.getElementById('div_mes').hidden = tipo !== 'mes';
        }
        document.getElementById('tipo_filtro').addEventListener('change', toggleFiltros);
        toggleFiltros();

        function actualizarVistaPrevia() {
            const f = document.getElementById('form_filtros');
            if (f.tipo_filtro.value === 'parcial' && (!f.fecha_inicio.value || !f.fecha_fin.value)) { alert('Elige la fecha de inicio y la de fin.'); return; }
            const tbody = document.getElementById('cuerpo_tabla');
            tbody.innerHTML = '<tr><td colspan="6" class="vacio" data-label=""><i class="fas fa-spinner fa-spin"></i> Calculando asistencias...</td></tr>';
            const qs = new URLSearchParams(new FormData(f)).toString();
            fetch('procesos/obtener_tabla_asistencia_ajax.php?' + qs)
                .then(r => r.json())
                .then(d => {
                    tbody.innerHTML = d.tbody;
                    document.getElementById('textoPeriodo').textContent = d.periodo;
                    Object.entries(d.resumen).forEach(([k, v]) => {
                        const el = document.querySelector(`[data-r="${k}"]`);
                        if (el) el.textContent = (k === 'promedio') ? (v === null ? '—' : v + '%') : v;
                    });
                    history.replaceState(null, '', 'lista_alumnos.php?' + qs);
                })
                .catch(() => { tbody.innerHTML = '<tr><td colspan="6" class="vacio" data-label="">No se pudieron cargar los datos. Intenta de nuevo.</td></tr>'; });
        }
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
