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
    'font-family:Plus Jakarta Sans,system-ui,Segoe UI,Roboto,sans-serif',
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

// ── Menú lateral moderno (todos los paneles) ──────────────────
(function() {
  var I = {
    dashboard:'<rect width="7" height="9" x="3" y="3" rx="1.5"/><rect width="7" height="5" x="14" y="3" rx="1.5"/><rect width="7" height="9" x="14" y="12" rx="1.5"/><rect width="7" height="5" x="3" y="16" rx="1.5"/>',
    orders:'<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
    bag:'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    tag:'<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor"/>',
    megaphone:'<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    chart:'<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    user:'<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    users:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    settings:'<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    store:'<path d="M3 9.5 4.5 4h15L21 9.5"/><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"/><path d="M10 21v-5h4v5"/>',
    grid:'<rect width="7" height="7" x="3" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="14" rx="1.5"/><rect width="7" height="7" x="3" y="14" rx="1.5"/>',
    map:'<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15"/><path d="M9 3.236v15"/>',
    search:'<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    wallet:'<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    bike:'<circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/>',
    wrench:'<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    box:'<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    plus:'<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/>',
    facebook:'<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    tiktok:'<path d="M9 12a4 4 0 1 0 4 4V3c0 2.5 2.5 5 6 5"/>',
    refresh:'<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    logout:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    trash:'<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    bell:'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    menu:'<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h10"/>',
    close:'<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    chevron:'<path d="m9 18 6-6-6-6"/>'
  };
  window.NX_ICONS = I;
  function svg(k){ return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(I[k]||I.chevron)+'</svg>'; }

  function pickIcon(label, raw) {
    var t = label.toLowerCase();
    if (t.indexOf('dashboard') > -1) return 'dashboard';
    if (t.indexOf('pedidos') > -1) return 'orders';
    if (t.indexOf('promoci') > -1) return 'tag';
    if (t.indexOf('anuncio') > -1 || t.indexOf('aviso') > -1) return 'megaphone';
    if (t.indexOf('reporte') > -1) return 'chart';
    if (t.indexOf('crear negocio') > -1) return 'plus';
    if (t.indexOf('negocio') > -1 && t.indexOf('pago') > -1) return 'wallet';
    if (t.indexOf('pago') > -1) return 'wallet';
    if (t.indexOf('negocio') > -1) return 'store';
    if (t.indexOf('usuario') > -1) return 'users';
    if (t.indexOf('categor') > -1) return 'grid';
    if (t.indexOf('zona') > -1) return 'map';
    if (t.indexOf('busca') > -1) return 'search';
    if (t.indexOf('entrega') > -1) return 'bike';
    if (t.indexOf('servicio') > -1) return 'wrench';
    if (t.indexOf('producto') > -1) return raw.indexOf('📦') > -1 ? 'box' : 'bag';
    if (t.indexOf('facebook') > -1) return 'facebook';
    if (t.indexOf('actualiz') > -1) return 'refresh';
    if (t.indexOf('reset') > -1) return 'trash';
    if (t.indexOf('salir') > -1 || t.indexOf('cerrar') > -1) return 'logout';
    if (t.indexOf('perfil') > -1) {
      if (raw.indexOf('⚙') > -1) return 'settings';
      if (raw.indexOf('🏪') > -1) return 'store';
      return 'user';
    }
    return 'chevron';
  }

  function cleanLabel(raw) {
    return raw.replace(/^[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9]+/, '').trim();
  }

  var ROLE = { cliente:'Cliente', negocio:'Negocio', repartidor:'Repartidor', admin:'Administrador' };

  function enhance() {
    var sb = document.querySelector('.sidebar');
    if (!sb || sb.classList.contains('sb-v2')) return;
    sb.classList.add('sb-v2');
    var user = {};
    try { user = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}'); } catch(e) {}

    // ── Encabezado ──
    var head = sb.querySelector('.sidebar-logo');
    if (head) {
      var logo = head.querySelector('.logo');
      var nameEl = null;
      Array.prototype.forEach.call(head.children, function(c){ if (!nameEl && c !== logo && c.tagName === 'DIV') nameEl = c; });
      if (logo) {
        var close = document.createElement('button');
        close.type = 'button'; close.className = 'sb-close'; close.setAttribute('aria-label','Cerrar menú');
        close.innerHTML = svg('close');
        close.onclick = function(){ if (typeof window.toggleSidebar === 'function') window.toggleSidebar(); else sb.classList.remove('open'); };
        var brand = document.createElement('div');
        brand.className = 'sb-brand';
        head.insertBefore(brand, logo);
        brand.appendChild(logo);
        brand.appendChild(close);
      }
      var roleTxt = ROLE[user.role] || '';
      var card = document.createElement('div');
      card.className = 'sb-user';
      var av = document.createElement('div'); av.className = 'sb-avatar';
      var meta = document.createElement('div'); meta.className = 'sb-meta';
      if (!nameEl) { nameEl = document.createElement('div'); }
      nameEl.removeAttribute('style');
      nameEl.classList.add('sb-name');
      if (!nameEl.id && user.name && nameEl.textContent.trim() === roleTxt) nameEl.textContent = user.name;
      var role = document.createElement('div'); role.className = 'sb-role'; role.textContent = roleTxt || 'NuevaExpress';
      meta.appendChild(nameEl); meta.appendChild(role);
      card.appendChild(av); card.appendChild(meta);
      var quick = document.createElement('div'); quick.className = 'sb-quick';
      card.appendChild(quick);
      head.appendChild(card);
      function syncAvatar(){
        var n = (nameEl.textContent || user.name || 'N').trim();
        av.textContent = (n.charAt(0) || 'N').toUpperCase();
      }
      syncAvatar();
      try { new MutationObserver(syncAvatar).observe(nameEl, { childList:true, characterData:true, subtree:true }); } catch(e) {}
    }

    // ── Navegación ──
    var nav = sb.querySelector('.sidebar-nav');
    if (nav) {
      var here = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
      var foot = document.createElement('ul');
      foot.className = 'sidebar-nav sb-foot';
      Array.prototype.slice.call(nav.querySelectorAll(':scope > li')).forEach(function(li){
        var a = li.querySelector('a');
        if (!a) return;
        ['marginTop','paddingTop','borderTop'].forEach(function(p){ li.style[p] = ''; });
        var raw = a.textContent;
        var label = cleanLabel(raw);
        var key = pickIcon(label, raw);
        a.removeAttribute('style');
        a.innerHTML = '<span class="sb-ic">'+svg(key)+'</span><span class="sb-label"></span>';
        a.querySelector('.sb-label').textContent = label;
        var href = (a.getAttribute('href') || '').split('?')[0].split('/').pop().toLowerCase();
        if (href && href !== '#' && href === here) a.classList.add('active');
        var oc = a.getAttribute('onclick') || '';
        if (key === 'logout' || oc.indexOf('doLogout') > -1) { a.classList.add('sb-danger'); foot.appendChild(li); }
        else if (key === 'trash' || oc.indexOf('openReset') > -1) { a.classList.add('sb-danger','sb-muted'); foot.appendChild(li); }
        else if (a.id === 'updateAppBtn') { foot.insertBefore(li, foot.firstChild); }
      });
      if (foot.children.length) nav.parentNode.insertBefore(foot, nav.nextSibling);
      if (!nav.querySelector('li')) nav.style.display = 'none';
    }

    // ── Botón hamburguesa ──
    var mb = document.querySelector('.mobile-menu-btn');
    if (mb) { mb.innerHTML = svg('menu'); mb.setAttribute('aria-label','Abrir menú'); }
    function syncOpen(){ document.body.classList.toggle('sb-open', sb.classList.contains('open')); }
    try { new MutationObserver(syncOpen).observe(sb, { attributes:true, attributeFilter:['class'] }); } catch(e) {}
    sb.addEventListener('click', function(e){
      var a = e.target.closest && e.target.closest('.sidebar-nav a');
      if (a && sb.classList.contains('open') && a.getAttribute('href') && a.getAttribute('href') !== '#' && a.target !== '_blank') {
        sb.classList.remove('open');
        var o = document.querySelector('.sidebar-overlay'); if (o) o.classList.remove('open');
      }
    });
  }
  window.nxEnhanceSidebar = enhance;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance);
  else enhance();
})();

