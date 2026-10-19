<?php
/**
 * Liga Chapingo — Finanzas · FUENTE DE DATOS.
 *
 * Los cobros y gastos viven en arbitrajes/datos/estado.json (Tesorería / Estado de cuenta).
 * Este archivo SÓLO LEE ese archivo, con la función que ya usa el portal de capitanes
 * (leerEstadoArbitrajes). Nunca escribe en él: Tesorería guarda el archivo completo con
 * control de revisión y cualquier escritura desde aquí podría pisar una captura.
 *
 * De MySQL lee: fin_parametros, fin_compromisos y tickets_futuros (si existen).
 */
declare(strict_types=1);

require_once __DIR__ . '/vinculos.php';          // cargar_portal_capitanes()

/**
 * estado.json completo, o null si no se pudo leer.
 * @return array{rev:int, actualizado:string, estado:array}|null
 */
function fin_leer_estado(): ?array
{
    static $memo = false;
    if ($memo !== false) return $memo;
    // Carga /capitanes/config.php y codigos-lib.php. Finanzas sólo necesita leerEstadoArbitrajes()
    // (no el pase de disponibilidad), así que funciona aunque el portal no tenga ese botón.
    cargar_portal_capitanes();
    if (!function_exists('leerEstadoArbitrajes') || !defined('DATOS_ARBITRAJES')) return $memo = null;
    $d = leerEstadoArbitrajes();
    if (!is_array($d) || !is_array($d['estado'] ?? null)) return $memo = null;
    if (is_file(__DIR__.'/competencia/activa.php') && (require __DIR__.'/competencia/activa.php')) {
        require_once __DIR__.'/competencia/lib.php';
        $d['estado']=cp_incorporar_libro($d['estado']);
    }
    return $memo = $d;
}

/** ¿Existe la tabla? (lista blanca: sólo nombres fijos del módulo). */
function fin_tabla_existe(string $tabla): bool
{
    static $cache = [];
    if (!in_array($tabla, ['fin_parametros', 'fin_compromisos', 'fin_huellas', 'fin_bitacora', 'tickets_futuros'], true)) {
        return false;
    }
    if (array_key_exists($tabla, $cache)) return $cache[$tabla];
    try {
        db()->query('SELECT 1 FROM ' . $tabla . ' LIMIT 1');
        return $cache[$tabla] = true;
    } catch (Throwable $ex) {
        return $cache[$tabla] = false;
    }
}

/**
 * Parámetros vigentes de una edición: [categoria_ref][clave] => valor.
 * categoria_ref '' = aplica a toda la edición. Gana la versión más reciente.
 */
function fin_parametros_vigentes(string $edicionRef): array
{
    if (!fin_tabla_existe('fin_parametros')) return [];
    $st = db()->prepare(
        'SELECT p.categoria_ref, p.clave, p.valor
           FROM fin_parametros p
           JOIN (SELECT categoria_ref, clave, MAX(vigente_desde) AS v
                   FROM fin_parametros
                  WHERE edicion_ref = ? AND vigente_desde <= NOW()
                  GROUP BY categoria_ref, clave) u
             ON u.categoria_ref = p.categoria_ref AND u.clave = p.clave AND u.v = p.vigente_desde
          WHERE p.edicion_ref = ?
          ORDER BY p.id'
    );
    $st->execute([$edicionRef, $edicionRef]);
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $out[(string)$f['categoria_ref']][(string)$f['clave']] = (float)$f['valor'];
    }
    return $out;
}

/**
 * Compromisos vivos (proyectado / comprometido) de una edición.
 * @return array<int, array{tipo:string, categoria_ref:string, monto:float, estado:string}>
 */
function fin_compromisos_vivos(string $edicionRef): array
{
    if (!fin_tabla_existe('fin_compromisos')) return [];
    $st = db()->prepare(
        "SELECT tipo, categoria_ref, monto, estado
           FROM fin_compromisos
          WHERE edicion_ref = ? AND estado IN ('proyectado','comprometido')"
    );
    $st->execute([$edicionRef]);
    return array_map(fn($f) => [
        'tipo'          => (string)$f['tipo'],
        'categoria_ref' => (string)$f['categoria_ref'],
        'monto'         => (float)$f['monto'],
        'estado'        => (string)$f['estado'],
    ], $st->fetchAll());
}

/**
 * Cargos programados (tickets futuros) por equipo del Estado de cuenta.
 * Se muestran como referencia; NO se suman a la proyección (podrían repetir arbitrajes).
 * @return array<string, float>
 */
function fin_tickets_futuros_por_equipo(): array
{
    if (!fin_tabla_existe('tickets_futuros')) return [];
    $out = [];
    foreach (db()->query('SELECT equipo_estado_id, SUM(importe) AS t FROM tickets_futuros GROUP BY equipo_estado_id') as $f) {
        $out[(string)$f['equipo_estado_id']] = (float)$f['t'];
    }
    return $out;
}
