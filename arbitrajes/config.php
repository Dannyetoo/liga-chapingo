<?php
/* =====================================================================
   Tesorería Premier Chapingo — configuración
   Edita este archivo ANTES de subirlo al servidor.
   ===================================================================== */

/* 1) Clave de acceso. Cámbiala por una tuya (mínimo 8 caracteres).
      Es una sola clave compartida entre quienes cobran. */
define('CLAVE_ACCESO', 'dos');

/* 2) Carpeta donde se guardan los datos.
      Si tu hosting te deja, muévela FUERA de public_html y pon aquí la ruta
      completa, por ejemplo: '/home/usuario/datos-tesoreria'
      Si la dejas donde está, el .htaccess incluido la protege en Apache. */
define('DATOS_DIR', __DIR__ . '/datos');

/* 3) Minutos de inactividad antes de volver a pedir la clave (0 = nunca). */
define('MINUTOS_SESION', 480);

/* 4) Copa Fácil — API v2 (opcional).
      La llave se obtiene dentro de la app de Copa Fácil, en la sección del API.
      Ponerla aquí es más seguro que capturarla en el navegador. Déjala vacía si
      prefieres escribirla desde Catálogo → CopaFacil. */
define('CF_API_KEY', '6KXL-W3BG-MPV4');

/* 5) Zona horaria para fechas y respaldos. */
date_default_timezone_set('America/Mexico_City');
