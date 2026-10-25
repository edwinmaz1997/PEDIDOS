importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");

// Punto/contador en el ícono de la app al recibir una notificación
self.addEventListener('push', function () {
  try { if (self.navigator && self.navigator.setAppBadge) self.navigator.setAppBadge().catch(function(){}); } catch (e) {}
});
