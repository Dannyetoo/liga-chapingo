<?php
/* =====================================================================
   Liga Chapingo — API de contenido del sitio público
   Guarda textos, eventos, campeones y galería en un archivo JSON con
   número de revisión. Las convocatorias e inscripciones (formularios de
   Copa Fácil) y las tablas de clasificación se editan directamente en
   index.html, no aquí. No tiene nada que ver con api.php ni con
   datos/estado.json de la app de arbitrajes.

   Endpoints:
     GET  contenido.php?a=ver      -> {ok, rev, contenido}   (público)
     GET  contenido.php?a=sesion   -> {ok, dentro}
     POST contenido.php?a=login    -> {ok}
     GET  contenido.php?a=salir    -> {ok}
     POST contenido.php?a=guardar  -> {ok, rev} | {conflicto, rev, contenido}
     POST contenido.php?a=subir    -> {ok, ruta}             (multipart)
     POST contenido.php?a=borrar   -> {ok}                   (imagen de img/)
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/config-sitio.php';

$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('lchsitio');
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

const LIMITE_BYTES = 4194304; // 4 MB de JSON

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
function archivo(): string { return rtrim(CONTENIDO_DIR, '/\\') . '/contenido.json'; }

function prepararCarpeta(): void {
    $dir = rtrim(CONTENIDO_DIR, '/\\');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $sub = rtrim(SUBIDAS_DIR, '/\\');
    if (!is_dir($sub)) @mkdir($sub, 0755, true);
    $hts = $sub . '/.htaccess';
    if (is_dir($sub) && !file_exists($hts)) {
        @file_put_contents($hts,
            "Options -Indexes\n" .
            "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n" .
            "<FilesMatch \"\\.(php|php5|phtml|pl|py|cgi|sh)$\">\n" .
            "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n" .
            "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n" .
            "</FilesMatch>\n");
    }
}

/* ---------- contenido de arranque ---------- */
function semilla(): array {
    return [
        'sitio' => [
            'nombre'    => 'Liga Chapingo',
            'lema'      => 'Deporte universitario con orden y palabra',
            'subtitulo' => 'Universidad Autónoma Chapingo',
        ],
        'hero' => [
            'eyebrow' => 'Tercera Edición 2026',
            'titulo'  => 'La liga de Chapingo, en una sola página',
            'texto'   => 'Convocatorias, inscripciones, clasificaciones, disciplinas, categorías y campeones. Si eres capitán, aquí mismo consultas el estado de cuenta de tu equipo.',
            'imagen'  => '',
        ],
        'torneo' => [
            'nombre'      => 'Tercera Edición',
            'estado'      => 'En curso',
            'texto'       => 'Fútbol, basquetbol y voleibol bajo un mismo torneo. Los partidos se juegan en las canchas de la Universidad Autónoma Chapingo, Texcoco, Estado de México.',
            'jornada'     => 'Jornada 1',
            'fechas'      => 'Sábados y domingos · 9:00 a 18:00 h',
            'sede'        => 'Canchas UACh, Chapingo, Texcoco',
            'datos'       => [
                ['etiqueta' => 'Disciplinas',        'valor' => '3'],
                ['etiqueta' => 'Categorías',         'valor' => '6'],
                ['etiqueta' => 'Equipos inscritos',  'valor' => 'Abierto'],
                ['etiqueta' => 'Jugadores',          'valor' => 'Abierto'],
            ],
            'enlaces'     => [
                ['titulo' => 'Calendario y resultados',  'url' => 'https://copafacil.com/ligachapingo', 'nota' => 'Copa Fácil · Rol de juegos'],
                ['titulo' => 'Tabla de clasificación',   'url' => '#tablas', 'nota' => 'Primera y Segunda Fuerza'],
            ],
        ],
        'eventos' => [
            [
                'id' => 'ev1', 'titulo' => 'Junta de representantes',
                'fecha' => '2026-09-20', 'hora' => '11:00',
                'lugar' => 'Aula Magna, UACh',
                'texto' => 'Entrega de credenciales, calendario de la Tercera Edición y acuerdos de arbitraje. Asistencia obligatoria para un representante por equipo.',
                'imagen' => '',
            ],
            [
                'id' => 'ev2', 'titulo' => 'Jornada doble de aniversario',
                'fecha' => '2026-10-04', 'hora' => '09:00',
                'lugar' => 'Canchas UACh',
                'texto' => 'Todas las categorías juegan el mismo día. Habrá reconocimiento a los equipos con inscripción completa.',
                'imagen' => '',
            ],
        ],
        'campeones' => [
            ['id' => 'ca1', 'anio' => '',     'edicion' => 'Relámpago A · Septiembre 2026',            'disciplina' => 'Fútbol', 'rama' => '', 'categoria' => '', 'equipo' => 'Monkeys FC',       'imagen' => ''],
            ['id' => 'ca2', 'anio' => '2026', 'edicion' => 'Segunda Edición',                          'disciplina' => 'Fútbol', 'rama' => '', 'categoria' => '', 'equipo' => 'Recursos Humanos', 'imagen' => ''],
            ['id' => 'ca3', 'anio' => '2025', 'edicion' => 'Primera Edición',                          'disciplina' => 'Fútbol', 'rama' => '', 'categoria' => '', 'equipo' => 'Rebaño Sagrado',    'imagen' => ''],
            ['id' => 'ca4', 'anio' => '',     'edicion' => 'Primer Torneo Relámpago · Octubre 2025',   'disciplina' => 'Fútbol', 'rama' => '', 'categoria' => '', 'equipo' => 'Chapuboys',        'imagen' => ''],
        ],
        'galeria' => [],
        'arbitrajes' => [
            'titulo' => 'Acceso para capitanes de equipo',
            'texto'  => 'Aquí el capitán revisa el estado de cuenta de su equipo: cargos de arbitraje, pagos entregados, saldo pendiente y multas individuales de sus jugadores.',
            'aviso'  => 'Entra con el código de capitán que te dio la mesa directiva. Es una pantalla de solo consulta: los cobros los registra la tesorería.',
            /* 'url' se conserva por compatibilidad con el contenido ya guardado,
               pero el sitio ya no lo usa: los botones llevan siempre a capitanes/. */
            'url'    => 'arbitrajes/',
        ],
        'contacto' => [
            'texto'     => 'Mesa directiva de Liga Chapingo. Atención de lunes a viernes de 10:00 a 18:00 h.',
            'whatsapp'  => '5657044949',
            'telefono'  => '',
            'correo'    => 'contacto@ligachapingo.com',
            'direccion' => 'Universidad Autónoma Chapingo, km 38.5 carretera México–Texcoco, Chapingo, Texcoco, Estado de México',
            'facebook'  => '',
            'instagram' => '',
            'tiktok'    => '',
        ],
    ];
}

