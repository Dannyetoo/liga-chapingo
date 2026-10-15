<?php
/**
 * Liga Chapingo — Finanzas · CÁLCULOS (funciones puras: reciben arreglos, devuelven arreglos).
 *
 * Reglas (ver diagnóstico del módulo financiero):
 *  - REAL sólo cuenta dinero que ya entró (recibido) o salió (gastos). Nunca se mezcla
 *    con PROYECTADO.
 *  - Pendiente de un cobro = max(0, importe − recibido).
 *  - Las transferencias internas entre cajeros no son gasto (neto 0). Los gastos antiguos
 *    llamados «Transferencia» hacia una cuenta interna se separan y se marcan para revisar.
 *  - Multas individuales: sólo cuentan cuando se cobran (entran como movimiento). Su
 *    pendiente se muestra aparte y no se suma, porque nace de adeudos de equipo que ya
 *    están como pendientes.
 *  - Gastos sin categoría de liga → «General de la liga».
 *  - Tarifas y costos salen del Catálogo de Tesorería o de fin_parametros; nunca del código.
 */
declare(strict_types=1);

const FIN_GENERAL = '';   // clave de la línea «General de la liga»

function fin_num($x): float { return is_numeric($x) ? (float)$x : 0.0; }
function fin_txt($x): string { return is_scalar($x) ? trim((string)$x) : ''; }
function fin_lista($x): array { return is_array($x) ? array_values(array_filter($x, 'is_array')) : []; }

function fin_por_id(array $lista): array
{
    $o = [];
    foreach (fin_lista($lista) as $x) {
        $id = fin_txt($x['id'] ?? '');
        if ($id !== '') $o[$id] = $x;
    }
    return $o;
}

function fin_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/** Tipo de ingreso de un movimiento: inscripcion | arbitraje | multa | otro. */
function fin_tipo_ingreso(array $m): string
{
    $c = fin_norm(fin_txt($m['concepto'] ?? ''));
    if (strpos($c, 'inscrip') !== false) return 'inscripcion';
    if (fin_txt($m['conceptoId'] ?? '') === 'c3' || strpos($c, 'arbitraj') !== false) return 'arbitraje';
    if (strpos($c, 'multa') !== false || strpos($c, 'adeudo') !== false) return 'multa';
    return 'otro';
}

/**
 * Tipo de gasto: arbitro | supervision | coordinacion | otros_partido | general | transferencia.
 * @param array<string,bool> $cuentas nombres normalizados de las cuentas internas
 */
function fin_tipo_gasto(array $g, array $cuentas): string
{
    $c   = fin_norm(fin_txt($g['concepto'] ?? ''));
    $cat = fin_norm(fin_txt($g['categoria'] ?? ''));
    $ben = fin_norm(fin_txt($g['beneficiario'] ?? ''));
    if (strpos($c, 'transfer') !== false && $ben !== '' && isset($cuentas[$ben])) return 'transferencia';

    $dePartido = fin_txt($g['categoriaGasto'] ?? '') === 'Partido' || $cat === 'arbitraje';
    if (!$dePartido) return 'general';
    if (strpos($c, 'supervis') !== false)  return 'supervision';
    if (strpos($c, 'coordina') !== false)  return 'coordinacion';
    if ($cat === 'arbitraje' || strpos($c, 'arbitr') !== false || strpos($c, 'partido') !== false) return 'arbitro';
    return 'otros_partido';
}

/** Monto de un concepto del catálogo cuyo nombre contiene $texto (o null). */
function fin_tarifa(array $catalogo, string $texto): ?float
{
    $t = fin_norm($texto);
    foreach (fin_lista($catalogo) as $c) {
        if (strpos(fin_norm(fin_txt($c['nombre'] ?? '')), $t) !== false && is_numeric($c['monto'] ?? null)) {
            return (float)$c['monto'];
        }
    }
    return null;
}

function fin_clave_categoria(string $di, string $ra, string $ca): string
{
    return $di . '|' . $ra . '|' . $ca;
}

function fin_clave_partido(string $ed, string $di, string $ra, string $ca, int $jor, string $a, string $b): string
{
    $p = [$a, $b];
    sort($p, SORT_STRING);
    return implode('|', [$ed, $di, $ra, $ca, $jor, implode('~', $p)]);
}

