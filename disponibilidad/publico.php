<?php
/**
 * Liga Chapingo — CALENDARIO PÚBLICO de la página inicial (04-oct-2026).
 * JSON de sólo lectura y SIN sesión: cualquiera puede verlo, por eso sólo entrega
 *   · partidos CONFIRMADOS (reservas_cancha.estado = 'confirmada'): fecha, hora, local,
 *     visitante, categoría, jornada y sede;
 *   · horas de la cancha de «Busca un rival» de hoy en adelante: libre / partido / cerrado / pasado
 *     (el mismo cálculo que ven los capitanes: rival_calendario()).
 * Nunca salen ids, teléfonos, encargados, adeudos ni nada de Tesorería.
 * No escribe en la base de datos. Guarda la respuesta 60 s en datos/ para no consultar
 * MySQL en cada visita al inicio.
 */
declare(strict_types=1);

const PUB_TTL        = 60;   // segundos que se reutiliza la respuesta
const PUB_DIAS_ATRAS = 7;    // días ya jugados que todavía se pueden consultar

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=30');
header('Referrer-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo '{"ok":false}'; exit; }

$cache = __DIR__ . '/datos/publico-cache.json';
if (is_file($cache) && (time() - (int)@filemtime($cache)) < PUB_TTL) {
    $raw = @file_get_contents($cache);
    if (is_string($raw) && $raw !== '' && $raw[0] === '{') { echo $raw; exit; }
}

/* Servicio anónimo: no lee ni crea cookies de sesión (tampoco la del capitán). */
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
ob_start();
require_once __DIR__ . '/funciones.php';
if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_destroy(); }
require_once __DIR__ . '/lib/rival.php';
ob_end_clean();

function pub_fallo(?Throwable $ex = null): void
{
    if ($ex) error_log('[publico] ' . $ex->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
    echo '{"ok":false}';
    exit;
}

try {
    if (!rival_instalado()) pub_fallo();
    $ahora = rival_ahora();
    $hoy   = $ahora->setTime(0, 0);
    $desde = $hoy->modify('-' . PUB_DIAS_ATRAS . ' days');
    $hasta = $hoy->modify('+' . RIVAL_DIAS . ' days');

    /* Categoría y jornada salen de Tesorería (sólo lectura): la categoría del grupo del partido
       y la jornada del ticket que «Busca un rival» generó para esa reserva. */
    $S    = (fin_leer_estado() ?? [])['estado'] ?? [];
    $cats = fin_por_id($S['categorias'] ?? []);
    $jor  = [];
    foreach (fin_lista($S['movs'] ?? []) as $m) {
        $a = $m['auto'] ?? null;
        $j = (int)fin_num($m['jornada'] ?? 0);
        if (is_array($a) && isset($a['reserva']) && $j > 0) $jor[(int)$a['reserva']] = $j;
    }

    $dias = [];
    for ($d = $desde; $d <= $hasta; $d = $d->modify('+1 day')) {
        $dias[$d->format('Y-m-d')] = ['fecha' => $d->format('Y-m-d'), 'partidos' => [], 'horas' => []];
    }

    $st = db()->prepare(
        "SELECT r.id, r.fecha, r.hora, r.equipo_a_nombre, r.equipo_b_nombre, r.grupo_ref, c.nombre AS cancha
           FROM reservas_cancha r
           JOIN canchas c ON c.id = r.cancha_id
          WHERE r.estado = 'confirmada' AND r.fecha BETWEEN ? AND ?
          ORDER BY r.fecha, r.hora, r.id"
    );
    $st->execute([$desde->format('Y-m-d'), $hasta->format('Y-m-d')]);
    foreach ($st->fetchAll() as $r) {
        $f = (string)$r['fecha'];
        if (!isset($dias[$f])) continue;
        $g = explode('|', (string)$r['grupo_ref']);
        $dias[$f]['partidos'][] = [
            'hora'      => substr((string)$r['hora'], 0, 5),
            'local'     => (string)$r['equipo_a_nombre'],
            'visitante' => (string)$r['equipo_b_nombre'],
            'categoria' => fin_txt(($cats[$g[3] ?? ''] ?? [])['nombre'] ?? ''),
            'jornada'   => $jor[(int)$r['id']] ?? null,
            'sede'      => (string)$r['cancha'],
        ];
    }

    $cancha = rival_cancha_id();
    if ($cancha) {
        foreach (rival_calendario($cancha) as $d) {
            if (!isset($dias[$d['fecha']])) continue;
            foreach ($d['slots'] as $s) {
                $dias[$d['fecha']]['horas'][] = ['hora' => $s['hora'], 'estado' => $s['estado'] === 'reservado' ? 'partido' : $s['estado']];
            }
        }
    }

    $json = json_encode([
        'ok'     => true,
        'hoy'    => $hoy->format('Y-m-d'),
        'ahora'  => $ahora->format('H:i'),
        'cancha' => RIVAL_CANCHA,
        'maxDia' => RIVAL_MAX_POR_DIA,
        'dias'   => array_values($dias),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) pub_fallo();

    if (is_dir(dirname($cache)) && is_writable(dirname($cache))) {
        $tmp = $cache . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) @rename($tmp, $cache); else @unlink($tmp);
    }
    echo $json;
} catch (Throwable $ex) {
    pub_fallo($ex);
}
