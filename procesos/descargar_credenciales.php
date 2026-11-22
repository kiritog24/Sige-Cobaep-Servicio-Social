<?php
/**
 * procesos/descargar_credenciales.php — Descarga las contraseñas provisionales del lote en sesión.
 * ?formato=fichas → PDF con fichas recortables (una por alumno, para entregar en mano)
 * ?formato=lista  → PDF con la lista por grupo y columna de firma de recibido
 * ?formato=xlsx   → Excel
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/credenciales_lote.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
exigir_mismo_origen();

// ?lote=alta → la cuenta recién creada en "Nuevo Registro"; si no, el lote de importación/regeneración
$es_alta = ($_GET['lote'] ?? '') === 'alta';
$lote = credenciales_lote($es_alta ? 'credenciales_alta' : 'credenciales_lote');
if (!$lote) {
    $msg = $es_alta ? 'La contraseña ya no está disponible. Restablécela desde la edición del usuario.'
                    : 'Las contraseñas ya no están disponibles. Vuelve a generarlas desde "Credenciales".';
    header("Location: ../admin/" . ($es_alta ? 'agregar_usuario.php?' : 'gestion_usuarios.php?tipo=alumnos&') . "error=" . urlencode($msg));
    exit();
}

$formato = $_GET['formato'] ?? 'fichas';
$alumnos = $lote['alumnos'];
$personal = isset($alumnos[0]['etiqueta']);                 // docentes, prefectos o administrativos
$quien = $personal ? 'personal' : 'alumnos';
$col_usuario = $personal ? 'Usuario' : 'Matrícula';
$col_grupo = $personal ? 'Puesto' : 'Grupo';
$expira = $lote['expira'];
$url = credenciales_url_login();
$base = 'Credenciales_provisionales_' . ($es_alta ? preg_replace('/[^A-Za-z0-9]/', '', $alumnos[0]['matricula']) . '_' : '') . date('Y-m-d_Hi');

if ($formato === 'xlsx') {
    require_once __DIR__ . '/../includes/xlsx_simple.php';
    $filas = [];
    foreach ($alumnos as $i => $a) $filas[] = [$i + 1, $a['grupo'], $a['matricula'], $a['nombre'], $a['password']];
    xlsx_descargar($base . '.xlsx', 'Credenciales',
        ['COBAEP Plantel 27 · Contraseñas provisionales de ' . $quien,
         'Vencen el ' . $expira . ' · Al entrar, el sistema pide crear una contraseña nueva · ' . $url],
        ['No.', $col_grupo, $personal ? 'Usuario' : 'Matrícula (usuario)', $personal ? 'Nombre' : 'Nombre del alumno', 'Contraseña provisional'],
        $filas, [6, 22, 20, 42, 24]);
}

require_once __DIR__ . '/../includes/pdf_documento.php';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if ($formato === 'lista') {
    $por_grupo = [];
    foreach ($alumnos as $a) $por_grupo[$a['grupo']][] = $a;
    $cuerpo = '';
    $primero = true;
    foreach ($por_grupo as $grupo => $lista) {
        $cuerpo .= '<div style="' . ($primero ? '' : 'page-break-before:always;') . '">'
            . '<h2 style="font-size:12px;color:#063f2b;margin:4px 0 6px">' . $col_grupo . ': ' . $e($grupo) . ' <span style="color:#64748b;font-weight:normal">(' . count($lista) . ($personal ? ' personas' : ' alumnos') . ')</span></h2>'
            . '<table><thead><tr><th style="width:5%">No.</th><th style="width:17%">' . $col_usuario . '</th><th>' . ($personal ? 'Nombre' : 'Nombre del alumno') . '</th>'
            . '<th style="width:20%">Contraseña provisional</th><th style="width:22%">Firma de recibido</th></tr></thead><tbody>';
        foreach ($lista as $i => $a) {
            $cuerpo .= '<tr><td class="c">' . ($i + 1) . '</td><td class="c">' . $e($a['matricula']) . '</td><td>' . $e(mb_strtoupper($a['nombre'], 'UTF-8')) . '</td>'
                . '<td class="c" style="font-family:DejaVu Sans Mono,monospace;font-size:10.5px;font-weight:bold;letter-spacing:.5px">' . $e($a['password']) . '</td><td></td></tr>';
        }
        $cuerpo .= '</tbody></table></div>';
        $primero = false;
    }
    $cuerpo .= '<p class="nota">' . ($personal ? 'Usuario: el indicado en la lista' : 'Usuario: matrícula') . ' · Entrar en ' . $e($url) . ' · La contraseña provisional vence el ' . $e($expira)
        . ' y solo sirve para entrar la primera vez: el sistema pedirá crear una contraseña nueva. Documento confidencial: no publicar ni compartir.</p>';
    pdf_descargar($base . '_lista.pdf', 'Contraseñas provisionales de ' . $quien, $lote['origen'] . ' · ' . count($alumnos) . ($personal ? ' personas' : ' alumnos') . ' · Vencen el ' . $expira, $cuerpo, 'portrait');
}

// Fichas recortables: 2 por fila
$ficha = function (array $a) use ($e, $url, $expira): string {
    return '<div style="border:1px dashed #94a3b8;border-radius:6px;padding:7px 9px;height:35mm;">'
        . '<div style="font-size:7.5px;color:#16a05d;font-weight:bold;text-transform:uppercase;letter-spacing:.5px">SIGE-COBAEP · Plantel 27 · Acceso al sistema</div>'
        . '<div style="font-size:10px;font-weight:bold;color:#0f172a;margin:3px 0 1px">' . $e(mb_strtoupper($a['nombre'], 'UTF-8')) . '</div>'
        . '<div style="font-size:8px;color:#475569;margin-bottom:5px">' . (isset($a['etiqueta']) ? '' : 'Grupo: ') . $e($a['grupo']) . '</div>'
        . '<table style="width:100%;border-collapse:collapse"><tr>'
        . '<td style="border:none;background:#f1f5f9;padding:4px 6px;width:48%"><span style="font-size:7px;color:#64748b">' . $e($a['etiqueta'] ?? 'USUARIO (MATRÍCULA)') . '</span><br><b style="font-size:10.5px">' . $e($a['matricula']) . '</b></td>'
        . '<td style="border:none;width:4%;background:#fff"></td>'
        . '<td style="border:none;background:#ecfdf5;padding:4px 6px;width:48%"><span style="font-size:7px;color:#047857">CONTRASEÑA PROVISIONAL</span><br><b style="font-size:11px;font-family:DejaVu Sans Mono,monospace;letter-spacing:.5px">' . $e($a['password']) . '</b></td>'
        . '</tr></table>'
        . '<div style="font-size:7px;color:#475569;margin-top:5px;line-height:1.35">Entra en <b>' . $e($url) . '</b>. Al iniciar sesión el sistema te pedirá crear tu propia contraseña. '
        . 'Esta contraseña vence el <b>' . $e($expira) . '</b>. No la compartas.</div>'
        . '</div>';
};
$cuerpo = '<table style="width:100%;border-collapse:separate;border-spacing:0 6px">';
for ($i = 0; $i < count($alumnos); $i += 2) {
    $cuerpo .= '<tr style="page-break-inside:avoid">'
        . '<td style="border:none;background:#fff;padding:0 4px 0 0;width:50%;vertical-align:top">' . $ficha($alumnos[$i]) . '</td>'
        . '<td style="border:none;background:#fff;padding:0 0 0 4px;width:50%;vertical-align:top">' . (isset($alumnos[$i + 1]) ? $ficha($alumnos[$i + 1]) : '') . '</td></tr>';
}
$cuerpo .= '</table>';
pdf_descargar($base . '_fichas.pdf', $es_alta ? 'Ficha de acceso' : 'Fichas de acceso' . ($personal ? '' : ' para alumnos'), $lote['origen'] . ($es_alta ? '' : ' · ' . count($alumnos) . ($personal ? ' personas' : ' alumnos')) . ' · Recorta por la línea punteada', $cuerpo, 'portrait');
