/* Liga Chapingo — Disponibilidad del equipo
 * JavaScript sólo mejora la experiencia; toda validación se repite en el servidor. */
(function () {
  'use strict';

  var DIAS = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };
  var MAX_BLOQUES = 6;

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function aMin(h) { if (!h) return null; var p = h.split(':'); return (+p[0]) * 60 + (+p[1]); }
  function fmt(m) { return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }

  /* ---------- Selector de equipo: cambia sin botón ---------- */
  var sel = $('[data-autosubmit]');
  if (sel) {
    $$('.js-ocultar').forEach(function (b) { b.hidden = true; });
    sel.addEventListener('change', function () {
      if (sucio && !confirm('Tienes cambios sin guardar. ¿Cambiar de todos modos?')) {
        sel.value = sel.getAttribute('data-inicial') || '';
        return;
      }
      sucio = false;
      sel.form.submit();
    });
    sel.setAttribute('data-inicial', sel.value);
  }

  /* ---------- Confirmaciones de eliminación ---------- */
  $$('form.js-confirmar').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!confirm(f.getAttribute('data-confirmar') || '¿Confirmas la eliminación?')) ev.preventDefault();
      else sucio = false;
    });
  });

  /* ---------- Formulario semanal ---------- */
  var form = $('#form-semana');
  var sucio = false;
  var estado = $('.js-estado');
  var tpl = $('#tpl-bloque');

  function marcarSucio() {
    sucio = true;
    if (estado) estado.textContent = 'Cambios sin guardar';
  }

  function renumerar(dia) {
    var n = dia.getAttribute('data-dia');
    $$('.bloque', dia).forEach(function (b, i) {
      var ins = $$('input[type=time]', b);
      ins[0].name = 'dias[' + n + '][bloques][' + i + '][inicio]';
      ins[1].name = 'dias[' + n + '][bloques][' + i + '][fin]';
    });
    var agregar = $('.js-agregar', dia);
    if (agregar) agregar.disabled = $$('.bloque', dia).length >= MAX_BLOQUES;
  }

  function nuevoBloque(dia, foco) {
    var cont = $('.js-bloques', dia);
    if ($$('.bloque', dia).length >= MAX_BLOQUES) return;
    var nodo = tpl.content.firstElementChild.cloneNode(true);
    // Sugerencia: empezar donde terminó el último bloque.
    var ultimo = $$('.bloque', dia).pop();
    if (ultimo) {
      var fin = aMin($$('input', ultimo)[1].value);
      if (fin !== null && fin + 60 <= 23 * 60 + 45) {
        $$('input', nodo)[0].value = fmt(fin + 60);
        $$('input', nodo)[1].value = fmt(Math.min(fin + 180, 23 * 60 + 45));
      }
    }
    cont.appendChild(nodo);
    renumerar(dia);
    if (foco) $$('input', nodo)[0].focus();
  }

  function validarDia(dia) {
    var err = $('.js-error', dia);
    var on = $('.js-toggle-dia', dia).checked;
    var nombre = DIAS[dia.getAttribute('data-dia')];
    var msgs = [];
    $$('.bloque', dia).forEach(function (b) { b.classList.remove('bloque--mal'); });

    if (on) {
      var lista = [];
      $$('.bloque', dia).forEach(function (b) {
        var ins = $$('input', b);
        var i = aMin(ins[0].value), f = aMin(ins[1].value);
        if (i === null && f === null) return;
        if (i === null || f === null) { msgs.push('Completa inicio y fin.'); b.classList.add('bloque--mal'); return; }
        if (f <= i) { msgs.push('La hora de fin debe ser posterior al inicio (' + fmt(i) + '–' + fmt(f) + ').'); b.classList.add('bloque--mal'); return; }
        lista.push({ i: i, f: f, el: b });
      });
      if (!lista.length && !msgs.length) msgs.push('Agrega al menos un horario o marca el día como no disponible.');
      lista.sort(function (a, b) { return a.i - b.i; });
      for (var k = 1; k < lista.length; k++) {
        var a = lista[k - 1], c = lista[k];
        if (a.i === c.i && a.f === c.f) {
          msgs.push('El horario ' + fmt(a.i) + '–' + fmt(a.f) + ' está repetido.');
          c.el.classList.add('bloque--mal');
        } else if (c.i < a.f) {
          msgs.push('Los horarios ' + fmt(a.i) + '–' + fmt(a.f) + ' y ' + fmt(c.i) + '–' + fmt(c.f) + ' se enciman.');
          a.el.classList.add('bloque--mal'); c.el.classList.add('bloque--mal');
        }
      }
    }
    if (msgs.length) { err.textContent = nombre + ': ' + msgs.join(' '); err.hidden = false; }
    else { err.textContent = ''; err.hidden = true; }
    return !msgs.length;
  }

  if (form && tpl) {
    $$('.dia', form).forEach(function (dia) {
      renumerar(dia);
      var toggle = $('.js-toggle-dia', dia);
      var txt = $('.interruptor__txt', dia);

      toggle.addEventListener('change', function () {
        dia.classList.toggle('is-on', toggle.checked);
        txt.textContent = toggle.checked ? txt.getAttribute('data-on') : txt.getAttribute('data-off');
        if (toggle.checked && !$$('.bloque', dia).length) nuevoBloque(dia, true);
        marcarSucio();
        validarDia(dia);
      });

      $('.js-agregar', dia).addEventListener('click', function () {
        nuevoBloque(dia, true);
        marcarSucio();
      });

      dia.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.js-quitar');
        if (!btn) return;
        var bloque = btn.closest('.bloque');
        var ins = $$('input', bloque);
        var texto = (ins[0].value || '--:--') + '–' + (ins[1].value || '--:--');
        if ((ins[0].value || ins[1].value) &&
            !confirm('¿Quitar el horario ' + texto + ' de ' + DIAS[dia.getAttribute('data-dia')] + '?' +
              (bloque.hasAttribute('data-guardado') ? '\nSe eliminará al pulsar «Guardar disponibilidad semanal».' : ''))) {
          return;
        }
        bloque.remove();
        if (!$$('.bloque', dia).length) {
          toggle.checked = false;
          toggle.dispatchEvent(new Event('change'));
        }
        renumerar(dia);
        marcarSucio();
        validarDia(dia);
      });

      dia.addEventListener('input', function () { marcarSucio(); });
      dia.addEventListener('change', function (ev) {
        if (ev.target.type === 'time') validarDia(dia);
      });
    });

    form.addEventListener('submit', function (ev) {
      var ok = true, primero = null;
      $$('.dia', form).forEach(function (dia) {
        if (!validarDia(dia)) { ok = false; primero = primero || dia; }
      });
      if (!ok) {
        ev.preventDefault();
        if (estado) estado.textContent = 'Corrige los horarios marcados en rojo.';
        primero.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      sucio = false;
      var b = form.querySelector('button[type=submit]');
      b.disabled = true; b.textContent = 'Guardando…';
    });
  }

  window.addEventListener('beforeunload', function (ev) {
    if (sucio) { ev.preventDefault(); ev.returnValue = ''; }
  });

  /* ---------- Excepciones ---------- */
  var fx = $('#form-exc');
  if (fx) {
    var ayuda = $('.js-ayuda-exc', fx);
    var errx = $('.js-error-exc', fx);
    var hi = fx.elements['hora_inicio'], hf = fx.elements['hora_fin'];

    function tipo() { var r = fx.querySelector('input[name=tipo]:checked'); return r ? r.value : ''; }
    function actualizar() {
      var extra = tipo() === 'disponibilidad_extra';
      $$('.js-opc', fx).forEach(function (e) { e.textContent = extra ? '(obligatoria)' : '(opcional)'; });
      hi.required = hf.required = extra;
      var sujeto = fx.getAttribute('data-sujeto') || 'el equipo';
      ayuda.textContent = extra
        ? 'Indica el horario extra en que ' + sujeto + ' sí está disponible.'
        : 'Si dejas las horas vacías, ' + sujeto + ' queda no disponible todo el día.';
    }
    $$('input[name=tipo]', fx).forEach(function (r) { r.addEventListener('change', actualizar); });
    actualizar();

    fx.addEventListener('submit', function (ev) {
      var m = [];
      var fecha = fx.elements['fecha'];
      if (!fecha.value) m.push('Indica la fecha.');
      else if ((fecha.min && fecha.value < fecha.min) || (fecha.max && fecha.value > fecha.max)) m.push('La fecha está fuera del semestre activo.');
      var i = aMin(hi.value), f = aMin(hf.value);
      if (tipo() === 'disponibilidad_extra' && (i === null || f === null)) m.push('Para disponibilidad extra, inicio y fin son obligatorios.');
      if ((i === null) !== (f === null) && tipo() === 'no_disponible') m.push('Llena inicio y fin, o deja ambas vacías para todo el día.');
      if (i !== null && f !== null && f <= i) m.push('La hora de fin debe ser posterior al inicio.');
      if (m.length) { ev.preventDefault(); errx.textContent = m.join(' '); errx.hidden = false; }
    });
  }
})();
