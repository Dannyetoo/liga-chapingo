<?php
/**
 * «Volver a mi estado de cuenta»: cierra el contexto de capitán en /disponibilidad/
 * (no toca la sesión del Estado de cuenta) y regresa a él. Para volver a entrar se pulsa
 * otra vez el botón del Estado de cuenta: no se pide código de nuevo.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_valido($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Solicitud no válida.');
}
$ctx = contexto_acceso();
$simulacion = $ctx['rol'] === 'capitan' && $ctx['origen'] === 'simulacion';
$destino = $ctx['rol'] === 'capitan' ? url_retorno_segura((string)$ctx['retorno']) : 'index.php';
cerrar_contexto_capitan();
header('Location: ' . ($simulacion && es_entorno_pruebas() ? 'admin/equipos.php' : $destino), true, 303);
exit;
