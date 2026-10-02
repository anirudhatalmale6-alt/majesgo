/* Majes Drive — puente nativo (solo se activa dentro de la app nativa de Play Store, vía Capacitor).
   En el navegador web normal no hace nada (retorna de inmediato). */
(function () {
  'use strict';
  var Cap = window.Capacitor;
  if (!Cap || typeof Cap.isNativePlatform !== 'function' || !Cap.isNativePlatform()) return;

  var MG = (window.MG = window.MG || {});
  var P = Cap.Plugins || {};
  var isDriver = location.pathname.indexOf('/conductor') === 0;
  var base = isDriver ? '/conductor' : '/app';
  var csrf = MG.csrf || (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  // null = la app todavía no averiguó si hay sesión. Importa porque el registro del token y
  // la respuesta de api/me llegan en cualquier orden: lo que pase primero espera al otro.
  var sesionAbierta = null;

  // Primera versión de CADA apk que trae su timbre propio en res/raw. Son dos números
  // distintos a propósito: las dos apps se versionan por separado y que hoy coincidan en 3
  // es casualidad. Si se escribiera uno solo, el día que una se adelante la otra quedaría
  // pidiendo un sonido que su apk no tiene, y ese aviso sale MUDO.
  var BUILD_TIMBRE = { conductor: 3, pasajero: 3 };
  var BUILD_TIMBRE_PROPIO = isDriver ? BUILD_TIMBRE.conductor : BUILD_TIMBRE.pasajero;
  // Cada app tiene su sonido: el del conductor avisa una carrera nueva y es insistente;
  // el del pasajero avisa que su taxi ya viene y es más corto.
  var SONIDO_PROPIO = isDriver ? 'nuevo_viaje' : 'viaje_confirmado';
  var appBuild = 0;

  function postToken(token) {
    if (!token) return;
    // Se guarda para poder SOLTARLO después. Sin esto el celular no sabe qué pedirle al
    // servidor que borre, y el token queda atado a la cuenta para siempre.
    MG.pushToken = token;
    fetch(base + '/api/push/fcm-token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      // El servidor necesita la versión para saber si este celular ya tiene el mp3 del
      // timbre. Un canal que apunta a un sonido que no está en el apk sale MUDO.
      body: JSON.stringify({ token: token, build: appBuild }),
    }).catch(function () {});
  }

  /**
   * Dejar de recibir los avisos de la cuenta que estaba en este celular.
   *
   * Sin esto, el celular seguía recibiendo "encontré conductor" y "tu conductor ya llegó" de
   * una cuenta que ya no está abierta ahí — con el nombre del conductor y la dirección del
   * recojo adentro.
   */
  function soltar(token) {
    if (!token) return;
    MG.pushToken = null;
    fetch(base + '/api/push/release', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ token: token }),
    }).catch(function () {});
  }

  /** Llega el token del celular. Qué hacer con él depende de si hay alguien dentro. */
  function registrado(token) {
    if (!token) return;
    MG.pushToken = token;
    // Puede que la app ya supiera el estado y nos llamara antes de que este archivo
    // existiera: entonces la llamada se perdió pero el dato quedó puesto. Se mira acá para
    // no depender del orden en que se cargan los archivos.
    if (sesionAbierta === null && typeof MG.sesionAbierta === 'boolean') sesionAbierta = MG.sesionAbierta;
    if (sesionAbierta === true) { postToken(token); return; }
    if (sesionAbierta === false) { soltar(token); return; }

    // Todavía no se sabe. Lo resolverá MG.pushSesion… salvo que la página sea una versión
    // vieja guardada en caché y no llame a MG.pushSesion nunca. En ese caso el token se
    // quedaría sin registrar y el celular dejaría de recibir avisos SIN QUE NADIE SE ENTERE,
    // que es peor que el problema que vine a arreglar. Pasado un rato se registra igual,
    // como se hacía antes: si no hay sesión el servidor responde 401 y no ata nada.
    setTimeout(function () {
      if (sesionAbierta === null && MG.pushToken) postToken(MG.pushToken);
    }, 8000);
  }

  /**
   * La app avisa si hay sesión abierta en este celular. La llama al arrancar (después de
   * api/me) y al cerrar sesión.
   *
   * ⚠ Tiene que funcionar en los dos órdenes posibles: el token puede llegar antes o después
   * de saberse si hay sesión. Por eso la decisión vive acá y en registrado(), y las dos
   * miran el mismo par de variables.
   */
  MG.pushSesion = function (abierta) {
    sesionAbierta = !!abierta;
    if (!MG.pushToken) return;
    if (sesionAbierta) postToken(MG.pushToken);
    else soltar(MG.pushToken);
  };

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
          id: 'majesgo_viajes', name: 'Viajes Majes Drive',
          description: 'Alertas de nuevos viajes y estado del viaje',
          importance: 5, visibility: 1, sound: 'default', vibration: true, lights: true,
        }).catch(function () {});

        // Timbre propio de Majes Drive, en las DOS apps, y sólo desde la versión que lleva el
        // mp3: crearlo sin el archivo dejaría el aviso sin sonido.
        // Va en un canal con id NUEVO porque los ajustes de un canal quedan congelados
        // al crearse — cambiarle el sonido a 'majesgo_viajes' no haría nada.
        if (appBuild >= BUILD_TIMBRE_PROPIO) {
          PN.createChannel({
            id: 'majesgo_viajes_v2',
            name: isDriver ? 'Viajes Majes Drive' : 'Tu taxi',
            description: isDriver
              ? 'Alertas de nuevos viajes y estado del viaje'
              : 'Cuando un conductor acepta tu viaje y cuando llega',
            importance: 5, visibility: 1, sound: SONIDO_PROPIO, vibration: true, lights: true,
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
      PN.addListener('registration', function (t) { registrado(t && t.value); });
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

  /* 4) Botón ATRÁS del celular.

     Sin un oyente propio, Capacitor lo interpreta como "volver en el historial" y, como la
     app es una sola pantalla, no hay historial: CIERRA LA APP. Estando en una carrera eso
     es lo peor que puede pasar. Acá el atrás cierra lo que esté abierto encima — la
     denuncia, el chat — y si no hay nada abierto no hace nada mientras dure el viaje.

     Se cierra apretando el MISMO botón que ve el usuario, no escondiendo el elemento a
     mano: así corre la limpieza que hace la app (vaciar el formulario, soltar timers) y no
     quedan dos caminos de salida que puedan divergir.

     ⚠ Y sólo se cierra lo que YA ofrece una salida explícita. El aviso de "el pasajero
     canceló" es un acuse obligatorio: su único botón es "Aceptar y continuar", que además
     libera al conductor en el servidor. Si el atrás lo escondiera, el conductor quedaría
     creyendo que sigue en un viaje que ya no existe. */
  function cerrarLoDeArriba() {
    var volver = document.querySelector('.modal:not(.hidden) #rpBack, .modal:not(.hidden) #cxBack');
    if (volver) { volver.click(); return true; }
    var atrasChat = document.querySelector('#chat.open #chatBack');
    if (atrasChat) { atrasChat.click(); return true; }
    return false;
  }

  try {
    if (P.App && P.App.addListener) {
      P.App.addListener('backButton', function (ev) {
        if (cerrarLoDeArriba()) return;
        // Nada abierto. Si hay una carrera en curso NO se cierra la app: el conductor
        // dejaría de recibir ubicación y avisos sin darse cuenta.
        var enViaje = !!document.querySelector('#chatFab, #chatReport, .tripbar');
        if (enViaje) return;
        if (ev && ev.canGoBack) window.history.back();
        else if (P.App.minimizeApp) P.App.minimizeApp();
      });
    }
  } catch (e) {}

  leerVersion().then(iniciarPush);
})();
