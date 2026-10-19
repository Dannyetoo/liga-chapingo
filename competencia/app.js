'use strict';
const $=id=>document.getElementById(id), esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const mx=x=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(x||0);
let csrf='',usuario=null,espacios=[],panel=null,tab='inicio',staff=[],docs=[],docsExtra=[];
const aviso=x=>{$('aviso').textContent=x||'';};
async function api(a,body,params={}) {
  const url='api.php?'+new URLSearchParams({a,...params});
  const r=await fetch(url,{credentials:'same-origin',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},method:body?'POST':'GET',body:body?JSON.stringify(body):undefined});
  const j=await r.json(); if(!j.ok) throw Error(j.error||'No se pudo completar la solicitud.'); return j;
}
const sid=()=>Number($('espacio').value), espacio=()=>espacios.find(e=>Number(e.id)===sid());
const editable=()=>!!espacio()?.escritura;
const tabla=(headers,rows)=>'<div class="tabla"><table><thead><tr>'+headers.map(h=>'<th>'+esc(h)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(r=>'<tr>'+r.map(c=>'<td>'+c+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
const boton=(label,id)=>'<button type="button" data-accion="'+id+'">'+esc(label)+'</button>';
const opts=items=>items.map(x=>'<option value="'+esc(x[0])+'">'+esc(x[1])+'</option>').join('');
const campo=(nombre,label,type='text',value='',extra='')=>'<label>'+esc(label)+'<input name="'+nombre+'" type="'+type+'" value="'+esc(value)+'" '+extra+' required></label>';
const select=(nombre,label,items,value)=>'<label>'+esc(label)+'<select name="'+nombre+'">'+items.map(x=>'<option value="'+esc(x[0])+'"'+(String(x[0])===String(value)?' selected':'')+'>'+esc(x[1])+'</option>').join('')+'</select></label>';
const equipos=()=>panel.equipos.map(e=>[e.id,e.nombre]);
function formulario(titulo,html,accion,extra={}) {
  $('titulo-editor').textContent=titulo; $('campos').innerHTML=html; $('dialogo').showModal();
  const request=accion==='movimiento'?Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join(''):null;
  $('editor').onsubmit=async ev=>{
    ev.preventDefault(); const b=ev.submitter; b.disabled=true;
    try {
      const datos={...Object.fromEntries(new FormData($('editor'))),...extra,espacio_id:sid()}; if(request) datos.solicitud=request;
      const r=await api(accion,datos); $('dialogo').close(); await cargar();
      aviso(r.clave?'Usuario: '+r.usuario+'\nContraseña (cópiala ahora): '+r.clave:'Guardado.');
    } catch(e){aviso(e.message);} finally{b.disabled=false;}
  };
}
async function cargar() {
  if(!sid()||usuario.rol==='jugador') {panel=null; pintar();return;}
  panel=await api('panel',null,{espacio_id:sid()}); pintar();
}
/* Botón guiado al módulo «Torneo y estadísticas» (aparece en el estado de cuenta de todos los usuarios). */
function botonTorneo(){
  return '<a class="btn-torneo" href="torneo.html?espacio='+encodeURIComponent(sid())+'"><span class="bt-ico" aria-hidden="true">🏆</span><span class="bt-txt"><strong>Torneo y estadísticas</strong><small>Tabla general, goleo, porteros, tarjetas y suspendidos, calendario por jornada, llaves, plantillas y calificaciones.</small></span><span class="bt-fl" aria-hidden="true">›</span></a>'
    +'<ol class="bt-guia" aria-label="Qué encontrarás"><li><b>1</b> Consulta la tabla y el goleo</li><li><b>2</b> Revisa calendario y llaves</li><li><b>3</b> Mira plantillas y califica</li></ol>';
}
function documentos(){
  const oficial='<a class="btn" href="api.php?a=estado_financiero_pdf" download="estado-financiero-2026.pdf">Descargar PDF · Estado financiero 2026</a>';
  return '<h3>Mis documentos</h3>'+oficial+(docs.length?tabla(['Documento','Publicado',''],docs.map(d=>[esc(d.titulo),esc(String(d.creado_en||'').slice(0,10)),d.disponible?'<a href="api.php?a=documento&amp;id='+encodeURIComponent(d.id)+'" target="_blank" rel="noopener">Abrir PDF</a>':'<small>Pendiente de cargar</small>'])):'<p class="nota">El documento oficial está disponible mediante el botón de descarga.</p>');
}
function documentosCoordinacion(){
  if(!['administrador','coordinador'].includes(usuario.rol))return '';
  return '<aside class="documentos-rail"><button type="button" class="documentos-toggle" data-accion="docs">▤<span>Documentos</span></button></aside>';
}
function dockIcon(tipo){
  const p={
    casa:'<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    equipos:'<circle cx="9" cy="8" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3 20c.5-4 3-6 6-6s5.5 2 6 6"/><path d="M14 15c3.5-.5 5.8 1.3 6.5 5"/>',
    estadisticas:'<path d="M4 19V5M4 19h17"/><path d="m7 15 3-4 3 2 5-7"/>',
    cancha:'<path d="M4 5h16v14H4z"/><path d="M12 5v14M4 12h16"/><circle cx="12" cy="12" r="2"/>',
    match:'<circle cx="12" cy="12" r="9"/><path d="m12 3 2.2 4.8 5.3.6-3.9 3.6 1.1 5.2-4.7-2.6-4.7 2.6 1.1-5.2-3.9-3.6 5.3-.6z"/>'
  };
  return '<svg viewBox="0 0 24 24" aria-hidden="true">'+p[tipo]+'</svg>';
}
function kpi(label,value){return '<div class="kpi">'+esc(label)+'<strong>'+esc(value)+'</strong></div>';}
function agenda(amistosos=false) {
  const ps=panel.partidos.filter(p=>!amistosos||p.tipo==='amistoso');
  const nombre=id=>panel.equipos.find(e=>Number(e.id)===Number(id))?.nombre||'Equipo';
  return (editable()?boton(amistosos?'Crear amistoso':'Programar partido',amistosos?'amistoso':'partido'):'')+tabla(['Fecha','Hora','Partido','Cancha','Estado','Resultado',''],ps.map(p=>[esc(p.fecha),esc(p.hora),esc(nombre(p.local_id))+' vs '+esc(nombre(p.visita_id)),esc(p.cancha),esc(p.estado),p.estado==='jugado'?esc(p.goles_local)+' – '+esc(p.goles_visita):'—',editable()?boton('Editar','editar:'+p.id):'']));
}
async function pintarGeneral(){
  try {
    const r=await api('general');
    if(tab!=='general')return;
    $('vista').innerHTML='<h2>Dashboard general</h2><div class="acciones grupo-acciones"><button type="button" data-tab="espacios">Gestionar espacios</button><button type="button" data-tab="equipos">Equipos y cuentas</button><button type="button" data-tab="finanzas">Tesorería y tickets</button></div><div class="kpis">'+kpi('Torneos',r.torneos.length)+kpi('Equipos',r.torneos.reduce((a,t)=>a+t.equipos,0))+kpi('Cobrado',mx(r.torneos.reduce((a,t)=>a+t.cobrado,0)))+kpi('Pendiente',mx(r.torneos.reduce((a,t)=>a+t.pendiente,0)))+'</div>'+tabla(['Espacio','Equipos','Cobrado','Pendiente','Permiso'],r.torneos.map(t=>[esc(t.nombre),t.equipos,mx(t.cobrado),mx(t.pendiente),t.escritura?'Administrar':'Monitoreo']))+'<h3>Reportes de los equipos</h3>'+tabla(['Espacio','Equipo','Fecha','Mensaje','Estado','Respuesta'],r.reportes.map(x=>[esc(x.espacio),esc(x.equipo),esc(x.creado_en),esc(x.mensaje),esc(x.estado),esc(x.respuesta)]));
  }catch(e){aviso(e.message);}
}
function pintar(){
  const esEquipo=usuario.rol==='equipo';
  if(usuario.rol==='jugador'){
    $('menu').innerHTML='';$('nombre').textContent=usuario.nombre+' · jugador';
    $('vista').innerHTML=(sid()?botonTorneo():'<p>Tu ficha de jugador aún no está activa en ningún torneo.</p>');return;
  }
  const tabs=esEquipo?[['match','Match'],['equipos','Mi equipo']]:[['inicio','Inicio'],['equipos','Equipos y cuentas'],['cancha','Canchas'],['rival','Match'],['reportes','Reportes'],['finanzas','Tesorería / Finanzas']];
  if(usuario.rol==='administrador')tabs.unshift(['general','Dashboard general'],['espacios','Espacios digitales']);
  if(!tabs.some(t=>t[0]===tab))tab=tabs[0][0];
  $('menu').innerHTML=tabs.filter(t=>!['cancha','rival','reportes'].includes(t[0])).map(t=>'<button data-tab="'+t[0]+'"'+(t[0]===tab?' aria-current="page"':'')+'>'+esc(t[1])+'</button>').join('');
  let pie=$('menu-secundario');
  if(!pie){pie=document.createElement('footer');pie.id='menu-secundario';$('app').append(pie);}
  pie.innerHTML='<button type="button" data-tab="cancha" class="dock-cancha" aria-label="Canchas">'+dockIcon('cancha')+'<span>Canchas</span></button><button type="button" data-tab="equipos" class="dock-equipos" aria-label="Equipos">'+dockIcon('equipos')+'<span>Equipos</span></button><button type="button" data-tab="inicio" class="dock-inicio" aria-label="Inicio">'+dockIcon('casa')+'<span>Inicio</span></button><a href="torneo.html?espacio='+encodeURIComponent(sid())+'" class="dock-estadisticas" aria-label="Estadísticas">'+dockIcon('estadisticas')+'<span>Estadísticas</span></a><button type="button" data-tab="rival" class="dock-match" aria-label="Match">'+dockIcon('match')+'<span>Match</span></button>';
  const rail=$('app').querySelector('.documentos-rail');if(rail)rail.remove();$('app').insertAdjacentHTML('beforeend',documentosCoordinacion());
  $('nombre').textContent=(usuario.rol==='coordinador'?'Coordinador: ':'')+usuario.nombre;
  let torneo=$('torneo-cabecera');
  if(!torneo){torneo=document.createElement('a');torneo.id='torneo-cabecera';torneo.className='torneo-cabecera';torneo.textContent='Torneo y Estadísticas';$('nombre').after(torneo);}
  torneo.href='torneo.html?espacio='+encodeURIComponent(sid());torneo.hidden=!sid();
  if(tab==='general'){pintarGeneral();return;}
  if(tab==='espacios'){
    $('vista').innerHTML=boton('Crear espacio','crear_espacio')+tabla(['Espacio','Edición','Acceso','Acciones'],espacios.map(e=>[esc(e.nombre),esc(e.edicion_ref),e.escritura?'Control propio':'Monitoreo',e.escritura?boton('Editar','editar_espacio:'+e.id)+' '+boton('Archivar','eliminar_espacio:'+e.id)+' '+boton('Asignar coordinador','asignar:'+e.id):'']));return;
  }
  if(!panel){$('vista').innerHTML='<p>No hay espacios asignados.</p>';return;}
  let html='';
  if(!esEquipo&&!editable())html='<p>Monitoreo: este torneo pertenece a otro administrador.</p>';
  if(tab==='inicio'||tab==='finanzas'){
    html+=tab==='inicio'?I18.inicio():I18.metricas()+(editable()?boton('Registrar cargo / pago / gasto','movimiento'):'')+tabla(['Fecha','Tipo','Concepto','Monto','Quién recibió el pago'],panel.movimientos.map(m=>[esc(m.fecha),esc(m.tipo),esc(m.concepto),mx(m.monto),esc(m.recibio||'Sin registrar')]));
  } else if(tab==='cancha')html+=canchasUI();
  else if(tab==='rival'||tab==='match')html+=agenda(tab==='rival')+(esEquipo?boton('Reportar incidencia','incidencia'):'');
  else if(tab==='equipos')html+=equiposUnificados();
  else if(tab==='reportes')html+=boton('Descargar reporte CSV','exportar')+tabla(['Equipo','Mensaje','Estado','Respuesta',''],panel.reportes.map(r=>[esc(panel.equipos.find(e=>Number(e.id)===Number(r.equipo_id))?.nombre),esc(r.mensaje),esc(r.estado),esc(r.respuesta),editable()&&r.estado==='pendiente'?boton('Resolver','reporte:'+r.id):'']));
  $('vista').innerHTML=html;if(tab==='inicio')I18.deportivo();
}
function equiposUnificados(){
  return '<div class="acciones grupo-acciones">'+boton('Vincular equipo','equipo')+boton('Registrar cargo o pago','movimiento')+'</div>'+tabla(['Equipo','Categoría','Saldo','Usuarios','Tickets','Acciones'],panel.equipos.map(e=>{const c=panel.cuentas.find(x=>Number(x.id)===Number(e.id))||{};return [esc(e.nombre),esc(e.categoria),I18.saldo(c.saldo||0),esc(e.usuario||'Sin acceso'),boton('Ver ticket','tickets:'+e.id),editable()?boton('Generar acceso','usuario:'+e.id)+' '+boton('Desactivar','desactivar:'+e.id):''];}));
}
function canchasUI(){
  const cards=(panel.canchas||[]).map(c=>'<article class="cancha-card"><h3>'+esc(c.nombre)+'</h3><p>'+esc(c.horarios||'Sin horarios definidos')+'</p>'+(editable()?boton('Editar','editar_cancha:'+c.id)+' '+boton('Eliminar','eliminar_cancha:'+c.id):'')+'</article>').join('');
  return '<div class="acciones grupo-acciones">'+(editable()?boton('Agregar cancha','cancha'):'')+'</div><p class="nota">Edita canchas y horarios desde este espacio; no se solicita una contraseña adicional.</p>'+(cards||'<p>No hay canchas registradas.</p>');
}
function partido(p={}) {
  formulario(p.id?'Editar partido':'Programar partido',select('local_id','Local',equipos(),p.local_id)+select('visita_id','Visitante',equipos(),p.visita_id)+campo('fecha','Fecha','date',p.fecha||'')+campo('hora','Hora','time',(p.hora||'').slice(0,5))+campo('cancha','Cancha','text',p.cancha||'','maxlength="100"')+campo('duracion','Duración en minutos','number',p.duracion||60,'min="15" max="240"')+select('tipo','Tipo',[['torneo','Torneo'],['amistoso','Amistoso']],p.tipo)+select('estado','Estado',[['programado','Programado'],['jugado','Jugado'],['cancelado','Cancelado']],p.estado)+campo('goles_local','Puntos local (si ya se jugó)','number',p.goles_local??0,'min="0" max="999"')+campo('goles_visita','Puntos visitante (si ya se jugó)','number',p.goles_visita??0,'min="0" max="999"'),'partido',{id:p.id||0,version:p.version||0});
}
$('app').addEventListener('click',ev=>{const b=ev.target.closest('[data-tab]');if(b){tab=b.dataset.tab;pintar();return;}const a=ev.target.closest('[data-accion]');if(a&&a.dataset.accion==='docs')I18.documentos(docsExtra);});
$('vista').onclick=async ev=>{
  const b=ev.target.closest('[data-accion]');if(!b)return;
  const [a,id]=b.dataset.accion.split(':');
  try {
    if(a==='exportar')I18.exportar();
    if(a==='detalle')I18.detalle(id);
    if(a==='tickets')I18.tickets(id);
    if(a==='docs')I18.documentos(docsExtra);
    if(a==='cancha')formulario('Agregar cancha',campo('nombre','Nombre de la cancha')+campo('horarios','Horarios disponibles','text','','placeholder="Lun-Vie 18:00-22:00"'),'cancha');
    if(a==='editar_cancha'){const c=(panel.canchas||[]).find(x=>String(x.id)===id);formulario('Editar cancha',campo('nombre','Nombre de la cancha','text',c?.nombre||'')+campo('horarios','Horarios disponibles','text',c?.horarios||''),'cancha',{id:Number(id)});}
    if(a==='eliminar_cancha'&&confirm('¿Eliminar esta cancha?'))api('eliminar_cancha',{id:Number(id),espacio_id:sid()}).then(cargar);
    if(a==='editar_espacio'){const e=espacios.find(x=>String(x.id)===id);if(!staff.length)staff=(await api('administradores')).usuarios.filter(u=>u.rol==='coordinador');formulario('Editar espacio',campo('nombre','Nombre','text',e?.nombre||'')+campo('edicion_ref','Referencia','text',e?.edicion_ref||'')+select('coordinador_id','Coordinador',staff.map(u=>[u.id,u.nombre]),e?.coordinador_id),'editar_espacio',{espacio_id:Number(id)});}
    if(a==='eliminar_espacio'&&confirm('¿Archivar este espacio digital?'))api('eliminar_espacio',{espacio_id:Number(id)}).then(iniciar);
    if(a==='financiero')I18.financiero();
    if(a==='partido'||a==='amistoso')partido({tipo:a==='amistoso'?'amistoso':'torneo'});
    if(a==='editar')partido(panel.partidos.find(p=>String(p.id)===id));
    if(a==='movimiento')formulario('Registrar movimiento',select('tipo','Tipo',[['cargo','Cargo'],['pago','Pago'],['gasto','Gasto']])+select('equipo_id','Equipo (gastos: no aplica)',equipos())+campo('monto','Monto MXN','number','','min="0.01" step="0.01"')+campo('concepto','Concepto','text','','maxlength="150"')+campo('fecha','Fecha','date')+campo('recibio','Quién recibió el pago (cargo: no aplica)','text','','maxlength="120"'),'movimiento');
    if(a==='usuario')formulario('Acceso del equipo',campo('usuario','Usuario','text','','maxlength="60"')+'<p>Se generará una contraseña aleatoria. Cópiala al guardar.</p>','usuario_equipo',{equipo_id:Number(id)});
    if(a==='desactivar')formulario('Desactivar acceso','<p>Se cerrarán las sesiones del equipo y se bloqueará su acceso.</p>','desactivar_equipo',{equipo_id:Number(id)});
    if(a==='crear_coordinador')formulario('Crear coordinador',campo('usuario','Usuario','text','','maxlength="60"')+campo('nombre','Nombre','text','','maxlength="120"'),'crear_coordinador');
    if(a==='equipo')formulario('Vincular equipo de Tesorería',campo('origen_ref','ID exacto del equipo en Tesorería','text','','maxlength="64"')+campo('categoria','Categoría'),'equipo');
    if(a==='reporte')formulario('Resolver incidencia',campo('respuesta','Respuesta','text','','maxlength="1000"'),'reporte',{id:Number(id)});
    if(a==='incidencia')formulario('Reportar incidencia',campo('mensaje','Mensaje','text','','maxlength="1000"'),'incidencia');
    if(a==='asignar'||a==='crear_espacio'){
      staff=(await api('administradores')).usuarios.filter(u=>u.rol==='coordinador');
      if(!staff.length)throw Error('Crea primero un coordinador.');
      if(a==='asignar'){
        $('espacio').value=id;await cargar();
        formulario('Asignar coordinador',select('coordinador_id','Coordinador',staff.map(u=>[u.id,u.nombre])),'asignar');
      }else formulario('Crear espacio',campo('nombre','Nombre')+campo('edicion_ref','Referencia exacta de edición (Tesorería)','text','','maxlength="64"')+select('coordinador_id','Coordinador',staff.map(u=>[u.id,u.nombre])),'crear_espacio');
    }
  }catch(e){aviso(e.message);}
};
$('cerrar').onclick=()=>$('dialogo').close();
$('espacio').onchange=()=>cargar().catch(e=>aviso(e.message));
async function iniciar(){
  const s=await api('sesion');csrf=s.csrf;usuario=s.usuario;
  $('login').hidden=!!usuario;$('app').hidden=!usuario;$('salir').hidden=!usuario;if(!usuario)return;
  espacios=(await api('espacios')).espacios;
  try{docs=(await api('documentos')).documentos;}catch(e){docs=[];}
  if(['administrador','coordinador'].includes(usuario.rol)){try{docsExtra=(await api('documentos_coordinacion')).documentos;}catch(e){docsExtra=[];}}
  $('espacio').innerHTML=opts(espacios.map(e=>[e.id,e.nombre+(e.escritura?'':' · consulta')]));
  if(usuario.rol==='administrador')tab='general';await cargar();
}
$('login').onsubmit=async ev=>{ev.preventDefault();ev.submitter.disabled=true;try{await api('entrar',Object.fromEntries(new FormData(ev.target)));ev.target.reset();aviso('');await iniciar();}catch(e){aviso(e.message);}finally{ev.submitter.disabled=false;}};
$('salir').onclick=async()=>{try{await api('salir',{});location.reload();}catch(e){aviso(e.message);}};
iniciar().catch(e=>aviso(e.message));
