<?php
/**
 * Liga Chapingo — TICKETS AUTOMÁTICOS en Tesorería al confirmar un partido (29-sep-2026).
 *
 * Cada partido CONFIRMADO de «Busca un rival» (reservas_cancha.estado = 'confirmada') genera en
 * Tesorería (arbitrajes/datos/estado.json) dos tickets de ARBITRAJE con forma «Pago futuro»,
 * uno por equipo y cada uno con el otro como rival. Se cobran después con «Abonar».
 *
 * Es una CONCILIACIÓN idempotente: se puede correr las veces que sea y deja todo igual.
 *   · Partido confirmado sin tickets        → los crea.
 *   · Partido reprogramado                   → cambia la fecha de sus tickets (si no tienen pagos).
 *   · Partido cancelado / pospuesto / borrado → quita sus tickets si no tienen pagos;
 *                                              si ya tenían pagos, los deja y anota la cancelación.
 * Sólo toca tickets que ella misma creó (campo «auto.reserva»). Nunca modifica los capturados a mano.
 *
 * Se escribe con el mismo candado (flock) y control de revisión que usa arbitrajes/api.php:
 * la app de Tesorería ve los tickets nuevos en su siguiente sincronización (≤ 20 s).
 */
declare(strict_types=1);

require_once __DIR__ . '/vinculos.php';

/** Sólo partidos desde esta fecha (evita crear tickets de partidos anteriores a la función). */
const TESO_AUTO_DESDE   = '2026-09-29';
const TESO_AUTO_CAJERO  = 'Premier league Administracion';
const TESO_AUTO_CONCEPTO_ID = 'c3';                 // Arbitraje en el Catálogo de Tesorería
const TESO_AUTO_IMPORTE_DEF = 150.0;                // si el Catálogo no trae tarifa

function teso_sync_al_terminar(): void
{
    static $reg = false;
    if ($reg) return;
    $reg = true;
    register_shutdown_function(static function () {
        try { teso_sincronizar_partidos(); }
        catch (Throwable $ex) { error_log('[tesoreria-auto] ' . $ex->getMessage()); }
    });
}

/**
 * Visibilidad para el capitán de un ticket automático (30-sep-2026):
 *   · nace OCULTO (ocultoCapitan) con la marca auto.ocultoHastaCobro;
 *   · en cuanto tiene un pago registrado se hace visible y la marca se apaga (false);
 *   · tickets automáticos anteriores a esta regla (sin la marca) y sin pagos se ocultan una vez.
 * Si en Tesorería alguien lo muestra u oculta a mano, la marca queda en false y ya no se toca.
 * @return bool true si cambió algo.
 */
function teso_visibilidad_por_cobro(array &$m, bool $pagado): bool
{
    if (!is_array($m['auto'] ?? null)) return false;
    $marca = $m['auto']['ocultoHastaCobro'] ?? null;
    if ($marca === null) {                                  // ticket viejo: se aplica la regla nueva
        if ($pagado) { $m['auto']['ocultoHastaCobro'] = false; return true; }
        $m['ocultoCapitan'] = true; $m['auto']['ocultoHastaCobro'] = true;
        return true;
    }
    if ($marca === true && $pagado) {                       // ya se cobró: visible
        unset($m['ocultoCapitan']);
        $m['auto']['ocultoHastaCobro'] = false;
        return true;
    }
    return false;
}

/** Id de Tesorería del equipo MySQL dentro del grupo «ed|di|ra|ca[|G]». '' si no hay vínculo. */
function teso_estado_id(array $vinc, int $equipoId, string $grupo, array $eqEstado): string
{
    $p = explode('|', $grupo);
    foreach ($vinc[$equipoId] ?? [] as $eid) {
        $e = $eqEstado[$eid] ?? null;
        if (!$e) continue;
        if ((string)($e['edicionId'] ?? '') === ($p[0] ?? '') && (string)($e['disciplinaId'] ?? '') === ($p[1] ?? '')
            && (string)($e['ramaId'] ?? '') === ($p[2] ?? '') && (string)($e['categoriaId'] ?? '') === ($p[3] ?? '')) return $eid;
    }
    return '';
}

/**
 * @return array{creados:int, actualizados:int, quitados:int, sin_vinculo:int, error?:string}
 */
