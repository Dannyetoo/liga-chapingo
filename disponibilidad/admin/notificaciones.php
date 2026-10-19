<?php
/** Administración · Notificaciones: estado del Cron, equipos con avisos activos y últimos avisos (sólo lectura). */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/rival.php';

requerir_admin();
cabeceras_admin();
$semestre = obtener_semestre_activo();
$tituloPagina = 'Notificaciones';
$paginaActual = 'notificaciones';
$ok = push_instalado();
$cron = null; $subs = []; $cola = []; $tot = []; $vapidOk = false;
if ($ok) {
    try { $vapidOk = strlen(push_b64u_dec(push_vapid()['publica'])) === 65; } catch (Throwable $ex) { $vapidOk = false; }
    $cron = push_config_get('cron_ultimo');
    $cronHace = db()->query("SELECT TIMESTAMPDIFF(MINUTE, actualizado_en, NOW()) FROM push_config WHERE clave = 'cron_ultimo'")->fetchColumn();
    $subs = db()->query('SELECT e.nombre, COUNT(*) AS n, GROUP_CONCAT(DISTINCT s.dispositivo SEPARATOR ", ") AS disp, MAX(s.ultimo_envio) AS ultimo
                           FROM push_suscripciones s JOIN equipos e ON e.id = s.equipo_id WHERE s.activa = 1 GROUP BY e.id, e.nombre ORDER BY e.nombre')->fetchAll();
    $cola = db()->query('SELECT c.*, e.nombre FROM push_cola c LEFT JOIN equipos e ON e.id = c.equipo_id ORDER BY c.id DESC LIMIT 40')->fetchAll();
    foreach (db()->query("SELECT estado, COUNT(*) n FROM push_cola WHERE creado_en > NOW() - INTERVAL 7 DAY GROUP BY estado")->fetchAll() as $r) $tot[$r['estado']] = (int)$r['n'];
}
$ET = ['pendiente' => 'Pendiente', 'enviando' => 'Enviando', 'enviada' => 'Enviada', 'sin_destino' => 'Sin dispositivo', 'error' => 'Error'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Notificaciones · Administración · Liga Chapingo</title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=5">
    <link rel="stylesheet" href="../assets/coincidencias.css?v=3">
    <link rel="stylesheet" href="../assets/finanzas.css?v=3">
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor fin">
<?php if (!$ok): ?>
    <div class="aviso aviso--error" role="alert">Falta ejecutar el SQL de notificaciones (push-PRODUCCION.sql).</div>
<?php else: ?>
    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Estado</h2>
        <ul>
            <li>Llaves de notificación: <b><?= $vapidOk ? 'listas' : 'con problema (revisa que PHP tenga OpenSSL)' ?></b></li>
            <li>Última corrida del Cron: <b><?= $cron ? e($cron) : 'todavía no ha corrido' ?></b>
                <?php if ($cron && $cronHace !== false && (int)$cronHace > 15): ?> · <span class="num--neg">hace <?= (int)$cronHace ?> min: revisa la tarea Cron</span><?php endif; ?></li>
            <li>Últimos 7 días: <?php foreach ($ET as $k => $t): ?><?= e($t) ?> <b><?= (int)($tot[$k] ?? 0) ?></b> · <?php endforeach; ?></li>
        </ul>
        <p class="texto-suave">«Sin dispositivo» significa que ese equipo todavía no activó los avisos en su celular (se activan en «Busca un rival»).</p>
    </section>
    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Equipos con avisos activos (<?= count($subs) ?>)</h2>
        <?php if (!$subs): ?><p class="texto-suave">Ningún equipo los ha activado todavía.</p><?php else: ?>
        <div class="tabla-scroll"><table class="fin-tabla">
            <thead><tr><th scope="col">Equipo</th><th scope="col">Dispositivos</th><th scope="col">Último aviso entregado</th></tr></thead>
            <tbody><?php foreach ($subs as $s): ?><tr><td><?= e($s['nombre']) ?></td><td><?= (int)$s['n'] ?> · <?= e((string)$s['disp']) ?></td><td><?= e((string)($s['ultimo'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Últimos avisos</h2>
        <?php if (!$cola): ?><p class="texto-suave">Todavía no hay avisos.</p><?php else: ?>
        <div class="tabla-scroll"><table class="fin-tabla">
            <thead><tr><th scope="col">Cuándo</th><th scope="col">Equipo</th><th scope="col">Aviso</th><th scope="col">Estado</th></tr></thead>
            <tbody><?php foreach ($cola as $c): ?><tr><td><?= e((string)$c['creado_en']) ?></td><td><?= e((string)($c['nombre'] ?? '')) ?></td>
                <td><b><?= e($c['titulo']) ?></b><br><span class="texto-suave"><?= e($c['cuerpo']) ?></span></td>
                <td><?= e($ET[$c['estado']] ?? $c['estado']) ?><?= $c['detalle'] ? '<br><span class="texto-suave">' . e((string)$c['detalle']) . '</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
<?php endif; ?>
</main>
</body>
</html>
