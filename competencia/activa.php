<?php
// Activar sólo después de migrar y entregar las nuevas cuentas a los equipos.
return getenv('LC_COMPETENCIA_ACTIVA') === '1';
