<?php
/* =====================================================================
   Liga Premier Chapingo — Portal de capitanes · ESTADO DE CUENTA EN PDF

   El PDF se arma EN EL SERVIDOR (7-oct-2026) y se entrega como un PDF
   normal por api.php?a=estado_pdf, igual que el corte de caja: así abre
   en cualquier celular o computadora, sin depender del navegador.

   Mismo formato que el estado de cuenta de Tesorería: saldo al corte y
   cómo se forma, movimientos, lo que queda por pagar, pagos programados
   (aparte del saldo) y multas pendientes.

   Sólo recibe los datos que api.php YA preparó para el equipo de la
   sesión (con los tickets ocultos al capitán fuera). No lee ni escribe
   ningún archivo. Usa FPDF (carpeta fpdf/, licencia libre).
   ===================================================================== */

declare(strict_types=1);

/**
 * Saldo y movimientos de un equipo a la fecha de corte.
 * Los «Pago futuro» todavía sin cobrar van aparte: no son adeudo.
 * @param array $movs   lista 'movs' de api.php (cada uno con 'abonos')
 * @param array $multas lista 'multas' de api.php
 */
function datosCorteEquipo(array $movs, array $multas, string $corte): array
{
    $ev = []; $pend = []; $futuros = []; $cargos = 0.0; $pagos = 0.0;
    foreach ($movs as $m) {
        $fm = (string)($m['fecha'] ?? '');
        if ($fm > $corte) continue;
        $imp = (float)($m['importe'] ?? 0);
        $evM = [['f' => $fm, 'o' => 0, 'tipo' => 'cargo', 'm' => $m, 'monto' => $imp, 'forma' => '']];
        $pagado = 0.0;
        foreach ((array)($m['abonos'] ?? []) as $a) {
            $fa = (string)($a['fecha'] ?? '') ?: $fm;
            if ($fa > $corte) continue;
            $v = (float)($a['monto'] ?? 0);
            $pagado += $v;
            $evM[] = ['f' => $fa, 'o' => 1, 'tipo' => 'pago', 'm' => $m, 'monto' => $v, 'forma' => (string)($a['forma'] ?? '')];
        }
        if (($m['forma'] ?? '') === 'Pago futuro' && $imp - $pagado > 0.009) {
            $futuros[] = ['m' => $m, 'debe' => $imp - $pagado];
            continue;
        }
        foreach ($evM as $x) $ev[] = $x;
        if ($imp - $pagado > 0.009) $pend[] = ['m' => $m, 'debe' => $imp - $pagado, 'pagado' => $pagado];
    }
    usort($ev, static function ($x, $y) {
        return strcmp($x['f'], $y['f']) ?: ($x['o'] <=> $y['o']) ?: ((int)($x['m']['folio'] ?? 0) <=> (int)($y['m']['folio'] ?? 0));
    });
    foreach ($ev as $x) { if ($x['tipo'] === 'cargo') $cargos += $x['monto']; else $pagos += $x['monto']; }
    usort($futuros, static fn($a, $b) => strcmp((string)($a['m']['fecha'] ?? ''), (string)($b['m']['fecha'] ?? '')));
    $mul = array_values(array_filter($multas, static fn($mu) => (string)($mu['fecha'] ?? '') <= $corte && (float)($mu['saldo'] ?? 0) > 0.009));
    return ['corte' => $corte, 'cargos' => $cargos, 'pagos' => $pagos, 'saldo' => $cargos - $pagos,
            'periodo' => $ev, 'pend' => $pend, 'futuros' => $futuros, 'multas' => $mul];
}

