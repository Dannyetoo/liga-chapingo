<?php
/**
 * Casos de prueba de los cálculos de Finanzas (sin base de datos).
 * Uso en consola:  php tests/finanzas-casos.php
 * (La carpeta tests/ está bloqueada para el navegador por su .htaccess.)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/finanzas-calculos.php';

$ok = 0; $mal = 0;
function caso(string $n, bool $c): void { global $ok, $mal; $c ? $ok++ : $mal++; echo ($c ? 'OK    ' : 'FALLA ') . $n . "\n"; }
function casi(float $a, float $b): bool { return abs($a - $b) < 0.005; }

$S = [
    'ediciones'   => [['id' => 'ed1', 'nombre' => 'Edición A']],
    'disciplinas' => [['id' => 'di1', 'nombre' => 'Fútbol Rápido']],
    'ramas'       => [['id' => 'ra1', 'nombre' => 'Varonil']],
    'categorias'  => [['id' => 'caP', 'nombre' => 'Primera'], ['id' => 'caR', 'nombre' => 'Relámpago']],
    'cuentas'     => [['id' => 'cu1', 'nombre' => 'Ana'], ['id' => 'cu2', 'nombre' => 'Beto']],
    'conceptos'   => [
        ['id' => 'c1', 'nombre' => 'Inscripción a Liga', 'monto' => 650],
        ['id' => 'c2', 'nombre' => 'Inscripción a Relámpago', 'monto' => 350],
        ['id' => 'c3', 'nombre' => 'Arbitraje', 'monto' => 150],
    ],
    'conceptosGasto' => [
        ['cat' => 'Partido', 'nombre' => 'Arbitraje', 'monto' => 130],
        ['cat' => 'Partido', 'nombre' => 'Supervisión', 'monto' => 50],
        ['cat' => 'Partido', 'nombre' => 'Coordinación', 'monto' => 50],
    ],
    'equipos' => [
        ['id' => 'A', 'nombre' => 'A', 'edicionId' => 'ed1', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caP'],
        ['id' => 'B', 'nombre' => 'B', 'edicionId' => 'ed1', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caP'],
        ['id' => 'C', 'nombre' => 'C', 'edicionId' => 'ed1', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caP'],
        ['id' => 'R1', 'nombre' => 'R1', 'edicionId' => 'ed1', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caR'],
        ['id' => 'R2', 'nombre' => 'R2', 'edicionId' => 'ed1', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caR'],
    ],
    'movs' => [
        ['id' => 'm1', 'equipoId' => 'A', 'conceptoId' => 'c1', 'concepto' => 'Inscripción a Liga', 'importe' => 650, 'recibido' => 650],
        ['id' => 'm2', 'equipoId' => 'B', 'conceptoId' => 'c1', 'concepto' => 'Inscripción a Liga', 'importe' => 650, 'recibido' => 300],
        ['id' => 'm3', 'equipoId' => 'A', 'conceptoId' => 'c3', 'concepto' => 'Arbitraje', 'importe' => 150, 'recibido' => 150, 'jornada' => 1, 'rivalId' => 'B'],
        ['id' => 'm4', 'equipoId' => 'B', 'conceptoId' => 'c3', 'concepto' => 'Arbitraje', 'importe' => 150, 'recibido' => 100, 'jornada' => 1, 'rivalId' => 'A'],
        ['id' => 'm5', 'equipoId' => 'R1', 'conceptoId' => 'c2', 'concepto' => 'Inscripción a Relámpago', 'importe' => 350, 'recibido' => 400],   // pagó de más
        ['id' => 'm6', 'equipoId' => 'C', 'conceptoId' => 'x', 'concepto' => 'Multa de reincorporación', 'importe' => 100, 'recibido' => 100],
        ['id' => 'm7', 'equipoId' => 'ZZ', 'conceptoId' => 'c3', 'concepto' => 'Arbitraje', 'importe' => 150, 'recibido' => 150],               // equipo borrado
    ],
    'gastos' => [
        ['id' => 'g1', 'edicionId' => 'ed1', 'categoriaGasto' => 'Partido', 'concepto' => 'Arbitraje', 'monto' => 130, 'jornada' => 1,
         'equipoAId' => 'B', 'equipoBId' => 'A', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caP'],
        ['id' => 'g2', 'edicionId' => 'ed1', 'categoriaGasto' => 'Partido', 'concepto' => 'Supervisión', 'monto' => 50, 'jornada' => 1,
         'equipoAId' => 'A', 'equipoBId' => 'B', 'disciplinaId' => 'di1', 'ramaId' => 'ra1', 'categoriaId' => 'caP'],
        ['id' => 'g3', 'edicionId' => 'ed1', 'categoria' => 'Administrativo', 'concepto' => 'Lona', 'monto' => 400],
        ['id' => 'g4', 'edicionId' => 'ed1', 'categoria' => 'Otro', 'concepto' => 'Transferencia', 'monto' => 999, 'pagador' => 'Ana', 'beneficiario' => 'Beto'],
        ['id' => 'g5', 'edicionId' => 'ed1', 'categoria' => 'Arbitraje', 'concepto' => '2 partidos', 'monto' => 260],
        ['id' => 'g6', 'edicionId' => 'otra', 'categoria' => 'Administrativo', 'concepto' => 'No es de esta edición', 'monto' => 10000],
    ],
    'multas' => [['id' => 'mu1', 'equipoId' => 'C', 'monto' => 80, 'pagado' => 20]],
];

$R = fin_resumen($S, 'ed1');
$T = $R['tarjetas'];
$cat = []; foreach ($R['categorias'] as $c) $cat[$c['nombre']] = $c;

caso('recibido = 650+300+150+100+400+100 = 1,700 (sin equipo borrado)', casi($T['recibido'], 1700));
caso('inscripciones cobradas = 1,350', casi($T['inscripciones'], 1350));
caso('arbitrajes cobrados = 250', casi($T['arbitrajes'], 250));
caso('otros ingresos (multa cobrada) = 100', casi($T['otros_ingresos'], 100));
caso('gastos operativos = 130 + 50 + 260 = 440', casi($T['gastos_operativos'], 440));
caso('otros gastos = 400 (transferencia y otra edición fuera)', casi($T['otros_gastos'], 400));
caso('saldo real = 1,700 − 840 = 860', casi($T['saldo_real'], 860));
caso('pendiente = 350 + 50 (el pago de más no resta)', casi($T['pendiente'], 400));
caso('transferencia interna detectada', count($R['revisar']['transferencias']) === 1);
caso('cobro de equipo inexistente contado aparte', $R['revisar']['cobros_sin_equipo'] === 1);
caso('multa pendiente informativa = 60', casi($R['revisar']['multas_pendientes'], 60));
caso('gastos sin categoría → General (400 + 260)', casi($cat['General de la liga']['gastos'], 660));
caso('Primera: 1 partido registrado (A vs B, J1)', $cat['Primera']['partidos_registrados'] === 1);
caso('Primera: 3 partidos por todos contra todos, quedan 2', $cat['Primera']['proy']['partidos_total'] === 3 && $cat['Primera']['proy']['partidos_restantes'] === 2);
caso('Primera: inscripción futura = 1 equipo sin cargo × 650', casi($cat['Primera']['proy']['inscripciones_futuras'], 650));
caso('Primera: arbitrajes futuros = 2 × 2 × 150 = 600', casi($cat['Primera']['proy']['arbitrajes_futuros'], 600));
caso('Primera: gasto op futuro = 2 × 230 = 460', casi($cat['Primera']['proy']['gasto_operativo_futuro'], 460));
caso('Relámpago: eliminación directa, 2 equipos = 1 partido', $cat['Relámpago']['proy']['partidos_total'] === 1);
caso('Relámpago: cuota de relámpago (350) para R2', casi($cat['Relámpago']['proy']['inscripciones_futuras'], 350));
$suma = 0.0; foreach ($R['categorias'] as $c) $suma += $c['utilidad'];
caso('consolidado = suma de categorías', casi($suma, $T['saldo_real']));
$p = fin_partidos($S, 'ed1');
$pk = array_values(array_filter($p, fn($x) => $x['b'] !== ''))[0] ?? null;
caso('partido A-B: ingreso 250, gasto 180, utilidad 70', $pk && casi($pk['ingreso'], 250) && casi($pk['gasto'], 180) && casi($pk['ingreso'] - $pk['gasto'], 70));

$R2 = fin_resumen($S, 'ed1', ['caP|x|y' => [], 'di1|ra1|caP' => ['partidos_regulares' => 10, 'arbitraje_equipo_regular' => 200]]);
foreach ($R2['categorias'] as $c) if ($c['nombre'] === 'Primera') $cp = $c;
caso('parámetro manda sobre el estimado (10 partidos, $200)', $cp['proy']['partidos_total'] === 10 && casi($cp['proy']['arbitrajes_futuros'], 9 * 2 * 200));
caso('proyectado nunca cambia el real', casi($R2['tarjetas']['saldo_real'], 860));

echo "\n$ok correctos, $mal con falla\n";
exit($mal ? 1 : 0);
