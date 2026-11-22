<?php
/**
 * includes/topbar_usuario.php
 * Parte derecha del encabezado para Administración y Prefectura:
 * fecha, campana con avisos reales del sistema y menú de perfil con foto.
 *
 * Uso dentro de <header class="topbar">:  <?php topbar_usuario($pdo); ?>
 * (las páginas deben estar en /admin/ o /Prefectura/)
 */

/** Configuración por rol: tabla, columna id, etiqueta y página de perfil. */
function usuario_rol_config(?string $rol = null): array {
    $rol = $rol ?? ($_SESSION['rol'] ?? '');
    $cfg = [
        'administrativo' => ['tabla' => 'ADMINISTRATIVOS', 'id' => 'id_admin',    'etiqueta' => 'Administrador', 'icono' => 'fa-user-tie'],
        'prefectos'      => ['tabla' => 'PREFECTOS',       'id' => 'id_prefecto', 'etiqueta' => 'Prefecto',      'icono' => 'fa-user-shield'],
    ];
    return $cfg[$rol] ?? $cfg['administrativo'];
}

/** Datos del usuario en sesión (incluye la URL de su foto, lista para usar desde /admin o /Prefectura). */
function usuario_actual(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $c = usuario_rol_config();
    $cache = ['nombre' => $_SESSION['nombre'] ?? $c['etiqueta'], 'foto_url' => null, 'fila' => []];
    try {
        $st = $pdo->prepare("SELECT * FROM {$c['tabla']} WHERE {$c['id']} = ?");
        $st->execute([(int)($_SESSION['id_usuario'] ?? 0)]);
        $fila = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $cache['fila'] = $fila;
        if ($fila) $cache['nombre'] = trim($fila['nombre'] . ' ' . $fila['apellido_paterno']);
        $foto = $fila['foto_perfil'] ?? null;
        if ($foto && strpos($foto, 'uploads/') === 0 && is_file(__DIR__ . '/../' . $foto)) {
            $cache['foto_url'] = '../' . $foto . '?v=' . filemtime(__DIR__ . '/../' . $foto);
        }
    } catch (PDOException $e) {
        error_log('usuario_actual: ' . $e->getMessage());
    }
    return $cache;
}

