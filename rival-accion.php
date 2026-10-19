<?php
/**
 * Administración · «Busca un rival» · ACCIONES (sólo POST + CSRF + sesión de administración).
 * Actualización 2026-09-29: proponer partidos, editar/cancelar partidos confirmados y atender
 * solicitudes de los capitanes. Cada cambio avisa a los equipos involucrados.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/rival.php';

requerir_admin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
function volver_admin(string $ancla = ''): void
{
    header('Location: rival.php' . ($ancla !== '' ? '#' . $ancla : ''), true, 303);
    exit;
}
if (!csrf_valido($_POST['csrf_token'] ?? null)) {
    flash('error', 'La página expiró. Vuelve a intentarlo.');
    volver_admin();
}
if (!rival_instalado() || !rival_v8_instalado()) {
    flash('error', 'Falta instalar la actualización (Administración → Busca un rival → Instalar).');
    volver_admin();
}
$adm = admin_en_sesion();
$por = 'admin:' . (string)($adm['usuario'] ?? '?');
push_enviar_al_terminar();

$accion = (string)($_POST['accion'] ?? '');
$id = static fn(string $k): int => max(0, (int)($_POST[$k] ?? 0));
$ancla = '';
try {
    switch ($accion) {
        case 'proponer':
            $ancla = 'generar';
            $ok = []; $err = [];
            foreach ((array)($_POST['p'] ?? []) as $k => $d) {
                if (!is_array($d) || empty($d['on'])) continue;
                if (!preg_match('/^(\d+)-(\d+)$/', (string)$k, $m)) continue;
                try {
                    $r = rival_admin_proponer((int)$m[1], (int)$m[2], (string)($d['slot'] ?? ''), $por);
                    $ok[] = $r['texto'];
                } catch (RivalError $ex) {
                    $err[] = $ex->getMessage();
                }
            }
            if (!$ok && !$err) flash('error', 'Marca al menos una propuesta.');
            if ($ok) flash('ok', array_merge([count($ok) . ' propuesta' . (count($ok) === 1 ? '' : 's') . ' enviada' . (count($ok) === 1 ? '' : 's') . ' (se avisó a los equipos):'], $ok));
            if ($err) flash('error', array_merge(['No se enviaron:'], $err));
            break;
        case 'proponer_manual':
            $ancla = 'manual';
            $clave = trim((string)($_POST['fecha'] ?? '')) . ' ' . substr(trim((string)($_POST['hora'] ?? '')), 0, 5);
            $r = rival_admin_proponer($id('equipo_a'), $id('equipo_b'), $clave, $por);
            flash('ok', 'Propuesta enviada: ' . $r['texto'] . '. Se avisó a los dos equipos; se confirma cuando ambos acepten.');
            break;
        case 'registrar_jugado':
            $ancla = 'historial';
            $t = rival_admin_registrar_jugado($id('equipo_a'), $id('equipo_b'), (string)($_POST['fecha'] ?? ''), (string)($_POST['nota'] ?? ''), $por);
            flash('ok', $t . ' quedó registrado como ya jugado (en el historial de ambos equipos). Ya no se volverá a proponer.');
            break;
        case 'solicitud_jugada':
            $ancla = 'solicitudes-equipos';
            $t = rival_admin_solicitud_jugada($id('solicitud'), (string)($_POST['fecha'] ?? ''), $por);
            flash('ok', $t . ': la solicitud se cerró y el partido quedó como ya jugado en el historial de ambos equipos.');
            break;
        case 'editar_jugado':
            $ancla = 'historial';
            if ($id('reserva')) $t = rival_admin_editar_jugado_dicifo($id('reserva'), (string)($_POST['fecha'] ?? ''), (string)($_POST['hora'] ?? ''), $por);
            else $t = rival_admin_editar_jugado($id('jugado'), (string)($_POST['fecha'] ?? ''), (string)($_POST['nota'] ?? ''), $por);
            flash('ok', $t . ': cambios guardados.');
            break;
        case 'borrar_jugado':
            if ($id('reserva')) {
                $ancla = 'historial';
                $t = rival_admin_borrar_jugado_dicifo($id('reserva'), $por);
                flash('ok', $t . ' se quitó del historial. Esos equipos pueden volver a enfrentarse.');
                break;
            }
            $ancla = 'historial';
            rival_admin_borrar_jugado($id('jugado'), $por);
            flash('ok', 'Registro quitado del historial. Esos equipos pueden volver a enfrentarse.');
            break;
        case 'cancelar_propuesta':
            $ancla = 'generar';
            rival_admin_cancelar_propuesta($id('propuesta'), $por);
            flash('ok', 'Propuesta retirada. Se avisó a los equipos.');
            break;
        case 'editar_partido':
            $ancla = 'confirmados';
            $clave = trim((string)($_POST['fecha'] ?? '')) . ' ' . substr(trim((string)($_POST['hora'] ?? '')), 0, 5);
            $r = rival_admin_editar_partido($id('reserva'), $clave, $por);
            flash('ok', $r['partido'] . ': se movió del ' . $r['antes'] . ' al ' . $r['ahora'] . '. Se avisó a los dos equipos.');
            break;
        case 'cancelar_partido':
            $ancla = 'confirmados';
            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if (mb_strlen($motivo) < 3) throw new RivalError('Escribe el motivo (lo verán los equipos).');
            if (mb_strlen($motivo) > 200) throw new RivalError('El motivo es muy largo (máximo 200 caracteres).');
            $r = rival_admin_cancelar_partido($id('reserva'), $motivo, $por);
            flash('ok', 'Partido eliminado: ' . $r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'] . '. La cancha quedó libre y se avisó a los dos equipos.');
            break;
        case 'peticion_aprobar':
        case 'peticion_rechazar':
        case 'peticion_atender':
            $ancla = 'solicitudes';
            $res = rival_admin_resolver_peticion($id('peticion'), substr($accion, 9), (string)($_POST['respuesta'] ?? ''), $por);
            flash('ok', $res === 'aprobada' ? 'Solicitud aprobada: el partido se canceló y se avisó a los dos equipos.'
                : ($res === 'rechazada' ? 'Solicitud rechazada. Se avisó al capitán.' : 'Marcada como atendida. Se avisó al capitán.'));
            break;
        default:
            flash('error', 'Acción no reconocida.');
    }
} catch (RivalError $ex) {
    flash('error', $ex->getMessage());
} catch (Throwable $ex) {
    flash('error', error_interno($ex, 'admin rival ' . $accion));
}
volver_admin($ancla);
