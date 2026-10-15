<?php
/**
 * Liga Chapingo — Finanzas · TICKETS.
 *
 * Cada ticket es el cargo de un equipo por un partido, y siempre tiene rival:
 *  - «Generar rol» (todos contra todos) o «Crear partido» (eliminatoria / relámpago)
 *    crean el partido con sus DOS tickets, uno por equipo.
 *  - Los tickets viejos sin rival aparecen arriba para asignárselo.
 * Estados: futuro → por cobrar (día del encuentro) → cobrado; o cancelado.
 * Visible / oculto para el capitán es independiente del estado.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/finanzas-pagina.php';

$ctx = fin_contexto_pagina();
$vistaFin = 'tickets';
$semestre = obtener_semestre_activo();
$tituloPagina = 'Finanzas · Tickets';
$paginaActual = 'finanzas';
$edicion = $ctx['edicion'];
$instalado = fp_instalado();
$cat = ($ctx['datos'] && $edicion !== '') ? fp_catalogo($ctx['S'], $edicion) : null;

$f = [
    'cat'       => (string)($_GET['cat'] ?? ''),
    'jornada'   => (string)($_GET['jornada'] ?? ''),
    'equipo'    => (string)($_GET['equipo'] ?? ''),
    'estado'    => (string)($_GET['estado'] ?? ''),
    'vis'       => (string)($_GET['vis'] ?? ''),
];
if ($cat && !isset($cat['categorias'][$f['cat']])) $f['cat'] = '';
if (!ctype_digit($f['jornada'])) $f['jornada'] = '';
if (!isset(FP_ESTADOS_TICKET[$f['estado']])) $f['estado'] = '';
if (!in_array($f['vis'], ['0', '1'], true)) $f['vis'] = '';
if ($cat && !isset($cat['equipos'][$f['equipo']])) $f['equipo'] = '';

$qs = http_build_query(array_filter(['edicion' => $edicion] + $f, fn($v) => $v !== ''));
$volver = 'tickets.php' . ($qs ? '?' . $qs : '');

$tickets = $sinRival = $partidos = $resCat = [];
$editar = null;
$tarifaArb = 150.0;
if ($cat && $instalado) {
    $tickets  = fp_tickets($cat, $f);
    $sinRival = array_values(array_filter(fp_tickets($cat, []), fn($t) => !$t['rival_estado_id'] && $t['estado'] !== 'cancelado'));
    $partidos = fp_partidos($edicion, $cat);
    $resCat   = fp_resumen_categorias($cat, $partidos);
    $tarifaArb = fin_tarifa($ctx['S']['conceptos'] ?? [], 'arbitraje') ?? 150.0;
    $idEd = (int)($_GET['editar'] ?? 0);
    if ($idEd) foreach (fp_tickets($cat, []) as $t) if ((int)$t['id'] === $idEd) $editar = $t;
}

/** Cruces que faltan en una categoría de liga (todos contra todos). */
function cruces_faltantes(array $c, array $partidos, string $k): array
{
    $ya = [];
    foreach ($partidos as $p) if ($p['categoria_ref'] === $k && $p['estado'] !== 'cancelado') $ya[$p['par_clave']] = true;
    $ids = array_keys($c['equipos']);
    $out = [];
    for ($i = 0; $i < count($ids); $i++) for ($j = $i + 1; $j < count($ids); $j++) {
        if (!isset($ya[fp_par_clave($ids[$i], $ids[$j])])) $out[] = $c['equipos'][$ids[$i]] . ' vs ' . $c['equipos'][$ids[$j]];
    }
    return $out;
}
$etqEstado = ['futuro' => 'et-futuro', 'por_cobrar' => 'et-cobrar', 'cobrado' => 'et-cobrado', 'cancelado' => 'et-cancelado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Tickets · Finanzas · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
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
    <?php else: ?>

    <?php if ($editar): ?>
    <!-- ===== Editar un ticket ===== -->
    <section class="tarjeta fin-editar" id="editar">
        <h2 class="tarjeta__titulo">Editar ticket #<?= (int)$editar['id'] ?></h2>
        <p class="texto-suave"><?= e($editar['equipo_actual']) ?> vs <?= e($editar['rival_actual'] ?: 'sin rival') ?> · jornada <?= e($editar['jornada'] ?? '—') ?>.
            La jornada y la fecha se cambian desde el partido.</p>
        <form method="post" action="finanzas-accion.php" class="fin-form">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="editar_ticket">
            <input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
            <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <label class="campo"><span>Concepto</span><input type="text" name="concepto" maxlength="150" required value="<?= e($editar['concepto']) ?>"></label>
            <label class="campo"><span>Importe</span><input type="text" inputmode="decimal" name="importe" required value="<?= e($editar['importe']) ?>"></label>
            <label class="campo"><span>Estado</span>
                <select name="estado">
                    <?php foreach (FP_ESTADOS_TICKET as $k => $t): ?><option value="<?= e($k) ?>" <?= $editar['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select></label>
            <label class="campo"><span>Fecha de cobro</span><input type="date" name="fecha_cobro" value="<?= e($editar['fecha_cobro'] ?? '') ?>"></label>
            <label class="campo"><span>Folio de Tesorería <em>(opcional)</em></span><input type="text" name="folio" maxlength="30" value="<?= e($editar['folio_tesoreria'] ?? '') ?>"></label>
            <label class="campo fin-form__ancho"><span>Nota <em>(opcional)</em></span><input type="text" name="nota" maxlength="255" value="<?= e($editar['nota'] ?? '') ?>"></label>
            <label class="casilla"><input type="checkbox" name="visible" value="1" <?= (int)$editar['visible_capitan'] ? 'checked' : '' ?>> Visible para el capitán</label>
            <div class="fin-form__botones">
                <button type="submit" class="btn btn--pri">Guardar ticket</button>
                <a class="btn btn--sec" href="<?= e($volver) ?>">Cerrar</a>
                <?php if ($editar['partido_id']): ?><a class="btn-texto" href="partidos.php?edicion=<?= e(rawurlencode($edicion)) ?>&amp;partido=<?= (int)$editar['partido_id'] ?>">Ver partido →</a><?php endif; ?>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($sinRival): ?>
    <!-- ===== Tickets sin rival ===== -->
    <section class="tarjeta fin-alerta" aria-labelledby="t-sin">
        <h2 id="t-sin" class="tarjeta__titulo"><?= count($sinRival) ?> tickets sin rival</h2>
        <p class="texto-suave">Todo ticket debe tener rival. Al asignarlo se identifica el partido; si el rival tiene un ticket sin rival en esa jornada se emparejan, y si no, se le crea el suyo.</p>
        <div class="fin-lista-sin">
        <?php foreach ($sinRival as $t): $c = $cat['categorias'][$t['cat']]; ?>
            <form method="post" action="finanzas-accion.php" class="fin-sin">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="asignar_rival">
                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
                <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <div class="fin-sin__info"><b><?= e($t['equipo_actual']) ?></b>
                    <small><?= e($c['nombre']) ?> · <?= e($t['concepto']) ?> · <?= e(mxn((float)$t['importe'])) ?><?= $t['fecha_estimada'] ? ' · ' . e(fecha_corta($t['fecha_estimada'])) : '' ?></small></div>
                <label class="campo"><span>Rival</span>
                    <select name="rival" required>
                        <option value="">Elegir…</option>
                        <?php foreach ($c['equipos'] as $id => $n): if ($id === $t['equipo_estado_id']) continue; ?>
                            <option value="<?= e($id) ?>"><?= e($n) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="campo campo--corto"><span>Jornada</span><input type="number" name="jornada" min="1" max="400" required value="<?= e($t['jornada'] ?? '') ?>"></label>
                <label class="casilla"><input type="checkbox" name="contraparte" value="1" checked> Crear el ticket del rival si no tiene</label>
                <button type="submit" class="btn btn--pri btn--chico">Asignar</button>
            </form>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== Cobertura por categoría ===== -->
    <section class="tarjeta" aria-labelledby="t-cob">
        <h2 id="t-cob" class="tarjeta__titulo">Partidos por categoría</h2>
        <p class="texto-suave">Liga (Primera, Segunda…): todos contra todos, un ticket por equipo para cada rival. Relámpago: eliminación directa, n − 1 partidos.</p>
        <div class="tabla-scroll">
        <table class="fin-tabla">
            <thead><tr><th scope="col">Categoría</th><th scope="col">Formato</th><th scope="col" class="num">Equipos</th>
                <th scope="col" class="num">Partidos</th><th scope="col" class="num">Jornadas</th><th scope="col" class="num">Tickets</th><th scope="col">Pendiente</th></tr></thead>
            <tbody>
            <?php foreach ($resCat as $k => $c):
                $faltan = $c['relampago'] ? [] : cruces_faltantes($c, $partidos, $k);
                $incompletos = count(array_filter($partidos, fn($p) => $p['categoria_ref'] === $k && $p['faltan_tickets']));
                $nTk = array_sum(array_map(fn($p) => $p['categoria_ref'] === $k ? $p['n_tickets'] : 0, $partidos)); ?>
                <tr>
                    <th scope="row"><?= e($c['nombre']) ?><small><?= e($c['ruta']) ?></small></th>
                    <td><?= $c['relampago'] ? 'Eliminación directa' : 'Todos contra todos' ?></td>
                    <td class="num"><?= (int)$c['n_equipos'] ?></td>
                    <td class="num"><?= (int)$c['partidos'] ?> / <?= (int)$c['partidos_esperados'] ?></td>
                    <td class="num"><?= (int)$c['jornadas'] ?> / <?= (int)$c['jornadas_esperadas'] ?></td>
                    <td class="num"><?= (int)$nTk ?> / <?= (int)$c['partidos_esperados'] * 2 ?></td>
                    <td>
                        <?php if (!$faltan && !$incompletos && $c['partidos'] >= $c['partidos_esperados'] && $c['partidos_esperados'] > 0): ?>
                            <span class="et et-cobrado">Completo</span>
                        <?php else: ?>
                            <?php if ($faltan): ?>
                                <details class="fin-det"><summary><?= count($faltan) ?> cruces sin partido</summary>
                                    <ul><?php foreach (array_slice($faltan, 0, 200) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></details>
                            <?php elseif ($c['relampago'] && $c['partidos'] < $c['partidos_esperados']): ?>
                                <?= (int)($c['partidos_esperados'] - $c['partidos']) ?> partidos por crear
                            <?php endif; ?>
                            <?php if ($incompletos): ?><div class="num--neg"><?= $incompletos ?> partidos con un solo ticket</div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <!-- ===== Crear partidos y tickets ===== -->
    <section class="tarjeta" aria-labelledby="t-crear">
        <h2 id="t-crear" class="tarjeta__titulo">Crear partidos y tickets</h2>
        <p class="texto-suave">Cada partido crea 2 tickets (uno por equipo, con el otro como rival). Los cruces que ya existen no se repiten.</p>
        <?php foreach ($cat['categorias'] as $k => $c): if (count($c['equipos']) < 2) continue; ?>
        <details class="fin-det fin-det--caja">
            <summary><b><?= e($c['nombre']) ?></b> · <?= e($c['ruta']) ?> · <?= count($c['equipos']) ?> equipos</summary>
            <?php if (!$c['relampago']): ?>
            <form method="post" action="finanzas-accion.php" class="fin-form">
                <h3 class="fin-form__titulo">Generar rol todos contra todos</h3>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="generar_rol">
                <input type="hidden" name="categoria" value="<?= e($k) ?>">
                <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
                <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <fieldset class="fin-form__ancho fin-equipos"><legend>Equipos que juegan</legend>
                    <?php foreach ($c['equipos'] as $id => $n): ?>
                        <label class="casilla"><input type="checkbox" name="equipos[]" value="<?= e($id) ?>" checked> <?= e($n) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="campo"><span>Concepto</span><input type="text" name="concepto" maxlength="150" required value="Arbitraje"></label>
                <label class="campo"><span>Importe por equipo</span><input type="text" inputmode="decimal" name="importe" required value="<?= e($tarifaArb) ?>"></label>
                <label class="campo"><span>Primera jornada</span><input type="number" name="jornada_inicial" min="1" max="400" value="1"></label>
                <label class="campo"><span>Fecha de la primera jornada <em>(opcional)</em></span><input type="date" name="fecha"></label>
                <label class="campo"><span>Días entre jornadas</span><input type="number" name="intervalo" min="0" max="60" value="7"></label>
                <label class="casilla"><input type="checkbox" name="visible" value="1"> Visibles para los capitanes</label>
                <div class="fin-form__botones"><button type="submit" class="btn btn--pri" data-confirmar="Se crearán los partidos que falten con sus tickets. ¿Continuar?">Generar rol</button></div>
            </form>
            <?php endif; ?>
            <form method="post" action="finanzas-accion.php" class="fin-form">
                <h3 class="fin-form__titulo"><?= $c['relampago'] ? 'Crear partido de eliminación directa' : 'Crear un partido suelto' ?></h3>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="crear_partido">
                <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
                <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <label class="campo"><span>Equipo</span><select name="equipo_a" required><option value="">Elegir…</option>
                    <?php foreach ($c['equipos'] as $id => $n): ?><option value="<?= e($id) ?>"><?= e($n) ?></option><?php endforeach; ?></select></label>
                <label class="campo"><span>Rival</span><select name="equipo_b" required><option value="">Elegir…</option>
                    <?php foreach ($c['equipos'] as $id => $n): ?><option value="<?= e($id) ?>"><?= e($n) ?></option><?php endforeach; ?></select></label>
                <label class="campo"><span><?= $c['relampago'] ? 'Ronda (número)' : 'Jornada' ?></span><input type="number" name="jornada" min="1" max="400" required value="1"></label>
                <?php if ($c['relampago']): ?>
                <label class="campo"><span>Nombre de la ronda</span>
                    <select name="ronda"><option>Cuartos de final</option><option>Semifinal</option><option>Final</option><option>Tercer lugar</option><option>Octavos de final</option><option value="">Otra</option></select></label>
                <?php endif; ?>
                <label class="campo"><span>Fecha <em>(opcional)</em></span><input type="date" name="fecha"></label>
                <label class="campo"><span>Concepto</span><input type="text" name="concepto" maxlength="150" required value="Arbitraje"></label>
                <label class="campo"><span>Importe por equipo</span><input type="text" inputmode="decimal" name="importe" required value="<?= e($tarifaArb) ?>"></label>
                <label class="casilla"><input type="checkbox" name="visible" value="1"> Visibles para los capitanes</label>
                <div class="fin-form__botones"><button type="submit" class="btn btn--sec">Crear partido</button></div>
            </form>
        </details>
        <?php endforeach; ?>
    </section>

    <!-- ===== Lista de tickets ===== -->
    <section class="tarjeta" aria-labelledby="t-lista">
        <h2 id="t-lista" class="tarjeta__titulo">Tickets</h2>
        <form method="get" action="tickets.php" class="fin-filtros">
            <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
            <label class="campo"><span>Categoría</span><select name="cat"><option value="">Todas</option>
                <?php foreach ($cat['categorias'] as $k => $c): ?><option value="<?= e($k) ?>" <?= $f['cat'] === $k ? 'selected' : '' ?>><?= e($c['nombre'] . ' · ' . $c['ruta']) ?></option><?php endforeach; ?></select></label>
            <label class="campo campo--corto"><span>Jornada</span><input type="number" name="jornada" min="0" max="400" value="<?= e($f['jornada']) ?>"></label>
            <label class="campo"><span>Equipo</span><select name="equipo"><option value="">Todos</option>
                <?php foreach ($cat['equipos'] as $id => $x): if ($f['cat'] !== '' && $x['cat'] !== $f['cat']) continue; ?><option value="<?= e($id) ?>" <?= $f['equipo'] === $id ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Estado</span><select name="estado"><option value="">Todos</option>
                <?php foreach (FP_ESTADOS_TICKET as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
            <label class="campo"><span>Capitán</span><select name="vis"><option value="">Todos</option><option value="1" <?= $f['vis'] === '1' ? 'selected' : '' ?>>Visibles</option><option value="0" <?= $f['vis'] === '0' ? 'selected' : '' ?>>Ocultos</option></select></label>
            <div class="campo campo--boton"><button type="submit" class="btn btn--sec btn--chico">Filtrar</button></div>
        </form>

        <?php if (!$tickets): ?>
            <p class="texto-suave">No hay tickets con esos filtros.</p>
        <?php else: $tot = array_sum(array_map(fn($t) => $t['estado'] === 'cancelado' ? 0 : (float)$t['importe'], $tickets)); ?>
        <form method="post" action="finanzas-accion.php">
            <?= csrf_campo() ?>
            <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <div class="fin-acciones">
                <label class="campo"><span>Pasar seleccionados a</span>
                    <select name="estado"><?php foreach (FP_ESTADOS_TICKET as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === 'por_cobrar' ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
                <label class="campo"><span>Fecha de cobro <em>(si es cobrado)</em></span><input type="date" name="fecha_cobro"></label>
                <button type="submit" name="accion" value="tickets_estado" class="btn btn--sec btn--chico" data-confirmar="¿Cambiar el estado de los tickets seleccionados?">Aplicar estado</button>
                <button type="submit" name="accion" value="tickets_visible" class="btn btn--sec btn--chico">Hacer visibles</button>
                <button type="submit" name="accion" value="tickets_ocultar" class="btn btn--sec btn--chico">Ocultar</button>
            </div>
            <div class="tabla-scroll">
            <table class="fin-tabla fin-tabla--tickets">
                <thead><tr>
                    <th scope="col"><input type="checkbox" data-todos="ids[]" aria-label="Seleccionar todos"></th>
                    <th scope="col" class="num">J</th><th scope="col">Equipo</th><th scope="col">Rival</th><th scope="col">Fecha</th>
                    <th scope="col">Concepto</th><th scope="col" class="num">Importe</th><th scope="col">Estado</th><th scope="col">Capitán</th><th scope="col"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr class="<?= $t['estado'] === 'cancelado' ? 'fila--off' : '' ?>">
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" aria-label="Ticket <?= (int)$t['id'] ?>"></td>
                        <td class="num"><?= e($t['jornada'] ?? '—') ?></td>
                        <td><?= e($t['equipo_actual']) ?></td>
                        <td><?= $t['rival_actual'] !== '' ? e($t['rival_actual']) : '<span class="et et-alerta">Sin rival</span>' ?></td>
                        <td><?= e(fecha_corta($t['fecha_estimada'])) ?></td>
                        <td><?= e($t['concepto']) ?></td>
                        <td class="num"><?= e(mxn((float)$t['importe'])) ?></td>
                        <td><span class="et <?= e($etqEstado[$t['estado']] ?? '') ?>"><?= e(FP_ESTADOS_TICKET[$t['estado']] ?? $t['estado']) ?></span>
                            <?php if ($t['estado'] === 'cobrado' && $t['fecha_cobro']): ?><small class="texto-suave"><?= e(fecha_corta($t['fecha_cobro'])) ?></small><?php endif; ?></td>
                        <td><?= (int)$t['visible_capitan'] ? 'Visible' : '<span class="texto-suave">Oculto</span>' ?></td>
                        <td><a class="btn-texto" href="tickets.php?<?= e(($qs ? $qs . '&' : '') . 'editar=' . (int)$t['id']) ?>#editar">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th scope="row" colspan="6"><?= count($tickets) ?> tickets · total sin cancelados</th><td class="num"><?= e(mxn($tot)) ?></td><td colspan="3"></td></tr></tfoot>
            </table>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
