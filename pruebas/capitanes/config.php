<?php
/* =====================================================================
   Liga Premier Chapingo — Portal de capitanes (SOLO CONSULTA)
   Configuración. Edita este archivo ANTES de subirlo al servidor.

   Este portal NO escribe nunca en la información financiera: solo lee
   el mismo archivo que ya usa la app de arbitrajes y devuelve, al
   capitán que inició sesión, la información de SU equipo y de nadie más.
   ===================================================================== */

/* 1) Semilla secreta con la que se calculan los códigos de capitán.
      Cámbiala por una cadena larga tuya (40 caracteres o más).

      IMPORTANTE: si algún día la cambias, TODOS los códigos de capitán
      cambian y hay que volver a repartirlos. Guárdala junto con la
      contraseña de la app de arbitrajes. */
define('SEMILLA_CAPITANES', 'iX2aNMsbiXKXxOIqy4jLHvs9tdLKbLptuGmrA9ctgUKMlDxL');

/* 2) Carpeta de datos de la app de arbitrajes. Es la MISMA que está en
      arbitrajes/config.php (DATOS_DIR). Aquí solo se lee estado.json.
      Si en arbitrajes/config.php moviste la carpeta fuera de public_html,
      escribe aquí exactamente la misma ruta. */
define('DATOS_ARBITRAJES', __DIR__ . '/../arbitrajes/datos');

/* 3) Largo del código de capitán (entre 6 y 12). Ocho es cómodo de dictar
      por WhatsApp y a la vez imposible de adivinar. */
define('LARGO_CODIGO', 8);

/* 4) Minutos de inactividad antes de volver a pedir el código (0 = nunca). */
define('MINUTOS_SESION_CAPITAN', 120);

/* 5) Carpeta donde se anotan los intentos fallidos de acceso, para frenar
      a quien intente adivinar códigos. Se crea sola. No guarda nada
      financiero. */
define('INTENTOS_DIR', __DIR__ . '/datos');

/* 6) Zona horaria. */
date_default_timezone_set('America/Mexico_City');
