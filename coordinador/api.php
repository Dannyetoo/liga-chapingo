<?php
/* =====================================================================
   Panel de coordinación — API

   Dos perfiles, separados EN EL SERVIDOR:
     · coordinador → lee y guarda toda la información de la liga.
     · capitán     → SÓLO LECTURA y sólo de su equipo. El equipo sale de la
                     sesión (del código con el que entró), nunca del navegador.
                     Ningún endpoint de escritura acepta una sesión de capitán.

   Dos espacios de datos, en archivos distintos que nunca se mezclan:
     · real → datos/real.json   (la liga)
     · demo → datos/demo.json   (demostración; se puede restablecer)

   Este módulo es independiente: no lee ni escribe la base de datos del sitio
   ni los archivos de otros módulos. La única excepción es «tesoreria», que
   LEE (nunca escribe) el registro de equipos de Tesorería si existe, para
   poder copiarlos al panel.

   Endpoints (JSON):
     GET  ?a=sesion                         -> {ok, rol, espacio, debeCambiar, csrf}
     POST ?a=entrar          {clave}        -> coordinador
     POST ?a=entrar_capitan  {codigo}       -> capitán (sólo lectura)
     GET  ?a=google                         -> {ok, activo, clienteId, nonce}   (acceso con Google, opcional)
     POST ?a=entrar_google   {credencial}   -> coordinador | {ok, falta:'clave', nombre, correo}
     POST ?a=ligar_google    {clave}        -> coordinador (liga la cuenta de Google, una sola vez)
     GET  ?a=google_cuentas                            [coordinador]
     POST ?a=google_quitar   {sub}                     [coordinador]
     POST ?a=salir
     POST ?a=cambiar_clave   {actual,nueva}            [coordinador]
     GET  ?a=estado&espacio=real|demo                  [coordinador]
     POST ?a=guardar  {espacio, rev, estado}           [coordinador]
     GET  ?a=capitan                                   [capitán]
     GET  ?a=vista_capitan&espacio=&equipo=            [coordinador: lo mismo que vería ese capitán]
     GET  ?a=tesoreria                                 [coordinador: copia de sólo lectura]
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/config.php';

const COORD_MAX_BYTES   = 3145728;      // 3 MB por espacio
const COORD_MAX_INTENTOS = 12;
const COORD_CASTIGO      = 900;         // 15 minutos
const COORD_ESPACIOS     = ['real', 'demo'];
const COORD_COLECCIONES  = ['temporadas', 'categorias', 'torneos', 'equipos', 'jornadas', 'partidos',
                            'conceptos', 'movs', 'gastos', 'cortes', 'bitacora'];

/* ---------- sesión propia del panel ---------- */
$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$ruta = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/coordinador/api.php'))), '/') . '/';
session_name('coordses');
session_set_cookie_params(['lifetime' => 0, 'path' => $ruta, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $seguro]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

function salida(array $x, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function cuerpo(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > COORD_MAX_BYTES + 65536) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function txt($x): string { return is_scalar($x) ? (string)$x : ''; }

function dirDatos(): string
{
    $d = rtrim(COORD_DATOS, '/\\');
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $ht = $d . '/.htaccess';
    if (is_dir($d) && !file_exists($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return $d;
}

/* ---------- freno a los intentos de adivinar la contraseña o un código ---------- */
function intentos(bool $sumar, bool $limpiar = false): bool
{
    $f = dirDatos() . '/intentos.json';
    $k = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . COORD_CLAVE_INICIAL), 0, 24);
    $fp = @fopen($f, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) $d = [];
    $ahora = time();
    foreach ($d as $kk => $v) if (!is_array($v) || $ahora - (int)($v['t'] ?? 0) > COORD_CASTIGO) unset($d[$kk]);
    $n = (int)($d[$k]['n'] ?? 0);
    if ($limpiar) { unset($d[$k]); $n = 0; }
    elseif ($sumar) { $n++; $d[$k] = ['n' => $n, 't' => $ahora]; }
    if ($sumar || $limpiar) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d)); fflush($fp); }
    flock($fp, LOCK_UN); fclose($fp);
    return $n >= COORD_MAX_INTENTOS;
}

/* ---------- contraseña del coordinador ---------- */
function acceso(): ?array
{
    $f = dirDatos() . '/acceso.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) && is_string($j['hash'] ?? null) ? $j : null;
}
function claveCorrecta(string $clave): bool
{
    $a = acceso();
    if ($a) return password_verify($clave, $a['hash']);
    return $clave !== '' && hash_equals((string)COORD_CLAVE_INICIAL, $clave);
}

