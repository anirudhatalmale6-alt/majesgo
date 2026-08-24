/* MajesGo — App del conductor (Hito 3) */
'use strict';

const CUR = MG.currency || 'S/';
const $ = (s) => document.querySelector(s);
const money = (n) => CUR + ' ' + Number(n).toFixed(2);
const km = (m) => (m / 1000).toFixed(1) + ' km';
const mins = (s) => Math.max(1, Math.round(s / 60)) + ' min';

/* ---------- Auto del conductor en el mapa ----------
 * El dibujo vive en /js/majesgo-car.js, compartido con la app del pasajero.
 * Aqui va en blanco/plata: es el propio conductor viendose a si mismo.
 */
const CAR_SVG = mgCarSvg('me', false);

/* Rumbo del auto. El GPS solo da 'heading' cuando el aparato se está moviendo:
 * parado devuelve null (o basura), así que se guarda el último rumbo bueno en
 * vez de dejar que el auto pegue saltos cuando el conductor está detenido. */
let carHeading = 0;

function pointCar(pos) {
  const h = pos && pos.coords ? pos.coords.heading : null;
  const sp = pos && pos.coords ? pos.coords.speed : null;
  // por debajo de ~4 km/h el rumbo del GPS es ruido puro
  if (h !== null && h !== undefined && !isNaN(h) && sp !== null && sp > 1.1) carHeading = h;
  const el = meMarker && meMarker.getElement && meMarker.getElement();
  const car = el && el.querySelector('.car');
  if (car) car.style.transform = 'rotate(' + carHeading + 'deg)';
}

/* ---------- API ---------- */
/**
 * ¿Estamos dentro de la app instalada de Play o en el navegador?
 * Se pregunta en cada llamada, no al cargar: el puente de Capacitor puede no estar listo
 * todavía cuando se ejecuta este archivo, y así nunca importa el orden de los scripts.
 */
function isNativeApp() {
  try { return !!(window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()); }
  catch (_) { return false; }
}

async function api(path, body, method) {
  const opt = {
    method: method || (body ? 'POST' : 'GET'),
    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': MG.csrf },
  };
  // el panel usa esto para saber quién ya instaló la app de Play y quién sigue en el navegador
  if (isNativeApp()) opt.headers['X-MajesGo-App'] = 'native';
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const res = await fetch('/conductor/' + path, opt);
  const data = await res.json().catch(() => ({}));
  /*
   * Sesión caída (401). Antes esto solo sacaba un aviso "No autenticado" y la app seguía
   * consultando igual: el conductor quedaba viendo el mapa creyendo que estaba en línea,
   * mientras el servidor rechazaba cada llamada y no le entraba ni un viaje. Ahora vuelve
   * a la pantalla de acceso con el motivo escrito y entra de nuevo con un toque.
   */
  if (res.status === 401) {
    kickToLogin('Tu sesión se cerró. Vuelve a ingresar para seguir recibiendo viajes.');
    throw { status: 401, message: 'Sesión cerrada', expired: true };
  }
  if (!res.ok) throw { status: res.status, message: data.message || 'Ocurrió un error', errors: data.errors };
  return data;
}

/**
 * Vuelve a la pantalla de acceso con el motivo a la vista.
 * El cerrojo evita que una ráfaga de llamadas en curso dispare diez recargas seguidas.
 */
let kicked = false;
function kickToLogin(msg) {
  if (kicked) return;
  kicked = true;
  try { sessionStorage.setItem('mg_kick', msg); } catch (_) {}
  location.replace(location.pathname);
}

/** Motivo por el que la app te devolvió a la pantalla de acceso, guardado antes de recargar. */
function showKickNotice() {
  let msg = null;
  try { msg = sessionStorage.getItem('mg_kick'); sessionStorage.removeItem('mg_kick'); } catch (_) {}
  if (!msg) return;
  const err = $('#authErr');
  err.textContent = msg;
  err.style.display = 'block';
  $('#auth').classList.remove('hidden');
}

/* ---------- Toast ---------- */
let toastT;
function toast(msg) {
  const t = $('#toast'); t.textContent = msg; t.classList.add('show');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2800);
}
function esc(s) { return (s || '').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

/* ---------- Estado ---------- */
let map, meMarker, oMarker, dMarker, routeLine;
let me = null, online = false, ride = null, myPos = null;
let dstats = null; // estadísticas del conductor (ganancias del día, horas, aceptación, etc.)
let dSheetState = 'open', dSheetDragging = false; // panel inferior colapsable
let reqCode = null, reqTimer = null, poll = null, lastPostAt = 0;
// Lista de solicitudes disponibles: el conductor las ve TODAS y elige cuál tomar.
// reqCode !== null significa que tiene abierta la ficha de una de ellas.
let reqList = [];
let reqSeen = new Set();        // códigos ya avisados: el sonido suena solo con lo NUEVO
let reqSort = 'cerca';          // 'cerca' | 'paga'
// ¿el servidor tiene con qué avisarle si la app está cerrada? null = todavía no lo sabemos
let pushOk = null;
let commissionPct = 5, minSaldo = 0.5;   // comisión = % de la tarifa (la fija el panel)

/** Comisión que se le descuenta al conductor por una tarifa dada. */
function commissionFor(price) { return Math.round((Number(price) || 0) * commissionPct) / 100; }
// Total pactado del viaje = tramo A→B + acercamiento hasta el pasajero + ajuste del conductor.
// Los viajes anteriores a esos campos no los traen: ahí el total es solo el viaje.
function rideTotal(r) {
  if (!r) return 0;
  if (r.total_price != null) return Number(r.total_price);
  return (Number(r.offered_price) || 0) + (Number(r.approach_fee) || 0) + (Number(r.counter_offer) || 0);
}
// Importes que el conductor puede añadir al aceptar (los define la central). [] = apagado.
let counterOptions = [];
let reqBump = 0; // ajuste elegido en la tarjeta que está en pantalla

let offerLabels = []; // etiquetas resaltadas (recojo + destino) durante la oferta

// ---- modo navegación (pantalla completa tipo GPS) ----
let navOpen = false, navMap = null, navCar = null, navLine = null, navPin = null;
let navLastLL = null, navLastT = 0, navBearing = 0, navFollow = true, navCanRotate = false, navTargetLL = null;
let chatOpen = false, chatLastId = 0, chatSeenId = 0, chatPoll = null, rideLastMsgId = 0;
let mapLight = false, baseTile = null, navTile = null, arrivedFor = null;
let cancelModalOpen = false, cancelledRide = null, cancelReason = null, audioCtx = null;

/* ---------- Alertas sonoras ---------- */
// Se prepara/reactiva el contexto de audio con un gesto del usuario (tocar la pantalla),
// requisito de los navegadores para poder reproducir sonido después.
function ensureAudio() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
  } catch (e) {}
  unlockAlertFile();
}

/* Tono sintetizado: no depende de ningún archivo, así que siempre hay sonido
   aunque la central todavía no haya subido el suyo. */
function playTones(steps, vol) {
  try {
    if (!audioCtx) return false;
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const now = audioCtx.currentTime;
    steps.forEach((hz, i) => {
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.type = 'sine'; o.frequency.value = hz;
      const s = now + i * 0.22;
      g.gain.setValueAtTime(0.0001, s);
      g.gain.exponentialRampToValueAtTime(vol, s + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, s + 0.20);
      o.connect(g); g.connect(audioCtx.destination);
      o.start(s); o.stop(s + 0.22);
    });
    return true;
  } catch (e) { return false; }
}

/* Sonido propio de la central (window.MG.alertSound). Si no hay archivo, o el
   navegador no lo puede reproducir, se cae al tono sintetizado: nunca queda mudo. */
let alertFile = null, alertFileReady = false;
function unlockAlertFile() {
  // Un <audio> también necesita un gesto del usuario la primera vez: se arranca y
  // se detiene en silencio para dejarlo habilitado.
  if (alertFileReady || !window.MG || !MG.alertSound) return;
  try {
    if (!alertFile) { alertFile = new Audio(MG.alertSound); alertFile.preload = 'auto'; }
    const p = alertFile.play();
    if (p && p.then) {
      p.then(() => { alertFile.pause(); alertFile.currentTime = 0; alertFileReady = true; })
       .catch(() => {});
    } else { alertFile.pause(); alertFile.currentTime = 0; alertFileReady = true; }
  } catch (e) {}
}
function playAlertFile() {
  if (!alertFile) return false;
  try {
    alertFile.currentTime = 0;
    const p = alertFile.play();
    if (p && p.catch) p.catch(() => playTones([660, 880, 1175], 0.5));
    return true;
  } catch (e) { return false; }
}

/* Aviso de VIAJE NUEVO: suena y vibra en repeticiones mientras la tarjeta está en
   pantalla, no una sola vez — el conductor casi nunca está mirando el teléfono. */
const NEW_RIDE_REPEATS = 6, NEW_RIDE_GAP_MS = 3000;
let newRideTimer = null;
const rideAlert = {
  start() {
    this.stop();
    let n = 0;
    const ring = () => {
      if (!playAlertFile()) playTones([660, 880, 1175], 0.5);
      if (navigator.vibrate) { try { navigator.vibrate([400, 150, 400]); } catch (e) {} }
      if (++n >= NEW_RIDE_REPEATS) this.stop();
    };
    ring();
    newRideTimer = setInterval(ring, NEW_RIDE_GAP_MS);
  },
  stop() {
    if (newRideTimer) { clearInterval(newRideTimer); newRideTimer = null; }
    if (alertFile) { try { alertFile.pause(); alertFile.currentTime = 0; } catch (e) {} }
    if (navigator.vibrate) { try { navigator.vibrate(0); } catch (e) {} }
  },
};
function alertBeep() {
  try {
    ensureAudio(); if (!audioCtx) return;
    const now = audioCtx.currentTime;
    for (let i = 0; i < 3; i++) {
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.type = 'sine'; o.frequency.value = i % 2 === 0 ? 880 : 620;
      const s = now + i * 0.32;
      g.gain.setValueAtTime(0.0001, s);
      g.gain.exponentialRampToValueAtTime(0.4, s + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, s + 0.26);
      o.connect(g); g.connect(audioCtx.destination);
      o.start(s); o.stop(s + 0.28);
    }
  } catch (e) {}
}

/* ================= MODAL: cancelación del pasajero ================= */
const CANCEL_REASONS = ['El pasajero canceló sin avisar', 'Tardó mucho en responder', 'Falsa solicitud'];

function showCancelModal(r) {
  cancelModalOpen = true; cancelledRide = r; cancelReason = null;
  if (navOpen) closeNav();
  closeChat();
  alertBeep();
  if (navigator.vibrate) { try { navigator.vibrate([220, 100, 220, 100, 220]); } catch (e) {} }
  const m = $('#cancelModal');
  m.innerHTML = `
    <div class="modalcard">
      <div class="micon">🚫</div>
      <h2>El pasajero ha cancelado la carrera</h2>
      <p class="msub">El viaje se canceló. Si quieres, reporta el motivo (opcional) y vuelve a estar disponible.</p>
      <div class="reasonlbl">Reportar cancelación (opcional):</div>
      <div class="reasons" id="cxReasons">
        ${CANCEL_REASONS.map((t, i) => `<button class="reason" data-i="${i}">${esc(t)}</button>`).join('')}
      </div>
      <button class="btn" id="cxContinue">Aceptar y continuar</button>
    </div>`;
  m.classList.remove('hidden');
  const btns = m.querySelectorAll('.reason');
  btns.forEach((b) => b.addEventListener('click', () => {
    const i = +b.dataset.i;
    if (cancelReason === CANCEL_REASONS[i]) { cancelReason = null; b.classList.remove('on'); }   // volver a tocar = quitar
    else { cancelReason = CANCEL_REASONS[i]; btns.forEach((x) => x.classList.remove('on')); b.classList.add('on'); }
  }));
  $('#cxContinue').addEventListener('click', continueAfterCancel);
}