/** Avisos de la campana según el rol. Cada aviso: [icono, color, titulo, texto, enlace] */
function usuario_avisos(PDO $pdo): array {
    $rol = $_SESSION['rol'] ?? '';
    $av = [];
    $q = function (string $sql) use ($pdo) {
        try { return $pdo->query($sql)->fetchColumn(); } catch (PDOException $e) { error_log('avisos: ' . $e->getMessage()); return 0; }
    };

    // Comunes: reportes recientes, cartas compromiso, puntos bajos, suspensiones de clases
    $rep = (int)$q("SELECT COUNT(*) FROM REPORTES WHERE fecha_hora >= NOW() - INTERVAL 7 DAY");
    $cartas = (int)$q("SELECT COUNT(*) FROM HISTORIAL_SANCIONES_ACUM WHERE carta_firmada = 0");
    $bajos = (int)$q("SELECT COUNT(*) FROM ALUMNOS WHERE estatus = 'Alta' AND puntos_conducta < 70");
    $susp = (int)$q("SELECT COUNT(*) FROM ALUMNOS WHERE estado_disciplinario = 'Suspendido'");
    $susp_clases = $q("SELECT CONCAT(motivo, ' · ', DATE_FORMAT(fecha_inicio, '%d/%m/%Y')) FROM SUSPENSIONES_CLASES WHERE fecha_fin >= CURDATE() ORDER BY fecha_inicio LIMIT 1");

    if ($rol === 'administrativo') {
        $sin_grupo = (int)$q("SELECT COUNT(*) FROM ALUMNOS a WHERE a.estatus = 'Alta' AND NOT EXISTS (SELECT 1 FROM GRUPO_ALUMNO ga INNER JOIN GRUPOS g ON g.id_grupo = ga.id_grupo AND g.estatus = 'Activo' WHERE ga.id_alumno = a.id_alumno AND ga.fecha_baja IS NULL)");
        $grupos_sin_doc = (int)$q("SELECT COUNT(*) FROM GRUPOS g WHERE g.estatus = 'Activo' AND NOT EXISTS (SELECT 1 FROM GRUPO_MAESTRO gm WHERE gm.id_grupo = g.id_grupo)");
        $grupos_sin_horario = (int)$q("SELECT COUNT(*) FROM GRUPOS g WHERE g.estatus = 'Activo' AND NOT EXISTS (SELECT 1 FROM HORARIOS_MAESTRO hm WHERE hm.id_grupo = g.id_grupo)");
        if ($sin_grupo)          $av[] = ['fa-user-slash', 'amber', "$sin_grupo alumno(s) sin grupo", 'Están en Alta pero no tienen un grupo activo asignado.', 'gestion_usuarios.php'];
        if ($grupos_sin_doc)     $av[] = ['fa-chalkboard-user', 'blue', "$grupos_sin_doc grupo(s) sin docentes", 'Asigna profesores para que puedan abrir sus aulas.', 'asignacion_masiva.php'];
        if ($grupos_sin_horario) $av[] = ['fa-calendar-xmark', 'gray', "$grupos_sin_horario grupo(s) sin horario", 'Aún no se ha subido el horario de estos grupos.', 'gestion_horarios.php'];
        if ($rep)                $av[] = ['fa-triangle-exclamation', 'red', "$rep reporte(s) de conducta esta semana", 'Revisa los reportes registrados por prefectura y docentes.', 'reportes.php'];
    } else {
        $rep_hoy = (int)$q("SELECT COUNT(*) FROM REPORTES WHERE DATE(fecha_hora) = CURDATE()");
        $faltas_hoy = (int)$q("SELECT COUNT(*) FROM ASISTENCIAS WHERE DATE(fecha_hora_escaneo) = CURDATE() AND estatus = 'Falta'");
        $sin_salida = (int)$q("SELECT COUNT(*) FROM ASISTENCIAS WHERE DATE(fecha_hora_escaneo) = CURDATE() AND tipo_registro = 'Entrada' AND estatus IN ('Puntual','Retardo','Retardo Justificado') AND fecha_hora_salida IS NULL AND CURTIME() > '15:00:00'");
        if ($rep_hoy)    $av[] = ['fa-file-lines', 'red', "$rep_hoy reporte(s) registrados hoy", 'Consulta los reportes en el expediente de cada alumno.', 'expediente_alumnos.php'];
        if ($faltas_hoy) $av[] = ['fa-user-xmark', 'amber', "$faltas_hoy falta(s) registradas hoy", 'Puedes justificarlas desde el historial de asistencias.', 'historial_asistencias.php'];
        if ($sin_salida) $av[] = ['fa-door-open', 'gray', "$sin_salida alumno(s) sin registro de salida", 'Entraron hoy y no tienen salida registrada.', 'historial_asistencias.php'];
        if ($rep && !$rep_hoy) $av[] = ['fa-triangle-exclamation', 'red', "$rep reporte(s) esta semana", 'Revisa los reportes recientes.', 'expediente_alumnos.php'];
    }
    if ($susp)        $av[] = ['fa-ban', 'red', "$susp alumno(s) suspendidos", 'Tienen suspensión disciplinaria vigente.', $rol === 'administrativo' ? 'reportes.php' : 'expediente_alumnos.php'];
    if ($cartas)      $av[] = ['fa-file-signature', 'amber', "$cartas carta(s) compromiso sin firmar", 'Sanciones acumulativas pendientes de firma del tutor.', $rol === 'administrativo' ? 'reportes.php' : 'expediente_alumnos.php'];
    if ($bajos)       $av[] = ['fa-shield-halved', 'amber', "$bajos alumno(s) con menos de 70 puntos", 'Conducta en riesgo: considera citar al tutor.', $rol === 'administrativo' ? 'reportes.php' : 'expediente_alumnos.php'];
    if ($susp_clases) $av[] = ['fa-school-circle-xmark', 'gray', 'Suspensión de clases próxima', $susp_clases, null];
    return $av;
}

