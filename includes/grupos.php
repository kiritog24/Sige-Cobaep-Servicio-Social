<?php
/**
 * includes/grupos.php — Inscripción y cambio de grupo de alumnos (reglas compartidas).
 *
 *  - Un alumno solo puede estar activo en UN grupo: al pasarlo a otro, el anterior queda con fecha de baja
 *    (se conserva el historial y su asistencia se cuenta en cada grupo solo mientras perteneció a él).
 *  - Al entrar a un grupo toma su semestre y turno.
 *  - analizar_lista_grupo(): lee una lista (Excel/CSV) y dice qué pasaría con cada renglón antes de aplicar nada.
 */
require_once __DIR__ . '/leer_tabla.php';
require_once __DIR__ . '/aulas.php';

/** Texto corto de un grupo: 1° "A" · Matutino */
function grupo_etiqueta(array $g): string {
    return $g['semestre'] . '° "' . $g['nombre_grupo'] . '" · ' . $g['turno'];
}

/** Grupo activo actual de cada alumno: [id_alumno => ['id_grupo', 'semestre', 'nombre_grupo', 'turno']] */
function grupos_actuales(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $st = $pdo->query("SELECT ga.id_alumno, g.id_grupo, g.semestre, g.nombre_grupo, g.turno
                       FROM GRUPO_ALUMNO ga INNER JOIN GRUPOS g ON g.id_grupo = ga.id_grupo
                       WHERE ga.fecha_baja IS NULL AND ga.id_alumno IN (" . implode(',', $ids) . ")
                       ORDER BY ga.fecha_asignacion DESC");
    $res = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($res[(int)$r['id_alumno']])) $res[(int)$r['id_alumno']] = $r;
    return $res;
}

/**
 * Deja al alumno activo en $id_grupo (dándolo de baja de cualquier otro grupo activo).
 * Usar dentro de una transacción. Devuelve el id del grupo anterior (0 si no tenía) o null si ya estaba en ese grupo.
 */