async function continueAfterCancel() {
  const btn = $('#cxContinue'); if (btn) btn.disabled = true;
  const rid = cancelledRide ? cancelledRide.id : null;
  // guarda el motivo (si eligió) + marca la carrera como vista para no repetir el modal
  try { await api('api/cancel-report', { ride_id: rid, reason: cancelReason }); } catch (e) {}
  const m = $('#cancelModal'); m.classList.add('hidden'); m.innerHTML = '';
  cancelModalOpen = false; cancelledRide = null; cancelReason = null;
  ride = null; online = true; me.status = 'disponible';
  clearTrip();
  renderHome();
}

const ACTIVE = ['ofrecido', 'aceptado', 'en_camino', 'llego', 'a_bordo'];
const ARRIVE_M = 30; // metros para avisar "llegaste"

// ---- recálculo de ruta (rerouting) cuando el conductor se desvía ----
let activeRoute = null;                 // tramo actual: { coords:[[lat,lng],...], target:[lat,lng], toDest }
let offRouteSince = 0, lastRerouteAt = 0, rerouting = false;
const OFFROUTE_M = 45;                   // metros de desvío para considerar "fuera de ruta"
const OFFROUTE_MS = 4500;                // debe estar desviado este tiempo (evita falsos por salto de GPS)
const REROUTE_COOLDOWN_MS = 12000;       // no recalcular más seguido que esto

/** Fija la ruta del tramo actual desde el servidor solo si cambió el tramo (recojo<->destino). */
function syncActiveRoute(r) {
  const toDest = r.status === 'a_bordo';
  // reinicia si es otro viaje o cambió el tramo (recojo<->destino)
  if (!activeRoute || activeRoute.rideId !== r.id || activeRoute.toDest !== toDest) {
    const coords = (toDest ? r.route_trip : r.route_to_pickup) || [];
    activeRoute = {
      rideId: r.id,
      coords: coords.slice(),
      target: toDest ? [r.dest.lat, r.dest.lng] : [r.origin.lat, r.origin.lng],
      toDest: toDest,
    };
    offRouteSince = 0;
  }
}

/** Distancia (m) de un punto al segmento a-b, en plano local equirectangular. */
function segDistM(plat, plng, alat, alng, blat, blng) {
  const latR = plat * Math.PI / 180;
  const mLat = 111320, mLng = 111320 * Math.cos(latR);
  const px = 0, py = 0;
  const ax = (alng - plng) * mLng, ay = (alat - plat) * mLat;
  const bx = (blng - plng) * mLng, by = (blat - plat) * mLat;
  const dx = bx - ax, dy = by - ay;
  if (dx === 0 && dy === 0) return Math.hypot(px - ax, py - ay);
  let t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy);
  t = Math.max(0, Math.min(1, t));
  return Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
}
/** Distancia mínima (m) de un punto a la polilínea de la ruta. */
function distToPathM(lat, lng, path) {
  let min = Infinity;
  for (let i = 0; i < path.length - 1; i++) {
    const d = segDistM(lat, lng, path[i][0], path[i][1], path[i + 1][0], path[i + 1][1]);
    if (d < min) min = d;
  }
  return min;
}

/** Si el conductor se desvía de la ruta de forma sostenida, pide una ruta nueva y la redibuja. */
function checkReroute() {
  if (!myPos || !ride || rerouting) return;
  if (!['aceptado', 'en_camino', 'llego', 'a_bordo'].includes(ride.status)) return;
  if (!activeRoute || activeRoute.coords.length < 2) return;
  const now = Date.now();
  const d = distToPathM(myPos.lat, myPos.lng, activeRoute.coords);
  if (d <= OFFROUTE_M) { offRouteSince = 0; return; }
  if (!offRouteSince) offRouteSince = now;
  if (now - offRouteSince < OFFROUTE_MS) return;      // desvío aún no sostenido
  if (now - lastRerouteAt < REROUTE_COOLDOWN_MS) return;
  doReroute();
}
async function doReroute() {
  rerouting = true; lastRerouteAt = Date.now();
  try {
    const r = await api('api/reroute', { lat: myPos.lat, lng: myPos.lng });
    if (r && r.geometry && r.geometry.length >= 2 && activeRoute) {
      activeRoute.coords = r.geometry;
      offRouteSince = 0;
      const color = activeRoute.toDest ? '#00C853' : '#FFC107';
      drawRoute(activeRoute.coords, color);
      if (navOpen && navMap) {
        if (navLine) navLine.setLatLngs(activeRoute.coords);
        else navLine = L.polyline(activeRoute.coords, { color: color, weight: 6, opacity: .9 }).addTo(navMap);
      }
      toast('Ruta recalculada');
    }
  } catch (e) { /* si falla el recálculo, mantenemos la ruta anterior */ }
  finally { rerouting = false; lastRerouteAt = Date.now(); }
}

/* ---------- modo del mapa (claro de día / oscuro de noche) ---------- */
function tileUrl(light) {
  return light
    ? 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png'
    : 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
}
function initialLight() {
  const saved = localStorage.getItem('mg_map_mode');
  if (saved === 'light') return true;
  if (saved === 'dark') return false;
  const h = new Date().getHours();
  return h >= 6 && h < 18; // por defecto: claro de día, oscuro de noche
}
function applyMapMode() {
  document.getElementById('app').classList.toggle('lightmap', mapLight);
  const b = document.getElementById('btnMapMode'); if (b) b.textContent = mapLight ? '☀️' : '🌙';
  if (baseTile) baseTile.setUrl(tileUrl(mapLight));
  if (navTile) navTile.setUrl(tileUrl(mapLight));
}
function toggleMapMode() {
  mapLight = !mapLight;
  localStorage.setItem('mg_map_mode', mapLight ? 'light' : 'dark');
  applyMapMode();
}

/* ---------- geo helpers ---------- */
function haversineM(a, b, c, d) {
  const R = 6371000, r = Math.PI / 180;
  const dLat = (c - a) * r, dLng = (d - b) * r;
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(a * r) * Math.cos(c * r) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(x));
}
function bearingDeg(a, b, c, d) {
  const r = Math.PI / 180;
  const y = Math.sin((d - b) * r) * Math.cos(c * r);
  const x = Math.cos(a * r) * Math.sin(c * r) - Math.sin(a * r) * Math.cos(c * r) * Math.cos((d - b) * r);
  return (Math.atan2(y, x) / r + 360) % 360;
}

/* ================= AUTH ================= */
$('#btnAuth').addEventListener('click', doAuth);
$('#inPass').addEventListener('keydown', (e) => { if (e.key === 'Enter') doAuth(); });

async function doAuth() {
  const err = $('#authErr'); err.style.display = 'none';
  const phone = $('#inPhone').value.trim();
  const pass = $('#inPass').value;
  const btn = $('#btnAuth'); const orig = btn.textContent;
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    const r = await api('api/login', { phone, password: pass });
    if (r && r.csrf) MG.csrf = r.csrf;
    $('#auth').classList.add('hidden');
    await boot();
  } catch (e) {
    err.textContent = e.errors ? Object.values(e.errors)[0][0] : e.message;
    err.style.display = 'block';
  } finally {
    btn.disabled = false; btn.textContent = orig;
  }
}

/* ================= BOOT ================= */
async function start() {
  showKickNotice();
  try {
    const m = await api('api/me');
    if (m.csrf) MG.csrf = m.csrf;
    if (m.authenticated) { me = m.driver; $('#auth').classList.add('hidden'); await boot(); }
    else { $('#auth').classList.remove('hidden'); }
  } catch (e) { $('#auth').classList.remove('hidden'); }
}

async function boot() {
  if (!me) { const m = await api('api/me'); me = m.driver; if (typeof m.push_ok === 'boolean') pushOk = m.push_ok; }
  if (!map) initMap();
  startGeo();
  if (typeof me.commission_pct === 'number') commissionPct = me.commission_pct;
  if (typeof me.min_saldo === 'number') minSaldo = me.min_saldo;
  online = (me.status === 'disponible' || me.status === 'ocupado');
  updateSaldo(me.saldo, me.can_receive);
  $('#sheet').classList.remove('hidden');

  const cur = await api('api/current').catch(() => ({ ride: null }));
  if (cur.ride && ACTIVE.includes(cur.ride.status)) { ride = cur.ride; renderRide(ride); }
  else { renderHome(); }
  startPoll();
  loadZones(); // mostrar los nombres de las zonas locales en el mapa del conductor

  // (el manejo de segundo plano vive junto a pushLocation: beaconLocation / heartbeatNow)

  // preparar/reactivar el audio con cualquier toque (necesario para la alerta sonora de cancelación)
  document.addEventListener('pointerdown', ensureAudio);
}

/* ================= MAPA ================= */
function initMap() {
  mapLight = initialLight();
  map = L.map('map', { zoomControl: false, attributionControl: true }).setView(MG.center, 15);
  baseTile = L.tileLayer(tileUrl(mapLight), {
    attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 20, subdomains: 'abcd',
  }).addTo(map);
  L.control.zoom({ position: 'bottomleft' }).addTo(map);
  // mismos puntos de referencia que ve el pasajero, para hablar el mismo idioma en el chat
  if (window.MGPois) window.MGPois.attach(map);
  $('#btnMenu').addEventListener('click', openDrawer);
  const bell = $('#btnBell'); if (bell) bell.addEventListener('click', openDrawer);
  const bm = $('#btnMapMode'); if (bm) bm.addEventListener('click', toggleMapMode);
  applyMapMode();
    setupDSheetDrag();
  const ro = new ResizeObserver(() => { if (!dSheetDragging) applyDSheetSnap(false); });
  ro.observe($('#sheet'));
}

/* ============ Zonas locales (nombres en el mapa; ayuda al conductor a ubicarse) ============ */
/*
 * Se dibujaban las 63 zonas a la vez y los nombres se pisaban unos con otros. Ahora en cada
 * movimiento se calcula qué cabe en pantalla, con el mismo criterio que los puntos de referencia.
 */
let zoneLayer = null, zoneData = [];
const ZONE_MIN_ZOOM = 13;

function drawZones() {
  if (!map || !zoneLayer) return;
  zoneLayer.clearLayers();

  const z = map.getZoom();
  if (z < ZONE_MIN_ZOOM || !zoneData.length) return;

  const onlyPrimary = z < 16;
  const items = zoneData.filter((s) => (onlyPrimary ? s.primary : true));
  const pin = '<svg class="zpin" viewBox="0 0 24 34"><path d="M12 0C5.4 0 0 5.4 0 12c0 8 12 22 12 22s12-14 12-22C24 5.4 18.6 0 12 0z"/><circle cx="12" cy="12" r="4"/></svg>';

  MGPois.place(map, items, {
    layer: 'zonas',
    max: z >= 16 ? 26 : 14,
    spacing: z >= 16 ? [44, 36] : [58, 46],
    labels: z >= 15,
    maxLabels: z >= 16 ? 14 : 9,
    text: (s) => s.name,
    maxChars: 20,
    labelOffset: 8,
  }).forEach((o) => {
    const s = o.item;
    const label = o.label ? '<span class="zname">' + esc(o.label) + '</span>' : '';
    L.marker([s.lat, s.lng], {
      icon: L.divIcon({
        className: 'zonemk' + (s.primary ? ' zprimary' : ''),
        html: pin + label,
        iconSize: [0, 0], iconAnchor: [0, 0],
      }),
      interactive: false,
      zIndexOffset: s.primary ? 260 : 200,
    }).addTo(zoneLayer);
  });
}

async function loadZones() {
  let d;
  try { d = await api('api/zones'); } catch (e) { return; }
  zoneData = d.zones || [];
  if (!zoneData.length) return;
  if (!zoneLayer) zoneLayer = L.layerGroup().addTo(map);
  map.on('zoomend moveend', drawZones);
  drawZones();
}
function icon(cls, html, size, anchor) {
  return L.divIcon({ className: '', html: '<div class="' + cls + '">' + (html || '') + '</div>', iconSize: size, iconAnchor: anchor });
}
function drawRoute(coords, color) {
  if (routeLine) routeLine.remove();
  if (!coords || !coords.length) return;
  routeLine = L.polyline(coords, { color: color, weight: 5, opacity: .9 }).addTo(map);
}
function setPin(which, p) {
  const ref = which === 'o' ? 'oMarker' : 'dMarker';
  const cls = 'pin ' + which;
  if (which === 'o') {
    if (!oMarker) oMarker = L.marker(p, { icon: icon(cls, '', [26, 26], [13, 26]), interactive: false }).addTo(map);
    else oMarker.setLatLng(p);
  } else {
    if (!dMarker) dMarker = L.marker(p, { icon: icon(cls, '', [26, 26], [13, 26]), interactive: false }).addTo(map);
    else dMarker.setLatLng(p);
  }
}
function clearTrip() {
  if (routeLine) { routeLine.remove(); routeLine = null; }
  if (dMarker) { dMarker.remove(); dMarker = null; }
}

