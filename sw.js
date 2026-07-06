// NuevaExpress Service Worker
// Push notifications handled by OneSignal (OneSignalSDKWorker.js)
const CACHE = 'nuevaexpress-v5';

self.addEventListener('install', function(e) { self.skipWaiting(); });
self.addEventListener('activate', function(e) {
  e.waitUntil(caches.keys().then(function(keys) {
    return Promise.all(keys.filter(function(k){ return k !== CACHE; }).map(function(k){ return caches.delete(k); }));
  }));
  self.clients.claim();
  // Notificar a todos los clientes que hay nueva versión
  self.clients.matchAll().then(function(clients) {
    clients.forEach(function(client) { client.postMessage({ type: 'SW_UPDATED' }); });
  });
});
self.addEventListener('fetch', function(e) {
  // Solo interceptar requests del mismo origen — no CDN externos ni OneSignal
  if (!e.request.url.startsWith(self.location.origin)) return;
  if (e.request.url.includes('/api/')) return;
  e.respondWith(fetch(e.request).catch(function() { return caches.match(e.request); }));
});
