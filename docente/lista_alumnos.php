<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

$id_clase = isset($_GET['id_clase']) ? (int)$_GET['id_clase'] : 0;
if ($id_clase === 0) { header("Location: mis_grupos.php"); exit(); }

try {
    $stmt = $pdo->prepare("SELECT m.nombre_materia, g.nombre_grupo, g.semestre FROM clases_regulares c INNER JOIN materias m ON c.id_materia = m.id_materia LEFT JOIN grupos g ON c.id_grupo = g.id_grupo WHERE c.id_clase = ?");
    $stmt->execute([$id_clase]);
    $clase = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmtAlumnos = $pdo->prepare("
        SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno,
            SUM(CASE WHEN ac.estatus IN ('Presente', 'Asistencia') THEN 1 ELSE 0 END) as presentes,
            SUM(CASE WHEN ac.estatus = 'Retardo' THEN 1 ELSE 0 END) as retardos,
            SUM(CASE WHEN ac.estatus IN ('Falta', 'Ausente') THEN 1 ELSE 0 END) as faltas
        FROM clase_regular_alumno cra
        INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno
        LEFT JOIN asistencias_clases ac ON a.id_alumno = ac.id_alumno AND ac.id_clase = cra.id_clase
        WHERE cra.id_clase = ?
        GROUP BY a.id_alumno
        ORDER BY a.apellido_paterno ASC
    ");
    $stmtAlumnos->execute([$id_clase]);
    $alumnos = $stmtAlumnos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) { 
    die("Error al cargar reporte: " . $e->getMessage()); 
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Asistencia - SIGE COBAEP</title>
    <link rel="stylesheet" href="css/lista_alumnos.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Reporte General de Asistencia</h1>
                <p><?php echo htmlspecialchars($clase['nombre_materia']); ?> | Grupo: <?php echo htmlspecialchars($clase['semestre']) . '° "' . htmlspecialchars($clase['nombre_grupo']) . '"'; ?></p>
            </div>
            <a href="gestionar_aula.php?id_clase=<?php echo $id_clase; ?>" class="btn-volver">Regresar al Aula</a>
        </div>

        <div class="filter-panel">
            <h3>Filtros y Exportación de Sábana (Excel)</h3>
            <form id="form_filtros" action="procesos/descargar_asistencia_excel.php" method="GET" class="form-row">
                <input type="hidden" name="id_clase" id="id_clase_input" value="<?php echo $id_clase; ?>">
                
                <div class="form-group">
                    <label>Tipo de Corte</label>
                    <select name="tipo_filtro" id="tipo_filtro" class="form-control" onchange="toggleFiltros()">
                        <option value="semestre">Semestre Completo</option>
                        <option value="parcial">Por Parcial (Fechas)</option>
                        <option value="mes">Por Mes Específico</option>
                    </select>
                </div>

                <!-- Filtro por Fechas -->
                <div class="form-group" id="div_fechas" style="display: none;">
                    <label>Desde - Hasta</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control">
                        <input type="date" name="fecha_fin" id="fecha_fin" class="form-control">
                    </div>
                </div>

                <!-- Filtro por Mes -->
                <div class="form-group" id="div_mes" style="display: none;">
                    <label>Mes</label>
                    <select name="mes" id="mes" class="form-control">
                        <option value="01">Enero</option>
                        <option value="02">Febrero</option>
                        <option value="03">Marzo</option>
                        <option value="04">Abril</option>
                        <option value="05">Mayo</option>
                        <option value="06">Junio</option>
                        <option value="07">Julio</option>
                        <option value="08">Agosto</option>
                        <option value="09">Septiembre</option>
                        <option value="10">Octubre</option>
                        <option value="11">Noviembre</option>
                        <option value="12">Diciembre</option>
                    </select>
                </div>

                <div class="form-group" style="display: flex; gap: 10px;">
                    <!-- Botón para actualizar la vista previa en pantalla -->
                    <button type="button" class="btn-excel" style="background: #0d6efd;" onclick="actualizarVistaPrevia()">
                        <i class="fas fa-sync-alt"></i> Actualizar Tabla
                    </button>
                    <!-- Botón para descargar el Excel real -->
                    <button type="submit" class="btn-excel">
                        <i class="fas fa-file-excel"></i> Descargar XLS
                    </button>
                </div>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 15%;">Matrícula</th>
                    <th style="width: 55%;">Nombre del Alumno</th>
                    <th class="text-center">Asist.</th>
                    <th class="text-center">Retardos</th>
                    <th class="text-center">Faltas</th>
                    <th class="text-center" title="Faltas con justificante de Prefectura">Just.</th>
                    <th class="text-center" title="Asistencia (presente + retardo + justificada)">%</th>
                </tr>
            </thead>
            <tbody id="cuerpo_tabla">
                <!-- Los datos se cargarán aquí por defecto o vía AJAX -->
                <?php foreach($alumnos as $alum): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($alum['matricula']); ?></td>
                        <td><a href="perfil_alumno.php?id=<?php echo (int)$alum['id_alumno']; ?>" style="color:inherit; font-weight:600;" title="Ver perfil"><?php echo htmlspecialchars($alum['apellido_paterno'] . ' ' . $alum['nombre']); ?></a></td>
                        <td class="text-center val-p"><?php echo $alum['presentes']; ?></td>
                        <td class="text-center val-r"><?php echo $alum['retardos']; ?></td>
                        <td class="text-center val-f"><?php echo $alum['faltas']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
   <script>
        // Función 1: Muestra u oculta los campos de fecha o mes según lo que elija el maestro
        function toggleFiltros() {
            var tipo = document.getElementById("tipo_filtro").value;
            document.getElementById("div_fechas").style.display = (tipo === 'parcial') ? 'block' : 'none';
            document.getElementById("div_mes").style.display = (tipo === 'mes') ? 'block' : 'none';
        }

        // Función 2: Actualiza la tabla dinámicamente usando AJAX (sin recargar la página)
        function actualizarVistaPrevia() {
            const id_clase = document.getElementById('id_clase_input').value;
            const tipo = document.getElementById('tipo_filtro').value;
            const mes = document.getElementById('mes').value;
            const f_inicio = document.getElementById('fecha_inicio').value;
            const f_fin = document.getElementById('fecha_fin').value;
            
            const tbody = document.getElementById('cuerpo_tabla');
            tbody.innerHTML = "<tr><td colspan='7' class='text-center'><i class='fas fa-spinner fa-spin'></i> Calculando asistencias...</td></tr>";

            // Construir la URL con los parámetros
            const url = `procesos/obtener_tabla_asistencia_ajax.php?id_clase=${id_clase}&tipo_filtro=${tipo}&mes=${mes}&fecha_inicio=${f_inicio}&fecha_fin=${f_fin}`;

            fetch(url)
                .then(response => response.text())
                .then(html => {
                    tbody.innerHTML = html;
                })
                .catch(error => {
                    tbody.innerHTML = "<tr><td colspan='7' class='text-center' style='color:red;'>Error al cargar los datos.</td></tr>";
                });
        }
    </script>
</body>
</html>
</body>
</html>

 
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>