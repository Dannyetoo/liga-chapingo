<?php
/**
 * Liga Chapingo — Disponibilidad EFECTIVA por fecha.
 *
 * ÚNICO lugar donde se aplican las reglas de excepciones, para equipos Y canchas.
 * Cualquier módulo futuro (generador de jornadas, reprogramaciones) debe pasar por aquí o por
 * calcularDisponibilidadEfectiva() en lugar de reinterpretar las tablas.
 *
 * REGLAS (en este orden):
 *   1. Base = bloques recurrentes activos del día de la semana (1=lunes … 7=domingo).
 *   2. + disponibilidad_extra de esa fecha (se UNE a la base).
 *   3. − no_disponible de esa fecha:
 *        - sin horas  → el día completo queda en [] (gana sobre cualquier extra);
 *        - con horas  → se resta sólo esa franja.
 *   Las restricciones siempre ganan: ante la duda, el equipo NO está disponible.
 */

declare(strict_types=1);

require_once __DIR__ . '/intervalos.php';

/**
 * Función PURA (sin BD) — reutilizable y probable con datos en memoria.
 *
 * @param array  $recurrentePorDia [dia(1-7) => [['hora_inicio'=>'16:00','hora_fin'=>'21:00'], ...]]
 * @param array  $excepciones      filas de excepciones_disponibilidad de ESA fecha
 *                                 (tipo, hora_inicio, hora_fin, motivo)
 * @param string $fecha            'Y-m-d'
 * @return array{
 *   fecha:string, dia_semana:int,
 *   base:array, extra:array, restringido:array, no_disponible_todo_el_dia:bool,
 *   bloques:array, notas:string[]
 * }  'bloques' es la disponibilidad real en minutos [[ini, fin], ...]
 */
function calcularDisponibilidadEfectiva(array $recurrentePorDia, array $excepciones, string $fecha): array
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        throw new InvalidArgumentException('Fecha inválida.');
    }
    $dia = (int)$d->format('N');

    $base = intervalos_desde_horas($recurrentePorDia[$dia] ?? []);
    $extra = [];
    $restringido = [];
    $todoElDia = false;
    $notas = [];

    foreach ($excepciones as $ex) {
        $fechaEx = substr((string)($ex['fecha'] ?? $fecha), 0, 10);
        if ($fechaEx !== $fecha) continue;
        $tipo   = (string)($ex['tipo'] ?? '');
        $ini    = hora_a_minutos($ex['hora_inicio'] ?? null);
        $fin    = hora_a_minutos($ex['hora_fin'] ?? null);
        $motivo = trim((string)($ex['motivo'] ?? ''));
        $sufijo = $motivo !== '' ? ' (' . $motivo . ')' : '';

        if ($tipo === 'no_disponible') {
            if ($ini === null || $fin === null || $fin <= $ini) {
                $todoElDia = true;
                $notas[] = 'No disponible todo el día por excepción registrada' . $sufijo . '.';
            } else {
                $restringido[] = [$ini, $fin];
                $notas[] = 'No disponible ' . intervalos_texto([[$ini, $fin]]) . ' por excepción registrada' . $sufijo . '.';
            }
        } elseif ($tipo === 'disponibilidad_extra' && $ini !== null && $fin !== null && $fin > $ini) {
            $extra[] = [$ini, $fin];
            $notas[] = 'Disponibilidad extraordinaria ' . intervalos_texto([[$ini, $fin]]) . $sufijo . '.';
        }
    }

    $extra = intervalos_normalizar($extra);
    $restringido = intervalos_normalizar($restringido);

    if ($todoElDia) {
        $bloques = [];
    } else {
        $bloques = intervalos_restar(intervalos_unir($base, $extra), $restringido);
    }

    if (!$base && !$extra && !$todoElDia) {
        $notas[] = 'Sin disponibilidad semanal registrada para este día.';
    }

    return [
        'fecha'                     => $fecha,
        'dia_semana'                => $dia,
        'base'                      => $base,
        'extra'                     => $extra,
        'restringido'               => $restringido,
        'no_disponible_todo_el_dia' => $todoElDia,
        'bloques'                   => $bloques,
        'notas'                     => $notas,
    ];
}

/**
 * Recurrente activo de cualquier entidad ('equipo', 'cancha', …) agrupado por día.
 * [dia => [['hora_inicio'=>..,'hora_fin'=>..], ...]]
 */
function cargarRecurrente(string $entidad, int $id, int $semestreId): array
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'SELECT dia_semana, hora_inicio, hora_fin
           FROM ' . $E['disp'] . '
          WHERE ' . $E['col'] . ' = ? AND semestre_id = ? AND activo = 1'
    );
    $st->execute([$id, $semestreId]);
    $porDia = [];
    foreach ($st->fetchAll() as $f) {
        $porDia[(int)$f['dia_semana']][] = $f;
    }
    return $porDia;
}

