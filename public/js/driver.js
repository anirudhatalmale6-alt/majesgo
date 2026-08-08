/* MajesGo — App del conductor (Hito 3) */
'use strict';

const CUR = MG.currency || 'S/';
const $ = (s) => document.querySelector(s);
const money = (n) => CUR + ' ' + Number(n).toFixed(2);
const km = (m) => (m / 1000).toFixed(1) + ' km';
const mins = (s) => Math.max(1, Math.round(s / 60)) + ' min';

/* ---------- API ---------- */
async function api(path, body, method) {
  const opt = {
    method: method || (body ? 'POST' : 'GET'),
    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': MG.csrf },
  };
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const res = await fetch('/conductor/' + path, opt);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw { status: res.status, message: data.message || 'Ocurrió un error', errors: data.errors };
  return data;
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
let reqCode = null, reqTimer = null, poll = null, lastPostAt = 0, commission = 0.5;
let offerLabels = []; // etiquetas resaltadas (recojo + destino) durante la oferta

// ---- modo navegación (pantalla completa tipo GPS) ----
let navOpen = false, navMap = null, navCar = null, navLine = null, navPin = null;
let navLastLL = null, navLastT = 0, navBearing = 0, navFollow = true, navCanRotate = false, navTargetLL = null;
let chatOpen = false, chatLastId = 0, chatSeenId = 0, chatPoll = null, rideLastMsgId = 0;
let mapLight = false, baseTile = null, navTile = null, arrivedFor = null;
let cancelModalOpen = false, cancelledRide = null, cancelReason = null, audioCtx = null;

/* ---------- Alerta sonora (para la cancelación del pasajero) ---------- */
// Se prepara/reactiva el contexto de audio con un gesto del usuario (tocar la pantalla),
// requisito de los navegadores para poder reproducir sonido después.
function ensureAudio() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
  } catch (e) {}
}
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
  try {
    const m = await api('api/me');
    if (m.csrf) MG.csrf = m.csrf;
    if (m.authenticated) { me = m.driver; $('#auth').classList.add('hidden'); await boot(); }
    else { $('#auth').classList.remove('hidden'); }
  } catch (e) { $('#auth').classList.remove('hidden'); }
}

async function boot() {
  if (!me) { const m = await api('api/me'); me = m.driver; }
  if (!map) initMap();
  startGeo();
  commission = me.commission || 0.5;
  online = (me.status === 'disponible' || me.status === 'ocupado');
  updateSaldo(me.saldo, me.can_receive);
  $('#sheet').classList.remove('hidden');

  const cur = await api('api/current').catch(() => ({ ride: null }));
  if (cur.ride && ACTIVE.includes(cur.ride.status)) { ride = cur.ride; renderRide(ride); }
  else { renderHome(); }
  startPoll();
  loadZones(); // mostrar los nombres de las zonas locales en el mapa del conductor

  // Al volver a la app (desbloqueo/foreground), reactivar presencia y buscar viajes de inmediato:
  // los temporizadores del navegador se pausan en segundo plano y el conductor podría quedar "inactivo".
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { pushLocation(); tick(); }
  });

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
  $('#btnMenu').addEventListener('click', openDrawer);
  const bell = $('#btnBell'); if (bell) bell.addEventListener('click', openDrawer);
  const bm = $('#btnMapMode'); if (bm) bm.addEventListener('click', toggleMapMode);
  applyMapMode();
  map.on('zoomend', applyZoneZoom);
  setupDSheetDrag();
  const ro = new ResizeObserver(() => { if (!dSheetDragging) applyDSheetSnap(false); });
  ro.observe($('#sheet'));
}

