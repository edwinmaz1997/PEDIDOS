// ============================================================
// NuevaExpress PWA — OneSignal Push + Install Prompt
// ============================================================

// ── OneSignal Init ────────────────────────────────────────────
window.OneSignalDeferred = window.OneSignalDeferred || [];

OneSignalDeferred.push(async function(OneSignal) {
  try {
    await OneSignal.init({
      appId: "36b01031-83d9-4f66-bad8-3c32478f9fb2",
      notifyButton: { enable: false },
      welcomeNotification: { disable: true },
      serviceWorkerParam: { scope: '/' },
      serviceWorkerPath: '/OneSignalSDKWorker.js',
    });
  } catch(e) {
    console.warn('OneSignal init error (permiso bloqueado o no soportado):', e.message);
    return;
  }

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
  if (window.OneSignal) {
    window.OneSignal.Notifications.requestPermission().then(function(accepted) {
      if (accepted) {
        alert('¡Notificaciones activadas! ✅ Recibirás avisos de tus pedidos.');
      } else {
        alert('Notificaciones rechazadas. Puedes activarlas desde la configuración del navegador.');
      }
    });
  } else if (window.OneSignalDeferred) {
    window.OneSignalDeferred.push(function(OneSignal) {
      OneSignal.Notifications.requestPermission();
    });
    alert('⏳ Activando notificaciones...\n\nSi no aparece ningún diálogo en 5 segundos, el SDK de notificaciones no pudo cargar. Verifica:\n\n• Desactiva bloqueadores de contenido en Safari\n• Asegúrate de estar en iOS 16.4 o superior\n• Abre la app desde el ícono instalado, no desde Safari directamente');
  } else {
    alert('⚠️ No se pudo cargar el servicio de notificaciones.\n\nSi tienes un bloqueador de anuncios (AdBlock, uBlock, etc.), desactívalo para nuevaexpress.com y recarga la página.');
  }
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



// ── Pull-to-refresh manual (en iOS PWA standalone el gesto nativo no funciona) ──
(function() {
  var startY = 0, pulling = false, threshold = 80, indicator = null;

  function createIndicator() {
    if (indicator) return indicator;
    indicator = document.createElement('div');
    indicator.id = 'ptr-indicator';
    indicator.style.cssText = 'position:fixed;top:-60px;left:0;right:0;height:60px;display:flex;align-items:center;justify-content:center;z-index:9999;transition:top .2s ease;pointer-events:none;background:linear-gradient(to bottom, rgba(255,255,255,.97), rgba(255,255,255,.9));';
    indicator.innerHTML = '<div style="width:28px;height:28px;border:3px solid #e0e0e0;border-top-color:#4A90D9;border-radius:50%;animation:ptr-spin .7s linear infinite"></div>';
    document.body.appendChild(indicator);
    if (!document.getElementById('ptr-style')) {
      var style = document.createElement('style');
      style.id = 'ptr-style';
      style.textContent = '@keyframes ptr-spin{to{transform:rotate(360deg)}}';
      document.head.appendChild(style);
    }
    return indicator;
  }

  document.addEventListener('touchstart', function(e) {
    if (window.scrollY === 0) {
      startY = e.touches[0].clientY;
      pulling = true;
    } else {
      pulling = false;
    }
  }, { passive: true });

  document.addEventListener('touchmove', function(e) {
    if (!pulling) return;
    var dy = e.touches[0].clientY - startY;
    if (dy > 0 && window.scrollY === 0) {
      var ind = createIndicator();
      var pull = Math.min(dy * 0.5, 70);
      ind.style.top = (pull - 60) + 'px';
      if (dy > threshold) ind.style.background = 'linear-gradient(to bottom, rgba(240,247,255,.97), rgba(240,247,255,.9))';
    }
  }, { passive: true });

  document.addEventListener('touchend', function(e) {
    if (!pulling) return;
    pulling = false;
    var dy = (e.changedTouches[0].clientY - startY);
    if (dy > threshold && window.scrollY === 0) {
      if (indicator) indicator.style.top = '0px';
      setTimeout(function() {
        // En iOS PWA standalone, location.reload() a veces sirve desde caché.
        // Forzamos una navegación con cache-buster para garantizar contenido fresco.
        var url = new URL(window.location.href);
        url.searchParams.set('_r', Date.now());
        window.location.replace(url.toString());
      }, 150);
    } else if (indicator) {
      indicator.style.top = '-60px';
    }
  }, { passive: true });
})();