function leerTodo(): array {
    $f = archivo();
    if (!is_file($f)) return ['rev' => 0, 'contenido' => semilla()];
    $raw = @file_get_contents($f);
    $j = $raw === false ? null : json_decode($raw, true);
    if (!is_array($j) || !isset($j['rev']) || !isset($j['contenido'])) {
        return ['rev' => 0, 'contenido' => semilla()];
    }
    return $j;
}

function respaldoDiario(string $contenidoActual): void {
    if ($contenidoActual === '') return;
    $dir = rtrim(CONTENIDO_DIR, '/\\') . '/respaldos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $destino = $dir . '/contenido-' . date('Y-m-d') . '.json';
    if (!file_exists($destino)) @file_put_contents($destino, $contenidoActual);
    $lista = glob($dir . '/contenido-*.json') ?: [];
    if (count($lista) > 30) {
        sort($lista);
        foreach (array_slice($lista, 0, count($lista) - 30) as $viejo) @unlink($viejo);
    }
}

function dentro(): bool {
    $vencida = MINUTOS_SESION_PANEL > 0
            && isset($_SESSION['visto'])
            && (time() - (int)$_SESSION['visto']) > MINUTOS_SESION_PANEL * 60;
    return !empty($_SESSION['ok']) && !$vencida;
}

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';

/* ---------- lectura pública ---------- */
if ($a === 'ver' || $a === '') {
    prepararCarpeta();
    $d = leerTodo();
    header('Cache-Control: public, max-age=60');
    salida(['ok' => true, 'rev' => (int)$d['rev'], 'contenido' => $d['contenido']]);
}

/* ---------- acceso al panel ---------- */
if ($a === 'login') {
    $intentos = (int)($_SESSION['intentos'] ?? 0);
    if ($intentos >= 8 && (time() - (int)($_SESSION['ultimo'] ?? 0)) < 300) {
        salida(['ok' => false, 'bloqueado' => true], 429);
    }
    $in = cuerpo();
    $clave = (string)($in['clave'] ?? '');
    usleep(400000);
    if (CLAVE_PANEL !== '' && hash_equals(CLAVE_PANEL, $clave)) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['visto'] = time();
        $_SESSION['intentos'] = 0;
        salida(['ok' => true]);
    }
    $_SESSION['intentos'] = $intentos + 1;
    $_SESSION['ultimo'] = time();
    salida(['ok' => false]);
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

