<?php
/**
 * includes/configuracion.php — Ajustes del sistema que Administración cambia desde la pantalla
 * (tabla CONFIGURACION). Los datos sensibles (contraseñas) se guardan cifrados con una llave propia
 * de esta instalación (config/clave_app.php, se crea sola y está bloqueada para el navegador).
 */

function config_tabla(PDO $pdo): void {
    static $lista = false;
    if ($lista) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS CONFIGURACION (
        clave VARCHAR(60) PRIMARY KEY,
        valor TEXT NULL,
        actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        id_admin INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $lista = true;
}

function config_obtener(PDO $pdo, string $clave, $defecto = null) {
    try {
        // Solo lectura (sin CREATE TABLE): no debe cerrar una transacción abierta
        $st = $pdo->prepare("SELECT valor FROM CONFIGURACION WHERE clave = ?");
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) return $defecto;
        $d = json_decode($v, true);
        return json_last_error() === JSON_ERROR_NONE ? $d : $v;
    } catch (Throwable $e) {
        error_log('config_obtener: ' . $e->getMessage());
        return $defecto;
    }
}

function config_guardar(PDO $pdo, string $clave, $valor): void {
    config_tabla($pdo);
    $pdo->prepare("INSERT INTO CONFIGURACION (clave, valor, id_admin) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor), id_admin = VALUES(id_admin)")
        ->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE), isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : null]);
}

/** Llave de cifrado de esta instalación (32 bytes). Se genera la primera vez. */
function config_llave(): string {
    $archivo = __DIR__ . '/../config/clave_app.php';
    if (is_file($archivo)) {
        $k = require $archivo;
        if (is_string($k) && strlen(base64_decode($k)) === 32) return base64_decode($k);
    }
    $k = random_bytes(32);
    @file_put_contents($archivo, "<?php\n// Llave de cifrado de esta instalación (generada automáticamente). No la compartas ni la borres:\n// sin ella habría que volver a escribir las contraseñas guardadas en Administración.\nreturn '" . base64_encode($k) . "';\n");
    return $k;
}

function config_cifrar(string $texto): string {
    if ($texto === '') return '';
    $iv = random_bytes(12);
    $c = openssl_encrypt($texto, 'aes-256-gcm', config_llave(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}

function config_descifrar(string $dato): string {
    if ($dato === '' || !str_starts_with($dato, 'v1:')) return '';
    $b = base64_decode(substr($dato, 3));
    if (strlen($b) < 29) return '';
    $t = openssl_decrypt(substr($b, 28), 'aes-256-gcm', config_llave(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $t === false ? '' : $t;
}