function _nxEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
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

    if (window.nxEnhanceSidebar) window.nxEnhanceSidebar();
    var I = window.NX_ICONS || {};
    function svg(k){ return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(I[k]||'')+'</svg>'; }

    // Fila de accesos rápidos: campanita + perfil/redes (cliente)
    var iconRow = sidebarLogo.querySelector('.sb-quick');
    if (!iconRow) { iconRow = document.createElement('div'); iconRow.className = 'sb-quick'; sidebarLogo.appendChild(iconRow); }

    var bell = document.createElement('button');
    bell.id = 'nx-bell';
    bell.className = 'sb-qbtn';
    bell.type = 'button';
    bell.title = 'Notificaciones';
    bell.onclick = togglePanel;
    bell.innerHTML = svg('bell')+'<span id="nx-badge" class="sb-badge" style="display:none">0</span>';
    iconRow.appendChild(bell);

    var currentUser = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}');
    if (currentUser.role === 'cliente') {
      [
        { href:'/cliente/perfil.html', title:'Mi Perfil', icon:'user', cls:'' },
        { href:'https://www.facebook.com/share/18Fbqca6CW/', title:'Facebook', icon:'facebook', cls:' sb-qbtn--fb', ext:true },
        { href:'https://www.tiktok.com/@nuevaexpress', title:'TikTok', icon:'tiktok', cls:' sb-qbtn--tt', ext:true }
      ].forEach(function(q){
        var a = document.createElement('a');
        a.href = q.href; a.title = q.title; a.className = 'sb-qbtn'+q.cls;
        if (q.ext) { a.target = '_blank'; a.rel = 'noopener'; }
        a.innerHTML = svg(q.icon);
        iconRow.appendChild(a);
      });
    }

    // Panel
    var panel = document.createElement('div');
    panel.id = 'nx-panel';
    panel.style.cssText = 'display:none;position:fixed;inset:0;z-index:1300;';
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
        if (bell) { bell.classList.add('sb-qbtn--ping'); setTimeout(function(){ bell.classList.remove('sb-qbtn--ping'); },1500); }
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
          '<div style="font-size:.85rem;font-weight:'+(n.is_read?'400':'600')+';color:#0f172a;line-height:1.4;margin-bottom:3px">'+_nxEsc(n.title)+'</div>'+
          '<div style="font-size:.78rem;color:#64748b;line-height:1.4;margin-bottom:4px">'+_nxEsc(n.message)+'</div>'+
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
  var APP_VERSION = '1.0.13'; // Incrementar en cada deploy importante
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
  function setUpdateState(btn, pending) {
    if (!btn) return;
    btn.classList.toggle('sb-has-update', !!pending);
    btn.removeAttribute('style');
    var label = btn.querySelector('.sb-label');
    var txt = pending ? 'Nueva versión' : 'Actualizar app';
    if (label) label.textContent = txt; else btn.textContent = txt;
  }
  function checkVersion() {
    var btn = document.getElementById('updateAppBtn');
    if (!btn) return;
    setUpdateState(btn, !stored || stored !== APP_VERSION);
  }

  // Escuchar mensaje del SW cuando hay nueva versión
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(e) {
      if (e.data && e.data.type === 'SW_UPDATED') {
        localStorage.removeItem('nx_app_version');
        setUpdateState(document.getElementById('updateAppBtn'), true);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkVersion);
  } else {
    setTimeout(checkVersion, 600);
  }
})();

