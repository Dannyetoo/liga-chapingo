/* Panel de coordinación — TORNEOS: temporadas, categorías y torneos (formato, fechas, equipos, estado, puntuación y desempate). */
(function () {
  'use strict';
  var FORMATOS = { liga: 'Liga (todos contra todos)', liga_ida_vuelta: 'Liga a ida y vuelta', grupos: 'Grupos y fase final', eliminacion: 'Eliminación directa' };
  var ESTADOS = { borrador: ['Borrador', ''], inscripciones: ['Inscripciones', 'az'], en_curso: ['En curso', 'ok'], finalizado: ['Finalizado', ''], cancelado: ['Cancelado', 'mal'] };
  var EST_TEMP = { planeada: ['Planeada', 'az'], activa: ['Activa', 'ok'], cerrada: ['Cerrada', ''] };
  C.FORMATOS = FORMATOS;
  function pill(mapa, k) { var e = mapa[k] || [k, '']; return '<span class="pill ' + e[1] + '">' + e[0] + '</span>'; }
  C.pillTorneo = function (t) { return pill(ESTADOS, t.estado); };

  C.modulo('torneos', {
    titulo: 'Torneos',
    pintar: function (v, args) {
      if (args[0] === 'ver' && C.porId(C.S.torneos, args[1])) return detalle(v, C.porId(C.S.torneos, args[1]));
      var tab = ['temporadas', 'categorias'].indexOf(args[0]) >= 0 ? args[0] : 'torneos';
      v.appendChild(C.pestanas([['torneos', 'Torneos'], ['temporadas', 'Temporadas'], ['categorias', 'Categorías']], tab, function (p) { C.ir('torneos', p); }));
      if (tab === 'torneos') listaTorneos(v); else if (tab === 'temporadas') listaTemporadas(v); else listaCategorias(v);
    }
  });

  /* ---------------- torneos ---------------- */
  function listaTorneos(v) {
    var S = C.S, lista = C.torneos().slice().sort(function (a, b) { return String(b.inicio).localeCompare(String(a.inicio)) || a.nombre.localeCompare(b.nombre, 'es'); });
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Torneos</h2><div class="acc"><button type="button" class="btn pri" data-nuevo>' + C.ICO.mas + 'Nuevo torneo</button></div></div>'
      + (lista.length ? '<ul class="lista">' + lista.map(function (t) {
        var np = S.partidos.filter(function (p) { return p.torneoId === t.id; }).length;
        return '<li class="item click" data-t="' + t.id + '"><span class="p"><b>' + C.esc(t.nombre) + '</b><small>' + C.esc(C.nom(S.temporadas, t.temporadaId)) + ' · ' + C.esc(C.nom(S.categorias, t.categoriaId))
          + ' · ' + FORMATOS[t.formato] + '</small></span><span class="d"><small class="suave">' + (t.equipos || []).length + ' equipos · ' + np + ' partidos</small>' + pill(ESTADOS, t.estado) + '</span></li>'; }).join('') + '</ul>'
        : C.vacio(S.torneos.length ? 'No hay torneos con estos filtros.' : 'Todavía no hay torneos. Crea primero una temporada y una categoría.')) + '</section>');
    n.querySelector('[data-nuevo]').onclick = function () { editarTorneo(null); };
    n.addEventListener('click', function (e) { var li = e.target.closest('[data-t]'); if (li) C.ir('torneos', 'ver', li.getAttribute('data-t')); });
    v.appendChild(n);
  }

  function editarTorneo(t) {
    var S = C.S;
    if (!S.temporadas.length || !S.categorias.length) { C.aviso('Falta un paso', 'Para crear un torneo necesitas al menos una temporada y una categoría.', function () { C.ir('torneos', S.temporadas.length ? 'categorias' : 'temporadas'); }); return; }
    var R = Object.assign({ pg: 3, pe: 1, pp: 0, desempate: ['dif', 'gf', 'h2h'] }, (t || {}).reglas || {});
    var des = Object.keys(C.DESEMPATES).map(function (k) { return [k, C.DESEMPATES[k]]; });
    var ta = C.temporadaActiva();
    C.formulario({
      titulo: t ? 'Editar torneo' : 'Nuevo torneo', ancho: true,
      valores: t ? Object.assign({}, t, { pg: R.pg, pe: R.pe, pp: R.pp, d1: R.desempate[0] || '', d2: R.desempate[1] || '', d3: R.desempate[2] || '' })
                 : { temporadaId: ta ? ta.id : '', categoriaId: C.f.categoria, formato: 'liga', estado: 'borrador', pg: 3, pe: 1, pp: 0, d1: 'dif', d2: 'gf', d3: 'h2h', equipos: [] },
      campos: [
        { k: 'nombre', t: 'Nombre del torneo', req: true },
        { k: 'temporadaId', t: 'Temporada', tipo: 'select', req: true, mitad: true, op: S.temporadas.map(function (x) { return [x.id, x.nombre]; }) },
        { k: 'categoriaId', t: 'Categoría', tipo: 'select', req: true, mitad: true, op: S.categorias.map(function (x) { return [x.id, x.nombre]; }) },
        { k: 'formato', t: 'Formato', tipo: 'select', req: true, vacio: false, mitad: true, op: Object.keys(FORMATOS).map(function (k) { return [k, FORMATOS[k]]; }) },
        { k: 'estado', t: 'Estado', tipo: 'select', req: true, vacio: false, mitad: true, op: Object.keys(ESTADOS).map(function (k) { return [k, ESTADOS[k][0]]; }) },
        { k: 'inicio', t: 'Fecha de inicio', tipo: 'date', mitad: true }, { k: 'fin', t: 'Fecha de término', tipo: 'date', mitad: true },
        { tipo: 'nota', t: '<b>Puntuación</b> por partido y <b>criterios de desempate</b>, en el orden en que se aplican.' },
        { k: 'pg', t: 'Puntos por ganar', tipo: 'number', req: true, min: 0, mitad: true }, { k: 'pe', t: 'Puntos por empatar', tipo: 'number', req: true, min: 0, mitad: true },
        { k: 'pp', t: 'Puntos por perder', tipo: 'number', req: true, min: 0, mitad: true }, { k: 'd1', t: '1.er desempate', tipo: 'select', req: true, vacio: false, op: des, mitad: true },
        { k: 'd2', t: '2.º desempate', tipo: 'select', vacio: 'Ninguno', op: des, mitad: true }, { k: 'd3', t: '3.er desempate', tipo: 'select', vacio: 'Ninguno', op: des, mitad: true },
        { k: 'equipos', t: 'Equipos inscritos', tipo: 'checks', op: S.equipos.slice().sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); }).map(function (e) { return [e.id, e.nombre]; }) }
      ],
      borrar: t ? function () { borrarTorneo(t); } : null,
      alGuardar: function (o) {
        if (o.inicio && o.fin && o.fin < o.inicio) return 'La fecha de término no puede ser anterior a la de inicio.';
        if (S.torneos.some(function (x) { return x !== t && x.temporadaId === o.temporadaId && x.categoriaId === o.categoriaId && C.norm(x.nombre) === C.norm(o.nombre); }))
          return 'Ya existe un torneo con ese nombre en la misma temporada y categoría.';
        if (C.temporadaCerrada(o.temporadaId) && (!t || t.temporadaId !== o.temporadaId)) return 'Esa temporada está cerrada: no admite torneos nuevos.';
        var des = [o.d1, o.d2, o.d3].filter(function (x, i, a) { return x && a.indexOf(x) === i; });
        if (t) {
          var fuera = (t.equipos || []).filter(function (id) { return o.equipos.indexOf(id) < 0 && S.partidos.some(function (p) { return p.torneoId === t.id && (p.localId === id || p.visitaId === id); }); });
          if (fuera.length) return 'No puedes quitar a ' + fuera.map(function (id) { return C.nom(S.equipos, id); }).join(', ') + ': ya tiene partidos en este torneo.';
        }
        var d = { nombre: o.nombre, temporadaId: o.temporadaId, categoriaId: o.categoriaId, formato: o.formato, estado: o.estado, inicio: o.inicio, fin: o.fin,
                  equipos: o.equipos, reglas: { pg: o.pg, pe: o.pe, pp: o.pp, desempate: des } };
        if (t) Object.assign(t, d); else { t = Object.assign({ id: C.uid('t') }, d); S.torneos.push(t); }
        C.guardar('Torneo guardado: ' + t.nombre, { torneoId: t.id });
        setTimeout(function () { if (C.ruta().mod === 'torneos' && C.ruta().args[0] === 'ver') C.pintar(); else C.ir('torneos', 'ver', t.id); }, 0);
      }
    });
  }
  function borrarTorneo(t) {
    var S = C.S, ps = S.partidos.filter(function (p) { return p.torneoId === t.id; });
    if (ps.some(function (p) { return p.estado === 'finalizado'; })) { C.aviso('No se puede eliminar', 'Este torneo ya tiene resultados capturados. Si ya no continúa, cámbialo a «Cancelado» o «Finalizado» para conservar su historial.'); return; }
    C.confirmar('Eliminar torneo', '«' + t.nombre + '» se eliminará junto con sus ' + ps.length + ' partidos programados y sus jornadas. Los equipos y sus movimientos no se tocan.', function () {
      S.partidos = S.partidos.filter(function (p) { return p.torneoId !== t.id; });
      S.jornadas = S.jornadas.filter(function (j) { return j.torneoId !== t.id; });
      S.torneos = S.torneos.filter(function (x) { return x !== t; });
      C.guardar('Torneo eliminado: ' + t.nombre); C.ir('torneos');
    }, { peligro: true, boton: 'Eliminar' });
  }

  function detalle(v, t) {
    var S = C.S, ps = S.partidos.filter(function (p) { return p.torneoId === t.id; }), R = Object.assign({ pg: 3, pe: 1, pp: 0, desempate: [] }, t.reglas || {});
    var jug = ps.filter(function (p) { return p.estado === 'finalizado'; }).length;
    var n = C.el('<div><p style="margin:0 0 12px"><a href="#/torneos">← Torneos</a></p>'
      + '<section class="tarjeta"><div class="cab"><h2>' + C.esc(t.nombre) + ' ' + pill(ESTADOS, t.estado) + '</h2><div class="acc">'
      + '<button type="button" class="btn" data-a="rol">Generar rol</button><button type="button" class="btn" data-a="cal">Ver calendario</button><button type="button" class="btn pri" data-a="editar">Editar</button></div></div>'
      + '<dl class="ficha"><div><dt>Temporada</dt><dd>' + C.esc(C.nom(S.temporadas, t.temporadaId)) + '</dd></div><div><dt>Categoría</dt><dd>' + C.esc(C.nom(S.categorias, t.categoriaId)) + '</dd></div>'
      + '<div><dt>Formato</dt><dd>' + FORMATOS[t.formato] + '</dd></div><div><dt>Fechas</dt><dd>' + (t.inicio ? C.fecha(t.inicio) : '—') + ' al ' + (t.fin ? C.fecha(t.fin) : '—') + '</dd></div>'
      + '<div><dt>Puntuación</dt><dd>Ganar ' + R.pg + ' · Empatar ' + R.pe + ' · Perder ' + R.pp + '</dd></div>'
      + '<div><dt>Desempate</dt><dd>' + (R.desempate.map(function (k) { return C.DESEMPATES[k]; }).join(' → ') || '—') + '</dd></div>'
      + '<div><dt>Avance</dt><dd>' + jug + ' de ' + ps.length + ' partidos jugados</dd></div></dl></section>'
      + '<section class="tarjeta"><div class="cab"><h2>Tabla de posiciones</h2></div>'
      + (t.formato === 'eliminacion' ? '<p class="suave" style="margin:0 0 10px;font-size:13px">En eliminación directa la tabla es sólo de referencia: avanza quien gana cada llave.</p>' : '')
      + C.tablaHTML(C.tabla(t, S.partidos, S.equipos)) + '</section>'
      + '<section class="tarjeta"><div class="cab"><h2>Equipos (' + (t.equipos || []).length + ')</h2></div>'
      + ((t.equipos || []).length ? '<ul class="lista">' + t.equipos.map(function (id) { var e = C.porId(S.equipos, id); return e ? '<li class="item click" data-eq="' + id + '"><span class="p"><b>' + C.esc(e.nombre) + '</b><small>' + C.esc((e.capitan || {}).nombre || 'Sin capitán') + '</small></span></li>' : ''; }).join('') + '</ul>'
        : C.vacio('Sin equipos. Agrégalos con «Editar».')) + '</section></div>');
    n.addEventListener('click', function (e) {
      var li = e.target.closest('[data-eq]'); if (li) return C.ir('equipos', li.getAttribute('data-eq'));
      var b = e.target.closest('[data-a]'); if (!b) return;
      var a = b.getAttribute('data-a');
      if (a === 'editar') editarTorneo(t);
      else if (a === 'cal') { C.calendarioUI.f.torneo = t.id; C.ir('calendario'); }
      else if (a === 'rol') generarRol(t);
    });
    v.appendChild(n);
  }

  /** Crea jornadas y partidos que falten. No pone hora ni cancha: se asignan en Calendario, donde se revisan los cruces. */
  function generarRol(t) {
    var S = C.S, ids = (t.equipos || []).filter(function (id) { return C.porId(S.equipos, id); });
    if (ids.length < 2) { C.aviso('Faltan equipos', 'Inscribe al menos dos equipos en el torneo para generar el rol.'); return; }
    if (C.temporadaCerrada(t.temporadaId)) { C.aviso('Temporada cerrada', 'No se pueden programar partidos en una temporada cerrada.'); return; }
    var ya = S.partidos.filter(function (p) { return p.torneoId === t.id; }).length;
    var elim = t.formato === 'eliminacion';
    C.formulario({
      titulo: 'Generar rol', sub: t.nombre + ' · ' + ids.length + ' equipos · ' + FORMATOS[t.formato],
      valores: { fecha: t.inicio || '', dias: 7 },
      campos: [
        { tipo: 'nota', t: (elim ? 'Se crea la <b>primera ronda</b> emparejando a los equipos en el orden de inscripción.' : 'Se crean las jornadas con todos los cruces' + (t.formato === 'liga_ida_vuelta' ? ' a ida y vuelta' : '') + '.')
          + (ya ? ' El torneo ya tiene ' + ya + ' partidos: <b>sólo se agregan los cruces que falten</b>.' : '') + ' La hora y la cancha se asignan después, en Calendario.' },
        { k: 'fecha', t: 'Fecha de la primera jornada', tipo: 'date', mitad: true }, { k: 'dias', t: 'Días entre jornadas', tipo: 'number', req: true, min: 1, mitad: true }
      ],
      boton: 'Generar',
      alGuardar: function (o) {
        var rondas = elim ? [ids.reduce(function (a, id, i) { if (i % 2) a[a.length - 1].push(id); else a.push([id]); return a; }, []).filter(function (p) { return p.length === 2; })]
                          : C.rolTodos(ids, t.formato === 'liga_ida_vuelta');
        var existe = {}; S.partidos.forEach(function (p) { if (p.torneoId === t.id && p.estado !== 'cancelado') existe[p.localId + '>' + p.visitaId] = 1; });
        var num = S.jornadas.filter(function (j) { return j.torneoId === t.id; }).reduce(function (m, j) { return Math.max(m, j.numero || 0); }, 0), creados = 0;
        rondas.forEach(function (cruces, r) {
          var faltan = cruces.filter(function (c) { return !existe[c[0] + '>' + c[1]] && (t.formato === 'liga_ida_vuelta' || !existe[c[1] + '>' + c[0]]); });
          if (!faltan.length) return;
          num++;
          var j = { id: C.uid('r'), torneoId: t.id, numero: num, nombre: elim ? 'Primera ronda' : 'Jornada ' + num, fecha: o.fecha ? C.sumaDias(o.fecha, r * o.dias) : '' };
          S.jornadas.push(j);
          faltan.forEach(function (c) {
            S.partidos.push({ id: C.uid('p'), torneoId: t.id, jornadaId: j.id, localId: c[0], visitaId: c[1], fecha: j.fecha, hora: '', sedeId: '', canchaId: '', estado: 'programado', gl: null, gv: null, motivo: '',
                              historial: [{ ts: C.sello(), accion: 'Creado al generar el rol', detalle: '' }] });
            creados++;
          });
        });
        if (!creados) return 'No hay cruces por agregar: el rol ya está completo.';
        C.guardar('Rol generado: ' + creados + ' partidos en ' + t.nombre, { torneoId: t.id });
        C.toast(creados + ' partidos creados. Asigna hora y cancha en Calendario.');
        setTimeout(C.pintar, 0);
      }
    });
  }

  /* ---------------- temporadas ---------------- */
  function listaTemporadas(v) {
    var S = C.S;
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Temporadas</h2><div class="acc"><button type="button" class="btn pri" data-nuevo>' + C.ICO.mas + 'Nueva temporada</button></div></div>'
      + (S.temporadas.length ? '<ul class="lista">' + S.temporadas.slice().reverse().map(function (t) {
        var nt = S.torneos.filter(function (x) { return x.temporadaId === t.id; }).length;
        return '<li class="item click" data-id="' + t.id + '"><span class="p"><b>' + C.esc(t.nombre) + '</b><small>' + (t.inicio ? C.fecha(t.inicio) : '—') + ' al ' + (t.fin ? C.fecha(t.fin) : '—') + ' · ' + nt + ' torneo' + (nt === 1 ? '' : 's') + '</small></span>'
          + '<span class="d">' + pill(EST_TEMP, t.estado) + '</span></li>'; }).join('') + '</ul>' : C.vacio('Crea tu primera temporada (por ejemplo «Apertura 2026»).'))
      + '<p class="suave" style="font-size:12.5px;margin:12px 0 0">Una temporada se <b>cierra</b> desde Finanzas → Cierre de temporada: queda de sólo consulta.</p></section>');
    n.querySelector('[data-nuevo]').onclick = function () { editarTemporada(null); };
    n.addEventListener('click', function (e) { var li = e.target.closest('[data-id]'); if (li) editarTemporada(C.porId(S.temporadas, li.getAttribute('data-id'))); });
    v.appendChild(n);
  }
  function editarTemporada(t) {
    var S = C.S, cerrada = t && t.estado === 'cerrada';
    C.formulario({
      titulo: t ? 'Editar temporada' : 'Nueva temporada', valores: t || { estado: S.temporadas.some(function (x) { return x.estado === 'activa'; }) ? 'planeada' : 'activa' },
      campos: [{ k: 'nombre', t: 'Nombre', req: true, ph: 'Apertura 2026' }, { k: 'inicio', t: 'Inicio', tipo: 'date', req: true, mitad: true }, { k: 'fin', t: 'Término', tipo: 'date', req: true, mitad: true }]
        .concat(cerrada ? [{ tipo: 'nota', t: 'Esta temporada está <b>cerrada</b>. Para reabrirla ve a Finanzas → Cierre de temporada.' }]
                        : [{ k: 'estado', t: 'Estado', tipo: 'select', req: true, vacio: false, op: [['planeada', 'Planeada'], ['activa', 'Activa']] }]),
      borrar: t ? function () {
        if (S.torneos.some(function (x) { return x.temporadaId === t.id; }) || S.movs.some(function (m) { return m.temporadaId === t.id; }) || S.gastos.some(function (g) { return g.temporadaId === t.id; }))
          return C.aviso('No se puede eliminar', 'Esta temporada tiene torneos o movimientos. Elimínalos primero o conserva la temporada como historial.');
        C.confirmar('Eliminar temporada', '¿Eliminar «' + t.nombre + '»?', function () { S.temporadas = S.temporadas.filter(function (x) { return x !== t; }); C.guardar('Temporada eliminada: ' + t.nombre); C.pintar(); }, { peligro: true, boton: 'Eliminar' });
      } : null,
      alGuardar: function (o) {
        if (o.fin < o.inicio) return 'La fecha de término no puede ser anterior a la de inicio.';
        if (S.temporadas.some(function (x) { return x !== t && C.norm(x.nombre) === C.norm(o.nombre); })) return 'Ya existe una temporada con ese nombre.';
        if (t) { t.nombre = o.nombre; t.inicio = o.inicio; t.fin = o.fin; if (!cerrada) t.estado = o.estado; }
        else { t = { id: C.uid('s'), nombre: o.nombre, inicio: o.inicio, fin: o.fin, estado: o.estado }; S.temporadas.push(t); if (!C.f.temporada && t.estado === 'activa') C.f.temporada = t.id; }
        C.guardar('Temporada guardada: ' + t.nombre); setTimeout(C.pintar, 0);
      }
    });
  }

  /* ---------------- categorías ---------------- */
  function listaCategorias(v) {
    var S = C.S;
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Categorías</h2><div class="acc"><button type="button" class="btn pri" data-nuevo>' + C.ICO.mas + 'Nueva categoría</button></div></div>'
      + (S.categorias.length ? '<ul class="lista">' + S.categorias.map(function (c) {
        var nt = S.torneos.filter(function (x) { return x.categoriaId === c.id; }).length;
        return '<li class="item click" data-id="' + c.id + '"><span class="p"><b>' + C.esc(c.nombre) + '</b><small>' + C.esc([c.disciplina, c.rama].filter(Boolean).join(' · ') || 'Sin detalle') + '</small></span>'
          + '<span class="d"><small class="suave">' + nt + ' torneo' + (nt === 1 ? '' : 's') + '</small></span></li>'; }).join('') + '</ul>' : C.vacio('Crea tus categorías (por ejemplo «Varonil Primera», «Femenil», «Veteranos»).')) + '</section>');
    n.querySelector('[data-nuevo]').onclick = function () { editarCategoria(null); };
    n.addEventListener('click', function (e) { var li = e.target.closest('[data-id]'); if (li) editarCategoria(C.porId(S.categorias, li.getAttribute('data-id'))); });
    v.appendChild(n);
  }
  function editarCategoria(c) {
    var S = C.S;
    C.formulario({
      titulo: c ? 'Editar categoría' : 'Nueva categoría', valores: c || {},
      campos: [{ k: 'nombre', t: 'Nombre', req: true }, { k: 'disciplina', t: 'Disciplina', ph: 'Fútbol 7', mitad: true },
               { k: 'rama', t: 'Rama', tipo: 'select', vacio: 'Sin especificar', mitad: true, op: [['Varonil', 'Varonil'], ['Femenil', 'Femenil'], ['Mixto', 'Mixto'], ['Libre', 'Libre']] }],
      borrar: c ? function () {
        if (S.torneos.some(function (x) { return x.categoriaId === c.id; })) return C.aviso('No se puede eliminar', 'Hay torneos en esta categoría.');
        C.confirmar('Eliminar categoría', '¿Eliminar «' + c.nombre + '»?', function () { S.categorias = S.categorias.filter(function (x) { return x !== c; }); C.guardar('Categoría eliminada: ' + c.nombre); C.pintar(); }, { peligro: true, boton: 'Eliminar' });
      } : null,
      alGuardar: function (o) {
        if (S.categorias.some(function (x) { return x !== c && C.norm(x.nombre) === C.norm(o.nombre); })) return 'Ya existe una categoría con ese nombre.';
        if (c) Object.assign(c, o); else { c = Object.assign({ id: C.uid('k') }, o); S.categorias.push(c); }
        C.guardar('Categoría guardada: ' + c.nombre); setTimeout(C.pintar, 0);
      }
    });
  }

  C.torneosUI = { editarTorneo: editarTorneo, generarRol: generarRol };
})();
