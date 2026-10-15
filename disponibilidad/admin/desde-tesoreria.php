<?php
/**
 * De Tesorería al panel sin volver a escribir la contraseña (pase firmado de un solo uso).
 * Abre la sesión de administración igual que admin/entrar.php, a nombre de la misma persona.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/mesa.php';

cabeceras_admin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: entrar.php', true, 303); exit; }
$uid = admin_tablas_instaladas() ? mesa_pase_usar((string)($_POST['pase'] ?? ''), 'panel') : 0;
$u   = $uid ? mesa_usuario(db(), $uid) : null;
if (!$u) { header('Location: entrar.php', true, 303); exit; }

unset($_SESSION[SESION_CAPITAN], $_SESSION['csrf_token'], $GLOBALS['__capitan_memo'], $GLOBALS['__admin_memo']);
session_regenerate_id(true);
$_SESSION[SESION_ADMIN] = ['id' => (int)$u['id'], 'huella' => huella_clave((string)$u['clave_hash']), 'inicio' => time(), 'ultimo' => time()];
fin_bitacora_registrar((string)$u['usuario'], 'acceso_desde_tesoreria', 'sesion', (string)$u['id']);
header('Location: ' . ((int)$u['puede_finanzas'] === 1 ? 'finanzas.php' : 'equipos.php'), true, 303);
exit;
