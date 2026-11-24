<?php
session_start();
require_once '../config/database.php'; 
require_once __DIR__ . '/../includes/calendario.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}

// Recibir los parámetros del formulario
$fecha_lista = $_GET['fecha_lista'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_lista) || !strtotime($fecha_lista)) $fecha_lista = date('Y-m-d');
$id_grupo_seleccionado = (int)($_GET['id_grupo'] ?? 0);

// Convertir fecha a formato legible
$fecha_legible = date('d/m/Y', strtotime($fecha_lista));
// En un día sin clases (fin de semana o suspensión) nadie tiene falta: se marca SC
$sin_clases = dia_sin_clases($pdo, $fecha_lista);

// 1. Obtener los datos del grupo seleccionado (Semestre y Nombre) para los títulos
$semestre_titulo = "N/A";
$grupo_titulo = "N/A";
try {
    $stmt_info = $pdo->prepare("SELECT semestre, nombre_grupo FROM grupos WHERE id_grupo = :id");
    $stmt_info->execute([':id' => $id_grupo_seleccionado]);
    if ($info = $stmt_info->fetch(PDO::FETCH_ASSOC)) {
        $semestre_titulo = $info['semestre'];
        $grupo_titulo = $info['nombre_grupo'];
    }
} catch (PDOException $e) {}

// 2. Consulta PRINCIPAL usando grupo_alumno como puente
$query = "
    SELECT 
        al.matricula, 
        al.apellido_paterno, 
        al.apellido_materno,
        al.nombre, 
        MIN(a.fecha_hora_escaneo) as fecha_hora_escaneo,
        SUBSTRING_INDEX(GROUP_CONCAT(a.estatus ORDER BY a.fecha_hora_escaneo ASC), ',', 1) as estatus
    FROM grupo_alumno ga
    INNER JOIN alumnos al ON ga.id_alumno = al.id_alumno
    LEFT JOIN asistencias a ON al.id_alumno = a.id_alumno AND DATE(a.fecha_hora_escaneo) = :fecha AND a.tipo_registro = 'Entrada'
    WHERE ga.id_grupo = :id_grupo
      AND ga.fecha_asignacion <= :fecha2
      AND (ga.fecha_baja IS NULL OR ga.fecha_baja > :fecha3)
    GROUP BY al.id_alumno, al.matricula, al.apellido_paterno, al.apellido_materno, al.nombre
    ORDER BY al.apellido_paterno ASC, al.apellido_materno ASC, al.nombre ASC
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':fecha' => $fecha_lista,
        ':fecha2' => $fecha_lista,
        ':fecha3' => $fecha_lista,
        ':id_grupo' => $id_grupo_seleccionado
    ]);
    $lista_alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $lista_alumnos = [];
}

