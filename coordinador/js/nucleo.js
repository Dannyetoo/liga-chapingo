/* Panel de coordinación — NÚCLEO: estado, guardado, navegación, filtros y piezas de interfaz.
   Todo cuelga de un solo objeto global «C». Cada módulo se registra con C.modulo(). */
var C = (function () {
  'use strict';
  var C = { S: null, rev: 0, espacio: 'real', rol: null, csrf: '', usuario: '', modulos: {}, orden: [] };

  /* ================= utilidades ================= */
  C.$ = function (id) { return document.getElementById(id); };
  C.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  C.uid = function (p) { return (p || 'x') + Date.now().toString(36) + Math.random().toString(36).slice(2, 7); };
  C.el = function (html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; };
  C.porId = function (lista, id) { for (var i = 0; i < (lista || []).length; i++) if (lista[i].id === id) return lista[i]; return null; };
  C.nom = function (lista, id, def) { var x = C.porId(lista, id); return x ? x.nombre : (def == null ? '—' : def); };
  C.norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); };

  /* Pesos mexicanos, fechas DD/MM/AAAA y «hoy» en la zona horaria de la Ciudad de México */
  var fmtMxn = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  var fmtMxn0 = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 });
  C.mxn = function (n) { return fmtMxn.format(Number(n) || 0); };
  C.mxn0 = function (n) { return fmtMxn0.format(Math.round(Number(n) || 0)); };
  C.ZONA = 'America/Mexico_City';
  C.hoy = function () { return new Intl.DateTimeFormat('en-CA', { timeZone: C.ZONA, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); };
  C.ahora = function () { return new Intl.DateTimeFormat('en-GB', { timeZone: C.ZONA, hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date()); };
  C.fecha = function (s) { var p = String(s || '').slice(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : (s || '—'); };
  var DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  C.MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  C.fechaDia = function (s) {            /* «sáb 03/10/2026» */
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s || '')) return 'Sin fecha';
    var p = s.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2]);
    return DIAS[d.getDay()].slice(0, 3) + ' ' + C.fecha(s);
  };
  C.hora = function (h) {                /* «7:30 p.m.» */
    if (!/^\d{2}:\d{2}/.test(h || '')) return '';
    var H = +h.slice(0, 2), M = h.slice(3, 5);
    return ((H % 12) || 12) + ':' + M + ' ' + (H < 12 ? 'a.m.' : 'p.m.');
  };
  C.sumaDias = function (s, n) { var p = s.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2] + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
  C.sello = function () { return C.hoy() + ' ' + C.ahora(); };
  C.selloTxt = function (s) { return C.fecha(String(s).slice(0, 10)) + ' ' + C.hora(String(s).slice(11, 16)); };

  /* ================= íconos ================= */
  function ico(d) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; }
  C.ICO = {
    inicio: ico('<path d="M4 11 12 4l8 7v8.5a.5.5 0 0 1-.5.5H15v-6H9v6H4.5a.5.5 0 0 1-.5-.5V11Z"/>'),
    torneos: ico('<path d="M7 4h10v4a5 5 0 0 1-10 0V4Z"/><path d="M7 6H4v1.5A3 3 0 0 0 7 10.5M17 6h3v1.5a3 3 0 0 1-3 3M12 13v4M8.5 20h7"/>'),
    equipos: ico('<path d="M12 3 5 5.5v5.8c0 4.3 3 7.7 7 9.2 4-1.5 7-4.9 7-9.2V5.5L12 3Z"/><circle cx="12" cy="11" r="2.3"/>'),
    calendario: ico('<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>'),
    resultados: ico('<rect x="3.5" y="4" width="17" height="16" rx="2.5"/><path d="M12 4v16M7 10h1.5M7 14h1.5M15.5 10H17M15.5 14H17"/>'),
    finanzas: ico('<rect x="3.5" y="6" width="17" height="12.5" rx="2"/><path d="M3.5 10h17M7.5 14.5h3"/>'),
    configuracion: ico('<circle cx="12" cy="12" r="3"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M5.6 18.4l1.8-1.8M16.6 7.4l1.8-1.8"/>'),
    salir: ico('<path d="M14 4h4.5a.5.5 0 0 1 .5.5v15a.5.5 0 0 1-.5.5H14M10 8l-4 4 4 4M6 12h9"/>'),
    mas: ico('<path d="M12 5v14M5 12h14"/>'),
    pago: ico('<circle cx="12" cy="12" r="8.5"/><path d="M14.5 9.2c-.4-.8-1.3-1.2-2.5-1.2-1.500 0-2.5.8-2.5 2s1 1.700 2.500 2 2.500.9 2.500 2-1 2-2.500 2c-1.200 0-2.200-.5-2.600-1.300M12 6.500V8M12 16v1.500"/>'),
    ojo: ico('<path d="M2.500 12S6 5.500 12 5.500 21.500 12 21.500 12 18 18.500 12 18.500 2.500 12 2.500 12Z"/><circle cx="12" cy="12" r="2.800"/>')
  };

  /* ================= estado ================= */
  C.estadoVacio = function () {
    return {
      v: 1,
      liga: { nombre: '', logo: '', color: '#123B63', contacto: { responsable: '', telefono: '', correo: '', direccion: '' }, sedes: [], duracion: 60, folio: 1, folioGasto: 1 },
      temporadas: [], categorias: [], torneos: [], equipos: [], jornadas: [], partidos: [],
      conceptos: [], movs: [], gastos: [], cortes: [], bitacora: []
    };
  };
  function normaliza(S) {
    var V = C.estadoVacio();
    Object.keys(V).forEach(function (k) { if (S[k] == null) S[k] = V[k]; });
    Object.keys(V.liga).forEach(function (k) { if (S.liga[k] == null) S.liga[k] = V.liga[k]; });
    if (!S.liga.contacto) S.liga.contacto = V.liga.contacto;
    return S;
  }

  C.api = function (a, cuerpo, params) {
    var op = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (cuerpo) { op.method = 'POST'; op.headers['Content-Type'] = 'application/json'; op.headers['X-Coord-Csrf'] = C.csrf || ''; op.body = JSON.stringify(cuerpo); }
    var q = 'api.php?a=' + encodeURIComponent(a);
    Object.keys(params || {}).forEach(function (k) { q += '&' + k + '=' + encodeURIComponent(params[k]); });
    return fetch(q, op).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'El servidor respondió algo inesperado.' }; }); });
  };

  C.cargar = function (espacio) {
    return C.api('estado', null, { espacio: espacio }).then(function (r) {
      if (!r.ok) return r;
      C.espacio = espacio; C.rev = r.rev || 0;
      try { sessionStorage.setItem('coord-espacio', espacio); } catch (e) {}
      if (r.estado) { C.S = normaliza(r.estado); return r; }
      /* Espacio todavía sin datos: la demostración se siembra; la liga real empieza en blanco. */
      C.S = espacio === 'demo' ? C.demo.crear() : C.estadoVacio();
      return espacio === 'demo' ? guardarAhora().then(function () { return r; }) : r;
    });
  };

  /* ---- guardado: cada cambio se manda al servidor; si alguien más guardó antes, se avisa y se recarga ---- */
  var guardando = false, pendiente = false;
  function marca(t, mal) { var g = C.$('guardado'); if (g) { g.textContent = t; g.classList.toggle('mal', !!mal); } }
  function guardarAhora() {
    if (guardando) { pendiente = true; return Promise.resolve(); }
    guardando = true; marca('Guardando…');
    return C.api('guardar', { espacio: C.espacio, rev: C.rev, estado: C.S }).then(function (r) {
      guardando = false;
      if (r.ok) {
        C.rev = r.rev; marca('Guardado ' + C.hora(C.ahora()));
        if (pendiente) { pendiente = false; return guardarAhora(); }
        return;
      }
      pendiente = false;
      if (r.conflicto) {
        marca('Sin guardar', true);
        C.aviso('Hay una versión más reciente', 'Alguien guardó cambios desde otro dispositivo. Se va a cargar la versión actual; repite tu último cambio.', function () {
          C.cargar(C.espacio).then(C.pintar);
        });
      } else if (r.auth === false) { location.reload(); }
      else { marca('Sin guardar', true); C.toast(r.error || 'No se pudo guardar.'); }
    }).catch(function () { guardando = false; marca('Sin conexión: no se guardó', true); C.toast('Sin conexión: el cambio no se guardó.'); });
  }
  /** Anota el cambio en la bitácora y guarda. extra: {equipoId, partidoId, torneoId, detalle} */
  C.guardar = function (accion, extra) {
    var b = Object.assign({ id: C.uid('b'), ts: C.sello(), accion: accion, por: C.usuario || 'Coordinador' }, extra || {});
    C.S.bitacora.push(b);
    if (C.S.bitacora.length > 600) C.S.bitacora.splice(0, C.S.bitacora.length - 600);
    return guardarAhora();
  };
  C.guardarSinBitacora = guardarAhora;

  /* ================= filtros globales (temporada y categoría) =================
     Un solo filtro para todo el panel: cada módulo consulta estas funciones, así los
     números del Inicio y los de cada sección siempre coinciden. */
  C.f = { temporada: '', categoria: '' };
  C.torneos = function () {
    return C.S.torneos.filter(function (t) { return (!C.f.temporada || t.temporadaId === C.f.temporada) && (!C.f.categoria || t.categoriaId === C.f.categoria); });
  };
  C.idsTorneos = function () { var o = {}; C.torneos().forEach(function (t) { o[t.id] = 1; }); return o; };
  C.partidos = function () { var o = C.idsTorneos(); return C.S.partidos.filter(function (p) { return o[p.torneoId]; }); };
  C.hayFiltro = function () { return !!(C.f.temporada || C.f.categoria); };
  /** Equipos del filtro: los inscritos en algún torneo que coincide. Sin filtro, todos. */
  C.equipos = function () {
    if (!C.hayFiltro()) return C.S.equipos.slice();
    var o = {}; C.torneos().forEach(function (t) { (t.equipos || []).forEach(function (id) { o[id] = 1; }); });
    return C.S.equipos.filter(function (e) { return o[e.id]; });
  };
  C.idsEquipos = function () { var o = {}; C.equipos().forEach(function (e) { o[e.id] = 1; }); return o; };
  /** Movimientos del filtro: por temporada del movimiento y, si hay categoría, por equipo de esa categoría. */
  C.movs = function () {
    var o = C.f.categoria ? C.idsEquipos() : null;
    return C.S.movs.filter(function (m) { return (!C.f.temporada || m.temporadaId === C.f.temporada) && (!o || o[m.equipoId]); });
  };
  C.gastos = function () { return C.S.gastos.filter(function (g) { return !C.f.temporada || g.temporadaId === C.f.temporada; }); };
  C.temporadaActiva = function () {
    return C.porId(C.S.temporadas, C.f.temporada) || C.S.temporadas.filter(function (t) { return t.estado === 'activa'; })[0] || C.S.temporadas[C.S.temporadas.length - 1] || null;
  };
  C.temporadaCerrada = function (id) { var t = C.porId(C.S.temporadas, id); return !!(t && t.estado === 'cerrada'); };

  function pintarFiltros() {
    var st = C.$('f-temporada'), sc = C.$('f-categoria');
    if (C.f.temporada && !C.porId(C.S.temporadas, C.f.temporada)) C.f.temporada = '';
    if (C.f.categoria && !C.porId(C.S.categorias, C.f.categoria)) C.f.categoria = '';
    st.innerHTML = '<option value="">Todas las temporadas</option>' + C.S.temporadas.map(function (t) {
      return '<option value="' + t.id + '"' + (t.id === C.f.temporada ? ' selected' : '') + '>' + C.esc(t.nombre) + (t.estado === 'cerrada' ? ' (cerrada)' : '') + '</option>'; }).join('');
    sc.innerHTML = '<option value="">Todas las categorías</option>' + C.S.categorias.map(function (c) {
      return '<option value="' + c.id + '"' + (c.id === C.f.categoria ? ' selected' : '') + '>' + C.esc(c.nombre) + '</option>'; }).join('');
  }
  function guardarFiltros() { try { sessionStorage.setItem('coord-filtro-' + C.espacio, JSON.stringify(C.f)); } catch (e) {} }
  C.leerFiltros = function () {
    C.f = { temporada: '', categoria: '' };
    try { var j = JSON.parse(sessionStorage.getItem('coord-filtro-' + C.espacio) || 'null'); if (j) C.f = { temporada: j.temporada || '', categoria: j.categoria || '' }; } catch (e) {}
    if (!C.f.temporada) { var a = C.S.temporadas.filter(function (t) { return t.estado === 'activa'; })[0]; if (a) C.f.temporada = a.id; }
  };

  /* ================= navegación ================= */
  C.modulo = function (clave, def) { C.modulos[clave] = def; if (def.menu !== false) C.orden.push(clave); };
  C.ruta = function () { var p = (location.hash || '#/inicio').replace(/^#\/?/, '').split('/'); return { mod: p[0] || 'inicio', args: p.slice(1).map(decodeURIComponent) }; };
  C.ir = function () { location.hash = '#/' + Array.prototype.map.call(arguments, encodeURIComponent).join('/'); };

  C.pintarMarco = function () {
    var L = C.S.liga, nombre = L.nombre || 'Mi liga';
    document.documentElement.style.setProperty('--marca', /^#[0-9a-f]{6}$/i.test(L.color || '') ? L.color : '#123B63');
    document.querySelectorAll('[data-liga]').forEach(function (x) { x.textContent = nombre; });
    document.querySelectorAll('[data-escudo]').forEach(function (x) { x.innerHTML = C.escudo(L); });
    document.title = nombre + ' · Coordinación';
    var r = C.ruta();
    function enlace(k) { var m = C.modulos[k]; return '<a class="menu-item" href="#/' + k + '"' + (r.mod === k ? ' aria-current="page"' : '') + '>' + C.ICO[k] + '<span>' + m.titulo + '</span></a>'; }
    C.$('menu').innerHTML = C.orden.map(enlace).join('');
    C.$('inferior').innerHTML = C.orden.map(function (k) { return '<a href="#/' + k + '"' + (r.mod === k ? ' aria-current="page"' : '') + '>' + C.ICO[k] + '<span>' + C.modulos[k].titulo + '</span></a>'; }).join('');
    var cfg = document.querySelector('.lateral-pie [data-mod="configuracion"]');
    cfg.innerHTML = C.ICO.configuracion + '<span>Configuración</span>';
    if (r.mod === 'configuracion') cfg.setAttribute('aria-current', 'page'); else cfg.removeAttribute('aria-current');
    C.$('btn-salir').innerHTML = C.ICO.salir + '<span>Salir</span>';
    C.$('btn-config-movil').innerHTML = C.ICO.configuracion;
    C.$('banda-demo').hidden = C.espacio !== 'demo';
  };
  C.escudo = function (L) {
    if (L && L.logo) return '<img src="' + C.esc(L.logo) + '" alt="">';
    var n = (L && L.nombre) || 'Liga', ini = n.split(/\s+/).filter(Boolean).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
    return C.esc(ini || 'L');
  };

  C.pintar = function () {
    if (C.rol !== 'coordinador' || !C.S) return;
    var r = C.ruta(), m = C.modulos[r.mod];
    if (!m) { C.ir('inicio'); return; }
    C.pintarMarco(); pintarFiltros();
    C.$('titulo').textContent = m.titulo;
    C.$('filtros').hidden = m.sinFiltros === true;
    var v = C.$('vista'); v.innerHTML = '';
    try { m.pintar(v, r.args); }
    catch (e) { v.innerHTML = '<div class="error ver">No se pudo mostrar esta sección: ' + C.esc(e.message) + '</div>'; if (window.console) console.error(e); }
  };

  /* ================= piezas de interfaz ================= */
  var alCerrar = null;
  C.modal = function (titulo, cuerpo, op) {
    op = op || {};
    var m = C.$('modal'); m.className = 'modal' + (op.ancho ? ' ancho' : '');
    m.innerHTML = '<div class="modal-cab"><h3>' + C.esc(titulo) + (op.sub ? '<small>' + C.esc(op.sub) + '</small>' : '') + '</h3><button type="button" class="modal-x" aria-label="Cerrar">×</button></div>';
    m.appendChild(typeof cuerpo === 'string' ? C.el('<div>' + cuerpo + '</div>') : cuerpo);
    m.querySelector('.modal-x').onclick = C.cerrarModal;
    alCerrar = op.alCerrar || null;
    C.$('telon').hidden = false;
    var foco = m.querySelector('input:not([type=hidden]),select,textarea,button.pri'); if (foco && !op.sinFoco) foco.focus();
    return m;
  };
  C.cerrarModal = function () { C.$('telon').hidden = true; C.$('modal').innerHTML = ''; var f = alCerrar; alCerrar = null; if (f) f(); };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !C.$('telon').hidden) C.cerrarModal(); });
  document.addEventListener('mousedown', function (e) { if (e.target === C.$('telon')) C.cerrarModal(); });

  var tToast = null;
  C.toast = function (t) { var x = C.$('toast'); x.textContent = t; x.hidden = false; clearTimeout(tToast); tToast = setTimeout(function () { x.hidden = true; }, 3200); };

  C.aviso = function (titulo, texto, alAceptar) {
    var b = C.el('<div><p style="margin:0">' + C.esc(texto) + '</p><div class="modal-pie"><button type="button" class="btn pri">Entendido</button></div></div>');
    C.modal(titulo, b); b.querySelector('button').onclick = function () { C.cerrarModal(); if (alAceptar) alAceptar(); };
  };
  /** Confirmación propia (sin diálogos del navegador). op: {peligro, boton, motivo:true → pide motivo obligatorio} */
  C.confirmar = function (titulo, texto, alAceptar, op) {
    op = op || {};
    var b = C.el('<form><p style="margin:0 0 12px">' + C.esc(texto) + '</p>'
      + (op.motivo ? '<label class="campo"><span>Motivo (queda en el historial)</span><textarea required maxlength="300"></textarea></label>' : '')
      + '<div class="modal-pie"><button type="button" class="btn" data-no>Cancelar</button><button type="submit" class="btn ' + (op.peligro ? 'peligro' : 'pri') + '">' + C.esc(op.boton || 'Aceptar') + '</button></div></form>');
    C.modal(titulo, b);
    b.querySelector('[data-no]').onclick = C.cerrarModal;
    b.onsubmit = function (e) { e.preventDefault(); var mo = op.motivo ? b.querySelector('textarea').value.trim() : ''; if (op.motivo && !mo) return; C.cerrarModal(); alAceptar(mo); };
  };

  /** Formulario en ventana.
      campos: [{k, t:etiqueta, tipo:'text|number|money|date|time|tel|email|select|textarea|checks|color|nota', op:[[valor,texto]], req, ayuda, min, max, mitad}]
      alGuardar(valores, form) → texto de error | {avisos:[…]} | nada (éxito). */
  C.formulario = function (cfg) {
    var v = cfg.valores || {};
    var h = '<form novalidate><div class="error" role="alert"></div><div class="aviso" hidden></div>';
    var abierta = false;
    cfg.campos.forEach(function (c) {
      if (c.tipo === 'nota') { h += '<p class="suave" style="font-size:13px;margin:0 0 12px">' + c.t + '</p>'; return; }
      var val = v[c.k] == null ? (c.def == null ? '' : c.def) : v[c.k], id = 'fc-' + c.k, at = ' id="' + id + '" name="' + c.k + '"' + (c.req ? ' required' : '');
      if (c.mitad && !abierta) { h += '<div class="campo fila2" style="margin-bottom:0">'; abierta = true; }
      else if (!c.mitad && abierta) { h += '</div>'; abierta = false; }
      h += '<label class="campo"><span>' + C.esc(c.t) + (c.req ? '' : ' <small class="suave">(opcional)</small>') + '</span>';
      if (c.tipo === 'select') {
        h += '<select' + at + '>' + (c.vacio === false ? '' : '<option value="">' + C.esc(c.vacio || 'Elegir…') + '</option>')
          + c.op.map(function (o) { return '<option value="' + C.esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + C.esc(o[1]) + '</option>'; }).join('') + '</select>';
      } else if (c.tipo === 'textarea') {
        h += '<textarea' + at + ' maxlength="' + (c.max || 500) + '">' + C.esc(val) + '</textarea>';
      } else if (c.tipo === 'checks') {
        var set = {}; (val || []).forEach(function (x) { set[x] = 1; });
        h += '<div class="checks" data-checks="' + c.k + '">' + (c.op.length ? c.op.map(function (o) {
          return '<label><input type="checkbox" value="' + C.esc(o[0]) + '"' + (set[o[0]] ? ' checked' : '') + '> ' + C.esc(o[1]) + '</label>'; }).join('') : '<span class="suave">No hay opciones todavía.</span>') + '</div>';
      } else {
        var tipo = c.tipo === 'money' ? 'number' : (c.tipo || 'text');
        h += '<input type="' + tipo + '"' + at + ' value="' + C.esc(val) + '"'
          + (c.tipo === 'money' ? ' step="0.01" min="' + (c.min == null ? 0 : c.min) + '" inputmode="decimal"' : '')
          + (c.tipo === 'number' ? ' step="' + (c.paso || 1) + '"' + (c.min != null ? ' min="' + c.min + '"' : '') + (c.maxv != null ? ' max="' + c.maxv + '"' : '') + ' inputmode="numeric"' : '')
          + (tipo === 'text' || tipo === 'tel' || tipo === 'email' ? ' maxlength="' + (c.max || 120) + '"' : '') + (c.ph ? ' placeholder="' + C.esc(c.ph) + '"' : '') + '>';
      }
      if (c.ayuda) h += '<small class="suave">' + C.esc(c.ayuda) + '</small>';
      h += '</label>';
    });
    if (abierta) h += '</div>';
    h += '<div class="modal-pie">' + (cfg.borrar ? '<button type="button" class="btn peligro" data-borrar style="margin-right:auto">' + C.esc(cfg.borrarTxt || 'Eliminar') + '</button>' : '')
      + '<button type="button" class="btn" data-no>Cancelar</button><button type="submit" class="btn pri">' + C.esc(cfg.boton || 'Guardar') + '</button></div></form>';
    var f = C.el(h), err = f.querySelector('.error'), av = f.querySelector('.aviso');
    C.modal(cfg.titulo, f, { sub: cfg.sub, ancho: cfg.ancho });
    if (cfg.alAbrir) cfg.alAbrir(f);
    f.querySelector('[data-no]').onclick = C.cerrarModal;
    if (cfg.borrar) f.querySelector('[data-borrar]').onclick = cfg.borrar;
    f.onsubmit = function (e) {
      e.preventDefault(); err.classList.remove('ver');
      var out = {}, falta = null;
      cfg.campos.forEach(function (c) {
        if (c.tipo === 'nota') return;
        if (c.tipo === 'checks') { out[c.k] = Array.prototype.map.call(f.querySelectorAll('[data-checks="' + c.k + '"] input:checked'), function (x) { return x.value; }); return; }
        var x = f.elements[c.k], val = x.value.trim();
        if (c.req && val === '' && !falta) falta = c;
        out[c.k] = (c.tipo === 'money' || c.tipo === 'number') ? (val === '' ? null : Number(val)) : val;
        if ((c.tipo === 'money' || c.tipo === 'number') && val !== '' && (isNaN(out[c.k]) || (c.min != null && out[c.k] < c.min) || (c.tipo === 'money' && c.min == null && out[c.k] < 0)) && !falta) falta = { t: c.t, malo: true };
      });
      if (falta) { err.textContent = falta.malo ? '«' + falta.t + '» no es un valor válido.' : 'Falta llenar «' + falta.t + '».'; err.classList.add('ver'); return; }
      var r = cfg.alGuardar(out, f);
      if (typeof r === 'string') { err.textContent = r; err.classList.add('ver'); return; }
      if (r && r.avisos) {         /* cruces u otras advertencias: se muestran y se pide confirmar */
        av.hidden = false;
        av.innerHTML = '<b>' + C.esc(r.titulo || 'Revisa antes de guardar') + '</b><ul>' + r.avisos.map(function (a) { return '<li>' + C.esc(a) + '</li>'; }).join('') + '</ul>'
          + '<label style="display:flex;gap:8px;align-items:center;margin-top:8px;font-weight:600"><input type="checkbox" name="forzar"> Guardar de todos modos</label>';
        av.scrollIntoView({ block: 'nearest' });
        return;
      }
      C.cerrarModal();
    };
    return f;
  };

  C.pestanas = function (lista, actual, alElegir) {
    var n = C.el('<div class="pestanas linea" role="tablist">' + lista.map(function (p) {
      return '<button type="button" role="tab" aria-selected="' + (p[0] === actual) + '" data-p="' + p[0] + '">' + C.esc(p[1]) + '</button>'; }).join('') + '</div>');
    n.addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b) alElegir(b.getAttribute('data-p')); });
    return n;
  };
  C.vacio = function (t) { return '<div class="vacio">' + C.esc(t) + '</div>'; };
  C.imprimir = function (html) { C.$('impresion').innerHTML = html; window.print(); };

  /* ================= cálculos compartidos ================= */
  C.ESTADOS_PARTIDO = { programado: ['Programado', ''], finalizado: ['Finalizado', 'ok'], suspendido: ['Suspendido', 'av'], cancelado: ['Cancelado', 'mal'] };
  C.pillPartido = function (p) { var e = C.ESTADOS_PARTIDO[p.estado] || [p.estado, '']; return '<span class="pill ' + e[1] + '">' + e[0] + '</span>'; };
  C.DESEMPATES = { dif: 'Diferencia de goles', gf: 'Goles a favor', h2h: 'Enfrentamiento directo', gan: 'Partidos ganados', gc: 'Menos goles en contra' };

  /** Tabla de posiciones de un torneo a partir de sus partidos FINALIZADOS.
      datos: {partidos, equipos} (así sirve igual en el panel y en la vista del capitán). */
  C.tabla = function (torneo, partidos, equipos) {
    var R = Object.assign({ pg: 3, pe: 1, pp: 0, desempate: ['dif', 'gf', 'h2h'] }, torneo.reglas || {});
    var F = {}, jug = partidos.filter(function (p) { return p.torneoId === torneo.id && p.estado === 'finalizado' && p.gl != null && p.gv != null; });
    (torneo.equipos || []).forEach(function (id) { F[id] = { id: id, nombre: C.nom(equipos, id, 'Equipo eliminado'), pj: 0, g: 0, e: 0, p: 0, gf: 0, gc: 0, dif: 0, pts: 0 }; });
    function suma(T, a, b, ga, gb) {
      if (!T[a] || !T[b]) return;
      T[a].pj++; T[b].pj++; T[a].gf += ga; T[a].gc += gb; T[b].gf += gb; T[b].gc += ga;
      if (ga > gb) { T[a].g++; T[b].p++; T[a].pts += R.pg; T[b].pts += R.pp; }
      else if (ga < gb) { T[b].g++; T[a].p++; T[b].pts += R.pg; T[a].pts += R.pp; }
      else { T[a].e++; T[b].e++; T[a].pts += R.pe; T[b].pts += R.pe; }
    }
    jug.forEach(function (p) { suma(F, p.localId, p.visitaId, +p.gl, +p.gv); });
    var filas = Object.keys(F).map(function (k) { F[k].dif = F[k].gf - F[k].gc; return F[k]; });
    /* enfrentamiento directo: puntos sólo entre los equipos empatados en puntos */
    var h2h = {};
    var grupos = {}; filas.forEach(function (f) { (grupos[f.pts] = grupos[f.pts] || []).push(f.id); });
    Object.keys(grupos).forEach(function (pts) {
      var ids = grupos[pts]; if (ids.length < 2) return;
      var T = {}; ids.forEach(function (id) { T[id] = { pj: 0, g: 0, e: 0, p: 0, gf: 0, gc: 0, pts: 0 }; });
      jug.forEach(function (p) { if (T[p.localId] && T[p.visitaId]) suma(T, p.localId, p.visitaId, +p.gl, +p.gv); });
      ids.forEach(function (id) { h2h[id] = T[id].pts; });
    });
    var crit = { dif: function (a, b) { return b.dif - a.dif; }, gf: function (a, b) { return b.gf - a.gf; }, gc: function (a, b) { return a.gc - b.gc; },
                 gan: function (a, b) { return b.g - a.g; }, h2h: function (a, b) { return (h2h[b.id] || 0) - (h2h[a.id] || 0); } };
    filas.sort(function (a, b) {
      if (b.pts !== a.pts) return b.pts - a.pts;
      for (var i = 0; i < R.desempate.length; i++) { var c = crit[R.desempate[i]]; if (c) { var d = c(a, b); if (d) return d; } }
      return a.nombre.localeCompare(b.nombre, 'es');
    });
    return filas;
  };
  C.tablaHTML = function (filas, resaltar) {
    if (!filas.length) return C.vacio('Este torneo todavía no tiene equipos.');
    return '<div class="tabla-w"><table><thead><tr><th class="c">#</th><th>Equipo</th><th class="n">PJ</th><th class="n">G</th><th class="n">E</th><th class="n">P</th><th class="n">GF</th><th class="n">GC</th><th class="n">DIF</th><th class="n">PTS</th></tr></thead><tbody>'
      + filas.map(function (f, i) {
        return '<tr' + (f.id === resaltar ? ' class="yo"' : '') + '><td class="c">' + (i + 1) + '</td><td>' + C.esc(f.nombre) + '</td><td class="n">' + f.pj + '</td><td class="n">' + f.g + '</td><td class="n">' + f.e + '</td><td class="n">' + f.p
          + '</td><td class="n">' + f.gf + '</td><td class="n">' + f.gc + '</td><td class="n">' + (f.dif > 0 ? '+' : '') + f.dif + '</td><td class="n"><b>' + f.pts + '</b></td></tr>'; }).join('')
      + '</tbody></table></div>';
  };

  /** Estado de cuenta: cargos + ajustes − pagos. movs ya filtrados por equipo (y temporada, si aplica). */
  C.cuenta = function (movs) {
    var r = { cargos: 0, pagos: 0, ajustes: 0, saldo: 0 };
    movs.forEach(function (m) { var v = Number(m.monto) || 0; if (m.tipo === 'pago') r.pagos += v; else if (m.tipo === 'ajuste') r.ajustes += v; else r.cargos += v; });
    r.saldo = r.cargos + r.ajustes - r.pagos;
    return r;
  };
  C.TIPOS_MOV = { cargo: 'Cargo', pago: 'Pago', ajuste: 'Ajuste' };
  C.ordenMovs = function (a, b) { return String(a.fecha).localeCompare(String(b.fecha)) || (a.folio || 0) - (b.folio || 0); };
  /** Renglones de un estado de cuenta con saldo acumulado (se usa en el panel y en la vista del capitán). */
  C.cuentaHTML = function (movs) {
    if (!movs.length) return C.vacio('Sin movimientos.');
    var ac = 0;
    return '<div class="tabla-w"><table><thead><tr><th>Fecha</th><th>Concepto</th><th class="n">Cargo</th><th class="n">Pago</th><th class="n">Saldo</th></tr></thead><tbody>'
      + movs.slice().sort(C.ordenMovs).map(function (m) {
        var v = Number(m.monto) || 0, car = m.tipo === 'pago' ? 0 : v; ac += m.tipo === 'pago' ? -v : v;
        return '<tr><td class="num">' + C.fecha(m.fecha) + '</td><td>' + C.esc(m.concepto || C.TIPOS_MOV[m.tipo]) + (m.tipo === 'ajuste' ? ' <span class="pill av">Ajuste</span>' : '')
          + (m.forma ? ' <small class="suave">· ' + C.esc(m.forma) + '</small>' : '') + (m.nota ? '<br><small class="suave">' + C.esc(m.nota) + '</small>' : '') + '</td>'
          + '<td class="n">' + (m.tipo === 'pago' ? '' : C.mxn(car)) + '</td><td class="n">' + (m.tipo === 'pago' ? C.mxn(v) : '') + '</td>'
          + '<td class="n ' + (ac > 0.009 ? 'deuda' : ac < -0.009 ? 'favor' : '') + '">' + C.mxn(ac) + '</td></tr>'; }).join('')
      + '</tbody></table></div>';
  };

  /** «Sede · Cancha» de un partido, con las sedes que se le pasen (liga.sedes). */
  C.lugar = function (sedes, p) {
    var s = C.porId(sedes, p.sedeId); if (!s) return '';
    var c = C.porId(s.canchas || [], p.canchaId);
    return s.nombre + (c ? ' · ' + c.nombre : '');
  };
  C.ordenPartidos = function (a, b) { return String(a.fecha || '9999').localeCompare(String(b.fecha || '9999')) || String(a.hora || '99').localeCompare(String(b.hora || '99')); };

  /* eventos del marco */
  window.addEventListener('hashchange', function () { if (!C.$('telon').hidden) C.cerrarModal(); C.pintar(); window.scrollTo(0, 0); });
  document.addEventListener('DOMContentLoaded', function () {
    C.$('f-temporada').onchange = function () { C.f.temporada = this.value; guardarFiltros(); C.pintar(); };
    C.$('f-categoria').onchange = function () { C.f.categoria = this.value; guardarFiltros(); C.pintar(); };
  });
  return C;
})();
