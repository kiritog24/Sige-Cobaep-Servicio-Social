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