/** Ediciones con datos, la más reciente con movimientos primero. */
function fin_ediciones(array $S): array
{
    $eq  = fin_por_id($S['equipos'] ?? []);
    $uso = [];
    foreach (fin_lista($S['movs'] ?? []) as $m) {
        $e = $eq[fin_txt($m['equipoId'] ?? '')] ?? null;
        if (!$e) continue;
        $ed = fin_txt($e['edicionId'] ?? '');
        $uso[$ed] = max($uso[$ed] ?? '', fin_txt($m['fecha'] ?? ''));
    }
    foreach (fin_lista($S['gastos'] ?? []) as $g) {
        $ed = fin_txt($g['edicionId'] ?? '');
        if ($ed !== '') $uso[$ed] = max($uso[$ed] ?? '', fin_txt($g['fecha'] ?? ''));
    }
    $out = [];
    foreach (fin_lista($S['ediciones'] ?? []) as $e) {
        $id = fin_txt($e['id'] ?? '');
        if ($id === '') continue;
        $out[] = ['id' => $id, 'nombre' => fin_txt($e['nombre'] ?? $id), 'ultimo' => $uso[$id] ?? '', 'con_datos' => isset($uso[$id])];
    }
    usort($out, fn($a, $b) => [$b['con_datos'], $b['ultimo']] <=> [$a['con_datos'], $a['ultimo']]);
    return $out;
}

/**
 * Partidos financieros de una edición (misma regla que Tesorería: gastos de categoría Partido
 * + cobros con jornada; un cobro sin rival se asigna sólo si hay UN partido posible).
 * @return array<string, array> clave => partido
 */
function fin_partidos(array $S, string $edicion): array
{
    $eq = fin_por_id($S['equipos'] ?? []);
    $P  = [];
    $reg = function (string $k, array $base) use (&$P) {
        if (!isset($P[$k])) $P[$k] = $base + ['ingreso' => 0.0, 'pendiente' => 0.0, 'gasto' => 0.0];
        return $k;
    };
    foreach (fin_lista($S['gastos'] ?? []) as $g) {
        if (fin_txt($g['edicionId'] ?? '') !== $edicion || fin_txt($g['categoriaGasto'] ?? '') !== 'Partido') continue;
        $jor = (int)fin_num($g['jornada'] ?? 0);
        $a = fin_txt($g['equipoAId'] ?? ''); $b = fin_txt($g['equipoBId'] ?? '');
        if (!$jor && $a === '' && $b === '') continue;
        $di = fin_txt($g['disciplinaId'] ?? ''); $ra = fin_txt($g['ramaId'] ?? ''); $ca = fin_txt($g['categoriaId'] ?? '');
        $k = $reg(fin_clave_partido($edicion, $di, $ra, $ca, $jor, $a, $b),
            ['jornada' => $jor, 'a' => $a, 'b' => $b, 'cat' => fin_clave_categoria($di, $ra, $ca)]);
        $P[$k]['gasto'] += fin_num($g['monto'] ?? 0);
    }
    $sueltos = [];
    foreach (fin_lista($S['movs'] ?? []) as $m) {
        $jor = (int)fin_num($m['jornada'] ?? 0);
        if (!$jor) continue;
        $e = $eq[fin_txt($m['equipoId'] ?? '')] ?? null;
        if (!$e || fin_txt($e['edicionId'] ?? '') !== $edicion) continue;
        $di = fin_txt($e['disciplinaId'] ?? ''); $ra = fin_txt($e['ramaId'] ?? ''); $ca = fin_txt($e['categoriaId'] ?? '');
        $riv = fin_txt($m['rivalId'] ?? '');
        if ($riv === '') { $sueltos[] = [$m, $e, $jor]; continue; }
        $k = $reg(fin_clave_partido($edicion, $di, $ra, $ca, $jor, (string)$e['id'], $riv),
            ['jornada' => $jor, 'a' => (string)$e['id'], 'b' => $riv, 'cat' => fin_clave_categoria($di, $ra, $ca)]);
        $P[$k]['ingreso']   += fin_num($m['recibido'] ?? 0);
        $P[$k]['pendiente'] += max(0.0, fin_num($m['importe'] ?? 0) - fin_num($m['recibido'] ?? 0));
    }
    foreach ($sueltos as [$m, $e, $jor]) {
        $cat = fin_clave_categoria(fin_txt($e['disciplinaId'] ?? ''), fin_txt($e['ramaId'] ?? ''), fin_txt($e['categoriaId'] ?? ''));
        $cand = array_keys(array_filter($P, fn($p) => $p['jornada'] === $jor && $p['cat'] === $cat
            && ($p['a'] === (string)$e['id'] || $p['b'] === (string)$e['id'])));
        $k = count($cand) === 1 ? $cand[0]
            : $reg(fin_clave_partido($edicion, ...array_merge(explode('|', $cat), [$jor, (string)$e['id'], ''])),
                ['jornada' => $jor, 'a' => (string)$e['id'], 'b' => '', 'cat' => $cat]);
        $P[$k]['ingreso']   += fin_num($m['recibido'] ?? 0);
        $P[$k]['pendiente'] += max(0.0, fin_num($m['importe'] ?? 0) - fin_num($m['recibido'] ?? 0));
    }
    return $P;
}