/* ================= GEOLOCALIZACIÓN ================= */
function startGeo() {
  if (!navigator.geolocation) { toast('Tu dispositivo no permite ubicación.'); return; }
  navigator.geolocation.watchPosition((pos) => {
    myPos = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    if (!meMarker) meMarker = L.marker(myPos, { icon: icon('medriver', '<div class="radar"></div><div class="car">' + CAR_SVG + '</div>', [0, 0], [0, 0]), interactive: false, zIndexOffset: 900 }).addTo(map);
    else meMarker.setLatLng(myPos);
    pointCar(pos);
    // primera vez, centrar
    if (!map._centeredOnce) { map.setView(myPos, 16); map._centeredOnce = true; }
    pushLocation();
    if (navOpen) navUpdate(pos);
    checkArrival();
    checkReroute();
  }, () => {}, { enableHighAccuracy: true, maximumAge: 1000, timeout: 12000 });
}

/* ================= MODO NAVEGACIÓN ================= */
function navArrowIcon() {
  return L.divIcon({
    className: '',
    html: '<svg class="navarrow" id="navArrowSvg" viewBox="0 0 24 24"><path d="M12 2.5l7.5 18-7.5-4.3-7.5 4.3z" fill="#FFC107" stroke="#0d0d0d" stroke-width="1.3" stroke-linejoin="round"/></svg>',
    iconSize: [44, 44], iconAnchor: [22, 22],
  });
}

function initNavMap() {
  const base = { zoomControl: false, attributionControl: false };
  try {
    navMap = L.map('navmap', Object.assign({ rotate: true, bearing: 0, rotateControl: false, touchRotate: false, shiftKeyRotate: false }, base));
  } catch (e) { navMap = L.map('navmap', base); }
  navCanRotate = typeof navMap.setBearing === 'function';
  navTile = L.tileLayer(tileUrl(mapLight), { maxZoom: 20, subdomains: 'abcd' }).addTo(navMap);
  navMap.setView(myPos ? [myPos.lat, myPos.lng] : MG.center, 17);
  navMap.on('dragstart', () => { navFollow = false; $('#navRecenter').classList.remove('hidden'); });
}

function drawNavRoute(r) {
  syncActiveRoute(r);
  const toDest = activeRoute.toDest;
  const coords = activeRoute.coords;
  navTargetLL = activeRoute.target;
  if (navLine) { navLine.remove(); navLine = null; }
  if (coords && coords.length) navLine = L.polyline(coords, { color: toDest ? '#00C853' : '#FFC107', weight: 6, opacity: .9 }).addTo(navMap);
  if (!navPin) navPin = L.marker(navTargetLL, { icon: icon(toDest ? 'pin d' : 'pin o', '', [24, 24], [12, 24]), interactive: false }).addTo(navMap);
  else navPin.setLatLng(navTargetLL).setIcon(icon(toDest ? 'pin d' : 'pin o', '', [24, 24], [12, 24]));
  $('#navTo').textContent = toDest ? 'Hacia el destino' : 'Hacia el pasajero';
}

function placeNavCar(p) {
  const ll = [p.lat, p.lng];
  if (!navCar) navCar = L.marker(ll, { icon: navArrowIcon(), interactive: false, zIndexOffset: 1200 }).addTo(navMap);
  else navCar.setLatLng(ll);
}

function navUpdate(pos) {
  if (!navMap) return;
  const cur = { lat: pos.coords.latitude, lng: pos.coords.longitude };
  const gh = pos.coords.heading, sp = pos.coords.speed;

  // rumbo: usar el del GPS si es fiable (con velocidad); si no, calcularlo del desplazamiento
  let h = navBearing;
  if (gh !== null && gh !== undefined && !isNaN(gh) && sp !== null && sp > 0.7) {
    h = gh;
  } else if (navLastLL) {
    const moved = haversineM(navLastLL.lat, navLastLL.lng, cur.lat, cur.lng);
    if (moved > 3) h = bearingDeg(navLastLL.lat, navLastLL.lng, cur.lat, cur.lng);
  }
  navBearing = h;

  // velocidad (km/h)
  let kmh = 0;
  if (sp !== null && sp !== undefined && !isNaN(sp) && sp >= 0) kmh = sp * 3.6;
  else if (navLastLL && navLastT) {
    const dt = (pos.timestamp - navLastT) / 1000;
    if (dt > 0) kmh = haversineM(navLastLL.lat, navLastLL.lng, cur.lat, cur.lng) / dt * 3.6;
  }
  $('#navKmh').textContent = Math.round(Math.max(0, Math.min(kmh, 200)));

  placeNavCar(cur);

  // rotar el mapa hacia el sentido de marcha (marcha = hacia arriba). Si el plugin no está, rotamos la flecha.
  const arrow = document.getElementById('navArrowSvg');
  if (navCanRotate) {
    navMap.setBearing(-h);
    if (arrow) arrow.style.transform = 'rotate(0deg)';
  } else if (arrow) {
    arrow.style.transform = 'rotate(' + h + 'deg)';
  }

  // zoom según velocidad
  const z = kmh > 55 ? 15 : kmh > 30 ? 16 : kmh > 12 ? 17 : 18;
  if (navFollow) navMap.setView([cur.lat, cur.lng], z, { animate: true, duration: 0.55 });

  // distancia al objetivo
  if (navTargetLL) {
    const d = haversineM(cur.lat, cur.lng, navTargetLL[0], navTargetLL[1]);
    $('#navDist').textContent = d >= 1000 ? (d / 1000).toFixed(1) + ' km' : Math.round(d) + ' m';
  }

  navLastLL = cur; navLastT = pos.timestamp;
}

function renderNavAction() {
  const r = ride; if (!r) { $('#navAction').innerHTML = ''; return; }
  const p = r.passenger || {};
  const earn = rideTotal(r) - (r.commission != null ? r.commission : commissionFor(rideTotal(r)));
  $('#navPax').innerHTML = '👤 <b>' + esc(p.name || 'Pasajero') + '</b>';
  $('#navFare').innerHTML = 'Recibes <b>' + money(earn) + '</b>';
  let html = '';
  if (r.status === 'en_camino' || r.status === 'aceptado') html = '<button class="btn amber" id="navPrimary">Llegué al punto</button>';
  else if (r.status === 'llego') html = '<button class="btn" id="navPrimary">Iniciar viaje</button>';
  else if (r.status === 'a_bordo') html = '<button class="btn" id="navPrimary">Finalizar viaje</button>';
  $('#navAction').innerHTML = html;
  const b = $('#navPrimary');
  if (b) b.addEventListener('click', async () => {
    const st = r.status;
    if (st === 'llego') await act('api/start', 'Viaje iniciado. Buen camino.');
    else if (st === 'a_bordo') await completeRide();
    else await act('api/arrive', 'Marcado: llegaste al punto.');
    // el viaje pudo cambiar de estado o terminar
    if (navOpen && ride && ACTIVE.includes(ride.status)) { drawNavRoute(ride); renderNavAction(); if (myPos) navUpdateFromMyPos(); }
    else closeNav();
  });
}

function navUpdateFromMyPos() {
  if (myPos) navUpdate({ coords: { latitude: myPos.lat, longitude: myPos.lng, heading: null, speed: null }, timestamp: Date.now() });
}

function openNav() {
  if (!ride || !ACTIVE.includes(ride.status)) { toast('No hay un viaje activo para navegar.'); return; }
  navOpen = true; navFollow = true; navLastLL = null; navLastT = 0;
  $('#navmode').classList.remove('hidden');
  $('#navRecenter').classList.add('hidden');
  if (!navMap) initNavMap();
  setTimeout(() => { if (navMap) navMap.invalidateSize(); }, 80);
  drawNavRoute(ride);
  renderNavAction();
  navUpdateFromMyPos();
}

function closeNav() {
  navOpen = false;
  $('#navmode').classList.add('hidden');
}
function pushLocation() {
  if (!myPos) return;
  const now = Date.now();
  const onTrip = ride && ACTIVE.includes(ride.status);
  // en viaje reportamos más seguido (ubicación en vivo tipo Uber); en espera, algo más lento
  const minGap = onTrip ? 1500 : 3500;
  if (now - lastPostAt < minGap) return;
  if (!online && !onTrip) return;
  lastPostAt = now;
  api('api/location', { lat: myPos.lat, lng: myPos.lng }).then((r) => {
    if (r && typeof r.saldo === 'number') updateSaldo(r.saldo, r.can_receive);
  }).catch(() => {});
}

/* ---------- Latido cuando la app va y viene de segundo plano ----------
 *
 * Android congela los temporizadores de la app en cuanto el conductor la minimiza, abre
 * WhatsApp o bloquea la pantalla: el latido de tick() deja de correr y no hay forma de
 * evitarlo desde el navegador. Por eso la presencia en el servidor dura horas (ver
 * Dispatch::presenceWindowS) y aquí solo nos aseguramos de dos cosas:
 *   1. dejar la última señal ANTES de que lo congelen (sendBeacon: sale aunque nos maten)
 *   2. avisar en cuanto vuelve, sin esperar los 3 s del sondeo
 */
function beaconLocation() {
  if (!online || !myPos || !navigator.sendBeacon) return;
  try {
    const body = new Blob(
      [JSON.stringify({ lat: myPos.lat, lng: myPos.lng, _token: MG.csrf })],
      { type: 'application/json' }
    );
    navigator.sendBeacon('/conductor/api/location', body);
  } catch (e) {}
}

function heartbeatNow() {
  if (!online) return;
  lastPostAt = 0; // saltarse el mínimo entre envíos: volvimos, hay que avisar ya
  pushLocation();
  if (typeof tick === 'function') tick(); // y traer las carreras que hayan entrado mientras tanto
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') beaconLocation(); else heartbeatNow();
});
window.addEventListener('pagehide', beaconLocation);
window.addEventListener('focus', heartbeatNow);

// App nativa (Capacitor): el evento fiable de "volví a primer plano".
try {
  const CapApp = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
  if (CapApp && CapApp.addListener) {
    CapApp.addListener('appStateChange', (s) => { if (s && s.isActive) heartbeatNow(); else beaconLocation(); });
  }
} catch (e) {}

