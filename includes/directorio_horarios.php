<?php
require_once __DIR__ . '/calendario.php';
/**
 * includes/directorio_horarios.php
 * "Horarios del Plantel": directorio compartido por los 4 perfiles para consultar
 * el horario de cualquier docente o grupo, y los horarios de entrada por turno.
 *
 * Uso (desde una página de cualquier módulo, ya con su sesión validada):
 *   require_once '../includes/directorio_horarios.php';
 *   $datos = dh_cargar($pdo);
 *   dh_render($datos, ['mi_grupo' => 6]);   // o ['mi_maestro' => 1]
 */

function dh_cargar(PDO $pdo): array {
    $maestros = $pdo->query("
        SELECT id_maestro, nombre, apellido_paterno, apellido_materno, titulo_academico, foto_perfil, horario_archivo
        FROM MAESTROS ORDER BY apellido_paterno, apellido_materno, nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grupos = $pdo->query("
        SELECT g.id_grupo, g.semestre, g.nombre_grupo, g.turno, g.capacitacion,
               (SELECT COUNT(*) FROM GRUPO_ALUMNO ga WHERE ga.id_grupo = g.id_grupo AND ga.fecha_baja IS NULL) AS alumnos
        FROM GRUPOS g
        WHERE g.estatus = 'Activo'
        ORDER BY g.semestre, g.nombre_grupo, g.turno
    ")->fetchAll(PDO::FETCH_ASSOC);

    $pdfs = $pdo->query("
        SELECT hm.id_horario, hm.id_maestro, hm.id_grupo, hm.nombre_archivo, hm.ruta_pdf, hm.fecha_subida, hm.ciclo, hm.anio
        FROM HORARIOS_MAESTRO hm ORDER BY hm.fecha_subida DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $clases = $pdo->query("
        SELECT hc.dia_semana, TIME_FORMAT(hc.hora_inicio, '%H:%i') AS ini, TIME_FORMAT(hc.hora_fin, '%H:%i') AS fin,
               c.id_maestro, c.id_grupo, m.nombre_materia AS materia
        FROM HORARIOS_CLASES hc
        INNER JOIN CLASES_REGULARES c ON c.id_clase = hc.id_clase AND c.estatus = 'Activa'
        INNER JOIN MATERIAS m ON m.id_materia = c.id_materia
        ORDER BY hc.dia_semana, hc.hora_inicio
    ")->fetchAll(PDO::FETCH_ASSOC);

    $asignaciones = $pdo->query("SELECT DISTINCT id_maestro, id_grupo FROM GRUPO_MAESTRO")->fetchAll(PDO::FETCH_ASSOC);
    $turnos = $pdo->query("SELECT turno, hora_entrada, fin_tolerancia, hora_corte FROM HORARIOS_TURNO ORDER BY hora_entrada")->fetchAll(PDO::FETCH_ASSOC);

    // Índices
    $M = []; foreach ($maestros as $m) {
        $nombre = trim($m['nombre'] . ' ' . $m['apellido_paterno'] . ' ' . $m['apellido_materno']);
        $ruta_foto = preg_replace('#^(\.\./)+#', '', (string)$m['foto_perfil']);   // acepta "../uploads/..." y "uploads/..."
        $foto = ($ruta_foto !== '' && strpos($ruta_foto, 'uploads/') === 0 && is_file(__DIR__ . '/../' . $ruta_foto)) ? $ruta_foto : null;
        $M[$m['id_maestro']] = ['id' => (int)$m['id_maestro'], 'nombre' => $nombre, 'titulo' => $m['titulo_academico'],
            'foto' => $foto, 'iniciales' => mb_strtoupper(mb_substr($m['nombre'], 0, 1) . mb_substr($m['apellido_paterno'], 0, 1)),
            'pdfs' => [], 'clases' => [], 'grupos' => []];
        // Horario general subido desde el perfil del docente (si no es uno de los ya listados por grupo)
        if (!empty($m['horario_archivo']) && is_file(__DIR__ . '/../' . $m['horario_archivo'])) {
            $M[$m['id_maestro']]['pdf_general'] = $m['horario_archivo'];
        }
    }
    $G = []; foreach ($grupos as $g) {
        $G[$g['id_grupo']] = ['id' => (int)$g['id_grupo'], 'nombre' => $g['semestre'] . '° "' . $g['nombre_grupo'] . '"',
            'semestre' => (int)$g['semestre'], 'turno' => $g['turno'], 'capacitacion' => $g['capacitacion'],
            'alumnos' => (int)$g['alumnos'], 'pdfs' => [], 'clases' => [], 'maestros' => []];
    }
    $nombre_grupo = fn($id) => isset($G[$id]) ? $G[$id]['nombre'] . ' · ' . $G[$id]['turno'] : 'Grupo';

    foreach ($pdfs as $p) {
        if (!is_file(__DIR__ . '/../' . $p['ruta_pdf'])) continue;
        $item = ['ruta' => $p['ruta_pdf'], 'archivo' => $p['nombre_archivo'], 'ciclo' => $p['ciclo'] . ' ' . $p['anio'],
                 'fecha' => date('d/m/Y', strtotime($p['fecha_subida']))];
        if (isset($M[$p['id_maestro']])) {
            $M[$p['id_maestro']]['pdfs'][] = $item + ['de' => $nombre_grupo($p['id_grupo'])];
            if (($M[$p['id_maestro']]['pdf_general'] ?? null) === $p['ruta_pdf']) unset($M[$p['id_maestro']]['pdf_general']);
        }
        if (isset($G[$p['id_grupo']])) $G[$p['id_grupo']]['pdfs'][] = $item + ['de' => $M[$p['id_maestro']]['nombre'] ?? 'Docente'];
        if (isset($M[$p['id_maestro']], $G[$p['id_grupo']])) $asignaciones[] = ['id_maestro' => $p['id_maestro'], 'id_grupo' => $p['id_grupo']];
    }
    foreach ($M as &$m) {
        if (!empty($m['pdf_general'])) array_unshift($m['pdfs'], ['ruta' => $m['pdf_general'], 'archivo' => basename($m['pdf_general']),
            'ciclo' => 'Horario general', 'fecha' => '', 'de' => 'Subido por el docente']);
        unset($m['pdf_general']);
    }
    unset($m);

    foreach ($clases as $c) {
        if (isset($M[$c['id_maestro']])) $M[$c['id_maestro']]['clases'][] = ['dia' => (int)$c['dia_semana'], 'ini' => $c['ini'], 'fin' => $c['fin'], 'materia' => $c['materia'], 'con' => $nombre_grupo($c['id_grupo'])];
        if (isset($G[$c['id_grupo']])) $G[$c['id_grupo']]['clases'][] = ['dia' => (int)$c['dia_semana'], 'ini' => $c['ini'], 'fin' => $c['fin'], 'materia' => $c['materia'], 'con' => $M[$c['id_maestro']]['nombre'] ?? 'Docente'];
        if (isset($M[$c['id_maestro']], $G[$c['id_grupo']])) $asignaciones[] = ['id_maestro' => $c['id_maestro'], 'id_grupo' => $c['id_grupo']];
    }
    foreach ($asignaciones as $a) {
        if (!isset($M[$a['id_maestro']], $G[$a['id_grupo']])) continue;
        $M[$a['id_maestro']]['grupos'][$a['id_grupo']] = $nombre_grupo($a['id_grupo']);
        $G[$a['id_grupo']]['maestros'][$a['id_maestro']] = $M[$a['id_maestro']]['nombre'];
    }
    foreach ($M as &$m) { $m['grupos'] = array_values($m['grupos']); } unset($m);
    foreach ($G as &$g) { $g['maestros'] = array_values($g['maestros']); } unset($g);

    return ['maestros' => array_values($M), 'grupos' => array_values($G), 'turnos' => $turnos];
}

function dh_render(array $datos, array $ctx = []): void {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $maestros = $datos['maestros']; $grupos = $datos['grupos']; $turnos = $datos['turnos'];
    $con_horario_m = count(array_filter($maestros, fn($m) => $m['pdfs'] || $m['clases']));
    $con_horario_g = count(array_filter($grupos, fn($g) => $g['pdfs'] || $g['clases']));
    $mi_grupo = (int)($ctx['mi_grupo'] ?? 0);
    $mi_maestro = (int)($ctx['mi_maestro'] ?? 0);
    ?>
<link rel="stylesheet" href="../css/directorio_horarios.css?v=3">

<div class="dh" id="dh">
    <div class="dh-resumen">
        <div class="dh-stat"><i class="fas fa-chalkboard-user"></i><div><strong><?php echo $con_horario_m; ?> / <?php echo count($maestros); ?></strong><span>Docentes con horario</span></div></div>
        <div class="dh-stat"><i class="fas fa-users-rectangle"></i><div><strong><?php echo $con_horario_g; ?> / <?php echo count($grupos); ?></strong><span>Grupos con horario</span></div></div>
        <div class="dh-stat"><i class="fas fa-door-open"></i><div><strong><?php echo count($turnos); ?></strong><span>Turnos del plantel</span></div></div>
    </div>

    <div class="dh-card">
        <div class="dh-tabs" role="tablist">
            <button type="button" class="dh-tab activo" role="tab" data-tab="docentes"><i class="fas fa-chalkboard-user"></i> Docentes <span class="dh-n"><?php echo count($maestros); ?></span></button>
            <button type="button" class="dh-tab" role="tab" data-tab="grupos"><i class="fas fa-users-rectangle"></i> Grupos <span class="dh-n"><?php echo count($grupos); ?></span></button>
            <button type="button" class="dh-tab" role="tab" data-tab="turnos"><i class="fas fa-door-open"></i> Entrada al plantel</button>
        </div>

        <div class="dh-toolbar" id="dhToolbar">
            <label class="dh-search"><i class="fas fa-search"></i><input type="search" id="dhBuscar" placeholder="Buscar docente, grupo o materia..." aria-label="Buscar"></label>
            <select class="dh-select" id="dhTurno" aria-label="Filtrar por turno">
                <option value="">Todos los turnos</option><option value="Matutino">Matutino</option><option value="Intermedio">Intermedio</option>
            </select>
            <label class="dh-check"><input type="checkbox" id="dhSoloCon"> Solo con horario</label>
        </div>

        <!-- DOCENTES -->
        <div class="dh-panel" data-panel="docentes">
            <div class="dh-grid">
                <?php foreach ($maestros as $i => $m):
                    $turnos_m = implode(' ', array_unique(array_map(fn($g) => explode(' · ', $g)[1] ?? '', $m['grupos'])));
                    $texto = mb_strtolower($m['nombre'] . ' ' . $m['titulo'] . ' ' . implode(' ', $m['grupos']) . ' ' . implode(' ', array_column($m['clases'], 'materia'))); ?>
                    <button type="button" class="dh-item<?php echo $m['id'] === $mi_maestro ? ' mio' : ''; ?>" data-tipo="maestros" data-i="<?php echo $i; ?>"
                            data-texto="<?php echo $h($texto); ?>" data-turnos="<?php echo $h($turnos_m); ?>" data-con="<?php echo ($m['pdfs'] || $m['clases']) ? 1 : 0; ?>">
                        <span class="dh-avatar"><?php if ($m['foto']): ?><img src="../<?php echo $h($m['foto']); ?>" alt=""><?php else: echo $h($m['iniciales']); endif; ?></span>
                        <span class="dh-item-body">
                            <strong><?php echo $h($m['nombre']); ?></strong>
                            <small><?php echo $h($m['titulo'] ?: ($m['grupos'] ? implode(', ', $m['grupos']) : 'Docente')); ?></small>
                            <span class="dh-badges">
                                <?php if ($m['id'] === $mi_maestro): ?><span class="dh-badge mio"><i class="fas fa-user"></i> Tú</span><?php endif; ?>
                                <?php if ($m['pdfs']): ?><span class="dh-badge pdf"><i class="fas fa-file-pdf"></i> <?php echo count($m['pdfs']); ?> PDF</span><?php endif; ?>
                                <?php if ($m['clases']): ?><span class="dh-badge cls"><i class="far fa-clock"></i> <?php echo count($m['clases']); ?> clases/sem</span><?php endif; ?>
                                <?php if ($m['grupos']): ?><span class="dh-badge"><i class="fas fa-users"></i> <?php echo count($m['grupos']); ?> grupos</span><?php endif; ?>
                                <?php if (!$m['pdfs'] && !$m['clases']): ?><span class="dh-badge sin">Sin horario registrado</span><?php endif; ?>
                            </span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- GRUPOS -->
        <div class="dh-panel" data-panel="grupos" hidden>
            <div class="dh-grid">
                <?php foreach ($grupos as $i => $g):
                    $texto = mb_strtolower($g['nombre'] . ' ' . $g['semestre'] . $g['turno'] . ' ' . $g['turno'] . ' ' . $g['capacitacion'] . ' ' . implode(' ', $g['maestros']) . ' ' . implode(' ', array_column($g['clases'], 'materia'))); ?>
                    <button type="button" class="dh-item<?php echo $g['id'] === $mi_grupo ? ' mio' : ''; ?>" data-tipo="grupos" data-i="<?php echo $i; ?>"
                            data-texto="<?php echo $h($texto); ?>" data-turnos="<?php echo $h($g['turno']); ?>" data-con="<?php echo ($g['pdfs'] || $g['clases']) ? 1 : 0; ?>">
                        <span class="dh-avatar grupo"><?php echo $g['semestre']; ?>°</span>
                        <span class="dh-item-body">
                            <strong>Grupo <?php echo $h($g['nombre']); ?> · <?php echo $h($g['turno']); ?></strong>
                            <small><?php echo $g['alumnos']; ?> alumnos<?php echo $g['capacitacion'] ? ' · ' . $h($g['capacitacion']) : ''; ?></small>
                            <span class="dh-badges">
                                <?php if ($g['id'] === $mi_grupo): ?><span class="dh-badge mio"><i class="fas fa-star"></i> Mi grupo</span><?php endif; ?>
                                <?php if ($g['pdfs']): ?><span class="dh-badge pdf"><i class="fas fa-file-pdf"></i> <?php echo count($g['pdfs']); ?> PDF</span><?php endif; ?>
                                <?php if ($g['clases']): ?><span class="dh-badge cls"><i class="far fa-clock"></i> <?php echo count($g['clases']); ?> clases/sem</span><?php endif; ?>
                                <?php if ($g['maestros']): ?><span class="dh-badge"><i class="fas fa-chalkboard-user"></i> <?php echo count($g['maestros']); ?> docentes</span><?php endif; ?>
                                <?php if (!$g['pdfs'] && !$g['clases']): ?><span class="dh-badge sin">Sin horario registrado</span><?php endif; ?>
                            </span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dh-vacio" id="dhVacio" hidden><i class="fas fa-magnifying-glass"></i>No se encontraron resultados con esos filtros.</div>

        <!-- TURNOS -->
        <div class="dh-panel" data-panel="turnos" hidden>
            <?php if (!$turnos): ?>
                <div class="dh-vacio"><i class="fas fa-door-closed"></i>Control Escolar aún no configura los horarios de entrada.</div>
            <?php else: ?>
                <div class="dh-turnos">
                    <?php foreach ($turnos as $t): ?>
                        <?php $tr = tramos_entrada_turno($t); ?>
                        <div class="dh-turno">
                            <h4><i class="far fa-sun"></i> Turno <?php echo $h($t['turno']); ?> <small class="dh-tol"><?php echo $tr['tolerancia_min']; ?> min de tolerancia</small></h4>
                            <div class="dh-fila"><span>Hora de entrada</span><strong><?php echo date('h:i a', strtotime($t['hora_entrada'])); ?></strong></div>
                            <div class="dh-tramo t-puntual"><span><i class="fas fa-circle-check"></i> Puntual</span><strong><?php echo $tr['puntual']; ?></strong></div>
                            <div class="dh-tramo t-retardo"><span><i class="fas fa-clock"></i> Retardo</span><strong><?php echo $tr['retardo']; ?></strong></div>
                            <div class="dh-tramo t-falta"><span><i class="fas fa-circle-xmark"></i> Falta</span><strong>desde <?php echo $tr['falta']; ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="dh-nota"><i class="fas fa-circle-info"></i> Al escanear la credencial en la entrada: dentro de la tolerancia es <b>Puntual</b>; después de la tolerancia es <b>Retardo</b>, y pasada la hora de corte se registra <b>Falta</b>.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ventana de detalle -->
    <div class="dh-modal" id="dhModal" role="dialog" aria-modal="true" aria-labelledby="dhTitulo">
        <div class="dh-ventana">
            <div class="dh-vhead">
                <span class="dh-avatar" id="dhAvatar"></span>
                <div><h3 id="dhTitulo"></h3><p id="dhSub"></p></div>
                <button type="button" class="dh-cerrar" id="dhCerrar" aria-label="Cerrar"><i class="fas fa-times"></i></button>
            </div>
            <div class="dh-vbody" id="dhCuerpo"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const DATOS = <?php echo json_encode(['maestros' => $maestros, 'grupos' => $grupos], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const HOY = <?php echo (int)date('N'); ?>;
    const AHORA = '<?php echo date('H:i'); ?>';
    const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    const root = document.getElementById('dh');
    const $ = s => root.querySelector(s), $$ = s => [...root.querySelectorAll(s)];
    const esc = t => { const d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; };
    const hora12 = hm => { let [h, m] = hm.split(':').map(Number); const s = h >= 12 ? 'pm' : 'am'; h = h % 12 || 12; return h + ':' + String(m).padStart(2, '0') + ' ' + s; };
    let tab = 'docentes';

    // ---- Pestañas y filtros ----
    function filtrar() {
        const q = $('#dhBuscar').value.trim().toLowerCase();
        const turno = $('#dhTurno').value, solo = $('#dhSoloCon').checked;
        let visibles = 0;
        $$('.dh-panel[data-panel="' + tab + '"] .dh-item').forEach(it => {
            const ok = (!q || it.dataset.texto.includes(q)) && (!turno || it.dataset.turnos.includes(turno)) && (!solo || it.dataset.con === '1');
            it.hidden = !ok; if (ok) visibles++;
        });
        $('#dhVacio').hidden = tab === 'turnos' || visibles > 0;
    }
    $$('.dh-tab').forEach(b => b.addEventListener('click', () => {
        tab = b.dataset.tab;
        $$('.dh-tab').forEach(t => t.classList.toggle('activo', t === b));
        $$('.dh-panel').forEach(p => p.hidden = p.dataset.panel !== tab);
        $('#dhToolbar').hidden = tab === 'turnos';
        $('#dhBuscar').placeholder = tab === 'grupos' ? 'Buscar grupo, docente o materia...' : 'Buscar docente, grupo o materia...';
        filtrar();
    }));
    ['input', 'change'].forEach(ev => { $('#dhBuscar').addEventListener(ev, filtrar); $('#dhTurno').addEventListener(ev, filtrar); $('#dhSoloCon').addEventListener(ev, filtrar); });

    // ---- Detalle ----
    function semana(clases, etiquetaCon) {
        if (!clases.length) return '<div class="dh-sin"><i class="far fa-calendar-xmark"></i> Aún no hay clases semanales registradas en el sistema.</div>';
        const dias = [1, 2, 3, 4, 5];
        [6, 7].forEach(d => { if (clases.some(c => c.dia === d)) dias.push(d); });
        return '<div class="dh-semana" style="--dh-dias:' + dias.length + '">' + dias.map(d => {
            const del = clases.filter(c => c.dia === d);
            return '<div class="dh-dia' + (d === HOY ? ' hoy' : '') + '"><div class="dh-dia-tit">' + DIAS[d] + (d === HOY ? ' · hoy' : '') + '</div>' +
                (del.length ? del.map(c => {
                    const ahora = d === HOY && AHORA >= c.ini && AHORA < c.fin;
                    return '<div class="dh-bloque' + (ahora ? ' ahora' : '') + '"><b>' + hora12(c.ini) + ' – ' + hora12(c.fin) + (ahora ? ' · ahora' : '') + '</b>' +
                        esc(c.materia) + '<em>' + etiquetaCon + ' ' + esc(c.con) + '</em></div>';
                }).join('') : '<div class="dh-libre">Libre</div>') + '</div>';
        }).join('') + '</div>';
    }
    function pdfs(lista) {
        if (!lista.length) return '<div class="dh-sin"><i class="far fa-file"></i> No se ha subido ningún horario en PDF.</div>';
        return '<div class="dh-pdfs">' + lista.map((p, k) =>
            '<div class="dh-pdf"><i class="fas fa-file-pdf"></i><div><strong>' + esc(p.de) + '</strong><span>' + esc(p.ciclo) + (p.fecha ? ' · subido el ' + esc(p.fecha) : '') + '</span></div>' +
            '<button type="button" class="dh-btn primario" data-ver="' + k + '"><i class="far fa-eye"></i> Ver</button>' +
            '<a class="dh-btn" href="../' + esc(p.ruta) + '" target="_blank" rel="noopener" download><i class="fas fa-download"></i> Descargar</a></div>'
        ).join('') + '</div><div class="dh-visor" id="dhVisor" hidden><iframe title="Vista del horario"></iframe></div>';
    }
    function abrir(tipo, i) {
        const x = DATOS[tipo][i];
        const avatar = $('#dhAvatar');
        let cuerpo = '';
        if (tipo === 'maestros') {
            avatar.className = 'dh-avatar';
            avatar.innerHTML = x.foto ? '<img src="../' + esc(x.foto) + '" alt="">' : esc(x.iniciales);
            $('#dhTitulo').textContent = x.nombre;
            $('#dhSub').textContent = x.titulo || 'Docente';
            cuerpo += '<div class="dh-sec"><h4><i class="fas fa-users"></i> Grupos que atiende</h4>' +
                (x.grupos.length ? '<div class="dh-chips">' + x.grupos.map(g => '<span class="dh-chip">' + esc(g) + '</span>').join('') + '</div>' : '<div class="dh-sin">Sin grupos asignados.</div>') + '</div>';
            cuerpo += '<div class="dh-sec"><h4><i class="far fa-calendar-days"></i> Horario semanal de clases</h4>' + semana(x.clases, 'Grupo') + '</div>';
        } else {
            avatar.className = 'dh-avatar grupo';
            avatar.textContent = x.semestre + '°';
            $('#dhTitulo').textContent = 'Grupo ' + x.nombre + ' · ' + x.turno;
            $('#dhSub').textContent = x.alumnos + ' alumnos' + (x.capacitacion ? ' · ' + x.capacitacion : '');
            cuerpo += '<div class="dh-sec"><h4><i class="fas fa-chalkboard-user"></i> Docentes del grupo</h4>' +
                (x.maestros.length ? '<div class="dh-chips">' + x.maestros.map(m => '<span class="dh-chip">' + esc(m) + '</span>').join('') + '</div>' : '<div class="dh-sin">Sin docentes asignados.</div>') + '</div>';
            cuerpo += '<div class="dh-sec"><h4><i class="far fa-calendar-days"></i> Horario semanal de clases</h4>' + semana(x.clases, 'Docente:') + '</div>';
        }
        cuerpo += '<div class="dh-sec"><h4><i class="fas fa-file-pdf"></i> Horarios en PDF</h4>' + pdfs(x.pdfs) + '</div>';
        $('#dhCuerpo').innerHTML = cuerpo;
        $$('#dhCuerpo [data-ver]').forEach(b => b.addEventListener('click', () => {
            const visor = $('#dhVisor');
            visor.querySelector('iframe').src = '../' + x.pdfs[b.dataset.ver].ruta;
            visor.hidden = false;
            visor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }));
        $('#dhModal').classList.add('abierto');
        document.body.style.overflow = 'hidden';
        $('#dhCerrar').focus();
    }
    function cerrar() { $('#dhModal').classList.remove('abierto'); document.body.style.overflow = ''; $('#dhCuerpo').innerHTML = ''; }
    $$('.dh-item').forEach(it => it.addEventListener('click', () => abrir(it.dataset.tipo, Number(it.dataset.i))));
    $('#dhCerrar').addEventListener('click', cerrar);
    $('#dhModal').addEventListener('click', e => { if (e.target.id === 'dhModal') cerrar(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('#dhModal').classList.contains('abierto')) cerrar(); });

    // Abrir directo desde la URL: ?ver=grupo-6 o ?ver=maestro-1
    const ver = new URLSearchParams(location.search).get('ver');
    if (ver) {
        const [t, id] = ver.split('-');
        const tipo = t === 'grupo' ? 'grupos' : 'maestros';
        const i = DATOS[tipo].findIndex(x => String(x.id) === id);
        if (i >= 0) { if (tipo === 'grupos') $('.dh-tab[data-tab="grupos"]').click(); abrir(tipo, i); }
    }
})();
</script>
<?php
}
