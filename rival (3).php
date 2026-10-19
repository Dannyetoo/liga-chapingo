<?php
/**
 * Liga Chapingo — «BUSCA UN RIVAL» (app del capitán).
 * Se entra desde el Estado de cuenta (pase firmado → entrar.php). El equipo sale SIEMPRE de la
 * sesión, nunca de la URL ni del formulario.
 *
 * Actualización 2026-09-29: la app se divide en secciones — Inicio · Rivales · Partidos · Horarios · 🔔.
 *   Inicio    → instrucciones, datos del equipo y resumen (próximo partido, pendientes, avisos, accesos).
 *   Rivales   → equipos del grupo con horarios; proponer partido (resalta los que coinciden contigo).
 *   Partidos  → pendientes, confirmados y jugados. No se eliminan: se pide reprogramación (1 a 5
 *               horarios, con el rival), cancelación (con la administración) o se reporta un problema.
 *   Horarios  → mínimo 3 en total, hasta 5 por día; se agregan y se quitan uno por uno.
 *   🔔        → avisos recibidos y activar/desactivar avisos en el celular.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/rival.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

const RV_SECCIONES = ['inicio' => 'Inicio', 'buscar' => 'Match', 'partidos' => 'Partidos', 'horarios' => 'Horarios', 'avisos' => 'Avisos'];
$sec = (string)($_GET['s'] ?? 'inicio');
if (!isset(RV_SECCIONES[$sec])) $sec = 'inicio';

$ctx = contexto_acceso();
if ($ctx['rol'] !== 'capitan') {
    http_response_code(403);
}
$eq = null; $P = null; $X = null; $cal = []; $error = null; $pushVapid = null; $pushDisp = 0; $v8 = false;
$notifs = []; $ultimoVisto = 0;
if ($ctx['rol'] === 'capitan') {
    try {
        if (!rival_instalado()) $error = 'Esta sección se está actualizando. Vuelve a intentarlo en unos minutos.';
        elseif (!rival_cancha_id()) $error = 'La cancha DICIFO no está dada de alta. Avisa a la mesa directiva.';
        else {
            $eq = rival_contexto_equipo((int)$ctx['equipo_id'], capitan_estado_id());
            if (!$eq) $error = 'Tu equipo todavía no está vinculado con su categoría. Avisa a la mesa directiva.';
            else {
                $P = rival_panel($eq);
                $cal = rival_calendario((int)rival_cancha_id());
                $v8 = rival_v8_instalado();
                $X = $v8 ? rival_panel_extra($eq)
                         : ['propuestas' => [], 'jugados' => [], 'cancelados' => [], 'peticiones' => [], 'petPend' => [], 'noVistas' => 0, 'historial' => [], 'yaJugo' => []];
                if (push_instalado()) {
                    try { $pushVapid = push_vapid()['publica']; $pushDisp = push_dispositivos($eq['equipo_id']); } catch (Throwable $ex) { $pushVapid = null; }
                }
                if ($sec === 'avisos') {
                    $notifs = rival_notificaciones($eq['equipo_id'], 40);
                    if ($v8) { $ultimoVisto = rival_notif_ultimo_visto($eq['equipo_id']); rival_notif_marcar_vistas($eq['equipo_id']); $X['noVistas'] = 0; }
                }
            }
        }
    } catch (Throwable $ex) {
        $error = error_interno($ex, 'rival');
    }
}
$mensajes = tomar_flash();

$h = fn(string $x) => substr($x, 0, 5);
$ETQ_OP = ['vigente' => 'Disponible', 'ocupada' => 'Ocupado por otro partido', 'vencida' => 'Ya pasó', 'fuera' => 'DICIFO no abre a esa hora', 'confirmada' => 'Partido'];

/** «sáb 03 oct» / «SÁB 03 OCT» */
$diaTxt = function (string $f, bool $mayus = false): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f, new DateTimeZone(RIVAL_TZ));
    if (!$d) return $f;
    $t = mb_substr(RIVAL_DIAS_SEMANA[(int)$d->format('N')], 0, 3) . ' ' . $d->format('d') . ' ' . RIVAL_MESES[(int)$d->format('n')];
    return $mayus ? mb_strtoupper($t) : mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
};
/** ['11:00', 'a.m.'] */
$horaTxt = function (string $x): array {
    [$H, $M] = array_map('intval', explode(':', substr($x, 0, 5)));
    return [($H % 12 ?: 12) . ':' . str_pad((string)$M, 2, '0', STR_PAD_LEFT), $H < 12 ? 'a.m.' : 'p.m.'];
};
/** «hace 5 min» (segundos calculados por la base de datos: no depende de zonas horarias) */
$haceTxt = function ($seg): string {
    $s = max(0, (int)$seg);
    if ($s < 60) return 'hace un momento';
    if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
    $dias = intdiv($s, 86400);
    return $dias === 1 ? 'ayer' : 'hace ' . $dias . ' días';
};
// «Primera · Grupo A · Fútbol rápido Varonil · Segunda Edición 2026» → categoría / disciplina / edición
$catTxt = $discTxt = $edTxt = '';
if ($eq) {
    $partes = array_values(array_filter(array_map('trim', explode(' · ', (string)$eq['grupo_nombre'])), 'strlen'));
    if (count($partes) >= 3) $edTxt = (string)array_pop($partes);
    if (count($partes) >= 2) { $discTxt = (string)array_pop($partes); $discTxt = mb_strtoupper(mb_substr($discTxt, 0, 1)) . mb_strtolower(mb_substr($discTxt, 1)); }
    $catTxt = implode(' · ', $partes);
}
$iniciales = '';
if ($eq) foreach (array_slice(preg_split('/\s+/u', trim((string)$eq['nombre'])) ?: [], 0, 2) as $pal) $iniciales .= mb_strtoupper(mb_substr($pal, 0, 1));
$esSimulacion = ($ctx['origen'] ?? '') === 'simulacion' && es_entorno_pruebas();
$retorno = $ctx['rol'] === 'capitan' ? url_retorno_segura((string)$ctx['retorno']) : url_retorno_segura(CAPITAN_RETORNO_DEF);

/* ---------- Números del resumen ---------- */
$propPend = $X ? array_values(array_filter($X['propuestas'], fn($p) => $p['mi_resp'] === 'pendiente')) : [];
$nPorConfirmar = $P ? count(($P['mia']['solicitudes'] ?? []) ?: []) + count($P['reprogParaMi']) + count($propPend) : 0;
$nActivas = $P && $P['mia'] ? (int)$P['mia']['activas'] : 0;
$faltan = max(0, RIVAL_MIN_HORARIOS - $nActivas);
$insigniaAvisos = $X ? (int)$X['noVistas'] : 0;