/* ================= HOME (conectar/desconectar) ================= */
function renderHome() {
  clearTrip();
  closeChat(); chatLastId = 0; chatSeenId = 0; rideLastMsgId = 0; arrivedFor = null;
  const lowSaldo = me.saldo < minSaldo;
  const b = $('#sheetBody');
  b.innerHTML = `
    <div id="homeEssential">
      ${online
        ? `<div class="onlinebar"><span class="odot"></span><span>EN LÍNEA · buscando viajes</span></div>
           <div class="slide off" id="slide"><div class="knob" id="knob"><svg viewBox="0 0 24 24" fill="none" stroke="#e3e8ee" stroke-width="2.6" stroke-linecap="round"><path d="M12 3.2v8.4"/><path d="M6.7 6.7a7.5 7.5 0 1 0 10.6 0"/></svg></div><span class="slidetext" id="slidetext">Desliza para desconectarte</span></div>`
        : `<div class="offlinebar"><span class="odot"></span><span>DESCONECTADO · no recibes viajes</span></div>
           <div class="slide" id="slide"><div class="knob" id="knob"><svg viewBox="0 0 24 24" fill="none" stroke="#5a1414" stroke-width="3" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div><span class="slidetext" id="slidetext">Desliza para conectarte</span></div>`}
      ${lowSaldo ? `<div class="warn red" style="margin-top:12px">⚠️ Tu saldo (${money(me.saldo)}) no alcanza para la comisión mínima de ${money(minSaldo)}. Recarga para recibir viajes.</div>` : ''}
      ${online && pushOk === false ? `<div class="warn red" style="margin-top:12px">🔕 Los avisos con la app cerrada están apagados: solo verás las carreras si tienes MajesGo en pantalla. <button class="minibtn" id="btnFixPush" style="margin-top:8px">Activar avisos</button></div>` : ''}
      <div class="essrow">
        <div class="statcell earn"><div class="sv g" id="stEarn">${dstats ? money(dstats.today_earnings) : '…'}</div><div class="sl">Ganancias del día</div></div>
        <div class="statcell saldocell"><div class="sv a" id="stSaldo">${money(me.saldo)}</div><div class="sl">Saldo</div><button class="minibtn" id="btnRecharge">Recargar</button></div>
      </div>
    </div>
    <div class="statgrid3">
      <div class="statcell"><div class="sv" id="stTrips">${dstats ? dstats.today_trips : '…'}</div><div class="sl">Viajes hoy</div></div>
      <div class="statcell"><div class="sv" id="stHours">${dstats ? dstats.hours_online + ' h' : '…'}</div><div class="sl">Horas conectado</div></div>
      <div class="statcell"><div class="sv" id="stRating">⭐ ${(me.rating || 5).toFixed(1)}</div><div class="sl">Calificación</div></div>
      <div class="statcell"><div class="sv" id="stAcc">${dstats ? (dstats.acceptance_rate === null ? '—' : dstats.acceptance_rate + '%') : '…'}</div><div class="sl">Aceptación</div></div>
    </div>`;

  const slide = $('#slide'); if (slide) setupSlide(); // deslizar sirve para conectarse Y desconectarse
  const rc = $('#btnRecharge'); if (rc) rc.addEventListener('click', openDrawer);
  const fp = $('#btnFixPush'); if (fp) fp.addEventListener('click', fixPush);
  dSheetState = 'peek'; // el home arranca colapsado (~28%); el conductor desliza arriba para ver el detalle
  applyDSheetSnap(false);
  refreshStats();
}

/**
 * Vuelve a intentar activar los avisos con la app cerrada y confirma contra el SERVIDOR.
 *
 * Se comprueba preguntándole al servidor si ya tiene un token/suscripción para este conductor,
 * no si el celular dice que dio permiso: el permiso puede estar dado y el token no haber
 * llegado nunca, y ese caso es indistinguible desde el celular.
 */
async function fixPush() {
  const b = $('#btnFixPush');
  if (b) { b.disabled = true; b.textContent = 'Activando…'; }

  // App nativa: volver a pedir permiso y re-registrar en Firebase.
  try {
    const PN = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;
    if (PN) {
      const p = await PN.checkPermissions();
      if (!p || p.receive !== 'granted') await PN.requestPermissions();
      await PN.register();
    }
  } catch (e) {}
  // Web: suscripción del navegador (la app nativa también la acepta como respaldo).
  try { await enablePush(); } catch (e) {}

  // Darle un momento a Firebase para devolver el token y guardarlo.
  await new Promise((r) => setTimeout(r, 2500));
  await refreshPushState();

  if (pushOk) toast('Listo, ya te avisaremos aunque tengas la app cerrada.');
  else toast('No se pudo activar. Revisa los permisos de notificaciones de MajesGo en los ajustes del celular.');
  renderHome();
}

/** Relee del servidor si ya podemos avisarle con la app cerrada. */
async function refreshPushState() {
  try {
    const d = await api('api/me');
    if (d && typeof d.push_ok === 'boolean') pushOk = d.push_ok;
  } catch (e) {}
}

/* ====== Panel inferior colapsable (peek = esenciales; deslizar arriba = detalle) ====== */
function dPeekHeight() {
  const s = $('#sheet'); const grab = s.querySelector('.grab');
  const ess = document.getElementById('homeEssential');
  if (!ess) return null; // vistas sin "esenciales" (viaje, etc.) → no colapsar
  return (grab ? grab.offsetHeight : 22) + ess.offsetHeight + 14;
}
function applyDSheetSnap(animate) {
  const s = $('#sheet');
  if (!s || s.classList.contains('hidden')) return;
  s.style.transition = (animate === false) ? 'none' : '';
  const h = s.offsetHeight;
  const peek = dPeekHeight();
  const off = (dSheetState === 'peek' && peek) ? Math.max(0, h - peek) : 0;
  s.style.transform = 'translateY(' + off + 'px)';
}
function setupDSheetDrag() {
  const s = $('#sheet');
  const grab = s.querySelector('.grab');
  if (!grab) return;
  let startY = 0, startOff = 0, h = 0, moved = 0, peek = 0;
  grab.addEventListener('pointerdown', (e) => {
    peek = dPeekHeight(); if (!peek) return; // solo colapsable en el home
    dSheetDragging = true; moved = 0; h = s.offsetHeight;
    startY = e.clientY; startOff = dSheetState === 'peek' ? Math.max(0, h - peek) : 0;
    s.style.transition = 'none';
    try { grab.setPointerCapture(e.pointerId); } catch (_) {}
  });
  grab.addEventListener('pointermove', (e) => {
    if (!dSheetDragging) return;
    const dy = e.clientY - startY; moved = Math.max(moved, Math.abs(dy));
    let off = Math.max(0, Math.min(startOff + dy, h - peek));
    s.style.transform = 'translateY(' + off + 'px)';
  });
  const end = (e) => {
    if (!dSheetDragging) return; dSheetDragging = false;
    if (moved < 6) { dSheetState = (dSheetState === 'peek') ? 'open' : 'peek'; }
    else { const off = startOff + (e.clientY - startY); dSheetState = off > (h - peek) / 2 ? 'peek' : 'open'; }
    applyDSheetSnap(true);
  };
  grab.addEventListener('pointerup', end);
  grab.addEventListener('pointercancel', end);
}

async function refreshStats() {
  try {
    dstats = await api('api/stats');
    if (!$('#stEarn')) return; // el home ya no está visible
    $('#stEarn').textContent = money(dstats.today_earnings);
    $('#stTrips').textContent = dstats.today_trips;
    $('#stHours').textContent = dstats.hours_online + ' h';
    $('#stAcc').textContent = dstats.acceptance_rate === null ? '—' : dstats.acceptance_rate + '%';
    $('#stSaldo').textContent = money(dstats.saldo);
    if (typeof dstats.saldo === 'number') me.saldo = dstats.saldo;
  } catch (e) { /* silencioso */ }
}

// Deslizar para conectarse (evita toques accidentales)
function setupSlide() {
  const slide = $('#slide'), knob = $('#knob'), text = $('#slidetext');
  if (!slide || !knob) return;
  let dragging = false, startX = 0, x = 0, max = 0;
  const kw = 52;
  const onDown = (e) => { dragging = true; max = slide.offsetWidth - kw - 8; startX = (e.touches ? e.touches[0].clientX : e.clientX) - x; knob.style.transition = 'none'; try { knob.setPointerCapture(e.pointerId); } catch (_) {} };
  const onMove = (e) => {
    if (!dragging) return;
    x = Math.max(0, Math.min(max, (e.touches ? e.touches[0].clientX : e.clientX) - startX));
    knob.style.transform = 'translateX(' + x + 'px)';
    if (text) text.style.opacity = Math.max(0, 1 - x / max);
  };
  const onUp = () => {
    if (!dragging) return; dragging = false;
    knob.style.transition = 'transform .18s';
    if (x >= max - 4) { knob.style.transform = 'translateX(' + max + 'px)'; toggleOnline(); }
    else { knob.style.transform = 'translateX(0)'; if (text) text.style.opacity = 1; }
    x = 0;
  };
  knob.addEventListener('pointerdown', onDown);
  knob.addEventListener('pointermove', onMove);
  knob.addEventListener('pointerup', onUp);
  knob.addEventListener('pointercancel', onUp);
}

async function toggleOnline() {
  try {
    if (!online) {
      const body = { online: true };
      if (myPos) { body.lat = myPos.lat; body.lng = myPos.lng; }
      const r = await api('api/connect', body);
      online = true; toast('Conectado. Buscando viajes cercanos…');
      if (r && typeof r.push_ok === 'boolean') pushOk = r.push_ok;
      enablePush(); // pedir permiso de notificaciones al conectarse (gesto del usuario)
      // El registro en Firebase tarda un momento; volver a preguntar y repintar el aviso
      // solo si sigue apagado, para no molestar a quien ya lo tiene bien.
      setTimeout(() => refreshPushState().then(() => { if (online && pushOk === false) renderHome(); }), 6000);
    } else {
      await api('api/connect', { online: false });
      online = false; toast('Te desconectaste.');
      // sin esto la lista de viajes se quedaría en pantalla: el sondeo ya no la repinta
      reqList = []; reqSeen = new Set(); hideRequests();
    }
    me.status = online ? 'disponible' : 'desconectado';
    renderHome();
  } catch (e) {
    toast(e.message);
    if (e.status === 422) openDrawer();
    renderHome(); // restaurar el control de deslizar si falló
  }
}

/* ================= POLLING ================= */
function startPoll() { if (poll) clearInterval(poll); tick(); poll = setInterval(tick, 3000); }

async function tick() {
  // modal de cancelación abierto → no hacer nada más (solo mantener presencia) hasta que el conductor continúe
  if (cancelModalOpen) { if (online) pushLocation(); return; }
  // viaje en curso → seguir su estado
  if (ride && ACTIVE.includes(ride.status)) {
    try { const d = await api('api/current'); handleCurrent(d.ride); } catch (e) {}
    return;
  }
  // LATIDO de presencia: mientras esté en línea, reportar ubicación aunque esté quieto,
  // para que 'last_active_at' no envejezca y el despacho lo siga considerando disponible.
  if (online) pushLocation();
  // libre y en línea → buscar solicitudes (y depurar una tarjeta obsoleta)
  if (online) {
    try {
      const d = await api('api/pending');
      if (typeof d.commission_pct === 'number') commissionPct = d.commission_pct;
      counterOptions = (d.counter && d.counter.enabled && Array.isArray(d.counter.options)) ? d.counter.options : [];
      // La lista se repinta en cada sondeo: entran las nuevas y salen las que otro ya tomó.
      // (los viajes activos salieron antes por 'return'; aquí 'ride' es null o ya finalizado)
      renderRequests(d.requests || []);
    } catch (e) {}
  }
}

function handleCurrent(r) {
  if (!r) {
    // teníamos una oferta pendiente y ya no hay viaje → el pasajero eligió otro o no confirmó a tiempo
    if (ride && ride.status === 'ofrecido') {
      if (navOpen) closeNav();
      ride = null; online = true; me.status = 'disponible';
      toast('El pasajero eligió otro conductor o no confirmó a tiempo.');
      renderHome();
    }
    return;
  }
  if (r.status === 'cancelado') {
    // si lo canceló el propio conductor, ya se manejó localmente → volver a home sin modal
    if (r.cancelled_by === 'conductor') { if (navOpen) closeNav(); ackRide(r); ride = null; online = true; me.status = 'disponible'; renderHome(); return; }
    // el PASAJERO canceló: mostrar modal + sonido + vibración + reporte de motivo
    showCancelModal(r);
    return;
  }
  if (r.status === 'completado') { if (navOpen) closeNav(); ride = r; renderCompleted(r); return; }
  const prevStatus = ride && ride.status;
  ride = r; renderRide(r);
  // si el modo navegación está abierto, mantenerlo sincronizado con el nuevo estado
  if (navOpen && ACTIVE.includes(r.status)) {
    drawNavRoute(r);
    if (prevStatus !== r.status) renderNavAction();
  }
}

/* ================= SOLICITUDES DISPONIBLES ================= */

/**
 * Pinta la LISTA de viajes disponibles en el radio. El conductor los ve todos y elige.
 *
 * Se llama en cada sondeo (3 s), así que tiene que ser barata y no pisar lo que el
 * conductor está haciendo: si tiene una ficha abierta, la lista no se repinta debajo.
 */
