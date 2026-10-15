/* Panel de coordinación — FINANZAS (pesos mexicanos, fechas DD/MM/AAAA, hora de la Ciudad de México):
   conceptos, cargos, pagos, ajustes y gastos; estados de cuenta, cortes de caja y cierre de temporada.
   Regla de control: lo que ya entró en un corte de caja no se edita ni se borra; se corrige con un ajuste. */
(function () {
  'use strict';
  var FORMAS = ['Efectivo', 'Transferencia', 'Tarjeta', 'Depósito'];
  var TABS = [['resumen', 'Resumen'], ['movs', 'Cargos y pagos'], ['cuentas', 'Estados de cuenta'], ['gastos', 'Gastos'], ['cortes', 'Cortes de caja'], ['conceptos', 'Conceptos'], ['cierre', 'Cierre de temporada']];
  var fm = { tipo: '', equipo: '' };

  /** Números de un periodo. desde/hasta incluidos ('' = sin límite). Sirve para cortes, cierres y la demostración. */
  C.calcCorte = function (S, temporadaId, desde, hasta) {
    var r = { ingresos: 0, gastos: 0, cargos: 0, ajustes: 0, saldo: 0, porCobrar: 0, formas: {}, nPagos: 0, nGastos: 0 }, sal = {};
    S.movs.forEach(function (m) {
      if (temporadaId && m.temporadaId !== temporadaId) return;
      var v = Number(m.monto) || 0, en = (!desde || m.fecha >= desde) && (!hasta || m.fecha <= hasta);
      if (!hasta || m.fecha <= hasta) sal[m.equipoId] = (sal[m.equipoId] || 0) + (m.tipo === 'pago' ? -v : v);   /* adeudo acumulado a la fecha */
      if (!en) return;
      if (m.tipo === 'pago') { r.ingresos += v; r.nPagos++; r.formas[m.forma || 'Sin especificar'] = (r.formas[m.forma || 'Sin especificar'] || 0) + v; }
      else if (m.tipo === 'ajuste') r.ajustes += v; else r.cargos += v;
    });
    S.gastos.forEach(function (g) { if ((!temporadaId || g.temporadaId === temporadaId) && (!desde || g.fecha >= desde) && (!hasta || g.fecha <= hasta)) { r.gastos += Number(g.monto) || 0; r.nGastos++; } });
    Object.keys(sal).forEach(function (k) { if (sal[k] > 0.009) r.porCobrar += sal[k]; });
    r.saldo = r.ingresos - r.gastos;
    return r;
  };
  function ultimoCorte(temporadaId) { return C.S.cortes.filter(function (c) { return c.temporadaId === temporadaId; }).sort(function (a, b) { return String(b.hasta).localeCompare(String(a.hasta)); })[0] || null; }
  /** ¿Esa fecha ya quedó dentro de un corte de caja (o la temporada está cerrada)? → texto del motivo, o '' */
  function bloqueo(temporadaId, fecha) {
    if (C.temporadaCerrada(temporadaId)) return 'La temporada está cerrada.';
    var u = ultimoCorte(temporadaId);
    return u && fecha <= u.hasta ? 'Esa fecha ya entró en el corte de caja del ' + C.fecha(u.hasta) + '. Regístralo con fecha posterior o corrige con un ajuste.' : '';
  }
  function temporadasAbiertas() { return C.S.temporadas.filter(function (t) { return t.estado !== 'cerrada'; }); }
  function opsTemporada() { return temporadasAbiertas().map(function (t) { return [t.id, t.nombre]; }); }
  function tempDef() { var t = C.temporadaActiva(); return t && t.estado !== 'cerrada' ? t.id : ((temporadasAbiertas()[0] || {}).id || ''); }
  function sinTemporada() { if (temporadasAbiertas().length) return false; C.aviso('Falta una temporada abierta', 'Los movimientos se registran en una temporada. Crea una en Torneos → Temporadas.', function () { C.ir('torneos', 'temporadas'); }); return true; }

  C.modulo('finanzas', {
    titulo: 'Finanzas',
    pintar: function (v, args) {
      var tab = TABS.some(function (t) { return t[0] === args[0]; }) ? args[0] : 'resumen';
      v.appendChild(C.pestanas(TABS, tab, function (p) { C.ir('finanzas', p); }));
      ({ resumen: resumen, movs: movimientos, cuentas: cuentas, gastos: gastos, cortes: cortes, conceptos: conceptos, cierre: cierre })[tab](v);
    }
  });

  /* ---------------- resumen ---------------- */
  function resumen(v) {
    var S = C.S, movs = C.movs(), c = C.cuenta(movs), gas = C.gastos().reduce(function (t, g) { return t + (Number(g.monto) || 0); }, 0), sal = {}, formas = {};
    movs.forEach(function (m) { sal[m.equipoId] = (sal[m.equipoId] || 0) + (m.tipo === 'pago' ? -m.monto : m.monto); if (m.tipo === 'pago') formas[m.forma || 'Sin especificar'] = (formas[m.forma || 'Sin especificar'] || 0) + m.monto; });
    var porCobrar = 0, nDeb = 0; Object.keys(sal).forEach(function (k) { if (sal[k] > 0.009) { porCobrar += sal[k]; nDeb++; } });
    v.appendChild(C.el('<div class="kpis"><a class="kpi" href="#/finanzas/movs"><div class="v num">' + C.mxn0(c.cargos + c.ajustes) + '</div><div class="e">Cargos</div><div class="s">' + (c.ajustes ? 'Con ajustes por ' + C.mxn(c.ajustes) : 'Sin ajustes') + '</div></a>'
      + '<a class="kpi bien" href="#/finanzas/movs"><div class="v num">' + C.mxn0(c.pagos) + '</div><div class="e">Cobrado</div><div class="s">' + (Object.keys(formas).map(function (k) { return k + ' ' + C.mxn0(formas[k]); }).join(' · ') || 'Sin pagos') + '</div></a>'
      + '<a class="kpi ' + (porCobrar > 0.009 ? 'alerta' : 'bien') + '" href="#/finanzas/cuentas"><div class="v num">' + C.mxn0(porCobrar) + '</div><div class="e">Por cobrar</div><div class="s">' + nDeb + ' equipo' + (nDeb === 1 ? '' : 's') + ' con adeudo</div></a>'
      + '<a class="kpi" href="#/finanzas/gastos"><div class="v num">' + C.mxn0(gas) + '</div><div class="e">Gastos</div><div class="s">' + C.gastos().length + ' registros</div></a>'
      + '<a class="kpi ' + (c.pagos - gas < -0.009 ? 'alerta' : '') + '" href="#/finanzas/cortes"><div class="v num">' + C.mxn0(c.pagos - gas) + '</div><div class="e">Caja</div><div class="s">Cobrado menos gastos</div></a></div>'));
    var ult = movs.slice().sort(function (a, b) { return C.ordenMovs(b, a); }).slice(0, 8);
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Últimos movimientos</h2><div class="acc"><button type="button" class="btn" data-m="cargo">+ Cargo</button><button type="button" class="btn pri" data-m="pago">+ Pago</button></div></div>'
      + (ult.length ? listaMovs(ult) : C.vacio('Sin movimientos con estos filtros.')) + '</section>');
    enlazaMovs(n); v.appendChild(n);
    if (C.f.categoria) v.appendChild(C.el('<p class="suave" style="font-size:12.5px">Con categoría elegida, los cargos y pagos son de los equipos de esa categoría; los gastos son los de toda la temporada.</p>'));
  }

  /* ---------------- cargos, pagos y ajustes ---------------- */
  function listaMovs(l) {
    var S = C.S;
    return '<ul class="lista">' + l.map(function (m) {
      var pago = m.tipo === 'pago';
      return '<li class="item click" data-mov="' + m.id + '"><span class="p"><b>' + C.esc(C.nom(S.equipos, m.equipoId, 'Equipo eliminado')) + '</b><small>' + C.esc(m.concepto || C.TIPOS_MOV[m.tipo]) + (m.forma ? ' · ' + C.esc(m.forma) : '')
        + (m.nota ? ' · ' + C.esc(m.nota) : '') + '</small></span><span class="d"><span class="num suave">' + C.fecha(m.fecha) + '</span><span class="pill ' + (pago ? 'ok' : m.tipo === 'ajuste' ? 'av' : '') + '">' + C.TIPOS_MOV[m.tipo] + '</span>'
        + '<b class="num" style="min-width:86px;text-align:right;color:' + (pago ? 'var(--verde)' : 'inherit') + '">' + (pago ? '− ' : '') + C.mxn(m.monto) + '</b></span></li>'; }).join('') + '</ul>';
  }
  function enlazaMovs(n) {
    n.addEventListener('click', function (e) {
      var b = e.target.closest('[data-m]'); if (b) return b.getAttribute('data-m') === 'masivo' ? cargoMasivo() : movimiento(b.getAttribute('data-m'));
      var li = e.target.closest('[data-mov]'); if (li) { var m = C.porId(C.S.movs, li.getAttribute('data-mov')); if (m) movimiento(m.tipo, m.equipoId, m); }
    });
  }
  function movimientos(v) {
    var S = C.S, eqs = C.equipos().sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
    if (fm.equipo && !C.idsEquipos()[fm.equipo]) fm.equipo = '';
    var l = C.movs().filter(function (m) { return (!fm.tipo || m.tipo === fm.tipo) && (!fm.equipo || m.equipoId === fm.equipo); }).sort(function (a, b) { return C.ordenMovs(b, a); });
    var tot = C.cuenta(l);
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Cargos y pagos</h2><div class="acc"><button type="button" class="btn" data-m="masivo">Cargo a varios equipos</button><button type="button" class="btn" data-m="ajuste">+ Ajuste</button>'
      + '<button type="button" class="btn" data-m="cargo">+ Cargo</button><button type="button" class="btn pri" data-m="pago">+ Pago</button></div></div>'
      + '<div class="barra-h"><select data-f="tipo" aria-label="Tipo"><option value="">Todos los tipos</option>' + Object.keys(C.TIPOS_MOV).map(function (k) { return '<option value="' + k + '"' + (fm.tipo === k ? ' selected' : '') + '>' + C.TIPOS_MOV[k] + 's</option>'; }).join('') + '</select>'
      + '<select data-f="equipo" aria-label="Equipo"><option value="">Todos los equipos</option>' + eqs.map(function (e) { return '<option value="' + e.id + '"' + (fm.equipo === e.id ? ' selected' : '') + '>' + C.esc(e.nombre) + '</option>'; }).join('') + '</select>'
      + '<span class="esp"></span><small class="suave">' + l.length + ' movimientos · cargos ' + C.mxn(tot.cargos + tot.ajustes) + ' · pagos ' + C.mxn(tot.pagos) + '</small></div>'
      + (l.length ? listaMovs(l.slice(0, 200)) + (l.length > 200 ? '<p class="suave" style="font-size:12.5px">Se muestran los 200 más recientes. Usa los filtros para acotar.</p>' : '') : C.vacio('Sin movimientos con estos filtros.')) + '</section>');
    enlazaMovs(n);
    n.addEventListener('change', function (e) { var s = e.target.closest('[data-f]'); if (s) { fm[s.getAttribute('data-f')] = s.value; C.pintar(); } });
    v.appendChild(n);
  }

  function movimiento(tipo, equipoId, m) {
    var S = C.S;
    if (!S.equipos.length) return C.aviso('Sin equipos', 'Da de alta un equipo antes de registrar movimientos.', function () { C.ir('equipos'); });
    if (!m && sinTemporada()) return;
    var cerrado = m ? bloqueo(m.temporadaId, m.fecha) : '';
    var eqs = S.equipos.slice().sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); }).map(function (e) { return [e.id, e.nombre]; });
    var cons = S.conceptos.filter(function (c) { return c.tipo === 'cargo'; });
    var campos = [{ k: 'equipoId', t: 'Equipo', tipo: 'select', req: true, op: eqs }];
    if (tipo === 'cargo') campos.push({ k: 'conceptoId', t: 'Concepto', tipo: 'select', vacio: 'Otro (escribirlo abajo)', op: cons.map(function (c) { return [c.id, c.nombre + (c.monto ? ' · ' + C.mxn(c.monto) : '')]; }), mitad: true },
      { k: 'concepto', t: 'Descripción', mitad: true, ayuda: 'Se llena sola al elegir un concepto.' });
    if (tipo === 'ajuste') campos.push({ k: 'signo', t: 'Tipo de ajuste', tipo: 'select', req: true, vacio: false, op: [['-1', 'Descuento o condonación (baja el adeudo)'], ['1', 'Recargo o corrección (sube el adeudo)']] });
    campos.push({ k: 'monto', t: 'Importe (MXN)', tipo: 'money', req: true, min: 0.01, mitad: true }, { k: 'fecha', t: 'Fecha', tipo: 'date', req: true, mitad: true });
    if (tipo === 'pago') campos.push({ k: 'forma', t: 'Forma de pago', tipo: 'select', req: true, vacio: false, op: FORMAS.map(function (f) { return [f, f]; }), mitad: true }, { k: 'concepto', t: 'Concepto', mitad: true, ph: 'Pago' });
    if (tipo === 'ajuste') campos.push({ k: 'concepto', t: 'Motivo del ajuste', req: true, ayuda: 'Queda en el estado de cuenta y en el historial.' });
    campos.push({ k: 'temporadaId', t: 'Temporada', tipo: 'select', req: true, vacio: false, op: m && C.temporadaCerrada(m.temporadaId) ? [[m.temporadaId, C.nom(S.temporadas, m.temporadaId)]] : opsTemporada() }, { k: 'nota', t: 'Nota', max: 200 });
    if (cerrado) campos.unshift({ tipo: 'nota', t: '<b>Sólo consulta.</b> ' + C.esc(cerrado) });
    C.formulario({
      titulo: (m ? (cerrado ? 'Ver ' : 'Editar ') : 'Registrar ') + C.TIPOS_MOV[tipo].toLowerCase(), sub: m ? 'Folio ' + m.folio : '',
      valores: m ? Object.assign({}, m, { monto: Math.abs(m.monto), signo: m.monto < 0 ? '-1' : '1' }) : { equipoId: equipoId || fm.equipo || '', fecha: C.hoy(), temporadaId: tempDef(), forma: 'Efectivo', signo: '-1' },
      campos: campos, boton: cerrado ? 'Cerrar' : 'Guardar',
      alAbrir: function (f) {
        if (f.elements.conceptoId) f.elements.conceptoId.onchange = function () { var c = C.porId(S.conceptos, this.value); if (c) { f.elements.concepto.value = c.nombre; if (c.monto) f.elements.monto.value = c.monto; } };
        if (tipo === 'pago' && !m) {
          var pinta = function () { var id = f.elements.equipoId.value, s = id ? C.cuenta(S.movs.filter(function (x) { return x.equipoId === id && x.temporadaId === f.elements.temporadaId.value; })).saldo : null;
            var h = f.querySelector('[data-saldo]') || f.elements.equipoId.parentNode.appendChild(C.el('<small class="suave" data-saldo></small>'));
            h.textContent = s == null ? '' : s > 0.009 ? 'Debe ' + C.mxn(s) + ' en esta temporada.' : s < -0.009 ? 'Tiene ' + C.mxn(-s) + ' a favor.' : 'Está al corriente.';
            if (s > 0.009 && !f.elements.monto.value) f.elements.monto.value = s.toFixed(2); };
          f.elements.equipoId.onchange = pinta; f.elements.temporadaId.onchange = pinta; pinta();
        }
        if (cerrado) Array.prototype.forEach.call(f.elements, function (x) { if (x.type !== 'submit' && x.type !== 'button') x.disabled = true; });
      },
      borrar: m && !cerrado ? function () {
        C.confirmar('Eliminar movimiento', 'Folio ' + m.folio + ' · ' + C.TIPOS_MOV[m.tipo] + ' de ' + C.mxn(Math.abs(m.monto)) + ' a ' + C.nom(S.equipos, m.equipoId) + '.', function (mo) {
          S.movs = S.movs.filter(function (x) { return x !== m; });
          C.guardar(C.TIPOS_MOV[m.tipo] + ' eliminado (folio ' + m.folio + ', ' + C.mxn(Math.abs(m.monto)) + ')', { equipoId: m.equipoId, detalle: mo }); C.pintar();
        }, { motivo: true, peligro: true, boton: 'Eliminar' });
      } : null,
      alGuardar: function (o, f) {
        if (cerrado) return;
        var b = bloqueo(o.temporadaId, o.fecha); if (b) return b;
        if (o.fecha > C.hoy() && tipo === 'pago') return 'Un pago no puede tener fecha futura.';
        var concepto = (o.concepto || '').trim() || (tipo === 'pago' ? 'Pago' : '');
        if (tipo === 'cargo' && !concepto) return 'Elige un concepto o escribe la descripción del cargo.';
        var monto = tipo === 'ajuste' ? o.monto * Number(o.signo) : o.monto, av = [];
        if (tipo === 'pago') {
          var s = C.cuenta(S.movs.filter(function (x) { return x !== m && x.equipoId === o.equipoId && x.temporadaId === o.temporadaId; })).saldo;
          if (monto > s + 0.009) av.push('El pago (' + C.mxn(monto) + ') es mayor que el adeudo (' + C.mxn(Math.max(0, s)) + '): quedará saldo a favor.');
        }
        if (tipo === 'cargo' && !m && S.movs.some(function (x) { return x.tipo === 'cargo' && x.equipoId === o.equipoId && x.fecha === o.fecha && C.norm(x.concepto) === C.norm(concepto) && Math.abs(x.monto - monto) < 0.005; }))
          av.push('Ese equipo ya tiene un cargo igual (mismo concepto, importe y fecha): podría estar duplicado.');
        if (av.length && !(f.elements.forzar && f.elements.forzar.checked)) return { avisos: av };
        var nuevo = !m;
        if (nuevo) { m = { id: C.uid('m'), folio: S.liga.folio++, tipo: tipo }; S.movs.push(m); }
        m.equipoId = o.equipoId; m.temporadaId = o.temporadaId; m.monto = Math.round(monto * 100) / 100; m.fecha = o.fecha; m.nota = o.nota || '';
        m.concepto = tipo === 'ajuste' ? concepto : concepto; m.conceptoId = o.conceptoId || ''; m.forma = tipo === 'pago' ? o.forma : '';
        C.guardar(C.TIPOS_MOV[tipo] + (nuevo ? ' registrado' : ' editado') + ': ' + concepto + ' · ' + C.mxn(Math.abs(m.monto)) + ' (folio ' + m.folio + ')', { equipoId: m.equipoId });
        setTimeout(C.pintar, 0);
      }
    });
  }

  function cargoMasivo() {
    var S = C.S, ts = C.torneos().filter(function (t) { return !C.temporadaCerrada(t.temporadaId) && (t.equipos || []).length; });
    if (!ts.length) return C.aviso('Sin torneos', 'Necesitas un torneo abierto con equipos para aplicar un cargo a varios.');
    var cons = S.conceptos.filter(function (c) { return c.tipo === 'cargo'; });
    C.formulario({
      titulo: 'Cargo a varios equipos', valores: { fecha: C.hoy(), torneoId: ts.length === 1 ? ts[0].id : '' },
      campos: [{ tipo: 'nota', t: 'Se registra el mismo cargo a todos los equipos del torneo (por ejemplo, la inscripción). Si un equipo ya tiene ese cargo en esa fecha, se omite.' },
        { k: 'torneoId', t: 'Torneo', tipo: 'select', req: true, op: ts.map(function (t) { return [t.id, t.nombre + ' · ' + t.equipos.length + ' equipos']; }) },
        { k: 'conceptoId', t: 'Concepto', tipo: 'select', vacio: 'Otro (escribirlo)', op: cons.map(function (c) { return [c.id, c.nombre]; }), mitad: true }, { k: 'concepto', t: 'Descripción', req: true, mitad: true },
        { k: 'monto', t: 'Importe por equipo (MXN)', tipo: 'money', req: true, min: 0.01, mitad: true }, { k: 'fecha', t: 'Fecha', tipo: 'date', req: true, mitad: true }],
      boton: 'Aplicar cargo',
      alAbrir: function (f) { f.elements.conceptoId.onchange = function () { var c = C.porId(S.conceptos, this.value); if (c) { f.elements.concepto.value = c.nombre; if (c.monto) f.elements.monto.value = c.monto; } }; },
      alGuardar: function (o) {
        var t = C.porId(S.torneos, o.torneoId), b = bloqueo(t.temporadaId, o.fecha); if (b) return b;
        var n = 0, om = 0;
        t.equipos.forEach(function (id) {
          if (!C.porId(S.equipos, id)) return;
          if (S.movs.some(function (x) { return x.tipo === 'cargo' && x.equipoId === id && x.fecha === o.fecha && C.norm(x.concepto) === C.norm(o.concepto); })) { om++; return; }
          S.movs.push({ id: C.uid('m'), folio: S.liga.folio++, tipo: 'cargo', equipoId: id, temporadaId: t.temporadaId, conceptoId: o.conceptoId || '', concepto: o.concepto, monto: Math.round(o.monto * 100) / 100, fecha: o.fecha, forma: '', nota: '' }); n++;
        });
        if (!n) return 'Todos los equipos ya tenían ese cargo en esa fecha.';
        C.guardar('Cargo a varios equipos: ' + o.concepto + ' · ' + C.mxn(o.monto) + ' × ' + n + ' (' + t.nombre + ')', { torneoId: t.id });
        C.toast(n + ' cargos registrados' + (om ? ' · ' + om + ' omitidos por duplicado' : '') + '.'); setTimeout(C.pintar, 0);
      }
    });
  }

  /* ---------------- estados de cuenta ---------------- */
  function cuentas(v) {
    var movs = C.movs(), tot = { c: 0, p: 0, s: 0 };
    var filas = C.equipos().map(function (e) { var c = C.cuenta(movs.filter(function (m) { return m.equipoId === e.id; })); return { e: e, c: c }; })
      .sort(function (a, b) { return b.c.saldo - a.c.saldo || a.e.nombre.localeCompare(b.e.nombre, 'es'); });
    filas.forEach(function (f) { tot.c += f.c.cargos + f.c.ajustes; tot.p += f.c.pagos; tot.s += f.c.saldo; });
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Estados de cuenta</h2></div>'
      + (filas.length ? '<div class="tabla-w"><table><thead><tr><th>Equipo</th><th class="n">Cargos</th><th class="n">Pagos</th><th class="n">Saldo</th></tr></thead><tbody>' + filas.map(function (f) {
        return '<tr class="click" data-eq="' + f.e.id + '"><td><b>' + C.esc(f.e.nombre) + '</b><br><small class="suave">' + C.esc((f.e.capitan || {}).nombre || 'Sin capitán') + '</small></td><td class="n">' + C.mxn(f.c.cargos + f.c.ajustes) + '</td><td class="n">' + C.mxn(f.c.pagos)
          + '</td><td class="n ' + (f.c.saldo > 0.009 ? 'deuda' : f.c.saldo < -0.009 ? 'favor' : '') + '">' + C.mxn(f.c.saldo) + '</td></tr>'; }).join('')
        + '</tbody><tfoot><tr><td>Total · ' + filas.length + ' equipos</td><td class="n">' + C.mxn(tot.c) + '</td><td class="n">' + C.mxn(tot.p) + '</td><td class="n">' + C.mxn(tot.s) + '</td></tr></tfoot></table></div>'
        : C.vacio('No hay equipos con estos filtros.')) + '<p class="suave" style="font-size:12.5px;margin:10px 0 0">Saldo = cargos + ajustes − pagos. En rojo, lo que debe el equipo; en verde, saldo a favor. Toca un equipo para ver su detalle.</p></section>');
    n.addEventListener('click', function (e) { var tr = e.target.closest('[data-eq]'); if (tr) C.ir('equipos', tr.getAttribute('data-eq'), 'cuenta'); });
    v.appendChild(n);
  }
  function cabImp(titulo, sub) { var L = C.S.liga; return '<h1>' + C.esc(L.nombre || 'Liga') + '</h1><div>' + C.esc(titulo) + '</div><div>' + C.esc(sub) + '</div>'; }
  function imprimirCuenta(e, movs) {
    var c = C.cuenta(movs), t = C.porId(C.S.temporadas, C.f.temporada);
    C.imprimir(cabImp('Estado de cuenta · ' + e.nombre, (t ? t.nombre + ' · ' : '') + 'Emitido el ' + C.fecha(C.hoy()) + ' · Importes en pesos mexicanos (MXN)')
      + '<h2>Saldo: ' + C.mxn(c.saldo) + (c.saldo > 0.009 ? ' por pagar' : c.saldo < -0.009 ? ' a favor' : ' (al corriente)') + '</h2>' + C.cuentaHTML(movs));
  }

  /* ---------------- gastos ---------------- */
  function gastos(v) {
    var S = C.S, l = C.gastos().slice().sort(function (a, b) { return String(b.fecha).localeCompare(String(a.fecha)) || b.folio - a.folio; }), tot = l.reduce(function (t, g) { return t + g.monto; }, 0);
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Gastos</h2><div class="acc"><button type="button" class="btn pri" data-nuevo>' + C.ICO.mas + 'Registrar gasto</button></div></div>'
      + (l.length ? '<div class="tabla-w"><table><thead><tr><th>Fecha</th><th>Concepto</th><th>Pagado a</th><th class="n">Importe</th></tr></thead><tbody>' + l.map(function (g) {
        return '<tr class="click" data-g="' + g.id + '"><td class="num">' + C.fecha(g.fecha) + '</td><td>' + C.esc(g.concepto) + (g.nota ? '<br><small class="suave">' + C.esc(g.nota) + '</small>' : '') + '</td><td>' + C.esc(g.beneficiario || '—') + '</td><td class="n">' + C.mxn(g.monto) + '</td></tr>'; }).join('')
        + '</tbody><tfoot><tr><td colspan="3">Total · ' + l.length + ' gastos</td><td class="n">' + C.mxn(tot) + '</td></tr></tfoot></table></div>' : C.vacio('Sin gastos registrados con estos filtros.')) + '</section>');
    n.querySelector('[data-nuevo]').onclick = function () { gasto(null); };
    n.addEventListener('click', function (e) { var tr = e.target.closest('[data-g]'); if (tr) gasto(C.porId(S.gastos, tr.getAttribute('data-g'))); });
    v.appendChild(n);
  }
  function gasto(g) {
    var S = C.S; if (!g && sinTemporada()) return;
    var cerrado = g ? bloqueo(g.temporadaId, g.fecha) : '', cons = S.conceptos.filter(function (c) { return c.tipo === 'gasto'; });
    C.formulario({
      titulo: g ? (cerrado ? 'Ver gasto' : 'Editar gasto') : 'Registrar gasto', sub: g ? 'Folio G-' + g.folio : '', valores: g || { fecha: C.hoy(), temporadaId: tempDef() },
      campos: (cerrado ? [{ tipo: 'nota', t: '<b>Sólo consulta.</b> ' + C.esc(cerrado) }] : []).concat([
        { k: 'conceptoId', t: 'Concepto', tipo: 'select', vacio: 'Otro (escribirlo)', op: cons.map(function (c) { return [c.id, c.nombre]; }), mitad: true }, { k: 'concepto', t: 'Descripción', req: true, mitad: true },
        { k: 'monto', t: 'Importe (MXN)', tipo: 'money', req: true, min: 0.01, mitad: true }, { k: 'fecha', t: 'Fecha', tipo: 'date', req: true, mitad: true },
        { k: 'beneficiario', t: 'Pagado a', mitad: true }, { k: 'temporadaId', t: 'Temporada', tipo: 'select', req: true, vacio: false, mitad: true, op: g && C.temporadaCerrada(g.temporadaId) ? [[g.temporadaId, C.nom(S.temporadas, g.temporadaId)]] : opsTemporada() },
        { k: 'nota', t: 'Nota', max: 200 }]),
      boton: cerrado ? 'Cerrar' : 'Guardar',
      alAbrir: function (f) {
        f.elements.conceptoId.onchange = function () { var c = C.porId(S.conceptos, this.value); if (c) { f.elements.concepto.value = c.nombre; if (c.monto && !f.elements.monto.value) f.elements.monto.value = c.monto; } };
        if (cerrado) Array.prototype.forEach.call(f.elements, function (x) { if (x.type !== 'submit' && x.type !== 'button') x.disabled = true; });
      },
      borrar: g && !cerrado ? function () { C.confirmar('Eliminar gasto', 'Folio G-' + g.folio + ' · ' + g.concepto + ' · ' + C.mxn(g.monto), function (mo) {
        S.gastos = S.gastos.filter(function (x) { return x !== g; }); C.guardar('Gasto eliminado (G-' + g.folio + ', ' + C.mxn(g.monto) + ')', { detalle: mo }); C.pintar(); }, { motivo: true, peligro: true, boton: 'Eliminar' }); } : null,
      alGuardar: function (o) {
        if (cerrado) return;
        var b = bloqueo(o.temporadaId, o.fecha); if (b) return b;
        if (o.fecha > C.hoy()) return 'Un gasto no puede tener fecha futura.';
        var nuevo = !g; if (nuevo) { g = { id: C.uid('g'), folio: S.liga.folioGasto++ }; S.gastos.push(g); }
        Object.assign(g, { conceptoId: o.conceptoId || '', concepto: o.concepto, monto: Math.round(o.monto * 100) / 100, fecha: o.fecha, beneficiario: o.beneficiario || '', temporadaId: o.temporadaId, nota: o.nota || '' });
        C.guardar('Gasto ' + (nuevo ? 'registrado' : 'editado') + ': ' + g.concepto + ' · ' + C.mxn(g.monto) + ' (G-' + g.folio + ')'); setTimeout(C.pintar, 0);
      }
    });
  }

  /* ---------------- cortes de caja ---------------- */
  function cortes(v) {
    var S = C.S, l = S.cortes.filter(function (c) { return !C.f.temporada || c.temporadaId === C.f.temporada; }).sort(function (a, b) { return String(b.hasta).localeCompare(String(a.hasta)); });
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Cortes de caja</h2><div class="acc"><button type="button" class="btn pri" data-nuevo>Hacer corte de caja</button></div></div>'
      + '<p class="suave" style="font-size:13px;margin:-6px 0 12px">Un corte congela un periodo: lo cobrado, lo gastado y lo que queda por cobrar a esa fecha. Después del corte, los movimientos de ese periodo ya no se editan.</p>'
      + (l.length ? '<div class="tabla-w"><table><thead><tr><th>Periodo</th><th>Temporada</th><th class="n">Ingresos</th><th class="n">Gastos</th><th class="n">Saldo del periodo</th><th class="n">Por cobrar</th></tr></thead><tbody>' + l.map(function (c) {
        return '<tr class="click" data-c="' + c.id + '"><td class="num">' + C.fecha(c.desde) + ' al ' + C.fecha(c.hasta) + '</td><td>' + C.esc(C.nom(S.temporadas, c.temporadaId)) + '</td><td class="n">' + C.mxn(c.ingresos) + '</td><td class="n">' + C.mxn(c.gastos)
          + '</td><td class="n ' + (c.saldo < -0.009 ? 'deuda' : '') + '"><b>' + C.mxn(c.saldo) + '</b></td><td class="n">' + C.mxn(c.porCobrar) + '</td></tr>'; }).join('') + '</tbody></table></div>' : C.vacio('Todavía no hay cortes de caja.')) + '</section>');
    n.querySelector('[data-nuevo]').onclick = nuevoCorte;
    n.addEventListener('click', function (e) { var tr = e.target.closest('[data-c]'); if (tr) verCorte(C.porId(S.cortes, tr.getAttribute('data-c'))); });
    v.appendChild(n);
  }
  function nuevoCorte() {
    var S = C.S; if (sinTemporada()) return;
    C.formulario({
      titulo: 'Hacer corte de caja', valores: { temporadaId: tempDef(), hasta: C.hoy() },
      campos: [{ k: 'temporadaId', t: 'Temporada', tipo: 'select', req: true, vacio: false, op: opsTemporada() }, { k: 'hasta', t: 'Fecha de corte', tipo: 'date', req: true, ayuda: 'El periodo empieza el día siguiente al corte anterior (o al inicio de la temporada).' },
               { k: 'nota', t: 'Observaciones', tipo: 'textarea', max: 300 }, { tipo: 'nota', t: '<span data-prev></span>' }],
      boton: 'Guardar corte',
      alAbrir: function (f) {
        function prev() {
          var u = ultimoCorte(f.elements.temporadaId.value), t = C.porId(S.temporadas, f.elements.temporadaId.value), desde = u ? C.sumaDias(u.hasta, 1) : '', h = f.elements.hasta.value;
          var k = h ? C.calcCorte(S, t.id, desde, h) : null;
          f.querySelector('[data-prev]').innerHTML = k ? '<b>Vista previa</b> · del ' + C.fecha(desde || t.inicio) + ' al ' + C.fecha(h) + ': ingresos ' + C.mxn(k.ingresos) + ' (' + k.nPagos + ' pagos) · gastos ' + C.mxn(k.gastos) + ' · saldo <b>' + C.mxn(k.saldo) + '</b> · por cobrar ' + C.mxn(k.porCobrar) : '';
        }
        f.elements.temporadaId.onchange = prev; f.elements.hasta.oninput = prev; prev();
      },
      alGuardar: function (o) {
        var u = ultimoCorte(o.temporadaId), t = C.porId(S.temporadas, o.temporadaId);
        if (o.hasta > C.hoy()) return 'La fecha de corte no puede ser futura.';
        if (u && o.hasta <= u.hasta) return 'Ya hay un corte hasta el ' + C.fecha(u.hasta) + '. Elige una fecha posterior.';
        var desde = u ? C.sumaDias(u.hasta, 1) : '', k = C.calcCorte(S, t.id, desde, o.hasta), ac = C.calcCorte(S, t.id, '', o.hasta);
        var c = { id: C.uid('c'), temporadaId: t.id, desde: desde || t.inicio || o.hasta, hasta: o.hasta, ingresos: k.ingresos, gastos: k.gastos, saldo: k.saldo, acumulado: ac.saldo, porCobrar: k.porCobrar,
                  formas: k.formas, nPagos: k.nPagos, nGastos: k.nGastos, nota: o.nota || '', creado: C.sello() };
        S.cortes.push(c);
        C.guardar('Corte de caja al ' + C.fecha(c.hasta) + ': saldo ' + C.mxn(c.saldo)); setTimeout(function () { C.pintar(); verCorte(c); }, 0);
      }
    });
  }
  function htmlCorte(c) {
    var S = C.S, f = c.formas || {};
    return '<table><tbody><tr><td>Ingresos cobrados' + (c.nPagos != null ? ' (' + c.nPagos + ' pagos)' : '') + '</td><td class="n">' + C.mxn(c.ingresos) + '</td></tr>'
      + Object.keys(f).map(function (k) { return '<tr><td style="padding-left:26px" class="suave">' + C.esc(k) + '</td><td class="n suave">' + C.mxn(f[k]) + '</td></tr>'; }).join('')
      + '<tr><td>Gastos' + (c.nGastos != null ? ' (' + c.nGastos + ')' : '') + '</td><td class="n">− ' + C.mxn(c.gastos) + '</td></tr></tbody><tfoot><tr><td>Saldo del periodo</td><td class="n">' + C.mxn(c.saldo) + '</td></tr>'
      + '<tr><td>Saldo acumulado de la temporada</td><td class="n">' + C.mxn(c.acumulado == null ? c.saldo : c.acumulado) + '</td></tr><tr><td>Por cobrar a equipos (a la fecha de corte)</td><td class="n">' + C.mxn(c.porCobrar) + '</td></tr></tfoot></table>';
  }
  function verCorte(c) {
    if (!c) return;
    var S = C.S, ult = ultimoCorte(c.temporadaId) === c;
    var b = C.el('<div><div class="tabla-w">' + htmlCorte(c) + '</div>' + (c.nota ? '<p style="margin:12px 0 0"><b>Observaciones:</b> ' + C.esc(c.nota) + '</p>' : '')
      + '<p class="suave" style="font-size:12.5px;margin:10px 0 0">Elaborado el ' + C.selloTxt(c.creado) + '. Cifras congeladas al momento del corte.</p>'
      + '<div class="modal-pie">' + (ult && !C.temporadaCerrada(c.temporadaId) ? '<button type="button" class="btn peligro" data-borrar style="margin-right:auto">Anular corte</button>' : '') + '<button type="button" class="btn pri" data-imp>Imprimir</button></div></div>');
    C.modal('Corte de caja', b, { sub: C.nom(S.temporadas, c.temporadaId) + ' · del ' + C.fecha(c.desde) + ' al ' + C.fecha(c.hasta), sinFoco: true });
    b.querySelector('[data-imp]').onclick = function () {
      C.imprimir(cabImp('Corte de caja · ' + C.nom(S.temporadas, c.temporadaId), 'Periodo del ' + C.fecha(c.desde) + ' al ' + C.fecha(c.hasta) + ' · Importes en pesos mexicanos (MXN)') + '<h2>Resumen</h2>' + htmlCorte(c)
        + (c.nota ? '<p><b>Observaciones:</b> ' + C.esc(c.nota) + '</p>' : '') + '<p>Elaborado el ' + C.selloTxt(c.creado) + ' (hora del centro de México).</p><div class="firma">Elaboró</div>');
    };
    var x = b.querySelector('[data-borrar]');
    if (x) x.onclick = function () { C.confirmar('Anular corte de caja', 'El periodo vuelve a quedar abierto para editar. Sólo se puede anular el corte más reciente.', function (mo) {
      S.cortes = S.cortes.filter(function (k) { return k !== c; }); C.guardar('Corte de caja anulado (al ' + C.fecha(c.hasta) + ')', { detalle: mo }); C.pintar(); }, { motivo: true, peligro: true, boton: 'Anular corte' }); };
  }

  /* ---------------- conceptos ---------------- */
  function conceptos(v) {
    var S = C.S;
    function bloque(tipo, titulo, vacio) {
      var l = S.conceptos.filter(function (c) { return c.tipo === tipo; });
      return '<div class="cab" style="margin-top:8px"><h3 style="font-size:18px">' + titulo + '</h3><button type="button" class="btn sm" data-nuevo="' + tipo + '">+ Concepto</button></div>'
        + (l.length ? '<ul class="lista">' + l.map(function (c) { return '<li class="item click" data-c="' + c.id + '"><span class="p"><b>' + C.esc(c.nombre) + '</b></span><span class="d num">' + (c.monto ? C.mxn(c.monto) : '<small class="suave">Importe libre</small>') + '</span></li>'; }).join('') + '</ul>' : C.vacio(vacio));
    }
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Conceptos</h2></div><p class="suave" style="font-size:13px;margin:-6px 0 6px">Catálogo para capturar más rápido. El importe es una sugerencia: se puede cambiar en cada movimiento.</p>'
      + bloque('cargo', 'De cobro a equipos', 'Agrega conceptos como «Inscripción» o «Arbitraje».') + '<div style="height:14px"></div>' + bloque('gasto', 'De gasto', 'Agrega conceptos como «Pago de árbitros» o «Renta de cancha».') + '</section>');
    function ed(c, tipo) {
      C.formulario({
        titulo: c ? 'Editar concepto' : 'Nuevo concepto', valores: c || { tipo: tipo },
        campos: [{ k: 'nombre', t: 'Nombre', req: true }, { k: 'monto', t: 'Importe sugerido (MXN)', tipo: 'money', mitad: true }, { k: 'tipo', t: 'Se usa en', tipo: 'select', req: true, vacio: false, mitad: true, op: [['cargo', 'Cobros a equipos'], ['gasto', 'Gastos']] }],
        borrar: c ? function () { S.conceptos = S.conceptos.filter(function (x) { return x !== c; }); C.cerrarModal(); C.guardar('Concepto eliminado: ' + c.nombre); C.pintar(); } : null,
        alGuardar: function (o) {
          if (S.conceptos.some(function (x) { return x !== c && x.tipo === o.tipo && C.norm(x.nombre) === C.norm(o.nombre); })) return 'Ya existe ese concepto.';
          o.monto = o.monto || 0;
          if (c) Object.assign(c, o); else S.conceptos.push(Object.assign({ id: C.uid('o') }, o));
          C.guardar('Concepto guardado: ' + o.nombre); setTimeout(C.pintar, 0);
        }
      });
    }
    n.addEventListener('click', function (e) {
      var b = e.target.closest('[data-nuevo]'); if (b) return ed(null, b.getAttribute('data-nuevo'));
      var li = e.target.closest('[data-c]'); if (li) ed(C.porId(S.conceptos, li.getAttribute('data-c')));
    });
    v.appendChild(n);
  }

  /* ---------------- cierre de temporada ---------------- */
  function cierre(v) {
    var S = C.S, t = C.porId(S.temporadas, C.f.temporada) || C.temporadaActiva();
    if (!t) { v.appendChild(C.el('<section class="tarjeta">' + C.vacio('No hay temporadas. Crea una en Torneos → Temporadas.') + '</section>')); return; }
    var k = C.calcCorte(S, t.id, '', ''), ids = {}; S.torneos.forEach(function (x) { if (x.temporadaId === t.id) ids[x.id] = 1; });
    var sinRes = S.partidos.filter(function (p) { return ids[p.torneoId] && (p.estado === 'programado' || p.estado === 'suspendido'); }).length;
    var abiertos = S.torneos.filter(function (x) { return x.temporadaId === t.id && x.estado !== 'finalizado' && x.estado !== 'cancelado'; }).length;
    var u = ultimoCorte(t.id), sinCorte = S.movs.some(function (m) { return m.temporadaId === t.id && (!u || m.fecha > u.hasta); }) || S.gastos.some(function (g) { return g.temporadaId === t.id && (!u || g.fecha > u.hasta); });
    var cerrada = t.estado === 'cerrada', z = t.cierre || {};
    var res = '<div class="kpis"><div class="kpi bien"><div class="v num">' + C.mxn0(cerrada ? z.ingresos : k.ingresos) + '</div><div class="e">Ingresos</div></div><div class="kpi"><div class="v num">' + C.mxn0(cerrada ? z.gastos : k.gastos) + '</div><div class="e">Gastos</div></div>'
      + '<div class="kpi"><div class="v num">' + C.mxn0(cerrada ? z.saldo : k.saldo) + '</div><div class="e">Saldo final</div></div><div class="kpi ' + ((cerrada ? z.porCobrar : k.porCobrar) > 0.009 ? 'alerta' : 'bien') + '"><div class="v num">' + C.mxn0(cerrada ? z.porCobrar : k.porCobrar) + '</div><div class="e">Quedó por cobrar</div></div></div>';
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Cierre de temporada · ' + C.esc(t.nombre) + ' ' + (cerrada ? '<span class="pill">Cerrada</span>' : '<span class="pill ok">Abierta</span>') + '</h2></div>'
      + (C.f.temporada ? '' : '<p class="suave" style="font-size:12.5px;margin:-6px 0 10px">Se muestra la temporada activa. Elige otra en el filtro de arriba.</p>') + res
      + (cerrada ? '<p style="margin:0 0 6px">Cerrada el <b>' + C.fecha(z.fecha) + '</b>.' + (z.nota ? ' ' + C.esc(z.nota) : '') + '</p><p class="suave" style="font-size:13px;margin:0 0 14px">Sus torneos, resultados y movimientos son de sólo consulta.</p><button type="button" class="btn" data-a="reabrir">Reabrir temporada</button>'
        : '<ul style="margin:0 0 14px;padding-left:20px;display:grid;gap:4px">'
          + '<li>' + (sinRes ? '<b style="color:var(--rojo)">' + sinRes + ' partidos</b> siguen programados o suspendidos.' : 'Todos los partidos tienen resultado o están cancelados. ✓') + '</li>'
          + '<li>' + (abiertos ? '<b style="color:var(--ambar)">' + abiertos + ' torneos</b> no están marcados como finalizados.' : 'Todos los torneos están finalizados o cancelados. ✓') + '</li>'
          + '<li>' + (k.porCobrar > 0.009 ? '<b style="color:var(--rojo)">' + C.mxn(k.porCobrar) + '</b> por cobrar: quedarán como adeudo histórico.' : 'Sin adeudos de equipos. ✓') + '</li>'
          + '<li>' + (sinCorte ? '<b style="color:var(--ambar)">Hay movimientos sin corte de caja.</b> Conviene hacer el último corte antes de cerrar.' : 'Todos los movimientos están en un corte de caja. ✓') + '</li></ul>'
          + '<button type="button" class="btn pri" data-a="cerrar">Cerrar temporada</button> <span class="suave" style="font-size:13px">Al cerrar, la temporada queda de sólo consulta.</span>') + '</section>');
    n.addEventListener('click', function (e) {
      var b = e.target.closest('[data-a]'); if (!b) return;
      if (b.getAttribute('data-a') === 'cerrar') {
        if (sinRes) return C.aviso('Aún hay partidos abiertos', 'Captura el resultado o cancela los ' + sinRes + ' partidos pendientes antes de cerrar la temporada.', function () { C.ir('resultados'); });
        C.confirmar('Cerrar temporada', '«' + t.nombre + '» quedará de sólo consulta: no admitirá partidos, resultados ni movimientos nuevos. Escribe una nota de cierre.', function (mo) {
          t.estado = 'cerrada'; t.cierre = { fecha: C.hoy(), ingresos: k.ingresos, gastos: k.gastos, saldo: k.saldo, porCobrar: k.porCobrar, nota: mo };
          S.torneos.forEach(function (x) { if (x.temporadaId === t.id && x.estado !== 'cancelado') x.estado = 'finalizado'; });
          C.guardar('Temporada cerrada: ' + t.nombre + ' · saldo ' + C.mxn(k.saldo), { detalle: mo }); C.pintar();
        }, { motivo: true, boton: 'Cerrar temporada' });
      } else C.confirmar('Reabrir temporada', '«' + t.nombre + '» volverá a admitir cambios.', function (mo) {
        t.estado = 'activa'; t.reaperturas = (t.reaperturas || []).concat([{ fecha: C.hoy(), motivo: mo }]); delete t.cierre;
        C.guardar('Temporada reabierta: ' + t.nombre, { detalle: mo }); C.pintar();
      }, { motivo: true, boton: 'Reabrir' });
    });
    v.appendChild(n);
  }

  C.finanzasUI = { movimiento: movimiento, imprimirCuenta: imprimirCuenta };
})();
