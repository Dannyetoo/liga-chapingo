<?php
/**
 * Administración · «Busca un rival».
 * Actualización 2026-09-29:
 *   · Editar y cancelar partidos confirmados (avisa a los dos equipos).
 *   · Primera y Segunda se distinguen a simple vista (color y etiqueta).
 *   · «Generar propuestas»: partidos posibles según los horarios que coinciden (mismo grupo, sin
 *     partido próximo y sin haberse elegido entre sí). La liga los propone; ambos equipos confirman.
 *   · Solicitudes de cancelación y reportes de problemas de los capitanes.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/rival.php';

requerir_admin();
cabeceras_admin();
$semestre = obtener_semestre_activo();
$tituloPagina = 'Busca un rival';
$paginaActual = 'rival';
$mensajes = tomar_flash();
$ok = rival_instalado();
$v8 = $ok && rival_v8_instalado();
$pubs = $sols = $res = $reprogs = $hist = $props = $pets = $cancelados = $candidatos = $gruposEq = $jugados = $jugadosDicifo = [];
$generar = isset($_GET['generar']);
$filtroCat = (string)($_GET['cat'] ?? '');
if (!in_array($filtroCat, ['', 'primera', 'segunda', 'otra'], true)) $filtroCat = '';
$horasCancha = [];
$error = null;
if ($ok) {
    try {
        $hoy = rival_ahora()->format('Y-m-d');
        $st = db()->prepare("SELECT * FROM reservas_cancha WHERE estado = 'confirmada' AND fecha >= ? ORDER BY fecha, hora");
        $st->execute([$hoy]);
        $res = $st->fetchAll();
        $pubs = db()->query("SELECT * FROM rival_publicaciones WHERE estado = 'activa' ORDER BY grupo_ref, equipo_nombre")->fetchAll();
        foreach ($pubs as &$p) {
            $st = db()->prepare("SELECT * FROM rival_opciones WHERE publicacion_id = ? AND estado IN ('vigente','ocupada','confirmada') AND fecha >= ? ORDER BY fecha, hora");
            $st->execute([$p['id'], $hoy]);
            $p['opciones'] = $st->fetchAll();
        }
        unset($p);
        $sols = db()->query("SELECT s.*, o.fecha, o.hora, p.equipo_nombre AS dueno, p.grupo_ref FROM rival_solicitudes s
                               JOIN rival_opciones o ON o.id = s.opcion_id JOIN rival_publicaciones p ON p.id = s.publicacion_id
                              WHERE s.estado = 'pendiente' ORDER BY s.creado_en")->fetchAll();
        $reprogs = db()->query("SELECT rp.*, r.grupo_ref FROM rival_reprogramaciones rp LEFT JOIN reservas_cancha r ON r.id = rp.reserva_id WHERE rp.estado = 'pendiente' ORDER BY rp.creado_en")->fetchAll();
        $hist = db()->query("SELECT * FROM rival_reprogramaciones WHERE estado <> 'pendiente' ORDER BY resuelta_en DESC LIMIT 20")->fetchAll();
        if ($v8) {
            $props = db()->query("SELECT * FROM rival_propuestas WHERE estado = 'pendiente' ORDER BY fecha, hora")->fetchAll();
            $pets = db()->query("SELECT p.*, r.grupo_ref, r.estado AS reserva_estado FROM rival_peticiones p LEFT JOIN reservas_cancha r ON r.id = p.reserva_id
                                  WHERE p.estado = 'pendiente' OR p.resuelta_en >= NOW() - INTERVAL 3 DAY ORDER BY p.estado = 'pendiente' DESC, p.id DESC LIMIT 40")->fetchAll();
            $cancelados = db()->query("SELECT * FROM reservas_cancha WHERE estado = 'cancelada' AND cancelada_en >= NOW() - INTERVAL 14 DAY ORDER BY cancelada_en DESC LIMIT 20")->fetchAll();
            if ($generar) $candidatos = rival_generar_propuestas();
            $gruposEq = rival_equipos_por_grupo();
            $jugados = db()->query('SELECT * FROM rival_jugados ORDER BY COALESCE(fecha, DATE(creado_en)) DESC, id DESC LIMIT 200')->fetchAll();
            $st = db()->prepare("SELECT * FROM reservas_cancha WHERE estado = 'confirmada' AND fecha <= ? ORDER BY fecha DESC, hora DESC LIMIT 60");
            $st->execute([$hoy]);
            $jugadosDicifo = array_values(array_filter($st->fetchAll(), fn($r) => rival_ya_empezo($r['fecha'], $r['hora'])));
        }
        // Horas posibles de la cancha (para mover un partido): todas las de los próximos días.
        $cid = (int)rival_cancha_id();
        if ($cid) {
            $d0 = rival_ahora()->setTime(0, 0);
            for ($i = 0; $i <= RIVAL_DIAS_ADMIN; $i++) foreach (rival_horas_de($d0->modify("+$i days"), $cid) as $hh) $horasCancha[$hh] = true;
            ksort($horasCancha);
        }
    } catch (Throwable $ex) {
        $error = error_interno($ex, 'admin rival');
    }
}
$GI = [];
$gi = function (?string $g) use (&$GI): array {
    $g = (string)$g;
    return $GI[$g] ??= rival_grupo_info($g);
};
$pasa = fn(?string $g) => $filtroCat === '' || $gi($g)['cat'] === $filtroCat;
$cuenta = ['primera' => 0, 'segunda' => 0, 'otra' => 0];
foreach ($res as $r) $cuenta[$gi($r['grupo_ref'])['cat']]++;
$pendPets = count(array_filter($pets, fn($p) => $p['estado'] === 'pendiente'));
$badge = function (?string $g) use ($gi): string {
    $x = $gi($g);
    return '<span class="cat-badge cat-badge--' . e($x['cat']) . '">' . e($x['categoria']) . ($x['grupo'] !== '' ? ' · ' . e($x['grupo']) : '') . '</span>';
};
$admin = admin_en_sesion();
/** <select> de equipos agrupados por grupo (data-grupo para que el JS limite el segundo al mismo grupo). */
$selEquipos = function (string $name, string $label) use ($gruposEq, $filtroCat): void { ?>
    <label><?= e($label) ?>
        <select name="<?= e($name) ?>" required data-sel-grupo>
            <option value="">Elegir equipo…</option>
            <?php foreach ($gruposEq as $g => $G): if ($filtroCat !== '' && $G['cat'] !== $filtroCat) continue; ?>
            <optgroup label="<?= e($G['texto']) ?>">
                <?php foreach ($G['equipos'] as $q): ?><option value="<?= (int)$q['id'] ?>" data-grupo="<?= e(md5($g)) ?>"><?= e($q['nombre']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select>
    </label>
<?php };
/** Nube de ayuda (ⓘ): el texto aparece flotando sólo cuando se pide. */
$ayuda = fn(string $t): string => '<span class="nube-w"><button type="button" class="nube-btn" aria-label="Más información" aria-expanded="false">i</button><span class="nube" role="tooltip" hidden>' . e($t) . '</span></span>';
$pasado = fn(string $f): bool => $f < rival_ahora()->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Busca un rival · Administración · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=6">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
    <link rel="stylesheet" href="../assets/finanzas.css?v=3">
    <link rel="stylesheet" href="../assets/admin-rival.css?v=20260929b">
    <script src="../assets/admin-rival.js?v=20260929b" defer></script>
    <script src="../assets/nube.js?v=20260929b" defer></script>
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin ar">
<?php foreach ($mensajes as $m): $m['mensajes'] = array_map(fn($x) => str_replace(['a.m..', 'p.m..'], ['a.m.', 'p.m.'], (string)$x), $m['mensajes']); ?>
    <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert">
        <?php if (count($m['mensajes']) === 1): ?><?= e($m['mensajes'][0]) ?>
        <?php else: ?><strong><?= e($m['mensajes'][0]) ?></strong><ul><?php foreach (array_slice($m['mensajes'], 1) as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (!$ok): ?>
    <div class="aviso aviso--error" role="alert">Falta ejecutar el SQL de «Busca un rival» (rival-PRODUCCION.sql y la actualización rival-fase7-PRODUCCION.sql).</div>
<?php elseif ($error): ?>
    <div class="aviso aviso--error" role="alert"><?= e($error) ?></div>
<?php else: ?>
    <?php if (!$v8): ?>
    <div class="aviso aviso--error" role="alert"><strong>Falta instalar la actualización.</strong> <a href="actualizar.php">Instalar ahora →</a></div>
    <?php endif; ?>

    <!-- Resumen + filtro -->
    <section class="tarjeta ar-resumen">
        <div class="ar-cifras">
            <a href="#confirmados"><b><?= count($res) ?></b><span>Próximos partidos</span></a>
            <a href="#solicitudes" class="<?= $pendPets ? 'ar-alerta' : '' ?>"><b><?= $pendPets ?></b><span>Solicitudes de capitanes</span></a>
            <a href="#generar"><b><?= count($props) ?></b><span>Propuestas en espera</span></a>
            <a href="#mas"><b><?= count($pubs) ?></b><span>Equipos con horarios</span></a>
        </div>
        <nav class="ar-filtro" aria-label="Filtrar por categoría">
            <?php foreach (['' => 'Todas', 'primera' => 'Primera', 'segunda' => 'Segunda', 'otra' => 'Otras'] as $k => $t): ?>
                <a href="rival.php<?= $k !== '' ? '?cat=' . $k : '' ?><?= $generar ? ($k !== '' ? '&' : '?') . 'generar=1' : '' ?>" class="ar-filtro__op cat-<?= $k ?: 'todas' ?>" <?= $filtroCat === $k ? 'aria-current="true"' : '' ?>><?= e($t) ?></a>
            <?php endforeach; ?>
        </nav>
    </section>

    <?php if ($v8 && $pendPets): ?>
    <!-- Solicitudes de los capitanes (sólo si hay pendientes) -->
    <section class="tarjeta ar-urgente" id="solicitudes">
        <h2 class="tarjeta__titulo">Solicitudes de capitanes <span class="ar-num"><?= $pendPets ?></span></h2>
        <div class="ar-lista">
        <?php foreach ($pets as $p): if ($p['estado'] !== 'pendiente') continue; ?>
            <article class="ar-item <?= $p['grupo_ref'] ? 'cat-' . e($gi($p['grupo_ref'])['cat']) : '' ?>">
                <div class="ar-item__txt">
                    <b><?= $p['tipo'] === 'cancelacion' ? 'Quiere cancelar' : 'Reporta un problema' ?> · <?= e($p['equipo_nombre']) ?></b>
                    <?php if ($p['partido_txt']): ?><small class="texto-suave"><?= e($p['partido_txt']) ?></small><?php endif; ?>
                    <span>«<?= e($p['mensaje']) ?>»</span>
                </div>
                <form method="post" action="rival-accion.php" class="ar-item__acc">
                    <?= csrf_campo() ?><input type="hidden" name="peticion" value="<?= (int)$p['id'] ?>">
                    <input type="text" name="respuesta" maxlength="250" placeholder="Respuesta (opcional)">
                    <?php if ($p['tipo'] === 'cancelacion' && $p['reserva_estado'] === 'confirmada'): ?>
                        <button type="submit" name="accion" value="peticion_aprobar" class="btn btn--peligro btn--chico" data-confirmar="¿Aprobar y CANCELAR el partido? Se avisará a los dos equipos.">Cancelar partido</button>
                        <button type="submit" name="accion" value="peticion_rechazar" class="btn btn--sec btn--chico">Rechazar</button>
                    <?php else: ?>
                        <button type="submit" name="accion" value="peticion_atender" class="btn btn--pri btn--chico">Atendida</button>
                    <?php endif; ?>
                </form>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($v8): ?>
    <!-- HACER MATCH -->
    <section class="tarjeta ar-match" id="generar">
        <div class="ar-cab">
            <h2 class="tarjeta__titulo">Hacer match <?= $ayuda('«Generar propuestas» busca equipos del mismo grupo cuyos horarios coinciden, con la cancha libre y que no se han enfrentado ni elegido entre sí. Al proponer, ambos equipos reciben aviso y el partido se confirma cuando los dos aceptan.') ?></h2>
            <form method="get" action="rival.php#generar">
                <?php if ($filtroCat): ?><input type="hidden" name="cat" value="<?= e($filtroCat) ?>"><?php endif; ?>
                <button type="submit" name="generar" value="1" class="btn btn--pri">⚡ Generar propuestas</button>
            </form>
        </div>
        <?php if ($generar):
            $candF = array_values(array_filter($candidatos, fn($c) => $pasa($c['grupo_ref']))); ?>
            <?php if (!$candF): ?>
                <p class="ar-vacio">No hay match posible por ahora<?= $filtroCat ? ' en esta categoría' : '' ?>.</p>
            <?php else: ?>
            <form method="post" action="rival-accion.php" class="ar-cand">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="proponer">
                <div class="ar-cand__cab">
                    <b><?= count($candF) ?> match posible<?= count($candF) === 1 ? '' : 's' ?></b>
                    <label class="ar-todos"><input type="checkbox" data-marcar-todos=".ar-cand input[data-cand]"> Todos</label>
                </div>
                <ul class="ar-cards">
                <?php foreach ($candF as $c): $k = $c['a']['id'] . '-' . $c['b']['id']; ?>
                    <li class="ar-card cat-<?= e($gi($c['grupo_ref'])['cat']) ?>">
                        <label class="ar-card__sel"><input type="checkbox" name="p[<?= e($k) ?>][on]" value="1" data-cand aria-label="Proponer <?= e($c['a']['nombre']) ?> vs <?= e($c['b']['nombre']) ?>"></label>
                        <div class="ar-card__vs"><b><?= e($c['a']['nombre']) ?></b><span>vs</span><b><?= e($c['b']['nombre']) ?></b></div>
                        <?= $badge($c['grupo_ref']) ?>
                        <select name="p[<?= e($k) ?>][slot]" aria-label="Horario"><?php foreach ($c['slots'] as $sl): ?><option value="<?= e($sl) ?>"><?= e(rival_slot_texto(substr($sl, 0, 10), substr($sl, 11))) ?></option><?php endforeach; ?></select>
                    </li>
                <?php endforeach; ?>
                </ul>
                <button type="submit" class="btn btn--pri" data-confirmar="¿Enviar las propuestas marcadas? Se avisará a los equipos.">Proponer marcados</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>

        <details class="ar-plegable" id="manual">
            <summary>+ Proponer un partido a mano</summary>
            <?php if (!$gruposEq): ?><p class="texto-suave">No hay equipos vinculados con su categoría.</p><?php else: ?>
            <form method="post" action="rival-accion.php" class="ar-form ar-form--fila" data-mismo-grupo>
                <?= csrf_campo() ?><input type="hidden" name="accion" value="proponer_manual">
                <?php $selEquipos('equipo_a', 'Equipo 1'); ?>
                <?php $selEquipos('equipo_b', 'Equipo 2'); ?>
                <label>Fecha <input type="date" name="fecha" required min="<?= e(rival_ahora()->format('Y-m-d')) ?>" max="<?= e(rival_ahora()->modify('+' . RIVAL_DIAS_ADMIN . ' days')->format('Y-m-d')) ?>"></label>
                <label>Hora <select name="hora" required><option value="">Hora…</option><?php foreach (array_keys($horasCancha) as $hh): ?><option value="<?= e($hh) ?>"><?= e(rival_hora_texto($hh)) ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="btn btn--pri btn--chico" data-confirmar="¿Enviar la propuesta a los dos equipos?">Enviar propuesta</button>
            </form>
            <?php endif; ?>
        </details>

        <?php $propsF = array_values(array_filter($props, fn($p) => $pasa($p['grupo_ref']))); ?>
        <?php if ($propsF): ?>
        <h3 class="subtitulo">En espera de respuesta <?= $ayuda('✓ = ya aceptó · … = aún no responde. «Retirar» cancela la propuesta y avisa a los equipos.') ?></h3>
        <ul class="ar-cards">
        <?php foreach ($propsF as $p): $ic = fn($r) => $r === 'aceptada' ? '✓' : ($r === 'rechazada' ? '✕' : '…'); ?>
            <li class="ar-card cat-<?= e($gi($p['grupo_ref'])['cat']) ?>">
                <div class="ar-card__vs"><b><?= e($p['equipo_a_nombre']) ?> <small class="ar-resp ar-resp--<?= e($p['resp_a']) ?>"><?= $ic($p['resp_a']) ?></small></b><span>vs</span><b><?= e($p['equipo_b_nombre']) ?> <small class="ar-resp ar-resp--<?= e($p['resp_b']) ?>"><?= $ic($p['resp_b']) ?></small></b></div>
                <span class="ar-card__cuando"><?= e(rival_slot_texto($p['fecha'], $p['hora'])) ?></span>
                <form method="post" action="rival-accion.php"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_propuesta"><input type="hidden" name="propuesta" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn-texto btn-texto--peligro" data-confirmar="¿Retirar esta propuesta?">Retirar</button></form>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- Partidos confirmados -->
    <section class="tarjeta" id="confirmados">
        <h2 class="tarjeta__titulo">Próximos partidos <?= $ayuda('Azul = Primera · Naranja = Segunda. «Editar» mueve el partido y «Eliminar» lo cancela; en ambos casos se avisa a los dos equipos.') ?></h2>
        <?php $resF = array_values(array_filter($res, fn($r) => $pasa($r['grupo_ref']))); ?>
        <?php if (!$resF): ?><p class="ar-vacio">Sin partidos próximos.</p><?php else: ?>
        <div class="tabla-scroll"><table class="fin-tabla ar-tabla">
            <thead><tr><th scope="col">Cuándo</th><th scope="col">Partido</th><th scope="col">Categoría</th><?php if ($v8): ?><th scope="col"><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
            <tbody><?php foreach ($resF as $r): $c = $gi($r['grupo_ref']); ?>
                <tr class="cat-<?= e($c['cat']) ?>">
                    <td class="ar-cuando"><?= e(rival_slot_texto($r['fecha'], $r['hora'])) ?></td>
                    <td><b><?= e($r['equipo_a_nombre']) ?></b> vs <b><?= e($r['equipo_b_nombre']) ?></b></td>
                    <td><?= $badge($r['grupo_ref']) ?></td>
                    <?php if ($v8): ?>
                    <td class="ar-acciones">
                        <details class="ar-det">
                            <summary>Editar</summary>
                            <form method="post" action="rival-accion.php" class="ar-form">
                                <?= csrf_campo() ?><input type="hidden" name="accion" value="editar_partido"><input type="hidden" name="reserva" value="<?= (int)$r['id'] ?>">
                                <label>Fecha <input type="date" name="fecha" required value="<?= e($r['fecha']) ?>" min="<?= e(rival_ahora()->format('Y-m-d')) ?>" max="<?= e(rival_ahora()->modify('+' . RIVAL_DIAS_ADMIN . ' days')->format('Y-m-d')) ?>"></label>
                                <label>Hora <select name="hora" required><?php foreach (array_keys($horasCancha) as $hh): ?><option value="<?= e($hh) ?>" <?= $hh === substr($r['hora'], 0, 5) ? 'selected' : '' ?>><?= e(rival_hora_texto($hh)) ?></option><?php endforeach; ?></select></label>
                                <button type="submit" class="btn btn--pri btn--chico" data-confirmar="¿Mover este partido? Se avisará a los dos equipos.">Guardar</button>
                            </form>
                        </details>
                        <details class="ar-det">
                            <summary class="ar-det--peligro">Eliminar</summary>
                            <form method="post" action="rival-accion.php" class="ar-form">
                                <?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_partido"><input type="hidden" name="reserva" value="<?= (int)$r['id'] ?>">
                                <label>Motivo (lo verán los equipos) <input type="text" name="motivo" maxlength="200" required></label>
                                <button type="submit" class="btn btn--peligro btn--chico" data-confirmar="¿Eliminar el partido <?= e($r['equipo_a_nombre']) ?> vs <?= e($r['equipo_b_nombre']) ?>?">Eliminar</button>
                            </form>
                        </details>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>

    <?php if ($v8): ?>
    <!-- Partidos ya jugados -->
    <section class="tarjeta" id="historial">
        <div class="ar-cab">
            <h2 class="tarjeta__titulo">Ya jugados <?= $ayuda('Los partidos de esta lista quedan en el historial de ambos equipos: «Generar propuestas» ya no los junta y los capitanes no pueden volver a pedirse partido. «Editar» corrige fecha o nota; «Eliminar» lo quita del historial y podrán enfrentarse de nuevo.') ?></h2>
        </div>
        <?php if ($gruposEq): ?>
        <details class="ar-plegable">
            <summary>+ Registrar un partido ya jugado</summary>
            <form method="post" action="rival-accion.php" class="ar-form ar-form--fila" data-mismo-grupo>
                <?= csrf_campo() ?><input type="hidden" name="accion" value="registrar_jugado">
                <?php $selEquipos('equipo_a', 'Equipo 1'); ?>
                <?php $selEquipos('equipo_b', 'Equipo 2'); ?>
                <label>Fecha <input type="date" name="fecha" max="<?= e(rival_ahora()->format('Y-m-d')) ?>"></label>
                <label>Nota <input type="text" name="nota" maxlength="200" placeholder="Opcional"></label>
                <button type="submit" class="btn btn--pri btn--chico">Registrar</button>
            </form>
        </details>
        <?php endif; ?>
        <?php
        $hj = [];
        foreach ($jugados as $j) $hj[] = ['fecha' => $j['fecha'], 'hora' => null, 'a' => $j['equipo_a_nombre'], 'b' => $j['equipo_b_nombre'], 'grupo' => $j['grupo_ref'], 'origen' => $j['origen'] === 'solicitud' ? 'Desde solicitud' : 'A mano', 'nota' => $j['nota'], 'id' => (int)$j['id'], 'reserva' => 0];
        foreach ($jugadosDicifo as $r) $hj[] = ['fecha' => $r['fecha'], 'hora' => $r['hora'], 'a' => $r['equipo_a_nombre'], 'b' => $r['equipo_b_nombre'], 'grupo' => $r['grupo_ref'], 'origen' => 'DICIFO', 'nota' => null, 'id' => 0, 'reserva' => (int)$r['id']];
        usort($hj, fn($x, $y) => strcmp((string)$y['fecha'], (string)$x['fecha']));
        $hj = array_values(array_filter($hj, fn($x) => $pasa($x['grupo'])));
        ?>
        <?php if (!$hj): ?><p class="ar-vacio">Todavía no hay partidos jugados.</p><?php else: ?>
        <div class="tabla-scroll"><table class="fin-tabla ar-tabla">
            <thead><tr><th scope="col">Fecha</th><th scope="col">Partido</th><th scope="col">Categoría</th><th scope="col">Origen</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
            <tbody><?php foreach ($hj as $x): ?><tr class="cat-<?= e($gi($x['grupo'])['cat']) ?>">
                <td class="ar-cuando"><?= $x['fecha'] ? e($x['hora'] ? rival_slot_texto((string)$x['fecha'], (string)$x['hora']) : rival_dia_texto((string)$x['fecha'])) : '<span class="texto-suave">—</span>' ?></td>
                <td><b><?= e($x['a']) ?></b> vs <b><?= e($x['b']) ?></b><?= $x['nota'] ? '<br><small class="texto-suave">' . e($x['nota']) . '</small>' : '' ?></td>
                <td><?= $badge($x['grupo']) ?></td>
                <td><small><?= e($x['origen']) ?></small></td>
                <td class="ar-acciones">
                    <details class="ar-det">
                        <summary>Editar</summary>
                        <form method="post" action="rival-accion.php" class="ar-form">
                            <?= csrf_campo() ?><input type="hidden" name="accion" value="editar_jugado">
                            <?php if ($x['reserva']): ?>
                                <input type="hidden" name="reserva" value="<?= $x['reserva'] ?>">
                                <label>Fecha <input type="date" name="fecha" required value="<?= e((string)$x['fecha']) ?>" max="<?= e(rival_ahora()->format('Y-m-d')) ?>"></label>
                                <label>Hora <input type="time" name="hora" required value="<?= e(substr((string)$x['hora'], 0, 5)) ?>"></label>
                            <?php else: ?>
                                <input type="hidden" name="jugado" value="<?= $x['id'] ?>">
                                <label>Fecha <input type="date" name="fecha" value="<?= e((string)$x['fecha']) ?>" max="<?= e(rival_ahora()->format('Y-m-d')) ?>"></label>
                                <label>Nota <input type="text" name="nota" maxlength="200" value="<?= e((string)$x['nota']) ?>"></label>
                            <?php endif; ?>
                            <button type="submit" class="btn btn--pri btn--chico">Guardar</button>
                        </form>
                    </details>
                    <form method="post" action="rival-accion.php"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_jugado">
                        <?php if ($x['reserva']): ?><input type="hidden" name="reserva" value="<?= $x['reserva'] ?>"><?php else: ?><input type="hidden" name="jugado" value="<?= $x['id'] ?>"><?php endif; ?>
                        <button type="submit" class="btn-texto btn-texto--peligro" data-confirmar="¿Eliminar <?= e($x['a']) ?> vs <?= e($x['b']) ?> del historial? Podrán volver a enfrentarse.">Eliminar</button></form>
                </td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- Lo demás, plegado -->
    <section class="tarjeta ar-mas" id="mas">
        <h2 class="tarjeta__titulo">Más</h2>
        <?php $solsF = array_values(array_filter($sols, fn($s) => $pasa($s['grupo_ref']))); ?>
        <details class="ar-plegable" id="solicitudes-equipos" <?= $solsF ? 'open' : '' ?>>
            <summary>Solicitudes entre equipos <span class="ar-chip"><?= count($solsF) ?></span> <?= $v8 ? $ayuda('Un equipo eligió el horario de otro y falta que lo confirme. Si esos equipos ya se enfrentaron, usa «Ya jugado»: la solicitud se cierra y se guarda en el historial de ambos.') : '' ?></summary>
            <?php if (!$solsF): ?><p class="ar-vacio">Ninguna.</p><?php else: ?>
            <div class="tabla-scroll"><table class="fin-tabla ar-tabla">
                <thead><tr><th scope="col">Solicita</th><th scope="col">A</th><th scope="col">Horario</th><th scope="col">Categoría</th><?php if ($v8): ?><th scope="col"><span class="sr-only">Acción</span></th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($solsF as $s): ?><tr class="cat-<?= e($gi($s['grupo_ref'])['cat']) ?>"><td><?= e($s['equipo_nombre']) ?></td><td><?= e($s['dueno']) ?></td><td><?= e(rival_slot_texto($s['fecha'], $s['hora'])) ?></td><td><?= $badge($s['grupo_ref']) ?></td>
                    <?php if ($v8): ?><td class="ar-acciones">
                        <details class="ar-det">
                            <summary>Ya jugado</summary>
                            <form method="post" action="rival-accion.php" class="ar-form">
                                <?= csrf_campo() ?><input type="hidden" name="accion" value="solicitud_jugada"><input type="hidden" name="solicitud" value="<?= (int)$s['id'] ?>">
                                <label>Fecha (opcional) <input type="date" name="fecha" max="<?= e(rival_ahora()->format('Y-m-d')) ?>"></label>
                                <button type="submit" class="btn btn--pri btn--chico" data-confirmar="¿Guardar <?= e($s['equipo_nombre']) ?> vs <?= e($s['dueno']) ?> como YA JUGADO?">Guardar</button>
                            </form>
                        </details>
                    </td><?php endif; ?></tr><?php endforeach; ?></tbody>
            </table></div><?php endif; ?>
        </details>

        <details class="ar-plegable">
            <summary>Reprogramaciones pendientes <span class="ar-chip"><?= count($reprogs) ?></span></summary>
            <?php if (!$reprogs): ?><p class="ar-vacio">Ninguna.</p><?php else: ?>
            <div class="tabla-scroll"><table class="fin-tabla ar-tabla">
                <thead><tr><th scope="col">Propone</th><th scope="col">Elige</th><th scope="col">Ahora</th><th scope="col">Propuestos</th></tr></thead>
                <tbody><?php foreach ($reprogs as $x): $ops = $v8 ? rival_reprog_opciones_de((int)$x['id']) : []; ?>
                    <tr class="cat-<?= e($gi($x['grupo_ref'])['cat']) ?>"><td><?= e($x['solicita_nombre']) ?></td><td><?= e($x['otro_nombre']) ?></td>
                    <td><?= e(rival_slot_texto($x['fecha_anterior'], $x['hora_anterior'])) ?></td>
                    <td><?php if ($ops): foreach ($ops as $o): ?><div><?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?></div><?php endforeach; else: ?><?= e(rival_slot_texto($x['fecha_nueva'], $x['hora_nueva'])) ?><?php endif; ?></td></tr><?php endforeach; ?></tbody>
            </table></div><?php endif; ?>
        </details>

        <?php $pubsF = array_values(array_filter($pubs, fn($p) => $pasa($p['grupo_ref']))); ?>
        <details class="ar-plegable">
            <summary>Equipos con horarios <span class="ar-chip"><?= count($pubsF) ?></span></summary>
            <?php if (!$pubsF): ?><p class="ar-vacio">Ninguno.</p><?php else: ?>
            <div class="tabla-scroll"><table class="fin-tabla ar-tabla">
                <thead><tr><th scope="col">Equipo</th><th scope="col">Categoría</th><th scope="col">Horarios</th></tr></thead>
                <tbody><?php foreach ($pubsF as $p): $disp = count(array_filter($p['opciones'], fn($o) => $o['estado'] === 'vigente')); ?>
                    <tr class="cat-<?= e($gi($p['grupo_ref'])['cat']) ?>"><td><b><?= e($p['equipo_nombre']) ?></b><?php if ($disp < RIVAL_MIN_HORARIOS): ?> <span class="ar-chip ar-chip--alerta" title="Menos de <?= RIVAL_MIN_HORARIOS ?> disponibles"><?= $disp ?></span><?php endif; ?></td><td><?= $badge($p['grupo_ref']) ?></td>
                    <td class="ar-horarios"><?php foreach ($p['opciones'] as $o): ?><span class="ar-h ar-h--<?= e($o['estado']) ?>"><?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?></span><?php endforeach; ?></td></tr><?php endforeach; ?></tbody>
            </table></div><?php endif; ?>
        </details>

        <?php if ($v8): ?>
        <details class="ar-plegable">
            <summary>Cancelados (14 días) <span class="ar-chip"><?= count($cancelados) ?></span></summary>
            <?php if (!$cancelados): ?><p class="ar-vacio">Ninguno.</p><?php else: ?>
            <div class="tabla-scroll"><table class="fin-tabla ar-tabla">
                <thead><tr><th scope="col">Era</th><th scope="col">Partido</th><th scope="col">Motivo</th></tr></thead>
                <tbody><?php foreach ($cancelados as $c): ?><tr class="cat-<?= e($gi($c['grupo_ref'])['cat']) ?>"><td><?= e(rival_slot_texto($c['fecha'], $c['hora'])) ?></td><td><?= e($c['equipo_a_nombre']) ?> vs <?= e($c['equipo_b_nombre']) ?></td><td><?= e((string)$c['motivo_cancelacion']) ?></td></tr><?php endforeach; ?></tbody>
            </table></div><?php endif; ?>
        </details>
        <details class="ar-plegable">
            <summary>Solicitudes de capitanes ya atendidas</summary>
            <?php $petsH = array_values(array_filter($pets, fn($p) => $p['estado'] !== 'pendiente')); ?>
            <?php if (!$petsH): ?><p class="ar-vacio">Ninguna.</p><?php else: ?>
            <ul class="ar-simple"><?php foreach ($petsH as $p): ?><li><b><?= e($p['equipo_nombre']) ?></b> · <?= e(RIVAL_PETICION_TIPOS[$p['tipo']] ?? $p['tipo']) ?> · <?= e($p['estado']) ?><?= $p['respuesta'] ? ' — ' . e($p['respuesta']) : '' ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </details>
        <?php endif; ?>

        <details class="ar-plegable">
            <summary>Historial de reprogramaciones</summary>
            <?php if (!$hist): ?><p class="ar-vacio">Todavía no hay.</p><?php else: ?>
            <ul class="ar-simple"><?php foreach ($hist as $x): ?><li><b><?= e($x['solicita_nombre']) ?> vs <?= e($x['otro_nombre']) ?></b> · <?= e(rival_slot_texto($x['fecha_anterior'], $x['hora_anterior'])) ?> → <?= e(rival_slot_texto($x['fecha_nueva'], $x['hora_nueva'])) ?> · <?= e($x['estado']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </details>
        <p class="ar-pie"><a href="canchas.php?cancha=<?= (int)rival_cancha_id() ?>#cancelar-hora">Cancelar una hora de la cancha →</a></p>
    </section>
<?php endif; ?>
</main>
</body>
</html>
