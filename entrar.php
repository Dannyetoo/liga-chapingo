<?php
/**
 * Liga Chapingo — Entrada de ADMINISTRACIÓN (usuario + contraseña).
 * Los capitanes NO entran aquí: ellos llegan desde su Estado de cuenta.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

cabeceras_admin();

/** Sólo páginas del propio módulo (evita redirecciones abiertas). */
function destino_seguro(string $v): string
{
    $v = trim($v);
    if (preg_match('#^(admin/)?[a-z0-9_-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $v)) return '../' . $v;
    return 'equipos.php';
}

$volver    = (string)($_POST['volver'] ?? $_GET['volver'] ?? '');
$instalado = admin_tablas_instaladas();
$error     = null;

if ($instalado && es_admin()) {
    header('Location: ' . destino_seguro($volver), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido($_POST['csrf_token'] ?? null)) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } elseif (es_capitan()) {
        $error = 'Cierra primero la sesión de capitán.';
    } else {
        try {
            $error = admin_iniciar_sesion((string)($_POST['usuario'] ?? ''), (string)($_POST['clave'] ?? ''));
        } catch (Throwable $ex) {
            $error = error_interno($ex, 'entrada de administración');
        }
        if ($error === null) {
            header('Location: ' . destino_seguro($volver), true, 303);
            exit;
        }
    }
    usleep(random_int(200000, 400000));   // suaviza intentos automáticos
}
$aviso = !$error && !empty($_GET['vencida']) ? 'Tu sesión se cerró por inactividad. Vuelve a entrar.' : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Administración · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/finanzas.css?v=1">
</head>
<body>
<?php if (es_entorno_pruebas()): ?>
<div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS · los cambios no afectan ligachapingo.com</div>
<?php endif; ?>
<main class="contenedor acceso">
    <section class="tarjeta acceso__caja">
        <div class="marca marca--oscura">
            <span class="marca__logo" aria-hidden="true">LC</span>
            <div>
                <div class="marca__nombre">Liga Chapingo</div>
                <div class="marca__sub">Administración</div>
            </div>
        </div>

        <?php if (!$instalado): ?>
            <div class="aviso aviso--error" role="alert">
                <strong>El acceso de administración no está instalado.</strong>
                Ejecuta <code>sql/finanzas-fase3.sql</code> en la base de pruebas.
            </div>
        <?php elseif (!admin_hay_usuarios()): ?>
            <div class="aviso aviso--info" role="status">
                Todavía no hay administradores.
                <?php if (es_entorno_pruebas()): ?><a href="instalar.php">Crea el primero</a>.<?php else: ?>Pide a la mesa directiva que dé de alta el primero.<?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($error): ?><div class="aviso aviso--error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($aviso): ?><div class="aviso aviso--info" role="status"><?= e($aviso) ?></div><?php endif; ?>
            <form method="post" action="entrar.php" class="acceso__form" autocomplete="on">
                <?= csrf_campo() ?>
                <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <label class="campo">
                    <span>Usuario</span>
                    <input type="text" name="usuario" required maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
                </label>
                <label class="campo">
                    <span>Contraseña</span>
                    <input type="password" name="clave" required maxlength="200" autocomplete="current-password">
                </label>
                <button type="submit" class="btn btn--pri">Entrar</button>
            </form>
            <p class="texto-suave acceso__nota">¿Eres capitán? Entra desde tu <strong>Estado de cuenta</strong>.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
