/* Panel de coordinación — CALENDARIO: jornadas y partidos (fecha, hora, sede, cancha), lista o mes, filtros,
   reprogramación / suspensión / cancelación con motivo y detección de cruces de horario. */
(function () {
  'use strict';
  var F = { torneo: '', equipo: '', sede: '', estado: '', periodo: 'proximos', vista: 'lista', mes: '', dia: '' }, jTorneo = '';

  C.modulo('calendario', {
    titulo: 'Calendario',
    pintar: function (v) {
      var S = C.S, ids = C.idsTorneos();
      if (F.torneo && !ids[F.torneo]) F.torneo = '';
      if (F.equipo && !C.porId(S.equipos, F.equipo)) F.equipo = '';
      if (!F.mes) F.mes = C.hoy().slice(0, 7);
      var ts = C.torneos(), eqs = C.equipos().sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
      function sel(k, vacio, ops) { return '<select data-f="' + k + '" aria-label="' + vacio + '"><option value="">' + vacio + '</option>' + ops.map(function (o) { return '<option value="' + o[0] + '"' + (F[k] === o[0] ? ' selected' : '') + '>' + C.esc(o[1]) + '</option>'; }).join('') + '</select>'; }
      var n = C.el('<section class="tarjeta"><div class="cab"><h2>Partidos</h2><div class="acc"><button type="button" class="btn" data-a="jornadas">Jornadas</button><button type="button" class="btn pri" data-a="nuevo">' + C.ICO.mas + 'Programar partido</button></div></div>'
        + '<div class="barra-h">' + sel('torneo', 'Todos los torneos', ts.map(function (t) { return [t.id, t.nombre]; })) + sel('equipo', 'Todos los equipos', eqs.map(function (e) { return [e.id, e.nombre]; }))
        + sel('sede', 'Todas las sedes', S.liga.sedes.map(function (s) { return [s.id, s.nombre]; })) + sel('estado', 'Todos los estados', Object.keys(C.ESTADOS_PARTIDO).map(function (k) { return [k, C.ESTADOS_PARTIDO[k][0]]; }).concat([['sinfecha', 'Sin fecha u hora']]))
        + (F.vista === 'lista' ? '<select data-f="periodo" aria-label="Periodo">' + [['proximos', 'De hoy en adelante'], ['pasados', 'Anteriores'], ['todos', 'Todas las fechas']].map(function (o) { return '<option value="' + o[0] + '"' + (F.periodo === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' : '')
        + '<span class="esp"></span><div class="pestanas" style="margin:0"><button type="button" data-v="lista" aria-selected="' + (F.vista === 'lista') + '">Lista</button><button type="button" data-v="mes" aria-selected="' + (F.vista === 'mes') + '">Mes</button></div></div>'
        + '<div data-cuerpo></div></section>');
      var ps = C.partidos().filter(function (p) {
        return (!F.torneo || p.torneoId === F.torneo) && (!F.equipo || p.localId === F.equipo || p.visitaId === F.equipo) && (!F.sede || p.sedeId === F.sede)
          && (!F.estado || (F.estado === 'sinfecha' ? (p.estado === 'programado' && (!p.fecha || !p.hora)) : p.estado === F.estado));
      }).sort(C.ordenPartidos);
      var c = n.querySelector('[data-cuerpo]'), hoyC = C.hoy();
      if (F.vista === 'mes') mes(c, ps);
      else lista(c, ps.filter(function (p) { return F.periodo === 'todos' || F.estado === 'sinfecha' || (F.periodo === 'proximos' ? (!p.fecha || p.fecha >= hoyC) : (p.fecha && p.fecha < hoyC)); }), F.periodo === 'pasados');

      n.addEventListener('change', function (e) { var s = e.target.closest('[data-f]'); if (s) { F[s.getAttribute('data-f')] = s.value; C.pintar(); } });
      n.addEventListener('click', function (e) {
        var b = e.target.closest('[data-v]'); if (b) { F.vista = b.getAttribute('data-v'); return C.pintar(); }
        var a = e.target.closest('[data-a]'); if (a) return a.getAttribute('data-a') === 'nuevo' ? editar(null) : jornadas();
        var li = e.target.closest('[data-partido]'); if (li) abrir(C.porId(S.partidos, li.getAttribute('data-partido')));
      });
      v.appendChild(n);
    }
  });

  function item(p) {
    var S = C.S;
    return '<li class="item click" data-partido="' + p.id + '"><span class="p"><span class="vs"><span class="l">' + C.esc(C.nom(S.equipos, p.localId)) + '</span><span class="x">'
      + (p.estado === 'finalizado' ? p.gl + ' – ' + p.gv : 'vs') + '</span><span class="v">' + C.esc(C.nom(S.equipos, p.visitaId)) + '</span></span>'
      + '<small style="text-align:center">' + C.esc(C.nom(S.torneos, p.torneoId)) + ' · ' + C.esc(C.nom(S.jornadas, p.jornadaId, 'Sin jornada')) + (C.lugar(S.liga.sedes, p) ? ' · ' + C.esc(C.lugar(S.liga.sedes, p)) : ' · sin cancha')
      + (p.motivo && p.estado !== 'finalizado' ? ' · ' + C.esc(p.motivo) : '') + '</small></span>'
      + '<span class="d"><b class="num">' + (p.hora ? C.hora(p.hora) : 'Sin hora') + '</b>' + C.pillPartido(p) + '</span></li>';
  }
  function lista(c, ps, alReves) {
    if (!ps.length) { c.innerHTML = C.vacio(C.S.partidos.length ? 'No hay partidos con estos filtros.' : 'Todavía no hay partidos. Usa «Programar partido» o genera el rol desde un torneo.'); return; }
    var g = {}, orden = [];
    ps.forEach(function (p) { var k = p.fecha || ''; if (!g[k]) { g[k] = []; orden.push(k); } g[k].push(p); });
    orden.sort(function (a, b) { return (a || '9999').localeCompare(b || '9999'); });
    if (alReves) orden.reverse();
    var hoy = C.hoy(), h = '';
    orden.forEach(function (k) {
      h += '<h3 style="font-size:16px;margin:14px 0 8px;color:' + (k === hoy ? 'var(--marca)' : 'var(--gris)') + '">' + (k ? C.fechaDia(k) + (k === hoy ? ' · hoy' : '') : 'Sin fecha asignada') + ' <small class="suave" style="font-family:var(--texto);font-weight:400">· ' + g[k].length + '</small></h3><ul class="lista">' + g[k].map(item).join('') + '</ul>';
    });
    c.innerHTML = h;
  }
  function mes(c, ps) {
    var y = +F.mes.slice(0, 4), m = +F.mes.slice(5, 7) - 1, hoy = C.hoy(), por = {};
    ps.forEach(function (p) { if (p.fecha) (por[p.fecha] = por[p.fecha] || []).push(p); });
    var h = '<div class="mes-cab"><button type="button" class="btn sm" data-m="-1" aria-label="Mes anterior">‹</button><h3>' + C.MESES[m].charAt(0).toUpperCase() + C.MESES[m].slice(1) + ' ' + y + '</h3>'
      + '<button type="button" class="btn sm" data-m="0">Hoy</button><button type="button" class="btn sm" data-m="1" aria-label="Mes siguiente">›</button></div><div class="mes">'
      + ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map(function (d) { return '<div class="dow">' + d + '</div>'; }).join('');
    var hue = (new Date(y, m, 1).getDay() + 6) % 7, dias = new Date(y, m + 1, 0).getDate();
    for (var i = 0; i < hue; i++) h += '<div class="dia hueco"></div>';
    for (var d = 1; d <= dias; d++) {
      var f = F.mes + '-' + ('0' + d).slice(-2), l = por[f] || [];
      h += '<button type="button" class="dia' + (f === hoy ? ' hoy' : '') + '" data-d="' + f + '"' + (F.dia === f ? ' aria-pressed="true"' : '') + ' aria-label="' + d + ': ' + l.length + ' partidos"><span class="dn">' + d + '</span><span class="chs">'
        + l.slice(0, 3).map(function (p) { return '<span class="ch ' + (p.estado === 'finalizado' ? 'fin' : p.estado !== 'programado' ? 'mal' : '') + '">' + (p.hora ? C.hora(p.hora).replace(':00', '').replace(' ', '') + ' ' : '') + C.esc(C.nom(C.S.equipos, p.localId)) + '</span>'; }).join('')
        + (l.length > 3 ? '<span class="ch fin">+' + (l.length - 3) + '</span>' : '') + '</span></button>';
    }
    h += '</div><div data-dia style="margin-top:14px"></div>';
    c.innerHTML = h;
    function dia() { var l = por[F.dia] || []; c.querySelector('[data-dia]').innerHTML = F.dia && F.dia.slice(0, 7) === F.mes ? '<h3 style="font-size:16px;margin:0 0 8px">' + C.fechaDia(F.dia) + '</h3>' + (l.length ? '<ul class="lista">' + l.map(item).join('') + '</ul>' : C.vacio('Sin partidos este día.')) : ''; }
    dia();
    c.addEventListener('click', function (e) {
      var b = e.target.closest('[data-m]');
      if (b) { var k = +b.getAttribute('data-m'), nd = k === 0 ? new Date(+hoy.slice(0, 4), +hoy.slice(5, 7) - 1, 1) : new Date(y, m + k, 1); F.mes = nd.getFullYear() + '-' + ('0' + (nd.getMonth() + 1)).slice(-2); if (k === 0) F.dia = hoy; return C.pintar(); }
      var di = e.target.closest('[data-d]');
      if (di) { F.dia = di.getAttribute('data-d'); c.querySelectorAll('.dia[aria-pressed]').forEach(function (x) { x.removeAttribute('aria-pressed'); }); di.setAttribute('aria-pressed', 'true'); dia(); }
    });
  }

  /* ---------------- cruces de horario ---------------- */
  function minutos(h) { return +h.slice(0, 2) * 60 + +h.slice(3, 5); }
  /** Cruces de un partido propuesto contra TODOS los partidos programados de la liga (no sólo los del filtro). */
  function cruces(x) {
    var S = C.S, out = [];
    if (!x.fecha || !x.hora) return out;
    var dur = Math.max(10, Number(S.liga.duracion) || 60), t0 = minutos(x.hora);
    S.partidos.forEach(function (p) {
      if (p.id === x.id || p.estado !== 'programado' || p.fecha !== x.fecha || !p.hora) return;
      if (Math.abs(minutos(p.hora) - t0) >= dur) return;
      var quien = C.nom(S.equipos, p.localId) + ' vs ' + C.nom(S.equipos, p.visitaId) + ' (' + C.hora(p.hora) + ')';
      if (x.sedeId && x.canchaId && p.sedeId === x.sedeId && p.canchaId === x.canchaId) out.push('La cancha ya está ocupada: ' + quien + '.');
      [x.localId, x.visitaId].forEach(function (id) { if (id && (p.localId === id || p.visitaId === id)) out.push(C.nom(S.equipos, id) + ' ya juega a esa hora: ' + quien + '.'); });
    });
    return out;
  }
  C.cruces = cruces;

  /* ---------------- alta y edición ---------------- */
  function editar(p, pre) {
    var S = C.S, ts = S.torneos.filter(function (t) { return !C.temporadaCerrada(t.temporadaId) && (t.estado !== 'cancelado' || (p && p.torneoId === t.id)); });
    if (!ts.length) { C.aviso('Falta un torneo', 'Para programar partidos necesitas un torneo abierto con equipos inscritos.', function () { C.ir('torneos'); }); return; }
    var v = p ? Object.assign({}, p) : Object.assign({ torneoId: F.torneo || (C.torneos().length === 1 ? C.torneos()[0].id : ''), fecha: F.vista === 'mes' ? F.dia : '' }, pre || {});
    var yaTenia = !!(p && p.fecha && p.hora);
    var f = C.formulario({
      titulo: p ? 'Editar partido' : 'Programar partido', ancho: true, valores: v,
      campos: [
        { k: 'torneoId', t: 'Torneo', tipo: 'select', req: true, mitad: true, op: ts.map(function (t) { return [t.id, t.nombre]; }) },
        { k: 'jornadaId', t: 'Jornada', tipo: 'select', vacio: 'Sin jornada', mitad: true, op: [] },
        { k: 'localId', t: 'Local', tipo: 'select', req: true, mitad: true, op: [] }, { k: 'visitaId', t: 'Visitante', tipo: 'select', req: true, mitad: true, op: [] },
        { k: 'fecha', t: 'Fecha', tipo: 'date', mitad: true }, { k: 'hora', t: 'Hora', tipo: 'time', mitad: true },
        { k: 'sedeId', t: 'Sede', tipo: 'select', vacio: 'Sin sede', mitad: true, op: S.liga.sedes.map(function (s) { return [s.id, s.nombre]; }) },
        { k: 'canchaId', t: 'Cancha', tipo: 'select', vacio: 'Sin cancha', mitad: true, op: [] }
      ].concat(yaTenia ? [{ k: 'motivoCambio', t: 'Motivo del cambio', ayuda: 'Obligatorio si cambias fecha, hora, sede o cancha: queda en el historial como reprogramación.' }] : [])
       .concat(S.liga.sedes.length ? [] : [{ tipo: 'nota', t: 'Aún no hay sedes. Agrégalas en <a href="#/configuracion">Configuración</a> para asignar cancha y detectar cruces.' }]),
      alAbrir: function (fm) {
        function llena(sel, ops, val, vacio) { sel.innerHTML = '<option value="">' + vacio + '</option>' + ops.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === val ? ' selected' : '') + '>' + C.esc(o[1]) + '</option>'; }).join(''); }
        function porTorneo() {
          var t = C.porId(S.torneos, fm.elements.torneoId.value);
          llena(fm.elements.jornadaId, t ? S.jornadas.filter(function (j) { return j.torneoId === t.id; }).sort(function (a, b) { return a.numero - b.numero; }).map(function (j) { return [j.id, j.nombre + (j.fecha ? ' · ' + C.fecha(j.fecha) : '')]; }) : [], fm.elements.jornadaId.value || v.jornadaId, 'Sin jornada');
          var eq = t ? (t.equipos || []).map(function (id) { return C.porId(S.equipos, id); }).filter(Boolean).sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); }).map(function (e) { return [e.id, e.nombre]; }) : [];
          llena(fm.elements.localId, eq, fm.elements.localId.value || v.localId, 'Elegir…'); llena(fm.elements.visitaId, eq, fm.elements.visitaId.value || v.visitaId, 'Elegir…');
        }
        function porSede() { var s = C.porId(S.liga.sedes, fm.elements.sedeId.value); llena(fm.elements.canchaId, s ? (s.canchas || []).map(function (c) { return [c.id, c.nombre]; }) : [], fm.elements.canchaId.value || v.canchaId, 'Sin cancha'); }
        fm.elements.torneoId.onchange = porTorneo; fm.elements.sedeId.onchange = porSede;
        fm.elements.jornadaId.onchange = function () { var j = C.porId(S.jornadas, this.value); if (j && j.fecha && !fm.elements.fecha.value) fm.elements.fecha.value = j.fecha; };
        porTorneo(); porSede();
      },
      borrar: p && p.estado !== 'finalizado' ? function () {
        C.confirmar('Eliminar partido', 'Se borra del calendario. Si el partido sí existía y no se jugará, es mejor «Cancelar» para conservar el motivo.', function () {
          S.partidos = S.partidos.filter(function (x) { return x !== p; }); C.guardar('Partido eliminado: ' + C.nom(S.equipos, p.localId) + ' vs ' + C.nom(S.equipos, p.visitaId), { torneoId: p.torneoId }); C.pintar();
        }, { peligro: true, boton: 'Eliminar' });
      } : null,
      alGuardar: function (o, fm) {
        var t = C.porId(S.torneos, o.torneoId);
        if (o.localId === o.visitaId) return 'Local y visitante no pueden ser el mismo equipo.';
        if (o.hora && !o.fecha) return 'Para poner hora primero indica la fecha.';
        if (o.canchaId && !o.sedeId) return 'Elige la sede de la cancha.';
        if (p && p.estado === 'finalizado' && (o.localId !== p.localId || o.visitaId !== p.visitaId)) return 'Este partido ya tiene resultado: no se pueden cambiar los equipos.';
        /* duplicados: el mismo cruce en la misma jornada del torneo */
        var dup = S.partidos.filter(function (x) { return x !== p && x.torneoId === o.torneoId && x.estado !== 'cancelado' && (o.jornadaId || '') === (x.jornadaId || '')
          && ((x.localId === o.localId && x.visitaId === o.visitaId) || (x.localId === o.visitaId && x.visitaId === o.localId)); });
        if (dup.length && o.jornadaId) return 'Ese cruce ya existe en esta jornada.';
        var cambioHorario = yaTenia && (o.fecha !== p.fecha || o.hora !== p.hora || o.sedeId !== p.sedeId || o.canchaId !== p.canchaId);
        if (cambioHorario && !(o.motivoCambio || '').trim()) return 'Escribe el motivo de la reprogramación.';
        var av = cruces({ id: p ? p.id : '', fecha: o.fecha, hora: o.hora, sedeId: o.sedeId, canchaId: o.canchaId, localId: o.localId, visitaId: o.visitaId });
        if (o.jornadaId) [o.localId, o.visitaId].forEach(function (id) {
          if (S.partidos.some(function (x) { return x !== p && x.jornadaId === o.jornadaId && x.estado !== 'cancelado' && (x.localId === id || x.visitaId === id); })) av.push(C.nom(S.equipos, id) + ' ya tiene otro partido en esta jornada.');
        });
        if (dup.length && !o.jornadaId) av.push('Estos equipos ya tienen un partido sin jornada en este torneo.');
        if (o.fecha && t && ((t.inicio && o.fecha < t.inicio) || (t.fin && o.fecha > t.fin))) av.push('La fecha queda fuera de las fechas del torneo (' + C.fecha(t.inicio) + ' al ' + C.fecha(t.fin) + ').');
        if (av.length && !(fm.elements.forzar && fm.elements.forzar.checked)) return { titulo: 'Se detectaron cruces', avisos: av };
        var nuevo = !p, antes = p ? C.fechaDia(p.fecha) + ' ' + C.hora(p.hora) + ' · ' + (C.lugar(S.liga.sedes, p) || 'sin cancha') : '';
        if (nuevo) { p = { id: C.uid('p'), estado: 'programado', gl: null, gv: null, motivo: '', historial: [] }; S.partidos.push(p); }
        ['torneoId', 'jornadaId', 'localId', 'visitaId', 'fecha', 'hora', 'sedeId', 'canchaId'].forEach(function (k) { p[k] = o[k] || ''; });
        /* un partido suspendido que recibe fecha y hora nuevas vuelve a quedar programado */
        if (!nuevo && p.estado === 'suspendido' && p.fecha && p.hora && (cambioHorario || !yaTenia)) { p.estado = 'programado'; p.motivo = ''; }
        var txt = C.nom(S.equipos, p.localId) + ' vs ' + C.nom(S.equipos, p.visitaId);
        if (nuevo) p.historial.push({ ts: C.sello(), accion: 'Programado', detalle: (p.fecha ? C.fechaDia(p.fecha) + ' ' + C.hora(p.hora) : 'Sin fecha') });
        else if (cambioHorario) p.historial.push({ ts: C.sello(), accion: 'Reprogramado', detalle: antes + ' → ' + C.fechaDia(p.fecha) + ' ' + C.hora(p.hora) + ' · ' + (C.lugar(S.liga.sedes, p) || 'sin cancha') + '. Motivo: ' + o.motivoCambio.trim() + (av.length ? ' (guardado con cruces)' : '') });
        else p.historial.push({ ts: C.sello(), accion: 'Editado', detalle: av.length ? 'Guardado con cruces de horario' : '' });
        C.guardar((nuevo ? 'Partido programado: ' : cambioHorario ? 'Partido reprogramado: ' : 'Partido editado: ') + txt, { partidoId: p.id, torneoId: p.torneoId, detalle: cambioHorario ? o.motivoCambio.trim() : '' });
        setTimeout(C.pintar, 0);
      }
    });
    return f;
  }

  /* ---------------- ficha del partido y cambios de estado ---------------- */
  function abrir(p) {
    if (!p) return;
    var S = C.S, cerrada = C.temporadaCerrada((C.porId(S.torneos, p.torneoId) || {}).temporadaId);
    var b = C.el('<div><div class="vs" style="margin-bottom:8px"><span class="l" style="font-size:17px">' + C.esc(C.nom(S.equipos, p.localId)) + '</span><span class="x marcador">' + (p.estado === 'finalizado' ? p.gl + ' – ' + p.gv : 'vs')
      + '</span><span class="v" style="font-size:17px">' + C.esc(C.nom(S.equipos, p.visitaId)) + '</span></div>'
      + '<p style="text-align:center;margin:0 0 14px">' + C.pillPartido(p) + '</p>'
      + '<dl class="ficha"><div><dt>Torneo</dt><dd>' + C.esc(C.nom(S.torneos, p.torneoId)) + '</dd></div><div><dt>Jornada</dt><dd>' + C.esc(C.nom(S.jornadas, p.jornadaId, 'Sin jornada')) + '</dd></div>'
      + '<div><dt>Fecha y hora</dt><dd>' + C.fechaDia(p.fecha) + (p.hora ? ' · ' + C.hora(p.hora) : ' · sin hora') + '</dd></div><div><dt>Lugar</dt><dd>' + C.esc(C.lugar(S.liga.sedes, p) || 'Sin asignar') + '</dd></div>'
      + (p.motivo ? '<div><dt>Motivo</dt><dd>' + C.esc(p.motivo) + '</dd></div>' : '') + '</dl>'
      + (cerrada ? '<p class="aviso" style="margin-top:14px">La temporada de este partido está cerrada: sólo consulta.</p>' : '<div class="barra-h" style="margin:16px 0 0">'
        + (p.estado === 'programado' ? '<button type="button" class="btn sm pri" data-a="resultado">Capturar resultado</button><button type="button" class="btn sm" data-a="editar">Editar / reprogramar</button><button type="button" class="btn sm" data-a="suspender">Suspender</button><button type="button" class="btn sm peligro" data-a="cancelar">Cancelar</button>' : '')
        + (p.estado === 'finalizado' ? '<button type="button" class="btn sm" data-a="resultado">Corregir resultado</button><button type="button" class="btn sm" data-a="editar">Editar datos</button>' : '')
        + (p.estado === 'suspendido' ? '<button type="button" class="btn sm pri" data-a="editar">Reprogramar</button><button type="button" class="btn sm peligro" data-a="cancelar">Cancelar</button>' : '')
        + (p.estado === 'cancelado' ? '<button type="button" class="btn sm" data-a="reactivar">Reactivar</button>' : '') + '</div>')
      + '<h4 style="font-size:16px;margin:18px 0 8px">Historial</h4>'
      + ((p.historial || []).length ? '<ul class="hist">' + p.historial.slice().reverse().map(function (h) { return '<li><time>' + C.selloTxt(h.ts) + '</time><b>' + C.esc(h.accion) + '</b>' + (h.detalle ? ' — ' + C.esc(h.detalle) : '') + '</li>'; }).join('') + '</ul>' : '<p class="suave" style="margin:0">Sin cambios registrados.</p>')
      + '</div>');
    C.modal('Partido', b, { sinFoco: true });
    b.addEventListener('click', function (e) {
      var x = e.target.closest('[data-a]'); if (!x) return;
      var a = x.getAttribute('data-a'), txt = C.nom(S.equipos, p.localId) + ' vs ' + C.nom(S.equipos, p.visitaId);
      if (a === 'editar') editar(p);
      else if (a === 'resultado') C.resultadosUI.capturar(p);
      else if (a === 'suspender' || a === 'cancelar') C.confirmar(a === 'suspender' ? 'Suspender partido' : 'Cancelar partido', txt + (a === 'suspender' ? ': queda pendiente de reprogramar.' : ': ya no se jugará y no cuenta para la tabla.'), function (mo) {
        p.estado = a === 'suspender' ? 'suspendido' : 'cancelado'; p.motivo = mo;
        (p.historial = p.historial || []).push({ ts: C.sello(), accion: a === 'suspender' ? 'Suspendido' : 'Cancelado', detalle: mo });
        C.guardar('Partido ' + (a === 'suspender' ? 'suspendido' : 'cancelado') + ': ' + txt, { partidoId: p.id, torneoId: p.torneoId, detalle: mo }); C.pintar();
      }, { motivo: true, peligro: a === 'cancelar', boton: a === 'suspender' ? 'Suspender' : 'Cancelar partido' });
      else if (a === 'reactivar') C.confirmar('Reactivar partido', txt + ' vuelve a «Programado».', function (mo) {
        var av = cruces(p); p.estado = 'programado'; p.motivo = '';
        p.historial.push({ ts: C.sello(), accion: 'Reactivado', detalle: mo + (av.length ? ' (con cruces: ' + av.join(' ') + ')' : '') });
        C.guardar('Partido reactivado: ' + txt, { partidoId: p.id, torneoId: p.torneoId, detalle: mo }); C.pintar();
        if (av.length) C.aviso('Revisa el horario', av.join(' '));
      }, { motivo: true, boton: 'Reactivar' });
    });
  }

  /* ---------------- jornadas ---------------- */
  function jornadas() {
    var S = C.S, ts = C.torneos();
    if (!ts.length) { C.aviso('Sin torneos', 'Crea un torneo para poder agregar jornadas.'); return; }
    var tid = (jTorneo && C.idsTorneos()[jTorneo]) ? jTorneo : (F.torneo || ts[0].id);
    var b = C.el('<div><label class="campo"><span>Torneo</span><select data-t>' + ts.map(function (t) { return '<option value="' + t.id + '"' + (t.id === tid ? ' selected' : '') + '>' + C.esc(t.nombre) + '</option>'; }).join('') + '</select></label><div data-l></div>'
      + '<div class="modal-pie"><button type="button" class="btn pri" data-nueva>+ Jornada</button></div></div>');
    function pinta() {
      var js = S.jornadas.filter(function (j) { return j.torneoId === tid; }).sort(function (a, b) { return a.numero - b.numero; });
      b.querySelector('[data-l]').innerHTML = js.length ? '<ul class="lista">' + js.map(function (j) {
        var n = S.partidos.filter(function (p) { return p.jornadaId === j.id; }).length;
        return '<li class="item click" data-j="' + j.id + '"><span class="p"><b>' + C.esc(j.nombre) + '</b><small>' + (j.fecha ? C.fechaDia(j.fecha) : 'Sin fecha') + ' · ' + n + ' partido' + (n === 1 ? '' : 's') + '</small></span></li>'; }).join('') + '</ul>'
        : C.vacio('Este torneo no tiene jornadas.');
    }
    function ed(j) {
      var num = S.jornadas.filter(function (x) { return x.torneoId === tid; }).reduce(function (m, x) { return Math.max(m, x.numero || 0); }, 0) + 1;
      C.formulario({
        titulo: j ? 'Editar jornada' : 'Nueva jornada', sub: C.nom(S.torneos, tid), valores: j || { numero: num, nombre: 'Jornada ' + num },
        campos: [{ k: 'numero', t: 'Número', tipo: 'number', req: true, min: 1, mitad: true }, { k: 'fecha', t: 'Fecha de referencia', tipo: 'date', mitad: true }, { k: 'nombre', t: 'Nombre', req: true, ph: 'Jornada 5, Semifinal…' }],
        borrar: j ? function () {
          if (S.partidos.some(function (p) { return p.jornadaId === j.id; })) return C.aviso('No se puede eliminar', 'Esta jornada tiene partidos. Muévelos o elimínalos primero.', jornadas);
          S.jornadas = S.jornadas.filter(function (x) { return x !== j; }); C.guardar('Jornada eliminada: ' + j.nombre, { torneoId: tid }); C.pintar(); jornadas();
        } : null,
        alGuardar: function (o) {
          if (S.jornadas.some(function (x) { return x !== j && x.torneoId === tid && x.numero === o.numero; })) return 'Ya existe la jornada número ' + o.numero + ' en este torneo.';
          if (j) Object.assign(j, o); else S.jornadas.push(Object.assign({ id: C.uid('r'), torneoId: tid }, o));
          C.guardar('Jornada guardada: ' + o.nombre, { torneoId: tid }); setTimeout(function () { C.pintar(); jornadas(); }, 0);
        }
      });
    }
    C.modal('Jornadas', b, { sinFoco: true });
    pinta();
    jTorneo = tid;
    b.querySelector('[data-t]').onchange = function () { tid = jTorneo = this.value; pinta(); };
    b.querySelector('[data-nueva]').onclick = function () { ed(null); };
    b.addEventListener('click', function (e) { var li = e.target.closest('[data-j]'); if (li) ed(C.porId(S.jornadas, li.getAttribute('data-j'))); });
  }

  C.calendarioUI = { f: F, editar: editar, abrir: abrir, item: item };
})();
