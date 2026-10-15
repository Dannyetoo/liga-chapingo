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
     POST api.php?a=login    -> {ok, yo}        {usuario, clave}: cuentas personales de la mesa (admin_usuarios)
     POST api.php?a=pase     (formulario)       pase firmado desde el panel → abre Tesorería sin contraseña
     POST api.php?a=pase_panel -> {ok, url, pase} para ir al panel de administración sin contraseña
     GET  api.php?a=salir    -> {ok}
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/disponibilidad/lib/mesa.php';

/* Conexión a MySQL (la misma de /disponibilidad/): cuentas de la mesa y bitácora. */
function tdb(): ?PDO {
    static $p = false;
    if ($p !== false) return $p;
    $p = null;
    $f = dirname(__DIR__) . '/disponibilidad/db.php';
    if (is_file($f)) {
        try { require $f; if (isset($pdo) && $pdo instanceof PDO) $p = $pdo; }
        catch (Throwable $ex) { error_log('[tesoreria] db: ' . $ex->getMessage()); }
    }
    return $p;
}

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

/**
 * Quién registró, editó o borró cada ticket, gasto o transferencia: compara lo guardado con lo
 * anterior (por id) y lo anota en fin_bitacora con el usuario de la sesión.
 */
function bitacoraCambios(array $antes, array $despues, int $rev): void {
    $pdo = tdb(); if (!$pdo) return;
    $u = (string)($_SESSION['usuario'] ?? '?');
    $n = 0;
    foreach (['movs' => 'teso_ticket', 'gastos' => 'teso_gasto', 'transferencias' => 'teso_transferencia'] as $k => $ent) {
        $A = []; foreach ((array)($antes[$k] ?? []) as $x) if (is_array($x) && isset($x['id'])) $A[(string)$x['id']] = $x;
        $D = []; foreach ((array)($despues[$k] ?? []) as $x) if (is_array($x) && isset($x['id'])) $D[(string)$x['id']] = $x;
        foreach ($D as $id => $x) {
            if ($n >= 300) return;
            if (!isset($A[$id])) { mesa_bitacora($pdo, $u, 'alta', $ent, $id, null, $x); $n++; }
            elseif (json_encode($A[$id]) !== json_encode($x)) { mesa_bitacora($pdo, $u, 'edicion', $ent, $id, $A[$id], $x); $n++; }
        }
        foreach ($A as $id => $x) if (!isset($D[$id]) && $n < 300) { mesa_bitacora($pdo, $u, 'baja', $ent, $id, $x, null); $n++; }
    }
}

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';

/* ---------- acceso ---------- */
function abrirSesionMesa(array $u): void {
    session_regenerate_id(true);
    $_SESSION = ['ok' => true, 'uid' => (int)$u['id'], 'usuario' => (string)$u['usuario'], 'nombre' => (string)$u['nombre'],
                 'huella' => mesa_huella((string)$u['clave_hash']), 'visto' => time()];
}
function yo(): array { return ['usuario' => (string)($_SESSION['usuario'] ?? ''), 'nombre' => (string)($_SESSION['nombre'] ?? '')]; }