/** Categoría vacía con todos los acumuladores en cero. */
function fin_categoria_vacia(string $clave, string $nombre, string $ruta = ''): array
{
    $t = ['cobrado' => 0.0, 'facturado' => 0.0, 'pendiente' => 0.0];
    return [
        'clave' => $clave, 'nombre' => $nombre, 'ruta' => $ruta, 'es_general' => $clave === FIN_GENERAL,
        'equipos' => 0, 'equipos_con_inscripcion' => 0,
        'ing' => ['inscripcion' => $t, 'arbitraje' => $t, 'multa' => $t, 'otro' => $t],
        'gas' => ['arbitro' => 0.0, 'supervision' => 0.0, 'administracion' => 0.0, 'coordinacion' => 0.0, 'otros_partido' => 0.0, 'general' => 0.0],
        'partidos_registrados' => 0,
        'proy' => [],
    ];
}

/**
 * Resumen financiero completo de una edición.
 *
 * @param array $S          estado de Tesorería (estado.json → estado)
 * @param string $edicion   id de la edición en estado.json
 * @param array $param      fin_parametros_vigentes(): [categoria_ref][clave] => valor
 * @param array $comp       fin_compromisos_vivos()
 * @param array $tickets    fin_tickets_futuros_por_equipo()
 * @param array $costosOp   fp_costos_para_resumen(): costos por partido capturados en el panel (MySQL)
 */
