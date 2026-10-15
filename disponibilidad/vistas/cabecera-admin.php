<?php
require_once __DIR__ . '/../lib/semestre.php';
/**
 * Cabecera común del panel administrativo.
 * Espera: $semestre (?array), $tituloPagina (string), $paginaActual (clave de $navAdmin).
 * Finanzas sólo aparece para usuarios con permiso (la página lo vuelve a comprobar).
 */
$adminActual = admin_en_sesion();
$navAdmin = [
    'equipos'        => ['equipos.php', 'Equipos'],
    'disponibilidad' => ['../index.php', 'Disponibilidad'],
    'coincidencias'  => ['coincidencias.php', 'Coincidencias'],
    'canchas'        => ['canchas.php', 'Canchas'],
    'vinculos'       => ['vinculos.php', 'Vínculos'],
    'rival'          => ['rival.php', 'Busca un rival'],
    'semestre'       => ['semestre.php', 'Semestre'],
    'notificaciones' => ['notificaciones.php', 'Notificaciones'],
];
// Actualización 2026-09-29: solicitudes de capitanes pendientes (cancelaciones y reportes).
if ($adminActual) {
    try {
        $nPetAdm = (int)db()->query("SELECT COUNT(*) FROM rival_peticiones WHERE estado = 'pendiente'")->fetchColumn();
        if ($nPetAdm > 0) $navAdmin['rival'][1] .= ' (' . $nPetAdm . ')';
    } catch (Throwable $ex) {
        // Tabla aún no instalada: sin contador.
    }
}
if ($adminActual && $adminActual['puede_finanzas']) {
    $navAdmin['finanzas'] = ['finanzas.php', 'Finanzas'];
    $navAdmin['usuarios'] = ['usuarios.php', 'Usuarios'];
    // 30-sep-2026: acceso directo al estado de cuenta de cada capitán (con su código).
    $navAdmin['estados'] = ['estados-cuenta.php', 'Estados de cuenta'];
}
// Un solo acceso (30-sep-2026): Tesorería abre con la misma cuenta, sin volver a escribir la contraseña.
if ($adminActual) $navAdmin['tesoreria'] = ['ir-tesoreria.php', 'Tesorería ↗'];
?>
<?php if (es_entorno_pruebas()): ?>
<div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS · los cambios no afectan ligachapingo.com</div>
<?php endif; ?>

<header class="cabecera cabecera--admin">
    <div class="contenedor cabecera__in">
        <div class="marca">
            <span class="marca__logo" aria-hidden="true">LC</span>
            <div>
                <div class="marca__nombre">Liga Chapingo</div>
                <div class="marca__sub">Administración · <?= e($tituloPagina) ?></div>
            </div>
        </div>
        <div class="cabecera__lado">
            <?php if (!empty($semestre)): ?>
                <a class="semestre-chip" href="semestre.php" title="Semestre activo · cambiar fechas" style="color:inherit;text-decoration:none">
                    <?= e($semestre['nombre']) ?>
                    <small><?= e(semestre_rango_texto($semestre)) ?></small>
                </a>
            <?php endif; ?>
            <?php if ($adminActual): ?>
                <form method="post" action="salir.php" class="sesion-admin">
                    <?= csrf_campo() ?>
                    <span class="sesion-admin__nombre" title="Sesión de administración"><?= e($adminActual['nombre']) ?></span>
                    <button type="submit" class="sesion-admin__salir">Salir</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <nav class="contenedor nav-admin" aria-label="Administración">
        <?php foreach ($navAdmin as $k => [$href, $txt]): ?>
            <a href="<?= e($href) ?>" <?= $k === $paginaActual ? 'aria-current="page"' : '' ?>><?= e($txt) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
