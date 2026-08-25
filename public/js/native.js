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

  // Primera versión del apk del conductor que trae el timbre propio en res/raw.
  var BUILD_TIMBRE_PROPIO = 3;
  var appBuild = 0;

  function postToken(token) {
    if (!token) return;
    fetch(base + '/api/push/fcm-token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      // El servidor necesita la versión para saber si este celular ya tiene el mp3 del
      // timbre. Un canal que apunta a un sonido que no está en el apk sale MUDO.
      body: JSON.stringify({ token: token, build: appBuild }),
    }).catch(function () {});
  }

  // 1) Permiso de ubicación: necesario para que navigator.geolocation funcione en la app nativa.
  try {
    if (P.Geolocation && P.Geolocation.requestPermissions) {
      P.Geolocation.requestPermissions().catch(function () {});
    }
  } catch (e) {}

  // 2) Notificaciones push nativas (FCM).
  //    Primero se averigua la VERSIÓN instalada, porque de ella depende qué canal crear:
  //    el timbre propio viaja dentro del apk y en las versiones viejas no existe.
  function leerVersion() {
    try {
      if (P.App && P.App.getInfo) {
        return P.App.getInfo().then(function (info) {
          appBuild = parseInt((info && info.build) || '0', 10) || 0;
        }).catch(function () {});
      }
    } catch (e) {}
    return Promise.resolve();
  }

  function iniciarPush() {
    try {
      var PN = P.PushNotifications;
      if (!PN) return;

      // Canal Android con sonido + vibración (coincide con channel_id del servidor).
      if (PN.createChannel) {
        PN.createChannel({
          id: 'majesgo_viajes', name: 'Viajes MajesGo',
          description: 'Alertas de nuevos viajes y estado del viaje',
          importance: 5, visibility: 1, sound: 'default', vibration: true, lights: true,
        }).catch(function () {});

        // Timbre propio de MajesGo. Sólo en la app del conductor y sólo desde la versión
        // que lleva el mp3: crearlo sin el archivo dejaría el aviso sin sonido.
        // Va en un canal con id NUEVO porque los ajustes de un canal quedan congelados
        // al crearse — cambiarle el sonido a 'majesgo_viajes' no haría nada.
        if (isDriver && appBuild >= BUILD_TIMBRE_PROPIO) {
          PN.createChannel({
            id: 'majesgo_viajes_v2', name: 'Viajes MajesGo',
            description: 'Alertas de nuevos viajes y estado del viaje',
            importance: 5, visibility: 1, sound: 'nuevo_viaje', vibration: true, lights: true,
          }).catch(function () {});
        }

        // Canal MUDO: sirve para apagar un aviso que quedó sonando (mismo tag lo reemplaza)
        // sin volver a molestar al conductor. Sin él, decirle "ya la tomó otro" sonaría
        // igual de fuerte que la carrera que ya perdió.
        PN.createChannel({
          id: 'majesgo_avisos', name: 'Avisos silenciosos',
          description: 'Cambios de estado que no necesitan sonar',
          importance: 2, visibility: 1, vibration: false, lights: false,
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
    } catch (e) {}
  }

  leerVersion().then(iniciarPush);
})();
