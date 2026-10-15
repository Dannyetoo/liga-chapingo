<?php
/* =====================================================================
   Tickets futuros — configuración
   Este archivo no imprime nada y .htaccess impide abrirlo desde el navegador.
   ===================================================================== */

/* Hash de la contraseña de administración (password_hash).
   Déjalo vacío la primera vez: admin/index.php te dará un formulario
   para generar el hash; pégalo aquí entre las comillas. */
const TF_ADMIN_HASH = '';

/* Minutos sin actividad antes de cerrar la sesión de administración. */
const TF_MINUTOS_SESION = 120;

/* Intentos fallidos de contraseña permitidos por IP cada 15 minutos. */
const TF_MAX_INTENTOS = 8;