/* ---------- lectura y guardado de un espacio ---------- */
function archivoEspacio(string $esp): string { return dirDatos() . '/' . $esp . '.json'; }

/** @return array{rev:int, actualizado:string, estado:?array} */
function leerEspacio(string $esp): array
{
    $f = archivoEspacio($esp);
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($j) || !is_array($j['estado'] ?? null)) return ['rev' => 0, 'actualizado' => '', 'estado' => null];
    return ['rev' => (int)($j['rev'] ?? 0), 'actualizado' => txt($j['actualizado'] ?? ''), 'estado' => $j['estado']];
}

/** @return array{ok:bool, rev?:int, conflicto?:bool, error?:string} */
function guardarEspacio(string $esp, int $revEsperada, array $estado): array
{
    foreach (COORD_COLECCIONES as $k) if (!is_array($estado[$k] ?? null)) return ['ok' => false, 'error' => 'Datos incompletos: falta «' . $k . '».'];
    if (!is_array($estado['liga'] ?? null)) return ['ok' => false, 'error' => 'Datos incompletos: falta la configuración de la liga.'];
    $f = archivoEspacio($esp);
    $fp = @fopen($f, 'c+');
    if (!$fp) return ['ok' => false, 'error' => 'No se puede escribir en la carpeta de datos. Revisa sus permisos.'];
    if (!flock($fp, LOCK_EX)) { fclose($fp); return ['ok' => false, 'error' => 'El archivo de datos está ocupado. Inténtalo de nuevo.']; }
    try {
        $raw = (string)stream_get_contents($fp);
        $act = $raw === '' ? null : json_decode($raw, true);
        $rev = is_array($act) ? (int)($act['rev'] ?? 0) : 0;
        if ($rev !== $revEsperada) return ['ok' => false, 'conflicto' => true, 'rev' => $rev];
        $nuevo = json_encode(['rev' => $rev + 1, 'actualizado' => date('c'), 'estado' => $estado], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($nuevo === false) return ['ok' => false, 'error' => 'No se pudieron preparar los datos para guardar.'];
        if (strlen($nuevo) > COORD_MAX_BYTES) return ['ok' => false, 'error' => 'La información supera el tamaño permitido (3 MB). Reduce el logotipo o depura registros.'];
        if ($raw !== '') {                                   // una copia por día, antes del primer cambio del día
            $dir = dirDatos() . '/respaldos';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $dest = $dir . '/' . $esp . '-' . date('Y-m-d') . '.json';
            if (!file_exists($dest)) @file_put_contents($dest, $raw);
            $viejos = glob($dir . '/' . $esp . '-*.json') ?: [];
            sort($viejos);
            foreach (array_slice($viejos, 0, max(0, count($viejos) - 30)) as $v) @unlink($v);
        }
        ftruncate($fp, 0); rewind($fp); fwrite($fp, $nuevo); fflush($fp);
        return ['ok' => true, 'rev' => $rev + 1];
    } finally {
        flock($fp, LOCK_UN); fclose($fp);
    }
}

/* ---------- lo ÚNICO que ve un capitán: su equipo y lo público de sus torneos ---------- */
function normalizaCodigo(string $s): string { return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($s)); }

