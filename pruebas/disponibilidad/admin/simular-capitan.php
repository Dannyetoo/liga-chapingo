<?php
/**
 * SÓLO PRUEBAS: abre el contexto de capitán de un equipo para probar el flujo real
 * (sin selector, sin equipo_id en formularios, bloqueo de otros equipos).
 * No existe en producción: exige administración Y entorno de pruebas.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

requerir_admin();
if (!es_entorno_pruebas() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_valido($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('No permitido.');
}
$equipo = ($id = entero_positivo($_POST['equipo_id'] ?? null)) ? obtener_equipo($id) : null;
if (!$equipo) {
    flash('error', 'El equipo no existe o no está activo.');
    header('Location: equipos.php', true, 303);
    exit;
}
iniciar_contexto_capitan((int)$equipo['id'], 'simulacion');
header('Location: ../index.php', true, 303);
exit;
