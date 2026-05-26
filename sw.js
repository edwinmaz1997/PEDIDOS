// ============================================================
// NuevaExpress Service Worker — PWA + Push Notifications
// ============================================================
const CACHE_NAME = 'nuevaexpress-v1';
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/assets/css/main.css',
  '/assets/js/app.js',
  '/assets/img/logo.jpg',
  '/cliente/login.html',
  '/cliente/index.html',
  '/negocio/index.html',
  '/admin/index.html',
  '/repartidor/index.html',
  'https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap'
];

// Install — cache static assets
self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(STATIC_ASSETS.map(function(url) {
        return new Request(url, { mode: 'no-cors' });
      })).catch(function() {});
    })
  );
  self.skipWaiting();
});

// Activate — clean old caches
self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k) {
        return k !== CACHE_NAME;
      }).map(function(k) {
        return caches.delete(k);
      }));
    })
  );
  self.clients.claim();
});

// Fetch — network first, fallback to cache
self.addEventListener('fetch', function(e) {
  // Skip API calls — always go to network
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
  try { data = e.data.json(); } catch(err) { data = { title: 'NuevaExpress', body: e.data ? e.data.text() : 'Nueva notificación' }; }

  var options = {
    body:    data.body    || 'Tienes una nueva notificación',
    icon:    '/assets/img/logo.jpg',
    badge:   '/assets/img/logo.jpg',
    vibrate: [200, 100, 200],
    data:    { url: data.url || '/' },
    actions: data.actions || []
  };

  e.waitUntil(
    self.registration.showNotification(data.title || 'NuevaExpress', options)
  );
});

// Notification click — open the app
self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var url = e.notification.data.url || '/';
  e.waitUntil(
    clients.matchAll({ type: 'window' }).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        if (clientList[i].url === url && 'focus' in clientList[i]) {
          return clientList[i].focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
