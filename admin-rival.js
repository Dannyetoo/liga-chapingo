/* Administración · «Busca un rival»: confirmaciones y «marcar todos» (sin scripts en línea por la CSP). */
(function () {
  'use strict';
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-confirmar]');
    if (b && !window.confirm(b.getAttribute('data-confirmar'))) ev.preventDefault();
  });
  document.querySelectorAll('[data-marcar-todos]').forEach(function (t) {
    t.addEventListener('change', function () {
      document.querySelectorAll(t.getAttribute('data-marcar-todos')).forEach(function (c) { c.checked = t.checked; });
    });
  });
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (ev.defaultPrevented) return;
    if (f.getAttribute('data-enviando')) { ev.preventDefault(); return; }
    f.setAttribute('data-enviando', '1');
  });
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) document.querySelectorAll('form[data-enviando]').forEach(function (f) { f.removeAttribute('data-enviando'); });
  });
})();
/* Equipo 2 sólo puede ser del mismo grupo que el Equipo 1. */
(function () {
  'use strict';
  document.querySelectorAll('form[data-mismo-grupo]').forEach(function (f) {
    var sels = f.querySelectorAll('select[data-sel-grupo]');
    if (sels.length < 2) return;
    var a = sels[0], b = sels[1];
    var pintar = function () {
      var op = a.options[a.selectedIndex];
      var g = op ? op.getAttribute('data-grupo') : null;
      Array.prototype.forEach.call(b.options, function (o) {
        if (!o.value) return;
        o.disabled = !!g && (o.getAttribute('data-grupo') !== g || o.value === a.value);
      });
      Array.prototype.forEach.call(b.querySelectorAll('optgroup'), function (og) {
        og.hidden = !!g && !og.querySelector('option:not([disabled])');
      });
      if (b.selectedOptions[0] && b.selectedOptions[0].disabled) b.value = '';
    };
    a.addEventListener('change', pintar);
    pintar();
  });
})();
