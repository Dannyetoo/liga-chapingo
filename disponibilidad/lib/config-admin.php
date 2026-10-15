<?php
/* =====================================================================
   Disponibilidad — contraseña de ADMINISTRACIÓN (producción)
   · En pruebas no se usa: el subdominio ya está protegido.
   · En producción: abre /disponibilidad/admin/login.php, escribe una
     contraseña, copia la línea que te da y pégala aquí en lugar de la de abajo.
   · Este archivo está en lib/, que .htaccess bloquea desde el navegador.
   · /tickets-futuros/ usa esta misma contraseña si su config.php se deja vacío.
   ===================================================================== */
const DISP_ADMIN_HASH = '$2y$10$RvgQ.Ucve3UKwcDoI0N9aOKwFCIHJ55NXbp.PssWz61MazyTsEhWu';

/* Minutos sin actividad antes de cerrar la sesión de administración. */
const DISP_ADMIN_MINUTOS = 120;