// ---------- Descargas reales: PDF (Dompdf) y Excel (.xlsx) ----------
$formato = $_GET['formato'] ?? '';
if (in_array($formato, ['pdf', 'xlsx'], true)) {
    $marca = function (?string $e) use ($sin_clases): string {
        $e = mb_strtolower(trim((string)$e), 'UTF-8');
        if ($e === '' || $e === 'falta') return $sin_clases ? 'SC' : '0';
        if (strpos($e, 'justificad') !== false) return '*';
        if ($e === 'suspendido') return '-';
        return '1';
    };
    $titulo_lista = 'Lista de asistencia · ' . $semestre_titulo . '° "' . $grupo_titulo . '"';
    $nombre_desc = 'Lista_Asistencia_' . preg_replace('/[^A-Za-z0-9]/', '', $semestre_titulo . $grupo_titulo) . '_' . $fecha_lista;
    if ($formato === 'xlsx') {
        require_once __DIR__ . '/../includes/xlsx_simple.php';
        $filas_x = [];
        foreach ($lista_alumnos as $n => $al) {
            $filas_x[] = [$n + 1, $al['matricula'], mb_strtoupper(trim($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ' ' . $al['nombre']), 'UTF-8'),
                          $al['fecha_hora_escaneo'] ? date('h:i a', strtotime($al['fecha_hora_escaneo'])) : '', $al['estatus'] ?: ($sin_clases ? 'Sin clases' : 'Sin registro'), $marca($al['estatus'])];
        }
        xlsx_descargar($nombre_desc . '.xlsx', 'Lista', ['COBAEP Plantel 27 · ' . $titulo_lista, 'Fecha: ' . $fecha_legible . ($sin_clases ? ' · SIN CLASES (' . $sin_clases . ')' : '') . ' · 1 = asistió, 0 = falta, * = justificada, - = suspendido, SC = sin clases'],
            ['No.', 'Matrícula', 'Nombre del alumno', 'Hora de entrada', 'Estatus', 'Marca'], $filas_x, [6, 16, 40, 14, 18, 8]);
    }
    require_once __DIR__ . '/../includes/pdf_documento.php';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $cuerpo = '<table><thead><tr><th style="width:6%">No.</th><th style="width:16%">Matrícula</th><th>Nombre del alumno</th><th style="width:13%">Entrada</th><th style="width:16%">Estatus</th><th style="width:8%">Marca</th></tr></thead><tbody>';
    foreach ($lista_alumnos as $n => $al) {
        $m = $marca($al['estatus']);
        $color = $m === '0' ? '#b91c1c' : ($m === '*' ? '#1d4ed8' : '#0f172a');
        $cuerpo .= '<tr><td class="c">' . ($n + 1) . '</td><td class="c">' . $e($al['matricula']) . '</td><td>' . $e(mb_strtoupper(trim($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ' ' . $al['nombre']), 'UTF-8')) . '</td>'
            . '<td class="c">' . ($al['fecha_hora_escaneo'] ? date('h:i a', strtotime($al['fecha_hora_escaneo'])) : '—') . '</td><td class="c">' . $e($al['estatus'] ?: ($sin_clases ? 'Sin clases' : 'Sin registro')) . '</td>'
            . '<td class="c" style="font-weight:bold;color:' . $color . '">' . $m . '</td></tr>';
    }
    if (!$lista_alumnos) $cuerpo .= '<tr><td colspan="6" class="c">No hay alumnos en este grupo.</td></tr>';
    $cuerpo .= '</tbody></table><p class="nota">1 = asistió · 0 = falta · * = justificada · - = suspendido · SC = sin clases</p>'
        . '<br><br><table style="width:60%;margin:0 auto"><tr><td style="border:none;border-top:1px solid #333;text-align:center;padding-top:4px">Prefectura · Firma y sello</td></tr></table>';
    pdf_descargar($nombre_desc . '.pdf', $titulo_lista, 'Fecha: ' . $fecha_legible . ($sin_clases ? ' · Sin clases (' . $sin_clases . ')' : ''), $cuerpo, 'portrait');
}
?>
<?php
// Marca y resumen de la lista para la vista en pantalla
$marca_vista = function (?string $estatus) use ($sin_clases): array {
    $e = mb_strtolower(trim((string)$estatus), 'UTF-8');
    if (($e === '' || $e === 'falta') && $sin_clases) return ['SC', 'm-sc', 'Sin clases'];
    if ($e === '' || $e === 'falta')                  return ['0', 'm-falta', $e === '' ? 'Sin registro' : 'Falta'];
    if (strpos($e, 'justificad') !== false || strpos($e, 'permiso') !== false) return ['*', 'm-just', $estatus];
    if ($e === 'suspendido')                          return ['-', 'm-susp', 'Suspendido'];
    return ['1', strpos($e, 'retardo') !== false ? 'm-retardo' : 'm-ok', $estatus];
};
$conteo = ['1' => 0, '0' => 0, '*' => 0, '-' => 0, 'SC' => 0];
foreach ($lista_alumnos as $al) $conteo[$marca_vista($al['estatus'])[0]]++;
$qs_l = http_build_query(['fecha_lista' => $fecha_lista, 'id_grupo' => $id_grupo_seleccionado]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Asistencia · Prefectura</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/vista_previa_lista.css?v=2">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>
    <main class="vpl-main">
        <div class="vpl-banner">
            <div class="vpl-banner-texto">
                <span class="vpl-eyebrow"><i class="fas fa-users"></i> Grupo <?php echo htmlspecialchars($semestre_titulo . '° "' . $grupo_titulo . '"'); ?> · COBAEP Plantel 27</span>
                <h2>Lista de asistencia</h2>
                <span class="vpl-sub"><i class="far fa-calendar"></i> <?php echo $fecha_legible; ?><?php if ($sin_clases): ?> · <b>Sin clases (<?php echo htmlspecialchars($sin_clases); ?>)</b><?php endif; ?></span>
            </div>
            <div class="vpl-acciones">
                <a href="?<?php echo htmlspecialchars($qs_l); ?>&amp;formato=pdf" class="vpl-btn vpl-pdf"><i class="fas fa-file-pdf"></i> Descargar PDF</a>
                <a href="?<?php echo htmlspecialchars($qs_l); ?>&amp;formato=xlsx" class="vpl-btn vpl-excel"><i class="fas fa-file-excel"></i> Descargar Excel</a>
                <a href="monitoreo_grupos.php" class="vpl-btn vpl-volver"><i class="fas fa-arrow-left"></i> Regresar</a>
            </div>
        </div>

        <div class="vpl-resumen">
            <div><strong><?php echo count($lista_alumnos); ?></strong><span>Alumnos</span></div>
            <div class="r-ok"><strong><?php echo $conteo['1']; ?></strong><span>Asistieron</span></div>
            <div class="r-falta"><strong><?php echo $conteo['0']; ?></strong><span>Faltas / sin registro</span></div>
            <div class="r-just"><strong><?php echo $conteo['*'] + $conteo['-']; ?></strong><span>Justificados o suspendidos</span></div>
        </div>

        <p class="vpl-leyenda"><b>1</b> asistió · <b class="t-falta">0</b> falta · <b class="t-just">*</b> justificada · <b>-</b> suspendido · <b>SC</b> sin clases</p>

        <div class="vpl-tabla-wrap">
            <table class="vpl-tabla">
                <thead>
                    <tr>
                        <th class="c">No.</th>
                        <th>Alumno</th>
                        <th class="c">Entrada</th>
                        <th>Estatus</th>
                        <th class="c">Marca</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lista_alumnos)): ?>
                        <tr><td colspan="5" class="vacio" data-label="">No hay alumnos registrados en este grupo para esa fecha.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($lista_alumnos as $n => $alumno):
                        [$marca, $cls, $texto] = $marca_vista($alumno['estatus']); ?>
                        <tr>
                            <td class="c num" data-label="No."><?php echo $n + 1; ?></td>
                            <td data-label="" class="td-alumno"><strong><?php echo htmlspecialchars(mb_strtoupper(trim($alumno['apellido_paterno'] . ' ' . $alumno['apellido_materno'] . ' ' . $alumno['nombre']), 'UTF-8')); ?></strong><span>Mat: <?php echo htmlspecialchars($alumno['matricula']); ?></span></td>
                            <td class="c" data-label="Entrada"><?php echo $alumno['fecha_hora_escaneo'] && substr($alumno['fecha_hora_escaneo'], 11, 5) !== '00:00' ? date('h:i a', strtotime($alumno['fecha_hora_escaneo'])) : '—'; ?></td>
                            <td data-label="Estatus"><span class="estatus <?php echo $cls; ?>"><?php echo htmlspecialchars($texto); ?></span></td>
                            <td class="c" data-label="Marca"><span class="marca <?php echo $cls; ?>"><?php echo $marca; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