/* ---------- Horarios del equipo por día y libres de la cancha ---------- */
$misClaves = [];
$misPorDia = [];
if ($P && $P['mia']) {
    foreach ($P['mia']['opciones'] as $o) {
        if ($o['efectivo'] === 'vencida') continue;
        $misClaves[$o['fecha'] . ' ' . $h($o['hora'])] = $o;
        $misPorDia[$o['fecha']][] = $o;
    }
}
$libres = [];                        // [fecha => [slot…]] horarios libres (para reprogramar)
foreach ($cal as $d) foreach ($d['slots'] as $sl) if ($sl['estado'] === 'libre') $libres[$d['fecha']][] = $sl;
$textoDia = [];
foreach ($cal as $d) $textoDia[$d['fecha']] = $d['texto'];

/** Rejilla de horarios con casillas (publicar / agregar / reprogramar). */
$rejilla = function (string $modo, array $opts = []) use ($cal, $misClaves, $misPorDia, $h): void {
    $esRep = $modo === 'reprog';
    foreach ($cal as $d):
        $vivos = array_filter($d['slots'], fn($s) => $s['estado'] !== 'pasado');
        if (!$vivos) continue;
        if ($esRep && !array_filter($d['slots'], fn($s) => $s['estado'] === 'libre')) continue;
        $ya = $esRep ? 0 : count($misPorDia[$d['fecha']] ?? []); ?>
        <fieldset class="rv-dia <?= $d['finde'] ? 'rv-dia--finde' : '' ?>" data-ya="<?= $ya ?>">
            <legend><?= e($d['texto']) ?><?php if (!$esRep && $ya): ?> <small class="rv-dia__ya"><?= $ya ?>/<?= RIVAL_MAX_POR_DIA ?></small><?php endif; ?></legend>
            <?php foreach ($d['slots'] as $sl):
                if ($sl['estado'] === 'pasado') continue;
                $mio = isset($misClaves[$sl['clave']]);
                if ($esRep && $sl['estado'] !== 'libre') continue;
                $libre = $sl['estado'] === 'libre' && ($esRep || !$mio); ?>
                <label class="rv-slot <?= $libre ? '' : 'rv-slot--off' ?> <?= $mio && !$esRep ? 'rv-slot--mio' : '' ?>">
                    <input type="checkbox" name="slots[]" value="<?= e($sl['clave']) ?>" <?= $libre ? '' : 'disabled' ?>>
                    <span><?= e(rival_hora_texto($sl['hora'])) ?></span>
                    <?php if (!$libre): ?><small><?= $mio && !$esRep ? 'tuyo' : ($sl['estado'] === 'cerrado' ? 'sin cancha' : 'ocupado') ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach;
};

/* Íconos (SVG en línea, sin dependencias) */
$ICO = [
    'campana' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20.5a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'inicio'  => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 11 12 4l8 7v8.5a.5.5 0 0 1-.5.5H15v-6H9v6H4.5a.5.5 0 0 1-.5-.5V11Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    'lupa'    => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="m15 15 5 5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>',
    'rayo'    => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12L13 2Z" fill="currentColor"/></svg>',
    'balon'   => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m12 7.5 3.2 2.3-1.2 3.7h-4l-1.2-3.7L12 7.5Z" fill="currentColor"/><path d="M12 3.5v4M15.2 9.8l4-1.3M14 13.5l2.5 3.5M10 13.5 7.5 17M8.8 9.8l-4-1.3" stroke="currentColor" stroke-width="1.4"/></svg>',
    'reloj'   => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'equipo'  => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3 5 5.5v5.8c0 4.3 3 7.7 7 9.2 4-1.5 7-4.9 7-9.2V5.5L12 3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="11" r="2.3" fill="currentColor"/></svg>',
    'pin'     => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11Z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="10" r="2.2" fill="currentColor"/></svg>',
    'cuenta'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><rect x="4" y="3.5" width="16" height="17" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'trofeo'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M7 4h10v4a5 5 0 0 1-10 0V4Z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 6H4v1.5A3 3 0 0 0 7 10.5M17 6h3v1.5a3 3 0 0 1-3 3M12 13v4M8.5 20h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'x'       => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="m7 7 10 10M17 7 7 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    'bandera' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M5 21V4m0 0h11l-2 4 2 4H5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
];
$vol = fn(string $s) => '<input type="hidden" name="volver" value="' . e($s) . '">';
$TITULOS = ['inicio' => 'Inicio', 'buscar' => 'Match', 'partidos' => 'Partidos', 'horarios' => 'Mis horarios', 'avisos' => 'Notificaciones'];
/** Nube de ayuda (ⓘ): la explicación flota sólo cuando se toca. $html ya viene escapado. */
$ayuda = fn(string $html, string $etq = 'Más información'): string =>
    '<span class="nube-w"><button type="button" class="nube-btn" aria-label="' . e($etq) . '" aria-expanded="false">i</button><span class="nube" role="tooltip" hidden>' . $html . '</span></span>';

