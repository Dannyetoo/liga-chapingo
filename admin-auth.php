<?php
/**
 * Liga Chapingo — Acceso de ADMINISTRACIÓN al panel /disponibilidad/admin/.
 *
 * Antes: en pruebas.* cualquier visitante era administrador (sólo lo cuidaba el .htpasswd)
 * y en producción no existía el rol. Ahora el administrador es una persona con usuario y
 * contraseña (tabla admin_usuarios), en los dos entornos. El .htpasswd de pruebas se
 * conserva como segunda barrera.
 *
 *  - Contraseñas con password_hash()/password_verify(); nunca se guardan ni se registran.
 *  - Freno: 5 fallos por usuario o 20 por IP en 15 min → se rechaza sin revisar la clave.
 *  - Sesión dentro de la cookie propia «lchdisp» (ruta /disponibilidad/), con
 *    session_regenerate_id al entrar y al salir, 30 min de inactividad y 8 h máximo.
 *  - Si la contraseña cambia o el usuario se desactiva, la sesión deja de valer
 *    en la siguiente petición (se compara una huella del hash guardado).
 *  - Finanzas exige además puede_finanzas = 1.
 */
declare(strict_types=1);

const SESION_ADMIN            = 'disp_admin';
const ADMIN_INACTIVIDAD_S     = 1800;      // 30 min sin actividad
const ADMIN_DURACION_MAX_S    = 28800;     // 8 h desde que entró
const ADMIN_FALLOS_USUARIO    = 5;
const ADMIN_FALLOS_IP         = 20;
const ADMIN_VENTANA_MIN       = 15;
const ADMIN_CLAVE_MIN         = 12;

