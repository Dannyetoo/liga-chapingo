<?php
/**
 * Liga Chapingo — Finanzas · GASTOS GENERALES (no ligados a un partido).
 *
 * Premios, trofeos, material deportivo, cancha, luz, publicidad, transporte…
 *  - «Pagado» es dinero real: resta del saldo real de Administración.
 *  - «Por pagar» es un compromiso: entra en la proyección.
 *  - Nada se borra: se cancela. Cada alta, cambio y cancelación queda en la bitácora.
 * Las categorías de gasto son ampliables (fin_categorias_gasto).
 */
declare(strict_types=1);

const FG_ESTADOS = ['pagado' => 'Pagado', 'por_pagar' => 'Por pagar', 'cancelado' => 'Cancelado'];
const FG_METODOS = ['Efectivo', 'Transferencia', 'Tarjeta', 'Otro'];

function fg_instalado(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM fin_gastos_generales LIMIT 1');
        db()->query('SELECT 1 FROM fin_categorias_gasto LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

/** @return array<int,array{id:int,nombre:string,activo:bool}> */
function fg_categorias(bool $soloActivas = false): array
{
    $out = [];
    foreach (db()->query('SELECT id, nombre, activo FROM fin_categorias_gasto ORDER BY orden, nombre') as $f) {
        if ($soloActivas && !(int)$f['activo']) continue;
        $out[(int)$f['id']] = ['id' => (int)$f['id'], 'nombre' => (string)$f['nombre'], 'activo' => (bool)(int)$f['activo']];
    }
    return $out;
}

function fg_crear_categoria(string $nombre, string $usuario): ?string
{
    $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
    if ($nombre === '' || mb_strlen($nombre) > 80) return 'Escribe el nombre de la categoría (máx. 80).';
    foreach (fg_categorias() as $c) {
        if (mb_strtolower($c['nombre']) === mb_strtolower($nombre)) return 'Esa categoría ya existe.';
    }
    $orden = (int)db()->query('SELECT COALESCE(MAX(orden), 0) + 10 FROM fin_categorias_gasto')->fetchColumn();
    db()->prepare('INSERT INTO fin_categorias_gasto (nombre, orden) VALUES (?, ?)')->execute([$nombre, $orden]);
    fin_bitacora_registrar($usuario, 'crear', 'categoria_gasto', (string)db()->lastInsertId(), null, ['nombre' => $nombre]);
    return null;
}

/**
 * Valida y normaliza un gasto enviado por el formulario.
 * @return array{0:?array,1:string[]} [datos, errores]
 */
function fg_validar(array $in, array $categoriasLiga): array
{
    $err = [];
    $cats = fg_categorias(true);
    $catG = (int)($in['categoria_gasto_id'] ?? 0);
    $catL = (string)($in['categoria_ref'] ?? '');
    $concepto = trim((string)($in['concepto'] ?? ''));
    $desc = trim((string)($in['descripcion'] ?? ''));
    $obs = trim((string)($in['observaciones'] ?? ''));
    $resp = trim((string)($in['responsable'] ?? ''));
    $met = (string)($in['metodo_pago'] ?? '');
    $estado = (string)($in['estado'] ?? 'pagado');
    $monto = monto_valido($in['monto'] ?? '');
    $fecha = fecha_valida($in['fecha'] ?? '');
    $fechaPago = fecha_valida($in['fecha_pago'] ?? '');

    if (!isset($cats[$catG])) $err[] = 'Elige la categoría del gasto.';
    if ($catL !== '' && !isset($categoriasLiga[$catL])) $err[] = 'La categoría de la liga no es válida.';
    if ($concepto === '' || mb_strlen($concepto) > 150) $err[] = 'Escribe el concepto (máx. 150).';
    if (mb_strlen($desc) > 255) $err[] = 'La descripción admite 255 caracteres.';
    if (mb_strlen($obs) > 255) $err[] = 'Las observaciones admiten 255 caracteres.';
    if (mb_strlen($resp) > 100) $err[] = 'El responsable admite 100 caracteres.';
    if ($met !== '' && !in_array($met, FG_METODOS, true)) $err[] = 'Método de pago no válido.';
    if (!isset(FG_ESTADOS[$estado]) || $estado === 'cancelado') $err[] = 'Estado no válido.';
    if ($monto === null) $err[] = 'El monto debe ser mayor a 0.';
    if (!$fecha) $err[] = 'Indica la fecha del gasto.';
    if ($err) return [null, $err];
    return [[
        'categoria_gasto_id' => $catG, 'categoria_ref' => $catL, 'concepto' => $concepto,
        'descripcion' => $desc ?: null, 'monto' => $monto, 'fecha' => $fecha, 'estado' => $estado,
        'fecha_pago' => $estado === 'pagado' ? ($fechaPago ?? $fecha) : null,
        'metodo_pago' => $met ?: null, 'responsable' => $resp ?: null, 'observaciones' => $obs ?: null,
    ], []];
}

function fg_crear(string $edicion, array $d, string $usuario): int
{
    db()->prepare('INSERT INTO fin_gastos_generales
        (edicion_ref, categoria_ref, categoria_gasto_id, concepto, descripcion, monto, fecha, estado, fecha_pago, metodo_pago, responsable, observaciones, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$edicion, $d['categoria_ref'], $d['categoria_gasto_id'], $d['concepto'], $d['descripcion'],
                   number_format($d['monto'], 2, '.', ''), $d['fecha'], $d['estado'], $d['fecha_pago'],
                   $d['metodo_pago'], $d['responsable'], $d['observaciones'], $usuario]);
    $id = (int)db()->lastInsertId();
    fin_bitacora_registrar($usuario, 'crear', 'gasto_general', (string)$id, null, $d);
    return $id;
}

function fg_obtener(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM fin_gastos_generales WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function fg_editar(int $id, array $d, string $usuario): ?string
{
    $g = fg_obtener($id);
    if (!$g) return 'Ese gasto ya no existe.';
    if ($g['estado'] === 'cancelado') return 'Un gasto cancelado no se edita.';
    db()->prepare('UPDATE fin_gastos_generales SET categoria_ref = ?, categoria_gasto_id = ?, concepto = ?, descripcion = ?, monto = ?,
                   fecha = ?, estado = ?, fecha_pago = ?, metodo_pago = ?, responsable = ?, observaciones = ?, actualizado_por = ? WHERE id = ?')
        ->execute([$d['categoria_ref'], $d['categoria_gasto_id'], $d['concepto'], $d['descripcion'], number_format($d['monto'], 2, '.', ''),
                   $d['fecha'], $d['estado'], $d['fecha_pago'], $d['metodo_pago'], $d['responsable'], $d['observaciones'], $usuario, $id]);
    fin_bitacora_registrar($usuario, 'modificar', 'gasto_general', (string)$id,
        array_intersect_key($g, array_flip(['concepto', 'monto', 'estado', 'fecha', 'categoria_gasto_id', 'categoria_ref'])), $d);
    return null;
}

/** Cambia el estado de varios gastos. Pagado anota la fecha de pago (hoy si no se da). */
function fg_cambiar_estado(array $ids, string $estado, ?string $fechaPago, string $usuario): int
{
    if (!$ids || !isset(FG_ESTADOS[$estado])) return 0;
    $m = implode(',', array_fill(0, count($ids), '?'));
    if ($estado === 'pagado') {
        $st = db()->prepare("UPDATE fin_gastos_generales SET estado = 'pagado', fecha_pago = COALESCE(?, fecha_pago, CURDATE()), actualizado_por = ?
                              WHERE id IN ($m) AND estado <> 'cancelado'");
        $st->execute(array_merge([$fechaPago, $usuario], $ids));
    } elseif ($estado === 'por_pagar') {
        $st = db()->prepare("UPDATE fin_gastos_generales SET estado = 'por_pagar', fecha_pago = NULL, actualizado_por = ?
                              WHERE id IN ($m) AND estado <> 'cancelado'");
        $st->execute(array_merge([$usuario], $ids));
    } else {
        $st = db()->prepare("UPDATE fin_gastos_generales SET estado = 'cancelado', actualizado_por = ? WHERE id IN ($m)");
        $st->execute(array_merge([$usuario], $ids));
    }
    fin_bitacora_registrar($usuario, 'gasto_' . $estado, 'gasto_general', null, null, ['ids' => $ids]);
    return $st->rowCount();
}

/** Gastos de una edición con filtros (categoría de gasto, categoría de liga, estado, mes AAAA-MM). */
function fg_listar(string $edicion, array $f = []): array
{
    $sql = 'SELECT g.*, c.nombre AS categoria_gasto FROM fin_gastos_generales g
              JOIN fin_categorias_gasto c ON c.id = g.categoria_gasto_id
             WHERE g.edicion_ref = ?';
    $p = [$edicion];
    if (!empty($f['cg']))     { $sql .= ' AND g.categoria_gasto_id = ?'; $p[] = (int)$f['cg']; }
    if (isset($f['cat']) && $f['cat'] !== null && $f['cat'] !== '*') { $sql .= ' AND g.categoria_ref = ?'; $p[] = (string)$f['cat']; }
    if (!empty($f['estado'])) { $sql .= ' AND g.estado = ?'; $p[] = (string)$f['estado']; }
    if (!empty($f['mes']))    { $sql .= " AND DATE_FORMAT(g.fecha, '%Y-%m') = ?"; $p[] = (string)$f['mes']; }
    $sql .= ' ORDER BY g.fecha DESC, g.id DESC';
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/** Para el Resumen: pagados y por pagar por categoría de liga. */
function fg_para_resumen(string $edicion): array
{
    $r = ['generales_pagado' => [], 'generales_por_pagar' => []];
    if (!fg_instalado()) return $r;
    $st = db()->prepare("SELECT categoria_ref, estado, SUM(monto) FROM fin_gastos_generales
                          WHERE edicion_ref = ? AND estado <> 'cancelado' GROUP BY categoria_ref, estado");
    $st->execute([$edicion]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $e, $t]) {
        $r[$e === 'pagado' ? 'generales_pagado' : 'generales_por_pagar'][(string)$k] = (float)$t;
    }
    return $r;
}
