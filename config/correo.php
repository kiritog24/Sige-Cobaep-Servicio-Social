<?php
/**
 * config/correo.php — Valores por defecto de la cuenta de correo para avisos a padres/tutores.
 *
 * LO NORMAL ES CONFIGURARLA DESDE EL SISTEMA: Administración › Correo de avisos (se guarda cifrada en la
 * base de datos y tiene prioridad sobre este archivo). Este archivo solo se usa si allí no hay nada.
 *
 * Mientras 'activo' sea false, los avisos se guardan como "pendientes" y se pueden enviar después
 * desde Prefectura › Avisos a tutores.
 *
 * Con Gmail (recomendado: una cuenta exclusiva del plantel, p. ej. avisos.plantel27@gmail.com):
 *   1. Activa la verificación en dos pasos de esa cuenta.
 *   2. Crea una "contraseña de aplicación" en https://myaccount.google.com/apppasswords
 *   3. Escribe la cuenta y esa contraseña de 16 letras abajo, y cambia 'activo' a true.
 * Con Outlook/Office 365: host smtp.office365.com, puerto 587, seguridad 'tls'.
 *
 * Esta carpeta está bloqueada para el navegador (.htaccess), la contraseña no se puede ver desde internet.
 */
return [
    'activo'           => false,
    'host'             => 'smtp.gmail.com',
    'puerto'           => 587,
    'seguridad'        => 'tls',            // 'tls' (puerto 587) o 'ssl' (puerto 465)
    'usuario'          => '',               // cuenta que envía
    'contrasena'       => '',               // contraseña de aplicación (no la contraseña normal)
    'remitente'        => '',               // normalmente la misma cuenta
    'nombre_remitente' => 'COBAEP Plantel 27 · Prefectura',
    'responder_a'      => '',               // opcional: correo donde los padres pueden responder
];
