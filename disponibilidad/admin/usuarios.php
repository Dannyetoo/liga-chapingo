<?php
/**
 * Administración · USUARIOS DE LA MESA DIRECTIVA (30-sep-2026).
 * Una cuenta por persona: sirve para el panel y para Tesorería. Lo que cada quien cobra o edita
 * queda en la bitácora con su usuario. Sólo quien tiene permiso de Finanzas administra cuentas.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';

requerir_admin();
cabeceras_admin();
$yo = admin_en_sesion();
if (!$yo || !$yo['puede_finanzas']) { http_response_code(403); exit('Sólo quien tiene permiso de Finanzas administra las cuentas.'); }

$semestre = obtener_semestre_activo();
$tituloPagina = 'Usuarios de la mesa';
$paginaActual = 'usuarios';

function usr_volver(): void { header('Location: usuarios.php', true, 303); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido($_POST['csrf_token'] ?? null)) { flash('error', 'La página expiró. Vuelve a intentarlo.'); usr_volver(); }
    $acc = (string)($_POST['accion'] ?? '');
    $id  = max(0, (int)($_POST['id'] ?? 0));
    $pdo = db();
    try {
        if ($acc === 'alta') {
            $usuario = mb_strtolower(trim((string)($_POST['usuario'] ?? '')));
            $nombre  = trim((string)($_POST['nombre'] ?? ''));
            $clave   = (string)($_POST['clave'] ?? '');
            $fin     = !empty($_POST['finanzas']) ? 1 : 0;
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $usuario)) throw new RuntimeException('Usuario: de 3 a 60 caracteres (minúsculas, números, punto, guion).');
            if ($nombre === '' || mb_strlen($nombre) > 120) throw new RuntimeException('Escribe el nombre de la persona.');
            if (strlen($clave) < ADMIN_CLAVE_MIN) throw new RuntimeException('La contraseña debe tener al menos ' . ADMIN_CLAVE_MIN . ' caracteres.');
            $st = $pdo->prepare('SELECT 1 FROM admin_usuarios WHERE usuario = ?'); $st->execute([$usuario]);
            if ($st->fetchColumn()) throw new RuntimeException('Ese usuario ya existe.');
            $pdo->prepare('INSERT INTO admin_usuarios (usuario, nombre, clave_hash, puede_finanzas, activo) VALUES (?, ?, ?, ?, 1)')
                ->execute([$usuario, $nombre, password_hash($clave, PASSWORD_DEFAULT), $fin]);
            fin_bitacora_registrar($yo['usuario'], 'alta_usuario', 'admin_usuario', $usuario, null, ['nombre' => $nombre, 'finanzas' => $fin]);
            flash('ok', 'Cuenta creada: ' . $usuario . '. Ya puede entrar al panel y a Tesorería.');
        } elseif ($acc === 'clave' && $id) {
            $clave = (string)($_POST['clave'] ?? '');
            if (strlen($clave) < ADMIN_CLAVE_MIN) throw new RuntimeException('La contraseña debe tener al menos ' . ADMIN_CLAVE_MIN . ' caracteres.');
            $pdo->prepare('UPDATE admin_usuarios SET clave_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
            fin_bitacora_registrar($yo['usuario'], 'cambio_clave', 'admin_usuario', (string)$id);
            flash('ok', 'Contraseña cambiada. Sus sesiones abiertas se cerraron.');
        } elseif (in_array($acc, ['activar', 'desactivar', 'con_finanzas', 'sin_finanzas'], true) && $id) {
            if ($id === $yo['id'] && in_array($acc, ['desactivar', 'sin_finanzas'], true)) throw new RuntimeException('No puedes quitarte acceso a ti mismo.');
            $sql = ['activar' => 'activo = 1', 'desactivar' => 'activo = 0', 'con_finanzas' => 'puede_finanzas = 1', 'sin_finanzas' => 'puede_finanzas = 0'][$acc];
            $pdo->prepare("UPDATE admin_usuarios SET $sql WHERE id = ?")->execute([$id]);
            fin_bitacora_registrar($yo['usuario'], $acc, 'admin_usuario', (string)$id);
            flash('ok', 'Cuenta actualizada.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    } catch (Throwable $ex) {
        flash('error', error_interno($ex, 'usuarios de la mesa'));
    }
    usr_volver();
}

$lista = db()->query('SELECT id, usuario, nombre, puede_finanzas, activo, ultimo_acceso FROM admin_usuarios ORDER BY activo DESC, nombre')->fetchAll();
$bit = [];
try {
    $bit = db()->query("SELECT usuario, accion, entidad, entidad_id, creado_en FROM fin_bitacora
                         WHERE entidad LIKE 'teso\\_%' ORDER BY id DESC LIMIT 40")->fetchAll();
} catch (Throwable $ex) { $bit = []; }
$nomAcc = ['alta' => 'registró', 'edicion' => 'editó', 'baja' => 'borró'];
$nomEnt = ['teso_ticket' => 'ticket', 'teso_gasto' => 'gasto', 'teso_transferencia' => 'transferencia'];
$mensajes = tomar_flash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Usuarios · Administración · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=6">
    <link rel="stylesheet" href="../assets/finanzas.css?v=3">
    <link rel="stylesheet" href="../assets/admin-rival.css?v=20260930">
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin">
    <?php foreach ($mensajes as $m): ?>
        <div class="aviso aviso--<?= e($m['tipo']) ?>" role="alert"><?= e(implode(' ', $m['mensajes'])) ?></div>
    <?php endforeach; ?>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Mesa directiva · un solo acceso</h2>
        <p class="texto-suave">Cada persona tiene su usuario y contraseña, y le sirven para el panel y para Tesorería. Lo que registra, cobra o edita en Tesorería queda a su nombre.</p>
        <div class="tabla-scroll">
        <table class="fin-tabla">
            <thead><tr><th>Persona</th><th>Usuario</th><th>Finanzas</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $u): $es = (int)$u['id'] === $yo['id']; ?>
                <tr class="<?= (int)$u['activo'] ? '' : 'fila--off' ?>">
                    <td><b><?= e($u['nombre']) ?></b><?= $es ? ' <small class="texto-suave">(tú)</small>' : '' ?></td>
                    <td><?= e($u['usuario']) ?></td>
                    <td><?= (int)$u['puede_finanzas'] ? 'Sí' : 'No' ?></td>
                    <td><?= (int)$u['activo'] ? 'Activa' : 'Desactivada' ?></td>
                    <td><?= $u['ultimo_acceso'] ? e(date('d/m/Y H:i', strtotime((string)$u['ultimo_acceso']))) : '—' ?></td>
                    <td>
                        <details class="ar-det"><summary>Cambiar</summary>
                            <form method="post" class="fin-form" style="margin-top:8px">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="accion" value="clave">
                                <label class="campo"><span>Contraseña nueva</span><input type="password" name="clave" minlength="<?= ADMIN_CLAVE_MIN ?>" required autocomplete="new-password"></label>
                                <button class="btn btn--sec btn--chico">Guardar contraseña</button>
                            </form>
                            <?php if (!$es): ?>
                            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn--sec btn--chico" name="accion" value="<?= (int)$u['activo'] ? 'desactivar' : 'activar' ?>"><?= (int)$u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                <button class="btn btn--sec btn--chico" name="accion" value="<?= (int)$u['puede_finanzas'] ? 'sin_finanzas' : 'con_finanzas' ?>"><?= (int)$u['puede_finanzas'] ? 'Quitar Finanzas' : 'Dar Finanzas' ?></button>
                            </form>
                            <?php endif; ?>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Agregar persona</h2>
        <form method="post" class="fin-form">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="alta">
            <label class="campo"><span>Nombre</span><input name="nombre" maxlength="120" required placeholder="Ej. Adán López"></label>
            <label class="campo"><span>Usuario</span><input name="usuario" maxlength="60" required pattern="[a-z0-9._\-]{3,60}" placeholder="adan" autocomplete="off"></label>
            <label class="campo"><span>Contraseña (mín. <?= ADMIN_CLAVE_MIN ?>)</span><input type="password" name="clave" minlength="<?= ADMIN_CLAVE_MIN ?>" required autocomplete="new-password"></label>
            <label class="casilla"><input type="checkbox" name="finanzas" value="1"> Puede ver Finanzas y administrar cuentas</label>
            <div class="fin-form__botones"><button class="btn btn--pri">Crear cuenta</button></div>
        </form>
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Últimos cambios en Tesorería</h2>
        <?php if (!$bit): ?><p class="texto-suave">Todavía no hay registros.</p><?php else: ?>
        <ul class="ar-simple">
            <?php foreach ($bit as $b): ?>
                <li><b><?= e($b['usuario']) ?></b> <?= e($nomAcc[$b['accion']] ?? $b['accion']) ?> un <?= e($nomEnt[$b['entidad']] ?? $b['entidad']) ?>
                    <small class="texto-suave">· <?= e(date('d/m H:i', strtotime((string)$b['creado_en']))) ?> · <?= e((string)$b['entidad_id']) ?></small></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
