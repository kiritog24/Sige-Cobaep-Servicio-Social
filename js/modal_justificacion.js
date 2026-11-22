// idAsistencia > 0: justificar ese registro. idAsistencia = 0 + idAlumno + fecha: falta de todo el día sin registro.
function abrirModalJustificar(idAsistencia, nombreAlumno, idAlumno, fecha) {
            document.getElementById('idAsistenciaModal').value = idAsistencia || '';
            const a = document.getElementById('idAlumnoModal'), f = document.getElementById('fechaModal'), aviso = document.getElementById('avisoAusencia');
            if (a) a.value = idAsistencia ? '' : (idAlumno || '');
            if (f) f.value = idAsistencia ? '' : (fecha || '');
            if (aviso) aviso.style.display = idAsistencia ? 'none' : 'block';
            document.getElementById('nombreAlumnoJustificar').innerText = nombreAlumno;
            document.getElementById('modalJustificar').style.display = 'flex';
        }
        
        // Agregar cierre del modal al hacer clic afuera
        window.addEventListener('click', function(event) {
            let modalJustificar = document.getElementById('modalJustificar');
            if (event.target === modalJustificar) {
                modalJustificar.style.display = 'none';
            }
        });