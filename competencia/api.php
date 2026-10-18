<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
require __DIR__.'/torneo-lib.php';
ini_set('display_errors','0');
cp_sesion();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
function respuesta(array $r, int $codigo=200): void { http_response_code($codigo); echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit; }
function exigir(bool $ok, string $mensaje='Acceso denegado.', int $codigo=403): void { if (!$ok) respuesta(['ok'=>false,'error'=>$mensaje],$codigo); }
function cadena(array $in,string $k,int $max=150): string { $s=$in[$k]??''; exigir(is_string($s)&&mb_strlen(trim($s))>0&&mb_strlen($s)<=$max,'Campo inválido: '.$k,422); return trim($s); }
try {
    $a=(string)($_GET['a']??'sesion'); $post=$_SERVER['REQUEST_METHOD']==='POST';
    $raw=$post?file_get_contents('php://input'):'';
    exigir(strlen($raw)<=16384,'Solicitud demasiado grande.',413);
    $in=$post?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
    exigir(is_array($in),'JSON inválido.',400);
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    if ($post) exigir(hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')),'Recarga la página.',403);
    if ($a==='entrar') {
        exigir($post,'Usa POST.',405);
        $login=cadena($in,'usuario',60); $clave=$in['clave']??'';
        exigir(is_string($clave)&&strlen($clave)>0&&strlen($clave)<=200,'Contraseña inválida.',422);
        $ip=substr($_SERVER['REMOTE_ADDR']??'',0,45);
        $n=cp_query('SELECT COUNT(*) FROM cp_intentos WHERE creado_en > NOW()-INTERVAL 15 MINUTE AND (usuario=? OR ip=?)',[$login,$ip])->fetchColumn();
        exigir((int)$n<12,'Demasiados intentos. Espera 15 minutos.',429);
        $u=cp_query('SELECT * FROM cp_usuarios WHERE usuario=? AND activo=1',[$login])->fetch();
        $hash=$u?$u['clave_hash']:'$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $valida=password_verify($clave,$hash);
        if (!$valida && $u && $u['rol']==='equipo' && cp_query('SELECT usuario_id FROM cp_codigos_migrados WHERE usuario_id=?',[$u['id']])->fetchColumn()) {
            $normalizada=(string)preg_replace('/[^A-Z0-9]/','',strtoupper(trim($clave)));
            $valida=password_verify($normalizada,$hash);
        }
        if (!$valida||!$u) {
            cp_query('INSERT INTO cp_intentos(usuario,ip) VALUES(?,?)',[$login,$ip]);
            respuesta(['ok'=>false,'error'=>'Usuario o contraseña incorrectos.'],401);
        }
        session_regenerate_id(true);
        $_SESSION=['uid'=>(int)$u['id'],'version'=>(int)$u['version_clave'],'inicio'=>time(),'visto'=>time(),'csrf'=>bin2hex(random_bytes(32))];
        respuesta(['ok'=>true]);
    }
    $u=isset($_SESSION['uid'])?cp_query('SELECT id,usuario,nombre,rol,activo,version_clave FROM cp_usuarios WHERE id=?',[$_SESSION['uid']])->fetch():false;
    if (!$u || !$u['activo'] || (int)$u['version_clave']!==(int)($_SESSION['version']??0) || time()-($_SESSION['visto']??0)>1800 || time()-($_SESSION['inicio']??0)>28800) {
        unset($_SESSION['uid']); $u=false;
    }
    if ($a==='sesion') respuesta(['ok'=>true,'usuario'=>$u?array_intersect_key($u,array_flip(['id','usuario','nombre','rol'])):null,'csrf'=>$_SESSION['csrf']]);
    exigir((bool)$u,'Inicia sesión.',401); $_SESSION['visto']=time();
    if ($a==='salir') { exigir($post,'Usa POST.',405); $_SESSION=[]; session_destroy(); respuesta(['ok'=>true]); }
    /* Estado financiero oficial: disponible para coordinadores y administradores aunque
       todavía no exista una fila en cp_documentos. El archivo permanece fuera del índice. */
    if ($a==='estado_financiero_pdf') {
        exigir(in_array($u['rol'],['administrador','coordinador'],true),'Sólo coordinación puede consultar este documento.');
        $ruta=__DIR__.'/documentos/estado de resultados 2026.pdf';
        exigir(is_file($ruta),'El PDF no está cargado en competencia/documentos/.',404);
        header_remove('Content-Type'); header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="estado-financiero-2026.pdf"');
        header('Content-Length: '.filesize($ruta)); readfile($ruta); exit;
    }
    if ($a==='espacios') {
        $es=cp_espacios_de($u);
        foreach($es as &$e) $e['escritura']=cp_puede($u,$e); unset($e);
        respuesta(['ok'=>true,'espacios'=>$es]);
    }
    if ($a==='administradores') {
        exigir($u['rol']==='administrador');
        respuesta(['ok'=>true,'usuarios'=>cp_query("SELECT id,nombre,usuario,rol FROM cp_usuarios WHERE rol IN ('administrador','coordinador') AND activo=1")->fetchAll()]);
    }
    if ($a==='crear_espacio') {
        exigir($post,'Usa POST.',405); exigir($u['rol']==='administrador');
        $coord=(int)($in['coordinador_id']??0);
        exigir((bool)cp_query("SELECT id FROM cp_usuarios WHERE id=? AND rol='coordinador' AND activo=1",[$coord])->fetch(),'Coordinador inválido.',422);
        cp_query('INSERT INTO cp_espacios(nombre,edicion_ref,propietario_id,coordinador_id) VALUES(?,?,?,?)',[cadena($in,'nombre'),cadena($in,'edicion_ref',64),$u['id'],$coord]);
        respuesta(['ok'=>true]);
    }
    // Dashboard general: todos los espacios; el detalle de equipos ajenos no se entrega.
    if ($a==='general') {
        exigir($u['rol']==='administrador'); $legacy=cp_legacy(); $out=[];
        foreach(cp_query('SELECT * FROM cp_espacios')->fetchAll() as $e) {
            $eq=cp_query('SELECT * FROM cp_equipos WHERE espacio_id=?',[$e['id']])->fetchAll();
            $ms=cp_query('SELECT * FROM cp_movimientos WHERE espacio_id=?',[$e['id']])->fetchAll();
            $cs=cp_cuentas($eq,$legacy,$ms); $out[]=['id'=>$e['id'],'nombre'=>$e['nombre'],'equipos'=>count($eq),'cobrado'=>array_sum(array_column($cs,'pagado')),'pendiente'=>array_sum(array_map(static fn($c)=>max(0,$c['saldo']),$cs)),'escritura'=>cp_puede($u,$e)];
        }
        $reportes=cp_query('SELECT r.*,e.nombre AS espacio,q.nombre AS equipo FROM cp_reportes r JOIN cp_espacios e ON e.id=r.espacio_id JOIN cp_equipos q ON q.id=r.equipo_id AND q.espacio_id=r.espacio_id ORDER BY r.id DESC')->fetchAll();
        respuesta(['ok'=>true,'torneos'=>$out,'reportes'=>$reportes]);
    }
    // Documentos del perfil (p. ej. «Estado de resultados 2026») y de los espacios del usuario.
    if ($a==='documentos'||$a==='documento') {
        $esp=array_map('intval',array_column(cp_espacios_de($u),'id')) ?: [0];
        $marcas=implode(',',array_fill(0,count($esp),'?'));
        $sql='SELECT id,titulo,archivo,creado_en FROM cp_documentos WHERE activo=1 AND (usuario_id=? OR (usuario_id IS NULL AND espacio_id IN ('.$marcas.')))';
        if ($a==='documentos') {
            $docs=cp_query($sql.' ORDER BY id DESC',array_merge([$u['id']],$esp))->fetchAll();
            foreach($docs as &$d){ $d['disponible']=is_file(__DIR__.'/documentos/'.basename($d['archivo'])); unset($d['archivo']); } unset($d);
            respuesta(['ok'=>true,'documentos'=>$docs]);
        }
        $d=cp_query($sql.' AND id=?',array_merge([$u['id']],$esp,[(int)($_GET['id']??0)]))->fetch();
        exigir((bool)$d,'Documento no disponible.',404);
        $arch=basename($d['archivo']); $ruta=__DIR__.'/documentos/'.$arch;
        exigir((bool)preg_match('/^[A-Za-z0-9._ ()-]+\.pdf$/i',$arch)&&is_file($ruta),'El documento todavía no se ha cargado. Avisa a la mesa directiva.',404);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.$arch.'"');
        header('Content-Length: '.filesize($ruta));
        readfile($ruta); exit;
    }
    $sid=(int)($post?($in['espacio_id']??0):($_GET['espacio_id']??0));
    $e=cp_query('SELECT * FROM cp_espacios WHERE id=?',[$sid])->fetch(); exigir((bool)$e,'Espacio inexistente.',404);
    $team=$u['rol']==='equipo'?cp_query('SELECT * FROM cp_equipos WHERE espacio_id=? AND usuario_id=?',[$sid,$u['id']])->fetch():false;
    $ficha=$u['rol']==='jugador'?cp_query('SELECT * FROM cp_jugadores WHERE espacio_id=? AND usuario_id=? AND activo=1',[$sid,$u['id']])->fetch():false;
    // Torneo y estadísticas: lo consultan administración, coordinación, equipos y jugadores del espacio.
    if ($a==='torneo') {
        exigir(cp_puede($u,$e)||(bool)$team||(bool)$ficha||$u['rol']==='administrador');
        $q=static fn(string $t)=>cp_query('SELECT * FROM '.$t.' WHERE espacio_id=?',[$sid])->fetchAll();
        $eqs=cp_query('SELECT id,nombre,categoria FROM cp_equipos WHERE espacio_id=?',[$sid])->fetchAll();
        $t=cp_torneo_calcular($eqs,$q('cp_partidos'),$q('cp_partido_info'),$q('cp_jugadores'),$q('cp_eventos'),$q('cp_porteros'),$q('cp_sanciones'),$q('cp_votos'));
        $mis=['jugador'=>[],'equipo'=>[]];
        if ($ficha) foreach(cp_query('SELECT tipo,objetivo_id,puntaje FROM cp_votos WHERE votante_id=?',[$ficha['id']])->fetchAll() as $v) $mis[$v['tipo']][$v['objetivo_id']]=(int)$v['puntaje'];
        $esc=cp_puede($u,$e);
        if ($esc) {
            $t['sanciones']=cp_query('SELECT id,jugador_id,partidos,motivo,desde FROM cp_sanciones WHERE espacio_id=? ORDER BY desde DESC,id DESC',[$sid])->fetchAll();
            $t['jugadores_todos']=cp_query('SELECT j.id,j.equipo_id,j.nombre,j.numero,j.posicion,j.activo,u.usuario FROM cp_jugadores j LEFT JOIN cp_usuarios u ON u.id=j.usuario_id WHERE j.espacio_id=? ORDER BY j.nombre',[$sid])->fetchAll();
            $t['porteros_asignados']=cp_query('SELECT partido_id,equipo_id,jugador_id FROM cp_porteros WHERE espacio_id=?',[$sid])->fetchAll();
        }
        respuesta(['ok'=>true,'espacio'=>['id'=>(int)$e['id'],'nombre'=>$e['nombre']],'escritura'=>$esc,
            'yo'=>['rol'=>$u['rol'],'nombre'=>$u['nombre'],'ficha_id'=>$ficha?(int)$ficha['id']:null,'equipo_id'=>$team?(int)$team['id']:($ficha?(int)$ficha['equipo_id']:null),'votos'=>$mis]]+$t);
    }
    if ($a==='votar') {
        exigir($post,'Usa POST.',405); exigir((bool)$ficha,'Sólo los jugadores con ficha pueden calificar.');
        $tipo=cadena($in,'tipo',10); $obj=(int)($in['objetivo_id']??0); $pts=cp_entero_opcional($in['puntaje']??null,1,5);
        exigir($pts!==null,'Califica de 1 a 5.',422);
        if ($tipo==='jugador') {
            exigir($obj!==(int)$ficha['id'],'No puedes calificarte a ti mismo.',422);
            exigir((bool)cp_query('SELECT id FROM cp_jugadores WHERE espacio_id=? AND id=? AND activo=1',[$sid,$obj])->fetch(),'Jugador fuera del torneo.',422);
        } elseif ($tipo==='equipo') {
            exigir($obj!==(int)$ficha['equipo_id'],'No puedes calificar a tu propio equipo.',422);
            exigir((bool)cp_query('SELECT id FROM cp_equipos WHERE espacio_id=? AND id=?',[$sid,$obj])->fetch(),'Equipo fuera del torneo.',422);
        } else exigir(false,'Tipo inválido.',422);
        cp_query('INSERT INTO cp_votos(espacio_id,votante_id,tipo,objetivo_id,puntaje) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE puntaje=VALUES(puntaje)',[$sid,$ficha['id'],$tipo,$obj,$pts]);
        respuesta(['ok'=>true]);
    }
    exigir(cp_puede($u,$e)||(bool)$team||($u['rol']==='administrador'&&$a==='panel'));
    $eq=cp_query('SELECT q.id,q.origen_ref,q.nombre,q.categoria,q.usuario_id,u.usuario FROM cp_equipos q LEFT JOIN cp_usuarios u ON u.id=q.usuario_id WHERE q.espacio_id=?',[$sid])->fetchAll();
    $ids=array_map('intval',array_column($eq,'id'));
    if ($a==='panel') {
        $movs=cp_query('SELECT m.*,d.recibio FROM cp_movimientos m LEFT JOIN cp_movimiento_detalle d ON d.movimiento_id=m.id WHERE m.espacio_id=? ORDER BY m.fecha,m.id',[$sid])->fetchAll();
        $cs=cp_cuentas($team?[$team]:$eq,cp_legacy(),$movs,(bool)$team);
        $partidos=cp_query('SELECT * FROM cp_partidos WHERE espacio_id=? ORDER BY fecha,hora',[$sid])->fetchAll();
        if ($team) $partidos=array_values(array_filter($partidos,static fn($p)=>(int)$p['local_id']===(int)$team['id']||(int)$p['visita_id']===(int)$team['id']));
        $publicos=array_map(static fn($q)=>['id'=>$q['id'],'nombre'=>$q['nombre'],'categoria'=>$q['categoria']],$eq);
        $gastos=array_sum(array_map(static fn($m)=>$m['tipo']==='gasto'?(float)$m['monto']:0,$movs));
        respuesta(['ok'=>true,'espacio'=>$e,'equipos'=>$team?$publicos:$eq,'partidos'=>$partidos,'cuentas'=>$cs,'gastos_nuevos'=>$team?null:$gastos,'movimientos'=>$team?[]:$movs,'reportes'=>$team?[]:cp_query('SELECT * FROM cp_reportes WHERE espacio_id=? ORDER BY id DESC',[$sid])->fetchAll()]);
    }
    exigir($post,'Usa POST.',405);
    if ($a==='incidencia' && $team) {
        cp_query('INSERT INTO cp_reportes(espacio_id,equipo_id,mensaje) VALUES(?,?,?)',[$sid,$team['id'],cadena($in,'mensaje',1000)]);
        respuesta(['ok'=>true]);
    }
    exigir(cp_puede($u,$e));
    cp_db()->beginTransaction();
    // Serializa cambios del espacio y reservas de cancha entre espacios.
    cp_query('SELECT id FROM cp_espacios ORDER BY id FOR UPDATE')->fetchAll();
    // Revalida después del bloqueo: una reasignación concurrente revoca el permiso.
    $e=cp_query('SELECT * FROM cp_espacios WHERE id=?',[$sid])->fetch();
    exigir((bool)$e&&cp_puede($u,$e));
    $equipo=(int)($in['equipo_id']??0);
    if ($a==='crear_coordinador') {
        exigir(cp_puede($u,$e,true));
        $clave=bin2hex(random_bytes(12));
        cp_query("INSERT INTO cp_usuarios(usuario,nombre,clave_hash,rol) VALUES(?,?,?,'coordinador')",[cadena($in,'usuario',60),cadena($in,'nombre',120),password_hash($clave,PASSWORD_DEFAULT)]);
        cp_audit((int)$u['id'],$sid,$a,[]); cp_db()->commit(); respuesta(['ok'=>true,'usuario'=>$in['usuario'],'clave'=>$clave]);
    } elseif ($a==='desactivar_equipo') {
        exigir(in_array($equipo,$ids,true),'Equipo fuera del espacio.',422);
        cp_query("UPDATE cp_usuarios u JOIN cp_equipos q ON q.usuario_id=u.id SET u.activo=0,u.version_clave=u.version_clave+1 WHERE q.espacio_id=? AND q.id=? AND u.rol='equipo'",[$sid,$equipo]);
    } elseif ($a==='asignar') {
        exigir(cp_puede($u,$e,true)); $coord=(int)($in['coordinador_id']??0);
        exigir((bool)cp_query("SELECT id FROM cp_usuarios WHERE id=? AND rol='coordinador' AND activo=1",[$coord])->fetch(),'Coordinador inválido.',422);
        cp_query('UPDATE cp_espacios SET coordinador_id=? WHERE id=?',[$coord,$sid]);
    } elseif ($a==='equipo') {
        $ref=cadena($in,'origen_ref',64); $origen=null;
        foreach(cp_legacy()['equipos'] as $q) if(($q['id']??'')===$ref) $origen=$q;
        exigir($origen && ($origen['edicionId']??'')===$e['edicion_ref'],'Equipo fuera de la edición.',422);
        cp_query('INSERT INTO cp_equipos(espacio_id,origen_ref,nombre,categoria) VALUES(?,?,?,?)',[$sid,$ref,$origen['nombre'],cadena($in,'categoria')]);
    } elseif ($a==='usuario_equipo') {
        exigir(in_array($equipo,$ids,true),'Equipo fuera del espacio.',422);
        $nombre=cadena($in,'usuario',60); $clave=$in['clave']??bin2hex(random_bytes(12));
        exigir(is_string($clave)&&strlen($clave)>=12&&strlen($clave)<=200,'Contraseña de 12 a 200 caracteres.',422);
        $q=cp_query('SELECT usuario_id FROM cp_equipos WHERE espacio_id=? AND id=? FOR UPDATE',[$sid,$equipo])->fetch();
        if ($q['usuario_id']) cp_query("UPDATE cp_usuarios SET usuario=?,clave_hash=?,version_clave=version_clave+1,activo=1 WHERE id=? AND rol='equipo'",[$nombre,password_hash($clave,PASSWORD_DEFAULT),$q['usuario_id']]);
        else {
            cp_query("INSERT INTO cp_usuarios(usuario,nombre,clave_hash,rol) VALUES(?,?,?,'equipo')",[$nombre,$nombre,password_hash($clave,PASSWORD_DEFAULT)]);
            cp_query('UPDATE cp_equipos SET usuario_id=? WHERE espacio_id=? AND id=?',[cp_db()->lastInsertId(),$sid,$equipo]);
        }
        cp_query('DELETE c FROM cp_codigos_migrados c JOIN cp_equipos q ON q.usuario_id=c.usuario_id WHERE q.espacio_id=? AND q.id=?',[$sid,$equipo]);
        cp_audit((int)$u['id'],$sid,$a,['equipo_id'=>$equipo]); cp_db()->commit(); respuesta(['ok'=>true,'usuario'=>$nombre,'clave'=>$clave]);
    } elseif ($a==='movimiento') {
        $tipo=cadena($in,'tipo',10); exigir(in_array($tipo,['cargo','pago','gasto'],true),'Tipo inválido.',422);
        exigir($tipo==='gasto'||in_array($equipo,$ids,true),'Equipo fuera del espacio.',422);
        $cent=cp_centavos($in['monto']??0); exigir($cent>0&&$cent<=999999999999,'Monto fuera de rango.',422);
        $fecha=cadena($in,'fecha',10); exigir(cp_fecha($fecha),'Fecha inválida.',422);
        $req=cadena($in,'solicitud',32); exigir((bool)preg_match('/^[a-f0-9]{32}$/D',$req),'Solicitud inválida.',422);
        $prev=cp_query('SELECT espacio_id,creado_por FROM cp_movimientos WHERE solicitud=?',[$req])->fetch();
        if ($prev) { exigir((int)$prev['espacio_id']===$sid&&(int)$prev['creado_por']===(int)$u['id']); cp_db()->rollBack(); respuesta(['ok'=>true,'repetido'=>true]); }
        cp_query('INSERT INTO cp_movimientos(espacio_id,equipo_id,tipo,monto,concepto,fecha,creado_por,solicitud) VALUES(?,?,?,?,?,?,?,?)',[$sid,$tipo==='gasto'?null:$equipo,$tipo,$cent/100,cadena($in,'concepto'),$fecha,$u['id'],$req]);
        $recibio=cadena($in,'recibio',120);
        exigir($tipo==='cargo'||$recibio!=='','Indica quién recibió el pago.',422);
        cp_query('INSERT INTO cp_movimiento_detalle(movimiento_id,recibio) VALUES(?,?)',[cp_db()->lastInsertId(),$tipo==='cargo'?'No aplica':$recibio]);
    } elseif ($a==='jugador') {
        exigir(in_array($equipo,$ids,true),'Equipo fuera del espacio.',422);
        $pos=cadena($in,'posicion',10); exigir(in_array($pos,CP_POSICIONES,true),'Posición inválida.',422);
        $num=cp_entero_opcional($in['numero']??null,0,999); $activo=(int)(($in['activo']??1)?1:0); $nombre=cp_nombre_limpio(cadena($in,'nombre',120));
        $id=(int)($in['id']??0);
        if ($id) {
            $st=cp_query('UPDATE cp_jugadores SET equipo_id=?,nombre=?,numero=?,posicion=?,activo=? WHERE espacio_id=? AND id=?',[$equipo,$nombre,$num,$pos,$activo,$sid,$id]);
            exigir((bool)cp_query('SELECT id FROM cp_jugadores WHERE espacio_id=? AND id=?',[$sid,$id])->fetch(),'Jugador fuera del espacio.',422);
            if (!$activo) cp_query("UPDATE cp_usuarios u JOIN cp_jugadores j ON j.usuario_id=u.id SET u.activo=0,u.version_clave=u.version_clave+1 WHERE j.espacio_id=? AND j.id=? AND u.rol='jugador'",[$sid,$id]);
        } else cp_query('INSERT INTO cp_jugadores(espacio_id,equipo_id,nombre,numero,posicion,activo) VALUES(?,?,?,?,?,?)',[$sid,$equipo,$nombre,$num,$pos,$activo]);
    } elseif ($a==='importar_plantillas') {
        $legacy=[]; foreach(cp_legacy()['equipos']??[] as $q) $legacy[(string)($q['id']??'')]=$q;
        $n=0;
        foreach($eq as $q) {
            foreach(($legacy[$q['origen_ref']]['jugadores']??[]) as $j) {
                $nombre=cp_nombre_limpio((string)($j['nombre']??'')); $ref=(string)($j['id']??'');
                if ($nombre===''||$ref==='') continue;
                $num=filter_var($j['num']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999]]);
                $n+=cp_query('INSERT IGNORE INTO cp_jugadores(espacio_id,equipo_id,nombre,numero,origen_ref) VALUES(?,?,?,?,?)',[$sid,$q['id'],mb_substr($nombre,0,120,'UTF-8'),$num===false?null:$num,$ref])->rowCount();
            }
        }
        cp_audit((int)$u['id'],$sid,$a,['nuevos'=>$n]); cp_db()->commit(); respuesta(['ok'=>true,'nuevos'=>$n]);
    } elseif ($a==='usuario_jugador') {
        $jid=(int)($in['jugador_id']??0);
        $j=cp_query('SELECT * FROM cp_jugadores WHERE espacio_id=? AND id=? AND activo=1 FOR UPDATE',[$sid,$jid])->fetch();
        exigir((bool)$j,'Jugador fuera del espacio.',422);
        $nombre=cadena($in,'usuario',60); $clave=bin2hex(random_bytes(6));
        $otro=cp_query('SELECT id FROM cp_usuarios WHERE usuario=?',[$nombre])->fetchColumn();
        exigir($otro===false||(int)$otro===(int)$j['usuario_id'],'Ese usuario ya existe. Elige otro.',409);
        if ($j['usuario_id']) cp_query("UPDATE cp_usuarios SET usuario=?,clave_hash=?,version_clave=version_clave+1,activo=1 WHERE id=? AND rol='jugador'",[$nombre,password_hash($clave,PASSWORD_DEFAULT),$j['usuario_id']]);
        else {
            cp_query("INSERT INTO cp_usuarios(usuario,nombre,clave_hash,rol) VALUES(?,?,?,'jugador')",[$nombre,$j['nombre'],password_hash($clave,PASSWORD_DEFAULT)]);
            cp_query('UPDATE cp_jugadores SET usuario_id=? WHERE espacio_id=? AND id=?',[cp_db()->lastInsertId(),$sid,$jid]);
        }
        cp_audit((int)$u['id'],$sid,$a,['jugador_id'=>$jid]); cp_db()->commit(); respuesta(['ok'=>true,'usuario'=>$nombre,'clave'=>$clave]);
    } elseif (in_array($a,['evento','portero','info_partido'],true)) {
        $p=cp_query("SELECT * FROM cp_partidos WHERE espacio_id=? AND id=? AND tipo='torneo'",[$sid,(int)($in['partido_id']??0)])->fetch();
        exigir((bool)$p,'Partido de torneo fuera del espacio.',422);
        $lados=[(int)$p['local_id'],(int)$p['visita_id']];
        if ($a==='info_partido') {
            $fase=cadena($in,'fase',15); exigir(isset(CP_FASES[$fase]),'Fase inválida.',422);
            $pl=cp_entero_opcional($in['penales_local']??null,0,99); $pv=cp_entero_opcional($in['penales_visita']??null,0,99);
            exigir(($pl===null)===($pv===null),'Captura los penales de ambos equipos o de ninguno.',422);
            cp_query('INSERT INTO cp_partido_info(partido_id,espacio_id,fase,jornada,llave,penales_local,penales_visita) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE fase=VALUES(fase),jornada=VALUES(jornada),llave=VALUES(llave),penales_local=VALUES(penales_local),penales_visita=VALUES(penales_visita)',
                [$p['id'],$sid,$fase,cp_entero_opcional($in['jornada']??null,1,99),cp_entero_opcional($in['llave']??null,1,64),$pl,$pv]);
        } else {
            exigir($p['estado']==='jugado','Primero captura el marcador del partido.',422);
            $jid=(int)($in['jugador_id']??0);
            $j=$jid?cp_query('SELECT * FROM cp_jugadores WHERE espacio_id=? AND id=?',[$sid,$jid])->fetch():false;
            if ($a==='portero') {
                $lado=(int)($in['equipo_id']??0); exigir(in_array($lado,$lados,true),'Equipo fuera del partido.',422);
                if (!$jid) cp_query('DELETE FROM cp_porteros WHERE partido_id=? AND equipo_id=?',[$p['id'],$lado]);
                else {
                    exigir($j&&(int)$j['equipo_id']===$lado,'El portero no es de ese equipo.',422);
                    cp_query('REPLACE INTO cp_porteros(partido_id,equipo_id,espacio_id,jugador_id) VALUES(?,?,?,?)',[$p['id'],$lado,$sid,$jid]);
                }
            } else {
                exigir($j&&in_array((int)$j['equipo_id'],$lados,true),'El jugador no es de ninguno de los dos equipos.',422);
                $tipo=cadena($in,'tipo',10); exigir(in_array($tipo,['gol','autogol','amarilla','roja'],true),'Tipo inválido.',422);
                cp_query('INSERT INTO cp_eventos(espacio_id,partido_id,jugador_id,tipo,minuto,creado_por) VALUES(?,?,?,?,?,?)',[$sid,$p['id'],$jid,$tipo,cp_entero_opcional($in['minuto']??null,0,150),$u['id']]);
            }
        }
    } elseif ($a==='borrar_evento') {
        cp_query('DELETE FROM cp_eventos WHERE espacio_id=? AND id=?',[$sid,(int)($in['id']??0)]);
    } elseif ($a==='sancion') {
        exigir((bool)cp_query('SELECT id FROM cp_jugadores WHERE espacio_id=? AND id=?',[$sid,(int)($in['jugador_id']??0)])->fetch(),'Jugador fuera del espacio.',422);
        $n=cp_entero_opcional($in['partidos']??null,1,20); exigir($n!==null,'Partidos de sanción: 1 a 20.',422);
        $desde=cadena($in,'desde',10); exigir(cp_fecha($desde),'Fecha inválida.',422);
        cp_query('INSERT INTO cp_sanciones(espacio_id,jugador_id,partidos,motivo,desde,creado_por) VALUES(?,?,?,?,?,?)',[$sid,(int)$in['jugador_id'],$n,cadena($in,'motivo',200),$desde,$u['id']]);
    } elseif ($a==='borrar_sancion') {
        cp_query('DELETE FROM cp_sanciones WHERE espacio_id=? AND id=?',[$sid,(int)($in['id']??0)]);
    } elseif ($a==='reporte') {
        $id=(int)($in['id']??0);
        cp_query("UPDATE cp_reportes SET estado='resuelto',respuesta=? WHERE espacio_id=? AND id=?",[cadena($in,'respuesta',1000),$sid,$id]);
    } elseif ($a==='partido') {
        $local=(int)($in['local_id']??0); $visita=(int)($in['visita_id']??0);
        exigir($local!==$visita&&in_array($local,$ids,true)&&in_array($visita,$ids,true),'Equipos inválidos.',422);
        $fecha=cadena($in,'fecha',10); $hora=cadena($in,'hora',5); $cancha=cadena($in,'cancha',100); $dur=(int)($in['duracion']??60);
        exigir(cp_fecha($fecha)&&preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$hora)&&$dur>=15&&$dur<=240,'Horario inválido.',422);
        $tipo=cadena($in,'tipo',10); $estado=cadena($in,'estado',12);
        exigir(in_array($tipo,['torneo','amistoso'],true)&&in_array($estado,['programado','jugado','cancelado'],true),'Estado inválido.',422);
        $id=(int)($in['id']??0); $ini=$fecha.' '.$hora.':00'; $fin=(new DateTimeImmutable($ini))->modify('+'.$dur.' minutes')->format('Y-m-d H:i:s');
        $choque=cp_query("SELECT id FROM cp_partidos WHERE id<>? AND estado<>'cancelado' AND TIMESTAMP(fecha,hora)<? AND DATE_ADD(TIMESTAMP(fecha,hora),INTERVAL duracion MINUTE)>? AND (cancha=? OR (espacio_id=? AND (local_id IN (?,?) OR visita_id IN (?,?)))) LIMIT 1",[$id,$fin,$ini,$cancha,$sid,$local,$visita,$local,$visita])->fetch();
        exigir($estado==='cancelado'||!$choque,'Hay un cruce de cancha o equipo.',409);
        $gl=$in['goles_local']??null; $gv=$in['goles_visita']??null;
        if ($estado==='jugado') exigir(filter_var($gl,FILTER_VALIDATE_INT)!==false&&filter_var($gv,FILTER_VALIDATE_INT)!==false&&$gl>=0&&$gl<=999&&$gv>=0&&$gv<=999,'Marcador inválido.',422);
        else { $gl=null; $gv=null; }
        if ($id) {
            $st=cp_query('UPDATE cp_partidos SET local_id=?,visita_id=?,fecha=?,hora=?,cancha=?,duracion=?,tipo=?,estado=?,goles_local=?,goles_visita=?,version=version+1 WHERE espacio_id=? AND id=? AND version=?',[$local,$visita,$fecha,$hora,$cancha,$dur,$tipo,$estado,$gl,$gv,$sid,$id,(int)($in['version']??0)]);
            exigir($st->rowCount()===1,'El partido cambió. Recarga.',409);
        } else cp_query('INSERT INTO cp_partidos(espacio_id,local_id,visita_id,fecha,hora,cancha,duracion,tipo,estado,goles_local,goles_visita) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$sid,$local,$visita,$fecha,$hora,$cancha,$dur,$tipo,$estado,$gl,$gv]);
    } else respuesta(['ok'=>false,'error'=>'Acción desconocida.'],404);
    cp_audit((int)$u['id'],$sid,$a,array_diff_key($in,array_flip(['clave']))); cp_db()->commit(); respuesta(['ok'=>true]);
} catch (JsonException|InvalidArgumentException $ex) {
    if (cp_db()->inTransaction()) cp_db()->rollBack();
    respuesta(['ok'=>false,'error'=>'Datos inválidos.'],422);
} catch (Throwable $ex) {
    try { if (cp_db()->inTransaction()) cp_db()->rollBack(); } catch (Throwable $ignorado) {}
    error_log('[competencia] '.$ex->getMessage());
    respuesta(['ok'=>false,'error'=>'No se pudo completar. Revisa la instalación o recarga e inténtalo de nuevo.'],503);
}
