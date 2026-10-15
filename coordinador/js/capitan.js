/* Panel de coordinación — VISTA DEL CAPITÁN (sólo lectura).
   Recibe la «vista» que arma el servidor: su equipo, lo público de sus torneos y su estado de cuenta.
   No hay ningún botón que modifique datos, y el servidor rechaza cualquier escritura con esta sesión.
   La misma función pinta la vista previa que abre el coordinador desde la ficha del equipo. */
(function () {
  'use strict';
  function pintar(host, V, op) {
    op = op || {};
    var e = V.equipo, hoy = C.hoy(), L = V.liga || {};
    var mios = V.partidos.filter(function (p) { return p.localId === e.id || p.visitaId === e.id; }).sort(C.ordenPartidos);
    var prox = mios.filter(function (p) { return p.estado === 'programado' || p.estado === 'suspendido'; });
    var jug = mios.filter(function (p) { return p.estado === 'finalizado'; }).reverse();
    var sig = prox.filter(function (p) { return p.estado === 'programado' && p.fecha && p.fecha >= hoy; })[0];
    var cta = C.cuenta(V.movs), g = 0, em = 0, pe = 0;
    jug.forEach(function (p) { var a = p.localId === e.id ? p.gl : p.gv, b = p.localId === e.id ? p.gv : p.gl; if (a > b) g++; else if (a < b) pe++; else em++; });
    var rival = function (p) { return C.nom(V.equipos, p.localId === e.id ? p.visitaId : p.localId); };
    function fila(p) {
      var local = p.localId === e.id, res;
      if (p.estado === 'finalizado') { var a = local ? p.gl : p.gv, b = local ? p.gv : p.gl; res = '<span class="marcador">' + a + '–' + b + '</span><span class="pill ' + (a > b ? 'ok' : a < b ? 'mal' : '') + '">' + (a > b ? 'Ganó' : a < b ? 'Perdió' : 'Empató') + '</span>'; }
      else res = C.pillPartido(p);
      return '<li class="item"><span class="p"><b>vs ' + C.esc(rival(p)) + '</b><small>' + (local ? 'Local' : 'Visitante') + ' · ' + C.esc(C.nom(V.torneos, p.torneoId)) + ' · ' + C.esc(C.nom(V.jornadas, p.jornadaId, 'Sin jornada'))
        + (C.lugar(L.sedes || [], p) ? ' · ' + C.esc(C.lugar(L.sedes, p)) : '') + (p.motivo && p.estado !== 'finalizado' ? ' · ' + C.esc(p.motivo) : '') + '</small></span>'
        + '<span class="d"><span class="num suave">' + C.fechaDia(p.fecha) + (p.hora ? ' · ' + C.hora(p.hora) : '') + '</span>' + res + '</span></li>';
    }
    var color = /^#[0-9a-f]{6}$/i.test(L.color || '') ? L.color : '#123B63';
    var h = '<div style="--marca:' + color + '">'
      + (V.espacio === 'demo' ? '<div class="banda-demo"><b>Demostración.</b> <span>Equipo y datos de ejemplo.</span></div>' : '')
      + '<header class="cap-cab"><div class="cap-in"><div class="cap-sup"><span class="marca"><span class="escudo">' + C.escudo(L) + '</span><span>' + C.esc(L.nombre || 'Liga') + '</span></span>'
      + (op.previa ? '' : '<button type="button" class="ico-btn" data-a="actualizar" aria-label="Actualizar" title="Actualizar" style="width:auto;padding:0 12px;font-weight:600;font-size:13px">Actualizar</button><button type="button" class="ico-btn" data-a="salir" style="width:auto;padding:0 12px;font-weight:600;font-size:13px">Salir</button>') + '</div>'
      + '<span class="lectura">Sólo consulta</span><h1>' + C.esc(e.nombre) + '</h1>'
      + '<div class="ruta">' + C.esc(V.torneos.map(function (t) { return t.nombre; }).join(' · ') || 'Sin torneo asignado') + ((e.capitan || {}).nombre ? ' · Capitán: ' + C.esc(e.capitan.nombre) : '') + '</div>'
      + '<div class="cap-kpis"><div><b>' + (sig ? C.fechaDia(sig.fecha) : '—') + '</b><span>' + (sig ? 'Próximo: vs ' + C.esc(rival(sig)) + (sig.hora ? ' · ' + C.hora(sig.hora) : '') : 'Sin partido programado') + '</span></div>'
      + '<div><b>' + g + '-' + em + '-' + pe + '</b><span>Ganados · empatados · perdidos</span></div>'
      + '<div><b>' + C.mxn0(Math.abs(cta.saldo)) + '</b><span>' + (cta.saldo > 0.009 ? 'Saldo por pagar' : cta.saldo < -0.009 ? 'Saldo a favor' : 'Al corriente') + '</span></div></div></div></header>'
      + '<div class="cap-cuerpo">'
      + '<section class="tarjeta"><div class="cab"><h2>Próximos partidos</h2></div>' + (prox.length ? '<ul class="lista">' + prox.map(fila).join('') + '</ul>' : C.vacio('No tienes partidos por jugar.')) + '</section>'
      + V.torneos.map(function (t) { return '<section class="tarjeta"><div class="cab"><h2>Tabla · ' + C.esc(t.nombre) + '</h2></div>' + C.tablaHTML(C.tabla(t, V.partidos, V.equipos), e.id) + '</section>'; }).join('')
      + '<section class="tarjeta"><div class="cab"><h2>Resultados</h2></div>' + (jug.length ? '<ul class="lista">' + jug.map(fila).join('') + '</ul>' : C.vacio('Todavía no hay resultados.')) + '</section>'
      + '<section class="tarjeta"><div class="cab"><h2>Estado de cuenta</h2><small class="suave">Importes en pesos mexicanos</small></div>' + C.cuentaHTML(V.movs) + '</section>'
      + '<section class="tarjeta"><div class="cab"><h2>Plantilla (' + (e.plantilla || []).length + ')</h2></div>' + ((e.plantilla || []).length ? '<ul class="lista">' + e.plantilla.slice().sort(function (a, b) { return (a.numero || 999) - (b.numero || 999); }).map(function (j) {
          return '<li class="item"><span class="p"><b>' + C.esc(j.nombre) + '</b></span><span class="d num suave">' + (j.numero == null ? '' : '#' + j.numero) + '</span></li>'; }).join('') + '</ul>' : C.vacio('Sin jugadores registrados.')) + '</section>'
      + (((L.contacto || {}).telefono || (L.contacto || {}).correo) ? '<p class="suave" style="font-size:13px;text-align:center">¿Algo no coincide? Contacta a la coordinación: ' + C.esc([L.contacto.responsable, L.contacto.telefono, L.contacto.correo].filter(Boolean).join(' · ')) + '</p>' : '')
      + '</div></div>';
    host.innerHTML = h;
  }
  C.capitanUI = { pintar: pintar };
})();
