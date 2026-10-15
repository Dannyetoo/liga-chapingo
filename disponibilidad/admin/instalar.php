<?php
/**
 * Alta del PRIMER administrador. Funciona mientras admin_usuarios está vacía: en pruebas
 * (detrás del .htpasswd) o en producción con la llave de un solo uso. En cuanto existe
 * un administrador, responde 404.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

cabeceras_admin();

/* Llave de un solo uso para dar de alta al primer administrador en PRODUCCIÓN.
   Sin ella la página no existe (404). En cuanto hay un administrador, tampoco. */
const LLAVE_INSTALACION = 'b9441e9808fdfb403276026d6553e982';
$llave = (string)($_GET['llave'] ?? $_POST['llave'] ?? '');
$llaveOk = strlen($llave) === 32 && hash_equals(LLAVE_INSTALACION, $llave);
if (!(es_entorno_pruebas() || $llaveOk) || !admin_tablas_instaladas() || admin_hay_usuarios()) {
    http_response_code(404);
    exit('No disponible.');
}

$errores = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido($_POST['csrf_token'] ?? null)) {
        $errores = ['La página expiró. Vuelve a intentarlo.'];
    } else {
        $errores = admin_crear_primero(
            (string)($_POST['usuario'] ?? ''), (string)($_POST['nombre'] ?? ''),
            (string)($_POST['clave'] ?? ''), (string)($_POST['clave2'] ?? '')
        );
        if (!$errores) {
            header('Location: entrar.php', true, 303);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Primer administrador · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/finanzas.css?v=1">
</head>
<body>
<?php if (es_entorno_pruebas()): ?>
<div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS · los cambios no afectan ligachapingo.com</div>
<?php endif; ?>
<main class="contenedor acceso">
    <section class="tarjeta acceso__caja">
        <h1 class="tarjeta__titulo">Primer administrador</h1>
        <p class="texto-suave">Esta página desaparece en cuanto se crea el primer usuario. Tendrá permiso de Finanzas.</p>
        <?php if ($errores): ?>
            <div class="aviso aviso--error" role="alert"><ul><?php foreach ($errores as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" action="instalar.php" class="acceso__form">
            <?= csrf_campo() ?>
            <input type="hidden" name="llave" value="<?= e($llave) ?>">
            <label class="campo"><span>Usuario</span>
                <input type="text" name="usuario" required minlength="3" maxlength="60" pattern="[a-z0-9._\-]{3,60}" autocomplete="username" autocapitalize="none" value="<?= e($_POST['usuario'] ?? '') ?>"></label>
            <label class="campo"><span>Nombre completo</span>
                <input type="text" name="nombre" required maxlength="120" value="<?= e($_POST['nombre'] ?? '') ?>"></label>
            <label class="campo"><span>Contraseña (mínimo <?= ADMIN_CLAVE_MIN ?> caracteres)</span>
                <input type="password" name="clave" required minlength="<?= ADMIN_CLAVE_MIN ?>" maxlength="200" autocomplete="new-password"></label>
            <label class="campo"><span>Repite la contraseña</span>
                <input type="password" name="clave2" required minlength="<?= ADMIN_CLAVE_MIN ?>" maxlength="200" autocomplete="new-password"></label>
            <button type="submit" class="btn btn--pri">Crear administrador</button>
        </form>
    </section>
</main>
</body>
</html>
