// ============================================================
// NuevaExpress PWA — Install prompt + Push notifications
// ============================================================

// ── Service Worker Registration ───────────────────────────────
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/sw.js').then(function(reg) {
      console.log('SW registered');
      // Check for push support
      if ('PushManager' in window) {
        checkPushSubscription(reg);
      }
    }).catch(function(err) {
      console.log('SW error:', err);
    });
  });
}

// ── Install Prompt ────────────────────────────────────────────
var deferredPrompt = null;

window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  deferredPrompt = e;
  showInstallBanner();
});

function showInstallBanner() {
  // Don't show if already installed
  if (window.matchMedia('(display-mode: standalone)').matches) return;
  if (localStorage.getItem('pwa_dismissed')) return;

  var banner = document.createElement('div');
  banner.id = 'pwa-banner';
  banner.style.cssText = [
    'position:fixed', 'bottom:0', 'left:0', 'right:0', 'z-index:9999',
    'background:linear-gradient(135deg,#1a1a2e,#16213e)',
    'color:white', 'padding:16px 20px',
    'display:flex', 'align-items:center', 'gap:14px',
    'box-shadow:0 -4px 20px rgba(0,0,0,.3)',
    'font-family:DM Sans,sans-serif',
    'animation:slideUp .3s ease'
  ].join(';');

  banner.innerHTML =
    '<img src="/assets/img/logo.jpg" style="width:44px;height:44px;border-radius:10px;flex-shrink:0">' +
    '<div style="flex:1">' +
      '<div style="font-weight:700;font-size:.95rem">Instalar NuevaExpress</div>' +
      '<div style="font-size:.78rem;opacity:.7;margin-top:2px">Acceso rápido desde tu pantalla de inicio</div>' +
    '</div>' +
    '<button onclick="installPWA()" style="background:#4A90D9;color:white;border:none;border-radius:8px;padding:9px 18px;font-weight:600;font-size:.85rem;cursor:pointer;white-space:nowrap">Instalar</button>' +
    '<button onclick="dismissInstall()" style="background:rgba(255,255,255,.1);color:white;border:none;border-radius:8px;padding:9px 12px;cursor:pointer;font-size:1rem">✕</button>';

  var style = document.createElement('style');
  style.textContent = '@keyframes slideUp{from{transform:translateY(100%)}to{transform:none}}';
  document.head.appendChild(style);
  document.body.appendChild(banner);
}

function installPWA() {
  if (!deferredPrompt) return;
  deferredPrompt.prompt();
  deferredPrompt.userChoice.then(function(result) {
    if (result.outcome === 'accepted') {
      document.getElementById('pwa-banner')?.remove();
    }
    deferredPrompt = null;
  });
}

function dismissInstall() {
  document.getElementById('pwa-banner')?.remove();
  localStorage.setItem('pwa_dismissed', '1');
}

// ── Push Notifications ────────────────────────────────────────
function checkPushSubscription(reg) {
  reg.pushManager.getSubscription().then(function(sub) {
    if (!sub && localStorage.getItem('nuevaexpress_token')) {
      // Ask user after 3 seconds
      setTimeout(function() { requestPushPermission(reg); }, 3000);
    }
  });
}

function requestPushPermission(reg) {
  // Don't ask if already asked
  if (localStorage.getItem('push_asked')) return;
  localStorage.setItem('push_asked', '1');

  Notification.requestPermission().then(function(permission) {
    if (permission === 'granted') {
      subscribeToPush(reg);
    }
  });
}

function subscribeToPush(reg) {
  // Use polling instead of real push for shared hosting compatibility
  // Real push requires a push server (Firebase, etc.)
  // We'll use polling every 30s when app is open
  startNotificationPolling();
}

// ── Notification Polling (for shared hosting) ─────────────────
var notifInterval = null;
var lastNotifId = parseInt(localStorage.getItem('last_notif_id') || '0');

function startNotificationPolling() {
  if (notifInterval) return;
  if (!localStorage.getItem('nuevaexpress_token')) return;

  notifInterval = setInterval(checkNewNotifications, 30000);
}

async function checkNewNotifications() {
  var token = localStorage.getItem('nuevaexpress_token');
  if (!token) { clearInterval(notifInterval); return; }

  try {
    var res = await fetch('/api/notifications', {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    var data = await res.json();
    if (!data.success) return;

    var notifs = data.data.notifications || [];
    var unread = notifs.filter(function(n) {
      return !n.is_read && n.id > lastNotifId;
    });

    if (unread.length > 0 && 'Notification' in window && Notification.permission === 'granted') {
      unread.forEach(function(n) {
        new Notification(n.title, {
          body: n.message,
          icon: '/assets/img/logo.jpg',
          badge: '/assets/img/logo.jpg',
          tag: 'notif-' + n.id
        });
        lastNotifId = Math.max(lastNotifId, n.id);
      });
      localStorage.setItem('last_notif_id', lastNotifId);
    }
  } catch(e) {}
}

// Start polling if logged in
if (localStorage.getItem('nuevaexpress_token')) {
  startNotificationPolling();
}
