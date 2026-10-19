/* Panel de coordinación — ARRANQUE: acceso (coordinador o capitán), cambio obligatorio de la contraseña inicial y salida. */
(function () {
  'use strict';
  function mostrar(cual) { ['acceso', 'app', 'capitan'].forEach(function (id) { C.$(id).hidden = id !== cual; }); }
  function error(id, t) { var e = C.$(id); e.textContent = t || ''; e.classList.toggle('ver', !!t); }

  C.cambiarEspacio = function (esp) {
    return C.cargar(esp).then(function (r) {
      if (r && r.ok === false) { C.toast(r.error || 'No se pudo cargar.'); return; }
      C.leerFiltros(); if (C.ruta().mod !== 'inicio') C.ir('inicio'); else C.pintar();
    });
  };

  /** obligatorio = true: primera entrada con la contraseña inicial; no se puede cerrar sin cambiarla. */
  C.cambiarClave = function (obligatorio, actual) {
    var f = C.el('<form><p class="suave" style="margin:0 0 12px;font-size:13.5px">' + (obligatorio ? 'Es tu primera entrada: elige tu propia contraseña para continuar. La inicial deja de funcionar.' : 'Usa al menos 8 caracteres.') + '</p>'
      + '<div class="error" role="alert"></div>'
      + (obligatorio ? '' : '<label class="campo"><span>Contraseña actual</span><input type="password" name="actual" autocomplete="current-password" required></label>')
      + '<label class="campo"><span>Contraseña nueva</span><input type="password" name="nueva" autocomplete="new-password" minlength="8" required></label>'
      + '<label class="campo"><span>Repite la contraseña nueva</span><input type="password" name="nueva2" autocomplete="new-password" minlength="8" required></label>'
      + '<div class="modal-pie">' + (obligatorio ? '<button type="button" class="btn" data-salir>Salir</button>' : '<button type="button" class="btn" data-no>Cancelar</button>') + '<button type="submit" class="btn pri">Guardar contraseña</button></div></form>');
    var m = C.modal(obligatorio ? 'Crea tu contraseña' : 'Cambiar contraseña', f, obligatorio ? { alCerrar: function () { if (!hecho) C.cambiarClave(true, actual); } } : {});
    var hecho = false, err = f.querySelector('.error');
    if (obligatorio) { m.querySelector('.modal-x').hidden = true; f.querySelector('[data-salir]').onclick = function () { hecho = true; salir(); }; }
    else f.querySelector('[data-no]').onclick = C.cerrarModal;
    f.onsubmit = function (e) {
      e.preventDefault(); err.classList.remove('ver');
      var n1 = f.elements.nueva.value, n2 = f.elements.nueva2.value;
      if (n1 !== n2) { err.textContent = 'Las dos contraseñas nuevas no coinciden.'; err.classList.add('ver'); return; }
      C.api('cambiar_clave', { actual: obligatorio ? actual : f.elements.actual.value, nueva: n1 }).then(function (r) {
        if (!r.ok) { err.textContent = r.error || 'No se pudo cambiar.'; err.classList.add('ver'); return; }
        hecho = true; C.cerrarModal(); C.toast('Contraseña guardada.');
        if (obligatorio) entrarPanel();
      });
    };
  };

  function entrarPanel() {
    var esp = 'real'; try { if (sessionStorage.getItem('coord-espacio') === 'demo') esp = 'demo'; } catch (e) {}
    C.rol = 'coordinador';
    return C.cargar(esp).then(function (r) {
      if (r && r.ok === false) { if (r.debeCambiar) return; mostrar('acceso'); error('err-coordinador', r.error || 'No se pudo cargar la información.'); return; }
      C.leerFiltros(); mostrar('app'); C.pintar();
    });
  }
  function entrarCapitan() {
    C.rol = 'capitan';
    return C.api('capitan').then(function (r) {
      if (!r.ok) { C.rol = null; mostrar('acceso'); if (r.error) error('err-capitan', r.error); return; }
      mostrar('capitan'); C.capitanUI.pintar(C.$('capitan'), r.vista, {});
      document.title = r.vista.equipo.nombre + ' · ' + (r.vista.liga.nombre || 'Liga');
    });
  }
  function salir() {
    try { if (window.google && google.accounts && google.accounts.id) google.accounts.id.disableAutoSelect(); } catch (e) {}
    C.api('salir', {}).then(function () { location.hash = ''; location.reload(); }, function () { location.reload(); });
  }

  /* ---------- acceso con Google (coordinador) ----------
     Google comprueba quién es la persona; el servidor verifica su respuesta y decide:
     · cuenta ya ligada → entra;  · cuenta nueva → pide la contraseña del coordinador UNA vez para ligarla. */
  var gCfg = null, gPend = false;
  function gSalirDePendiente() { gPend = false; C.$('g-ligar').hidden = true; C.$('f-coordinador').querySelector('button[type=submit]').textContent = 'Entrar'; if (gCfg) C.$('g-acceso').hidden = false; }
  function gPintarBoton() {
    if (!gCfg || !window.google || !google.accounts || !google.accounts.id) return;
    google.accounts.id.initialize({ client_id: gCfg.clienteId, nonce: gCfg.nonce, callback: gRespuesta, ux_mode: 'popup' });
    C.$('g-acceso').hidden = false; C.$('g-boton').innerHTML = '';
    google.accounts.id.renderButton(C.$('g-boton'), { theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill', locale: 'es-419', width: Math.max(220, Math.min(340, C.$('g-boton').offsetWidth || 320)) });
  }
  function gIniciar() {
    gPend = false; C.$('g-ligar').hidden = true;
    C.api('google').then(function (r) {
      if (!r || !r.ok || !r.activo) { gCfg = null; C.$('g-acceso').hidden = true; return; }
      gCfg = r;
      if (window.google && google.accounts && google.accounts.id) return gPintarBoton();
      if (document.getElementById('g-script')) return;
      var s = document.createElement('script'); s.id = 'g-script'; s.src = 'https://accounts.google.com/gsi/client'; s.async = true; s.onload = gPintarBoton;
      document.head.appendChild(s);
    }).catch(function () {});
  }
  function gRespuesta(resp) {
    error('err-coordinador', '');
    C.api('entrar_google', { credencial: (resp && resp.credential) || '' }).then(function (r) {
      if (r && r.ok && r.rol === 'coordinador') { C.csrf = r.csrf; C.usuario = r.nombre || ''; return entrarPanel(); }
      if (r && r.ok && r.falta === 'clave') {
        gPend = true;
        C.$('g-hola').textContent = r.nombre ? 'Hola, ' + r.nombre : 'Cuenta de Google comprobada';
        C.$('g-ligar').hidden = false; C.$('g-acceso').hidden = true;
        C.$('f-coordinador').querySelector('button[type=submit]').textContent = 'Ligar y entrar';
        C.$('clave').focus();
        return;
      }
      error('err-coordinador', (r && r.error) || 'No se pudo entrar con Google. Inténtalo de nuevo.');
      gIniciar();                                  /* el número de un solo uso ya se gastó: se pide otro */
    }).catch(function () { error('err-coordinador', 'No se pudo contactar al servidor.'); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    C.$('acceso-escudo').textContent = 'PC';
    document.querySelectorAll('[data-acceso]').forEach(function (b) {
      b.onclick = function () {
        var k = b.getAttribute('data-acceso');
        document.querySelectorAll('[data-acceso]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        C.$('f-coordinador').hidden = k !== 'coordinador'; C.$('f-capitan').hidden = k !== 'capitan';
        (k === 'coordinador' ? C.$('clave') : C.$('codigo')).focus();
      };
    });
    C.$('f-coordinador').onsubmit = function (e) {
      e.preventDefault(); error('err-coordinador', '');
      var clave = C.$('clave').value, b = this.querySelector('button[type=submit]'); b.disabled = true;
      /* con una cuenta de Google ya comprobada, la contraseña LIGA esa cuenta (una sola vez) */
      C.api(gPend ? 'ligar_google' : 'entrar', { clave: clave }).then(function (r) {
        b.disabled = false;
        if (!r.ok) { if (r.reiniciar) { gSalirDePendiente(); gIniciar(); } return error('err-coordinador', r.error || 'No se pudo entrar.'); }
        C.csrf = r.csrf; C.usuario = r.nombre || ''; C.$('clave').value = '';
        if (r.ligada) C.toast('Tu cuenta de Google quedó ligada.');
        if (r.debeCambiar) { C.rol = 'coordinador'; C.cambiarClave(true, clave); } else entrarPanel();
      }).catch(function () { b.disabled = false; error('err-coordinador', 'No se pudo contactar al servidor.'); });
    };
    C.$('codigo').oninput = function () { var x = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); this.value = x.length > 4 ? x.slice(0, 4) + '-' + x.slice(4, 8) : x; };
    C.$('f-capitan').onsubmit = function (e) {
      e.preventDefault(); error('err-capitan', '');
      var b = this.querySelector('button[type=submit]'); b.disabled = true;
      C.api('entrar_capitan', { codigo: C.$('codigo').value }).then(function (r) {
        b.disabled = false;
        if (!r.ok) return error('err-capitan', r.error || 'No se pudo entrar.');
        C.$('codigo').value = ''; entrarCapitan();
      }).catch(function () { b.disabled = false; error('err-capitan', 'No se pudo contactar al servidor.'); });
    };
    C.$('g-otra').onclick = function () { gSalirDePendiente(); gIniciar(); };
    C.$('btn-salir').onclick = salir;
    C.$('btn-ir-real').onclick = function () { C.cambiarEspacio('real'); };
    C.$('capitan').addEventListener('click', function (e) {
      var b = e.target.closest('[data-a]'); if (!b) return;
      if (b.getAttribute('data-a') === 'salir') salir(); else entrarCapitan();
    });

    C.api('sesion').then(function (r) {
      C.csrf = r.csrf || ''; C.usuario = r.nombre || '';
      /* Google se prepara DESPUÉS de saber si hay sesión: así su número de un solo uso queda en la misma sesión. */
      if (r.rol === 'coordinador') { if (r.debeCambiar) { mostrar('acceso'); C.api('salir', {}).then(gIniciar); } else entrarPanel(); }
      else if (r.rol === 'capitan') entrarCapitan();
      else { mostrar('acceso'); C.$('clave').focus(); gIniciar(); }
    }).catch(function () { mostrar('acceso'); error('err-coordinador', 'No se pudo contactar al servidor.'); });
  });
})();
