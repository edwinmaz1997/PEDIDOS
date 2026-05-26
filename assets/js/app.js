// ============================================================
// NuevaExpress — Shared Utilities
// ============================================================

const APP = {
  API: '/api',
  token: () => localStorage.getItem('nuevaexpress_token'),
  user: () => JSON.parse(localStorage.getItem('nuevaexpress_user') || '{}'),

  // Auth headers
  authHeaders: () => ({
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${APP.token()}`
  }),

  // Fetch wrapper with auth
  async fetch(endpoint, options = {}) {
    const res = await fetch(APP.API + endpoint, {
      ...options,
      headers: { ...APP.authHeaders(), ...(options.headers || {}) }
    });
    const data = await res.json();
    if (res.status === 401) {
      // No borrar localStorage ni redirigir automaticamente
      // Solo retornar null para que cada pagina maneje el error
      return null;
    }
    return data;
  },

  // GET
  async get(endpoint) {
    return APP.fetch(endpoint);
  },

  // POST
  async post(endpoint, body) {
    return APP.fetch(endpoint, { method: 'POST', body: JSON.stringify(body) });
  },

  // PUT
  async put(endpoint, body) {
    return APP.fetch(endpoint, { method: 'PUT', body: JSON.stringify(body) });
  },

  // DELETE
  async delete(endpoint) {
    return APP.fetch(endpoint, { method: 'DELETE' });
  },

  // Upload file
  async upload(file, folder = 'general') {
    const form = new FormData();
    form.append('image', file);
    form.append('folder', folder);
    const res = await fetch(APP.API + '/upload', {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${APP.token()}` },
      body: form
    });
    return res.json();
  },

  // Escape HTML
  esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  },

  // Format currency
  money(amount) {
    return 'Q' + parseFloat(amount || 0).toFixed(2);
  },

  // Format date
  date(str) {
    if (!str) return '';
    return new Date(str).toLocaleString('es-GT', {
      timeZone: 'America/Guatemala',
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  },

  // Toast notification
  toast(msg, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
      document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    const colors = { success: '#1db954', error: '#e63946', info: '#1a56db', warning: '#ffb703' };
    toast.style.cssText = `background:${colors[type]||colors.info};color:white;padding:12px 20px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.9rem;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .2s ease;max-width:320px;`;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  },

  // Status labels & badges
  statusLabel: {
    pendiente: '⏳ Pendiente', aceptado: '✅ Aceptado',
    en_preparacion: '👨‍🍳 En preparación', listo: '📦 Listo',
    en_camino: '🛵 En camino', entregado: '✔ Entregado', cancelado: '✕ Cancelado'
  },
  statusBadge: {
    pendiente: 'badge-pending', aceptado: 'badge-accepted',
    en_preparacion: 'badge-delivery', listo: 'badge-active',
    en_camino: 'badge-delivery', entregado: 'badge-done', cancelado: 'badge-cancel'
  },

  // Check auth & role
  requireAuth(allowedRoles = []) {
    const token = APP.token();
    const user  = APP.user();
    if (!token) { window.location.href = '/cliente/login.html'; return false; }
    if (allowedRoles.length && !allowedRoles.includes(user.role)) {
      window.location.href = `/${user.role}/index.html`;
      return false;
    }
    return true;
  },

  logout() {
    APP.fetch('/auth/logout', { method: 'POST' }).catch(() => {});
    localStorage.clear();
    window.location.href = '/cliente/login.html';
  }
};

// Inject toast keyframe
const style = document.createElement('style');
style.textContent = `@keyframes slideIn { from { opacity:0; transform:translateX(40px); } to { opacity:1; transform:none; } }`;
document.head.appendChild(style);
