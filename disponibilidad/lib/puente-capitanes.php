<?php
/**
 * Liga Chapingo — PUENTE Estado de cuenta → Disponibilidad.
 *
 * Cómo identifica hoy /capitanes/ al equipo:
 *   código de capitán (HMAC de SEMILLA_CAPITANES) → $_SESSION['equipoId'] en la sesión
 *   «lchcap», cookie limitada a /capitanes/. Esa cookie NUNCA llega a /disponibilidad/.
 *
 * Por eso el paso entre módulos es un PASE firmado de un solo uso:
 *   1. El capitán, ya dentro de su Estado de cuenta, pulsa «Disponibilidad del equipo».
 *   2. capitanes/api.php?a=disponibilidad firma {equipoId de la sesión, vence, nonce}.
 *   3. El navegador lo manda por POST a entrar.php (no queda en la URL).
 *   4. Aquí se verifica la firma (misma clave derivada de SEMILLA_CAPITANES), la vigencia
 *      y que el nonce no se haya usado; luego se traduce equipoId → equipos.id con el
 *      vínculo EXPLÍCITO (lib/vinculos.php). Ante cualquier duda: null (falla cerrado).
 */
declare(strict_types=1);

require_once __DIR__ . '/vinculos.php';

const PUENTE_CAPITANES_LISTO = true;
const PUENTE_NONCES_ARCHIVO  = __DIR__ . '/../datos/pases-usados.json';

/**
 * @return array{estado: string, equipo_id?: int, retorno?: string, id_estado?: string}
 *   estado: 'ok' | 'pase_invalido' | 'pase_usado' | 'sin_vinculo' | 'no_disponible'
 */
function puente_resolver_pase(string $pase): array
{
    if (!cargar_portal_capitanes() || !function_exists('clavePuente') || clavePuente() === '') {
        return ['estado' => 'no_disponible'];
    }
    $p = verificaPaseDisponibilidad($pase);
    if (!$p) return ['estado' => 'pase_invalido'];
    if (!marcar_pase_usado($p['nonce'], $p['vence'])) return ['estado' => 'pase_usado'];

    $equipoId = equipo_por_id_estado_cuenta($p['equipoId']);
    if (!$equipoId) {
        error_log('[disponibilidad] pase válido sin vínculo para el equipo del Estado de cuenta: ' . substr($p['equipoId'], 0, 64));
        return ['estado' => 'sin_vinculo', 'id_estado' => $p['equipoId']];
    }
    return ['estado' => 'ok', 'equipo_id' => $equipoId, 'retorno' => CAPITAN_RETORNO_DEF, 'id_estado' => (string)$p['equipoId']];
}

/** Anota el nonce; false si ya se había usado. Con bloqueo de archivo para peticiones simultáneas. */
function marcar_pase_usado(string $nonce, int $vence): bool
{
    $dir = dirname(PUENTE_NONCES_ARCHIVO);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess',
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    $fp = @fopen(PUENTE_NONCES_ARCHIVO, 'c+');
    if (!$fp) {
        error_log('[disponibilidad] no se pudo abrir el registro de pases usados');
        return false;                                   // sin registro no se acepta el pase
    }
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) $d = [];
    $ahora = time();
    foreach ($d as $n => $x) if ((int)$x < $ahora) unset($d[$n]);   // limpia vencidos
    $nuevo = !isset($d[$nonce]);
    if ($nuevo) {
        $d[$nonce] = $vence;
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($d));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $nuevo;
}
