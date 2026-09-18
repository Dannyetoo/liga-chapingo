<?php
/* =====================================================================
   Tesorería Premier Chapingo — API de almacenamiento
   Guarda todo el estado de la liga en un archivo JSON con control de
   revisión, para que varios dispositivos capturen sobre la misma
   información sin pisarse.

   Endpoints:
     GET  api.php?a=estado   -> {ok, rev, estado}
     GET  api.php?a=rev      -> {ok, rev}                (sondeo barato)
     POST api.php?a=guardar  -> {ok, rev} | {conflicto, rev, estado}
     POST api.php?a=login    -> {ok}
     GET  api.php?a=salir    -> {ok}
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/config.php';

$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('pctes');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => $seguro,
    ]);
} else {
    session_set_cookie_params(0, '/', '', $seguro, true);
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

const LIMITE_BYTES = 8388608; // 8 MB
const CF_HOSTS = ['https://copafacil.com', 'https://www1.copafacil.com', 'https://www.copafacil.com'];

function salida(array $x, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function cuerpo(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > LIMITE_BYTES) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function archivo(): string { return rtrim(DATOS_DIR, '/\\') . '/estado.json'; }

function prepararCarpeta(): void {
    $dir = rtrim(DATOS_DIR, '/\\');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

function leerTodo(): array {
    $f = archivo();
    if (!is_file($f)) return ['rev' => 0, 'estado' => null];
    $raw = @file_get_contents($f);
    $j = $raw === false ? null : json_decode($raw, true);
    if (!is_array($j) || !isset($j['rev'])) return ['rev' => 0, 'estado' => null];
    return $j;
}

/* respaldo diario automático, antes de la primera escritura del día */
function respaldoDiario(string $contenidoActual): void {
    if ($contenidoActual === '') return;
    $dir = rtrim(DATOS_DIR, '/\\') . '/respaldos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $destino = $dir . '/estado-' . date('Y-m-d') . '.json';
    if (!file_exists($destino)) @file_put_contents($destino, $contenidoActual);
    // conserva los últimos 30 respaldos
    $lista = glob($dir . '/estado-*.json') ?: [];
    if (count($lista) > 30) {
        sort($lista);
        foreach (array_slice($lista, 0, count($lista) - 30) as $viejo) @unlink($viejo);
    }
}

/* ---------- puente a la API de Copa Fácil ----------
   El navegador no puede llamar a copafacil.com (CORS) y la llave no debe quedar
   expuesta en la página: la petición sale desde aquí. */
function copafacil(string $ruta, string $clave, string $lang = 'es', string $host = ''): array {
    $base = in_array($host, CF_HOSTS, true) ? $host : CF_HOSTS[0];
    $url = $base . $ruta;
    $cab = ['Accept: application/json', 'lang: ' . $lang];
    if ($clave !== '') $cab[] = 'x-api-key: ' . $clave;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cab,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        if ($cuerpo === false) return ['ok' => false, 'error' => $error ?: 'sin respuesta'];
        return ['ok' => true, 'status' => $codigo, 'cuerpo' => $cuerpo, 'url' => $url];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $cab), 'timeout' => 25, 'ignore_errors' => true,
    ]]);
    $cuerpo = @file_get_contents($url, false, $ctx);
    if ($cuerpo === false) return ['ok' => false, 'error' => 'no se pudo contactar a copafacil.com'];
    $codigo = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
    }
    return ['ok' => true, 'status' => $codigo, 'cuerpo' => $cuerpo, 'url' => $url];
}

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';

/* ---------- acceso ---------- */
if ($a === 'login') {
    $intentos = (int)($_SESSION['intentos'] ?? 0);
    if ($intentos >= 8 && (time() - (int)($_SESSION['ultimo'] ?? 0)) < 300) {
        salida(['ok' => false, 'bloqueado' => true], 429);
    }
    $in = cuerpo();
    $clave = (string)($in['clave'] ?? '');
    usleep(400000);
    if (hash_equals(CLAVE_ACCESO, $clave) && CLAVE_ACCESO !== '') {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['visto'] = time();
        $_SESSION['intentos'] = 0;
        salida(['ok' => true]);
    }
    $_SESSION['intentos'] = $intentos + 1;
    $_SESSION['ultimo'] = time();
    salida(['ok' => false], 200);
}

if ($a === 'salir') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    salida(['ok' => true]);
}

/* ---------- muro de sesión ---------- */
$vencida = MINUTOS_SESION > 0
        && isset($_SESSION['visto'])
        && (time() - (int)$_SESSION['visto']) > MINUTOS_SESION * 60;
