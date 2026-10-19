<?php
/**
 * Liga Chapingo — Acciones de escritura compartidas (equipos y canchas).
 * Cada endpoint sólo decide QUÉ entidad y QUIÉN puede; el flujo es el mismo:
 * POST + CSRF + semestre activo + registro existente → validar → guardar → redirigir.
 */
declare(strict_types=1);

function accion_guardar_semana(string $entidad): void
{
    [$reg, $semestre] = preparar_escritura($entidad);
    $id = (int)$reg['id'];
    $dias = $_POST['dias'] ?? [];
    if (!is_array($dias)) $dias = [];

    [$bloques, $errores] = validar_semana($dias);
    if ($errores) {
        recordar_formulario(clave_formulario($entidad, 'semana', $id), $dias);
        flash('error', array_merge(['No se guardó la disponibilidad. Revisa lo siguiente:'], $errores));
        redirigir_entidad($entidad, $id, 'semanal');
    }
    try {
        $r = guardar_semana($id, (int)$semestre['id'], $bloques, $entidad);
        if ($r['insertados'] === 0 && $r['desactivados'] === 0) {
            flash('info', 'No había cambios: la disponibilidad semanal ya estaba así.');
        } else {
            flash('ok', 'Disponibilidad semanal guardada (' . $r['insertados'] . ' horario(s) nuevo(s), '
                . $r['desactivados'] . ' quitado(s), ' . $r['sin_cambio'] . ' sin cambio).');
        }
    } catch (Throwable $ex) {
        recordar_formulario(clave_formulario($entidad, 'semana', $id), $dias);
        flash('error', error_interno($ex, 'guardar-semana-' . $entidad));
    }
    redirigir_entidad($entidad, $id, 'semanal');
}

function accion_eliminar_bloque(string $entidad): void
{
    [$reg, $semestre] = preparar_escritura($entidad);
    $id = (int)$reg['id'];
    $bloqueId = entero_positivo($_POST['bloque_id'] ?? null);
    try {
        if ($bloqueId && eliminar_bloque($bloqueId, $id, (int)$semestre['id'], $entidad)) {
            flash('ok', 'Horario eliminado.');
        } else {
            flash('error', 'No se encontró ese horario para ' . entidad($entidad)['este'] . '.');
        }
    } catch (Throwable $ex) {
        flash('error', error_interno($ex, 'eliminar-bloque-' . $entidad));
    }
    redirigir_entidad($entidad, $id, 'semanal');
}

function accion_guardar_excepcion(string $entidad): void
{
    [$reg, $semestre] = preparar_escritura($entidad);
    $id = (int)$reg['id'];
    $entrada = [];
    foreach (['fecha', 'tipo', 'hora_inicio', 'hora_fin', 'motivo'] as $k) {
        $entrada[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    [$datos, $errores] = validar_excepcion($entrada, $semestre, $id, $entidad);
    if ($errores) {
        recordar_formulario(clave_formulario($entidad, 'excepcion', $id), $entrada);
        flash('error', array_merge(['No se registró la excepción:'], $errores));
        redirigir_entidad($entidad, $id, 'excepciones');
    }
    try {
        guardar_excepcion($id, (int)$semestre['id'], $datos, $entidad);
        flash('ok', 'Excepción registrada para el ' . fecha_mx($datos['fecha']) . '.');
    } catch (Throwable $ex) {
        recordar_formulario(clave_formulario($entidad, 'excepcion', $id), $entrada);
        flash('error', error_interno($ex, 'guardar-excepcion-' . $entidad));
    }
    redirigir_entidad($entidad, $id, 'excepciones');
}

function accion_eliminar_excepcion(string $entidad): void
{
    [$reg, $semestre] = preparar_escritura($entidad);
    $id = (int)$reg['id'];
    $excId = entero_positivo($_POST['excepcion_id'] ?? null);
    try {
        if ($excId && eliminar_excepcion($excId, $id, (int)$semestre['id'], $entidad)) {
            flash('ok', 'Excepción eliminada.');
        } else {
            flash('error', 'No se encontró esa excepción para ' . entidad($entidad)['este'] . '.');
        }
    } catch (Throwable $ex) {
        flash('error', error_interno($ex, 'eliminar-excepcion-' . $entidad));
    }
    redirigir_entidad($entidad, $id, 'excepciones');
}
