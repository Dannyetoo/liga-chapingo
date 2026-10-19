<?php
/**
 * «Busca un rival» · ACCIONES del capitán (sólo POST + CSRF + sesión de capitán).
 * El equipo sale de la sesión; ningún id de equipo se acepta del formulario.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/rival.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
$ctx = contexto_acceso();
if ($ctx['rol'] !== 'capitan') {
    http_response_code(403);
    exit('Entra desde tu Estado de cuenta.');
}
/** Regresa a la sección desde donde se envió el formulario (lista blanca). */
function volver(): void
{
    $s = (string)($_POST['volver'] ?? '');
    $ok = ['inicio', 'buscar', 'partidos', 'horarios', 'avisos'];
    header('Location: rival.php' . (in_array($s, $ok, true) ? '?s=' . $s : ''), true, 303);
    exit;
}

if (!csrf_valido($_POST['csrf_token'] ?? null)) {
    flash('error', 'La página expiró. Vuelve a intentarlo.');
    volver();
}
if (!rival_instalado()) { flash('error', 'Esta sección todavía no está instalada.'); volver(); }
$eq = rival_contexto_equipo((int)$ctx['equipo_id'], capitan_estado_id());
if (!$eq) { flash('error', 'Tu equipo todavía no está vinculado con su categoría. Avisa a la mesa directiva.'); volver(); }

// Los avisos que genere esta acción se envían justo después de responder (sin esperar al Cron).
push_enviar_al_terminar();

$accion = (string)($_POST['accion'] ?? '');
$id = static fn(string $k): int => max(0, (int)($_POST[$k] ?? 0));
try {
    switch ($accion) {
        case 'publicar':
            rival_publicar($eq, array_values((array)($_POST['slots'] ?? [])));
            flash('ok', 'Tus horarios quedaron publicados. Los equipos de tu grupo ya pueden verlos.');
            break;
        case 'agregar':
            $tenia = (bool)rival_mi_publicacion($eq['equipo_id']);
            $n = rival_agregar_horarios($eq, array_values((array)($_POST['slots'] ?? [])));
            flash('ok', $tenia ? ($n === 1 ? 'Horario agregado.' : $n . ' horarios agregados.') : 'Tus horarios quedaron publicados. Los equipos de tu grupo ya pueden verlos.');
            break;
        case 'eliminar_horario':
            $t = rival_eliminar_horario($eq, $id('opcion'));
            flash('ok', 'Quitaste el horario del ' . $t . '. Tus demás horarios siguen publicados.');
            break;
        case 'reemplazar':
            rival_reemplazar($eq, $id('opcion'), (string)($_POST['slot'] ?? ''));
            flash('ok', 'Opción reemplazada.');
            break;
        case 'retirar':
            // Actualización 2026-09-29: los equipos mantienen siempre al menos 3 horarios; se quitan uno por uno.
            flash('error', 'Los horarios se quitan uno por uno desde «Horarios» (siempre debes conservar al menos ' . RIVAL_MIN_HORARIOS . ').');
            break;
        case 'solicitar':
            rival_solicitar($eq, $id('opcion'));
            flash('ok', 'Solicitud enviada. Falta que el otro equipo la confirme.');
            break;
        case 'cancelar_solicitud':
            rival_cancelar_solicitud($eq, $id('solicitud'));
            flash('ok', 'Cancelaste tu solicitud.');
            break;
        case 'rechazar':
            rival_rechazar($eq, $id('solicitud'));
            flash('ok', 'Solicitud rechazada.');
            break;
        case 'confirmar':
            $r = rival_confirmar($eq, $id('solicitud'));
            flash('ok', '¡Partido confirmado contra ' . $r['rival'] . ' el ' . rival_slot_texto($r['fecha'], $r['hora']) . ' en DICIFO! Tus demás horarios siguen publicados.');
            break;
        case 'reprog_proponer':
            $sl = $_POST['slots'] ?? ($_POST['slot'] ?? []);
            rival_reprog_proponer($eq, $id('reserva'), is_array($sl) ? array_values($sl) : (string)$sl, (string)($_POST['motivo'] ?? ''));
            flash('ok', 'Propuesta de reprogramación enviada. El partido sigue en su horario hasta que tu rival elija uno de los nuevos horarios.');
            break;
        case 'reprog_cancelar':
            rival_reprog_cancelar($eq, $id('reprog'));
            flash('ok', 'Retiraste tu propuesta de reprogramación.');
            break;
        case 'reprog_rechazar':
            rival_reprog_rechazar($eq, $id('reprog'));
            flash('ok', 'Rechazaste el cambio. El partido se queda en su horario original.');
            break;
        case 'reprog_aceptar':
            $r = rival_reprog_aceptar($eq, $id('reprog'), (string)($_POST['elegido'] ?? ''));
            flash('ok', 'Partido reprogramado: ahora es el ' . rival_slot_texto($r['fecha'], $r['hora']) . ' en DICIFO contra ' . $r['rival'] . '.');
            break;
        case 'propuesta_aceptar':
        case 'propuesta_rechazar':
            if (!rival_v8_instalado()) throw new RivalError('Esta función todavía no está instalada.');
            $r = rival_propuesta_responder($eq, $id('propuesta'), $accion === 'propuesta_aceptar');
            flash('ok', $r === 'confirmada' ? '¡Partido confirmado! Ya aparece en tus partidos.'
                : ($r === 'esperando' ? 'Aceptaste la propuesta. Falta que el otro equipo responda.' : 'Rechazaste la propuesta de partido.'));
            break;
        case 'peticion':
            if (!rival_v8_instalado()) throw new RivalError('Esta función todavía no está instalada.');
            $tipo = (string)($_POST['tipo'] ?? '');
            rival_peticion_crear($eq, $tipo, $id('reserva'), (string)($_POST['mensaje'] ?? ''));
            flash('ok', $tipo === 'cancelacion' ? 'Solicitud de cancelación enviada a la administración. El partido sigue en pie hasta que respondan.'
                : 'Reporte enviado a la administración. Te avisaremos cuando lo revisen.');
            break;
        default:
            flash('error', 'Acción no reconocida.');
    }
} catch (RivalError $ex) {
    flash('error', $ex->getMessage());
} catch (Throwable $ex) {
    flash('error', error_interno($ex, 'rival ' . $accion));
}
volver();
