<?php
/** Cierra la sesión de administración (sólo POST + CSRF). */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_valido($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Solicitud no válida.');
}
admin_cerrar_sesion();
header('Location: entrar.php', true, 303);
exit;
