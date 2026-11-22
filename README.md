# SIGE-COBAEP · Plantel 27

Sistema de Información y Gestión Educativa del COBAEP Plantel 27 (Zaragoza, Puebla).
Portales para Control Escolar, Prefectura, docentes y alumnos.

## Requisitos

- XAMPP con PHP 8.2 y MariaDB/MySQL (Apache y MySQL encendidos).
- Extensiones de PHP: `pdo_mysql`, `mbstring`, `gd`, `zip`, `openssl`, `fileinfo` (XAMPP ya las trae activadas).

## Instalación en una PC nueva

1. **Descargar el proyecto dentro de `htdocs` con el nombre `sige_cobaep1`:**

   ```
   cd C:\xampp\htdocs
   git clone https://github.com/marinagus004-bot/SIGE-COBAEP.git sige_cobaep1
   ```

   El nombre de la carpeta importa: las páginas de error (`.htaccess`) apuntan a `/sige_cobaep1/error.php`.
   Si usas otro nombre, cambia esas líneas en `.htaccess`.

2. **Crear la base de datos.** En phpMyAdmin (http://localhost/phpmyadmin) → pestaña *Importar*, importa en este orden:
   1. `database/Sistema_SIGE_COBAEP_estructura.sql` (crea la base `sistema_sige_cobaep` y sus tablas)
   2. `database/datos_iniciales.sql` (reglamento, sanciones y horarios de turno)

   Ninguno de los dos borra datos: si las tablas ya existen, las deja como están.

3. **Terminar la instalación:** abre http://localhost/sige_cobaep1/database/actualizar_bd.php
   (solo funciona desde la misma PC). Aplica los ajustes pendientes y, si no hay ningún administrador,
   **crea el usuario `admin` y muestra su contraseña provisional una sola vez**. Anótala.

4. **Entrar:** http://localhost/sige_cobaep1/login.php?rol=admin con el usuario `admin`.
   El sistema pide cambiar la contraseña provisional (vence en 72 horas).
   Desde Control Escolar se dan de alta o se importan alumnos, docentes y prefectos.

## Configuración

- **Base de datos:** `config/database.php` (por defecto `root` sin contraseña en el puerto 3306, como XAMPP).
- **Correo para avisos a tutores:** se configura desde Control Escolar → *Configuración de correo*.
  La contraseña se guarda cifrada con una clave que se crea sola en `config/clave_app.php` (cada PC tiene la suya y no se sube a GitHub).

## Qué NO está en GitHub (a propósito)

| Carpeta / archivo | Por qué |
|---|---|
| `uploads/` | Fotos, tareas, materiales y horarios que suben los usuarios. El sistema crea las carpetas solo. |
| `respaldos/` | Respaldos de la base de datos con datos personales. |
| `config/clave_app.php` | Clave de cifrado propia de cada instalación. |
| `database/Sistema_SIGE_COBAEP_completo.sql` | Copia completa con datos de alumnos. |

Por eso una instalación nueva arranca **sin alumnos, sin archivos subidos y sin respaldos**.
Para trabajar con los mismos datos que otra PC hay que pasarlos por fuera de GitHub
(exportar la base desde phpMyAdmin y copiar la carpeta `uploads/`), cuidando que son datos personales.

> **Antes de hacer `git pull` en una PC que ya tenía el sistema** con una versión anterior a noviembre de 2026,
> copia la carpeta `uploads/` a otro lugar: al actualizar, git la borra porque antes formaba parte del repositorio.
> Después vuelve a ponerla en su lugar.

## Actualizar una instalación existente

1. `git pull`
2. Abrir http://localhost/sige_cobaep1/database/actualizar_bd.php (agrega tablas o columnas nuevas; nunca borra datos).
