<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/lib.php';
function comprobar(bool $v,string $mensaje): void { if(!$v)throw new RuntimeException($mensaje); }
$space=['propietario_id'=>1,'coordinador_id'=>3];
comprobar(cp_puede(['id'=>1,'rol'=>'administrador'],$space),'Dueño administra');
comprobar(!cp_puede(['id'=>2,'rol'=>'administrador'],$space),'Otro administrador sólo consulta');
comprobar(cp_puede(['id'=>3,'rol'=>'coordinador'],$space),'Coordinador asignado');
comprobar(!cp_puede(['id'=>3,'rol'=>'coordinador'],$space,true),'Coordinador no reasigna');
comprobar(!cp_puede(['id'=>4,'rol'=>'coordinador'],$space),'Coordinador ajeno');
comprobar(!cp_puede(['id'=>1,'rol'=>'equipo'],$space),'Equipo no administra');
$eq=[['id'=>10,'nombre'=>'A','origen_ref'=>'a']];
$legacy=['movs'=>[['equipoId'=>'a','importe'=>100.10,'recibido'=>20.05],['equipoId'=>'b','importe'=>999,'recibido'=>999],['equipoId'=>'a','importe'=>50,'recibido'=>0,'ocultoCapitan'=>true]]];
$ms=[['equipo_id'=>10,'tipo'=>'pago','monto'=>'10.05','fecha'=>'2026-10-15','concepto'=>'Abono']];
$cuenta=cp_cuentas($eq,$legacy,$ms,true)[0];
comprobar($cuenta['saldo']===70.0,'Centavos, aislamiento de equipos, ocultos');
comprobar($cuenta['pagado']===30.10,'Pago nuevo más histórico');
comprobar(count($cuenta['movimientos'])===2,'No filtrar cargos ajenos u ocultos');
comprobar(cp_cuentas($eq,$legacy,$ms,false)[0]['saldo']===120.0,'Operador ve cargos ocultos');
comprobar(cp_fecha('2024-02-29')&&!cp_fecha('2026-02-29')&&!cp_fecha('2026-13-01'),'Fechas reales');
comprobar(!cp_visible(['auto'=>['reserva'=>1],'recibido'=>0]),'Ticket automático sin cobro');
comprobar(cp_visible(['auto'=>['reserva'=>1,'ocultoHastaCobro'=>true],'ocultoCapitan'=>true,'recibido'=>1]),'Ticket automático cobrado');
comprobar(cp_normalizar(' Águilas  Azules!')==='aguilasazules','Nombres comparables');
$liga=['duracion'=>50,'sedes'=>[['nombre'=>'Central','canchas'=>[['id'=>'c1','nombre'=>'Cancha 1']]]]];
$c=cp_convertir_partido(['fecha'=>'2026-09-05','hora'=>'08:00','canchaId'=>'c1','estado'=>'finalizado','gl'=>2,'gv'=>0],$liga);
comprobar($c['estado']==='jugado'&&$c['goles_local']===2&&$c['cancha']==='Central · Cancha 1'&&$c['duracion']===50,'Partido finalizado');
comprobar(isset(cp_convertir_partido(['fecha'=>'2026-09-05','hora'=>'08:00','estado'=>'finalizado','gl'=>''],$liga)['error']),'Finalizado sin marcador');
comprobar(cp_convertir_partido(['fecha'=>'2026-09-05','hora'=>'08:00','estado'=>'suspendido','gl'=>1,'gv'=>1],$liga)['goles_local']===null,'Suspendido sin marcador');
comprobar(isset(cp_convertir_partido(['fecha'=>'','hora'=>'','estado'=>'programado'],$liga)['error']),'Sin fecha');
echo "Comprobaciones correctas.\n";
