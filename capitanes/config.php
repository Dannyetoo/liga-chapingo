<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';

define('SEMILLA_CAPITANES', (string)ligaConfig('LIGA_CAPTAIN_SEED', ''));
if (SEMILLA_CAPITANES === '') {
    throw new RuntimeException('El portal de capitanes no está configurado.');
}
define('DATOS_ARBITRAJES', (string)ligaConfig('LIGA_DATOS_DIR', __DIR__ . '/../arbitrajes/datos'));
define('LARGO_CODIGO', (int)ligaConfig('LIGA_CAPTAIN_CODE_LENGTH', 8));
define('MINUTOS_SESION_CAPITAN', (int)ligaConfig('LIGA_CAPTAIN_SESSION_MINUTES', 120));
define('INTENTOS_DIR', (string)ligaConfig('LIGA_CAPTAIN_ATTEMPTS_DIR', __DIR__ . '/datos'));
date_default_timezone_set((string)ligaConfig('LIGA_TIMEZONE', 'America/Mexico_City'));
