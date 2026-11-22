/**
 * js/ficha_modal.js — Ventana emergente de perfil (Administración, Prefectura y Alumno).
 * Uso: <button data-ficha-tipo="maestros" data-ficha-id="3">…</button>
 * Los datos vienen de ../procesos/ficha_usuario.php
 */
(function () {
    const css = `
    .fm-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);z-index:10000;display:none;align-items:center;justify-content:center;padding:16px;}
    .fm-overlay.abierto{display:flex;animation:fmFade .18s ease;}
    @keyframes fmFade{from{opacity:0}to{opacity:1}}
    @keyframes fmUp{from{transform:translateY(18px);opacity:.4}to{transform:none;opacity:1}}
    .fm-box{background:#fff;width:100%;max-width:520px;max-height:90vh;overflow:auto;border-radius:22px;box-shadow:0 30px 70px rgba(0,0,0,.28);animation:fmUp .22s ease;font-family:'Inter',system-ui,sans-serif;}
    .fm-head{position:relative;padding:28px 24px 18px;text-align:center;background:linear-gradient(160deg,#063f2b,#0f7a45 60%,#16a05d);color:#fff;border-radius:22px 22px 0 0;}
    .fm-cerrar{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:50%;border:none;background:rgba(255,255,255,.18);color:#fff;font-size:1rem;cursor:pointer;}
    .fm-cerrar:hover{background:rgba(255,255,255,.3);}
    .fm-foto{width:96px;height:96px;border-radius:50%;margin:0 auto 12px;border:4px solid rgba(255,255,255,.85);background:#ecfdf5;color:#047857;display:grid;place-items:center;font-size:2rem;font-weight:800;overflow:hidden;}
    .fm-foto img{width:100%;height:100%;object-fit:cover;}
    .fm-head h3{margin:0;font-size:1.2rem;font-weight:800;line-height:1.25;}
    .fm-head p{margin:4px 0 0;font-size:.85rem;color:#d1fae5;}
    .fm-tags{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:12px;}
    .fm-tags span{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:20px;}
    .fm-body{padding:18px 22px 6px;}
    .fm-bio{background:#f8fafc;border-left:3px solid #10b981;border-radius:10px;padding:10px 14px;font-size:.86rem;color:#334155;line-height:1.5;margin-bottom:14px;white-space:pre-line;}
    .fm-sec{margin-bottom:14px;}
    .fm-sec h4{margin:0 0 8px;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#64748b;}
    .fm-dato{display:flex;gap:12px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:.88rem;}
    .fm-dato:last-child{border-bottom:none;}
    .fm-dato i{width:30px;height:30px;flex:0 0 30px;border-radius:9px;background:#ecfdf5;color:#059669;display:grid;place-items:center;font-size:.8rem;}
    .fm-dato small{display:block;color:#64748b;font-size:.72rem;}
    .fm-dato b{color:#0f172a;font-weight:600;word-break:break-word;}
    .fm-dato .vacio{color:#94a3b8;font-weight:500;font-style:italic;}
    .fm-lista{display:flex;flex-wrap:wrap;gap:6px;}
    .fm-lista span{background:#f1f5f9;color:#334155;font-size:.78rem;font-weight:600;padding:5px 10px;border-radius:8px;}
    .fm-acciones{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:14px 22px 20px;}
    .fm-acciones a{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:12px;background:#16a05d;color:#fff;font-weight:700;font-size:.85rem;text-decoration:none;}
    .fm-acciones a.sec{background:#fff6e6;color:#b45309;}
    .fm-cargando{padding:50px;text-align:center;color:#64748b;}
    @media (max-width:560px){.fm-overlay{padding:0;align-items:flex-end;}.fm-box{max-width:none;border-radius:22px 22px 0 0;max-height:92vh;}.fm-acciones a{flex:1;justify-content:center;}}
    [data-ficha-tipo]{cursor:pointer;}`;
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

    const ov = document.createElement('div');
    ov.className = 'fm-overlay';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.innerHTML = '<div class="fm-box"></div>';
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => document.body.appendChild(ov));
    else document.body.appendChild(ov);
    const box = () => ov.querySelector('.fm-box');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function cerrar() { ov.classList.remove('abierto'); document.body.style.overflow = ''; }
    ov.addEventListener('click', e => { if (e.target === ov || e.target.closest('.fm-cerrar')) cerrar(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });

    function render(d) {
        const ini = d.nombre.split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase();
        let h = `<div class="fm-head"><button class="fm-cerrar" aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
            <div class="fm-foto">${d.foto ? `<img src="${esc(d.foto)}" alt="Foto">` : esc(ini)}</div>
            <h3>${esc(d.nombre)}</h3><p>${esc(d.subtitulo)}</p>
            ${(d.etiquetas || []).length ? `<div class="fm-tags">${d.etiquetas.map(t => `<span>${esc(t)}</span>`).join('')}</div>` : ''}</div><div class="fm-body">`;
        if (d.biografia) h += `<div class="fm-bio">${esc(d.biografia)}</div>`;
        (d.secciones || []).forEach(s => {
            h += `<div class="fm-sec"><h4>${esc(s.titulo)}</h4>`;
            if (s.lista) h += `<div class="fm-lista">${s.lista.map(x => `<span>${esc(x)}</span>`).join('')}</div>`;
            (s.datos || []).forEach(([ico, et, val]) => {
                h += `<div class="fm-dato"><i class="fas ${esc(ico)}"></i><div><small>${esc(et)}</small>${val ? `<b>${esc(val)}</b>` : '<b class="vacio">Sin registro</b>'}</div></div>`;
            });
            h += '</div>';
        });
        h += '</div>';
        if ((d.acciones || []).length) h += `<div class="fm-acciones">${d.acciones.map(([u, i, t], k) => `<a href="${esc(u)}" class="${k ? 'sec' : ''}"><i class="fas ${esc(i)}"></i> ${esc(t)}</a>`).join('')}</div>`;
        box().innerHTML = h;
    }

    window.abrirFicha = async function (tipo, id) {
        box().innerHTML = '<div class="fm-cargando"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Cargando perfil…</p></div>';
        ov.classList.add('abierto');
        document.body.style.overflow = 'hidden';
        try {
            const r = await fetch(`../procesos/ficha_usuario.php?tipo=${encodeURIComponent(tipo)}&id=${encodeURIComponent(id)}`, { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.ok) throw new Error(d.error || 'No se pudo cargar el perfil.');
            render(d);
        } catch (err) {
            box().innerHTML = `<div class="fm-cargando"><button class="fm-cerrar" style="position:static;background:#f1f5f9;color:#475569;float:right;"><i class="fas fa-xmark"></i></button><i class="fas fa-circle-exclamation fa-2x" style="color:#dc2626;"></i><p>${esc(err.message)}</p></div>`;
        }
    };

    document.addEventListener('click', e => {
        const el = e.target.closest('[data-ficha-tipo]');
        if (!el || e.target.closest('a[href]:not([data-ficha-tipo]), button:not([data-ficha-tipo]), form')) return;
        e.preventDefault();
        abrirFicha(el.dataset.fichaTipo, el.dataset.fichaId);
    });
    document.addEventListener('keydown', e => {
        const el = document.activeElement;
        if ((e.key === 'Enter' || e.key === ' ') && el && el.matches('[data-ficha-tipo]')) { e.preventDefault(); abrirFicha(el.dataset.fichaTipo, el.dataset.fichaId); }
    });
})();
