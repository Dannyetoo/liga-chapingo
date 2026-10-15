<?php
/* =====================================================================
   Liga Premier Chapingo — Portal de capitanes · ACCESO CON GOOGLE
   Edita este archivo ANTES de subirlo al servidor.

   1) ID de cliente de Google («Iniciar sesión con Google»).
      Se crea gratis en Google Cloud → Clientes → Crear cliente → Aplicación web,
      con https://ligachapingo.com como «Origen autorizado de JavaScript».
      Termina en «.apps.googleusercontent.com». NO es una contraseña: es público.

      Si lo dejas vacío, el botón de Google no aparece y el portal sigue
      funcionando igual que siempre, con el código de equipo. */
if (!defined('GOOGLE_CLIENT_ID')) define('GOOGLE_CLIENT_ID', '1068808901530-pmnnejb1fe8nard26incfo90sb2l1cag.apps.googleusercontent.com');

/* 2) Versión de los vínculos. Si algún día quieres que TODAS las cuentas de
      Google vuelvan a ligarse con el código de su equipo, sube este número. */
if (!defined('GOOGLE_VINCULOS_VERSION')) define('GOOGLE_VINCULOS_VERSION', 1);