function pdfFechaLarga(string $f): string
{
    $M = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $p = explode('-', $f);
    return count($p) === 3 ? (int)$p[2] . ' de ' . ($M[(int)$p[1] - 1] ?? '') . ' de ' . $p[0] : $f;
}
function pdfFecha(string $f): string
{
    $p = explode('-', substr($f, 0, 10));
    return count($p) === 3 ? $p[2] . '/' . $p[1] . '/' . $p[0] : $f;
}
function pdfMxn(float $n): string
{
    return ($n < -0.004 ? '-$' : '$') . number_format(abs($n), 2, '.', ',');
}
/** FPDF trabaja en Windows-1252: se convierten acentos y se descarta lo que no exista (emojis). */
function pdfTxt(string $s): string
{
    $s = trim((string)preg_replace('/[\x{2190}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1FAFF}]\s?/u', '', $s) ?: $s);   // emojis fuera
    $s = strtr($s, ["\u{2212}" => '-', "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"']);
    $r = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) : false;
    if ($r === false && function_exists('mb_convert_encoding')) $r = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    return $r === false ? preg_replace('/[^\x20-\x7E]/', '', $s) : $r;
}
function pdfConcepto(array $m): string
{
    return ((string)($m['concepto'] ?? '') ?: 'Cargo')
        . (!empty($m['jornada']) ? ' · Jornada ' . (int)$m['jornada'] : '')
        . ((string)($m['rival'] ?? '') !== '' ? ' vs ' . $m['rival'] : '');
}

/**
 * @param array $equipo  bloque 'equipo' de api.php
 * @param string $liga   nombre de la liga
 * @return string        el PDF, listo para enviarse
 */
function pdfEstadoCorte(array $equipo, string $liga, array $d): string
{
    require_once __DIR__ . '/fpdf/fpdf.php';

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(false);
    $pdf->SetTitle(pdfTxt('Estado de cuenta · ' . (string)($equipo['nombre'] ?? '')), false);
    $pdf->SetAuthor(pdfTxt($liga), false);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth(); $H = $pdf->GetPageHeight(); $X = 18.0; $ANCHO = $W - 2 * $X;
    $tinta = [24, 28, 26]; $gris = [104, 110, 104]; $suave = [150, 156, 148]; $linea = [214, 218, 210];
    $azul = [18, 59, 99]; $rojo = [170, 45, 40]; $azulFut = [29, 95, 168]; $rayaCab = [120, 126, 118];

    $color  = static function (array $c) use ($pdf) { $pdf->SetTextColor($c[0], $c[1], $c[2]); };
    $fuente = static function (string $peso, float $tam) use ($pdf) { $pdf->SetFont('Helvetica', $peso === 'bold' ? 'B' : '', $tam); };
    $txt = static function (string $t, float $x, float $y, string $al = 'L') use ($pdf) {
        $t = pdfTxt($t);
        if ($al === 'R') $x -= $pdf->GetStringWidth($t);
        $pdf->Text($x, $y, $t);
    };
    $raya = static function (float $x1, float $y, float $x2, array $c, float $g = 0.2) use ($pdf) {
        $pdf->SetDrawColor($c[0], $c[1], $c[2]); $pdf->SetLineWidth($g); $pdf->Line($x1, $y, $x2, $y);
    };
    /** Parte un texto en renglones que quepan en $ancho (con la fuente activa). */
    $partir = static function (string $t, float $ancho) use ($pdf): array {
        $out = []; $ren = '';
        foreach (preg_split('/\s+/u', trim($t)) ?: [] as $pal) {
            $p = $ren === '' ? $pal : $ren . ' ' . $pal;
            if ($ren !== '' && $pdf->GetStringWidth(pdfTxt($p)) > $ancho) { $out[] = $ren; $ren = $pal; }
            else $ren = $p;
        }
        if ($ren !== '') $out[] = $ren;
        return $out ?: [''];
    };

    $saldo = (float)$d['saldo']; $debe = $saldo > 0.009; $aFavor = $saldo < -0.009;
    $y = 20.0;

    /* ---------- encabezado ---------- */
    $pdf->SetFillColor($azul[0], $azul[1], $azul[2]); $pdf->Rect(0, 0, $W, 2.5, 'F');
    $fuente('bold', 8.5); $color($azul); $txt('ESTADO DE CUENTA', $X, $y);
    $fuente('normal', 8.5); $color($gris);
    $txt($liga . ((string)($equipo['edicion'] ?? '') !== '' ? ' · ' . $equipo['edicion'] : ''), $W - $X, $y, 'R');
    $y += 8; $fuente('bold', 18); $color($tinta); $txt((string)($equipo['nombre'] ?? ''), $X, $y);
    $y += 6; $fuente('normal', 9.5); $color($gris);
    $ruta = implode(' · ', array_filter([(string)($equipo['categoria'] ?? ''), (string)($equipo['disciplina'] ?? ''), (string)($equipo['rama'] ?? '')], 'strlen'));
    $txt($ruta . ((string)($equipo['encargado'] ?? '') !== '' ? '   |   Representante: ' . $equipo['encargado'] : ''), $X, $y);
    $y += 5; $txt('Corte de caja al ' . pdfFechaLarga((string)$d['corte']), $X, $y);
    $y += 6; $raya($X, $y, $W - $X, $linea, 0.3);

    /* ---------- saldo ---------- */
    $y += 11; $fuente('normal', 9); $color($gris);
    $txt($debe ? 'Saldo por pagar al corte' : ($aFavor ? 'Saldo a favor del equipo' : 'Saldo al corte'), $X, $y);
    $fuente('bold', 22); $color($debe ? $rojo : $azul); $txt(pdfMxn(abs($saldo)), $X, $y + 9);
    if (!$debe && !$aFavor) { $an = $pdf->GetStringWidth(pdfMxn(0)); $fuente('bold', 10); $txt('Al corriente', $X + $an + 4, $y + 9); }
    $fx = $W - $X; $fy = $y + 1.5;
    $fuente('normal', 9);
    foreach ([['Cargos', (float)$d['cargos']], ['Pagos', -(float)$d['pagos']]] as [$et, $v]) {
        $color($gris); $txt($et, $fx - 34, $fy, 'R');
        $color($tinta); $txt(($v < 0 ? '- ' : '') . pdfMxn(abs($v)), $fx, $fy, 'R');
        $fy += 5;
    }
    $raya($fx - 62, $fy - 3.2, $fx, $linea, 0.3);
    $fuente('bold', 9); $color($tinta); $txt('Saldo al corte', $fx - 34, $fy + 1, 'R'); $txt(pdfMxn($saldo), $fx, $fy + 1, 'R');
    $y += 26;

    /* ---------- tablas ---------- */
    $pie = static function () use ($pdf, $fuente, $color, $txt, $raya, $suave, $linea, $liga, $X, $W, $H) {
        $fuente('normal', 7.5); $color($suave);
        $raya($X, $H - 14, $W - $X, $linea, 0.2);
        $txt($liga . ' · Emitido el ' . date('d/m/Y') . ' · Aclaraciones con la administración', $X, $H - 9.5);
        // «{nb}» se sustituye al final por el total de páginas: se alinea con un número de muestra.
        $pdf->Text($W - $X - $pdf->GetStringWidth(pdfTxt('Página ' . $pdf->PageNo() . ' de 9')), $H - 9.5, pdfTxt('Página ' . $pdf->PageNo() . ' de {nb}'));
    };
    $pdf->AliasNbPages('{nb}');
    $LIM = $H - 22.0;
    $salto = static function (float $alto) use ($pdf, &$y, $LIM, $pie): bool {
        if ($y + $alto <= $LIM) return false;
        $pie(); $pdf->AddPage(); $y = 22.0;
        return true;
    };
    /**
     * @param array $cols  [[titulo, ancho|null (el resto), 'L'|'R'], …]
     * @param array $filas [[celda…] | ['_gris' => true, celda…]]; una celda puede ser [texto, color]
     * @param ?array $total [etiqueta, importe]
     */
    $tabla = static function (string $titulo, array $cols, array $filas, ?array $total = null, ?array $colorCab = null)
        use ($pdf, &$y, $fuente, $color, $txt, $raya, $partir, $salto, $tinta, $gris, $linea, $rayaCab, $X, $ANCHO) {
        $fijo = 0.0; foreach ($cols as $c) $fijo += (float)($c[1] ?? 0);
        $xs = []; $ws = []; $x = $X;
        foreach ($cols as $c) { $w = $c[1] ?? ($ANCHO - $fijo); $xs[] = $x; $ws[] = $w; $x += $w; }
        $cab = static function () use ($cols, $xs, $ws, $fuente, $color, $txt, $raya, &$y, $gris, $rayaCab, $colorCab, $X, $ANCHO) {
            $fuente('bold', 8); $color($colorCab ?? $gris);
            foreach ($cols as $k => $c) $txt($c[0], ($c[2] ?? 'L') === 'R' ? $xs[$k] + $ws[$k] - 1.5 : $xs[$k] + 1.5, $y + 4.6, $c[2] ?? 'L');
            $y += 6.6; $raya($X, $y, $X + $ANCHO, $colorCab ?? $rayaCab, 0.4);
        };
        $salto(26);
        $fuente('bold', 10.5); $color($tinta); $txt($titulo, $X, $y); $y += 3;
        $cab();
        foreach ($filas as $fila) {
            $esGris = !empty($fila['_gris']); unset($fila['_gris']);
            $fila = array_values($fila);
            $fuente('normal', 9);
            $rens = [];
            foreach ($fila as $k => $celda) {
                $t = is_array($celda) ? (string)$celda[0] : (string)$celda;
                $rens[$k] = ($cols[$k][2] ?? 'L') === 'R' ? [$t] : $partir($t, $ws[$k] - 3);
            }
            $n = max(array_map('count', $rens));
            $alto = 3.2 + $n * 3.9;
            if ($salto($alto + 2)) $cab();
            foreach ($fila as $k => $celda) {
                $fuente('normal', 9);
                $color(is_array($celda) ? $celda[1] : ($esGris ? $gris : $tinta));
                $al = $cols[$k][2] ?? 'L';
                foreach ($rens[$k] as $i => $t) $txt($t, $al === 'R' ? $xs[$k] + $ws[$k] - 1.5 : $xs[$k] + 1.5, $y + 4.9 + $i * 3.9, $al);
            }
            $y += $alto; $raya($X, $y, $X + $ANCHO, $linea, 0.2);
        }
        if ($total) {
            $salto(9);
            $raya($X, $y, $X + $ANCHO, $rayaCab, 0.4);
            $fuente('bold', 9); $color($tinta);
            $txt($total[0], $X + 1.5, $y + 4.9); $txt($total[1], $X + $ANCHO - 1.5, $y + 4.9, 'R');
            $y += 7.1;
        }
        $y += 10;
    };

    /* movimientos */
    $acum = 0.0; $filas = [];
    foreach ($d['periodo'] as $x) {
        $m = $x['m'];
        if ($x['tipo'] === 'cargo') {
            $acum += $x['monto'];
            $filas[] = [pdfFecha($x['f']), pdfConcepto($m), pdfMxn($x['monto']), '', pdfMxn($acum)];
        } else {
            $acum -= $x['monto'];
            $forma = $x['forma'] !== '' && $x['forma'] !== 'Crédito' && $x['forma'] !== 'Pago futuro' ? ' (' . mb_strtolower($x['forma']) . ')' : '';
            $filas[] = [pdfFecha($x['f']), ['Pago de ' . mb_strtolower((string)($m['concepto'] ?? '') ?: 'cargo') . $forma, $gris], '', pdfMxn($x['monto']), pdfMxn($acum)];
        }
    }
    if (!$filas) $filas[] = ['_gris' => true, '', 'Sin movimientos a esta fecha.', '', '', ''];
    $tabla('Movimientos al corte', [['Fecha', 22, 'L'], ['Concepto', null, 'L'], ['Cargo', 25, 'R'], ['Pago', 25, 'R'], ['Saldo', 26, 'R']], $filas);

    if ($d['pend'] && $debe) {
        $tp = 0.0; $filas = [];
        foreach ($d['pend'] as $x) {
            $tp += $x['debe'];
            $filas[] = [pdfFecha((string)($x['m']['fecha'] ?? '')), pdfConcepto($x['m']) . ($x['pagado'] > 0.009 ? ' (abonado ' . pdfMxn($x['pagado']) . ')' : ''), pdfMxn($x['debe'])];
        }
        $tabla('Por pagar', [['Fecha', 22, 'L'], ['Concepto', null, 'L'], ['Pendiente', 30, 'R']], $filas, ['Total por pagar', pdfMxn($tp)]);
    }
    if ($d['futuros']) {
        $tf = 0.0; $filas = [];
        foreach ($d['futuros'] as $x) {
            $tf += $x['debe'];
            $filas[] = [pdfFecha((string)($x['m']['fecha'] ?? '')), pdfConcepto($x['m']), [pdfMxn($x['debe']), $azulFut]];
        }
        $tabla('Pagos programados (no forman parte del saldo)', [['Fecha', 22, 'L'], ['Concepto', null, 'L'], ['Programado', 30, 'R']], $filas, ['Total programado', pdfMxn($tf)], $azulFut);
    }
    if ($d['multas']) {
        $tm = 0.0; $filas = [];
        foreach ($d['multas'] as $mu) {
            $tm += (float)($mu['saldo'] ?? 0);
            $filas[] = [(string)($mu['jugador'] ?? ''), (string)($mu['concepto'] ?? ''), pdfMxn((float)($mu['saldo'] ?? 0))];
        }
        $tabla('Multas pendientes de jugadores', [['Jugador', 80, 'L'], ['Concepto', null, 'L'], ['Pendiente', 30, 'R']], $filas, ['Total de multas (aparte del saldo del equipo)', pdfMxn($tm)]);
    }

    /* nota final */
    $salto(14);
    $fuente('normal', 9); $color($gris);
    $nota = $debe ? 'Le pedimos cubrir el saldo pendiente antes de su próximo partido. Si ya realizó el pago, no considere este aviso.'
          : ($aFavor ? 'El equipo tiene saldo a favor, que se aplicará a sus próximos cargos.'
                     : 'El equipo está al corriente. Gracias por su puntualidad.');
    foreach ($partir($nota, $ANCHO) as $i => $t) $txt($t, $X, $y + $i * 4.2);
    $pie();

    return $pdf->Output('S');
}
