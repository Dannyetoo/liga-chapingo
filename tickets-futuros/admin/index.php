<?php
/**
 * Tickets futuros · la administración se trasladó a Finanzas.
 * Desde la fase 4 cada ticket nace de un partido (siempre con rival), y se administra en
 * /disponibilidad/admin/tickets.php con el acceso de administración.
 * Este archivo sólo redirige; la versión anterior se conserva como respaldo fuera del sitio.
 */
header('Cache-Control: no-store');
header('Location: ../../disponibilidad/admin/tickets.php', true, 302);
exit;
