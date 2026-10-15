<?php
/**
 * Casos de prueba del motor de coincidencias (A–E + extras).
 * Uso (sólo consola):  php tests/casos.php
 * No usa la base de datos: trabaja con datos en memoria, así que no modifica nada.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/coincidencias.php';

$ok = 0; $fallas = 0;
function verificar(string $nombre, $obtenido, $esperado): void
{
    global $ok, $fallas;
    if ($obtenido === $esperado) { $ok++; echo "  ✔ $nombre\n"; }
    else { $fallas++; echo "  ✘ $nombre\n     esperado: " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n     obtenido: " . json_encode($obtenido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function h(string $i, string $f): array { return ['hora_inicio' => $i, 'hora_fin' => $f]; }
/** Fuente en memoria: recurrente por día + excepciones por fecha. */
function fuente(string $nombre, array $recurrente, array $excepciones, array $fechas, string $tipo = 'equipo'): array
{
    $pf = [];
    foreach ($fechas as $f) $pf[$f] = calcularDisponibilidadEfectiva($recurrente, $excepciones[$f] ?? [], $f);
    return ['tipo' => $tipo, 'id' => crc32($nombre), 'nombre' => $nombre, 'por_fecha' => $pf];
}
function comun(array $r, string $f): string { return intervalos_texto($r['fechas'][$f]['comun']); }
function slots(array $r, string $f): string { return intervalos_texto($r['fechas'][$f]['slots']); }

$LUN = '2026-09-28'; // lunes
$MIE = '2026-10-14'; // miércoles
$DOM = '2026-10-18'; // domingo

