<?php
/**
 * Liga Chapingo — Motor de coincidencias.
 *
 * Trabaja con "fuentes" de disponibilidad, no con "dos equipos":
 *   fuente = ['tipo' => 'equipo', 'id' => 1, 'nombre' => 'PSG', 'por_fecha' => [fecha => efectiva]]
 * Fase 4: equipo A + equipo B + cancha = 3 fuentes. Los partidos ya programados (fase
 * siguiente) entrarán por el parámetro $ocupados, que ya se resta del resultado.
 * Tipos de fuente:
 *   'equipo'  → participante (debe estar disponible)
 *   'cancha'  → recurso (debe estar disponible); en la explicación se distingue de los equipos.
 *
 * Esta capa NO escribe nada en la base de datos.
 *
 * PRINCIPIO (ver docs/ARQUITECTURA-PROPUESTAS.md): UNA PROPUESTA NO ES UN PARTIDO.
 *   Un slot calculado aquí es sólo una POSIBILIDAD. Guardar disponibilidad no crea partidos;
 *   una propuesta aceptada tampoco, hasta que el servidor vuelva a ejecutar este mismo cálculo
 *   (con $ocupados = partidos confirmados) dentro de una transacción y confirme que el slot sigue libre.
 */

declare(strict_types=1);

require_once __DIR__ . '/disponibilidad.php';

const COINC_DURACIONES = [40, 50, 60, 70, 80, 90];   // opciones sugeridas en la interfaz
const COINC_PASOS      = [10, 15, 20, 30, 60];
const COINC_DURACION_MIN = 20;
const COINC_DURACION_MAX = 180;
const COINC_MAX_DIAS     = 62;                          // tope del rango consultable

/** Construye una fuente a partir de un equipo (2 consultas para todo el rango). */
function fuenteEquipo(array $equipo, string $desde, string $hasta, array $semestre): array
{
    return [
        'tipo'      => 'equipo',
        'id'        => (int)$equipo['id'],
        'nombre'    => (string)$equipo['nombre'],
        'por_fecha' => obtenerDisponibilidadEfectivaRango((int)$equipo['id'], $desde, $hasta, $semestre),
    ];
}

/** Construye una fuente a partir de una cancha (mismas reglas de excepciones). */
function fuenteCancha(array $cancha, string $desde, string $hasta, array $semestre): array
{
    return [
        'tipo'      => 'cancha',
        'id'        => (int)$cancha['id'],
        'nombre'    => (string)$cancha['nombre'],
        'por_fecha' => obtenerDisponibilidadEfectivaCanchaRango((int)$cancha['id'], $desde, $hasta, $semestre),
    ];
}

/**
 * Núcleo del motor.
 *
 * @param array $fuentes   ≥ 2 fuentes (equipos, y a futuro canchas)
 * @param array $fechas    ['Y-m-d', ...]
 * @param int   $duracion  minutos del partido
 * @param int   $paso      intervalo entre inicios posibles
 * @param array $ocupados  [fecha => [[ini, fin], ...]] horarios ya tomados (futuro); se restan
 */
function calcularCoincidencias(array $fuentes, array $fechas, int $duracion, int $paso, array $ocupados = []): array
{
    if (count($fuentes) < 2) {
        throw new InvalidArgumentException('Se requieren al menos dos fuentes de disponibilidad.');
    }
    $res = ['fechas' => [], 'total_slots' => 0, 'dias_con_slots' => 0, 'dias_sin_coincidencia' => 0];

    foreach ($fechas as $f) {
        $detalle = [];
        $listas = [];
        foreach ($fuentes as $fu) {
            $ef = $fu['por_fecha'][$f] ?? ['bloques' => [], 'notas' => ['Sin datos para esta fecha.'], 'no_disponible_todo_el_dia' => false, 'base' => [], 'extra' => []];
            $listas[] = $ef['bloques'];
            $detalle[] = [
                'tipo'    => $fu['tipo'],
                'id'      => $fu['id'],
                'nombre'  => $fu['nombre'],
                'bloques' => $ef['bloques'],
                'base'    => $ef['base'] ?? [],
                'notas'   => $ef['notas'] ?? [],
                'por_excepcion' => !empty($ef['no_disponible_todo_el_dia'])
                    || (($ef['base'] ?? []) && !$ef['bloques']),
            ];
        }

        $comun = intervalos_intersectar_varios($listas);
        $comunEquipos = intervalos_intersectar_varios(array_column(
            array_filter($detalle, fn($d) => $d['tipo'] === 'equipo'), 'bloques'));
        $libre = intervalos_restar($comun, $ocupados[$f] ?? []);
        $slots = intervalos_a_slots($libre, $duracion, $paso);

        if ($slots) {
            $estado = 'con_slots';
            $motivo = '';
            $res['dias_con_slots']++;
        } elseif ($libre) {
            $estado = 'coincidencia_corta';
            $motivo = 'Coinciden ' . intervalos_texto($libre) . ' (máx. ' . intervalos_max_bloque($libre)
                . ' min), insuficiente para un partido de ' . $duracion . ' min.';
        } else {
            $estado = 'sin_coincidencia';
            $res['dias_sin_coincidencia']++;
            $motivo = explicarSinCoincidencia($detalle, $comun, $ocupados[$f] ?? [], $comunEquipos);
        }

        $res['total_slots'] += count($slots);
        $res['fechas'][$f] = [
            'fecha'   => $f,
            'comun'   => $comun,
            'comun_equipos' => $comunEquipos,
            'libre'   => $libre,
            'slots'   => $slots,
            'estado'  => $estado,
            'motivo'  => $motivo,
            'fuentes' => $detalle,
        ];
    }
    return $res;
}

