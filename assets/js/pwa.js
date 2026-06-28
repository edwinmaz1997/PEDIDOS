// ============================================================
// NuevaExpress PWA — OneSignal Push + Install Prompt
// ============================================================

// ── OneSignal Init ────────────────────────────────────────────
window.OneSignalDeferred = window.OneSignalDeferred || [];

OneSignalDeferred.push(async function(OneSignal) {
  await OneSignal.init({
    appId: "36b01031-83d9-4f66-bad8-3c32478f9fb2",
    notifyButton: { enable: false },
    welcomeNotification: { disable: true },
  });

  // Link OneSignal user to our user ID when logged in
  var user = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}');
  if (user.id) {
    OneSignal.login(String(user.id));
    OneSignal.User.addTag('role', user.role || 'cliente');
    OneSignal.User.addTag('name', user.name || '');
  }

  // Listen for permission changes
  OneSignal.Notifications.addEventListener('permissionChange', function(permission) {
    console.log('Push permission:', permission);
  });

  // Listen for notification clicks
  OneSignal.Notifications.addEventListener('click', function(event) {
    var url = event.notification.launchURL || '/';
    if (url && url !== window.location.href) {
      window.location.href = url;
    }
  });
});

// ── Navbar auth state ─────────────────────────────────────────
(function updateNavbar() {
  var token = localStorage.getItem('nuevaexpress_token');
  var user  = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}');
  if (!token || !user.name) return;

  var role  = user.role || 'cliente';
  var panel = {
    admin:      '/admin/index.html',
    negocio:    '/negocio/index.html',
    repartidor: '/repartidor/index.html',
    cliente:    '/cliente/index.html'
  }[role] || '/cliente/index.html';

  var perfil = {
    admin:      '/admin/index.html',
    negocio:    '/negocio/perfil.html',
    repartidor: '/repartidor/index.html',
    cliente:    '/cliente/perfil.html'
  }[role] || '/cliente/perfil.html';

  var nav = document.getElementById('navRight') || document.querySelector('.nav-right');
  if (nav) {
    nav.innerHTML =
      '<a href="' + perfil + '" class="btn-ghost" style="padding:7px 14px;font-size:.82rem">👤 ' + user.name.split(' ')[0] + '</a>' +
      '<a href="' + panel + '" class="btn-primary" style="padding:7px 14px;font-size:.82rem">Mi panel</a>';
  }
})();

// ── Manual notification subscribe ────────────────────────────
function requestNotificationPermission() {
  if (!('Notification' in window)) {
    alert('Tu navegador no soporta notificaciones push.');
    return;
  }
  if (Notification.permission === 'granted') {
    alert('Las notificaciones ya están activadas ✅');
    return;
  }

  function doRequest() {
    if (window.OneSignal && window.OneSignal.Notifications) {
      window.OneSignal.Notifications.requestPermission().then(function(accepted) {
        if (accepted) {
          alert('¡Notificaciones activadas! ✅ Recibirás avisos de tus pedidos.');
        } else {
          alert('Notificaciones rechazadas. Puedes activarlas desde la configuración del navegador.');
        }
      }).catch(function(e) {
        alert('Error: ' + e.message);
      });
    } else {
      // Esperar hasta 5 segundos a que OneSignal cargue
      var attempts = 0;
      var interval = setInterval(function() {
        attempts++;
        if (window.OneSignal && window.OneSignal.Notifications) {
          clearInterval(interval);
          window.OneSignal.Notifications.requestPermission().then(function(accepted) {
            if (accepted) alert('¡Notificaciones activadas! ✅');
            else alert('Notificaciones rechazadas.');
          });
        } else if (attempts >= 10) {
          clearInterval(interval);
          alert('⚠️ No se pudo cargar el servicio de notificaciones.\n\nIntenta:\n1. Desactivar AdBlock para nuevaexpress.com\n2. Hacer Clear site data (F12 → Application → Storage)\n3. Recargar la página');
        }
      }, 500);
    }
  }
  doRequest();
}

// ── PWA Install Prompt ────────────────────────────────────────
var deferredPrompt = null;

window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  deferredPrompt = e;
  showInstallBanner();
});

function showInstallBanner() {
  if (window.matchMedia('(display-mode: standalone)').matches) return;
  if (localStorage.getItem('pwa_dismissed')) return;

  var banner = document.createElement('div');
  banner.id = 'pwa-banner';
  banner.style.cssText = [
    'position:fixed','bottom:0','left:0','right:0','z-index:9999',
    'background:linear-gradient(135deg,#1a1a2e,#16213e)',
    'color:white','padding:16px 20px',
    'display:flex','align-items:center','gap:14px',
    'box-shadow:0 -4px 20px rgba(0,0,0,.3)',
    'font-family:DM Sans,sans-serif',
    'animation:slideUp .3s ease'
  ].join(';');

  banner.innerHTML =
    '<img src="/assets/img/logo.jpg" style="width:44px;height:44px;border-radius:10px;flex-shrink:0">' +
    '<div style="flex:1"><div style="font-weight:700;font-size:.95rem">Instalar NuevaExpress</div>' +
    '<div style="font-size:.78rem;opacity:.7;margin-top:2px">Acceso rápido desde tu pantalla de inicio</div></div>' +
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
    if (result.outcome === 'accepted') document.getElementById('pwa-banner')?.remove();
    deferredPrompt = null;
  });
}

function dismissInstall() {
  document.getElementById('pwa-banner')?.remove();
  localStorage.setItem('pwa_dismissed', '1');
}