function vistaCapitan(array $S, string $equipoId, string $esp): ?array
{
    $eq = null;
    foreach ((array)($S['equipos'] ?? []) as $e) if (is_array($e) && txt($e['id'] ?? '') === $equipoId) { $eq = $e; break; }
    if ($eq === null) return null;
    $nomEq = [];
    foreach ((array)$S['equipos'] as $e) if (is_array($e)) $nomEq[txt($e['id'] ?? '')] = txt($e['nombre'] ?? '');
    $torneos = []; $idsT = [];
    foreach ((array)($S['torneos'] ?? []) as $t) {
        if (!is_array($t) || !in_array($equipoId, (array)($t['equipos'] ?? []), true)) continue;
        $idsT[txt($t['id'] ?? '')] = true;
        $torneos[] = [
            'id' => txt($t['id'] ?? ''), 'nombre' => txt($t['nombre'] ?? ''), 'formato' => txt($t['formato'] ?? ''), 'estado' => txt($t['estado'] ?? ''),
            'temporadaId' => txt($t['temporadaId'] ?? ''), 'categoriaId' => txt($t['categoriaId'] ?? ''),
            'inicio' => txt($t['inicio'] ?? ''), 'fin' => txt($t['fin'] ?? ''),
            'reglas' => is_array($t['reglas'] ?? null) ? $t['reglas'] : [],
            'equipos' => array_values(array_map('strval', (array)($t['equipos'] ?? []))),
        ];
    }
    $partidos = [];
    foreach ((array)($S['partidos'] ?? []) as $p) {
        if (!is_array($p) || !isset($idsT[txt($p['torneoId'] ?? '')])) continue;
        $partidos[] = [                                       // sin historial ni notas internas
            'id' => txt($p['id'] ?? ''), 'torneoId' => txt($p['torneoId'] ?? ''), 'jornadaId' => txt($p['jornadaId'] ?? ''),
            'localId' => txt($p['localId'] ?? ''), 'visitaId' => txt($p['visitaId'] ?? ''),
            'fecha' => txt($p['fecha'] ?? ''), 'hora' => txt($p['hora'] ?? ''), 'sedeId' => txt($p['sedeId'] ?? ''), 'canchaId' => txt($p['canchaId'] ?? ''),
            'estado' => txt($p['estado'] ?? ''), 'gl' => $p['gl'] ?? null, 'gv' => $p['gv'] ?? null, 'motivo' => txt($p['motivo'] ?? ''),
        ];
    }
    $jornadas = array_values(array_filter((array)($S['jornadas'] ?? []), static fn($j) => is_array($j) && isset($idsT[txt($j['torneoId'] ?? '')])));
    $equiposPub = [];                                         // de los demás equipos: sólo id y nombre
    foreach ($torneos as $t) foreach ($t['equipos'] as $id) $equiposPub[$id] = ['id' => $id, 'nombre' => $nomEq[$id] ?? ''];
    $movs = [];
    foreach ((array)($S['movs'] ?? []) as $m) {
        if (!is_array($m) || txt($m['equipoId'] ?? '') !== $equipoId) continue;
        $movs[] = ['id' => txt($m['id'] ?? ''), 'folio' => (int)($m['folio'] ?? 0), 'tipo' => txt($m['tipo'] ?? ''), 'concepto' => txt($m['concepto'] ?? ''),
                   'monto' => (float)($m['monto'] ?? 0), 'fecha' => txt($m['fecha'] ?? ''), 'forma' => txt($m['forma'] ?? ''), 'nota' => txt($m['nota'] ?? ''),
                   'temporadaId' => txt($m['temporadaId'] ?? '')];
    }
    $liga = is_array($S['liga'] ?? null) ? $S['liga'] : [];
    return [
        'espacio' => $esp,
        'liga' => ['nombre' => txt($liga['nombre'] ?? ''), 'logo' => txt($liga['logo'] ?? ''), 'color' => txt($liga['color'] ?? ''),
                   'contacto' => is_array($liga['contacto'] ?? null) ? $liga['contacto'] : [], 'sedes' => array_values((array)($liga['sedes'] ?? []))],
        'equipo' => ['id' => $equipoId, 'nombre' => txt($eq['nombre'] ?? ''), 'capitan' => is_array($eq['capitan'] ?? null) ? $eq['capitan'] : [],
                     'inscripcion' => is_array($eq['inscripcion'] ?? null) ? $eq['inscripcion'] : [], 'plantilla' => array_values((array)($eq['plantilla'] ?? []))],
        'temporadas' => array_values((array)($S['temporadas'] ?? [])),
        'categorias' => array_values((array)($S['categorias'] ?? [])),
        'torneos' => $torneos, 'jornadas' => $jornadas, 'partidos' => $partidos,
        'equipos' => array_values($equiposPub),
        'movs' => $movs,
    ];
}

/* Acceso con Google: opcional. Sin google.php o sin ID de cliente, el panel entra sólo con contraseña. */
if (is_file(__DIR__ . '/google.php')) require __DIR__ . '/google.php';
$googleListo = function_exists('cgActivo') && cgActivo();

function abrirSesionCoordinador(string $nombre = '', string $via = 'clave'): void
{
    session_regenerate_id(true);
    $_SESSION = ['rol' => 'coordinador', 'visto' => time(), 'csrf' => bin2hex(random_bytes(16)), 'nombre' => $nombre, 'via' => $via];
}

/* =====================================================================
   ACCIONES
   ===================================================================== */
$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$metodo = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

