<?php
/** Registra una excepción de disponibilidad del equipo. */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

// FUTURO (capitanes): el equipo saldrá de equipo_autorizado(), no de $_POST['equipo_id'].
accion_guardar_excepcion('equipo');
