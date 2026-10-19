/* Nubes de ayuda (ⓘ): la explicación aparece flotando sólo cuando se toca el botón.
   Se cierra al tocar fuera, con Esc o al abrir otra. Sin dependencias. */
(function () {
  'use strict';
  var abierta = null;
  function cerrar() {
    if (!abierta) return;
    abierta.btn.setAttribute('aria-expanded', 'false');
    abierta.nube.hidden = true;
    abierta = null;
  }
  function ubicar(btn, nube) {
    nube.style.left = '0px';
    var r = nube.getBoundingClientRect(), m = 12, dx = 0;
    if (r.right > window.innerWidth - m) dx = window.innerWidth - m - r.right;
    if (r.left + dx < m) dx = m - r.left;
    nube.style.left = dx + 'px';
    var flecha = btn.getBoundingClientRect().left + btn.offsetWidth / 2 - (r.left + dx);
    nube.style.setProperty('--flecha', Math.max(12, Math.min(r.width - 12, flecha)) + 'px');
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.nube-btn');
    if (btn) {
      ev.preventDefault();
      ev.stopPropagation();
      var nube = btn.parentNode.querySelector('.nube');
      if (abierta && abierta.btn === btn) { cerrar(); return; }
      cerrar();
      nube.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      ubicar(btn, nube);
      abierta = { btn: btn, nube: nube };
      return;
    }
    if (abierta && !ev.target.closest('.nube')) cerrar();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') cerrar(); });
  window.addEventListener('resize', cerrar);
})();