function topbar_usuario(PDO $pdo): void {
    $c = usuario_rol_config();
    $u = usuario_actual($pdo);
    $avisos = usuario_avisos($pdo);
    $firma = md5(json_encode(array_column($avisos, 2)));
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ?>
            <div class="topbar-right">
                <div class="date-pill"><i class="far fa-calendar"></i><span class="tbu-fecha"><?php echo date('d/m/Y, h:i a'); ?></span></div>

                <div class="tbu-wrap">
                    <button class="notification-button tbu-btn-campana" type="button" aria-label="Avisos" data-firma="<?php echo $firma; ?>">
                        <i class="far fa-bell"></i>
                        <?php if ($avisos): ?><span class="tbu-count"><?php echo count($avisos) > 9 ? '9+' : count($avisos); ?></span><?php endif; ?>
                    </button>
                    <div class="tbu-menu tbu-avisos" role="menu">
                        <div class="tbu-menu-head"><strong>Avisos</strong><span><?php echo count($avisos); ?> pendiente(s)</span></div>
                        <?php if (!$avisos): ?>
                            <div class="tbu-vacio"><i class="far fa-bell-slash"></i> Todo al día, no hay avisos.</div>
                        <?php else: foreach ($avisos as [$ico, $color, $tit, $txt, $link]): ?>
                            <<?php echo $link ? 'a href="' . $h($link) . '"' : 'div'; ?> class="tbu-aviso">
                                <span class="tbu-ico <?php echo $color; ?>"><i class="fas <?php echo $ico; ?>"></i></span>
                                <span><strong><?php echo $h($tit); ?></strong><small><?php echo $h($txt); ?></small></span>
                            </<?php echo $link ? 'a' : 'div'; ?>>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="tbu-wrap">
                    <div class="profile tbu-btn-perfil" role="button" tabindex="0" aria-label="Menú de perfil">
                        <div class="profile-avatar tbu-avatar">
                            <?php if ($u['foto_url']): ?><img src="<?php echo $h($u['foto_url']); ?>" alt="Foto de perfil"><?php else: ?><i class="fas <?php echo $c['icono']; ?>"></i><?php endif; ?>
                        </div>
                        <div class="profile-info"><strong><?php echo $h($u['nombre']); ?></strong><span><?php echo $c['etiqueta']; ?></span></div>
                        <i class="fas fa-chevron-down tbu-chevron"></i>
                    </div>
                    <div class="tbu-menu tbu-perfil" role="menu">
                        <a href="perfil.php"><i class="fas fa-user-pen"></i> Mi Perfil</a>
                        <a href="perfil.php#foto"><i class="fas fa-camera"></i> Cambiar foto</a>
                        <a href="perfil.php#seguridad"><i class="fas fa-key"></i> Cambiar contraseña</a>
                        <hr>
                        <a href="../logout.php" class="tbu-salir"><i class="fas fa-arrow-right-from-bracket"></i> Cerrar Sesión</a>
                    </div>
                </div>
            </div>
    <?php
    static $assets = false;
    if ($assets) return;
    $assets = true;
    ?>
    <style>
        .tbu-wrap { position: relative; }
        .tbu-btn-perfil { cursor: pointer; user-select: none; }
        .tbu-chevron { font-size: 12px; color: #94a3b8; margin-left: 6px; }
        .tbu-avatar { overflow: hidden; }
        .tbu-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; display: block; }
        .tbu-btn-campana { position: relative; cursor: pointer; }
        .tbu-count { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; display: grid; place-items: center; border: 2px solid #fff; }
        .tbu-menu { display: none; position: absolute; right: 0; top: calc(100% + 10px); background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 18px 40px rgba(15, 23, 42, .14); z-index: 1000; min-width: 220px; overflow: hidden; }
        .tbu-menu.abierto { display: block; animation: tbuIn .15s ease; }
        @keyframes tbuIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        .tbu-perfil a { display: flex; align-items: center; gap: 10px; padding: 11px 16px; color: #334155; text-decoration: none; font-size: .9rem; font-weight: 600; }
        .tbu-perfil a:hover { background: #f1f5f9; color: #047857; }
        .tbu-perfil a i { width: 18px; text-align: center; color: #10b981; }
        .tbu-perfil hr { margin: 0; border: none; border-top: 1px solid #e2e8f0; }
        .tbu-perfil .tbu-salir, .tbu-perfil .tbu-salir i { color: #ef4444; }
        .tbu-avisos { width: 340px; max-width: calc(100vw - 32px); max-height: 420px; overflow-y: auto; }
        .tbu-menu-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: .9rem; color: #0f172a; }
        .tbu-menu-head span { font-size: .75rem; color: #64748b; }
        .tbu-aviso { display: flex; gap: 12px; padding: 11px 16px; border-bottom: 1px solid #f8fafc; text-decoration: none; color: inherit; }
        a.tbu-aviso:hover { background: #f8fafc; }
        .tbu-aviso strong { display: block; font-size: .84rem; color: #0f172a; }
        .tbu-aviso small { display: block; font-size: .76rem; color: #64748b; margin-top: 2px; line-height: 1.35; }
        .tbu-ico { flex: 0 0 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: .9rem; }
        .tbu-ico.red { background: #fef2f2; color: #dc2626; } .tbu-ico.amber { background: #fffbeb; color: #d97706; }
        .tbu-ico.blue { background: #eff6ff; color: #2563eb; } .tbu-ico.gray { background: #f1f5f9; color: #475569; } .tbu-ico.green { background: #ecfdf5; color: #059669; }
        .tbu-vacio { padding: 22px 16px; text-align: center; color: #64748b; font-size: .85rem; }
    </style>
    <script>
    (function () {
        function cerrarTodos(excepto) { document.querySelectorAll('.tbu-menu.abierto').forEach(m => { if (m !== excepto) m.classList.remove('abierto'); }); }
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.tbu-btn-campana, .tbu-btn-perfil');
            if (btn) {
                const menu = btn.parentElement.querySelector('.tbu-menu');
                cerrarTodos(menu);
                menu.classList.toggle('abierto');
                if (btn.classList.contains('tbu-btn-campana')) {
                    try { localStorage.setItem('tbu_avisos_vistos', btn.dataset.firma); } catch (err) {}
                    const c = btn.querySelector('.tbu-count'); if (c) c.style.display = 'none';
                }
                return;
            }
            if (!e.target.closest('.tbu-menu')) cerrarTodos(null);
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarTodos(null); });
        // Si ya vio estos mismos avisos, no mostrar el contador
        document.querySelectorAll('.tbu-btn-campana').forEach(b => {
            try { if (localStorage.getItem('tbu_avisos_vistos') === b.dataset.firma) { const c = b.querySelector('.tbu-count'); if (c) c.style.display = 'none'; } } catch (err) {}
        });
        // Reloj
        function reloj() {
            const t = new Date().toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            document.querySelectorAll('.tbu-fecha').forEach(el => el.textContent = t);
        }
        reloj(); setInterval(reloj, 30000);
    })();
    </script>
    <?php
}
