<?php
/**
 * admin/carga_grupo.php — Revisión de una lista (Excel/CSV) antes de inscribirla en un grupo.
 * Muestra qué pasará con cada alumno; nada se guarda hasta confirmar.
 */
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/topbar_usuario.php';
require_once __DIR__ . '/../includes/grupos.php';
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}
$id_grupo = (int)($_GET['id'] ?? 0);
$carga = $_SESSION['carga_grupo'] ?? null;
if (!$carga || (int)$carga['id_grupo'] !== $id_grupo || !hash_equals($carga['token'], (string)($_GET['t'] ?? ''))) {
    header("Location: ver_grupo.php?id=$id_grupo&error_carga=" . urlencode('La revisión ya no está disponible. Vuelve a subir el archivo.'));
    exit();
}
$st = $pdo->prepare("SELECT id_grupo, semestre, nombre_grupo, turno FROM GRUPOS WHERE id_grupo = ?");
$st->execute([$id_grupo]);
$grupo = $st->fetch(PDO::FETCH_ASSOC);
if (!$grupo) { header("Location: gestion_grupos.php"); exit(); }

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$an = $carga['analisis'];
$cats = [
    'nuevo'         => ['Se inscribirán', 'fa-user-plus', 'verde', 'No tienen grupo: entran a este grupo al confirmar.'],
    'otro_grupo'    => ['Están en otro grupo', 'fa-right-left', 'ambar', 'Marca a los que sí debes cambiar a este grupo. Los que no marques se quedan donde están.'],
    'ya_en_grupo'   => ['Ya están en este grupo', 'fa-check-double', 'gris', 'No se hace nada con ellos.'],
    'repetido'      => ['Repetidos en el archivo', 'fa-clone', 'gris', 'Aparecen más de una vez; se toman una sola vez.'],
    'baja'          => ['Dados de baja', 'fa-user-slash', 'rojo', 'Su estatus no es "Alta": no se inscriben.'],
    'no_encontrado' => ['No encontrados', 'fa-circle-question', 'rojo', 'No existen en el sistema. Primero dalos de alta en Nuevo Registro o con la importación de alumnos.'],
    'ambiguo'       => ['Nombre repetido en el sistema', 'fa-people-arrows', 'rojo', 'Hay más de un alumno con ese nombre: inscríbelos con su matrícula.'],
];
$por_cat = array_fill_keys(array_keys($cats), []);
foreach ($an['renglones'] as $r) $por_cat[$r['categoria']][] = $r;
$n_nuevos = count($por_cat['nuevo']);
$n_otros = count($por_cat['otro_grupo']);
$nom = fn($a) => trim($a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ' ' . $a['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisar lista · SIGE COBAEP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style_dashboard_admin.css">
    <link rel="stylesheet" href="style-ver_grupo.css">
    <link rel="stylesheet" href="style_sidebar.css">
    <link rel="stylesheet" href="css/carga_grupo.css?v=2">
    <link rel="stylesheet" href="../css/responsive.css?v=5">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-content layout-detalle">
        <header class="top-header">
            <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menú" style="margin-right: 15px; display: none;"><i class="fas fa-bars"></i></button>
            <div class="page-title-container">
                <div class="icon-box"><i class="fas fa-file-import"></i></div>
                <div><h1>Revisar lista</h1><div class="subtitle-date"><i class="far fa-file-excel"></i> <?php echo $h($carga['archivo']); ?></div></div>
            </div>
            <?php topbar_usuario($pdo); ?>
        </header>

        <div class="group-info-card">
            <div class="group-details">
                <div class="group-icon"><i class="fas fa-users-viewfinder"></i></div>
                <div><h2><?php echo $h($grupo['semestre']); ?>° Grupo "<?php echo $h($grupo['nombre_grupo']); ?>"</h2>
                    <p>Turno <?php echo $h($grupo['turno']); ?> · Se leyeron <?php echo count($an['renglones']); ?> alumno(s) del archivo
                    (por <?php echo $an['modo'] === 'matricula' ? 'matrícula' : 'nombre completo'; ?>)</p></div>
            </div>
            <div class="group-actions">
                <a href="ver_grupo.php?id=<?php echo $id_grupo; ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Cancelar</a>
            </div>
        </div>

        <div class="cg-resumen">
            <?php foreach ($cats as $k => [$titulo, $icono, $color]): if (!$por_cat[$k]) continue; ?>
                <a href="#cat-<?php echo $k; ?>" class="cg-num <?php echo $color; ?>"><i class="fas <?php echo $icono; ?>"></i><strong><?php echo count($por_cat[$k]); ?></strong><span><?php echo $titulo; ?></span></a>
            <?php endforeach; ?>
        </div>

        <?php if ($an['modo'] === 'nombre'): ?>
            <div class="cg-aviso"><i class="fas fa-circle-info"></i> El archivo no trae matrículas, así que se buscó a cada alumno por su nombre completo. Revisa bien la lista antes de confirmar.</div>
        <?php endif; ?>

        <form action="procesos/inscribirAlumnos_excel.php" method="POST" id="formCarga">
            <input type="hidden" name="paso" value="aplicar">
            <input type="hidden" name="id_grupo" value="<?php echo $id_grupo; ?>">
            <input type="hidden" name="token" value="<?php echo $h($carga['token']); ?>">

            <?php foreach ($cats as $k => [$titulo, $icono, $color, $explica]): if (!$por_cat[$k]) continue; ?>
            <section class="content-card cg-seccion" id="cat-<?php echo $k; ?>">
                <div class="cg-titulo">
                    <h3><i class="fas <?php echo $icono; ?> cg-ic-<?php echo $color; ?>"></i> <?php echo $titulo; ?> <small>(<?php echo count($por_cat[$k]); ?>)</small></h3>
                    <?php if ($k === 'otro_grupo'): ?>
                        <label class="cg-todos"><input type="checkbox" id="moverTodos"> Cambiar a todos</label>
                    <?php endif; ?>
                </div>
                <p class="cg-explica"><?php echo $explica; ?></p>
                <div class="table-responsive">
                    <table class="data-table no-tarjetas" data-sin-buscador>
                        <thead><tr>
                            <?php if ($k === 'otro_grupo'): ?><th style="width:90px;">Cambiar</th><?php endif; ?>
                            <th style="width:60px;">Fila</th><th>Matrícula</th><th>Alumno</th>
                            <?php if (in_array($k, ['otro_grupo', 'ya_en_grupo', 'nuevo', 'repetido', 'baja'], true)): ?><th>Grupo actual</th><?php endif; ?>
                            <th>Observaciones</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($por_cat[$k] as $r): $a = $r['alumno']; ?>
                            <tr>
                                <?php if ($k === 'otro_grupo'): ?>
                                    <td><label class="cg-mover"><input type="checkbox" name="mover[]" value="<?php echo (int)$a['id_alumno']; ?>" class="chk-mover"> Sí</label></td>
                                <?php endif; ?>
                                <td><?php echo (int)$r['fila']; ?></td>
                                <td><strong><?php echo $h($a ? $a['matricula'] : ($an['modo'] === 'matricula' ? $r['valor'] : '—')); ?></strong></td>
                                <td><?php echo $h($a ? $nom($a) : ($an['modo'] === 'nombre' ? $r['valor'] : '—')); ?></td>
                                <?php if (in_array($k, ['otro_grupo', 'ya_en_grupo', 'nuevo', 'repetido', 'baja'], true)): ?>
                                    <td><?php echo $r['grupo_actual'] ? '<span class="cg-grupo">' . $h($r['grupo_actual']) . '</span>' : '<span class="cg-muted">Sin grupo</span>'; ?></td>
                                <?php endif; ?>
                                <td class="cg-obs">
                                    <?php
                                    $obs = $r['avisos'] ?? [];
                                    if ($k === 'repetido') array_unshift($obs, 'Ya aparece en la fila ' . (int)$r['repite_fila']);
                                    if ($k === 'baja') array_unshift($obs, 'Estatus: ' . $a['estatus']);
                                    echo $obs ? implode('<br>', array_map(fn($o) => '<i class="fas fa-triangle-exclamation"></i> ' . $h($o), $obs)) : '<span class="cg-muted">—</span>';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endforeach; ?>

            <div class="cg-barra">
                <span id="cgResumen"><b><?php echo $n_nuevos; ?></b> se inscribirán<?php if ($n_otros): ?> · <b id="cgMover">0</b> se cambiarán de grupo<?php endif; ?></span>
                <div>
                    <a href="ver_grupo.php?id=<?php echo $id_grupo; ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary" id="btnConfirmar" <?php echo !$n_nuevos && !$n_otros ? 'disabled' : ''; ?>><i class="fas fa-check"></i> Confirmar inscripción</button>
                </div>
            </div>
        </form>
    </main>
    <script src="../js/responsive.js?v=10"></script>
    <script>
        (function () {
            const chks = [...document.querySelectorAll('.chk-mover')], todos = document.getElementById('moverTodos'), n = document.getElementById('cgMover');
            const btn = document.getElementById('btnConfirmar'), nuevos = <?php echo $n_nuevos; ?>;
            const contar = () => { const m = chks.filter(c => c.checked).length; if (n) n.textContent = m; if (btn) btn.disabled = !(nuevos || m); if (todos) todos.checked = m && m === chks.length; };
            chks.forEach(c => c.addEventListener('change', contar));
            if (todos) todos.addEventListener('change', () => { chks.forEach(c => c.checked = todos.checked); contar(); });
            contar();
            document.getElementById('formCarga').addEventListener('submit', async function (e) {
                if (this.dataset.ok) return;
                e.preventDefault();
                const m = chks.filter(c => c.checked).length;
                const msg = `Se inscribirán ${nuevos} alumno(s)` + (m ? ` y ${m} se cambiarán desde otro grupo (su grupo anterior queda con fecha de baja).` : '.') + ' ¿Continuar?';
                const ok = window.sigeConfirm ? await window.sigeConfirm(msg, { titulo: 'Confirmar inscripción', aceptar: 'Sí, inscribir' }) : confirm(msg);
                if (ok) { this.dataset.ok = '1'; this.submit(); }
            });
            // Menú en celular
            const sb = document.getElementById('sidebar'), mm = document.getElementById('mobileMenu');
            if (mm && sb) mm.addEventListener('click', () => sb.classList.toggle('mobile-open'));
        })();
    </script>
</body>
</html>
