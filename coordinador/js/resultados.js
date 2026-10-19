/* Panel de coordinación — RESULTADOS: captura y corrección de marcadores con motivo e historial.
   Las tablas de posiciones se calculan en el momento a partir de los partidos finalizados. */
(function () {
  'use strict';
  var tab = 'pendientes';

  C.modulo('resultados', {
    titulo: 'Resultados',
    pintar: function (v, args) {
      if (['pendientes', 'capturados', 'tablas', 'otros'].indexOf(args[0]) >= 0) tab = args[0];
      var S = C.S, hoy = C.hoy(), ps = C.partidos();
      var pend = ps.filter(function (p) { return p.estado === 'programado' && p.fecha && p.fecha <= hoy; }).sort(C.ordenPartidos);
      var fin = ps.filter(function (p) { return p.estado === 'finalizado'; }).sort(C.ordenPartidos).reverse();
      var otros = ps.filter(function (p) { return p.estado === 'suspendido' || p.estado === 'cancelado'; }).sort(C.ordenPartidos).reverse();
      v.appendChild(C.pestanas([['pendientes', 'Por capturar (' + pend.length + ')'], ['capturados', 'Capturados (' + fin.length + ')'], ['tablas', 'Tablas de posiciones'], ['otros', 'Suspendidos y cancelados (' + otros.length + ')']],
        tab, function (p) { C.ir('resultados', p); }));
      var n = C.el('<section class="tarjeta"></section>');
      if (tab === 'tablas') {
        var ts = C.torneos();
        n.innerHTML = ts.length ? ts.map(function (t) {
          var j = S.partidos.filter(function (p) { return p.torneoId === t.id && p.estado === 'finalizado'; }).length;
          return '<div class="cab" style="margin-top:6px"><h2><a href="#/torneos/ver/' + t.id + '" style="text-decoration:none">' + C.esc(t.nombre) + '</a></h2><small class="suave">' + j + ' partidos jugados</small></div>' + C.tablaHTML(C.tabla(t, S.partidos, S.equipos)) + '<div style="height:18px"></div>';
        }).join('') : C.vacio('No hay torneos con estos filtros.');
      } else {
        var l = tab === 'pendientes' ? pend : tab === 'capturados' ? fin : otros;
        n.innerHTML = '<div class="cab"><h2>' + (tab === 'pendientes' ? 'Partidos por capturar' : tab === 'capturados' ? 'Resultados capturados' : 'Suspendidos y cancelados') + '</h2></div>'
          + (tab === 'pendientes' ? '<p class="suave" style="font-size:13px;margin:-6px 0 12px">Partidos programados cuya fecha ya llegó. Toca uno para capturar el marcador; si no se jugó, ábrelo y suspéndelo o cancélalo.</p>' : '')
          + (l.length ? '<ul class="lista">' + l.map(C.calendarioUI.item).join('') + '</ul>'
            : C.vacio(tab === 'pendientes' ? 'No hay resultados pendientes.' : tab === 'capturados' ? 'Todavía no hay resultados capturados.' : 'Ningún partido suspendido o cancelado.'));
        n.addEventListener('click', function (e) {
          var li = e.target.closest('[data-partido]'); if (!li) return;
          var p = C.porId(S.partidos, li.getAttribute('data-partido'));
          if (tab === 'pendientes') capturar(p); else C.calendarioUI.abrir(p);
        });
      }
      v.appendChild(n);
    }
  });

  function capturar(p) {
    var S = C.S, t = C.porId(S.torneos, p.torneoId) || {}, corr = p.estado === 'finalizado';
    var txt = C.nom(S.equipos, p.localId) + ' vs ' + C.nom(S.equipos, p.visitaId);
    if (C.temporadaCerrada(t.temporadaId)) return C.aviso('Temporada cerrada', 'Los resultados de una temporada cerrada ya no se modifican.');
    if (p.estado === 'cancelado') return C.aviso('Partido cancelado', 'Un partido cancelado no lleva marcador. Si sí se jugó, reactívalo primero desde su ficha.');
    if (p.estado === 'suspendido') return C.aviso('Partido suspendido', 'Reprograma el partido antes de capturar su resultado.');
    C.formulario({
      titulo: corr ? 'Corregir resultado' : 'Capturar resultado', sub: txt + ' · ' + C.fechaDia(p.fecha) + (p.hora ? ' ' + C.hora(p.hora) : ''),
      valores: { gl: p.gl, gv: p.gv },
      campos: [{ k: 'gl', t: C.nom(S.equipos, p.localId) + ' (local)', tipo: 'number', req: true, min: 0, maxv: 99, mitad: true }, { k: 'gv', t: C.nom(S.equipos, p.visitaId) + ' (visitante)', tipo: 'number', req: true, min: 0, maxv: 99, mitad: true }]
        .concat(corr ? [{ k: 'motivo', t: 'Motivo de la corrección', tipo: 'textarea', req: true, max: 300, ayuda: 'El marcador anterior y el motivo quedan en el historial del partido.' }] : []),
      boton: corr ? 'Guardar corrección' : 'Guardar resultado',
      borrar: corr ? function () {
        C.confirmar('Quitar resultado', txt + ' vuelve a «Programado» y deja de contar en la tabla.', function (mo) {
          p.historial.push({ ts: C.sello(), accion: 'Resultado retirado', detalle: p.gl + '–' + p.gv + '. Motivo: ' + mo });
          p.estado = 'programado'; p.gl = p.gv = null;
          C.guardar('Resultado retirado: ' + txt, { partidoId: p.id, torneoId: p.torneoId, detalle: mo }); C.pintar();
        }, { motivo: true, peligro: true, boton: 'Quitar resultado' });
      } : null, borrarTxt: 'Quitar resultado',
      alGuardar: function (o, fm) {
        if (!Number.isInteger(o.gl) || !Number.isInteger(o.gv) || o.gl > 99 || o.gv > 99) return 'El marcador debe ser de números enteros entre 0 y 99.';
        if (corr && o.gl === p.gl && o.gv === p.gv) return 'El marcador es igual al que ya estaba capturado.';
        var av = [];
        if (!corr) {
          if (p.fecha && p.fecha > C.hoy()) av.push('La fecha del partido (' + C.fecha(p.fecha) + ') todavía no llega.');
          if (!p.fecha) av.push('El partido no tiene fecha asignada.');
          /* duplicado: el mismo cruce ya finalizado en la misma jornada */
          if (S.partidos.some(function (x) { return x !== p && x.torneoId === p.torneoId && x.estado === 'finalizado' && (x.jornadaId || '') === (p.jornadaId || '')
            && ((x.localId === p.localId && x.visitaId === p.visitaId) || (x.localId === p.visitaId && x.visitaId === p.localId)); }))
            av.push('Ya hay un resultado capturado para este mismo cruce en esta jornada: podría estar duplicado.');
          if (t.formato === 'eliminacion' && o.gl === o.gv) av.push('En eliminación directa no debería haber empate: captura el marcador definitivo (con penales).');
        }
        if (av.length && !(fm.elements.forzar && fm.elements.forzar.checked)) return { avisos: av };
        var antes = p.gl + '–' + p.gv;
        p.gl = o.gl; p.gv = o.gv; p.estado = 'finalizado'; p.motivo = '';
        (p.historial = p.historial || []).push(corr ? { ts: C.sello(), accion: 'Resultado corregido', detalle: antes + ' → ' + o.gl + '–' + o.gv + '. Motivo: ' + o.motivo }
                                                    : { ts: C.sello(), accion: 'Resultado capturado', detalle: o.gl + '–' + o.gv });
        C.guardar((corr ? 'Resultado corregido: ' : 'Resultado capturado: ') + txt + ' ' + o.gl + '–' + o.gv, { partidoId: p.id, torneoId: p.torneoId, detalle: corr ? antes + ' → ' + o.gl + '–' + o.gv + '. ' + o.motivo : '' });
        C.toast('Resultado guardado. La tabla ya está actualizada.');
        setTimeout(C.pintar, 0);
      }
    });
  }

  C.resultadosUI = { capturar: capturar };
})();
