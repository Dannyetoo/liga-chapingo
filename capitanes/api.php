<?php
/* =====================================================================
   Liga Premier Chapingo — Portal de capitanes · API DE SOLO LECTURA

   Reglas de esta API, sin excepciones:
     · No existe ningún endpoint que escriba, modifique o borre nada.
       El archivo estado.json se abre siempre en modo lectura.
     · El equipo que puede consultarse se decide EN EL SERVIDOR, a partir
       de la sesión ($_SESSION['equipoId']). Nunca se lee un id de equipo
       que venga del navegador.
     · La respuesta solo contiene datos del equipo autenticado. El resto
       de la liga nunca sale de aquí.
     · Esta sesión no da acceso a nada de la administración: usa su propia
       cookie, con su propio nombre y su propia ruta.

   Endpoints:
     POST api.php?a=entrar   {codigo}  -> {ok, equipo} | {ok:false, ...}
     GET  api.php?a=sesion             -> {ok, auth, equipo}
     GET  api.php?a=estado             -> {ok, equipo, resumen, movs, multas}
     GET  api.php?a=salir              -> {ok}
     GET  api.php?a=google             -> {ok, activo, clienteId, nonce}   (acceso con Google)
     POST api.php?a=entrar_google {credencial[, codigo]} -> {ok, equipo} | {ok, falta:'codigo'} | {ok, elegir:[…]}
     POST api.php?a=ligar_google  {codigo}     -> {ok, equipo}   (liga la cuenta de Google al equipo, una vez)
     POST api.php?a=elegir_google {i}          -> {ok, equipo}   (cuenta con varios equipos ligados)
     GET  api.php?a=corte              -> el PDF del corte de caja publicado (sólo con
                                         sesión abierta; ver corte-caja.php)
     GET  api.php?a=estado_pdf         -> el estado de cuenta en PDF del equipo de la
                                         sesión, con saldo a la fecha del corte
     POST api.php?a=torneo             -> {ok, url, pase}   (pase de un solo uso para abrir
                                        «Torneo y estadísticas» en /competencia/)
    POST api.php?a=disponibilidad     -> {ok, url, pase}   (pase firmado de un solo uso
                                         para abrir /disponibilidad/ con el equipo de la
                                         sesión; no lee ni escribe finanzas)

   Los movimientos con `ocultoCapitan` (marcados en Tesorería) no salen
   aquí ni cuentan en cargos/pagado/saldo del capitán.

   «estado» incluye además `futuros`: los tickets futuros (cargos programados)
   del equipo de la sesión que la mesa directiva marcó como visibles. Se leen
   de MySQL con ../tickets-futuros/lib.php. Si ese módulo no existe o falla,
   el Estado de cuenta se muestra igual, sin esa sección.
   ===================================================================== */

declare(strict_types=1);
if (require __DIR__ . '/../competencia/activa.php') {
    http_response_code(410);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'El acceso de equipos se unificó en /competencia/. Usa tu usuario personal.']);
    exit;
}
require __DIR__ . '/config.php';
require __DIR__ . '/codigos-lib.php';
/* Acceso con Google (04-oct-2026): opcional. Sin estos dos archivos, o sin ID de cliente,
   el portal funciona igual que siempre con el código de equipo. */
if (is_file(__DIR__ . '/google-config.php') && is_file(__DIR__ . '/google-lib.php')) {
    require __DIR__ . '/google-config.php';
    require __DIR__ . '/google-lib.php';
}

/* ---------- cabeceras y sesión ---------- */
$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

/* La cookie se limita a la carpeta del portal: así el navegador nunca la
   manda a /arbitrajes/ ni a ninguna otra parte del sitio. */
$rutaCookie = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/capitanes/api.php'))), '/') . '/';
if ($rutaCookie === '/' || $rutaCookie === '') $rutaCookie = '/';

