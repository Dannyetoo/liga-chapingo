/* Administración · Estados de cuenta: buscar, ver/copiar código y cambiar de edición (sin scripts en línea por la CSP). */
(function () {
  'use strict';
  var sel = document.querySelector('select[data-autoenvio]');
  if (sel) sel.addEventListener('change', function () { sel.form.submit(); });

  var buscar = document.querySelector('[data-buscar]'), total = document.querySelector('[data-total]');
  function filtrar() {
    var q = (buscar.value || '').trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    var n = 0;
    document.querySelectorAll('[data-grupo]').forEach(function (g) {
      var vis = 0;
      g.querySelectorAll('.ec-eq').forEach(function (li) {
        var t = (li.getAttribute('data-texto') || '').normalize('NFD').replace(/[̀-ͯ]/g, '');
        var ok = !q || t.indexOf(q) !== -1;
        li.hidden = !ok; if (ok) vis++;
      });
      g.hidden = vis === 0; n += vis;
    });
    if (total) total.textContent = n + (n === 1 ? ' equipo' : ' equipos');
  }
  if (buscar) buscar.addEventListener('input', filtrar);

  document.addEventListener('click', function (ev) {
    var v = ev.target.closest('[data-ver]');
    if (v) {
      var c = v.closest('.ec-eq').querySelector('.ec-cod');
      var ver = c.textContent.indexOf('•') !== -1;
      c.textContent = ver ? c.getAttribute('data-cod') : '••••-••••';
      c.setAttribute('aria-label', ver ? 'Código ' + c.getAttribute('data-cod') : 'Código oculto');
      v.textContent = ver ? 'Ocultar' : 'Ver';
      return;
    }
    var k = ev.target.closest('[data-copiar]');
    if (k) {
      var txt = k.getAttribute('data-copiar');
      var listo = function () { var o = k.textContent; k.textContent = '¡Copiado!'; setTimeout(function () { k.textContent = o; }, 1400); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(listo, function () {});
      else {
        var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); listo(); } catch (e) {}
        ta.remove();
      }
    }
  });
})();
