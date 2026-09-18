<?php
/* =====================================================================
   Liga Chapingo — Portal de capitanes · API DE SOLO LECTURA

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
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/codigos-lib.php';

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

    $e = null;
    foreach (equiposDelEstado($d['estado']) as $x) {
        if (is_array($x) && (string)($x['id'] ?? '') === $equipoId) { $e = $x; break; }
    }
    salida(['ok' => true, 'equipo' => ['nombre' => texto($e['nombre'] ?? '')]]);
}

/* ---------- salir ---------- */
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
$vencida = MINUTOS_SESION_CAPITAN > 0
        && isset($_SESSION['visto'])
        && (time() - (int)$_SESSION['visto']) > MINUTOS_SESION_CAPITAN * 60;

$dentro = (($_SESSION['rol'] ?? '') === 'capitan')
       && texto($_SESSION['equipoId'] ?? '') !== ''
       && !$vencida;

if ($a === 'sesion') {
    salida(['ok' => true, 'auth' => $dentro]);
}
if (!$dentro) {
    salida(['ok' => false, 'auth' => false]);
}
$_SESSION['visto'] = time();

/* ---------- estado de cuenta del equipo de la sesión ---------- */
if ($a === 'estado') {
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

    salida([
        'ok' => true,
        'rev'         => $d['rev'],
        'actualizado' => $d['actualizado'],
        'liga' => [
            'nombre'       => texto($liga['nombre'] ?? 'Liga Chapingo'),
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
        ],
        'movs'   => $listaMovs,
        'multas' => $listaMultas,
    ]);
}

/* Cualquier otra cosa —incluido cualquier intento de escribir— muere aquí. */
salida(['ok' => false, 'error' => 'Acción no permitida en el portal de capitanes.'], 400);