session_name('lchcap');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => $rutaCookie, 'httponly' => true,
        'samesite' => 'Lax', 'secure' => $seguro,
    ]);
} else {
    session_set_cookie_params(0, $rutaCookie, '', $seguro, true);
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

const LIMITE_ENTRADA = 65536;          /* 64 KB: aquí nunca se sube nada */
const MAX_INTENTOS   = 20;             /* por dirección IP (varios capitanes pueden compartir la red de la escuela) */
const VENTANA_CASTIGO = 900;           /* 15 minutos */

function salida(array $x, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function cuerpo(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > LIMITE_ENTRADA) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function num($x): float { return is_numeric($x) ? (float)$x : 0.0; }
function texto($x): string { return is_scalar($x) ? (string)$x : ''; }

/* ---------- freno a los intentos de adivinar códigos ---------- */
function huellaCliente(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return substr(hash_hmac('sha256', $ip, SEMILLA_CAPITANES), 0, 24);
}
function archivoIntentos(): string {
    $dir = rtrim(INTENTOS_DIR, '/\\');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !file_exists($ht)) {
            @file_put_contents($ht,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
    }
    return $dir . '/intentos.json';
}
/** Devuelve [bloqueado, restantes] y, si $sumar, anota un intento fallido. */
function intentos(bool $sumar): array {
    $f = archivoIntentos();
    $k = huellaCliente();
    $ahora = time();
    $fp = @fopen($f, 'c+');
    if (!$fp) return [false, MAX_INTENTOS];            /* sin disco, no se bloquea a nadie */
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $d = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($d)) $d = [];
    foreach ($d as $kk => $v) {                        /* limpia lo viejo */
        if (!is_array($v) || ($ahora - (int)($v['t'] ?? 0)) > VENTANA_CASTIGO) unset($d[$kk]);
    }
    $n = (int)($d[$k]['n'] ?? 0);
    if ($sumar) {
        $n++;
        $d[$k] = ['n' => $n, 't' => $ahora];
        ftruncate($fp, 0); rewind($fp);
        @fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
    @flock($fp, LOCK_UN);
    fclose($fp);
    return [$n >= MAX_INTENTOS, max(0, MAX_INTENTOS - $n)];
}

/* ---------- «recordarme en este dispositivo» ----------
   Cookie HttpOnly firmada con HMAC (clave derivada de SEMILLA_CAPITANES con otra
   etiqueta): lleva sólo el id del equipo y su vencimiento. No guarda el código.
   Para invalidar TODAS las cookies recordadas, sube RECORDAR_VERSION en config.php. */
const COOKIE_RECORDAR = 'lchcap_rec';
const DIAS_RECORDAR   = 60;
function claveRecordar(): string {
    $v = defined('RECORDAR_VERSION') ? (string)RECORDAR_VERSION : '1';
    return hash_hmac('sha256', 'recordar-capitan:v' . $v, SEMILLA_CAPITANES, true);
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64ud(string $s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function ponerCookieRecordar(string $valor, int $vence, string $ruta, bool $seguro): void {
    if (PHP_VERSION_ID >= 70300) {
        setcookie(COOKIE_RECORDAR, $valor, ['expires' => $vence, 'path' => $ruta, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $seguro]);
    } else {
        setcookie(COOKIE_RECORDAR, $valor, $vence, $ruta . '; samesite=Lax', '', $seguro, true);
    }
}
function recordarEquipo(string $equipoId, string $ruta, bool $seguro): void {
    $x = time() + DIAS_RECORDAR * 86400;
    $c = b64u(json_encode(['e' => $equipoId, 'x' => $x], JSON_UNESCAPED_UNICODE));
    ponerCookieRecordar($c . '.' . b64u(hash_hmac('sha256', $c, claveRecordar(), true)), $x, $ruta, $seguro);
}
function olvidarEquipo(string $ruta, bool $seguro): void { ponerCookieRecordar('', time() - 42000, $ruta, $seguro); }
/** Id del equipo recordado si la cookie es válida y no ha vencido; '' si no. */
function equipoRecordado(): string {
    $v = (string)($_COOKIE[COOKIE_RECORDAR] ?? '');
    if ($v === '' || strlen($v) > 400 || substr_count($v, '.') !== 1) return '';
    [$c, $f] = explode('.', $v, 2);
    $firma = b64ud($f);
    if ($firma === false || !hash_equals(hash_hmac('sha256', $c, claveRecordar(), true), $firma)) return '';
    $j = json_decode((string)b64ud($c), true);
    if (!is_array($j) || !is_string($j['e'] ?? null) || !is_int($j['x'] ?? null) || $j['x'] < time()) return '';
    return $j['e'];
}

/* ---------- corte de caja publicado (7-oct-2026) ----------
   Se configura en corte-caja.php. El PDF vive en datos/cortes/ (carpeta cerrada
   a internet) y sólo sale por api.php?a=corte con la sesión de capitán abierta. */
function cortePublicado(): ?array {
    $f = __DIR__ . '/corte-caja.php';
    if (!is_file($f)) return null;
    $c = include $f;
    if (!is_array($c) || empty($c['activo'])) return null;
    $fecha = texto($c['fecha'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return null;
    $arch = basename(texto($c['archivo'] ?? ''));
    $ruta = '';
    if ($arch !== '' && preg_match('/^[A-Za-z0-9._ ()-]+\.pdf$/i', $arch)) {
        $r = rtrim(INTENTOS_DIR, '/\\') . '/cortes/' . $arch;
        if (is_file($r) && is_readable($r)) $ruta = $r;
    }
    return ['fecha' => $fecha, 'archivo' => $arch, 'ruta' => $ruta];
}

/* ---------- catálogos ---------- */
function nombreDe($lista, string $id): string {
    if (!is_array($lista) || $id === '') return '';
    foreach ($lista as $x) {
        if (is_array($x) && (string)($x['id'] ?? '') === $id) return texto($x['nombre'] ?? '');
    }
    return '';
}

/* =====================================================================
   ACCIONES
   ===================================================================== */
$a = isset($_GET['a']) ? (string)$_GET['a'] : '';

/* ---------- entrar ---------- */
if ($a === 'entrar') {
    [$bloqueado, ] = intentos(false);
    if ($bloqueado) {
        salida(['ok' => false, 'bloqueado' => true,
                'error' => 'Demasiados intentos. Espera 15 minutos y vuelve a probar.'], 429);
    }

    $in = cuerpo();
    $codigo = normalizaCodigo(texto($in['codigo'] ?? ''));
    usleep(400000);                                    /* frena los intentos automáticos */

    $d = leerEstadoArbitrajes();
    if ($d === null) {
        salida(['ok' => false, 'error' => 'No se pudo leer la información de la liga. Avisa a la mesa directiva.'], 503);
    }

    $equipoId = $codigo === '' ? '' : equipoPorCodigo(equiposDelEstado($d['estado']), $codigo);
    if ($equipoId === '') {
        [, $restantes] = intentos(true);
        salida(['ok' => false, 'error' => 'Ese código no corresponde a ningún equipo.', 'restantes' => $restantes]);
    }

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['rol']      = 'capitan';
    $_SESSION['equipoId'] = $equipoId;                 /* ← la única fuente de verdad */
    $_SESSION['visto']    = time();
    if (!empty($in['recordar'])) recordarEquipo($equipoId, $rutaCookie, $seguro);
    else olvidarEquipo($rutaCookie, $seguro);

    $e = null;
    foreach (equiposDelEstado($d['estado']) as $x) {
        if (is_array($x) && (string)($x['id'] ?? '') === $equipoId) { $e = $x; break; }
    }
    salida(['ok' => true, 'equipo' => ['nombre' => texto($e['nombre'] ?? '')]]);
}

/* =====================================================================
   ACCESO CON GOOGLE (04-oct-2026)
   Google comprueba quién es la persona; el equipo lo decide el código de capitán,
   que se escribe UNA sola vez para ligar la cuenta. La sesión que se abre es la
   misma de siempre ($_SESSION['equipoId']): lo demás del portal no cambia.
   ===================================================================== */
function abrirSesionCapitan(string $equipoId, bool $recordar, string $ruta, bool $seguro): void {
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['rol']      = 'capitan';
    $_SESSION['equipoId'] = $equipoId;
    $_SESSION['visto']    = time();
    if ($recordar) recordarEquipo($equipoId, $ruta, $seguro);
    else olvidarEquipo($ruta, $seguro);
}
/** Nombre y categoría de un equipo del registro, o null si ya no existe. */
function fichaEquipo(array $S, string $equipoId): ?array {
    foreach (equiposDelEstado($S) as $x) {
        if (!is_array($x) || (string)($x['id'] ?? '') !== $equipoId) continue;
        $det = array_filter([nombreDe($S['ediciones'] ?? [], texto($x['edicionId'] ?? '')),
                             nombreDe($S['categorias'] ?? [], texto($x['categoriaId'] ?? ''))]);
        return ['nombre' => texto($x['nombre'] ?? ''), 'detalle' => implode(' · ', $det)];
    }
    return null;
}
/** Cuenta de Google ya comprobada que todavía no elige o liga equipo (vence a los 10 minutos). */
function pendienteGoogle(): ?array {
    $p = $_SESSION['g_pend'] ?? null;
    if (!is_array($p) || !is_string($p['sub'] ?? null) || (time() - (int)($p['t'] ?? 0)) > GOOGLE_ESPERA) return null;
    return $p;
}
$googleListo = function_exists('googleActivo') && googleActivo();

if ($a === 'google') {
    if (!$googleListo) salida(['ok' => true, 'activo' => false]);
    unset($_SESSION['g_pend']);
    $_SESSION['g_nonce'] = bin2hex(random_bytes(16));          /* viaja dentro del token y sirve una vez */
    salida(['ok' => true, 'activo' => true, 'clienteId' => googleClienteId(), 'nonce' => $_SESSION['g_nonce']]);
}

if ($a === 'entrar_google' || $a === 'ligar_google' || $a === 'elegir_google') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') salida(['ok' => false, 'error' => 'Método no permitido.'], 405);
    if (!$googleListo) salida(['ok' => false, 'error' => 'El acceso con Google no está habilitado.'], 503);
    [$bloqueado, ] = intentos(false);
    if ($bloqueado) {
        salida(['ok' => false, 'bloqueado' => true,
                'error' => 'Demasiados intentos. Espera 15 minutos y vuelve a probar.'], 429);
    }
    $in = cuerpo();
    $d = leerEstadoArbitrajes();
    if ($d === null) {
        salida(['ok' => false, 'error' => 'No se pudo leer la información de la liga. Avisa a la mesa directiva.'], 503);
    }
    $S = $d['estado'];

    if ($a === 'entrar_google') {
        $nonce = texto($_SESSION['g_nonce'] ?? '');
        $_SESSION = [];                                         /* identidad nueva: nada de la sesión anterior */
        $v = googleVerificar(texto($in['credencial'] ?? ''), $nonce);
        if (!$v['ok']) {
            error_log('[capitanes/google] token rechazado: ' . ($v['motivo'] ?? '?'));
            salida(['ok' => false, 'reintentar' => true, 'error' => 'No se pudo comprobar tu cuenta de Google. Inténtalo de nuevo.']);
        }
        $recordar = !empty($in['recordar']);
        /* Con un código escrito en el formulario, entrar con Google LIGA ese equipo a la cuenta
           (así se agrega un segundo equipo). Es el mismo código y el mismo freno de intentos. */
        $codigo = normalizaCodigo(texto($in['codigo'] ?? ''));
        if ($codigo !== '') {
            usleep(400000);
            $equipoId = equipoPorCodigo(equiposDelEstado($S), $codigo);
            if ($equipoId === '') {
                [, $restantes] = intentos(true);
                salida(['ok' => false, 'reintentar' => true, 'error' => 'Ese código no corresponde a ningún equipo.', 'restantes' => $restantes]);
            }
            if (!googleLigar($v['sub'], $equipoId, $v['correo'], $v['nombre'])) {
                salida(['ok' => false, 'reintentar' => true, 'error' => 'No se pudo ligar tu cuenta. Avisa a la mesa directiva.'], 500);
            }
            abrirSesionCapitan($equipoId, $recordar, $rutaCookie, $seguro);
            salida(['ok' => true, 'ligado' => true, 'equipo' => ['nombre' => fichaEquipo($S, $equipoId)['nombre']]]);
        }
        $mios = [];
        foreach (googleEquiposDe($v['sub']) as $id) if (fichaEquipo($S, $id) !== null) $mios[] = $id;
        if (count($mios) === 1) {
            abrirSesionCapitan($mios[0], $recordar, $rutaCookie, $seguro);
            salida(['ok' => true, 'equipo' => ['nombre' => fichaEquipo($S, $mios[0])['nombre']]]);
        }
        session_regenerate_id(true);
        $_SESSION['g_pend'] = ['sub' => $v['sub'], 'correo' => $v['correo'], 'nombre' => $v['nombre'],
                               't' => time(), 'equipos' => $mios, 'recordar' => $recordar];
        $nombre = trim((string)strtok($v['nombre'], ' '));
        if (!$mios) salida(['ok' => true, 'falta' => 'codigo', 'nombre' => $nombre]);
        $lista = [];
        foreach ($mios as $i => $id) $lista[] = ['i' => $i] + fichaEquipo($S, $id);
        salida(['ok' => true, 'elegir' => $lista, 'nombre' => $nombre]);
    }

    $p = pendienteGoogle();
    if ($p === null) {
        unset($_SESSION['g_pend']);
        salida(['ok' => false, 'reintentar' => true, 'error' => 'Tu acceso con Google venció. Vuelve a pulsar «Continuar con Google».']);
    }

    if ($a === 'elegir_google') {
        $i = is_int($in['i'] ?? null) ? $in['i'] : -1;
        $id = is_string($p['equipos'][$i] ?? null) ? $p['equipos'][$i] : '';
        $f = $id !== '' ? fichaEquipo($S, $id) : null;
        if ($f === null) salida(['ok' => false, 'error' => 'Ese equipo ya no está disponible.']);
        abrirSesionCapitan($id, !empty($p['recordar']), $rutaCookie, $seguro);
        salida(['ok' => true, 'equipo' => ['nombre' => $f['nombre']]]);
    }

    /* ligar_google: el mismo código de capitán de siempre, con el mismo freno de intentos */
    $codigo = normalizaCodigo(texto($in['codigo'] ?? ''));
    usleep(400000);
    $equipoId = $codigo === '' ? '' : equipoPorCodigo(equiposDelEstado($S), $codigo);
    if ($equipoId === '') {
        [, $restantes] = intentos(true);
        salida(['ok' => false, 'error' => 'Ese código no corresponde a ningún equipo.', 'restantes' => $restantes]);
    }
    if (!googleLigar($p['sub'], $equipoId, texto($p['correo'] ?? ''), texto($p['nombre'] ?? ''))) {
        salida(['ok' => false, 'error' => 'No se pudo ligar tu cuenta. Avisa a la mesa directiva.'], 500);
    }
    $recordar = array_key_exists('recordar', $in) ? !empty($in['recordar']) : !empty($p['recordar']);
    abrirSesionCapitan($equipoId, $recordar, $rutaCookie, $seguro);
    salida(['ok' => true, 'ligado' => true, 'equipo' => ['nombre' => fichaEquipo($S, $equipoId)['nombre']]]);
}

/* ---------- salir ---------- */
if ($a === 'salir') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    olvidarEquipo($rutaCookie, $seguro);
    salida(['ok' => true]);
}

/* ---------- muro de sesión ---------- */
$vencida = MINUTOS_SESION_CAPITAN > 0
        && isset($_SESSION['visto'])
        && (time() - (int)$_SESSION['visto']) > MINUTOS_SESION_CAPITAN * 60;

$dentro = (($_SESSION['rol'] ?? '') === 'capitan')
       && texto($_SESSION['equipoId'] ?? '') !== ''
       && !$vencida;

/* sin sesión, pero con «recordarme»: se reabre si el equipo sigue en el registro */
if (!$dentro) {
    $rec = equipoRecordado();
    if ($rec !== '') {
        $d0 = leerEstadoArbitrajes();
        $existe = false;
        if ($d0 !== null) foreach (equiposDelEstado($d0['estado']) as $x) {
            if (is_array($x) && (string)($x['id'] ?? '') === $rec) { $existe = true; break; }
        }
        if ($existe) {
            session_regenerate_id(true);
            $_SESSION = ['rol' => 'capitan', 'equipoId' => $rec, 'visto' => time()];
            $dentro = true;
            recordarEquipo($rec, $rutaCookie, $seguro);     /* renueva los 60 días */
        } elseif ($d0 !== null) {
            olvidarEquipo($rutaCookie, $seguro);
        }
    }
}

if ($a === 'sesion') {
    salida(['ok' => true, 'auth' => $dentro]);
}
if (!$dentro) {
    /* Los dos PDF se abren en otra pestaña: sin sesión se manda al acceso, no un texto suelto. */
    if ($a === 'corte' || $a === 'estado_pdf') {
        header('Content-Type: text/html; charset=utf-8');
        header('Location: ./', true, 303);
        exit;
    }
    salida(['ok' => false, 'auth' => false]);
}
$_SESSION['visto'] = time();

/* ---------- puente a «Disponibilidad del equipo» ----------
   Sólo con la sesión de capitán ya abierta (arriba). El equipo del pase
   sale de la sesión, nunca del navegador. No toca estado.json. */
if ($a === 'disponibilidad') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        salida(['ok' => false, 'error' => 'Método no permitido.'], 405);
    }
    $pase = paseDisponibilidad(texto($_SESSION['equipoId']));
    if ($pase === '') {
        salida(['ok' => false, 'error' => 'La disponibilidad del equipo todavía no está habilitada. Avisa a la mesa directiva.'], 503);
    }
    salida(['ok' => true, 'url' => '../disponibilidad/entrar.php', 'pase' => $pase]);
}

/* ---------- puente a «Torneo y estadísticas» (/competencia/) ----------
   Igual que disponibilidad: pase firmado de un solo uso con el equipo de la sesión. */
if ($a === 'torneo') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        salida(['ok' => false, 'error' => 'Método no permitido.'], 405);
    }
    $pase = paseTorneo(texto($_SESSION['equipoId']));
    if ($pase === '') {
        salida(['ok' => false, 'error' => 'El torneo todavía no está habilitado. Avisa a la mesa directiva.'], 503);
    }
    salida(['ok' => true, 'url' => '../competencia/entrar-capitan.php', 'pase' => $pase]);
}

