<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';

define('CLAVE_ACCESO', (string)ligaConfig('LIGA_TESORERIA_PASSWORD', ''));
define('DATOS_DIR', (string)ligaConfig('LIGA_DATOS_DIR', __DIR__ . '/datos'));
define('MINUTOS_SESION', (int)ligaConfig('LIGA_TESORERIA_SESSION_MINUTES', 480));
define('CF_API_KEY', (string)ligaConfig('COPAFACIL_API_KEY', ''));
date_default_timezone_set((string)ligaConfig('LIGA_TIMEZONE', 'America/Mexico_City'));

