<?php
/**
 * Liga Chapingo — Finanzas · PARTIDOS Y GASTOS.
 *
 * Los partidos salen de los tickets (cada partido = 2 tickets). Sobre el ingreso de sus tickets
 * se aplican los costos operativos: árbitro, supervisión, administración y coordinación
 * (tarifas vigentes) más «otros gastos». Resultado por partido, por jornada y por categoría,
 * y lo que queda por cobrar y por pagar.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/finanzas-pagina.php';

$ctx = fin_contexto_pagina();
$vistaFin = 'partidos';
$semestre = obtener_semestre_activo();
$tituloPagina = 'Finanzas · Partidos y gastos';
$paginaActual = 'finanzas';
$edicion = $ctx['edicion'];
$instalado = fp_instalado();
$cat = ($ctx['datos'] && $edicion !== '') ? fp_catalogo($ctx['S'], $edicion) : null;

$f = [
    'cat'     => (string)($_GET['cat'] ?? ''),
    'jornada' => (string)($_GET['jornada'] ?? ''),
    'equipo'  => (string)($_GET['equipo'] ?? ''),
    'costos'  => (string)($_GET['costos'] ?? ''),
];
if ($cat && !isset($cat['categorias'][$f['cat']])) $f['cat'] = '';
if (!ctype_digit($f['jornada'])) $f['jornada'] = '';
if ($cat && !isset($cat['equipos'][$f['equipo']])) $f['equipo'] = '';
if (!in_array($f['costos'], ['por_pagar', 'sin_costos', 'pagados'], true)) $f['costos'] = '';
$verId = (int)($_GET['partido'] ?? 0);

$qs = http_build_query(array_filter(['edicion' => $edicion] + $f, fn($v) => $v !== ''));
$volver = 'partidos.php' . ($qs ? '?' . $qs : '');

$P = $lista = $porJornada = $porCategoria = [];
$tot = null; $tarifas = []; $ver = null;
if ($cat && $instalado) {
    $tarifas = fp_tarifas($edicion);
    $P = fp_partidos($edicion, $cat, $f);
    $lista = array_filter($P, function ($p) use ($f) {
        if ($f['costos'] === 'por_pagar') return $p['por_pagar_total'] > 0;
        if ($f['costos'] === 'sin_costos') return !$p['con_costos'] && $p['estado'] !== 'cancelado';
        if ($f['costos'] === 'pagados') return $p['con_costos'] && $p['por_pagar_total'] < 0.005;
        return true;
    });
    foreach ($lista as $p) {
        $kj = $p['categoria_ref'] . '#' . str_pad((string)$p['jornada'], 4, '0', STR_PAD_LEFT);
        $porJornada[$kj]['nombre'] = $p['cat_nombre'] . ' · ' . ($p['tipo'] === 'eliminatoria' ? ($p['ronda'] ?: 'Ronda ' . $p['jornada']) : 'Jornada ' . $p['jornada']);
        $porJornada[$kj]['p'][] = $p;
        $porCategoria[$p['categoria_ref']]['nombre'] = $p['cat_nombre'] . ' · ' . ($cat['categorias'][$p['categoria_ref']]['ruta'] ?? '');
        $porCategoria[$p['categoria_ref']]['p'][] = $p;
    }
    ksort($porJornada, SORT_STRING);
    foreach ($porJornada as &$g) $g['s'] = fp_sumar($g['p']); unset($g);
    foreach ($porCategoria as &$g) $g['s'] = fp_sumar($g['p']); unset($g);
    $tot = fp_sumar($lista);
    if ($verId) {
        $todos = fp_partidos($edicion, $cat);
        $ver = $todos[$verId] ?? null;
    }
}
$COL = ['arbitro' => 'Árbitro', 'supervision' => 'Superv.', 'administracion' => 'Admón.', 'coordinacion' => 'Coord.', 'otro' => 'Otros'];

/** Fila de montos para las tablas de resumen. */
function celdas_resumen(array $s): string
{
    $h = '<td class="num">' . (int)$s['partidos'] . '</td>'
       . '<td class="num">' . e(mxn($s['ingreso'])) . '</td>';
    foreach (array_keys(FP_CONCEPTOS) as $c) $h .= '<td class="num">' . e(mxn($s['costo'][$c])) . '</td>';
    $h .= '<td class="num">' . e(mxn($s['costo_total'])) . '</td>'
        . '<td class="num fin-proy">' . e(mxn($s['por_pagar_total'])) . '</td>'
        . '<td class="num fin-fuerte' . clase_signo($s['utilidad']) . '">' . e(mxn($s['utilidad'])) . '</td>'
        . '<td class="num fin-proy' . clase_signo($s['utilidad_por_cobrar']) . '">' . e(mxn($s['utilidad_por_cobrar'])) . '</td>';
    return $h;
}
$encResumen = '<th scope="col" class="num">Partidos</th><th scope="col" class="num">Ingreso</th>'
    . '<th scope="col" class="num">Árbitro</th><th scope="col" class="num">Superv.</th><th scope="col" class="num">Admón.</th><th scope="col" class="num">Coord.</th><th scope="col" class="num">Otros</th>'
    . '<th scope="col" class="num">Costo total</th><th scope="col" class="num">Por pagar</th><th scope="col" class="num">Utilidad</th><th scope="col" class="num">Utilidad por cobrar</th>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Partidos y gastos · Finanzas · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
    <link rel="stylesheet" href="../assets/finanzas.css?v=2">
    <script src="../assets/finanzas.js?v=1" defer></script>
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin">
<?php if ($ctx['error']): ?>
    <div class="aviso aviso--error" role="alert"><?= e($ctx['error']) ?></div>
