<?php
/**
 * Liga Chapingo — Panel ADMINISTRATIVO: coincidencias Equipo A ∩ Equipo B (∩ Cancha).
 * Herramienta de análisis: sólo lee datos, no guarda partidos.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/coincidencias.php';

requerir_admin();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$semestre = obtener_semestre_activo();
$equipos  = listar_equipos();
$canchasInstaladas = canchas_instaladas();
$canchas  = $canchasInstaladas ? listar_canchas(true) : [];
$tituloPagina = 'Coincidencias';
$paginaActual = 'coincidencias';
$buscar   = isset($_GET['buscar']);

// Valores por defecto: una semana desde hoy (o desde el inicio del semestre).
$hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
$defDesde = $hoy;
if ($semestre) {
    $sIni = substr((string)$semestre['fecha_inicio'], 0, 10);
    $sFin = substr((string)$semestre['fecha_fin'], 0, 10);
    if ($defDesde < $sIni || $defDesde > $sFin) $defDesde = $sIni;
}
$defHasta = (new DateTimeImmutable($defDesde))->modify('+6 days')->format('Y-m-d');
if ($semestre && $defHasta > $sFin) $defHasta = $sFin;

$f = [
    'equipo_a' => (string)($_GET['equipo_a'] ?? ''),
    'equipo_b' => (string)($_GET['equipo_b'] ?? ''),
    'cancha'   => (string)($_GET['cancha'] ?? ''),
    'desde'    => (string)($_GET['desde'] ?? $defDesde),
    'hasta'    => (string)($_GET['hasta'] ?? $defHasta),
    'duracion' => (string)($_GET['duracion'] ?? '60'),
    'paso'     => (string)($_GET['paso'] ?? '30'),
];

$errores = [];
$res = null;
$p = null;
if ($buscar) {
    [$p, $errores] = validarConsultaCoincidencias($f, $semestre);
    if ($p) {
        try {
            $res = buscarCoincidenciasEquipos($p);
        } catch (Throwable $ex) {
            $errores[] = error_interno($ex, 'coincidencias');
        }
    }
}

/** Texto de la disponibilidad efectiva de una fuente en una fecha. */
function texto_fuente(array $fu): string
{
    if ($fu['bloques']) return intervalos_texto($fu['bloques']);
    return $fu['por_excepcion'] ? 'No disponible por excepción registrada' : 'Sin disponibilidad';
}

function pintar_detalle(array $dia): void
{
    echo '<div class="detalle">';
    foreach ($dia['fuentes'] as $fu) {
        echo '<div class="detalle__fila' . ($fu['tipo'] === 'cancha' ? ' detalle__fila--cancha' : '') . '"><span class="detalle__eq">'
           . e($fu['nombre']) . ($fu['tipo'] === 'cancha' ? ' <small>cancha</small>' : '') . '</span>'
           . '<span class="detalle__horas' . ($fu['bloques'] ? '' : ' detalle__horas--no') . '">' . e(texto_fuente($fu)) . '</span>';
        foreach ($fu['notas'] as $n) echo '<span class="detalle__nota">' . e($n) . '</span>';
        echo '</div>';
    }
    $hayRecurso = count(array_filter($dia['fuentes'], fn($x) => $x['tipo'] !== 'equipo')) > 0;
    if ($hayRecurso) {
        echo '<div class="detalle__fila detalle__fila--res"><span class="detalle__eq">Equipos</span><span class="detalle__horas">'
           . e($dia['comun_equipos'] ? 'Coinciden ' . intervalos_texto($dia['comun_equipos']) : 'Sin coincidencia') . '</span></div>';
    }
    echo '<div class="detalle__fila detalle__fila--res"><span class="detalle__eq">' . ($hayRecurso ? 'Con cancha' : 'Resultado') . '</span><span class="detalle__horas">'
       . e($dia['comun'] ? 'Coinciden ' . intervalos_texto($dia['comun']) : 'Sin coincidencia') . '</span></div>';
    echo '</div>';
}

