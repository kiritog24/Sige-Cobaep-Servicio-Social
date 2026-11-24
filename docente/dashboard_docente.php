<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/topbar_docente.php';
require_once 'includes/avisos_docente.php';

// Validación estricta: Busca el rol 'docente'
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') {
    header("Location: ../login.php");
    exit();
}

// Obtenemos el nombre y el ID del maestro desde la sesión
$nombre_usuario = isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') : 'Docente';
$id_docente = $_SESSION['id_usuario'];

// =======================================================
// CONSULTAS REALES A LA BASE DE DATOS
// =======================================================
date_default_timezone_set('America/Mexico_City');
$dias_semana = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$numero_dia_hoy = (int)date('N'); // 1 (Lunes) a 7 (Domingo)
$nombre_dia_hoy = $dias_semana[$numero_dia_hoy];
$ahora = time();

$total_grupos = 0;
$total_alumnos = 0;
$regularizaciones_activas = 0;
$clases_semana = [];   // todas las clases de la semana (el menú de días filtra en el navegador)
$avisos = [];          // avisos y tareas generados con datos reales

try {
    // Grupos del docente: asignados por Control Escolar o con horario subido
    $sql_mis_grupos = "SELECT id_grupo FROM GRUPO_MAESTRO WHERE id_maestro = :d1 UNION SELECT id_grupo FROM HORARIOS_MAESTRO WHERE id_maestro = :d2";

    $st = $pdo->prepare("SELECT COUNT(*) FROM ($sql_mis_grupos) t");
    $st->execute(['d1' => $id_docente, 'd2' => $id_docente]);
    $total_grupos = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(DISTINCT ga.id_alumno) FROM GRUPO_ALUMNO ga WHERE ga.fecha_baja IS NULL AND ga.id_grupo IN ($sql_mis_grupos)");
    $st->execute(['d1' => $id_docente, 'd2' => $id_docente]);
    $total_alumnos = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM CLASES_REGULARIZACION WHERE id_maestro = ? AND estatus = 'Activa'");
    $st->execute([$id_docente]);
    $regularizaciones_activas = (int)$st->fetchColumn();

    // Horario de la semana y avisos (compartido con la campana de las demás páginas)
    $info_docente = docente_avisos($pdo, (int)$id_docente);
    $clases_semana = $info_docente['clases_semana'];
    $avisos = $info_docente['avisos'];

} catch (PDOException $e) {
    error_log("Error obteniendo datos del inicio docente: " . $e->getMessage());
}

// Días que se muestran en el menú: Lunes a Viernes, y fin de semana solo si hay clases
$dias_menu = [1, 2, 3, 4, 5];
foreach ([6, 7] as $d) {
    if (in_array($d, array_map('intval', array_column($clases_semana, 'dia_semana')), true)) $dias_menu[] = $d;
}
$clases_por_dia = array_fill_keys(array_keys($dias_semana), 0);
foreach ($clases_semana as $c) $clases_por_dia[(int)$c['dia_semana']]++;
$dia_inicial = in_array($numero_dia_hoy, $dias_menu, true) ? $numero_dia_hoy : 1;
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
    <title>Portal Docente · SIGE COBAEP 27</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link rel="stylesheet" href="style_dashboard_docente.css?v=<?php echo time(); ?>">

    <link rel="stylesheet" href="css/dashboard_docente.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>

