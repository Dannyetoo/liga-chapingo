<?php
/**
 * Acceso de ADMINISTRACIÓN (producción).
 * En pruebas no hace falta: ahí la administración ya está protegida por el subdominio.
 * La contraseña se compara con DISP_ADMIN_HASH (lib/config-admin.php); nunca se guarda en claro.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const LOGIN_MAX_INTENTOS = 8;           // por IP cada 15 minutos
const LOGIN_ARCHIVO      = __DIR__ . '/../datos/intentos-admin.json';

function login_intentos(bool $sumar): array
{
    $dir = dirname(LOGIN_ARCHIVO);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $k  = hash('sha256', 'disp-admin:' . (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $fp = @fopen(LOGIN_ARCHIVO, 'c+');
    if (!$fp) return [false, LOGIN_MAX_INTENTOS];
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) $d = [];
    foreach ($d as $kk => $v) if (!is_array($v) || time() - (int)($v['t'] ?? 0) > 900) unset($d[$kk]);
    $n = (int)($d[$k]['n'] ?? 0);
    if ($sumar) {
        $d[$k] = ['n' => ++$n, 't' => time()];
        ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)json_encode($d)); fflush($fp);
    }
    flock($fp, LOCK_UN); fclose($fp);
    return [$n >= LOGIN_MAX_INTENTOS, max(0, LOGIN_MAX_INTENTOS - $n)];
}

$accion = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (string)($_POST['accion'] ?? '') : '';
if ($accion !== '' && !csrf_valido($_POST['csrf_token'] ?? null)) {
    flash('error', 'La sesión caducó. Vuelve a intentarlo.');
    header('Location: login.php', true, 303);
    exit;
}

if ($accion === 'salir') {
    cerrar_sesion_admin();
    flash('ok', 'Sesión de administración cerrada.');
    header('Location: login.php', true, 303);
    exit;
}

/* Ya es administración (sesión vigente o entorno de pruebas): al panel. */
if (es_admin()) {
    header('Location: equipos.php', true, 303);
    exit;
}

$hashGenerado = '';
if ($accion === 'generar' && DISP_ADMIN_HASH === '') {
    $c = (string)($_POST['clave'] ?? '');
    if (strlen($c) < 10)                           flash('error', 'Usa al menos 10 caracteres.');
    elseif ($c !== (string)($_POST['clave2'] ?? '')) flash('error', 'Las contraseñas no coinciden.');
    else $hashGenerado = password_hash($c, PASSWORD_DEFAULT);
}

if ($accion === 'entrar' && DISP_ADMIN_HASH !== '') {
    [$bloqueado] = login_intentos(false);
    usleep(300000);
    if ($bloqueado) {
        flash('error', 'Demasiados intentos. Espera 15 minutos.');
    } elseif (password_verify((string)($_POST['clave'] ?? ''), DISP_ADMIN_HASH)) {
        iniciar_sesion_admin();
        header('Location: equipos.php', true, 303);
        exit;
    } else {
        [, $restantes] = login_intentos(true);
        flash('error', 'Contraseña incorrecta. Intentos restantes: ' . $restantes . '.');
    }
    header('Location: login.php', true, 303);
    exit;
}

$mensajes = tomar_flash();
http_response_code(es_capitan() ? 403 : 200);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Administración · Disponibilidad · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
</head>
<body>
<main class="contenedor" style="padding-top:40px;max-width:460px">
    <?php foreach ($mensajes as $m): ?>
        <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(implode(' ', $m['mensajes'])) ?></div>
    <?php endforeach; ?>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Administración</h2>

        <?php if (es_capitan()): ?>
            <p>Esta sección es sólo para la mesa directiva. Si eres capitán, vuelve a tu Estado de cuenta.</p>

        <?php elseif (DISP_ADMIN_HASH === ''): ?>
            <p class="texto-suave">Todavía no hay contraseña de administración. Escribe una aquí: se genera un <em>hash</em> y no se guarda nada.
               Copia la línea en <code>disponibilidad/lib/config-admin.php</code> en lugar de <code>const DISP_ADMIN_HASH = '';</code> y recarga.</p>
            <?php if ($hashGenerado !== ''): ?>
                <p><strong>Pega esta línea en config-admin.php:</strong></p>
                <textarea readonly rows="3" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">const DISP_ADMIN_HASH = '<?= e($hashGenerado) ?>';</textarea>
            <?php endif; ?>
            <form method="post" style="display:grid;gap:12px;margin-top:12px">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="generar">
                <label class="campo">Contraseña nueva <input type="password" name="clave" minlength="10" required autocomplete="new-password"></label>
                <label class="campo">Repítela <input type="password" name="clave2" minlength="10" required autocomplete="new-password"></label>
                <button class="btn btn--pri">Generar hash</button>
            </form>

        <?php else: ?>
            <p class="texto-suave">Sólo mesa directiva.</p>
            <form method="post" style="display:grid;gap:12px">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="entrar">
                <label class="campo">Contraseña <input type="password" name="clave" required autofocus autocomplete="current-password"></label>
                <button class="btn btn--pri">Entrar</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
