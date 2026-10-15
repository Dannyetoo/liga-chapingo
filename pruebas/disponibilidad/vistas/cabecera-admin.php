<?php
/**
 * Cabecera común del panel administrativo.
 * Espera: $semestre (?array), $tituloPagina (string), $paginaActual ('coincidencias'|'canchas').
 */
$navAdmin = [
    'equipos'        => ['equipos.php', 'Equipos'],
    'vinculos'       => ['vinculos.php', 'Vínculos'],
    'coincidencias'  => ['coincidencias.php', 'Coincidencias'],
    'canchas'        => ['canchas.php', 'Canchas'],
    'disponibilidad' => ['../index.php', 'Editar disponibilidad'],
];
?>
<div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS · los cambios no afectan ligachapingo.com</div>

<header class="cabecera cabecera--admin">
    <div class="contenedor cabecera__in">
        <div class="marca">
            <span class="marca__logo" aria-hidden="true">LC</span>
            <div>
                <div class="marca__nombre">Liga Chapingo</div>
                <div class="marca__sub">Administración · <?= e($tituloPagina) ?></div>
            </div>
        </div>
        <?php if ($semestre): ?>
            <div class="semestre-chip" title="Semestre activo">
                <?= e($semestre['nombre']) ?>
                <small><?= e(fecha_mx((string)$semestre['fecha_inicio'])) ?> – <?= e(fecha_mx((string)$semestre['fecha_fin'])) ?></small>
            </div>
        <?php endif; ?>
    </div>
    <nav class="contenedor nav-admin" aria-label="Administración">
        <?php foreach ($navAdmin as $k => [$href, $txt]): ?>
            <a href="<?= e($href) ?>" <?= $k === $paginaActual ? 'aria-current="page"' : '' ?>><?= e($txt) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
