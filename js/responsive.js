/**
 * js/responsive.js — Utilidades responsivas compartidas por todos los portales:
 *  - Garantiza el botón de menú (☰) en móvil aunque la página no lo tenga.
 *  - Abre/cierra la barra lateral con el overlay, la tecla Esc y al tocar un enlace.
 *  - Marca las tablas para mostrarse como tarjetas en teléfono (etiqueta por columna).
 */
(function () {
    function init() {
        const body = document.body;
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            let overlay = document.getElementById('sidebarOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                overlay.id = 'sidebarOverlay';
                sidebar.after(overlay);
            }
            let btn = document.getElementById('mobileMenu') || document.querySelector('.mobile-menu');
            const propio = !btn;
            if (!btn) {
                btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mobile-menu';
                btn.id = 'mobileMenu';
                btn.setAttribute('aria-label', 'Abrir menú');
                btn.innerHTML = '<i class="fas fa-bars"></i>';
                const destino = document.querySelector('.topbar-left, .group-topbar-left, .header-left-title, .page-header-title, .topbar, .main-content');
                if (destino) destino.prepend(btn);
            }
            const abrir = () => { sidebar.classList.add('mobile-open'); overlay.classList.add('visible'); body.classList.add('menu-open'); };
            const cerrar = () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('visible'); body.classList.remove('menu-open'); };
            // Si la página ya tenía su botón, solo abrimos (idempotente) para no chocar con su propio manejador
            btn.addEventListener('click', e => { e.preventDefault(); (propio && sidebar.classList.contains('mobile-open')) ? cerrar() : abrir(); });
            overlay.addEventListener('click', cerrar);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });
            sidebar.querySelectorAll('a.nav-item').forEach(a => a.addEventListener('click', () => { if (window.innerWidth <= 900) cerrar(); }));
            window.addEventListener('resize', () => { if (window.innerWidth > 992) cerrar(); });
        }

        // Tablas como tarjetas en teléfono
        document.querySelectorAll('table').forEach(t => {
            if (t.classList.contains('no-tarjetas') || t.closest('.no-tarjetas')) return;
            const ths = t.querySelectorAll('thead th');
            if (ths.length < 3) return;
            const etiquetas = Array.from(ths).map(th => th.textContent.trim());
            t.querySelectorAll('tbody tr').forEach(tr => {
                Array.from(tr.children).forEach((td, i) => {
                    if (td.hasAttribute('data-label') || td.hasAttribute('colspan')) return;
                    const et = etiquetas[i] || '';
                    // La columna de acciones ocupa todo el ancho, sin etiqueta
                    if (/^acci|^opciones$/i.test(et) || (et === '' && td.querySelector('a, button, form'))) { td.setAttribute('data-label', ''); td.classList.add('td-acciones'); }
                    else td.setAttribute('data-label', et);
                });
            });
            t.classList.add('tabla-tarjetas');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/**
 * Diálogos con el estilo del sistema (reemplazan confirm() y alert() del navegador).
 *  - sigeConfirm(mensaje, {titulo, aceptar, peligro}) → Promise<boolean>
 *  - sigeAlert(mensaje, tipo)  (tipo: 'info' | 'error' | 'exito' | 'aviso')
 *  - Convierte automáticamente onclick="return confirm('...')" y onsubmit="return confirm('...')".
 */
(function () {
    const css = `
    .sd-ov{position:fixed;inset:0;z-index:20000;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:16px;animation:sdF .15s ease;}
    @keyframes sdF{from{opacity:0}to{opacity:1}} @keyframes sdU{from{transform:translateY(14px) scale(.98);opacity:.3}to{transform:none;opacity:1}}
    .sd-box{background:#fff;width:100%;max-width:420px;border-radius:20px;box-shadow:0 28px 60px rgba(0,0,0,.25);overflow:hidden;animation:sdU .2s ease;font-family:'Inter',system-ui,sans-serif;}
    .sd-body{padding:24px 24px 8px;text-align:center;}
    .sd-ico{width:58px;height:58px;border-radius:18px;margin:0 auto 14px;display:grid;place-items:center;font-size:1.4rem;}
    .sd-ico.info{background:#eff6ff;color:#2563eb}.sd-ico.exito{background:#ecfdf5;color:#059669}.sd-ico.aviso{background:#fffbeb;color:#d97706}.sd-ico.error,.sd-ico.peligro{background:#fef2f2;color:#dc2626}.sd-ico.pregunta{background:#ecfdf5;color:#047857}
    .sd-box h3{margin:0 0 6px;font-size:1.08rem;color:#0f172a;font-weight:800;}
    .sd-box p{margin:0;color:#475569;font-size:.92rem;line-height:1.5;white-space:pre-line;}
    .sd-acc{display:flex;gap:10px;padding:18px 24px 22px;}
    .sd-acc button{flex:1;padding:12px 14px;border-radius:12px;font-weight:700;font-size:.92rem;cursor:pointer;font-family:inherit;border:1px solid transparent;transition:.15s;}
    .sd-no{background:#fff;border-color:#cbd5e1 !important;color:#334155;} .sd-no:hover{background:#f8fafc;}
    .sd-si{background:#16a05d;color:#fff;} .sd-si:hover{background:#0f7a45;}
    .sd-si.peligro{background:#dc2626;} .sd-si.peligro:hover{background:#b91c1c;}
    .sd-acc button:focus-visible{outline:3px solid #a7f3d0;outline-offset:2px;}
    @media (max-width:480px){.sd-ov{align-items:flex-end;padding:0}.sd-box{max-width:none;border-radius:20px 20px 0 0}.sd-acc{flex-direction:column-reverse}}`;
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const PELIGRO = /eliminar|borrar|quitar|baja|bloquear|restablecer/i;

    function dialogo({ mensaje, titulo, tipo, aceptar, cancelar }) {
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.className = 'sd-ov';
            ov.setAttribute('role', 'alertdialog');
            ov.setAttribute('aria-modal', 'true');
            const iconos = { info: 'fa-circle-info', exito: 'fa-circle-check', aviso: 'fa-triangle-exclamation', error: 'fa-circle-exclamation', peligro: 'fa-trash-can', pregunta: 'fa-circle-question' };
            ov.innerHTML = '<div class="sd-box"><div class="sd-body"><div class="sd-ico ' + tipo + '"><i class="fas ' + (iconos[tipo] || iconos.info) + '"></i></div>'
                + '<h3>' + esc(titulo) + '</h3><p>' + esc(mensaje) + '</p></div><div class="sd-acc">'
                + (cancelar ? '<button type="button" class="sd-no">' + esc(cancelar) + '</button>' : '')
                + '<button type="button" class="sd-si ' + (tipo === 'peligro' ? 'peligro' : '') + '">' + esc(aceptar) + '</button></div></div>';
            const tecla = e => { if (e.key === 'Escape') { e.preventDefault(); cerrar(false); } };
            const cerrar = v => { document.removeEventListener('keydown', tecla, true); ov.remove(); resolve(v); };
            ov.querySelector('.sd-si').onclick = () => cerrar(true);
            const no = ov.querySelector('.sd-no'); if (no) no.onclick = () => cerrar(false);
            ov.addEventListener('click', e => { if (e.target === ov) cerrar(false); });
            document.addEventListener('keydown', tecla, true);
            document.body.appendChild(ov);
            setTimeout(() => ov.querySelector(cancelar ? '.sd-no' : '.sd-si').focus(), 30);
        });
    }

    window.sigeConfirm = (mensaje, o = {}) => {
        const peligro = o.peligro ?? PELIGRO.test(mensaje);
        return dialogo({ mensaje, titulo: o.titulo || (peligro ? 'Confirmar acción' : '¿Deseas continuar?'), tipo: peligro ? 'peligro' : 'pregunta',
                         aceptar: o.aceptar || (peligro ? 'Sí, continuar' : 'Aceptar'), cancelar: o.cancelar || 'Cancelar' });
    };
    window.sigeAlert = (mensaje, tipo) => {
        tipo = tipo || (/error|no se pudo|hubo un/i.test(mensaje) ? 'error' : (/m[aá]ximo|selecciona|pesa|debes/i.test(mensaje) ? 'aviso' : 'info'));
        const titulos = { info: 'Aviso', exito: 'Listo', aviso: 'Revisa la información', error: 'Ocurrió un problema' };
        return dialogo({ mensaje, titulo: titulos[tipo] || 'Aviso', tipo, aceptar: 'Entendido' });
    };
    // alert() no devuelve valor: se reemplaza sin cambiar el comportamiento de las páginas
    window.alert = m => { window.sigeAlert(String(m)); };

    // Convierte onclick/onsubmit="return confirm('...')" en diálogos del sistema
    function convertir(raiz) {
        raiz.querySelectorAll('[onclick*="confirm("], [onsubmit*="confirm("]').forEach(el => {
            const attr = (el.getAttribute('onsubmit') || '').includes('confirm(') ? 'onsubmit' : 'onclick';
            const codigo = el.getAttribute(attr) || '';
            const m = codigo.match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/);
            if (!m) return;
            const mensaje = m[2].replace(/\\(['"])/g, '$1');
            el.removeAttribute(attr);
            if (attr === 'onsubmit') {
                el.addEventListener('submit', async e => {
                    if (el.dataset.sdOk === '1') { delete el.dataset.sdOk; return; }
                    e.preventDefault();
                    if (await window.sigeConfirm(mensaje)) { el.dataset.sdOk = '1'; if (el.requestSubmit) el.requestSubmit(e.submitter || undefined); else el.submit(); }
                });
            } else {
                el.addEventListener('click', async e => {
                    if (el.dataset.sdOk === '1') { delete el.dataset.sdOk; return; }
                    e.preventDefault(); e.stopImmediatePropagation();
                    if (!(await window.sigeConfirm(mensaje))) return;
                    if (el.tagName === 'A' && el.href) { window.location.href = el.href; return; }
                    el.dataset.sdOk = '1'; el.click();
                }, true);
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => convertir(document));
    else convertir(document);
})();

/* Mensaje ?exito=... en páginas que no lo muestran por sí mismas (p. ej. tras cambiar la contraseña) */
(function () {
    function avisarExito() {
        const msg = new URLSearchParams(location.search).get('exito');
        if (!msg || !window.sigeAlert) return;
        if (document.body && document.body.innerText.includes(msg)) return;
        window.sigeAlert(msg, 'exito');
        const u = new URL(location.href); u.searchParams.delete('exito'); history.replaceState(null, '', u);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avisarExito); else avisarExito();
})();

/**
 * Menú lateral compacto/desplegado — un solo manejador para TODOS los portales
 * (Administración, Prefectura, Docente y Alumno).
 *  - Se registra en fase de captura sobre document: corre antes que los manejadores antiguos de cada
 *    página y los detiene, así el botón nunca se activa dos veces (lo que lo dejaba "trabado").
 *  - Recuerda el estado (localStorage) al cambiar de página.
 *  - En modo compacto, cada ícono muestra su nombre al pasar el mouse.
 */
(function () {
    if (window.__sigeMenuCompacto) return;
    window.__sigeMenuCompacto = true;
    const CLAVE = 'sidebarCollapsed';
    function titulos(compacto) {
        document.querySelectorAll('#sidebar .nav-item, #sidebar .logout, #sidebar .nav-link, #sidebar nav a').forEach(a => {
            const s = a.querySelector('span');
            if (!s || !s.textContent.trim()) return;
            if (compacto) a.setAttribute('title', s.textContent.trim()); else if (a.dataset.tituloPropio !== '1') a.removeAttribute('title');
        });
        const btn = document.getElementById('collapseToggle');
        if (btn) { const t = compacto ? 'Expandir menú' : 'Compactar menú'; btn.setAttribute('aria-label', t); btn.setAttribute('title', t); }
    }
    // El modo compacto solo existe en escritorio: si el botón de compactar está oculto (teléfono/tablet),
    // el menú siempre se abre completo aunque en la computadora se haya dejado compacto.
    function enEscritorio() {
        const tog = document.getElementById('collapseToggle');
        return !!tog && getComputedStyle(tog).display !== 'none' && tog.offsetWidth > 0;
    }
    function aplicar() {
        if (!document.getElementById('collapseToggle')) return;
        let guardado = false;
        try { guardado = localStorage.getItem(CLAVE) === 'true'; } catch (e) {}
        const compacto = guardado && enEscritorio();
        document.body.classList.toggle('sidebar-collapsed', compacto);
        titulos(compacto);
    }
    let tReajuste;
    window.addEventListener('resize', () => { clearTimeout(tReajuste); tReajuste = setTimeout(aplicar, 120); });
    document.addEventListener('click', e => {
        const t = e.target.closest ? e.target.closest('#collapseToggle') : null;
        if (!t) return;
        e.preventDefault();
        e.stopPropagation();
        const compacto = document.body.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem(CLAVE, compacto ? 'true' : 'false'); } catch (err) {}
        titulos(compacto);
    }, true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', aplicar); else aplicar();
})();

/**
 * Cierre de sesión por inactividad (todos los roles).
 *  - El servidor envía la cookie "sige_inactividad" (minutos) solo cuando hay sesión iniciada.
 *  - Cuenta como actividad: mover el mouse, teclear, hacer clic, desplazarse o tocar la pantalla.
 *  - Mientras el usuario sigue trabajando en la misma página, se avisa al servidor cada pocos minutos
 *    para que la sesión no expire (por ejemplo, al escribir una tarea larga).
 *  - 1 minuto antes muestra un aviso con cuenta regresiva: "Seguir conectado" o "Cerrar sesión".
 *  - Varias pestañas abiertas comparten la actividad: si usas una, las demás no se cierran.
 *  - Páginas tipo kiosco (p. ej. el escáner QR) usan <body data-sin-inactividad>: no muestran aviso y
 *    mantienen la sesión mientras estén abiertas.
 */
(function () {
    const m = document.cookie.match(/(?:^|;\s*)sige_inactividad=(\d+)/);
    if (!m) return;
    if (/\/(login\.php|index\.html|error\.php)?$/.test(location.pathname) && !document.getElementById('sidebar')) return;
    const LIMITE = parseInt(m[1], 10) * 60000, AVISO = 60000, PING_CADA = 4 * 60000;
    const src = (document.currentScript && document.currentScript.src) || '';
    const base = src.replace(/js\/responsive\.js.*$/, '');
    const CLAVE = 'sige_ultima_actividad';
    const leer = () => { try { return parseInt(localStorage.getItem(CLAVE), 10) || Date.now(); } catch (e) { return Date.now(); } };
    const guardar = t => { try { localStorage.setItem(CLAVE, String(t)); } catch (e) {} };
    let ultimoPing = Date.now(), cerrando = false, aviso = null, contador = null;
    guardar(Date.now());   // cargar una página ya es actividad (el servidor también la registró)

    function ping() {
        ultimoPing = Date.now();
        return fetch(base + 'procesos/mantener_sesion.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }, cache: 'no-store' })
            .then(r => r.json().then(d => { if (!r.ok || !d.ok) cerrar(); }))
            .catch(() => {});
    }
    function cerrar() {
        if (cerrando) return;
        cerrando = true;
        location.href = base + 'logout.php?inactividad=1';
    }

    const quiosco = document.body && document.body.hasAttribute('data-sin-inactividad');
    if (quiosco) { setInterval(ping, PING_CADA); return; }

    function crearAviso() {
        const st = document.createElement('style');
        st.textContent = `
        .ina-ov{position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);z-index:20000;display:none;align-items:center;justify-content:center;padding:16px;font-family:'Inter',system-ui,sans-serif;}
        .ina-ov.abierto{display:flex;}
        .ina-box{background:#fff;border-radius:22px;max-width:400px;width:100%;padding:28px 24px 22px;text-align:center;box-shadow:0 30px 70px rgba(0,0,0,.3);}
        .ina-reloj{width:86px;height:86px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#16a05d var(--p,100%),#e2e8f0 0);}
        .ina-reloj span{width:70px;height:70px;border-radius:50%;background:#fff;display:grid;place-items:center;font-size:1.6rem;font-weight:800;color:#063f2b;}
        .ina-box h3{margin:0 0 6px;font-size:1.15rem;color:#0f172a;}
        .ina-box p{margin:0 0 18px;color:#64748b;font-size:.9rem;line-height:1.5;}
        .ina-acc{display:flex;gap:10px;flex-wrap:wrap;}
        .ina-acc button{flex:1;min-width:140px;padding:12px 16px;border-radius:12px;font-weight:700;font-size:.9rem;cursor:pointer;font-family:inherit;border:none;}
        .ina-seguir{background:#16a05d;color:#fff;}
        .ina-salir{background:#fff;color:#b91c1c;border:1px solid #fecaca !important;}`;
        document.head.appendChild(st);
        aviso = document.createElement('div');
        aviso.className = 'ina-ov';
        aviso.setAttribute('role', 'alertdialog');
        aviso.setAttribute('aria-modal', 'true');
        aviso.innerHTML = '<div class="ina-box"><div class="ina-reloj"><span id="inaSeg">60</span></div>'
            + '<h3>¿Sigues ahí?</h3><p>Por seguridad, tu sesión se cerrará en <b id="inaTexto">60 segundos</b> por inactividad.</p>'
            + '<div class="ina-acc"><button type="button" class="ina-salir" id="inaSalir">Cerrar sesión</button>'
            + '<button type="button" class="ina-seguir" id="inaSeguir">Seguir conectado</button></div></div>';
        document.body.appendChild(aviso);
        aviso.querySelector('#inaSeguir').addEventListener('click', () => { guardar(Date.now()); ocultarAviso(); ping(); });
        aviso.querySelector('#inaSalir').addEventListener('click', () => { location.href = base + 'logout.php'; });
    }
    function mostrarAviso(restante) {
        if (!aviso) crearAviso();
        const s = Math.max(0, Math.ceil(restante / 1000));
        aviso.querySelector('#inaSeg').textContent = s;
        aviso.querySelector('#inaTexto').textContent = s + (s === 1 ? ' segundo' : ' segundos');
        aviso.querySelector('.ina-reloj').style.setProperty('--p', Math.round(restante / AVISO * 100) + '%');
        if (!aviso.classList.contains('abierto')) { aviso.classList.add('abierto'); aviso.querySelector('#inaSeguir').focus(); }
    }
    function ocultarAviso() { if (aviso) aviso.classList.remove('abierto'); }
    const avisoAbierto = () => aviso && aviso.classList.contains('abierto');

    // Actividad del usuario (se ignora mientras el aviso está abierto: ahí debe elegir una opción)
    let ultimaMarca = 0;
    const marcar = () => {
        if (avisoAbierto()) return;
        const ahora = Date.now();
        if (ahora - ultimaMarca < 5000) return;
        ultimaMarca = ahora;
        guardar(ahora);
        if (ahora - ultimoPing > PING_CADA) ping();
    };
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach(ev => window.addEventListener(ev, marcar, { passive: true, capture: true }));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) revisar(); });
    window.addEventListener('storage', e => { if (e.key === CLAVE && avisoAbierto() && Date.now() - leer() < LIMITE - AVISO) ocultarAviso(); });

    function revisar() {
        if (cerrando) return;
        const inactivo = Date.now() - leer();
        if (inactivo >= LIMITE) { ocultarAviso(); cerrar(); }
        else if (inactivo >= LIMITE - AVISO) mostrarAviso(LIMITE - inactivo);
        else if (avisoAbierto()) ocultarAviso();
    }
    contador = setInterval(revisar, 1000);
})();

/**
 * Encabezado en teléfono: la campana y la foto de perfil quedan a la misma altura que el botón de menú (☰).
 * Cada página arma su encabezado distinto; aquí se mide y se corrige la posición (solo en pantallas chicas).
 */
(function () {
    function alinear() {
        const der = document.querySelector('.topbar-right');
        if (!der) return;
        der.style.top = '';
        der.style.marginTop = '';
        if (window.innerWidth > 900) return;
        const menu = [...document.querySelectorAll('#mobileMenu, .mobile-menu')].find(b => b.offsetWidth > 0 && b.offsetHeight > 0);
        if (!menu) return;
        const a = menu.getBoundingClientRect(), b = der.getBoundingClientRect();
        const dif = Math.round((a.top + a.height / 2) - (b.top + b.height / 2));
        if (Math.abs(dif) <= 2) return;
        const pos = getComputedStyle(der).position;
        if (pos === 'absolute' || pos === 'fixed') der.style.top = ((parseFloat(getComputedStyle(der).top) || 0) + dif) + 'px';
        else if (dif < 0 && menu.closest('main, .main-content') === der.closest('main, .main-content')) {
            // Está debajo del menú (en otra fila): se sube a la fila del menú
            der.style.position = 'absolute'; der.style.right = '0';
            const b2 = der.getBoundingClientRect();
            der.style.top = ((parseFloat(getComputedStyle(der).top) || 0) + Math.round((a.top + a.height / 2) - (b2.top + b2.height / 2))) + 'px';
        }
    }
    let t;
    const programar = () => { clearTimeout(t); t = setTimeout(alinear, 60); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', alinear); else alinear();
    window.addEventListener('load', alinear);
    window.addEventListener('resize', programar);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(alinear);
})();

/**
 * Listas largas: toda tabla con más de 10 filas queda dentro de un contenedor con su propia barra de
 * desplazamiento (encabezado fijo arriba) y, si su sección no tiene ya un buscador, se le agrega uno
 * para filtrar por nombre o matrícula. Funciona también con tablas que se cargan después (AJAX).
 * Para excluir una tabla: <table data-sin-scroll> (sin contenedor) o <table data-sin-buscador>.
 */
(function () {
    const MIN_FILAS = 10;
    const norm = s => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const filas = t => t.tBodies.length ? [...t.tBodies].flatMap(b => [...b.rows]) : [...t.rows].slice(1);

    function contenedorDe(t) {
        // Si la tabla ya vive en un envoltorio horizontal propio (overflow-x), se reutiliza ese mismo
        let el = t.parentElement;
        for (let i = 0; i < 3 && el && !el.matches('main, .main-content, body'); i++, el = el.parentElement) {
            const cs = getComputedStyle(el);
            if (/(auto|scroll)/.test(cs.overflowX) || /(auto|scroll)/.test(cs.overflowY)) {
                const otros = [...el.children].filter(c => c !== t && !c.contains(t) && c.offsetHeight > 0);
                if (!otros.length) return el;
                break;
            }
        }
        const w = document.createElement('div');
        t.parentNode.insertBefore(w, t);
        w.appendChild(t);
        return w;
    }

    function tieneBuscador(t, cont) {
        const caja = t.closest('section, .card, .panel, .table-card, .content-card, [class*="card"], .main-content > div') || cont.parentElement;
        return !!(caja && caja.querySelector('input[type=search], input[placeholder*="uscar" i], input[id*="busc" i], input[name="q"], input[name*="busc" i]'));
    }

    function procesar(t) {
        if (t.dataset.sigeLista || t.closest('.fm-overlay, .sd-overlay, .tbu-menu, .modal, .modal-overlay')) return;
        const lista = filas(t);
        if (lista.length <= MIN_FILAS) return;
        t.dataset.sigeLista = '1';
        let cont = null;
        if (!t.hasAttribute('data-sin-scroll')) {
            cont = contenedorDe(t);
            cont.classList.add('sige-tabla-scroll');
        }
        if (t.hasAttribute('data-sin-buscador') || tieneBuscador(t, cont || t.parentElement)) return;
        const barra = document.createElement('div');
        barra.className = 'sige-buscador';
        barra.innerHTML = '<i class="fas fa-magnifying-glass"></i><input type="search" placeholder="Buscar por nombre o matrícula…" aria-label="Buscar en la lista"><span class="sige-buscador-n"></span>';
        (cont || t).parentNode.insertBefore(barra, cont || t);
        const input = barra.querySelector('input'), n = barra.querySelector('.sige-buscador-n');
        const total = () => filas(t).filter(r => !r.querySelector('td[colspan]')).length;
        const contar = vis => { n.textContent = input.value.trim() ? vis + ' de ' + total() : total() + ' en la lista'; };
        contar(total());
        let sinRes = null;
        input.addEventListener('input', () => {
            const q = norm(input.value.trim());
            let vis = 0;
            filas(t).forEach(r => {
                if (r.classList.contains('sige-sin-resultados')) return;
                const ok = !q || norm(r.textContent).includes(q);
                r.style.display = ok ? '' : 'none';
                if (ok && !r.querySelector('td[colspan]')) vis++;
            });
            if (!sinRes) {
                sinRes = document.createElement('tr');
                sinRes.className = 'sige-sin-resultados';
                sinRes.innerHTML = '<td colspan="99">No hay coincidencias con la búsqueda.</td>';
                (t.tBodies[0] || t).appendChild(sinRes);
            }
            sinRes.style.display = q && !vis ? '' : 'none';
            contar(vis);
            if (cont) cont.scrollTop = 0;
        });
    }

    function revisar(raiz) { (raiz || document).querySelectorAll('main table, .main-content table, .main table, .content table').forEach(procesar); }
    function iniciar() {
        revisar();
        const main = document.querySelector('main, .main-content');
        if (main && window.MutationObserver) {
            let t;
            new MutationObserver(() => { clearTimeout(t); t = setTimeout(revisar, 250); }).observe(main, { childList: true, subtree: true });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
