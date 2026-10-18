<?php
/* =====================================================================
   Puente portal de capitanes → «Torneo y estadísticas».
   Recibe por POST el pase firmado de un solo uso que entrega
   capitanes/api.php?a=torneo, abre la sesión de competencia de la cuenta
   ligada a ESE equipo y lleva a torneo.html. Sin pase válido no abre nada.
   ===================================================================== */
declare(strict_types=1);
require __DIR__.'/lib.php';
require_once __DIR__.'/../capitanes/config.php';
require_once __DIR__.'/../capitanes/codigos-lib.php';
ini_set('display_errors','0');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');

function salir_con(string $msg, int $codigo): void {
    http_response_code($codigo); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Torneo · Liga Chapingo</title>'
       . '<p style="font:16px system-ui;max-width:520px;margin:60px auto;padding:0 16px">'.htmlspecialchars($msg,ENT_QUOTES,'UTF-8')
       . '</p><p style="font:16px system-ui;max-width:520px;margin:0 auto;padding:0 16px"><a href="./">Entrar con usuario y contraseña</a></p>';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') salir_con('Abre el torneo desde tu estado de cuenta.', 405);
try {
    $p = verificaPaseTorneo((string)($_POST['pase'] ?? ''));
    if (!$p) salir_con('El acceso venció. Vuelve a tu estado de cuenta y pulsa otra vez «Torneo y estadísticas».', 403);
    cp_db()->beginTransaction();
    cp_query('DELETE FROM cp_pases_usados WHERE vence < ?', [time()]);
    $usado = cp_query('INSERT IGNORE INTO cp_pases_usados(nonce,vence) VALUES(?,?)', [$p['nonce'], $p['vence']])->rowCount();
    if ($usado !== 1) { cp_db()->rollBack(); salir_con('Este acceso ya se usó. Vuelve a tu estado de cuenta.', 403); }
    $u = cp_query("SELECT u.id,u.version_clave FROM cp_equipos q JOIN cp_usuarios u ON u.id=q.usuario_id WHERE q.origen_ref=? AND u.activo=1 AND u.rol='equipo'", [$p['equipoId']])->fetch();
    cp_db()->commit();
    if (!$u) salir_con('Tu equipo todavía no está dado de alta en el sistema de competencia. Avisa a la mesa directiva.', 404);
    cp_sesion();
    session_regenerate_id(true);
    $_SESSION = ['uid'=>(int)$u['id'],'version'=>(int)$u['version_clave'],'inicio'=>time(),'visto'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    header('Location: torneo.html', true, 303);
} catch (Throwable $ex) {
    try { if (cp_db()->inTransaction()) cp_db()->rollBack(); } catch (Throwable $ignorado) {}
    error_log('[competencia/entrar-capitan] '.$ex->getMessage());
    salir_con('No se pudo abrir el torneo. Inténtalo de nuevo en unos minutos.', 503);
}
