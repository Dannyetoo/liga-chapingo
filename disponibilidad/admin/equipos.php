<?php
/**
 * Liga Chapingo — Administración: disponibilidad registrada por equipo.
 * Sirve para saber qué capitanes todavía deben registrar sus horarios.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

requerir_admin();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$semestre     = obtener_semestre_activo();
$mensajes     = tomar_flash();
$filas        = $semestre ? resumen_registro_equipos((int)$semestre['id']) : [];
$total        = count($filas);
$registradas  = count(array_filter($filas, fn($f) => $f['registrada']));
$tituloPagina = 'Equipos';
$paginaActual = 'equipos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Equipos · Administración · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=4">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor">

    <?php foreach ($mensajes as $m): ?>
        <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(implode(' ', $m['mensajes'])) ?></div>
    <?php endforeach; ?>

    <?php if (!$semestre): ?>
        <div class="aviso aviso--error" role="alert"><strong>No hay un semestre activo.</strong></div>
    <?php else: ?>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Disponibilidad registrada</h2>
        <p class="texto-suave"><?= e($semestre['nombre']) ?> · un equipo cuenta como registrado cuando tiene al menos un horario semanal.</p>

        <div class="cifras">
            <div class="cifra"><b><?= $total ?></b><span>equipos totales</span></div>
            <div class="cifra"><b><?= $registradas ?></b><span>con disponibilidad</span></div>
            <div class="cifra"><b><?= $total - $registradas ?></b><span>pendientes</span></div>
            <div class="cifra"><b><?= $total ? round($registradas * 100 / $total) : 0 ?>%</b><span>avance</span></div>
        </div>
        <div class="barra-avance" role="img" aria-label="<?= $registradas ?> de <?= $total ?> equipos registrados">
            <span style="width: <?= $total ? round($registradas * 100 / $total, 1) : 0 ?>%"></span>
        </div>

        <ul class="lista-registro">
            <?php foreach ($filas as $f): ?>
            <li class="registro <?= $f['registrada'] ? 'registro--ok' : 'registro--pend' ?>">
                <span class="registro__marca" aria-hidden="true"><?= $f['registrada'] ? '✓' : '○' ?></span>
                <div class="registro__info">
                    <b><?= e($f['nombre']) ?></b>
                    <small>
                        <?php if ($f['registrada']): ?>
                            <?= (int)$f['dias'] ?> día<?= (int)$f['dias'] === 1 ? '' : 's' ?> · <?= (int)$f['bloques'] ?> horario<?= (int)$f['bloques'] === 1 ? '' : 's' ?>
                            <?= (int)$f['excepciones'] ? ' · ' . (int)$f['excepciones'] . ((int)$f['excepciones'] === 1 ? ' excepción' : ' excepciones') : '' ?>
                            <?= $f['ultima'] ? ' · actualizado ' . e(fecha_mx((string)$f['ultima'])) : '' ?>
                        <?php else: ?>
                            Pendiente<?= !empty($f['capitan_nombre']) ? ' · capitán: ' . e($f['capitan_nombre']) : '' ?>
                        <?php endif; ?>
                    </small>
                </div>
                <a class="btn-texto" href="../index.php?equipo=<?= (int)$f['id'] ?>">Ver</a>
                <?php if (es_entorno_pruebas()): ?>
                <form method="post" action="simular-capitan.php">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="equipo_id" value="<?= (int)$f['id'] ?>">
                    <button type="submit" class="btn-texto" title="Abre /disponibilidad/ tal como la vería el capitán">Ver como capitán</button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</main>
<footer class="pie contenedor">Liga Chapingo · administración<?= es_entorno_pruebas() ? ' · entorno de pruebas' : '' ?></footer>
</body>
</html>
