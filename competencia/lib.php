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
/** Nombre comparable: sin acentos, mayúsculas ni signos. Sólo sugiere equivalencias; nunca las aplica. */
function cp_normalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n','ç'=>'c']);
    return (string)preg_replace('/[^a-z0-9]+/', '', $s);
}
/** Convierte un partido del Coordinador anterior al formato de cp_partidos. Devuelve ['error'=>motivo] si no se puede. */
function cp_convertir_partido(array $p, array $liga): array {
    $fecha = (string)($p['fecha'] ?? ''); $hora = substr((string)($p['hora'] ?? ''), 0, 5);
    if (!cp_fecha($fecha) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $hora)) return ['error'=>'sin fecha u hora'];
    $origen = (string)($p['estado'] ?? 'programado');
    // El sistema nuevo no tiene "suspendido": queda programado para que coordinación lo reprograme.
    $estado = ['finalizado'=>'jugado','cancelado'=>'cancelado','programado'=>'programado','suspendido'=>'programado'][$origen] ?? null;
    if ($estado === null) return ['error'=>'estado desconocido: '.$origen];
    $gl = null; $gv = null;
    if ($estado === 'jugado') {
        $gl = filter_var($p['gl'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>999]]);
        $gv = filter_var($p['gv'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>999]]);
        if ($gl === false || $gv === false) return ['error'=>'finalizado sin marcador'];
    }
    $cancha = '';
    foreach ($liga['sedes'] ?? [] as $s) {
        foreach ($s['canchas'] ?? [] as $c) {
            if (($c['id'] ?? null) === ($p['canchaId'] ?? '')) $cancha = trim(($s['nombre'] ?? '').' · '.($c['nombre'] ?? ''), ' ·');
        }
    }
    $dur = max(15, min(240, (int)($liga['duracion'] ?? 60) ?: 60));
    return ['fecha'=>$fecha,'hora'=>$hora,'cancha'=>mb_substr($cancha !== '' ? $cancha : 'Por definir', 0, 100, 'UTF-8'),
        'duracion'=>$dur,'estado'=>$estado,'estado_origen'=>$origen,'goles_local'=>$gl,'goles_visita'=>$gv];
}
function cp_audit(int $uid, ?int $esp, string $accion, array $detalle): void {
    cp_query('INSERT INTO cp_bitacora(usuario_id,espacio_id,accion,detalle) VALUES(?,?,?,?)',
        [$uid,$esp,$accion,json_encode($detalle,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
/** Sesión del sistema de competencia (la misma cookie para api.php y entrar-capitan.php). */
function cp_sesion(): void {
    ini_set('session.use_strict_mode','1');
    session_name('lchcompetencia');
    session_set_cookie_params(['path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/').'/', 'httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Strict']);
    session_start();
}
/** Espacios que puede ver un usuario según su rol. */
function cp_espacios_de(array $u): array {
    if ($u['rol']==='administrador') return cp_query('SELECT * FROM cp_espacios')->fetchAll();
    if ($u['rol']==='coordinador') return cp_query('SELECT * FROM cp_espacios WHERE coordinador_id=?',[$u['id']])->fetchAll();
    if ($u['rol']==='jugador') return cp_query('SELECT DISTINCT e.* FROM cp_espacios e JOIN cp_jugadores j ON j.espacio_id=e.id WHERE j.usuario_id=? AND j.activo=1',[$u['id']])->fetchAll();
    return cp_query('SELECT e.* FROM cp_espacios e JOIN cp_equipos q ON q.espacio_id=e.id WHERE q.usuario_id=?',[$u['id']])->fetchAll();
}
/** Entero opcional: '' o null → null; fuera de rango → excepción. */
function cp_entero_opcional($v, int $min, int $max): ?int {
    if ($v === null || $v === '') return null;
    $n = filter_var($v, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if ($n === false) throw new InvalidArgumentException('Número fuera de rango.');
    return $n;
}
