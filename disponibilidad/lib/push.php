<?php
/**
 * Liga Chapingo — NOTIFICACIONES PUSH (Web Push estándar, sin librerías externas).
 *
 * · Llaves VAPID: se generan solas la primera vez y se guardan en push_config.
 * · Cifrado del mensaje: RFC 8291 (aes128gcm) con OpenSSL; firma VAPID: RFC 8292 (ES256).
 * · Los avisos se guardan en push_cola al momento; los envía el Cron (cron/notificaciones.php)
 *   y, para que lleguen al instante, también el propio sitio justo después de cada acción.
 * · Si las tablas no existen todavía, nada falla: simplemente no se encolan avisos.
 */
declare(strict_types=1);

const PUSH_CONTACTO = 'https://ligachapingo.com';    // «sub» de VAPID (identifica al remitente)
const PUSH_TTL      = 86400;                          // el servicio de push guarda el aviso hasta 24 h
const PUSH_MAX_FALLOS = 5;                            // tras 5 errores seguidos se desactiva el dispositivo

function push_instalado(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        foreach (['push_config', 'push_suscripciones', 'push_cola'] as $t) db()->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

function push_b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function push_b64u_dec(string $s): string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? '' : $d;
}

function push_config_get(string $k): ?string
{
    $st = db()->prepare('SELECT valor FROM push_config WHERE clave = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function push_config_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO push_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$k, $v]);
}

/* ------------------------------------------------------------------ */
/* Criptografía (P-256)                                                */
/* ------------------------------------------------------------------ */

/** Llave pública EC en formato crudo sin comprimir (65 bytes: 0x04 || X || Y). */
function push_pub_cruda($llave): string
{
    $d = openssl_pkey_get_details($llave);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** PEM de una llave pública P-256 a partir de sus 65 bytes crudos. */
function push_pub_pem(string $cruda): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $cruda;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function push_nueva_llave()
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$k) throw new RuntimeException('OpenSSL no pudo generar la llave EC.');
    return $k;
}

/** Llaves VAPID del sitio (se crean una sola vez). @return array{publica:string, privada:string} */
function push_vapid(): array
{
    static $v = null;
    if ($v) return $v;
    $pub = push_config_get('vapid_publica');
    $priv = push_config_get('vapid_privada');
    if (!$pub || !$priv) {
        $k = push_nueva_llave();
        openssl_pkey_export($k, $pem);
        $pub = push_b64u(push_pub_cruda($k));
        // INSERT IGNORE: si dos visitas las generan a la vez, se queda la primera.
        db()->prepare('INSERT IGNORE INTO push_config (clave, valor) VALUES (?, ?), (?, ?)')
            ->execute(['vapid_publica', $pub, 'vapid_privada', $pem]);
        $pub = push_config_get('vapid_publica');
        $priv = push_config_get('vapid_privada');
    }
    return $v = ['publica' => (string)$pub, 'privada' => (string)$priv];
}

/** Firma DER de ECDSA → 64 bytes (r || s) como pide JWT ES256. */
function push_der_a_crudo(string $der): string
{
    $p = 2;
    if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
    $leer = function () use ($der, &$p): string {
        $p++;                                       // 0x02
        $n = ord($der[$p++]);
        $v = substr($der, $p, $n);
        $p += $n;
        return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    };
    return $leer() . $leer();
}