/** ¿Existen las tablas de administración? (antes de ejecutar el SQL de la fase 3, no). */
function admin_tablas_instaladas(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM admin_usuarios LIMIT 1');
        db()->query('SELECT 1 FROM admin_intentos LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

function admin_hay_usuarios(): bool
{
    if (!admin_tablas_instaladas()) return false;
    return (int)db()->query('SELECT COUNT(*) FROM admin_usuarios')->fetchColumn() > 0;
}

function ip_cliente(): string
{
    // REMOTE_ADDR: la única que el cliente no puede inventar.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function huella_clave(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 24);
}

/**
 * Administrador en sesión si sigue vigente (existe, activo, misma contraseña, sin
 * vencer). Si no, limpia la sesión de admin y devuelve null.
 * @return array{id:int,usuario:string,nombre:string,puede_finanzas:bool}|null
 */
function admin_en_sesion(): ?array
{
    if (array_key_exists('__admin_memo', $GLOBALS)) return $GLOBALS['__admin_memo'];
    $s = $_SESSION[SESION_ADMIN] ?? null;
    $r = null;
    if (is_array($s) && isset($s['id'], $s['huella'], $s['inicio'], $s['ultimo']) && admin_tablas_instaladas()) {
        $ahora = time();
        if ($ahora - (int)$s['ultimo'] > ADMIN_INACTIVIDAD_S || $ahora - (int)$s['inicio'] > ADMIN_DURACION_MAX_S) {
            unset($_SESSION[SESION_ADMIN]);
            $GLOBALS['__admin_vencida'] = true;
        } else {
            $st = db()->prepare('SELECT id, usuario, nombre, clave_hash, puede_finanzas FROM admin_usuarios WHERE id = ? AND activo = 1');
            $st->execute([(int)$s['id']]);
            $u = $st->fetch();
            if ($u && hash_equals(huella_clave((string)$u['clave_hash']), (string)$s['huella'])) {
                $_SESSION[SESION_ADMIN]['ultimo'] = $ahora;
                $r = [
                    'id'             => (int)$u['id'],
                    'usuario'        => (string)$u['usuario'],
                    'nombre'         => (string)$u['nombre'],
                    'puede_finanzas' => (int)$u['puede_finanzas'] === 1,
                ];
            } else {
                unset($_SESSION[SESION_ADMIN]);
            }
        }
    } elseif ($s !== null) {
        unset($_SESSION[SESION_ADMIN]);
    }
    return $GLOBALS['__admin_memo'] = $r;
}

/** Fallos recientes: true si hay que frenar este intento. */
function admin_bloqueado(string $usuario, string $ip): bool
{
    // La ventana se calcula en MySQL (NOW()) para no depender de la zona horaria de PHP.
    $st = db()->prepare('SELECT
            SUM(CASE WHEN usuario = ? THEN 1 ELSE 0 END) AS por_usuario,
            SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END)      AS por_ip
          FROM admin_intentos
         WHERE exito = 0 AND creado_en >= NOW() - INTERVAL ' . (int)ADMIN_VENTANA_MIN . ' MINUTE
           AND (usuario = ? OR ip = ?)');
    $st->execute([$usuario, $ip, $usuario, $ip]);
    $f = $st->fetch() ?: [];
    return (int)($f['por_usuario'] ?? 0) >= ADMIN_FALLOS_USUARIO || (int)($f['por_ip'] ?? 0) >= ADMIN_FALLOS_IP;
}

function admin_anotar_intento(string $usuario, string $ip, bool $exito): void
{
    db()->prepare('INSERT INTO admin_intentos (usuario, ip, exito) VALUES (?, ?, ?)')
        ->execute([mb_substr($usuario, 0, 60), $ip, $exito ? 1 : 0]);
}

/**
 * Intenta abrir la sesión de administración.
 * @return string|null  null = correcto; texto = mensaje para mostrar (siempre genérico).
 */
function admin_iniciar_sesion(string $usuario, string $clave): ?string
{
    $generico = 'Usuario o contraseña incorrectos.';
    $usuario  = mb_strtolower(trim($usuario));
    if (!admin_tablas_instaladas()) return 'El acceso de administración todavía no está instalado.';
    if ($usuario === '' || $clave === '' || mb_strlen($usuario) > 60 || strlen($clave) > 200) return $generico;

    $ip = ip_cliente();
    if (admin_bloqueado($usuario, $ip)) {
        error_log('[disponibilidad] acceso admin frenado por intentos: ' . $usuario . ' desde ' . $ip);
        return 'Demasiados intentos. Espera ' . ADMIN_VENTANA_MIN . ' minutos e inténtalo de nuevo.';
    }

    $st = db()->prepare('SELECT id, usuario, clave_hash, activo FROM admin_usuarios WHERE usuario = ?');
    $st->execute([$usuario]);
    $u = $st->fetch();
    // Se verifica contra un hash de relleno si el usuario no existe: mismo tiempo de respuesta.
    $hash = $u ? (string)$u['clave_hash'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok   = password_verify($clave, $hash) && $u && (int)$u['activo'] === 1;

    admin_anotar_intento($usuario, $ip, $ok);
    if (!$ok) {
        fin_bitacora_registrar($usuario, 'acceso_fallido', 'sesion', null);
        return $generico;
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $nuevo = password_hash($clave, PASSWORD_DEFAULT);
        db()->prepare('UPDATE admin_usuarios SET clave_hash = ? WHERE id = ?')->execute([$nuevo, (int)$u['id']]);
        $hash = $nuevo;
    }
    db()->prepare('UPDATE admin_usuarios SET ultimo_acceso = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$u['id']]);

    // Identidad nueva: se cierra cualquier contexto de capitán y se cambia el id de sesión.
    unset($_SESSION[SESION_CAPITAN], $_SESSION['csrf_token'], $GLOBALS['__capitan_memo'], $GLOBALS['__admin_memo']);
    session_regenerate_id(true);
    $_SESSION[SESION_ADMIN] = [
        'id'     => (int)$u['id'],
        'huella' => huella_clave($hash),
        'inicio' => time(),
        'ultimo' => time(),
    ];
    fin_bitacora_registrar((string)$u['usuario'], 'acceso', 'sesion', (string)$u['id']);
    return null;
}

function admin_cerrar_sesion(): void
{
    $a = admin_en_sesion();
    if ($a) fin_bitacora_registrar($a['usuario'], 'salida', 'sesion', (string)$a['id']);
    unset($_SESSION[SESION_ADMIN], $_SESSION[SESION_CAPITAN], $_SESSION['csrf_token'],
          $GLOBALS['__admin_memo'], $GLOBALS['__capitan_memo']);
    session_regenerate_id(true);
}

/**
 * Alta del PRIMER administrador (sólo cuando la tabla está vacía).
 * @return string[] errores (vacío = creado)
 */
function admin_crear_primero(string $usuario, string $nombre, string $clave, string $clave2): array
{
    $err = [];
    $usuario = mb_strtolower(trim($usuario));
    $nombre  = trim($nombre);
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $usuario)) $err[] = 'El usuario debe tener de 3 a 60 caracteres: letras minúsculas, números, punto, guion o guion bajo.';
    if ($nombre === '' || mb_strlen($nombre) > 120) $err[] = 'Escribe el nombre de la persona (máximo 120 caracteres).';
    if (strlen($clave) < ADMIN_CLAVE_MIN) $err[] = 'La contraseña debe tener al menos ' . ADMIN_CLAVE_MIN . ' caracteres.';
    if (strlen($clave) > 200) $err[] = 'La contraseña es demasiado larga.';
    if (!hash_equals($clave, $clave2)) $err[] = 'Las contraseñas no coinciden.';
    if ($err) return $err;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Bloquea la tabla para que dos personas no creen «el primero» al mismo tiempo.
        $n = (int)$pdo->query('SELECT COUNT(*) FROM admin_usuarios FOR UPDATE')->fetchColumn();
        if ($n > 0) {
            $pdo->rollBack();
            return ['Ya existe un administrador. Esta página ya no está disponible.'];
        }
        $pdo->prepare('INSERT INTO admin_usuarios (usuario, nombre, clave_hash, puede_finanzas, activo) VALUES (?, ?, ?, 1, 1)')
            ->execute([$usuario, $nombre, password_hash($clave, PASSWORD_DEFAULT)]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [error_interno($ex, 'alta del primer administrador')];
    }
    fin_bitacora_registrar($usuario, 'alta_primer_admin', 'admin_usuario', null);
    return [];
}

/** Registro en la bitácora. Nunca interrumpe la petición si falla. */
function fin_bitacora_registrar(string $usuario, string $accion, string $entidad, ?string $entidadId,
                                $antes = null, $despues = null): void
{
    try {
        db()->prepare('INSERT INTO fin_bitacora (usuario, accion, entidad, entidad_id, antes, despues, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                mb_substr($usuario, 0, 60), $accion, $entidad, $entidadId,
                $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
                ip_cliente(),
            ]);
    } catch (Throwable $ex) {
        error_log('[disponibilidad] bitácora: ' . $ex->getMessage());
    }
}

/** Cabeceras comunes de las páginas de administración. */
function cabeceras_admin(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}
