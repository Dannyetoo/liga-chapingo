<?php
/**
 * Liga Chapingo — Panel ADMINISTRATIVO de canchas.
 * Catálogo + disponibilidad semanal + excepciones + resumen.
 * Las secciones de disponibilidad son las mismas vistas que usan los equipos.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

requerir_admin();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$semestre     = obtener_semestre_activo();
$instaladas   = canchas_instaladas();
$canchas      = $instaladas ? listar_canchas() : [];
$canchaId     = entero_positivo($_GET['cancha'] ?? null);
$cancha       = ($instaladas && $canchaId) ? obtener_cancha($canchaId) : null;
$mensajes     = tomar_flash();
$formNueva    = tomar_formulario('cancha_nueva') ?? ['nombre' => '', 'ubicacion' => ''];
$tituloPagina = 'Canchas';
$paginaActual = 'canchas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Canchas · Administración · Liga Chapingo (pruebas)</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=3">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=2">
</head>
<body>

<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>

<main class="contenedor">

    <?php foreach ($mensajes as $m): ?>
        <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert">
            <?php if (count($m['mensajes']) === 1): ?>
                <?= e($m['mensajes'][0]) ?>
            <?php else: ?>
                <strong><?= e($m['mensajes'][0]) ?></strong>
                <ul><?php foreach (array_slice($m['mensajes'], 1) as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$instaladas): ?>
        <div class="aviso aviso--error" role="alert">
            <strong>Las canchas aún no están instaladas.</strong>
            Ejecuta <code>sql/fase4-canchas.sql</code> en la base de pruebas desde phpMyAdmin y recarga esta página.
        </div>
    <?php endif; ?>

    <?php if (!$semestre): ?>
        <div class="aviso aviso--error" role="alert">
            <strong>No hay un semestre activo.</strong> Puedes administrar el catálogo de canchas, pero no registrar su disponibilidad.
        </div>
    <?php endif; ?>

<?php if ($instaladas): ?>

    <!-- 1. CATÁLOGO -->
    <section class="tarjeta" id="catalogo">
        <h2 class="tarjeta__titulo"><span class="num">1</span> Canchas</h2>

        <?php if (!$canchas): ?>
            <p class="texto-suave">Todavía no hay canchas. Crea la primera abajo.</p>
        <?php else: ?>
            <ul class="lista-canchas">
                <?php foreach ($canchas as $c): $sel = $cancha && (int)$cancha['id'] === (int)$c['id']; ?>
                <li class="cancha-fila <?= $sel ? 'is-sel' : '' ?> <?= (int)$c['activo'] === 1 ? '' : 'is-inactiva' ?>">
                    <a class="cancha-fila__nombre" href="canchas.php?cancha=<?= (int)$c['id'] ?>#semanal" <?= $sel ? 'aria-current="true"' : '' ?>>
                        <b><?= e($c['nombre']) ?></b>
                        <?php if (!empty($c['ubicacion'])): ?><small><?= e($c['ubicacion']) ?></small><?php endif; ?>
                    </a>
                    <span class="etiqueta <?= (int)$c['activo'] === 1 ? 'etiqueta--disponibilidad_extra' : 'etiqueta--no_disponible' ?>">
                        <?= (int)$c['activo'] === 1 ? 'Activa' : 'Inactiva' ?>
                    </span>
                    <form method="post" action="canchas-accion.php" class="js-confirmar"
                          data-confirmar="<?= (int)$c['activo'] === 1
                              ? e('¿Desactivar ' . $c['nombre'] . '? Ya no aparecerá para buscar horarios (sus datos se conservan).')
                              : e('¿Activar ' . $c['nombre'] . '?') ?>">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="estado">
                        <input type="hidden" name="cancha_id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="activo" value="<?= (int)$c['activo'] === 1 ? '0' : '1' ?>">
                        <button type="submit" class="btn-texto <?= (int)$c['activo'] === 1 ? 'btn-texto--peligro' : '' ?>">
                            <?= (int)$c['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                        </button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3 class="subtitulo" id="nueva">Nueva cancha</h3>
        <form method="post" action="canchas-accion.php" class="form-nueva" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="rejilla-coinc">
                <label class="campo">
                    <span>Nombre</span>
                    <input type="text" name="nombre" maxlength="100" required placeholder="Ej. Preparatoria, Gimnasio, Cancha 2" value="<?= e($formNueva['nombre']) ?>">
                </label>
                <label class="campo">
                    <span>Ubicación <em>(opcional)</em></span>
                    <input type="text" name="ubicacion" maxlength="150" placeholder="Ej. junto al edificio principal" value="<?= e($formNueva['ubicacion']) ?>">
                </label>
                <div class="campo campo--boton">
                    <button type="submit" class="btn btn--sec">Crear cancha</button>
                </div>
            </div>
        </form>
    </section>

    <?php if ($canchaId && !$cancha): ?>
        <div class="aviso aviso--error">La cancha seleccionada no existe.</div>
    <?php endif; ?>

    <?php if ($cancha): ?>
        <div class="equipo-activo equipo-activo--cancha">
            <div class="equipo-activo__nombre"><?= e($cancha['nombre']) ?></div>
            <div class="equipo-activo__cap">
                <?= !empty($cancha['ubicacion']) ? e($cancha['ubicacion']) . ' · ' : '' ?>
                <?= (int)$cancha['activo'] === 1 ? 'Activa' : 'Inactiva — no se usa para buscar horarios' ?>
            </div>
        </div>

        <?php
        $V = vista_config('cancha', $cancha, $semestre,
            array_fill_keys(['semana', 'bloque', 'excepcion', 'eliminar_excepcion'], 'canchas-accion.php'),
            [
                'semana'             => ['accion' => 'guardar_semana'],
                'bloque'             => ['accion' => 'eliminar_bloque'],
                'excepcion'          => ['accion' => 'guardar_excepcion'],
                'eliminar_excepcion' => ['accion' => 'eliminar_excepcion'],
            ]
        );
        require __DIR__ . '/../vistas/semana.php';
        require __DIR__ . '/../vistas/excepciones.php';
        require __DIR__ . '/../vistas/resumen.php';
        ?>
    <?php elseif ($canchas): ?>
        <p class="texto-suave">Selecciona una cancha para registrar su disponibilidad.</p>
    <?php endif; ?>

<?php endif; ?>

</main>

<footer class="pie contenedor">Liga Chapingo · administración · entorno de pruebas</footer>
<script src="../assets/disponibilidad.js?v=3" defer></script>
</body>
</html>
