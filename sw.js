// NuevaExpress Service Worker
// Push notifications handled by OneSignal (OneSignalSDKWorker.js)
const CACHE = 'nuevaexpress-v3';

self.addEventListener('install', function(e) { self.skipWaiting(); });
self.addEventListener('activate', function(e) {
  e.waitUntil(caches.keys().then(function(keys) {
    return Promise.all(keys.filter(function(k){ return k !== CACHE; }).map(function(k){ return caches.delete(k); }));
  }));
  self.clients.claim();
});
self.addEventListener('fetch', function(e) {
  if (e.request.url.includes('/api/')) return;
  e.respondWith(fetch(e.request).catch(function() { return caches.match(e.request); }));
});