/* ============ Zonas locales (nombres en el mapa; ayuda al conductor a ubicarse) ============ */
let zoneLayer = null;
const ZONE_ZOOM_PINS = 13, ZONE_ZOOM_LABELS = 16; // nombres solo al acercar más (evita que se pisen)
function applyZoneZoom() {
  const app = document.getElementById('app');
  if (!app || !map) return;
  const z = map.getZoom();
  app.classList.toggle('zmid', z >= ZONE_ZOOM_PINS && z < ZONE_ZOOM_LABELS);
  app.classList.toggle('znear', z >= ZONE_ZOOM_LABELS);
}
async function loadZones() {
  let d;
  try { d = await api('api/zones'); } catch (e) { return; }
  if (zoneLayer) { zoneLayer.remove(); zoneLayer = null; }
  const zones = d.zones || [];
  if (!zones.length) return;
  zoneLayer = L.layerGroup().addTo(map);
  const pin = '<svg class="zpin" viewBox="0 0 24 34"><path d="M12 0C5.4 0 0 5.4 0 12c0 8 12 22 12 22s12-14 12-22C24 5.4 18.6 0 12 0z"/><circle cx="12" cy="12" r="4"/></svg>';
  zones.forEach((z) => {
    const cls = 'zonemk' + (z.primary ? ' zprimary' : '');
    L.marker([z.lat, z.lng], {
      icon: L.divIcon({ className: cls, html: pin + '<span class="zname">' + esc(z.name) + '</span>', iconSize: [0, 0], iconAnchor: [0, 0] }),
      interactive: false, zIndexOffset: z.primary ? 260 : 200,
    }).addTo(zoneLayer);
  });
  applyZoneZoom();
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
    if (!meMarker) meMarker = L.marker(myPos, { icon: icon('medriver', '<div class="radar"></div><div class="car">🚕</div>', [0, 0], [0, 0]), interactive: false, zIndexOffset: 900 }).addTo(map);
    else meMarker.setLatLng(myPos);
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
  const earn = r.offered_price - (r.commission || commission);
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

/* ================= HOME (conectar/desconectar) ================= */
function renderHome() {
  clearTrip();
  closeChat(); chatLastId = 0; chatSeenId = 0; rideLastMsgId = 0; arrivedFor = null;
  const lowSaldo = me.saldo < commission;
  const b = $('#sheetBody');
  b.innerHTML = `
    <div id="homeEssential">
      ${online
        ? `<div class="onlinebar"><span class="odot"></span><span>EN LÍNEA · buscando viajes</span></div>
           <div class="slide off" id="slide"><div class="knob" id="knob"><svg viewBox="0 0 24 24" fill="none" stroke="#5a1414" stroke-width="2.6" stroke-linecap="round"><path d="M12 3.2v8.4"/><path d="M6.7 6.7a7.5 7.5 0 1 0 10.6 0"/></svg></div><span class="slidetext" id="slidetext">Desliza para desconectarte</span></div>`
        : `<div class="slide" id="slide"><div class="knob" id="knob"><svg viewBox="0 0 24 24" fill="none" stroke="#0f5132" stroke-width="3" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div><span class="slidetext" id="slidetext">Desliza para conectarte</span></div>`}
      ${lowSaldo ? `<div class="warn red" style="margin-top:12px">⚠️ Tu saldo (${money(me.saldo)}) no alcanza para la comisión de ${money(commission)}. Recarga para recibir viajes.</div>` : ''}
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
  dSheetState = 'peek'; // el home arranca colapsado (~28%); el conductor desliza arriba para ver el detalle
  applyDSheetSnap(false);
  refreshStats();
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
      await api('api/connect', body);
      online = true; toast('Conectado. Buscando viajes cercanos…');
      enablePush(); // pedir permiso de notificaciones al conectarse (gesto del usuario)
    } else {
      await api('api/connect', { online: false });
      online = false; toast('Te desconectaste.');
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
      commission = d.commission || commission;
      const reqs = d.requests || [];
      // si la solicitud mostrada ya no existe (el pasajero canceló o la tomó otro), quitarla
      if (reqCode && !reqs.some((x) => x.code === reqCode)) hideRequest();
      // mostrar la solicitud aunque el conductor siga viendo el resumen de un viaje ya terminado
      // (los viajes activos salieron antes por 'return'; aquí 'ride' es null o ya finalizado).
      if (reqs.length && !reqCode) showRequest(reqs[0]);
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

/* ================= SOLICITUD ENTRANTE ================= */
function showRequest(req) {
  reqCode = req.code;
  const wrap = $('#reqwrap'); wrap.classList.remove('hidden');
  const earn = req.offered_price - (commission || 0);
  $('#reqcard').innerHTML = `
    <div class="reqhead">
      <span class="ping"><i></i> Nuevo viaje</span>
      <span style="color:var(--muted);font-size:12px">a ${km(req.to_pickup_m)} de ti</span>
    </div>
    <div class="bar"><i id="reqBar"></i></div>
    <div class="fare">
      <div class="n"><span class="cur">${CUR}</span> ${Number(req.offered_price).toFixed(2)}</div>
      <div class="l">${req.payment_method === 'yape' ? '💜 Pago con Yape' : '💵 Pago en efectivo'} · sugerido ${money(req.suggested_price)}</div>
    </div>
    <div class="earnnote">Recibes ${money(earn)} (comisión ${money(commission)})</div>
    <div class="drv">
      <div class="av">${req.passenger.initial || 'P'}</div>
      <div><div class="nm">${esc(req.passenger.name)}</div><div class="car2">⭐ ${(req.passenger.rating || 5).toFixed(1)} · ${req.passenger.trips || 0} viajes</div></div>
    </div>
    ${req.origin_zone
      ? `<div class="addr"><span class="dot o"></span><div class="tx">📍 ${esc(req.origin_zone)}<small>Recojo · ${esc(req.origin.address || '')} · a ${km(req.to_pickup_m)}</small></div></div>`
      : `<div class="addr"><span class="dot o"></span><div class="tx">${esc(req.origin.address || 'Punto de recojo')}<small>Recojo · a ${km(req.to_pickup_m)}</small></div></div>`}
    ${req.reference ? `<div class="addr"><span class="dot" style="background:var(--amarillo)"></span><div class="tx">${esc(req.reference)}<small>Referencia del pasajero</small></div></div>` : ''}
    <div class="addr"><span class="dot d"></span><div class="tx">${esc(req.dest.address || 'Destino')}<small>Destino · ${km(req.trip_distance_m)} · ${mins(req.trip_duration_s)}</small></div></div>
    <div class="acts">
      <button class="btn ghost" id="reqNo">Rechazar</button>
      <button class="btn" id="reqYes">Aceptar</button>
    </div>`;
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

  $('#reqYes').addEventListener('click', () => acceptRequest(req));
  $('#reqNo').addEventListener('click', () => rejectRequest(req.code));

  // cuenta regresiva 28s
  let left = 28;
  const bar = $('#reqBar');
  clearInterval(reqTimer);
  reqTimer = setInterval(() => {
    left--; if (bar) bar.style.width = Math.max(0, (left / 28) * 100) + '%';
    if (left <= 0) { rejectRequest(req.code, true); }
  }, 1000);
}
function addOfferLabel(latlng, text, variant) {
  const m = L.marker(latlng, {
    icon: L.divIcon({ className: 'offerzone', html: '<span class="ozlabel ' + variant + '">📍 ' + esc(text) + '</span>', iconSize: [0, 0], iconAnchor: [0, 0] }),
    interactive: false, zIndexOffset: 1300,
  }).addTo(map);
  offerLabels.push(m);
}
function hideRequest() {
  clearInterval(reqTimer); reqTimer = null; reqCode = null;
  $('#reqwrap').classList.add('hidden');
  // limpiar la vista previa (ruta + pines + etiquetas) y restaurar las zonas del mapa
  if (routeLine) { routeLine.remove(); routeLine = null; }
  if (oMarker) { oMarker.remove(); oMarker = null; }
  if (dMarker) { dMarker.remove(); dMarker = null; }
  offerLabels.forEach((m) => m.remove()); offerLabels = [];
  document.getElementById('app').classList.remove('offering');
}
async function acceptRequest(req) {
  const btn = $('#reqYes'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    const r = await api('api/accept', { code: req.code });
    hideRequest();
    ride = r.ride; online = true; me.status = 'ocupado';
    toast('Enviado. Esperando que el pasajero confirme…');
    renderRide(ride);
  } catch (e) {
    hideRequest();
    toast(e.status === 409 ? 'Ese viaje ya fue tomado por otro conductor.' : e.message);
    if (!dMarker) { /* limpiar preview */ }
    renderHome();
  }
}
async function rejectRequest(code, silent) {
  hideRequest();
  try { await api('api/reject', { code }); } catch (e) {}
  if (!silent) toast('Viaje rechazado.');
  clearTrip();
  if (!ride) renderHome();
}

/* ================= VIAJE EN CURSO ================= */
function renderRide(r) {
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
  const earn = r.offered_price - (r.commission || commission);

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
    <div class="addr"><span class="dot d"></span><div class="tx">${esc(r.dest.address || 'Destino')}<small>Destino · ${km(r.distance_m)} · ${money(r.offered_price)} ${r.payment_method === 'yape' ? '(Yape)' : '(efectivo)'}</small></div></div>
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
    <div class="drv" style="margin-top:8px">
      <div class="av">${p.initial || 'P'}</div>
      <div><div class="nm">${esc(p.name || 'Pasajero')}</div><div class="car2">Recojo: ${esc(r.origin.address || 'Punto marcado')}${r.reference ? ' · ' + esc(r.reference) : ''}</div></div>
      <div class="rate"><b>${money(r.offered_price)}</b><small>${r.payment_method === 'yape' ? 'Yape' : 'efectivo'}</small></div>
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
    ride = r.ride; if (typeof r.saldo === 'number') { me.saldo = r.saldo; updateSaldo(r.saldo, r.saldo >= commission); }
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
  const earn = (r.final_price || r.offered_price) - (r.commission || commission);
  $('#sheetBody').innerHTML = `
    <div style="text-align:center"><div style="font-size:42px">✅</div><h2>Viaje completado</h2></div>
    <div class="fare"><div class="n"><span class="cur">${CUR}</span> ${Number(r.final_price || r.offered_price).toFixed(2)}</div>
      <div class="l">${r.payment_method === 'yape' ? 'Cobras por Yape' : 'Cobras en efectivo'}</div></div>
    <div class="routeinfo">
      <div class="chip"><div class="v g">${money(earn)}</div><div class="l">Para ti</div></div>
      <div class="chip"><div class="v" style="color:#ff9d9d">- ${money(r.commission || commission)}</div><div class="l">Comisión</div></div>
      <div class="chip"><div class="v a">${money(me.saldo)}</div><div class="l">Tu saldo</div></div>
    </div>
    <div class="sub" style="text-align:center">¿Cómo estuvo el pasajero?</div>
    <div class="stars" id="stars">${[1, 2, 3, 4, 5].map((n) => `<span data-n="${n}">★</span>`).join('')}</div>
    <button class="btn amber" id="btnDone">Listo</button>`;
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
}

function ackRide(r) { if (r && r.id) api('api/ack', { ride_id: r.id }).catch(() => {}); }

/* ================= SALDO / MENÚ ================= */
function updateSaldo(saldo, canReceive) {
  if (typeof saldo !== 'number') return;
  me.saldo = saldo; me.can_receive = canReceive;
  const st = $('#stSaldo'); if (st) st.textContent = money(saldo); // el saldo vive en el panel inferior
}

let rTier = null, rMethod = 'yape';
async function openDrawer() {
  $('#drawer').classList.add('open');
  $('#drawerBody').innerHTML = '<p class="sub" style="text-align:center;color:var(--muted)">Cargando…</p>';
  let d, h;
  try { d = await api('api/saldo'); h = await api('api/history'); }
  catch (e) { $('#drawerBody').innerHTML = '<p class="sub" style="text-align:center">No se pudo cargar.</p>'; return; }
  updateSaldo(d.saldo, d.can_receive);
  const tiers = (d.tiers && d.tiers.length) ? d.tiers : ['20', '50', '100'];
  rTier = null; rMethod = 'yape';

  const pend = (d.pending && d.pending.length)
    ? `<div class="pend">⏳ Recarga pendiente de ${money(d.pending[0].amount)} (${d.pending[0].method}). La central la validará pronto.</div>` : '';

  $('#drawerBody').innerHTML = `
    <div class="balcard">
      <div class="n">${money(d.saldo)}</div>
      <div class="l">Saldo disponible</div>
      <div class="canr ${d.can_receive ? 'ok' : 'no'}">${d.can_receive ? '● Puedes recibir viajes' : '● Saldo insuficiente para la comisión'}</div>
    </div>

    <div class="routeinfo">
      <div class="chip"><div class="v a">${money(h.today.earnings)}</div><div class="l">Ganado hoy</div></div>
      <div class="chip"><div class="v">${h.today.trips}</div><div class="l">Viajes hoy</div></div>
      <div class="chip"><div class="v">${money(d.commission)}</div><div class="l">Comisión</div></div>
    </div>

    <div class="seg">RECARGAR SALDO</div>
    ${pend}
    <div class="tiers" id="tiers">${tiers.map((t) => `<button data-t="${t}">${CUR} ${t}</button>`).join('')}</div>
    <input class="field" id="rAmount" type="number" inputmode="decimal" placeholder="Otro monto (${CUR})" min="1">
    <div class="pay2" id="rPay">
      <button data-m="yape" class="on">💜 Yape</button>
      <button data-m="transferencia">🏦 Transferencia</button>
    </div>
    <input class="field" id="rRef" type="text" placeholder="N° de operación (opcional)">
    ${d.yape_number ? `<div class="statesub" style="margin:-2px 0 10px">Yapea al ${esc(d.yape_number)}${d.yape_holder ? ' · ' + esc(d.yape_holder) : ''}, luego registra tu recarga aquí.</div>` : ''}
    <button class="btn amber" id="btnDoRecharge">Enviar recarga</button>

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
  const payBtns = $('#rPay').querySelectorAll('button');
  payBtns.forEach((b) => b.addEventListener('click', () => { rMethod = b.dataset.m; payBtns.forEach((x) => x.classList.toggle('on', x === b)); }));
  $('#btnDoRecharge').addEventListener('click', doRecharge);
}

async function doRecharge() {
  const amount = parseFloat($('#rAmount').value);
  if (!amount || amount < 1) { toast('Ingresa un monto válido.'); return; }
  const btn = $('#btnDoRecharge'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    const r = await api('api/recharge', { amount, method: rMethod, reference: $('#rRef').value.trim() });
    toast(r.message || 'Recarga enviada.');
    openDrawer();
  } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = 'Enviar recarga'; }
}

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
