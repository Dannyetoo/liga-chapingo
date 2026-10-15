/* Panel de coordinación — DATOS DE DEMOSTRACIÓN y generador de rol.
   La demostración vive en su propio archivo del servidor (demo.json): nunca se mezcla con la liga real.
   Todos los nombres, teléfonos e importes son inventados. Las fechas se calculan alrededor de «hoy». */
(function () {
  'use strict';

  /** Todos contra todos (método del círculo). Devuelve jornadas: [[ [local, visita], … ], …] */
  C.rolTodos = function (ids, idaVuelta) {
    var e = ids.slice(); if (e.length % 2) e.push(null);
    var n = e.length, rondas = [];
    for (var r = 0; r < n - 1; r++) {
      var j = [];
      for (var i = 0; i < n / 2; i++) {
        var a = e[i], b = e[n - 1 - i];
        if (a && b) j.push(r % 2 ? [b, a] : [a, b]);
      }
      rondas.push(j);
      e.splice(1, 0, e.pop());
    }
    if (idaVuelta) rondas = rondas.concat(rondas.map(function (j) { return j.map(function (p) { return [p[1], p[0]]; }); }));
    return rondas;
  };
  C.nuevoCodigo = function () {
    var A = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', s = '', a = new Uint8Array(8);
    (window.crypto || window.msCrypto).getRandomValues(a);
    for (var i = 0; i < 8; i++) s += A[a[i] % A.length];
    return s.slice(0, 4) + '-' + s.slice(4);
  };

  C.demo = {};
  C.demo.crear = function () {
    var S = C.estadoVacio(), hoy = C.hoy(), n = 0;
    /* las jornadas caen en sábado (y domingo la femenil): se ancla al último sábado */
    var ph = hoy.split('-'), sab = C.sumaDias(hoy, -((new Date(+ph[0], +ph[1] - 1, +ph[2]).getDay() + 1) % 7));
    function id(p) { return p + 'demo' + (++n); }
    /* semilla fija: la demostración sale igual cada vez que se restablece */
    var sem = 20261;
    function azar(max) { sem = (sem * 9301 + 49297) % 233280; return Math.floor(sem / 233280 * max); }

    S.liga = { nombre: 'Liga Demostración', logo: '', color: '#123B63',
      contacto: { responsable: 'Coordinación general', telefono: '55 0000 0000', correo: 'contacto@ejemplo.mx', direccion: 'Av. del Deporte 100, Ciudad de México' },
      sedes: [{ id: 'sdemo1', nombre: 'Unidad Deportiva Central', direccion: 'Av. del Deporte 100', canchas: [{ id: 'cdemo1', nombre: 'Cancha 1' }, { id: 'cdemo2', nombre: 'Cancha 2' }] },
              { id: 'sdemo2', nombre: 'Deportivo Norte', direccion: 'Calle Norte 45', canchas: [{ id: 'cdemo3', nombre: 'Cancha A' }] }],
      duracion: 60, folio: 1, folioGasto: 1 };

    var tAnt = { id: 'tdemo0', nombre: 'Clausura 2026', inicio: C.sumaDias(hoy, -210), fin: C.sumaDias(hoy, -80), estado: 'cerrada',
                 cierre: { fecha: C.sumaDias(hoy, -75), ingresos: 18400, gastos: 12150, saldo: 6250, porCobrar: 0, nota: 'Temporada cerrada sin adeudos.' } };
    var tAct = { id: 'tdemo1', nombre: 'Apertura 2026', inicio: C.sumaDias(hoy, -35), fin: C.sumaDias(hoy, 70), estado: 'activa' };
    S.temporadas = [tAnt, tAct];
    S.categorias = [{ id: 'kdemo1', nombre: 'Varonil Primera', disciplina: 'Fútbol 7', rama: 'Varonil' },
                    { id: 'kdemo2', nombre: 'Varonil Segunda', disciplina: 'Fútbol 7', rama: 'Varonil' },
                    { id: 'kdemo3', nombre: 'Femenil', disciplina: 'Fútbol 7', rama: 'Femenil' }];
    S.conceptos = [{ id: 'odemo1', nombre: 'Inscripción', monto: 650, tipo: 'cargo' }, { id: 'odemo2', nombre: 'Arbitraje', monto: 150, tipo: 'cargo' },
                   { id: 'odemo3', nombre: 'Credenciales', monto: 120, tipo: 'cargo' },
                   { id: 'odemo4', nombre: 'Pago de árbitros', monto: 280, tipo: 'gasto' }, { id: 'odemo5', nombre: 'Renta de cancha', monto: 400, tipo: 'gasto' },
                   { id: 'odemo6', nombre: 'Trofeos y medallas', monto: 0, tipo: 'gasto' }];

    var NOMBRES = [['Halcones del Valle', 'Atlético Nopal', 'Real Ajusco', 'Deportivo Maguey', 'Jaguares FC', 'Coyotes Unidos', 'Lobos del Sur', 'Águilas Doradas'],
                   ['Cometas FC', 'Deportivo Obsidiana', 'Rayos del Norte', 'Unión Cempasúchil', 'Tigrillos', 'Real Tezontle'],
                   ['Colibríes', 'Panteras Rosas', 'Estrellas del Centro', 'Lunas FC']];
    var CAP = ['Mariana Soto', 'Luis Herrera', 'Diego Ramírez', 'Fernanda Cruz', 'Jorge Medina', 'Paola Aguilar', 'Raúl Domínguez', 'Karla Vega', 'Iván Castillo', 'Sofía Morales',
               'Emilio Navarro', 'Daniela Ríos', 'Héctor Salinas', 'Andrea León', 'Óscar Fuentes', 'Valeria Campos', 'Rubén Ortega', 'Lucía Paredes'];
    var JUG = ['Alan', 'Brenda', 'Carlos', 'Dulce', 'Erick', 'Fabiola', 'Gerardo', 'Hilda', 'Israel', 'Julia', 'Kevin', 'Lorena'];
    var APE = ['García', 'López', 'Martínez', 'Pérez', 'Sánchez', 'Flores', 'Torres', 'Reyes', 'Mendoza', 'Rojas'];

    var formatos = ['liga', 'liga_ida_vuelta', 'liga_ida_vuelta'], k = 0;
    S.categorias.forEach(function (cat, ci) {
      var ids = NOMBRES[ci].map(function (nom, i) {
        var e = { id: id('e'), nombre: nom, capitan: { nombre: CAP[k % CAP.length], telefono: '55 0000 ' + ('000' + (100 + k)).slice(-4), correo: '' },
                  codigo: C.nuevoCodigo(), inscripcion: { estado: i === NOMBRES[ci].length - 1 ? 'pendiente' : 'completa', fecha: C.sumaDias(hoy, -34 + i) },
                  plantilla: [], notas: '', creado: C.sumaDias(hoy, -36) };
        for (var j = 0; j < 8; j++) e.plantilla.push({ id: id('j'), nombre: JUG[(k + j * 3) % JUG.length] + ' ' + APE[(k + j) % APE.length], numero: j + 1 });
        k++; S.equipos.push(e); return e.id;
      });
      var t = { id: id('t'), nombre: 'Torneo ' + cat.nombre, temporadaId: tAct.id, categoriaId: cat.id, formato: formatos[ci], inicio: tAct.inicio, fin: tAct.fin,
                estado: 'en_curso', equipos: ids, reglas: { pg: 3, pe: 1, pp: 0, desempate: ['dif', 'gf', 'h2h'] } };
      S.torneos.push(t);
      /* una jornada por semana: las pasadas con resultado, las próximas programadas */
      var horas = ['08:00', '09:15', '10:30', '11:45'];
      C.rolTodos(ids, formatos[ci] === 'liga_ida_vuelta').forEach(function (cruces, r) {
        var f = C.sumaDias(sab, -28 + r * 7 + (ci === 2 ? 1 : 0));
        var jor = { id: id('r'), torneoId: t.id, numero: r + 1, nombre: 'Jornada ' + (r + 1), fecha: f };
        S.jornadas.push(jor);
        cruces.forEach(function (c, i) {
          var p = { id: id('p'), torneoId: t.id, jornadaId: jor.id, localId: c[0], visitaId: c[1], fecha: f, hora: horas[i % horas.length],
                    sedeId: ci === 1 ? 'sdemo2' : 'sdemo1', canchaId: ci === 1 ? 'cdemo3' : (ci === 0 ? 'cdemo1' : 'cdemo2'),
                    estado: 'programado', gl: null, gv: null, motivo: '', historial: [] };
          if (f < hoy) {
            if (r === 2 && i === 0 && ci === 0) { p.estado = 'suspendido'; p.motivo = 'Lluvia: cancha inundada.'; p.historial.push({ ts: f + ' 08:10', accion: 'Suspendido', detalle: p.motivo }); }
            else if (C.sumaDias(f, 7) >= hoy && i === cruces.length - 1) { /* jugado la semana pasada, falta capturar */ }
            else { p.estado = 'finalizado'; p.gl = azar(5); p.gv = azar(4); p.historial.push({ ts: f + ' 13:00', accion: 'Resultado capturado', detalle: p.gl + '–' + p.gv }); }
          }
          S.partidos.push(p);
        });
      });
    });

    /* finanzas: inscripción a todos, arbitraje por partido jugado; algunos pagan completo, otros a medias */
    var folio = 1;
    function mov(o) { o.id = id('m'); o.folio = folio++; o.temporadaId = tAct.id; S.movs.push(o); }
    S.equipos.forEach(function (e, i) {
      mov({ tipo: 'cargo', equipoId: e.id, conceptoId: 'odemo1', concepto: 'Inscripción', monto: 650, fecha: C.sumaDias(hoy, -34), forma: '', nota: '' });
      if (i % 6 !== 5) mov({ tipo: 'pago', equipoId: e.id, concepto: 'Pago de inscripción', monto: i % 7 === 3 ? 400 : 650, fecha: C.sumaDias(hoy, -33 + (i % 6)), forma: i % 2 ? 'Transferencia' : 'Efectivo', nota: '' });
    });
    S.partidos.forEach(function (p, i) {
      if (p.estado !== 'finalizado') return;
      [p.localId, p.visitaId].forEach(function (eid, j) {
        mov({ tipo: 'cargo', equipoId: eid, conceptoId: 'odemo2', concepto: 'Arbitraje', monto: 150, fecha: p.fecha, forma: '', nota: '', partidoId: p.id });
        /* sólo queda sin pagar parte del arbitraje de la última jornada jugada */
        if (p.fecha < C.sumaDias(hoy, -8) || (i + j) % 3 !== 0) mov({ tipo: 'pago', equipoId: eid, concepto: 'Pago de arbitraje', monto: 150, fecha: p.fecha, forma: (i + j) % 5 ? 'Efectivo' : 'Transferencia', nota: '' });
      });
    });
    mov({ tipo: 'ajuste', equipoId: S.equipos[1].id, concepto: 'Descuento por pronto pago', monto: -50, fecha: C.sumaDias(hoy, -20), forma: '', nota: 'Autorizado por coordinación.' });
    S.liga.folio = folio;
    var fg = 1;
    [[-28, 'Pago de árbitros', 'Jornada 1', 1680], [-27, 'Renta de cancha', 'Deportivo Norte', 400], [-21, 'Pago de árbitros', 'Jornada 2', 1680],
     [-14, 'Pago de árbitros', 'Jornada 3', 1400], [-10, 'Trofeos y medallas', 'Anticipo', 1500], [-7, 'Pago de árbitros', 'Jornada 4', 1680]].forEach(function (g) {
      S.gastos.push({ id: id('g'), folio: fg++, fecha: C.sumaDias(hoy, g[0]), concepto: g[1], beneficiario: g[2], monto: g[3], nota: '', temporadaId: tAct.id });
    });
    S.liga.folioGasto = fg;

    /* un corte de caja ya hecho, hace dos semanas (mismo cálculo que usa Finanzas) */
    var fc = C.sumaDias(hoy, -15), k0 = C.calcCorte(S, tAct.id, '', fc);
    S.cortes.push({ id: id('c'), temporadaId: tAct.id, desde: tAct.inicio, hasta: fc, ingresos: k0.ingresos, gastos: k0.gastos, saldo: k0.saldo, acumulado: k0.saldo,
                    porCobrar: k0.porCobrar, nota: 'Primer corte de la temporada.', creado: fc + ' 18:00' });
    S.bitacora.push({ id: id('b'), ts: C.sello(), accion: 'Se crearon los datos de demostración', por: 'Sistema' });
    return S;
  };
})();
