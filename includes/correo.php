<?php
/**
 * includes/correo.php — Envío de correos por SMTP (Gmail, Outlook…) sin librerías externas.
 *
 *   $r = correo_enviar('padre@ejemplo.com', 'Asunto', '<p>HTML</p>');   // ['ok' => bool, 'error' => string]
 */

require_once __DIR__ . '/configuracion.php';

/**
 * Configuración de la cuenta de correo. Prioridad: lo que Administración guardó en
 * Administración › Correo de avisos (base de datos); si no hay nada, config/correo.php.
 */
function correo_config(bool $recargar = false): array {
    static $cfg = null;
    if ($cfg === null || $recargar) {
        $archivo = __DIR__ . '/../config/correo.php';
        $cfg = is_file($archivo) ? (array)require $archivo : [];
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            $bd = config_obtener($pdo, 'correo_avisos');
            if (is_array($bd)) {
                $bd['contrasena'] = config_descifrar((string)($bd['contrasena'] ?? ''));
                $cfg = array_merge($cfg, $bd);
            }
        }
    }
    return $cfg;
}

/** ¿Está configurada una cuenta para enviar? */
function correo_activo(): bool {
    $c = correo_config();
    return !empty($c['activo']) && !empty($c['host']) && !empty($c['usuario']) && !empty($c['contrasena']);
}

function correo_enviar(string $para, string $asunto, string $html, ?string $texto = null): array {
    $c = correo_config();
    if (!correo_activo()) return ['ok' => false, 'error' => 'El envío de correos no está configurado (Administración › Correo de avisos).'];
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Correo de destino no válido.'];

    $remitente = $c['remitente'] ?: $c['usuario'];
    if ($texto === null) {
        $t = preg_replace('/<\/td>\s*<td[^>]*>/i', ': ', $html);                         // "Etiqueta: valor"
        $t = preg_replace('/<br\s*\/?>|<\/p>|<\/tr>|<\/div>|<\/h\d>/i', "\n", $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
        $texto = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $t)));
    }
    $seguridad = strtolower((string)($c['seguridad'] ?? 'tls'));
    $puerto = (int)($c['puerto'] ?? 587);
    $destino = ($seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $puerto;   // 'ninguna': solo para un servidor local de pruebas

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $s = @stream_socket_client($destino, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return ['ok' => false, 'error' => "No se pudo conectar con el servidor de correo ($errstr)."];
    stream_set_timeout($s, 20);

    $leer = function () use ($s): array {
        $resp = '';
        while (($linea = fgets($s, 1024)) !== false) {
            $resp .= $linea;
            if (strlen($linea) < 4 || $linea[3] !== '-') break;
        }
        return [(int)substr($resp, 0, 3), trim($resp)];
    };
    $cmd = function (string $c, array $esperados) use ($s, $leer): array {
        fwrite($s, $c . "\r\n");
        [$cod, $resp] = $leer();
        if (!in_array($cod, $esperados, true)) throw new RuntimeException($resp ?: 'Sin respuesta del servidor');
        return [$cod, $resp];
    };

    try {
        [$cod, $resp] = $leer();
        if ($cod !== 220) throw new RuntimeException($resp);
        $equipo = 'sige-cobaep.local';
        $cmd("EHLO $equipo", [250]);
        if ($seguridad === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar la conexión segura (TLS).');
            }
            $cmd("EHLO $equipo", [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($c['usuario']), [334]);
        $cmd(base64_encode($c['contrasena']), [235]);
        $cmd('MAIL FROM:<' . $remitente . '>', [250]);
        $cmd('RCPT TO:<' . $para . '>', [250, 251]);
        $cmd('DATA', [354]);

        $limite = 'sige_' . bin2hex(random_bytes(8));
        $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
        $cab = [
            'Date: ' . date('r'),
            'From: ' . $enc($c['nombre_remitente'] ?? 'SIGE-COBAEP') . " <$remitente>",
            "To: <$para>",
            'Subject: ' . $enc($asunto),
            'MIME-Version: 1.0',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@sige-cobaep>',
            "Content-Type: multipart/alternative; boundary=\"$limite\"",
        ];
        if (!empty($c['responder_a'])) $cab[] = 'Reply-To: <' . $c['responder_a'] . '>';
        $cuerpo = implode("\r\n", $cab) . "\r\n\r\n"
            . "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto)) . "\r\n"
            . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
            . "--$limite--\r\n";
        // Las líneas que empiezan con "." se duplican (regla de SMTP)
        $cuerpo = preg_replace('/^\./m', '..', $cuerpo);
        $cmd($cuerpo . "\r\n.", [250]);
        @fwrite($s, "QUIT\r\n");
        fclose($s);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        @fwrite($s, "QUIT\r\n");
        @fclose($s);
        $msg = $e->getMessage();
        if (preg_match('/^5\d\d/', $msg) && stripos($msg, 'auth') !== false || str_starts_with($msg, '535')) $msg = 'Usuario o contraseña de la cuenta de correo incorrectos (usa una contraseña de aplicación).';
        return ['ok' => false, 'error' => mb_substr($msg, 0, 250)];
    }
}