<?php elseif (!$cat): ?>
    <div class="aviso aviso--error" role="alert">No se pudieron leer los equipos de Tesorería.</div>
<?php else: ?>
    <?php require __DIR__ . '/../vistas/finanzas-nav.php'; ?>

    <?php if (!$instalado): ?>
    <div class="aviso aviso--error" role="alert"><strong>Falta instalar esta sección.</strong> Ejecuta <code>sql/finanzas-fase4-partidos.sql</code> en la base de pruebas.</div>
    <?php elseif ($ver): ?>

    <!-- ===================== DETALLE DE UN PARTIDO ===================== -->
    <p><a class="btn-texto" href="<?= e($volver) ?>">← Todos los partidos</a></p>
    <section class="tarjeta" aria-labelledby="t-par">
        <h2 id="t-par" class="tarjeta__titulo"><?= e($ver['a_actual']) ?> vs <?= e($ver['b_actual']) ?></h2>
        <p class="texto-suave"><?= e($ver['cat_nombre']) ?> · <?= $ver['tipo'] === 'eliminatoria' ? e($ver['ronda'] ?: 'Ronda ' . $ver['jornada']) : 'Jornada ' . (int)$ver['jornada'] ?>
            · <?= e(fecha_corta($ver['fecha'])) ?> · <?= e(ucfirst($ver['estado'])) ?></p>
        <div class="fin-tarjetas">
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Ingreso (tickets)</span><b class="fin-tarjeta__num"><?= e(mxn($ver['ingreso'])) ?></b><small>cobrado <?= e(mxn($ver['cobrado'])) ?> · por cobrar <?= e(mxn($ver['por_cobrar'])) ?></small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Costos</span><b class="fin-tarjeta__num num--neg"><?= e(mxn(-$ver['costo_total'])) ?></b><small>por pagar <?= e(mxn($ver['por_pagar_total'])) ?></small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Utilidad del partido</span><b class="fin-tarjeta__num<?= clase_signo($ver['utilidad']) ?>"><?= e(mxn($ver['utilidad'])) ?></b></div>
        </div>
    </section>

    <section class="tarjeta" aria-labelledby="t-tk">
        <h2 id="t-tk" class="tarjeta__titulo">Tickets</h2>
        <ul class="fin-lista">
        <?php foreach ($ver['tickets'] as $t): ?>
            <li><b><?= e($cat['equipos'][$t['equipo_estado_id']]['nombre'] ?? $t['equipo_nombre']) ?></b> · <?= e($t['concepto']) ?> · <?= e(mxn((float)$t['importe'])) ?>
                · <?= e(FP_ESTADOS_TICKET[$t['estado']] ?? $t['estado']) ?> · <?= (int)$t['visible_capitan'] ? 'visible' : 'oculto' ?>
                · <a class="btn-texto" href="tickets.php?edicion=<?= e(rawurlencode($edicion)) ?>&amp;editar=<?= (int)$t['id'] ?>#editar">Editar</a></li>
        <?php endforeach; ?>
        <?php if ($ver['faltan_tickets']): ?><li class="num--neg">Falta el ticket de uno de los equipos.</li><?php endif; ?>
        </ul>
    </section>

    <section class="tarjeta" aria-labelledby="t-cos">
        <h2 id="t-cos" class="tarjeta__titulo">Costos del partido</h2>
        <?php if (!$ver['con_costos'] && $ver['estado'] !== 'cancelado'): ?>
        <form method="post" action="finanzas-accion.php" class="fin-acciones">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="aplicar_costos"><input type="hidden" name="partidos[]" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="volver" value="<?= e($volver . '&partido=' . (int)$ver['id']) ?>">
            <button type="submit" class="btn btn--pri btn--chico">Aplicar costos (<?= e(mxn(array_sum($tarifas))) ?>)</button>
        </form>
        <?php endif; ?>
        <?php if ($ver['costos']): ?>
        <form method="post" action="finanzas-accion.php">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="costos_estado"><input type="hidden" name="por" value="costo">
            <input type="hidden" name="volver" value="<?= e($volver . '&partido=' . (int)$ver['id']) ?>">
            <div class="tabla-scroll"><table class="fin-tabla">
                <thead><tr><th scope="col"><input type="checkbox" data-todos="costos[]" aria-label="Seleccionar todos"></th><th scope="col">Concepto</th><th scope="col" class="num">Monto</th><th scope="col">Estado</th><th scope="col">Pagado el</th></tr></thead>
                <tbody>
                <?php foreach ($ver['costos'] as $co): ?>
                    <tr class="<?= $co['estado'] === 'cancelado' ? 'fila--off' : '' ?>">
                        <td><input type="checkbox" name="costos[]" value="<?= (int)$co['id'] ?>" aria-label="Costo <?= (int)$co['id'] ?>"></td>
                        <td><?= e(FP_CONCEPTOS[$co['concepto']]) ?><?= $co['descripcion'] ? ': ' . e($co['descripcion']) : '' ?></td>
                        <td class="num"><?= e(mxn((float)$co['monto'])) ?></td>
                        <td><?= e(FP_ESTADOS_COSTO[$co['estado']]) ?></td>
                        <td><?= e(fecha_corta($co['fecha_pago'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="fin-acciones">
                <label class="campo"><span>Fecha de pago</span><input type="date" name="fecha_pago"></label>
                <button type="submit" name="estado" value="pagado" class="btn btn--pri btn--chico">Marcar pagados</button>
                <button type="submit" name="estado" value="por_pagar" class="btn btn--sec btn--chico">Regresar a por pagar</button>
                <button type="submit" name="estado" value="cancelado" class="btn btn--sec btn--chico" data-confirmar="¿Cancelar los costos seleccionados? Quedan en el historial.">Cancelar</button>
            </div>
        </form>
        <?php endif; ?>
        <?php if ($ver['estado'] !== 'cancelado'): ?>
        <form method="post" action="finanzas-accion.php" class="fin-form">
            <h3 class="fin-form__titulo">Agregar otro gasto</h3>
            <?= csrf_campo() ?><input type="hidden" name="accion" value="otro_gasto"><input type="hidden" name="partido_id" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="volver" value="<?= e($volver . '&partido=' . (int)$ver['id']) ?>">
            <label class="campo fin-form__ancho"><span>¿Qué fue?</span><input type="text" name="descripcion" maxlength="150" required placeholder="Balón, agua, lona…"></label>
            <label class="campo"><span>Monto</span><input type="text" inputmode="decimal" name="monto" required></label>
            <label class="campo"><span>Estado</span><select name="estado"><option value="por_pagar">Por pagar</option><option value="pagado">Pagado hoy</option></select></label>
            <div class="fin-form__botones"><button type="submit" class="btn btn--sec">Agregar gasto</button></div>
        </form>
        <?php endif; ?>
    </section>

    <section class="tarjeta" aria-labelledby="t-edp">
        <h2 id="t-edp" class="tarjeta__titulo">Datos del partido</h2>
        <form method="post" action="finanzas-accion.php" class="fin-form">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="editar_partido"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="volver" value="<?= e($volver . '&partido=' . (int)$ver['id']) ?>">
            <label class="campo"><span><?= $ver['tipo'] === 'eliminatoria' ? 'Ronda (número)' : 'Jornada' ?></span><input type="number" name="jornada" min="1" max="400" required value="<?= (int)$ver['jornada'] ?>"></label>
            <?php if ($ver['tipo'] === 'eliminatoria'): ?><label class="campo"><span>Nombre de la ronda</span><input type="text" name="ronda" maxlength="40" value="<?= e($ver['ronda'] ?? '') ?>"></label><?php endif; ?>
            <label class="campo"><span>Fecha</span><input type="date" name="fecha" value="<?= e($ver['fecha'] ?? '') ?>"></label>
            <label class="campo"><span>Estado</span><select name="estado">
                <?php foreach (['programado' => 'Programado', 'jugado' => 'Jugado', 'cancelado' => 'Cancelado'] as $k => $t): ?><option value="<?= $k ?>" <?= $ver['estado'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
            <div class="fin-form__botones"><button type="submit" class="btn btn--sec" data-confirmar="Si lo cancelas, sus tickets sin cobrar y sus costos sin pagar se cancelan. ¿Guardar?">Guardar partido</button></div>
        </form>
    </section>

    <?php else: ?>

    <!-- ===================== RESUMEN ===================== -->
    <form method="get" action="partidos.php" class="fin-filtros">
        <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
        <label class="campo"><span>Categoría</span><select name="cat"><option value="">Todas</option>
            <?php foreach ($cat['categorias'] as $k => $c): ?><option value="<?= e($k) ?>" <?= $f['cat'] === $k ? 'selected' : '' ?>><?= e($c['nombre'] . ' · ' . $c['ruta']) ?></option><?php endforeach; ?></select></label>
        <label class="campo campo--corto"><span>Jornada</span><input type="number" name="jornada" min="0" max="400" value="<?= e($f['jornada']) ?>"></label>
        <label class="campo"><span>Equipo</span><select name="equipo"><option value="">Todos</option>
            <?php foreach ($cat['equipos'] as $id => $x): if ($f['cat'] !== '' && $x['cat'] !== $f['cat']) continue; ?><option value="<?= e($id) ?>" <?= $f['equipo'] === $id ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select></label>
        <label class="campo"><span>Costos</span><select name="costos"><option value="">Todos</option>
            <option value="por_pagar" <?= $f['costos'] === 'por_pagar' ? 'selected' : '' ?>>Con costos por pagar</option>
            <option value="sin_costos" <?= $f['costos'] === 'sin_costos' ? 'selected' : '' ?>>Sin costos aplicados</option>
            <option value="pagados" <?= $f['costos'] === 'pagados' ? 'selected' : '' ?>>Todo pagado</option></select></label>
        <div class="campo campo--boton"><button type="submit" class="btn btn--sec btn--chico">Filtrar</button></div>
    </form>

    <?php if (!$P): ?>
        <div class="aviso aviso--info" role="status">Todavía no hay partidos. Créalos en <a href="tickets.php?edicion=<?= e(rawurlencode($edicion)) ?>">Tickets</a> (rol todos contra todos o partido suelto), o asigna rival a los tickets viejos.</div>
    <?php else: ?>

    <section class="fin-bloque fin-bloque--real" aria-labelledby="t-tot">
        <header class="fin-bloque__cab"><h2 id="t-tot" class="fin-bloque__titulo">Partidos</h2><p><?= (int)$tot['partidos'] ?> partidos con estos filtros · ingreso según tickets</p></header>
        <div class="fin-tarjetas">
            <div class="fin-tarjeta fin-tarjeta--grande"><span class="fin-tarjeta__etq">Utilidad de los partidos</span>
                <b class="fin-tarjeta__num<?= clase_signo($tot['utilidad']) ?>"><?= e(mxn($tot['utilidad'])) ?></b>
                <small>ingreso <?= e(mxn($tot['ingreso'])) ?> − costos <?= e(mxn($tot['costo_total'])) ?></small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Utilidad cobrada</span><b class="fin-tarjeta__num<?= clase_signo($tot['utilidad_cobrada']) ?>"><?= e(mxn($tot['utilidad_cobrada'])) ?></b><small>partidos con tickets cobrados</small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Utilidad por cobrar</span><b class="fin-tarjeta__num<?= clase_signo($tot['utilidad_por_cobrar']) ?>"><?= e(mxn($tot['utilidad_por_cobrar'])) ?></b><small>ingreso pendiente <?= e(mxn($tot['por_cobrar'])) ?></small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Costos por pagar</span><b class="fin-tarjeta__num num--neg"><?= e(mxn(-$tot['por_pagar_total'])) ?></b><small><?= (int)$tot['sin_costos'] ?> partidos sin costos aplicados</small></div>
        </div>
    </section>

    <section class="tarjeta" aria-labelledby="t-con">
        <h2 id="t-con" class="tarjeta__titulo">Costos por concepto</h2>
        <div class="tabla-scroll"><table class="fin-tabla">
            <thead><tr><th scope="col">Concepto</th><th scope="col" class="num">Tarifa vigente</th><th scope="col" class="num">Total aplicado</th><th scope="col" class="num">Pagado</th><th scope="col" class="num">Por pagar</th></tr></thead>
            <tbody>
            <?php foreach (FP_CONCEPTOS as $k => $n): ?>
                <tr><th scope="row"><?= e($n) ?></th><td class="num"><?= isset($tarifas[$k]) ? e(mxn($tarifas[$k])) : '—' ?></td>
                    <td class="num"><?= e(mxn($tot['costo'][$k])) ?></td><td class="num"><?= e(mxn($tot['costo'][$k] - $tot['por_pagar'][$k])) ?></td>
                    <td class="num fin-proy"><?= e(mxn($tot['por_pagar'][$k])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th scope="row">Total</th><td class="num"><?= e(mxn(array_sum($tarifas))) ?></td><td class="num"><?= e(mxn($tot['costo_total'])) ?></td>
                <td class="num"><?= e(mxn($tot['costo_total'] - $tot['por_pagar_total'])) ?></td><td class="num fin-proy"><?= e(mxn($tot['por_pagar_total'])) ?></td></tr></tfoot>
        </table></div>
        <details class="fin-det">
            <summary>Cambiar tarifas por partido</summary>
            <form method="post" action="finanzas-accion.php" class="fin-form">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="tarifas"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                <?php foreach (FP_CONCEPTOS_FIJOS as $c): ?>
                    <label class="campo"><span><?= e(FP_CONCEPTOS[$c]) ?></span><input type="text" inputmode="decimal" name="tarifa_<?= e($c) ?>" required value="<?= e($tarifas[$c]) ?>"></label>
                <?php endforeach; ?>
                <div class="fin-form__botones"><button type="submit" class="btn btn--sec">Guardar tarifas</button></div>
                <p class="fin-nota fin-form__ancho">Las tarifas nuevas se usan en los costos que se apliquen desde ahora; lo ya aplicado no cambia.</p>
            </form>
        </details>
    </section>

    <section class="tarjeta" aria-labelledby="t-cat">
        <h2 id="t-cat" class="tarjeta__titulo">Por categoría</h2>
        <div class="tabla-scroll"><table class="fin-tabla fin-tabla--ancha">
            <thead><tr><th scope="col">Categoría</th><?= $encResumen ?></tr></thead>
            <tbody><?php foreach ($porCategoria as $g): ?><tr><th scope="row"><?= e($g['nombre']) ?></th><?= celdas_resumen($g['s']) ?></tr><?php endforeach; ?></tbody>
            <tfoot><tr><th scope="row">Total</th><?= celdas_resumen($tot) ?></tr></tfoot>
        </table></div>
    </section>

    <section class="tarjeta" aria-labelledby="t-jor">
        <h2 id="t-jor" class="tarjeta__titulo">Por jornada</h2>
        <div class="tabla-scroll"><table class="fin-tabla fin-tabla--ancha">
            <thead><tr><th scope="col">Jornada</th><?= $encResumen ?></tr></thead>
            <tbody><?php foreach ($porJornada as $g): ?><tr><th scope="row"><?= e($g['nombre']) ?></th><?= celdas_resumen($g['s']) ?></tr><?php endforeach; ?></tbody>
            <tfoot><tr><th scope="row">Total</th><?= celdas_resumen($tot) ?></tr></tfoot>
        </table></div>
    </section>

    <section class="tarjeta" aria-labelledby="t-lis">
        <h2 id="t-lis" class="tarjeta__titulo">Partidos</h2>
        <form method="post" action="finanzas-accion.php">
            <?= csrf_campo() ?><input type="hidden" name="volver" value="<?= e($volver) ?>"><input type="hidden" name="por" value="partido">
            <div class="fin-acciones">
                <button type="submit" name="accion" value="aplicar_costos" class="btn btn--pri btn--chico">Aplicar costos (<?= e(mxn(array_sum($tarifas))) ?> c/u)</button>
                <label class="campo"><span>Fecha de pago</span><input type="date" name="fecha_pago"></label>
                <input type="hidden" name="estado" value="pagado">
                <button type="submit" name="accion" value="costos_estado" class="btn btn--sec btn--chico" data-confirmar="¿Marcar como pagados todos los costos de los partidos seleccionados?">Marcar costos pagados</button>
            </div>
            <div class="tabla-scroll"><table class="fin-tabla fin-tabla--ancha">
                <thead><tr>
                    <th scope="col"><input type="checkbox" data-todos="partidos[]" aria-label="Seleccionar todos"></th>
                    <th scope="col" class="num">J</th><th scope="col">Partido</th><th scope="col">Fecha</th>
                    <th scope="col" class="num">Ingreso</th><th scope="col" class="num">Cobrado</th>
                    <?php foreach ($COL as $n): ?><th scope="col" class="num"><?= e($n) ?></th><?php endforeach; ?>
                    <th scope="col" class="num">Utilidad</th><th scope="col">Costos</th>
                </tr></thead>
                <tbody>
                <?php foreach ($lista as $p): ?>
                    <tr class="<?= $p['estado'] === 'cancelado' ? 'fila--off' : '' ?>">
                        <td><input type="checkbox" name="partidos[]" value="<?= (int)$p['id'] ?>" aria-label="Partido <?= (int)$p['id'] ?>"></td>
                        <td class="num"><?= (int)$p['jornada'] ?></td>
                        <td><a href="partidos.php?<?= e(($qs ? $qs . '&' : '') . 'partido=' . (int)$p['id']) ?>"><?= e($p['a_actual']) ?> vs <?= e($p['b_actual']) ?></a>
                            <small class="texto-suave"><?= e($p['cat_nombre']) ?><?= $p['tipo'] === 'eliminatoria' && $p['ronda'] ? ' · ' . e($p['ronda']) : '' ?><?= $p['faltan_tickets'] ? ' · falta un ticket' : '' ?></small></td>
                        <td><?= e(fecha_corta($p['fecha'])) ?></td>
                        <td class="num"><?= e(mxn($p['ingreso'])) ?></td>
                        <td class="num"><?= e(mxn($p['cobrado'])) ?></td>
                        <?php foreach (array_keys($COL) as $c): ?><td class="num"><?= $p['costo'][$c] > 0 ? e(mxn($p['costo'][$c])) : '<span class="texto-suave">—</span>' ?></td><?php endforeach; ?>
                        <td class="num fin-fuerte<?= clase_signo($p['utilidad']) ?>"><?= e(mxn($p['utilidad'])) ?></td>
                        <td><?php if ($p['estado'] === 'cancelado'): ?><span class="et et-cancelado">Cancelado</span>
                            <?php elseif (!$p['con_costos']): ?><span class="et et-alerta">Sin aplicar</span>
                            <?php elseif ($p['por_pagar_total'] > 0): ?><span class="et et-cobrar">Por pagar <?= e(mxn($p['por_pagar_total'])) ?></span>
                            <?php else: ?><span class="et et-cobrado">Pagado</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </form>
        <p class="fin-nota">Utilidad del partido = ingreso de sus tickets − árbitro − supervisión − administración − coordinación − otros. «Utilidad por cobrar» = la de los partidos cuyos tickets no están todos cobrados.</p>
    </section>
    <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
