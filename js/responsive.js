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
