<?php
/**
 * Liga Chapingo — Administración · SEMESTRE ACTIVO.
 * Permite corregir el nombre y las fechas del semestre activo (por ejemplo, si quedaron en
 * «0000-00-00» y se mostraban como «30/11/-0001»). Sólo actualiza la fila del semestre activo;
 * no crea ni borra semestres ni toca la disponibilidad registrada.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/semestre.php';

requerir_admin();
cabeceras_admin();

$semestre = obtener_semestre_activo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $errores = [];
    if (!csrf_valido($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La página expiró. Vuelve a intentarlo.';
    } elseif (!$semestre) {
        $errores[] = 'No hay un semestre activo.';
    } else {
        $nombre = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nombre'] ?? '')) ?? '');
        $ini = (string)($_POST['fecha_inicio'] ?? '');
        $fin = (string)($_POST['fecha_fin'] ?? '');
        $valida = static function (string $f): bool {
            $d = DateTime::createFromFormat('!Y-m-d', $f);
            return $d && $d->format('Y-m-d') === $f && (int)$d->format('Y') >= 2020 && (int)$d->format('Y') <= 2100;
        };
        if ($nombre === '' || mb_strlen($nombre) > 100) $errores[] = 'Escribe un nombre de hasta 100 caracteres.';
        if (!$valida($ini)) $errores[] = 'La fecha de inicio no es válida.';
        if (!$valida($fin)) $errores[] = 'La fecha de fin no es válida.';
        if (!$errores && $fin <= $ini) $errores[] = 'La fecha de fin debe ser posterior a la de inicio.';
        if (!$errores) {
            try {
                db()->prepare('UPDATE semestres SET nombre = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ? AND activo = 1')
                    ->execute([$nombre, $ini, $fin, (int)$semestre['id']]);
                flash('ok', 'Semestre actualizado: ' . $nombre . ' · ' . semestre_fecha_texto($ini) . ' – ' . semestre_fecha_texto($fin) . '.');
            } catch (Throwable $ex) {
                flash('error', error_interno($ex, 'semestre'));
            }
            header('Location: semestre.php', true, 303);
            exit;
        }
    }
    flash('error', $errores);
    header('Location: semestre.php', true, 303);
    exit;
}

$mensajes = tomar_flash();
$tituloPagina = 'Semestre';
$paginaActual = 'semestre';
$fechaOk = static fn($f) => semestre_fecha_texto($f) !== '' ? substr((string)$f, 0, 10) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Semestre · Administración · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <style>
        .form-semestre { display: grid; gap: 12px; grid-template-columns: 1fr; max-width: 640px; margin: 8px 0 12px; }
        @media (min-width: 640px) { .form-semestre { grid-template-columns: 2fr 1fr 1fr; align-items: end; } .form-semestre .btn { grid-column: 1 / -1; justify-self: start; } }
        .form-semestre input { width: 100%; }
    </style>
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor">
<?php foreach ($mensajes as $m): ?>
    <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(implode(' ', $m['mensajes'])) ?></div>
<?php endforeach; ?>
<?php if (!$semestre): ?>
    <div class="aviso aviso--error" role="alert">No hay un semestre activo (semestres.activo = 1). Avisa al administrador del sistema.</div>
<?php else: ?>
    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Semestre activo</h2>
        <?php if (semestre_rango_texto($semestre) === 'Fechas por definir'): ?>
            <div class="aviso aviso--error" role="alert">Las fechas de este semestre no están capturadas correctamente. Escríbelas y guarda.
                Mientras tanto no se pueden registrar excepciones de disponibilidad (las fechas deben quedar dentro del semestre).</div>
        <?php endif; ?>
        <form method="post" action="semestre.php" class="form-semestre">
            <?= csrf_campo() ?>
            <label class="campo"><span>Nombre</span>
                <input type="text" name="nombre" maxlength="100" required value="<?= e((string)$semestre['nombre']) ?>"></label>
            <label class="campo"><span>Fecha de inicio</span>
                <input type="date" name="fecha_inicio" required value="<?= e($fechaOk($semestre['fecha_inicio'])) ?>"></label>
            <label class="campo"><span>Fecha de fin</span>
                <input type="date" name="fecha_fin" required value="<?= e($fechaOk($semestre['fecha_fin'])) ?>"></label>
            <button type="submit" class="btn btn--pri">Guardar semestre</button>
        </form>
        <p class="texto-suave">Sólo se cambia el semestre activo. Los horarios, equipos y cobros registrados no se tocan.</p>
    </section>
<?php endif; ?>
</main>
</body>
</html>
