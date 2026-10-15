<?php
/* =====================================================================
   Panel de coordinación — ACCESO CON GOOGLE (opcional)

   Google sólo dice QUIÉN es la persona. Que esa persona sea coordinador lo
   decide la contraseña del panel, que se escribe UNA vez para ligar la cuenta.
   Después entra con Google, sin contraseña.

   · El token de Google se verifica aquí, en el servidor: firma (llaves públicas
     de Google), destinatario (el ID de cliente de config.php), emisor, vigencia,
     correo verificado y el número de un solo uso que entregó este mismo panel.
   · La cuenta se identifica por su «sub» (id fijo de Google), nunca por el correo.
   · Las cuentas ligadas viven en datos/google-coordinador.json (carpeta cerrada).
   · Sin ID de cliente en config.php, nada de esto se activa.
   ===================================================================== */

declare(strict_types=1);

if (!defined('COORD_DATOS')) { http_response_code(404); exit; }      /* nunca se abre directo */

const CG_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
const CG_EMISORES  = ['accounts.google.com', 'https://accounts.google.com'];
const CG_ESPERA    = 600;     /* segundos para escribir la contraseña después de entrar con Google */
const CG_MAX_CUENTAS = 8;

function cgClienteId(): string { return defined('COORD_GOOGLE_CLIENT_ID') ? trim((string)COORD_GOOGLE_CLIENT_ID) : ''; }
function cgActivo(): bool { return cgClienteId() !== '' && function_exists('openssl_verify'); }
function cgB64(string $s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function cgDescargar(string $url): ?array
{
    $cuerpo = false; $cab = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$cab) { $cab[] = trim($h); return strlen($h); }]);
        $cuerpo = curl_exec($ch);
        $ok = $cuerpo !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if (!$ok) $cuerpo = false;
    } else {
        $cuerpo = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
        $cab = $http_response_header ?? [];
    }
    if (!is_string($cuerpo) || $cuerpo === '') return null;
    $vig = 3600;
    foreach ($cab as $h) if (stripos($h, 'cache-control:') === 0 && preg_match('/max-age=(\d+)/i', $h, $m)) $vig = max(300, min(86400, (int)$m[1]));
    return [$cuerpo, $vig];
}

/** Certificados públicos de Google (kid → PEM), guardados el tiempo que Google indica. */
function cgCertificados(bool $forzar = false): array
{
    $f = dirDatos() . '/google-certs.json';
    $guardado = null;
    if (is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j) && is_array($j['certs'] ?? null)) $guardado = $j; }
    if (!$forzar && $guardado && (int)($guardado['vence'] ?? 0) > time()) return $guardado['certs'];
    $r = cgDescargar(CG_CERTS_URL);
    $certs = $r ? json_decode($r[0], true) : null;
    if (is_array($certs) && $certs) {
        $certs = array_filter($certs, 'is_string');
        @file_put_contents($f, json_encode(['vence' => time() + $r[1], 'certs' => $certs]), LOCK_EX);
        return $certs;
    }
    return $guardado ? $guardado['certs'] : [];
}

/** @return array{ok:bool, sub?:string, correo?:string, nombre?:string, motivo?:string} */
function cgVerificar(string $jwt, string $nonce): array
{
    $no = static fn(string $m): array => ['ok' => false, 'motivo' => $m];
    if (!cgActivo()) return $no('inactivo');
    if ($jwt === '' || strlen($jwt) > 4096 || substr_count($jwt, '.') !== 2) return $no('formato');
    [$c64, $p64, $f64] = explode('.', $jwt);
    $cab = json_decode((string)cgB64($c64), true);
    $car = json_decode((string)cgB64($p64), true);
    $firma = cgB64($f64);
    if (!is_array($cab) || !is_array($car) || !is_string($firma) || $firma === '') return $no('formato');
    if (($cab['alg'] ?? '') !== 'RS256' || !is_string($cab['kid'] ?? null)) return $no('algoritmo');

    $certs = cgCertificados();
    if (!isset($certs[$cab['kid']])) $certs = cgCertificados(true);            /* Google rota sus llaves */
    $pem = $certs[$cab['kid']] ?? null;
    if (!is_string($pem)) return $no('llave');
    $llave = openssl_pkey_get_public($pem);
    if ($llave === false || openssl_verify($c64 . '.' . $p64, $firma, $llave, OPENSSL_ALGO_SHA256) !== 1) return $no('firma');

    $ahora = time();
    if (!in_array($car['iss'] ?? '', CG_EMISORES, true)) return $no('emisor');
    if (!is_string($car['aud'] ?? null) || !hash_equals(cgClienteId(), $car['aud'])) return $no('destinatario');
    if (!is_int($car['exp'] ?? null) || $car['exp'] < $ahora) return $no('vencido');
    if (is_int($car['iat'] ?? null) && $car['iat'] > $ahora + 300) return $no('fecha');
    if ($nonce === '' || !is_string($car['nonce'] ?? null) || !hash_equals($nonce, $car['nonce'])) return $no('nonce');
    $sub = is_string($car['sub'] ?? null) ? $car['sub'] : '';
    if (!preg_match('/^[0-9]{5,64}$/', $sub)) return $no('cuenta');
    if (($car['email_verified'] ?? false) !== true && ($car['email_verified'] ?? '') !== 'true') return $no('correo');
    $corta = static fn($s, int $n): string => function_exists('mb_substr') ? mb_substr((string)$s, 0, $n) : substr((string)$s, 0, $n);
    return ['ok' => true, 'sub' => $sub, 'correo' => $corta(is_string($car['email'] ?? null) ? $car['email'] : '', 190),
            'nombre' => $corta(is_string($car['name'] ?? null) ? $car['name'] : '', 120)];
}

/* ---------- cuentas de Google ligadas al perfil de coordinador ---------- */
function cgArchivo(): string { return dirDatos() . '/google-coordinador.json'; }

/** sub → {correo, nombre, desde, ultimo} */
function cgCuentas(): array
{
    $j = is_file(cgArchivo()) ? json_decode((string)@file_get_contents(cgArchivo()), true) : null;
    return is_array($j) && is_array($j['cuentas'] ?? null) ? $j['cuentas'] : [];
}
function cgGuardar(array $cuentas): bool
{
    return @file_put_contents(cgArchivo(), json_encode(['cuentas' => $cuentas], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
/** @return string '' si quedó ligada, o el motivo por el que no */
function cgLigar(string $sub, string $correo, string $nombre): string
{
    $c = cgCuentas();
    if (!isset($c[$sub]) && count($c) >= CG_MAX_CUENTAS) return 'Ya hay ' . CG_MAX_CUENTAS . ' cuentas de Google ligadas. Quita alguna en Configuración → Acceso.';
    $c[$sub] = ['correo' => $correo, 'nombre' => $nombre, 'desde' => $c[$sub]['desde'] ?? date('c'), 'ultimo' => date('c')];
    return cgGuardar($c) ? '' : 'No se pudo guardar el vínculo. Revisa los permisos de la carpeta de datos.';
}
