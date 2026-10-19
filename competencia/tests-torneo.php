<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/torneo-lib.php';
function ver(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); echo "ok · $m\n"; }
$eq = [['id'=>1,'nombre'=>'A','categoria'=>'Primera'],['id'=>2,'nombre'=>'B','categoria'=>'Primera'],['id'=>3,'nombre'=>'C','categoria'=>'Primera']];
$p = static fn($id,$l,$v,$f,$gl,$gv,$e='jugado',$t='torneo')=>['id'=>$id,'local_id'=>$l,'visita_id'=>$v,'fecha'=>$f,'hora'=>'10:00:00','cancha'=>'1','estado'=>$e,'tipo'=>$t,'goles_local'=>$gl,'goles_visita'=>$gv];
$ps = [$p(1,1,2,'2026-10-01',2,0),$p(2,2,3,'2026-10-08',1,1),$p(3,3,1,'2026-10-15',0,1),$p(4,1,3,'2026-10-20',5,0,'jugado','amistoso'),$p(5,1,2,'2026-11-01',1,1),$p(6,2,1,'2026-11-08',0,0)];
$info = [['partido_id'=>5,'fase'=>'final','jornada'=>null,'llave'=>1,'penales_local'=>null,'penales_visita'=>null],['partido_id'=>6,'fase'=>'final','jornada'=>null,'llave'=>1,'penales_local'=>3,'penales_visita'=>4]];
$j = static fn($id,$e,$pos='medio')=>['id'=>$id,'equipo_id'=>$e,'nombre'=>"J$id",'numero'=>$id,'posicion'=>$pos,'activo'=>1,'usuario_id'=>null];
$jug = [$j(10,1),$j(11,1,'portero'),$j(20,2),$j(21,2,'portero'),$j(30,3,'portero')];
$ev = [['id'=>1,'partido_id'=>1,'jugador_id'=>10,'tipo'=>'gol','minuto'=>5],['id'=>2,'partido_id'=>1,'jugador_id'=>10,'tipo'=>'gol','minuto'=>9],
       ['id'=>3,'partido_id'=>4,'jugador_id'=>10,'tipo'=>'gol','minuto'=>1],['id'=>4,'partido_id'=>2,'jugador_id'=>20,'tipo'=>'roja','minuto'=>80],
       ['id'=>5,'partido_id'=>3,'jugador_id'=>10,'tipo'=>'autogol','minuto'=>3]];
$votos = [['tipo'=>'jugador','objetivo_id'=>10,'puntaje'=>5],['tipo'=>'jugador','objetivo_id'=>10,'puntaje'=>4],['tipo'=>'equipo','objetivo_id'=>2,'puntaje'=>3]];
$t = cp_torneo_calcular($eq, $ps, $info, $jug, $ev, [], [['id'=>1,'jugador_id'=>30,'partidos'=>3,'motivo'=>'Mesa','desde'=>'2026-10-10']], $votos);
$tab = $t['categorias'][0]['tabla'];
ver($tab[0]['equipo_id']===1 && $tab[0]['pts']===6 && $tab[0]['pj']===2, 'Tabla: amistosos y eliminatoria no cuentan');
ver($t['goleo'][0]['goles']===2, 'Goleo: no cuenta amistoso ni autogol');
$s = array_column($t['suspendidos'], 'pendientes', 'jugador_id');
ver(!isset($s[20]), 'Roja cumplida en el siguiente partido del equipo');
ver(($s[30] ?? 0)===2, 'Sanción manual descuenta partidos jugados después');
$ll = $t['llaves'][0]['llaves'][0];
ver($t['llaves'][0]['fase']==='final' && $ll['global_a']===1 && $ll['global_b']===1 && $ll['ganador']===1, 'Llave ida y vuelta definida por penales');
ver($t['porteros'][0]['jugador_id']===11 && $t['porteros'][0]['gc']===1 && $t['porteros'][0]['invictas']===3, 'Portero único del equipo como respaldo');
ver($t['ranking_jugadores'][0]['promedio']===4.5 && $t['ranking_equipos'][0]['votos']===1, 'Calificaciones promedian');
echo "Todas las pruebas del torneo pasaron.\n";
