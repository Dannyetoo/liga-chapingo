<?php
declare(strict_types=1);

function cp_db(): PDO {
    static $conexion;
    if (!$conexion) {
        require __DIR__ . '/../disponibilidad/db.php';
        if (!isset($pdo) || !$pdo instanceof PDO) throw new RuntimeException('PDO no disponible.');
        $conexion = $pdo;
    }
    return $conexion;
}
function cp_query(string $sql, array $params = []): PDOStatement {
    $s = cp_db()->prepare($sql); $s->execute($params); return $s;
}
function cp_legacy(): array {
    $f = __DIR__ . '/../arbitrajes/datos/estado.json';
    $fp = fopen($f, 'rb');
    if (!$fp) throw new RuntimeException('Tesorería no disponible.');
    try { flock($fp, LOCK_SH); $j = json_decode(stream_get_contents($fp), true, 512, JSON_THROW_ON_ERROR); }
    finally { flock($fp, LOCK_UN); fclose($fp); }
    if (!is_array($j['estado'] ?? null)) throw new RuntimeException('Tesorería inválida.');
    return $j['estado'];
}
function cp_puede(array $u, array $e, bool $asignar = false): bool {
    return ($u['rol'] === 'administrador' && (int)$e['propietario_id'] === (int)$u['id'])
        || (!$asignar && $u['rol'] === 'coordinador' && (int)$e['coordinador_id'] === (int)$u['id']);
}
function cp_centavos($x): int {
    if (!is_numeric($x) || !is_finite((float)$x)) throw new InvalidArgumentException('Monto inválido.');
    return (int)round((float)$x * 100);
}
function cp_visible(array $m): bool {
    $auto=$m['auto']??null;
    if(is_array($auto)&&isset($auto['reserva'])) {
        $marca=$auto['ocultoHastaCobro']??null; $cobrado=cp_centavos($m['recibido']??0)>0;
        if(($marca===null||$marca===true)&&!$cobrado)return false;
        if($marca===true&&$cobrado)return true;
    }
    return empty($m['ocultoCapitan']);
}
/** Adaptador de sólo lectura para los informes financieros anteriores. Nunca guardar este estado en Tesorería. */
function cp_incorporar_libro(array $S): array {
    $rows=cp_query('SELECT m.*,e.origen_ref,s.edicion_ref FROM cp_movimientos m LEFT JOIN cp_equipos e ON e.id=m.equipo_id JOIN cp_espacios s ON s.id=m.espacio_id ORDER BY m.id')->fetchAll();
    foreach($rows as $m) {
        $x=['id'=>'cp-'.$m['id'],'fecha'=>$m['fecha'],'concepto'=>$m['concepto'],'folio'=>(int)$m['id'],'hora'=>'00:00','forma'=>'Registro en competencia','nota'=>'Libro del espacio digital'];
        if($m['tipo']==='gasto') {
            $S['gastos'][]=$x+['monto'=>(float)$m['monto'],'edicionId'=>$m['edicion_ref'],'categoriaGasto'=>'Administración','beneficiario'=>''];
        } else {
            $S['movs'][]=$x+['equipoId'=>$m['origen_ref'],'importe'=>$m['tipo']==='cargo'?(float)$m['monto']:0,'recibido'=>$m['tipo']==='pago'?(float)$m['monto']:0];
        }
    }
    return $S;
}
/** Histórico vivo + libro nuevo, sin copiar importes del histórico a SQL. */
function cp_cuentas(array $equipos, array $legacy, array $movs, bool $equipo = false): array {
    $r = []; $porRef = [];
    foreach ($equipos as $e) {
        $id = (int)$e['id']; $porRef[$e['origen_ref']] = $id;
        $r[$id] = ['id'=>$id,'nombre'=>$e['nombre'],'cargos'=>0,'pagado'=>0,'saldo'=>0,'movimientos'=>[]];
    }
    foreach ($legacy['movs'] ?? [] as $m) {
        $id = $porRef[$m['equipoId'] ?? ''] ?? null;
        if (!$id || ($equipo && !cp_visible($m))) continue;
        $c = cp_centavos($m['importe'] ?? 0); $p = cp_centavos($m['recibido'] ?? 0);
        $r[$id]['cargos'] += $c; $r[$id]['pagado'] += $p;
        $r[$id]['movimientos'][] = ['origen'=>'tesoreria','fecha'=>$m['fecha'] ?? '', 'concepto'=>$m['concepto'] ?? '', 'cargo'=>$c/100,'pago'=>$p/100];
    }
    foreach ($movs as $m) {
        $id = (int)$m['equipo_id']; if (!isset($r[$id]) || $m['tipo'] === 'gasto') continue;
        $v = cp_centavos($m['monto']); $r[$id][$m['tipo'] === 'pago' ? 'pagado' : 'cargos'] += $v;
        $r[$id]['movimientos'][] = ['origen'=>'competencia','fecha'=>$m['fecha'],'concepto'=>$m['concepto'],'cargo'=>$m['tipo']==='cargo'?$v/100:0,'pago'=>$m['tipo']==='pago'?$v/100:0];
    }
    foreach ($r as &$c) { $c['saldo']=($c['cargos']-$c['pagado'])/100; $c['cargos']/=100; $c['pagado']/=100; }
    unset($c); return array_values($r);
}
function cp_fecha(string $s): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}
function cp_audit(int $uid, ?int $esp, string $accion, array $detalle): void {
    cp_query('INSERT INTO cp_bitacora(usuario_id,espacio_id,accion,detalle) VALUES(?,?,?,?)',
        [$uid,$esp,$accion,json_encode($detalle,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
