<?php
/**
 * Notificaciones · alta, baja y prueba desde el celular del capitán (sólo POST + CSRF + sesión de capitán).
 * El equipo sale de la sesión; nunca del formulario. Responde JSON.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/push.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function responder(array $r, int $cod = 200): void { http_response_code($cod); echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
$ctx = contexto_acceso();
if ($ctx['rol'] !== 'capitan') responder(['ok' => false, 'error' => 'Entra desde tu Estado de cuenta.'], 403);
if (!csrf_valido($_POST['csrf_token'] ?? null)) responder(['ok' => false, 'error' => 'La página expiró. Recárgala e inténtalo de nuevo.'], 400);
if (!push_instalado()) responder(['ok' => false, 'error' => 'Las notificaciones todavía no están instaladas. Avisa a la mesa directiva.'], 503);

$equipo = (int)$ctx['equipo_id'];
$accion = (string)($_POST['accion'] ?? '');
try {
    switch ($accion) {
        case 'suscribir':
            push_guardar_suscripcion($equipo, (string)($_POST['endpoint'] ?? ''), (string)($_POST['p256dh'] ?? ''), (string)($_POST['auth'] ?? ''),
                push_nombre_dispositivo((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
            responder(['ok' => true, 'dispositivos' => push_dispositivos($equipo)]);
        case 'baja':
            push_quitar_suscripcion($equipo, (string)($_POST['endpoint'] ?? ''));
            responder(['ok' => true, 'dispositivos' => push_dispositivos($equipo)]);
        case 'prueba':
            // Máximo una prueba por minuto por equipo.
            $st = db()->prepare("SELECT COUNT(*) FROM push_cola WHERE equipo_id = ? AND tipo = 'prueba' AND creado_en > NOW() - INTERVAL 1 MINUTE");
            $st->execute([$equipo]);
            if ((int)$st->fetchColumn() > 0) responder(['ok' => false, 'error' => 'Espera un minuto antes de enviar otra prueba.']);
            push_encolar($equipo, 'prueba', 'Liga Chapingo', '¡Listo! Las notificaciones de tu equipo están activas en este dispositivo.');
            $r = push_procesar_cola(10, 10);
            responder(['ok' => true, 'enviadas' => $r['enviadas']]);
        default:
            responder(['ok' => false, 'error' => 'Acción no reconocida.'], 400);
    }
} catch (InvalidArgumentException $ex) {
    responder(['ok' => false, 'error' => $ex->getMessage()], 400);
} catch (Throwable $ex) {
    responder(['ok' => false, 'error' => error_interno($ex, 'push ' . $accion)], 500);
}
