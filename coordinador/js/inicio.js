/* Panel de coordinación — INICIO: resumen operativo, indicadores con enlace y accesos rápidos. */
(function () {
  'use strict';
  C.modulo('inicio', {
    titulo: 'Inicio',
    pintar: function (v) {
      var S = C.S, hoy = C.hoy();
      if (!S.temporadas.length && !S.equipos.length && !S.torneos.length) { bienvenida(v); return; }

      var torneos = C.torneos(), partidos = C.partidos(), equipos = C.equipos(), ids = C.idsEquipos();
      var enCurso = torneos.filter(function (t) { return t.estado === 'en_curso'; }).length;
      var proximos = partidos.filter(function (p) { return p.estado === 'programado' && p.fecha && p.fecha >= hoy && p.fecha <= C.sumaDias(hoy, 7); }).sort(C.ordenPartidos);
      var porCapturar = partidos.filter(function (p) { return p.estado === 'programado' && p.fecha && p.fecha < hoy; }).sort(C.ordenPartidos);
      var sinFecha = partidos.filter(function (p) { return p.estado === 'programado' && !p.fecha; }).length;
      var movs = C.movs(), cta = C.cuenta(movs);
      var adeudos = equipos.map(function (e) { return { e: e, s: C.cuenta(movs.filter(function (m) { return m.equipoId === e.id; })).saldo }; })
        .filter(function (x) { return x.s > 0.009; }).sort(function (a, b) { return b.s - a.s; });
      var porCobrar = adeudos.reduce(function (t, x) { return t + x.s; }, 0);
      var gastos = C.gastos().reduce(function (t, g) { return t + (Number(g.monto) || 0); }, 0);
      var pendInsc = equipos.filter(function (e) { return (e.inscripcion || {}).estado === 'pendiente'; }).length;

      var k = '<div class="kpis">'
        + kpi('#/torneos', enCurso, 'Torneos en curso', torneos.length + ' en total')
        + kpi('#/equipos', equipos.length, 'Equipos', pendInsc ? pendInsc + ' con inscripción pendiente' : 'Todos inscritos', pendInsc ? '' : 'bien')
        + kpi('#/calendario', proximos.length, 'Partidos en 7 días', sinFecha ? sinFecha + ' sin fecha asignada' : 'Calendario al día')
        + kpi('#/resultados', porCapturar.length, 'Resultados por capturar', porCapturar.length ? 'Partidos ya jugados' : 'Nada pendiente', porCapturar.length ? 'alerta' : 'bien')
        + kpi('#/finanzas/cuentas', C.mxn0(porCobrar), 'Por cobrar', adeudos.length + ' equipo' + (adeudos.length === 1 ? '' : 's') + ' con adeudo', porCobrar > 0.009 ? 'alerta' : 'bien')
        + kpi('#/finanzas', C.mxn0(cta.pagos - gastos), 'Caja', 'Cobrado ' + C.mxn0(cta.pagos) + ' · gastos ' + C.mxn0(gastos), cta.pagos - gastos < -0.009 ? 'alerta' : '')
        + '</div>';
      v.appendChild(C.el(k));

      var r = C.el('<section class="tarjeta"><div class="cab"><h2>Accesos rápidos</h2></div><div class="rapidos">'
        + rapido('equipo', C.ICO.equipos, 'Nuevo equipo') + rapido('torneo', C.ICO.torneos, 'Nuevo torneo') + rapido('partido', C.ICO.calendario, 'Programar partido')
        + rapido('resultado', C.ICO.resultados, 'Capturar resultado') + rapido('pago', C.ICO.pago, 'Registrar pago') + '</div></section>');
      r.addEventListener('click', function (e) {
        var b = e.target.closest('[data-r]'); if (!b) return;
        var a = b.getAttribute('data-r');
        if (a === 'equipo') C.equiposUI.editar(null);
        else if (a === 'torneo') C.torneosUI.editarTorneo(null);
        else if (a === 'partido') C.calendarioUI.editar(null);
        else if (a === 'resultado') C.ir('resultados');
        else if (a === 'pago') C.finanzasUI.movimiento('pago');
      });
      v.appendChild(r);

      var d = C.el('<div class="dos"></div>');
      d.appendChild(C.el('<section class="tarjeta"><div class="cab"><h2>Próximos partidos</h2><a class="btn sm" href="#/calendario">Ver calendario</a></div>'
        + (proximos.length ? '<ul class="lista">' + proximos.slice(0, 6).map(itemPartido).join('') + '</ul>' : C.vacio('No hay partidos programados en los próximos 7 días.')) + '</section>'));
      d.appendChild(C.el('<section class="tarjeta"><div class="cab"><h2>Por capturar</h2><a class="btn sm" href="#/resultados">Ir a resultados</a></div>'
        + (porCapturar.length ? '<ul class="lista">' + porCapturar.slice(0, 6).map(itemPartido).join('') + '</ul>' : C.vacio('Todos los partidos jugados tienen resultado.')) + '</section>'));
      v.appendChild(d);
      v.appendChild(C.el('<div style="height:18px"></div>'));
      v.appendChild(C.el('<section class="tarjeta"><div class="cab"><h2>Adeudos más altos</h2><a class="btn sm" href="#/finanzas/cuentas">Estados de cuenta</a></div>'
        + (adeudos.length ? '<ul class="lista">' + adeudos.slice(0, 5).map(function (x) {
          return '<li class="item click" data-eq="' + x.e.id + '"><span class="p"><b>' + C.esc(x.e.nombre) + '</b><small>' + C.esc((x.e.capitan || {}).nombre || 'Sin capitán') + '</small></span>'
            + '<span class="d"><b class="num" style="color:var(--rojo)">' + C.mxn(x.s) + '</b></span></li>'; }).join('') + '</ul>'
          : C.vacio('Ningún equipo tiene adeudo con estos filtros.')) + '</section>'));
      v.addEventListener('click', function (e) { var li = e.target.closest('[data-eq]'); if (li) C.ir('equipos', li.getAttribute('data-eq'), 'cuenta'); });
    }
  });

  function kpi(href, valor, etq, sub, cls) { return '<a class="kpi ' + (cls || '') + '" href="' + href + '"><div class="v num">' + valor + '</div><div class="e">' + etq + '</div><div class="s">' + C.esc(sub) + '</div></a>'; }
  function rapido(k, ico, t) { return '<button type="button" class="rapido" data-r="' + k + '">' + ico + '<span>' + t + '</span></button>'; }
  function itemPartido(p) {
    var S = C.S;
    return '<li class="item"><span class="p"><b>' + C.esc(C.nom(S.equipos, p.localId)) + ' vs ' + C.esc(C.nom(S.equipos, p.visitaId)) + '</b>'
      + '<small>' + C.esc(C.nom(S.torneos, p.torneoId)) + (C.lugar(S.liga.sedes, p) ? ' · ' + C.esc(C.lugar(S.liga.sedes, p)) : '') + '</small></span>'
      + '<span class="d"><span class="num">' + C.fechaDia(p.fecha) + (p.hora ? ' · ' + C.hora(p.hora) : '') + '</span></span></li>';
  }

  function bienvenida(v) {
    var n = C.el('<section class="tarjeta"><h2>Empieza a armar tu liga</h2>'
      + '<p class="suave" style="margin:8px 0 14px">Este espacio todavía no tiene información. Sigue estos pasos, en este orden:</p>'
      + '<ol style="margin:0 0 16px;padding-left:20px;display:grid;gap:6px">'
      + '<li><a href="#/configuracion">Configuración</a>: nombre de la liga, logotipo, contacto y sedes.</li>'
      + '<li><a href="#/torneos/temporadas">Torneos</a>: crea una temporada, tus categorías y el primer torneo.</li>'
      + '<li><a href="#/equipos">Equipos</a>: da de alta los equipos con su capitán y plantilla.</li>'
      + '<li><a href="#/calendario">Calendario</a>: genera el rol o programa los partidos.</li>'
      + '<li><a href="#/finanzas/conceptos">Finanzas</a>: define tus conceptos de cobro y registra cargos y pagos.</li></ol>'
      + (C.espacio === 'real' ? '<button type="button" class="btn pri" id="ver-demo">Ver la demostración</button> <span class="suave" style="font-size:13px">Datos de ejemplo, separados de los tuyos.</span>' : '')
      + '</section>');
    v.appendChild(n);
    var b = n.querySelector('#ver-demo'); if (b) b.onclick = function () { C.cambiarEspacio('demo'); };
  }
})();
