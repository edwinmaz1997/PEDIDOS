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

  function getToken() { return localStorage.getItem('nuevaexpress_token'); }

  function timeAgo(dateStr) {
    var d = new Date(dateStr.replace(' ','T'));
    var diff = Math.floor((Date.now()-d)/1000);
    if (diff<60) return 'ahora';
    if (diff<3600) return Math.floor(diff/60)+' min';
    if (diff<86400) return Math.floor(diff/3600)+'h';
    return Math.floor(diff/86400)+'d';
  }

  function getUrl(n) {
    try {
      var data = n.data ? (typeof n.data==='string' ? JSON.parse(n.data) : n.data) : {};
      return data.url || null;
    } catch(e) { return null; }
  }

  function typeIcon(type) {
    var icons = { new_order:'🛒', order_update:'📦', new_message:'💬', promotion:'🏷️', total_updated:'💰' };
    return icons[type] || '🔔';
  }

  function initBell() {
    if (!getToken()) return;
    var sidebarLogo = document.querySelector('.sidebar-logo');
    if (!sidebarLogo || document.getElementById('nx-bell')) return;

    // Fila de íconos: campanita + perfil (si es cliente)
    var iconRow = document.createElement('div');
    iconRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:10px';
    sidebarLogo.appendChild(iconRow);

    // Campanita
    var bell = document.createElement('button');
    bell.id = 'nx-bell';
    bell.onclick = togglePanel;
    bell.innerHTML = '<span style="font-size:1.1rem">🔔</span><span id="nx-badge" style="display:none;position:absolute;top:-4px;right:-4px;background:#ef4444;color:white;font-size:.6rem;font-weight:700;min-width:18px;height:18px;border-radius:20px;align-items:center;justify-content:center;padding:0 4px;border:2px solid #1a1a2e">0</span>';
    bell.style.cssText = 'position:relative;background:rgba(255,255,255,.12);border:none;border-radius:50%;width:38px;height:38px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.2s;flex-shrink:0';
    bell.onmouseenter = function(){ this.style.background='rgba(255,255,255,.2)'; };
    bell.onmouseleave = function(){ this.style.background='rgba(255,255,255,.12)'; };
    iconRow.appendChild(bell);

    // Ícono de perfil — solo para clientes
    var currentUser = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}');
    if (currentUser.role === 'cliente') {
      var profileBtn = document.createElement('a');
      profileBtn.href = '/cliente/perfil.html';
      profileBtn.title = 'Mi Perfil';
      profileBtn.style.cssText = 'background:rgba(255,255,255,.12);border-radius:50%;width:38px;height:38px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;text-decoration:none;flex-shrink:0;transition:.2s';
      profileBtn.innerHTML = '👤';
      profileBtn.onmouseenter = function(){ this.style.background='rgba(255,255,255,.2)'; };
      profileBtn.onmouseleave = function(){ this.style.background='rgba(255,255,255,.12)'; };
      iconRow.appendChild(profileBtn);
    } // end if cliente

    // Panel
    var panel = document.createElement('div');
    panel.id = 'nx-panel';
    panel.style.cssText = 'display:none;position:fixed;inset:0;z-index:600;';
    panel.innerHTML =
      '<div id="nx-overlay" onclick="window._nxClose()" style="position:absolute;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(2px)"></div>'+
      '<div id="nx-drawer" style="position:absolute;right:0;top:0;height:100%;width:min(380px,100vw);background:#f8fafc;display:flex;flex-direction:column;box-shadow:-4px 0 30px rgba(0,0,0,.2);transform:translateX(100%);transition:transform .25s cubic-bezier(.4,0,.2,1)">'+
        '<div style="background:linear-gradient(135deg,#1a1a2e,#2d3a6e);padding:16px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0">'+
          '<button onclick="window._nxClose()" style="background:rgba(255,255,255,.15);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">←</button>'+
          '<div style="flex:1;color:white;font-weight:700;font-size:1rem">Notificaciones</div>'+
          '<div style="display:flex;gap:6px">'+
            '<button onclick="window._nxMarkAll()" style="background:rgba(255,255,255,.15);border:none;color:white;padding:6px 10px;border-radius:8px;cursor:pointer;font-size:.72rem;font-family:inherit">✓ Leídas</button>'+
            '<button onclick="window._nxDeleteAll()" style="background:rgba(239,68,68,.4);border:none;color:white;padding:6px 10px;border-radius:8px;cursor:pointer;font-size:.72rem;font-family:inherit">🗑️ Borrar</button>'+
          '</div>'+
        '</div>'+
        '<div id="nx-list" style="flex:1;overflow-y:auto"></div>'+
      '</div>';
    document.body.appendChild(panel);

    window._nxClose = closePanel;
    window._nxMarkAll = markAll;
    window._nxDeleteAll = deleteAll;

    fetchNotifs();
  }

  // Polling independiente — siempre arranca aunque no haya sidebar
  function startPolling() {
    if (!getToken()) return;
    fetchNotifs();
    setInterval(fetchNotifs, 10000);
  }

  async function fetchNotifs() {
    if (!getToken()) return;
    try {
      var res = await fetch('/api/notifications', { headers: { Authorization: 'Bearer '+getToken() } });
      var data = await res.json();
      if (!data.success) return;
      var unread = data.data.unread || 0;
      var badge = document.getElementById('nx-badge');
      if (badge) { badge.textContent = unread>99?'99+':unread; badge.style.display = unread>0?'flex':'none'; }
      if (unread > _lastUnread && _lastUnread !== null) {
        var bell = document.getElementById('nx-bell');
        if (bell) { bell.style.background='rgba(239,68,68,.3)'; setTimeout(function(){ bell.style.background='rgba(255,255,255,.12)'; },1500); }
      }
      _lastUnread = unread;
      if (_notifOpen) renderList(data.data.notifications || []);
    } catch(e) {}
  }

  function renderList(notifs) {
    var list = document.getElementById('nx-list');
    if (!list) return;
    if (!notifs.length) {
      list.innerHTML = '<div style="padding:60px 24px;text-align:center"><div style="font-size:2.5rem;margin-bottom:12px">🔔</div><div style="color:#6b7280;font-size:.9rem">Sin notificaciones</div></div>';
      return;
    }
    list.innerHTML = notifs.map(function(n) {
      var url = getUrl(n);
      var icon = typeIcon(n.type);
      var bg = n.is_read ? 'white' : '#eff6ff';
      var borderL = n.is_read ? 'transparent' : '#3b82f6';
      return '<div class="nx-item" data-id="'+n.id+'" data-url="'+(url ? url.replace(/"/g,'&quot;') : '')+'" '+
        'style="display:flex;gap:14px;padding:16px 20px;border-bottom:1px solid #f1f5f9;background:'+bg+';border-left:3px solid '+borderL+';cursor:pointer;transition:.15s">' +
        '<div style="width:40px;height:40px;border-radius:50%;background:'+(n.is_read?'#f1f5f9':'#dbeafe')+';display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">'+icon+'</div>'+
        '<div style="flex:1;min-width:0">'+
          '<div style="font-size:.85rem;font-weight:'+(n.is_read?'400':'600')+';color:#0f172a;line-height:1.4;margin-bottom:3px">'+n.title+'</div>'+
          '<div style="font-size:.78rem;color:#64748b;line-height:1.4;margin-bottom:4px">'+n.message+'</div>'+
          '<div style="font-size:.7rem;color:#94a3b8">'+timeAgo(n.created_at)+'</div>'+
        '</div>'+
        (url ? '<div style="color:#94a3b8;font-size:.9rem;align-self:center">›</div>' : '')+
      '</div>';
    }).join('');

    // Adjuntar listeners en lugar de onclick inline (evita problemas con comillas en URL)
    list.querySelectorAll('.nx-item').forEach(function(item) {
      item.addEventListener('click', async function() {
        var id = this.dataset.id;
        var url = this.dataset.url;
        await fetch('/api/notifications/'+id, { method:'PUT', headers:{ Authorization:'Bearer '+getToken() } });
        closePanel();
        if (url) window.location.href = url;
        else fetchNotifs();
      });
    });
  }

  function togglePanel() { _notifOpen ? closePanel() : openPanel(); }

  async function openPanel() {
    _notifOpen = true;
    var panel = document.getElementById('nx-panel');
    var drawer = document.getElementById('nx-drawer');
    if (!panel) return;
    panel.style.display = 'block';
    document.body.style.overflow = 'hidden';
    setTimeout(function(){ if(drawer) drawer.style.transform='translateX(0)'; }, 10);
    try {
      var res = await fetch('/api/notifications', { headers:{ Authorization:'Bearer '+getToken() } });
      var data = await res.json();
      if (data.success) renderList(data.data.notifications || []);
    } catch(e) {}
  }

  function closePanel() {
    _notifOpen = false;
    var drawer = document.getElementById('nx-drawer');
    var panel = document.getElementById('nx-panel');
    if (drawer) drawer.style.transform = 'translateX(100%)';
    setTimeout(function(){
      if (panel) panel.style.display = 'none';
      document.body.style.overflow = '';
    }, 260);
    fetchNotifs();
  }

  async function markAll() {
    await fetch('/api/notifications', { method:'PUT', headers:{ Authorization:'Bearer '+getToken() } });
    fetchNotifs();
  }

  async function deleteAll() {
    if (!confirm('¿Borrar todas las notificaciones?')) return;
    await fetch('/api/notifications', { method:'DELETE', headers:{ Authorization:'Bearer '+getToken() } });
    var list = document.getElementById('nx-list');
    if (list) list.innerHTML = '<div style="padding:60px 24px;text-align:center"><div style="font-size:2.5rem;margin-bottom:12px">🔔</div><div style="color:#6b7280;font-size:.9rem">Sin notificaciones</div></div>';
    _lastUnread = 0;
    var badge = document.getElementById('nx-badge');
    if (badge) badge.style.display = 'none';
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ initBell(); startPolling(); });
  } else {
    setTimeout(initBell, 500);
    startPolling();
  }
})();
// ── Sistema de actualización de app ─────────────────────────
(function() {
  var APP_VERSION = '1.0.8'; // Incrementar en cada deploy importante
  var stored = localStorage.getItem('nx_app_version');

  function forceUpdateFn() {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations().then(function(regs) {
        regs.forEach(function(r){ r.unregister(); });
      });
    }
    // Limpiar cache del navegador
    if ('caches' in window) {
      caches.keys().then(function(names) {
        names.forEach(function(n){ caches.delete(n); });
      });
    }
    localStorage.setItem('nx_app_version', APP_VERSION);
    window.location.reload(true);
  }
  window.forceUpdate = forceUpdateFn;

  // Marcar botón en rojo si hay versión nueva
  function checkVersion() {
    var btn = document.getElementById('updateAppBtn');
    if (!btn) return;
    if (!stored || stored !== APP_VERSION) {
      btn.style.color = '#ef4444';
      btn.style.fontWeight = '700';
      btn.textContent = '🔴 Actualizar app';
    } else {
      btn.style.color = 'rgba(255,255,255,.5)';
      btn.style.fontWeight = '400';
      btn.textContent = '🔄 Actualizar app';
    }
  }

  // Escuchar mensaje del SW cuando hay nueva versión
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(e) {
      if (e.data && e.data.type === 'SW_UPDATED') {
        localStorage.removeItem('nx_app_version');
        var btn = document.getElementById('updateAppBtn');
        if (btn) {
          btn.style.color = '#ef4444';
          btn.style.fontWeight = '700';
          btn.textContent = '🔴 Actualizar app';
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkVersion);
  } else {
    setTimeout(checkVersion, 600);
  }
})();