echo "CASO A — Lunes 16:00-20:00 ∩ Lunes 18:00-21:00\n";
$A = fuente('PSG', [1 => [h('16:00', '20:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('18:00', '21:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B], [$LUN], 60, 30);
verificar('coincidencia 18:00–20:00', comun($r, $LUN), '18:00–20:00');

echo "CASO B — (13-15, 18-21) ∩ (14-19)\n";
$A = fuente('PSG', [1 => [h('13:00', '15:00'), h('18:00', '21:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('14:00', '19:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B], [$LUN], 60, 30);
verificar('coincidencias 14:00–15:00 y 18:00–19:00', comun($r, $LUN), '14:00–15:00, 18:00–19:00');

echo "CASO C — coinciden, pero A tiene excepción no_disponible ese día\n";
$A = fuente('PSG', [3 => [h('16:00', '21:00')]], [$MIE => [['fecha' => $MIE, 'tipo' => 'no_disponible', 'hora_inicio' => null, 'hora_fin' => null, 'motivo' => 'Examen']]], [$MIE]);
$B = fuente('Monkey CFC', [3 => [h('17:00', '21:00')]], [], [$MIE]);
$r = calcularCoincidencias([$A, $B], [$MIE], 60, 30);
verificar('disponibilidad efectiva de A = []', $A['por_fecha'][$MIE]['bloques'], []);
verificar('ningún horario', $r['fechas'][$MIE]['slots'], []);
verificar('estado sin_coincidencia', $r['fechas'][$MIE]['estado'], 'sin_coincidencia');
verificar('explicación menciona la excepción', $r['fechas'][$MIE]['motivo'], 'PSG no está disponible por excepción registrada.');

echo "CASO D — A normalmente no disponible + extra 10:00-14:00; B 09:00-12:00\n";
$A = fuente('PSG', [], [$DOM => [['fecha' => $DOM, 'tipo' => 'disponibilidad_extra', 'hora_inicio' => '10:00:00', 'hora_fin' => '14:00:00', 'motivo' => null]]], [$DOM]);
$B = fuente('Monkey CFC', [7 => [h('09:00', '12:00')]], [], [$DOM]);
$r = calcularCoincidencias([$A, $B], [$DOM], 60, 30);
verificar('disponibilidad efectiva de A = 10:00–14:00', intervalos_texto($A['por_fecha'][$DOM]['bloques']), '10:00–14:00');
verificar('coincidencia 10:00–12:00', comun($r, $DOM), '10:00–12:00');

echo "CASO E — coincidencia 18:00-20:00, partido 60, intervalo 30\n";
$A = fuente('PSG', [1 => [h('18:00', '20:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('17:00', '22:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B], [$LUN], 60, 30);
verificar('slots 18:00–19:00, 18:30–19:30, 19:00–20:00', slots($r, $LUN), '18:00–19:00, 18:30–19:30, 19:00–20:00');

echo "EXTRAS\n";
verificar('18:00–21:00 / 60 / 30 → 5 slots (ejemplo del requerimiento)',
    intervalos_texto(intervalos_a_slots([[1080, 1260]], 60, 30)),
    '18:00–19:00, 18:30–19:30, 19:00–20:00, 19:30–20:30, 20:00–21:00');
verificar('nunca un slot que termine fuera: 18:00–19:30 / 90 / 30 → sólo 18:00',
    intervalos_texto(intervalos_a_slots([[1080, 1170]], 90, 30)), '18:00–19:30');
verificar('coincidencia de 30 min con partido de 60 → sin slots', intervalos_a_slots([[1080, 1110]], 60, 30), []);
verificar('inicio alineado a la rejilla: 18:10–20:00 / 60 / 30 → 18:30 y 19:00',
    intervalos_texto(intervalos_a_slots([[1090, 1200]], 60, 30)), '18:30–19:30, 19:00–20:00');
verificar('no_disponible parcial 18:00–19:00 sobre 16:00–21:00',
    intervalos_texto(calcularDisponibilidadEfectiva([3 => [h('16:00', '21:00')]], [['fecha' => $MIE, 'tipo' => 'no_disponible', 'hora_inicio' => '18:00', 'hora_fin' => '19:00']], $MIE)['bloques']),
    '16:00–18:00, 19:00–21:00');
verificar('no_disponible todo el día gana sobre un extra el mismo día',
    calcularDisponibilidadEfectiva([], [['fecha' => $DOM, 'tipo' => 'disponibilidad_extra', 'hora_inicio' => '10:00', 'hora_fin' => '14:00'], ['fecha' => $DOM, 'tipo' => 'no_disponible']], $DOM)['bloques'], []);
verificar('extra que se encima con la base se une (16–20 ∪ 19–22 = 16–22)',
    intervalos_texto(calcularDisponibilidadEfectiva([1 => [h('16:00', '20:00')]], [['fecha' => $LUN, 'tipo' => 'disponibilidad_extra', 'hora_inicio' => '19:00', 'hora_fin' => '22:00']], $LUN)['bloques']),
    '16:00–22:00');
verificar('tres fuentes (A ∩ B ∩ cancha futura)',
    intervalos_texto(intervalos_intersectar_varios([[[960, 1200]], [[1080, 1260]], [[1020, 1140]]])), '18:00–19:00');
verificar('restar horarios ocupados (futuro): 18–21 − 19–20',
    intervalos_texto(intervalos_restar([[1080, 1260]], [[1140, 1200]])), '18:00–19:00, 20:00–21:00');
$A = fuente('PSG', [1 => [h('16:00', '18:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('19:00', '21:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B], [$LUN], 60, 30);
verificar('ambos disponibles sin cruce → explicación', $r['fechas'][$LUN]['motivo'], 'Todos tienen disponibilidad, pero sus horarios no se cruzan.');

echo "\nFASE 4 · CANCHAS\n";
$MIE30 = '2026-09-30'; // miércoles
function cancha(string $n, array $rec, array $exc, array $fechas): array { return fuente($n, $rec, $exc, $fechas, 'cancha'); }

echo "CASO 1 — A 16-20 ∩ B 18-21 ∩ Cancha 17-22\n";
$A = fuente('PSG', [1 => [h('16:00', '20:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('18:00', '21:00')]], [], [$LUN]);
$C = cancha('DICIFO', [1 => [h('17:00', '22:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B, $C], [$LUN], 60, 30);
verificar('coincidencia 18:00–20:00', comun($r, $LUN), '18:00–20:00');

echo "CASO 2 — A 16-21 ∩ B 18-22 ∩ Cancha 08-17\n";
$A = fuente('PSG', [1 => [h('16:00', '21:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('18:00', '22:00')]], [], [$LUN]);
$C = cancha('DICIFO', [1 => [h('08:00', '17:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B, $C], [$LUN], 60, 30);
verificar('sin coincidencia', $r['fechas'][$LUN]['comun'], []);
verificar('estado sin_coincidencia', $r['fechas'][$LUN]['estado'], 'sin_coincidencia');
verificar('los equipos sí coinciden 18:00–21:00', intervalos_texto($r['fechas'][$LUN]['comun_equipos']), '18:00–21:00');
verificar('explicación: coinciden los equipos, no la cancha', $r['fechas'][$LUN]['motivo'],
    'Los equipos coinciden entre 18:00 y 21:00, pero DICIFO no está disponible en ese horario (DICIFO: 08:00–17:00).');

echo "CASO 3 — equipos 18-21, cancha 17-22 con excepción no_disponible ese día\n";
$A = fuente('PSG', [3 => [h('18:00', '21:00')]], [], [$MIE30]);
$B = fuente('Monkey CFC', [3 => [h('18:00', '21:00')]], [], [$MIE30]);
$C = cancha('DICIFO', [3 => [h('17:00', '22:00')]], [$MIE30 => [['fecha' => $MIE30, 'tipo' => 'no_disponible', 'hora_inicio' => null, 'hora_fin' => null, 'motivo' => 'Mantenimiento']]], [$MIE30]);
$r = calcularCoincidencias([$A, $B, $C], [$MIE30], 60, 30);
verificar('efectiva de la cancha = []', $C['por_fecha'][$MIE30]['bloques'], []);
verificar('sin coincidencia', $r['fechas'][$MIE30]['slots'], []);
verificar('explicación menciona la excepción de la cancha', $r['fechas'][$MIE30]['motivo'],
    'Los equipos coinciden entre 18:00 y 21:00, pero DICIFO no está disponible ese día por excepción registrada.');

echo "CASO 4 — cancha sin base + extra 18-20; equipos 17-21\n";
$A = fuente('PSG', [7 => [h('17:00', '21:00')]], [], [$DOM]);
$B = fuente('Monkey CFC', [7 => [h('17:00', '21:00')]], [], [$DOM]);
$C = cancha('DICIFO', [], [$DOM => [['fecha' => $DOM, 'tipo' => 'disponibilidad_extra', 'hora_inicio' => '18:00', 'hora_fin' => '20:00']]], [$DOM]);
$r = calcularCoincidencias([$A, $B, $C], [$DOM], 60, 30);
verificar('coincidencia 18:00–20:00', comun($r, $DOM), '18:00–20:00');

echo "CASO 5 — coincidencia final 18-20, 60 min, cada 30\n";
verificar('slots 18:00–19:00, 18:30–19:30, 19:00–20:00', slots($r, $DOM), '18:00–19:00, 18:30–19:30, 19:00–20:00');

echo "EXTRAS FASE 4\n";
$A = fuente('PSG', [3 => [h('16:00', '21:00')]], [], [$MIE30]);
$B = fuente('Monkey CFC', [3 => [h('18:00', '22:00')]], [], [$MIE30]);
$C = cancha('DICIFO', [3 => [h('17:00', '20:00')]], [], [$MIE30]);
$r = calcularCoincidencias([$A, $B, $C], [$MIE30], 60, 30);
verificar('ejemplo del requerimiento: 16-21 ∩ 18-22 ∩ 17-20 = 18:00–20:00', comun($r, $MIE30), '18:00–20:00');
verificar('DICIFO miércoles 16-21 + excepción 30/09 → []',
    calcularDisponibilidadEfectiva([3 => [h('16:00', '21:00')]], [['fecha' => $MIE30, 'tipo' => 'no_disponible']], $MIE30)['bloques'], []);
$r = calcularCoincidencias([$A, $B, $C], [$MIE30], 60, 30, [$MIE30 => [[1080, 1140]]]);
verificar('preparado para ocupación: restar partido 18:00–19:00 deja 19:00–20:00', slots($r, $MIE30), '19:00–20:00');
$A = fuente('PSG', [1 => [h('16:00', '17:00')]], [], [$LUN]);
$B = fuente('Monkey CFC', [1 => [h('19:00', '21:00')]], [], [$LUN]);
$C = cancha('DICIFO', [1 => [h('08:00', '22:00')]], [], [$LUN]);
$r = calcularCoincidencias([$A, $B, $C], [$LUN], 60, 30);
verificar('con cancha, equipos sin cruce → culpa a los equipos', $r['fechas'][$LUN]['motivo'], 'Los equipos tienen disponibilidad, pero sus horarios no se cruzan.');

echo "\nResultado: $ok correctos, $fallas fallidos\n";
exit($fallas ? 1 : 0);