/**
 * Texto comprensible de por qué no hay coincidencia en una fecha.
 * Se deriva sólo del algoritmo:
 *   1. ¿algún equipo sin disponibilidad?  2. ¿los equipos no se cruzan?
 *   3. ¿los equipos sí coinciden pero la cancha no?  4. ¿todo ocupado?
 */
function explicarSinCoincidencia(array $detalle, array $comun, array $ocupados, ?array $comunEquipos = null): string
{
    $equipos  = array_values(array_filter($detalle, fn($d) => $d['tipo'] === 'equipo'));
    $recursos = array_values(array_filter($detalle, fn($d) => $d['tipo'] !== 'equipo'));
    $comunEquipos = $comunEquipos ?? intervalos_intersectar_varios(array_column($equipos, 'bloques'));

    $sinNada = [];
    foreach ($equipos as $d) {
        if (!$d['bloques']) {
            $sinNada[] = $d['nombre'] . ($d['por_excepcion'] ? ' no está disponible por excepción registrada' : ' no tiene disponibilidad este día');
        }
    }
    if ($sinNada) {
        return implode('; ', $sinNada) . '.';
    }
    if (!$comunEquipos) {
        return $recursos
            ? 'Los equipos tienen disponibilidad, pero sus horarios no se cruzan.'
            : 'Todos tienen disponibilidad, pero sus horarios no se cruzan.';
    }

    $coinciden = count($comunEquipos) === 1
        ? 'entre ' . minutos_a_hora($comunEquipos[0][0]) . ' y ' . minutos_a_hora($comunEquipos[0][1])
        : intervalos_texto($comunEquipos);
    $partes = [];
    foreach ($recursos as $r) {
        if (!$r['bloques']) {
            $partes[] = $r['nombre'] . ($r['por_excepcion'] ? ' no está disponible ese día por excepción registrada' : ' no tiene disponibilidad ese día');
        } elseif (!intervalos_intersectar($comunEquipos, $r['bloques'])) {
            $partes[] = $r['nombre'] . ' no está disponible en ese horario (' . $r['nombre'] . ': ' . intervalos_texto($r['bloques']) . ')';
        }
    }
    if ($partes) {
        return 'Los equipos coinciden ' . $coinciden . ', pero ' . implode('; ', $partes) . '.';
    }
    if ($comun && $ocupados) {
        return 'Los horarios comunes ya están ocupados.';
    }
    return 'No hay un horario en el que todos coincidan.';
}

/* ------------------------------------------------------------------ */
/* Validación de la consulta (servidor)                                */
/* ------------------------------------------------------------------ */

