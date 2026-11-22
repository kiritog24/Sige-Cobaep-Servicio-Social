<?php
// Solo se puede ejecutar desde la terminal del servidor (nunca desde el navegador)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once 'config/database.php';

if ($pdo) {
    echo "<h2 style='color: green;'>¡Conexión exitosa a la base de datos Sistema SIGE-COBAEP!</h2>";
}
?>