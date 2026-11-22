<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') { exit(); }

$id_clase = isset($_GET['id_clase']) ? (int)$_GET['id_clase'] : 0;
require_once __DIR__ . '/permisos.php';
if ($id_clase > 0 && !docente_dueno_clase($pdo, $id_clase)) { http_response_code(403); exit('Acceso denegado.'); }
if ($id_clase === 0) exit("Clase no válida.");

// Recibir Filtros
$tipo_filtro = isset($_GET['tipo_filtro']) ? $_GET['tipo_filtro'] : 'semestre';
$mes_filtro = isset($_GET['mes']) ? $_GET['mes'] : '';
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';

// 1. Info de la materia
$stmt = $pdo->prepare("SELECT m.nombre_materia, g.nombre_grupo, g.semestre FROM clases_regulares c INNER JOIN materias m ON c.id_materia = m.id_materia LEFT JOIN grupos g ON c.id_grupo = g.id_grupo WHERE c.id_clase = ?");
$stmt->execute([$id_clase]);
$clase = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Construir la consulta según el filtro elegido
$where_fecha = "";
$params = [$id_clase];
// Semestre escolar actual (Ago–Ene o Feb–Jul)
$mes_hoy = (int)date('n'); $anio_hoy = (int)date('Y');
if ($mes_hoy >= 8)      { $sem_ini = "$anio_hoy-08-01"; $sem_fin = ($anio_hoy + 1) . "-01-31"; $sem_txt = "Ago $anio_hoy – Ene " . ($anio_hoy + 1); }
elseif ($mes_hoy === 1) { $sem_ini = ($anio_hoy - 1) . "-08-01"; $sem_fin = "$anio_hoy-01-31"; $sem_txt = "Ago " . ($anio_hoy - 1) . " – Ene $anio_hoy"; }
else                    { $sem_ini = "$anio_hoy-02-01"; $sem_fin = "$anio_hoy-07-31"; $sem_txt = "Feb – Jul $anio_hoy"; }
$where_fecha = " AND fecha BETWEEN ? AND ?";
$params = [$id_clase, $sem_ini, $sem_fin];
$titulo_periodo = "Periodo: semestre $sem_txt";
$nombre_archivo = "Semestre";

if ($tipo_filtro === 'mes' && !empty($mes_filtro) && is_numeric($mes_filtro) && $mes_filtro >= 1 && $mes_filtro <= 12) {
    $where_fecha = " AND MONTH(fecha) = ? AND fecha BETWEEN ? AND ?";
    $params = [$id_clase, (int)$mes_filtro, $sem_ini, $sem_fin];
    $titulo_periodo = "Corte del Mes: " . $mes_filtro;
    $nombre_archivo = "Mes_" . $mes_filtro;
} elseif ($tipo_filtro === 'parcial' && strtotime($fecha_inicio) && strtotime($fecha_fin)) {
    $where_fecha = " AND fecha BETWEEN ? AND ?";
    $params = [$id_clase, date('Y-m-d', strtotime($fecha_inicio)), date('Y-m-d', strtotime($fecha_fin))];
    $titulo_periodo = "PERIODO (Parcial): " . date('d/m/Y', strtotime($fecha_inicio)) . " AL " . date('d/m/Y', strtotime($fecha_fin));
    $nombre_archivo = "Parcial";
}

// 3. Traer los días exactos donde se pasó lista
$stmtFechas = $pdo->prepare("SELECT DISTINCT fecha FROM asistencias_clases WHERE id_clase = ? $where_fecha ORDER BY fecha ASC");
$stmtFechas->execute($params);
$fechas = $stmtFechas->fetchAll(PDO::FETCH_COLUMN);

// 4. Obtener Alumnos
$stmtAlumnos = $pdo->prepare("SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno FROM clase_regular_alumno cra INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno WHERE cra.id_clase = ? ORDER BY a.apellido_paterno ASC");
$stmtAlumnos->execute([$id_clase]);
$alumnos = $stmtAlumnos->fetchAll(PDO::FETCH_ASSOC);

// 5. Cruzar todas las asistencias
$stmtAsist = $pdo->prepare("SELECT id_alumno, fecha, estatus FROM asistencias_clases WHERE id_clase = ? $where_fecha");
$stmtAsist->execute($params);
$asistencias = [];
while($row = $stmtAsist->fetch(PDO::FETCH_ASSOC)){
    $asistencias[$row['id_alumno']][$row['fecha']] = $row['estatus'];
}

// 6. Archivo Excel real (.xlsx)
require_once __DIR__ . '/../../includes/xlsx_simple.php';
$encabezados = ['No.', 'Matrícula', 'Nombre del alumno'];
foreach ($fechas as $f) $encabezados[] = date('d/m', strtotime($f));
array_push($encabezados, 'Asist.', 'Ret.', 'Faltas');
$filas_x = [];
foreach ($alumnos as $n => $alum) {
    $fila = [$n + 1, $alum['matricula'], trim($alum['apellido_paterno'] . ' ' . $alum['nombre'])];
    $tp = $tr = $tf = 0;
    foreach ($fechas as $f) {
        $est = $asistencias[$alum['id_alumno']][$f] ?? '';
        if ($est === 'Presente' || $est === 'Asistencia') { $fila[] = 'P'; $tp++; }
        elseif ($est === 'Retardo') { $fila[] = 'R'; $tr++; }
        elseif ($est === 'Falta' || $est === 'Ausente') { $fila[] = 'F'; $tf++; }
        else $fila[] = '';
    }
    array_push($fila, $tp, $tr, $tf);
    $filas_x[] = $fila;
}
$anchos = array_merge([6, 16, 36], array_fill(0, count($fechas), 7), [8, 8, 8]);
$nombre_materia = $clase['nombre_materia'] ?? 'Materia';
xlsx_descargar('Asistencia_' . $nombre_materia . '_' . $nombre_archivo . '.xlsx', 'Asistencia',
    ['COBAEP Plantel 27 Zaragoza, Pue. · Reporte de asistencia',
     mb_strtoupper($nombre_materia, 'UTF-8') . ' (' . ($clase['semestre'] ?? '') . '° "' . ($clase['nombre_grupo'] ?? '') . '") · ' . $titulo_periodo . ' · P = asistencia, R = retardo, F = falta'],
    $encabezados, $filas_x, $anchos);
