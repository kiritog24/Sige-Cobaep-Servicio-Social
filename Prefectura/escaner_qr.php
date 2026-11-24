<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'prefectos') {
    header("Location: ../login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="../img/icono-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="../img/icono-192.png">
    <link rel="apple-touch-icon" href="../img/icono-180.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escáner de Asistencia · COBAEP 27</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <link rel="stylesheet" href="css/escaner_qr.css?v=1">
    <link rel="stylesheet" href="../css/responsive.css?v=6">
</head>
<body data-sin-inactividad>

    <div class="header">
        <h1><i class="fas fa-qrcode"></i> Control de Acceso</h1>
        <p>Apunta el gafete del alumno a la cámara</p>
    </div>

    <div class="reader-container">
        <div id="reader"></div>
        <div id="resultado"><i class="fas fa-camera" style="margin-right:8px;"></i> Esperando credencial...</div>
    </div>

    <a href="dashboard_prefectura.php" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver a Prefectura</a>

    <script>
        // === SISTEMA DE SONIDOS SINTETIZADOS (Sin archivos MP3) ===
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        const audioCtx = new AudioContext();

        function playSound(type) {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);

            if (type === 'success') {
                // Tono agudo y corto (Asistencia correcta)
                osc.type = 'sine';
                osc.frequency.setValueAtTime(900, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                osc.start(); osc.stop(audioCtx.currentTime + 0.15);
            } else if (type === 'warning') {
                // Doble tono (Retardo o Salida)
                osc.type = 'square';
                osc.frequency.setValueAtTime(400, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.05, audioCtx.currentTime);
                osc.start(); osc.stop(audioCtx.currentTime + 0.3);
            } else if (type === 'error') {
                // Tono grave e intermitente (Error o Suspendido)
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(150, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                osc.start(); 
                osc.frequency.exponentialRampToValueAtTime(50, audioCtx.currentTime + 0.5);
                osc.stop(audioCtx.currentTime + 0.6);
            }
        }

        // === CONFIGURACIÓN DEL ESCÁNER ===
        const html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
        let escaneando = false;

        function onScanSuccess(decodedText, decodedResult) {
            if (escaneando) return;
            escaneando = true;
            
            const resDiv = document.getElementById('resultado');
            resDiv.className = '';
            resDiv.innerHTML = "<i class='fas fa-spinner fa-spin'></i> Verificando...";
            
            fetch('procesos/procesar_qr.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'matricula=' + encodeURIComponent(decodedText)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success' || data.status === 'success_salida') {
                    playSound('success');
                    resDiv.className = 'status-success';
                    resDiv.innerHTML = `<i class='fas fa-check-circle'></i> ${data.message}`;
                } 
                else if (data.status === 'warning') {
                    playSound('warning');
                    resDiv.className = 'status-warning';
                    resDiv.innerHTML = `<i class='fas fa-exclamation-triangle'></i> ${data.message}`;
                }
                else if (data.status === 'error_suspendido') {
                    playSound('error');
                    resDiv.className = 'status-suspendido';
                    resDiv.innerHTML = `<i class='fas fa-user-lock'></i> ${data.message}`;
                } 
                else {
                    playSound('error');
                    resDiv.className = 'status-error';
                    resDiv.innerHTML = `<i class='fas fa-times-circle'></i> ${data.message}`;
                }
                
                // Tiempo de espera dinámico antes de permitir otro escaneo
                let tiempoEspera = (data.status === 'error_suspendido') ? 3500 : 2000;
                
                setTimeout(() => {
                    resDiv.className = '';
                    resDiv.innerHTML = "<i class='fas fa-camera'></i> Esperando credencial...";
                    escaneando = false;
                }, tiempoEspera);
            })
            .catch(error => {
                playSound('error');
                resDiv.className = 'status-error';
                resDiv.innerHTML = "<i class='fas fa-wifi'></i> Error de conexión.";
                setTimeout(() => { escaneando = false; }, 2000);
            });
        }

        html5QrcodeScanner.render(onScanSuccess);
    </script>
    <script src="../js/responsive.js?v=10"></script>
</body>
</html>