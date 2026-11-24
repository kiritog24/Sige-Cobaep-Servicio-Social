// idAsistencia > 0: justificar ese registro. idAsistencia = 0 + idAlumno + fecha: falta de todo el día sin registro.
function abrirModalJustificar(idAsistencia, nombreAlumno, idAlumno, fecha) {
            document.getElementById('idAsistenciaModal').value = idAsistencia || '';
            const a = document.getElementById('idAlumnoModal'), f = document.getElementById('fechaModal'), aviso = document.getElementById('avisoAusencia');
            if (a) a.value = idAsistencia ? '' : (idAlumno || '');
            if (f) f.value = idAsistencia ? '' : (fecha || '');
            if (aviso) aviso.style.display = idAsistencia ? 'none' : 'block';
            document.getElementById('nombreAlumnoJustificar').innerText = nombreAlumno;
            document.getElementById('modalJustificar').style.display = 'flex';
            cargarReportesJustificables(idAsistencia, idAlumno, fecha);
        }

        // Reportes de conducta (llegar tarde / faltar) de ese día o de días cercanos: Prefectura elige cuáles quitar
        function cargarReportesJustificables(idAsistencia, idAlumno, fecha) {
            const caja = document.getElementById('reportesJustificar');
            if (!caja) return;
            const lista = document.getElementById('reportesLista'), enviados = document.getElementById('reportesEnviados');
            caja.hidden = true; lista.innerHTML = ''; enviados.value = '';
            const qs = idAsistencia ? 'id_asistencia=' + encodeURIComponent(idAsistencia) : 'id_alumno=' + encodeURIComponent(idAlumno) + '&fecha=' + encodeURIComponent(fecha);
            fetch('procesos/reportes_justificables.php?' + qs, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok || !d.reportes.length) return;
                    const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                    document.getElementById('reportesAyuda').textContent = 'Tiene reportes por ' + (d.tipo === 'retardo' ? 'llegar tarde' : 'faltar a clases')
                        + ' del ' + d.fecha + ' o de días cercanos. Los que marques se quitan, se le regresan sus puntos y se avisa a su tutor.';
                    lista.innerHTML = d.reportes.map(r => '<label class="rep-just-item"><input type="checkbox" name="reportes[]" value="' + r.id + '"' + (r.mismo_dia ? ' checked' : '') + '>'
                        + '<span><b>' + esc(r.fecha) + '</b>' + (r.mismo_dia ? '<span class="rj-dia">mismo día</span>' : '') + '<br>Art. ' + r.articulo + ': ' + esc(r.falta) + '</span>'
                        + '<span class="rj-pts">-' + r.puntos + ' pts</span></label>').join('');
                    enviados.value = '1';
                    caja.hidden = false;
                })
                .catch(() => {});
        }
        
        // Agregar cierre del modal al hacer clic afuera
        window.addEventListener('click', function(event) {
            let modalJustificar = document.getElementById('modalJustificar');
            if (event.target === modalJustificar) {
                modalJustificar.style.display = 'none';
            }
        });