/* Panel de coordinación — CONFIGURACIÓN GENERAL: datos de la liga (nombre, logotipo, color, contacto),
   sedes y canchas, contraseña, espacios de datos (liga / demostración) y bitácora. */
(function () {
  'use strict';
  C.modulo('configuracion', {
    titulo: 'Configuración', menu: false, sinFiltros: true,
    pintar: function (v) { liga(v); sedes(v); datos(v); acceso(v); acerca(v); }
  });

  function liga(v) {
    var L = C.S.liga, k = L.contacto || {};
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Datos de la liga</h2><button type="button" class="btn pri" data-ed>Editar</button></div>'
      + '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px"><span class="escudo" style="width:64px;height:64px;font-size:26px;border-radius:16px">' + C.escudo(L) + '</span>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap"><label class="btn sm">Subir logotipo<input type="file" accept="image/*" hidden data-logo></label>' + (L.logo ? '<button type="button" class="btn sm peligro" data-quitar>Quitar logotipo</button>' : '') + '</div></div>'
      + '<dl class="ficha"><div><dt>Nombre</dt><dd>' + C.esc(L.nombre || 'Sin nombre') + '</dd></div><div><dt>Responsable</dt><dd>' + C.esc(k.responsable || '—') + '</dd></div><div><dt>Teléfono</dt><dd>' + C.esc(k.telefono || '—') + '</dd></div>'
      + '<div><dt>Correo</dt><dd>' + C.esc(k.correo || '—') + '</dd></div><div><dt>Dirección</dt><dd>' + C.esc(k.direccion || '—') + '</dd></div><div><dt>Duración de un partido</dt><dd>' + (L.duracion || 60) + ' min (para detectar cruces)</dd></div>'
      + '<div><dt>Moneda y horario</dt><dd>Pesos mexicanos (MXN) · hora de la Ciudad de México</dd></div></dl></section>');
    n.querySelector('[data-ed]').onclick = function () {
      C.formulario({
        titulo: 'Datos de la liga', ancho: true, valores: { nombre: L.nombre, color: L.color || '#123B63', duracion: L.duracion || 60, responsable: k.responsable, telefono: k.telefono, correo: k.correo, direccion: k.direccion },
        campos: [{ k: 'nombre', t: 'Nombre de la liga', req: true, ayuda: 'Aparece en el panel, en la vista de los capitanes y en los impresos.' },
          { k: 'color', t: 'Color principal', tipo: 'color', req: true, mitad: true }, { k: 'duracion', t: 'Duración de un partido (minutos)', tipo: 'number', req: true, min: 10, mitad: true },
          { k: 'responsable', t: 'Responsable o área de contacto', mitad: true }, { k: 'telefono', t: 'Teléfono', tipo: 'tel', mitad: true, max: 20 },
          { k: 'correo', t: 'Correo', tipo: 'email', mitad: true }, { k: 'direccion', t: 'Dirección', mitad: true, max: 160 }],
        alGuardar: function (o) {
          L.nombre = o.nombre; L.color = o.color; L.duracion = o.duracion; L.contacto = { responsable: o.responsable, telefono: o.telefono, correo: o.correo, direccion: o.direccion };
          C.guardar('Se actualizaron los datos de la liga'); setTimeout(C.pintar, 0);
        }
      });
    };
    n.querySelector('[data-logo]').onchange = function () {
      var f = this.files[0]; if (!f) return;
      if (!/^image\//.test(f.type)) return C.toast('Elige un archivo de imagen.');
      var img = new Image(), url = URL.createObjectURL(f);
      img.onload = function () {       /* se reduce a 256 px para que guardar siga siendo rápido */
        var m = 256, e = Math.min(1, m / Math.max(img.width, img.height)), cv = document.createElement('canvas');
        cv.width = Math.round(img.width * e); cv.height = Math.round(img.height * e);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(url);
        L.logo = cv.toDataURL('image/png'); if (L.logo.length > 200000) L.logo = cv.toDataURL('image/jpeg', 0.85);
        C.guardar('Se cambió el logotipo'); C.pintar();
      };
      img.onerror = function () { URL.revokeObjectURL(url); C.toast('No se pudo leer esa imagen.'); };
      img.src = url;
    };
    var q = n.querySelector('[data-quitar]'); if (q) q.onclick = function () { L.logo = ''; C.guardar('Se quitó el logotipo'); C.pintar(); };
    v.appendChild(n);
  }

  function sedes(v) {
    var S = C.S, L = S.liga;
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Sedes y canchas</h2><button type="button" class="btn pri" data-nueva>' + C.ICO.mas + 'Nueva sede</button></div>'
      + (L.sedes.length ? '<ul class="lista">' + L.sedes.map(function (s) {
        return '<li class="item click" data-s="' + s.id + '"><span class="p"><b>' + C.esc(s.nombre) + '</b><small>' + C.esc(s.direccion || 'Sin dirección') + '</small></span><span class="d">' + ((s.canchas || []).map(function (c) { return '<span class="pill">' + C.esc(c.nombre) + '</span>'; }).join('') || '<small class="suave">Sin canchas</small>') + '</span></li>'; }).join('') + '</ul>'
        : C.vacio('Agrega las sedes donde se juega y sus canchas. Sirven para programar partidos y detectar cruces de horario.')) + '</section>');
    function ed(s) {
      C.formulario({
        titulo: s ? 'Editar sede' : 'Nueva sede', valores: s ? { nombre: s.nombre, direccion: s.direccion, canchas: (s.canchas || []).map(function (c) { return c.nombre; }).join('\n') } : { canchas: 'Cancha 1' },
        campos: [{ k: 'nombre', t: 'Nombre de la sede', req: true }, { k: 'direccion', t: 'Dirección', max: 160 }, { k: 'canchas', t: 'Canchas', tipo: 'textarea', req: true, ayuda: 'Una por renglón.' }],
        borrar: s ? function () {
          if (S.partidos.some(function (p) { return p.sedeId === s.id; })) return C.aviso('No se puede eliminar', 'Hay partidos asignados a esta sede.');
          L.sedes = L.sedes.filter(function (x) { return x !== s; }); C.cerrarModal(); C.guardar('Sede eliminada: ' + s.nombre); C.pintar();
        } : null,
        alGuardar: function (o) {
          var nombres = o.canchas.split(/\n/).map(function (x) { return x.trim(); }).filter(Boolean).filter(function (x, i, a) { return a.indexOf(x) === i; });
          if (!nombres.length) return 'Agrega al menos una cancha.';
          if (L.sedes.some(function (x) { return x !== s && C.norm(x.nombre) === C.norm(o.nombre); })) return 'Ya existe una sede con ese nombre.';
          var viejas = s ? (s.canchas || []) : [];
          var canchas = nombres.map(function (nm, i) { var v0 = viejas.filter(function (c) { return C.norm(c.nombre) === C.norm(nm); })[0] || (viejas.length === nombres.length ? viejas[i] : null); return { id: v0 ? v0.id : C.uid('c'), nombre: nm }; });
          var perdidas = viejas.filter(function (c) { return !canchas.some(function (x) { return x.id === c.id; }) && S.partidos.some(function (p) { return p.canchaId === c.id; }); });
          if (perdidas.length) return 'No puedes quitar «' + perdidas[0].nombre + '»: tiene partidos asignados.';
          if (s) { s.nombre = o.nombre; s.direccion = o.direccion; s.canchas = canchas; } else L.sedes.push({ id: C.uid('s'), nombre: o.nombre, direccion: o.direccion, canchas: canchas });
          C.guardar('Sede guardada: ' + o.nombre); setTimeout(C.pintar, 0);
        }
      });
    }
    n.querySelector('[data-nueva]').onclick = function () { ed(null); };
    n.addEventListener('click', function (e) { var li = e.target.closest('[data-s]'); if (li) ed(C.porId(L.sedes, li.getAttribute('data-s'))); });
    v.appendChild(n);
  }

  function datos(v) {
    var S = C.S, demo = C.espacio === 'demo';
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Datos</h2></div>'
      + '<p style="margin:0 0 12px">Estás en: <b>' + (demo ? 'Demostración' : 'Mi liga') + '</b>. ' + (demo ? 'Los datos de ejemplo se guardan aparte y nunca se mezclan con los de tu liga.' : 'Esta es la información real de tu liga.') + '</p>'
      + '<div class="barra-h"><button type="button" class="btn" data-a="cambiar">' + (demo ? 'Ir a mi liga' : 'Ver la demostración') + '</button>'
      + (demo ? '<button type="button" class="btn peligro" data-a="reiniciar">Restablecer demostración</button>' : '<button type="button" class="btn" data-a="importar">Copiar equipos desde Tesorería</button>')
      + '<button type="button" class="btn" data-a="respaldo">Descargar respaldo</button></div>'
      + '<p class="suave" style="font-size:12.5px;margin:0 0 14px">Cada cambio se guarda en el servidor al momento. Además se conserva una copia por día (30 días) en la carpeta de datos.</p>'
      + '<h3 style="font-size:17px;margin-bottom:8px">Bitácora reciente</h3>'
      + (S.bitacora.length ? '<ul class="hist">' + S.bitacora.slice(-25).reverse().map(function (b) { return '<li><time>' + C.selloTxt(b.ts) + ' · ' + C.esc(b.por || '') + '</time>' + C.esc(b.accion) + (b.detalle ? '<br><small class="suave">' + C.esc(b.detalle) + '</small>' : '') + '</li>'; }).join('') + '</ul>' : '<p class="suave" style="margin:0">Sin cambios registrados.</p>')
      + '</section>');
    n.addEventListener('click', function (e) {
      var b = e.target.closest('[data-a]'); if (!b) return;
      var a = b.getAttribute('data-a');
      if (a === 'cambiar') C.cambiarEspacio(demo ? 'real' : 'demo');
      else if (a === 'reiniciar') C.confirmar('Restablecer demostración', 'Se borran los cambios hechos en la demostración y vuelven los datos de ejemplo. Tu liga real no se toca.', function () {
        C.S = C.demo.crear(); C.guardarSinBitacora().then(function () { C.leerFiltros(); C.ir('inicio'); C.pintar(); C.toast('Demostración restablecida.'); }); }, { peligro: true, boton: 'Restablecer' });
      else if (a === 'respaldo') {
        var blob = new Blob([JSON.stringify({ espacio: C.espacio, exportado: C.sello(), estado: S }, null, 1)], { type: 'application/json' }), url = URL.createObjectURL(blob), x = document.createElement('a');
        x.href = url; x.download = 'respaldo-coordinacion-' + C.espacio + '-' + C.hoy() + '.json'; document.body.appendChild(x); x.click(); x.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
      }
      else if (a === 'importar') importar();
    });
    v.appendChild(n);
  }

  /** Copia (no mueve ni modifica) equipos del registro de Tesorería de este sitio, si existe. */
  function importar() {
    C.api('tesoreria').then(function (r) {
      if (!r.ok || !r.disponible) return C.aviso('No disponible', 'Este sitio no tiene un registro de Tesorería del cual copiar equipos.');
      var eds = []; r.equipos.forEach(function (e) { if (e.edicion && eds.indexOf(e.edicion) < 0) eds.push(e.edicion); });
      C.formulario({
        titulo: 'Copiar equipos desde Tesorería', sub: r.equipos.length + ' equipos encontrados' + (r.liga ? ' · ' + r.liga : ''),
        valores: { edicion: eds.slice().sort(function (a, b) { return r.equipos.filter(function (e) { return e.edicion === b; }).length - r.equipos.filter(function (e) { return e.edicion === a; }).length; })[0] || '' },
        campos: [{ tipo: 'nota', t: 'Se <b>copian</b> los equipos de una edición con su encargado y jugadores, y se crea una temporada con sus categorías y torneos. Tesorería no se modifica y <b>no se copian cobros ni pagos</b>. Los equipos que ya existan aquí (mismo nombre) no se duplican.' },
          { k: 'edicion', t: 'Edición a copiar', tipo: 'select', req: true, op: eds.map(function (x) { return [x, x + ' · ' + r.equipos.filter(function (e) { return e.edicion === x; }).length + ' equipos']; }) }],
        boton: 'Copiar',
        alGuardar: function (o) {
          var S = C.S, hoy = C.hoy(), nE = 0, nT = 0;
          var temp = S.temporadas.filter(function (t) { return C.norm(t.nombre) === C.norm(o.edicion); })[0];
          if (!temp) { temp = { id: C.uid('s'), nombre: o.edicion, inicio: hoy, fin: C.sumaDias(hoy, 120), estado: S.temporadas.some(function (t) { return t.estado === 'activa'; }) ? 'planeada' : 'activa' }; S.temporadas.push(temp); }
          if (temp.estado === 'cerrada') return 'La temporada «' + temp.nombre + '» está cerrada.';
          r.equipos.filter(function (e) { return e.edicion === o.edicion; }).forEach(function (x) {
            var nomCat = [x.disciplina, x.rama, x.categoria].filter(Boolean).join(' · ') || 'General';
            var cat = S.categorias.filter(function (c) { return C.norm(c.nombre) === C.norm(nomCat); })[0];
            if (!cat) { cat = { id: C.uid('k'), nombre: nomCat, disciplina: x.disciplina, rama: x.rama }; S.categorias.push(cat); }
            var tor = S.torneos.filter(function (t) { return t.temporadaId === temp.id && t.categoriaId === cat.id; })[0];
            if (!tor) { tor = { id: C.uid('t'), nombre: nomCat, temporadaId: temp.id, categoriaId: cat.id, formato: 'liga', inicio: temp.inicio, fin: temp.fin, estado: 'inscripciones', equipos: [], reglas: { pg: 3, pe: 1, pp: 0, desempate: ['dif', 'gf', 'h2h'] } }; S.torneos.push(tor); nT++; }
            var eq = S.equipos.filter(function (e) { return C.norm(e.nombre) === C.norm(x.nombre); })[0];
            if (!eq) {
              eq = { id: C.uid('e'), nombre: x.nombre, capitan: { nombre: x.encargado, telefono: x.tel, correo: '' }, codigo: C.nuevoCodigo(), inscripcion: { estado: 'pendiente', fecha: hoy },
                     plantilla: x.jugadores.map(function (j, i) { return { id: C.uid('j') + i, nombre: j, numero: null }; }), notas: 'Copiado de Tesorería', creado: hoy };
              S.equipos.push(eq); nE++;
            }
            if (tor.equipos.indexOf(eq.id) < 0) tor.equipos.push(eq.id);
          });
          C.guardar('Se copiaron ' + nE + ' equipos desde Tesorería (' + o.edicion + ')');
          C.toast(nE + ' equipos y ' + nT + ' torneos creados.'); setTimeout(function () { C.f.temporada = temp.id; C.ir('equipos'); C.pintar(); }, 0);
        }
      });
    });
  }

  function acceso(v) {
    var n = C.el('<section class="tarjeta"><div class="cab"><h2>Acceso</h2><button type="button" class="btn" data-clave>Cambiar contraseña</button></div>'
      + '<dl class="ficha"><div><dt>Coordinador</dt><dd>Entra con contraseña o con una cuenta de Google ligada. Puede ver y modificar todo.</dd></div>'
      + '<div><dt>Capitán</dt><dd>Entra con el código de su equipo (en la ficha del equipo). Sólo consulta lo de su equipo: el servidor no le entrega nada más ni acepta cambios.</dd></div></dl>'
      + '<div data-google style="margin-top:16px;padding-top:14px;border-top:1px solid var(--linea)"></div></section>');
    n.querySelector('[data-clave]').onclick = function () { C.cambiarClave(false); };
    v.appendChild(n);
    /* cuentas de Google que pueden entrar como coordinador */
    var g = n.querySelector('[data-google]');
    function cuentas() {
      C.api('google_cuentas').then(function (r) {
        if (!r.ok) { g.innerHTML = ''; return; }
        if (!r.activo) { g.innerHTML = '<h3 style="font-size:17px;margin-bottom:6px">Acceso con Google</h3><p class="suave" style="margin:0;font-size:13.5px">No está activado. Se activa poniendo el ID de cliente de Google en <code>config.php</code>.</p>'; return; }
        g.innerHTML = '<h3 style="font-size:17px;margin-bottom:6px">Cuentas de Google con acceso de coordinador</h3>'
          + '<p class="suave" style="margin:0 0 10px;font-size:13px">Para agregar una: en la pantalla de entrada pulsa «Continuar con Google» y escribe la contraseña del coordinador una sola vez. Quitar una cuenta le retira el acceso con Google; la contraseña no cambia.</p>'
          + (r.cuentas.length ? '<ul class="lista">' + r.cuentas.map(function (c) {
            return '<li class="item"><span class="p"><b>' + C.esc(c.nombre || c.correo) + '</b><small>' + C.esc(c.correo) + (c.ultimo ? ' · última entrada ' + C.fecha(c.ultimo.slice(0, 10)) : '') + '</small></span>'
              + '<span class="d"><button type="button" class="btn sm peligro" data-quitar="' + C.esc(c.sub) + '" data-n="' + C.esc(c.correo || c.nombre) + '">Quitar</button></span></li>'; }).join('') + '</ul>'
            : C.vacio('Todavía no hay cuentas de Google ligadas.'));
      });
    }
    g.addEventListener('click', function (e) {
      var b = e.target.closest('[data-quitar]'); if (!b) return;
      C.confirmar('Quitar cuenta de Google', b.getAttribute('data-n') + ' ya no podrá entrar con Google. Podrá volver a ligarse con la contraseña del coordinador.', function () {
        C.api('google_quitar', { sub: b.getAttribute('data-quitar') }).then(function (r) { if (!r.ok) C.toast(r.error || 'No se pudo quitar.'); cuentas(); });
      }, { peligro: true, boton: 'Quitar' });
    });
    cuentas();
  }

  function acerca(v) {
    v.appendChild(C.el('<section class="tarjeta"><div class="cab"><h2>Alcance y limitaciones</h2></div>'
      + '<ul style="margin:0;padding-left:20px;display:grid;gap:5px;font-size:14px">'
      + '<li><b>Una sola liga</b> con varias temporadas y categorías. No hay varias ligas en la misma instalación.</li>'
      + '<li><b>Un solo perfil de coordinador</b> (una contraseña). Varias personas pueden entrar con su cuenta de Google ligada y la bitácora guarda su nombre, pero todas tienen los mismos permisos.</li>'
      + '<li><b>Sin pagos en línea, suscripciones ni facturación.</b> Los pagos se registran a mano; no se emiten comprobantes fiscales.</li>'
      + '<li><b>Sin avisos externos</b> (correo, SMS, WhatsApp). Los capitanes consultan entrando con su código.</li>'
      + '<li><b>Guardado completo por cambio:</b> pensado para una coordinación que captura desde un dispositivo a la vez. Si dos personas guardan al mismo tiempo, la segunda recibe un aviso y debe repetir su cambio.</li>'
      + '<li><b>Eliminación directa y grupos:</b> se programan y capturan los partidos, pero las llaves y el pase de ronda se arman a mano; no hay marcador de penales aparte.</li>'
      + '<li><b>Módulo independiente:</b> no lee ni escribe los otros sistemas del sitio. La única conexión es copiar equipos desde Tesorería, de sólo lectura.</li>'
      + '</ul></section>'));
  }
})();
