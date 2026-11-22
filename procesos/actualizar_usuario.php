<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrativo') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_usuario    = intval($_POST['id_usuario'] ?? 0);
    $tipo_usuario  = trim($_POST['tipo_usuario'] ?? '');
    $nombre        = trim($_POST['nombre'] ?? '');
    $apellido_p    = trim($_POST['apellido_p'] ?? '');
    $apellido_m    = trim($_POST['apellido_m'] ?? '');
    $identificador = trim($_POST['identificador'] ?? '');

    $url_editar = "../admin/editar_usuario.php?id=" . $id_usuario . "&tipo=" . urlencode($tipo_usuario);

    if ($id_usuario <= 0 || empty($tipo_usuario) || empty($nombre) || empty($apellido_p) || empty($identificador)) {
        header("Location: " . $url_editar . "&error=" . urlencode("Por favor completa todos los campos obligatorios."));
        exit();
    }

    // Estado anterior, para la bitácora
    $antes = ['quien' => bitacora_quien($pdo, $tipo_usuario, $id_usuario), 'grupo' => null];
    if ($tipo_usuario === 'alumnos') {
        $stg = $pdo->prepare("SELECT ga.id_grupo FROM GRUPO_ALUMNO ga WHERE ga.id_alumno = ? AND ga.fecha_baja IS NULL LIMIT 1");
        $stg->execute([$id_usuario]);
        $antes['grupo'] = (int)$stg->fetchColumn() ?: null;
    }

    try {
        $pdo->beginTransaction();

        if ($tipo_usuario === 'alumnos') {
            // Correo institucional (lo asigna Control Escolar)
            if (array_key_exists('correo_institucional', $_POST)) {
                $correo_inst = mb_strtolower(trim($_POST['correo_institucional']));
                if ($correo_inst !== '' && !filter_var($correo_inst, FILTER_VALIDATE_EMAIL)) throw new Exception('El correo institucional no es válido.');
                if ($correo_inst !== '') {
                    $stc = $pdo->prepare("SELECT COUNT(*) FROM ALUMNOS WHERE LOWER(correo_institucional) = ? AND id_alumno <> ?");
                    $stc->execute([$correo_inst, $id_usuario]);
                    if ($stc->fetchColumn() > 0) throw new Exception('Ese correo institucional ya está asignado a otro alumno.');
                }
                $pdo->prepare("UPDATE ALUMNOS SET correo_institucional = ? WHERE id_alumno = ?")->execute([$correo_inst !== '' ? $correo_inst : null, $id_usuario]);
            }
            // Padre, madre o tutor (nombre y correo para avisos)
            if (array_key_exists('correo_tutor', $_POST)) {
                $nombre_tutor = mb_substr(trim($_POST['nombre_tutor'] ?? ''), 0, 200);
                $correo_tutor = mb_strtolower(trim($_POST['correo_tutor']));
                if ($correo_tutor !== '' && !filter_var($correo_tutor, FILTER_VALIDATE_EMAIL)) throw new Exception('El correo del tutor no es válido.');
                $stt = $pdo->prepare("SELECT nombre_tutor FROM TUTOR_ALUMNO WHERE id_alumno = ?");
                $stt->execute([$id_usuario]);
                $tutor_existe = $stt->fetchColumn();
                if ($tutor_existe !== false) {
                    $pdo->prepare("UPDATE TUTOR_ALUMNO SET nombre_tutor = ?, correo_tutor = ? WHERE id_alumno = ?")
                        ->execute([$nombre_tutor !== '' ? $nombre_tutor : $tutor_existe, $correo_tutor !== '' ? $correo_tutor : null, $id_usuario]);
                } elseif ($nombre_tutor !== '' || $correo_tutor !== '') {
                    $pdo->prepare("INSERT INTO TUTOR_ALUMNO (id_alumno, nombre_tutor, correo_tutor) VALUES (?, ?, ?)")
                        ->execute([$id_usuario, $nombre_tutor, $correo_tutor !== '' ? $correo_tutor : null]);
                }
            }
            $codigo_qr = trim($_POST['codigo_qr'] ?? ('QR' . $identificador));
            $id_grupo  = intval($_POST['id_grupo'] ?? 0);
            $turno     = trim($_POST['turno'] ?? 'Matutino');

            if (!in_array($turno, ['Matutino', 'Intermedio'])) {
                $turno = 'Intermedio';
            }

            if ($id_grupo > 0) {
                $stmtG = $pdo->prepare("SELECT semestre, turno FROM GRUPOS WHERE id_grupo = ?");
                $stmtG->execute([$id_grupo]);
                $datosGrupo = $stmtG->fetch(PDO::FETCH_ASSOC);

                if ($datosGrupo) {
                    $turno = $datosGrupo['turno'];
                    $semestre = $datosGrupo['semestre'];

                    $sql = "UPDATE ALUMNOS 
                            SET nombre = ?, apellido_paterno = ?, apellido_materno = ?, 
                                matricula = ?, codigo_qr = ?, turno = ?, semestre = ?
                            WHERE id_alumno = ?";
                    $pdo->prepare($sql)->execute([
                        $nombre, $apellido_p, $apellido_m ?: null, 
                        $identificador, $codigo_qr, $turno, $semestre, $id_usuario
                    ]);

                    // Dar de baja de otros grupos previos y activar el nuevo
                    $pdo->prepare("UPDATE GRUPO_ALUMNO SET fecha_baja = CURDATE() WHERE id_alumno = ? AND id_grupo != ? AND fecha_baja IS NULL")
                        ->execute([$id_usuario, $id_grupo]);

                    $checkGA = $pdo->prepare("SELECT id_ga FROM GRUPO_ALUMNO WHERE id_grupo = ? AND id_alumno = ?");
                    $checkGA->execute([$id_grupo, $id_usuario]);
                    if ($checkGA->rowCount() > 0) {
                        $pdo->prepare("UPDATE GRUPO_ALUMNO SET fecha_baja = NULL, fecha_asignacion = CURDATE() WHERE id_grupo = ? AND id_alumno = ?")
                            ->execute([$id_grupo, $id_usuario]);
                    } else {
                        $pdo->prepare("INSERT INTO GRUPO_ALUMNO (id_grupo, id_alumno, fecha_asignacion) VALUES (?, ?, CURDATE())")
                            ->execute([$id_grupo, $id_usuario]);
                    }
                }
            } else {
                // Si eligió "-- Sin grupo asignado --", damos de baja cualquier grupo activo
                $pdo->prepare("UPDATE GRUPO_ALUMNO SET fecha_baja = CURDATE() WHERE id_alumno = ? AND fecha_baja IS NULL")
                    ->execute([$id_usuario]);

                $sql = "UPDATE ALUMNOS 
                        SET nombre = ?, apellido_paterno = ?, apellido_materno = ?, 
                            matricula = ?, codigo_qr = ?, turno = ?
                        WHERE id_alumno = ?";
                $pdo->prepare($sql)->execute([
                    $nombre, $apellido_p, $apellido_m ?: null, 
                    $identificador, $codigo_qr, $turno, $id_usuario
                ]);
            }

        } elseif ($tipo_usuario === 'maestros') {
            $sql = "UPDATE MAESTROS 
                    SET nombre = ?, apellido_paterno = ?, apellido_materno = ?, usuario = ? 
                    WHERE id_maestro = ?";
            $pdo->prepare($sql)->execute([$nombre, $apellido_p, $apellido_m ?: null, $identificador, $id_usuario]);

        } elseif ($tipo_usuario === 'admin') {
            $sql = "UPDATE ADMINISTRATIVOS 
                    SET nombre = ?, apellido_paterno = ?, apellido_materno = ?, usuario = ? 
                    WHERE id_admin = ?";
            $pdo->prepare($sql)->execute([$nombre, $apellido_p, $apellido_m ?: null, $identificador, $id_usuario]);

        } elseif ($tipo_usuario === 'prefectos') {
            $sql = "UPDATE PREFECTOS 
                    SET nombre = ?, apellido_paterno = ?, apellido_materno = ?, usuario = ? 
                    WHERE id_prefecto = ?";
            $pdo->prepare($sql)->execute([$nombre, $apellido_p, $apellido_m ?: null, $identificador, $id_usuario]);
        } else {
            throw new Exception("Tipo de usuario no reconocido.");
        }

        $pdo->commit();

        $despues = bitacora_quien($pdo, $tipo_usuario, $id_usuario);
        $cambios = $antes['quien'] !== $despues ? ['antes' => $antes['quien'], 'ahora' => $despues] : [];
        if ($tipo_usuario === 'alumnos') {
            $grupo_nuevo = (int)($_POST['id_grupo'] ?? 0) ?: null;
            if ($grupo_nuevo !== $antes['grupo']) {
                $g_antes = $antes['grupo'] ? bitacora_quien($pdo, 'grupo', $antes['grupo']) : 'Sin grupo';
                $g_nuevo = $grupo_nuevo ? bitacora_quien($pdo, 'grupo', $grupo_nuevo) : 'Sin grupo';
                bitacora($pdo, 'grupos', 'cambio_grupo', "Cambió a $despues del grupo $g_antes al grupo $g_nuevo", 'alumno', $id_usuario, ['de' => $g_antes, 'a' => $g_nuevo]);
            }
        }
        bitacora($pdo, 'usuarios', 'edicion', 'Editó los datos de ' . $despues, rtrim($tipo_usuario, 's'), $id_usuario, $cambios ?: null);

        header("Location: " . $url_editar . "&exito=1");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Detectar si fue error de duplicado (Matrícula, QR o Usuario repetido)
        if ($e->getCode() == 23000) {
            $msgError = "La matrícula, código QR o nombre de usuario '$identificador' ya está registrado en otra cuenta.";
        } else {
            $msgError = "Error en la base de datos: " . $e->getMessage();
        }
        header("Location: " . $url_editar . "&error=" . urlencode($msgError));
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: " . $url_editar . "&error=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    header("Location: ../admin/gestion_usuarios.php");
    exit();
}