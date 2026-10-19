<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/lib.php';
/*
 Importa los partidos del Coordinador anterior (coordinador/datos/real.json) a cp_partidos.
 1. --generar   escribe la tabla de equivalencias de equipos (CSV) para revisarla.
 2. (sin flags) valida la tabla y muestra el plan, sin escribir.
 3. --aplicar   inserta en una transacción. Repetirlo no duplica partidos.
 Los equipos del Coordinador anterior tienen IDs propios: nunca se vinculan por nombre sin revisión.
*/
$op=getopt('', ['edicion:','origen:','mapa:','generar','reemplazar','aplicar','permitir-cruces']);
$ed=(string)($op['edicion']??'ed3');
$origen=(string)($op['origen']??__DIR__.'/../coordinador/datos/real.json');
$mapa=(string)($op['mapa']??__DIR__.'/../coordinador/datos/equivalencias-partidos-'.preg_replace('/[^A-Za-z0-9_-]/','',$ed).'.csv');
const OMITIR='OMITIR';

function leer_origen(string $f): array {
    $j=is_file($f)?json_decode((string)file_get_contents($f),true):null;
    if(!is_array($j['estado']??null)) throw new RuntimeException('No se pudo leer el Coordinador anterior: '.$f);
    return $j['estado'];
}
function leer_mapa(string $f): array {
    $fp=@fopen($f,'rb'); if(!$fp) throw new RuntimeException("No existe la tabla de equivalencias. Genérala con --generar: $f");
    $primera=(string)fgets($fp); rewind($fp);
    $sep=substr_count($primera,';')>substr_count($primera,',')?';':','; // Excel en español puede guardar con ;
    $cab=fgetcsv($fp,0,$sep,'"','\\');
    if(!$cab) throw new RuntimeException('Tabla de equivalencias vacía.');
    $cab[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$cab[0]);
    $iId=array_search('coordinador_id',$cab,true); $iRef=array_search('origen_ref',$cab,true);
    if($iId===false||$iRef===false) throw new RuntimeException('La tabla debe conservar las columnas coordinador_id y origen_ref.');
    $m=[]; $n=1;
    while(($r=fgetcsv($fp,0,$sep,'"','\\'))!==false) {
        $n++; if($r===[null]) continue;
        $id=trim((string)($r[$iId]??'')); $ref=trim((string)($r[$iRef]??''));
        if($id==='') continue;
        if(isset($m[$id])) throw new RuntimeException("Equipo repetido en la línea $n: $id");
        $m[$id]=$ref;
    }
    fclose($fp); return $m;
}

