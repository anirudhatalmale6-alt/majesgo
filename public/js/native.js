/* MajesGo — puente nativo (solo se activa dentro de la app nativa de Play Store, vía Capacitor).
   En el navegador web normal no hace nada (retorna de inmediato). */
(function () {
  'use strict';
  var Cap = window.Capacitor;
  if (!Cap || typeof Cap.isNativePlatform !== 'function' || !Cap.isNativePlatform()) return;

  var P = Cap.Plugins || {};
  var isDriver = location.pathname.indexOf('/conductor') === 0;
  var base = isDriver ? '/conductor' : '/app';
  var csrf = (window.MG && MG.csrf) || (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  function postToken(token) {
    if (!token) return;
    fetch(base + '/api/push/fcm-token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ token: token }),
    }).catch(function () {});
  }

  // 1) Permiso de ubicación: necesario para que navigator.geolocation funcione en la app nativa.
  try {
    if (P.Geolocation && P.Geolocation.requestPermissions) {
      P.Geolocation.requestPermissions().catch(function () {});
    }
  } catch (e) {}

  // 2) Notificaciones push nativas (FCM).
  try {
    var PN = P.PushNotifications;
    if (PN) {
      // Canal Android con sonido + vibración (coincide con channel_id del servidor).
      if (PN.createChannel) {
        PN.createChannel({
          id: 'majesgo_viajes', name: 'Viajes MajesGo',
          description: 'Alertas de nuevos viajes y estado del viaje',
          importance: 5, visibility: 1, sound: 'default', vibration: true, lights: true,
        }).catch(function () {});
      }
      PN.addListener('registration', function (t) { postToken(t && t.value); });
      PN.addListener('registrationError', function () {});
      // Al tocar la notificación, la app se abre (WebView ya está en la app correcta).
      PN.addListener('pushNotificationActionPerformed', function () {});

      PN.checkPermissions().then(function (res) {
        if (res && res.receive === 'granted') return PN.register();
        return PN.requestPermissions().then(function (r) {
          if (r && r.receive === 'granted') return PN.register();
        });
      }).catch(function () {});
    }
  } catch (e) {}
})();