/* ---------- MATCH: horarios de rivales que coinciden con los míos ---------- */
$misDisp = [];
if ($P && $P['mia']) foreach ($P['mia']['opciones'] as $o) if ($o['efectivo'] === 'vigente') $misDisp[$o['fecha'] . ' ' . $h($o['hora'])] = true;
$motivoNoDe = function (array $r) use ($P, $X): string {
    if ($X && array_key_exists((int)$r['equipo_id'], $X['yaJugo'])) return 'Ya jugaste contra este equipo';
    if ($r['con_partido']) return 'Ya tienen partido';
    if ($r['mi_solicitud']) return 'Ya le propusiste';
    if (!$P['puedoSolicitar']) return 'Tienes ' . RIVAL_MAX_SOLICITUDES . ' propuestas en espera';
    return '';
};
$coinc = [];
if ($P) foreach ($P['rivales'] as $r) {
    $mn = $motivoNoDe($r);
    foreach ($r['opciones'] as $o) {
        if (isset($misDisp[$o['fecha'] . ' ' . $h($o['hora'])])) $coinc[] = ['o' => $o, 'r' => $r, 'no' => $mn];
    }
}
usort($coinc, fn($a, $b) => [$a['no'] !== '', $a['o']['fecha'] . $a['o']['hora']] <=> [$b['no'] !== '', $b['o']['fecha'] . $b['o']['hora']]);
$nCoincLibres = count(array_filter($coinc, fn($c) => $c['no'] === ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($TITULOS[$sec]) ?> · <?= $eq ? e($eq['nombre']) . ' · ' : '' ?>Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="assets/disponibilidad.css?v=20260929">
    <link rel="stylesheet" href="assets/rival.css?v=20260929b">
    <script src="assets/rival.js?v=20260929b" defer></script>
    <script src="assets/push.js?v=20260929" defer></script>
    <script src="assets/nube.js?v=20260929b" defer></script>
    <link rel="manifest" href="../capitanes/manifest.webmanifest">
    <meta name="theme-color" content="#123B63">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../icono-180.png">
</head>
<body class="app-rival rv-s-<?= e($sec) ?><?= $ctx['rol'] === 'capitan' && !$error ? ' con-nav' : '' ?>"<?= $eq ? ' data-version="' . e(rival_version($eq)) . '"' : '' ?>>
<?php if (es_entorno_pruebas()): ?>
<div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS<?= ($ctx['origen'] ?? '') === 'simulacion' ? ' · simulando capitán' : '' ?></div>
<?php endif; ?>

<!-- ===== Encabezado compacto ===== -->
<header class="rv-cab" id="arriba">
    <div class="rv-cab__in">
        <div class="rv-cab__fila">
            <div class="rv-marca">
                <span class="rv-marca__logo" aria-hidden="true">LC</span>
                <span class="rv-marca__nombre">Liga Chapingo</span>
            </div>
            <?php if ($eq && $P): ?>
            <div class="rv-cab__acc">
                <a class="rv-cab__btn" href="rival.php?s=avisos" aria-label="Notificaciones<?= $insigniaAvisos ? ': ' . $insigniaAvisos . ' nuevas' : '' ?>"><?= $ICO['campana'] ?><?php if ($insigniaAvisos): ?><span class="rv-insignia" aria-hidden="true"><?= $insigniaAvisos > 9 ? '9+' : $insigniaAvisos ?></span><?php endif; ?></a>
                <a class="rv-cab__perfil" href="rival.php?s=inicio#equipo" aria-label="Tu equipo: <?= e($eq['nombre']) ?>"><?= e($iniciales ?: 'LC') ?></a>
            </div>
            <?php endif; ?>
        </div>
        <div class="rv-cab__titulo"><?= e($TITULOS[$sec]) ?>
            <?php if ($eq && $P): ?><?= $ayuda('<b>Así se hace match:</b><ol><li><b>Mis horarios:</b> elige cuándo puedes jugar (mínimo ' . RIVAL_MIN_HORARIOS . ', hasta ' . RIVAL_MAX_POR_DIA . ' por día).</li><li><b>Match:</b> los horarios con ★ coinciden con los tuyos. Tócalo para proponer el partido.</li><li>El otro equipo confirma y la cancha queda apartada.</li><li>Ya confirmado, sólo se reprograma o se pide cancelar.</li></ol>', 'Cómo funciona') ?><?php endif; ?></div>
        <?php if ($catTxt !== ''): ?><div class="rv-cab__sub"><?= e($catTxt) ?><?= $sec !== 'inicio' && $eq ? ' · ' . e($eq['nombre']) : '' ?></div><?php endif; ?>
    </div>
</header>

<main class="rv">
<?php foreach ($mensajes as $m): ?>
    <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(str_replace(['a.m..', 'p.m..'], ['a.m.', 'p.m.'], implode(' ', $m['mensajes']))) ?></div>
<?php endforeach; ?>

<?php if ($ctx['rol'] !== 'capitan'): ?>
    <section class="rv-sec rv-sec--centro">
        <h2 class="rv-sec__titulo">Entra desde tu Estado de cuenta</h2>
        <p>Abre tu <strong>Estado de cuenta</strong> y pulsa <strong>«Busca un rival»</strong>.</p>
        <a class="btn btn--pri" href="<?= e(url_retorno_segura(CAPITAN_RETORNO_DEF)) ?>">Ir a mi estado de cuenta</a>
    </section>
<?php elseif ($error): ?>
    <div class="aviso aviso--error" role="alert"><?= e($error) ?></div>
<?php elseif ($sec === 'inicio'): ?>
<?php /* ===================== INICIO: lo esencial para hacer match ===================== */ ?>
    <section class="rv-equipo rv-equipo--mini" id="equipo">
        <h1 class="rv-equipo__nombre"><?= e($eq['nombre']) ?></h1>
        <p class="rv-equipo__meta"><?php if ($catTxt !== ''): ?><span><?= e($catTxt) ?></span><?php endif; ?></p>
    </section>

    <?php if ($nPorConfirmar): ?>
    <a class="rv-cta rv-cta--alerta" href="rival.php?s=buscar">
        <span class="rv-cta__ico"><?= $ICO['rayo'] ?></span>
        <span class="rv-cta__txt"><b><?= $nPorConfirmar ?> por confirmar</b><small>Te eligieron o la liga te propone un partido</small></span>
        <span aria-hidden="true">→</span>
    </a>
    <?php endif; ?>

    <a class="rv-cta" href="rival.php?s=<?= $P['mia'] ? 'buscar' : 'horarios' ?>">
        <span class="rv-cta__ico"><?= $ICO['rayo'] ?></span>
        <span class="rv-cta__txt">
            <?php if (!$P['mia']): ?><b>Elige tus horarios</b><small>Es el primer paso para hacer match</small>
            <?php elseif ($nCoincLibres): ?><b><?= $nCoincLibres ?> match disponible<?= $nCoincLibres === 1 ? '' : 's' ?> ★</b><small>Horarios que coinciden con otros equipos</small>
            <?php else: ?><b>Hacer match</b><small>Ver rivales de tu grupo</small><?php endif; ?>
        </span>
        <span aria-hidden="true">→</span>
    </a>

    <?php if ($P['confirmados']): $r0 = $P['confirmados'][0]; [$hh, $ap] = $horaTxt($r0['hora']); ?>
    <a class="rv-proximo rv-proximo--link" href="rival.php?s=partidos">
        <div class="rv-proximo__cab">
            <h2 class="rv-proximo__eti">Próximo partido</h2>
            <span class="rv-sello">Confirmado <span aria-hidden="true">✓</span></span>
        </div>
        <div class="rv-partido">
            <div class="rv-proximo__cuando">
                <span class="rv-proximo__dia"><?= e($diaTxt($r0['fecha'], true)) ?></span>
                <span class="rv-proximo__hora"><?= e($hh) ?><small><?= e($ap) ?></small></span>
            </div>
            <div class="rv-proximo__vs"><b><?= e($eq['nombre']) ?></b><span class="rv-proximo__x">VS</span><b><?= e($r0['rival']) ?></b></div>
            <p class="rv-proximo__cancha"><?= $ICO['pin'] ?> <?= e(RIVAL_CANCHA) ?></p>
        </div>
    </a>
    <?php endif; ?>

    <?php
    $alertas = [];
    if ($P['mia'] && $faltan) $alertas[] = ['Agrega ' . $faltan . ' horario' . ($faltan === 1 ? '' : 's'), 'horarios'];
    if ($P['mia']) {
        $malos = count(array_filter($P['mia']['opciones'], fn($o) => in_array($o['efectivo'], ['ocupada', 'fuera'], true)));
        if ($malos) $alertas[] = [$malos . ' horario' . ($malos === 1 ? '' : 's') . ' ya no disponible' . ($malos === 1 ? '' : 's'), 'horarios'];
    }
    foreach ($X['cancelados'] as $c) $alertas[] = ['Cancelado: vs ' . $c['rival'], 'partidos'];
    ?>
    <?php if ($alertas): ?>
    <div class="rv-alertas">
        <?php foreach ($alertas as [$t, $ir]): ?><a class="rv-alerta-chip" href="rival.php?s=<?= e($ir) ?>">⚠ <?= e($t) ?> →</a><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="rv-accesos">
        <a class="rv-acceso" href="<?= e($retorno) ?>"><?= $ICO['cuenta'] ?><span>Estado de cuenta</span></a>
        <a class="rv-acceso" href="<?= e(RIVAL_COPAFACIL) ?>" target="_blank" rel="noopener noreferrer"><?= $ICO['trofeo'] ?><span>Copa Fácil ↗</span></a>
    </div>

    <?php if ($esSimulacion): ?>
    <form method="post" action="salir.php" class="form-volver"><?= csrf_campo() ?><button type="submit" class="btn btn--sec btn--ancho">Salir de la simulación (pruebas)</button></form>
    <?php endif; ?>

<?php elseif ($sec === 'buscar'): ?>
<?php /* ===================== MATCH ===================== */
    $propPendMias = array_values(array_filter($X['propuestas'], fn($p) => $p['mi_resp'] === 'pendiente'));
    $propEsperan  = array_values(array_filter($X['propuestas'], fn($p) => $p['mi_resp'] !== 'pendiente'));
?>
    <?php if (!$P['mia']): ?>
        <a class="rv-cta" href="rival.php?s=horarios"><span class="rv-cta__ico"><?= $ICO['reloj'] ?></span><span class="rv-cta__txt"><b>Primero elige tus horarios</b><small>Así verás con quién coincides</small></span><span aria-hidden="true">→</span></a>
    <?php endif; ?>

    <?php if (($P['mia'] && $P['mia']['solicitudes']) || $propPendMias): ?>
    <section class="rv-bloque" aria-labelledby="t-te">
        <h2 id="t-te" class="rv-bloque__t">Por confirmar</h2>
        <?php foreach (($P['mia']['solicitudes'] ?? []) as $s): [$hh, $ap] = $horaTxt($s['hora']); ?>
            <article class="rv-match" data-match="<?= (int)$s['id'] ?>">
                <div class="rv-match__cab"><span class="rv-match__rayo"><?= $ICO['rayo'] ?> MATCH</span><span class="rv-match__lema">Te eligieron</span></div>
                <div class="rv-match__vs"><b><?= e($eq['nombre']) ?></b><span class="rv-match__x" aria-hidden="true">×</span><span class="sr-only">contra</span><b><?= e($s['equipo_nombre']) ?></b></div>
                <p class="rv-match__cuando"><?= e($diaTxt($s['fecha'])) ?> · <?= e($hh) ?> <?= e($ap) ?></p>
                <form method="post" action="rival-accion.php" class="rv-match__acc">
                    <?= csrf_campo() ?><?= $vol('buscar') ?><input type="hidden" name="solicitud" value="<?= (int)$s['id'] ?>">
                    <button type="submit" name="accion" value="confirmar" class="btn btn--pri" data-confirmar="¿Confirmar contra <?= e($s['equipo_nombre']) ?> el <?= e(rival_slot_texto($s['fecha'], $s['hora'])) ?>?">Confirmar</button>
                    <button type="submit" name="accion" value="rechazar" class="btn btn--sec" data-confirmar="¿Rechazar?">Rechazar</button>
                </form>
            </article>
        <?php endforeach; ?>
        <?php foreach ($propPendMias as $p): [$hh, $ap] = $horaTxt($p['hora']); ?>
            <article class="rv-match rv-match--liga">
                <div class="rv-match__cab"><span class="rv-match__rayo"><?= $ICO['rayo'] ?> MATCH</span><span class="rv-match__lema">Propuesto por la liga</span></div>
                <div class="rv-match__vs"><b><?= e($eq['nombre']) ?></b><span class="rv-match__x" aria-hidden="true">×</span><b><?= e($p['rival']) ?></b></div>
                <p class="rv-match__cuando"><?= e($diaTxt($p['fecha'])) ?> · <?= e($hh) ?> <?= e($ap) ?><?= $p['su_resp'] === 'aceptada' ? ' · ya aceptó ✓' : '' ?></p>
                <form method="post" action="rival-accion.php" class="rv-match__acc">
                    <?= csrf_campo() ?><?= $vol('buscar') ?><input type="hidden" name="propuesta" value="<?= (int)$p['id'] ?>">
                    <button type="submit" name="accion" value="propuesta_aceptar" class="btn btn--pri" data-confirmar="¿Aceptar contra <?= e($p['rival']) ?> el <?= e(rival_slot_texto($p['fecha'], $p['hora'])) ?>?">Aceptar</button>
                    <button type="submit" name="accion" value="propuesta_rechazar" class="btn btn--sec" data-confirmar="¿Rechazar?">Rechazar</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($P['mia']): ?>
    <section class="rv-bloque" aria-labelledby="t-co">
        <h2 id="t-co" class="rv-bloque__t">★ Coinciden contigo <?= $ayuda('Son horarios que tú <b>y</b> otro equipo de tu grupo eligieron. Toca «Proponer»: si el otro equipo confirma, el partido queda listo.') ?></h2>
        <?php if (!$coinc): ?>
            <p class="rv-vacio-txt">Aún no hay coincidencias. <a href="rival.php?s=horarios">Agrega horarios</a> o elige uno abajo.</p>
        <?php else: ?>
        <form method="post" action="rival-accion.php" class="rv-coincs">
            <?= csrf_campo() ?><?= $vol('buscar') ?><input type="hidden" name="accion" value="solicitar">
            <?php foreach ($coinc as $c): $o = $c['o']; $r = $c['r']; [$hh, $ap] = $horaTxt($o['hora']); ?>
            <div class="rv-coinc-card <?= $c['no'] !== '' ? 'is-off' : '' ?>">
                <div class="rv-coinc-card__cuando"><span><?= e($diaTxt($o['fecha'])) ?></span><b><?= e($hh) ?> <small><?= e($ap) ?></small></b></div>
                <div class="rv-coinc-card__rival"><b><?= e($r['equipo_nombre']) ?></b><?php if ($c['no'] !== ''): ?><small><?= e($c['no']) ?></small><?php endif; ?></div>
                <button type="submit" name="opcion" value="<?= (int)$o['id'] ?>" class="btn btn--pri btn--chico rv-hora--coinc" <?= $c['no'] !== '' ? 'disabled' : '' ?>
                        data-confirmar="¿Proponer partido contra <?= e($r['equipo_nombre']) ?> el <?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?>?">Proponer</button>
            </div>
            <?php endforeach; ?>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($P['misSolicitudes'] || $propEsperan): ?>
    <section class="rv-bloque rv-bloque--suave" aria-labelledby="t-sp">
        <h2 id="t-sp" class="rv-bloque__t">Esperando respuesta</h2>
        <?php foreach ($P['misSolicitudes'] as $s): ?>
            <div class="rv-sol">
                <div class="rv-sol__txt"><b><?= e($s['rival']) ?></b><span><?= e(rival_slot_texto($s['fecha'], $s['hora'])) ?></span></div>
                <form method="post" action="rival-accion.php" class="rv-sol__acc">
                    <?= csrf_campo() ?><?= $vol('buscar') ?><input type="hidden" name="solicitud" value="<?= (int)$s['id'] ?>">
                    <button type="submit" name="accion" value="cancelar_solicitud" class="btn-texto btn-texto--peligro" data-confirmar="¿Cancelar tu propuesta a <?= e($s['rival']) ?>?">Cancelar</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php foreach ($propEsperan as $p): ?>
            <div class="rv-sol"><div class="rv-sol__txt"><b><?= e($p['rival']) ?> · liga</b><span><?= e(rival_slot_texto($p['fecha'], $p['hora'])) ?> · ya aceptaste</span></div></div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php
    $rivales = $P['rivales'];
    foreach ($rivales as &$r) $r['coinciden'] = count(array_filter($r['opciones'], fn($o) => isset($misDisp[$o['fecha'] . ' ' . $h($o['hora'])])));
    unset($r);
    usort($rivales, fn($a, $b) => [$b['coinciden'], count($b['opciones'])] <=> [$a['coinciden'], count($a['opciones'])]);
    $diasRiv = [];
    foreach ($rivales as $r) foreach ($r['opciones'] as $o) $diasRiv[$o['fecha']] = true;
    ksort($diasRiv);
    ?>
    <section class="rv-bloque" id="rivales">
        <details class="rv-todos" <?= !$coinc ? 'open' : '' ?>>
            <summary><span class="rv-bloque__t">Todos los horarios de tu grupo</span> <span class="rv-cuenta"><?= count($rivales) ?> equipos</span></summary>
            <?php if (!$rivales): ?>
                <p class="rv-vacio-txt">Ningún equipo de tu grupo tiene horarios todavía.</p>
            <?php else: ?>
            <div class="rv-filtros" id="rv-filtros" hidden>
                <div class="rv-chips" role="group" aria-label="Filtrar por día">
                    <button type="button" class="rv-chip is-activo" data-dia="">Todos</button>
                    <?php foreach (array_keys($diasRiv) as $f): ?><button type="button" class="rv-chip" data-dia="<?= e($f) ?>"><?= e($diaTxt($f)) ?></button><?php endforeach; ?>
                </div>
            </div>
            <ul class="rv-rivales rv-rivales--compacto">
            <?php foreach ($rivales as $r):
                $motivoNo = $motivoNoDe($r);
                $porDia = [];
                foreach ($r['opciones'] as $o) $porDia[$o['fecha']][] = $o; ?>
            <li class="rv-rival" data-coincide="<?= $r['coinciden'] ? '1' : '0' ?>">
                <div class="rv-rival__cab"><span class="rv-rival__nombre"><?= e($r['equipo_nombre']) ?></span><?php if ($motivoNo !== ''): ?><small class="rv-rival__nota"><?= e($motivoNo) ?></small><?php endif; ?></div>
                <form method="post" action="rival-accion.php" class="rv-rival__form">
                    <?= csrf_campo() ?><?= $vol('buscar') ?><input type="hidden" name="accion" value="solicitar">
                    <?php foreach ($porDia as $f => $ops): ?>
                    <div class="rv-fila-dia" data-fecha="<?= e($f) ?>">
                        <span class="rv-fila-dia__d"><?= e($diaTxt($f)) ?></span>
                        <span class="rv-fila-dia__h">
                        <?php foreach ($ops as $o): [$hh, $ap] = $horaTxt($o['hora']); $co = isset($misDisp[$o['fecha'] . ' ' . $h($o['hora'])]); ?>
                            <button type="submit" name="opcion" value="<?= (int)$o['id'] ?>" class="rv-hora <?= $co ? 'rv-hora--coinc' : '' ?>" data-coincide="<?= $co ? '1' : '0' ?>"
                                    <?= $motivoNo !== '' ? 'disabled title="' . e($motivoNo) . '"' : '' ?>
                                    aria-label="Proponer partido contra <?= e($r['equipo_nombre']) ?> el <?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?>"
                                    data-confirmar="¿Proponer partido contra <?= e($r['equipo_nombre']) ?> el <?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?>?"><?= $co ? '★ ' : '' ?><?= e($hh) ?> <small><?= e($ap) ?></small></button>
                        <?php endforeach; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </form>
            </li>
            <?php endforeach; ?>
            </ul>
            <p class="rv-vacio-txt" id="rv-filtro-vacio" hidden>Nada ese día.</p>
            <?php endif; ?>
        </details>
    </section>

<?php elseif ($sec === 'partidos'): ?>
<?php /* ===================== PARTIDOS ===================== */ ?>
    <?php if ($P['reprogParaMi']): ?>
    <section class="rv-bloque" aria-labelledby="t-rp">
        <h2 id="t-rp" class="rv-bloque__t">Te proponen cambiar horario</h2>
        <?php foreach ($P['reprogParaMi'] as $r): $rp = $r['reprog']; $opsRp = $v8 ? rival_reprog_opciones_de((int)$rp['id']) : [];
            if (!$opsRp) $opsRp = [['fecha' => $rp['fecha_nueva'], 'hora' => $rp['hora_nueva'], 'clave' => $rp['fecha_nueva'] . ' ' . $h($rp['hora_nueva'])]]; ?>
            <article class="rv-sol rv-sol--reprog">
                <div class="rv-sol__txt">
                    <b><?= e($rp['solicita_nombre']) ?></b>
                    <span>Ahora: <s><?= e(rival_slot_texto($rp['fecha_anterior'], $rp['hora_anterior'])) ?></s><?= $rp['motivo'] ? ' ' . $ayuda('Motivo: ' . e($rp['motivo'])) : '' ?></span>
                </div>
                <form method="post" action="rival-accion.php" class="rv-sol__acc rv-elige">
                    <?= csrf_campo() ?><?= $vol('partidos') ?><input type="hidden" name="reprog" value="<?= (int)$rp['id'] ?>">
                    <fieldset class="rv-elige__ops">
                        <legend>Elige el nuevo horario</legend>
                        <?php foreach ($opsRp as $k => $o): ?>
                            <label class="rv-radio"><input type="radio" name="elegido" value="<?= e($o['clave']) ?>" <?= $k === 0 ? 'checked' : '' ?> required> <span><?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <button type="submit" name="accion" value="reprog_aceptar" class="btn btn--pri" data-confirmar="¿Cambiar el partido al horario elegido?">Aceptar</button>
                    <button type="submit" name="accion" value="reprog_rechazar" class="btn btn--sec" formnovalidate data-confirmar="¿Rechazar? El partido sigue en su horario.">Rechazar</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php if ($nPorConfirmar - count($P['reprogParaMi']) > 0): ?>
        <a class="rv-cta rv-cta--alerta" href="rival.php?s=buscar"><span class="rv-cta__ico"><?= $ICO['rayo'] ?></span><span class="rv-cta__txt"><b><?= $nPorConfirmar - count($P['reprogParaMi']) ?> match por confirmar</b></span><span aria-hidden="true">→</span></a>
    <?php endif; ?>

    <section class="rv-bloque" id="confirmados" aria-labelledby="t-conf">
        <h2 id="t-conf" class="rv-bloque__t">Confirmados <?= $ayuda('Un partido confirmado no se borra: desde «Opciones» puedes <b>reprogramarlo</b> (propones de 1 a ' . RIVAL_REPROG_MAX . ' horarios y tu rival elige, con ' . RIVAL_REPROG_HORAS . ' h de anticipación), <b>pedir la cancelación</b> a la liga o <b>reportar un problema</b>.') ?></h2>
        <?php if (!$P['confirmados']): ?><p class="rv-vacio-txt">Sin partidos confirmados. <a href="rival.php?s=buscar">Hacer match →</a></p><?php endif; ?>
        <?php foreach ($P['confirmados'] as $r): [$hh, $ap] = $horaTxt($r['hora']); $rp = $r['reprog']; $pp = $X['petPend'][(int)$r['id']] ?? []; ?>
            <article class="rv-conf">
                <div class="rv-conf__cuando"><span><?= e($diaTxt($r['fecha'])) ?></span><b><?= e($hh) ?> <small><?= e($ap) ?></small></b></div>
                <div class="rv-conf__vs"><span>vs</span> <b><?= e($r['rival']) ?></b><small><?= $ICO['pin'] ?> <?= e(RIVAL_CANCHA) ?></small></div>
                <?php if ($rp && (int)$rp['solicita_equipo_id'] === $eq['equipo_id']): ?>
                    <div class="rv-reprog-estado">Cambio propuesto · falta que <?= e($r['rival']) ?> elija.
                        <form method="post" action="rival-accion.php"><?= csrf_campo() ?><?= $vol('partidos') ?><input type="hidden" name="reprog" value="<?= (int)$rp['id'] ?>">
                            <button type="submit" name="accion" value="reprog_cancelar" class="btn-texto btn-texto--peligro" data-confirmar="¿Retirar tu propuesta de cambio?">Retirar</button></form>
                    </div>
                <?php elseif ($rp): ?>
                    <div class="rv-reprog-estado"><?= e($r['rival']) ?> propone otro horario (arriba).</div>
                <?php endif; ?>
                <?php if (!empty($pp['cancelacion'])): ?><div class="rv-reprog-estado">Cancelación en revisión · el partido sigue en pie.</div><?php endif; ?>

                <details class="rv-opciones rv-form">
                    <summary>Opciones <span aria-hidden="true">⋯</span></summary>
                    <div class="rv-opciones__lista">
                    <?php if (!$rp && !$r['empezo'] && $r['reprog_a_tiempo']): ?>
                    <details class="rv-reprog rv-form">
                        <summary>Solicitar reprogramación</summary>
                        <form method="post" action="rival-accion.php" class="rv-reprog__form js-slots" data-min="1" data-max-total="<?= $v8 ? RIVAL_REPROG_MAX : 1 ?>">
                            <?= csrf_campo() ?><?= $vol('partidos') ?><input type="hidden" name="accion" value="reprog_proponer"><input type="hidden" name="reserva" value="<?= (int)$r['id'] ?>">
                            <div class="rv-dias rv-dias--mini"><?php $rejilla('reprog'); ?></div>
                            <input type="text" name="motivo" maxlength="200" placeholder="Motivo (opcional)" aria-label="Motivo">
                            <div class="rv-barra rv-barra--plana">
                                <span class="rv-contador" aria-live="polite">0 elegidos</span>
                                <button type="submit" class="btn btn--pri">Enviar</button>
                            </div>
                        </form>
                    </details>
                    <?php elseif (!$rp && !$r['empezo']): ?>
                        <p class="rv-reprog-no">Faltan menos de <?= RIVAL_REPROG_HORAS ?> h: ya no se puede reprogramar.</p>
                    <?php endif; ?>
                    <?php if ($v8 && !$r['empezo'] && empty($pp['cancelacion'])): ?>
                    <details class="rv-reprog rv-form">
                        <summary>Pedir cancelación</summary>
                        <form method="post" action="rival-accion.php" class="rv-reprog__form">
                            <?= csrf_campo() ?><?= $vol('partidos') ?><input type="hidden" name="accion" value="peticion"><input type="hidden" name="tipo" value="cancelacion"><input type="hidden" name="reserva" value="<?= (int)$r['id'] ?>">
                            <textarea name="mensaje" rows="2" maxlength="500" minlength="5" required placeholder="¿Por qué?" aria-label="Motivo de la cancelación"></textarea>
                            <button type="submit" class="btn btn--peligro" data-confirmar="¿Enviar la solicitud de cancelación a la liga?">Enviar a la liga</button>
                        </form>
                    </details>
                    <?php endif; ?>
                    <?php if ($v8): ?>
                    <details class="rv-reprog rv-form">
                        <summary>Reportar un problema</summary>
                        <form method="post" action="rival-accion.php" class="rv-reprog__form">
                            <?= csrf_campo() ?><?= $vol('partidos') ?><input type="hidden" name="accion" value="peticion"><input type="hidden" name="tipo" value="problema"><input type="hidden" name="reserva" value="<?= (int)$r['id'] ?>">
                            <textarea name="mensaje" rows="2" maxlength="500" minlength="5" required placeholder="¿Qué pasó?" aria-label="Describe el problema"></textarea>
                            <button type="submit" class="btn btn--sec">Enviar reporte</button>
                        </form>
                    </details>
                    <?php endif; ?>
                    </div>
                </details>
            </article>
        <?php endforeach; ?>
    </section>

    <?php
    $histJ = [];
    foreach ($X['jugados'] as $j) $histJ[] = ['cuando' => rival_slot_texto($j['fecha'], $j['hora']), 'orden' => $j['fecha'] . ' ' . $j['hora'], 'rival' => $j['rival']];
    foreach ($X['historial'] as $j) $histJ[] = ['cuando' => $j['fecha'] ? rival_dia_texto((string)$j['fecha']) : '—', 'orden' => (string)($j['fecha'] ?? ''), 'rival' => $j['rival']];
    usort($histJ, fn($a, $b) => strcmp($b['orden'], $a['orden']));
    ?>
    <section class="rv-bloque" id="jugados">
        <details class="rv-todos">
            <summary><span class="rv-bloque__t">Jugados · historial</span> <span class="rv-cuenta"><?= count($histJ) ?></span></summary>
            <?php if (!$histJ): ?><p class="rv-vacio-txt">Aún no hay partidos jugados.</p><?php else: ?>
            <ul class="rv-lista"><?php foreach ($histJ as $j): ?><li><span><?= e($j['cuando']) ?></span><b>vs <?= e($j['rival']) ?></b></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <p class="rv-vacio-txt"><a href="<?= e(RIVAL_COPAFACIL) ?>" target="_blank" rel="noopener noreferrer">Resultados y tabla en Copa Fácil ↗</a></p>
        </details>
        <?php if ($X['cancelados']): ?>
        <details class="rv-todos">
            <summary><span class="rv-bloque__t">Cancelados</span> <span class="rv-cuenta"><?= count($X['cancelados']) ?></span></summary>
            <ul class="rv-lista rv-lista--cancel"><?php foreach ($X['cancelados'] as $c): ?><li><span><s><?= e(rival_slot_texto($c['fecha'], $c['hora'])) ?></s></span><b>vs <?= e($c['rival']) ?></b><small><?= e((string)$c['motivo_cancelacion']) ?></small></li><?php endforeach; ?></ul>
        </details>
        <?php endif; ?>
        <?php if ($v8): ?>
        <details class="rv-todos">
            <summary><span class="rv-bloque__t">Con la liga</span><?php $pend = count(array_filter($X['peticiones'], fn($p) => $p['estado'] === 'pendiente')); if ($pend): ?> <span class="rv-cuenta"><?= $pend ?> en revisión</span><?php endif; ?></summary>
            <?php if ($X['peticiones']): $ETP = ['pendiente' => 'En revisión', 'aprobada' => 'Aprobada', 'rechazada' => 'No aprobada', 'atendida' => 'Atendida']; ?>
            <ul class="rv-lista rv-lista--pet"><?php foreach ($X['peticiones'] as $p): ?>
                <li><span><?= $p['tipo'] === 'cancelacion' ? 'Cancelación' : 'Reporte' ?> · <?= e($haceTxt($p['hace_s'])) ?></span><b class="rv-est rv-est--<?= e($p['estado']) ?>"><?= e($ETP[$p['estado']] ?? $p['estado']) ?></b><?php if ($p['respuesta']): ?><small><?= e($p['respuesta']) ?></small><?php endif; ?></li>
            <?php endforeach; ?></ul>
            <?php endif; ?>
            <form method="post" action="rival-accion.php" class="rv-reprog__form">
                <?= csrf_campo() ?><?= $vol('partidos') ?><input type="hidden" name="accion" value="peticion"><input type="hidden" name="tipo" value="problema">
                <textarea name="mensaje" rows="2" maxlength="500" minlength="5" required placeholder="Reportar un problema a la liga…" aria-label="Reportar un problema"></textarea>
                <button type="submit" class="btn btn--sec">Enviar reporte</button>
            </form>
        </details>
        <?php endif; ?>
    </section>

<?php elseif ($sec === 'horarios'): ?>
<?php /* ===================== MIS HORARIOS ===================== */ ?>
    <section class="rv-bloque" aria-labelledby="t-mias" id="mis-opciones">
        <h2 id="t-mias" class="rv-bloque__t">Tus horarios
            <span class="rv-cuenta <?= $faltan ? 'rv-cuenta--alerta' : '' ?>"><?= $nActivas ?> / mín. <?= RIVAL_MIN_HORARIOS ?></span>
            <?= $ayuda('Mínimo <b>' . RIVAL_MIN_HORARIOS . '</b> en total y hasta <b>' . RIVAL_MAX_POR_DIA . ' por día</b>, en los próximos ' . RIVAL_DIAS . ' días. Entre más horarios, más match. Toca ✕ para quitar uno (los demás se quedan).') ?></h2>
        <?php if ($P['mia'] && $faltan): ?><p class="rv-alerta" role="status">⚠ Agrega <?= $faltan ?> más.</p><?php endif; ?>
        <?php if ($P['mia'] && $misPorDia): ksort($misPorDia); ?>
            <div class="rv-mis">
            <?php foreach ($misPorDia as $f => $ops): ?>
                <div class="rv-mis__dia">
                    <h3 class="rv-mis__fecha"><?= e($diaTxt($f)) ?></h3>
                    <ul class="rv-mis__lista">
                    <?php foreach ($ops as $o):
                        $mal = in_array($o['efectivo'], ['ocupada', 'fuera'], true);
                        $conf = $o['efectivo'] === 'confirmada';
                        [$hh, $ap] = $horaTxt($o['hora']);
                        $bloq = !$mal && !$conf && $nActivas <= RIVAL_MIN_HORARIOS; ?>
                        <li class="rv-mi <?= $mal ? 'rv-mi--mal' : '' ?> <?= $conf ? 'rv-mi--ok' : '' ?>">
                            <span class="rv-mi__hora"><?= e($hh) ?> <small><?= e($ap) ?></small></span>
                            <span class="rv-mi__et"><?php
                                if ($conf) {
                                    $pt = $o['partido'] ?? null;
                                    echo $pt ? e('vs ' . ((int)$pt['equipo_a_id'] === $eq['equipo_id'] ? $pt['equipo_b_nombre'] : $pt['equipo_a_nombre'])) : 'Partido';
                                } elseif ($mal) echo e($o['efectivo'] === 'fuera' ? 'Sin cancha' : 'Ocupado');
                            ?></span>
                            <?php if (!$conf): ?>
                            <form method="post" action="rival-accion.php" class="rv-mi__quitar">
                                <?= csrf_campo() ?><?= $vol('horarios') ?><input type="hidden" name="accion" value="eliminar_horario"><input type="hidden" name="opcion" value="<?= (int)$o['id'] ?>">
                                <button type="submit" class="rv-x" <?= $bloq ? 'disabled title="Necesitas al menos ' . RIVAL_MIN_HORARIOS . '. Agrega otro primero."' : '' ?>
                                        aria-label="Quitar <?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?>"
                                        data-confirmar="¿Quitar el <?= e(rival_slot_texto($o['fecha'], $o['hora'])) ?>?"><?= $ICO['x'] ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if ($mal): ?>
                            <form method="post" action="rival-accion.php" class="rv-reemplazo">
                                <?= csrf_campo() ?><?= $vol('horarios') ?><input type="hidden" name="accion" value="reemplazar"><input type="hidden" name="opcion" value="<?= (int)$o['id'] ?>">
                                <select name="slot" required aria-label="Nuevo horario">
                                    <option value="">Cambiar por…</option>
                                    <?php foreach ($libres as $fl => $sls): foreach ($sls as $sl): if (isset($misClaves[$sl['clave']])) continue; ?>
                                        <option value="<?= e($sl['clave']) ?>"><?= e(rival_slot_texto($fl, $sl['hora'])) ?></option>
                                    <?php endforeach; endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn--pri btn--chico">Cambiar</button>
                            </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            </div>
        <?php elseif (!$P['mia']): ?>
            <p class="rv-vacio-txt">Aún no tienes horarios. Elige al menos <?= RIVAL_MIN_HORARIOS ?>.</p>
        <?php endif; ?>
    </section>

    <section class="rv-bloque" id="agregar">
        <details class="rv-agregar rv-form" <?= !$P['mia'] || $faltan ? 'open' : '' ?>>
            <summary class="btn btn--pri btn--ancho">+ <?= $P['mia'] ? 'Agregar horarios' : 'Elegir mis horarios' ?></summary>
            <?php $minNuevo = $P['mia'] ? 1 : RIVAL_MIN_HORARIOS; ?>
            <form method="post" action="rival-accion.php" class="rv-cal js-slots" id="form-publicar" data-min="<?= $minNuevo ?>" data-max-dia="<?= RIVAL_MAX_POR_DIA ?>">
                <?= csrf_campo() ?><?= $vol('horarios') ?><input type="hidden" name="accion" value="agregar">
                <div class="rv-dias"><?php $rejilla('agregar'); ?></div>
                <div class="rv-barra">
                    <span class="rv-contador" aria-live="polite">0 elegidos</span>
                    <button type="submit" class="btn btn--pri"><?= $P['mia'] ? 'Agregar' : 'Publicar' ?></button>
                </div>
            </form>
        </details>
    </section>

<?php elseif ($sec === 'avisos'): ?>
<?php /* ===================== 🔔 ===================== */ ?>
    <?php if ($pushVapid): ?>
    <section class="rv-push" id="rv-push" data-vapid="<?= e($pushVapid) ?>" aria-labelledby="t-push">
        <form id="rv-push-form" hidden><?= csrf_campo() ?></form>
        <div class="rv-push__fila">
            <span class="rv-push__ico" aria-hidden="true"><?= $ICO['campana'] ?></span>
            <div class="rv-push__txt">
                <h2 id="t-push" class="rv-push__titulo"><span class="rv-push__on">Avisos activados <span aria-hidden="true">✓</span></span><span class="rv-push__off">Avisos en tu celular</span>
                    <?= $ayuda('Te avisamos de solicitudes, confirmaciones, cambios, cancelaciones y recordatorios. Se activan en cada celular por separado.') ?></h2>
                <p class="rv-push__n" id="rv-push-n" data-n="<?= (int)$pushDisp ?>"><?= $pushDisp ? (int)$pushDisp . ' dispositivo' . ($pushDisp === 1 ? '' : 's') : '' ?></p>
            </div>
            <button type="button" class="btn btn--pri btn--chico" id="rv-push-activar" hidden>Activar</button>
        </div>
        <p id="rv-push-txt" class="rv-nota"></p>
        <details class="rv-push__cfg">
            <summary>Configurar <span aria-hidden="true">→</span></summary>
            <div class="rv-push__acc">
                <button type="button" class="btn btn--sec btn--chico" id="rv-push-prueba" hidden>Probar</button>
                <button type="button" class="btn btn--peligro btn--chico" id="rv-push-baja" hidden>Desactivar</button>
            </div>
        </details>
    </section>
    <?php endif; ?>

    <section class="rv-bloque" aria-labelledby="t-not">
        <h2 id="t-not" class="rv-bloque__t">Notificaciones</h2>
        <?php if (!$notifs): ?>
            <p class="rv-vacio-txt">Sin notificaciones.</p>
        <?php else: ?>
            <ul class="rv-notifs">
            <?php foreach ($notifs as $n):
                $url = (string)$n['url'];
                $url = strpos($url, '/disponibilidad/') === 0 ? substr($url, strlen('/disponibilidad/')) : 'rival.php';
                if (!preg_match('/^rival\.php(\?s=[a-z]+)?$/', $url)) $url = 'rival.php';
                $nuevo = $v8 && (int)$n['id'] > $ultimoVisto; ?>
                <li class="rv-notif rv-notif--<?= e(preg_replace('/[^a-z_]/', '', (string)$n['tipo'])) ?> <?= $nuevo ? 'es-nueva' : '' ?>">
                    <a href="<?= e($url) ?>">
                        <b><?= e($n['titulo']) ?></b>
                        <span><?= e($n['cuerpo']) ?></span>
                        <small><?= e($haceTxt($n['hace_s'])) ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($ctx['rol'] === 'capitan' && !$error): ?>
    <p class="rv-vivo" id="rv-vivo"><span class="rv-vivo__punto" aria-hidden="true"></span> Se actualiza automáticamente.</p>
<?php endif; ?>

<?php if ($ctx['rol'] === 'capitan' && $error): ?>
    <form method="post" action="salir.php" class="form-volver">
        <?= csrf_campo() ?>
        <button type="submit" class="btn btn--sec btn--ancho"><?= $esSimulacion ? 'Salir de la simulación (pruebas)' : '← Volver a mi estado de cuenta' ?></button>
    </form>
<?php endif; ?>
</main>

<?php if ($ctx['rol'] === 'capitan' && !$error): ?>
<!-- ===== Navegación inferior: Inicio · Rivales · Partidos · Horarios · 🔔 ===== -->
<nav class="rv-nav rv-nav--5" aria-label="Navegación principal">
    <?php
    $NAV = [
        'inicio'   => [$ICO['inicio'], 'Inicio', 0],
        'buscar'   => [$ICO['rayo'], 'Match', $nPorConfirmar],
        'partidos' => [$ICO['balon'], 'Partidos', count($P['reprogParaMi'] ?? [])],
        'horarios' => [$ICO['reloj'], 'Horarios', $faltan ? 1 : 0],
        'avisos'   => [$ICO['campana'], 'Avisos', $insigniaAvisos],
    ];
    foreach ($NAV as $k => [$ico, $txt, $n]): $act = $k === $sec; ?>
        <a href="rival.php?s=<?= $k ?>" class="rv-nav__item <?= $act ? 'rv-nav__item--pri' : '' ?>" <?= $act ? 'aria-current="page"' : '' ?>
           aria-label="<?= e($txt) ?><?= $n ? ($k === 'horarios' ? ' (faltan horarios)' : ': ' . $n . ' pendientes') : '' ?>">
            <span class="rv-nav__burbuja"><?= $ico ?><?php if ($n): ?><span class="rv-nav__n" aria-hidden="true"><?= $k === 'horarios' ? '!' : ($n > 9 ? '9+' : $n) ?></span><?php endif; ?></span><span><?= e($txt) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
</body>
</html>
