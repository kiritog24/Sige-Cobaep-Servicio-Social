<?php
/**
 * admin/credenciales_personal.php — Credenciales con foto del personal (docentes, prefectos y administrativos).
 * Se eligen una o varias personas y se genera un PDF con una credencial por página (tamaño CR80).
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/credencial_personal.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

$tipo = array_key_exists($_GET['tipo'] ?? '', CRED_PERSONAL) ? $_GET['tipo'] : 'maestros';
$c = CRED_PERSONAL[$tipo];
$personas = $pdo->query("SELECT {$c['id']} AS id, nombre, apellido_paterno, apellido_materno, usuario, foto_perfil
                         FROM {$c['tabla']} ORDER BY apellido_paterno, nombre")->fetchAll(PDO::FETCH_ASSOC);
$sin_foto = count(array_filter($personas, fn($p) => empty($p['foto_perfil'])));
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$plural = ['maestros' => 'Docentes', 'prefectos' => 'Prefectos', 'admin' => 'Administrativos'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Credenciales del personal · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
    <link rel="stylesheet" href="css/credenciales_personal.css?v=1">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <a class="cp-back" href="gestion_usuarios.php?tipo=<?php echo $h($tipo); ?>"><i class="fas fa-arrow-left"></i> Directorio</a>
        <div class="cp-head">
            <div class="cp-title">
                <div class="ico"><i class="fas fa-id-card"></i></div>
                <div>
                    <h1>Credenciales del personal</h1>
                    <p>Credencial con foto y código QR, igual a la de los alumnos. Una credencial por página (tamaño CR80).</p>
                </div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </div>

        <div class="cp-tabs">
            <?php foreach (CRED_PERSONAL as $k => $t): ?><a class="cp-tab <?php echo $k === $tipo ? 'activo' : ''; ?>" href="?tipo=<?php echo $k; ?>"><?php echo $plural[$k]; ?></a><?php endforeach; ?>
        </div>

        <?php if ($sin_foto): ?>
            <div class="cp-nota"><i class="fas fa-camera"></i> <b><?php echo $sin_foto; ?></b> persona(s) no tienen foto: su credencial saldrá con sus iniciales. Cada quien puede subirla en <b>Mi perfil</b>.</div>
        <?php endif; ?>

        <section class="cp-card">
            <?php if (!$personas): ?>
                <div class="cp-vacio">No hay <?php echo mb_strtolower($plural[$tipo]); ?> registrados.</div>
            <?php else: ?>
                <div class="cp-barra">
                    <label><input type="checkbox" id="todos"> Seleccionar todos (<?php echo count($personas); ?>)</label>
                    <div class="cp-btns">
                        <button type="button" class="cp-btn sec" id="btnVer"><i class="fas fa-eye"></i> Ver PDF</button>
                        <button type="button" class="cp-btn" id="btnDescargar"><i class="fas fa-download"></i> Descargar PDF</button>
                    </div>
                </div>
                <div class="cp-buscar"><i class="fas fa-magnifying-glass"></i><input type="search" id="cpBuscar" placeholder="Buscar por nombre o usuario…" aria-label="Buscar personal"></div>
                <div class="cp-lista">
                    <?php foreach ($personas as $p):
                        $p['tipo'] = $tipo;
                        $clave = $tipo . ':' . (int)$p['id'];
                        $qr = credencial_qr_texto($tipo, $p);
                        $foto = foto_publica($p['foto_perfil'] ?? null); ?>
                        <label class="cp-item">
                            <input type="checkbox" class="sel" value="<?php echo $h($clave); ?>" data-qr="<?php echo $h($qr); ?>">
                            <div class="cp-av"><?php if ($foto): ?><img src="<?php echo $h($foto); ?>" alt=""><?php else: echo $h(mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['apellido_paterno'], 0, 1))); endif; ?></div>
                            <div style="min-width:0">
                                <b><?php echo $h(trim($p['nombre'] . ' ' . $p['apellido_paterno'] . ' ' . $p['apellido_materno'])); ?></b>
                                <small><?php echo $h($p['usuario']); ?></small><?php if (!$foto): ?> · <small class="cp-sinfoto">Sin foto</small><?php endif; ?>
                            </div>
                            <button type="button" class="cp-una" title="Ver solo esta credencial" data-credencial="<?php echo $h($clave); ?>" data-qr="<?php echo $h($qr); ?>" data-modo="ver"><i class="fas fa-id-card"></i></button>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="../js/credencial_personal.js"></script>
    <script>
        const todos = document.getElementById('todos');
        // "Seleccionar todos" marca solo a los que se ven con la búsqueda actual
        if (todos) todos.addEventListener('change', () => document.querySelectorAll('.cp-item:not(.oculto) .sel').forEach(c => c.checked = todos.checked));
        const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        document.getElementById('cpBuscar')?.addEventListener('input', e => {
            const q = norm(e.target.value.trim());
            document.querySelectorAll('.cp-item').forEach(it => it.classList.toggle('oculto', !!q && !norm(it.textContent).includes(q)));
        });
        const elegidas = () => [...document.querySelectorAll('.sel:checked')].map(c => ({ clave: c.value, qr: c.dataset.qr }));
        document.getElementById('btnVer')?.addEventListener('click', e => sigeCredenciales(elegidas(), 'ver', e.currentTarget));
        document.getElementById('btnDescargar')?.addEventListener('click', e => sigeCredenciales(elegidas(), 'descargar', e.currentTarget));
        // El botón de "una sola" no debe marcar la casilla
        document.querySelectorAll('.cp-una').forEach(b => b.addEventListener('click', e => e.preventDefault()));
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>
