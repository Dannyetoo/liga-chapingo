/* Liga Chapingo — Finanzas: ayudas de interfaz (el servidor valida todo por su cuenta). */
(function () {
  'use strict';
  // «Seleccionar todos»: <input type="checkbox" data-todos="nombre[]">
  document.querySelectorAll('input[data-todos]').forEach(function (maestro) {
    maestro.addEventListener('change', function () {
      var form = maestro.form || document;
      form.querySelectorAll('input[type=checkbox][name="' + maestro.getAttribute('data-todos') + '"]').forEach(function (c) {
        if (!c.disabled) c.checked = maestro.checked;
      });
    });
  });
  // Confirmación: <button data-confirmar="¿Seguro?">
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-confirmar]');
    if (b && !window.confirm(b.getAttribute('data-confirmar'))) ev.preventDefault();
  });
})();