function renderRequests(reqs) {
  reqList = reqs;
  const codes = reqs.map((r) => r.code);

  // Sonido y vibración SOLO cuando entra un viaje que antes no estaba. Si sonara con la
  // lista entera cada 3 s, el conductor apagaría el volumen y volveríamos al problema
  // de que no se entera de nada.
  const nuevos = codes.filter((c) => !reqSeen.has(c));
  reqSeen = new Set(codes); // olvidar los que ya no están: si vuelven, vuelven a avisar
  if (nuevos.length && !reqCode) rideAlert.start();

  // Ficha abierta: si ese viaje ya no está (lo tomó otro o el pasajero canceló), avisar y
  // devolverlo a la lista en vez de dejarlo mirando una tarjeta muerta.
  if (reqCode) {
    if (!codes.includes(reqCode)) {
      toast('Ese viaje ya fue tomado por otro conductor.');
      closeDetail();
    }
    return; // mientras lee una ficha, no le movemos nada debajo
  }

  if (!reqs.length) { hideRequests(); return; }

  const ord = reqs.slice().sort(reqSort === 'paga'
    ? (a, b) => (b.total_price - a.total_price) || (a.to_pickup_m - b.to_pickup_m)
    : (a, b) => (a.to_pickup_m - b.to_pickup_m) || (b.total_price - a.total_price));

  $('#reqwrap').classList.remove('hidden');
  $('#reqcard').innerHTML = `
    <div class="reqhead">
      <span class="ping"><i></i> ${ord.length} ${ord.length === 1 ? 'viaje disponible' : 'viajes disponibles'}</span>
      <span style="color:var(--muted);font-size:12px">toca uno para verlo</span>
    </div>
    <div class="sortrow">
      <button type="button" class="sortb ${reqSort === 'cerca' ? 'on' : ''}" data-s="cerca">Más cerca</button>
      <button type="button" class="sortb ${reqSort === 'paga' ? 'on' : ''}" data-s="paga">Mejor pagado</button>
    </div>
    <div class="reqlist">
      ${ord.map((r) => reqRow(r)).join('')}
    </div>`;

  $('#reqcard').querySelectorAll('.sortb').forEach((b) => {
    b.addEventListener('click', () => { reqSort = b.dataset.s; renderRequests(reqList); });
  });
  $('#reqcard').querySelectorAll('.reqrow').forEach((row) => {
    row.addEventListener('click', () => {
      const r = reqList.find((x) => x.code === row.dataset.code);
      if (r) showRequest(r);
    });
  });
}

/** Una fila de la lista: lo justo para decidir sin abrir la ficha. */
function reqRow(r) {
  const total = Number(r.total_price != null ? r.total_price : r.offered_price) || 0;
  const recibe = total - commissionFor(total);
  // Recojo: la ZONA le dice más a un conductor de Majes que el número de una calle.
  // Destino: al revés — el nombre del sitio ("Mercado Central") es más claro que su zona ("B2").
  const origen = r.origin_zone || (r.origin.address || 'Punto de recojo').split(',')[0];
  const destino = (r.dest.address || '').split(',')[0] || r.dest_zone || 'Destino';
  return `
    <button type="button" class="reqrow" data-code="${r.code}">
      <span class="rr-money">
        <b>${money(total)}</b>
        <small>recibes ${money(recibe)}</small>
      </span>
      <span class="rr-tx">
        <span class="rr-o">${esc(origen)}<small>a ${km(r.to_pickup_m)} de ti${r.approach_fee > 0 ? ' · +' + money(r.approach_fee) + ' de acercamiento' : ''}</small></span>
        <span class="rr-d">→ ${esc(destino)}<small>${km(r.trip_distance_m)} · ${mins(r.trip_duration_s)} · ${r.payment_method === 'yape' ? 'Yape' : 'efectivo'}</small></span>
      </span>
      <span class="rr-go">›</span>
    </button>`;
}

/* ================= FICHA DE UN VIAJE ================= */
function showRequest(req) {
  reqCode = req.code;
  reqBump = 0; // cada solicitud arranca sin ajuste
  rideAlert.stop(); // ya la está mirando: no tiene sentido seguir sonando
  const wrap = $('#reqwrap'); wrap.classList.remove('hidden');
  // El total incluye el acercamiento hasta el pasajero, calculado con la distancia de ESTE
  // conductor: por eso la cifra grande puede ser mayor que lo que ofreció el pasajero.
  const apFee = Number(req.approach_fee) || 0;
  $('#reqcard').innerHTML = `
    <div class="reqhead">
      <button type="button" class="backlist" id="reqBack">‹ Ver los ${reqList.length} viajes</button>
      <span style="color:var(--muted);font-size:12px">a ${km(req.to_pickup_m)} de ti</span>
    </div>
    <div class="bar"><i id="reqBar"></i></div>
    <div class="fare">
      <div class="n"><span class="cur">${CUR}</span> <span id="reqTotal">0.00</span></div>
      <div class="l">${req.payment_method === 'yape' ? '💜 Pago con Yape' : '💵 Pago en efectivo'}${apFee > 0 ? '' : ` · sugerido ${money(req.suggested_price)}`}</div>
    </div>
    <div class="breakdown hidden" id="reqBd"></div>
    <div class="earnnote" id="reqEarn"></div>
    <div class="earnnote lock" id="reqLock"></div>
    <div class="drv">
      <div class="av">${req.passenger.initial || 'P'}</div>
      <div><div class="nm">${esc(req.passenger.name)}</div><div class="car2">⭐ ${(req.passenger.rating || 5).toFixed(1)} · ${req.passenger.trips || 0} viajes</div></div>
    </div>
    ${req.origin_zone
      ? `<div class="addr"><span class="dot o"></span><div class="tx">📍 ${esc(req.origin_zone)}<small>Recojo · ${esc(req.origin.address || '')} · a ${km(req.to_pickup_m)}</small></div></div>`
      : `<div class="addr"><span class="dot o"></span><div class="tx">${esc(req.origin.address || 'Punto de recojo')}<small>Recojo · a ${km(req.to_pickup_m)}</small></div></div>`}
    ${req.reference ? `<div class="addr"><span class="dot" style="background:var(--amarillo)"></span><div class="tx">${esc(req.reference)}<small>Referencia del pasajero</small></div></div>` : ''}
    <div class="addr"><span class="dot d"></span><div class="tx">${esc(req.dest.address || 'Destino')}<small>Destino · ${km(req.trip_distance_m)} · ${mins(req.trip_duration_s)}</small></div></div>
    ${counterOptions.length
      ? `<div class="bumps" id="reqBumps">
           <div class="bl">¿Te queda justo? Puedes pedir un poco más:</div>
           <div class="brow">
             ${counterOptions.map((b) => `<button type="button" class="bump" data-b="${b}">+ ${CUR} ${Number(b).toFixed(2)}</button>`).join('')}
           </div>
         </div>`
      : ''}
    <div class="acts">
      <button class="btn ghost" id="reqNo">Rechazar</button>
      <button class="btn" id="reqYes">Aceptar</button>
    </div>`;
  paintReq(req);
  // vista previa en el mapa: recojo + destino + RUTA (para que el conductor mire el mapa, no la dirección escrita)
  setPin('o', [req.origin.lat, req.origin.lng]);
  setPin('d', [req.dest.lat, req.dest.lng]);
  drawRoute(req.route_trip, '#00C853');
  // encuadre con margen: arriba para que no se corte la etiqueta, abajo para que la tarjeta no tape los pines
  map.fitBounds(L.latLngBounds([[req.origin.lat, req.origin.lng], [req.dest.lat, req.dest.lng]]), { paddingTopLeft: [30, 66], paddingBottomRight: [30, 410] });
  // durante la oferta: mapa limpio → ocultar las demás zonas y resaltar recojo + destino
  document.getElementById('app').classList.add('offering');
  offerLabels.forEach((m) => m.remove()); offerLabels = [];
  if (req.origin_zone) addOfferLabel([req.origin.lat, req.origin.lng], req.origin_zone, '');
  const destLabel = req.dest_zone || (req.dest.address ? req.dest.address.split(',')[0].trim() : '');
  if (destLabel) addOfferLabel([req.dest.lat, req.dest.lng], destLabel, 'dest');

  $('#reqBack').addEventListener('click', () => closeDetail());
  $('#reqYes').addEventListener('click', () => acceptRequest(req));
  $('#reqNo').addEventListener('click', () => rejectRequest(req.code));
  // los botones de ajuste se activan/desactivan: volver a tocar el elegido lo quita
  document.querySelectorAll('#reqBumps .bump').forEach((b) => {
    b.addEventListener('click', () => {
      const v = Number(b.dataset.b) || 0;
      reqBump = (reqBump === v) ? 0 : v;
      paintReq(req);
    });
  });

  // Cuenta regresiva 28 s para no dejarlo clavado en una ficha. Al vencerse VUELVE A LA LISTA:
  // dudar no puede costarle el viaje. Solo el botón «Rechazar» lo descarta de verdad.
  let left = 28;
  const bar = $('#reqBar');
  clearInterval(reqTimer);
  reqTimer = setInterval(() => {
    left--; if (bar) bar.style.width = Math.max(0, (left / 28) * 100) + '%';
    if (left <= 0) closeDetail();
  }, 1000);
}
/**
 * Repinta las cifras de la tarjeta según el ajuste elegido (reqBump).
 * Solo toca los nodos de importes: la cuenta regresiva y el mapa se quedan como están.
 */
function paintReq(req) {
  const apFee = Number(req.approach_fee) || 0;
  const base  = req.total_price != null ? Number(req.total_price) : Number(req.offered_price) + apFee;
  const total = base + reqBump;
  const com   = commissionFor(total);

  const t = $('#reqTotal'); if (t) t.textContent = total.toFixed(2);

  // desglose: solo aparece si hay algo que desglosar (acercamiento o ajuste)
  const bd = $('#reqBd');
  if (bd) {
    if (apFee > 0 || reqBump > 0) {
      bd.classList.remove('hidden');
      bd.innerHTML =
        `<div><span>Viaje (recojo → destino)</span><b>${money(req.offered_price)}</b></div>` +
        (apFee > 0 ? `<div><span>Tu acercamiento · ${km(req.approach_m != null ? req.approach_m : req.to_pickup_m)}</span><b>+ ${money(apFee)}</b></div>` : '') +
        (reqBump > 0 ? `<div><span>Lo que pides de más</span><b>+ ${money(reqBump)}</b></div>` : '');
    } else {
      bd.classList.add('hidden');
      bd.innerHTML = '';
    }
  }

  const e = $('#reqEarn');
  if (e) e.textContent = `Recibes ${money(total - com)} (comisión ${money(com)} · ${commissionPct}%)`;

  // Con ajuste el precio NO está cerrado todavía: el pasajero puede irse con otro conductor.
  // Decírselo aquí evita que crea que ya ganó la carrera por tocar «+5».
  const lock = $('#reqLock');
  if (lock) {
    lock.classList.toggle('warn', reqBump > 0);
    lock.textContent = reqBump > 0
      ? `El pasajero verá ${money(total)} y puede aceptarte o buscar otro conductor.`
      : `🔒 Precio cerrado: cobras ${money(total)} aunque el viaje demore más.`;
  }

  document.querySelectorAll('#reqBumps .bump').forEach((b) => {
    b.classList.toggle('on', Number(b.dataset.b) === reqBump && reqBump > 0);
  });

  const yes = $('#reqYes');
  if (yes && !yes.disabled) yes.textContent = reqBump > 0 ? `Pedir ${money(total)}` : 'Aceptar';
}

function addOfferLabel(latlng, text, variant) {
  const m = L.marker(latlng, {
    icon: L.divIcon({ className: 'offerzone', html: '<span class="ozlabel ' + variant + '">📍 ' + esc(text) + '</span>', iconSize: [0, 0], iconAnchor: [0, 0] }),
    interactive: false, zIndexOffset: 1300,
  }).addTo(map);
  offerLabels.push(m);
}
/** Limpia la vista previa del mapa (ruta + pines + etiquetas) y devuelve las zonas. */
function clearPreview() {
  if (routeLine) { routeLine.remove(); routeLine = null; }
  if (oMarker) { oMarker.remove(); oMarker = null; }
  if (dMarker) { dMarker.remove(); dMarker = null; }
  offerLabels.forEach((m) => m.remove()); offerLabels = [];
  document.getElementById('app').classList.remove('offering');
}

