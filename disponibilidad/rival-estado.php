<?php
/** «Busca un rival» · huella para la actualización automática (JSON, sólo lectura). */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/rival.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$ctx = contexto_acceso();
if ($ctx['rol'] !== 'capitan' || !rival_instalado()) { http_response_code(403); echo '{"ok":false}'; exit; }
$eq = rival_contexto_equipo((int)$ctx['equipo_id'], capitan_estado_id());
echo json_encode(['ok' => (bool)$eq, 'v' => $eq ? rival_version($eq) : '']);
