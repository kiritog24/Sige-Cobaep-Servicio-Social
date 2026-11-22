-- =====================================================================
-- SIGE-COBAEP · Migración: entregas por actividad en Regularización
-- Ejecutar UNA vez en phpMyAdmin sobre la BD sistema_sige_cobaep
-- (o bien abrir database/actualizar_bd.php, que lo hace de forma segura).
-- =====================================================================

ALTER TABLE `actividades_regularizacion`
  ADD COLUMN `permite_entregas`    TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN `fecha_limite`        DATETIME     NULL DEFAULT NULL,
  ADD COLUMN `acepta_retardo`      TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN `tipos_archivo`       VARCHAR(255) NOT NULL DEFAULT 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png',
  ADD COLUMN `tamano_maximo_mb`    SMALLINT     NOT NULL DEFAULT 10,
  ADD COLUMN `escala_calificacion` SMALLINT     NOT NULL DEFAULT 10;

ALTER TABLE `entregas_regularizacion`
  ADD COLUMN `id_actividad`      INT          NULL DEFAULT NULL AFTER `id_clase`,
  ADD COLUMN `nombre_original`   VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN `estatus_entrega`   VARCHAR(20)  NOT NULL DEFAULT 'A tiempo',
  ADD COLUMN `retroalimentacion` TEXT         NULL,
  ADD UNIQUE KEY `uq_entrega_actividad_alumno` (`id_actividad`, `id_alumno`),
  ADD CONSTRAINT `fk_entrega_reg_actividad` FOREIGN KEY (`id_actividad`)
      REFERENCES `actividades_regularizacion` (`id_actividad`) ON DELETE CASCADE;

-- Foto de perfil de Administración y Prefectura
ALTER TABLE `administrativos` ADD COLUMN `foto_perfil` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `prefectos`       ADD COLUMN `foto_perfil` VARCHAR(255) NULL DEFAULT NULL;

-- Bloqueo de intentos de inicio de sesión (también se crea solo al primer uso)
CREATE TABLE IF NOT EXISTS `intentos_login` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(100) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_usuario_fecha` (`usuario`, `fecha`),
  KEY `idx_ip_fecha` (`ip`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contraseña provisional (todos los roles): cambio obligatorio y vencimiento
ALTER TABLE `alumnos`         ADD COLUMN `debe_cambiar_password` TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN `password_provisional_expira` DATETIME NULL DEFAULT NULL;
ALTER TABLE `maestros`        ADD COLUMN `debe_cambiar_password` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `password_provisional_expira` DATETIME NULL DEFAULT NULL;
ALTER TABLE `administrativos` ADD COLUMN `debe_cambiar_password` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `password_provisional_expira` DATETIME NULL DEFAULT NULL;
ALTER TABLE `prefectos`       ADD COLUMN `debe_cambiar_password` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `password_provisional_expira` DATETIME NULL DEFAULT NULL;

-- ------------------------------------------------------------------
-- Correo del tutor, bitácora y avisos a tutores
-- (también lo aplica automáticamente database/actualizar_bd.php)
-- ------------------------------------------------------------------
ALTER TABLE `TUTOR_ALUMNO` ADD COLUMN IF NOT EXISTS `correo_tutor` VARCHAR(150) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `BITACORA` (
    `id_bitacora` INT AUTO_INCREMENT PRIMARY KEY,
    `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `rol` VARCHAR(20) NOT NULL,
    `id_usuario` INT NULL,
    `usuario` VARCHAR(100) NULL,
    `nombre_usuario` VARCHAR(200) NULL,
    `modulo` VARCHAR(40) NOT NULL,
    `accion` VARCHAR(40) NOT NULL,
    `descripcion` VARCHAR(500) NOT NULL,
    `entidad` VARCHAR(40) NULL,
    `id_entidad` INT NULL,
    `detalle` TEXT NULL,
    `ip` VARCHAR(45) NULL,
    KEY `idx_fecha` (`fecha`),
    KEY `idx_modulo` (`modulo`, `fecha`),
    KEY `idx_usuario` (`rol`, `id_usuario`),
    KEY `idx_entidad` (`entidad`, `id_entidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AVISOS_TUTOR` (
    `id_aviso` INT AUTO_INCREMENT PRIMARY KEY,
    `id_alumno` INT NOT NULL,
    `tipo` ENUM('conducta','faltas') NOT NULL,
    `referencia` VARCHAR(60) NOT NULL,
    `correo_destino` VARCHAR(150) NULL,
    `asunto` VARCHAR(200) NOT NULL,
    `cuerpo` TEXT NOT NULL,
    `estatus` ENUM('pendiente','enviado','fallido','sin_correo') NOT NULL DEFAULT 'pendiente',
    `error` VARCHAR(255) NULL,
    `intentos` TINYINT NOT NULL DEFAULT 0,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_envio` DATETIME NULL,
    `rol_genero` VARCHAR(20) NULL,
    `id_usuario_genero` INT NULL,
    UNIQUE KEY `uq_aviso` (`tipo`, `id_alumno`, `referencia`),
    KEY `idx_estatus` (`estatus`),
    CONSTRAINT `fk_aviso_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `ALUMNOS` (`id_alumno`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CONFIGURACION` (
    `clave` VARCHAR(60) PRIMARY KEY,
    `valor` TEXT NULL,
    `actualizado` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `id_admin` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
