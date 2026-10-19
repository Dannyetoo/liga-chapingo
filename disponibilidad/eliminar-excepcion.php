<?php
/** Elimina una excepción. Sólo si pertenece al equipo y al semestre activo. */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

// FUTURO (capitanes): el equipo saldrá de equipo_autorizado(), no de $_POST['equipo_id'].
accion_eliminar_excepcion('equipo');
