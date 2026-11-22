<?php
// Hora de Puebla para todo el sistema (XAMPP trae Europe/Berlin por defecto)
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/fotos.php';
require_once __DIR__ . '/../includes/bitacora.php';
// config/database.php

$host     = '127.0.0.1';
$port     = '3306';              // Puerto MySQL de MAMP
$dbname   = 'sistema_sige_cobaep';
$username = 'root';
$password = '';              // MAMP usa root por defecto

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Conexión BD: " . $e->getMessage());
    http_response_code(500);
    die("No fue posible conectar con la base de datos. Intenta más tarde.");
}

// Alumno con contraseña temporal: debe cambiarla antes de usar el sistema
exigir_cambio_password($pdo);
