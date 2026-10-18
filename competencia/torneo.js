'use strict';
/* Torneo y estadísticas — vista estilo Copa Fácil sobre api.php?a=torneo */
const $=id=>document.getElementById(id);
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let csrf='',usuario=null,espacios=[],T=null,tab='tabla',cat='',perfil=null,eqById={},jugById={};
const FASES={regular:'Fase regular',dieciseisavos:'Dieciseisavos',octavos:'Octavos de final',cuartos:'Cuartos de final',semifinal:'Semifinales',tercer_lugar:'Tercer lugar',final:'Final'};
const POS={portero:'Portero',defensa:'Defensa',medio:'Medio',delantero:'Delantero'};
const TABS=[['tabla','Tabla'],['goleo','Goleo'],['porteros','Porteros'],['disciplina','Disciplina'],['calendario','Calendario'],['llaves','Llaves'],['equipos','Equipos'],['calificaciones','Calificaciones']];

function aviso(x,ok=false){const a=$('t-aviso');a.textContent=x||'';a.classList.toggle('ok',ok);}
async function api(a,body,params={}){
  const r=await fetch('api.php?'+new URLSearchParams({a,...params}),{credentials:'same-origin',method:body?'POST':'GET',
    headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined});
  const j=await r.json().catch(()=>({ok:false,error:'Respuesta inválida del servidor.'}));
  if(!j.ok)throw Error(j.error||'No se pudo completar.');return j;
}
const sid=()=>Number($('t-espacio').value);
const ls={get(k){try{return localStorage.getItem(k);}catch(e){return null;}},set(k,v){try{localStorage.setItem(k,v);}catch(e){}}};

/* ---------- piezas visuales ---------- */
function hash(s){let h=0;for(const c of String(s))h=(h*31+c.charCodeAt(0))>>>0;return h;}
function iniciales(n){return String(n||'?').split(/\s+/).filter(Boolean).slice(0,2).map(w=>w[0]).join('').toUpperCase();}
const escudo=(id)=>{const n=eqById[id]?.nombre||'?';return '<span class="esc c'+(hash(n)%8)+'" aria-hidden="true">'+esc(iniciales(n))+'</span>';};
const eqBtn=id=>'<button type="button" class="eq" data-perfil="'+id+'">'+escudo(id)+esc(eqById[id]?.nombre||'Equipo')+'</button>';
const nomJ=id=>jugById[id]?.nombre||'Jugador';
const tabla=(cab,filas,vacio='Sin datos todavía.')=>filas.length?'<div class="tabla"><table><thead><tr>'+cab.map(h=>'<th'+(h[1]?' class="'+h[1]+'"':'')+'>'+esc(h[0])+'</th>').join('')+'</tr></thead><tbody>'+filas.join('')+'</tbody></table></div>':'<p class="vacio">'+esc(vacio)+'</p>';
const carta=(titulo,html,extra='')=>'<section class="carta"><div class="carta-t"><span>'+titulo+'</span>'+extra+'</div>'+html+'</section>';
const enCat=id=>!cat||eqById[id]?.categoria===cat;
function estrellas(tipo,id,mio,activo){
  let h='<span class="estrellas" role="group" aria-label="Calificar">';
  for(let i=1;i<=5;i++)h+='<button type="button" class="'+(i<=(mio||0)?'on':'')+'" '+(activo?'data-votar="'+tipo+':'+id+':'+i+'"':'disabled')+' aria-label="'+i+' de 5">★</button>';
  return h+'</span>';
}
const prom=c=>c&&c.votos?'<span class="prom">'+Number(c.promedio).toFixed(1)+' <small>('+c.votos+')</small></span>':'<span class="prom"><small>Sin votos</small></span>';
const fecha=f=>{const d=new Date(f+'T12:00:00');return isNaN(d)?f:d.toLocaleDateString('es-MX',{weekday:'short',day:'numeric',month:'short'});};
function puedeVotarJ(id){return T.yo.ficha_id&&Number(id)!==Number(T.yo.ficha_id);}
function puedeVotarE(id){return T.yo.ficha_id&&Number(id)!==Number(T.yo.equipo_id);}

