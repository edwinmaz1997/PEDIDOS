Host not in allowlist: cdn.onesignal.com. Add this host to your network egress settings to allow access.
// Punto/contador en el ícono de la app al recibir una notificación
self.addEventListener('push', function () {
  try { if (self.navigator && self.navigator.setAppBadge) self.navigator.setAppBadge().catch(function(){}); } catch (e) {}
});
