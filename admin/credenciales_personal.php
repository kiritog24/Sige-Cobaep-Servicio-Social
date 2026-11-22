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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#063f2b">
    <title>Credenciales del personal · COBAEP Plantel 27</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="style_dashboard_admin.css" rel="stylesheet">
    <link href="style_sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .cp-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .cp-title { display: flex; align-items: center; gap: 14px; }
        .cp-title .ico { width: 52px; height: 52px; border-radius: 14px; background: #ecfeff; color: #0e7490; display: grid; place-items: center; font-size: 1.35rem; flex-shrink: 0; }
        .cp-title h1 { margin: 0; font-size: 1.55rem; color: #0f172a; }
        .cp-title p { margin: 3px 0 0; color: #64748b; font-size: .9rem; }
        .cp-back { color: #16a05d; text-decoration: none; font-weight: 600; font-size: .88rem; display: inline-flex; gap: 6px; align-items: center; margin-bottom: 14px; }
        .cp-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .cp-tab { padding: 9px 14px; border-radius: 12px; border: 1px solid #e2e8f0; background: #fff; color: #475569; text-decoration: none; font-weight: 600; font-size: .88rem; }
        .cp-tab.activo { background: #0e7490; color: #fff; border-color: #0e7490; }
        .cp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 18px; box-shadow: 0 4px 18px rgba(15, 23, 42, .04); }
        .cp-barra { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .cp-barra label { display: flex; align-items: center; gap: 8px; font-weight: 600; color: #334155; font-size: .9rem; cursor: pointer; }
        .cp-btns { display: flex; gap: 8px; flex-wrap: wrap; }
        .cp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 10px; border: none; background: #16a05d; color: #fff; font-weight: 700; font-family: inherit; font-size: .88rem; cursor: pointer; }
        .cp-btn.sec { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
        .cp-btn:disabled { opacity: .55; cursor: wait; }
        .cp-lista { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); gap: 10px; max-height: min(64vh, 620px); overflow-y: auto; padding-right: 4px; overscroll-behavior: contain; align-content: start; }
        .cp-buscar { display: flex; align-items: center; gap: 10px; padding: 0 12px; border: 1px solid #dbe4df; border-radius: 12px; margin-bottom: 12px; max-width: 420px; }
        .cp-buscar input { flex: 1; border: none; outline: none; padding: 10px 0; font: inherit; font-size: .9rem; }
        .cp-buscar i { color: #94a3b8; }
        .cp-item.oculto { display: none; }
        .cp-item { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 14px; cursor: pointer; }
        .cp-item:has(input:checked) { border-color: #16a05d; background: #f0fdf4; }
        .cp-item input { width: 18px; height: 18px; accent-color: #16a05d; flex-shrink: 0; }
        .cp-av { width: 46px; height: 46px; border-radius: 50%; overflow: hidden; background: #e2e8f0; display: grid; place-items: center; font-weight: 700; color: #475569; flex-shrink: 0; }
        .cp-av img { width: 100%; height: 100%; object-fit: cover; }
        .cp-item b { display: block; font-size: .9rem; color: #0f172a; }
        .cp-item small { color: #64748b; font-size: .78rem; }
        .cp-sinfoto { color: #b45309 !important; font-weight: 600; }
        .cp-una { margin-left: auto; border: none; background: #ecfeff; color: #0e7490; width: 34px; height: 34px; border-radius: 9px; cursor: pointer; flex-shrink: 0; }
        .cp-nota { font-size: .82rem; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; }
        .cp-vacio { text-align: center; padding: 40px; color: #94a3b8; }
        @media (max-width: 560px) { .cp-btn { width: 100%; } .cp-btns { width: 100%; } }
    </style>
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