/* ---------- secciones ---------- */
function vTabla(){
  const cs=T.categorias.filter(c=>!cat||c.nombre===cat);
  return cs.map(c=>carta(esc(c.nombre),tabla([['#'],['Equipo','izq'],['PJ'],['G'],['E'],['P'],['GF','oculto-m'],['GC','oculto-m'],['DG'],['PTS'],['Forma','oculto-m']],
    c.tabla.map(r=>'<tr><td class="pos">'+r.pos+'</td><td class="izq">'+eqBtn(r.equipo_id)+'</td><td>'+r.pj+'</td><td>'+r.g+'</td><td>'+r.e+'</td><td>'+r.p+'</td><td class="oculto-m">'+r.gf+'</td><td class="oculto-m">'+r.gc+'</td><td>'+(r.dg>0?'+':'')+r.dg+'</td><td class="pts">'+r.pts+'</td><td class="oculto-m"><span class="forma">'+r.forma.map(f=>'<i class="f-'+f+'">'+f+'</i>').join('')+'</span></td></tr>')))).join('')
    +'<p class="nota">Ganado '+T.reglas.ganado+' pts · empate '+T.reglas.empate+' · desempate: diferencia de goles y goles a favor. Sólo cuenta la fase regular.</p>';
}
function lideres(){
  const g=T.goleo.find(x=>enCat(x.equipo_id)),p=T.porteros.find(x=>x.califica&&enCat(x.equipo_id)),j=T.ranking_jugadores.find(x=>enCat(x.equipo_id));
  const c=(t,n,e,v)=>'<div class="lider"><small>'+t+'</small><strong>'+esc(n)+'</strong>'+(e?eqBtn(e):'')+'<div><b>'+v+'</b></div></div>';
  return '<div class="lideres">'+(g?c('Líder de goleo',g.nombre,g.equipo_id,g.goles+' ⚽'):'')+(p?c('Mejor portero',p.nombre,p.equipo_id,p.promedio+' GC/PJ'):'')+(j?c('Mejor calificado',j.nombre,j.equipo_id,Number(j.promedio).toFixed(1)+' ★'):'')+'</div>';
}
function vGoleo(){
  const l=T.goleo.filter(x=>enCat(x.equipo_id));
  return lideres()+carta('Tabla de goleo',tabla([['#'],['Jugador','izq'],['Equipo','izq'],['Goles']],l.map((x,i)=>'<tr><td class="pos">'+(i+1)+'</td><td class="izq">'+esc(x.nombre)+'</td><td class="izq">'+eqBtn(x.equipo_id)+'</td><td class="pts">'+x.goles+'</td></tr>'),'Aún no hay goles capturados.'));
}
function vPorteros(){
  const l=T.porteros.filter(x=>enCat(x.equipo_id));
  return carta('Mejor portero',tabla([['#'],['Portero','izq'],['Equipo','izq'],['PJ'],['GC'],['GC/PJ'],['Invicto']],l.map((x,i)=>'<tr class="'+(x.califica?'':'tenue')+'"><td class="pos">'+(x.califica?i+1:'—')+'</td><td class="izq">'+esc(x.nombre)+'</td><td class="izq">'+eqBtn(x.equipo_id)+'</td><td>'+x.pj+'</td><td>'+x.gc+'</td><td class="pts">'+x.promedio+'</td><td>'+x.invictas+'</td></tr>'),'Aún no hay porteros registrados.'))
    +'<p class="nota">Promedio de goles recibidos por partido. Para el ranking se requieren al menos '+T.porteros_minimo+' partido(s) jugado(s).</p>';
}
function vDisciplina(){
  const s=T.suspendidos.filter(x=>enCat(x.equipo_id)),d=T.disciplina.filter(x=>enCat(x.equipo_id));
  return carta('Suspendidos',tabla([['Jugador','izq'],['Equipo','izq'],['Partidos pendientes'],['Motivo','izq']],s.map(x=>'<tr><td class="izq">'+esc(x.nombre)+'</td><td class="izq">'+eqBtn(x.equipo_id)+'</td><td><span class="chip rojo">'+x.pendientes+'</span></td><td class="izq">'+esc(x.motivos.join(' · '))+'</td></tr>'),'No hay jugadores suspendidos.'))
    +carta('Tarjetas',tabla([['Jugador','izq'],['Equipo','izq'],['Amarillas'],['Rojas']],d.map(x=>'<tr><td class="izq">'+esc(x.nombre)+'</td><td class="izq">'+eqBtn(x.equipo_id)+'</td><td><span class="tarjeta t-am"></span>'+x.amarillas+'</td><td><span class="tarjeta t-ro"></span>'+x.rojas+'</td></tr>'),'Sin tarjetas.'))
    +'<p class="nota">Roja directa: '+T.reglas.roja+' partido. Cada '+T.reglas.amarillas+' amarillas acumuladas: 1 partido. Más las sanciones de la mesa directiva.</p>';
}
function partidoHtml(p){
  const jug=p.estado==='jugado',pen=p.penales_local!==null?'<small>pen. '+p.penales_local+'–'+p.penales_visita+'</small>':'';
  const mid=jug?p.goles_local+' – '+p.goles_visita+pen:(p.estado==='cancelado'?'<small>Cancelado</small>':esc(p.hora)+'<small>'+esc(fecha(p.fecha))+'</small>');
  const ic={gol:'⚽',autogol:'⚽ (a.g.)',amarilla:'🟨',roja:'🟥'};
  const evs=p.eventos.length?'<div class="evs">'+p.eventos.map(e=>'<span>'+ic[e.tipo]+' '+esc(nomJ(e.jugador_id))+(e.minuto!==null?' '+e.minuto+"'":'')+'</span>').join('')+'</div>':'';
  return '<div class="partido"><div class="l">'+eqBtn(p.local_id)+'</div><div class="marc">'+mid+'</div><div>'+eqBtn(p.visita_id)+'</div><div class="meta">'+esc(fecha(p.fecha))+' · '+esc(p.hora)+' · '+esc(p.cancha)+'</div>'+evs+'</div>';
}
function vCalendario(){
  const ps=T.partidos.filter(p=>enCat(p.local_id)||enCat(p.visita_id));
  if(!ps.length)return '<p class="vacio">No hay partidos de torneo programados.</p>';
  const grupos=new Map();
  for(const p of ps){const k=p.fase==='regular'?(p.jornada?'A'+String(p.jornada).padStart(3,'0'):'B'):'C'+Object.keys(FASES).indexOf(p.fase);if(!grupos.has(k))grupos.set(k,[]);grupos.get(k).push(p);}
  return [...grupos.keys()].sort().map(k=>{const l=grupos.get(k),p=l[0];
    const t=k[0]==='A'?'Jornada '+p.jornada:k==='B'?'Partidos sin jornada asignada':FASES[p.fase];
    return '<section class="carta jornada"><div class="carta-t"><span>'+esc(t)+'</span><span class="chip">'+l.length+' partido'+(l.length>1?'s':'')+'</span></div>'+l.map(partidoHtml).join('')+'</section>';}).join('');
}
function vLlaves(){
  const rondas=T.llaves.map(r=>({...r,llaves:r.llaves.filter(l=>enCat(l.a)||enCat(l.b))})).filter(r=>r.llaves.length);
  if(!rondas.length)return '<p class="vacio">La fase eliminatoria aún no tiene llaves. La coordinación las arma asignando fase y número de llave a cada partido.</p>';
  const lado=(id,g,pe,w)=>'<div class="lado'+(w===id?' gana':'')+'"><span>'+eqBtn(id)+'</span><span>'+g+(pe!==null?' <small>('+pe+')</small>':'')+'</span></div>';
  return '<div class="cuadro">'+rondas.map(r=>'<div class="ronda"><h3>'+esc(FASES[r.fase])+'</h3>'+r.llaves.map(l=>'<div class="llave">'+lado(l.a,l.global_a,l.penales_a,l.ganador)+lado(l.b,l.global_b,l.penales_b,l.ganador)+'<div class="pie">'+(l.llave?'Llave '+l.llave+' · ':'')+l.partidos.length+' partido'+(l.partidos.length>1?'s':'')+(l.ganador?' · Avanza '+esc(eqById[l.ganador]?.nombre):'')+'</div></div>').join('')+'</div>').join('')+'</div>';
}
function vEquipos(){
  if(perfil)return vPerfil(perfil);
  const l=T.equipos.filter(e=>enCat(e.id));
  return l.length?'<div class="rejilla">'+l.map(e=>'<button type="button" class="ficha-eq" data-perfil="'+e.id+'">'+escudo(e.id)+'<span><strong>'+esc(e.nombre)+'</strong><small>'+esc(e.categoria||'')+' · '+e.plantilla.length+' jugadores</small><small>'+prom(e.calificacion)+'</small></span></button>').join('')+'</div>':'<p class="vacio">Sin equipos.</p>';
}
function vPerfil(id){
  const e=T.equipos.find(x=>Number(x.id)===Number(id));if(!e){perfil=null;return vEquipos();}
  let fila=null;for(const c of T.categorias){const r=c.tabla.find(r=>Number(r.equipo_id)===Number(id));if(r)fila=r;}
  const ps=T.partidos.filter(p=>Number(p.local_id)===Number(id)||Number(p.visita_id)===Number(id));
  const jug=ps.filter(p=>p.estado==='jugado').slice(-5).reverse(),prox=ps.filter(p=>p.estado==='programado').slice(0,3);
  const vE=puedeVotarE(id);
  let h='<p><button type="button" class="sec mini" data-volver="1">← Todos los equipos</button></p><div class="perfil-cab">'+escudo(id)+'<div><h2>'+esc(e.nombre)+'</h2><div class="nota">'+esc(e.categoria||'')+'</div><div>'+prom(e.calificacion)+' '+(vE?estrellas('equipo',id,T.yo.votos.equipo[id],true):'')+'</div></div>';
  if(fila)h+='<div class="nums"><div><b>'+fila.pos+'°</b><small>Lugar</small></div><div><b>'+fila.pts+'</b><small>Pts</small></div><div><b>'+fila.pj+'</b><small>PJ</small></div><div><b>'+fila.g+'-'+fila.e+'-'+fila.p+'</b><small>G-E-P</small></div><div><b>'+(fila.dg>0?'+':'')+fila.dg+'</b><small>DG</small></div></div>';
  h+='</div>';
  h+=carta('Plantilla',tabla([['#'],['Jugador','izq'],['Posición','izq oculto-m'],['⚽'],['🟨'],['🟥'],['Calificación'],[T.yo.ficha_id?'Tu voto':'','izq']],
    e.plantilla.map(j=>'<tr><td>'+(j.numero??'')+'</td><td class="izq">'+esc(j.nombre)+(j.suspendido?' <span class="chip rojo">Suspendido '+j.suspendido+'</span>':'')+'</td><td class="izq oculto-m">'+esc(POS[j.posicion])+'</td><td>'+j.goles+'</td><td>'+j.amarillas+'</td><td>'+j.rojas+'</td><td>'+prom(j.calificacion)+'</td><td class="izq">'+(puedeVotarJ(j.id)?estrellas('jugador',j.id,T.yo.votos.jugador[j.id],true):(T.yo.ficha_id&&Number(j.id)===Number(T.yo.ficha_id)?'<span class="chip verde">Tú</span>':''))+'</td></tr>'),'La plantilla todavía no está capturada.'));
  h+=carta('Próximos partidos',prox.length?prox.map(partidoHtml).join(''):'<p class="vacio">Sin partidos programados.</p>');
  h+=carta('Últimos resultados',jug.length?jug.map(partidoHtml).join(''):'<p class="vacio">Sin partidos jugados.</p>');
  return h;
}
function vCalificaciones(){
  let h='';
  if(T.yo.ficha_id){
    h+=carta('Califica a otros jugadores','<div class="barra"><input id="t-busca" type="search" placeholder="Busca por nombre o equipo…" aria-label="Buscar jugador"></div><div id="t-lista-votos"></div>');
  } else h+='<p class="nota">Sólo los usuarios ligados a una ficha de jugador pueden calificar. Pide tu acceso a la coordinación.</p>';
  const rj=T.ranking_jugadores.filter(x=>enCat(x.equipo_id)),re=T.ranking_equipos.filter(x=>enCat(x.equipo_id));
  h+=carta('Jugadores mejor calificados',tabla([['#'],['Jugador','izq'],['Equipo','izq'],['Promedio'],['Votos']],rj.slice(0,50).map((x,i)=>'<tr><td class="pos">'+(i+1)+'</td><td class="izq">'+esc(x.nombre)+'</td><td class="izq">'+eqBtn(x.equipo_id)+'</td><td class="pts">'+Number(x.promedio).toFixed(2)+'</td><td>'+x.votos+'</td></tr>'),'Todavía no hay votos.'));
  h+=carta('Equipos mejor calificados',tabla([['#'],['Equipo','izq'],['Promedio'],['Votos']],re.map((x,i)=>'<tr><td class="pos">'+(i+1)+'</td><td class="izq">'+eqBtn(x.equipo_id)+'</td><td class="pts">'+Number(x.promedio).toFixed(2)+'</td><td>'+x.votos+'</td></tr>'),'Todavía no hay votos.'));
  return h;
}
function listaVotos(){
  const box=$('t-lista-votos');if(!box)return;
  const q=($('t-busca')?.value||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  const todos=[];for(const e of T.equipos)if(enCat(e.id))for(const j of e.plantilla)if(puedeVotarJ(j.id))todos.push({j,e});
  const f=todos.filter(({j,e})=>!q||(j.nombre+' '+e.nombre).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').includes(q)).slice(0,40);
  box.innerHTML=tabla([['Jugador','izq'],['Equipo','izq'],['Promedio'],['Tu voto','izq']],f.map(({j,e})=>'<tr><td class="izq">'+esc(j.nombre)+'</td><td class="izq">'+eqBtn(e.id)+'</td><td>'+prom(j.calificacion)+'</td><td class="izq">'+estrellas('jugador',j.id,T.yo.votos.jugador[j.id],true)+'</td></tr>'),'Sin coincidencias.');
}

/* ---------- captura (coordinación / administración propietaria) ---------- */
function vCaptura(){
  const js=(T.jugadores_todos||[]).filter(j=>enCat(j.equipo_id));
  let h='<div class="barra"><button type="button" data-acc="importar">Importar plantillas de Tesorería</button><button type="button" class="sec" data-acc="jugador">Agregar jugador</button><button type="button" class="sec" data-acc="sancion">Registrar sanción</button></div>';
  const ps=T.partidos.filter(p=>enCat(p.local_id)||enCat(p.visita_id));
  h+=carta('Partidos: fase, jornada, llave y cédula',tabla([['Fecha','izq'],['Partido','izq'],['Marcador'],['Fase','izq'],['Jornada'],['Llave'],['','izq']],ps.map(p=>'<tr><td class="izq">'+esc(p.fecha)+' '+esc(p.hora)+'</td><td class="izq">'+esc(eqById[p.local_id]?.nombre)+' vs '+esc(eqById[p.visita_id]?.nombre)+'</td><td>'+(p.estado==='jugado'?p.goles_local+'–'+p.goles_visita:esc(p.estado))+'</td><td class="izq">'+esc(FASES[p.fase])+'</td><td>'+(p.jornada??'—')+'</td><td>'+(p.llave??'—')+'</td><td class="izq"><button type="button" class="mini sec" data-acc="info:'+p.id+'">Fase / jornada</button> '+(p.estado==='jugado'?'<button type="button" class="mini" data-acc="cedula:'+p.id+'">Cédula</button>':'')+'</td></tr>'),'Programa partidos de torneo desde el panel principal.'));
  h+=carta('Plantillas ('+js.length+' jugadores)',tabla([['#'],['Jugador','izq'],['Equipo','izq'],['Posición','izq'],['Acceso','izq'],['','izq']],js.map(j=>'<tr class="'+(Number(j.activo)?'':'inactivo')+'"><td>'+(j.numero??'')+'</td><td class="izq">'+esc(j.nombre)+'</td><td class="izq">'+esc(eqById[j.equipo_id]?.nombre)+'</td><td class="izq">'+esc(POS[j.posicion])+'</td><td class="izq">'+(j.usuario?'<span class="chip verde">'+esc(j.usuario)+'</span>':'—')+'</td><td class="izq"><button type="button" class="mini sec" data-acc="editar:'+j.id+'">Editar</button> '+(Number(j.activo)?'<button type="button" class="mini" data-acc="cuenta:'+j.id+'">'+(j.usuario?'Restablecer acceso':'Crear acceso')+'</button>':'')+'</td></tr>'),'Sin jugadores. Importa las plantillas de Tesorería o agrégalos.'));
  const sn=T.sanciones||[];
  h+=carta('Sanciones de la mesa directiva',tabla([['Desde','izq'],['Jugador','izq'],['Partidos'],['Motivo','izq'],['','izq']],sn.map(s=>'<tr><td class="izq">'+esc(s.desde)+'</td><td class="izq">'+esc(nomJT(s.jugador_id))+'</td><td>'+s.partidos+'</td><td class="izq">'+esc(s.motivo)+'</td><td class="izq"><button type="button" class="mini sec" data-acc="borrar_sancion:'+s.id+'">Quitar</button></td></tr>'),'Sin sanciones manuales.'));
  return h;
}
const nomJT=id=>(T.jugadores_todos||[]).find(j=>Number(j.id)===Number(id))?.nombre||nomJ(id);
const campo=(n,l,t='text',v='',x='')=>'<label>'+esc(l)+'<input name="'+n+'" type="'+t+'" value="'+esc(v)+'" '+x+'></label>';
const sel=(n,l,items,v)=>'<label>'+esc(l)+'<select name="'+n+'">'+items.map(i=>'<option value="'+esc(i[0])+'"'+(String(i[0])===String(v??'')?' selected':'')+'>'+esc(i[1])+'</option>').join('')+'</select></label>';
const opEquipos=()=>T.equipos.map(e=>[e.id,e.nombre]);
function dialogo(titulo,html,enviar,despues){
  $('t-dlg-tit').textContent=titulo;$('t-dlg-campos').innerHTML=html;$('t-dlg').showModal();
  $('t-form').onsubmit=async ev=>{ev.preventDefault();const b=ev.submitter;if(b)b.disabled=true;
    try{const datos=Object.fromEntries(new FormData($('t-form')));const msg=await enviar(datos);$('t-dlg').close();await cargar();aviso(msg||'Guardado.',true);if(despues)despues();}
    catch(e){aviso(e.message);$('t-dlg').close();}finally{if(b)b.disabled=false;}};
}
const post=(a,d)=>api(a,{...d,espacio_id:sid()});
function fichaJugador(j={}){
  dialogo(j.id?'Editar jugador':'Agregar jugador',campo('nombre','Nombre completo','text',j.nombre||'','maxlength="120" required')+sel('equipo_id','Equipo',opEquipos(),j.equipo_id)
    +'<div class="dos">'+campo('numero','Número (opcional)','number',j.numero??'','min="0" max="999"')+sel('posicion','Posición',Object.entries(POS),j.posicion||'medio')+'</div>'
    +(j.id?sel('activo','Estado',[[1,'Activo'],[0,'Baja (cierra su acceso)']],j.activo??1):''),
    d=>post('jugador',{...d,id:j.id||0}));
}
function cedula(pid){
  const p=T.partidos.find(x=>Number(x.id)===Number(pid));if(!p)return;
  const pl=(T.jugadores_todos||[]).filter(j=>Number(j.activo)&&(Number(j.equipo_id)===p.local_id||Number(j.equipo_id)===p.visita_id));
  const asig=lado=>(T.porteros_asignados||[]).find(x=>Number(x.partido_id)===p.id&&Number(x.equipo_id)===lado)?.jugador_id||'';
  const deEq=lado=>[['','— Sin registrar —'],...pl.filter(j=>Number(j.equipo_id)===lado).map(j=>[j.id,j.nombre])];
  const ic={gol:'Gol',autogol:'Autogol',amarilla:'Amarilla',roja:'Roja'};
  const evs=p.eventos.length?'<ul>'+p.eventos.map(e=>'<li>'+esc(ic[e.tipo])+' · '+esc(nomJT(e.jugador_id))+(e.minuto!==null?' '+e.minuto+"'":'')+' <button type="button" class="mini sec" data-borrar-ev="'+e.id+'">Quitar</button></li>').join('')+'</ul>':'<p class="nota">Sin eventos capturados.</p>';
  const pl0=asig(p.local_id),pv0=asig(p.visita_id);
  dialogo('Cédula: '+(eqById[p.local_id]?.nombre)+' '+p.goles_local+'–'+p.goles_visita+' '+(eqById[p.visita_id]?.nombre),
    evs+'<h3>Agregar evento</h3>'+sel('jugador_id','Jugador',[['','— Ninguno —'],...pl.map(j=>[j.id,j.nombre+' ('+(eqById[j.equipo_id]?.nombre||'')+')'])],'')
    +'<div class="dos">'+sel('tipo','Tipo',Object.entries(ic),'gol')+campo('minuto','Minuto (opcional)','number','','min="0" max="150"')+'</div>'
    +'<h3>Porteros</h3><div class="dos">'+sel('portero_local',eqById[p.local_id]?.nombre||'Local',deEq(p.local_id),pl0)+sel('portero_visita',eqById[p.visita_id]?.nombre||'Visitante',deEq(p.visita_id),pv0)+'</div>'
    +'<p class="nota">Los autogoles se registran al jugador que lo cometió y no suman a su goleo.</p>',
    async d=>{
      if(String(d.portero_local)!==String(pl0))await post('portero',{partido_id:p.id,equipo_id:p.local_id,jugador_id:Number(d.portero_local)||0});
      if(String(d.portero_visita)!==String(pv0))await post('portero',{partido_id:p.id,equipo_id:p.visita_id,jugador_id:Number(d.portero_visita)||0});
      if(d.jugador_id)await post('evento',{partido_id:p.id,jugador_id:Number(d.jugador_id),tipo:d.tipo,minuto:d.minuto});
      return 'Cédula actualizada.';
    },()=>cedula(pid));
}
async function accion(a,id){
  if(a==='importar'){const r=await post('importar_plantillas',{});await cargar();aviso(r.nuevos+' jugador(es) nuevos importados de Tesorería.',true);}
  if(a==='jugador')fichaJugador();
  if(a==='editar')fichaJugador((T.jugadores_todos||[]).find(j=>String(j.id)===id));
  if(a==='cuenta'){const j=(T.jugadores_todos||[]).find(x=>String(x.id)===id);
    dialogo('Acceso de '+j.nombre,campo('usuario','Usuario','text',j.usuario||'','maxlength="60" required autocomplete="off"')+'<p class="nota">Se genera una contraseña aleatoria. Cópiala al guardar: no se vuelve a mostrar.</p>',
      async d=>{const r=await post('usuario_jugador',{...d,jugador_id:j.id});return 'Usuario: '+r.usuario+'\nContraseña (cópiala ahora): '+r.clave;});}
  if(a==='info'){const p=T.partidos.find(x=>String(x.id)===id);
    dialogo('Fase y jornada',sel('fase','Fase',Object.entries(FASES),p.fase)+'<div class="dos">'+campo('jornada','Jornada (fase regular)','number',p.jornada??'','min="1" max="99"')+campo('llave','Número de llave (eliminatoria)','number',p.llave??'','min="1" max="64"')+'</div><div class="dos">'+campo('penales_local','Penales local','number',p.penales_local??'','min="0" max="99"')+campo('penales_visita','Penales visitante','number',p.penales_visita??'','min="0" max="99"')+'</div><p class="nota">Ida y vuelta: da el mismo número de llave a los dos partidos; el global se suma solo.</p>',
      d=>post('info_partido',{...d,partido_id:p.id}));}
  if(a==='cedula')cedula(id);
  if(a==='sancion')dialogo('Registrar sanción',sel('jugador_id','Jugador',(T.jugadores_todos||[]).filter(j=>Number(j.activo)).map(j=>[j.id,j.nombre+' ('+(eqById[j.equipo_id]?.nombre||'')+')']))+'<div class="dos">'+campo('partidos','Partidos','number','1','min="1" max="20" required')+campo('desde','Cuentan después del','date',new Date().toISOString().slice(0,10),'required')+'</div>'+campo('motivo','Motivo','text','','maxlength="200" required'),d=>post('sancion',d));
  if(a==='borrar_sancion'){await post('borrar_sancion',{id:Number(id)});await cargar();aviso('Sanción retirada.',true);}
}

/* ---------- navegación ---------- */
function pintar(){
  const tabs=[...TABS];if(T.escritura)tabs.push(['captura','Captura']);
  if(!tabs.some(t=>t[0]===tab))tab='tabla';
  $('t-tabs').innerHTML=tabs.map(t=>'<button type="button" data-tab="'+t[0]+'"'+(t[0]===tab?' aria-current="page"':'')+'>'+esc(t[1])+'</button>').join('');
  $('t-nombre').textContent=T.espacio.nombre;
  const v={tabla:vTabla,goleo:vGoleo,porteros:vPorteros,disciplina:vDisciplina,calendario:vCalendario,llaves:vLlaves,equipos:vEquipos,calificaciones:vCalificaciones,captura:vCaptura}[tab];
  $('t-vista').innerHTML=v();if(tab==='calificaciones')listaVotos();
  $('t-tabs').querySelector('[aria-current]')?.scrollIntoView({block:'nearest',inline:'center'});
}
async function cargar(){
  T=await api('torneo',null,{espacio_id:sid()});
  eqById={};jugById={};
  for(const e of T.equipos){eqById[e.id]=e;for(const j of e.plantilla)jugById[j.id]={...j,equipo_id:e.id};}
  const cats=[...new Set(T.equipos.map(e=>e.categoria).filter(Boolean))].sort();
  $('t-cat').innerHTML='<option value="">Todas</option>'+cats.map(c=>'<option'+(c===cat?' selected':'')+'>'+esc(c)+'</option>').join('');
  if(cat&&!cats.includes(cat))cat='';
  pintar();
}
$('t-tabs').onclick=ev=>{const b=ev.target.closest('[data-tab]');if(!b)return;tab=b.dataset.tab;perfil=null;history.replaceState(null,'','#'+tab);aviso('');pintar();window.scrollTo(0,0);};
$('t-cat').onchange=()=>{cat=$('t-cat').value;pintar();};
$('t-espacio').onchange=()=>{ls.set('lch-torneo-espacio',String(sid()));perfil=null;cargar().catch(e=>aviso(e.message));};
$('t-vista').addEventListener('input',ev=>{if(ev.target.id==='t-busca')listaVotos();});
$('t-vista').onclick=async ev=>{
  const p=ev.target.closest('[data-perfil]');if(p){tab='equipos';perfil=Number(p.dataset.perfil);history.replaceState(null,'','#equipos');pintar();window.scrollTo(0,0);return;}
  if(ev.target.closest('[data-volver]')){perfil=null;pintar();return;}
  const v=ev.target.closest('[data-votar]');
  if(v){const [tipo,id,pts]=v.dataset.votar.split(':');v.parentElement.querySelectorAll('button').forEach(b=>b.disabled=true);
    try{await post('votar',{tipo,objetivo_id:Number(id),puntaje:Number(pts)});await cargar();aviso('Calificación guardada.',true);}catch(e){aviso(e.message);pintar();}return;}
  const a=ev.target.closest('[data-acc]');
  if(a){const [acc,id]=a.dataset.acc.split(':');a.disabled=true;try{await accion(acc,id);}catch(e){aviso(e.message);}finally{a.disabled=false;}}
};
$('t-dlg-campos').onclick=async ev=>{
  const b=ev.target.closest('[data-borrar-ev]');if(!b)return;b.disabled=true;
  const pid=T.partidos.find(p=>p.eventos.some(e=>String(e.id)===b.dataset.borrarEv))?.id;
  try{await post('borrar_evento',{id:Number(b.dataset.borrarEv)});$('t-dlg').close();await cargar();if(pid)cedula(pid);}catch(e){aviso(e.message);}
};
$('t-dlg-cerrar').onclick=()=>$('t-dlg').close();
$('t-guia-ok').onclick=()=>{$('t-guia').hidden=true;ls.set('lch-guia-torneo','1');};

async function iniciar(){
  const s=await api('sesion');csrf=s.csrf;usuario=s.usuario;
  if(!usuario){$('t-nombre').textContent='Inicia sesión';$('t-vista').innerHTML='<p class="vacio">Para ver el torneo entra con tu usuario y contraseña.<br><br><a class="btn" href="./">Entrar</a></p>';return;}
  espacios=(await api('espacios')).espacios;
  if(!espacios.length){$('t-nombre').textContent='Sin torneo';$('t-vista').innerHTML='<p class="vacio">Tu cuenta no tiene un torneo asignado.</p>';return;}
  const pedido=new URLSearchParams(location.search).get('espacio')||ls.get('lch-torneo-espacio');
  $('t-espacio').innerHTML=espacios.map(e=>'<option value="'+esc(e.id)+'"'+(String(e.id)===String(pedido)?' selected':'')+'>'+esc(e.nombre)+'</option>').join('');
  $('t-espacio').closest('label').hidden=espacios.length<2;
  const h=location.hash.slice(1);if(h)tab=h;
  if(!ls.get('lch-guia-torneo'))$('t-guia').hidden=false;
  await cargar();
}
iniciar().catch(e=>aviso(e.message));
