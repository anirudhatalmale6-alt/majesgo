/* ====== Buscar el destino con la voz (pedido de la central, 13 oct) ======
 *
 * El pasajero toca el micrófono, dice "Quiero ir al Hospital de Ciudad Majes" y la frase
 * entra al MISMO buscador que cuando escribe. Nada se elige solo: el pasajero sigue
 * tocando el resultado para confirmar, así una palabra mal entendida nunca pide un taxi.
 *
 * Dos motores, el mismo resultado:
 *  · App de Play: el reconocimiento de Android (plugin nativo SpeechRecognition). Gratis.
 *  · Navegador (Chrome): la Web Speech API. Sirve para probar sin esperar a Play.
 * ⚠ DENTRO de la app NO se usa la Web Speech API aunque exista: el WebView de Android
 *   trae el objeto pero no lo deja funcionar. Una app vieja (sin el plugin) no muestra el
 *   botón — y nada más cambia para ella.
 */
(function () {
  'use strict';

  function esNativa() {
    try { return !!(window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()); }
    catch (_) { return false; }
  }

  // registerPlugin y NO Capacitor.Plugins (misma lección que rastreo.js): el objeto Plugins
  // no siempre trae los plugins de una app que carga la web desde el servidor.
  var plugin = null;
  function nativo() {
    if (plugin) return plugin;
    try {
      var Cap = window.Capacitor;
      if (!Cap || !Cap.isPluginAvailable || !Cap.isPluginAvailable('SpeechRecognition')) return null;
      plugin = typeof Cap.registerPlugin === 'function'
        ? Cap.registerPlugin('SpeechRecognition')
        : (Cap.Plugins && Cap.Plugins.SpeechRecognition) || null;
    } catch (_) { plugin = null; }
    return plugin;
  }

  function motorWeb() { return window.SpeechRecognition || window.webkitSpeechRecognition || null; }

  /** ¿Se puede mostrar el micrófono? Sólo si de verdad hay con qué escuchar. */
  function disponible() {
    if (esNativa()) return !!nativo();
    return !!motorWeb();
  }

  /* ---------- De lo que dijo a lo que se busca ---------- */

  var NUMEROS = {
    cero: 0, uno: 1, una: 1, un: 1, dos: 2, tres: 3, cuatro: 4, cinco: 5, seis: 6, siete: 7,
    ocho: 8, nueve: 9, diez: 10, once: 11, doce: 12, trece: 13, catorce: 14, quince: 15,
    dieciseis: 16, 'dieciséis': 16, diecisiete: 17, dieciocho: 18, diecinueve: 19, veinte: 20,
  };
  // Cómo SUENA cada letra de sección en español: "de tres" es D3, "be uno" es B1.
  var LETRA = { a: 'A', be: 'B', b: 'B', ve: 'B', ce: 'C', c: 'C', se: 'C', de: 'D', d: 'D', e: 'E' };
  var SONIDO = '(a|be|b|ve|ce|c|se|de|d|e)';

  function seccion(sonido, num) {
    var l = LETRA[sonido.toLowerCase()];
    if (!l) return null;
    // La sección A no tiene subsecciones: "A 5" es la parcela 5 de la A (así lo lee el buscador)
    return l === 'A' ? 'A ' + num : l + num;
  }

  /**
   * "Quiero ir al Hospital de Ciudad Majes, por favor" → "Hospital de Ciudad Majes".
   * "parcela 45 de la sección de tres"                 → "D3 parcela 45".
   * Quita SOLO lo que está al principio o al final: "Plaza de Armas" no pierde su "de".
   */
  function limpiar(frase) {
    var t = String(frase || '').replace(/[.!?¿¡]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (!t) return '';
    var antes;
    do { // las muletillas pueden venir encadenadas: "hola, por favor, llévame a..."
      antes = t;
      t = t.replace(/^(hola|buenas|buenos días|buenas tardes|buenas noches|oye|ok|okay|este|eh+|por favor|señor|señora|joven)\b[\s,]*/i, '');
      var sinIntencion = t.replace(new RegExp('^(?:yo\\s+)?(?:quiero|quisiera|necesito|deseo|me gustaría|me gustaria|voy a|tengo que|me puede|me puedes|puede|puedes)?\\s*'
        + '(?:ir|irme|llegar|viajar|llevar(?:me)?|ll[eé]vame|ll[eé]veme|ll[eé]venme|voy|vamos|me voy|dirígete|dirigete)\\b[\\s,]*', 'i'), '');
      if (sinIntencion === t) sinIntencion = t.replace(/^(?:yo\s+)?(?:quiero|quisiera|necesito|busco|buscar)\s+/i, '');
      // "quiero ir el hospital": el artículo suelto sólo sobra JUSTO después de la intención
      // ("La Colina" dicha sola es un nombre, y en "me voy a El Pedregal" el "El" es del nombre)
      if (sinIntencion !== t) t = sinIntencion.replace(/^(?:el|la|los|las)\s+/i, '');
      // "a" sola NO si sigue un número: "A 120" es la parcela 120 de la sección A
      t = t.replace(/^(?:a\s+la|a\s+los|a\s+las|al|a(?!\s+\d)|hacia\s+el|hacia\s+la|hacia|hasta\s+el|hasta\s+la|hasta|para\s+el|para\s+la|para|en\s+el|en\s+la|en)\s+/i, '');
    } while (t !== antes && t);
    t = t.replace(/[\s,]*(por favor|gracias|porfa)\s*$/i, '').replace(/[\s,]+$/, '').trim();

    // números dichos en palabras → cifras ("parcela cuarenta" ya viene en cifras de Android)
    t = t.replace(/\b([a-záéíóú]+)\b/gi, function (w) {
      var n = NUMEROS[w.toLowerCase()];
      return n === undefined ? w : String(n);
    });

    // Sección dicha con la palabra: "sección de 3", "sector be 2", "seccion D3"
    var sec = null, m;
    var reSec = new RegExp('\\b(?:la\\s+)?(?:secci[oó]n|sector)\\s+' + SONIDO + '\\s*-?\\s*(\\d{1,2})\\b', 'i');
    if ((m = t.match(reSec))) { sec = seccion(m[1], m[2]); t = t.replace(m[0], ' '); }
    // ...o ya escrita como código: "D3", "d 3", "B-1"
    if (!sec && (m = t.match(/\b([B-Eb-e])\s*-?\s*(\d{1,2})\b/))) {
      if (/^\s*[B-Eb-e]\s*-?\s*\d{1,2}\b/.test(t) || /parcela|lote/i.test(t)) { sec = m[1].toUpperCase() + m[2]; t = t.replace(m[0], ' '); }
    }
    // ...o la frase ENTERA es una sección dicha sola: "de tres" → D3
    if (!sec && (m = t.match(new RegExp('^' + SONIDO + '\\s*-?\\s*(\\d{1,2})$', 'i')))) {
      return seccion(m[1], m[2]);
    }
    // "sección A parcela 120" / "parcela 120 de la A"
    if (!sec && /parcela|lote/i.test(t) && (m = t.match(/\b(?:secci[oó]n\s+|de\s+la\s+)(a)\b/i))) { sec = 'A'; t = t.replace(m[0], ' '); }

    if (sec) {
      var p = t.match(/\b(?:parcela|lote|parc\.?)\s*(?:n[uú]mero\s*)?(\d{1,4})\b/i);
      if (p) return sec.charAt(0) === 'A' ? 'A ' + p[1] : sec + ' parcela ' + p[1];
      // Sección sin parcela: lo demás era relleno ("la", "de la", "en la")
      var resto = t.replace(/\b(de|la|el|en|del|de la)\b/gi, ' ').replace(/\s+/g, ' ').trim();
      return resto ? sec + ' ' + resto : sec.replace(/^A (\d+)$/, 'A $1');
    }
    return t.replace(/\s+/g, ' ').trim();
  }

  /* ---------- Escuchar ---------- */

  var turno = 0;      // cada escucha tiene su número: si cancela, la respuesta tardía se ignora
  var activo = null;  // { terminar, abortar } de la escucha en curso

  /**
   * Escucha UNA frase. Llama a:
   *   on.estado('preparando' | 'escuchando' | 'procesando')
   *   on.parcial(texto)         (sólo el navegador da texto en vivo)
   *   on.listo(frasesPosibles)  (array, la más probable primero)
   *   on.error(motivo)          ('permiso' | 'silencio' | 'no-entendi' | 'red' | 'otro')
   */
  async function escuchar(on) {
    var mio = ++turno;
    var vivo = function () { return mio === turno; };
    on.estado('preparando');
    if (esNativa()) {
      var SR = nativo();
      if (!SR) { on.error('otro'); return; }
      try {
        var p = await SR.checkPermissions();
        if (!p || p.speechRecognition !== 'granted') p = await SR.requestPermissions();
        if (!vivo()) return;
        if (!p || p.speechRecognition !== 'granted') { on.error('permiso'); return; }
      } catch (e) { if (vivo()) on.error('permiso'); return; }
      var h = null;
      try {
        h = await SR.addListener('listeningState', function (s) {
          if (vivo() && s && s.status === 'stopped') on.estado('procesando');
        });
      } catch (_) { h = null; }
      // ⚠ stop() del plugin nunca resuelve su promesa: se llama sin await
      var parar = function () { try { SR.stop(); } catch (_) {} };
      activo = { terminar: parar, abortar: parar };
      // Red de seguridad: si Android no contesta nunca, no dejar al pasajero mirando una animación
      var tope = setTimeout(function () { if (vivo()) { parar(); turno++; on.error('silencio'); } }, 20000);
      on.estado('escuchando');
      try {
        var r = await SR.start({ language: 'es-PE', maxResults: 5, partialResults: false, popup: false });
        if (!vivo()) return;
        var lista = (r && r.matches) || [];
        if (lista.length) on.listo(lista); else on.error('no-entendi');
      } catch (e) {
        if (!vivo()) return;
        var msg = String((e && e.message) || e || '');
        if (/permission/i.test(msg)) on.error('permiso');
        else if (/no speech/i.test(msg)) on.error('silencio');
        else if (/no match/i.test(msg)) on.error('no-entendi');
        else if (/network/i.test(msg)) on.error('red');
        else on.error('otro');
      } finally {
        clearTimeout(tope);
        if (h && h.remove) { try { h.remove(); } catch (_) {} }
        if (vivo()) activo = null;
      }
      return;
    }

    var Motor = motorWeb();
    if (!Motor) { on.error('otro'); return; }
    var rec = new Motor();
    rec.lang = 'es-PE';
    rec.interimResults = true;
    rec.maxAlternatives = 5;
    rec.continuous = false;
    var final = null, fallo = null;
    activo = {
      terminar: function () { try { rec.stop(); } catch (_) {} },   // stop = entrega lo que oyó
      abortar: function () { try { rec.abort(); } catch (_) {} },   // abort = descarta
    };
    rec.onstart = function () { if (vivo()) on.estado('escuchando'); };
    rec.onspeechend = function () { if (vivo()) on.estado('procesando'); };
    rec.onresult = function (ev) {
      if (!vivo()) return;
      var res = ev.results[ev.results.length - 1];
      if (res.isFinal) {
        final = [];
        for (var i = 0; i < res.length; i++) final.push(res[i].transcript);
      } else {
        on.parcial(res[0].transcript);
      }
    };
    rec.onerror = function (ev) {
      var c = ev && ev.error;
      fallo = c === 'not-allowed' || c === 'service-not-allowed' || c === 'audio-capture' ? 'permiso'
        : c === 'no-speech' ? 'silencio'
        : c === 'network' ? 'red'
        : c === 'aborted' ? 'cancelado' : 'otro';
    };
    rec.onend = function () {
      if (!vivo()) return;
      activo = null;
      if (final && final.length) on.listo(final);
      else if (fallo === 'cancelado') { /* lo canceló él: no hay nada que decir */ }
      else on.error(fallo || 'no-entendi');
    };
    try { rec.start(); } catch (e) { if (vivo()) on.error('otro'); }
  }

  /** Corta la escucha en curso; su respuesta, si llega tarde, se descarta. */
  function cancelar() {
    turno++;
    if (activo) { var a = activo; activo = null; a.abortar(); }
  }
  /** "Ya terminé de hablar": deja de escuchar pero SÍ usa lo que oyó. */
  function terminar() { if (activo) activo.terminar(); }

  window.MGVoz = { disponible: disponible, escuchar: escuchar, cancelar: cancelar, terminar: terminar, limpiar: limpiar };
})();
