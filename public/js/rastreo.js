/* Majes Drive — la ubicación sigue saliendo aunque el conductor cambie de app.

   EL PROBLEMA. Hasta ahora la posición salía de watchPosition() dentro del WebView. En
   cuanto el conductor abría WhatsApp o Google Maps, Android congelaba la app y las
   actualizaciones se cortaban: el pasajero lo veía clavado en el último punto. No era un
   error, es que el navegador no puede trabajar en segundo plano.

   LA SOLUCIÓN. Un servicio en primer plano de Android, que es el mecanismo que el sistema da
   justo para esto. Arranca al aceptar la carrera y se apaga al terminarla. Mientras vive, el
   sistema no congela el proceso y la posición sigue llegando.

   ⚠ TRES COSAS QUE HACEN QUE ESTO FALLE EN SILENCIO SI NO SE MANEJAN:

   1. La notificación fija NO es opcional. Android la exige a cambio de dejar correr el
      servicio. No se puede ocultar; intentarlo mata el servicio.

   2. Después de 5 minutos en segundo plano, Android ESTRANGULA las peticiones HTTP que salen
      del WebView (documentado por el propio plugin). Las posiciones llegarían bien a la app
      y se perderían al enviarlas — el síntoma sería «funciona cinco minutos y se congela»,
      que es malísimo de diagnosticar. Por eso se manda con CapacitorHttp, que sale por el
      cliente nativo y no pasa por esa limitación.

   3. Ese envío nativo no arrastra necesariamente la cookie de sesión, así que se autentica
      con un token propio del conductor (MG.locToken) en el cuerpo. Si dependiera de la
      sesión, el servidor contestaría 401 y la ubicación se perdería sin dejar rastro.

   NO se pide el permiso de «ubicación todo el tiempo»: arrancando el servicio con la app en
   pantalla alcanza con el permiso normal, y así nos ahorramos el formulario especial de
   Google Play para apps que rastrean en segundo plano. */
(function () {
  'use strict';
  var MG = (window.MG = window.MG || {});

  var Cap = window.Capacitor;
  var nativo = !!(Cap && typeof Cap.isNativePlatform === 'function' && Cap.isNativePlatform());
  var BG = nativo && Cap.Plugins ? Cap.Plugins.BackgroundGeolocation : null;
  var Http = nativo && Cap.Plugins ? Cap.Plugins.CapacitorHttp : null;

  var watcherId = null;
  var arrancando = false;   // se pidió addWatcher y todavía no contestó
  var queremos = false;     // si el viaje pide seguir enviando ahora mismo
  var ultimoEnvio = 0;

  /** Mientras va a recoger o lleva al pasajero: ahí es cuando el pasajero lo está mirando. */
  function enViaje(estado) {
    return ['aceptado', 'en_camino', 'llego', 'a_bordo'].indexOf(estado) >= 0;
  }

  function enviar(pos) {
    if (!pos || !MG.locToken || !Http) return;
    // Mismo ritmo que el sondeo de la app en pantalla: más seguido no mejora el mapa del
    // pasajero y sí gasta batería y datos del conductor.
    var ahora = Date.now();
    if (ahora - ultimoEnvio < 4000) return;
    ultimoEnvio = ahora;

    Http.post({
      url: location.origin + '/conductor/api/location-bg',
      headers: { 'Content-Type': 'application/json' },
      data: { token: MG.locToken, lat: pos.latitude, lng: pos.longitude },
    }).then(function (r) {
      // 409 = el viaje terminó mientras el servicio seguía vivo. El servidor es la verdad:
      // se apaga acá en vez de reintentar para siempre contra un viaje que ya no existe.
      if (r && r.status === 409) detener();
    }).catch(function () {});
  }

  /* ⚠ EL HUECO ENTRE PEDIR EL VIGILANTE Y TENERLO.

     addWatcher devuelve su identificador por promesa, así que entre que se pide y que
     contesta pasan varios segundos con watcherId todavía en null. En ese hueco:

       · otra llamada a arrancar() veía watcherId null y pedía un SEGUNDO vigilante;
       · detener() veía watcherId null, se iba sin hacer nada, y el vigilante quedaba vivo
         para siempre mandando posiciones de un viaje ya terminado.

     Se vio en el log del 2026-10-02: después de cancelar la carrera, el celular siguió
     mandando a /conductor/api/location-bg casi un minuto, cobrando 409 una y otra vez.
     No rompía nada porque el servidor rechaza sin viaje vivo, pero gastaba batería y datos
     del conductor y dejaba el aviso fijo en pantalla sin carrera detrás.

     Por eso ahora manda `queremos` (lo que el viaje pide) y no la existencia del id. */
  function arrancar() {
    if (!BG) return;
    queremos = true;
    if (watcherId || arrancando) return;
    arrancando = true;
    try {
      BG.addWatcher({
        // Con backgroundMessage definido, el plugin levanta el servicio en primer plano.
        // Sin esto sólo seguiría la posición con la app en pantalla, o sea nada nuevo.
        backgroundMessage: 'Tu ubicación se comparte con el pasajero durante la carrera.',
        backgroundTitle: 'Majes Drive · viaje en curso',
        requestPermissions: true,
        stale: false,
        distanceFilter: 15,
      }, function (pos, err) {
        if (err) return;        // permiso denegado o GPS apagado: la app en pantalla sigue reportando
        enviar(pos);
      }).then(function (id) {
        arrancando = false;
        watcherId = id;
        if (!queremos) detener();   // el viaje terminó mientras el vigilante arrancaba
      }).catch(function () { arrancando = false; });
    } catch (e) {
      // que el plugin falle no puede dejar a medias al que nos llamó
      arrancando = false;
    }
  }

  function detener() {
    queremos = false;
    if (!BG || !watcherId) return;
    var id = watcherId; watcherId = null;
    BG.removeWatcher({ id: id }).catch(function () {});
  }

  /**
   * La llama la app del conductor en cada refresco del viaje.
   * Idempotente: se puede llamar en cada sondeo sin crear vigilantes de más.
   */
  MG.rastreo = function (estadoDelViaje) {
    if (!nativo || !BG) return;            // en el navegador no hay servicio que levantar
    if (enViaje(estadoDelViaje)) arrancar();
    else detener();
  };

  MG.rastreoActivo = function () { return !!watcherId; };
})();