/** Equipo por id SIN filtrar por activo (para distinguir "no existe" de "inactivo"). */
function buscarEquipoCualquierEstado(int $id): ?array
{
    $st = db()->prepare('SELECT id, nombre, capitan_nombre, activo FROM equipos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Valida los parámetros de la consulta de coincidencias.
 * @return array [params|null, errores[]]  params = equipo_a, equipo_b, cancha|null, desde, hasta, duracion, paso, semestre
 */
function validarConsultaCoincidencias(array $in, ?array $semestre): array
{
    $err = [];
    if (!$semestre) {
        return [null, ['No hay un semestre activo. Activa un semestre para consultar coincidencias.']];
    }

    $equipos = [];
    foreach (['a' => 'A', 'b' => 'B'] as $k => $letra) {
        $id = entero_positivo($in['equipo_' . $k] ?? null);
        if (!$id) { $err[] = "Selecciona el equipo $letra."; continue; }
        $eq = buscarEquipoCualquierEstado($id);
        if (!$eq) { $err[] = "El equipo $letra no existe."; continue; }
        if ((int)$eq['activo'] !== 1) { $err[] = 'El equipo ' . $eq['nombre'] . " ($letra) no está activo."; continue; }
        $equipos[$k] = $eq;
    }
    if (isset($equipos['a'], $equipos['b']) && (int)$equipos['a']['id'] === (int)$equipos['b']['id']) {
        $err[] = 'El equipo A y el equipo B deben ser distintos.';
    }

    // Cancha: opcional. Vacía = sólo equipos (comportamiento de la Fase 3).
    $cancha = null;
    $canchaId = trim((string)($in['cancha'] ?? ''));
    if ($canchaId !== '') {
        if (!canchas_instaladas()) {
            $err[] = 'Las canchas aún no están instaladas (falta ejecutar sql/fase4-canchas.sql).';
        } elseif (!($cid = entero_positivo($canchaId)) || !($cancha = obtener_cancha($cid))) {
            $err[] = 'La cancha seleccionada no existe.';
            $cancha = null;
        } elseif ((int)$cancha['activo'] !== 1) {
            $err[] = 'La cancha ' . $cancha['nombre'] . ' está desactivada.';
            $cancha = null;
        }
    }

    $fechas = [];
    foreach (['desde' => 'inicial', 'hasta' => 'final'] as $k => $nom) {
        $v = (string)($in[$k] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) { $err[] = "Indica una fecha $nom válida."; continue; }
        $fechas[$k] = $v;
    }
    $sIni = substr((string)$semestre['fecha_inicio'], 0, 10);
    $sFin = substr((string)$semestre['fecha_fin'], 0, 10);
    if (isset($fechas['desde'], $fechas['hasta'])) {
        if ($fechas['hasta'] < $fechas['desde']) {
            $err[] = 'La fecha final debe ser igual o posterior a la inicial.';
        } else {
            if ($fechas['desde'] < $sIni || $fechas['hasta'] > $sFin) {
                $err[] = 'El periodo debe estar dentro del semestre activo (' . fecha_mx($sIni) . ' – ' . fecha_mx($sFin) . ').';
            }
            $dias = (new DateTimeImmutable($fechas['desde']))->diff(new DateTimeImmutable($fechas['hasta']))->days + 1;
            if ($dias > COINC_MAX_DIAS) {
                $err[] = 'El periodo máximo por consulta es de ' . COINC_MAX_DIAS . " días (seleccionaste $dias).";
            }
        }
    }

    $dur = entero_positivo($in['duracion'] ?? null);
    if (!$dur || $dur < COINC_DURACION_MIN || $dur > COINC_DURACION_MAX || $dur % 5 !== 0) {
        $err[] = 'La duración debe ser de ' . COINC_DURACION_MIN . ' a ' . COINC_DURACION_MAX . ' minutos, en múltiplos de 5.';
    }
    $paso = entero_positivo($in['paso'] ?? null);
    if (!$paso || !in_array($paso, COINC_PASOS, true)) {
        $err[] = 'El intervalo de inicio debe ser uno de: ' . implode(', ', COINC_PASOS) . ' minutos.';
    }

    if ($err) return [null, $err];

    return [[
        'equipo_a' => $equipos['a'],
        'equipo_b' => $equipos['b'],
        'cancha'   => $cancha,
        'desde'    => $fechas['desde'],
        'hasta'    => $fechas['hasta'],
        'duracion' => $dur,
        'paso'     => $paso,
        'semestre' => $semestre,
    ], []];
}

/**
 * Equipo A vs equipo B (∩ cancha, si se eligió) con parámetros ya validados.
 * $ocupados: [fecha => [[ini, fin], ...]] — reservado para restar partidos ya programados.
 */
function buscarCoincidenciasEquipos(array $p, array $ocupados = []): array
{
    $fuentes = [
        fuenteEquipo($p['equipo_a'], $p['desde'], $p['hasta'], $p['semestre']),
        fuenteEquipo($p['equipo_b'], $p['desde'], $p['hasta'], $p['semestre']),
    ];
    if (!empty($p['cancha'])) {
        $fuentes[] = fuenteCancha($p['cancha'], $p['desde'], $p['hasta'], $p['semestre']);
    }
    return calcularCoincidencias($fuentes, fechasEnRango($p['desde'], $p['hasta']), $p['duracion'], $p['paso'], $ocupados);
}

/** 'LUNES 28 SEPTIEMBRE' */
function fecha_larga_mx(string $fecha): string
{
    static $dias = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    static $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $d = new DateTimeImmutable($fecha);
    return $dias[(int)$d->format('N')] . ' ' . (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n')];
}
