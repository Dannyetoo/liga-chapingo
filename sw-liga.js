/* Liga Chapingo — service worker (app instalable + notificaciones).
   No guarda páginas en caché: siempre pide la versión más reciente al servidor.
   Sólo muestra un aviso si no hay internet. */
'use strict';
var VERSION = 'liga-sw-1';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

/* Sin internet: una página sencilla en lugar del error del navegador. */
self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate') return;          // imágenes, estilos, APIs: el navegador normal
  e.respondWith(fetch(e.request).catch(function () {
    return new Response(
      '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>Sin conexión · Liga Chapingo</title><body style="font-family:system-ui,sans-serif;background:#F4F3EC;color:#14231C;' +
      'display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px">' +
      '<div><h1 style="color:#1B6B45;margin:0 0 8px">Sin conexión</h1><p>Revisa tu internet y vuelve a intentarlo.</p>' +
      '<button onclick="location.reload()" style="padding:12px 20px;border:0;border-radius:10px;background:#1B6B45;color:#fff;font-weight:700">Reintentar</button></div>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});

/* Aviso recibido del servidor (cifrado de extremo a extremo; el navegador ya lo descifró). */
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { cuerpo: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.titulo || 'Liga Chapingo', {
    body: d.cuerpo || '',
    icon: '/icono-192.png',
    badge: '/icono-192.png',
    tag: d.tag || undefined,
    data: { url: d.url || '/disponibilidad/rival.php' },
    lang: 'es-MX'
  }));
});

/* Al tocar el aviso: abre (o enfoca) la app en la página indicada. */
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var destino = new URL((e.notification.data && e.notification.data.url) || '/disponibilidad/rival.php', self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (lista) {
    for (var i = 0; i < lista.length; i++) {
      var c = lista[i];
      if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
        return c.focus().then(function (w) { return w && 'navigate' in w ? w.navigate(destino) : w; });
      }
    }
    return self.clients.openWindow(destino);
  }));
});
