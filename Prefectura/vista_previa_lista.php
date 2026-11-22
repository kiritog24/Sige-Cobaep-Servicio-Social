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
        al.nombre, 
        MIN(a.fecha_hora_escaneo) as fecha_hora_escaneo,
        SUBSTRING_INDEX(GROUP_CONCAT(a.estatus ORDER BY a.fecha_hora_escaneo ASC), ',', 1) as estatus
    FROM grupo_alumno ga
    INNER JOIN alumnos al ON ga.id_alumno = al.id_alumno
    LEFT JOIN asistencias a ON al.id_alumno = a.id_alumno AND DATE(a.fecha_hora_escaneo) = :fecha AND a.tipo_registro = 'Entrada'
    WHERE ga.id_grupo = :id_grupo
      AND ga.fecha_asignacion <= :fecha2
      AND (ga.fecha_baja IS NULL OR ga.fecha_baja > :fecha3)
    GROUP BY al.id_alumno, al.matricula, al.apellido_paterno, al.nombre
    ORDER BY al.apellido_paterno ASC, al.nombre ASC
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
            $filas_x[] = [$n + 1, $al['matricula'], mb_strtoupper($al['apellido_paterno'] . ' ' . $al['nombre'], 'UTF-8'),
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
        $cuerpo .= '<tr><td class="c">' . ($n + 1) . '</td><td class="c">' . $e($al['matricula']) . '</td><td>' . $e(mb_strtoupper($al['apellido_paterno'] . ' ' . $al['nombre'], 'UTF-8')) . '</td>'
            . '<td class="c">' . ($al['fecha_hora_escaneo'] ? date('h:i a', strtotime($al['fecha_hora_escaneo'])) : '—') . '</td><td class="c">' . $e($al['estatus'] ?: ($sin_clases ? 'Sin clases' : 'Sin registro')) . '</td>'
            . '<td class="c" style="font-weight:bold;color:' . $color . '">' . $m . '</td></tr>';
    }
    if (!$lista_alumnos) $cuerpo .= '<tr><td colspan="6" class="c">No hay alumnos en este grupo.</td></tr>';
    $cuerpo .= '</tbody></table><p class="nota">1 = asistió · 0 = falta · * = justificada · - = suspendido · SC = sin clases</p>'
        . '<br><br><table style="width:60%;margin:0 auto"><tr><td style="border:none;border-top:1px solid #333;text-align:center;padding-top:4px">Prefectura · Firma y sello</td></tr></table>';
    pdf_descargar($nombre_desc . '.pdf', $titulo_lista, 'Fecha: ' . $fecha_legible . ($sin_clases ? ' · Sin clases (' . $sin_clases . ')' : ''), $cuerpo, 'portrait');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Etiqueta crucial para responsividad -->
    <title>Lista Provisoria Asistencia - SIGE COBAEP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link rel="stylesheet" href="css/vista_previa_lista.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>

