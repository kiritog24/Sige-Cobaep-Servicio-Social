<?php
/**
 * includes/seguridad.php — Protecciones comunes de SIGE-COBAEP.
 * Se carga automáticamente desde config/database.php (todas las páginas y procesos lo usan).
 *
 *  - Encabezados de seguridad (clickjacking, sniffing de tipos, referer).
 *  - Protección CSRF por mismo origen: un POST que venga de otro sitio se rechaza.
 *  - exigir_mismo_origen(): para enlaces GET que borran o modifican datos.
 *  - extension_permitida(): lista blanca de archivos que se pueden subir.
 *  - Bloqueo temporal de inicio de sesión tras varios intentos fallidos.
 */

if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header_remove('X-Powered-By');
}

/* ---------- IP real del usuario ----------
 * Si el sistema se comparte con un túnel o proxy (ngrok, Cloudflare Tunnel, un proxy de Apache/Nginx...),
 * Apache ve la conexión del túnel (127.0.0.1 / ::1) y la IP real llega en un encabezado.
 * Solo se confía en esos encabezados cuando la conexión viene de la misma PC o de la red local
 * (donde corre el túnel); si alguien se conecta directo desde internet, se usa su IP tal cual,
 * así nadie puede inventarse una IP mandando el encabezado.
 */
function ip_es_local(string $ip): bool {
    if ($ip === '') return true;
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** ¿La petición llegó a través de un túnel o proxy? */
function peticion_por_proxy(): bool {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED'] as $h) {
        if (!empty($_SERVER[$h])) return true;
    }
    return false;
}

/** IP del usuario (la real, aunque entre por un túnel). */
function ip_cliente(): string {
    static $ip = null;
    if ($ip !== null) return $ip;
    $remota = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = $remota;
    if (!ip_es_local($remota)) return $ip;   // conexión directa desde internet: no se confía en encabezados

    $candidatas = [];
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) $candidatas[] = trim($_SERVER[$h]);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $x) $candidatas[] = trim($x);   // la primera es el usuario
    }
    if (!empty($_SERVER['HTTP_FORWARDED']) && preg_match_all('/for="?\[?([^\]";,]+)/i', $_SERVER['HTTP_FORWARDED'], $m)) {
        foreach ($m[1] as $x) $candidatas[] = trim($x);
    }
    foreach ($candidatas as $c) {
        $c = preg_replace('/:\d+$/', '', $c) ?: $c;          // quita el puerto en "1.2.3.4:5678"
        if (filter_var($c, FILTER_VALIDATE_IP)) { $ip = $c; break; }
    }
    return $ip;
}

/** ¿La petición viene de verdad desde la misma PC del servidor (no por un túnel)? */
function peticion_desde_esta_pc(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && !peticion_por_proxy();
}

/** Host de una URL (o null). */
function seguridad_host(?string $url): ?string {
    if (!$url) return null;
    $h = parse_url($url, PHP_URL_HOST);
    $p = parse_url($url, PHP_URL_PORT);
    return $h ? strtolower($h . ($p ? ":$p" : '')) : null;
}

/** ¿La petición viene de una página de este mismo sitio? null = el navegador no envió Origin ni Referer. */
function peticion_mismo_origen(): ?bool {
    $propio = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $origen = seguridad_host($_SERVER['HTTP_ORIGIN'] ?? null) ?? seguridad_host($_SERVER['HTTP_REFERER'] ?? null);
    if ($origen === null) return null;
    return $origen === $propio;
}

function seguridad_rechazar(string $motivo = 'Solicitud no permitida.'): void {
    http_response_code(403);
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => $motivo]);
    } else {
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Solicitud bloqueada</title>'
           . '<div style="font-family:system-ui;max-width:480px;margin:60px auto;padding:24px;border:1px solid #fecaca;border-radius:16px;background:#fef2f2;color:#991b1b">'
           . '<h2 style="margin-top:0">Solicitud bloqueada</h2><p>' . htmlspecialchars($motivo) . '</p><p><a href="javascript:history.back()">Volver</a></p></div>';
    }
    exit();
}

/** Para procesos que cambian datos con un enlace GET (eliminar, dar de baja…): deben abrirse desde el propio sistema. */
function exigir_mismo_origen(): void {
    if (PHP_SAPI === 'cli') return;
    if (peticion_mismo_origen() !== true) seguridad_rechazar('Por seguridad, esta acción solo puede ejecutarse desde el propio sistema.');
}

// CSRF: cualquier POST que llegue desde otro sitio web se rechaza
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && peticion_mismo_origen() === false) {
    seguridad_rechazar('La solicitud proviene de un sitio externo.');
}

/** Lista blanca de extensiones que se pueden subir (documentos, imágenes, comprimidos, audio y video). */
function extension_permitida(string $ext): bool {
    static $ok = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'csv', 'ods', 'ppt', 'pptx', 'odp',
                  'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z', 'mp3', 'mp4', 'm4a', 'wav', 'ogg'];
    return in_array(strtolower($ext), $ok, true);
}

