<?php
/**
 * Liga Chapingo — Disponibilidad del equipo
 *
 * Dos modos, decididos SÓLO por contexto_acceso() en el servidor:
 *   capitán → su equipo sale de la sesión abierta desde el Estado de cuenta. Sin selector;
 *             ?equipo= se ignora.
 *   admin   → (hoy sólo en pruebas) selector de equipos, como en fases anteriores.
 *   ninguno → aviso "entra desde tu Estado de cuenta"; no se muestra ni guarda nada.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/semestre.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

header('Cache-Control: no-store');

$ctx        = contexto_acceso();
$modo       = $ctx['rol'];                      // 'capitan' | 'admin' | null
$semestre   = obtener_semestre_activo();
$mensajes   = tomar_flash();
$equipos    = [];
$equipoId   = null;
$equipo     = null;

if ($modo === 'capitan') {
    // Para los capitanes, «Busca un rival» sustituye a la disponibilidad semanal.
    header('Location: rival.php', true, 303);
    exit;
}
if ($modo === 'capitan') {
    $equipo   = obtener_equipo((int)$ctx['equipo_id']);   // ← de la sesión, nunca de la URL
    $equipoId = $equipo ? (int)$equipo['id'] : null;
} elseif ($modo === 'admin') {
    $equipos  = listar_equipos();
    $equipoId = entero_positivo($_GET['equipo'] ?? null);
    $equipo   = $equipoId ? obtener_equipo($equipoId) : null;
} else {
    http_response_code(403);
}
$retorno = $modo === 'capitan' ? url_retorno_segura((string)$ctx['retorno']) : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $equipo && $modo === 'capitan' ? e($equipo['nombre']) . ' · ' : '' ?>Disponibilidad del equipo · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="assets/disponibilidad.css?v=5">
</head>
<body class="modo-<?= e($modo ?? 'anonimo') ?>">

<?php if (es_entorno_pruebas()): ?>
<div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS<?= $modo === 'capitan' && $ctx['origen'] === 'simulacion' ? ' · simulando capitán' : ' · los cambios no afectan ligachapingo.com' ?></div>
<?php endif; ?>

<header class="cabecera">
    <div class="contenedor cabecera__in">
        <div class="marca">
            <span class="marca__logo" aria-hidden="true">LC</span>
            <div>
                <div class="marca__nombre">Liga Chapingo</div>
                <div class="marca__sub">Disponibilidad del equipo</div>
            </div>
        </div>
        <?php if ($semestre): ?>
            <div class="semestre-chip" title="Semestre activo">
                <?= e($semestre['nombre']) ?>
                <small><?= e(semestre_rango_texto($semestre)) ?></small>
            </div>
        <?php endif; ?>
    </div>
</header>

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

    <?php if (!$semestre && $modo !== null): ?>
        <div class="aviso aviso--error" role="alert">
            <strong>No hay un semestre activo.</strong>
            <?php if ($modo === 'capitan'): ?>
                Por ahora no se puede registrar disponibilidad. Intenta más tarde.
            <?php else: ?>
                Para registrar disponibilidad, un administrador debe marcar un semestre con <code>activo = 1</code> en la tabla <code>semestres</code>.
                Mientras tanto no se permite guardar información.
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php if ($modo === null): ?>
    <section class="tarjeta tarjeta--centro">
        <h2 class="tarjeta__titulo">Entra desde tu Estado de cuenta</h2>
        <p>Para registrar la disponibilidad de tu equipo, abre tu <strong>Estado de cuenta</strong> y pulsa <strong>«Disponibilidad del equipo»</strong>.</p>
        <p class="texto-suave">Si ya lo hiciste, tu sesión pudo haber expirado por inactividad.</p>
        <a class="btn btn--pri" href="<?= e(url_retorno_segura(CAPITAN_RETORNO_DEF)) ?>">Ir a mi estado de cuenta</a>
        <p class="texto-suave"><a href="admin/entrar.php">Acceso de administración</a></p>
    </section>

<?php elseif ($modo === 'capitan'): ?>
    <!-- CAPITÁN: el equipo viene de la sesión -->
    <section class="capitan-hero">
        <div class="capitan-hero__eyebrow">Disponibilidad del equipo</div>
        <h1 class="capitan-hero__equipo"><?= e($equipo['nombre'] ?? '') ?></h1>
        <p class="capitan-hero__texto">Indica cuándo puede jugar tu equipo. Estos horarios servirán para encontrar posibles partidos compatibles.</p>
        <p class="capitan-hero__nota">Tu disponibilidad indica los horarios en los que tu equipo puede recibir propuestas de partido. <strong>Registrar un horario disponible no programa automáticamente un encuentro.</strong></p>
        <form method="post" action="salir.php" class="form-volver"><?= csrf_campo() ?><button type="submit" class="btn-volver">← Volver a mi estado de cuenta</button></form>
    </section>

<?php else: ?>
    <!-- 1. EQUIPO (sólo administración / pruebas) -->
    <section class="tarjeta" id="equipo">
        <h2 class="tarjeta__titulo"><span class="num">1</span> Equipo <small class="chip-admin">administración</small></h2>
        <form method="get" action="index.php" class="selector-equipo">
            <label for="sel-equipo" class="sr-only">Equipo</label>
            <select name="equipo" id="sel-equipo" data-autosubmit>
                <option value="">— Selecciona un equipo —</option>
                <?php foreach ($equipos as $eq): ?>
                    <option value="<?= (int)$eq['id'] ?>" <?= $equipo && (int)$equipo['id'] === (int)$eq['id'] ? 'selected' : '' ?>>
                        <?= e($eq['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--sec js-ocultar">Ver</button>
        </form>
        <?php if ($equipo): ?>
            <div class="equipo-activo">
                <div class="equipo-activo__nombre"><?= e($equipo['nombre']) ?></div>
                <?php if (!empty($equipo['capitan_nombre'])): ?>
                    <div class="equipo-activo__cap">Capitán: <?= e($equipo['capitan_nombre']) ?></div>
                <?php endif; ?>
            </div>
        <?php elseif ($equipoId): ?>
            <p class="texto-suave">Ese equipo no existe o no está activo.</p>
        <?php else: ?>
            <p class="texto-suave">Elige un equipo para ver y editar su disponibilidad. Los capitanes no ven este selector: entran desde su Estado de cuenta.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($equipo): ?>

    <?php
    $esCapitan = $modo === 'capitan';
    $V = vista_config('equipo', $equipo, $semestre, [
        'semana' => 'guardar-disponibilidad.php', 'bloque' => 'eliminar-bloque.php',
        'excepcion' => 'guardar-excepcion.php', 'eliminar_excepcion' => 'eliminar-excepcion.php',
    ], [], $esCapitan ? [1, 2, 3] : [2, 3, 4]);
    $V['sin_id']      = $esCapitan;          // el capitán no envía equipo_id: lo pone el servidor
    $V['exc_plegado'] = $esCapitan;          // "¿Hay algún día en que no puedas jugar?" → [Agregar excepción]
    $V['capitan']     = $esCapitan;
    require __DIR__ . '/vistas/semana.php';
    require __DIR__ . '/vistas/excepciones.php';
    require __DIR__ . '/vistas/resumen.php';
    ?>

    <?php if ($modo === 'capitan'): ?>
        <div class="salida-capitan">
            <form method="post" action="salir.php" class="form-volver">
                <?= csrf_campo() ?>
                <button type="submit" class="btn btn--sec btn--ancho">
                    <?= $ctx['origen'] === 'simulacion' && es_entorno_pruebas() ? 'Salir de la simulación (pruebas)' : '← Volver a mi estado de cuenta' ?>
                </button>
            </form>
            <p class="texto-suave">Tus horarios quedan guardados. Para volver aquí, pulsa otra vez «Disponibilidad del equipo» en tu Estado de cuenta.</p>
        </div>
    <?php endif; ?>

<?php endif; ?>

</main>

<footer class="pie contenedor">
    Liga Chapingo · disponibilidad del equipo<?= es_entorno_pruebas() ? ' · entorno de pruebas' : '' ?>
    <?php if ($modo === 'admin'): ?>
        · <a href="admin/equipos.php">Administración</a>
    <?php endif; ?>
</footer>

<script src="assets/disponibilidad.js?v=4" defer></script>
</body>
</html>
