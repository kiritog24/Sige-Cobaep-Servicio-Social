/**
 * js/credencial_personal.js — Genera las credenciales del personal en PDF.
 * El código QR se dibuja aquí con qrcode.js (no hay librería de QR en el servidor) y se envía como PNG.
 *
 * Uso:
 *   <button data-credencial="maestros:3" data-qr="SIGE-COBAEP|P27|DOC|maestro03" data-modo="ver">…</button>
 *   o bien  sigeCredenciales([{clave: 'maestros:3', qr: '…'}], 'descargar')
 */
(function () {
    const src = (document.currentScript && document.currentScript.src) || '';
    const base = src.replace(/js\/credencial_personal\.js.*$/, '');

    function cargarQR() {
        if (window.QRCode) return Promise.resolve();
        return new Promise((ok, mal) => {
            const s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
            s.onload = ok; s.onerror = () => mal(new Error('No se pudo cargar el generador de QR (revisa la conexión a internet).'));
            document.head.appendChild(s);
        });
    }

    function qrPNG(texto) {
        return new Promise(resolve => {
            const tmp = document.createElement('div');
            tmp.style.cssText = 'position:fixed;left:-9999px;top:0';
            document.body.appendChild(tmp);
            new QRCode(tmp, { text: texto, width: 480, height: 480, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
            setTimeout(() => {
                const c = tmp.querySelector('canvas');
                const img = tmp.querySelector('img');
                const dato = c ? c.toDataURL('image/png') : (img && img.src.startsWith('data:image/png') ? img.src : '');
                tmp.remove();
                resolve(dato);
            }, 30);
        });
    }

    window.sigeCredenciales = async function (personas, modo, boton) {
        if (!personas || !personas.length) { (window.sigeAlert || alert)('Selecciona al menos una persona.', 'aviso'); return; }
        const textoOriginal = boton ? boton.innerHTML : '';
        if (boton) { boton.disabled = true; boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando…'; }
        try {
            await cargarQR();
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = base + 'procesos/credencial_personal_pdf.php';
            form.target = modo === 'ver' ? '_blank' : '_self';
            form.style.display = 'none';
            const agregar = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); };
            agregar('modo', modo === 'ver' ? 'ver' : 'descargar');
            for (const p of personas) {
                agregar('personas[]', p.clave);
                const png = await qrPNG(p.qr);
                if (png) agregar('qr[' + p.clave + ']', png);
            }
            document.body.appendChild(form);
            form.submit();
            setTimeout(() => form.remove(), 2000);
        } catch (e) {
            (window.sigeAlert || alert)(e.message || 'No se pudo generar la credencial.', 'error');
        } finally {
            if (boton) setTimeout(() => { boton.disabled = false; boton.innerHTML = textoOriginal; }, 1200);
        }
    };

    // Botones con data-credencial
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-credencial]');
        if (!b) return;
        e.preventDefault();
        window.sigeCredenciales([{ clave: b.dataset.credencial, qr: b.dataset.qr }], b.dataset.modo || 'ver', b);
    });
})();
