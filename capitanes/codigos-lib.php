<?php
/* =====================================================================
   Liga Premier Chapingo — funciones compartidas del portal de capitanes

   Aquí vive lo único que el portal de capitanes y la mesa directiva
   necesitan compartir: cómo se calcula el código de cada equipo y cómo
   se lee (NUNCA se escribe) el archivo de la app de arbitrajes.

   El código de capitán no se guarda en ninguna parte: se calcula con
   HMAC-SHA256 a partir del id del equipo y de SEMILLA_CAPITANES. Por eso
   no hace falta tocar estado.json ni agregarle campos nuevos.
   ===================================================================== */

declare(strict_types=1);

/* Alfabeto sin 0, 1, I ni O: así nadie confunde un cero con una "o"
   al dictar el código por teléfono o por WhatsApp. */
const ALFABETO_CODIGO = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

/** Código de capitán de un equipo. Siempre el mismo para el mismo id. */
function codigoEquipo(string $equipoId): string {
    $largo = defined('LARGO_CODIGO') ? (int)LARGO_CODIGO : 8;
    if ($largo < 6)  $largo = 6;
    if ($largo > 12) $largo = 12;

    $bytes = hash_hmac('sha256', 'equipo:' . $equipoId, SEMILLA_CAPITANES, true);
    $n = strlen(ALFABETO_CODIGO);
    $out = '';
    for ($i = 0; $i < $largo; $i++) {
        $out .= ALFABETO_CODIGO[ord($bytes[$i]) % $n];
    }
    return $out;
}

/** Código con un guion a la mitad, solo para mostrarlo: ABCD-2345 */
function codigoBonito(string $codigo): string {
    $m = (int)floor(strlen($codigo) / 2);
    return substr($codigo, 0, $m) . '-' . substr($codigo, $m);
}

/** Deja el código como se guarda: sin espacios, guiones ni minúsculas. */
function normalizaCodigo(string $s): string {
    $s = strtoupper(trim($s));
    return (string)preg_replace('/[^A-Z0-9]/', '', $s);
}

/** Lee el estado de la app de arbitrajes. Solo lectura, nunca escribe. */
function leerEstadoArbitrajes(): ?array {
    $f = rtrim(DATOS_ARBITRAJES, '/\\') . '/estado.json';
    if (!is_file($f) || !is_readable($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['estado']) || !is_array($j['estado'])) return null;
    return [
        'rev'         => (int)($j['rev'] ?? 0),
        'actualizado' => (string)($j['actualizado'] ?? ''),
        'estado'      => $j['estado'],
    ];
}

/** Lista de equipos del estado, siempre como arreglo. */
function equiposDelEstado(array $estado): array {
    $eq = $estado['equipos'] ?? [];
    return is_array($eq) ? $eq : [];
}

/**
 * Busca a qué equipo pertenece un código.
 * Devuelve el id del equipo, o '' si no coincide con ninguno.
 * La comparación es en tiempo constante y recorre todos los equipos
 * aunque ya haya encontrado uno, para no filtrar información por el
 * tiempo que tarda la respuesta.
 */
function equipoPorCodigo(array $equipos, string $codigo): string {
    $codigo = normalizaCodigo($codigo);
    $hallado = '';
    $cuantos = 0;
    foreach ($equipos as $e) {
        if (!is_array($e) || !isset($e['id'])) continue;
        $id = (string)$e['id'];
        if ($id === '') continue;
        if (hash_equals(codigoEquipo($id), $codigo)) {
            $hallado = $id;
            $cuantos++;
        }
    }
    /* Si dos equipos compartieran código (prácticamente imposible) no se
       abre ninguno: más vale negar el acceso que enseñar el equipo
       equivocado. */
    return $cuantos === 1 ? $hallado : '';
}

/* =====================================================================
   PUENTE A «DISPONIBILIDAD DEL EQUIPO»  (/disponibilidad/)

   El portal de capitanes y el módulo de disponibilidad usan cookies
   distintas a propósito. Para pasar de uno a otro SIN pedir otra vez el
   código, el portal entrega un PASE firmado con HMAC que:
     · lleva únicamente el id del equipo de la sesión (el del estado.json);
     · vence en PUENTE_VIGENCIA segundos;
     · trae un número único (nonce) para que disponibilidad lo acepte
       una sola vez.
   La clave del pase se DERIVA de SEMILLA_CAPITANES con otra etiqueta:
   un pase no sirve como código de capitán ni al revés.
   Nada de esto lee ni escribe información financiera.
   ===================================================================== */

const PUENTE_VIGENCIA = 120;   /* segundos */

function b64urlCod(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64urlDec(string $s) {
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}
function clavePuente(): string {
    if (!defined('SEMILLA_CAPITANES') || strlen((string)SEMILLA_CAPITANES) < 16) return '';
    return hash_hmac('sha256', 'puente-disponibilidad:v1', SEMILLA_CAPITANES, true);
}

/** Pase de un solo uso para el equipo de la sesión. '' si la semilla no está configurada. */
function paseDisponibilidad(string $equipoId): string {
    $k = clavePuente();
    if ($k === '' || $equipoId === '') return '';
    $carga = json_encode([
        'v' => 1,
        'e' => $equipoId,
        'x' => time() + PUENTE_VIGENCIA,
        'n' => bin2hex(random_bytes(16)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $c = b64urlCod($carga);
    return $c . '.' . b64urlCod(hash_hmac('sha256', $c, $k, true));
}

/**
 * Verifica firma y vigencia de un pase. Devuelve ['equipoId','nonce','vence'] o null.
 * (Que no se haya usado antes lo comprueba quien lo recibe.)
 */
function verificaPaseDisponibilidad(string $pase): ?array {
    $k = clavePuente();
    if ($k === '' || strlen($pase) > 600 || substr_count($pase, '.') !== 1) return null;
    [$c, $f] = explode('.', $pase, 2);
    $firma = b64urlDec($f);
    if ($firma === false || !hash_equals(hash_hmac('sha256', $c, $k, true), $firma)) return null;
    $j = json_decode((string)b64urlDec($c), true);
    if (!is_array($j) || ($j['v'] ?? 0) !== 1) return null;
    $e = is_string($j['e'] ?? null) ? $j['e'] : '';
    $x = is_int($j['x'] ?? null) ? $j['x'] : 0;
    $n = is_string($j['n'] ?? null) ? $j['n'] : '';
    if ($e === '' || !preg_match('/^[0-9a-f]{32}$/', $n)) return null;
    if ($x < time() || $x > time() + PUENTE_VIGENCIA + 5) return null;
    return ['equipoId' => $e, 'nonce' => $n, 'vence' => $x];
}