<body>
    <div class="container">
        <div class="header-actions">
            <div class="header-title">
                <h2>Vista Previa de Lista</h2>
                <p>Formato de Asistencia Provisional</p>
            </div>
            <div class="btn-group">
                <a href="monitoreo_grupos.php" class="back-btn"><i class="fas fa-arrow-left"></i> Regresar</a>
                <?php $qs_l = http_build_query(['fecha_lista' => $fecha_lista, 'id_grupo' => $id_grupo_seleccionado]); ?>
                <a href="?<?php echo htmlspecialchars($qs_l); ?>&amp;formato=xlsx" class="export-btn"><i class="fas fa-file-excel"></i> Descargar Excel</a>
                <a href="?<?php echo htmlspecialchars($qs_l); ?>&amp;formato=pdf" class="export-btn" style="background:#b91c1c;"><i class="fas fa-file-pdf"></i> Descargar PDF</a>
            </div>
        </div>

        <div class="preview-table-container">
            <?php $total_columnas = 4; ?>
            <!-- Los estilos en línea se mantienen limpios para que el Excel salga formal -->
            <table id="tablaAsistencia" border="1" style="font-family: Arial, sans-serif; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr>
                        <th colspan="<?php echo $total_columnas; ?>" style="text-align: left; font-size: 16px; color: #1b4332; font-weight: bold; padding: 5px; border-bottom: none; border: 1px solid #000;">
                            COBAEP PLANTEL 27 ZARAGOZA, PUE.
                        </th>
                    </tr>
                    
                    <tr>
                        <th colspan="<?php echo $total_columnas; ?>" style="text-align: left; font-size: 14px; font-weight: bold; padding: 5px; border: 1px solid #000;">
                            LISTA DE ASISTENCIA - SEMESTRE: <?php echo htmlspecialchars($semestre_titulo); ?>° GRUPO: "<?php echo htmlspecialchars($grupo_titulo); ?>"
                        </th>
                    </tr>
                    
                    <tr>
                        <th colspan="<?php echo $total_columnas; ?>" style="text-align: left; font-size: 12px; font-weight: normal; padding: 5px; color: #555; border: 1px solid #000;">
                            FECHA DEL REPORTE: <?php echo $fecha_legible; ?><?php if ($sin_clases): ?> · <b style="color:#475569;">SIN CLASES (<?php echo htmlspecialchars(mb_strtoupper($sin_clases, 'UTF-8')); ?>)</b><?php endif; ?>
                        </th>
                    </tr>
                    
                    <tr>
                        <th style="background-color: #e9ecef; border: 1px solid #000000; text-align: center; padding: 8px; width: 40px; font-weight: bold;">NO.</th>
                        <th style="background-color: #e9ecef; border: 1px solid #000000; text-align: center; padding: 8px; width: 120px; font-weight: bold;">MATRÍCULA</th>
                        <th style="background-color: #e9ecef; border: 1px solid #000000; text-align: left; padding: 8px; width: 350px; font-weight: bold;">NOMBRE DEL ALUMNO</th>
                        <th style="background-color: #e9ecef; border: 1px solid #000000; text-align: center; padding: 8px; width: 100px; font-weight: bold;">ASIST.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lista_alumnos)): ?>
                        <tr>
                            <td colspan="4" style="border: 1px solid #000000; text-align: center; padding: 20px;">No hay alumnos registrados.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $contador = 1;
                        foreach ($lista_alumnos as $alumno): 
                            $estado_str = strtolower($alumno['estatus'] ?? '');
                            $marca_asistencia = '0';
                            $color_texto = '#000000';

                            // LÓGICA DE MARCADORES (1, 0, *)
                            if ((empty($estado_str) || $estado_str === 'falta') && $sin_clases) {
                                $marca_asistencia = 'SC';
                                $color_texto = '#6c757d'; // Día sin clases: no es falta
                            } elseif (empty($estado_str) || $estado_str === 'falta') {
                                $marca_asistencia = '0';
                                $color_texto = '#dc3545'; // Rojo sobrio
                            } elseif (strpos($estado_str, 'justificad') !== false || strpos($estado_str, 'permiso') !== false) {
                                $marca_asistencia = '*'; 
                                $color_texto = '#0d6efd'; // Azul sobrio
                            } elseif ($estado_str === 'suspendido') {
                                $marca_asistencia = '-'; 
                                $color_texto = '#6c757d'; // Gris
                            } else {
                                $marca_asistencia = '1'; 
                                $color_texto = '#000000'; // Negro normal
                            }
                            
                            $nombre_completo = mb_strtoupper(htmlspecialchars($alumno['apellido_paterno'] . ' ' . $alumno['nombre']), 'UTF-8');
                        ?>
                        <tr>
                            <td style="border: 1px solid #000000; text-align: center; padding: 5px;"><?php echo $contador++; ?></td>
                            <td style="border: 1px solid #000000; text-align: center; padding: 5px;"><?php echo htmlspecialchars($alumno['matricula']); ?></td>
                            <td style="border: 1px solid #000000; text-align: left; padding: 5px;"><?php echo $nombre_completo; ?></td>
                            <td style="border: 1px solid #000000; text-align: center; padding: 5px; font-weight: bold; font-size: 14px; color: <?php echo $color_texto; ?>;">
                                <?php echo $marca_asistencia; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <script src="../js/responsive.js?v=10"></script>
</body>
</html>