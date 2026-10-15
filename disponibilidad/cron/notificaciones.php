<?php
/**
 * Liga Chapingo — CRON de notificaciones (cada 5 minutos, desde hPanel → Avanzado → Tareas Cron).
 *   1) agrega los recordatorios de los partidos de las próximas 24 horas;
 *   2) envía los avisos pendientes de la cola.
 * Sólo corre desde la línea de comandos (el Cron); por web responde 403.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo para la tarea programada.');
}
chdir(dirname(__DIR__));
require_once dirname(__DIR__) . '/funciones.php';
require_once dirname(__DIR__) . '/lib/rival.php';
require_once dirname(__DIR__) . '/lib/tesoreria-auto.php';

$ini = microtime(true);
try {
    $rec = rival_encolar_recordatorios();
    $r = push_procesar_cola(200, 50);
    $t = teso_sincronizar_partidos();          // tickets de Tesorería al día aunque nadie haya usado el panel
    if (!empty($t['error'])) error_log('[cron tesoreria-auto] ' . $t['error']);
    if (push_instalado()) push_config_set('cron_ultimo', rival_ahora()->format('Y-m-d H:i') . ' · recordatorios ' . $rec . ' · enviadas ' . $r['enviadas'] . ' · sin destino ' . $r['sin_destino'] . ' · errores ' . $r['errores']);
    // Limpieza: avisos de hace más de 30 días.
    if (push_instalado()) db()->exec('DELETE FROM push_cola WHERE creado_en < NOW() - INTERVAL 30 DAY');
    echo rival_ahora()->format('Y-m-d H:i'), ' recordatorios=', $rec, ' enviadas=', $r['enviadas'], ' sin_destino=', $r['sin_destino'], ' errores=', $r['errores'],
         ' (', round(microtime(true) - $ini, 1), " s)\n";
} catch (Throwable $ex) {
    error_log('[cron notificaciones] ' . $ex->getMessage());
    echo 'Error: ', $ex->getMessage(), "\n";
    exit(1);
}