<body>
    <!-- SCRIPT ANTI-PARPADEO -->
    <script>
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.body.classList.add('sidebar-collapsed', 'preload-transitions');
        }
    </script>

    <!-- BARRA LATERAL -->
    <aside class="sidebar" id="sidebar">
        <button id="collapseToggle" class="collapse-toggle" aria-label="Colapsar menú">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="sidebar-inner">
            <div class="brand">
                <img src="../img/LogoCobaep.png" alt="Logo COBAEP" class="brand-logo">
                <div class="brand-copy">
                    <strong>SIGE<span>-Cobaep</span></strong>
                    <small>Plantel 27</small>
                </div>
            </div>

            <nav class="navigation" aria-label="Navegación principal">
                <span class="nav-heading">Menú principal</span>
                <a href="dashboard_docente.php" class="nav-item active">
                    <i class="fas fa-house"></i><span>Inicio</span>
                </a>
                <span class="nav-heading">Académico</span>
                <a href="mis_grupos.php" class="nav-item">
                    <i class="fas fa-users-rectangle"></i><span>Mis Grupos</span>
                </a>
                <a href="horario.php" class="nav-item">
                    <i class="far fa-calendar-alt"></i><span>Mi Horario</span>
                </a>
                <a href="horarios_plantel.php" class="nav-item"><i class="fas fa-calendar-week"></i><span>Horarios del Plantel</span></a>
                <span class="nav-heading">Evaluación</span>
                <a href="regularizaciones.php" class="nav-item">
                    <i class="fas fa-book-open-reader"></i><span>Regularizaciones</span>
                </a>
            </nav>

            <div class="sidebar-bottom">
                <div class="institution">
                    <div class="institution-icon"><i class="fas fa-building-columns"></i></div>
                    <div>
                        <strong>COBAEP Plantel 27</strong>
                        <span>Portal Docente</span>
                    </div>
                </div>
                <a href="../logout.php" class="logout">
                    <i class="fas fa-arrow-right-from-bracket"></i><span>Cerrar Sesión</span>
                </a>
            </div>
        </div>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php include __DIR__ . '/../includes/menu_estado.php'; ?>

    <main class="main-content">
        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" id="mobileMenu"><i class="fas fa-bars"></i></button>
                <div>
                    <span class="eyebrow">PORTAL DOCENTE · SIGE</span>
                    <h1>¡Hola, Maestro(a) <?php echo $nombre_usuario; ?>!</h1>
                    <p>Aquí tienes el resumen de tus grupos y actividades académicas.</p>
                </div>
            </div>
            <?php docente_topbar($pdo); ?>
        </header>

        <!-- TARJETAS DE ESTADÍSTICAS -->
        <section class="stats-grid">
            <article class="stat-card groups">
                <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-content">
                    <span>Mis Grupos</span>
                    <strong><?php echo $total_grupos; ?></strong>
                    <small>Grupos asignados</small>
                </div>
            </article>
            <article class="stat-card students">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <span>Alumnos</span>
                    <strong><?php echo $total_alumnos; ?></strong>
                    <small>Total en tus clases</small>
                </div>
            </article>
            <article class="stat-card regularizations">
                <div class="stat-icon"><i class="fas fa-file-signature"></i></div>
                <div class="stat-content">
                    <span>Regularizaciones</span>
                    <strong><?php echo $regularizaciones_activas; ?></strong>
                    <small>Procesos activos</small>
                </div>
            </article>
        </section>

        <!-- ACCIONES RÁPIDAS -->
        <section class="section">
            <div class="section-heading">
                <span class="section-kicker">ACCESOS</span>
                <h2>Acciones Rápidas</h2>
            </div>
            <div class="quick-grid">
                <a href="mis_grupos.php" class="quick-card">
                    <div class="quick-top"><div class="quick-icon blue"><i class="fas fa-clipboard-list"></i></div></div>
                    <h3>Consultar Grupos</h3>
                    <p>Revisa la información de los grupos asignados.</p>
                    <span class="quick-link">Ver mis grupos <i class="fas fa-arrow-right"></i></span>
                </a>
                <a href="regularizaciones.php" class="quick-card">
                    <div class="quick-top"><div class="quick-icon orange"><i class="fas fa-folder-open"></i></div></div>
                    <h3>Gestionar Regularizaciones</h3>
                    <p>Crea espacios de recuperación, sube material de apoyo y activa la entrega de trabajos.</p>
                    <span class="quick-link">Ir a regularizaciones <i class="fas fa-arrow-right"></i></span>
                </a>
                <a href="horario.php" class="quick-card">
                    <div class="quick-top"><div class="quick-icon green"><i class="far fa-clock"></i></div></div>
                    <h3>Mis Horarios</h3>
                    <p>Consulta tus horarios de clase asignados para el semestre en curso.</p>
                    <span class="quick-link">Ver horarios <i class="fas fa-arrow-right"></i></span>
                </a>
            </div>
        </section>

        <!-- PANELES: CLASES DE LA SEMANA Y AVISOS -->
        <?php
        $lunes = strtotime('monday this week');
        $cortos = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
        ?>
        <section class="dashboard-panels">
            <!-- PANEL 1: CLASES -->
            <div class="panel-card" style="display: flex; flex-direction: column;">
                <div class="panel-header">
                    <h3><i class="fas fa-chalkboard-user"></i> <span id="tituloClases">Mis Clases de Hoy</span></h3>
                    <span class="reloj-pill"><i class="far fa-clock"></i> <span id="relojActual"><?php echo date('h:i a'); ?></span></span>
                </div>

                <!-- Menú de días de la semana -->
                <div class="dias-menu" role="tablist" aria-label="Días de la semana" style="--dias: <?php echo count($dias_menu); ?>;">
                    <?php foreach ($dias_menu as $d): ?>
                        <button type="button" role="tab" class="dia-tab<?php echo $d === $numero_dia_hoy ? ' hoy' : ''; ?><?php echo $d === $dia_inicial ? ' activo' : ''; ?>"
                                data-dia="<?php echo $d; ?>" data-nombre="<?php echo $dias_semana[$d]; ?>" aria-selected="<?php echo $d === $dia_inicial ? 'true' : 'false'; ?>">
                            <span class="dia-corto"><?php echo $cortos[$d]; ?></span>
                            <span class="dia-num"><?php echo date('j', $lunes + ($d - 1) * 86400); ?></span>
                            <span class="dia-count"><?php echo $clases_por_dia[$d] ? $clases_por_dia[$d] . ($clases_por_dia[$d] == 1 ? ' clase' : ' clases') : 'Libre'; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="panel-body" style="flex: 1; display: flex; flex-direction: column;">
                    <div class="lista-clases" id="listaClases">
                        <?php foreach ($clases_semana as $c):
                            $grupo = $c['semestre'] . 'º "' . $c['nombre_grupo'] . '"'; ?>
                            <div class="clase-item" data-dia="<?php echo (int)$c['dia_semana']; ?>"
                                 data-ini="<?php echo substr($c['hora_inicio'], 0, 5); ?>" data-fin="<?php echo substr($c['hora_fin'], 0, 5); ?>"
                                 data-id="<?php echo (int)$c['id_horario']; ?>"
                                 data-materia="<?php echo htmlspecialchars($c['nombre_materia'], ENT_QUOTES); ?>"
                                 data-grupo="<?php echo htmlspecialchars($grupo, ENT_QUOTES); ?>"
                                 <?php echo (int)$c['dia_semana'] !== $dia_inicial ? 'hidden' : ''; ?>>
                                <div class="clase-tiempo">
                                    <div class="ini"><?php echo date('h:i a', strtotime($c['hora_inicio'])); ?></div>
                                    <div class="fin">a <?php echo date('h:i a', strtotime($c['hora_fin'])); ?></div>
                                </div>
                                <div class="clase-detalles">
                                    <h4><?php echo htmlspecialchars($c['nombre_materia']); ?></h4>
                                    <p>
                                        <span class="grupo-badge"><i class="fas fa-users"></i> Grupo <?php echo htmlspecialchars($grupo); ?></span>
                                        <span class="turno-txt"><i class="far fa-sun"></i> <?php echo htmlspecialchars($c['turno']); ?></span>
                                        <?php if ((int)$c['dia_semana'] === $numero_dia_hoy && (int)$c['lista_hoy'] > 0): ?>
                                            <span class="lista-ok"><i class="fas fa-check-circle"></i> Lista tomada</span>
                                        <?php endif; ?>
                                    </p>
                                    <div class="progreso" aria-hidden="true"><span></span></div>
                                </div>
                                <span class="estatus-clase programada"><i class="far fa-calendar"></i> <span class="txt">Programada</span></span>
                                <a href="gestionar_aula.php?id_grupo=<?php echo (int)$c['id_grupo']; ?>&abrir_lista=<?php echo (int)$c['id_clase']; ?>" class="btn-asistencia">
                                    <i class="fas fa-clipboard-user"></i> Lista
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="vacio-dia" id="vacioDia" <?php echo $clases_por_dia[$dia_inicial] ? 'hidden' : ''; ?>>
                        <div class="icono"><i class="fas fa-mug-hot"></i></div>
                        <h4>Día Libre</h4>
                        <p id="vacioTexto">No tienes clases programadas para este día.</p>
                    </div>
                </div>
            </div>

            <!-- PANEL 2: AVISOS Y TAREAS -->
            <div class="panel-card" id="panelAvisos" style="display: flex; flex-direction: column;">
                <div class="panel-header">
                    <h3><i class="fas fa-bell"></i> Avisos y Tareas
                        <?php if ($avisos): ?><span class="contador-avisos"><?php echo count($avisos); ?></span><?php endif; ?>
                    </h3>
                    <button type="button" class="btn-alertas" id="btnAlertas" hidden><i class="fas fa-bell"></i> Activar alertas</button>
                </div>
                <div class="avisos-body">
                    <!-- Alerta en vivo de la próxima clase (la llena el script) -->
                    <div id="alertaClase"></div>

                    <?php foreach ($avisos as $a): ?>
                        <div class="aviso-item">
                            <div class="aviso-icon <?php echo $a['tipo']; ?>"><i class="fas <?php echo $a['icono']; ?>"></i></div>
                            <div class="aviso-contenido">
                                <h4><?php echo htmlspecialchars($a['titulo']); ?></h4>
                                <p><?php echo $a['texto']; /* ya escapado al construirlo */ ?></p>
                                <span class="aviso-fecha"><?php echo htmlspecialchars($a['meta']); ?></span>
                                <?php if ($a['enlace']): ?>
                                    <a class="aviso-accion" href="<?php echo htmlspecialchars($a['enlace']); ?>"><?php echo htmlspecialchars($a['accion'] ?? 'Ver'); ?> <i class="fas fa-arrow-right"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$avisos): ?>
                        <div class="aviso-item" id="todoAlDia">
                            <div class="aviso-icon ok"><i class="fas fa-check"></i></div>
                            <div class="aviso-contenido">
                                <h4>¡Todo al día!</h4>
                                <p>No tienes trabajos por revisar, actividades por cerrar ni listas pendientes.</p>
                                <span class="aviso-fecha">Actualizado <?php echo date('h:i a'); ?> • Sistema</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="toast-clase" id="toastClase" role="alert">
            <i class="fas fa-bell"></i>
            <div><strong id="toastTitulo"></strong><span id="toastTexto"></span></div>
            <button type="button" id="toastCerrar" aria-label="Cerrar aviso"><i class="fas fa-times"></i></button>
        </div>

    </main>

    <script src="../js/dashboard_docente.js"></script>
    <script>
    (function () {
        const HOY = <?php echo $numero_dia_hoy; ?>;
        // Diferencia entre el reloj del servidor (hora de México) y el del navegador
        const desfase = <?php echo $ahora * 1000; ?> - Date.now();
        const ahora = () => new Date(Date.now() + desfase);
        const mins = hhmm => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
        const fmtDur = m => m < 60 ? m + ' min' : Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '');
        const fmtHora = d => d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });

        const items = [...document.querySelectorAll('.clase-item')];
        const tabs = [...document.querySelectorAll('.dia-tab')];
        let diaActivo = Number(document.querySelector('.dia-tab.activo')?.dataset.dia || HOY);

        // ---------- Menú de días ----------
        function mostrarDia(dia) {
            diaActivo = dia;
            tabs.forEach(t => { const on = Number(t.dataset.dia) === dia; t.classList.toggle('activo', on); t.setAttribute('aria-selected', on); });
            let visibles = 0;
            items.forEach(it => { const on = Number(it.dataset.dia) === dia; it.hidden = !on; if (on) visibles++; });
            const nombre = tabs.find(t => Number(t.dataset.dia) === dia)?.dataset.nombre || '';
            document.getElementById('tituloClases').textContent = dia === HOY ? 'Mis Clases de Hoy' : 'Mis Clases del ' + nombre;
            document.getElementById('vacioDia').hidden = visibles > 0;
            document.getElementById('vacioTexto').textContent = dia === HOY
                ? 'No tienes clases programadas para hoy.'
                : 'No tienes clases programadas el ' + nombre.toLowerCase() + '.';
            actualizar();
        }
        tabs.forEach(t => t.addEventListener('click', () => mostrarDia(Number(t.dataset.dia))));

        // ---------- Estatus de cada clase ----------
        function estatus(it, n, minutoAhora) {
            const dia = Number(it.dataset.dia), ini = mins(it.dataset.ini), fin = mins(it.dataset.fin);
            if (dia !== HOY) return dia < HOY ? ['terminada', 'fa-check', 'Impartida esta semana'] : ['programada', 'fa-calendar', 'Programada'];
            if (minutoAhora < ini) {
                const falta = ini - minutoAhora;
                return [falta <= 60 ? 'proxima' : 'programada', 'fa-hourglass-start', 'Inicia en ' + fmtDur(falta)];
            }
            if (minutoAhora < fin) return ['en-curso', 'fa-circle', 'En curso · termina en ' + fmtDur(fin - minutoAhora)];
            return ['terminada', 'fa-check', 'Terminada'];
        }

        function actualizar() {
            const d = ahora();
            const m = d.getHours() * 60 + d.getMinutes();
            document.getElementById('relojActual').textContent = fmtHora(d);
            items.forEach(it => {
                const [clase, icono, texto] = estatus(it, null, m);
                it.classList.remove('en-curso', 'proxima', 'terminada');
                if (clase !== 'programada') it.classList.add(clase);
                const badge = it.querySelector('.estatus-clase');
                badge.className = 'estatus-clase ' + clase;
                badge.querySelector('i').className = (clase === 'en-curso' ? 'fas ' : 'fas ') + icono;
                badge.querySelector('.txt').textContent = texto;
                if (clase === 'en-curso') {
                    const ini = mins(it.dataset.ini), fin = mins(it.dataset.fin);
                    it.querySelector('.progreso span').style.width = Math.round((m - ini) / (fin - ini) * 100) + '%';
                }
            });
            alertaProximaClase(m);
        }

        // ---------- Avisos de la próxima clase (10 y 5 minutos) ----------
        const caja = document.getElementById('alertaClase');
        function alertaProximaClase(m) {
            const hoy = items.filter(it => Number(it.dataset.dia) === HOY);
            const enCurso = hoy.find(it => m >= mins(it.dataset.ini) && m < mins(it.dataset.fin));
            const proxima = hoy.filter(it => mins(it.dataset.ini) > m).sort((a, b) => mins(a.dataset.ini) - mins(b.dataset.ini))[0];
            let html = '';
            if (proxima) {
                const falta = mins(proxima.dataset.ini) - m;
                if (falta <= 60) {
                    const inminente = falta <= 10;
                    html += tarjeta(inminente ? 'alerta-clase inminente' : 'alerta-clase', inminente ? 'urgente' : 'tareas', 'fa-person-chalkboard',
                        inminente ? '¡Tu clase está por iniciar!' : 'Próxima clase',
                        'Faltan <strong>' + falta + ' min</strong> para ' + esc(proxima.dataset.materia) + ' · Grupo ' + esc(proxima.dataset.grupo) + '.',
                        'Inicia a las ' + proxima.querySelector('.ini').textContent + ' • Horario');
                    if (falta <= 10) notificar(proxima, falta <= 5 ? 5 : 10, falta);
                }
            }
            if (enCurso) {
                html += tarjeta('alerta-clase', 'ok', 'fa-chalkboard', 'Clase en curso',
                    esc(enCurso.dataset.materia) + ' · Grupo ' + esc(enCurso.dataset.grupo) + '. Termina en <strong>' + fmtDur(mins(enCurso.dataset.fin) - m) + '</strong>.',
                    'No olvides pasar lista • Horario');
            }
            caja.innerHTML = html;
            const todo = document.getElementById('todoAlDia');
            if (todo) todo.hidden = html !== '';
        }
        function esc(t) { const s = document.createElement('span'); s.textContent = t; return s.innerHTML; }
        function tarjeta(clase, tipo, icono, titulo, texto, meta) {
            return '<div class="aviso-item ' + clase + '"><div class="aviso-icon ' + tipo + '"><i class="fas ' + icono + '"></i></div>' +
                   '<div class="aviso-contenido"><h4>' + titulo + '</h4><p>' + texto + '</p><span class="aviso-fecha">' + meta + '</span></div></div>';
        }

        // Toast (y notificación del navegador si se permitió) una sola vez por umbral
        const toast = document.getElementById('toastClase');
        document.getElementById('toastCerrar').addEventListener('click', () => toast.classList.remove('show'));
        function notificar(it, umbral, falta) {
            const clave = 'aviso-clase-' + it.dataset.id + '-' + ahora().toDateString() + '-' + umbral;
            try { if (sessionStorage.getItem(clave)) return; sessionStorage.setItem(clave, '1'); } catch (e) {}
            const titulo = 'Tu clase inicia en ' + falta + ' min';
            const texto = it.dataset.materia + ' · Grupo ' + it.dataset.grupo;
            document.getElementById('toastTitulo').textContent = titulo;
            document.getElementById('toastTexto').textContent = texto;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 12000);
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(titulo, { body: texto, icon: '../img/LogoCobaep.png' });
            }
        }
        const btnAlertas = document.getElementById('btnAlertas');
        if ('Notification' in window && Notification.permission === 'default') {
            btnAlertas.hidden = false;
            btnAlertas.addEventListener('click', () => Notification.requestPermission().then(p => {
                btnAlertas.hidden = true;
                if (p === 'granted') new Notification('Alertas activadas', { body: 'Te avisaremos 10 y 5 minutos antes de cada clase.', icon: '../img/LogoCobaep.png' });
            }));
        }

        // Campana del encabezado: lleva a los avisos
        const campana = document.querySelector('.notification-button');
        if (campana) campana.addEventListener('click', () => document.getElementById('panelAvisos').scrollIntoView({ behavior: 'smooth', block: 'start' }));

        mostrarDia(diaActivo);
        setInterval(actualizar, 20000);
    })();
    </script>
    <script src="../js/responsive.js?v=11"></script>
</body>
</html>
