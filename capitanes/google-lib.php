<?php
/* =====================================================================
   Liga Premier Chapingo — Portal de capitanes · ACCESO CON GOOGLE (04-oct-2026)

   Google sólo dice QUIÉN es la persona; a qué equipo entra lo decide el código
   de capitán, que se escribe UNA vez para ligar la cuenta. Después entra con Google.

   · El token de Google se verifica aquí, en el servidor: firma (llaves públicas
     de Google), destinatario (nuestro ID de cliente), emisor, vigencia, correo
     verificado y el número de un solo uso que entregó este mismo portal.
   · La cuenta se identifica por su «sub» (id fijo de Google), nunca por el correo.
   · Los vínculos viven en datos/google-vinculos.json (carpeta ya protegida):
     sub → equipos. No se guarda el código ni nada financiero.
   ===================================================================== */

declare(strict_types=1);

if (!defined('SEMILLA_CAPITANES')) { http_response_code(404); exit; }   /* nunca se abre directo */

const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
const GOOGLE_EMISORES  = ['accounts.google.com', 'https://accounts.google.com'];
const GOOGLE_ESPERA    = 600;    /* segundos para escribir el código después de entrar con Google */
const GOOGLE_MAX_EQUIPOS = 12;   /* equipos que una misma cuenta puede ligar */

function googleClienteId(): string {
    return defined('GOOGLE_CLIENT_ID') ? trim((string)GOOGLE_CLIENT_ID) : '';
}
function googleActivo(): bool {
    return googleClienteId() !== '' && function_exists('openssl_verify');
}
function googleDirDatos(): string {
    archivoIntentos();                                   /* crea datos/ y su .htaccess si faltan */
    return rtrim(INTENTOS_DIR, '/\\');
}

/** Descarga un recurso de Google por HTTPS. Devuelve [cuerpo, segundos de vigencia] o null. */
function googleDescargar(string $url): ?array {
    $cuerpo = false; $cab = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$cab) { $cab[] = trim($h); return strlen($h); },
        ]);
        $cuerpo = curl_exec($ch);
        $ok = $cuerpo !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if (!$ok) $cuerpo = false;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $cuerpo = @file_get_contents($url, false, $ctx);
        $cab = $http_response_header ?? [];
    }
    if (!is_string($cuerpo) || $cuerpo === '') return null;
    $vig = 3600;
    foreach ($cab as $h) {
        if (stripos($h, 'cache-control:') === 0 && preg_match('/max-age=(\d+)/i', $h, $m)) $vig = max(300, min(86400, (int)$m[1]));
    }
    return [$cuerpo, $vig];
}

/**
 * Certificados públicos de Google: kid → PEM. Se guardan en datos/ el tiempo que Google indica.
 * Si Google no responde, sirve la última copia guardada.
 */
function googleCertificados(bool $forzar = false): array {
    $f = googleDirDatos() . '/google-certs.json';
    $guardado = null;
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && is_array($j['certs'] ?? null)) $guardado = $j;
    }
    if (!$forzar && $guardado && (int)($guardado['vence'] ?? 0) > time()) return $guardado['certs'];
    $r = googleDescargar(GOOGLE_CERTS_URL);
    $certs = $r ? json_decode($r[0], true) : null;
    if (is_array($certs) && $certs) {
        $certs = array_filter($certs, 'is_string');
        @file_put_contents($f, json_encode(['vence' => time() + $r[1], 'certs' => $certs]), LOCK_EX);
        return $certs;
    }
    return $guardado ? $guardado['certs'] : [];
}

/**
 * Verifica un token de identidad de Google.
 * @return array{ok:bool, sub?:string, correo?:string, nombre?:string, motivo?:string}
 */
