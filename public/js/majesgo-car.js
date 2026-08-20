/* MajesGo — el auto que sale en los mapas de las dos apps.
 *
 * Sedán visto desde arriba, calcado del 3er auto de la guía que mandó el cliente:
 * carrocería y TECHO claros, y oscuros solo los cristales (parabrisas, lunas
 * laterales y luneta trasera). Ojo con esto, que es justo lo contrario de lo que
 * yo había hecho antes: no es un panel oscuro continuo de morro a cola.
 * Completan el dibujo los faros LED blancos, la barra LED roja envolvente atrás y
 * las esquinas delanteras en gris oscuro.
 *
 *   verde = false → blanco/plata. Es el propio conductor en su mapa.
 *   verde = true  → verde MajesGo. Son los taxis que ve el PASAJERO: él se ve a sí
 *                   mismo como el círculo azul, y dos manchas claras sobre un mapa
 *                   oscuro no se distinguen de un vistazo.
 *
 * uid: los degradados de un SVG se llaman por id, y el id vale para TODA la página,
 * no para cada SVG. Si todos los autos comparten el mismo id y el mapa quita justo
 * al que traía la definición (un taxi se alejó y desaparece), los demás se quedan
 * sin relleno. Por eso cada marcador lleva su propio sufijo.
 *
 * Los faros LED llevan un borde gris fino a propósito: blanco sobre una carrocería
 * blanca no se ve absolutamente nada.
 */
