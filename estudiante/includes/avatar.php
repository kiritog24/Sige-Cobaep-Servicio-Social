<?php
/**
 * Avatar del alumno en el encabezado de todas las páginas del estudiante.
 * Si el alumno subió fotografía se muestra; si no, sus iniciales.
 */
function estudiante_foto_url(): ?string {
    static $url = false;
    if ($url !== false) return $url;
    $url = null;
    global $pdo;
    if (!($pdo instanceof PDO) || empty($_SESSION['id_usuario'])) return $url;
    try {
        $st = $pdo->prepare("SELECT fotografia FROM ALUMNOS WHERE id_alumno = ?");
        $st->execute([(int)$_SESSION['id_usuario']]);
        $foto = $st->fetchColumn();
        if ($foto && is_file(__DIR__ . '/../../' . $foto)) {
            // ?v= evita que el navegador muestre la foto anterior en caché
            $url = '../' . $foto . '?v=' . filemtime(__DIR__ . '/../../' . $foto);
        }
    } catch (PDOException $e) { error_log('avatar alumno: ' . $e->getMessage()); }
    return $url;
}

function avatar_estudiante(string $iniciales): string {
    $url = estudiante_foto_url();
    if ($url) {
        return '<div class="avatar" style="padding:0;overflow:hidden;"><img src="' . htmlspecialchars($url) . '" alt="Foto" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"></div>';
    }
    return '<div class="avatar">' . htmlspecialchars($iniciales) . '</div>';
}
