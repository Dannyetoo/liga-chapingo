<?php
/**
 * Liga Chapingo — Finanzas · GASTOS GENERALES (no ligados a un partido).
 * Alta, edición, pago y cancelación. Pagado = resta del saldo real; por pagar = proyección.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/finanzas-pagina.php';

$ctx = fin_contexto_pagina();
$vistaFin = 'gastos';
$semestre = obtener_semestre_activo();
$tituloPagina = 'Finanzas · Gastos generales';
$paginaActual = 'finanzas';
$edicion = $ctx['edicion'];
$instalado = fg_instalado();
$cat = ($ctx['datos'] && $edicion !== '') ? fp_catalogo($ctx['S'], $edicion) : null;

$f = [
    'cg'     => (string)($_GET['cg'] ?? ''),
    'cat'    => (string)($_GET['cat'] ?? '*'),
    'estado' => (string)($_GET['estado'] ?? ''),
    'mes'    => (string)($_GET['mes'] ?? ''),
];
$catsG = $instalado ? fg_categorias() : [];
if (!isset($catsG[(int)$f['cg']])) $f['cg'] = '';
if ($f['cat'] !== '*' && $f['cat'] !== '' && !isset($cat['categorias'][$f['cat']])) $f['cat'] = '*';
if (!isset(FG_ESTADOS[$f['estado']])) $f['estado'] = '';
if (!preg_match('/^\d{4}-\d{2}$/', $f['mes'])) $f['mes'] = '';

$qs = http_build_query(array_filter(['edicion' => $edicion] + $f, fn($v) => $v !== '' && $v !== '*'));
$volver = 'gastos.php' . ($qs ? '?' . $qs : '');

$lista = []; $editar = null; $tot = ['pagado' => 0.0, 'por_pagar' => 0.0]; $porCat = [];
if ($cat && $instalado) {
    $lista = fg_listar($edicion, $f);
    foreach ($lista as $g) {
        if ($g['estado'] === 'cancelado') continue;
        $tot[$g['estado']] += (float)$g['monto'];
        $porCat[$g['categoria_gasto']][$g['estado']] = ($porCat[$g['categoria_gasto']][$g['estado']] ?? 0) + (float)$g['monto'];
    }
    ksort($porCat);
    if ($id = (int)($_GET['editar'] ?? 0)) {
        $e = fg_obtener($id);
        if ($e && $e['edicion_ref'] === $edicion && $e['estado'] !== 'cancelado') $editar = $e;
    }
}
$nomCatLiga = function (string $k) use ($cat): string {
    if ($k === '') return 'General de la liga';
    $c = $cat['categorias'][$k] ?? null;
    return $c ? $c['nombre'] . ' · ' . $c['ruta'] : '—';
};
$v = $editar ?? ['categoria_gasto_id' => '', 'categoria_ref' => '', 'concepto' => '', 'descripcion' => '', 'monto' => '',
                 'fecha' => date('Y-m-d'), 'estado' => 'pagado', 'fecha_pago' => '', 'metodo_pago' => '', 'responsable' => '', 'observaciones' => ''];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Gastos generales · Finanzas · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
    <link rel="stylesheet" href="../assets/finanzas.css?v=3">
    <script src="../assets/finanzas.js?v=1" defer></script>
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin">
<?php if ($ctx['error']): ?>
    <div class="aviso aviso--error" role="alert"><?= e($ctx['error']) ?></div>
<?php elseif (!$cat): ?>
    <div class="aviso aviso--error" role="alert">No se pudieron leer los datos de Tesorería.</div>
<?php else: ?>
    <?php require __DIR__ . '/../vistas/finanzas-nav.php'; ?>

    <?php if (!$instalado): ?>
    <div class="aviso aviso--error" role="alert"><strong>Falta instalar esta sección.</strong> Ejecuta el SQL de gastos generales (fase 5).</div>
    <?php else: ?>

    <section class="fin-bloque fin-bloque--real" aria-labelledby="t-tot">
        <header class="fin-bloque__cab"><h2 id="t-tot" class="fin-bloque__titulo">Gastos generales</h2><p>No ligados a un partido · con los filtros de abajo</p></header>
        <div class="fin-tarjetas">
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Pagado</span><b class="fin-tarjeta__num num--neg"><?= e(mxn(-$tot['pagado'])) ?></b><small>resta del saldo real</small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Por pagar</span><b class="fin-tarjeta__num fin-proy"><?= e(mxn(-$tot['por_pagar'])) ?></b><small>entra en la proyección</small></div>
        </div>
    </section>

    <!-- ===== Alta / edición ===== -->
    <section class="tarjeta <?= $editar ? 'fin-editar' : '' ?>" id="formulario" aria-labelledby="t-form">
        <h2 id="t-form" class="tarjeta__titulo"><?= $editar ? 'Editar gasto #' . (int)$editar['id'] : 'Registrar gasto' ?></h2>
        <form method="post" action="finanzas-accion.php" class="fin-form">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="gasto_guardar">
            <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
            <label class="campo"><span>Fecha</span><input type="date" name="fecha" required value="<?= e($v['fecha']) ?>"></label>
            <label class="campo"><span>Categoría del gasto</span><select name="categoria_gasto_id" required><option value="">Elegir…</option>
                <?php foreach ($catsG as $c): if (!$c['activo'] && (int)$v['categoria_gasto_id'] !== $c['id']) continue; ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$v['categoria_gasto_id'] === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                <?php endforeach; ?></select></label>
            <label class="campo"><span>Monto</span><input type="text" inputmode="decimal" name="monto" required value="<?= e($v['monto']) ?>"></label>
            <label class="campo"><span>Concepto</span><input type="text" name="concepto" maxlength="150" required value="<?= e($v['concepto']) ?>" placeholder="Trofeos de campeón, lona, luz…"></label>
            <label class="campo"><span>Categoría de la liga</span><select name="categoria_ref">
                <option value="">General de la liga</option>
                <?php foreach ($cat['categorias'] as $k => $c): ?><option value="<?= e($k) ?>" <?= $v['categoria_ref'] === $k ? 'selected' : '' ?>><?= e($c['nombre'] . ' · ' . $c['ruta']) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Estado</span><select name="estado">
                <option value="pagado" <?= $v['estado'] === 'pagado' ? 'selected' : '' ?>>Pagado</option>
                <option value="por_pagar" <?= $v['estado'] === 'por_pagar' ? 'selected' : '' ?>>Por pagar</option></select></label>
            <label class="campo"><span>Fecha de pago <em>(si ya se pagó)</em></span><input type="date" name="fecha_pago" value="<?= e($v['fecha_pago'] ?? '') ?>"></label>
            <label class="campo"><span>Método de pago</span><select name="metodo_pago"><option value="">—</option>
                <?php foreach (FG_METODOS as $m): ?><option <?= $v['metodo_pago'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Responsable</span><input type="text" name="responsable" maxlength="100" value="<?= e($v['responsable'] ?? '') ?>" placeholder="Quién pagó"></label>
            <label class="campo fin-form__ancho"><span>Descripción <em>(opcional)</em></span><input type="text" name="descripcion" maxlength="255" value="<?= e($v['descripcion'] ?? '') ?>"></label>
            <label class="campo fin-form__ancho"><span>Observaciones <em>(opcional)</em></span><input type="text" name="observaciones" maxlength="255" value="<?= e($v['observaciones'] ?? '') ?>"></label>
            <div class="fin-form__botones">
                <button type="submit" class="btn btn--pri"><?= $editar ? 'Guardar cambios' : 'Registrar gasto' ?></button>
                <?php if ($editar): ?><a class="btn btn--sec" href="<?= e($volver) ?>">Cancelar edición</a><?php endif; ?>
            </div>
        </form>
        <details class="fin-det">
            <summary>Agregar una categoría de gasto</summary>
            <form method="post" action="finanzas-accion.php" class="fin-form">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="categoria_gasto"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                <label class="campo"><span>Nombre</span><input type="text" name="nombre" maxlength="80" required></label>
                <div class="fin-form__botones"><button type="submit" class="btn btn--sec">Agregar categoría</button></div>
            </form>
        </details>
    </section>

    <!-- ===== Lista ===== -->
    <section class="tarjeta" aria-labelledby="t-lis">
        <h2 id="t-lis" class="tarjeta__titulo">Gastos registrados</h2>
        <form method="get" action="gastos.php" class="fin-filtros">
            <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
            <label class="campo"><span>Categoría del gasto</span><select name="cg"><option value="">Todas</option>
                <?php foreach ($catsG as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $f['cg'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Categoría de la liga</span><select name="cat"><option value="*">Todas</option><option value="" <?= $f['cat'] === '' ? 'selected' : '' ?>>General de la liga</option>
                <?php foreach ($cat['categorias'] as $k => $c): ?><option value="<?= e($k) ?>" <?= $f['cat'] === $k ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Estado</span><select name="estado"><option value="">Todos</option>
                <?php foreach (FG_ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Mes</span><input type="month" name="mes" value="<?= e($f['mes']) ?>"></label>
            <div class="campo campo--boton"><button type="submit" class="btn btn--sec btn--chico">Filtrar</button></div>
        </form>

        <?php if ($porCat): ?>
        <div class="tabla-scroll"><table class="fin-tabla">
            <thead><tr><th scope="col">Categoría del gasto</th><th scope="col" class="num">Pagado</th><th scope="col" class="num">Por pagar</th><th scope="col" class="num">Total</th></tr></thead>
            <tbody><?php foreach ($porCat as $n => $x): $p = $x['pagado'] ?? 0; $pp = $x['por_pagar'] ?? 0; ?>
                <tr><th scope="row"><?= e($n) ?></th><td class="num"><?= e(mxn($p)) ?></td><td class="num fin-proy"><?= e(mxn($pp)) ?></td><td class="num fin-fuerte"><?= e(mxn($p + $pp)) ?></td></tr>
            <?php endforeach; ?></tbody>
            <tfoot><tr><th scope="row">Total</th><td class="num"><?= e(mxn($tot['pagado'])) ?></td><td class="num fin-proy"><?= e(mxn($tot['por_pagar'])) ?></td><td class="num"><?= e(mxn($tot['pagado'] + $tot['por_pagar'])) ?></td></tr></tfoot>
        </table></div>
        <?php endif; ?>

        <?php if (!$lista): ?>
            <p class="texto-suave">No hay gastos con esos filtros.</p>
        <?php else: ?>
        <form method="post" action="finanzas-accion.php">
            <?= csrf_campo() ?><input type="hidden" name="volver" value="<?= e($volver) ?>"><input type="hidden" name="accion" value="gastos_estado">
            <div class="fin-acciones">
                <label class="campo"><span>Fecha de pago</span><input type="date" name="fecha_pago"></label>
                <button type="submit" name="estado" value="pagado" class="btn btn--pri btn--chico">Marcar pagados</button>
                <button type="submit" name="estado" value="por_pagar" class="btn btn--sec btn--chico">Regresar a por pagar</button>
                <button type="submit" name="estado" value="cancelado" class="btn btn--sec btn--chico" data-confirmar="¿Cancelar los gastos seleccionados? Quedan en el historial.">Cancelar</button>
            </div>
            <div class="tabla-scroll"><table class="fin-tabla fin-tabla--tickets">
                <thead><tr><th scope="col"><input type="checkbox" data-todos="ids[]" aria-label="Seleccionar todos"></th>
                    <th scope="col">Fecha</th><th scope="col">Categoría</th><th scope="col">Concepto</th><th scope="col">Liga</th>
                    <th scope="col" class="num">Monto</th><th scope="col">Estado</th><th scope="col">Pago</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $g): ?>
                    <tr class="<?= $g['estado'] === 'cancelado' ? 'fila--off' : '' ?>">
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$g['id'] ?>" aria-label="Gasto <?= (int)$g['id'] ?>"></td>
                        <td><?= e(fecha_corta($g['fecha'])) ?></td>
                        <td><?= e($g['categoria_gasto']) ?></td>
                        <td><?= e($g['concepto']) ?><?php if ($g['descripcion']): ?><small class="texto-suave"><?= e($g['descripcion']) ?></small><?php endif; ?></td>
                        <td><?= e($nomCatLiga((string)$g['categoria_ref'])) ?></td>
                        <td class="num"><?= e(mxn((float)$g['monto'])) ?></td>
                        <td><span class="et <?= $g['estado'] === 'pagado' ? 'et-cobrado' : ($g['estado'] === 'por_pagar' ? 'et-cobrar' : 'et-cancelado') ?>"><?= e(FG_ESTADOS[$g['estado']]) ?></span></td>
                        <td><?= e(trim(fecha_corta($g['fecha_pago']) . ' ' . ($g['metodo_pago'] ?? ''))) ?><?php if ($g['responsable']): ?><small class="texto-suave"><?= e($g['responsable']) ?></small><?php endif; ?></td>
                        <td><?php if ($g['estado'] !== 'cancelado'): ?><a class="btn-texto" href="gastos.php?<?= e(($qs ? $qs . '&' : '') . 'editar=' . (int)$g['id']) ?>#formulario">Editar</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </form>
        <?php endif; ?>
        <p class="fin-nota">Los gastos anteriores a este módulo siguen en Tesorería y ya se cuentan en el Resumen. Los costos de cada partido (árbitro, supervisión…) se registran en «Partidos y gastos».</p>
    </section>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
