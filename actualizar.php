<?php
/**
 * Administración · Instalar la actualización del 29-sep-2026 de «Busca un rival».
 * Crea 4 tablas nuevas y 2 columnas en reservas_cancha. No borra ni cambia datos existentes.
 * Se puede ejecutar varias veces sin problema (si ya está instalada, no hace nada nuevo).
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/rival.php';

requerir_admin();
cabeceras_admin();
$semestre = obtener_semestre_activo();
$tituloPagina = 'Instalar actualización';
$paginaActual = 'rival';
$log = null; $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido($_POST['csrf_token'] ?? null)) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } elseif (!rival_instalado()) {
        $error = 'Primero debe estar instalado «Busca un rival» (rival-PRODUCCION.sql y rival-fase7-PRODUCCION.sql).';
    } else {
        try {
            $log = rival_v8_migrar();
        } catch (Throwable $ex) {
            error_log('[rival actualizar] ' . $ex->getMessage());
            $error = 'No se pudo completar: ' . $ex->getMessage();
        }
    }
}
// (Se consulta después de migrar: la función guarda su resultado sólo durante esta petición.)
$instalada = rival_instalado() && rival_v8_instalado();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Instalar actualización · Administración · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=6">
    <link rel="stylesheet" href="../assets/finanzas.css?v=3">
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin">
    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Actualización 29-sep-2026 · «Busca un rival»</h2>
        <p>Agrega: horarios uno por uno (mín. <?= RIVAL_MIN_HORARIOS ?>, hasta <?= RIVAL_MAX_POR_DIA ?> por día), reprogramación con varios horarios,
           solicitudes de cancelación y reportes, propuestas de la liga, editar/eliminar partidos, cancelar horas de cancha y avisos dentro de la app.</p>
        <p class="texto-suave">Crea las tablas <code>rival_reprog_opciones</code>, <code>rival_propuestas</code>, <code>rival_peticiones</code>, <code>rival_notif_visto</code>, <code>rival_jugados</code>
           y las columnas <code>motivo_cancelacion</code> y <code>cancelada_en</code> en <code>reservas_cancha</code>. <b>No borra ni modifica datos.</b>
           Si alguna columna de estado es de tipo ENUM, sólo le agrega los valores nuevos.</p>
        <?php if ($error): ?><div class="aviso aviso--error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($log !== null): ?>
            <div class="aviso aviso--ok" role="status"><strong>Listo.</strong><ul><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <p>Estado: <b><?= $instalada ? '✓ instalada' : 'pendiente' ?></b></p>
        <?php if (!$instalada): ?>
        <form method="post" action="actualizar.php">
            <?= csrf_campo() ?>
            <button type="submit" class="btn btn--pri">Instalar actualización</button>
        </form>
        <?php else: ?>
            <p><a class="btn btn--pri" href="rival.php">Ir a «Busca un rival» →</a></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