function mgCarSvg(uid, verde) {
  const b='b'+uid, g='g'+uid, s='s'+uid;
  const body = verde
    ? '<stop offset="0" stop-color="#04682f"/><stop offset=".1" stop-color="#12a457"/><stop offset=".34" stop-color="#2ce473"/><stop offset=".62" stop-color="#00c853"/><stop offset=".9" stop-color="#0a9247"/><stop offset="1" stop-color="#04592c"/>'
    : '<stop offset="0" stop-color="#98a1ab"/><stop offset=".1" stop-color="#e6ebf0"/><stop offset=".34" stop-color="#ffffff"/><stop offset=".62" stop-color="#fafcfd"/><stop offset=".9" stop-color="#d8dee5"/><stop offset="1" stop-color="#909aa4"/>';
  const edge   = verde ? '#03542a' : '#7d868f';
  const crease = verde ? '#0a8f45' : '#cdd5dd';
  const mirror = verde ? '#1fc766' : '#f2f5f8';
  return '<svg viewBox="0 0 44 74" xmlns="http://www.w3.org/2000/svg">'
  + '<defs>'
  + '<linearGradient id="'+b+'" x1="0" y1="0" x2="1" y2="0">'+body+'</linearGradient>'
  + '<linearGradient id="'+g+'" x1="0" y1="0" x2="0" y2="1">'
  +   '<stop offset="0" stop-color="#3a4552"/><stop offset=".3" stop-color="#1e262f"/>'
  +   '<stop offset="1" stop-color="#12181e"/>'
  + '</linearGradient>'
  + '<linearGradient id="'+s+'" x1="0" y1="0" x2="1" y2="0">'
  +   '<stop offset="0" stop-color="#ffffff" stop-opacity=".5"/><stop offset=".45" stop-color="#ffffff" stop-opacity="0"/>'
  +   '<stop offset="1" stop-color="#000000" stop-opacity=".2"/>'
  + '</linearGradient>'
  + '</defs>'
  // espejos (color carroceria, asoman a los lados a la altura del parabrisas)
  + '<path d="M7.4 24.4c-2.2.2-3.3 1-3.3 2s1.1 1.6 3.3 1.8z" fill="'+mirror+'" stroke="'+edge+'" stroke-width=".5" stroke-linejoin="round"/>'
  + '<path d="M36.6 24.4c2.2.2 3.3 1 3.3 2s-1.1 1.6-3.3 1.8z" fill="'+mirror+'" stroke="'+edge+'" stroke-width=".5" stroke-linejoin="round"/>'
  // carroceria
  + '<path d="M22 2.6c4.7 0 7.6.5 9.4 1.6 3.3 2 5.2 5.5 5.9 10.2.6 4.1.9 7.8.9 12v17.8c0 6-.3 11-1.1 15.4-.9 5.2-3.1 9.2-6.9 9.8-2.4.4-5.4.5-8.2.5s-5.8-.1-8.2-.5c-3.8-.6-6-4.6-6.9-9.8-.8-4.4-1.1-9.4-1.1-15.4V26.4c0-4.2.3-7.9.9-12 .7-4.7 2.6-8.2 5.9-10.2 1.8-1.1 4.7-1.6 9.4-1.6z" fill="url(#'+b+')" stroke="'+edge+'" stroke-width=".7" stroke-linejoin="round"/>'
  // esquinas delanteras oscuras (paragolpes / faros vistos desde arriba)
  + '<path d="M13.9 5.2c-2.5 1.9-4 4.9-4.6 9l1.7.4c.6-3.5 1.8-6 3.9-7.6z" fill="#2b333c" opacity=".55"/>'
  + '<path d="M30.1 5.2c2.5 1.9 4 4.9 4.6 9l-1.7.4c-.6-3.5-1.8-6-3.9-7.6z" fill="#2b333c" opacity=".55"/>'
  // volumen: luz a la izquierda, sombra a la derecha
  + '<path d="M22 2.6c4.7 0 7.6.5 9.4 1.6 3.3 2 5.2 5.5 5.9 10.2.6 4.1.9 7.8.9 12v17.8c0 6-.3 11-1.1 15.4-.9 5.2-3.1 9.2-6.9 9.8-2.4.4-5.4.5-8.2.5s-5.8-.1-8.2-.5c-3.8-.6-6-4.6-6.9-9.8-.8-4.4-1.1-9.4-1.1-15.4V26.4c0-4.2.3-7.9.9-12 .7-4.7 2.6-8.2 5.9-10.2 1.8-1.1 4.7-1.6 9.4-1.6z" fill="url(#'+s+')"/>'
  // pliegues del capo
  + '<path d="M16.4 7.4c-1.1 2.2-1.8 4.9-2.1 8" stroke="'+crease+'" stroke-width=".7" fill="none" stroke-linecap="round" opacity=".8"/>'
  + '<path d="M27.6 7.4c1.1 2.2 1.8 4.9 2.1 8" stroke="'+crease+'" stroke-width=".7" fill="none" stroke-linecap="round" opacity=".8"/>'
  // parabrisas
  + '<path d="M22 16.2c5.1 0 8.3.5 9.3 1.5l.5 8.5c.2 1.4-2.6 1.8-9.8 1.8s-10-.4-9.8-1.8l.5-8.5c1-1 4.2-1.5 9.3-1.5z" fill="url(#'+g+')"/>'
  // lunas laterales
  + '<path d="M12.4 27.6c-1.4.5-2.2 1.9-2.3 4.2v11c.1 2.3.9 3.7 2.3 4.2z" fill="url(#'+g+')"/>'
  + '<path d="M31.6 27.6c1.4.5 2.2 1.9 2.3 4.2v11c-.1 2.3-.9 3.7-2.3 4.2z" fill="url(#'+g+')"/>'
  // luneta trasera
  + '<path d="M22 48.4c5.6 0 8.5.4 8.8 1.1l-.5 8.4c-.1 1-3.2 1.5-8.3 1.5s-8.2-.5-8.3-1.5l-.5-8.4c.3-.7 3.2-1.1 8.8-1.1z" fill="url(#'+g+')"/>'
  // faros LED blancos (finos, en el morro)
  + '<rect x="14.2" y="8.2" width="5.4" height="1.3" rx=".65" fill="#ffffff" stroke="#9aa4ae" stroke-width=".35"/>'
  + '<rect x="24.4" y="8.2" width="5.4" height="1.3" rx=".65" fill="#ffffff" stroke="#9aa4ae" stroke-width=".35"/>'
  // barra LED trasera roja, envolvente
  + '<path d="M8.8 61.4c2.6 1.1 5.7 1.7 9.1 1.9l-.15 1.7c-3.8-.2-7.2-.9-9.9-2z" fill="#e33a3a"/>'
  + '<path d="M35.2 61.4c-2.6 1.1-5.7 1.7-9.1 1.9l.15 1.7c3.8-.2 7.2-.9 9.9-2z" fill="#e33a3a"/>'
  + '<path d="M19 63.4c2 .1 4 .1 6 0l-.1 1.7c-1.9.1-3.9.1-5.8 0z" fill="#e33a3a" opacity=".5"/>'
  + '</svg>';
}
