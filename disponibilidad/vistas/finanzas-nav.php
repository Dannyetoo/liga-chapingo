<?php
/**
 * Barra de Finanzas: edición + pestañas. Espera $ctx (fin_contexto_pagina) y $vistaFin.
 * Conserva los filtros de la página en el selector de edición sólo si se pasan en $extraGet.
 */
$pestanas = [
    'resumen'       => ['finanzas.php', 'Resumen'],
    'tickets'       => ['tickets.php', 'Tickets'],
    'partidos'      => ['partidos.php', 'Partidos y gastos'],
    'gastos'        => ['gastos.php', 'Gastos generales'],
    'categorias'    => ['finanzas.php?vista=categorias', 'Por categoría'],
    'inscripciones' => ['finanzas.php?vista=inscripciones', 'Inscripciones'],
    'arbitrajes'    => ['finanzas.php?vista=arbitrajes', 'Arbitrajes'],
    'configuracion' => ['configuracion.php', 'Configuración'],
];
$paginaForm = strtok($pestanas[$vistaFin][0], '?');
$sep = fn(string $u) => strpos($u, '?') === false ? '?' : '&';
?>
<div class="fin-barra">
    <form method="get" action="<?= e($paginaForm) ?>" class="fin-edicion">
        <?php if (strpos($pestanas[$vistaFin][0], 'vista=') !== false): ?><input type="hidden" name="vista" value="<?= e($vistaFin) ?>"><?php endif; ?>
        <label for="edicion" class="sr-only">Edición</label>
        <select id="edicion" name="edicion">
            <?php foreach ($ctx['ediciones'] as $x): ?>
                <option value="<?= e($x['id']) ?>" <?= $x['id'] === $ctx['edicion'] ? 'selected' : '' ?>><?= e($x['nombre']) ?><?= $x['con_datos'] ? '' : ' (sin movimientos)' ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--sec btn--chico">Ver</button>
    </form>
    <p class="fin-fuente">Tesorería al <?= e(fecha_hora_mx((string)($ctx['datos']['actualizado'] ?? ''))) ?> · revisión <?= (int)($ctx['datos']['rev'] ?? 0) ?></p>
</div>
<nav class="fin-tabs" aria-label="Secciones de finanzas">
    <?php foreach ($pestanas as $k => [$u, $txt]): ?>
        <a class="fin-tab" href="<?= e($u . $sep($u) . 'edicion=' . rawurlencode($ctx['edicion'])) ?>" <?= $k === $vistaFin ? 'aria-current="page"' : '' ?>><?= e($txt) ?></a>
    <?php endforeach; ?>
</nav>
<?php foreach (tomar_flash() as $m): ?>
    <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(implode(' ', $m['mensajes'])) ?></div>
<?php endforeach; ?>
