<?php
/**
 * Liga Chapingo — utilidades del semestre activo (27-sep-2026).
 * Evita mostrar «30/11/-0001» cuando MySQL guardó fechas vacías («0000-00-00»).
 */
declare(strict_types=1);

/** «24/08/2026», o '' si la fecha está vacía, es «0000-00-00» o no existe. */
function semestre_fecha_texto($fecha): string
{
    $f = substr((string)$fecha, 0, 10);
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    if (!$d || $d->format('Y-m-d') !== $f || (int)$d->format('Y') < 2000) return '';
    return $d->format('d/m/Y');
}

/** «24/08/2026 – 19/12/2026», o «Fechas por definir». */
function semestre_rango_texto(?array $semestre): string
{
    if (!$semestre) return '';
    $a = semestre_fecha_texto($semestre['fecha_inicio'] ?? '');
    $b = semestre_fecha_texto($semestre['fecha_fin'] ?? '');
    return ($a !== '' && $b !== '') ? $a . ' – ' . $b : 'Fechas por definir';
}
