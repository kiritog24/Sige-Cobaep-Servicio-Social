<?php
/**
 * docente/procesos/obtener_lista_asistencia.php — Lista para el pase de lista del docente (se carga dentro del modal).
 *
 * Junto a cada alumno se muestra lo que registró Prefectura ese día (entrada, retardo, salida, justificante,
 * suspensión) para que el docente confirme quién está realmente en su clase.
 * Ningún alumno queda marcado por defecto: el docente decide (con atajos para ir más rápido).
 */
session_start();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'docente') { http_response_code(403); exit('Acceso denegado.'); }

$id_clase = (int)($_GET['id_clase'] ?? 0);
require_once __DIR__ . '/permisos.php';
if ($id_clase <= 0 || !docente_dueno_clase($pdo, $id_clase)) { http_response_code(403); exit('Acceso denegado.'); }

$hoy = date('Y-m-d');
$fecha = $_GET['fecha'] ?? $hoy;
if (!fecha_valida($fecha)) $fecha = $hoy;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ¿Se puede pasar lista ese día?
$bloqueo = null;
if ($fecha > $hoy) $bloqueo = 'No se puede pasar lista de un día que todavía no llega.';
elseif ($motivo = dia_sin_clases($pdo, $fecha)) $bloqueo = 'Ese día no hay clases (' . $motivo . '). No se registra asistencia.';
$horario = horario_clase_dia($pdo, $id_clase, $fecha);

$st = $pdo->prepare("
    SELECT a.id_alumno, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno, ac.estatus
    FROM clase_regular_alumno cra
    INNER JOIN alumnos a ON cra.id_alumno = a.id_alumno AND a.estatus = 'Alta'
    LEFT JOIN asistencias_clases ac ON ac.id_alumno = a.id_alumno AND ac.id_clase = cra.id_clase AND ac.fecha = ?
    WHERE cra.id_clase = ?
    ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre");
$st->execute([$fecha, $id_clase]);
$alumnos = $st->fetchAll(PDO::FETCH_ASSOC);

$ids = array_column($alumnos, 'id_alumno');
$entradas = entradas_plantel($pdo, $ids, $fecha);
$suspendidos = suspendidos_disciplina($pdo, $ids, $fecha);
$ya_guardada = (bool)array_filter($alumnos, fn($a) => $a['estatus'] !== null);
$entraron = count(array_filter($ids, fn($i) => entro_al_plantel($entradas[(int)$i] ?? null)));
?>
<div id="asistMeta" data-bloqueo="<?php echo $bloqueo ? '1' : '0'; ?>" data-total="<?php echo count($alumnos); ?>" hidden></div>

<?php if ($bloqueo): ?>
    <div class="asist-aviso asist-aviso--gris"><i class="fas fa-calendar-xmark"></i> <?php echo $h($bloqueo); ?></div>
<?php else: ?>
    <?php if ($horario['tiene_horario'] && !$horario['toca']): ?>
        <div class="asist-aviso asist-aviso--ambar"><i class="fas fa-circle-info"></i> Según tu horario, esta materia no tiene clase los <?php echo DIAS_SEMANA[(int)date('N', strtotime($fecha))]; ?>. Puedes pasar lista si fue una clase extra.</div>
    <?php endif; ?>
    <div class="asist-aviso asist-aviso--verde">
        <i class="fas fa-door-open"></i> <b><?php echo $entraron; ?> de <?php echo count($alumnos); ?></b> alumnos registraron su entrada al plantel en Prefectura<?php echo $fecha === $hoy ? ' hoy' : ' ese día'; ?>.
        <?php if ($ya_guardada): ?><span class="asist-guardada"><i class="fas fa-check"></i> Ya pasaste lista, puedes corregirla</span><?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$alumnos): ?>
    <div class="asist-vacio">No hay alumnos inscritos en esta materia.</div>
<?php else: ?>
    <?php if (!$bloqueo): ?>
    <div class="asist-barra">
        <div class="asist-contadores">
            <span class="c-p" title="Presentes"><i class="fas fa-check"></i> <b data-cuenta="Presente">0</b></span>
            <span class="c-r" title="Retardos"><i class="fas fa-clock"></i> <b data-cuenta="Retardo">0</b></span>
            <span class="c-f" title="Faltas"><i class="fas fa-xmark"></i> <b data-cuenta="Falta">0</b></span>
            <span class="c-j" title="Justificadas"><i class="fas fa-file-signature"></i> <b data-cuenta="Justificada">0</b></span>
            <span class="c-s" title="Sin marcar">Sin marcar: <b data-cuenta="">0</b></span>
        </div>
        <div class="asist-atajos">
            <button type="button" data-atajo="entraron" title="Marca Presente a quienes registraron entrada en Prefectura (solo a los que no has marcado)"><i class="fas fa-door-open"></i> Presentes los que entraron</button>
            <button type="button" data-atajo="no_entraron" title="Marca Falta a quienes no registraron entrada (solo a los que no has marcado)"><i class="fas fa-door-closed"></i> Falta a los que no entraron</button>
            <button type="button" data-atajo="todos" title="Marca Presente a todos los que faltan por marcar"><i class="fas fa-users"></i> Resto presentes</button>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($alumnos as $al):
        $id = (int)$al['id_alumno'];
        $e = $entradas[$id] ?? null;
        $susp = $suspendidos[$id] ?? null;
        [$tipo, $icono, $texto] = texto_entrada_plantel($e, $susp);
        $actual = estatus_clase_normal($al['estatus']);
        $sugerido = false;
        if ($actual === null) {                 // sin pase de lista: solo se sugiere en casos claros
            if ($e && $e['estatus'] === 'Falta Justificada') { $actual = 'Justificada'; $sugerido = true; }
            elseif ($susp) { $actual = 'Falta'; $sugerido = true; }
        }
        $plantel = entro_al_plantel($e) ? 'entro' : 'no';
    ?>
    <div class="alumno-asist-row" data-plantel="<?php echo $plantel; ?>">
        <div class="asist-info">
            <strong class="asist-nombre"><?php echo $h(trim($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ' ' . $al['nombre'])); ?></strong>
            <span class="asist-mat"><?php echo $h($al['matricula']); ?></span>
            <span class="asist-plantel asist-plantel--<?php echo $tipo; ?>"><i class="fas <?php echo $icono; ?>"></i> <?php echo $h($texto); ?></span>
            <?php if ($sugerido): ?><span class="asist-sugerido">Sugerido por Prefectura</span><?php endif; ?>
            <span class="asist-pinta"><i class="fas fa-triangle-exclamation"></i> Entró al plantel y no está en tu clase: Prefectura lo verá como posible pinta.</span>
        </div>
        <div class="asist-opciones" role="radiogroup" aria-label="Asistencia">
            <?php foreach (['Presente' => 'P', 'Retardo' => 'R', 'Falta' => 'F', 'Justificada' => 'J'] as $valor => $letra): ?>
            <label class="asist-op asist-op--<?php echo strtolower($letra); ?>" title="<?php echo $valor; ?>">
                <input type="radio" name="asistencia[<?php echo $id; ?>]" value="<?php echo $valor; ?>" <?php echo $actual === $valor ? 'checked' : ''; ?> <?php echo $bloqueo ? 'disabled' : ''; ?>>
                <span><?php echo $letra; ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
