<?php
/** Quita (activo = 0) un bloque de horario ya guardado. Sólo si pertenece al equipo. */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

// FUTURO (capitanes): el equipo saldrá de equipo_autorizado(), no de $_POST['equipo_id'].
accion_eliminar_bloque('equipo');
