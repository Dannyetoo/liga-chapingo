<?php
/**
 * Liga Chapingo — Finanzas · ACCIONES (sólo POST + CSRF + permiso de finanzas).
 * Tickets, partidos y costos viven en MySQL. estado.json (Tesorería) sólo se LEE para
 * validar equipos y categorías.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/finanzas-pagina.php';

$admin = requerir_finanzas();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

/** Regresa a una página del módulo (lista blanca) conservando su consulta. */
function regresar(): void
{
    $v = (string)($_POST['volver'] ?? '');
    if (!preg_match('#^(tickets|partidos|partido|gastos|configuracion)\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $v)) $v = 'tickets.php';
    header('Location: ' . $v, true, 303);
    exit;
}
function ids_post(string $campo = 'ids'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$campo] ?? [])), fn($i) => $i > 0)));
    return array_slice($ids, 0, 5000);
}

if (!csrf_valido($_POST['csrf_token'] ?? null)) {
    flash('error', 'La página expiró. Recarga e inténtalo de nuevo.');
    regresar();
}
$accion  = (string)($_POST['accion'] ?? '');
$accionesGastos = ['gasto_guardar', 'gastos_estado', 'categoria_gasto'];
if (in_array($accion, $accionesGastos, true) ? !fg_instalado() : ($accion !== 'config_guardar' && !fp_instalado())) {
    flash('error', 'Falta ejecutar el SQL de esta sección en la base de datos.');
    regresar();
}

$usuario = $admin['usuario'];
$datos   = fin_leer_estado();
$edicion = (string)($_POST['edicion'] ?? '');
$cat     = null;
if ($datos) {
    $ids = array_column(fin_ediciones($datos['estado']), 'id');
    if (in_array($edicion, $ids, true)) $cat = fp_catalogo($datos['estado'], $edicion);
}
$necesitaCatalogo = ['generar_rol', 'crear_partido', 'asignar_rival', 'gasto_guardar', 'config_guardar'];
if (in_array($accion, $necesitaCatalogo, true) && !$cat) {
    flash('error', 'No se pudieron leer los equipos de Tesorería para esa edición.');
    regresar();
}

