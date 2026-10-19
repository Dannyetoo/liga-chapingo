/* Liga Chapingo — Calendario mensual (30-sep-2026).
 * Componente ligero y sin dependencias. Lee sus datos de un bloque
 *   <script type="application/json" class="calm-datos">{…}</script>
 * dentro de cada <div class="calm" data-calendario>. (Un bloque JSON no se ejecuta,
 * así que respeta la CSP sin scripts en línea.)
 *
 * Datos: { hoy: "AAAA-MM-DD",
 *          tipos: { clave: { nombre: "Confirmado" } , … }   ← el orden es el de la leyenda
 *          eventos: [ { f: "AAAA-MM-DD", h: "HH:MM" | "", t: "Título", s: "Detalle", tipo: "clave" }, … ] }
 */
(function () {
  'use strict';
  var MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  var DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
  var DIAS_LARGOS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

  function z2(n) { return (n < 10 ? '0' : '') + n; }
  function clave(y, m, d) { return y + '-' + z2(m + 1) + '-' + z2(d); }
  function hora12(h) {
    if (!h) return '';
    var p = h.split(':'), H = +p[0];
    return ((H % 12) || 12) + ':' + p[1] + ' ' + (H < 12 ? 'a.m.' : 'p.m.');
  }
  function hora12corta(h) {
    if (!h) return '';
    var p = h.split(':'), H = +p[0];
    return ((H % 12) || 12) + (p[1] !== '00' ? ':' + p[1] : '') + (H < 12 ? 'a' : 'p');
  }
  function el(tag, cls, txt) {
    var x = document.createElement(tag);
    if (cls) x.className = cls;
    if (txt != null) x.textContent = txt;
    return x;
  }

  function iniciar(raiz) {
    var bloque = raiz.querySelector('script.calm-datos');
    var D;
    try { D = JSON.parse(bloque ? bloque.textContent : '{}'); } catch (e) { return; }
    var eventos = Array.isArray(D.eventos) ? D.eventos : [];
    var tipos = D.tipos || {};
    var hoy = D.hoy || clave(new Date().getFullYear(), new Date().getMonth(), new Date().getDate());
    var ocultos = {};

    var porDia = {};
    eventos.forEach(function (ev) {
      if (!ev || !/^\d{4}-\d{2}-\d{2}$/.test(ev.f || '')) return;
      (porDia[ev.f] = porDia[ev.f] || []).push(ev);
    });
    Object.keys(porDia).forEach(function (k) {
      porDia[k].sort(function (a, b) { return (a.h || '99').localeCompare(b.h || '99'); });
    });
    var visibles = function (f) { return (porDia[f] || []).filter(function (ev) { return !ocultos[ev.tipo]; }); };

    var y = +hoy.slice(0, 4), m = +hoy.slice(5, 7) - 1;
    var ini = raiz.getAttribute('data-mes');
    if (ini && /^\d{4}-\d{2}$/.test(ini)) { y = +ini.slice(0, 4); m = +ini.slice(5, 7) - 1; }
    var sel = null;

    /* ---- estructura ---- */
    raiz.textContent = '';
    var cab = el('div', 'calm__cab');
    var antes = el('button', 'calm__nav', '‹'); antes.type = 'button'; antes.setAttribute('aria-label', 'Mes anterior');
    var titulo = el('h3', 'calm__mes'); titulo.setAttribute('aria-live', 'polite');
    var despues = el('button', 'calm__nav', '›'); despues.type = 'button'; despues.setAttribute('aria-label', 'Mes siguiente');
    var bHoy = el('button', 'calm__hoy', 'Hoy'); bHoy.type = 'button';
    cab.appendChild(antes); cab.appendChild(titulo); cab.appendChild(despues); cab.appendChild(bHoy);
    raiz.appendChild(cab);

    var ley = el('div', 'calm__leyenda');
    Object.keys(tipos).forEach(function (k) {
      if (!eventos.some(function (ev) { return ev.tipo === k; })) return;
      var b = el('button', 'calm__tipo calm-t--' + k);
      b.type = 'button'; b.setAttribute('aria-pressed', 'true');
      b.title = 'Mostrar u ocultar';
      b.appendChild(el('i', 'calm__punto')); b.appendChild(document.createTextNode(tipos[k].nombre || k));
      b.onclick = function () {
        ocultos[k] = !ocultos[k];
        b.setAttribute('aria-pressed', ocultos[k] ? 'false' : 'true');
        pintar();
      };
      ley.appendChild(b);
    });
    if (ley.children.length > 1) raiz.appendChild(ley);

    var rej = el('div', 'calm__rejilla'); rej.setAttribute('role', 'grid');
    raiz.appendChild(rej);
    var det = el('div', 'calm__detalle'); det.setAttribute('aria-live', 'polite');
    raiz.appendChild(det);

    function mover(n) {
      m += n;
      if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
      sel = null;
      pintar(n);
    }
    antes.onclick = function () { mover(-1); };
    despues.onclick = function () { mover(1); };
    bHoy.onclick = function () { y = +hoy.slice(0, 4); m = +hoy.slice(5, 7) - 1; sel = hoy; pintar(0); };

    /* deslizar con el dedo para cambiar de mes */
    var x0 = null, y0 = null;
    rej.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
    rej.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
      x0 = null;
      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) mover(dx < 0 ? 1 : -1);
    }, { passive: true });

    function pintarDetalle() {
      det.textContent = '';
      if (!sel) return;
      var p = sel.split('-'), fecha = new Date(+p[0], +p[1] - 1, +p[2]);
      var t = DIAS_LARGOS[(fecha.getDay() + 6) % 7] + ' ' + (+p[2]) + ' de ' + MESES[+p[1] - 1].toLowerCase();
      det.appendChild(el('h4', 'calm__detalle-t', t.charAt(0).toUpperCase() + t.slice(1)));
      var lista = visibles(sel);
      if (!lista.length) { det.appendChild(el('p', 'calm__vacio', 'Sin horarios este día.')); return; }
      var ul = el('ul', 'calm__lista');
      lista.forEach(function (ev) {
        var li = el('li', 'calm__ev calm-t--' + ev.tipo);
        li.appendChild(el('span', 'calm__ev-h', ev.h ? hora12(ev.h) : 'Todo el día'));
        var cuerpo = el('span', 'calm__ev-c');
        cuerpo.appendChild(el('b', null, ev.t || ''));
        var nt = tipos[ev.tipo] ? tipos[ev.tipo].nombre : '';
        var ya = ((ev.t || '') + ' ' + (ev.s || '')).toLowerCase();
        var sub = [nt && ya.indexOf(nt.toLowerCase()) === -1 ? nt : '', ev.s || ''].filter(Boolean).join(' · ');
        if (sub) cuerpo.appendChild(el('small', null, sub));
        li.appendChild(cuerpo);
        ul.appendChild(li);
      });
      det.appendChild(ul);
    }

    function pintar(dir) {
      titulo.textContent = MESES[m] + ' ' + y;
      rej.textContent = '';
      DIAS.forEach(function (d) { rej.appendChild(el('div', 'calm__dow', d)); });
      var primero = new Date(y, m, 1), huecos = (primero.getDay() + 6) % 7;
      var nDias = new Date(y, m + 1, 0).getDate();
      for (var i = 0; i < huecos; i++) rej.appendChild(el('div', 'calm__celda calm__celda--vacia'));
      var total = 0, primeroConEv = null;
      for (var d = 1; d <= nDias; d++) {
        var f = clave(y, m, d), lista = visibles(f);
        total += lista.length;
        if (lista.length && !primeroConEv) primeroConEv = f;
        var c = el('button', 'calm__celda');
        c.type = 'button'; c.setAttribute('data-f', f);
        if (f === hoy) c.classList.add('calm__celda--hoy');
        if (f < hoy) c.classList.add('calm__celda--pasado');
        if ((huecos + d - 1) % 7 >= 5) c.classList.add('calm__celda--finde');
        if (lista.length) c.classList.add('calm__celda--con');
        c.setAttribute('aria-label', d + ' de ' + MESES[m].toLowerCase() + ': ' + (lista.length ? lista.length + ' horario' + (lista.length === 1 ? '' : 's') : 'sin horarios'));
        c.appendChild(el('span', 'calm__num', String(d)));
        if (lista.length) {
          var chips = el('span', 'calm__chips');
          lista.slice(0, 3).forEach(function (ev) {
            var ch = el('span', 'calm__chip calm-t--' + ev.tipo);
            ch.appendChild(el('i', 'calm__punto'));
            ch.appendChild(el('span', 'calm__chip-t', (ev.h ? hora12corta(ev.h) + ' ' : '') + (ev.t || '')));
            chips.appendChild(ch);
          });
          if (lista.length > 3) chips.appendChild(el('span', 'calm__mas', '+' + (lista.length - 3)));
          c.appendChild(chips);
        }
        c.onclick = function () {
          sel = this.getAttribute('data-f');
          rej.querySelectorAll('[aria-pressed]').forEach(function (b) { b.removeAttribute('aria-pressed'); });
          this.setAttribute('aria-pressed', 'true');
          pintarDetalle();
        };
        rej.appendChild(c);
      }
      if (!sel || sel.slice(0, 7) !== clave(y, m, 1).slice(0, 7)) {
        sel = (hoy.slice(0, 7) === clave(y, m, 1).slice(0, 7) && (visibles(hoy).length || !primeroConEv)) ? hoy : primeroConEv;
      }
      var b = sel && rej.querySelector('[data-f="' + sel + '"]');
      if (b) b.setAttribute('aria-pressed', 'true');
      raiz.classList.toggle('calm--vacio', total === 0);
      if (total === 0) {
        det.textContent = '';
        det.appendChild(el('p', 'calm__vacio', 'Sin horarios en ' + MESES[m].toLowerCase() + '.'));
      } else pintarDetalle();
      if (dir) {
        rej.classList.remove('calm__rejilla--izq', 'calm__rejilla--der');
        void rej.offsetWidth;
        rej.classList.add(dir > 0 ? 'calm__rejilla--der' : 'calm__rejilla--izq');
      }
    }
    pintar(0);
  }

  function arrancar() { document.querySelectorAll('[data-calendario]').forEach(iniciar); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();

  /* Conmutador «Calendario / Lista» (admin): botones con data-vista-btn dentro de [data-vistas]. */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-vista-btn]');
    if (!b) return;
    var caja = b.closest('[data-vistas]');
    if (!caja) return;
    var v = b.getAttribute('data-vista-btn');
    caja.querySelectorAll('[data-vista-btn]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    caja.querySelectorAll('[data-vista]').forEach(function (p) { p.hidden = p.getAttribute('data-vista') !== v; });
    try { localStorage.setItem('calm-vista', v); } catch (e) {}
  });
  document.querySelectorAll('[data-vistas]').forEach(function (caja) {
    var v = null;
    try { v = localStorage.getItem('calm-vista'); } catch (e) {}
    var b = v && caja.querySelector('[data-vista-btn="' + v + '"]');
    if (b) b.click();
  });
})();