/**
 * Cierra la ficha y VUELVE A LA LISTA (no descarta el viaje).
 * Toda salida de la ficha pasa por aquí: volver, vencerse, o que otro se lo lleve.
 */
function closeDetail() {
  clearInterval(reqTimer); reqTimer = null; reqCode = null;
  clearPreview();
  // volver a pintar la lista tal como la dejó el último sondeo (ya sin el que se fue).
  // No vuelve a sonar: renderRequests solo avisa por códigos que no estaban antes.
  renderRequests(reqList);
}

/** Esconde TODO el panel de solicitudes (aceptó un viaje, se desconectó o ya no hay nada). */
function hideRequests() {
  clearInterval(reqTimer); reqTimer = null; reqCode = null;
  rideAlert.stop();
  clearPreview();
  $('#reqwrap').classList.add('hidden');
}
async function acceptRequest(req) {
  const btn = $('#reqYes'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  const bump = reqBump; // congelar: el servidor lo valida igual contra la lista de la central
  try {
    const r = await api('api/accept', { code: req.code, bump });
    hideRequests(); // se lo quedó: fuera todo el panel, ahora manda el viaje en curso
    ride = r.ride; online = true; me.status = 'ocupado';
    toast(bump > 0 ? `Enviado con ${money(bump)} más. Esperando al pasajero…` : 'Enviado. Esperando que el pasajero confirme…');
    renderRide(ride);
  } catch (e) {
    // No se lo quedó (409 = se le adelantó otro). Vuelve a la LISTA, que puede tener más
    // viajes esperando: perder uno no debe dejarlo con la pantalla vacía.
    toast(e.status === 409 ? 'Ese viaje ya fue tomado por otro conductor.' : e.message);
    reqList = reqList.filter((x) => x.code !== req.code);
    reqSeen.delete(req.code);
    closeDetail();
    if (!reqList.length) renderHome();
  }
}
async function rejectRequest(code, silent) {
  // Descarte explícito: se lo quitamos de la lista y el servidor no se lo vuelve a ofrecer.
  reqList = reqList.filter((x) => x.code !== code);
  reqSeen.delete(code);
  closeDetail();
  try { await api('api/reject', { code }); } catch (e) {}
  if (!silent) toast('Viaje rechazado.');
  if (!reqList.length) { clearTrip(); if (!ride) renderHome(); }
}

/* ================= VIAJE EN CURSO ================= */
function renderRide(r) {
  // Con un viaje en curso no hay lista que ofrecer. Ojo: esto corre en CADA sondeo, así que
  // no se toca el mapa aquí (clearPreview borraría la ruta que dibujamos abajo y parpadearía).
  reqList = []; reqSeen = new Set(); reqCode = null;
  clearInterval(reqTimer); reqTimer = null;
  rideAlert.stop();
  $('#reqwrap').classList.add('hidden');
  if (typeof r.last_message_id === 'number') rideLastMsgId = r.last_message_id;
  if (r.status === 'ofrecido') { renderWaitingConfirm(r); return; }
  // rutas (usa la ruta activa: recalculada si el conductor se desvió)
  syncActiveRoute(r);
  drawRoute(activeRoute.coords, activeRoute.toDest ? '#00C853' : '#FFC107');
  // pines
  setPin('o', [r.origin.lat, r.origin.lng]);
  setPin('d', [r.dest.lat, r.dest.lng]);
  // encuadre
  const target = r.status === 'a_bordo' ? [r.dest.lat, r.dest.lng] : [r.origin.lat, r.origin.lng];
  const pts = [target]; if (myPos) pts.push([myPos.lat, myPos.lng]);
  if (pts.length > 1) map.fitBounds(L.latLngBounds(pts).pad(0.4), { paddingBottomRight: [0, 300] });
  else map.setView(target, 15);

  const p = r.passenger || {};
  const goingToDest = r.status === 'a_bordo';
  const navTarget = goingToDest ? r.dest : r.origin;
  const earn = rideTotal(r) - (r.commission != null ? r.commission : commissionFor(rideTotal(r)));

  let primary = '';
  if (r.status === 'en_camino' || r.status === 'aceptado') primary = `<button class="btn amber" id="btnArrive">Llegué al punto</button>`;
  else if (r.status === 'llego') primary = `<button class="btn" id="btnStart">Iniciar viaje</button>`;
  else if (r.status === 'a_bordo') primary = `<button class="btn" id="btnComplete">Finalizar viaje</button>`;

  $('#sheetBody').innerHTML = `
    <div class="statusband">${r.status_label}<small>${goingToDest ? 'Lleva al pasajero a su destino' : 'Recoge al pasajero en el punto marcado'}</small></div>
    <div class="drv">
      <div class="av">${p.initial || 'P'}</div>
      <div><div class="nm">${esc(p.name || 'Pasajero')}</div><div class="car2">⭐ ${(p.rating || 5).toFixed(1)} · ${p.trips || 0} viajes</div></div>
      <div class="rate"><b>${money(earn)}</b><small>recibes</small></div>
    </div>
    <div class="addr"><span class="dot o"></span><div class="tx">${esc(r.origin.address || 'Punto de recojo')}<small>Recojo</small></div></div>
    ${r.reference ? `<div class="addr"><span class="dot" style="background:var(--amarillo)"></span><div class="tx">${esc(r.reference)}<small>Referencia del pasajero</small></div></div>` : ''}
    <div class="addr"><span class="dot d"></span><div class="tx">${esc(r.dest.address || 'Destino')}<small>Destino · ${km(r.distance_m)} · ${money(rideTotal(r))} ${r.payment_method === 'yape' ? '(Yape)' : '(efectivo)'}</small></div></div>
    ${primary}
    <div class="acts">
      <button class="btn ghost" id="btnChat">💬 Chat${(rideLastMsgId > chatSeenId && !chatOpen) ? ' <span class="undot"></span>' : ''}</button>
      <button class="btn ghost" id="btnNav">🧭 Navegar</button>
      ${goingToDest ? '' : '<button class="btn danger" id="btnCancel">Cancelar</button>'}
    </div>`;

  const a = $('#btnArrive'); if (a) a.addEventListener('click', () => act('api/arrive', 'Marcado: llegaste al punto.'));
  const s = $('#btnStart'); if (s) s.addEventListener('click', () => act('api/start', 'Viaje iniciado. Buen camino.'));
  const c = $('#btnComplete'); if (c) c.addEventListener('click', completeRide);
  const cc = $('#btnCancel'); if (cc) cc.addEventListener('click', cancelRide);
  const bch = $('#btnChat'); if (bch) bch.addEventListener('click', () => openChat(p.name));
  $('#btnNav').addEventListener('click', openNav);
  // (mantener referencia al destino externo por si se necesita)
  window._extNav = () => window.open('https://www.google.com/maps/dir/?api=1&destination=' + navTarget.lat + ',' + navTarget.lng + '&travelmode=driving', '_blank');
  checkArrival(); // reaplica el resaltado si ya está en el punto
}

function renderWaitingConfirm(r) {
  drawRoute(r.route_to_pickup, '#FFC107');
  setPin('o', [r.origin.lat, r.origin.lng]);
  setPin('d', [r.dest.lat, r.dest.lng]);
  const pts = []; if (myPos) pts.push([myPos.lat, myPos.lng]); pts.push([r.origin.lat, r.origin.lng]);
  if (pts.length > 1) map.fitBounds(L.latLngBounds(pts).pad(0.4), { paddingBottomRight: [0, 260] });
  const p = r.passenger || {};
  $('#sheetBody').innerHTML = `
    <div style="text-align:center;padding:6px 0 2px">
      <div class="spin" style="width:34px;height:34px;border-width:3px;border-top-color:var(--amarillo);margin:8px auto 12px"></div>
      <h2>Esperando confirmación…</h2>
      <div class="statesub" style="margin-top:4px">${esc(p.name || 'El pasajero')} está confirmando tu viaje. Un momento por favor.</div>
    </div>
    ${Number(r.counter_offer) > 0
      ? `<div class="earnnote warn" style="margin:10px 0 0">Le pediste ${money(r.counter_offer)} más. Si no acepta, el viaje pasa a otro conductor.</div>`
      : ''}
    <div class="drv" style="margin-top:8px">
      <div class="av">${p.initial || 'P'}</div>
      <div><div class="nm">${esc(p.name || 'Pasajero')}</div><div class="car2">Recojo: ${esc(r.origin.address || 'Punto marcado')}${r.reference ? ' · ' + esc(r.reference) : ''}</div></div>
      <div class="rate"><b>${money(rideTotal(r))}</b><small>${r.payment_method === 'yape' ? 'Yape' : 'efectivo'}</small></div>
    </div>`;
}

/* Aviso de llegada: cuando el conductor está a <30 m del punto (recojo o destino),
   avisa y resalta el botón de acción para que sea claro qué tocar. */
function checkArrival() {
  if (!ride || !myPos) return;
  let target, label;
  if (ride.status === 'en_camino' || ride.status === 'aceptado') { target = ride.origin; label = '¡Llegaste al punto de recojo!'; }
  else if (ride.status === 'a_bordo') { target = ride.dest; label = '¡Llegaste al destino!'; }
  else return;
  if (!target) return;
  const d = haversineM(myPos.lat, myPos.lng, target.lat, target.lng);
  if (d <= ARRIVE_M) {
    if (arrivedFor !== ride.status) {
      arrivedFor = ride.status;
      toast(label + ' Toca el botón para continuar.');
      if (navigator.vibrate) { try { navigator.vibrate([130, 70, 130]); } catch (e) {} }
    }
    ['btnArrive', 'btnComplete', 'navPrimary'].forEach((id) => { const b = document.getElementById(id); if (b) b.classList.add('pulsebtn'); });
  }
}

async function act(path, msg) {
  const btns = document.querySelectorAll('#sheetBody .btn'); btns.forEach((b) => b.disabled = true);
  try { const r = await api(path, {}); ride = r.ride; toast(msg); renderRide(ride); }
  catch (e) { toast(e.message); btns.forEach((b) => b.disabled = false); }
}

async function completeRide() {
  const b = $('#btnComplete'); b.disabled = true; b.innerHTML = '<span class="spin"></span>';
  try {
    const r = await api('api/complete', {});
    ride = r.ride; if (typeof r.saldo === 'number') { me.saldo = r.saldo; updateSaldo(r.saldo, r.saldo >= minSaldo); }
    renderCompleted(ride);
  } catch (e) { toast(e.message); b.disabled = false; b.textContent = 'Finalizar viaje'; }
}

async function cancelRide() {
  if (!confirm('¿Cancelar este viaje?')) return;
  try { await api('api/cancel', {}); } catch (e) {}
  ride = null; online = true; me.status = 'disponible';
  clearTrip(); toast('Viaje cancelado.'); renderHome();
}

function renderCompleted(r) {
  const fp = r.final_price || rideTotal(r);
  const earn = fp - (r.commission != null ? r.commission : commissionFor(fp));
  $('#sheetBody').innerHTML = `
    <div style="text-align:center"><div style="font-size:42px">✅</div><h2>Viaje completado</h2></div>
    <div class="fare"><div class="n"><span class="cur">${CUR}</span> ${fp.toFixed(2)}</div>
      <div class="l">${r.payment_method === 'yape' ? 'Cobras por Yape' : 'Cobras en efectivo'}</div></div>
    <div class="routeinfo">
      <div class="chip"><div class="v g">${money(earn)}</div><div class="l">Para ti</div></div>
      <div class="chip"><div class="v" style="color:#ff9d9d">- ${money(r.commission != null ? r.commission : commissionFor(fp))}</div><div class="l">Comisión</div></div>
      <div class="chip"><div class="v a">${money(me.saldo)}</div><div class="l">Tu saldo</div></div>
    </div>
    <div class="sub" style="text-align:center">¿Cómo estuvo el pasajero?</div>
    <div class="stars" id="stars">${[1, 2, 3, 4, 5].map((n) => `<span data-n="${n}">★</span>`).join('')}</div>
    <button class="btn amber" id="btnDone">Listo</button>
    <button class="btn ghost" id="btnReport" style="margin-top:8px;color:#ff8a80">Tuve un problema con el pasajero</button>`;
  let chosen = 0;
  const stars = $('#stars').querySelectorAll('span');
  stars.forEach((s) => s.addEventListener('click', () => { chosen = +s.dataset.n; stars.forEach((x, i) => x.classList.toggle('on', i < chosen)); }));
  $('#btnDone').addEventListener('click', async () => {
    try {
      if (chosen) await api('api/rate', { code: r.code, rating: chosen });
      else await api('api/ack', { ride_id: r.id });
    } catch (e) {}
    ride = null; online = true; me.status = 'disponible';
    clearTrip(); renderHome();
  });
  // La denuncia no cierra la pantalla: si además quiere calificar, puede.
  $('#btnReport').addEventListener('click', () => openReportModal(r.code));
}

/* ====== Denuncia al pasajero ======
   Se llega desde el chat (durante el viaje) y desde la pantalla de fin de viaje.
   Google Play exige que exista esta vía dentro de la app; además la central necesita
   el caso escrito, porque por WhatsApp se pierde. */
let reportReason = null;

function openReportModal(code) {
  if (!code) { toast('No hay un viaje para denunciar'); return; }
  reportReason = null;
  const reasons = MG.reportReasons || {};
  $('#reportModal').innerHTML = `
    <div class="modalcard">
      <div class="micon">⚠️</div>
      <h2>Denunciar al pasajero</h2>
      <p class="msub">Cuéntanos qué pasó. La central revisa cada caso y puede suspender la cuenta del pasajero.</p>
      <div class="reasons" id="rpReasons">
        ${Object.keys(reasons).map((k) => `<button class="reason" data-k="${k}">${esc(reasons[k])}</button>`).join('')}
      </div>
      <textarea id="rpDetails" maxlength="600" placeholder="Detalles (opcional)"
        style="width:100%;min-height:78px;margin:4px 0 12px;padding:11px 13px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:#fff;font-family:inherit;font-size:14px;resize:none"></textarea>
      <button class="btn danger" id="rpSend">Enviar denuncia</button>
      <button class="btn ghost" id="rpBack">Cancelar</button>
    </div>`;
  $('#reportModal').classList.remove('hidden');

  const btns = [...document.querySelectorAll('#rpReasons .reason')];
  btns.forEach((b) => b.addEventListener('click', () => {
    reportReason = b.dataset.k;
    btns.forEach((x) => x.classList.toggle('on', x === b));
  }));

  $('#rpBack').addEventListener('click', closeReportModal);
  $('#rpSend').addEventListener('click', async () => {
    if (!reportReason) { toast('Elige un motivo'); return; }
    const details = ($('#rpDetails').value || '').trim();
    // "Otro motivo" sin explicación no le sirve a la central: lo pedimos acá para no
    // hacer ir y volver al servidor.
    if (reportReason === 'otro' && !details) { toast('Cuéntanos brevemente qué pasó'); $('#rpDetails').focus(); return; }
    const b = $('#rpSend'); b.disabled = true; b.textContent = 'Enviando…';
    try {
      await api('api/report', { code, reason: reportReason, details });
      closeReportModal();
      toast('Denuncia enviada. Gracias por avisarnos.');
    } catch (e) {
      b.disabled = false; b.textContent = 'Enviar denuncia';
      toast((e && e.message) || 'No se pudo enviar');
    }
  });
}

function closeReportModal() {
  const m = $('#reportModal'); m.classList.add('hidden'); m.innerHTML = '';
}

function ackRide(r) { if (r && r.id) api('api/ack', { ride_id: r.id }).catch(() => {}); }

/* ================= SALDO / MENÚ ================= */
function updateSaldo(saldo, canReceive) {
  if (typeof saldo !== 'number') return;
  me.saldo = saldo; me.can_receive = canReceive;
  const st = $('#stSaldo'); if (st) st.textContent = money(saldo); // el saldo vive en el panel inferior
}

let rTier = null, saldoData = null;
async function openDrawer() {
  $('#drawer').classList.add('open');
  $('#drawerBody').innerHTML = '<p class="sub" style="text-align:center;color:var(--muted)">Cargando…</p>';
  let d, h;
  try { d = await api('api/saldo'); h = await api('api/history'); }
  catch (e) { $('#drawerBody').innerHTML = '<p class="sub" style="text-align:center">No se pudo cargar.</p>'; return; }
  updateSaldo(d.saldo, d.can_receive);
  const tiers = (d.tiers && d.tiers.length) ? d.tiers : ['20', '50', '100'];
  rTier = null; saldoData = d;

  const p = (d.pending && d.pending.length) ? d.pending[0] : null;
  const pend = p
    ? `<div class="pend">⏳ Recarga en revisión de ${money(p.amount)} (${p.method}). La central la validará pronto.
        ${p.receipt ? `<a class="vlink" href="${p.receipt}" target="_blank" rel="noopener">Ver mi comprobante</a>` : ''}</div>` : '';

  $('#drawerBody').innerHTML = `
    <div class="balcard">
      <div class="n">${money(d.saldo)}</div>
      <div class="l">Saldo disponible</div>
      <div class="canr ${d.can_receive ? 'ok' : 'no'}">${d.can_receive ? '● Puedes recibir viajes' : '● Saldo insuficiente para la comisión'}</div>
    </div>

    <div class="routeinfo">
      <div class="chip"><div class="v a">${money(h.today.earnings)}</div><div class="l">Ganado hoy</div></div>
      <div class="chip"><div class="v">${h.today.trips}</div><div class="l">Viajes hoy</div></div>
      <div class="chip"><div class="v">${d.commission_pct}%</div><div class="l">Comisión</div></div>
    </div>

    <div class="seg">MIS FOTOS</div>
    ${d.photo_block ? `<div class="photoblock">🔒 ${esc(d.photo_block)}</div>` : ''}
    ${photoBox('perfil', d.photos)}
    ${photoBox('vehiculo', d.photos)}

    <div class="seg">RECARGAR SALDO</div>
    ${pend}
    <div class="tiers" id="tiers">${tiers.map((t) => `<button data-t="${t}">${CUR} ${t}</button>`).join('')}</div>
    <input class="field" id="rAmount" type="number" inputmode="decimal" placeholder="Otro monto (${CUR})" min="1">
    <button class="btn amber" id="btnDoRecharge">Continuar al pago</button>
    <div class="paynote" style="margin-top:8px;text-align:center">En el siguiente paso verás a qué número yapear y podrás subir tu comprobante.</div>

    <div class="seg">MOVIMIENTOS</div>
    ${d.movements.length ? d.movements.map((m) => `
      <div class="mv">
        <div class="t">${m.label}<small>${m.desc || ''} · ${m.date}</small></div>
        <div class="a ${m.amount >= 0 ? 'pos' : 'neg'}">${m.amount >= 0 ? '+' : ''}${money(Math.abs(m.amount)).replace(CUR + ' ', CUR + ' ')}<small>saldo ${money(m.balance)}</small></div>
      </div>`).join('') : '<p class="sub" style="color:var(--muted)">Sin movimientos aún.</p>'}

    <div class="seg">MIS VIAJES</div>
    ${h.rides.length ? h.rides.map((r) => `
      <div class="hitem">
        <div class="top"><span>${r.code}</span><span>${r.date}</span></div>
        <div class="rt">📍 ${esc(r.origin || 'Origen')} → 🏁 ${esc(r.dest || 'Destino')}</div>
        <div class="top"><span>${r.status_label}</span><span class="pr">${money(r.price)}</span></div>
      </div>`).join('') : '<p class="sub" style="color:var(--muted)">Aún no tienes viajes.</p>'}
  `;

  const tierBtns = $('#tiers').querySelectorAll('button');
  tierBtns.forEach((b) => b.addEventListener('click', () => {
    rTier = b.dataset.t; $('#rAmount').value = b.dataset.t;
    tierBtns.forEach((x) => x.classList.toggle('on', x === b));
  }));
  $('#rAmount').addEventListener('input', () => { rTier = null; tierBtns.forEach((x) => x.classList.remove('on')); });
  $('#btnDoRecharge').addEventListener('click', goToPayment);

  ['perfil', 'vehiculo'].forEach(bindPhotoBox);
}

/* ---------- Mis fotos (rostro y vehículo) ---------- */
/*
 * Ninguna foto se publica sola: al subirla queda pendiente y la central la aprueba.
 * Mientras tanto se sigue mostrando la que ya estaba aprobada, para que el conductor
 * no se quede sin foto visible por intentar cambiarla.
 */

const PHOTO_META = {
  perfil:   { title: 'Foto de perfil', empty: '🙂', emptyTx: 'Aún no subes tu foto',
              note: 'El pasajero ve tu rostro para reconocerte. Foto de frente, con buena luz y sin lentes oscuros.',
              capture: 'user', cls: 'face' },
  vehiculo: { title: 'Foto del vehículo', empty: '🚗', emptyTx: 'Aún no subes la foto de tu auto',
              note: 'El pasajero la ve para reconocer tu vehículo cuando llegas. De costado y con la placa visible.',
              capture: 'environment', cls: '' },
};

function photoBox(type, photos) {
  const m = PHOTO_META[type];
  const p = (photos && photos[type]) || { status: 'ninguna' };
  const shown = p.pending_url || p.url;

  const chip = {
    pendiente: '<span class="pchip wait">⏳ En revisión por la central</span>',
    aprobada:  '<span class="pchip ok">✓ Aprobada</span>',
    rechazada: '<span class="pchip bad">✕ Rechazada</span>',
    ninguna:   '',
  }[p.status] || '';

  // si le rechazaron un cambio pero sigue teniendo una aprobada, hay que decirle las dos cosas
  const reason = p.reason
    ? `<div class="preason">Motivo: ${esc(p.reason)}${p.url && p.status === 'aprobada' ? '<br>Sigue vigente tu foto aprobada anterior.' : ''}</div>`
    : '';

  return `
    <div class="vehbox" data-ptype="${type}">
      <div class="ptitle">${m.title} ${chip}</div>
      ${shown
        ? `<img class="vehimg ${m.cls}" src="${shown}" alt="${m.title}">`
        : `<div class="vehempty">${p.status === 'rechazada' ? '↻' : m.empty}<span>${p.status === 'rechazada' ? 'Sube una foto nueva' : m.emptyTx}</span></div>`}
      ${p.status === 'pendiente' && p.pending_url ? '<div class="vehnote">Esta es la foto que enviaste. Se publicará cuando la central la apruebe.</div>' : ''}
      ${reason}
      <div class="vehnote">${m.note}</div>
      <input type="file" class="pfile" accept="image/jpeg,image/png,image/webp" capture="${m.capture}" hidden>
      <div class="vehacts">
        <button class="btn ghost pPick">${shown ? 'Cambiar foto' : 'Subir foto'}</button>
        ${shown ? '<button class="btn ghost danger pDel">Quitar</button>' : ''}
      </div>
    </div>`;
}

function bindPhotoBox(type) {
  const box = $(`.vehbox[data-ptype="${type}"]`);
  if (!box) return;
  const file = box.querySelector('.pfile');
  box.querySelector('.pPick').addEventListener('click', () => file.click());
  file.addEventListener('change', (e) => { if (e.target.files[0]) uploadDriverPhoto(type, e.target.files[0], box); });
  const del = box.querySelector('.pDel');
  if (del) del.addEventListener('click', () => deleteDriverPhoto(type, box));
}

/* ---------- Foto del vehículo ---------- */

/**
 * Reduce la foto en el propio celular antes de subirla.
 * Una foto de cámara pesa 5-10 MB; así se sube ~300 KB y no le gasta
 * los megas al conductor ni lo hace esperar con mala señal.
 * Si algo falla, se sube el archivo original tal cual.
 */
function shrinkPhoto(file, maxSide = 1280, quality = 0.85) {
  return new Promise((resolve) => {
    try {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        const scale = Math.min(maxSide / img.width, maxSide / img.height, 1);
        if (scale === 1 && file.size < 900 * 1024) { resolve(file); return; }
        const cv = document.createElement('canvas');
        cv.width = Math.round(img.width * scale);
        cv.height = Math.round(img.height * scale);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        cv.toBlob((blob) => resolve(blob && blob.size < file.size ? blob : file), 'image/jpeg', quality);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    } catch (e) { resolve(file); }
  });
}

async function uploadDriverPhoto(type, file, box) {
  const btn = box.querySelector('.pPick');
  const old = btn.textContent;
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    const small = await shrinkPhoto(file);
    const fd = new FormData();
    fd.append('photo', small, type + '.jpg');
    // FormData va sin Content-Type: el navegador pone el boundary correcto
    const res = await fetch('/conductor/api/photo/' + type, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': MG.csrf },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'No se pudo subir la foto.');
    toast(data.message || 'Foto enviada a revisión.');
    openDrawer();
  } catch (e) {
    toast(e.message);
    btn.disabled = false; btn.textContent = old;
  }
}

async function deleteDriverPhoto(type, box) {
  const btn = box.querySelector('.pDel');
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    await api('api/photo/' + type, null, 'DELETE');
    if (me && type === 'vehiculo') me.vehicle_photo = null;
    toast('Foto eliminada.');
    openDrawer();
  } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = 'Quitar'; }
}