function googleVerificar(string $jwt, string $nonce): array {
    $no = function (string $m): array { return ['ok' => false, 'motivo' => $m]; };
    if (!googleActivo()) return $no('inactivo');
    if ($jwt === '' || strlen($jwt) > 4096 || substr_count($jwt, '.') !== 2) return $no('formato');
    [$c64, $p64, $f64] = explode('.', $jwt);
    $cab = json_decode((string)b64urlDec($c64), true);
    $car = json_decode((string)b64urlDec($p64), true);
    $firma = b64urlDec($f64);
    if (!is_array($cab) || !is_array($car) || !is_string($firma) || $firma === '') return $no('formato');
    if (($cab['alg'] ?? '') !== 'RS256' || !is_string($cab['kid'] ?? null)) return $no('algoritmo');

    $certs = googleCertificados();
    if (!isset($certs[$cab['kid']])) $certs = googleCertificados(true);        /* Google rota sus llaves */
    $pem = $certs[$cab['kid']] ?? null;
    if (!is_string($pem)) return $no('llave');
    $llave = openssl_pkey_get_public($pem);
    if ($llave === false || openssl_verify($c64 . '.' . $p64, $firma, $llave, OPENSSL_ALGO_SHA256) !== 1) return $no('firma');

    $ahora = time();
    if (!in_array($car['iss'] ?? '', GOOGLE_EMISORES, true)) return $no('emisor');
    if (!is_string($car['aud'] ?? null) || !hash_equals(googleClienteId(), $car['aud'])) return $no('destinatario');
    if (!is_int($car['exp'] ?? null) || $car['exp'] < $ahora) return $no('vencido');
    if (is_int($car['iat'] ?? null) && $car['iat'] > $ahora + 300) return $no('fecha');
    if ($nonce === '' || !is_string($car['nonce'] ?? null) || !hash_equals($nonce, $car['nonce'])) return $no('nonce');
    $sub = is_string($car['sub'] ?? null) ? $car['sub'] : '';
    if (!preg_match('/^[0-9]{5,64}$/', $sub)) return $no('cuenta');
    if (($car['email_verified'] ?? false) !== true && ($car['email_verified'] ?? '') !== 'true') return $no('correo');

    return ['ok' => true, 'sub' => $sub,
            'correo' => mb_substr(is_string($car['email'] ?? null) ? $car['email'] : '', 0, 190),
            'nombre' => mb_substr(is_string($car['name'] ?? null) ? $car['name'] : '', 0, 120)];
}

/* ---------- vínculos cuenta de Google → equipos ---------- */
function googleArchivoVinculos(): string { return googleDirDatos() . '/google-vinculos.json'; }
function googleVersion(): int { return defined('GOOGLE_VINCULOS_VERSION') ? (int)GOOGLE_VINCULOS_VERSION : 1; }

/** Equipos ligados a una cuenta (ids de Tesorería). Sólo los de la versión vigente. */
function googleEquiposDe(string $sub): array {
    $j = json_decode((string)@file_get_contents(googleArchivoVinculos()), true);
    $v = is_array($j) ? ($j['cuentas'][$sub] ?? null) : null;
    if (!is_array($v) || (int)($v['v'] ?? 0) !== googleVersion()) return [];
    return array_values(array_filter(is_array($v['equipos'] ?? null) ? $v['equipos'] : [], 'is_string'));
}

/** Liga un equipo a la cuenta (no repite). Devuelve false si no se pudo guardar. */
function googleLigar(string $sub, string $equipoId, string $correo, string $nombre): bool {
    $fp = @fopen(googleArchivoVinculos(), 'c+');
    if (!$fp) return false;
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $j = $raw === '' ? [] : json_decode((string)$raw, true);
    if (!is_array($j) || !is_array($j['cuentas'] ?? null)) $j = ['cuentas' => []];
    $v = $j['cuentas'][$sub] ?? null;
    if (!is_array($v) || (int)($v['v'] ?? 0) !== googleVersion()) $v = ['v' => googleVersion(), 'equipos' => [], 'desde' => date('c')];
    $eq = array_values(array_filter(is_array($v['equipos'] ?? null) ? $v['equipos'] : [], 'is_string'));
    if (!in_array($equipoId, $eq, true)) $eq[] = $equipoId;
    $v['equipos'] = array_slice($eq, -GOOGLE_MAX_EQUIPOS);
    $v['correo'] = $correo; $v['nombre'] = $nombre; $v['ultimo'] = date('c');
    $j['cuentas'][$sub] = $v;
    ftruncate($fp, 0); rewind($fp);
    $ok = @fwrite($fp, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fp); @flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