/** Excepciones de cualquier entidad en el rango, agrupadas por fecha. */
function cargarExcepciones(string $entidad, int $id, int $semestreId, string $desde, string $hasta): array
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'SELECT fecha, tipo, hora_inicio, hora_fin, motivo
           FROM ' . $E['exc'] . '
          WHERE ' . $E['col'] . ' = ? AND semestre_id = ? AND fecha BETWEEN ? AND ?'
    );
    $st->execute([$id, $semestreId, $desde, $hasta]);
    $porFecha = [];
    foreach ($st->fetchAll() as $f) {
        $porFecha[substr((string)$f['fecha'], 0, 10)][] = $f;
    }
    return $porFecha;
}

/** Compatibilidad Fase 3. */
function cargarRecurrenteEquipo(int $equipoId, int $semestreId): array
{
    return cargarRecurrente('equipo', $equipoId, $semestreId);
}

/** Compatibilidad Fase 3. */
function cargarExcepcionesEquipo(int $equipoId, int $semestreId, string $desde, string $hasta): array
{
    return cargarExcepciones('equipo', $equipoId, $semestreId, $desde, $hasta);
}

/** Lista de fechas 'Y-m-d' entre $desde y $hasta (inclusive). */
function fechasEnRango(string $desde, string $hasta): array
{
    $out = [];
    $d = new DateTimeImmutable($desde);
    $h = new DateTimeImmutable($hasta);
    for (; $d <= $h; $d = $d->modify('+1 day')) {
        $out[] = $d->format('Y-m-d');
    }
    return $out;
}

/**
 * Disponibilidad efectiva de CUALQUIER entidad para cada fecha de un rango.
 * 2 consultas en total, sin importar cuántos días tenga el rango.
 * Las fechas fuera del semestre quedan con bloques [] y una nota.
 *
 * @return array [fecha => resultado de calcularDisponibilidadEfectiva()]
 */
function obtenerDisponibilidadEfectivaEntidadRango(string $entidad, int $id, string $desde, string $hasta, ?array $semestre = null): array
{
    $semestre = $semestre ?? obtener_semestre_activo();
    if (!$semestre) {
        throw new RuntimeException('No hay semestre activo.');
    }
    $semId = (int)$semestre['id'];
    $sIni = substr((string)$semestre['fecha_inicio'], 0, 10);
    $sFin = substr((string)$semestre['fecha_fin'], 0, 10);

    $recurrente  = cargarRecurrente($entidad, $id, $semId);
    $excepciones = cargarExcepciones($entidad, $id, $semId, $desde, $hasta);

    $out = [];
    foreach (fechasEnRango($desde, $hasta) as $f) {
        $r = calcularDisponibilidadEfectiva($recurrente, $excepciones[$f] ?? [], $f);
        if ($f < $sIni || $f > $sFin) {
            $r['bloques'] = [];
            $r['notas'] = ['Fecha fuera del semestre activo.'];
        }
        $out[$f] = $r;
    }
    return $out;
}

/* ---- Equipos ---- */

function obtenerDisponibilidadEfectivaRango(int $equipoId, string $desde, string $hasta, ?array $semestre = null): array
{
    return obtenerDisponibilidadEfectivaEntidadRango('equipo', $equipoId, $desde, $hasta, $semestre);
}

/**
 * Disponibilidad efectiva de UN equipo en UNA fecha.
 * Ejemplo: obtenerDisponibilidadEfectiva(PSG, '2026-10-14')['bloques'] → []
 */
function obtenerDisponibilidadEfectiva(int $equipoId, string $fecha, ?array $semestre = null): array
{
    return obtenerDisponibilidadEfectivaRango($equipoId, $fecha, $fecha, $semestre)[$fecha];
}

/* ---- Canchas (mismas reglas, mismas excepciones) ---- */

function obtenerDisponibilidadEfectivaCanchaRango(int $canchaId, string $desde, string $hasta, ?array $semestre = null): array
{
    return obtenerDisponibilidadEfectivaEntidadRango('cancha', $canchaId, $desde, $hasta, $semestre);
}

/**
 * Disponibilidad efectiva de UNA cancha en UNA fecha.
 * Ejemplo: cancha con miércoles 16–21 + excepción 30/09 no_disponible → ['bloques'] = []
 */
function obtenerDisponibilidadEfectivaCancha(int $canchaId, string $fecha, ?array $semestre = null): array
{
    return obtenerDisponibilidadEfectivaCanchaRango($canchaId, $fecha, $fecha, $semestre)[$fecha];
}
