<?php
/* =====================================================================
   Liga Chapingo — funciones compartidas del portal de capitanes

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
