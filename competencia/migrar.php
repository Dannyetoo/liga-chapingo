<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/lib.php';
// Sin --aplicar sólo valida y muestra el plan. No ejecuta DDL automáticamente.
$op=getopt('', ['admin:','edicion:','aplicar']);
try {
    $admin=(string)($op['admin']??'');
    if ($admin==='') throw new RuntimeException('Uso: php competencia/migrar.php --admin=USUARIO_EXISTENTE [--edicion=ed3] [--aplicar]');
    $owner=cp_query('SELECT usuario,nombre,clave_hash FROM admin_usuarios WHERE usuario=? AND activo=1',[$admin])->fetch();
    if (!$owner) throw new RuntimeException('Administrador existente no encontrado.');
    require_once __DIR__.'/../capitanes/config.php';
    require_once __DIR__.'/../capitanes/codigos-lib.php';
    if (!defined('SEMILLA_CAPITANES') || strlen((string)SEMILLA_CAPITANES)<16) throw new RuntimeException('Configura la semilla existente de capitanes antes de migrar.');
    $S=cp_legacy(); $ed=(string)($op['edicion']??'ed3'); $nombre=null;
    foreach($S['ediciones']??[] as $x) if(($x['id']??'')===$ed) $nombre=$x['nombre'];
    if (!$nombre) throw new RuntimeException('Edición no encontrada.');
    $equipos=array_values(array_filter($S['equipos']??[],static fn($q)=>($q['edicionId']??'')===$ed));
    if (!$equipos) throw new RuntimeException('La edición no tiene equipos.');
    $refs=array_column($equipos,'id');
    if(count(array_unique($refs))!==count($equipos)||count($refs)!==count($equipos)) throw new RuntimeException('Identificadores duplicados o ausentes.');
    echo json_encode(['edicion'=>$ed,'nombre'=>$nombre,'equipos'=>count($equipos),'propietario'=>$admin,'coordinador'=>'Jonathan Piña','aplicar'=>isset($op['aplicar'])],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
    if(!isset($op['aplicar'])) exit;
    cp_db()->beginTransaction();
    $u=cp_query('SELECT * FROM cp_usuarios WHERE usuario=? FOR UPDATE',[$admin])->fetch();
    if($u && $u['rol']!=='administrador') throw new RuntimeException('El usuario ya tiene otro rol.');
    if(!$u) { cp_query("INSERT INTO cp_usuarios(usuario,nombre,clave_hash,rol) VALUES(?,?,?,'administrador')",[$admin,$owner['nombre'],$owner['clave_hash']]); $ownerId=(int)cp_db()->lastInsertId(); }
    else $ownerId=(int)$u['id'];
    $coord=cp_query("SELECT * FROM cp_usuarios WHERE usuario='jonathan.pina' FOR UPDATE")->fetch();
    if($coord && ($coord['rol']!=='coordinador'||!$coord['activo'])) throw new RuntimeException('Cuenta de Jonathan incompatible o inactiva.');
    if(!$coord) {
        $clave=getenv('LC_JONATHAN_CLAVE');
        if(!$clave||strlen($clave)<12) throw new RuntimeException('Define LC_JONATHAN_CLAVE (12 caracteres mínimo) para crear su cuenta.');
        cp_query("INSERT INTO cp_usuarios(usuario,nombre,clave_hash,rol) VALUES('jonathan.pina','Jonathan Piña',?,'coordinador')",[password_hash($clave,PASSWORD_DEFAULT)]);
        $coordId=(int)cp_db()->lastInsertId();
    } else $coordId=(int)$coord['id'];
    $space=cp_query('SELECT * FROM cp_espacios WHERE edicion_ref=? FOR UPDATE',[$ed])->fetch();
    if($space && ((int)$space['propietario_id']!==$ownerId || (int)$space['coordinador_id']!==$coordId)) throw new RuntimeException('La edición está asignada a otra persona. Reasigna explícitamente desde el panel.');
    if(!$space) { cp_query('INSERT INTO cp_espacios(nombre,edicion_ref,propietario_id,coordinador_id) VALUES(?,?,?,?)',[$nombre,$ed,$ownerId,$coordId]); $sid=(int)cp_db()->lastInsertId(); }
    else $sid=(int)$space['id'];
    $cats=[]; foreach($S['categorias']??[] as $c) $cats[$c['id']]=$c['nombre'];
    foreach($equipos as $q) {
        $old=cp_query('SELECT espacio_id FROM cp_equipos WHERE origen_ref=?',[$q['id']])->fetchColumn();
        if($old!==false && (int)$old!==$sid) throw new RuntimeException('Equipo ya asignado a otro espacio: '.$q['id']);
        cp_query('INSERT INTO cp_equipos(espacio_id,origen_ref,nombre,categoria) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),categoria=VALUES(categoria)',[$sid,$q['id'],$q['nombre'],$cats[$q['categoriaId']??'']??'Sin categoría']);
    }
    $accesos=0;
    foreach($equipos as $q) {
        $dest=cp_query('SELECT id,usuario_id FROM cp_equipos WHERE espacio_id=? AND origen_ref=? FOR UPDATE',[$sid,$q['id']])->fetch();
        if($dest['usuario_id']) continue; // Nunca restablecer claves ni reactivar cuentas al repetir.
        $login='equipo-'.substr(hash('sha256',(string)$q['id']),0,40);
        if(cp_query('SELECT id FROM cp_usuarios WHERE usuario=?',[$login])->fetch()) throw new RuntimeException('Usuario de migración ocupado; revisa la asignación del equipo.');
        cp_query("INSERT INTO cp_usuarios(usuario,nombre,clave_hash,rol) VALUES(?,?,?,'equipo')",[$login,$q['nombre'],password_hash(codigoEquipo((string)$q['id']),PASSWORD_DEFAULT)]);
        $uid=(int)cp_db()->lastInsertId();
        cp_query('INSERT INTO cp_codigos_migrados(usuario_id) VALUES(?)',[$uid]);
        cp_query('UPDATE cp_equipos SET usuario_id=? WHERE id=?',[$uid,$dest['id']]);
        $accesos++;
    }
    $cuentas=cp_cuentas(cp_query('SELECT * FROM cp_equipos WHERE espacio_id=?',[$sid])->fetchAll(),$S,cp_query('SELECT * FROM cp_movimientos WHERE espacio_id=?',[$sid])->fetchAll());
    echo json_encode(['accesos_creados'=>$accesos,'cargos'=>array_sum(array_column($cuentas,'cargos')),'pagado'=>array_sum(array_column($cuentas,'pagado')),'saldo'=>array_sum(array_column($cuentas,'saldo'))],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
    cp_audit($ownerId,$sid,'migracion',['edicion'=>$ed,'equipos'=>count($equipos)]);
    cp_db()->commit(); echo "Migración completada. Códigos conservados como hashes; saldos vinculados al histórico sin duplicar cobros.\n";
} catch(Throwable $ex) {
    try { if(cp_db()->inTransaction()) cp_db()->rollBack(); } catch(Throwable $ignorado) {}
    fwrite(STDERR,$ex->getMessage().PHP_EOL); exit(1);
}
