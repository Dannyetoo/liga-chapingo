<?php
/* =====================================================================
   Torneo y estadísticas (estilo Copa Fácil) — cálculos puros, sin base de datos.
   Recibe filas ya filtradas por espacio y devuelve tabla general, goleo,
   mejor portero, disciplina, suspendidos, calendario, llaves, plantillas y
   calificaciones. Así se prueba con tests.php sin MySQL.
   ===================================================================== */
declare(strict_types=1);

const CP_PUNTOS_GANADO = 3, CP_PUNTOS_EMPATE = 1;
const CP_SUSPENSION_ROJA = 1;        // partidos por tarjeta roja directa
const CP_AMARILLAS_POR_SUSPENSION = 5; // cada 5 amarillas acumuladas = 1 partido
const CP_FASES = ['regular'=>0,'dieciseisavos'=>1,'octavos'=>2,'cuartos'=>3,'semifinal'=>4,'tercer_lugar'=>5,'final'=>6];
const CP_POSICIONES = ['portero','defensa','medio','delantero'];

function cp_nombre_limpio(string $s): string {
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** Momento comparable de un partido. */
function cp_momento(array $p): string { return $p['fecha'].' '.substr((string)$p['hora'], 0, 5); }

function cp_torneo_calcular(array $equipos, array $partidos, array $info, array $jugadores, array $eventos, array $porteros, array $sanciones, array $votos): array {
    $eq = []; foreach ($equipos as $e) $eq[(int)$e['id']] = $e;
    $jug = []; foreach ($jugadores as $j) $jug[(int)$j['id']] = $j;
    $inf = []; foreach ($info as $i) $inf[(int)$i['partido_id']] = $i;

    /* --- partidos de torneo con su fase/jornada --- */
    $ps = [];
    foreach ($partidos as $p) {
        if (($p['tipo'] ?? 'torneo') !== 'torneo') continue;
        $i = $inf[(int)$p['id']] ?? [];
        $ps[(int)$p['id']] = [
            'id'=>(int)$p['id'],'local_id'=>(int)$p['local_id'],'visita_id'=>(int)$p['visita_id'],
            'fecha'=>$p['fecha'],'hora'=>substr((string)$p['hora'],0,5),'cancha'=>$p['cancha'],'estado'=>$p['estado'],
            'goles_local'=>$p['goles_local']===null?null:(int)$p['goles_local'],
            'goles_visita'=>$p['goles_visita']===null?null:(int)$p['goles_visita'],
            'fase'=>$i['fase'] ?? 'regular','jornada'=>isset($i['jornada'])&&$i['jornada']!==null?(int)$i['jornada']:null,
            'llave'=>isset($i['llave'])&&$i['llave']!==null?(int)$i['llave']:null,
            'penales_local'=>isset($i['penales_local'])&&$i['penales_local']!==null?(int)$i['penales_local']:null,
            'penales_visita'=>isset($i['penales_visita'])&&$i['penales_visita']!==null?(int)$i['penales_visita']:null,
            'eventos'=>[],
        ];
    }
    uasort($ps, static fn($a,$b)=>strcmp(cp_momento($a), cp_momento($b)) ?: $a['id']<=>$b['id']);
    $jugados = array_filter($ps, static fn($p)=>$p['estado']==='jugado');

    /* --- tabla general por categoría (sólo fase regular) --- */
    $fila = [];
    foreach ($eq as $id=>$e) $fila[$id] = ['equipo_id'=>$id,'nombre'=>$e['nombre'],'pj'=>0,'g'=>0,'e'=>0,'p'=>0,'gf'=>0,'gc'=>0,'dg'=>0,'pts'=>0,'forma'=>[]];
    foreach ($jugados as $p) {
        if ($p['fase'] !== 'regular') continue;
        foreach ([[$p['local_id'],$p['goles_local'],$p['goles_visita']],[$p['visita_id'],$p['goles_visita'],$p['goles_local']]] as [$t,$f,$c]) {
            if (!isset($fila[$t])) continue;
            $r = &$fila[$t]; $r['pj']++; $r['gf'] += $f; $r['gc'] += $c;
            if ($f > $c) { $r['g']++; $r['pts'] += CP_PUNTOS_GANADO; $r['forma'][] = 'G'; }
            elseif ($f === $c) { $r['e']++; $r['pts'] += CP_PUNTOS_EMPATE; $r['forma'][] = 'E'; }
            else { $r['p']++; $r['forma'][] = 'P'; }
            unset($r);
        }
    }
    $cats = [];
    foreach ($fila as $id=>$r) {
        $r['dg'] = $r['gf'] - $r['gc']; $r['forma'] = array_slice($r['forma'], -5);
        $cats[(string)($eq[$id]['categoria'] ?? '') ?: 'General'][] = $r;
    }
    ksort($cats, SORT_NATURAL | SORT_FLAG_CASE);
    $categorias = [];
    foreach ($cats as $nombre=>$rows) {
        usort($rows, static fn($a,$b)=>[$b['pts'],$b['dg'],$b['gf']] <=> [$a['pts'],$a['dg'],$a['gf']] ?: strcmp($a['nombre'],$b['nombre']));
        foreach ($rows as $k=>&$r) $r['pos'] = $k + 1; unset($r);
        $categorias[] = ['nombre'=>$nombre,'tabla'=>$rows];
    }

    /* --- eventos por jugador (sólo partidos de torneo jugados) --- */
    $st = [];
    foreach ($jug as $id=>$j) $st[$id] = ['goles'=>0,'autogoles'=>0,'amarillas'=>0,'rojas'=>0];
    $tarjetas = [];
    foreach ($eventos as $ev) {
        $pid = (int)$ev['partido_id']; $jid = (int)$ev['jugador_id'];
        if (!isset($jugados[$pid], $st[$jid])) continue;
        $ps[$pid]['eventos'][] = ['id'=>(int)$ev['id'],'jugador_id'=>$jid,'tipo'=>$ev['tipo'],'minuto'=>$ev['minuto']===null?null:(int)$ev['minuto']];
        $k = ['gol'=>'goles','autogol'=>'autogoles','amarilla'=>'amarillas','roja'=>'rojas'][$ev['tipo']] ?? null;
        if ($k) $st[$jid][$k]++;
        if ($ev['tipo']==='amarilla' || $ev['tipo']==='roja') $tarjetas[] = ['j'=>$jid,'tipo'=>$ev['tipo'],'t'=>cp_momento($ps[$pid]),'pid'=>$pid];
    }
    $pub = static fn(int $id)=>['jugador_id'=>$id,'nombre'=>$jug[$id]['nombre'],'equipo_id'=>(int)$jug[$id]['equipo_id']];

    $goleo = [];
    foreach ($st as $id=>$s) if ($s['goles'] > 0) $goleo[] = $pub($id) + ['goles'=>$s['goles']];
    usort($goleo, static fn($a,$b)=>$b['goles']<=>$a['goles'] ?: strcmp($a['nombre'],$b['nombre']));

    $disciplina = [];
    foreach ($st as $id=>$s) if ($s['amarillas'] + $s['rojas'] > 0) $disciplina[] = $pub($id) + ['amarillas'=>$s['amarillas'],'rojas'=>$s['rojas']];
    usort($disciplina, static fn($a,$b)=>[$b['rojas'],$b['amarillas']] <=> [$a['rojas'],$a['amarillas']] ?: strcmp($a['nombre'],$b['nombre']));

    /* --- mejor portero: goles recibidos por partido --- */
    $pt = []; foreach ($porteros as $x) $pt[(int)$x['partido_id'].':'.(int)$x['equipo_id']] = (int)$x['jugador_id'];
    $unico = [];
    foreach ($jug as $id=>$j) if ($j['posicion']==='portero' && (int)$j['activo']===1) $unico[(int)$j['equipo_id']][] = $id;
    $gk = [];
    foreach ($jugados as $p) {
        foreach ([[$p['local_id'],$p['goles_visita']],[$p['visita_id'],$p['goles_local']]] as [$t,$gc]) {
            $id = $pt[$p['id'].':'.$t] ?? ((count($unico[$t] ?? []) === 1) ? $unico[$t][0] : null);
            if ($id === null || !isset($jug[$id])) continue;
            $gk[$id] ??= ['pj'=>0,'gc'=>0,'invictas'=>0];
            $gk[$id]['pj']++; $gk[$id]['gc'] += $gc; if ($gc === 0) $gk[$id]['invictas']++;
        }
    }
    $maxPj = $gk ? max(array_column($gk, 'pj')) : 0; $minimo = max(1, (int)ceil($maxPj / 2));
    $listaPorteros = [];
    foreach ($gk as $id=>$g) $listaPorteros[] = $pub($id) + $g + ['promedio'=>round($g['gc'] / $g['pj'], 2),'califica'=>$g['pj'] >= $minimo];
    usort($listaPorteros, static fn($a,$b)=>[$b['califica'],$a['promedio'],$b['invictas'],$b['pj']] <=> [$a['califica'],$b['promedio'],$a['invictas'],$a['pj']] ?: strcmp($a['nombre'],$b['nombre']));

    /* --- suspendidos: rojas, acumulación de amarillas y sanciones manuales --- */
    $cola = [];
    $amar = [];
    foreach ($tarjetas as $t) {
        if ($t['tipo'] === 'roja') { $cola[$t['j']][] = ['desde'=>$t['t'],'partidos'=>CP_SUSPENSION_ROJA,'motivo'=>'Tarjeta roja']; continue; }
        $amar[$t['j']] = ($amar[$t['j']] ?? 0) + 1;
        if ($amar[$t['j']] % CP_AMARILLAS_POR_SUSPENSION === 0) $cola[$t['j']][] = ['desde'=>$t['t'],'partidos'=>1,'motivo'=>CP_AMARILLAS_POR_SUSPENSION.' amarillas acumuladas'];
    }
    foreach ($sanciones as $s) {
        $jid = (int)$s['jugador_id']; if (!isset($jug[$jid])) continue;
        $cola[$jid][] = ['desde'=>$s['desde'].' 23:59','partidos'=>(int)$s['partidos'],'motivo'=>$s['motivo'],'sancion_id'=>(int)$s['id']];
    }
    $suspendidos = [];
    foreach ($cola as $jid=>$lista) {
        usort($lista, static fn($a,$b)=>strcmp($a['desde'],$b['desde']));
        $team = (int)$jug[$jid]['equipo_id'];
        $tiempos = [];
        foreach ($jugados as $p) if ($p['local_id']===$team || $p['visita_id']===$team) $tiempos[$p['id']] = cp_momento($p);
        $usados = []; $pend = 0; $motivos = [];
        foreach ($lista as $s) {
            $cumplidos = 0;
            foreach ($tiempos as $pid=>$t) {
                if ($cumplidos >= $s['partidos']) break;
                if ($t > $s['desde'] && !isset($usados[$pid])) { $usados[$pid] = true; $cumplidos++; }
            }
            if ($s['partidos'] - $cumplidos > 0) { $pend += $s['partidos'] - $cumplidos; $motivos[] = $s['motivo']; }
        }
        if ($pend > 0) $suspendidos[] = $pub($jid) + ['pendientes'=>$pend,'motivos'=>$motivos];
    }
    usort($suspendidos, static fn($a,$b)=>$b['pendientes']<=>$a['pendientes'] ?: strcmp($a['nombre'],$b['nombre']));
    $susp = array_column($suspendidos, 'pendientes', 'jugador_id');

    /* --- llaves de eliminación --- */
    $grupos = [];
    foreach ($ps as $p) {
        if ($p['fase'] === 'regular') continue;
        $grupos[$p['fase']][$p['llave'] !== null ? 'l'.$p['llave'] : 'p'.$p['id']][] = $p;
    }
    uksort($grupos, static fn($a,$b)=>CP_FASES[$a] <=> CP_FASES[$b]);
    $llaves = [];
    foreach ($grupos as $fase=>$porLlave) {
        $items = [];
        foreach ($porLlave as $clave=>$lista) {
            $a = $lista[0]['local_id']; $b = $lista[0]['visita_id'];
            $ga = 0; $gb = 0; $todos = true; $ult = end($lista); $pa = null; $pb = null;
            foreach ($lista as $p) {
                if ($p['estado'] !== 'jugado') { if ($p['estado'] !== 'cancelado') $todos = false; continue; }
                if ($p['local_id'] === $a) { $ga += $p['goles_local']; $gb += $p['goles_visita']; }
                else { $ga += $p['goles_visita']; $gb += $p['goles_local']; }
            }
            if ($ult['penales_local'] !== null && $ult['penales_visita'] !== null) {
                [$pa, $pb] = $ult['local_id'] === $a ? [$ult['penales_local'], $ult['penales_visita']] : [$ult['penales_visita'], $ult['penales_local']];
            }
            $ganador = null;
            if ($todos && array_filter($lista, static fn($p)=>$p['estado']==='jugado')) {
                if ($ga !== $gb) $ganador = $ga > $gb ? $a : $b;
                elseif ($pa !== null && $pa !== $pb) $ganador = $pa > $pb ? $a : $b;
            }
            $items[] = ['llave'=>$lista[0]['llave'],'a'=>$a,'b'=>$b,'global_a'=>$ga,'global_b'=>$gb,'penales_a'=>$pa,'penales_b'=>$pb,'ganador'=>$ganador,'partidos'=>array_column($lista,'id'),'clave'=>$clave];
        }
        usort($items, static fn($x,$y)=>[$x['llave']===null, $x['llave'] ?? 0, $x['clave']] <=> [$y['llave']===null, $y['llave'] ?? 0, $y['clave']]);
        foreach ($items as &$it) unset($it['clave']); unset($it);
        $llaves[] = ['fase'=>$fase,'llaves'=>$items];
    }

    /* --- calificaciones --- */
    $cal = ['jugador'=>[],'equipo'=>[]];
    foreach ($votos as $v) {
        $c = &$cal[$v['tipo']][(int)$v['objetivo_id']]; $c ??= ['suma'=>0,'n'=>0];
        $c['suma'] += (int)$v['puntaje']; $c['n']++; unset($c);
    }
    $prom = static fn(?array $c)=>$c ? ['promedio'=>round($c['suma'] / $c['n'], 2),'votos'=>$c['n']] : ['promedio'=>null,'votos'=>0];

    /* --- perfiles y plantillas --- */
    $perfiles = [];
    $orden = array_flip(CP_POSICIONES);
    foreach ($eq as $id=>$e) {
        $plantilla = [];
        foreach ($jug as $jid=>$j) {
            if ((int)$j['equipo_id'] !== $id || (int)$j['activo'] !== 1) continue;
            $plantilla[] = ['id'=>$jid,'nombre'=>$j['nombre'],'numero'=>$j['numero']===null?null:(int)$j['numero'],'posicion'=>$j['posicion'],
                'cuenta'=>!empty($j['usuario_id'])] + $st[$jid] + ['suspendido'=>$susp[$jid] ?? 0,'calificacion'=>$prom($cal['jugador'][$jid] ?? null)];
        }
        usort($plantilla, static fn($a,$b)=>[$orden[$a['posicion']], $a['numero'] ?? 999, $a['nombre']] <=> [$orden[$b['posicion']], $b['numero'] ?? 999, $b['nombre']]);
        $perfiles[] = ['id'=>$id,'nombre'=>$e['nombre'],'categoria'=>$e['categoria'] ?? '','calificacion'=>$prom($cal['equipo'][$id] ?? null),'plantilla'=>$plantilla];
    }
    usort($perfiles, static fn($a,$b)=>strcmp($a['nombre'],$b['nombre']));

    $rankJug = [];
    foreach ($cal['jugador'] as $jid=>$c) if (isset($jug[$jid])) $rankJug[] = $pub($jid) + $prom($c);
    usort($rankJug, static fn($a,$b)=>[$b['promedio'],$b['votos']] <=> [$a['promedio'],$a['votos']]);
    $rankEq = [];
    foreach ($cal['equipo'] as $eid=>$c) if (isset($eq[$eid])) $rankEq[] = ['equipo_id'=>$eid,'nombre'=>$eq[$eid]['nombre']] + $prom($c);
    usort($rankEq, static fn($a,$b)=>[$b['promedio'],$b['votos']] <=> [$a['promedio'],$a['votos']]);

    return [
        'categorias'=>$categorias,'goleo'=>$goleo,'porteros'=>$listaPorteros,'porteros_minimo'=>$minimo,
        'disciplina'=>$disciplina,'suspendidos'=>$suspendidos,'partidos'=>array_values($ps),'llaves'=>$llaves,
        'equipos'=>$perfiles,'ranking_jugadores'=>$rankJug,'ranking_equipos'=>$rankEq,
        'reglas'=>['roja'=>CP_SUSPENSION_ROJA,'amarillas'=>CP_AMARILLAS_POR_SUSPENSION,'ganado'=>CP_PUNTOS_GANADO,'empate'=>CP_PUNTOS_EMPATE],
    ];
}
