/* Chat del partido confirmado: envía sin recargar y trae mensajes nuevos cada pocos segundos. */
(function () {
  'use strict';
  var lista = document.getElementById('chat-lista');
  if (!lista) return;
  var form = document.getElementById('chat-form'), txt = document.getElementById('chat-texto');
  var r = lista.getAttribute('data-r'), ultimo = Number(lista.getAttribute('data-ultimo')) || 0, ocupado = false;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function abajo() { window.scrollTo(0, document.body.scrollHeight); }
  function pintar(ms) {
    if (!ms.length) return;
    var v = document.getElementById('chat-vacio'); if (v) v.remove();
    ms.forEach(function (m) {
      if (m.id <= ultimo) return;
      var li = document.createElement('li');
      li.className = 'rv-chat__m' + (m.mio ? ' rv-chat__m--mio' : '');
      li.innerHTML = '<span class="rv-chat__txt">' + esc(m.texto).replace(/\n/g, '<br>') + '</span><small>' + (m.mio ? '' : esc(m.de) + ' · ') + esc(m.hora) + '</small>';
      lista.appendChild(li);
      ultimo = m.id;
    });
    abajo();
  }
  function traer() {
    if (ocupado || document.hidden) return;
    ocupado = true;
    fetch('rival-chat.php?r=' + r + '&json=1&desde=' + ultimo, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function (x) { return x.json(); })
      .then(function (j) { if (j && j.ok) { pintar(j.mensajes || []); if (!j.abierto && form) location.reload(); } })
      .catch(function () {})
      .then(function () { ocupado = false; });
  }
  setInterval(traer, 6000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) traer(); });
  abajo();
  if (!form) return;
  function crecer() { txt.style.height = 'auto'; txt.style.height = Math.min(txt.scrollHeight, 140) + 'px'; }
  txt.addEventListener('input', crecer);
  txt.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer:fine)').matches) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); }
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var t = txt.value.trim(); if (!t) return;
    var fd = new FormData(form); fd.set('texto', t);
    var btn = form.querySelector('button'); btn.disabled = true;
    fetch('rival-chat.php', {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function (x) { return x.json(); })
      .then(function (j) {
        if (j && j.ok) { txt.value = ''; crecer(); traer(); }
        else alert((j && j.error) || 'No se pudo enviar.');
      })
      .catch(function () { alert('Sin conexión. Intenta de nuevo.'); })
      .then(function () { btn.disabled = false; txt.focus(); });
  });
})();
