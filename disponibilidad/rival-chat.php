<?php
/**
 * «Busca un rival» · CHAT del partido confirmado (sólo los dos capitanes).
 *   GET  rival-chat.php?r=ID                    → pantalla del chat
 *   GET  rival-chat.php?r=ID&json=1&desde=N     → mensajes nuevos (para actualizar sin recargar)
 *   POST rival-chat.php  (csrf, r, texto)        → enviar; responde JSON si se pidió así
 * El equipo sale de la sesión; nunca del formulario.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/rival.php';
require_once __DIR__ . '/lib/rival-chat.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$ctx = contexto_acceso();
$json = !empty($_GET['json']) || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
function chat_json(array $x, int $c = 200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }

if ($ctx['rol'] !== 'capitan') {
    if ($json) chat_json(['ok' => false, 'error' => 'Entra desde tu Estado de cuenta.'], 403);
    header('Location: rival.php', true, 303); exit;
}
$eq = rival_contexto_equipo((int)$ctx['equipo_id'], capitan_estado_id());
$rid = max(0, (int)($_POST['r'] ?? $_GET['r'] ?? 0));
$p = $eq ? chat_partido((int)$eq['equipo_id'], $rid) : null;
if (!$eq || !$p) {
    if ($json) chat_json(['ok' => false, 'error' => 'Este partido no está confirmado.'], 404);
    flash('error', 'El chat sólo existe para partidos confirmados.');
    header('Location: rival.php?s=partidos', true, 303); exit;
}
$yoId = (int)$eq['equipo_id'];
$fmt = static function (array $m) use ($yoId): array {
    return ['id' => (int)$m['id'], 'mio' => (int)$m['equipo_id'] === $yoId, 'de' => (string)$m['equipo_nombre'],
            'texto' => (string)$m['texto'], 'hora' => date('d/m H:i', strtotime((string)$m['creado_en']))];
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido($_POST['csrf_token'] ?? null)) {
        if ($json) chat_json(['ok' => false, 'error' => 'La página expiró. Recárgala.'], 400);
        flash('error', 'La página expiró. Vuelve a intentarlo.');
    } else {
        try {
            chat_enviar($eq, $rid, (string)($_POST['texto'] ?? ''));
            if ($json) chat_json(['ok' => true]);
        } catch (RivalError $ex) {
            if ($json) chat_json(['ok' => false, 'error' => $ex->getMessage()]);
            flash('error', $ex->getMessage());
        } catch (Throwable $ex) {
            $m = error_interno($ex, 'chat');
            if ($json) chat_json(['ok' => false, 'error' => $m]);
            flash('error', $m);
        }
    }
    header('Location: rival-chat.php?r=' . $rid . '#fin', true, 303); exit;
}

$msgs = array_map($fmt, chat_mensajes($rid, max(0, (int)($_GET['desde'] ?? 0))));
if ($msgs) chat_marcar_visto($rid, $yoId, end($msgs)['id']);
if ($json) chat_json(['ok' => true, 'abierto' => $p['abierto'], 'mensajes' => $msgs]);

$mensajes = tomar_flash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Chat · vs <?= e($p['rival']) ?> · Liga Chapingo</title>
    <link rel="stylesheet" href="assets/disponibilidad.css?v=20260929">
    <link rel="stylesheet" href="assets/rival.css?v=20260930">
    <script src="assets/rival-chat.js?v=20260930" defer></script>
    <meta name="theme-color" content="#123B63">
</head>
<body class="app-rival rv-chat-pag">
<header class="rv-cab">
    <div class="rv-cab__in">
        <div class="rv-cab__fila">
            <a class="rv-chat__volver" href="rival.php?s=partidos" aria-label="Volver a Partidos">←</a>
            <div class="rv-chat__quien">
                <b>vs <?= e($p['rival']) ?></b>
                <small><?= e(rival_slot_texto((string)$p['fecha'], (string)$p['hora'])) ?> · <?= e(RIVAL_CANCHA) ?></small>
            </div>
        </div>
    </div>
</header>
<main class="rv rv-chat">
    <?php foreach ($mensajes as $m): ?>
        <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(implode(' ', $m['mensajes'])) ?></div>
    <?php endforeach; ?>
    <p class="rv-chat__nota">Sólo lo ven los dos capitanes. La liga puede revisarlo si hay un reporte.</p>
    <ol class="rv-chat__lista" id="chat-lista" aria-live="polite" data-ultimo="<?= $msgs ? (int)end($msgs)['id'] : 0 ?>" data-r="<?= (int)$rid ?>">
        <?php if (!$msgs): ?><li class="rv-chat__vacio" id="chat-vacio">Todavía no hay mensajes. Saluda y pónganse de acuerdo.</li><?php endif; ?>
        <?php foreach ($msgs as $m): ?>
            <li class="rv-chat__m<?= $m['mio'] ? ' rv-chat__m--mio' : '' ?>" data-id="<?= $m['id'] ?>">
                <span class="rv-chat__txt"><?= nl2br(e($m['texto'])) ?></span>
                <small><?= $m['mio'] ? '' : e($m['de']) . ' · ' ?><?= e($m['hora']) ?></small>
            </li>
        <?php endforeach; ?>
    </ol>
    <span id="fin"></span>
    <?php if ($p['abierto']): ?>
    <form method="post" action="rival-chat.php" class="rv-chat__form" id="chat-form">
        <?= csrf_campo() ?><input type="hidden" name="r" value="<?= (int)$rid ?>">
        <textarea name="texto" id="chat-texto" rows="1" maxlength="<?= CHAT_MAX_TEXTO ?>" placeholder="Escribe un mensaje…" required aria-label="Mensaje"></textarea>
        <button type="submit" class="rv-chat__enviar" aria-label="Enviar">➤</button>
    </form>
    <?php else: ?>
    <p class="rv-chat__cerrado">El chat de este partido ya se cerró.</p>
    <?php endif; ?>
</main>
</body>
</html>
