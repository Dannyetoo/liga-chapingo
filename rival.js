/* «Busca un rival» — app del capitán.
   Actualización 2026-09-29:
   · Rejillas de horarios (.js-slots): mínimo total (data-min), máximo por día (data-max-dia,
     contando los horarios que ya tiene el equipo: fieldset[data-ya]) y máximo total (data-max-total).
   · Filtros de «Rivales» por día y «sólo los que coinciden conmigo».
   · Se conservan: data-confirmar, estado «cargando», anti doble envío, MATCH y recarga automática. */
(function () {
  'use strict';

  // ---- Rejillas de horarios ----
  document.querySelectorAll('form.js-slots').forEach(function (form) {
    var min = parseInt(form.getAttribute('data-min') || '1', 10);
    var maxDia = parseInt(form.getAttribute('data-max-dia') || '0', 10);
    var maxTot = parseInt(form.getAttribute('data-max-total') || '0', 10);
    var cont = form.querySelector('.rv-contador');
    var btn = form.querySelector('button[type="submit"]');
    var cajas = form.querySelectorAll('input[name="slots[]"]');
    cajas.forEach(function (c) { if (c.disabled) c.setAttribute('data-off', ''); });
    var pintar = function () {
      var n = form.querySelectorAll('input[name="slots[]"]:checked').length;
      var lleno = maxTot && n >= maxTot;
      form.querySelectorAll('fieldset').forEach(function (fs) {
        var ya = parseInt(fs.getAttribute('data-ya') || '0', 10);
        var marc = fs.querySelectorAll('input[name="slots[]"]:checked').length;
        var topeDia = maxDia && ya + marc >= maxDia;
        fs.classList.toggle('rv-dia--lleno', !!topeDia);
        fs.querySelectorAll('input[name="slots[]"]').forEach(function (c) {
          if (c.checked || c.hasAttribute('data-off')) return;
          c.disabled = !!(topeDia || lleno);
        });
      });
      if (cont) {
        var t = n + ' horario' + (n === 1 ? '' : 's') + ' elegido' + (n === 1 ? '' : 's');
        if (n < min) t += ' (mínimo ' + min + ')';
        if (maxTot) t += ' · máximo ' + maxTot;
        cont.textContent = t;
        cont.className = 'rv-contador' + (n >= min ? ' rv-contador--ok' : '');
      }
      if (btn) btn.disabled = n < min;
    };
    cajas.forEach(function (c) { c.addEventListener('change', pintar); });
    pintar();
  });

  // ---- Confirmaciones ----
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-confirmar]');
    if (b && !b.disabled && !window.confirm(b.getAttribute('data-confirmar'))) ev.preventDefault();
  });

  // ---- Estado «cargando» y anti doble envío (NO se deshabilita el botón: su name/value debe viajar) ----
  var ultimoBoton = null;
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[type="submit"], button:not([type])');
    if (b && !ev.defaultPrevented) ultimoBoton = b;
  }, true);
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (ev.defaultPrevented || !f || f.id === 'rv-push-form') return;
    if (f.getAttribute('data-enviando')) { ev.preventDefault(); return; }
    f.setAttribute('data-enviando', '1');
    f.setAttribute('aria-busy', 'true');
    var b = ev.submitter || (ultimoBoton && (ultimoBoton.form === f) ? ultimoBoton : f.querySelector('button[type="submit"]'));
    if (b) b.classList.add('is-cargando');
  });
  window.addEventListener('pageshow', function (ev) {   // al volver con «atrás» (bfcache) se limpia
    if (!ev.persisted) return;
    document.querySelectorAll('form[data-enviando]').forEach(function (f) { f.removeAttribute('data-enviando'); f.removeAttribute('aria-busy'); });
    document.querySelectorAll('.is-cargando').forEach(function (b) { b.classList.remove('is-cargando'); });
  });

  // ---- MATCH: animación discreta sólo la primera vez que aparece cada coincidencia ----
  var matches = document.querySelectorAll('.rv-match[data-match]');
  if (matches.length) {
    var vistos = [];
    try { vistos = JSON.parse(sessionStorage.getItem('rv-matches') || '[]') || []; } catch (e) { vistos = []; }
    matches.forEach(function (m) {
      var id = m.getAttribute('data-match');
      if (vistos.indexOf(id) === -1) { m.classList.add('es-nuevo'); vistos.push(id); }
    });
    try { sessionStorage.setItem('rv-matches', JSON.stringify(vistos.slice(-50))); } catch (e) {}
  }

  // ---- Rivales: filtros por día y coincidencias ----
  var filtros = document.getElementById('rv-filtros');
  if (filtros) {
    filtros.hidden = false;
    var dia = '';
    var solo = document.getElementById('rv-solo-coinc');
    var vacio = document.getElementById('rv-filtro-vacio');
    var aplicar = function () {
      var soloC = !!(solo && solo.checked), total = 0;
      document.querySelectorAll('.rv-rivales--compacto .rv-rival').forEach(function (li) {
        var visibles = 0;
        li.querySelectorAll('.rv-fila-dia').forEach(function (fila) {
          var okDia = !dia || fila.getAttribute('data-fecha') === dia;
          var horas = 0;
          fila.querySelectorAll('.rv-hora').forEach(function (b) {
            var ok = okDia && (!soloC || b.getAttribute('data-coincide') === '1');
            b.hidden = !ok;
            if (ok) horas++;
          });
          fila.hidden = horas === 0;
          visibles += horas;
        });
        li.hidden = visibles === 0;
        if (visibles && (dia || soloC)) { var d = li.querySelector('details'); if (d) d.open = true; }
        total += visibles;
      });
      if (vacio) vacio.hidden = total > 0;
    };
    filtros.addEventListener('click', function (ev) {
      var c = ev.target.closest('.rv-chip');
      if (!c) return;
      dia = c.getAttribute('data-dia') || '';
      filtros.querySelectorAll('.rv-chip').forEach(function (x) { x.classList.toggle('is-activo', x === c); x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
      aplicar();
    });
    if (solo) solo.addEventListener('change', aplicar);
  }

  // ---- Actualización automática: si algo cambió, recarga (salvo que el capitán esté ocupado) ----
  var v = document.body.getAttribute('data-version');
  if (!v || !window.fetch) return;
  var ocupado = function () {
    if (document.querySelector('form.js-slots input[name="slots[]"]:checked')) return true;
    if (document.querySelector('details.rv-reprog[open]')) return true;
    if (document.querySelector('form[data-enviando]')) return true;
    var a = document.activeElement;
    return !!(a && /^(SELECT|INPUT|TEXTAREA)$/.test(a.tagName));
  };
  setInterval(function () {
    if (document.hidden) return;
    fetch('rival-estado.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (d.v !== v) {
          if (ocupado()) { var el = document.getElementById('rv-vivo'); if (el) el.textContent = 'Hay cambios: al terminar verás lo más reciente.'; }
          else location.reload();
        }
      }).catch(function () {});
  }, 15000);
})();