if (empty($_SESSION['ok']) || $vencida) {
    /* 200 a propósito: preguntar "¿sigo dentro?" no es un error, y así el
       navegador no registra un 401 en la consola cada vez que abre la app. */
    salida(['ok' => false, 'auth' => false]);
}
$_SESSION['visto'] = time();

prepararCarpeta();

/* ---------- lectura ---------- */
if ($a === 'rev') {
    $d = leerTodo();
    salida(['ok' => true, 'rev' => (int)$d['rev']]);
}

if ($a === 'estado') {
    $d = leerTodo();
    salida(['ok' => true, 'rev' => (int)$d['rev'], 'estado' => $d['estado']]);
}

/* ---------- Copa Fácil ---------- */
if ($a === 'cf') {
    $in    = cuerpo();
    $ruta  = (string)($in['ruta'] ?? '');
    $clave = defined('CF_API_KEY') && CF_API_KEY !== '' ? CF_API_KEY : (string)($in['clave'] ?? '');
    if (!empty($in['sinClave'])) $clave = '';
    elseif ($clave === '') salida(['ok' => false, 'error' => 'Falta la llave del API. Ponla en config.php (CF_API_KEY) o en Catálogo.'], 400);
    if (!preg_match('#^/api2/[A-Za-z0-9/_\-\.]*(\?[A-Za-z0-9/_\-\.=&%+,]*)?$#', $ruta))
        salida(['ok' => false, 'error' => 'Ruta no permitida: solo /api2/…'], 400);
    $r = copafacil($ruta, $clave, (string)($in['lang'] ?? 'es'), (string)($in['host'] ?? ''));
    if (!$r['ok']) salida(['ok' => false, 'error' => 'No se pudo contactar a copafacil.com: ' . $r['error']]);
    $json = json_decode($r['cuerpo'], true);
    salida([
        'ok'     => $r['status'] >= 200 && $r['status'] < 300,
        'status' => $r['status'],
        'url'    => $r['url'],
        'datos'  => $json,
        'crudo'  => mb_substr(trim((string)$r['cuerpo']), 0, 1500),
        'largo'  => strlen((string)$r['cuerpo']),
    ]);
}

/* ---------- escritura ---------- */
if ($a === 'guardar') {
    $in = cuerpo();
    if (!isset($in['estado']) || !is_array($in['estado'])) {
        salida(['ok' => false, 'error' => 'estado inválido'], 400);
    }
    $estado = $in['estado'];
    foreach (['equipos', 'cargos', 'pagos', 'gastos', 'conceptos', 'disciplinas'] as $k) {
        if (isset($estado[$k]) && !is_array($estado[$k])) {
            salida(['ok' => false, 'error' => 'estado inválido'], 400);
        }
    }
    $revCliente = (int)($in['rev'] ?? 0);

    $f = archivo();
    $fp = @fopen($f, 'c+');
    if (!$fp) salida(['ok' => false, 'error' => 'no se puede escribir en la carpeta de datos'], 500);
    if (!flock($fp, LOCK_EX)) { fclose($fp); salida(['ok' => false, 'error' => 'archivo ocupado'], 503); }

    $raw = stream_get_contents($fp);
    $act = $raw === '' ? null : json_decode($raw, true);
    $revServidor = is_array($act) && isset($act['rev']) ? (int)$act['rev'] : 0;

    if ($revServidor > 0 && $revCliente !== $revServidor) {
        flock($fp, LOCK_UN); fclose($fp);
        salida(['ok' => false, 'conflicto' => true, 'rev' => $revServidor,
                'estado' => is_array($act) ? ($act['estado'] ?? null) : null]);
    }

    respaldoDiario($raw ?: '');

    $nuevo = json_encode([
        'rev'         => $revServidor + 1,
        'actualizado' => date('c'),
        'estado'      => $estado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($nuevo === false || strlen($nuevo) > LIMITE_BYTES) {
        flock($fp, LOCK_UN); fclose($fp);
        salida(['ok' => false, 'error' => 'datos demasiado grandes'], 413);
    }

    ftruncate($fp, 0);
    rewind($fp);
    $escrito = fwrite($fp, $nuevo);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($escrito === false) salida(['ok' => false, 'error' => 'error al escribir'], 500);
    salida(['ok' => true, 'rev' => $revServidor + 1]);
}

salida(['ok' => false, 'error' => 'acción desconocida'], 400);