if ($a === 'sesion') {
    salida(['ok' => true, 'dentro' => dentro()]);
}

/* ---------- de aquí para abajo, solo con sesión ---------- */
if (!dentro()) salida(['ok' => false, 'auth' => false]);
$_SESSION['visto'] = time();
prepararCarpeta();

/* ---------- subir imagen o PDF ---------- */
if ($a === 'subir') {
    if (empty($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        salida(['ok' => false, 'error' => 'No llegó ningún archivo.'], 400);
    }
    $f = $_FILES['archivo'];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        salida(['ok' => false, 'error' => 'El servidor rechazó la subida (código ' . (int)$f['error'] . ').'], 400);
    }
    if ((int)$f['size'] > MAX_MB * 1024 * 1024) {
        salida(['ok' => false, 'error' => 'El archivo pesa más de ' . MAX_MB . ' MB.'], 413);
    }
    $permitidos = [
        'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
    ];
    $tipo = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $tipo = (string)finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
    } else {
        $ext0 = strtolower((string)pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $mapa = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];
        $tipo = $mapa[$ext0] ?? '';
    }
    if (!isset($permitidos[$tipo])) {
        salida(['ok' => false, 'error' => 'Solo se aceptan imágenes JPG, PNG, WEBP, GIF o archivos PDF.'], 415);
    }
    $ext = $permitidos[$tipo];
    $base = strtolower((string)pathinfo((string)$f['name'], PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim((string)$base, '-');
    if ($base === '') $base = 'archivo';
    $base = substr($base, 0, 40);
    $nombre = $base . '-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
    $destino = rtrim(SUBIDAS_DIR, '/\\') . '/' . $nombre;
    if (!@move_uploaded_file($f['tmp_name'], $destino)) {
        salida(['ok' => false, 'error' => 'No se pudo escribir en la carpeta img/. Dale permisos 755 o 775.'], 500);
    }
    @chmod($destino, 0644);
    salida(['ok' => true, 'ruta' => rtrim(SUBIDAS_URL, '/') . '/' . $nombre, 'tipo' => $ext]);
}

/* ---------- borrar imagen subida ---------- */
if ($a === 'borrar') {
    $in = cuerpo();
    $ruta = (string)($in['ruta'] ?? '');
    $prefijo = rtrim(SUBIDAS_URL, '/') . '/';
    if (strpos($ruta, $prefijo) !== 0 || strpos($ruta, '..') !== false) {
        salida(['ok' => false, 'error' => 'Ruta no permitida.'], 400);
    }
    $nombre = basename($ruta);
    $f = rtrim(SUBIDAS_DIR, '/\\') . '/' . $nombre;
    if (is_file($f)) @unlink($f);
    salida(['ok' => true]);
}

/* ---------- guardar contenido ---------- */
if ($a === 'guardar') {
    $in = cuerpo();
    if (!isset($in['contenido']) || !is_array($in['contenido'])) {
        salida(['ok' => false, 'error' => 'contenido inválido'], 400);
    }
    $contenido = $in['contenido'];
    foreach (['eventos', 'campeones', 'galeria'] as $k) {
        if (isset($contenido[$k]) && !is_array($contenido[$k])) {
            salida(['ok' => false, 'error' => 'contenido inválido'], 400);
        }
    }
    $revCliente = (int)($in['rev'] ?? 0);

    $f = archivo();
    $fp = @fopen($f, 'c+');
    if (!$fp) salida(['ok' => false, 'error' => 'no se puede escribir en la carpeta de contenido'], 500);
    if (!flock($fp, LOCK_EX)) { fclose($fp); salida(['ok' => false, 'error' => 'archivo ocupado'], 503); }

    $raw = stream_get_contents($fp);
    $act = $raw === '' ? null : json_decode($raw, true);
    $revServidor = is_array($act) && isset($act['rev']) ? (int)$act['rev'] : 0;

    if ($revServidor > 0 && $revCliente !== $revServidor) {
        flock($fp, LOCK_UN); fclose($fp);
        salida(['ok' => false, 'conflicto' => true, 'rev' => $revServidor,
                'contenido' => is_array($act) ? ($act['contenido'] ?? null) : null]);
    }

    respaldoDiario($raw ?: '');

    $nuevo = json_encode([
        'rev'         => $revServidor + 1,
        'actualizado' => date('c'),
        'contenido'   => $contenido,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($nuevo === false || strlen($nuevo) > LIMITE_BYTES) {
        flock($fp, LOCK_UN); fclose($fp);
        salida(['ok' => false, 'error' => 'contenido demasiado grande'], 413);
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
