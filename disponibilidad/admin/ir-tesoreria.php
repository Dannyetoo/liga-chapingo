<?php
/**
 * Del panel a Tesorería sin volver a escribir la contraseña: pase firmado de un solo uso (90 s)
 * que Tesorería canjea por una sesión a nombre de esta misma persona.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/mesa.php';

requerir_admin();
cabeceras_admin();
$adm  = admin_en_sesion();
$pase = $adm ? mesa_pase_crear((int)$adm['id'], 'tesoreria') : '';
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Abriendo Tesorería…</title><link rel="stylesheet" href="../assets/disponibilidad.css?v=6"></head>
<body><main class="contenedor" style="padding-top:60px;max-width:420px;text-align:center">
<?php if ($pase === ''): ?>
    <p>No se pudo abrir Tesorería desde aquí. Entra en <a href="../../arbitrajes/">/arbitrajes/</a> con tu usuario y contraseña.</p>
<?php else: ?>
    <form method="post" action="../../arbitrajes/api.php?a=pase" data-auto>
        <input type="hidden" name="pase" value="<?= e($pase) ?>">
        <p>Abriendo Tesorería…</p>
        <noscript><button type="submit" class="btn btn--pri">Continuar</button></noscript>
    </form>
    <script src="../assets/auto-envio.js"></script>
<?php endif; ?>
</main></body></html>