function push_jwt(string $aud): string
{
    $v = push_vapid();
    $h = push_b64u((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = push_b64u((string)json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => PUSH_CONTACTO], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($h . '.' . $c, $firma, $v['privada'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar VAPID.');
    return $h . '.' . $c . '.' . push_b64u(push_der_a_crudo($firma));
}

/**
 * Cifra el contenido para una suscripción (RFC 8291, «aes128gcm»).
 * @return string cuerpo listo para enviar
 */
function push_cifrar(string $texto, string $p256dhB64, string $authB64): string
{
    $uaPub = push_b64u_dec($p256dhB64);
    $auth = push_b64u_dec($authB64);
    if (strlen($uaPub) !== 65 || $uaPub[0] !== "\x04" || strlen($auth) < 16) throw new InvalidArgumentException('Suscripción con llaves no válidas.');
    $as = push_nueva_llave();
    $asPub = push_pub_cruda($as);
    $secreto = openssl_pkey_derive(push_pub_pem($uaPub), $as, 32);
    if ($secreto === false || strlen($secreto) !== 32) throw new RuntimeException('Falló el acuerdo de llaves ECDH.');
    $hmac = fn(string $k, string $d) => hash_hmac('sha256', $d, $k, true);
    $prkKey = $hmac($auth, $secreto);
    $ikm = $hmac($prkKey, "WebPush: info\0" . $uaPub . $asPub . "\x01");
    $sal = random_bytes(16);
    $prk = $hmac($sal, $ikm);
    $cek = substr($hmac($prk, "Content-Encoding: aes128gcm\0\x01"), 0, 16);
    $nonce = substr($hmac($prk, "Content-Encoding: nonce\0\x01"), 0, 12);
    $cifrado = openssl_encrypt($texto . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cifrado === false) throw new RuntimeException('Falló el cifrado AES-GCM.');
    return $sal . pack('N', 4096) . chr(65) . $asPub . $cifrado . $tag;
}

/**
 * Envía un aviso a una suscripción.
 * @return array{ok:bool, codigo:int, baja:bool, error:string}
 */
function push_enviar_a(array $sus, array $datos): array
{
    $p = parse_url((string)$sus['endpoint']);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return ['ok' => false, 'codigo' => 0, 'baja' => true, 'error' => 'endpoint no válido'];
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $cuerpo = push_cifrar((string)json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)$sus['p256dh'], (string)$sus['auth']);
    $ch = curl_init((string)$sus['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . PUSH_TTL,
            'Urgency: high',
            'Authorization: vapid t=' . push_jwt($aud) . ', k=' . push_vapid()['publica'],
        ],
    ]);
    $resp = curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $resp === false ? curl_error($ch) : '';
    curl_close($ch);
    return [
        'ok' => $cod >= 200 && $cod < 300,
        'codigo' => $cod,
        'baja' => in_array($cod, [404, 410], true),       // el dispositivo ya no existe / se dio de baja
        'error' => $err !== '' ? $err : ($cod >= 300 ? 'HTTP ' . $cod . ' ' . mb_substr((string)$resp, 0, 120) : ''),
    ];
}

/* ------------------------------------------------------------------ */
/* Suscripciones                                                       */
/* ------------------------------------------------------------------ */

function push_guardar_suscripcion(int $equipoId, string $endpoint, string $p256dh, string $auth, string $dispositivo): void
{
    if (!preg_match('#^https://[^\s]{10,}$#', $endpoint) || strlen($endpoint) > 1000) throw new InvalidArgumentException('Dirección de notificaciones no válida.');
    if (!preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256dh) || strlen(push_b64u_dec($p256dh)) !== 65) throw new InvalidArgumentException('Llave del dispositivo no válida.');
    if (!preg_match('/^[A-Za-z0-9_-]{16,30}$/', $auth)) throw new InvalidArgumentException('Llave del dispositivo no válida.');
    db()->prepare('INSERT INTO push_suscripciones (equipo_id, endpoint, endpoint_hash, p256dh, auth, dispositivo, activa, fallos)
                   VALUES (?, ?, ?, ?, ?, ?, 1, 0)
                   ON DUPLICATE KEY UPDATE equipo_id = VALUES(equipo_id), p256dh = VALUES(p256dh), auth = VALUES(auth),
                                           dispositivo = VALUES(dispositivo), activa = 1, fallos = 0')
        ->execute([$equipoId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, mb_substr($dispositivo, 0, 120)]);
}

function push_quitar_suscripcion(int $equipoId, string $endpoint): void
{
    db()->prepare('UPDATE push_suscripciones SET activa = 0 WHERE endpoint_hash = ? AND equipo_id = ?')->execute([hash('sha256', $endpoint), $equipoId]);
}

function push_dispositivos(int $equipoId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM push_suscripciones WHERE equipo_id = ? AND activa = 1');
    $st->execute([$equipoId]);
    return (int)$st->fetchColumn();
}

/** Nombre corto del dispositivo a partir del User-Agent (sólo para el panel). */
function push_nombre_dispositivo(string $ua): string
{
    $so = stripos($ua, 'iPhone') !== false ? 'iPhone' : (stripos($ua, 'iPad') !== false ? 'iPad' : (stripos($ua, 'Android') !== false ? 'Android'
        : (stripos($ua, 'Windows') !== false ? 'Windows' : (stripos($ua, 'Mac') !== false ? 'Mac' : 'Otro'))));
    $nav = stripos($ua, 'Edg/') !== false ? 'Edge' : (stripos($ua, 'Firefox') !== false ? 'Firefox'
        : (stripos($ua, 'Chrome') !== false ? 'Chrome' : (stripos($ua, 'Safari') !== false ? 'Safari' : '')));
    return trim($so . ' ' . $nav);
}

/* ------------------------------------------------------------------ */
/* Cola                                                                */
/* ------------------------------------------------------------------ */

/**
 * Agrega un aviso a la cola. Nunca lanza excepciones: un aviso no debe impedir que la
 * acción del capitán se guarde. $llave evita duplicados (recordatorios).
 */
function push_encolar(int $equipoId, string $tipo, string $titulo, string $cuerpo, string $url = '/disponibilidad/rival.php', ?string $llave = null): void
{
    if ($equipoId <= 0 || !push_instalado()) return;
    $cuerpo = str_replace(['a.m..', 'p.m..'], ['a.m.', 'p.m.'], $cuerpo);   // la hora ya termina en punto
    try {
        db()->prepare('INSERT IGNORE INTO push_cola (equipo_id, tipo, titulo, cuerpo, url, llave_unica) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$equipoId, $tipo, mb_substr($titulo, 0, 120), mb_substr($cuerpo, 0, 255), mb_substr($url, 0, 255), $llave]);
        $GLOBALS['PUSH_HAY_NUEVOS'] = true;
    } catch (Throwable $ex) {
        error_log('[push] no se pudo encolar: ' . $ex->getMessage());
    }
}

/**
 * Envía los avisos pendientes (máximo $max o $segundos). Seguro de correr en paralelo:
 * cada aviso se «aparta» con un UPDATE antes de enviarse.
 * @return array{enviadas:int, sin_destino:int, errores:int}
 */
function push_procesar_cola(int $max = 50, int $segundos = 40): array
{
    $res = ['enviadas' => 0, 'sin_destino' => 0, 'errores' => 0];
    if (!push_instalado()) return $res;
    $fin = microtime(true) + $segundos;
    $pdo = db();
    // Avisos que quedaron «enviando» por un corte (más de 10 min): se reintentan.
    $pdo->exec("UPDATE push_cola SET estado = 'pendiente' WHERE estado = 'enviando' AND creado_en < NOW() - INTERVAL 10 MINUTE AND intentos < 3");
    $ids = $pdo->query("SELECT id FROM push_cola WHERE estado = 'pendiente' ORDER BY id LIMIT " . (int)$max)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        if (microtime(true) > $fin) break;
        $st = $pdo->prepare("UPDATE push_cola SET estado = 'enviando', intentos = intentos + 1 WHERE id = ? AND estado = 'pendiente'");
        $st->execute([$id]);
        if (!$st->rowCount()) continue;                      // otro proceso lo tomó
        $st = $pdo->prepare('SELECT *, (creado_en < NOW() - INTERVAL 1 DAY) AS vencido FROM push_cola WHERE id = ?');
        $st->execute([$id]);
        $av = $st->fetch();
        // Avisos viejos (más de 1 día) ya no sirven: se descartan.
        if ((int)$av['vencido'] === 1) {
            $pdo->prepare("UPDATE push_cola SET estado = 'error', detalle = 'Vencido sin enviar' WHERE id = ?")->execute([$id]);
            $res['errores']++;
            continue;
        }
        $st = $pdo->prepare('SELECT * FROM push_suscripciones WHERE equipo_id = ? AND activa = 1');
        $st->execute([(int)$av['equipo_id']]);
        $subs = $st->fetchAll();
        if (!$subs) {
            $pdo->prepare("UPDATE push_cola SET estado = 'sin_destino', detalle = 'El equipo no ha activado notificaciones', enviado_en = NOW() WHERE id = ?")->execute([$id]);
            $res['sin_destino']++;
            continue;
        }
        $datos = ['titulo' => $av['titulo'], 'cuerpo' => $av['cuerpo'], 'url' => $av['url'], 'tag' => $av['tipo'] . '-' . $av['id']];
        $ok = 0; $errs = [];
        foreach ($subs as $s) {
            try {
                $r = push_enviar_a($s, $datos);
            } catch (Throwable $ex) {
                $r = ['ok' => false, 'codigo' => 0, 'baja' => false, 'error' => $ex->getMessage()];
            }
            if ($r['ok']) {
                $ok++;
                $pdo->prepare('UPDATE push_suscripciones SET fallos = 0, ultimo_envio = NOW() WHERE id = ?')->execute([$s['id']]);
            } else {
                $errs[] = $r['error'];
                if ($r['baja']) $pdo->prepare('UPDATE push_suscripciones SET activa = 0 WHERE id = ?')->execute([$s['id']]);
                else $pdo->prepare('UPDATE push_suscripciones SET fallos = fallos + 1, activa = IF(fallos + 1 >= ?, 0, activa) WHERE id = ?')
                        ->execute([PUSH_MAX_FALLOS, $s['id']]);
            }
        }
        if ($ok) {
            $pdo->prepare("UPDATE push_cola SET estado = 'enviada', enviado_en = NOW(), detalle = ? WHERE id = ?")
                ->execute([$ok . ' dispositivo' . ($ok === 1 ? '' : 's') . ($errs ? ' · ' . mb_substr(implode('; ', $errs), 0, 180) : ''), $id]);
            $res['enviadas']++;
        } else {
            $reintento = (int)$av['intentos'] + 1 < 3;
            $pdo->prepare('UPDATE push_cola SET estado = ?, detalle = ? WHERE id = ?')
                ->execute([$reintento ? 'pendiente' : 'error', mb_substr(implode('; ', $errs), 0, 250), $id]);
            $res['errores']++;
        }
    }
    return $res;
}

/**
 * Tras responder al navegador, envía en segundo plano lo que se acaba de encolar
 * (así el aviso llega en segundos sin esperar al Cron).
 */
function push_enviar_al_terminar(): void
{
    static $registrado = false;
    if ($registrado) return;
    $registrado = true;
    register_shutdown_function(function () {
        if (empty($GLOBALS['PUSH_HAY_NUEVOS'])) return;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        ignore_user_abort(true);
        try {
            push_procesar_cola(15, 12);
        } catch (Throwable $ex) {
            error_log('[push] envío inmediato: ' . $ex->getMessage());
        }
    });
}