/* ---------- Bloqueo de fuerza bruta en el inicio de sesión ---------- */
const LOGIN_MAX_INTENTOS = 5;      // intentos fallidos permitidos por cuenta…
// Por IP solo se frena un ataque contra muchas cuentas: en la escuela (o en una misma casa) muchos usuarios
// comparten la misma IP, así que unos cuantos errores de otros no deben bloquear a nadie.
const LOGIN_MAX_INTENTOS_IP = 40;
const LOGIN_VENTANA_MIN  = 15;     // …dentro de esta ventana (minutos)

function login_tabla(PDO $pdo): void {
    static $lista = false;
    if ($lista) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS INTENTOS_LOGIN (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(100) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_usuario_fecha (usuario, fecha),
        KEY idx_ip_fecha (ip, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $lista = true;
}

/** Minutos que faltan para poder intentar de nuevo (0 = permitido). */
function login_bloqueado(PDO $pdo, string $usuario): int {
    try {
        login_tabla($pdo);
        // El tiempo restante lo calcula MySQL para no depender de la zona horaria de PHP
        $st = $pdo->prepare("SELECT COUNT(*), TIMESTAMPDIFF(SECOND, NOW(), MIN(fecha) + INTERVAL " . LOGIN_VENTANA_MIN . " MINUTE)
                             FROM INTENTOS_LOGIN
                             WHERE usuario = ? AND fecha >= NOW() - INTERVAL " . LOGIN_VENTANA_MIN . " MINUTE");
        $st->execute([mb_strtolower($usuario)]);
        [$n, $segundos] = $st->fetch(PDO::FETCH_NUM);
        if ((int)$n >= LOGIN_MAX_INTENTOS) return max(1, (int)ceil(((int)$segundos) / 60));

        // Muchos intentos fallidos desde la misma IP contra distintas cuentas (posible ataque)
        $st = $pdo->prepare("SELECT COUNT(*), TIMESTAMPDIFF(SECOND, NOW(), MIN(fecha) + INTERVAL " . LOGIN_VENTANA_MIN . " MINUTE)
                             FROM INTENTOS_LOGIN WHERE ip = ? AND fecha >= NOW() - INTERVAL " . LOGIN_VENTANA_MIN . " MINUTE");
        $st->execute([ip_cliente()]);
        [$n, $segundos] = $st->fetch(PDO::FETCH_NUM);
        if ((int)$n >= LOGIN_MAX_INTENTOS_IP) return max(1, (int)ceil(((int)$segundos) / 60));
        return 0;
    } catch (PDOException $e) { error_log('login_bloqueado: ' . $e->getMessage()); return 0; }
}

function login_registrar_fallo(PDO $pdo, string $usuario): void {
    try {
        login_tabla($pdo);
        $pdo->prepare("INSERT INTO INTENTOS_LOGIN (usuario, ip) VALUES (?, ?)")->execute([mb_strtolower($usuario), ip_cliente()]);
        $pdo->exec("DELETE FROM INTENTOS_LOGIN WHERE fecha < NOW() - INTERVAL 1 DAY");
    } catch (PDOException $e) { error_log('login_registrar_fallo: ' . $e->getMessage()); }
}

function login_limpiar(PDO $pdo, string $usuario): void {
    try {
        login_tabla($pdo);
        $pdo->prepare("DELETE FROM INTENTOS_LOGIN WHERE usuario = ?")->execute([mb_strtolower($usuario)]);
    } catch (PDOException $e) { error_log('login_limpiar: ' . $e->getMessage()); }
}

/* ---------- Contraseñas provisionales y cambio obligatorio (todos los roles) ---------- */
const PROVISIONAL_HORAS = 72;   // la contraseña provisional que genera Administración vence en 3 días

/** Datos de cada tipo de cuenta: tabla, columna id, columna de usuario, panel de inicio y etiqueta. */
function cuenta_config(string $rol): ?array {
    $cfg = [
        'estudiante'     => ['tabla' => 'ALUMNOS',         'id' => 'id_alumno',   'usuario' => 'matricula', 'inicio' => 'estudiante/dashboard_estud.php',        'etiqueta' => 'Alumno'],
        'docente'        => ['tabla' => 'MAESTROS',        'id' => 'id_maestro',  'usuario' => 'usuario',   'inicio' => 'docente/dashboard_docente.php',         'etiqueta' => 'Docente'],
        'administrativo' => ['tabla' => 'ADMINISTRATIVOS', 'id' => 'id_admin',    'usuario' => 'usuario',   'inicio' => 'admin/dashboard_admin.php',             'etiqueta' => 'Administración'],
        'prefectos'      => ['tabla' => 'PREFECTOS',       'id' => 'id_prefecto', 'usuario' => 'usuario',   'inicio' => 'Prefectura/dashboard_prefectura.php',  'etiqueta' => 'Prefectura'],
    ];
    return $cfg[$rol] ?? null;
}
/** Tipo usado en las pantallas de Administración (alumnos/maestros/admin/prefectos) → rol de sesión. */
function cuenta_rol_desde_tipo(string $tipo): ?string {
    return ['alumnos' => 'estudiante', 'maestros' => 'docente', 'admin' => 'administrativo', 'prefectos' => 'prefectos'][$tipo] ?? null;
}

/** Genera una contraseña provisional única y fácil de dictar, p. ej. "QHTM-4827-kd" (sin 0/O ni 1/l/I). */
function generar_password_provisional(): string {
    $may = 'ABCDEFGHJKMNPQRSTUVWXYZ'; $min = 'abcdefghjkmnpqrstuvwxyz'; $dig = '23456789';
    $p = '';
    for ($i = 0; $i < 4; $i++) $p .= $may[random_int(0, strlen($may) - 1)];
    $p .= '-';
    for ($i = 0; $i < 4; $i++) $p .= $dig[random_int(0, strlen($dig) - 1)];
    $p .= '-';
    for ($i = 0; $i < 2; $i++) $p .= $min[random_int(0, strlen($min) - 1)];
    return $p;
}

/** URL base del sistema (p. ej. "/sige_cobaep1"), calculada a partir del DOCUMENT_ROOT. */
function seguridad_base_url(): string {
    $raiz = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $doc = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    return ($doc !== '' && stripos($raiz, $doc) === 0) ? rtrim(substr($raiz, strlen($doc)), '/') : '';
}

/**
 * Si el usuario en sesión tiene una contraseña provisional (o temporal de alumno), cualquier página
 * o proceso lo lleva a cambiar_password.php hasta que la cambie.
 */
function exigir_cambio_password(PDO $pdo): void {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['id_usuario'])) return;
    $c = cuenta_config($_SESSION['rol'] ?? '');
    if (!$c) return;
    // Se revisa en cada petición (consulta por llave primaria): si Administración restablece la
    // contraseña mientras el usuario tiene la sesión abierta, se le pide cambiarla de inmediato.
    try {
        $st = $pdo->prepare("SELECT debe_cambiar_password FROM {$c['tabla']} WHERE {$c['id']} = ?");
        $st->execute([(int)$_SESSION['id_usuario']]);
        if ((int)$st->fetchColumn() === 1) $_SESSION['debe_cambiar_password'] = 1;
        else unset($_SESSION['debe_cambiar_password']);
    } catch (PDOException $e) { error_log('exigir_cambio_password: ' . $e->getMessage()); }
    if (empty($_SESSION['debe_cambiar_password'])) return;
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['cambiar_password.php', 'logout.php'], true)) return;

    $destino = seguridad_base_url() . '/cambiar_password.php';
    $es_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '' || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false;
    if ($es_ajax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'Debes cambiar tu contraseña provisional.', 'redirect' => $destino]);
        exit();
    }
    header('Location: ' . $destino);
    exit();
}

