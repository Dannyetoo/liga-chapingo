<?php
/**
 * Liga Chapingo — Administración · FINANZAS (sólo lectura).
 *
 * Lee cobros y gastos de Tesorería (arbitrajes/datos/estado.json) sin escribir en ellos.
 * Acceso: sesión de administración con permiso de finanzas (requerir_finanzas()).
 * Lo único que escribe es el historial interno fin_huellas (ver lib/finanzas-huellas.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/finanzas-fuente.php';
require_once __DIR__ . '/../lib/finanzas-calculos.php';
require_once __DIR__ . '/../lib/finanzas-huellas.php';

$admin = requerir_finanzas();
cabeceras_admin();

function mxn(float $x): string
{
    $neg = $x < -0.004;
    $a   = abs($x);
    $txt = '$' . number_format($a, fmod($a, 1.0) >= 0.005 ? 2 : 0, '.', ',');
    return $neg ? '−' . $txt : $txt;
}
function fecha_hora_mx(string $iso): string
{
    try {
        return $iso === '' ? '—' : (new DateTime($iso))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i');
    } catch (Throwable $ex) {
        return '—';
    }
}
function clase_signo(float $x): string { return $x < -0.004 ? ' num--neg' : ''; }

$VISTAS = [
    'resumen'       => 'Resumen',
    'partidos'      => 'Por partido',
    'categorias'    => 'Por categoría',
    'inscripciones' => 'Inscripciones',
    'arbitrajes'    => 'Arbitrajes',
    'gastos'        => 'Gastos',
    'movimientos'   => 'Movimientos',
];
$PROXIMAS = ['partidos', 'gastos', 'movimientos'];

$vista = (string)($_GET['vista'] ?? 'resumen');
if (!isset($VISTAS[$vista]) || in_array($vista, $PROXIMAS, true)) $vista = 'resumen';

$semestre     = obtener_semestre_activo();
$tituloPagina = 'Finanzas';
$paginaActual = 'finanzas';
$datos        = null;
$R            = null;
$error        = null;
$huellas      = null;
$ediciones    = [];
$edicion      = '';

try {
    $datos = fin_leer_estado();
    if ($datos) {
        fin_sincronizar_huellas($datos);
        $huellas   = fin_resumen_huellas();
        $S         = $datos['estado'];
        $ediciones = fin_ediciones($S);
        $ids       = array_column($ediciones, 'id');
        $pedida    = (string)($_GET['edicion'] ?? '');
        $edicion   = in_array($pedida, $ids, true) ? $pedida : ($ids[0] ?? '');
        if ($edicion !== '') {
            $R = fin_resumen($S, $edicion, fin_parametros_vigentes($edicion),
                             fin_compromisos_vivos($edicion), fin_tickets_futuros_por_equipo());
        }
    }
} catch (Throwable $ex) {
    $error = error_interno($ex, 'finanzas');
}
$nombreEdicion = '';
foreach ($ediciones as $x) if ($x['id'] === $edicion) $nombreEdicion = $x['nombre'];
$T = $R['tarjetas'] ?? [];
$qsEd = 'edicion=' . rawurlencode($edicion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Finanzas · Administración · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
    <link rel="stylesheet" href="../assets/finanzas.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin">

<?php if ($error): ?>
    <div class="aviso aviso--error" role="alert"><?= e($error) ?></div>
<?php elseif (!$datos): ?>
    <div class="aviso aviso--error" role="alert">
        <strong>No se pudieron leer los datos de Tesorería.</strong>
        Revisa que <code>/capitanes/config.php</code> y <code>/arbitrajes/datos/estado.json</code> existan junto a <code>/disponibilidad/</code>.
    </div>
<?php elseif (!$R): ?>
    <div class="aviso aviso--info" role="status">Tesorería todavía no tiene ediciones registradas.</div>
<?php else: ?>

    <div class="fin-barra">
        <form method="get" action="finanzas.php" class="fin-edicion">
            <input type="hidden" name="vista" value="<?= e($vista) ?>">
            <label for="edicion" class="sr-only">Edición</label>
            <select id="edicion" name="edicion">
                <?php foreach ($ediciones as $x): ?>
                    <option value="<?= e($x['id']) ?>" <?= $x['id'] === $edicion ? 'selected' : '' ?>>
                        <?= e($x['nombre']) ?><?= $x['con_datos'] ? '' : ' (sin movimientos)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--sec btn--chico">Ver</button>
        </form>
        <p class="fin-fuente">
            Datos de Tesorería al <?= e(fecha_hora_mx((string)$datos['actualizado'])) ?>
            · revisión <?= (int)$datos['rev'] ?> · sólo lectura
        </p>
    </div>

    <nav class="fin-tabs" aria-label="Secciones de finanzas">
        <?php foreach ($VISTAS as $k => $txt): ?>
            <?php if (in_array($k, $PROXIMAS, true)): ?>
                <span class="fin-tab fin-tab--off" aria-disabled="true" title="Próxima fase"><?= e($txt) ?></span>
            <?php else: ?>
                <a class="fin-tab" href="finanzas.php?vista=<?= e($k) ?>&amp;<?= e($qsEd) ?>" <?= $k === $vista ? 'aria-current="page"' : '' ?>><?= e($txt) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

<?php if ($vista === 'resumen'): ?>

    <section class="fin-bloque fin-bloque--real" aria-labelledby="t-real">
        <header class="fin-bloque__cab">
            <h2 id="t-real" class="fin-bloque__titulo">Real</h2>
            <p>Dinero que ya entró o ya salió · <?= e($nombreEdicion) ?></p>
        </header>
        <div class="fin-tarjetas">
            <div class="fin-tarjeta fin-tarjeta--grande">
                <span class="fin-tarjeta__etq">Saldo real Administración</span>
                <b class="fin-tarjeta__num<?= clase_signo($T['saldo_real']) ?>"><?= e(mxn($T['saldo_real'])) ?></b>
                <small>recibido − gastos pagados</small>
            </div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Dinero recibido</span><b class="fin-tarjeta__num"><?= e(mxn($T['recibido'])) ?></b></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Inscripciones</span><b class="fin-tarjeta__num"><?= e(mxn($T['inscripciones'])) ?></b></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Arbitrajes</span><b class="fin-tarjeta__num"><?= e(mxn($T['arbitrajes'])) ?></b></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Otros ingresos</span><b class="fin-tarjeta__num"><?= e(mxn($T['otros_ingresos'])) ?></b></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Gastos operativos</span><b class="fin-tarjeta__num"><?= e(mxn($T['gastos_operativos'])) ?></b><small>árbitros, supervisión, coordinación</small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Otros gastos</span><b class="fin-tarjeta__num"><?= e(mxn($T['otros_gastos'])) ?></b><small>generales</small></div>
        </div>
    </section>

    <section class="fin-bloque fin-bloque--proy" aria-labelledby="t-proy">
        <header class="fin-bloque__cab">
            <h2 id="t-proy" class="fin-bloque__titulo">Proyectado</h2>
            <p>Estimación · no es dinero disponible</p>
        </header>
        <div class="fin-tarjetas">
            <div class="fin-tarjeta fin-tarjeta--grande">
                <span class="fin-tarjeta__etq">Resultado proyectado</span>
                <b class="fin-tarjeta__num<?= clase_signo($T['resultado_proyectado']) ?>"><?= e(mxn($T['resultado_proyectado'])) ?></b>
                <small>saldo real + por cobrar + ingresos futuros − gastos futuros</small>
            </div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Por cobrar hoy</span><b class="fin-tarjeta__num"><?= e(mxn($T['pendiente'])) ?></b><small>cargos con saldo</small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Inscripciones futuras</span><b class="fin-tarjeta__num"><?= e(mxn((float)($R['total']['proy']['inscripciones_futuras'] ?? 0))) ?></b></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Arbitrajes futuros</span><b class="fin-tarjeta__num"><?= e(mxn((float)($R['total']['proy']['arbitrajes_futuros'] ?? 0))) ?></b><small><?= (int)($R['total']['proy']['partidos_restantes'] ?? 0) ?> partidos por jugar</small></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Gasto operativo futuro</span><b class="fin-tarjeta__num num--neg"><?= e(mxn(-(float)($R['total']['proy']['gasto_operativo_futuro'] ?? 0))) ?></b></div>
            <div class="fin-tarjeta"><span class="fin-tarjeta__etq">Compromisos</span><b class="fin-tarjeta__num"><?= e(mxn((float)($R['total']['proy']['compromisos_ingreso'] ?? 0) - (float)($R['total']['proy']['compromisos_gasto'] ?? 0))) ?></b><small>ingresos − gastos comprometidos</small></div>
        </div>
        <p class="fin-nota">
            Partidos por jugar: todos contra todos (relámpago: eliminación directa) con los equipos registrados, menos los partidos ya cobrados.
            Tarifas y costos del Catálogo de Tesorería. Se puede fijar cada dato en <code>fin_parametros</code>.
        </p>
    </section>

    <?php $V = $R['revisar']; ?>
    <section class="tarjeta fin-revisar" aria-labelledby="t-rev">
        <h2 id="t-rev" class="tarjeta__titulo">Datos por revisar en Tesorería</h2>
        <ul>
            <?php if ($V['transferencias']): ?>
                <li><b><?= count($V['transferencias']) ?> transferencias internas capturadas como gasto</b>
                    (<?= e(mxn(array_sum(array_column($V['transferencias'], 'monto')))) ?>). No se restan del saldo:
                    <?php foreach ($V['transferencias'] as $i => $t): ?><?= $i ? '; ' : '' ?>folio <?= e($t['folio']) ?>, <?= e($t['de']) ?> → <?= e($t['para']) ?>, <?= e(mxn($t['monto'])) ?><?php endforeach; ?>.</li>
            <?php endif; ?>
            <?php if ($V['gastos_sin_categoria']): ?>
                <li><b><?= (int)$V['gastos_sin_categoria'] ?> gastos sin categoría de liga</b> (<?= e(mxn($V['gastos_sin_categoria_monto'])) ?>): van a «General de la liga».</li>
            <?php endif; ?>
            <?php if ($V['cobros_sin_jornada_arbitraje']): ?>
                <li><b><?= (int)$V['cobros_sin_jornada_arbitraje'] ?> cobros de arbitraje sin jornada</b>: no se pueden asignar a un partido.</li>
            <?php endif; ?>
            <?php if ($V['cobros_jornada_sin_rival']): ?>
                <li><b><?= (int)$V['cobros_jornada_sin_rival'] ?> cobros de arbitraje con jornada pero sin rival.</b></li>
            <?php endif; ?>
            <?php if ($V['cobros_sin_equipo']): ?>
                <li><b><?= (int)$V['cobros_sin_equipo'] ?> cobros de equipos que ya no existen</b>: no se cuentan.</li>
            <?php endif; ?>
            <?php if ($V['multas_n']): ?>
                <li><?= (int)$V['multas_n'] ?> multas individuales pendientes (<?= e(mxn($V['multas_pendientes'])) ?>): informativo, no se suman a lo pendiente.</li>
            <?php endif; ?>
            <?php if ($V['tickets_futuros'] > 0): ?>
                <li>Cargos programados en Tickets futuros: <?= e(mxn($V['tickets_futuros'])) ?> (referencia; no se suman a la proyección).</li>
            <?php endif; ?>
            <?php if ($huellas): ?>
                <li class="texto-suave">Historial: <?= (int)$huellas['registros'] ?> registros con huella<?= $huellas['borrados'] ? ', ' . (int)$huellas['borrados'] . ' borrados en Tesorería y conservados aquí' : '' ?>.</li>
            <?php else: ?>
                <li class="texto-suave">El historial (fin_huellas) no está instalado: ejecuta <code>sql/finanzas-fase3.sql</code>.</li>
            <?php endif; ?>
        </ul>
    </section>

<?php elseif ($vista === 'categorias'): ?>

    <div class="fin-cats">
        <?php foreach (array_merge($R['categorias'], [$R['total']]) as $c): $esTotal = $c['clave'] === '__total'; ?>
        <article class="tarjeta fin-cat<?= $esTotal ? ' fin-cat--total' : '' ?>">
            <h2 class="fin-cat__titulo"><?= e($c['nombre']) ?></h2>
            <?php if ($c['ruta'] !== ''): ?><p class="texto-suave fin-cat__ruta"><?= e($c['ruta']) ?> · <?= (int)$c['equipos'] ?> equipos</p><?php endif; ?>
            <dl class="fin-dl">
                <dt>Inscripciones</dt><dd><?= e(mxn($c['ing']['inscripcion']['cobrado'])) ?></dd>
                <dt>Arbitrajes</dt><dd><?= e(mxn($c['ing']['arbitraje']['cobrado'])) ?></dd>
                <dt>Otros ingresos</dt><dd><?= e(mxn($c['ing']['multa']['cobrado'] + $c['ing']['otro']['cobrado'])) ?></dd>
                <dt class="fin-dl__sub">Ingresos totales</dt><dd class="fin-dl__sub"><?= e(mxn($c['ingresos'])) ?></dd>
                <dt>Árbitros</dt><dd><?= e(mxn(-$c['gas']['arbitro'])) ?></dd>
                <dt>Supervisión</dt><dd><?= e(mxn(-$c['gas']['supervision'])) ?></dd>
                <dt>Coordinación</dt><dd><?= e(mxn(-$c['gas']['coordinacion'])) ?></dd>
                <dt>Otros gastos</dt><dd><?= e(mxn(-($c['gas']['otros_partido'] + $c['gas']['general']))) ?></dd>
                <dt class="fin-dl__real">Utilidad real</dt><dd class="fin-dl__real<?= clase_signo($c['utilidad']) ?>"><?= e(mxn($c['utilidad'])) ?></dd>
                <dt class="fin-dl__proy">Resultado proyectado</dt><dd class="fin-dl__proy<?= clase_signo((float)($c['proy']['resultado'] ?? 0)) ?>"><?= e(mxn((float)($c['proy']['resultado'] ?? 0))) ?></dd>
            </dl>
        </article>
        <?php endforeach; ?>
    </div>

<?php elseif ($vista === 'inscripciones' || $vista === 'arbitrajes'):
    $tipo = $vista === 'inscripciones' ? 'inscripcion' : 'arbitraje';
    $campoProy = $vista === 'inscripciones' ? 'inscripciones_total' : 'arbitrajes_total';
    $filas = array_values(array_filter($R['categorias'], fn($c) => !$c['es_general']));
?>
    <section class="tarjeta">
        <h2 class="tarjeta__titulo"><?= e($VISTAS[$vista]) ?> por categoría</h2>
        <p class="texto-suave">Cobrado = dinero recibido. Pendiente = cargos con saldo. Proyectado = cobrado + pendiente + lo que falta cargar<?= $tipo === 'arbitraje' ? ' por los partidos por jugar' : ' a los equipos esperados' ?>.</p>
        <div class="tabla-scroll">
        <table class="fin-tabla">
            <thead><tr><th scope="col">Categoría</th>
                <?php if ($tipo === 'inscripcion'): ?><th scope="col" class="num">Equipos</th><?php else: ?><th scope="col" class="num">Partidos</th><?php endif; ?>
                <th scope="col" class="num">Cobrado</th><th scope="col" class="num">Pendiente</th><th scope="col" class="num">Proyectado</th></tr></thead>
            <tbody>
            <?php foreach ($filas as $c): ?>
                <tr>
                    <th scope="row"><?= e($c['nombre']) ?><small><?= e($c['ruta']) ?></small></th>
                    <?php if ($tipo === 'inscripcion'): ?>
                        <td class="num"><?= (int)$c['equipos_con_inscripcion'] ?> / <?= (int)round((float)($c['proy']['equipos_esperados'] ?? 0)) ?></td>
                    <?php else: ?>
                        <td class="num"><?= (int)$c['partidos_registrados'] ?> / <?= (int)($c['proy']['partidos_total'] ?? 0) ?></td>
                    <?php endif; ?>
                    <td class="num"><?= e(mxn($c['ing'][$tipo]['cobrado'])) ?></td>
                    <td class="num"><?= e(mxn($c['ing'][$tipo]['pendiente'])) ?></td>
                    <td class="num fin-proy"><?= e(mxn((float)($c['proy'][$campoProy] ?? 0))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th scope="row">Total</th><td></td>
                    <td class="num"><?= e(mxn($R['total']['ing'][$tipo]['cobrado'])) ?></td>
                    <td class="num"><?= e(mxn($R['total']['ing'][$tipo]['pendiente'])) ?></td>
                    <td class="num fin-proy"><?= e(mxn((float)($R['total']['proy'][$campoProy] ?? 0))) ?></td></tr>
            </tfoot>
        </table>
        </div>
        <?php if ($tipo === 'inscripcion'): ?>
            <p class="fin-nota">Equipos: con cargo de inscripción / esperados (por defecto, los registrados en Tesorería).</p>
        <?php else: ?>
            <p class="fin-nota">Partidos: con cobro de arbitraje y rival registrados / total estimado de la categoría.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php endif; ?>
</main>
</body>
</html>
