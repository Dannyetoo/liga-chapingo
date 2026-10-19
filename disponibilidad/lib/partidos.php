<?php
/**
 * Liga Chapingo — Finanzas · TICKETS, PARTIDOS y COSTOS OPERATIVOS.
 *
 * Reglas:
 *  - Un ticket es el cargo de UN equipo por UN partido. Todo ticket nuevo nace de un partido
 *    (equipo A vs equipo B), así que siempre tiene rival: se crean de dos en dos.
 *  - Los tickets viejos sin rival se marcan y se les asigna rival desde el panel.
 *  - Categorías de liga (Primera, Segunda…): todos contra todos (método del círculo).
 *    Relámpago: eliminación directa; cada cruce se crea a mano por ronda (n − 1 partidos).
 *  - Estados del ticket: futuro → por_cobrar (el día del encuentro) → cobrado; o cancelado.
 *    Visible/oculto para el capitán es independiente del estado.
 *  - Ingreso del partido = suma de sus tickets (no cancelados).
 *    Costos: árbitro, supervisión, administración, coordinación (tarifas en fin_parametros)
 *    y «otros». Se guardan con el monto aplicado. Nada se borra: se cancela.
 *  - Equipos, categorías y ediciones salen de estado.json (sólo lectura).
 */
declare(strict_types=1);

const FP_CONCEPTOS = [
    'arbitro'        => 'Árbitro',
    'supervision'    => 'Supervisión',
    'administracion' => 'Administración',
    'coordinacion'   => 'Coordinación',
    'otro'           => 'Otros gastos',
];
const FP_CONCEPTOS_FIJOS = ['arbitro', 'supervision', 'administracion', 'coordinacion'];
const FP_ESTADOS_TICKET = [
    'futuro'     => 'Futuro',
    'por_cobrar' => 'Por cobrar (día del encuentro)',
    'cobrado'    => 'Cobrado',
    'cancelado'  => 'Cancelado',
];
const FP_ESTADOS_COSTO = ['por_pagar' => 'Por pagar', 'pagado' => 'Pagado', 'cancelado' => 'Cancelado'];

/* ------------------------------------------------------------------ */
/* Instalación                                                         */
/* ------------------------------------------------------------------ */