if ($a === 'login') {
    $pdo = tdb();
    if (!$pdo) salida(['ok' => false, 'error' => 'No se pudo conectar con la base de datos de la liga.']);
    $in = cuerpo();
    usleep(300000);
    try { $r = mesa_verificar($pdo, (string)($in['usuario'] ?? ''), (string)($in['clave'] ?? '')); }
    catch (Throwable $ex) { error_log('[tesoreria] login: ' . $ex->getMessage()); salida(['ok' => false, 'error' => 'El acceso de la mesa todavía no está instalado.']); }
    if (!$r['ok']) salida(['ok' => false, 'error' => $r['error']]);
    abrirSesionMesa($r['usuario']);
    mesa_bitacora($pdo, (string)$r['usuario']['usuario'], 'acceso', 'tesoreria', (string)$r['usuario']['id']);
    salida(['ok' => true, 'yo' => yo()]);
}
/* Llega del panel de administración con un pase firmado (formulario POST). */
if ($a === 'pase') {
    $pdo = tdb();
    $uid = $pdo ? mesa_pase_usar((string)($_POST['pase'] ?? ''), 'tesoreria') : 0;
    $u = $uid ? mesa_usuario($pdo, $uid) : null;
    if ($u) { abrirSesionMesa($u); mesa_bitacora($pdo, (string)$u['usuario'], 'acceso_desde_panel', 'tesoreria', (string)$u['id']); }
    header('Content-Type: text/html; charset=utf-8');
    header('Location: index.html', true, 303);
    exit;
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
$vigente = false;
if (!empty($_SESSION['ok']) && !$vencida && !empty($_SESSION['uid'])) {
    $pdo = tdb();
    $u = $pdo ? mesa_usuario($pdo, (int)$_SESSION['uid']) : null;
    $vigente = $u && hash_equals(mesa_huella((string)$u['clave_hash']), (string)($_SESSION['huella'] ?? ''));
    if ($u && $vigente) $_SESSION['nombre'] = (string)$u['nombre'];
}
if (!$vigente) {
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
    salida(['ok' => true, 'rev' => (int)$d['rev'], 'estado' => $d['estado'], 'yo' => yo()]);
}

/* ---------- gastos generales (04-oct-2026) ----------
   Sólo LECTURA de fin_gastos_generales (se capturan en el panel: Finanzas → Gastos generales),
   para el Estado de resultados de Tesorería. Pide el mismo permiso que Finanzas en el panel.
   No devuelve los cancelados. Tesorería nunca escribe en esta tabla. */
if ($a === 'gastos_generales') {
    if ((int)($u['puede_finanzas'] ?? 0) !== 1) salida(['ok' => false, 'permiso' => false]);
    try {
        $st = $pdo->prepare(
            'SELECT g.id, g.edicion_ref, g.categoria_ref, g.concepto, g.descripcion, g.monto, g.fecha,
                    g.estado, g.responsable, c.nombre AS categoria
               FROM fin_gastos_generales g
               JOIN fin_categorias_gasto c ON c.id = g.categoria_gasto_id
              WHERE g.estado <> ?
              ORDER BY g.fecha, g.id'
        );
        $st->execute(['cancelado']);
        $gastos = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $gastos[] = [
                'id'            => (int)$f['id'],
                'edicion_ref'   => (string)$f['edicion_ref'],
                'categoria_ref' => (string)$f['categoria_ref'],
                'categoria'     => (string)$f['categoria'],
                'concepto'      => (string)$f['concepto'],
                'descripcion'   => (string)($f['descripcion'] ?? ''),
                'responsable'   => (string)($f['responsable'] ?? ''),
                'monto'         => (float)$f['monto'],
                'fecha'         => (string)$f['fecha'],
                'estado'        => (string)$f['estado'],
            ];
        }
        salida(['ok' => true, 'gastos' => $gastos]);
    } catch (Throwable $ex) {
        error_log('[tesoreria] gastos_generales: ' . $ex->getMessage());
        salida(['ok' => false, 'error' => 'No se pudieron leer los gastos generales.']);
    }
}

/* ---------- ir al panel de administración sin contraseña ---------- */
if ($a === 'pase_panel') {
    $pase = mesa_pase_crear((int)$_SESSION['uid'], 'panel');
    if ($pase === '') salida(['ok' => false, 'error' => 'No se pudo generar el acceso al panel.']);
    salida(['ok' => true, 'url' => '../disponibilidad/admin/desde-tesoreria.php', 'pase' => $pase]);
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
    bitacoraCambios(is_array($act) ? ($act['estado'] ?? []) : [], $estado, $revServidor + 1);
    salida(['ok' => true, 'rev' => $revServidor + 1]);
}

salida(['ok' => false, 'error' => 'acción desconocida'], 400);