// ── Admin viendo como otro usuario: barra para volver ─────────
(function () {
  var raw = null;
  try { raw = localStorage.getItem('nx_admin_backup'); } catch (e) {}
  if (!raw) return;
  var bk; try { bk = JSON.parse(raw); } catch (e) { return; }
  var cur = {}; try { cur = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}'); } catch (e) {}
  if (!bk || !bk.token || !cur.role || !localStorage.getItem('nuevaexpress_token') || cur.role === 'admin') { if (cur.role === 'admin') try { localStorage.removeItem('nx_admin_backup'); } catch (e) {} return; }

  function goBack() {
    var tok = localStorage.getItem('nuevaexpress_token');
    // Cerrar la sesión temporal del negocio
    try { fetch('/api/auth/logout', { method: 'POST', headers: { Authorization: 'Bearer ' + tok } }); } catch (e) {}
    localStorage.setItem('nuevaexpress_token', bk.token);
    if (bk.user) localStorage.setItem('nuevaexpress_user', bk.user);
    localStorage.removeItem('nx_admin_backup');
    window.location.href = bk.back || '/admin/negocios.html';
  }
  window.nxBackToAdmin = goBack;

  function show() {
    if (document.getElementById('nxImpBar')) return;
    var bar = document.createElement('div');
    bar.id = 'nxImpBar';
    bar.style.cssText = 'position:fixed;left:0;right:0;margin:0 auto;width:max-content;bottom:calc(76px + env(safe-area-inset-bottom));z-index:3000;display:flex;align-items:center;gap:10px;padding:8px 8px 8px 14px;border-radius:999px;background:#0f172a;color:#fff;box-shadow:0 14px 30px -12px rgba(15,23,42,.7);font:600 .8rem "Plus Jakarta Sans",system-ui,sans-serif;max-width:calc(100vw - 24px)';
    var name = String(cur.name || 'usuario').replace(/[<>&"]/g, '');
    bar.innerHTML = '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Viendo como <b>' + name + '</b></span>' +
      '<button type="button" style="flex-shrink:0;height:32px;padding:0 14px;border:0;border-radius:999px;background:#fff;color:#0f172a;font:800 .78rem inherit;font-family:inherit;cursor:pointer">Volver al admin</button>';
    bar.querySelector('button').onclick = goBack;
    document.body.appendChild(bar);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show); else show();
})();