function fp_instalado(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM fin_partidos LIMIT 1');
        db()->query('SELECT 1 FROM fin_partido_costos LIMIT 1');
        db()->query('SELECT rival_estado_id, partido_id, estado FROM tickets_futuros LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

/* ------------------------------------------------------------------ */
/* Equipos y categorías (de estado.json)                               */
/* ------------------------------------------------------------------ */

function fp_es_relampago(string $nombreCategoria): bool
{
    return strpos(fin_norm($nombreCategoria), 'relampago') !== false;
}

/**
 * Equipos y categorías de una edición.
 * @return array{equipos: array<string,array>, categorias: array<string,array>}
 */
function fp_catalogo(array $S, string $edicion): array
{
    $cats  = fin_por_id($S['categorias'] ?? []);
    $ramas = fin_por_id($S['ramas'] ?? []);
    $disc  = fin_por_id($S['disciplinas'] ?? []);
    $nom   = fn(array $l, string $id) => fin_txt(($l[$id] ?? [])['nombre'] ?? '');
    $E = []; $C = [];
    foreach (fin_lista($S['equipos'] ?? []) as $e) {
        $id = fin_txt($e['id'] ?? '');
        if ($id === '' || fin_txt($e['edicionId'] ?? '') !== $edicion) continue;
        $di = fin_txt($e['disciplinaId'] ?? ''); $ra = fin_txt($e['ramaId'] ?? ''); $ca = fin_txt($e['categoriaId'] ?? '');
        $k = fin_clave_categoria($di, $ra, $ca);
        if (!isset($C[$k])) {
            $n = $nom($cats, $ca) ?: 'Sin categoría';
            $C[$k] = ['clave' => $k, 'nombre' => $n, 'ruta' => trim($nom($disc, $di) . ' ' . $nom($ramas, $ra)),
                      'relampago' => fp_es_relampago($n), 'equipos' => []];
        }
        $E[$id] = ['id' => $id, 'nombre' => fin_txt($e['nombre'] ?? $id), 'cat' => $k];
        $C[$k]['equipos'][$id] = $E[$id]['nombre'];
    }
    foreach ($C as &$c) asort($c['equipos'], SORT_NATURAL | SORT_FLAG_CASE);
    unset($c);
    uasort($C, fn($a, $b) => [$a['ruta'], $a['nombre']] <=> [$b['ruta'], $b['nombre']]);
    return ['equipos' => $E, 'categorias' => $C];
}

/** Partidos esperados de una categoría: liga n(n−1)/2, relámpago n−1. */
function fp_partidos_esperados(int $n, bool $relampago): int
{
    if ($n < 2) return 0;
    return $relampago ? $n - 1 : intdiv($n * ($n - 1), 2);
}

/** Jornadas esperadas: liga n−1 (par) o n (impar); relámpago = rondas. */
function fp_jornadas_esperadas(int $n, bool $relampago): int
{
    if ($n < 2) return 0;
    if ($relampago) return (int)ceil(log($n, 2));
    return $n % 2 === 0 ? $n - 1 : $n;
}

/**
 * Rol todos contra todos (método del círculo). Con número impar, un equipo descansa cada jornada.
 * @param string[] $ids
 * @return array<int, array{0:int,1:string,2:string}> [jornada, a, b]
 */
function fp_round_robin(array $ids): array
{
    $ids = array_values($ids);
    if (count($ids) < 2) return [];
    if (count($ids) % 2) $ids[] = null;                  // descanso
    $n = count($ids);
    $out = [];
    for ($r = 0; $r < $n - 1; $r++) {
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $ids[$i]; $b = $ids[$n - 1 - $i];
            if ($a === null || $b === null) continue;
            $out[] = $r % 2 ? [$r + 1, $b, $a] : [$r + 1, $a, $b];
        }
        // rota todos menos el primero
        $fijo = array_shift($ids);
        array_unshift($ids, array_pop($ids));
        array_unshift($ids, $fijo);
    }
    return $out;
}

function fp_par_clave(string $a, string $b): string
{
    $p = [$a, $b];
    sort($p, SORT_STRING);
    return implode('~', $p);
}

/* ------------------------------------------------------------------ */
/* Tarifas                                                             */
/* ------------------------------------------------------------------ */

/** Tarifas vigentes por concepto fijo (la edición puede sobreescribir las generales '*'). */
function fp_tarifas(string $edicion): array
{
    $t = array_fill_keys(FP_CONCEPTOS_FIJOS, 0.0);
    foreach (['*', $edicion] as $ed) {
        $p = fin_parametros_vigentes($ed)[''] ?? [];
        foreach (FP_CONCEPTOS_FIJOS as $c) {
            if (isset($p['costo_' . $c])) $t[$c] = (float)$p['costo_' . $c];
        }
    }
    return $t;
}

/**
 * Tarifas para UN partido: las generales y, si es de eliminatoria y la categoría (o la edición)
 * tiene «costo_arbitro_eliminatoria», ese costo para el árbitro.
 */
function fp_tarifas_partido(string $edicion, string $categoria, string $tipo, array $base): array
{
    if ($tipo !== 'eliminatoria') return $base;
    $p = fin_parametros_vigentes($edicion);
    $v = $p[$categoria]['costo_arbitro_eliminatoria'] ?? ($p['']['costo_arbitro_eliminatoria'] ?? null);
    if ($v !== null) $base['arbitro'] = (float)$v;
    return $base;
}

function fp_guardar_tarifas(array $nuevas, string $usuario): int
{
    $act = fp_tarifas('*');
    $ins = db()->prepare("INSERT INTO fin_parametros (edicion_ref, categoria_ref, clave, valor, motivo, creado_por)
                          VALUES ('*', '', ?, ?, 'Cambio de tarifa desde el panel', ?)");
    $n = 0;
    foreach (FP_CONCEPTOS_FIJOS as $c) {
        if (!array_key_exists($c, $nuevas)) continue;
        $v = round((float)$nuevas[$c], 2);
        if (abs($v - $act[$c]) < 0.005) continue;
        $ins->execute(['costo_' . $c, $v, $usuario]);
        $n++;
    }
    if ($n) fin_bitacora_registrar($usuario, 'tarifas', 'fin_parametros', '*', $act, $nuevas);
    return $n;
}

/* ------------------------------------------------------------------ */
/* Partidos + tickets                                                  */
/* ------------------------------------------------------------------ */

/**
 * Crea un partido y sus DOS tickets (uno por equipo, cada uno con el otro como rival).
 * Si el cruce ya existe en esa jornada, no crea nada.
 * @return array{estado:string, id:int}  estado: 'creado' | 'existe'
 */
function fp_crear_partido(array $d, string $usuario): array
{
    $pdo = db();
    $par = fp_par_clave($d['a'], $d['b']);
    $st = $pdo->prepare('INSERT IGNORE INTO fin_partidos
        (edicion_ref, categoria_ref, tipo, jornada, ronda, equipo_a_ref, equipo_a_nombre, equipo_b_ref, equipo_b_nombre, par_clave, fecha, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$d['edicion'], $d['cat'], $d['tipo'], $d['jornada'], $d['ronda'] ?: null,
                  $d['a'], mb_substr($d['a_nombre'], 0, 150), $d['b'], mb_substr($d['b_nombre'], 0, 150),
                  $par, $d['fecha'], $usuario]);
    if ($st->rowCount() === 0) {
        $q = $pdo->prepare('SELECT id FROM fin_partidos WHERE edicion_ref = ? AND categoria_ref = ? AND jornada = ? AND par_clave = ?');
        $q->execute([$d['edicion'], $d['cat'], $d['jornada'], $par]);
        return ['estado' => 'existe', 'id' => (int)$q->fetchColumn()];
    }
    $pid = (int)$pdo->lastInsertId();
    $tk = $pdo->prepare('INSERT INTO tickets_futuros
        (equipo_estado_id, equipo_nombre, rival_estado_id, rival_nombre, partido_id, jornada, fecha_estimada, concepto, importe, estado, nota, visible_capitan, actualizado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'futuro\', ?, ?, ?)');
    foreach ([[$d['a'], $d['a_nombre'], $d['b'], $d['b_nombre']], [$d['b'], $d['b_nombre'], $d['a'], $d['a_nombre']]] as [$e, $en, $r, $rn]) {
        $tk->execute([$e, mb_substr($en, 0, 150), $r, mb_substr($rn, 0, 150), $pid, $d['jornada'], $d['fecha'],
                      $d['concepto'], number_format($d['importe'], 2, '.', ''), $d['nota'] ?: null, $d['visible'] ? 1 : 0, $usuario]);
    }
    return ['estado' => 'creado', 'id' => $pid];
}

/**
 * Asigna rival a un ticket viejo. Busca (o crea) el partido de esa jornada y liga el ticket.
 * Si el rival ya tiene un ticket sin rival en esa jornada, lo empareja; si no, y $crearContraparte,
 * le crea su ticket con el mismo concepto, importe, fecha y visibilidad.
 * @return string|null error (null = listo)
 */
function fp_asignar_rival(int $ticketId, string $rivalId, ?int $jornadaNueva, bool $crearContraparte,
                          array $cat, string $edicion, string $usuario): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM tickets_futuros WHERE id = ? FOR UPDATE');
        $st->execute([$ticketId]);
        $t = $st->fetch();
        if (!$t) { $pdo->rollBack(); return 'Ese ticket ya no existe.'; }
        if ($t['estado'] === 'cancelado') { $pdo->rollBack(); return 'El ticket está cancelado.'; }
        if (!empty($t['rival_estado_id'])) { $pdo->rollBack(); return 'Ese ticket ya tiene rival.'; }
        $a = (string)$t['equipo_estado_id'];
        $A = $cat['equipos'][$a] ?? null;
        $B = $cat['equipos'][$rivalId] ?? null;
        if (!$A) { $pdo->rollBack(); return 'El equipo del ticket no pertenece a esta edición.'; }
        if (!$B || $rivalId === $a) { $pdo->rollBack(); return 'Elige un rival válido.'; }
        if ($A['cat'] !== $B['cat']) { $pdo->rollBack(); return 'El rival debe ser de la misma categoría.'; }
        $jor = $jornadaNueva ?? ($t['jornada'] !== null ? (int)$t['jornada'] : null);
        if (!$jor) { $pdo->rollBack(); return 'Indica la jornada del partido.'; }
        $categoria = $cat['categorias'][$A['cat']];

        $p = fp_crear_partido_solo([
            'edicion' => $edicion, 'cat' => $A['cat'], 'tipo' => $categoria['relampago'] ? 'eliminatoria' : 'liga',
            'jornada' => $jor, 'ronda' => null, 'a' => $a, 'a_nombre' => $A['nombre'], 'b' => $rivalId, 'b_nombre' => $B['nombre'],
            'fecha' => $t['fecha_estimada'] ?: null,
        ], $usuario);

        // ¿Ya hay otro ticket vivo de este equipo en ese partido?
        $dup = $pdo->prepare("SELECT id FROM tickets_futuros WHERE partido_id = ? AND equipo_estado_id = ? AND id <> ? AND estado <> 'cancelado' LIMIT 1");
        $dup->execute([$p, $a, $ticketId]);
        if ($dup->fetchColumn()) { $pdo->rollBack(); return 'Ese equipo ya tiene ticket para ese partido. Cancela uno de los dos.'; }

        $pdo->prepare('UPDATE tickets_futuros SET rival_estado_id = ?, rival_nombre = ?, partido_id = ?, jornada = ?, actualizado_por = ? WHERE id = ?')
            ->execute([$rivalId, mb_substr($B['nombre'], 0, 150), $p, $jor, $usuario, $ticketId]);

        // Contraparte del rival
        $q = $pdo->prepare("SELECT id FROM tickets_futuros
                             WHERE equipo_estado_id = ? AND estado <> 'cancelado'
                               AND (partido_id = ? OR (partido_id IS NULL AND rival_estado_id IS NULL AND jornada <=> ?))
                             ORDER BY partido_id IS NULL, id LIMIT 1 FOR UPDATE");
        $q->execute([$rivalId, $p, $jor]);
        $cid = (int)$q->fetchColumn();
        if ($cid) {
            $pdo->prepare('UPDATE tickets_futuros SET rival_estado_id = ?, rival_nombre = ?, partido_id = ?, jornada = ?, actualizado_por = ? WHERE id = ?')
                ->execute([$a, mb_substr($A['nombre'], 0, 150), $p, $jor, $usuario, $cid]);
        } elseif ($crearContraparte) {
            $pdo->prepare('INSERT INTO tickets_futuros
                (equipo_estado_id, equipo_nombre, rival_estado_id, rival_nombre, partido_id, jornada, fecha_estimada, concepto, importe, estado, nota, visible_capitan, actualizado_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'futuro\', ?, ?, ?)')
                ->execute([$rivalId, mb_substr($B['nombre'], 0, 150), $a, mb_substr($A['nombre'], 0, 150), $p, $jor,
                           $t['fecha_estimada'], $t['concepto'], $t['importe'], $t['nota'], (int)$t['visible_capitan'], $usuario]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return error_interno($ex, 'asignar rival');
    }
    fin_bitacora_registrar($usuario, 'asignar_rival', 'ticket', (string)$ticketId, null, ['rival' => $rivalId, 'jornada' => $jor]);
    return null;
}

/** Partido sin tickets (lo usa asignar rival). Devuelve el id (nuevo o existente). */
function fp_crear_partido_solo(array $d, string $usuario): int
{
    $pdo = db();
    $par = fp_par_clave($d['a'], $d['b']);
    $pdo->prepare('INSERT IGNORE INTO fin_partidos
        (edicion_ref, categoria_ref, tipo, jornada, ronda, equipo_a_ref, equipo_a_nombre, equipo_b_ref, equipo_b_nombre, par_clave, fecha, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$d['edicion'], $d['cat'], $d['tipo'], $d['jornada'], $d['ronda'], $d['a'], mb_substr($d['a_nombre'], 0, 150),
                   $d['b'], mb_substr($d['b_nombre'], 0, 150), $par, $d['fecha'], $usuario]);
    $q = $pdo->prepare('SELECT id FROM fin_partidos WHERE edicion_ref = ? AND categoria_ref = ? AND jornada = ? AND par_clave = ?');
    $q->execute([$d['edicion'], $d['cat'], $d['jornada'], $par]);
    return (int)$q->fetchColumn();
}

/**
 * Cambia el estado de varios tickets. Cobrado anota la fecha de cobro (hoy si no se da).
 * @return int tickets cambiados
 */
function fp_cambiar_estado_tickets(array $ids, string $estado, ?string $fecha, string $usuario): int
{
    if (!isset(FP_ESTADOS_TICKET[$estado]) || !$ids) return 0;
    $m = implode(',', array_fill(0, count($ids), '?'));
    if ($estado === 'cobrado') {
        $sql = "UPDATE tickets_futuros SET estado = 'cobrado', fecha_cobro = COALESCE(?, fecha_cobro, CURDATE()), actualizado_por = ?
                 WHERE id IN ($m) AND estado <> 'cancelado'";
        $par = array_merge([$fecha, $usuario], $ids);
    } elseif ($estado === 'cancelado') {
        $sql = "UPDATE tickets_futuros SET estado = 'cancelado', actualizado_por = ? WHERE id IN ($m)";
        $par = array_merge([$usuario], $ids);
    } else {
        $sql = "UPDATE tickets_futuros SET estado = ?, fecha_cobro = NULL, actualizado_por = ?
                 WHERE id IN ($m) AND estado <> 'cancelado'";
        $par = array_merge([$estado, $usuario], $ids);
    }
    $st = db()->prepare($sql);
    $st->execute($par);
    $n = $st->rowCount();
    fin_bitacora_registrar($usuario, 'ticket_' . $estado, 'ticket', null, null, ['ids' => $ids]);
    return $n;
}

function fp_visibilidad_tickets(array $ids, bool $visible, string $usuario): int
{
    if (!$ids) return 0;
    $m = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("UPDATE tickets_futuros SET visible_capitan = ?, actualizado_por = ? WHERE id IN ($m)");
    $st->execute(array_merge([$visible ? 1 : 0, $usuario], $ids));
    fin_bitacora_registrar($usuario, $visible ? 'ticket_visible' : 'ticket_oculto', 'ticket', null, null, ['ids' => $ids]);
    return $st->rowCount();
}

/** Actualiza jornada/ronda/fecha/estado de un partido y lleva jornada y fecha a sus tickets. */
function fp_editar_partido(int $id, array $d, string $usuario): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM fin_partidos WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { $pdo->rollBack(); return 'Ese partido ya no existe.'; }
        $ex = $pdo->prepare('SELECT id FROM fin_partidos WHERE edicion_ref = ? AND categoria_ref = ? AND jornada = ? AND par_clave = ? AND id <> ?');
        $ex->execute([$p['edicion_ref'], $p['categoria_ref'], $d['jornada'], $p['par_clave'], $id]);
        if ($ex->fetchColumn()) { $pdo->rollBack(); return 'Ya existe ese mismo cruce en la jornada ' . $d['jornada'] . '.'; }
        $pdo->prepare('UPDATE fin_partidos SET jornada = ?, ronda = ?, fecha = ?, estado = ?, actualizado_por = ? WHERE id = ?')
            ->execute([$d['jornada'], $d['ronda'] ?: null, $d['fecha'], $d['estado'], $usuario, $id]);
        $pdo->prepare('UPDATE tickets_futuros SET jornada = ?, fecha_estimada = ?, actualizado_por = ? WHERE partido_id = ?')
            ->execute([$d['jornada'], $d['fecha'], $usuario, $id]);
        if ($d['estado'] === 'cancelado') {
            // Partido cancelado: sus tickets sin cobrar y sus costos sin pagar se cancelan (no se borran).
            $pdo->prepare("UPDATE tickets_futuros SET estado = 'cancelado', actualizado_por = ? WHERE partido_id = ? AND estado IN ('futuro','por_cobrar')")
                ->execute([$usuario, $id]);
            $pdo->prepare("UPDATE fin_partido_costos SET estado = 'cancelado', actualizado_por = ? WHERE partido_id = ? AND estado = 'por_pagar'")
                ->execute([$usuario, $id]);
        }
        $pdo->commit();
    } catch (Throwable $ex2) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return error_interno($ex2, 'editar partido');
    }
    fin_bitacora_registrar($usuario, 'editar_partido', 'partido', (string)$id,
        ['jornada' => $p['jornada'], 'fecha' => $p['fecha'], 'estado' => $p['estado']], $d);
    return null;
}

/* ------------------------------------------------------------------ */
/* Costos                                                              */
/* ------------------------------------------------------------------ */

/**
 * Aplica las tarifas vigentes a los partidos indicados. Sólo agrega los conceptos que el
 * partido todavía no tiene (no cancelados); nunca duplica.
 * @return array{partidos:int, costos:int}
 */
function fp_aplicar_costos(array $partidoIds, array $tarifas, string $usuario): array
{
    $r = ['partidos' => 0, 'costos' => 0];
    if (!$partidoIds) return $r;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = implode(',', array_fill(0, count($partidoIds), '?'));
        $vivos = $pdo->prepare("SELECT id FROM fin_partidos WHERE id IN ($m) AND estado <> 'cancelado' FOR UPDATE");
        $vivos->execute($partidoIds);
        $ids = array_map('intval', $vivos->fetchAll(PDO::FETCH_COLUMN));
        $datosP = $pdo->prepare('SELECT edicion_ref, categoria_ref, tipo FROM fin_partidos WHERE id = ?');
        $tiene = $pdo->prepare("SELECT concepto FROM fin_partido_costos WHERE partido_id = ? AND estado <> 'cancelado' AND concepto <> 'otro'");
        $ins = $pdo->prepare('INSERT INTO fin_partido_costos (partido_id, concepto, monto, estado, creado_por) VALUES (?, ?, ?, \'por_pagar\', ?)');
        foreach ($ids as $pid) {
            $tiene->execute([$pid]);
            $ya = array_flip($tiene->fetchAll(PDO::FETCH_COLUMN));
            $n = 0;
            $datosP->execute([$pid]);
            $dp = $datosP->fetch();
            $tar = $dp ? fp_tarifas_partido((string)$dp['edicion_ref'], (string)$dp['categoria_ref'], (string)$dp['tipo'], $tarifas) : $tarifas;
            foreach (FP_CONCEPTOS_FIJOS as $c) {
                if (isset($ya[$c])) continue;
                $ins->execute([$pid, $c, number_format((float)$tar[$c], 2, '.', ''), $usuario]);
                $n++;
            }
            if ($n) { $r['partidos']++; $r['costos'] += $n; }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    fin_bitacora_registrar($usuario, 'aplicar_costos', 'partido', null, null, ['partidos' => $partidoIds, 'tarifas' => $tarifas]);
    return $r;
}

/** Marca costos como pagados/por pagar/cancelados. $porPartido = true: $ids son partidos (todos sus costos). */
function fp_estado_costos(array $ids, string $estado, bool $porPartido, ?string $fecha, string $usuario): int
{
    if (!isset(FP_ESTADOS_COSTO[$estado]) || !$ids) return 0;
    $m = implode(',', array_fill(0, count($ids), '?'));
    $col = $porPartido ? 'partido_id' : 'id';
    $desde = $estado === 'pagado' ? "estado = 'por_pagar'" : ($estado === 'por_pagar' ? "estado = 'pagado'" : "estado <> 'cancelado'");
    if ($estado === 'pagado') {
        $st = db()->prepare("UPDATE fin_partido_costos SET estado = ?, fecha_pago = COALESCE(?, CURDATE()), actualizado_por = ?
                              WHERE $col IN ($m) AND $desde");
        $st->execute(array_merge([$estado, $fecha, $usuario], $ids));
    } else {
        $st = db()->prepare("UPDATE fin_partido_costos SET estado = ?, fecha_pago = NULL, actualizado_por = ?
                              WHERE $col IN ($m) AND $desde");
        $st->execute(array_merge([$estado, $usuario], $ids));
    }
    fin_bitacora_registrar($usuario, 'costo_' . $estado, $porPartido ? 'partido' : 'costo', null, null, ['ids' => $ids]);
    return $st->rowCount();
}

function fp_agregar_otro_gasto(int $partidoId, string $desc, float $monto, string $estado, string $usuario): ?string
{
    $st = db()->prepare("SELECT estado FROM fin_partidos WHERE id = ?");
    $st->execute([$partidoId]);
    $e = $st->fetchColumn();
    if ($e === false) return 'Ese partido ya no existe.';
    if ($e === 'cancelado') return 'El partido está cancelado.';
    db()->prepare("INSERT INTO fin_partido_costos (partido_id, concepto, descripcion, monto, estado, fecha_pago, creado_por)
                   VALUES (?, 'otro', ?, ?, ?, " . ($estado === 'pagado' ? 'CURDATE()' : 'NULL') . ", ?)")
        ->execute([$partidoId, $desc, number_format($monto, 2, '.', ''), $estado, $usuario]);
    fin_bitacora_registrar($usuario, 'otro_gasto', 'partido', (string)$partidoId, null, ['descripcion' => $desc, 'monto' => $monto, 'estado' => $estado]);
    return null;
}

/* ------------------------------------------------------------------ */
/* Lectura y reportes                                                  */
/* ------------------------------------------------------------------ */

/** Tickets de la edición (según el catálogo de equipos), con filtros simples. */
function fp_tickets(array $cat, array $f = []): array
{
    $rows = db()->query('SELECT * FROM tickets_futuros ORDER BY jornada IS NULL, jornada, equipo_nombre, id')->fetchAll();
    $out = [];
    foreach ($rows as $t) {
        $eq = $cat['equipos'][(string)$t['equipo_estado_id']] ?? null;
        if (!$eq) continue;                                                   // de otra edición o equipo borrado
        $t['cat'] = $eq['cat'];
        $t['equipo_actual'] = $eq['nombre'];
        $t['rival_actual'] = $t['rival_estado_id'] ? ($cat['equipos'][(string)$t['rival_estado_id']]['nombre'] ?? (string)$t['rival_nombre']) : '';
        if (($f['cat'] ?? '') !== '' && $t['cat'] !== $f['cat']) continue;
        if (($f['jornada'] ?? '') !== '' && (string)$t['jornada'] !== (string)$f['jornada']) continue;
        if (($f['equipo'] ?? '') !== '' && $t['equipo_estado_id'] !== $f['equipo']) continue;
        if (($f['estado'] ?? '') !== '' && $t['estado'] !== $f['estado']) continue;
        if (($f['vis'] ?? '') !== '' && (string)(int)$t['visible_capitan'] !== $f['vis']) continue;
        if (!empty($f['sin_rival']) && $t['rival_estado_id']) continue;
        $out[] = $t;
    }
    return $out;
}

/** Tickets vivos sin rival de la edición. */
function fp_contar_sin_rival(array $cat): int
{
    $n = 0;
    foreach (db()->query("SELECT equipo_estado_id FROM tickets_futuros WHERE rival_estado_id IS NULL AND estado <> 'cancelado'") as $r) {
        if (isset($cat['equipos'][(string)$r['equipo_estado_id']])) $n++;
    }
    return $n;
}

/**
 * Partidos de la edición con ingresos (de sus tickets) y costos.
 * @return array<int,array> id => partido
 */
function fp_partidos(string $edicion, array $cat, array $f = []): array
{
    $st = db()->prepare('SELECT * FROM fin_partidos WHERE edicion_ref = ? ORDER BY jornada, id');
    $st->execute([$edicion]);
    $P = [];
    foreach ($st->fetchAll() as $p) {
        if (($f['cat'] ?? '') !== '' && $p['categoria_ref'] !== $f['cat']) continue;
        if (($f['jornada'] ?? '') !== '' && (string)$p['jornada'] !== (string)$f['jornada']) continue;
        if (($f['equipo'] ?? '') !== '' && $p['equipo_a_ref'] !== $f['equipo'] && $p['equipo_b_ref'] !== $f['equipo']) continue;
        $id = (int)$p['id'];
        $p['a_actual'] = $cat['equipos'][$p['equipo_a_ref']]['nombre'] ?? $p['equipo_a_nombre'];
        $p['b_actual'] = $cat['equipos'][$p['equipo_b_ref']]['nombre'] ?? $p['equipo_b_nombre'];
        $p['cat_nombre'] = $cat['categorias'][$p['categoria_ref']]['nombre'] ?? '—';
        $p += ['tickets' => [], 'ingreso' => 0.0, 'cobrado' => 0.0, 'por_cobrar' => 0.0, 'n_tickets' => 0,
               'costo' => array_fill_keys(array_keys(FP_CONCEPTOS), 0.0), 'costo_total' => 0.0,
               'por_pagar' => array_fill_keys(array_keys(FP_CONCEPTOS), 0.0), 'por_pagar_total' => 0.0,
               'pagado_total' => 0.0, 'costos' => [], 'con_costos' => false];
        $P[$id] = $p;
    }
    if (!$P) return [];
    $ids = array_keys($P);
    $m = implode(',', array_fill(0, count($ids), '?'));
    $t = db()->prepare("SELECT * FROM tickets_futuros WHERE partido_id IN ($m) ORDER BY id");
    $t->execute($ids);
    foreach ($t->fetchAll() as $tk) {
        $p = &$P[(int)$tk['partido_id']];
        $p['tickets'][] = $tk;
        if ($tk['estado'] === 'cancelado') { unset($p); continue; }
        $imp = (float)$tk['importe'];
        $p['n_tickets']++;
        $p['ingreso'] += $imp;
        if ($tk['estado'] === 'cobrado') $p['cobrado'] += $imp; else $p['por_cobrar'] += $imp;
        unset($p);
    }
    $c = db()->prepare("SELECT * FROM fin_partido_costos WHERE partido_id IN ($m) ORDER BY FIELD(concepto,'arbitro','supervision','administracion','coordinacion','otro'), id");
    $c->execute($ids);
    foreach ($c->fetchAll() as $co) {
        $p = &$P[(int)$co['partido_id']];
        $p['costos'][] = $co;
        if ($co['estado'] !== 'cancelado') {
            $v = (float)$co['monto'];
            $p['costo'][$co['concepto']] += $v;
            $p['costo_total'] += $v;
            if ($co['concepto'] !== 'otro') $p['con_costos'] = true;
            if ($co['estado'] === 'por_pagar') { $p['por_pagar'][$co['concepto']] += $v; $p['por_pagar_total'] += $v; }
            else $p['pagado_total'] += $v;
        }
        unset($p);
    }
    foreach ($P as &$p) {
        $p['utilidad'] = $p['ingreso'] - $p['costo_total'];
        // Utilidad cobrada: el partido cuenta como cobrado cuando todos sus tickets vivos están cobrados.
        $p['cobrado_completo'] = $p['n_tickets'] > 0 && $p['por_cobrar'] < 0.005;
        $p['faltan_tickets'] = $p['estado'] !== 'cancelado' && $p['n_tickets'] < 2;
    }
    unset($p);
    return $P;
}

/** Suma un grupo de partidos (para jornada, categoría o total). */
function fp_sumar(array $partidos): array
{
    $s = ['partidos' => 0, 'ingreso' => 0.0, 'cobrado' => 0.0, 'por_cobrar' => 0.0,
          'costo' => array_fill_keys(array_keys(FP_CONCEPTOS), 0.0), 'costo_total' => 0.0,
          'por_pagar' => array_fill_keys(array_keys(FP_CONCEPTOS), 0.0), 'por_pagar_total' => 0.0,
          'utilidad' => 0.0, 'utilidad_cobrada' => 0.0, 'utilidad_por_cobrar' => 0.0, 'sin_costos' => 0];
    foreach ($partidos as $p) {
        if ($p['estado'] === 'cancelado') continue;
        $s['partidos']++;
        foreach (['ingreso', 'cobrado', 'por_cobrar', 'costo_total', 'por_pagar_total', 'utilidad'] as $k) $s[$k] += $p[$k];
        foreach (FP_CONCEPTOS as $k => $_) { $s['costo'][$k] += $p['costo'][$k]; $s['por_pagar'][$k] += $p['por_pagar'][$k]; }
        if ($p['cobrado_completo']) $s['utilidad_cobrada'] += $p['utilidad']; else $s['utilidad_por_cobrar'] += $p['utilidad'];
        if (!$p['con_costos']) $s['sin_costos']++;
    }
    return $s;
}

/** Resumen por categoría: equipos, partidos esperados/creados, jornadas. */
function fp_resumen_categorias(array $cat, array $partidos): array
{
    $out = [];
    foreach ($cat['categorias'] as $k => $c) {
        $n = count($c['equipos']);
        $mios = array_filter($partidos, fn($p) => $p['categoria_ref'] === $k);
        $vivos = array_filter($mios, fn($p) => $p['estado'] !== 'cancelado');
        $jornadas = array_unique(array_map(fn($p) => (int)$p['jornada'], $vivos));
        $out[$k] = $c + [
            'n_equipos' => $n,
            'partidos_esperados' => fp_partidos_esperados($n, $c['relampago']),
            'jornadas_esperadas' => fp_jornadas_esperadas($n, $c['relampago']),
            'partidos' => count($vivos),
            'jornadas' => $jornadas ? max($jornadas) : 0,
            'suma' => fp_sumar($mios),
        ];
    }
    return $out;
}

/**
 * Costos operativos por categoría y concepto (para el Resumen de Finanzas).
 * @return array{pagado: array<string,array<string,float>>, por_pagar: array<string,float>, partidos: array<string,int>}
 */
function fp_costos_para_resumen(string $edicion): array
{
    $r = ['pagado' => [], 'por_pagar' => [], 'partidos' => [], 'sin_costos' => [], 'tickets_por_cobrar' => [], 'programados' => [],
          'creados_liga' => [], 'creados_eli' => [], 'sin_costos_liga' => [], 'sin_costos_eli' => [], 'instalado' => false];
    if (!fp_instalado()) return $r;
    $r['instalado'] = true;
    // Partidos creados por categoría y tipo (liga / eliminatoria), con y sin costos aplicados.
    $st = db()->prepare("SELECT p.categoria_ref, p.tipo, COUNT(*) AS n,
                                SUM(NOT EXISTS (SELECT 1 FROM fin_partido_costos c WHERE c.partido_id = p.id AND c.estado <> 'cancelado' AND c.concepto <> 'otro')) AS sin
                           FROM fin_partidos p WHERE p.edicion_ref = ? AND p.estado <> 'cancelado'
                          GROUP BY p.categoria_ref, p.tipo");
    $st->execute([$edicion]);
    foreach ($st->fetchAll() as $f) {
        $suf = $f['tipo'] === 'eliminatoria' ? 'eli' : 'liga';
        $r['creados_' . $suf][$f['categoria_ref']] = (int)$f['n'];
        $r['sin_costos_' . $suf][$f['categoria_ref']] = (int)$f['sin'];
    }
    $st = db()->prepare("SELECT p.categoria_ref, c.concepto, c.estado, SUM(c.monto) AS t
                           FROM fin_partido_costos c JOIN fin_partidos p ON p.id = c.partido_id
                          WHERE p.edicion_ref = ? AND c.estado <> 'cancelado'
                          GROUP BY p.categoria_ref, c.concepto, c.estado");
    $st->execute([$edicion]);
    foreach ($st->fetchAll() as $f) {
        if ($f['estado'] === 'pagado') $r['pagado'][$f['categoria_ref']][$f['concepto']] = (float)$f['t'];
        else $r['por_pagar'][$f['categoria_ref']] = ($r['por_pagar'][$f['categoria_ref']] ?? 0) + (float)$f['t'];
    }
    $st = db()->prepare("SELECT categoria_ref, COUNT(*) FROM fin_partidos WHERE edicion_ref = ? AND estado <> 'cancelado' GROUP BY categoria_ref");
    $st->execute([$edicion]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) $r['partidos'][$k] = (int)$n;
    // Partidos programados que todavía no tienen costos aplicados (su costo es «futuro»).
    $st = db()->prepare("SELECT p.categoria_ref, COUNT(*) FROM fin_partidos p
                          WHERE p.edicion_ref = ? AND p.estado <> 'cancelado'
                            AND NOT EXISTS (SELECT 1 FROM fin_partido_costos c WHERE c.partido_id = p.id AND c.estado <> 'cancelado' AND c.concepto <> 'otro')
                          GROUP BY p.categoria_ref");
    $st->execute([$edicion]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) $r['sin_costos'][$k] = (int)$n;
    // Ingreso programado por cobrar: tickets futuros o por cobrar de partidos vivos.
    $st = db()->prepare("SELECT p.categoria_ref, SUM(t.importe) FROM tickets_futuros t JOIN fin_partidos p ON p.id = t.partido_id
                          WHERE p.edicion_ref = ? AND p.estado <> 'cancelado' AND t.estado IN ('futuro','por_cobrar')
                          GROUP BY p.categoria_ref");
    $st->execute([$edicion]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $t]) $r['tickets_por_cobrar'][$k] = (float)$t;
    $st = db()->prepare("SELECT categoria_ref, COUNT(*) FROM fin_partidos WHERE edicion_ref = ? AND estado = 'programado' GROUP BY categoria_ref");
    $st->execute([$edicion]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) $r['programados'][$k] = (int)$n;
    return $r;
}