$conSlots = $sinCoinc = [];
if ($res) {
    foreach ($res['fechas'] as $dia) {
        if ($dia['estado'] === 'con_slots') $conSlots[] = $dia; else $sinCoinc[] = $dia;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Coincidencias · Administración · Liga Chapingo (pruebas)</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=3">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=2">
</head>
<body>

<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>

<main class="contenedor">

    <?php if (!$semestre): ?>
        <div class="aviso aviso--error" role="alert"><strong>No hay un semestre activo.</strong> Activa un semestre en la tabla <code>semestres</code> para consultar coincidencias.</div>
    <?php endif; ?>

    <?php if ($errores): ?>
        <div class="aviso aviso--error" role="alert">
            <strong>No se pudo realizar la búsqueda:</strong>
            <ul><?php foreach ($errores as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Buscar horarios compatibles</h2>
        <p class="texto-suave">Herramienta de análisis: cruza la disponibilidad semanal y las excepciones de ambos equipos y, si eliges una, de la cancha. No guarda ningún partido.</p>

        <form method="get" action="coincidencias.php" class="form-coinc">
            <input type="hidden" name="buscar" value="1">
            <div class="rejilla-coinc">
                <label class="campo">
                    <span>Equipo A</span>
                    <select name="equipo_a" required>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= (int)$eq['id'] ?>" <?= $f['equipo_a'] === (string)$eq['id'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="campo">
                    <span>Equipo B</span>
                    <select name="equipo_b" required>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= (int)$eq['id'] ?>" <?= $f['equipo_b'] === (string)$eq['id'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="campo">
                    <span>Cancha</span>
                    <select name="cancha" <?= $canchasInstaladas ? '' : 'disabled' ?>>
                        <option value="">— Sin cancha (sólo equipos) —</option>
                        <?php foreach ($canchas as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $f['cancha'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="campo">
                    <span>Fecha inicial</span>
                    <input type="date" name="desde" required value="<?= e($f['desde']) ?>"
                        <?php if ($semestre): ?>min="<?= e($sIni) ?>" max="<?= e($sFin) ?>"<?php endif; ?>>
                </label>
                <label class="campo">
                    <span>Fecha final</span>
                    <input type="date" name="hasta" required value="<?= e($f['hasta']) ?>"
                        <?php if ($semestre): ?>min="<?= e($sIni) ?>" max="<?= e($sFin) ?>"<?php endif; ?>>
                </label>
                <label class="campo">
                    <span>Duración del partido</span>
                    <select name="duracion">
                        <?php
                        $durs = COINC_DURACIONES;
                        if (ctype_digit($f['duracion']) && !in_array((int)$f['duracion'], $durs, true)) $durs[] = (int)$f['duracion'];
                        sort($durs);
                        foreach ($durs as $d): ?>
                            <option value="<?= (int)$d ?>" <?= $f['duracion'] === (string)$d ? 'selected' : '' ?>><?= (int)$d ?> minutos</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="campo">
                    <span>Intervalo de inicio</span>
                    <select name="paso">
                        <?php foreach (COINC_PASOS as $d): ?>
                            <option value="<?= (int)$d ?>" <?= $f['paso'] === (string)$d ? 'selected' : '' ?>>cada <?= (int)$d ?> minutos</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="texto-suave">Periodo máximo por consulta: <?= COINC_MAX_DIAS ?> días, dentro del semestre activo.
                <?php if (!$canchasInstaladas): ?><br>Las canchas aún no están instaladas: ejecuta <code>sql/fase4-canchas.sql</code> para poder elegir una.
                <?php elseif (!$canchas): ?><br>No hay canchas activas. <a href="canchas.php">Crear o activar una cancha</a>.<?php endif; ?>
            </p>
            <div class="barra-guardar">
                <button type="submit" class="btn btn--pri" <?= $semestre ? '' : 'disabled' ?>>Buscar horarios compatibles</button>
            </div>
        </form>
    </section>

<?php if ($res && $p): ?>
    <section class="tarjeta" id="resultados">
        <h2 class="tarjeta__titulo">Horarios compatibles</h2>
        <p class="enfrentamiento">
            <b><?= e($p['equipo_a']['nombre']) ?></b> <span>vs</span> <b><?= e($p['equipo_b']['nombre']) ?></b>
            <?php if ($p['cancha']): ?><em class="enfrentamiento__cancha">en <?= e($p['cancha']['nombre']) ?></em><?php endif; ?>
        </p>
        <p class="texto-suave">
            <?= e(fecha_mx($p['desde'])) ?> – <?= e(fecha_mx($p['hasta'])) ?> · partido de <?= (int)$p['duracion'] ?> min · inicio cada <?= (int)$p['paso'] ?> min
        </p>

        <div class="cifras">
            <div class="cifra"><b><?= (int)$res['total_slots'] ?></b><span>horarios posibles</span></div>
            <div class="cifra"><b><?= count($conSlots) ?></b><span>fechas con horario</span></div>
            <div class="cifra"><b><?= count($sinCoinc) ?></b><span>fechas sin horario</span></div>
            <div class="cifra"><b><?= count($res['fechas']) ?></b><span>fechas analizadas</span></div>
        </div>

        <?php if (!$conSlots): ?>
            <div class="aviso aviso--info"><?= $p['cancha']
                ? 'No hay horarios compatibles entre los equipos y ' . e($p['cancha']['nombre']) . ' durante el periodo seleccionado.'
                : 'Los equipos no tienen horarios compatibles durante el periodo seleccionado.' ?></div>
        <?php endif; ?>

        <?php foreach ($conSlots as $dia): ?>
            <article class="fecha-res">
                <h3 class="fecha-res__titulo">
                    <?= e(fecha_larga_mx($dia['fecha'])) ?>
                    <small><?= count($dia['slots']) ?> horario<?= count($dia['slots']) === 1 ? '' : 's' ?> · coinciden <?= e(intervalos_texto($dia['libre'])) ?></small>
                </h3>
                <ul class="slots">
                    <?php foreach ($dia['slots'] as [$i, $fn]): ?>
                        <li><?= e(minutos_a_hora($i)) ?> – <?= e(minutos_a_hora($fn)) ?></li>
                    <?php endforeach; ?>
                </ul>
                <details class="plegable">
                    <summary>Ver disponibilidad de cada equipo<?= $p['cancha'] ? ' y de la cancha' : '' ?></summary>
                    <?php pintar_detalle($dia); ?>
                </details>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($sinCoinc): ?>
    <section class="tarjeta" id="sin-coincidencia">
        <details class="plegable plegable--seccion">
            <summary><span class="tarjeta__titulo tarjeta__titulo--inline">Sin horario compatible (<?= count($sinCoinc) ?>)</span> <span class="texto-suave">— ver por qué</span></summary>
            <?php foreach ($sinCoinc as $dia): ?>
                <article class="fecha-res fecha-res--sin">
                    <h3 class="fecha-res__titulo"><?= e(fecha_larga_mx($dia['fecha'])) ?></h3>
                    <p class="motivo"><?= e($dia['motivo']) ?></p>
                    <?php pintar_detalle($dia); ?>
                </article>
            <?php endforeach; ?>
        </details>
    </section>
    <?php endif; ?>
<?php endif; ?>

    </main>

<footer class="pie contenedor">Liga Chapingo · administración · entorno de pruebas · sólo análisis, no se guardan partidos</footer>
</body>
</html>