function teso_sincronizar_partidos(): array
{
    $r = ['creados' => 0, 'actualizados' => 0, 'quitados' => 0, 'sin_vinculo' => 0];
    if (!cargar_portal_capitanes() || !defined('DATOS_ARBITRAJES') || !vinculos_instalados()) return $r + ['error' => 'sin configuración'];

    $pdo = db();
    try {
        $st = $pdo->prepare("SELECT id, fecha, hora, estado, grupo_ref, equipo_a_id, equipo_b_id, equipo_a_nombre, equipo_b_nombre
                               FROM reservas_cancha WHERE fecha >= ?");
        $st->execute([TESO_AUTO_DESDE]);
        $reservas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        return $r + ['error' => 'reservas_cancha no disponible'];
    }
    $vinc = [];
    foreach ($pdo->query('SELECT equipo_id, estado_equipo_id FROM equipos_estado_cuenta')->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $vinc[(int)$v['equipo_id']][] = (string)$v['estado_equipo_id'];
    }

    $f = rtrim(DATOS_ARBITRAJES, '/\\') . '/estado.json';
    if (!is_file($f)) return $r + ['error' => 'no existe estado.json'];
    $fp = @fopen($f, 'c+');
    if (!$fp) return $r + ['error' => 'no se puede abrir estado.json'];
    if (!flock($fp, LOCK_EX)) { fclose($fp); return $r + ['error' => 'estado.json ocupado']; }
    try {
        $raw = stream_get_contents($fp);
        $J = $raw === '' ? null : json_decode((string)$raw, true);
        if (!is_array($J) || !is_array($J['estado'] ?? null)) return $r + ['error' => 'estado.json inválido'];
        $S = &$J['estado'];
        if (!is_array($S['movs'] ?? null)) $S['movs'] = [];

        $eqEstado = [];
        foreach ((array)($S['equipos'] ?? []) as $e) if (is_array($e) && isset($e['id'])) $eqEstado[(string)$e['id']] = $e;
        $importe = TESO_AUTO_IMPORTE_DEF;
        foreach ((array)($S['conceptos'] ?? []) as $c) {
            if (is_array($c) && ($c['id'] ?? '') === TESO_AUTO_CONCEPTO_ID && is_numeric($c['monto'] ?? null)) $importe = (float)$c['monto'];
        }

        // Tickets automáticos que ya existen: "reserva|equipoEstado" => índice en movs
        $auto = [];
        foreach ($S['movs'] as $i => $m) {
            if (is_array($m) && is_array($m['auto'] ?? null) && isset($m['auto']['reserva'])) {
                $auto[(int)$m['auto']['reserva'] . '|' . (string)$m['equipoId']] = $i;
            }
        }
        $pagado = static fn(array $m): bool => ((float)($m['recibido'] ?? 0)) > 0.009 || !empty($m['abonos']);
        $folio = max(1, (int)($S['liga']['folio'] ?? 1));
        foreach ($S['movs'] as $m) if (is_array($m) && (int)($m['folio'] ?? 0) >= $folio) $folio = (int)$m['folio'] + 1;

        $vivos = [];   // claves que deben existir
        foreach ($reservas as $res) {
            if ($res['estado'] !== 'confirmada') continue;
            $idA = teso_estado_id($vinc, (int)$res['equipo_a_id'], (string)$res['grupo_ref'], $eqEstado);
            $idB = teso_estado_id($vinc, (int)$res['equipo_b_id'], (string)$res['grupo_ref'], $eqEstado);
            if ($idA === '' || $idB === '') { $r['sin_vinculo']++; continue; }
            $hora = substr((string)$res['hora'], 0, 5);
            $nota = 'Partido ' . date('d/m/Y', strtotime((string)$res['fecha'])) . ' ' . $hora . ' · Busca un rival';
            foreach ([[$idA, $idB], [$idB, $idA]] as [$yo, $otro]) {
                $k = (int)$res['id'] . '|' . $yo;
                $vivos[$k] = true;
                if (isset($auto[$k])) {
                    $m = &$S['movs'][$auto[$k]];
                    if (!$pagado($m) && (($m['fecha'] ?? '') !== $res['fecha'] || ($m['nota'] ?? '') !== $nota)) {
                        $m['fecha'] = (string)$res['fecha']; $m['nota'] = $nota; $r['actualizados']++;
                    }
                    if (teso_visibilidad_por_cobro($m, $pagado($m))) $r['actualizados']++;
                    unset($m);
                    continue;
                }
                $S['movs'][] = [
                    'id' => 'mauto' . base_convert((string)time(), 10, 36) . bin2hex(random_bytes(3)),
                    'equipoId' => $yo, 'conceptoId' => TESO_AUTO_CONCEPTO_ID, 'concepto' => 'Arbitraje',
                    'importe' => $importe, 'recibido' => 0, 'forma' => 'Pago futuro',
                    'fecha' => (string)$res['fecha'], 'hora' => '', 'nota' => $nota,
                    'cajero' => TESO_AUTO_CAJERO, 'entrego' => '', 'jornada' => 0, 'rivalId' => $otro,
                    'folio' => $folio++,
                    // Oculto al capitán hasta que se cobre (30-sep-2026).
                    'ocultoCapitan' => true,
                    'auto' => ['reserva' => (int)$res['id'], 'origen' => 'busca-un-rival', 'ocultoHastaCobro' => true],
                ];
                $r['creados']++;
            }
        }
        // Tickets automáticos cuyo partido ya no está confirmado
        $quitar = [];
        foreach ($auto as $k => $i) {
            if (isset($vivos[$k])) continue;
            $m = &$S['movs'][$i];
            if (teso_visibilidad_por_cobro($m, $pagado($m))) $r['actualizados']++;
            if ($pagado($m)) {
                if (strpos((string)($m['nota'] ?? ''), 'CANCELADO') === false) { $m['nota'] = 'PARTIDO CANCELADO · ' . ($m['nota'] ?? ''); $r['actualizados']++; }
            } else { $quitar[$i] = true; $r['quitados']++; }
            unset($m);
        }
        if ($quitar) $S['movs'] = array_values(array_filter($S['movs'], static fn($m, $i) => !isset($quitar[$i]), ARRAY_FILTER_USE_BOTH));

        if ($r['creados'] + $r['actualizados'] + $r['quitados'] === 0) return $r;
        $S['liga']['folio'] = max((int)($S['liga']['folio'] ?? 1), $folio);
        unset($S);
        // respaldo diario (mismo criterio que arbitrajes/api.php)
        $dirR = rtrim(DATOS_ARBITRAJES, '/\\') . '/respaldos';
        if (!is_dir($dirR)) @mkdir($dirR, 0755, true);
        $dest = $dirR . '/estado-' . date('Y-m-d') . '.json';
        if (!file_exists($dest) && $raw !== '') @file_put_contents($dest, $raw);

        $J['rev'] = (int)($J['rev'] ?? 0) + 1;
        $J['actualizado'] = date('c');
        $nuevo = json_encode($J, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($nuevo === false) return $r + ['error' => 'no se pudo codificar'];
        ftruncate($fp, 0); rewind($fp); fwrite($fp, $nuevo); fflush($fp);
        return $r;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
