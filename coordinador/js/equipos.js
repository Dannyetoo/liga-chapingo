/* Panel de coordinación — EQUIPOS: alta y edición, capitán, inscripción, plantilla, calendario, resultados,
   estado de cuenta, historial y la vista de sólo lectura que ve el capitán. */
(function () {
  'use strict';
  var INSC = { pendiente: ['Inscripción pendiente', 'av'], completa: ['Inscrito', 'ok'], baja: ['Baja', 'mal'] };
  var busca = '';

  C.modulo('equipos', {
    titulo: 'Equipos',
    pintar: function (v, args) {
      var e = args[0] ? C.porId(C.S.equipos, args[0]) : null;
      if (e) return detalle(v, e, args[1] || 'resumen');
      lista(v);
    }
  });

  function saldoDe(e) { return C.cuenta(C.movs().filter(function (m) { return m.equipoId === e.id; })).saldo; }
  function torneosDe(e) { return C.S.torneos.filter(function (t) { return (t.equipos || []).indexOf(e.id) >= 0; }); }

  function lista(v) {
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Equipos</h2><div class="acc"><button type="button" class="btn pri" data-nuevo>' + C.ICO.mas + 'Nuevo equipo</button></div></div>'
      + '<div class="barra-h"><input type="search" placeholder="Buscar equipo o capitán…" value="' + C.esc(busca) + '" aria-label="Buscar"></div><div data-lista></div></section>');
    function pinta() {
      var q = C.norm(busca), eqs = C.equipos().filter(function (e) { return !q || C.norm(e.nombre + ' ' + ((e.capitan || {}).nombre || '')).indexOf(q) >= 0; })
        .sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
      n.querySelector('[data-lista]').innerHTML = eqs.length ? '<ul class="lista">' + eqs.map(function (e) {
        var s = saldoDe(e), ts = torneosDe(e), i = INSC[(e.inscripcion || {}).estado] || INSC.pendiente;
        return '<li class="item click" data-id="' + e.id + '"><span class="p"><b>' + C.esc(e.nombre) + '</b><small>' + C.esc((e.capitan || {}).nombre || 'Sin capitán')
          + ' · ' + (e.plantilla || []).length + ' jugadores' + (ts.length ? ' · ' + C.esc(ts.map(function (t) { return C.nom(C.S.categorias, t.categoriaId); }).filter(function (x, k, a) { return a.indexOf(x) === k; }).join(', ')) : ' · sin torneo') + '</small></span>'
          + '<span class="d">' + (s > 0.009 ? '<span class="pill mal num">Debe ' + C.mxn0(s) + '</span>' : s < -0.009 ? '<span class="pill az num">A favor ' + C.mxn0(-s) + '</span>' : '') + '<span class="pill ' + i[1] + '">' + i[0] + '</span></span></li>'; }).join('') + '</ul>'
        : C.vacio(C.S.equipos.length ? 'No hay equipos con estos filtros' + (C.hayFiltro() ? ' (se muestran los inscritos en torneos de la temporada y categoría elegidas).' : '.') : 'Todavía no hay equipos.');
    }
    pinta();
    n.querySelector('input').oninput = function () { busca = this.value; pinta(); };
    n.querySelector('[data-nuevo]').onclick = function () { editar(null); };
    n.addEventListener('click', function (e) { var li = e.target.closest('[data-id]'); if (li) C.ir('equipos', li.getAttribute('data-id')); });
    v.appendChild(n);
    if (C.hayFiltro() && C.S.equipos.length > C.equipos().length) {
      v.appendChild(C.el('<p class="suave" style="font-size:12.5px">Con los filtros actuales se ocultan ' + (C.S.equipos.length - C.equipos().length) + ' equipos que no están en esos torneos. Elige «Todas» para verlos.</p>'));
    }
  }

  function editar(e) {
    var S = C.S, ts = S.torneos.filter(function (t) { return !C.temporadaCerrada(t.temporadaId) && t.estado !== 'cancelado' && t.estado !== 'finalizado'; });
    C.formulario({
      titulo: e ? 'Editar equipo' : 'Nuevo equipo', ancho: true,
      valores: e ? { nombre: e.nombre, capNombre: (e.capitan || {}).nombre, capTel: (e.capitan || {}).telefono, capCorreo: (e.capitan || {}).correo, insc: (e.inscripcion || {}).estado || 'pendiente',
                     inscFecha: (e.inscripcion || {}).fecha, notas: e.notas, torneos: torneosDe(e).map(function (t) { return t.id; }) }
                 : { insc: 'pendiente', inscFecha: C.hoy(), torneos: C.torneos().length === 1 ? [C.torneos()[0].id] : [] },
      campos: [
        { k: 'nombre', t: 'Nombre del equipo', req: true },
        { k: 'capNombre', t: 'Capitán', mitad: true }, { k: 'capTel', t: 'Teléfono del capitán', tipo: 'tel', mitad: true, max: 20 },
        { k: 'capCorreo', t: 'Correo del capitán', tipo: 'email', mitad: true }, { k: 'insc', t: 'Inscripción', tipo: 'select', req: true, vacio: false, mitad: true, op: Object.keys(INSC).map(function (k) { return [k, INSC[k][0]]; }) },
        { k: 'inscFecha', t: 'Fecha de inscripción', tipo: 'date' },
        { k: 'torneos', t: 'Torneos en los que participa', tipo: 'checks', op: ts.map(function (t) { return [t.id, t.nombre + ' · ' + C.nom(S.temporadas, t.temporadaId)]; }) },
        { k: 'notas', t: 'Notas internas', tipo: 'textarea', ayuda: 'Sólo las ve la coordinación.' }
      ],
      borrar: e ? function () { borrar(e); } : null,
      alGuardar: function (o) {
        if (S.equipos.some(function (x) { return x !== e && C.norm(x.nombre) === C.norm(o.nombre); })) return 'Ya existe un equipo con ese nombre.';
        if (e) {
          var fuera = ts.filter(function (t) { return (t.equipos || []).indexOf(e.id) >= 0 && o.torneos.indexOf(t.id) < 0 && S.partidos.some(function (p) { return p.torneoId === t.id && (p.localId === e.id || p.visitaId === e.id); }); });
          if (fuera.length) return 'No se puede sacar del torneo «' + fuera[0].nombre + '»: el equipo ya tiene partidos ahí.';
        }
        var nuevo = !e;
        if (nuevo) { e = { id: C.uid('e'), codigo: C.nuevoCodigo(), plantilla: [], creado: C.hoy() }; S.equipos.push(e); }
        e.nombre = o.nombre; e.capitan = { nombre: o.capNombre, telefono: o.capTel, correo: o.capCorreo };
        e.inscripcion = { estado: o.insc, fecha: o.inscFecha }; e.notas = o.notas;
        ts.forEach(function (t) {
          var i = (t.equipos = t.equipos || []).indexOf(e.id), quiere = o.torneos.indexOf(t.id) >= 0;
          if (quiere && i < 0) t.equipos.push(e.id); else if (!quiere && i >= 0) t.equipos.splice(i, 1);
        });
        C.guardar((nuevo ? 'Equipo creado: ' : 'Equipo editado: ') + e.nombre, { equipoId: e.id });
        setTimeout(function () { if (nuevo) C.ir('equipos', e.id); else C.pintar(); }, 0);
      }
    });
  }
  function borrar(e) {
    var S = C.S;
    if (S.partidos.some(function (p) { return p.localId === e.id || p.visitaId === e.id; }) || S.movs.some(function (m) { return m.equipoId === e.id; }))
      return C.aviso('No se puede eliminar', 'Este equipo tiene partidos o movimientos en su estado de cuenta. Para retirarlo, cambia su inscripción a «Baja»: así se conserva su historial.');
    C.confirmar('Eliminar equipo', '¿Eliminar «' + e.nombre + '»? No tiene partidos ni movimientos.', function () {
      S.torneos.forEach(function (t) { t.equipos = (t.equipos || []).filter(function (id) { return id !== e.id; }); });
      S.equipos = S.equipos.filter(function (x) { return x !== e; });
      C.guardar('Equipo eliminado: ' + e.nombre); C.ir('equipos');
    }, { peligro: true, boton: 'Eliminar' });
  }

  /* ---------------- ficha del equipo ---------------- */
  function detalle(v, e, tab) {
    var S = C.S, i = INSC[(e.inscripcion || {}).estado] || INSC.pendiente, ids = C.idsTorneos();
    var ps = S.partidos.filter(function (p) { return ids[p.torneoId] && (p.localId === e.id || p.visitaId === e.id); }).sort(C.ordenPartidos);
    var movs = C.movs().filter(function (m) { return m.equipoId === e.id; }), cta = C.cuenta(movs);
    var cab = C.el('<div><p style="margin:0 0 12px"><a href="#/equipos">← Equipos</a></p>'
      + '<section class="tarjeta"><div class="cab"><h2>' + C.esc(e.nombre) + ' <span class="pill ' + i[1] + '">' + i[0] + '</span></h2><div class="acc">'
      + '<button type="button" class="btn" data-a="capitan">' + C.ICO.ojo + 'Ver como capitán</button><button type="button" class="btn pri" data-a="editar">Editar</button></div></div>'
      + (C.hayFiltro() ? '<p class="suave" style="font-size:12.5px;margin:-6px 0 10px">Partidos y movimientos según los filtros de temporada y categoría de arriba.</p>' : '')
      + '<div data-tabs></div><div data-cuerpo></div></section></div>');
    cab.querySelector('[data-tabs]').appendChild(C.pestanas([['resumen', 'Resumen'], ['plantilla', 'Plantilla (' + (e.plantilla || []).length + ')'], ['calendario', 'Calendario'],
      ['resultados', 'Resultados'], ['cuenta', 'Estado de cuenta'], ['historial', 'Historial']], tab, function (p) { C.ir('equipos', e.id, p); }));
    var c = cab.querySelector('[data-cuerpo]');
    cab.querySelector('[data-a="editar"]').onclick = function () { editar(e); };
    cab.querySelector('[data-a="capitan"]').onclick = function () { verComoCapitan(e); };
    v.appendChild(cab);

    if (tab === 'plantilla') return plantilla(c, e);
    if (tab === 'calendario') {
      var prox = ps.filter(function (p) { return p.estado === 'programado' || p.estado === 'suspendido'; });
      c.innerHTML = prox.length ? '<ul class="lista">' + prox.map(function (p) { return filaPartido(p, e); }).join('') + '</ul>' : C.vacio('Sin partidos por jugar.');
      return;
    }
    if (tab === 'resultados') {
      var jug = ps.filter(function (p) { return p.estado === 'finalizado' || p.estado === 'cancelado'; }).reverse();
      c.innerHTML = jug.length ? '<ul class="lista">' + jug.map(function (p) { return filaPartido(p, e); }).join('') + '</ul>' : C.vacio('Todavía no hay resultados.');
      return;
    }
    if (tab === 'cuenta') {
      c.innerHTML = '<div class="kpis"><div class="kpi"><div class="v num">' + C.mxn0(cta.cargos + cta.ajustes) + '</div><div class="e">Cargos</div><div class="s">' + (cta.ajustes ? 'Incluye ajustes por ' + C.mxn(cta.ajustes) : 'Sin ajustes') + '</div></div>'
        + '<div class="kpi bien"><div class="v num">' + C.mxn0(cta.pagos) + '</div><div class="e">Pagado</div><div class="s">&nbsp;</div></div>'
        + '<div class="kpi ' + (cta.saldo > 0.009 ? 'alerta' : 'bien') + '"><div class="v num">' + C.mxn0(Math.abs(cta.saldo)) + '</div><div class="e">' + (cta.saldo > 0.009 ? 'Saldo por pagar' : cta.saldo < -0.009 ? 'Saldo a favor' : 'Al corriente') + '</div><div class="s">&nbsp;</div></div></div>'
        + '<div class="barra-h"><button type="button" class="btn sm" data-m="cargo">+ Cargo</button><button type="button" class="btn sm pri" data-m="pago">+ Pago</button><button type="button" class="btn sm" data-m="ajuste">+ Ajuste</button>'
        + '<span class="esp"></span><button type="button" class="btn sm" data-imp>Imprimir</button></div>' + C.cuentaHTML(movs);
      c.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-m]'); if (b) return C.finanzasUI.movimiento(b.getAttribute('data-m'), e.id);
        if (ev.target.closest('[data-imp]')) C.finanzasUI.imprimirCuenta(e, movs);
      });
      return;
    }
    if (tab === 'historial') {
      var idsP = {}; S.partidos.forEach(function (p) { if (p.localId === e.id || p.visitaId === e.id) idsP[p.id] = 1; });
      var h = S.bitacora.filter(function (b) { return b.equipoId === e.id || (b.partidoId && idsP[b.partidoId]); }).reverse().slice(0, 80);
      c.innerHTML = h.length ? '<ul class="hist">' + h.map(function (b) { return '<li><time>' + C.selloTxt(b.ts) + ' · ' + C.esc(b.por || '') + '</time>' + C.esc(b.accion) + (b.detalle ? '<br><small class="suave">' + C.esc(b.detalle) + '</small>' : '') + '</li>'; }).join('') + '</ul>'
        : C.vacio('Sin movimientos registrados para este equipo.');
      return;
    }
    /* resumen */
    var fin = ps.filter(function (p) { return p.estado === 'finalizado'; }), g = 0, em = 0, pe = 0;
    fin.forEach(function (p) { var a = p.localId === e.id ? p.gl : p.gv, b = p.localId === e.id ? p.gv : p.gl; if (a > b) g++; else if (a < b) pe++; else em++; });
    var sig = ps.filter(function (p) { return p.estado === 'programado' && p.fecha && p.fecha >= C.hoy(); })[0];
    c.innerHTML = '<div class="kpis"><div class="kpi"><div class="v num">' + fin.length + '</div><div class="e">Partidos jugados</div><div class="s">' + g + ' G · ' + em + ' E · ' + pe + ' P</div></div>'
      + '<a class="kpi ' + (cta.saldo > 0.009 ? 'alerta' : 'bien') + '" href="#/equipos/' + e.id + '/cuenta"><div class="v num">' + C.mxn0(Math.abs(cta.saldo)) + '</div><div class="e">' + (cta.saldo > 0.009 ? 'Saldo por pagar' : cta.saldo < -0.009 ? 'A favor' : 'Al corriente') + '</div><div class="s">Ver estado de cuenta</div></a>'
      + '<a class="kpi" href="#/equipos/' + e.id + '/calendario"><div class="v" style="font-size:22px">' + (sig ? C.fechaDia(sig.fecha) : '—') + '</div><div class="e">Próximo partido</div><div class="s">' + (sig ? C.esc('vs ' + C.nom(S.equipos, sig.localId === e.id ? sig.visitaId : sig.localId)) + (sig.hora ? ' · ' + C.hora(sig.hora) : '') : 'Sin partidos programados') + '</div></a></div>'
      + '<dl class="ficha"><div><dt>Capitán</dt><dd>' + C.esc((e.capitan || {}).nombre || '—') + '</dd></div><div><dt>Teléfono</dt><dd>' + C.esc((e.capitan || {}).telefono || '—') + '</dd></div>'
      + '<div><dt>Correo</dt><dd>' + C.esc((e.capitan || {}).correo || '—') + '</dd></div><div><dt>Inscripción</dt><dd>' + i[0] + ((e.inscripcion || {}).fecha ? ' · ' + C.fecha(e.inscripcion.fecha) : '') + '</dd></div>'
      + '<div><dt>Torneos</dt><dd>' + (torneosDe(e).map(function (t) { return '<a href="#/torneos/ver/' + t.id + '">' + C.esc(t.nombre) + '</a>'; }).join(', ') || '—') + '</dd></div>'
      + (e.notas ? '<div><dt>Notas internas</dt><dd>' + C.esc(e.notas) + '</dd></div>' : '') + '</dl>'
      + '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--linea)"><div class="cab" style="margin-bottom:6px"><h3 style="font-size:17px">Acceso del capitán (sólo lectura)</h3></div>'
      + '<p class="suave" style="font-size:13px;margin:0 0 10px">Con este código el capitán consulta calendario, resultados, tabla y estado de cuenta de su equipo. No puede modificar nada ni ver a otros equipos.</p>'
      + '<div class="barra-h" style="margin:0"><span class="codigo" data-cod>••••-••••</span><button type="button" class="btn sm" data-c="ver">Ver</button><button type="button" class="btn sm" data-c="copiar">Copiar</button>'
      + '<button type="button" class="btn sm peligro" data-c="nuevo">Cambiar código</button></div></div>';
    c.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-c]'); if (!b) return;
      var a = b.getAttribute('data-c'), s = c.querySelector('[data-cod]');
      if (a === 'ver') { var oc = s.textContent.indexOf('•') >= 0; s.textContent = oc ? e.codigo : '••••-••••'; b.textContent = oc ? 'Ocultar' : 'Ver'; }
      else if (a === 'copiar') { (navigator.clipboard ? navigator.clipboard.writeText(e.codigo) : Promise.reject()).then(function () { C.toast('Código copiado'); }, function () { s.textContent = e.codigo; C.toast('Cópialo de la pantalla'); }); }
      else if (a === 'nuevo') C.confirmar('Cambiar código', 'El código actual dejará de funcionar y tendrás que darle el nuevo al capitán.', function () {
        e.codigo = C.nuevoCodigo(); C.guardar('Se cambió el código de acceso del capitán', { equipoId: e.id }); C.pintar(); }, { boton: 'Cambiar' });
    });
  }

  function filaPartido(p, e) {
    var S = C.S, local = p.localId === e.id, rival = C.nom(S.equipos, local ? p.visitaId : p.localId), res = '';
    if (p.estado === 'finalizado') {
      var a = local ? p.gl : p.gv, b = local ? p.gv : p.gl;
      res = '<span class="marcador">' + a + '–' + b + '</span><span class="pill ' + (a > b ? 'ok' : a < b ? 'mal' : '') + '">' + (a > b ? 'Ganó' : a < b ? 'Perdió' : 'Empató') + '</span>';
    } else res = C.pillPartido(p);
    return '<li class="item"><span class="p"><b>vs ' + C.esc(rival) + '</b><small>' + (local ? 'Local' : 'Visitante') + ' · ' + C.esc(C.nom(S.torneos, p.torneoId)) + ' · ' + C.esc(C.nom(S.jornadas, p.jornadaId, 'Sin jornada'))
      + (C.lugar(S.liga.sedes, p) ? ' · ' + C.esc(C.lugar(S.liga.sedes, p)) : '') + (p.motivo && p.estado !== 'finalizado' ? ' · ' + C.esc(p.motivo) : '') + '</small></span>'
      + '<span class="d"><span class="num suave">' + C.fechaDia(p.fecha) + (p.hora ? ' · ' + C.hora(p.hora) : '') + '</span>' + res + '</span></li>';
  }

  function plantilla(c, e) {
    var js = (e.plantilla = e.plantilla || []);
    c.innerHTML = '<div class="barra-h"><span class="esp"></span><button type="button" class="btn sm pri" data-nuevo>+ Jugador</button></div>'
      + (js.length ? '<div class="tabla-w"><table><thead><tr><th class="c">#</th><th>Jugador</th><th></th></tr></thead><tbody>'
        + js.slice().sort(function (a, b) { return (a.numero || 999) - (b.numero || 999) || a.nombre.localeCompare(b.nombre, 'es'); }).map(function (j) {
          return '<tr><td class="c num">' + (j.numero == null ? '—' : j.numero) + '</td><td>' + C.esc(j.nombre) + '</td><td class="n"><button type="button" class="btn-txt" data-j="' + j.id + '">Editar</button></td></tr>'; }).join('')
        + '</tbody></table></div>' : C.vacio('La plantilla está vacía.'));
    function ed(j) {
      C.formulario({
        titulo: j ? 'Editar jugador' : 'Nuevo jugador', valores: j || {},
        campos: [{ k: 'nombre', t: 'Nombre completo', req: true }, { k: 'numero', t: 'Número', tipo: 'number', min: 0 }],
        borrar: j ? function () { e.plantilla = js.filter(function (x) { return x !== j; }); C.cerrarModal(); C.guardar('Jugador dado de baja: ' + j.nombre, { equipoId: e.id }); C.pintar(); } : null, borrarTxt: 'Dar de baja',
        alGuardar: function (o) {
          if (js.some(function (x) { return x !== j && C.norm(x.nombre) === C.norm(o.nombre); })) return 'Ese jugador ya está en la plantilla.';
          if (o.numero != null && js.some(function (x) { return x !== j && x.numero === o.numero; })) return 'El número ' + o.numero + ' ya lo usa otro jugador.';
          if (j) { j.nombre = o.nombre; j.numero = o.numero; } else js.push({ id: C.uid('j'), nombre: o.nombre, numero: o.numero });
          C.guardar((j ? 'Jugador editado: ' : 'Jugador agregado: ') + o.nombre, { equipoId: e.id }); setTimeout(C.pintar, 0);
        }
      });
    }
    c.querySelector('[data-nuevo]').onclick = function () { ed(null); };
    c.addEventListener('click', function (ev) { var b = ev.target.closest('[data-j]'); if (b) ed(C.porId(js, b.getAttribute('data-j'))); });
  }

  /** Lo que ve el capitán: se pide al servidor con el MISMO filtro que usa su acceso, así la vista previa es fiel. */
  function verComoCapitan(e) {
    var caja = C.el('<div><div class="cap-vista-previa"><div class="vacio" style="border:0">Cargando…</div></div></div>');
    C.modal('Vista del capitán', caja, { ancho: true, sub: 'Sólo lectura · así lo ve ' + (((e.capitan || {}).nombre) || 'el capitán') + ' con su código', sinFoco: true });
    C.api('vista_capitan', null, { espacio: C.espacio, equipo: e.id }).then(function (r) {
      var h = caja.querySelector('.cap-vista-previa');
      if (!r.ok) { h.innerHTML = '<div class="error ver" style="margin:14px">' + C.esc(r.error || 'No se pudo cargar la vista.') + '</div>'; return; }
      h.innerHTML = ''; C.capitanUI.pintar(h, r.vista, { previa: true });
    });
  }

  C.equiposUI = { editar: editar };
})();
