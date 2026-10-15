<?php
/**
 * Liga Chapingo — Finanzas · arranque común de las páginas del módulo
 * (permiso, lectura de Tesorería, edición elegida y formatos).
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/finanzas-fuente.php';
require_once __DIR__ . '/finanzas-calculos.php';
require_once __DIR__ . '/finanzas-huellas.php';
require_once __DIR__ . '/partidos.php';

function mxn(float $x): string
{
    $neg = $x < -0.004;
    $a   = abs($x);
    $txt = '$' . number_format($a, fmod($a, 1.0) >= 0.005 ? 2 : 0, '.', ',');
    return $neg ? '−' . $txt : $txt;
}
function fecha_hora_mx(string $iso): string
{
    try {
        return $iso === '' ? '—' : (new DateTime($iso))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i');
    } catch (Throwable $ex) {
        return '—';
    }
}
function fecha_corta(?string $f): string
{
    return $f && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $f, $m) ? "$m[3]/$m[2]/$m[1]" : '—';
}
function clase_signo(float $x): string { return $x < -0.004 ? ' num--neg' : ''; }

/** Fecha AAAA-MM-DD válida o null. */
function fecha_valida(?string $f): ?string
{
    $f = trim((string)$f);
    if ($f === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f ? $f : null;
}

/** Monto positivo (≤ 999,999.99) o null. */
function monto_valido($v, bool $permitirCero = false): ?float
{
    $v = str_replace([',', '$', ' '], '', (string)$v);
    if (!is_numeric($v)) return null;
    $n = round((float)$v, 2);
    if ($n < 0 || (!$permitirCero && $n <= 0) || $n > 999999.99) return null;
    return $n;
}

/**
 * Contexto de cualquier página de Finanzas.
 * @return array{admin:array, datos:?array, S:array, ediciones:array, edicion:string, nombre_edicion:string, error:?string}
 */
function fin_contexto_pagina(): array
{
    $admin = requerir_finanzas();
    cabeceras_admin();
    $ctx = ['admin' => $admin, 'datos' => null, 'S' => [], 'ediciones' => [], 'edicion' => '', 'nombre_edicion' => '', 'error' => null];
    try {
        $d = fin_leer_estado();
        if ($d) {
            fin_sincronizar_huellas($d);
            $ctx['datos'] = $d;
            $ctx['S'] = $d['estado'];
            $ctx['ediciones'] = fin_ediciones($ctx['S']);
            $ids = array_column($ctx['ediciones'], 'id');
            $pedida = (string)($_GET['edicion'] ?? $_POST['edicion'] ?? '');
            $ctx['edicion'] = in_array($pedida, $ids, true) ? $pedida : ($ids[0] ?? '');
            foreach ($ctx['ediciones'] as $x) if ($x['id'] === $ctx['edicion']) $ctx['nombre_edicion'] = $x['nombre'];
        }
    } catch (Throwable $ex) {
        $ctx['error'] = error_interno($ex, 'finanzas');
    }
    return $ctx;
}