$vencida = COORD_MINUTOS_SESION > 0 && isset($_SESSION['visto']) && (time() - (int)$_SESSION['visto']) > COORD_MINUTOS_SESION * 60;
if ($vencida) $_SESSION = [];
$rol = (string)($_SESSION['rol'] ?? '');
if ($rol !== 'coordinador' && $rol !== 'capitan') $rol = '';
if ($rol !== '') $_SESSION['visto'] = time();

if ($a === 'sesion') {
    salida(['ok' => true, 'rol' => $rol ?: null, 'espacio' => txt($_SESSION['espacio'] ?? ''), 'debeCambiar' => $rol === 'coordinador' && acceso() === null,
            'csrf' => txt($_SESSION['csrf'] ?? ''), 'nombre' => $rol === 'coordinador' ? txt($_SESSION['nombre'] ?? '') : '']);
}

/* ---------- acceso con Google (coordinador) ---------- */
if ($a === 'google') {
    if (!$googleListo || $rol !== '') salida(['ok' => true, 'activo' => false]);
    unset($_SESSION['g_pend']);
    $_SESSION['g_nonce'] = bin2hex(random_bytes(16));               /* viaja dentro del token y sirve una vez */
    salida(['ok' => true, 'activo' => true, 'clienteId' => cgClienteId(), 'nonce' => $_SESSION['g_nonce']]);
}
if ($a === 'entrar_google' || $a === 'ligar_google') {
    if ($metodo !== 'POST') salida(['ok' => false, 'error' => 'Método no permitido.'], 405);
    if (!$googleListo) salida(['ok' => false, 'error' => 'El acceso con Google no está habilitado.'], 503);
    if (intentos(false)) salida(['ok' => false, 'error' => 'Demasiados intentos. Espera 15 minutos y vuelve a probar.'], 429);
    $in = cuerpo();
    if ($a === 'entrar_google') {
        $nonce = txt($_SESSION['g_nonce'] ?? '');
        unset($_SESSION['g_nonce'], $_SESSION['g_pend']);            /* el número se gasta aunque falle */
        $v = cgVerificar(txt($in['credencial'] ?? ''), $nonce);
        if (!$v['ok']) {
            error_log('[coordinador/google] token rechazado: ' . ($v['motivo'] ?? '?'));
            salida(['ok' => false, 'error' => 'Google no pudo confirmar tu cuenta. Recarga la página e inténtalo de nuevo.']);
        }
        if (isset(cgCuentas()[$v['sub']])) {
            cgLigar($v['sub'], $v['correo'], $v['nombre']);          /* refresca nombre y última entrada */
            abrirSesionCoordinador($v['nombre'] ?: $v['correo'], 'google');
            salida(['ok' => true, 'rol' => 'coordinador', 'debeCambiar' => acceso() === null, 'csrf' => $_SESSION['csrf'], 'nombre' => $_SESSION['nombre']]);
        }
        /* cuenta comprobada pero todavía no ligada: falta la contraseña del coordinador, una sola vez */
        $_SESSION['g_pend'] = ['sub' => $v['sub'], 'correo' => $v['correo'], 'nombre' => $v['nombre'], 'vence' => time() + CG_ESPERA];
        salida(['ok' => true, 'falta' => 'clave', 'nombre' => $v['nombre'], 'correo' => $v['correo']]);
    }
    $p = $_SESSION['g_pend'] ?? null;
    if (!is_array($p) || (int)($p['vence'] ?? 0) < time()) { unset($_SESSION['g_pend']); salida(['ok' => false, 'error' => 'Pasó mucho tiempo. Pulsa otra vez «Continuar con Google».', 'reiniciar' => true]); }
    usleep(350000);
    if (acceso() === null) salida(['ok' => false, 'error' => 'Primero entra con la contraseña inicial y crea tu contraseña. Después podrás ligar tu cuenta de Google.']);
    if (!claveCorrecta(txt($in['clave'] ?? ''))) { intentos(true); salida(['ok' => false, 'error' => 'Contraseña incorrecta.']); }
    $e = cgLigar(txt($p['sub']), txt($p['correo']), txt($p['nombre']));
    if ($e !== '') salida(['ok' => false, 'error' => $e]);
    intentos(false, true);
    abrirSesionCoordinador(txt($p['nombre']) ?: txt($p['correo']), 'google');
    salida(['ok' => true, 'rol' => 'coordinador', 'debeCambiar' => false, 'csrf' => $_SESSION['csrf'], 'nombre' => $_SESSION['nombre'], 'ligada' => true]);
}