/* ---------- PDF del corte de caja de la liga (sólo con sesión) ---------- */
if ($a === 'corte') {
    $c = cortePublicado();
    if ($c === null || $c['ruta'] === '') {
        salida(['ok' => false, 'error' => 'El corte de caja todavía no está disponible.'], 404);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="corte-de-caja-' . $c['fecha'] . '.pdf"');
    header('Content-Length: ' . (string)filesize($c['ruta']));
    header('Cache-Control: private, no-store');
    readfile($c['ruta']);
    exit;
}

/* ---------- estado de cuenta del equipo de la sesión ---------- */
if ($a === 'estado' || $a === 'estado_pdf') {
    $d = leerEstadoArbitrajes();
    if ($d === null) {
        salida(['ok' => false, 'error' => 'No se pudo leer la información de la liga.'], 503);
    }
    $S = $d['estado'];

    /* El id NO viene del navegador: se toma de la sesión. */
    $equipoId = texto($_SESSION['equipoId']);

    $e = null;
    foreach (equiposDelEstado($S) as $x) {
        if (is_array($x) && (string)($x['id'] ?? '') === $equipoId) { $e = $x; break; }
    }
    if ($e === null) {
        salida(['ok' => false, 'error' => 'Tu equipo ya no aparece en el registro de la liga. Avisa a la mesa directiva.'], 404);
    }

    $liga = is_array($S['liga'] ?? null) ? $S['liga'] : [];

    /* --- movimientos del equipo, en orden y con saldo acumulado --- */
    $movs = [];
    foreach ((is_array($S['movs'] ?? null) ? $S['movs'] : []) as $m) {
        if (!is_array($m) || (string)($m['equipoId'] ?? '') !== $equipoId) continue;
        /* Ticket marcado en Tesorería como «oculto al capitán»: no se muestra
           ni suma en el estado de cuenta del capitán (en Tesorería sí cuenta). */
        /* Tickets automáticos del match (Busca un rival): ocultos hasta que se
           cobren. Con un pago registrado se muestran aunque Tesorería no haya
           quitado todavía la marca. */
        $auto    = is_array($m['auto'] ?? null) ? $m['auto'] : null;
        $cobrado = num($m['recibido'] ?? 0) > 0.009;
        if ($auto !== null && isset($auto['reserva'])) {
            $marca = $auto['ocultoHastaCobro'] ?? null;
            if (($marca === null || $marca === true) && !$cobrado) continue;
            if ($marca === true && $cobrado) { $movs[] = $m; continue; }
        }
        if (!empty($m['ocultoCapitan'])) continue;
        $movs[] = $m;
    }
    usort($movs, function ($x, $y) {
        $c = strcmp(texto($x['fecha'] ?? ''), texto($y['fecha'] ?? ''));
        if ($c !== 0) return $c;
        $c = strcmp(texto($x['hora'] ?? ''), texto($y['hora'] ?? ''));
        if ($c !== 0) return $c;
        return (int)num($x['folio'] ?? 0) <=> (int)num($y['folio'] ?? 0);
    });

    $cargos = 0.0; $pagado = 0.0; $acum = 0.0;
    $listaMovs = [];
    foreach ($movs as $m) {
        $importe  = num($m['importe'] ?? 0);
        $recibido = num($m['recibido'] ?? 0);
        $adeudo   = $importe - $recibido;
        $cargos  += $importe;
        $pagado  += $recibido;
        $acum    += $adeudo;

        $estatus = 'Pagado';
        if ($adeudo > 0.009)      $estatus = $recibido > 0.009 ? 'Pago parcial' : 'Pendiente';
        if ($adeudo > 0.009 && $recibido <= 0.009 && texto($m['forma'] ?? '') === 'Pago futuro') $estatus = 'Programado';
        elseif ($adeudo < -0.009) $estatus = 'A favor';

        $listaMovs[] = [
            'id'        => texto($m['id'] ?? ''),
            'folio'     => (int)num($m['folio'] ?? 0),
            'fecha'     => texto($m['fecha'] ?? ''),
            'hora'      => texto($m['hora'] ?? ''),
            'concepto'  => texto($m['concepto'] ?? ''),
            'importe'   => $importe,
            'recibido'  => $recibido,
            'adeudo'    => $adeudo,
            'saldoAcum' => $acum,
            'forma'     => texto($m['forma'] ?? ''),
            'nota'      => texto($m['nota'] ?? ''),
            'jornada'   => (int)num($m['jornada'] ?? 0),
            /* del rival solo sale el nombre, nada más */
            'rival'     => nombreDe($S['equipos'] ?? [], texto($m['rivalId'] ?? '')),
            'entrego'   => texto($m['entrego'] ?? ''),
            'cajero'    => texto($m['cajero'] ?? ''),
            'estatus'   => $estatus,
            /* pagos con su fecha (para el saldo al corte de caja): sólo fecha, monto y forma */
            'abonos'    => (function () use ($m, $recibido): array {
                $out = [];
                if (is_array($m['abonos'] ?? null) && $m['abonos']) {
                    foreach ($m['abonos'] as $ab) {
                        if (!is_array($ab)) continue;
                        $out[] = ['fecha' => texto($ab['fecha'] ?? ($m['fecha'] ?? '')), 'monto' => num($ab['monto'] ?? 0), 'forma' => texto($ab['forma'] ?? '')];
                    }
                } elseif ($recibido > 0.009) {
                    $out[] = ['fecha' => texto($m['fecha'] ?? ''), 'monto' => $recibido, 'forma' => texto($m['forma'] ?? '')];
                }
                return $out;
            })(),
        ];
    }

    /* --- multas de los jugadores de este equipo --- */
    $listaMultas = [];
    $multasPend = 0.0;
    foreach ((is_array($S['multas'] ?? null) ? $S['multas'] : []) as $mu) {
        if (!is_array($mu) || (string)($mu['equipoId'] ?? '') !== $equipoId) continue;
        $monto  = num($mu['monto'] ?? 0);
        $pag    = num($mu['pagado'] ?? 0);
        $resta  = $monto - $pag;
        if ($resta > 0.009) $multasPend += $resta;
        $ident  = is_array($mu['ident'] ?? null) ? $mu['ident'] : [];
        $listaMultas[] = [
            'jugador'  => trim((string)preg_replace('/\s+/u', ' ', texto($ident['nombre'] ?? ''))),
            'concepto' => texto($mu['concepto'] ?? ''),
            'fecha'    => texto($mu['fecha'] ?? ''),
            'monto'    => $monto,
            'pagado'   => $pag,
            'saldo'    => $resta,
            'nota'     => texto($mu['nota'] ?? ''),
        ];
    }
    usort($listaMultas, function ($x, $y) {
        if (($y['saldo'] <=> $x['saldo']) !== 0) return $y['saldo'] <=> $x['saldo'];
        return strcmp($y['fecha'], $x['fecha']);
    });

    /* --- tickets futuros visibles (sólo lectura; si falla, no rompe el estado de cuenta) --- */
    $listaFuturos = [];
    $totalFuturos = 0.0;
    $libFuturos = __DIR__ . '/../tickets-futuros/lib.php';
    if (is_file($libFuturos)) {
        try {
            require_once $libFuturos;
            $listaFuturos = tf_futuros_visibles($equipoId);          /* mismo id de la sesión */
            foreach ($listaFuturos as $f) $totalFuturos += $f['importe'];
        } catch (Throwable $t) {
            error_log('[capitanes/futuros] ' . $t->getMessage());
            $listaFuturos = [];
            $totalFuturos = 0.0;
        }
    }

    /* --- corte de caja publicado: saldo del equipo a esa fecha y su PDF --- */
    $corte = cortePublicado();
    $datosCorte = null;
    if ($corte !== null) {
        require_once __DIR__ . '/estado-pdf.php';
        $datosCorte = datosCorteEquipo($listaMovs, $listaMultas, $corte['fecha']);
    }
    $equipoSalida = [
        'nombre'     => texto($e['nombre'] ?? ''),
        'edicion'    => nombreDe($S['ediciones'] ?? [],   texto($e['edicionId'] ?? '')),
        'disciplina' => nombreDe($S['disciplinas'] ?? [], texto($e['disciplinaId'] ?? '')),
        'rama'       => nombreDe($S['ramas'] ?? [],       texto($e['ramaId'] ?? '')),
        'categoria'  => nombreDe($S['categorias'] ?? [],  texto($e['categoriaId'] ?? '')),
        'encargado'  => texto($e['encargado'] ?? ''),
    ];
    if ($a === 'estado_pdf') {
        if ($datosCorte === null) salida(['ok' => false, 'error' => 'El estado de cuenta al corte todavía no está disponible.'], 404);
        try {
            $pdf = pdfEstadoCorte($equipoSalida, texto($liga['nombre'] ?? 'Liga Premier Chapingo'), $datosCorte);
        } catch (Throwable $t) {
            error_log('[capitanes/estado_pdf] ' . $t->getMessage());
            salida(['ok' => false, 'error' => 'No se pudo preparar el estado de cuenta. Avisa a la mesa directiva.'], 500);
        }
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)(function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $equipoSalida['nombre']) : $equipoSalida['nombre']))), '-');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="estado-cuenta-' . ($slug !== '' ? $slug : 'equipo') . '-' . $corte['fecha'] . '.pdf"');
        header('Content-Length: ' . (string)strlen($pdf));
        header('Cache-Control: private, no-store');
        echo $pdf;
        exit;
    }

    salida([
        'ok' => true,
        'rev'         => $d['rev'],
        'actualizado' => $d['actualizado'],
        'liga' => [
            'nombre'       => texto($liga['nombre'] ?? 'Liga Premier Chapingo'),
            'pie'          => texto($liga['pie'] ?? ''),
            'avisoCredito' => texto($liga['avisoCredito'] ?? ''),
        ],
        'equipo' => [
            'nombre'     => texto($e['nombre'] ?? ''),
            'edicion'    => nombreDe($S['ediciones'] ?? [],   texto($e['edicionId'] ?? '')),
            'disciplina' => nombreDe($S['disciplinas'] ?? [], texto($e['disciplinaId'] ?? '')),
            'rama'       => nombreDe($S['ramas'] ?? [],       texto($e['ramaId'] ?? '')),
            'categoria'  => nombreDe($S['categorias'] ?? [],  texto($e['categoriaId'] ?? '')),
            'encargado'  => texto($e['encargado'] ?? ''),
            'estado'     => texto($e['estado'] ?? ''),
            'jugadores'  => is_array($e['jugadores'] ?? null) ? count($e['jugadores']) : 0,
        ],
        'resumen' => [
            'cargos'          => $cargos,
            'pagado'          => $pagado,
            'saldo'           => $cargos - $pagado,
            'movimientos'     => count($listaMovs),
            'multasPendientes'=> $multasPend,
            'futuros'         => $totalFuturos,
        ],
        'corte'  => $corte === null ? null : [
            'fecha'  => $corte['fecha'],
            'pdf'    => $corte['ruta'] !== '',
            'saldo'  => $datosCorte['saldo'],
            'cargos' => $datosCorte['cargos'],
            'pagos'  => $datosCorte['pagos'],
        ],
        'movs'   => $listaMovs,
        'multas' => $listaMultas,
        'futuros' => $listaFuturos,
    ]);
}

/* Cualquier otra cosa —incluido cualquier intento de escribir— muere aquí. */
salida(['ok' => false, 'error' => 'Acción no permitida en el portal de capitanes.'], 400);
