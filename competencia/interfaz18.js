'use strict';
/* Presentación del corte documental; no inserta movimientos contables. */
const I18={
  exportar(){
    const filas=[['Equipo','Mensaje','Estado','Respuesta'],...panel.reportes.map(r=>[panel.equipos.find(e=>Number(e.id)===Number(r.equipo_id))?.nombre||'',r.mensaje,r.estado,r.respuesta||''])];
    const csv='\uFEFF'+filas.map(f=>f.map(x=>'"'+String(x??'').replace(/^[=+@\-\t\r]/,"'$&").replace(/"/g,'""')+'"').join(',')).join('\r\n');
    const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'})),a=document.createElement('a');
    a.href=url;a.download='reportes-espacio-'+sid()+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  },
  async deportivo(){
    const espacioActual=sid();
    try{
      const t=await api('torneo',null,{espacio_id:espacioActual});
      if(tab!=='inicio'||sid()!==espacioActual||!$('resumen-deportivo'))return;
      const datos=t.torneo||t;
      $('resumen-deportivo').innerHTML='<h2>Tabla general</h2>'+(datos.categorias||[]).map(c=>'<h3>'+esc(c.nombre)+'</h3>'+tabla(['#','Equipo','PJ','DG','PTS'],c.tabla.map(r=>[esc(r.pos),esc(panel.equipos.find(e=>Number(e.id)===Number(r.equipo_id))?.nombre||'Equipo'),esc(r.pj),esc(r.dg),esc(r.pts)]))).join('')+'<p><a href="torneo.html?espacio='+encodeURIComponent(espacioActual)+'">Ver goleo, porteros y calendario por jornada</a></p>';
    }catch(e){if(tab==='inicio'&&sid()===espacioActual&&$('resumen-deportivo'))$('resumen-deportivo').textContent='Estadísticas no disponibles: '+e.message;}
  },
  saldo(n){return '<span class="saldo '+(Number(n)>0?'deuda':'saldado')+'">'+mx(n)+'</span>';},
  abrir(t,h){$('detalle-titulo').textContent=t;$('detalle-contenido').innerHTML=h;$('detalle').showModal();},
  financiero(){
    const filas=[['Ingresos cobrados (efectivo y transferencias)',33466],['Gastos generales',7542],['Premio en efectivo · Relámpago B',1350],['Servicios SIDC liquidados',13800],['Salidas totales',22692],['Saldo total del torneo',10774],['Adán · pendiente de entregar',9835],['Administración · caja disponible',939]];
    this.abrir('Estado financiero · corte documental',
      '<p><a class="btn" href="api.php?a=estado_financiero_pdf" download="estado-financiero-2026.pdf">Descargar PDF oficial</a></p>'+ 
      '<p>Fuente: estado de resultados 2026.pdf, fechado el 04/10/2026. Importes en MXN.</p>'+tabla(['Concepto','Monto'],filas.map(r=>[esc(r[0]),mx(r[1])]))+
      '<p>El documento agrupa los ingresos y gastos: no separa arbitrajes, inscripciones, trofeos, logística ni las partes administrativa y coordinativa de SIDC.</p>'+
      '<article class="corte"><h3>Caja disponible al 18/10/2026</h3><strong>'+mx(939)+'</strong><p>Importe indicado en la actualización. Este corte es independiente de los movimientos en vivo de cada espacio.</p></article>'+
      '<h3>Devoluciones de inscripción · 18/10/2026</h3><p>Se realizó la devolución a los capitanes participantes en el Torneo Relámpago B:</p>'+
      tabla(['Equipo','Devolución'],[['BLACK KAISER',mx(350)],['NOSOTROS LOS GUAPOS 2.0',mx(350)],['PARIS',mx(350)],['LA 4T',mx(300)],['Total',mx(1350)]])+
      '<p>Las devoluciones se muestran conforme al XML. No se descuentan de nuevo de los $939 ni se identifican automáticamente con el premio de $1,350 del PDF.</p>'+documentos());
  },
  tarjeta(id,label,value,clase=''){return '<button type="button" class="kpi '+clase+'" data-accion="detalle:'+id+'" aria-haspopup="dialog"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong><small>Ver detalle ↗</small></button>';},
  metricas(){
    const cs=panel.cuentas,pend=cs.reduce((a,c)=>a+Math.max(0,Number(c.saldo)),0);
    return '<div class="kpis">'+this.tarjeta('equipos','Equipos',panel.equipos.length)+this.tarjeta('cargos','Cargos',mx(cs.reduce((a,c)=>a+Number(c.cargos),0)))+this.tarjeta('pagos','Recaudado',mx(cs.reduce((a,c)=>a+Number(c.pagado),0)))+this.tarjeta('deudas','Por cobrar',mx(pend),pend>0?'deuda':'saldado')+this.tarjeta('gastos','Gastos nuevos',mx(panel.gastos_nuevos))+'</div>';
  },
  detalle(id){
    const cs=panel.cuentas;
    if(id==='equipos')return this.abrir('Equipos por categoría',Array.from(new Set(panel.equipos.map(e=>e.categoria||'Sin categoría'))).map(cat=>'<h3>'+esc(cat)+'</h3>'+tabla(['Equipo','Acceso'],panel.equipos.filter(e=>(e.categoria||'Sin categoría')===cat).map(e=>[esc(e.nombre),esc(e.usuario||'Pendiente de generar')]))).join(''));
    if(id==='gastos')return this.abrir('Gastos registrados',tabla(['Fecha','Concepto','Monto','Quién recibió el pago'],panel.movimientos.filter(m=>m.tipo==='gasto').slice().reverse().map(m=>[esc(m.fecha),esc(m.concepto),mx(m.monto),esc(m.recibio||'Sin registrar')])));
    if(id==='cargos')return this.abrir('Cargos y partidos jugados','<h3>Cargos por equipo</h3>'+tabla(['Equipo','Cargos'],cs.map(c=>[esc(c.nombre),mx(c.cargos)]))+'<h3>Partidos jugados</h3>'+tabla(['Fecha','Partido','Marcador'],panel.partidos.filter(p=>p.estado==='jugado').map(p=>[esc(p.fecha),esc(panel.equipos.find(e=>Number(e.id)===Number(p.local_id))?.nombre)+' vs '+esc(panel.equipos.find(e=>Number(e.id)===Number(p.visita_id))?.nombre),esc(p.goles_local)+' – '+esc(p.goles_visita)]))+'<p>Los cargos históricos no siempre tienen un partido o una jornada asociados.</p>');
    const deuda=id==='deudas';
    this.abrir(deuda?'Adeudos por equipo':'Pagos por equipo',tabla(['Equipo',deuda?'Por cobrar':'Pagado'],cs.filter(c=>!deuda||Number(c.saldo)>0).map(c=>[esc(c.nombre),deuda?this.saldo(c.saldo):mx(c.pagado)])));
  },
  inicio(){
    const hoy=new Intl.DateTimeFormat('en-CA',{timeZone:'America/Mexico_City',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const ps=panel.partidos.filter(p=>p.fecha===hoy),nombre=id=>panel.equipos.find(e=>Number(e.id)===Number(id))?.nombre||'Equipo';
    return this.metricas()+'<div class="acciones resumen-acciones">'+boton('Ver Estado Financiero','financiero')+'<button type="button" data-tab="finanzas">Abrir Tesorería y Finanzas</button><a class="btn" href="api.php?a=estado_financiero_pdf" download="estado-financiero-2026.pdf">Descargar PDF</a></div><section class="bloque"><h2>Partidos de hoy</h2>'+(ps.length?tabla(['Hora','Partido','Cancha','Estado'],ps.map(p=>[esc(p.hora),esc(nombre(p.local_id))+' vs '+esc(nombre(p.visita_id)),esc(p.cancha),esc(p.estado)])):'<p>No hay partidos programados para hoy.</p>')+'</section><section class="bloque" id="resumen-deportivo"><p>Cargando tabla general…</p></section><section class="bloque"><h2>Roles de juego</h2>'+agenda()+'</section>';
  }
};
