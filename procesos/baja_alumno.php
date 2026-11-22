<?php
session_start();
require_once '../config/database.php';
exigir_mismo_origen(); // evita que un enlace externo dispare la acción

// Verificamos que sea un administrador
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

// Verificamos que lleguen los IDs por la URL
if (isset($_GET['id']) && isset($_GET['grupo'])) {
    $id_alumno = intval($_GET['id']);
    $id_grupo = intval($_GET['grupo']);

    try {
        // Baja del grupo conservando el historial (la fecha de baja limita su asistencia en el concentrado)
        $stmt = $pdo->prepare("UPDATE GRUPO_ALUMNO SET fecha_baja = CURDATE() WHERE id_alumno = ? AND id_grupo = ? AND fecha_baja IS NULL");
        $stmt->execute([$id_alumno, $id_grupo]);
        bitacora($pdo, 'grupos', 'baja_grupo', 'Dio de baja a ' . bitacora_quien($pdo, 'alumnos', $id_alumno) . ' del grupo ' . bitacora_quien($pdo, 'grupo', $id_grupo), 'alumno', $id_alumno);

        // ==========================================
        // AQUÍ ESTÁ LA CLAVE DEL PROBLEMA
        // ==========================================
        // Ajusta el nombre "ver_grupo_2.php" exactamente al nombre
        // del archivo donde ves tu lista de alumnos. 
        // Si tu archivo se llama "ver_grupo.php", cámbialo aquí abajo:
        
        $nombre_archivo_vista = "ver_grupo.php"; // <-- REVISA ESTE NOMBRE
        
        header("Location: ../admin/" . $nombre_archivo_vista . "?id=" . $id_grupo . "&msg=baja_ok");
        exit();

    } catch (PDOException $e) {
        die("Error en la base de datos: " . $e->getMessage());
    }
} else {
    die("Error: Faltan datos (ID de alumno o grupo) en la URL.");
}
?>