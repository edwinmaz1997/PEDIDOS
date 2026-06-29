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

// ── Sistema de Notificaciones (Campanita) ─────────────────────
(function() {
  var _notifOpen = false;
  var _lastUnread = 0;
  var _pollInterval = null;

  function getToken() {
    return localStorage.getItem('nuevaexpress_token');
  }

  function timeAgo(dateStr) {
    var d = new Date(dateStr.replace(' ', 'T'));
    var diff = Math.floor((Date.now() - d) / 1000);
    if (diff < 60) return 'ahora';
    if (diff < 3600) return Math.floor(diff/60) + ' min';
    if (diff < 86400) return Math.floor(diff/3600) + 'h';
    return Math.floor(diff/86400) + 'd';
  }

  function initBell() {
    if (!getToken()) return;
    // Buscar el sidebar-logo para agregar la campanita
    var sidebarLogo = document.querySelector('.sidebar-logo');
    if (!sidebarLogo) return;
    if (document.getElementById('nx-bell')) return;

    // Crear campanita
    var bell = document.createElement('div');
    bell.id = 'nx-bell';
    bell.style.cssText = 'position:relative;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.1);margin-top:8px;flex-shrink:0';
    bell.innerHTML = '<span style="font-size:1.2rem">🔔</span><span id="nx-badge" style="display:none;position:absolute;top:-2px;right:-2px;background:#ef4444;color:white;font-size:.6rem;font-weight:700;min-width:16px;height:16px;border-radius:20px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid #1a1a2e">0</span>';
    bell.onclick = toggleNotifPanel;
    sidebarLogo.appendChild(bell);

    // Crear panel dropdown
    var panel = document.createElement('div');
    panel.id = 'nx-notif-panel';
    panel.style.cssText = 'display:none;position:fixed;top:0;left:260px;width:320px;height:100vh;background:white;z-index:500;box-shadow:4px 0 20px rgba(0,0,0,.15);flex-direction:column;overflow:hidden';
    panel.innerHTML = '<div style="padding:14px 16px;background:var(--navy);display:flex;align-items:center;gap:10px">' +
      '<button onclick="window._nxClosePanel()" style="background:rgba(255,255,255,.15);border:none;color:white;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:1rem;flex-shrink:0">←</button>' +
      '<div style="color:white;font-weight:700;font-size:.95rem;flex:1">🔔 Notificaciones</div>' +
      '<button onclick="window._nxMarkAllRead()" style="background:rgba(255,255,255,.15);border:none;color:white;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:.72rem;white-space:nowrap">✓ Leídas</button>' +
      '<button onclick="window._nxDeleteAll()" style="background:rgba(239,68,68,.4);border:none;color:white;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:.72rem;white-space:nowrap">🗑️ Borrar</button>' +
    '</div>' +
    '<div id="nx-notif-list" style="flex:1;overflow-y:auto;padding:8px 0"></div>';
    document.body.appendChild(panel);

    // Overlay para cerrar
    var overlay = document.createElement('div');
    overlay.id = 'nx-overlay';
    overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:499;background:rgba(0,0,0,.3)';
    overlay.onclick = closeNotifPanel;
    document.body.appendChild(overlay);

    // Exponer funciones globales
    window._nxClosePanel = closeNotifPanel;
    window._nxMarkAllRead = markAllRead;
    window._nxDeleteAll = deleteAll;

    // Ajustar panel en móvil
    function adjustPanel() {
      var p = document.getElementById('nx-notif-panel');
      if (!p) return;
      if (window.innerWidth < 768) {
        p.style.left = '0';
        p.style.width = '100%';
      } else {
        p.style.left = '260px';
        p.style.width = '320px';
      }
    }
    window.addEventListener('resize', adjustPanel);
    adjustPanel();

    // Iniciar polling
    fetchNotifs();
    _pollInterval = setInterval(fetchNotifs, 15000);
  }

  async function fetchNotifs() {
    if (!getToken()) return;
    try {
      var res = await fetch('/api/notifications', { headers: { Authorization: 'Bearer ' + getToken() } });
      var data = await res.json();
      if (!data.success) return;
      var unread = data.data.unread || 0;
      var badge = document.getElementById('nx-badge');
      if (badge) {
        badge.textContent = unread > 99 ? '99+' : unread;
        badge.style.display = unread > 0 ? 'flex' : 'none';
      }
      // Notificación visual si llegó algo nuevo
      if (unread > _lastUnread && _lastUnread !== null && document.visibilityState === 'visible') {
        var bell = document.getElementById('nx-bell');
        if (bell) { bell.style.background = 'rgba(239,68,68,.3)'; setTimeout(function(){ bell.style.background = 'rgba(255,255,255,.1)'; }, 1500); }
      }
      _lastUnread = unread;
      if (_notifOpen) renderNotifs(data.data.notifications || []);
    } catch(e) {}
  }

  function renderNotifs(notifs) {
    var list = document.getElementById('nx-notif-list');
    if (!list) return;
    if (!notifs.length) {
      list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#9ca3af"><div style="font-size:2rem;margin-bottom:8px">🔔</div><div style="font-size:.88rem">No tienes notificaciones</div></div>';
      return;
    }
    list.innerHTML = notifs.map(function(n) {
      var data = n.data ? (typeof n.data === 'string' ? JSON.parse(n.data) : n.data) : {};
      var url = data.url || '#';
      return '<div onclick="window._nxClickNotif('+n.id+',\''+url+'\')" style="padding:14px 20px;border-bottom:1px solid #f3f4f6;cursor:pointer;background:'+(n.is_read?'white':'#f0f7ff')+';transition:.15s" onmouseenter="this.style.background=\'#f9fafb\'" onmouseleave="this.style.background=\''+(n.is_read?'white':'#f0f7ff')+'\'">' +
        '<div style="display:flex;align-items:flex-start;gap:10px">' +
          (n.is_read ? '' : '<div style="width:8px;height:8px;border-radius:50%;background:#3b82f6;flex-shrink:0;margin-top:5px"></div>') +
          '<div style="flex:1">' +
            '<div style="font-size:.85rem;font-weight:'+(n.is_read?'400':'600')+';color:#111;line-height:1.4">'+n.title+'</div>' +
            '<div style="font-size:.78rem;color:#6b7280;margin-top:2px;line-height:1.4">'+n.message+'</div>' +
            '<div style="font-size:.72rem;color:#9ca3af;margin-top:4px">'+timeAgo(n.created_at)+'</div>' +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('');

    window._nxClickNotif = async function(id, url) {
      await fetch('/api/notifications/'+id, { method: 'PUT', headers: { Authorization: 'Bearer ' + getToken() } });
      closeNotifPanel();
      if (url && url !== '#') window.location.href = url;
      else fetchNotifs();
    };
  }

  async function deleteAll() {
    if (!confirm('¿Borrar todas las notificaciones?')) return;
    await fetch('/api/notifications?delete_all=1', { method: 'DELETE', headers: { Authorization: 'Bearer ' + getToken() } });
    var list = document.getElementById('nx-notif-list');
    if (list) list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#9ca3af"><div style="font-size:2rem;margin-bottom:8px">🔔</div><div style="font-size:.88rem">No tienes notificaciones</div></div>';
    _lastUnread = 0;
    var badge = document.getElementById('nx-badge');
    if (badge) badge.style.display = 'none';
  }

  function toggleNotifPanel() {
    _notifOpen ? closeNotifPanel() : openNotifPanel();
  }

  async function openNotifPanel() {
    _notifOpen = true;
    var panel = document.getElementById('nx-notif-panel');
    var overlay = document.getElementById('nx-overlay');
    if (panel) panel.style.display = 'flex';
    if (overlay) overlay.style.display = 'block';
    document.body.style.overflow = 'hidden';
    try {
      var res = await fetch('/api/notifications', { headers: { Authorization: 'Bearer ' + getToken() } });
      var data = await res.json();
      if (data.success) renderNotifs(data.data.notifications || []);
    } catch(e) {}
  }

  function closeNotifPanel() {
    _notifOpen = false;
    var panel = document.getElementById('nx-notif-panel');
    var overlay = document.getElementById('nx-overlay');
    if (panel) panel.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
    document.body.style.overflow = '';
    fetchNotifs();
  }

  async function markAllRead() {
    await fetch('/api/notifications', { method: 'PUT', headers: { Authorization: 'Bearer ' + getToken() } });
    fetchNotifs();
    var list = document.getElementById('nx-notif-list');
    if (list) { var items = list.querySelectorAll('[style*="f0f7ff"]'); items.forEach(function(el){ el.style.background = 'white'; }); }
  }

  // Inicializar cuando el DOM esté listo
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBell);
  } else {
    setTimeout(initBell, 500);
  }
})();
