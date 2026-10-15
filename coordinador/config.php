<?php
/* =====================================================================
   Panel de coordinación — CONFIGURACIÓN DE ACCESO
   Este archivo no se puede abrir desde el navegador (.htaccess lo bloquea).

   1) Contraseña INICIAL del coordinador. Sólo sirve para la primera entrada:
      el panel obliga a cambiarla y la nueva queda cifrada en datos/acceso.json.
      Si olvidas tu contraseña, borra datos/acceso.json y vuelve a entrar con ésta. */
define('COORD_CLAVE_INICIAL', 'Coord-3KM5-FRC3');

/* 1b) Acceso con Google (opcional). ID de cliente de «Iniciar sesión con Google»:
       Google Cloud → Clientes → Aplicación web, con el dominio del sitio como
       «Origen autorizado de JavaScript». No es una contraseña: es público.
       Puede ser el mismo que usa el portal de capitanes (mismo dominio).
       Vacío ('') = el botón de Google no aparece y se entra sólo con contraseña.
       Cada cuenta de Google se liga UNA vez escribiendo la contraseña del coordinador. */
define('COORD_GOOGLE_CLIENT_ID', '1068808901530-pmnnejb1fe8nard26incfo90sb2l1cag.apps.googleusercontent.com');

/* 2) Carpeta de datos (se crea sola). Aquí viven, separados:
        real.json  → la información de tu liga
        demo.json  → los datos de demostración
        respaldos/ → una copia por día de cada uno
      Si tu hosting lo permite, muévela fuera de public_html y pon la ruta completa. */
define('COORD_DATOS', __DIR__ . '/datos');

/* 3) Minutos sin actividad antes de volver a pedir la contraseña. */
define('COORD_MINUTOS_SESION', 180);

/* 4) Zona horaria de la liga. */
date_default_timezone_set('America/Mexico_City');
