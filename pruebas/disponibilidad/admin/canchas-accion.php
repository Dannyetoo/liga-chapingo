<?php
/**
 * Liga Chapingo — Acciones administrativas sobre canchas (sólo POST + CSRF).
 * La disponibilidad y las excepciones usan exactamente las mismas acciones que los equipos.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

requerir_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
if (!canchas_instaladas()) {
    flash('error', 'Las tablas de canchas aún no existen. Ejecuta sql/fase4-canchas.sql en la base de pruebas.');
    redirigir_entidad('cancha', null);
}

$accion = (string)($_POST['accion'] ?? '');

switch ($accion) {
    case 'guardar_semana':      accion_guardar_semana('cancha');     break;
    case 'eliminar_bloque':     accion_eliminar_bloque('cancha');    break;
    case 'guardar_excepcion':   accion_guardar_excepcion('cancha');  break;
    case 'eliminar_excepcion':  accion_eliminar_excepcion('cancha'); break;

    case 'crear':
        if (!csrf_valido($_POST['csrf_token'] ?? null)) {
            flash('error', 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.');
            redirigir_entidad('cancha', null);
        }
        $entrada = ['nombre' => (string)($_POST['nombre'] ?? ''), 'ubicacion' => (string)($_POST['ubicacion'] ?? '')];
        try {
            [$datos, $errores] = validar_cancha($entrada);
            if ($errores) {
                recordar_formulario('cancha_nueva', $entrada);
                flash('error', array_merge(['No se creó la cancha:'], $errores));
                redirigir_entidad('cancha', null, 'nueva');
            }
            $nuevo = crear_cancha($datos);
            flash('ok', 'Cancha «' . $datos['nombre'] . '» creada. Registra ahora su disponibilidad semanal.');
            redirigir_entidad('cancha', $nuevo, 'semanal');
        } catch (Throwable $ex) {
            flash('error', error_interno($ex, 'crear-cancha'));
            redirigir_entidad('cancha', null);
        }
        break;

    case 'estado':
        $id = entero_positivo($_POST['cancha_id'] ?? null);
        if (!csrf_valido($_POST['csrf_token'] ?? null)) {
            flash('error', 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.');
            redirigir_entidad('cancha', $id);
        }
        $cancha = $id ? obtener_cancha($id) : null;
        if (!$cancha) {
            flash('error', 'La cancha seleccionada no existe.');
            redirigir_entidad('cancha', null);
        }
        $activar = ($_POST['activo'] ?? '') === '1';
        try {
            cambiar_estado_cancha($id, $activar);
            flash('ok', 'Cancha «' . $cancha['nombre'] . '» ' . ($activar ? 'activada.' : 'desactivada: ya no aparecerá para buscar horarios.'));
        } catch (Throwable $ex) {
            flash('error', error_interno($ex, 'estado-cancha'));
        }
        redirigir_entidad('cancha', $id);
        break;

    default:
        http_response_code(400);
        exit('Acción no válida.');
}
