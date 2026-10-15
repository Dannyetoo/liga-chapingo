<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

define('CLAVE_PANEL', (string)ligaConfig('LIGA_PANEL_PASSWORD', ''));
define('CONTENIDO_DIR', (string)ligaConfig('LIGA_CONTENIDO_DIR', __DIR__ . '/contenido'));
define('SUBIDAS_DIR', (string)ligaConfig('LIGA_SUBIDAS_DIR', __DIR__ . '/img'));
define('SUBIDAS_URL', (string)ligaConfig('LIGA_SUBIDAS_URL', 'img'));
define('MINUTOS_SESION_PANEL', (int)ligaConfig('LIGA_PANEL_SESSION_MINUTES', 240));
define('MAX_MB', (int)ligaConfig('LIGA_UPLOAD_MAX_MB', 8));
date_default_timezone_set((string)ligaConfig('LIGA_TIMEZONE', 'America/Mexico_City'));

