// ============================================================
// NuevaExpress — Interfaz del cliente (v2)
// Barra superior con saludo + campanita y barra inferior con íconos.
// ============================================================
(function () {
  var P = {
    orders: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
    tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor"/>',
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
    chart: '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    megaphone: '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'
  };
  function svg(k) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (P[k] || '') + '</svg>'; }
  window.cxSvg = svg;

  var TITLES = {
    index: 'Tus pedidos', promociones: 'Promociones', reportes: 'Mis reportes',
    anuncios: 'Anuncios', perfil: 'Mi perfil', 'pedido-detalle': 'Detalle del pedido'
  };

  function init() {
    if (document.body.classList.contains('cx')) return;
    document.body.classList.add('cx');
    var page = (location.pathname.split('/').pop() || 'index.html').replace('.html', '') || 'index';
    var user = {};
    try { user = JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}'); } catch (e) {}
    var first = String(user.name || '').trim().split(/\s+/)[0] || '';

    // ── Barra superior ──
    var top = document.createElement('header');
    top.className = 'cx-top';
    var hello = '<div class="cx-hello"><small>' + (first ? 'Hola, ' + first.replace(/[<>&"]/g, '') : 'NuevaExpress') + '</small><strong>' + (TITLES[page] || document.title.split('—')[0].trim()) + '</strong></div>';
    top.innerHTML = hello + '<button type="button" class="cx-icon-btn" id="cxBell" aria-label="Notificaciones">' + svg('bell') + '<span class="sb-badge" id="cxBadge" style="display:none">0</span></button>';
    var mb = document.querySelector('.mobile-menu-btn');
    if (mb) top.insertBefore(mb, top.firstChild);
    document.body.insertBefore(top, document.body.firstChild);
    document.getElementById('cxBell').onclick = function () {
      var b = document.getElementById('nx-bell');
      if (b) b.click();
    };
    setInterval(function () {
      var src = document.getElementById('nx-badge'), dst = document.getElementById('cxBadge');
      if (!src || !dst) return;
      dst.textContent = src.textContent;
      dst.style.display = src.style.display === 'none' ? 'none' : 'flex';
    }, 1000);
    function onScroll() { top.classList.toggle('scrolled', window.scrollY > 6); }
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

    // ── Barra inferior ──
    var ICON = { index: 'orders', promociones: 'tag', directorio: 'home', reportes: 'chart', anuncios: 'megaphone' };
    var firstItem = document.querySelector('.bnav-item');
    if (firstItem && firstItem.parentNode) firstItem.parentNode.classList.add('cx-bnav');
    document.querySelectorAll('.bnav-item').forEach(function (a) {
      var ic = a.querySelector('.bnav-icon');
      if (ic && ICON[a.dataset.page]) ic.innerHTML = svg(ICON[a.dataset.page]);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