/* ================= PAGO DE LA RECARGA ================= */
/*
 * El conductor primero elige el monto (drawer) y recién aquí ve a dónde pagar.
 * Antes la recarga quedaba "pendiente" sin que él supiera a qué número yapear,
 * así que la central recibía pedidos de recarga sin ningún pago detrás.
 */

let pay = null; // { amount, method, file, previewUrl, confirmed }

function goToPayment() {
  const amount = parseFloat($('#rAmount').value);
  if (!amount || amount < 1) { toast('Elige o escribe cuánto quieres recargar.'); return; }
  if (amount > 1000) { toast(`El máximo por recarga es ${money(1000)}.`); return; }

  const methods = (saldoData && saldoData.payment) || [];
  if (!methods.length) {
    toast('La central aún no cargó sus datos de pago. Comunícate con ella.');
    return;
  }

  pay = { amount, method: methods[0].key, file: null, previewUrl: null, confirmed: false };
  $('#payPanel').classList.add('open');
  renderPay();
}

function closePay() {
  $('#payPanel').classList.remove('open');
  if (pay && pay.previewUrl) URL.revokeObjectURL(pay.previewUrl);
  pay = null;
}

function renderPay() {
  const methods = (saldoData && saldoData.payment) || [];
  const active = methods.find((m) => m.key === pay.method) || methods[0];
  pay.method = active.key;

  const note = (saldoData && saldoData.recharge_note) || '';

  $('#payBody').innerHTML = `
    <div class="payamt">
      <div class="n">${money(pay.amount)}</div>
      <div class="l">Monto a recargar</div>
    </div>

    <div class="paystep"><i>1</i> Paga con el medio que prefieras</div>
    ${methods.length > 1 ? `<div class="pay2" id="payTabs">
      ${methods.map((m) => `<button data-m="${m.key}" class="${m.key === active.key ? 'on' : ''}">${m.icon} ${esc(m.label)}</button>`).join('')}
    </div>` : ''}

    <div class="paycard">
      ${active.fields.map((f, i) => `
        <div class="payrow">
          <div class="pd">
            <div class="pl">${esc(f.label)}</div>
            <div class="pv ${f.big ? 'big' : ''}">${esc(f.value)}</div>
          </div>
          ${f.copy ? `<button class="copybtn" data-copy="${i}">Copiar</button>` : ''}
        </div>`).join('')}
    </div>
    <div class="paynote">Paga exactamente ${money(pay.amount)} desde tu app. ${note ? esc(note) : ''}</div>

    <div class="paystep"><i>2</i> Adjunta tu comprobante</div>
    <input type="file" id="vFile" accept="image/jpeg,image/png,image/webp" hidden>
    ${pay.previewUrl
      ? `<div class="vouchprev"><img src="${pay.previewUrl}" alt="Comprobante"><button class="rm" id="vRemove">Quitar</button></div>
         <button class="btn ghost" id="vPick" style="margin-bottom:10px">Cambiar comprobante</button>`
      : `<div class="vouch"><div class="ic">🧾</div><div class="tx">La captura de tu Yape, Plin o el voucher del banco.<br>Es lo que la central revisa para acreditar tu saldo.</div></div>
         <button class="btn ghost" id="vPick" style="margin-bottom:10px">Adjuntar comprobante</button>`}

    <input class="field" id="vRef" type="text" inputmode="numeric" placeholder="N° de operación (opcional)" maxlength="60" value="${pay.reference ? esc(pay.reference) : ''}">

    ${pay.previewUrl ? '' : `
      <label class="chk">
        <input type="checkbox" id="vConfirm" ${pay.confirmed ? 'checked' : ''}>
        <span>Ya realicé el pago y no tengo el comprobante a la mano. Entiendo que la central puede tardar más en validarlo.</span>
      </label>`}

    <div class="paystep"><i>3</i> Envía tu recarga</div>
    <button class="btn amber" id="vSend" ${(pay.previewUrl || pay.confirmed) ? '' : 'disabled'}>Enviar recarga a revisión</button>
    <div class="paynote" style="margin-top:9px;text-align:center">Tu saldo se acredita cuando la central valide el pago.</div>
  `;

  const tabs = $('#payTabs');
  if (tabs) {
    tabs.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
      pay.reference = $('#vRef').value.trim();
      pay.method = b.dataset.m;
      renderPay(); // el comprobante ya elegido se conserva: vive en `pay`, no en el DOM
    }));
  }

  $('#payBody').querySelectorAll('.copybtn').forEach((b) => b.addEventListener('click', () => {
    copyText(active.fields[+b.dataset.copy].value, b);
  }));

  $('#vPick').addEventListener('click', () => $('#vFile').click());
  $('#vFile').addEventListener('change', async (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (!/^image\//.test(f.type)) { toast('Elige una imagen (captura o foto del voucher).'); return; }
    const small = await shrinkPhoto(f, 1600, 0.88);
    if (pay.previewUrl) URL.revokeObjectURL(pay.previewUrl);
    pay.reference = $('#vRef').value.trim();
    pay.file = small;
    pay.previewUrl = URL.createObjectURL(small);
    renderPay();
  });

  const rm = $('#vRemove');
  if (rm) rm.addEventListener('click', () => {
    URL.revokeObjectURL(pay.previewUrl);
    pay.reference = $('#vRef').value.trim();
    pay.file = null; pay.previewUrl = null;
    renderPay();
  });

  const cf = $('#vConfirm');
  if (cf) cf.addEventListener('change', () => {
    pay.confirmed = cf.checked;
    $('#vSend').disabled = !cf.checked;
  });

  $('#vSend').addEventListener('click', sendRecharge);
}