function fin_resumen(array $S, string $edicion, array $param = [], array $comp = [], array $tickets = [], array $costosOp = []): array
{
    $eq    = fin_por_id($S['equipos'] ?? []);
    $cats  = fin_por_id($S['categorias'] ?? []);
    $ramas = fin_por_id($S['ramas'] ?? []);
    $disc  = fin_por_id($S['disciplinas'] ?? []);
    $cuentas = [];
    foreach (fin_lista($S['cuentas'] ?? []) as $c) {
        $n = fin_norm(fin_txt($c['nombre'] ?? ''));
        if ($n !== '') $cuentas[$n] = true;
    }
    $nom = fn(array $l, string $id) => fin_txt(($l[$id] ?? [])['nombre'] ?? '');

    $C = [];
    $cat = function (string $di, string $ra, string $ca) use (&$C, $cats, $ramas, $disc, $nom): string {
        $k = fin_clave_categoria($di, $ra, $ca);
        if (!isset($C[$k])) {
            $C[$k] = fin_categoria_vacia($k, $nom($cats, $ca) ?: 'Sin categoría',
                trim($nom($disc, $di) . ' ' . $nom($ramas, $ra)));
        }
        return $k;
    };

    $revisar = ['cobros_sin_equipo' => 0, 'cobros_jornada_sin_rival' => 0, 'cobros_sin_jornada_arbitraje' => 0,
                'gastos_sin_categoria' => 0, 'gastos_sin_categoria_monto' => 0.0,
                'transferencias' => [], 'multas_pendientes' => 0.0, 'multas_n' => 0,
                'tickets_futuros' => 0.0];

    // Equipos de la edición
    foreach ($eq as $id => $e) {
        if (fin_txt($e['edicionId'] ?? '') !== $edicion) continue;
        $k = $cat(fin_txt($e['disciplinaId'] ?? ''), fin_txt($e['ramaId'] ?? ''), fin_txt($e['categoriaId'] ?? ''));
        $C[$k]['equipos']++;
        if (isset($tickets[$id])) $revisar['tickets_futuros'] += $tickets[$id];
    }

    // Ingresos
    $conInsc = [];
    foreach (fin_lista($S['movs'] ?? []) as $m) {
        $e = $eq[fin_txt($m['equipoId'] ?? '')] ?? null;
        if (!$e) { $revisar['cobros_sin_equipo']++; continue; }
        if (fin_txt($e['edicionId'] ?? '') !== $edicion) continue;
        $k = $cat(fin_txt($e['disciplinaId'] ?? ''), fin_txt($e['ramaId'] ?? ''), fin_txt($e['categoriaId'] ?? ''));
        $t = fin_tipo_ingreso($m);
        $imp = fin_num($m['importe'] ?? 0); $rec = fin_num($m['recibido'] ?? 0);
        $C[$k]['ing'][$t]['cobrado']   += $rec;
        $C[$k]['ing'][$t]['facturado'] += $imp;
        $C[$k]['ing'][$t]['pendiente'] += max(0.0, $imp - $rec);
        if ($t === 'inscripcion') $conInsc[(string)$e['id']] = $k;
        if ($t === 'arbitraje') {
            $jor = (int)fin_num($m['jornada'] ?? 0);
            if (!$jor) $revisar['cobros_sin_jornada_arbitraje']++;
            elseif (fin_txt($m['rivalId'] ?? '') === '') $revisar['cobros_jornada_sin_rival']++;
        }
    }
    foreach ($conInsc as $k) $C[$k]['equipos_con_inscripcion']++;

    // Gastos
    $C[FIN_GENERAL] = $C[FIN_GENERAL] ?? fin_categoria_vacia(FIN_GENERAL, 'General de la liga');
    foreach (fin_lista($S['gastos'] ?? []) as $g) {
        if (fin_txt($g['edicionId'] ?? '') !== $edicion) continue;
        $tipo = fin_tipo_gasto($g, $cuentas);
        $monto = fin_num($g['monto'] ?? 0);
        if ($tipo === 'transferencia') {
            $revisar['transferencias'][] = [
                'fecha' => fin_txt($g['fecha'] ?? ''), 'monto' => $monto,
                'de' => fin_txt($g['pagador'] ?? ''), 'para' => fin_txt($g['beneficiario'] ?? ''),
                'folio' => fin_txt($g['folio'] ?? ''),
            ];
            continue;
        }
        $ca = fin_txt($g['categoriaId'] ?? '');
        if ($ca === '') {
            $k = FIN_GENERAL;
            $revisar['gastos_sin_categoria']++;
            $revisar['gastos_sin_categoria_monto'] += $monto;
        } else {
            $k = $cat(fin_txt($g['disciplinaId'] ?? ''), fin_txt($g['ramaId'] ?? ''), $ca);
        }
        $C[$k]['gas'][$tipo] += $monto;
    }

    // Costos operativos por partido capturados en el panel (MySQL). Sólo los PAGADOS son dinero real.
    foreach (($costosOp['pagado'] ?? []) as $k => $porConcepto) {
        if (!isset($C[$k])) {
            [$di, $ra, $ca] = array_pad(explode('|', (string)$k), 3, '');
            $k = $cat($di, $ra, $ca);
        }
        foreach ($porConcepto as $concepto => $monto) {
            $C[$k]['gas'][$concepto === 'otro' ? 'otros_partido' : $concepto] += (float)$monto;
        }
    }

    // Multas individuales (informativo)
    foreach (fin_lista($S['multas'] ?? []) as $mu) {
        $e = $eq[fin_txt($mu['equipoId'] ?? '')] ?? null;
        if ($e && fin_txt($e['edicionId'] ?? '') !== $edicion) continue;
        $p = max(0.0, fin_num($mu['monto'] ?? 0) - fin_num($mu['pagado'] ?? 0));
        if ($p > 0) { $revisar['multas_pendientes'] += $p; $revisar['multas_n']++; }
    }

    // Partidos registrados por categoría
    foreach (fin_partidos($S, $edicion) as $p) {
        if (isset($C[$p['cat']]) && $p['b'] !== '') $C[$p['cat']]['partidos_registrados']++;
    }

    // Proyección por categoría
    $conceptos = $S['conceptos'] ?? []; $cgasto = $S['conceptosGasto'] ?? [];
    $tarLiga = fin_tarifa($conceptos, 'inscripcion a liga');
    $tarRel  = fin_tarifa($conceptos, 'inscripcion a relampago');
    $tarArb  = fin_tarifa($conceptos, 'arbitraje');
    $cArb    = fin_tarifa($cgasto, 'arbitraje');
    $cSup    = fin_tarifa($cgasto, 'supervision');
    $cCoo    = fin_tarifa($cgasto, 'coordinacion');
    $faltan  = [];

    foreach ($C as $k => &$c) {
        $ing = $c['ing'];
        $c['ingresos']       = array_sum(array_column($ing, 'cobrado'));
        $c['gastos_partido'] = $c['gas']['arbitro'] + $c['gas']['supervision'] + $c['gas']['administracion'] + $c['gas']['coordinacion'] + $c['gas']['otros_partido'];
        $c['gastos']         = $c['gastos_partido'] + $c['gas']['general'];
        $c['utilidad']       = $c['ingresos'] - $c['gastos'];
        $c['pendiente']      = $ing['inscripcion']['pendiente'] + $ing['arbitraje']['pendiente'] + $ing['otro']['pendiente'] + $ing['multa']['pendiente'];

        $P = ($param[FIN_GENERAL] ?? []);
        $P = array_merge($P, $param[$k] ?? []);
        // Tarifas por partido del panel (claves cortas) valen como costo regular.
        foreach (['arbitro', 'supervision', 'coordinacion'] as $cc) {
            if (isset($P['costo_' . $cc]) && $cc === 'arbitro' && !isset($P['costo_arbitro_regular'])) $P['costo_arbitro_regular'] = $P['costo_arbitro'];
        }
        // Partidos creados en el panel (tickets con rival) mandan sobre el estimado automático.
        if (!isset($P['partidos_regulares']) && !empty($costosOp['partidos'][$k])) {
            $P['partidos_regulares'] = (float)$costosOp['partidos'][$k];
            $P['partidos_eliminatoria'] = $P['partidos_eliminatoria'] ?? 0.0;
        }
        $pv = function (string $clave, ?float $auto, string $etq) use ($P, &$faltan, $c) {
            if (array_key_exists($clave, $P)) return [(float)$P[$clave], 'parametro'];
            if ($auto === null) { $faltan[$etq] = true; return [0.0, 'falta']; }
            return [$auto, 'auto'];
        };

        $proy = ['fuente' => []];
        if (!$c['es_general']) {
            $n = $c['equipos'];
            $rel = strpos(fin_norm($c['nombre']), 'relampago') !== false;
            [$esperados, $proy['fuente']['equipos']]   = $pv('equipos_esperados', (float)$n, 'equipos esperados');
            [$cuota, $proy['fuente']['cuota']]         = $pv('cuota_inscripcion', $rel ? $tarRel : $tarLiga, 'cuota de inscripción');
            [$pReg, $proy['fuente']['partidos']]       = $pv('partidos_regulares', (float)($rel ? max(0, $n - 1) : intdiv($n * ($n - 1), 2)), 'partidos regulares');
            [$pEli, $proy['fuente']['eliminatoria']]   = $pv('partidos_eliminatoria', 0.0, 'partidos de eliminatoria');
            [$tArb, $proy['fuente']['tarifa']]         = $pv('arbitraje_equipo_regular', $tarArb, 'tarifa de arbitraje');
            [$tEli, ]                                  = $pv('arbitraje_equipo_eliminatoria', $tArb, 'tarifa de arbitraje en eliminatoria');
            [$gArb, ]                                  = $pv('costo_arbitro_regular', $cArb, 'costo de árbitro');
            [$gArbE, ]                                 = $pv('costo_arbitro_eliminatoria', $gArb, 'costo de árbitro en eliminatoria');
            [$gSup, ]                                  = $pv('costo_supervision', $cSup, 'costo de supervisión');
            [$gCoo, ]                                  = $pv('costo_coordinacion', $cCoo, 'costo de coordinación');
            $gAdm = (float)($P['costo_administracion'] ?? 0.0);

            $jug     = $c['partidos_registrados'];
            $restReg = max(0, (int)$pReg - $jug);
            $restEli = max(0, (int)$pEli - max(0, $jug - (int)$pReg));
            $proy += [
                'equipos_esperados'     => $esperados,
                'partidos_total'        => (int)$pReg + (int)$pEli,
                'partidos_restantes'    => $restReg + $restEli,
                'inscripciones_futuras' => max(0.0, $esperados - $c['equipos_con_inscripcion']) * $cuota,
                'arbitrajes_futuros'    => $restReg * 2 * $tArb + $restEli * 2 * $tEli,
                'gasto_operativo_futuro'=> $restReg * ($gArb + $gSup + $gAdm + $gCoo) + $restEli * ($gArbE + $gSup + $gAdm + $gCoo),
            ];
            // Con partidos del panel: el costo futuro es sólo el de los partidos que aún no tienen costos
            // aplicados (los aplicados sin pagar ya están en «compromisos»).
            if (!empty($costosOp['partidos'][$k])) {
                $proy['gasto_operativo_futuro'] = (int)($costosOp['sin_costos'][$k] ?? 0) * ($gArb + $gSup + $gAdm + $gCoo);
                // Los arbitrajes por venir salen de los TICKETS (futuros o por cobrar), no de un estimado.
                $proy['arbitrajes_futuros'] = (float)($costosOp['tickets_por_cobrar'][$k] ?? 0.0);
                $proy['partidos_restantes'] = (int)($costosOp['programados'][$k] ?? 0);
                $proy['partidos_total']     = (int)$costosOp['partidos'][$k];
                $proy['fuente']['partidos'] = 'tickets';
            }
        } else {
            $proy += ['equipos_esperados' => 0, 'partidos_total' => 0, 'partidos_restantes' => 0,
                      'inscripciones_futuras' => 0.0, 'arbitrajes_futuros' => 0.0, 'gasto_operativo_futuro' => 0.0];
        }
        $proy['compromisos_ingreso'] = 0.0;
        $proy['compromisos_gasto'] = (float)($costosOp['por_pagar'][$k] ?? 0.0);   // costos de partido aún sin pagar
        foreach ($comp as $x) {
            if ($x['categoria_ref'] !== $k) continue;
            $proy[$x['tipo'] === 'ingreso' ? 'compromisos_ingreso' : 'compromisos_gasto'] += $x['monto'];
        }
        $proy['inscripciones_total'] = $ing['inscripcion']['cobrado'] + $ing['inscripcion']['pendiente'] + $proy['inscripciones_futuras'];
        $proy['arbitrajes_total']    = $ing['arbitraje']['cobrado'] + $ing['arbitraje']['pendiente'] + $proy['arbitrajes_futuros'];
        // Resultado proyectado = real + pendientes + futuros − compromisos − gasto operativo futuro
        $proy['resultado'] = $c['utilidad'] + $c['pendiente'] + $proy['inscripciones_futuras'] + $proy['arbitrajes_futuros']
            + $proy['compromisos_ingreso'] - $proy['compromisos_gasto'] - $proy['gasto_operativo_futuro'];
        $c['proy'] = $proy;
    }
    unset($c);

    // Orden: categorías con equipos por nombre; General al final
    $lista = array_values(array_filter($C, fn($c) => !$c['es_general'] && ($c['equipos'] > 0 || $c['ingresos'] > 0 || $c['gastos'] > 0)));
    usort($lista, fn($a, $b) => [$a['ruta'], $a['nombre']] <=> [$b['ruta'], $b['nombre']]);
    $gen = $C[FIN_GENERAL];
    if ($gen['gastos'] > 0 || $gen['ingresos'] > 0 || $gen['proy']['compromisos_gasto'] > 0 || $gen['proy']['compromisos_ingreso'] > 0) $lista[] = $gen;

    // Total consolidado
    $T = fin_categoria_vacia('__total', 'Total Premier League Chapingo');
    $sumar = ['ingresos', 'gastos_partido', 'gastos', 'utilidad', 'pendiente'];
    foreach ($lista as $c) {
        $T['equipos'] += $c['equipos'];
        foreach ($c['ing'] as $t => $v) foreach ($v as $kk => $x) $T['ing'][$t][$kk] += $x;
        foreach ($c['gas'] as $t => $x) $T['gas'][$t] += $x;
        foreach ($sumar as $s) $T[$s] = ($T[$s] ?? 0) + $c[$s];
        foreach ($c['proy'] as $pk => $pv2) if (is_int($pv2) || is_float($pv2)) $T['proy'][$pk] = ($T['proy'][$pk] ?? 0) + $pv2;
    }
    foreach ($sumar as $s) $T[$s] = $T[$s] ?? 0.0;

    $tarjetas = [
        'recibido'        => $T['ingresos'],
        'inscripciones'   => $T['ing']['inscripcion']['cobrado'],
        'arbitrajes'      => $T['ing']['arbitraje']['cobrado'],
        'otros_ingresos'  => $T['ing']['multa']['cobrado'] + $T['ing']['otro']['cobrado'],
        'gastos_operativos' => $T['gastos_partido'],
        'otros_gastos'    => $T['gas']['general'],
        'saldo_real'      => $T['utilidad'],
        'resultado_proyectado' => $T['proy']['resultado'] ?? $T['utilidad'],
        'pendiente'       => $T['pendiente'],
    ];

    return [
        'edicion'    => $edicion,
        'categorias' => $lista,
        'total'      => $T,
        'tarjetas'   => $tarjetas,
        'revisar'    => $revisar,
        'faltan_parametros' => array_keys($faltan),
    ];
}
