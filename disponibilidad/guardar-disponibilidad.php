<?php
/** Guarda TODA la disponibilidad semanal del equipo en una sola operación (transacción). */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

// FUTURO (capitanes): el equipo saldrá de equipo_autorizado(), no de $_POST['equipo_id'].
accion_guardar_semana('equipo');
