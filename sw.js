// NuevaExpress Service Worker v2
const CACHE = 'nuevaexpress-v2';

self.addEventListener('install', function(e) {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.map(function(k) { return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

// Network first — no caching issues
self.addEventListener('fetch', function(e) {
  if (e.request.url.includes('/api/')) return;
  e.respondWith(
    fetch(e.request).catch(function() {
      return caches.match(e.request);
    })
  );
});

// Push notifications
self.addEventListener('push', function(e) {
  var data = {};
  try { data = e.data.json(); } catch(err) {
    data = { title: 'NuevaExpress', body: e.data ? e.data.text() : 'Nueva notificación' };
  }
  e.waitUntil(
    self.registration.showNotification(data.title || 'NuevaExpress', {
      body:    data.body || 'Tienes una nueva notificación',
      icon:    '/assets/img/icon-192.png',
      badge:   '/assets/img/icon-192.png',
      vibrate: [200, 100, 200],
      data:    { url: data.url || '/' }
    })
  );
});

self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var url = '/';
  try { url = e.notification.data.url || '/'; } catch(err) {}
  e.waitUntil(
    clients.matchAll({type:'window', includeUncontrolled:true}).then(function(cls) {
      // Focus existing window if open
      for (var i = 0; i < cls.length; i++) {
        if (cls[i].url.indexOf(url) !== -1 && 'focus' in cls[i]) return cls[i].focus();
      }
      return clients.openWindow(url);
    })
  );
});