/* ---------- Cierre de sesión por inactividad (todos los roles) ---------- */
const SESION_INACTIVIDAD_MIN = 30;   // minutos sin usar el sistema antes de cerrar la sesión

/** Rol de sesión → valor de ?rol= en login.php */
function sesion_rol_login(string $rol): string {
    return ['administrativo' => 'admin', 'docente' => 'docente', 'prefectos' => 'prefectos'][$rol] ?? 'estudiante';
}

/**
 * Se ejecuta en cada petición con sesión iniciada. Si pasaron más de SESION_INACTIVIDAD_MIN minutos
 * desde la última actividad, cierra la sesión y lleva al inicio de sesión con un aviso.
 * El navegador recibe la cookie "sige_inactividad" (minutos) para mostrar el aviso con cuenta regresiva.
 */
function controlar_inactividad(): void {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['id_usuario'])) return;
    $ahora = time();
    $ultima = (int)($_SESSION['ultima_actividad'] ?? $ahora);
    $ruta_cookie = (seguridad_base_url() ?: '') . '/';

    if ($ahora - $ultima > SESION_INACTIVIDAD_MIN * 60) {
        $rol = sesion_rol_login((string)($_SESSION['rol'] ?? ''));
        session_unset();
        session_destroy();
        setcookie('sige_inactividad', '', ['expires' => time() - 3600, 'path' => $ruta_cookie, 'samesite' => 'Lax']);
        $destino = seguridad_base_url() . '/login.php?rol=' . $rol . '&inactividad=1';
        $es_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '' || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false;
        if ($es_ajax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'success' => false, 'error' => 'Tu sesión se cerró por inactividad.', 'redirect' => $destino]);
            exit();
        }
        header('Location: ' . $destino);
        exit();
    }
    $_SESSION['ultima_actividad'] = $ahora;
    if (!headers_sent()) {
        setcookie('sige_inactividad', (string)SESION_INACTIVIDAD_MIN, ['expires' => 0, 'path' => $ruta_cookie, 'samesite' => 'Lax']);
    }
}
controlar_inactividad();