try {
    switch ($accion) {

    /* ---------- Rol todos contra todos ---------- */
    case 'generar_rol': {
        $k = (string)($_POST['categoria'] ?? '');
        $c = $cat['categorias'][$k] ?? null;
        if (!$c) { flash('error', 'Elige una categoría.'); regresar(); }
        if ($c['relampago']) { flash('error', 'Relámpago es eliminación directa: crea cada partido con «Crear partido».'); regresar(); }
        $sel = array_values(array_intersect(array_map('strval', (array)($_POST['equipos'] ?? [])), array_keys($c['equipos'])));
        if (count($sel) < 2) { flash('error', 'Selecciona al menos 2 equipos de la categoría.'); regresar(); }
        $importe = monto_valido($_POST['importe'] ?? '');
        $concepto = trim((string)($_POST['concepto'] ?? ''));
        $fecha = fecha_valida($_POST['fecha'] ?? '');
        $intervalo = (int)($_POST['intervalo'] ?? 7);
        $j0 = max(1, min(400, (int)($_POST['jornada_inicial'] ?? 1)));
        $err = [];
        if ($importe === null) $err[] = 'El importe por equipo debe ser mayor a 0.';
        if ($concepto === '' || mb_strlen($concepto) > 150) $err[] = 'Escribe el concepto (máx. 150).';
        if (trim((string)($_POST['fecha'] ?? '')) !== '' && !$fecha) $err[] = 'Fecha no válida.';
        if ($intervalo < 0 || $intervalo > 60) $err[] = 'Días entre jornadas: de 0 a 60.';
        if ($err) { flash('error', $err); regresar(); }

        // Cruces ya existentes en la categoría (en cualquier jornada): no se repiten.
        $st = db()->prepare("SELECT par_clave FROM fin_partidos WHERE edicion_ref = ? AND categoria_ref = ? AND estado <> 'cancelado'");
        $st->execute([$edicion, $k]);
        $ya = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        $rol = fp_round_robin($sel);
        $pdo = db();
        $pdo->beginTransaction();
        $creados = 0; $omitidos = 0;
        foreach ($rol as [$j, $a, $b]) {
            if (isset($ya[fp_par_clave($a, $b)])) { $omitidos++; continue; }
            $jor = $j0 + $j - 1;
            $f = $fecha ? (new DateTime($fecha))->modify('+' . (($j - 1) * $intervalo) . ' days')->format('Y-m-d') : null;
            $r = fp_crear_partido([
                'edicion' => $edicion, 'cat' => $k, 'tipo' => 'liga', 'jornada' => $jor, 'ronda' => null,
                'a' => $a, 'a_nombre' => $c['equipos'][$a], 'b' => $b, 'b_nombre' => $c['equipos'][$b],
                'fecha' => $f, 'concepto' => $concepto, 'importe' => $importe, 'nota' => null,
                'visible' => !empty($_POST['visible']),
            ], $usuario);
            $r['estado'] === 'creado' ? $creados++ : $omitidos++;
        }
        $pdo->commit();
        fin_bitacora_registrar($usuario, 'generar_rol', 'categoria', $k, null, ['partidos' => $creados, 'omitidos' => $omitidos]);
        flash('ok', "Rol generado: $creados partidos (" . ($creados * 2) . ' tickets)' . ($omitidos ? ", $omitidos cruces ya existían" : '') . '.');
        regresar();
    }

    /* ---------- Un partido (eliminatoria o suelto) ---------- */
    case 'crear_partido': {
        $a = (string)($_POST['equipo_a'] ?? ''); $b = (string)($_POST['equipo_b'] ?? '');
        $A = $cat['equipos'][$a] ?? null; $B = $cat['equipos'][$b] ?? null;
        $jor = (int)($_POST['jornada'] ?? 0);
        $importe = monto_valido($_POST['importe'] ?? '');
        $concepto = trim((string)($_POST['concepto'] ?? ''));
        $ronda = trim((string)($_POST['ronda'] ?? ''));
        $fecha = fecha_valida($_POST['fecha'] ?? '');
        $err = [];
        if (!$A || !$B) $err[] = 'Elige los dos equipos.';
        elseif ($a === $b) $err[] = 'Un equipo no puede jugar contra sí mismo.';
        elseif ($A['cat'] !== $B['cat']) $err[] = 'Los dos equipos deben ser de la misma categoría.';
        if ($jor < 1 || $jor > 400) $err[] = 'La jornada (o ronda) debe ser un número de 1 a 400.';
        if ($importe === null) $err[] = 'El importe por equipo debe ser mayor a 0.';
        if ($concepto === '' || mb_strlen($concepto) > 150) $err[] = 'Escribe el concepto (máx. 150).';
        if (mb_strlen($ronda) > 40) $err[] = 'El nombre de la ronda admite 40 caracteres.';
        if (trim((string)($_POST['fecha'] ?? '')) !== '' && !$fecha) $err[] = 'Fecha no válida.';
        if ($err) { flash('error', $err); regresar(); }
        $rel = $cat['categorias'][$A['cat']]['relampago'];
        $tipoPedido = (string)($_POST['tipo'] ?? '');
        $pdo = db();
        $pdo->beginTransaction();
        $r = fp_crear_partido([
            'edicion' => $edicion, 'cat' => $A['cat'], 'tipo' => ($rel || $tipoPedido === 'eliminatoria') ? 'eliminatoria' : 'liga', 'jornada' => $jor,
            'ronda' => $ronda, 'a' => $a, 'a_nombre' => $A['nombre'], 'b' => $b, 'b_nombre' => $B['nombre'],
            'fecha' => $fecha, 'concepto' => $concepto, 'importe' => $importe, 'nota' => null,
            'visible' => !empty($_POST['visible']),
        ], $usuario);
        $pdo->commit();
        if ($r['estado'] === 'existe') flash('error', 'Ese cruce ya existe en esa jornada. No se creó nada.');
        else {
            fin_bitacora_registrar($usuario, 'crear_partido', 'partido', (string)$r['id']);
            flash('ok', 'Partido creado con sus 2 tickets: ' . $A['nombre'] . ' vs ' . $B['nombre'] . '.');
        }
        regresar();
    }

    /* ---------- Asignar rival a un ticket viejo ---------- */
    case 'asignar_rival': {
        $jor = trim((string)($_POST['jornada'] ?? ''));
        $jorN = $jor === '' ? null : (int)$jor;
        if ($jorN !== null && ($jorN < 1 || $jorN > 400)) { flash('error', 'Jornada no válida.'); regresar(); }
        $e = fp_asignar_rival((int)($_POST['ticket_id'] ?? 0), (string)($_POST['rival'] ?? ''), $jorN,
                              !empty($_POST['contraparte']), $cat, $edicion, $usuario);
        $e ? flash('error', $e) : flash('ok', 'Rival asignado y partido identificado.');
        regresar();
    }

    /* ---------- Estados y visibilidad de tickets ---------- */
    case 'tickets_estado': {
        $ids = ids_post();
        $estado = (string)($_POST['estado'] ?? '');
        if (!$ids) { flash('error', 'No seleccionaste tickets.'); regresar(); }
        if (!isset(FP_ESTADOS_TICKET[$estado])) { flash('error', 'Estado no válido.'); regresar(); }
        $n = fp_cambiar_estado_tickets($ids, $estado, fecha_valida($_POST['fecha_cobro'] ?? ''), $usuario);
        flash('ok', "$n tickets → " . FP_ESTADOS_TICKET[$estado] . '.' . ($n < count($ids) ? ' Los cancelados no cambian.' : ''));
        regresar();
    }
    case 'tickets_visible':
    case 'tickets_ocultar': {
        $ids = ids_post();
        if (!$ids) { flash('error', 'No seleccionaste tickets.'); regresar(); }
        $n = fp_visibilidad_tickets($ids, $accion === 'tickets_visible', $usuario);
        flash('ok', $accion === 'tickets_visible' ? "$n tickets visibles para los capitanes." : "$n tickets ocultos para los capitanes.");
        regresar();
    }

    /* ---------- Editar un ticket ---------- */
    case 'editar_ticket': {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM tickets_futuros WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) { flash('error', 'Ese ticket ya no existe.'); regresar(); }
        $importe = monto_valido($_POST['importe'] ?? '');
        $concepto = trim((string)($_POST['concepto'] ?? ''));
        $nota = trim((string)($_POST['nota'] ?? ''));
        $folio = trim((string)($_POST['folio'] ?? ''));
        $estado = (string)($_POST['estado'] ?? $t['estado']);
        $fc = fecha_valida($_POST['fecha_cobro'] ?? '');
        $err = [];
        if ($importe === null) $err[] = 'El importe debe ser mayor a 0.';
        if ($concepto === '' || mb_strlen($concepto) > 150) $err[] = 'Escribe el concepto (máx. 150).';
        if (mb_strlen($nota) > 255) $err[] = 'La nota admite 255 caracteres.';
        if (mb_strlen($folio) > 30) $err[] = 'El folio admite 30 caracteres.';
        if (!isset(FP_ESTADOS_TICKET[$estado])) $err[] = 'Estado no válido.';
        if ($t['estado'] === 'cancelado' && $estado !== 'cancelado') $err[] = 'Un ticket cancelado no se reactiva: crea el partido de nuevo.';
        if ($err) { flash('error', $err); regresar(); }
        $antes = ['importe' => $t['importe'], 'concepto' => $t['concepto'], 'estado' => $t['estado'], 'visible' => $t['visible_capitan']];
        db()->prepare('UPDATE tickets_futuros SET importe = ?, concepto = ?, nota = ?, folio_tesoreria = ?, estado = ?, fecha_cobro = ?,
                       visible_capitan = ?, actualizado_por = ? WHERE id = ?')
            ->execute([number_format($importe, 2, '.', ''), $concepto, $nota ?: null, $folio ?: null, $estado,
                       $estado === 'cobrado' ? ($fc ?? ($t['fecha_cobro'] ?: date('Y-m-d'))) : null,
                       !empty($_POST['visible']) ? 1 : 0, $usuario, $id]);
        fin_bitacora_registrar($usuario, 'editar_ticket', 'ticket', (string)$id, $antes,
            ['importe' => $importe, 'concepto' => $concepto, 'estado' => $estado, 'visible' => !empty($_POST['visible'])]);
        flash('ok', 'Ticket actualizado.');
        regresar();
    }

    /* ---------- Partido: jornada, ronda, fecha, estado ---------- */
    case 'editar_partido': {
        $jor = (int)($_POST['jornada'] ?? 0);
        $estado = (string)($_POST['estado'] ?? '');
        $fecha = fecha_valida($_POST['fecha'] ?? '');
        $err = [];
        if ($jor < 1 || $jor > 400) $err[] = 'La jornada debe ser de 1 a 400.';
        if (!in_array($estado, ['programado', 'jugado', 'cancelado'], true)) $err[] = 'Estado no válido.';
        if (trim((string)($_POST['fecha'] ?? '')) !== '' && !$fecha) $err[] = 'Fecha no válida.';
        if ($err) { flash('error', $err); regresar(); }
        $e = fp_editar_partido((int)($_POST['id'] ?? 0),
            ['jornada' => $jor, 'ronda' => mb_substr(trim((string)($_POST['ronda'] ?? '')), 0, 40), 'fecha' => $fecha, 'estado' => $estado], $usuario);
        $e ? flash('error', $e) : flash('ok', 'Partido actualizado (jornada y fecha también en sus tickets).');
        regresar();
    }

    /* ---------- Costos ---------- */
    case 'aplicar_costos': {
        $ids = ids_post('partidos');
        if (!$ids) { flash('error', 'No seleccionaste partidos.'); regresar(); }
        $ed = db()->prepare('SELECT DISTINCT edicion_ref FROM fin_partidos WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $ed->execute($ids);
        $eds = $ed->fetchAll(PDO::FETCH_COLUMN);
        $total = ['partidos' => 0, 'costos' => 0];
        foreach ($eds as $e) {
            $st = db()->prepare('SELECT id FROM fin_partidos WHERE edicion_ref = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute(array_merge([$e], $ids));
            $r = fp_aplicar_costos(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), fp_tarifas((string)$e), $usuario);
            $total['partidos'] += $r['partidos']; $total['costos'] += $r['costos'];
        }
        flash('ok', $total['partidos']
            ? "Costos aplicados a {$total['partidos']} partidos ({$total['costos']} conceptos). Quedan «por pagar»."
            : 'Esos partidos ya tenían sus costos (o están cancelados).');
        regresar();
    }
    case 'costos_estado': {
        $estado = (string)($_POST['estado'] ?? '');
        $porPartido = ($_POST['por'] ?? '') === 'partido';
        $ids = ids_post($porPartido ? 'partidos' : 'costos');
        if (!$ids) { flash('error', 'No seleccionaste nada.'); regresar(); }
        if (!isset(FP_ESTADOS_COSTO[$estado])) { flash('error', 'Estado no válido.'); regresar(); }
        $n = fp_estado_costos($ids, $estado, $porPartido, fecha_valida($_POST['fecha_pago'] ?? ''), $usuario);
        flash('ok', "$n costos → " . FP_ESTADOS_COSTO[$estado] . '.');
        regresar();
    }
    case 'otro_gasto': {
        $desc = trim((string)($_POST['descripcion'] ?? ''));
        $monto = monto_valido($_POST['monto'] ?? '');
        $estado = ($_POST['estado'] ?? '') === 'pagado' ? 'pagado' : 'por_pagar';
        if ($desc === '' || mb_strlen($desc) > 150 || $monto === null) {
            flash('error', 'Escribe qué fue el gasto (máx. 150) y un monto mayor a 0.');
            regresar();
        }
        $e = fp_agregar_otro_gasto((int)($_POST['partido_id'] ?? 0), $desc, $monto, $estado, $usuario);
        $e ? flash('error', $e) : flash('ok', 'Gasto agregado al partido.');
        regresar();
    }
    /* ---------- Gastos generales ---------- */
    case 'gasto_guardar': {
        [$d, $err] = fg_validar($_POST, $cat['categorias']);
        if ($err) { flash('error', $err); regresar(); }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $g = fg_obtener($id);
            if (!$g || $g['edicion_ref'] !== $edicion) { flash('error', 'Ese gasto no existe en esta edición.'); regresar(); }
            $e = fg_editar($id, $d, $usuario);
            $e ? flash('error', $e) : flash('ok', 'Gasto actualizado.');
        } else {
            fg_crear($edicion, $d, $usuario);
            flash('ok', 'Gasto registrado: ' . $d['concepto'] . ' · ' . mxn($d['monto']) . ($d['estado'] === 'pagado' ? ' (pagado).' : ' (por pagar).'));
        }
        regresar();
    }
    case 'gastos_estado': {
        $ids = ids_post();
        $estado = (string)($_POST['estado'] ?? '');
        if (!$ids) { flash('error', 'No seleccionaste gastos.'); regresar(); }
        if (!isset(FG_ESTADOS[$estado])) { flash('error', 'Estado no válido.'); regresar(); }
        $n = fg_cambiar_estado($ids, $estado, fecha_valida($_POST['fecha_pago'] ?? ''), $usuario);
        flash('ok', "$n gastos → " . FG_ESTADOS[$estado] . '.');
        regresar();
    }
    case 'categoria_gasto': {
        $e = fg_crear_categoria((string)($_POST['nombre'] ?? ''), $usuario);
        $e ? flash('error', $e) : flash('ok', 'Categoría agregada.');
        regresar();
    }

    /* ---------- Configuración de la proyección ---------- */
    case 'config_guardar': {
        $k = (string)($_POST['categoria'] ?? '');
        if (!isset($cat['categorias'][$k])) { flash('error', 'Categoría no válida.'); regresar(); }
        if (!fin_tabla_existe('fin_parametros')) { flash('error', 'Falta la tabla fin_parametros (fase 3).'); regresar(); }
        $permitidas = ['equipos_esperados' => 500, 'partidos_regulares' => 5000, 'partidos_eliminatoria' => 500,
                       'cuota_inscripcion' => 100000, 'arbitraje_equipo_regular' => 100000,
                       'arbitraje_equipo_eliminatoria' => 100000, 'costo_arbitro_eliminatoria' => 100000];
        $enteros = ['equipos_esperados', 'partidos_regulares', 'partidos_eliminatoria'];
        $vig = fin_parametros_vigentes($edicion)[$k] ?? [];
        $ins = db()->prepare("INSERT INTO fin_parametros (edicion_ref, categoria_ref, clave, valor, motivo, creado_por)
                              VALUES (?, ?, ?, ?, 'Configuración de la proyección', ?)");
        $n = 0; $err = [];
        foreach ((array)($_POST['p'] ?? []) as $clave => $valor) {
            $clave = (string)$clave;
            if (!isset($permitidas[$clave])) continue;
            $txt = str_replace([',', '$', ' '], '', trim((string)$valor));
            if ($txt === '') continue;
            if (!is_numeric($txt) || (float)$txt < 0 || (float)$txt > $permitidas[$clave]
                || (in_array($clave, $enteros, true) && floor((float)$txt) != (float)$txt)) {
                $err[] = 'Valor no válido en «' . $clave . '».';
                continue;
            }
            $v = round((float)$txt, 2);
            if (isset($vig[$clave]) && abs($vig[$clave] - $v) < 0.005) continue;
            $ins->execute([$edicion, $k, $clave, number_format($v, 2, '.', ''), $usuario]);
            $n++;
        }
        if ($n) fin_bitacora_registrar($usuario, 'parametros', 'categoria', $k, $vig, (array)$_POST['p']);
        if ($err) flash('error', $err);
        flash('ok', $n ? "$n datos guardados para " . $cat['categorias'][$k]['nombre'] . '.' : 'Sin cambios.');
        regresar();
    }

    case 'tarifas': {
        $nuevas = [];
        foreach (FP_CONCEPTOS_FIJOS as $c) {
            $v = monto_valido($_POST['tarifa_' . $c] ?? '', true);
            if ($v === null) { flash('error', 'Tarifa no válida para ' . FP_CONCEPTOS[$c] . '.'); regresar(); }
            $nuevas[$c] = $v;
        }
        $n = fp_guardar_tarifas($nuevas, $usuario);
        flash('ok', $n ? 'Tarifas actualizadas. Se usan en los costos que se apliquen desde ahora; lo ya aplicado no cambia.' : 'Sin cambios en las tarifas.');
        regresar();
    }
    }
    flash('error', 'Acción no reconocida.');
    regresar();
} catch (Throwable $ex) {
    if (db()->inTransaction()) db()->rollBack();
    flash('error', error_interno($ex, 'finanzas-accion ' . $accion));
    regresar();
}
