<?php
/**
 * Liga Chapingo — Finanzas · CONFIGURACIÓN DE LA PROYECCIÓN.
 * Por categoría: equipos esperados, partidos de fase regular y de liguilla, cuota de inscripción,
 * tarifa de arbitraje y costo del árbitro en liguilla. Cada cambio es una fila nueva en
 * fin_parametros (historial); un campo vacío deja el valor como está.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/finanzas-pagina.php';

$ctx = fin_contexto_pagina();
$vistaFin = 'configuracion';
$semestre = obtener_semestre_activo();
$tituloPagina = 'Finanzas · Configuración';
$paginaActual = 'finanzas';
$edicion = $ctx['edicion'];
$cat = ($ctx['datos'] && $edicion !== '') ? fp_catalogo($ctx['S'], $edicion) : null;
$qs = http_build_query(['edicion' => $edicion]);
$volver = 'configuracion.php?' . $qs;

$param = $cat ? fin_parametros_vigentes($edicion) : [];
$tarifas = ($cat && fp_instalado()) ? fp_tarifas($edicion) : [];
$conceptos = $ctx['S']['conceptos'] ?? [];
$tarLiga = fin_tarifa($conceptos, 'inscripcion a liga') ?? 0.0;
$tarRel  = fin_tarifa($conceptos, 'inscripcion a relampago') ?? 0.0;
$tarArb  = fin_tarifa($conceptos, 'arbitraje') ?? 0.0;

/** Valor automático de cada clave para una categoría. */
function valor_auto(string $clave, array $c, float $tarLiga, float $tarRel, float $tarArb, array $tarifas): float
{
    $n = count($c['equipos']);
    switch ($clave) {
        case 'equipos_esperados':            return (float)$n;
        case 'partidos_regulares':           return $c['relampago'] ? 0.0 : (float)intdiv($n * ($n - 1), 2);
        case 'partidos_eliminatoria':        return $c['relampago'] ? (float)max(0, $n - 1) : 0.0;
        case 'cuota_inscripcion':            return $c['relampago'] ? $tarRel : $tarLiga;
        case 'arbitraje_equipo_regular':     return $tarArb;
        case 'arbitraje_equipo_eliminatoria':return $tarArb;
        case 'costo_arbitro_eliminatoria':   return (float)($tarifas['arbitro'] ?? 0);
    }
    return 0.0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Configuración · Finanzas · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
    <link rel="stylesheet" href="../assets/finanzas.css?v=3">
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

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Proyección por categoría</h2>
        <p class="texto-suave">Estos datos alimentan el bloque PROYECTADO del Resumen. Si un campo dice «automático», el sistema lo calcula
            con los equipos de Tesorería (todos contra todos; relámpago: eliminación directa) y las tarifas del Catálogo.
            Escribe un número sólo donde quieras fijar otro valor; lo que dejes vacío no cambia.</p>
        <p class="texto-suave">Tarifas por partido (árbitro, supervisión, administración, coordinación): en
            <a href="partidos.php?<?= e($qs) ?>">Partidos y gastos → Cambiar tarifas</a><?php if ($tarifas): ?> · hoy <?= e(mxn(array_sum($tarifas))) ?> por partido<?php endif; ?>.</p>
    </section>

    <?php
    $CAMPOS = [
        'equipos_esperados'             => ['Equipos esperados', 'equipos', false],
        'partidos_regulares'            => ['Partidos de fase regular', 'partidos', false],
        'partidos_eliminatoria'         => ['Partidos de liguilla / eliminación (incluye 3er lugar)', 'partidos', false],
        'cuota_inscripcion'             => ['Cuota de inscripción por equipo', '$', true],
        'arbitraje_equipo_regular'      => ['Arbitraje por equipo (fase regular)', '$', true],
        'arbitraje_equipo_eliminatoria' => ['Arbitraje por equipo (liguilla)', '$', true],
        'costo_arbitro_eliminatoria'    => ['Pago al árbitro por partido (liguilla)', '$', true],
    ];
    foreach ($cat['categorias'] as $k => $c):
        $P = array_merge($param[''] ?? [], $param[$k] ?? []); ?>
    <section class="tarjeta" aria-label="<?= e($c['nombre']) ?>">
        <h2 class="fin-cat__titulo"><?= e($c['nombre']) ?></h2>
        <p class="texto-suave fin-cat__ruta"><?= e($c['ruta']) ?> · <?= count($c['equipos']) ?> equipos en Tesorería · <?= $c['relampago'] ? 'eliminación directa' : 'todos contra todos' ?></p>
        <form method="post" action="finanzas-accion.php" class="fin-form">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="config_guardar">
            <input type="hidden" name="edicion" value="<?= e($edicion) ?>">
            <input type="hidden" name="categoria" value="<?= e($k) ?>">
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <?php foreach ($CAMPOS as $clave => [$etq, $unidad, $dinero]):
                if ($c['relampago'] && in_array($clave, ['partidos_regulares', 'arbitraje_equipo_regular'], true)) continue;
                $tiene = array_key_exists($clave, $P);
                $actual = $tiene ? (float)$P[$clave] : valor_auto($clave, $c, $tarLiga, $tarRel, $tarArb, $tarifas);
                $txt = $dinero ? mxn($actual) : (string)(int)$actual; ?>
                <label class="campo"><span><?= e($etq) ?></span>
                    <input type="text" inputmode="decimal" name="p[<?= e($clave) ?>]" placeholder="<?= e($txt) ?>">
                    <small class="texto-suave">Actual: <b><?= e($txt) ?></b> · <?= $tiene ? 'fijado' : 'automático' ?></small>
                </label>
            <?php endforeach; ?>
            <div class="fin-form__botones"><button type="submit" class="btn btn--sec">Guardar <?= e($c['nombre']) ?></button></div>
        </form>
    </section>
    <?php endforeach; ?>
<?php endif; ?>
</main>
</body>
</html>
