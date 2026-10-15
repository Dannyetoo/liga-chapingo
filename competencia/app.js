'use strict';
const $=id=>document.getElementById(id), esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const mx=x=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(x||0);
let csrf='',usuario=null,espacios=[],panel=null,tab='inicio',staff=[];
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
  if(!sid()) {panel=null; pintar();return;}
  panel=await api('panel',null,{espacio_id:sid()}); pintar();
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
    $('vista').innerHTML='<h2>Dashboard general</h2><div class="kpis">'+kpi('Torneos',r.torneos.length)+kpi('Equipos',r.torneos.reduce((a,t)=>a+t.equipos,0))+kpi('Cobrado',mx(r.torneos.reduce((a,t)=>a+t.cobrado,0)))+kpi('Pendiente',mx(r.torneos.reduce((a,t)=>a+t.pendiente,0)))+'</div>'+tabla(['Espacio','Equipos','Cobrado','Pendiente','Permiso'],r.torneos.map(t=>[esc(t.nombre),t.equipos,mx(t.cobrado),mx(t.pendiente),t.escritura?'Administrar':'Monitoreo']));
  }catch(e){aviso(e.message);}
}
function pintar(){
  const esEquipo=usuario.rol==='equipo';
  const tabs=esEquipo?[['match','Match'],['cuentas','Estado de cuenta']]:[['inicio','Inicio'],['equipos','Equipos'],['cancha','Cancha'],['rival','Busca un rival'],['reportes','Reportes'],['finanzas','Tesorería / Finanzas'],['cuentas','Estados de Cuenta'],['usuarios','Usuarios']];
  if(usuario.rol==='administrador')tabs.unshift(['general','Dashboard general'],['espacios','Espacios digitales']);
  if(!tabs.some(t=>t[0]===tab))tab=tabs[0][0];
  $('menu').innerHTML=tabs.map(t=>'<button data-tab="'+t[0]+'"'+(t[0]===tab?' aria-current="page"':'')+'>'+esc(t[1])+'</button>').join('');
  $('nombre').textContent=usuario.nombre+' · '+usuario.rol;
  if(tab==='general'){pintarGeneral();return;}
  if(tab==='espacios'){
    $('vista').innerHTML=boton('Crear espacio','crear_espacio')+tabla(['Espacio','Edición','Acceso',''],espacios.map(e=>[esc(e.nombre),esc(e.edicion_ref),e.escritura?'Control propio':'Monitoreo',e.escritura?boton('Asignar coordinador','asignar:'+e.id):'']));return;
  }
  if(!panel){$('vista').innerHTML='<p>No hay espacios asignados.</p>';return;}
  let html='';
  if(!esEquipo&&!editable())html='<p>Monitoreo: este torneo pertenece a otro administrador.</p>';
  if(tab==='inicio'||tab==='finanzas'){
    const cargos=panel.cuentas.reduce((a,c)=>a+c.cargos,0),pagos=panel.cuentas.reduce((a,c)=>a+c.pagado,0),pend=panel.cuentas.reduce((a,c)=>a+Math.max(0,c.saldo),0);
    html+='<div class="kpis">'+kpi('Equipos',panel.equipos.length)+kpi('Cargos',mx(cargos))+kpi('Recaudado',mx(pagos))+kpi('Por cobrar',mx(pend))+kpi('Gastos nuevos',mx(panel.gastos_nuevos))+'</div><p><small>Recaudación: histórico de Tesorería más movimientos del espacio. Gastos nuevos: libro del espacio; los gastos históricos generales no se atribuyen automáticamente.</small></p>';
    if(tab==='finanzas')html+=(editable()?boton('Registrar cargo / pago / gasto','movimiento'):'')+tabla(['Fecha','Tipo','Concepto','Monto'],panel.movimientos.map(m=>[esc(m.fecha),esc(m.tipo),esc(m.concepto),mx(m.monto)]));
    else html+=agenda();
  } else if(['cancha','rival','match'].includes(tab))html+=agenda(tab==='rival')+(esEquipo?boton('Reportar incidencia','incidencia'):'');
  else if(tab==='equipos')html+=(editable()?boton('Vincular equipo registrado','equipo'):'')+tabla(['Equipo','Categoría','Referencia'],panel.equipos.map(e=>[esc(e.nombre),esc(e.categoria),esc(e.origen_ref)]));
  else if(tab==='cuentas')html+=panel.cuentas.map(c=>'<details><summary>'+esc(c.nombre)+' · Saldo '+mx(c.saldo)+'</summary>'+tabla(['Fecha','Concepto','Cargo','Pago','Origen'],c.movimientos.map(m=>[esc(m.fecha),esc(m.concepto),mx(m.cargo),mx(m.pago),esc(m.origen)]))+'</details>').join('');
  else if(tab==='reportes')html+=tabla(['Equipo','Mensaje','Estado','Respuesta',''],panel.reportes.map(r=>[esc(panel.equipos.find(e=>Number(e.id)===Number(r.equipo_id))?.nombre),esc(r.mensaje),esc(r.estado),esc(r.respuesta),editable()&&r.estado==='pendiente'?boton('Resolver','reporte:'+r.id):'']));
  else if(tab==='usuarios')html+=(usuario.rol==='administrador'&&editable()?boton('Crear coordinador','crear_coordinador'):'')+tabla(['Equipo','Usuario asignado',''],panel.equipos.map(e=>[esc(e.nombre),e.usuario_id?'Sí':'No',editable()?boton('Generar / restablecer contraseña','usuario:'+e.id)+' '+boton('Desactivar','desactivar:'+e.id):'']));
  $('vista').innerHTML=html;
}
function partido(p={}) {
  formulario(p.id?'Editar partido':'Programar partido',select('local_id','Local',equipos(),p.local_id)+select('visita_id','Visitante',equipos(),p.visita_id)+campo('fecha','Fecha','date',p.fecha||'')+campo('hora','Hora','time',(p.hora||'').slice(0,5))+campo('cancha','Cancha','text',p.cancha||'','maxlength="100"')+campo('duracion','Duración en minutos','number',p.duracion||60,'min="15" max="240"')+select('tipo','Tipo',[['torneo','Torneo'],['amistoso','Amistoso']],p.tipo)+select('estado','Estado',[['programado','Programado'],['jugado','Jugado'],['cancelado','Cancelado']],p.estado)+campo('goles_local','Puntos local (si ya se jugó)','number',p.goles_local??0,'min="0" max="999"')+campo('goles_visita','Puntos visitante (si ya se jugó)','number',p.goles_visita??0,'min="0" max="999"'),'partido',{id:p.id||0,version:p.version||0});
}
$('menu').onclick=ev=>{const b=ev.target.closest('[data-tab]');if(b){tab=b.dataset.tab;pintar();}};
$('vista').onclick=async ev=>{
  const b=ev.target.closest('[data-accion]');if(!b)return;
  const [a,id]=b.dataset.accion.split(':');
  try {
    if(a==='partido'||a==='amistoso')partido({tipo:a==='amistoso'?'amistoso':'torneo'});
    if(a==='editar')partido(panel.partidos.find(p=>String(p.id)===id));
    if(a==='movimiento')formulario('Registrar movimiento',select('tipo','Tipo',[['cargo','Cargo'],['pago','Pago'],['gasto','Gasto']])+select('equipo_id','Equipo (gastos: no aplica)',equipos())+campo('monto','Monto MXN','number','','min="0.01" step="0.01"')+campo('concepto','Concepto','text','','maxlength="150"')+campo('fecha','Fecha','date'),'movimiento');
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
  $('espacio').innerHTML=opts(espacios.map(e=>[e.id,e.nombre+(e.escritura?'':' · consulta')]));
  if(usuario.rol==='administrador')tab='general';await cargar();
}
$('login').onsubmit=async ev=>{ev.preventDefault();ev.submitter.disabled=true;try{await api('entrar',Object.fromEntries(new FormData(ev.target)));ev.target.reset();aviso('');await iniciar();}catch(e){aviso(e.message);}finally{ev.submitter.disabled=false;}};
$('salir').onclick=async()=>{try{await api('salir',{});location.reload();}catch(e){aviso(e.message);}};
iniciar().catch(e=>aviso(e.message));