function asignar_alumno_grupo(PDO $pdo, int $id_alumno, array $grupo): ?int {
    $id_grupo = (int)$grupo['id_grupo'];
    $st = $pdo->prepare("SELECT id_grupo FROM GRUPO_ALUMNO WHERE id_alumno = ? AND fecha_baja IS NULL");
    $st->execute([$id_alumno]);
    $activos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($activos === [$id_grupo]) return null;                       // ya está ahí y en ningún otro

    $anterior = 0;
    foreach ($activos as $g) if ($g !== $id_grupo) $anterior = $g;
    $pdo->prepare("UPDATE GRUPO_ALUMNO SET fecha_baja = CURDATE() WHERE id_alumno = ? AND id_grupo <> ? AND fecha_baja IS NULL")
        ->execute([$id_alumno, $id_grupo]);
    if (!in_array($id_grupo, $activos, true)) {
        $pdo->prepare("INSERT INTO GRUPO_ALUMNO (id_grupo, id_alumno, fecha_asignacion) VALUES (?, ?, CURDATE())
                       ON DUPLICATE KEY UPDATE fecha_baja = NULL, fecha_asignacion = CURDATE()")
            ->execute([$id_grupo, $id_alumno]);
    }
    $pdo->prepare("UPDATE ALUMNOS SET semestre = ?, turno = ? WHERE id_alumno = ?")
        ->execute([$grupo['semestre'], $grupo['turno'], $id_alumno]);
    return $anterior;
}

/** Después de inscribir o cambiar alumnos: sus materias (aulas) se ponen al día con su grupo nuevo. */
function grupos_sincronizar_aulas(PDO $pdo, array $ids_grupos): void {
    foreach (array_unique(array_filter(array_map('intval', $ids_grupos))) as $g) sincronizar_aulas_grupo($pdo, $g);
}

/**
 * Analiza una lista de alumnos (filas de leer_tabla) para inscribirla en $grupo.
 * Detecta sola la columna de matrículas (sirve la lista oficial con encabezados); si no hay matrículas,
 * busca por nombre completo. Nada se guarda aquí.
 *
 * Categorías de cada renglón:
 *   nuevo        no tiene grupo: se inscribirá
 *   otro_grupo   está activo en otro grupo: solo se cambia si se marca
 *   ya_en_grupo  ya está en este grupo: se omite
 *   repetido     aparece más de una vez en el archivo: se toma una vez
 *   baja         su estatus no es Alta: se omite
 *   no_encontrado / ambiguo   no se pudo identificar al alumno
 */
function analizar_lista_grupo(PDO $pdo, array $filas, array $grupo): array {
    $alumnos = $pdo->query("SELECT id_alumno, matricula, nombre, apellido_paterno, apellido_materno, estatus, semestre, turno FROM ALUMNOS")->fetchAll(PDO::FETCH_ASSOC);
    $por_mat = []; $por_nom = [];
    foreach ($alumnos as $a) {
        $por_mat[llave_matricula($a['matricula'])] = $a;
        $por_nom[llave_nombre($a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ' ' . $a['nombre'])][] = $a;
    }
    $parece_matricula = fn(string $v) => (bool)preg_match('/^\d{2}\s*\/\s*\d{4}\s*[A-Z]\s*\d{3,5}$/i', trim($v));

    // 1) ¿Qué columna trae las matrículas?
    $votos = [];
    foreach ($filas as $f) foreach ($f as $c => $v) {
        $v = (string)$v;
        if ($v !== '' && (isset($por_mat[llave_matricula($v)]) || $parece_matricula($v))) $votos[$c] = ($votos[$c] ?? 0) + 1;
    }
    arsort($votos);
    $modo = 'matricula'; $cols = [];
    if ($votos) {
        $cols = [array_key_first($votos)];
    } else {
        // 2) Sin matrículas: columna (o 2-3 columnas seguidas) con nombres completos
        $mejor = 0; $max_col = 0;
        foreach ($filas as $f) $max_col = max($max_col, count($f));
        for ($ini = 0; $ini < $max_col; $ini++) for ($ancho = 1; $ancho <= 3 && $ini + $ancho <= $max_col; $ancho++) {
            $n = 0;
            foreach ($filas as $f) {
                $txt = trim(implode(' ', array_slice($f, $ini, $ancho)));
                if ($txt !== '' && isset($por_nom[llave_nombre($txt)])) $n++;
            }
            if ($n > $mejor) { $mejor = $n; $cols = range($ini, $ini + $ancho - 1); }
        }
        $modo = $mejor ? 'nombre' : 'ninguno';
    }

    $res = ['modo' => $modo, 'columnas' => $cols, 'renglones' => []];
    if ($modo === 'ninguno') return $res;

    $vistos = []; $empezo = false;
    foreach ($filas as $i => $f) {
        $valor = trim(implode(' ', array_map(fn($c) => (string)($f[$c] ?? ''), $cols)));
        if ($valor === '') continue;
        $al = null; $cat = null;
        if ($modo === 'matricula') {
            $al = $por_mat[llave_matricula($valor)] ?? null;
            if (!$al) {
                if (!$parece_matricula($valor)) continue;          // encabezados y títulos de la lista
                $cat = 'no_encontrado';
            }
        } else {
            $coinc = $por_nom[llave_nombre($valor)] ?? [];
            if (count($coinc) === 1) $al = $coinc[0];
            elseif (count($coinc) > 1) $cat = 'ambiguo';
            else {
                if (!$empezo || count(explode(' ', norm_texto($valor))) < 2) continue;
                $cat = 'no_encontrado';
            }
        }
        $empezo = true;
        $res['renglones'][] = ['fila' => $i + 1, 'valor' => $valor, 'alumno' => $al, 'categoria' => $cat];
    }

    // 3) Situación de cada alumno identificado
    $ids = array_map(fn($r) => (int)$r['alumno']['id_alumno'], array_filter($res['renglones'], fn($r) => $r['alumno']));
    $actuales = grupos_actuales($pdo, $ids);
    foreach ($res['renglones'] as &$r) {
        if (!$r['alumno']) continue;
        $id = (int)$r['alumno']['id_alumno'];
        $r['grupo_actual'] = isset($actuales[$id]) ? grupo_etiqueta($actuales[$id]) : null;
        $avisos = [];
        if ((string)$r['alumno']['semestre'] !== '' && (int)$r['alumno']['semestre'] !== (int)$grupo['semestre']) $avisos[] = 'Está registrado en ' . (int)$r['alumno']['semestre'] . '° semestre';
        if (!empty($r['alumno']['turno']) && $r['alumno']['turno'] !== $grupo['turno']) $avisos[] = 'Turno registrado: ' . $r['alumno']['turno'];
        $r['avisos'] = $avisos;
        if (isset($vistos[$id]))                                   $r['categoria'] = 'repetido';
        elseif ($r['alumno']['estatus'] !== 'Alta')                 $r['categoria'] = 'baja';
        elseif (!isset($actuales[$id]))                            $r['categoria'] = 'nuevo';
        elseif ((int)$actuales[$id]['id_grupo'] === (int)$grupo['id_grupo']) $r['categoria'] = 'ya_en_grupo';
        else                                                       $r['categoria'] = 'otro_grupo';
        if ($r['categoria'] === 'repetido') $r['repite_fila'] = $vistos[$id];
        $vistos[$id] = $vistos[$id] ?? $r['fila'];
    }
    unset($r);
    return $res;
}