try {
    $esp=cp_query('SELECT * FROM cp_espacios WHERE edicion_ref=?',[$ed])->fetch();
    if(!$esp) throw new RuntimeException("No hay espacio para la edición $ed. Ejecuta antes migrar.php.");
    $sid=(int)$esp['id'];
    $nuevos=cp_query('SELECT id,origen_ref,nombre,categoria FROM cp_equipos WHERE espacio_id=?',[$sid])->fetchAll();
    $porRef=array_column($nuevos,null,'origen_ref');
    $S=leer_origen($origen);
    $viejos=array_column($S['equipos']??[],null,'id');
    $torneos=array_column($S['torneos']??[],'nombre','id');
    $partidos=array_values(array_filter($S['partidos']??[],'is_array'));
    $usos=[];
    foreach($partidos as $p) foreach([$p['localId']??'',$p['visitaId']??''] as $x) if($x!=='') $usos[$x]=($usos[$x]??0)+1;

    if(isset($op['generar'])) {
        if(is_file($mapa)&&!isset($op['reemplazar'])) throw new RuntimeException("Ya existe $mapa. Revísalo o usa --reemplazar.");
        $porNombre=[]; foreach($nuevos as $q) $porNombre[cp_normalizar($q['nombre'])][]=$q;
        $fp=fopen($mapa,'wb'); if(!$fp) throw new RuntimeException("No se pudo escribir $mapa");
        fwrite($fp,"\xEF\xBB\xBF");
        fputcsv($fp,['coordinador_id','coordinador_nombre','partidos','origen_ref','nombre_en_competencia','categoria','sugerencia'],',','"','\\');
        $ids=array_unique(array_merge(array_keys($viejos),array_keys($usos)));
        $sug=0; $pend=0;
        foreach($ids as $id) {
            $nom=(string)($viejos[$id]['nombre']??'(no está en la lista de equipos)');
            $c=$porNombre[cp_normalizar($nom)]??[];
            if(count($c)===1) { $q=$c[0]; $sug++; $fila=[$q['origen_ref'],$q['nombre'],$q['categoria'],'mismo nombre: confirmar']; }
            else { $pend++; $fila=['','','',count($c)>1?'varios equipos con ese nombre: elegir':'sin coincidencia: escribir origen_ref u OMITIR']; }
            fputcsv($fp,array_merge([$id,$nom,$usos[$id]??0],$fila),',','"','\\');
        }
        fclose($fp);
        // Lista de referencia para llenar origen_ref a mano.
        $ref=preg_replace('/\.csv$/','',$mapa).'-equipos-competencia.csv';
        $fp=fopen($ref,'wb'); fwrite($fp,"\xEF\xBB\xBF"); fputcsv($fp,['origen_ref','nombre','categoria'],',','"','\\');
        foreach($nuevos as $q) fputcsv($fp,[$q['origen_ref'],$q['nombre'],$q['categoria']],',','"','\\');
        fclose($fp);
        echo json_encode(['tabla'=>$mapa,'referencia'=>$ref,'equipos_coordinador'=>count($ids),'sugeridos_por_nombre'=>$sug,'por_completar'=>$pend],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
        echo "Revisa TODAS las filas (también las sugeridas). Escribe el origen_ref correcto o OMITIR; luego ejecuta sin --generar.\n";
        exit;
    }

    // Validación completa de la tabla antes de tocar la base.
    $m=leer_mapa($mapa); $errores=[]; $destino=[];
    foreach(array_keys($usos) as $id) if(!array_key_exists($id,$m)) $errores[]="Falta en la tabla el equipo $id (".($viejos[$id]['nombre']??'?').')';
    foreach($m as $id=>$ref) {
        if($ref==='') { if(($usos[$id]??0)>0) $errores[]="Sin origen_ref: $id (".($viejos[$id]['nombre']??'?').'). Escribe uno u OMITIR.'; continue; }
        if($ref===OMITIR) continue;
        if(!isset($porRef[$ref])) { $errores[]="origen_ref $ref ($id) no pertenece a la edición $ed."; continue; }
        if(isset($destino[$ref])) $errores[]="Dos equipos apuntan a $ref: {$destino[$ref]} y $id.";
        $destino[$ref]=$id;
    }
    $ya=array_flip(cp_query('SELECT origen_ref FROM cp_partidos_importados WHERE espacio_id=?',[$sid])->fetchAll(PDO::FETCH_COLUMN));
    $plan=[]; $omitidos=[]; $resumen=['jugado'=>0,'programado'=>0,'cancelado'=>0]; $porTorneo=[]; $yaImportados=0; $suspendidos=0;
    foreach($partidos as $p) {
        $pid=(string)($p['id']??''); $l=(string)($p['localId']??''); $v=(string)($p['visitaId']??'');
        $desc=$pid.' '.($viejos[$l]['nombre']??$l).' vs '.($viejos[$v]['nombre']??$v).' '.($p['fecha']??'');
        if($pid===''||strlen($pid)>64) { $errores[]="Partido sin identificador válido: $desc"; continue; }
        if(isset($ya[$pid])) { $yaImportados++; continue; }
        if(($m[$l]??'')===OMITIR||($m[$v]??'')===OMITIR) { $omitidos[]="$desc: equipo omitido"; continue; }
        if(!isset($porRef[$m[$l]??''],$porRef[$m[$v]??''])) continue; // ya reportado arriba
        if($m[$l]===$m[$v]) { $errores[]="Local y visita son el mismo equipo: $desc"; continue; }
        $c=cp_convertir_partido($p,$S['liga']??[]);
        if(isset($c['error'])) { $omitidos[]="$desc: {$c['error']}"; continue; }
        if($c['estado_origen']==='suspendido') $suspendidos++;
        $c+=['origen_ref'=>$pid,'local_id'=>(int)$porRef[$m[$l]]['id'],'visita_id'=>(int)$porRef[$m[$v]]['id'],'desc'=>$desc];
        $plan[]=$c; $resumen[$c['estado']]++;
        $t=(string)($torneos[$p['torneoId']??'']??'Sin torneo'); $porTorneo[$t]=($porTorneo[$t]??0)+1;
    }
    // Cruces con partidos ya existentes en el sistema nuevo (los importados entre sí son históricos).
    $cruces=[];
    foreach($plan as $c) {
        if($c['estado']!=='programado') continue;
        $ini=$c['fecha'].' '.$c['hora'].':00'; $fin=(new DateTimeImmutable($ini))->modify('+'.$c['duracion'].' minutes')->format('Y-m-d H:i:s');
        $x=cp_query("SELECT id FROM cp_partidos WHERE estado<>'cancelado' AND TIMESTAMP(fecha,hora)<? AND DATE_ADD(TIMESTAMP(fecha,hora),INTERVAL duracion MINUTE)>? AND (cancha=? OR (espacio_id=? AND (local_id IN (?,?) OR visita_id IN (?,?)))) AND id NOT IN (SELECT partido_id FROM cp_partidos_importados) LIMIT 1",
            [$fin,$ini,$c['cancha'],$sid,$c['local_id'],$c['visita_id'],$c['local_id'],$c['visita_id']])->fetchColumn();
        if($x!==false) $cruces[]=$c['desc']." choca con el partido #$x";
    }
    echo json_encode(['espacio'=>$esp['nombre'],'origen'=>$origen,'tabla'=>$mapa,'partidos_en_origen'=>count($partidos),'a_importar'=>count($plan),
        'por_estado'=>$resumen,'suspendidos_quedan_programados'=>$suspendidos,'por_torneo'=>$porTorneo,'ya_importados'=>$yaImportados,
        'omitidos'=>$omitidos,'cruces'=>$cruces,'errores'=>$errores,'aplicar'=>isset($op['aplicar'])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
    if($errores) throw new RuntimeException('Corrige la tabla de equivalencias; no se escribió nada.');
    if(!isset($op['aplicar'])) { echo "Vista previa: no se escribió nada. Agrega --aplicar para importar.\n"; exit; }
    if($cruces&&!isset($op['permitir-cruces'])) throw new RuntimeException('Hay cruces con partidos programados en el sistema nuevo. Resuélvelos o usa --permitir-cruces.');

    cp_db()->beginTransaction();
    cp_query('SELECT id FROM cp_espacios ORDER BY id FOR UPDATE')->fetchAll(); // misma serialización que api.php
    $n=0;
    foreach($plan as $c) {
        if(cp_query('SELECT 1 FROM cp_partidos_importados WHERE espacio_id=? AND origen_ref=?',[$sid,$c['origen_ref']])->fetchColumn()) continue;
        cp_query("INSERT INTO cp_partidos(espacio_id,local_id,visita_id,fecha,hora,cancha,duracion,tipo,estado,goles_local,goles_visita) VALUES(?,?,?,?,?,?,?,'torneo',?,?,?)",
            [$sid,$c['local_id'],$c['visita_id'],$c['fecha'],$c['hora'],$c['cancha'],$c['duracion'],$c['estado'],$c['goles_local'],$c['goles_visita']]);
        cp_query('INSERT INTO cp_partidos_importados(espacio_id,origen_ref,partido_id) VALUES(?,?,?)',[$sid,$c['origen_ref'],(int)cp_db()->lastInsertId()]);
        $n++;
    }
    cp_audit((int)$esp['propietario_id'],$sid,'importar_partidos',['origen'=>basename($origen),'importados'=>$n,'omitidos'=>count($omitidos)]);
    cp_db()->commit();
    echo "Importación completada: $n partidos.\n";
} catch(Throwable $ex) {
    try { if(cp_db()->inTransaction()) cp_db()->rollBack(); } catch(Throwable $ignorado) {}
    fwrite(STDERR,$ex->getMessage().PHP_EOL); exit(1);
}