if ($a === 'entrar' || $a === 'entrar_capitan') {
    if ($metodo !== 'POST') salida(['ok' => false, 'error' => 'Método no permitido.'], 405);
    if (intentos(false)) salida(['ok' => false, 'error' => 'Demasiados intentos. Espera 15 minutos y vuelve a probar.'], 429);
    $in = cuerpo();
    usleep(350000);
    if ($a === 'entrar') {
        if (!claveCorrecta(txt($in['clave'] ?? ''))) { intentos(true); salida(['ok' => false, 'error' => 'Contraseña incorrecta.']); }
        intentos(false, true);
        abrirSesionCoordinador();
        salida(['ok' => true, 'rol' => 'coordinador', 'debeCambiar' => acceso() === null, 'csrf' => $_SESSION['csrf'], 'nombre' => '']);
    }
    $codigo = normalizaCodigo(txt($in['codigo'] ?? ''));
    $hallado = null;
    if (strlen($codigo) >= 6) {
        foreach (COORD_ESPACIOS as $esp) {
            $S = leerEspacio($esp)['estado'];
            foreach ((array)($S['equipos'] ?? []) as $e) {
                if (is_array($e) && txt($e['codigo'] ?? '') !== '' && hash_equals(normalizaCodigo(txt($e['codigo'])), $codigo)) { $hallado = [$esp, txt($e['id'] ?? '')]; break 2; }
            }
        }
    }
    if ($hallado === null) { intentos(true); salida(['ok' => false, 'error' => 'Ese código no corresponde a ningún equipo.']); }
    intentos(false, true);
    session_regenerate_id(true);
    $_SESSION = ['rol' => 'capitan', 'espacio' => $hallado[0], 'equipoId' => $hallado[1], 'visto' => time()];
    salida(['ok' => true, 'rol' => 'capitan', 'espacio' => $hallado[0]]);
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

if ($rol === '') salida(['ok' => false, 'auth' => false]);

/* ---------- CAPITÁN: un solo endpoint, de lectura ---------- */
if ($rol === 'capitan') {
    if ($a !== 'capitan' || $metodo !== 'GET') salida(['ok' => false, 'error' => 'Tu acceso es de sólo consulta.'], 403);
    $esp = txt($_SESSION['espacio'] ?? '');
    if (!in_array($esp, COORD_ESPACIOS, true)) salida(['ok' => false, 'auth' => false]);
    $v = vistaCapitan(leerEspacio($esp)['estado'] ?? [], txt($_SESSION['equipoId'] ?? ''), $esp);
    if ($v === null) { $_SESSION = []; salida(['ok' => false, 'auth' => false, 'error' => 'Tu equipo ya no está registrado.']); }
    salida(['ok' => true, 'vista' => $v]);
}

/* ---------- COORDINADOR ---------- */
$espacio = static function (string $e): string {
    if (!in_array($e, COORD_ESPACIOS, true)) salida(['ok' => false, 'error' => 'Espacio de datos no válido.'], 400);
    return $e;
};
$exigirEscritura = static function () use ($metodo): void {
    if ($metodo !== 'POST') salida(['ok' => false, 'error' => 'Método no permitido.'], 405);
    $t = (string)($_SERVER['HTTP_X_COORD_CSRF'] ?? '');
    if ($t === '' || !hash_equals(txt($_SESSION['csrf'] ?? ''), $t)) salida(['ok' => false, 'error' => 'La sesión cambió. Recarga la página.'], 403);
};

if ($a === 'cambiar_clave') {
    $exigirEscritura();
    $in = cuerpo();
    $nueva = txt($in['nueva'] ?? '');
    if (!claveCorrecta(txt($in['actual'] ?? ''))) salida(['ok' => false, 'error' => 'La contraseña actual no coincide.']);
    if (strlen($nueva) < 8) salida(['ok' => false, 'error' => 'La contraseña nueva debe tener al menos 8 caracteres.']);
    if (hash_equals((string)COORD_CLAVE_INICIAL, $nueva)) salida(['ok' => false, 'error' => 'Elige una contraseña distinta de la inicial.']);
    $ok = @file_put_contents(dirDatos() . '/acceso.json', json_encode(['hash' => password_hash($nueva, PASSWORD_DEFAULT), 'cambiada' => date('c')]), LOCK_EX);
    if ($ok === false) salida(['ok' => false, 'error' => 'No se pudo guardar la contraseña. Revisa los permisos de la carpeta de datos.'], 500);
    salida(['ok' => true]);
}
/* Mientras no se cambie la contraseña inicial no se entrega ni se guarda nada. */
if (acceso() === null) salida(['ok' => false, 'debeCambiar' => true, 'error' => 'Primero cambia la contraseña inicial.'], 403);

/* Cuentas de Google que pueden entrar como coordinador (para verlas y quitarlas en Configuración). */
if ($a === 'google_cuentas') {
    $l = [];
    if ($googleListo) foreach (cgCuentas() as $sub => $c) $l[] = ['sub' => (string)$sub, 'correo' => txt($c['correo'] ?? ''), 'nombre' => txt($c['nombre'] ?? ''), 'desde' => txt($c['desde'] ?? ''), 'ultimo' => txt($c['ultimo'] ?? '')];
    salida(['ok' => true, 'activo' => $googleListo, 'cuentas' => $l]);
}
if ($a === 'google_quitar') {
    $exigirEscritura();
    $c = $googleListo ? cgCuentas() : [];
    $sub = txt(cuerpo()['sub'] ?? '');
    if (!isset($c[$sub])) salida(['ok' => false, 'error' => 'Esa cuenta ya no está ligada.']);
    unset($c[$sub]);
    if (!cgGuardar($c)) salida(['ok' => false, 'error' => 'No se pudo guardar. Revisa los permisos de la carpeta de datos.'], 500);
    salida(['ok' => true]);
}

if ($a === 'estado') {
    $d = leerEspacio($espacio(txt($_GET['espacio'] ?? 'real')));
    salida(['ok' => true, 'rev' => $d['rev'], 'actualizado' => $d['actualizado'], 'estado' => $d['estado']]);
}

if ($a === 'guardar') {
    $exigirEscritura();
    $in = cuerpo();
    if (!is_array($in['estado'] ?? null)) salida(['ok' => false, 'error' => 'No llegaron datos para guardar.'], 400);
    $r = guardarEspacio($espacio(txt($in['espacio'] ?? '')), (int)($in['rev'] ?? -1), $in['estado']);
    if (!$r['ok']) salida($r, !empty($r['conflicto']) ? 409 : 400);
    salida($r);
}

if ($a === 'vista_capitan') {
    $esp = $espacio(txt($_GET['espacio'] ?? 'real'));
    $v = vistaCapitan(leerEspacio($esp)['estado'] ?? [], txt($_GET['equipo'] ?? ''), $esp);
    if ($v === null) salida(['ok' => false, 'error' => 'Ese equipo no existe.'], 404);
    salida(['ok' => true, 'vista' => $v]);
}

/* Copia de SÓLO LECTURA del registro de equipos de Tesorería (si este sitio la tiene). */
if ($a === 'tesoreria') {
    $f = __DIR__ . '/../arbitrajes/datos/estado.json';
    $j = is_file($f) && is_readable($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $T = is_array($j) && is_array($j['estado'] ?? null) ? $j['estado'] : null;
    if ($T === null) salida(['ok' => true, 'disponible' => false]);
    $nom = static function ($lista, string $id): string {
        foreach ((array)$lista as $x) if (is_array($x) && txt($x['id'] ?? '') === $id) return txt($x['nombre'] ?? '');
        return '';
    };
    $eqs = [];
    foreach ((array)($T['equipos'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $eqs[] = [
            'nombre' => txt($e['nombre'] ?? ''), 'encargado' => txt($e['encargado'] ?? ''), 'tel' => txt($e['tel'] ?? ''),
            'edicion' => $nom($T['ediciones'] ?? [], txt($e['edicionId'] ?? '')),
            'disciplina' => $nom($T['disciplinas'] ?? [], txt($e['disciplinaId'] ?? '')),
            'rama' => $nom($T['ramas'] ?? [], txt($e['ramaId'] ?? '')),
            'categoria' => $nom($T['categorias'] ?? [], txt($e['categoriaId'] ?? '')),
            'jugadores' => array_values(array_filter(array_map(static fn($x) => is_array($x) ? trim(txt($x['nombre'] ?? '')) : '', (array)($e['jugadores'] ?? [])), 'strlen')),
        ];
    }
    salida(['ok' => true, 'disponible' => true, 'liga' => txt($T['liga']['nombre'] ?? ''), 'equipos' => $eqs]);
}

salida(['ok' => false, 'error' => 'Acción no reconocida.'], 400);
