/* MajesGo — el auto que sale en los mapas de las dos apps.
 *
 * Sedán visto desde arriba, estilo minimalista: carrocería clara, vidrios y techo
 * en un solo panel oscuro, luces LED blancas adelante y rojas atrás. Va aquí y no
 * dentro de cada app para que el conductor y el pasajero no se vayan separando con
 * los retoques.
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
 * Las luces LED van dentro de un faro oscuro a propósito: una tira blanca sobre una
 * carrocería blanca no se ve absolutamente nada.
 */
function mgCarSvg(uid, verde) {
  const b='b'+uid, g='g'+uid, s='s'+uid;
  const body = verde
    ? '<stop offset="0" stop-color="#04672f"/><stop offset=".1" stop-color="#12a457"/><stop offset=".34" stop-color="#2ce06f"/><stop offset=".62" stop-color="#00c853"/><stop offset=".9" stop-color="#0b9247"/><stop offset="1" stop-color="#04592c"/>'
    : '<stop offset="0" stop-color="#8b939d"/><stop offset=".1" stop-color="#dee3e9"/><stop offset=".34" stop-color="#ffffff"/><stop offset=".62" stop-color="#f4f7f9"/><stop offset=".9" stop-color="#ccd3da"/><stop offset="1" stop-color="#828b95"/>';
  const edge = verde ? '#03562b' : '#79828d';
  return '<svg viewBox="0 0 44 74" xmlns="http://www.w3.org/2000/svg">'
  + '<defs>'
  + '<linearGradient id="'+b+'" x1="0" y1="0" x2="1" y2="0">'+body+'</linearGradient>'
  + '<linearGradient id="'+g+'" x1="0" y1="0" x2="0" y2="1">'
  +   '<stop offset="0" stop-color="#39434f"/><stop offset=".22" stop-color="#1d242c"/>'
  +   '<stop offset=".78" stop-color="#161d24"/><stop offset="1" stop-color="#2b333d"/>'
  + '</linearGradient>'
  + '<linearGradient id="'+s+'" x1="0" y1="0" x2="1" y2="0">'
  +   '<stop offset="0" stop-color="#ffffff" stop-opacity=".55"/><stop offset=".5" stop-color="#ffffff" stop-opacity="0"/>'
  +   '<stop offset="1" stop-color="#000000" stop-opacity=".18"/>'
  + '</linearGradient>'
  + '</defs>'
  // carroceria
  + '<path d="M22 2.6c6.5 0 11 2 12.9 6 1.6 3.4 2.3 8.4 2.3 15.4v26c0 8-.6 14-2.2 17.6-1.8 3.4-6.4 4.4-13 4.4s-11.2-1-13-4.4C7.4 64 6.8 58 6.8 50V24c0-7 .7-12 2.3-15.4C11 4.6 15.5 2.6 22 2.6z" fill="url(#'+b+')" stroke="'+edge+'" stroke-width=".7" stroke-linejoin="round"/>'
  // volumen: luz a la izquierda, sombra a la derecha
  + '<path d="M22 2.6c6.5 0 11 2 12.9 6 1.6 3.4 2.3 8.4 2.3 15.4v26c0 8-.6 14-2.2 17.6-1.8 3.4-6.4 4.4-13 4.4s-11.2-1-13-4.4C7.4 64 6.8 58 6.8 50V24c0-7 .7-12 2.3-15.4C11 4.6 15.5 2.6 22 2.6z" fill="url(#'+s+')"/>'
  // panel oscuro continuo: parabrisas + techo + luneta (vidrios y techo oscuros)
  + '<path d="M22 19c5.2 0 8.4 1.8 9.8 6.2l1.1 7.2c.4 5.1.4 10.4 0 15.5l-1.1 7.6c-1.4 4.4-4.6 6.2-9.8 6.2s-8.4-1.8-9.8-6.2l-1.1-7.6c-.4-5.1-.4-10.4 0-15.5l1.1-7.2c1.4-4.4 4.6-6.2 9.8-6.2z" fill="url(#'+g+')"/>'
  // separacion techo / vidrios
  + '<path d="M12.2 32.4c6.5-1.3 13.1-1.3 19.6 0" stroke="#59636f" stroke-opacity=".75" stroke-width=".7" fill="none"/>'
  + '<path d="M12.2 48c6.5 1.3 13.1 1.3 19.6 0" stroke="#59636f" stroke-opacity=".75" stroke-width=".7" fill="none"/>'
  // reflejo del vidrio
  + '<path d="M14.6 22.8c1.6-1.6 4-2.4 7.4-2.4 2 0 3.7.3 5 .8-4.4.2-8.6.8-12.4 1.6z" fill="#8ea3b8" opacity=".35"/>'
  // luces LED delanteras: la tira blanca va dentro de un faro oscuro, si no,
  // sobre una carroceria blanca no se ve absolutamente nada
  + '<path d="M11.4 6.6h7.2c.7 0 1.2.5 1.2 1.2v2.4c0 .7-.5 1.2-1.2 1.2h-5.4c-1.9 0-3-1-3-2.6V7.8c0-.7.5-1.2 1.2-1.2z" fill="#252d36"/>'
  + '<path d="M32.6 6.6h-7.2c-.7 0-1.2.5-1.2 1.2v2.4c0 .7.5 1.2 1.2 1.2h5.4c1.9 0 3-1 3-2.6V7.8c0-.7-.5-1.2-1.2-1.2z" fill="#252d36"/>'
  + '<rect x="11.2" y="8" width="7.4" height="1.7" rx=".85" fill="#ffffff"/>'
  + '<rect x="25.4" y="8" width="7.4" height="1.7" rx=".85" fill="#ffffff"/>'
  // luces LED traseras: misma idea, tira roja sobre piloto oscuro
  + '<path d="M10.9 63.9h22.2c.7 0 1.2.5 1.2 1.2 0 2.1-1.2 3.3-3.4 3.3H13.1c-2.2 0-3.4-1.2-3.4-3.3 0-.7.5-1.2 1.2-1.2z" fill="#252d36"/>'
  + '<rect x="11.2" y="65.1" width="7.6" height="1.8" rx=".9" fill="#ff4040"/>'
  + '<rect x="25.2" y="65.1" width="7.6" height="1.8" rx=".9" fill="#ff4040"/>'
  + '<rect x="18.8" y="65.6" width="6.4" height=".9" rx=".45" fill="#ff4040" opacity=".6"/>'
  // espejos
  + '<path d="M7.2 29.6c-2.8.1-4.3 1.1-4.3 2.3s1.5 2 4.3 2.1z" fill="#2b333d"/>'
  + '<path d="M36.8 29.6c2.8.1 4.3 1.1 4.3 2.3s-1.5 2-4.3 2.1z" fill="#2b333d"/>'
  + '</svg>';
}
