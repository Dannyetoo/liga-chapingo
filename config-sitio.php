<?php
/* =====================================================================
   Liga Chapingo — ligachapingo.com
   Configuración del SITIO PÚBLICO (no toca la app de arbitrajes)
   Edita este archivo ANTES de subirlo al servidor.
   ===================================================================== */

/* 1) Contraseña del panel de administración del sitio (admin.html).
      Es distinta de la clave de la app de tesorería/arbitrajes.
      Cámbiala por una tuya, mínimo 8 caracteres. */
define('CLAVE_PANEL', 'chapingo2026');

/* 2) Carpeta donde se guarda el contenido del sitio (textos, eventos,
      convocatorias, campeones, galería, uniformes).
      Si tu hosting te deja, muévela FUERA de public_html y pon aquí la
      ruta completa, por ejemplo: '/home/usuario/contenido-liga' */
define('CONTENIDO_DIR', __DIR__ . '/contenido');

/* 3) Carpeta pública donde se suben las imágenes y los PDF.
      Tiene que ser visible desde el navegador: déjala dentro del sitio. */
define('SUBIDAS_DIR', __DIR__ . '/img');
define('SUBIDAS_URL', 'img');

/* 4) Minutos de inactividad antes de volver a pedir la contraseña del panel. */
define('MINUTOS_SESION_PANEL', 240);

/* 5) Peso máximo por archivo subido, en megabytes. */
define('MAX_MB', 8);

/* 6) Zona horaria. */
date_default_timezone_set('America/Mexico_City');
