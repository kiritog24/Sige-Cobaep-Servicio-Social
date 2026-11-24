-- ============================================================
-- SIGE-COBAEP · Estructura de la base de datos (sin datos de alumnos)
-- Compatible con XAMPP (MariaDB 10.4) y MySQL 8.
--
-- Instalación en una PC nueva:
--   1) Importar este archivo (phpMyAdmin > Importar, o: mysql -uroot < Sistema_SIGE_COBAEP_estructura.sql)
--   2) Importar database/datos_iniciales.sql (reglamento, sanciones y horarios de turno)
--   3) Ejecutar database/actualizar_bd.php (crea el primer administrador y aplica ajustes)
--
-- Es seguro: NO borra tablas ni datos. Si una tabla ya existe, la deja como está.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `sistema_sige_cobaep` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sistema_sige_cobaep`;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `actividades_clase` (
  `id_actividad` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `archivo_ruta` varchar(255) DEFAULT NULL,
  `fecha_publicacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_limite` datetime DEFAULT NULL,
  `fecha_apertura` datetime DEFAULT NULL,
  `permite_entregas` tinyint(1) NOT NULL DEFAULT 0,
  `acepta_retardo` tinyint(1) NOT NULL DEFAULT 0,
  `visible` tinyint(1) DEFAULT 1,
  `tamano_maximo` int(11) DEFAULT 10,
  `numero_archivos` int(11) DEFAULT 1,
  `tipo_archivo` varchar(255) DEFAULT '*',
  `solo_asistentes` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_filtro` date DEFAULT NULL,
  PRIMARY KEY (`id_actividad`),
  KEY `fk_act_clase` (`id_clase`),
  CONSTRAINT `fk_act_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases_regulares` (`id_clase`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `actividades_regularizacion` (
  `id_actividad` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `id_bloque` int(11) DEFAULT NULL,
  `titulo` varchar(150) NOT NULL,
  `color_titulo` varchar(7) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `archivo_ruta` varchar(255) DEFAULT NULL,
  `fecha_publicacion` datetime NOT NULL DEFAULT current_timestamp(),
  `permite_entregas` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_limite` datetime DEFAULT NULL,
  `acepta_retardo` tinyint(1) NOT NULL DEFAULT 0,
  `tipos_archivo` varchar(255) NOT NULL DEFAULT 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png',
  `tamano_maximo_mb` smallint(6) NOT NULL DEFAULT 10,
  `escala_calificacion` smallint(6) NOT NULL DEFAULT 10,
  PRIMARY KEY (`id_actividad`),
  KEY `id_clase` (`id_clase`),
  KEY `fk_actividad_bloque` (`id_bloque`),
  CONSTRAINT `actividades_regularizacion_ibfk_1` FOREIGN KEY (`id_clase`) REFERENCES `clases_regularizacion` (`id_clase`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_actividad_bloque` FOREIGN KEY (`id_bloque`) REFERENCES `bloques_regularizacion` (`id_bloque`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `administrativos` (
  `id_admin` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellido_paterno` varchar(100) NOT NULL,
  `apellido_materno` varchar(100) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `usuario` varchar(60) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL,
  `rol` varchar(50) DEFAULT 'administrativo',
  `foto_perfil` varchar(255) DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_provisional_expira` datetime DEFAULT NULL,
  PRIMARY KEY (`id_admin`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `alumnos` (
  `id_alumno` int(11) NOT NULL AUTO_INCREMENT,
  `matricula` varchar(20) NOT NULL,
  `codigo_qr` varchar(100) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL,
  `turno` enum('Matutino','Intermedio') NOT NULL,
  `sexo` enum('F','M') DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido_paterno` varchar(100) NOT NULL,
  `apellido_materno` varchar(100) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `edad` tinyint(3) unsigned DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo_personal` varchar(150) DEFAULT NULL,
  `correo_institucional` varchar(150) DEFAULT NULL,
  `periodo_ingreso` varchar(20) DEFAULT NULL,
  `semestre` tinyint(4) DEFAULT NULL,
  `capacitacion` varchar(100) DEFAULT NULL,
  `estatus` enum('Alta','Baja','Suspendido','Finalizado') NOT NULL DEFAULT 'Alta',
  `puntos_conducta` smallint(6) NOT NULL DEFAULT 100,
  `estado_disciplinario` enum('Activo','Suspendido','Baja') NOT NULL DEFAULT 'Activo',
  `fecha_fin_suspension` date DEFAULT NULL,
  `fotografia` varchar(255) DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 1,
  `password_provisional_expira` datetime DEFAULT NULL,
  PRIMARY KEY (`id_alumno`),
  UNIQUE KEY `matricula` (`matricula`),
  UNIQUE KEY `codigo_qr` (`codigo_qr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_alumnos_calcular_edad_insert` BEFORE INSERT ON `ALUMNOS` FOR EACH ROW BEGIN
  IF NEW.fecha_nacimiento IS NOT NULL THEN
    SET NEW.edad = TIMESTAMPDIFF(YEAR, NEW.fecha_nacimiento, CURDATE());
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_alumnos_calcular_edad_update` BEFORE UPDATE ON `ALUMNOS` FOR EACH ROW BEGIN
  IF NEW.fecha_nacimiento IS NOT NULL THEN
    SET NEW.edad = TIMESTAMPDIFF(YEAR, NEW.fecha_nacimiento, CURDATE());
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `asistencias` (
  `id_asistencia` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `id_clase` int(11) DEFAULT NULL,
  `fecha_hora_escaneo` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_hora_salida` datetime DEFAULT NULL,
  `tipo_registro` enum('Entrada','Salida') NOT NULL,
  `estatus` enum('Puntual','Retardo','Falta','Retardo Justificado','Falta Justificada','Suspendido') NOT NULL,
  `grado` tinyint(4) DEFAULT NULL,
  `grupo` varchar(10) DEFAULT NULL,
  `turno` enum('Matutino','Intermedio') DEFAULT NULL,
  `id_admin` int(11) DEFAULT NULL,
  `id_prefecto` int(11) DEFAULT NULL,
  `motivo_justificacion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_asistencia`),
  KEY `fk_asist_alumno` (`id_alumno`),
  KEY `fk_asist_admin` (`id_admin`),
  KEY `fk_asist_prefecto` (`id_prefecto`),
  CONSTRAINT `fk_asist_admin` FOREIGN KEY (`id_admin`) REFERENCES `administrativos` (`id_admin`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asist_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asist_prefecto` FOREIGN KEY (`id_prefecto`) REFERENCES `prefectos` (`id_prefecto`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `asistencias_clases` (
  `id_asistencia` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `estatus` varchar(20) NOT NULL,
  PRIMARY KEY (`id_asistencia`),
  UNIQUE KEY `idx_asistencia_unica` (`id_clase`,`id_alumno`,`fecha`),
  KEY `idx_fecha_estatus` (`fecha`,`estatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `avisos_tutor` (
  `id_aviso` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `tipo` enum('conducta','faltas','retardos','citatorio','general','clases') NOT NULL,
  `referencia` varchar(60) NOT NULL,
  `correo_destino` varchar(150) DEFAULT NULL,
  `asunto` varchar(200) NOT NULL,
  `cuerpo` text NOT NULL,
  `estatus` enum('pendiente','enviado','fallido','sin_correo') NOT NULL DEFAULT 'pendiente',
  `error` varchar(255) DEFAULT NULL,
  `intentos` tinyint(4) NOT NULL DEFAULT 0,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_envio` datetime DEFAULT NULL,
  `rol_genero` varchar(20) DEFAULT NULL,
  `id_usuario_genero` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_aviso`),
  UNIQUE KEY `uq_aviso` (`tipo`,`id_alumno`,`referencia`),
  KEY `idx_estatus` (`estatus`),
  KEY `fk_aviso_alumno` (`id_alumno`),
  CONSTRAINT `fk_aviso_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `bitacora` (
  `id_bitacora` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `rol` varchar(20) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `nombre_usuario` varchar(200) DEFAULT NULL,
  `modulo` varchar(40) NOT NULL,
  `accion` varchar(40) NOT NULL,
  `descripcion` varchar(500) NOT NULL,
  `entidad` varchar(40) DEFAULT NULL,
  `id_entidad` int(11) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id_bitacora`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_modulo` (`modulo`,`fecha`),
  KEY `idx_usuario` (`rol`,`id_usuario`),
  KEY `idx_entidad` (`entidad`,`id_entidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `bloques_regularizacion` (
  `id_bloque` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `titulo_bloque` varchar(150) NOT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_bloque`),
  KEY `id_clase` (`id_clase`),
  CONSTRAINT `bloques_regularizacion_ibfk_1` FOREIGN KEY (`id_clase`) REFERENCES `clases_regularizacion` (`id_clase`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `clase_alumno` (
  `id_clase_alumno` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `id_asignador` int(11) NOT NULL,
  `tipo_asignador` enum('admin','maestro') NOT NULL,
  `fecha_asignacion` date NOT NULL DEFAULT curdate(),
  `calificacion_previa` decimal(5,2) DEFAULT NULL,
  `calificacion_final` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id_clase_alumno`),
  UNIQUE KEY `uq_clase_alumno` (`id_clase`,`id_alumno`),
  KEY `fk_ca_alumno` (`id_alumno`),
  CONSTRAINT `fk_ca_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ca_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases_regularizacion` (`id_clase`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `clase_regular_alumno` (
  `id_cra` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `acceso` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_cra`),
  UNIQUE KEY `uq_clase_alumno_regular` (`id_clase`,`id_alumno`),
  KEY `fk_cra_clase` (`id_clase`),
  KEY `fk_cra_alumno` (`id_alumno`),
  CONSTRAINT `fk_cra_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE,
  CONSTRAINT `fk_cra_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases_regulares` (`id_clase`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `clases_regulares` (
  `id_clase` int(11) NOT NULL AUTO_INCREMENT,
  `id_materia` int(11) NOT NULL,
  `id_maestro` int(11) DEFAULT NULL,
  `enlace_clase_virtual` varchar(255) DEFAULT NULL,
  `estatus` enum('Activa','Inactiva') DEFAULT 'Activa',
  `id_grupo` int(11) DEFAULT NULL,
  `dias_clase` varchar(255) DEFAULT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fin` time DEFAULT NULL,
  PRIMARY KEY (`id_clase`),
  KEY `fk_cr_materia` (`id_materia`),
  KEY `fk_cr_maestro` (`id_maestro`),
  CONSTRAINT `fk_cr_maestro` FOREIGN KEY (`id_maestro`) REFERENCES `maestros` (`id_maestro`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_materia` FOREIGN KEY (`id_materia`) REFERENCES `materias` (`id_materia`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `clases_regularizacion` (
  `id_clase` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_materia` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `id_maestro` int(11) DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `estatus` enum('Activa','Finalizada','Cancelada') NOT NULL DEFAULT 'Activa',
  `imagen_portada` varchar(255) DEFAULT NULL,
  `permitir_entregas` tinyint(1) NOT NULL DEFAULT 0,
  `visible` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_clase`),
  KEY `fk_clase_maestro` (`id_maestro`),
  CONSTRAINT `fk_clase_maestro` FOREIGN KEY (`id_maestro`) REFERENCES `maestros` (`id_maestro`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `configuracion` (
  `clave` varchar(60) NOT NULL,
  `valor` text DEFAULT NULL,
  `actualizado` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `id_admin` int(11) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `domicilio_alumno` (
  `id_domicilio` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `calle` varchar(150) DEFAULT NULL,
  `num_exterior` varchar(20) DEFAULT NULL,
  `num_interior` varchar(20) DEFAULT NULL,
  `colonia` varchar(100) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `codigo_postal` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id_domicilio`),
  UNIQUE KEY `id_alumno` (`id_alumno`),
  CONSTRAINT `fk_dom_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `entregas_actividad` (
  `id_entrega` int(11) NOT NULL AUTO_INCREMENT,
  `id_actividad` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `archivo_ruta` varchar(255) NOT NULL,
  `fecha_entrega` datetime NOT NULL,
  `estatus_entrega` varchar(20) NOT NULL DEFAULT 'A Tiempo',
  `retroalimentacion` text DEFAULT NULL,
  `calificacion` decimal(4,1) DEFAULT NULL,
  `revisada` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_revision` datetime DEFAULT NULL,
  PRIMARY KEY (`id_entrega`),
  KEY `id_actividad` (`id_actividad`),
  KEY `id_alumno` (`id_alumno`),
  CONSTRAINT `entregas_actividad_ibfk_1` FOREIGN KEY (`id_actividad`) REFERENCES `actividades_clase` (`id_actividad`) ON DELETE CASCADE,
  CONSTRAINT `entregas_actividad_ibfk_2` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `entregas_actividad_archivos` (
  `id_archivo` int(11) NOT NULL AUTO_INCREMENT,
  `id_entrega` int(11) NOT NULL,
  `archivo_ruta` varchar(255) NOT NULL,
  `nombre_original` varchar(255) DEFAULT NULL,
  `tamano` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_archivo`),
  KEY `idx_entrega` (`id_entrega`),
  CONSTRAINT `fk_archivo_entrega` FOREIGN KEY (`id_entrega`) REFERENCES `entregas_actividad` (`id_entrega`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `avisos_guardados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `clave` varchar(80) NOT NULL,
  `oculto` tinyint(1) NOT NULL DEFAULT 1,
  `automatico` tinyint(1) NOT NULL DEFAULT 0,
  `titulo` varchar(255) NOT NULL,
  `texto` text DEFAULT NULL,
  `icono` varchar(60) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  `enlace` varchar(255) DEFAULT NULL,
  `fecha_aviso` datetime DEFAULT NULL,
  `fecha_guardado` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alumno_clave` (`id_alumno`,`clave`),
  KEY `idx_alumno_oculto` (`id_alumno`,`oculto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reportes_sugeridos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `tipo` enum('retardo','falta') NOT NULL,
  `mes` char(7) NOT NULL,
  `bloque` smallint(6) NOT NULL,
  `accion` enum('reporte','descartado') NOT NULL,
  `id_reporte` int(11) DEFAULT NULL,
  `fechas` varchar(255) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `id_prefecto` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bloque` (`id_alumno`,`tipo`,`mes`,`bloque`),
  KEY `idx_mes` (`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `entregas_alumno` (
  `id_entrega` int(11) NOT NULL AUTO_INCREMENT,
  `id_actividad` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `id_clase` int(11) NOT NULL,
  `archivo_ruta` varchar(255) NOT NULL,
  `fecha_entrega` datetime NOT NULL DEFAULT current_timestamp(),
  `calificacion` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id_entrega`),
  KEY `fk_ea_actividad` (`id_actividad`),
  KEY `fk_ea_alumno` (`id_alumno`),
  KEY `fk_ea_clase` (`id_clase`),
  CONSTRAINT `fk_ea_actividad` FOREIGN KEY (`id_actividad`) REFERENCES `actividades_clase` (`id_actividad`) ON DELETE CASCADE,
  CONSTRAINT `fk_ea_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE,
  CONSTRAINT `fk_ea_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases_regulares` (`id_clase`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `entregas_regularizacion` (
  `id_entrega` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `id_actividad` int(11) DEFAULT NULL,
  `id_alumno` int(11) NOT NULL,
  `archivo_ruta` varchar(255) NOT NULL,
  `fecha_entrega` datetime NOT NULL DEFAULT current_timestamp(),
  `calificacion` decimal(5,2) DEFAULT NULL,
  `nombre_original` varchar(255) DEFAULT NULL,
  `estatus_entrega` varchar(20) NOT NULL DEFAULT 'A tiempo',
  `retroalimentacion` text DEFAULT NULL,
  PRIMARY KEY (`id_entrega`),
  UNIQUE KEY `uq_entrega_actividad_alumno` (`id_actividad`,`id_alumno`),
  KEY `id_clase` (`id_clase`),
  KEY `id_alumno` (`id_alumno`),
  CONSTRAINT `entregas_regularizacion_ibfk_1` FOREIGN KEY (`id_clase`) REFERENCES `clases_regularizacion` (`id_clase`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `entregas_regularizacion_ibfk_2` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_entrega_reg_actividad` FOREIGN KEY (`id_actividad`) REFERENCES `actividades_regularizacion` (`id_actividad`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `grupo_alumno` (
  `id_ga` int(11) NOT NULL AUTO_INCREMENT,
  `id_grupo` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `fecha_asignacion` date NOT NULL DEFAULT curdate(),
  `fecha_baja` date DEFAULT NULL,
  PRIMARY KEY (`id_ga`),
  UNIQUE KEY `uq_grupo_alumno` (`id_grupo`,`id_alumno`),
  KEY `fk_ga_alumno` (`id_alumno`),
  CONSTRAINT `fk_ga_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ga_grupo` FOREIGN KEY (`id_grupo`) REFERENCES `grupos` (`id_grupo`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `grupo_maestro` (
  `id_gm` int(11) NOT NULL AUTO_INCREMENT,
  `id_grupo` int(11) NOT NULL,
  `id_maestro` int(11) NOT NULL,
  `fecha_asignacion` date NOT NULL DEFAULT curdate(),
  PRIMARY KEY (`id_gm`),
  UNIQUE KEY `uq_grupo_maestro` (`id_grupo`,`id_maestro`),
  KEY `fk_gm_maestro` (`id_maestro`),
  CONSTRAINT `fk_gm_grupo` FOREIGN KEY (`id_grupo`) REFERENCES `grupos` (`id_grupo`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gm_maestro` FOREIGN KEY (`id_maestro`) REFERENCES `maestros` (`id_maestro`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `grupos` (
  `id_grupo` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_grupo` varchar(50) NOT NULL,
  `semestre` tinyint(4) NOT NULL,
  `capacitacion` varchar(100) DEFAULT NULL,
  `turno` enum('Matutino','Intermedio') NOT NULL,
  `id_admin_responsable` int(11) DEFAULT NULL,
  `estatus` varchar(20) NOT NULL DEFAULT 'Activo',
  PRIMARY KEY (`id_grupo`),
  KEY `fk_grupo_admin` (`id_admin_responsable`),
  CONSTRAINT `fk_grupo_admin` FOREIGN KEY (`id_admin_responsable`) REFERENCES `administrativos` (`id_admin`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `historial_sanciones_acum` (
  `id_historial` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `id_reporte_disparador` int(11) NOT NULL,
  `id_sancion_acum` int(11) NOT NULL,
  `fecha_activacion` datetime NOT NULL DEFAULT current_timestamp(),
  `carta_firmada` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_firma` date DEFAULT NULL,
  `firmado_por` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id_historial`),
  KEY `fk_hist_alumno` (`id_alumno`),
  KEY `fk_hist_reporte` (`id_reporte_disparador`),
  KEY `fk_hist_sancion` (`id_sancion_acum`),
  CONSTRAINT `fk_hist_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hist_reporte` FOREIGN KEY (`id_reporte_disparador`) REFERENCES `reportes` (`id_reporte`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hist_sancion` FOREIGN KEY (`id_sancion_acum`) REFERENCES `sanciones_acumulativas` (`id_sancion_acum`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `horarios_clases` (
  `id_horario` int(11) NOT NULL AUTO_INCREMENT,
  `id_clase` int(11) NOT NULL,
  `dia_semana` tinyint(4) NOT NULL COMMENT '1=Lunes, 2=Martes, 3=Miercoles, 4=Jueves, 5=Viernes',
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  PRIMARY KEY (`id_horario`),
  KEY `id_clase` (`id_clase`),
  CONSTRAINT `horarios_clases_ibfk_1` FOREIGN KEY (`id_clase`) REFERENCES `clases_regulares` (`id_clase`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `horarios_maestro` (
  `id_horario` int(11) NOT NULL AUTO_INCREMENT,
  `id_maestro` int(11) NOT NULL,
  `id_grupo` int(11) NOT NULL,
  `nombre_archivo` varchar(200) DEFAULT NULL,
  `ruta_pdf` varchar(500) DEFAULT NULL,
  `fecha_subida` datetime NOT NULL DEFAULT current_timestamp(),
  `ciclo` enum('Ago-Ene','Feb-Jul') NOT NULL,
  `anio` year(4) NOT NULL,
  PRIMARY KEY (`id_horario`),
  KEY `fk_hor_maestro` (`id_maestro`),
  KEY `fk_hor_grupo` (`id_grupo`),
  CONSTRAINT `fk_hor_grupo` FOREIGN KEY (`id_grupo`) REFERENCES `grupos` (`id_grupo`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hor_maestro` FOREIGN KEY (`id_maestro`) REFERENCES `maestros` (`id_maestro`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `horarios_turno` (
  `turno` enum('Matutino','Intermedio') NOT NULL,
  `hora_entrada` time NOT NULL,
  `fin_tolerancia` time NOT NULL,
  `hora_corte` time NOT NULL,
  PRIMARY KEY (`turno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `intentos_login` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario_fecha` (`usuario`,`fecha`),
  KEY `idx_ip_fecha` (`ip`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `maestros` (
  `id_maestro` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellido_paterno` varchar(100) NOT NULL,
  `apellido_materno` varchar(100) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `titulo_academico` varchar(100) DEFAULT NULL,
  `biografia` text DEFAULT NULL,
  `usuario` varchar(60) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL,
  `id_admin_registro` int(11) DEFAULT NULL,
  `horario_archivo` varchar(255) DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_provisional_expira` datetime DEFAULT NULL,
  PRIMARY KEY (`id_maestro`),
  UNIQUE KEY `usuario` (`usuario`),
  KEY `fk_maestro_admin` (`id_admin_registro`),
  CONSTRAINT `fk_maestro_admin` FOREIGN KEY (`id_admin_registro`) REFERENCES `administrativos` (`id_admin`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `materias` (
  `id_materia` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_materia` varchar(150) NOT NULL,
  `imagen_portada` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_materia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `notificaciones_whatsapp` (
  `id_notificacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `tipo` enum('ingreso','salida','inasistencia','reporte') NOT NULL,
  `telefono_destino` varchar(20) NOT NULL,
  `payload_json` text NOT NULL,
  `estatus` enum('pendiente','enviado','fallido') DEFAULT 'pendiente',
  `intentos` tinyint(4) DEFAULT 0,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_envio` datetime DEFAULT NULL,
  PRIMARY KEY (`id_notificacion`),
  KEY `id_alumno` (`id_alumno`),
  CONSTRAINT `notificaciones_whatsapp_ibfk_1` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `prefectos` (
  `id_prefecto` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellido_paterno` varchar(100) NOT NULL,
  `apellido_materno` varchar(100) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `usuario` varchar(60) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL,
  `id_admin_registro` int(11) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_provisional_expira` datetime DEFAULT NULL,
  PRIMARY KEY (`id_prefecto`),
  UNIQUE KEY `usuario` (`usuario`),
  KEY `fk_prefecto_admin` (`id_admin_registro`),
  CONSTRAINT `fk_prefecto_admin` FOREIGN KEY (`id_admin_registro`) REFERENCES `administrativos` (`id_admin`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `reportes` (
  `id_reporte` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `id_tipo_falta` int(11) NOT NULL,
  `id_emisor` int(11) NOT NULL,
  `tipo_emisor` enum('admin','maestro','prefecto') NOT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `puntos_descontados` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `puntos_acumulados_descuento` smallint(6) NOT NULL DEFAULT 0,
  `puntos_restantes` smallint(6) NOT NULL DEFAULT 100,
  `sancion_acumulativa_activada` tinyint(1) NOT NULL DEFAULT 0,
  `id_sancion_acum_activada` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id_reporte`),
  KEY `fk_rep_alumno` (`id_alumno`),
  KEY `fk_rep_tipo_falta` (`id_tipo_falta`),
  KEY `fk_rep_sancion_acum` (`id_sancion_acum_activada`),
  CONSTRAINT `fk_rep_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rep_sancion_acum` FOREIGN KEY (`id_sancion_acum_activada`) REFERENCES `sanciones_acumulativas` (`id_sancion_acum`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rep_tipo_falta` FOREIGN KEY (`id_tipo_falta`) REFERENCES `tipos_falta` (`id_tipo_falta`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reporte_descuento_puntos` BEFORE INSERT ON `REPORTES` FOR EACH ROW BEGIN
  DECLARE v_puntos_tipo          TINYINT UNSIGNED DEFAULT 0;
  DECLARE v_acumulado_previo     SMALLINT         DEFAULT 0;
  DECLARE v_nuevo_acumulado      SMALLINT;
  DECLARE v_puntos_restantes     SMALLINT;
  DECLARE v_id_sancion_acum      INT              DEFAULT NULL;
  
  SET v_puntos_tipo = COALESCE((SELECT puntos_descuento FROM TIPOS_FALTA WHERE id_tipo_falta = NEW.id_tipo_falta LIMIT 1), 0);

  SET v_acumulado_previo = COALESCE((SELECT SUM(puntos_descontados) FROM REPORTES WHERE id_alumno = NEW.id_alumno), 0);
  SET v_nuevo_acumulado  = v_acumulado_previo + v_puntos_tipo;
  SET v_puntos_restantes = 100 - v_nuevo_acumulado;
  IF v_puntos_restantes < 0 THEN 
      SET v_puntos_restantes = 0; 
  END IF;

  SET NEW.puntos_descontados           = v_puntos_tipo;
  SET NEW.puntos_acumulados_descuento  = v_nuevo_acumulado;
  SET NEW.puntos_restantes             = v_puntos_restantes;

  UPDATE ALUMNOS SET puntos_conducta = v_puntos_restantes WHERE id_alumno = NEW.id_alumno;

  IF v_acumulado_previo < 40 AND v_nuevo_acumulado >= 40 THEN
    SET v_id_sancion_acum = (SELECT id_sancion_acum FROM SANCIONES_ACUMULATIVAS WHERE umbral_puntos_menos = 40 LIMIT 1);
  ELSEIF v_acumulado_previo < 25 AND v_nuevo_acumulado >= 25 THEN
    SET v_id_sancion_acum = (SELECT id_sancion_acum FROM SANCIONES_ACUMULATIVAS WHERE umbral_puntos_menos = 25 LIMIT 1);
  END IF;

  IF v_id_sancion_acum IS NOT NULL THEN
    SET NEW.sancion_acumulativa_activada = TRUE;
    SET NEW.id_sancion_acum_activada     = v_id_sancion_acum;
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reporte_historial_sancion` AFTER INSERT ON `REPORTES` FOR EACH ROW BEGIN
  IF NEW.sancion_acumulativa_activada = TRUE
     AND NEW.id_sancion_acum_activada IS NOT NULL THEN

    INSERT INTO HISTORIAL_SANCIONES_ACUM
      (id_alumno, id_reporte_disparador, id_sancion_acum,
       fecha_activacion, carta_firmada)
    VALUES
      (NEW.id_alumno, NEW.id_reporte, NEW.id_sancion_acum_activada,
       NOW(), FALSE);

  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `sanciones_acumulativas` (
  `id_sancion_acum` int(11) NOT NULL AUTO_INCREMENT,
  `umbral_puntos_menos` tinyint(4) NOT NULL,
  `accion` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  PRIMARY KEY (`id_sancion_acum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `sesiones_prefecto` (
  `id_sesion` int(11) NOT NULL AUTO_INCREMENT,
  `id_prefecto` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `dispositivo` varchar(150) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` datetime NOT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_sesion`),
  UNIQUE KEY `token` (`token`),
  KEY `fk_sesion_prefecto` (`id_prefecto`),
  CONSTRAINT `fk_sesion_prefecto` FOREIGN KEY (`id_prefecto`) REFERENCES `prefectos` (`id_prefecto`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `suspensiones_clases` (
  `id_suspension` int(11) NOT NULL AUTO_INCREMENT,
  `motivo` enum('Suspensión oficial','Consejo Técnico','Vacaciones','Fuerza Mayor') NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  PRIMARY KEY (`id_suspension`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `tipos_falta` (
  `id_tipo_falta` int(11) NOT NULL AUTO_INCREMENT,
  `numero_reglamento` tinyint(4) NOT NULL,
  `descripcion_falta` text NOT NULL,
  `tipo_sancion` enum('Puntos','Suspension','Citacion','Reposicion','Pago') NOT NULL,
  `puntos_descuento` tinyint(3) unsigned DEFAULT NULL,
  `detalle_sancion` text DEFAULT NULL,
  `requiere_citacion_tutor` tinyint(1) NOT NULL DEFAULT 0,
  `puede_ser_definitiva` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_tipo_falta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `tutor_alumno` (
  `id_tutor` int(11) NOT NULL AUTO_INCREMENT,
  `id_alumno` int(11) NOT NULL,
  `nombre_tutor` varchar(200) NOT NULL,
  `telefono_1` varchar(20) DEFAULT NULL,
  `telefono_2` varchar(20) DEFAULT NULL,
  `correo_tutor` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id_tutor`),
  UNIQUE KEY `id_alumno` (`id_alumno`),
  CONSTRAINT `fk_tutor_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `visibilidad_actividad` (
  `id_actividad` int(11) NOT NULL,
  `id_alumno` int(11) NOT NULL,
  `visible` tinyint(1) DEFAULT 1,
  `fecha_limite_excepcion` datetime DEFAULT NULL,
  PRIMARY KEY (`id_actividad`,`id_alumno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50106 SET @save_time_zone= @@TIME_ZONE */ ;
DELIMITER ;;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;;
/*!50003 SET character_set_client  = utf8mb4 */ ;;
/*!50003 SET character_set_results = utf8mb4 */ ;;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;;
/*!50003 SET @saved_time_zone      = @@time_zone */ ;;
/*!50003 SET time_zone             = '+00:00' */ ;;
/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`localhost`*/ /*!50106 EVENT `evt_actualizar_edades_diario` ON SCHEDULE EVERY 1 DAY STARTS '2026-06-30 00:00:00' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
  UPDATE ALUMNOS
  SET edad = TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE())
  WHERE fecha_nacimiento IS NOT NULL;
END */ ;;
/*!50003 SET time_zone             = @saved_time_zone */ ;;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;;
/*!50003 SET character_set_client  = @saved_cs_client */ ;;
/*!50003 SET character_set_results = @saved_cs_results */ ;;
/*!50003 SET collation_connection  = @saved_col_connection */ ;;
DELIMITER ;;
DELIMITER ;;
DELIMITER ;
/*!50106 SET TIME_ZONE= @save_time_zone */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

