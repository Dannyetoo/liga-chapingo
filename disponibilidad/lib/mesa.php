<?php
/**
 * Liga Chapingo — UN SOLO ACCESO para la mesa directiva (30-sep-2026).
 *
 * Tesorería (/arbitrajes/) y el panel de administración (/disponibilidad/admin/) usan las MISMAS
 * cuentas personales: tabla admin_usuarios (usuario + contraseña con password_hash).
 *  · Tesorería ya no tiene contraseña general: cada quien entra con su usuario.
 *  · Del panel a Tesorería (y de regreso) se pasa con un PASE firmado de un solo uso,
 *    sin volver a escribir la contraseña.
 *  · Lo que se registra o edita en Tesorería queda en fin_bitacora con el usuario.
 *
 * Este archivo no depende de funciones.php: Tesorería lo usa con su propia conexión PDO.
 */
declare(strict_types=1);

const MESA_PASE_VIGENCIA = 90;                 // segundos
const MESA_FALLOS_USUARIO = 5;
const MESA_FALLOS_IP      = 20;
const MESA_VENTANA_MIN    = 15;

function mesa_cargar_semilla(): bool
{
    if (!defined('SEMILLA_CAPITANES')) {
        $f = dirname(__DIR__, 2) . '/capitanes/config.php';
        if (is_file($f)) require_once $f;
    }
    return defined('SEMILLA_CAPITANES') && strlen((string)SEMILLA_CAPITANES) >= 16;
}

function mesa_huella(string $hash): string { return substr(hash('sha256', $hash), 0, 24); }
function mesa_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }
function mesa_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mesa_b64d(string $s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/** Usuario activo por id, o null. */
function mesa_usuario(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, usuario, nombre, clave_hash, puede_finanzas FROM admin_usuarios WHERE id = ? AND activo = 1');
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

/**
 * Verifica usuario y contraseña con el mismo freno de intentos que el panel.
 * @return array{ok:bool, error?:string, usuario?:array}
 */
function mesa_verificar(PDO $pdo, string $usuario, string $clave): array
{
    $generico = 'Usuario o contraseña incorrectos.';
    $usuario = mb_strtolower(trim($usuario));
    if ($usuario === '' || $clave === '' || mb_strlen($usuario) > 60 || strlen($clave) > 200) return ['ok' => false, 'error' => $generico];
    $ip = mesa_ip();
    $st = $pdo->prepare('SELECT SUM(CASE WHEN usuario = ? THEN 1 ELSE 0 END) pu, SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END) pi
                           FROM admin_intentos WHERE exito = 0 AND creado_en >= NOW() - INTERVAL ' . MESA_VENTANA_MIN . ' MINUTE AND (usuario = ? OR ip = ?)');
    $st->execute([$usuario, $ip, $usuario, $ip]);
    $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($f['pu'] ?? 0) >= MESA_FALLOS_USUARIO || (int)($f['pi'] ?? 0) >= MESA_FALLOS_IP) {
        return ['ok' => false, 'error' => 'Demasiados intentos. Espera ' . MESA_VENTANA_MIN . ' minutos.'];
    }
    $st = $pdo->prepare('SELECT id, usuario, nombre, clave_hash, activo, puede_finanzas FROM admin_usuarios WHERE usuario = ?');
    $st->execute([$usuario]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    $hash = $u ? (string)$u['clave_hash'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok = password_verify($clave, $hash) && $u && (int)$u['activo'] === 1;
    $pdo->prepare('INSERT INTO admin_intentos (usuario, ip, exito) VALUES (?, ?, ?)')->execute([mb_substr($usuario, 0, 60), $ip, $ok ? 1 : 0]);
    if (!$ok) return ['ok' => false, 'error' => $generico];
    $pdo->prepare('UPDATE admin_usuarios SET ultimo_acceso = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$u['id']]);
    return ['ok' => true, 'usuario' => $u];
}

/** Bitácora (fin_bitacora). Nunca interrumpe. */
function mesa_bitacora(PDO $pdo, string $usuario, string $accion, string $entidad, ?string $id, $antes = null, $despues = null): void
{
    try {
        $pdo->prepare('INSERT INTO fin_bitacora (usuario, accion, entidad, entidad_id, antes, despues, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($usuario, 0, 60), mb_substr($accion, 0, 40), mb_substr($entidad, 0, 40), $id === null ? null : mb_substr($id, 0, 64),
                       $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                       $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE), mesa_ip()]);
    } catch (Throwable $ex) {
        error_log('[mesa] bitácora: ' . $ex->getMessage());
    }
}

/* ---------- pase firmado entre Tesorería y el panel ---------- */
function mesa_clave_pase(): string
{
    return mesa_cargar_semilla() ? hash_hmac('sha256', 'pase-mesa-directiva:v1', SEMILLA_CAPITANES, true) : '';
}
/** $destino: 'tesoreria' | 'panel'. '' si no se puede firmar. */
function mesa_pase_crear(int $uid, string $destino): string
{
    $k = mesa_clave_pase();
    if ($k === '' || $uid <= 0) return '';
    $c = mesa_b64(json_encode(['u' => $uid, 'd' => $destino, 'x' => time() + MESA_PASE_VIGENCIA, 'n' => bin2hex(random_bytes(16))]));
    return $c . '.' . mesa_b64(hash_hmac('sha256', $c, $k, true));
}
/** Id del usuario si el pase es válido, vigente, para ese destino y no usado antes; si no, 0. */
function mesa_pase_usar(string $pase, string $destino): int
{
    $k = mesa_clave_pase();
    if ($k === '' || strlen($pase) > 600 || substr_count($pase, '.') !== 1) return 0;
    [$c, $f] = explode('.', $pase, 2);
    $firma = mesa_b64d($f);
    if ($firma === false || !hash_equals(hash_hmac('sha256', $c, $k, true), $firma)) return 0;
    $j = json_decode((string)mesa_b64d($c), true);
    if (!is_array($j) || ($j['d'] ?? '') !== $destino || (int)($j['x'] ?? 0) < time()) return 0;
    $n = (string)($j['n'] ?? '');
    // un solo uso
    $arch = dirname(__DIR__) . '/datos/pases-mesa.json';
    if (!is_dir(dirname($arch))) @mkdir(dirname($arch), 0755, true);
    $fp = @fopen($arch, 'c+');
    if (!$fp) return 0;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) $d = [];
    foreach ($d as $kk => $v) if ((int)$v < time()) unset($d[$kk]);
    $nuevo = $n !== '' && !isset($d[$n]);
    if ($nuevo) { $d[$n] = (int)$j['x']; ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)json_encode($d)); fflush($fp); }
    flock($fp, LOCK_UN); fclose($fp);
    return $nuevo ? (int)($j['u'] ?? 0) : 0;
}