/** Copia al portapapeles. El WebView de Android a veces no expone navigator.clipboard: por eso el respaldo. */
function copyText(text, btn) {
  const done = () => {
    const old = btn.textContent;
    btn.textContent = '✓ Copiado'; btn.classList.add('done');
    setTimeout(() => { btn.textContent = old; btn.classList.remove('done'); }, 1600);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
  } else {
    fallbackCopy(text, done);
  }
}

function fallbackCopy(text, done) {
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(ta);
    ta.select(); ta.setSelectionRange(0, text.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    ok ? done() : toast('Copia el dato manualmente: ' + text);
  } catch (e) { toast('Copia el dato manualmente: ' + text); }
}

async function sendRecharge() {
  const btn = $('#vSend');
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    const fd = new FormData();
    fd.append('amount', pay.amount);
    fd.append('method', pay.method);
    fd.append('reference', $('#vRef').value.trim());
    fd.append('confirmed', pay.confirmed ? '1' : '0');
    if (pay.file) fd.append('receipt', pay.file, 'comprobante.jpg');

    // FormData va sin Content-Type: el navegador pone el boundary correcto
    const res = await fetch('/conductor/api/recharge', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': MG.csrf },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'No se pudo enviar la recarga.');

    toast(data.message || 'Recarga enviada a revisión.');
    closePay();
    openDrawer();
  } catch (e) {
    toast(e.message);
    btn.disabled = false; btn.textContent = 'Enviar recarga a revisión';
  }
}

$('#payBack').addEventListener('click', closePay);

$('#navClose').addEventListener('click', closeNav);
$('#navRecenter').addEventListener('click', () => {
  navFollow = true;
  $('#navRecenter').classList.add('hidden');
  if (navMap && myPos) navMap.setView([myPos.lat, myPos.lng], navMap.getZoom() || 17, { animate: true });
});

$('#btnBack').addEventListener('click', () => $('#drawer').classList.remove('open'));
$('#btnLogout').addEventListener('click', async () => {
  try { await api('api/logout', {}); } catch (e) {}
  location.reload();
});

/* ================= CHAT con el pasajero ================= */
function openChat(name) {
  chatOpen = true;
  $('#chat').classList.add('open');
  if (name) $('#chatSub').textContent = name;
  chatSeenId = rideLastMsgId;
  loadChat(true);
  clearInterval(chatPoll);
  chatPoll = setInterval(() => loadChat(false), 3000);
  setTimeout(() => { const i = $('#chatIn'); if (i) i.focus(); }, 250);
}
function closeChat() {
  chatOpen = false;
  const c = $('#chat'); if (c) c.classList.remove('open');
  clearInterval(chatPoll); chatPoll = null;
}
async function loadChat(reset) {
  if (reset) { chatLastId = 0; $('#chatBody').innerHTML = ''; }
  let data;
  try { data = await api('api/messages?after=' + chatLastId); } catch (e) { return; }
  const msgs = data.messages || [];
  if (reset && !msgs.length) {
    $('#chatBody').innerHTML = '<div class="cempty">Coordina con el pasajero: confirma el punto de recojo, avísale que ya llegas, etc.</div>';
  }
  appendChat(msgs);
}
function appendChat(msgs) {
  if (!msgs.length) return;
  const body = $('#chatBody');
  const empty = body.querySelector('.cempty'); if (empty) empty.remove();
  const atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 60;
  msgs.forEach((m) => {
    if (m.id > chatLastId) chatLastId = m.id;
    if (m.id > chatSeenId) chatSeenId = m.id;
    const div = document.createElement('div');
    div.className = 'bub ' + (m.mine ? 'me' : 'them');
    div.innerHTML = esc(m.body) + '<span class="tm">' + esc(m.time) + '</span>';
    body.appendChild(div);
  });
  rideLastMsgId = Math.max(rideLastMsgId, chatLastId);
  if (atBottom) body.scrollTop = body.scrollHeight;
}
async function sendChat() {
  const inp = $('#chatIn'); const body = inp.value.trim();
  if (!body) return;
  inp.value = '';
  try {
    const r = await api('api/messages', { body });
    if (r && r.msg) appendChat([r.msg]);
    $('#chatBody').scrollTop = $('#chatBody').scrollHeight;
  } catch (e) { toast(e.message || 'No se pudo enviar.'); inp.value = body; }
}
$('#chatBack').addEventListener('click', closeChat);
$('#chatReport').addEventListener('click', () => openReportModal(ride && ride.code));
$('#chatSend').addEventListener('click', sendChat);
$('#chatIn').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendChat(); });

/* ================= PWA + PUSH ================= */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').then(() => {
    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') enablePush();
  }).catch(() => {}));
}

function urlB64ToUint8(base64) {
  const pad = '='.repeat((4 - base64.length % 4) % 4);
  const b = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b);
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}
async function enablePush() {
  try {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !MG.vapidPublic) return;
    if (typeof Notification === 'undefined') return;
    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') return;
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(MG.vapidPublic) });
    }
    await api('api/push/subscribe', sub.toJSON());
  } catch (e) { /* silencioso */ }
}

start();
