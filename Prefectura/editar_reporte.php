<?php
session_start();
require_once '../config/database.php'; 

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}

$id_reporte = $_GET['id'] ?? '';
$return_to = $_GET['return_to'] ?? '';
$mat = $_GET['mat'] ?? '';

if (empty($id_reporte)) {
    header("Location: creacion_reportes.php");
    exit();
}

// Obtener datos del reporte
$stmt = $pdo->prepare("
    SELECT r.*, a.matricula, a.nombre, a.apellido_paterno 
    FROM reportes r
    INNER JOIN alumnos a ON r.id_alumno = a.id_alumno
    WHERE r.id_reporte = ?
");
$stmt->execute([$id_reporte]);
$reporte = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporte) {
    die("Reporte no encontrado.");
}

// Obtener catálogo de faltas
$stmt_faltas = $pdo->query("SELECT id_tipo_falta, numero_reglamento, descripcion_falta FROM tipos_falta ORDER BY numero_reglamento ASC");
$tipos_falta = $stmt_faltas->fetchAll(PDO::FETCH_ASSOC);

$url_regreso = ($return_to === 'expediente') ? "expediente_alumnos.php?matricula=" . htmlspecialchars($mat) : "creacion_reportes.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <title>Editar Reporte · COBAEP 27</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/editar_reporte.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body>
    <div class="edit-container">
        <div class="edit-header">
            <i class="fas fa-edit" style="font-size: 1.8rem; color: #059669;"></i>
            <h2>Modificar Reporte</h2>
        </div>
        
        <div class="form-group">
            <label>Alumno Sancionado:</label>
            <div class="student-badge">
                <strong><?php echo htmlspecialchars($reporte['matricula']); ?></strong> - 
                <?php echo htmlspecialchars($reporte['apellido_paterno'] . ' ' . $reporte['nombre']); ?>
            </div>
        </div>

        <form action="procesos/actualizar_reporte.php" method="POST">
            <input type="hidden" name="id_reporte" value="<?php echo $id_reporte; ?>">
            <input type="hidden" name="id_alumno" value="<?php echo $reporte['id_alumno']; ?>">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($url_regreso); ?>">

            <div class="form-group">
                <label>Falta Cometida:</label>
                <select name="id_tipo_falta" class="form-control" required>
                    <?php foreach ($tipos_falta as $falta): ?>
                        <option value="<?php echo $falta['id_tipo_falta']; ?>" <?php echo ($falta['id_tipo_falta'] == $reporte['id_tipo_falta']) ? 'selected' : ''; ?>>
                            Regla <?php echo $falta['numero_reglamento']; ?>: <?php echo htmlspecialchars(mb_strimwidth($falta['descripcion_falta'], 0, 80, '...')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Observaciones / Detalles:</label>
                <textarea name="observaciones" class="form-control" style="min-height: 120px; resize: vertical;"><?php echo htmlspecialchars($reporte['observaciones'] ?? ''); ?></textarea>
            </div>

            <div class="btn-container">
                <a href="<?php echo htmlspecialchars($url_regreso); ?>" class="btn btn-cancel">Cancelar</a>
                <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Guardar Cambios</button>
            </div>
        </form>
    </div>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>