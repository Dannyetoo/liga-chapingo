<?php
/**
 * Liga Chapingo — Álgebra de intervalos horarios (sin base de datos).
 *
 * Un intervalo es [inicio, fin) en MINUTOS desde las 00:00 (ej. 18:30 = 1110).
 * Una "lista" es un arreglo de intervalos. Todas las funciones devuelven listas
 * normalizadas: ordenadas, sin solapes y sin intervalos vacíos.
 *
 * Este archivo no sabe nada de equipos, canchas ni partidos: por eso es reutilizable
 * para disponibilidad de canchas, horarios ocupados, jornadas y reprogramaciones.
 */

declare(strict_types=1);

/** 'HH:MM' o 'HH:MM:SS' → minutos. null si el formato no es válido. */
function hora_a_minutos(?string $hora): ?int
{
    if ($hora === null || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($hora), $m)) {
        return null;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

/** minutos → 'HH:MM' */
function minutos_a_hora(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** Convierte [['hora_inicio'=>'13:00','hora_fin'=>'15:00'], ...] a lista de intervalos. */
function intervalos_desde_horas(array $filas, string $kIni = 'hora_inicio', string $kFin = 'hora_fin'): array
{
    $lista = [];
    foreach ($filas as $f) {
        $i = hora_a_minutos($f[$kIni] ?? null);
        $j = hora_a_minutos($f[$kFin] ?? null);
        if ($i !== null && $j !== null && $j > $i) {
            $lista[] = [$i, $j];
        }
    }
    return intervalos_normalizar($lista);
}

/** Ordena y une intervalos que se enciman o se tocan. */
function intervalos_normalizar(array $lista): array
{
    $lista = array_values(array_filter($lista, fn($x) => $x[1] > $x[0]));
    usort($lista, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    $out = [];
    foreach ($lista as [$i, $f]) {
        $n = count($out);
        if ($n && $i <= $out[$n - 1][1]) {
            $out[$n - 1][1] = max($out[$n - 1][1], $f);
        } else {
            $out[] = [$i, $f];
        }
    }
    return $out;
}

/** A ∪ B */
function intervalos_unir(array $a, array $b): array
{
    return intervalos_normalizar(array_merge($a, $b));
}

/** A ∩ B (barrido lineal, soporta múltiples bloques en ambos lados). */
function intervalos_intersectar(array $a, array $b): array
{
    $a = intervalos_normalizar($a);
    $b = intervalos_normalizar($b);
    $out = [];
    $i = $j = 0;
    while ($i < count($a) && $j < count($b)) {
        $ini = max($a[$i][0], $b[$j][0]);
        $fin = min($a[$i][1], $b[$j][1]);
        if ($fin > $ini) {
            $out[] = [$ini, $fin];
        }
        if ($a[$i][1] < $b[$j][1]) $i++; else $j++;
    }
    return $out;
}

/** A₁ ∩ A₂ ∩ … ∩ Aₙ. Con 0 listas devuelve []. */
function intervalos_intersectar_varios(array $listas): array
{
    if (!$listas) return [];
    $acum = intervalos_normalizar(array_shift($listas));
    foreach ($listas as $l) {
        if (!$acum) break;
        $acum = intervalos_intersectar($acum, $l);
    }
    return $acum;
}

/** A − B (quita de A todo lo que cae en B). Servirá para "horarios ya ocupados". */
function intervalos_restar(array $a, array $b): array
{
    $a = intervalos_normalizar($a);
    $b = intervalos_normalizar($b);
    $out = [];
    foreach ($a as [$ini, $fin]) {
        $cursor = $ini;
        foreach ($b as [$bi, $bf]) {
            if ($bf <= $cursor || $bi >= $fin) continue;
            if ($bi > $cursor) $out[] = [$cursor, $bi];
            $cursor = max($cursor, $bf);
            if ($cursor >= $fin) break;
        }
        if ($cursor < $fin) $out[] = [$cursor, $fin];
    }
    return $out;
}

/**
 * Convierte una lista de intervalos en horarios concretos de partido.
 *
 * - $duracion: minutos del partido (configurable, no fija).
 * - $paso: cada cuántos minutos puede iniciar un partido.
 * - Los inicios se alinean a la rejilla del paso contada desde las 00:00
 *   (con paso 30 → :00 y :30), para que los horarios sean "redondos".
 * - Nunca se genera un slot cuyo final quede fuera del intervalo.
 *
 * Devuelve [[inicio, fin], ...] en minutos.
 */
function intervalos_a_slots(array $lista, int $duracion, int $paso): array
{
    if ($duracion <= 0 || $paso <= 0) {
        throw new InvalidArgumentException('Duración y paso deben ser positivos.');
    }
    $slots = [];
    foreach (intervalos_normalizar($lista) as [$ini, $fin]) {
        $t = (int)(ceil($ini / $paso) * $paso);
        for (; $t + $duracion <= $fin; $t += $paso) {
            $slots[] = [$t, $t + $duracion];
        }
    }
    return $slots;
}

/** Suma de minutos cubiertos por la lista. */
function intervalos_minutos(array $lista): int
{
    $t = 0;
    foreach (intervalos_normalizar($lista) as [$i, $f]) $t += $f - $i;
    return $t;
}

/** Bloque más largo, en minutos (0 si vacío). */
function intervalos_max_bloque(array $lista): int
{
    $m = 0;
    foreach ($lista as [$i, $f]) $m = max($m, $f - $i);
    return $m;
}

/** [[1080,1200]] → '18:00–20:00' ; varios separados por coma. */
function intervalos_texto(array $lista, string $sep = ', '): string
{
    return implode($sep, array_map(fn($x) => minutos_a_hora($x[0]) . '–' . minutos_a_hora($x[1]), $lista));
}
