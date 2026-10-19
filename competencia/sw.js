'use strict';
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));
// No almacena cuentas, documentos ni respuestas de la API en caché.
self.addEventListener('fetch',e=>{
  if(e.request.mode!=='navigate')return;
  e.respondWith(fetch(e.request).catch(()=>new Response('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sin conexión</title><body style="font:18px system-ui;padding:24px;background:#f2f5f8;color:#123b63"><h1>Sin conexión</h1><p>Conéctate a internet para consultar datos actualizados o guardar movimientos.</p><button onclick="location.reload()">Reintentar</button></body></html>',{headers:{'Content-Type':'text/html; charset=utf-8'}})));
});
