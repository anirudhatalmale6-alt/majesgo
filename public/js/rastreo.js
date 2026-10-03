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

  /* ⚠ registerPlugin, NO Capacitor.Plugins: el propio registro de cambios del plugin dice
     "BREAKING: el plugin se importa con registerPlugin, no desde el objeto Plugins".

     Pero OJO, que esto me costó dos vueltas: cambiar de dónde se toma el plugin NO fue el
     arreglo. El problema de fondo era qué DEVUELVE addWatcher, y las dos vías devuelven
     cosas distintas. Lo que se midió en el celular de Joel:

       · por Capacitor.Plugins → algo con .then que no se resuelve nunca;
       · por registerPlugin    → algo SIN .then ("addWatcher(...).then is not a function").

     En los dos casos el servicio arranca y el callback entrega posiciones — por eso la
     ubicación llegaba perfecta y la notificación aparecía — pero nos quedábamos sin el
     identificador, y sin identificador no se puede apagar. De ahí el aviso que no se iba.

     Por eso abajo se aceptan las TRES formas posibles de respuesta en vez de apostar a una. */
  var BG = null;
  if (nativo) {
    try {
      BG = typeof Cap.registerPlugin === 'function'
        ? Cap.registerPlugin('BackgroundGeolocation')
        : (Cap.Plugins && Cap.Plugins.BackgroundGeolocation) || null;
    } catch (e) {
      BG = (Cap.Plugins && Cap.Plugins.BackgroundGeolocation) || null;
    }
  }
  var Http = nativo && Cap.Plugins ? Cap.Plugins.CapacitorHttp : null;

  var watcherId = null;
  var manija = null;        // algunas versiones devuelven un objeto con remove() en vez de un id
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
    }).then(revisarRespuesta).catch(revisarRespuesta);
  }

  /**
   * Leer el código de respuesta venga como venga.
   *
   * ⚠ ESTO ES LO QUE DEJÓ UN SERVICIO ANDANDO 50 MINUTOS DE MÁS. Antes acá había
   * `.then(ver 409).catch(function () {})`: un catch vacío. CapacitorHttp, según versión y
   * plataforma, puede CONTESTAR con el código o puede RECHAZAR la promesa cuando no es 2xx.
   * Cuando rechazaba, el 409 caía en ese catch vacío y se perdía la ÚNICA señal que tenía el
   * celular para apagarse. El 2026-10-02 el viaje 348 terminó 20:58:27 y el celular siguió
   * mandando posiciones hasta las 21:48, con el aviso fijo en pantalla y el GPS encendido.
   *
   * Un catch vacío sobre la señal de apagado es lo peor de los dos mundos: no se ve el error
   * y tampoco se actúa. Ahora las dos ramas pasan por acá y el código se busca en las formas
   * en que lo puede traer cada una.
   */
  function revisarRespuesta(r) {
    var code = 0;
    if (r) {
      code = r.status || (r.response && r.response.status)
          || (r.error && r.error.status) || 0;
      if (!code && typeof r.message === 'string' && r.message.indexOf('409') >= 0) {
        code = 409;
      }
    }
    // 409 = el viaje terminó mientras el servicio seguía vivo. El servidor es la verdad:
    // se apaga acá en vez de reintentar para siempre contra un viaje que ya no existe.
    if (code === 409) detener();
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
    // ⚠ Si addWatcher nunca contesta, `arrancando` se queda en true y TODAS las llamadas
    // siguientes se iban por acá sin decir una palabra. Ese silencio fue lo que me hizo
    // creer que arrancar() ni se llamaba.
    if (watcherId || arrancando) return;
    arrancando = true;
    try {
      var devuelto = BG.addWatcher({
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
      });

      /* ⚠ addWatcher NO SIEMPRE DEVUELVE UNA PROMESA.

         Yo daba por hecho que sí y encadenaba .then() directo. En el celular de Joel eso
         explotaba con "BG.addWatcher(...).then is not a function", el error se comía el
         catch de abajo y nos quedábamos sin id — o sea, sin poder apagar nunca el servicio.

         Según cómo se tome el plugin y qué versión del puente haya, esto puede volver como
         promesa, como el id directo (string), o como un objeto con remove(). Las tres son
         formas legítimas y no se puede elegir desde acá: hay que aceptar las tres. */
      if (devuelto && typeof devuelto.then === 'function') {
        devuelto.then(anotarVigilante).catch(function (e) {
          arrancando = false;
          });
      } else if (typeof devuelto === 'string' || typeof devuelto === 'number') {
        anotarVigilante(devuelto);
      } else if (devuelto && typeof devuelto.remove === 'function') {
        manija = devuelto;
        arrancando = false;
        if (!queremos) detener();
      } else {
        arrancando = false;
      }
    } catch (e) {
      // que el plugin falle no puede dejar a medias al que nos llamó
      arrancando = false;
    }
  }

  /** Guardar el vigilante recién creado, venga su id como venga. */
  function anotarVigilante(id) {
    arrancando = false;
    watcherId = id;
    recordarId(id);
    if (!queremos) detener();     // el viaje terminó mientras el vigilante arrancaba
  }

  function detener() {
    queremos = false;
    // Si el plugin nos dio un objeto con remove(), ESA es la forma de apagarlo.
    if (manija) {
      var m = manija; manija = null;
      olvidarId();
      try { m.remove(); } catch (e) {}
      return;
    }
    if (!BG || !watcherId) {
      return;
    }
    var id = watcherId; watcherId = null;
    olvidarId();
    BG.removeWatcher({ id: id }).catch(function () {});
  }

  /* ⚠ EL VIGILANTE HUÉRFANO.

     El identificador vivía SÓLO en memoria de la página. Pero el servicio es NATIVO: no se
     muere porque la página se recargue. Si Android recrea la app, o el conductor la cierra y
     la vuelve a abrir, la página arranca con watcherId en null mientras el servicio anterior
     sigue vivo del otro lado. A partir de ahí es imposible apagarlo: detener() no tiene qué
     id pedirle al plugin, y ese servicio queda con el GPS encendido y el aviso fijo puesto
     hasta que el conductor mate la app a mano.

     Por eso el id se guarda en el celular. Al arrancar la app se limpia lo que haya quedado
     de la sesión anterior; si hay carrera viva, MG.rastreo lo vuelve a levantar enseguida. */
  var LLAVE = 'mg_watcher_id';

  function recordarId(id) {
    try { localStorage.setItem(LLAVE, String(id)); } catch (e) {}
  }

  function olvidarId() {
    try { localStorage.removeItem(LLAVE); } catch (e) {}
  }

  function limpiarHuerfano() {
    if (!BG) return;
    var id = null;
    try { id = localStorage.getItem(LLAVE); } catch (e) {}
    if (!id) return;
    olvidarId();
    BG.removeWatcher({ id: id }).catch(function () {});
  }

  /**
   * La llama la app del conductor en cada refresco del viaje.
   * Idempotente: se puede llamar en cada sondeo sin crear vigilantes de más.
   */
  var ultimoEstado = '__nada__';
  MG.rastreo = function (estadoDelViaje) {
    if (estadoDelViaje !== ultimoEstado) {
      ultimoEstado = estadoDelViaje;
    }
    if (!nativo || !BG) return;            // en el navegador no hay servicio que levantar
    if (enViaje(estadoDelViaje)) arrancar();
    else detener();
  };

  MG.rastreoActivo = function () { return !!watcherId; };

  // Al cargar la página se barre lo que haya quedado colgado de la sesión anterior. Si hay
  // carrera viva, el primer sondeo llama a MG.rastreo y el servicio vuelve a levantarse en
  // segundos; si no la hay, el conductor deja de tener el GPS encendido al pedo.
  limpiarHuerfano();
})();
