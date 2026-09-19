/* MajesGo — mantener la pantalla encendida mientras hay una carrera activa.

   El conductor lleva el celular en el soporte y lo mira de reojo: que se apague a los
   segundos es como si se apagara el GPS. Mientras dura la carrera pedimos que la pantalla
   no se duerma, y al terminar la soltamos para no comerle la batería el resto del día.

   ⚠ Dos cosas que hacen que un "wake lock" falle en silencio si no se manejan:
   1. El sistema LO SUELTA solo cada vez que la app pasa a segundo plano. Al volver hay que
      volver a pedirlo, o la pantalla se apaga desde la segunda vez y parece que nunca
      funcionó.
   2. Pedirlo requiere que el documento esté visible; si se pide con la app oculta, la
      promesa se rechaza. Por eso se reintenta en 'visibilitychange' y no una sola vez.

   Si el aparato no soporta la función, no se rompe nada: simplemente no se pide. */
(function () {
  'use strict';
  var MG = (window.MG = window.MG || {});

  var querido = false;   // ¿queremos la pantalla encendida ahora mismo?
  var lock = null;       // el permiso vivo, si lo tenemos
  var soportado = !!(navigator.wakeLock && navigator.wakeLock.request);

  function pedir() {
    if (!soportado || !querido || lock || document.visibilityState !== 'visible') return;
    navigator.wakeLock.request('screen').then(function (l) {
      lock = l;
      // 'release' salta tanto si lo soltamos nosotros como si lo suelta el sistema.
      l.addEventListener('release', function () { lock = null; });
    }).catch(function () { lock = null; });
  }

  function soltar() {
    if (!lock) return;
    var l = lock; lock = null;
    try { l.release(); } catch (e) {}
  }

  /** true mientras haya una carrera en curso; false al terminarla o cancelarla. */
  MG.pantalla = function (encendida) {
    encendida = !!encendida;
    if (encendida === querido) return;   // idempotente: se puede llamar en cada render
    querido = encendida;
    if (querido) pedir(); else soltar();
  };

  MG.pantallaSoportada = function () { return soportado; };

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') pedir(); else lock = null;
  });
})();
