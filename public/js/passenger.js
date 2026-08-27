/* MajesGo — App del pasajero (Hito 2) */
'use strict';

const CUR = MG.currency || 'S/';
const $ = (s) => document.querySelector(s);
const money = (n) => CUR + ' ' + Number(n).toFixed(2);
// Total a pagar = tramo A→B + acercamiento del conductor que tomó el viaje.
// Mientras nadie lo tome, approach_fee es 0 y el total es solo el viaje.
function rideTotal(r) {
  if (!r) return 0;
  if (r.total_price != null) return Number(r.total_price);
  return (Number(r.offered_price) || 0) + (Number(r.approach_fee) || 0) + (Number(r.counter_offer) || 0);
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
  const res = await fetch('/app/' + path, opt);
  const data = await res.json().catch(() => ({}));
  // La central cerró la cuenta con la sesión ya abierta. Sin esto la app se quedaría
  // "pensando" para siempre: casi todas las llamadas atrapan el error en silencio y el
  // pasajero no sabría por qué dejó de funcionar. Lo devolvemos a la pantalla de acceso
  // con el motivo a la vista.
  if (res.status === 403 && data.blocked) {
    try { sessionStorage.setItem('mg_blocked', data.message || ''); } catch (_) {}
    location.replace(location.pathname);
    throw { status: 403, message: data.message, blocked: true };
  }
  /*
   * Sesión caída (401). Antes esto solo sacaba un aviso "No autenticado" y la app seguía
   * consultando igual: el mapa quedaba puesto, el pasajero creía que funcionaba y cada
   * llamada fallaba en silencio detrás. Se llegaron a ver 62 llamadas rechazadas seguidas
   * de un mismo teléfono. Ahora lo devolvemos a la pantalla de acceso con un texto que se
   * entiende, y con un toque vuelve a entrar.
   */
  if (res.status === 401) {
    kickToLogin('Tu sesión se cerró. Vuelve a ingresar para seguir pidiendo tu taxi.');
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
  try { sessionStorage.setItem('mg_blocked', msg); } catch (_) {}
  location.replace(location.pathname);
}

/* ---------- Toast ---------- */
let toastT;
function toast(msg) {
  const t = $('#toast'); t.textContent = msg; t.classList.add('show');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2600);
}

/* ---------- Estado ---------- */
let map, oMarker, dMarker, meMarker, routeLine, carMarker;
let origin = null, dest = null, reference = '';
let quote = null, price = null, method = 'efectivo';
let poll = false, pollTimer = null, lastStatus = null, carFrom = null;
let offerTimer = null, offerKey = null;
let meWatchId = null, followMe = true, originPinned = false, lastGeoAt = 0, lastMeLL = null;
let chatOpen = false, chatLastId = 0, chatSeenId = 0, chatPoll = null, rideLastMsgId = 0;
let mapLight = false, baseTile = null;
let sheetState = 'peek', sheetDragging = false; // arranca COMPACTO (solo la barra principal) para ver más mapa
let refOpen = false; // el campo de referencia solo se muestra si el pasajero lo pide
let searchLeft = null; // {seconds_left, timeout} de la búsqueda en curso, tal como lo manda el servidor
const SHEET_PEEK = 96; // respaldo: px visibles si no hay bloque "esencial" para medir

/* Icono de persona/pasajero para la ubicación actual del usuario (se distingue del origen) */
// Personaje 3D de MajesGo (el pasajero con su maleta). El halo azul va debajo, a la altura del
// piso, para que el punto exacto sea donde pisa el personaje y no se confunda con el pin verde.
const ME_ICON = '<div class="mepax"><span class="mehalo"></span>'
  + '<img class="mefig" src="/img/pasajero.png?v=1" alt="Tu ubicación" draggable="false"></div>';
/* Pin de color para los botones "elegir en el mapa" (verde=origen, rojo=destino) */
function pinBtn(color) {
  return '<svg viewBox="0 0 24 34" width="15" height="20" aria-hidden="true"><path fill="' + color + '" stroke="#fff" stroke-width="2.4" d="M12 1C6 1 1.2 5.8 1.2 11.8 1.2 19.6 12 33 12 33s10.8-13.4 10.8-21.2C22.8 5.8 18 1 12 1z"/><circle cx="12" cy="11.8" r="3.9" fill="#fff"/></svg>';
}

let curRide = null; // último viaje recibido (para decidir el tipo de cancelación)
const PAX_CANCEL_REASONS = ['El conductor tarda mucho', 'Ingresé mal la dirección', 'Cambié de planes / Ya no necesito el viaje', 'Otro motivo'];
let paxCancelReason = null;
let nearbyMarkers = {}, nearbyTimer = null; // carritos de taxis disponibles en el mapa
let zoneLayer = null; // etiquetas de las zonas locales en el mapa

/* ---------- modo del mapa (claro de día / oscuro de noche) ---------- */
/**
 * Mosaicos del mapa base. Desde 2026 CARTO estampa "API KEY REQUIRED" sobre cada
 * mosaico servido sin llave: la llave es gratis y va como ?key=. Si aún no está
 * configurada devolvemos la URL sin ella — el mapa sigue funcionando, solo que con
 * la marca de agua. Nunca dejar al pasajero sin mapa por una llave que falta.
 */
function tileUrl(light) {
  const base = light
    ? 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png'
    : 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
  const k = (MG && MG.mapKey) || '';
  return k ? base + '?key=' + encodeURIComponent(k) : base;
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
}
function toggleMapMode() {
  mapLight = !mapLight;
  localStorage.setItem('mg_map_mode', mapLight ? 'light' : 'dark');
  applyMapMode();
}

/* ================= AUTH ================= */
let authMode = 'login';

function toggleAuthMode(e) {
  if (e) e.preventDefault();
  authMode = authMode === 'login' ? 'register' : 'login';
  $('#fName').classList.toggle('hidden', authMode !== 'register');
  $('#btnAuth').textContent = authMode === 'register' ? 'Crear cuenta' : 'Ingresar';
  $('#authToggle').innerHTML = authMode === 'register'
    ? '¿Ya tienes cuenta? <a href="#" id="lnkToggle">Inicia sesión</a>'
    : '¿Primera vez? <a href="#" id="lnkToggle">Crea tu cuenta</a>';
  $('#lnkToggle').addEventListener('click', toggleAuthMode);
  $('#inPass').setAttribute('autocomplete', authMode === 'register' ? 'new-password' : 'current-password');
  $('#authErr').style.display = 'none';
}
$('#lnkToggle').addEventListener('click', toggleAuthMode);

$('#btnAuth').addEventListener('click', doAuth);
$('#inPass').addEventListener('keydown', (e) => { if (e.key === 'Enter') doAuth(); });

async function doAuth() {
  const err = $('#authErr'); err.style.display = 'none';
  const phone = $('#inPhone').value.trim();
  const pass = $('#inPass').value;
  const name = $('#inName').value.trim();
  const btn = $('#btnAuth'); const orig = btn.textContent;
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    let r;
    if (authMode === 'register') {
      r = await api('api/register', { name, phone, password: pass });
    } else {
      r = await api('api/login', { phone, password: pass });
    }
    if (r && r.csrf) MG.csrf = r.csrf;   // el token rota al regenerar la sesión
    $('#auth').classList.add('hidden');
    await boot();
  } catch (e) {
    const msg = e.errors ? Object.values(e.errors)[0][0] : e.message;
    err.textContent = msg; err.style.display = 'block';
  } finally {
    btn.disabled = false; btn.textContent = orig;
  }
}

/* ================= BOOT ================= */
async function start() {
  showBlockedNotice();
  try {
    const me = await api('api/me');
    if (me.csrf) MG.csrf = me.csrf;
    if (me.authenticated) { $('#auth').classList.add('hidden'); await boot(); }
    else { $('#auth').classList.remove('hidden'); }
  } catch (e) { $('#auth').classList.remove('hidden'); }
}

/** Motivo del cierre de cuenta, guardado justo antes de recargar. */
function showBlockedNotice() {
  let msg = null;
  try { msg = sessionStorage.getItem('mg_blocked'); sessionStorage.removeItem('mg_blocked'); } catch (_) {}
  if (!msg) return;
  const err = $('#authErr');
  err.textContent = msg;
  err.style.display = 'block';
  $('#auth').classList.remove('hidden');
}

async function boot() {
  if (!map) initMap();
  $('#sheet').classList.remove('hidden');
  $('#btnLoc').classList.remove('hidden');
  startMeWatch(); // rastreo GPS del pasajero en tiempo real
  // ¿hay un viaje activo?
  const cur = await api('api/rides/current').catch(() => ({ ride: null }));
  if (cur.ride) { startPolling(); }
  else { setDefaultOrigin(); renderPlanning(); }
  startNearby(); // mostrar taxis disponibles cerca en el mapa (se ocultan durante el viaje)
  loadZones();   // mostrar los nombres de las zonas locales en el mapa
}

/* ============ Zonas locales (nombres visibles en el mapa) ============ */
/*
 * 63 zonas con su nombre encima dejaban el mapa ilegible: se dibujaban TODAS a la vez
 * porque las marcadas como principales se saltaban el límite de zoom. Ahora se decide
 * en cada movimiento qué cabe en pantalla, igual que con los puntos de referencia.
 */
const ZONE_MIN_ZOOM = 13;    // más lejos que esto el mapa se lee mejor sin zonas
let zoneData = [];

function drawZones() {
  if (!map || !zoneLayer) return;
  zoneLayer.clearLayers();

  const z = map.getZoom();
  if (z < ZONE_MIN_ZOOM || !zoneData.length) return;

  // de lejos solo las zonas principales y sin nombre; al acercar, nombres y luego todas
  const onlyPrimary = z < 16;
  const withLabels = z >= 15;
  const items = zoneData.filter((s) => (onlyPrimary ? s.primary : true));

  const pin = '<svg class="zpin" viewBox="0 0 24 34"><path d="M12 0C5.4 0 0 5.4 0 12c0 8 12 22 12 22s12-14 12-22C24 5.4 18.6 0 12 0z"/><circle cx="12" cy="12" r="4"/></svg>';

  MGPois.place(map, items, {
    layer: 'zonas',
    max: z >= 16 ? 26 : 14,
    spacing: z >= 16 ? [44, 36] : [58, 46],
    labels: withLabels,
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

/* ============ Taxis disponibles cerca (tiempo real) ============ */
function startNearby() {
  if (nearbyTimer) return;
  loadNearby();
  nearbyTimer = setInterval(loadNearby, 5000);
}
async function loadNearby() {
  if (isRiding()) { clearNearby(); return; } // en viaje ya se muestra el conductor asignado
  const c = origin ? { lat: origin.lat, lng: origin.lng } : (map ? map.getCenter() : null);
  if (!c) return;
  let d;
  try { d = await api('api/drivers/nearby?lat=' + c.lat + '&lng=' + c.lng); } catch (e) { return; }
  if (isRiding()) { clearNearby(); return; }
  const present = {};
  (d.drivers || []).forEach((v) => {
    present[v.id] = 1;
    const ll = [v.lat, v.lng];
    if (nearbyMarkers[v.id]) glideNearby(nearbyMarkers[v.id], ll);
    else nearbyMarkers[v.id] = L.marker(ll, { icon: icon('taxicar', carSvg('n' + v.id), [26, 26], [13, 13]), interactive: false, zIndexOffset: 300 }).addTo(map);
  });
  Object.keys(nearbyMarkers).forEach((id) => { if (!present[id]) { nearbyMarkers[id].remove(); delete nearbyMarkers[id]; } });
  updateNearbyPill(d.count || 0, d.nearest_m);
}
function glideNearby(marker, to) {
  const from = marker.getLatLng();
  faceMarker(marker, 'taxicar', from, to);
  if (marker._an) cancelAnimationFrame(marker._an);
  const t0 = performance.now(), dur = 900;
  const step = (t) => {
    const k = Math.min(1, (t - t0) / dur), e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k;
    marker.setLatLng([from.lat + (to[0] - from.lat) * e, from.lng + (to[1] - from.lng) * e]);
    if (k < 1) marker._an = requestAnimationFrame(step); else marker._an = null;
  };
  marker._an = requestAnimationFrame(step);
}
function clearNearby() {
  Object.values(nearbyMarkers).forEach((m) => m.remove());
  nearbyMarkers = {};
  updateNearbyPill(0, null);
}
function updateNearbyPill(count, nearestM) {
  const pill = $('#nearbyPill'); if (!pill) return;
  if (isRiding() || !count) { pill.classList.add('hidden'); return; }
  let txt = '🚕 ' + count + (count === 1 ? ' taxi disponible cerca' : ' taxis disponibles cerca');
  if (nearestM != null) {
    const min = Math.max(1, Math.round((nearestM / 1000) / 22 * 60)); // ETA aprox. a ~22 km/h urbano
    txt += ' · el más cercano a ~' + min + ' min';
  }
  pill.textContent = txt; pill.classList.remove('hidden');
}

/* ================= MAPA ================= */
/**
 * Acercar / alejar con un dedo. Los que trae Leaflet iban abajo a la izquierda, debajo del
 * panel que sube desde el fondo: estaban ahí pero no se podían tocar. Con el pin central
 * fijo, poder acercar es lo que separa "mi calle" de "mi manzana" al marcar el recojo.
 * Acercar a mano cuenta como mover el mapa: apaga el recentrado automático, igual que
 * arrastrar, para no llevarle el pin de vuelta mientras afina el punto.
 */
function setupZoomButtons() {
  const mas = document.getElementById('btnZoomIn');
  const menos = document.getElementById('btnZoomOut');
  const pintar = () => {
    if (!map || !mas || !menos) return;
    const z = map.getZoom();
    mas.disabled = z >= map.getMaxZoom();
    menos.disabled = z <= map.getMinZoom();
  };
  const paso = (d) => {
    if (!map) return;
    followMe = false;
    map.setZoom(map.getZoom() + d);
  };
  if (mas) mas.addEventListener('click', () => paso(1));
  if (menos) menos.addEventListener('click', () => paso(-1));
  map.on('zoomend', pintar);
  pintar();
}

function initMap() {
  mapLight = initialLight();
  map = L.map('map', { zoomControl: false, attributionControl: true }).setView(MG.center, 15);
  baseTile = L.tileLayer(tileUrl(mapLight), {
    attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 20, subdomains: 'abcd',
  }).addTo(map);
  setupZoomButtons();
  // puntos de referencia (grifos, mercados, hoteles): ayudan al pasajero a ubicarse
  if (window.MGPois) window.MGPois.attach(map);
  const bm = $('#btnMapMode'); if (bm) bm.addEventListener('click', toggleMapMode);
  applyMapMode();

  // Ya NO se fija el punto con un toque en el mapa (causaba chinchetas accidentales al hacer
  // zoom/paneo). Ahora se usa el pin central fijo + botón "Confirmar ubicación" (estilo Uber/InDrive).
  // si el usuario mueve el mapa a mano, dejamos de recentrar automáticamente
  map.on('dragstart', () => { followMe = false; });

  const ro = new ResizeObserver(() => { if (!sheetDragging) applySheetSnap(false); });
  ro.observe($('#sheet'));
  setupSheetDrag();

  $('#btnLoc').addEventListener('click', () => locate(true));
  $('#btnMenu').addEventListener('click', openHistory);
  const cbC = $('#cbConfirm'); if (cbC) cbC.addEventListener('click', () => exitPick(true));
  const cbB = $('#cbBack'); if (cbB) cbB.addEventListener('click', () => exitPick(false));
}

/* ====== Panel inferior arrastrable (colapsa a una barrita para ver el mapa) ====== */
/* Alto visible en modo compacto = agarradera + el bloque "esencial" (título + campos).
   Si la vista no tiene bloque esencial (viaje en curso, etc.) el panel va siempre abierto. */
function sheetPeekPx() {
  const s = $('#sheet'); if (!s) return SHEET_PEEK;
  const grab = s.querySelector('.grab');
  const ess = document.getElementById('planEssential');
  if (!ess) return Math.min(SHEET_PEEK, s.offsetHeight); // sin esencial → prácticamente abierto
  const gh = grab ? grab.offsetHeight : 30;
  return Math.min(s.offsetHeight, gh + ess.offsetHeight + 10);
}
function applySheetSnap(animate) {
  const s = $('#sheet');
  if (!s || s.classList.contains('hidden')) return;
  s.style.transition = (animate === false) ? 'none' : '';
  const h = s.offsetHeight;
  const peek = sheetPeekPx();
  const off = sheetState === 'peek' ? Math.max(0, h - peek) : 0;
  s.style.transform = 'translateY(' + off + 'px)';
  document.documentElement.style.setProperty('--sheet-h', (h - off) + 'px');
}
function setupSheetDrag() {
  const s = $('#sheet');
  const grab = s.querySelector('.grab');
  if (!grab) return;
  let startY = 0, startOff = 0, h = 0, peek = 0, moved = 0;
  grab.addEventListener('pointerdown', (e) => {
    sheetDragging = true; moved = 0; h = s.offsetHeight; peek = sheetPeekPx();
    startY = e.clientY;
    startOff = sheetState === 'peek' ? Math.max(0, h - peek) : 0;
    s.style.transition = 'none';
    try { grab.setPointerCapture(e.pointerId); } catch (_) {}
  });
  grab.addEventListener('pointermove', (e) => {
    if (!sheetDragging) return;
    const dy = e.clientY - startY; moved = Math.max(moved, Math.abs(dy));
    let off = startOff + dy; off = Math.max(0, Math.min(off, h - peek));
    s.style.transform = 'translateY(' + off + 'px)';
    document.documentElement.style.setProperty('--sheet-h', (h - off) + 'px');
  });
  const end = (e) => {
    if (!sheetDragging) return; sheetDragging = false;
    if (moved < 6) { sheetState = (sheetState === 'peek') ? 'open' : 'peek'; }         // toque = alternar
    else { const off = startOff + (e.clientY - startY); sheetState = off > (h - peek) / 2 ? 'peek' : 'open'; }
    applySheetSnap(true);
  };
  grab.addEventListener('pointerup', end);
  grab.addEventListener('pointercancel', end);
}
function openSheet() { if (sheetState !== 'open') { sheetState = 'open'; applySheetSnap(true); } }

/* ---------- Auto en el mapa ----------
 * El dibujo vive en /js/majesgo-car.js, compartido con la app del conductor.
 * En VERDE: el pasajero se ve a si mismo como el circulo azul con la personita,
 * y dos manchas claras sobre un mapa oscuro no se distinguen de un vistazo.
 */
function carSvg(uid) { return mgCarSvg(uid, true); }

/* Rumbo de A a B, para que el auto apunte hacia donde avanza. */
function bearingDeg(a, b) {
  const r = Math.PI / 180;
  const y = Math.sin((b.lng - a.lng) * r) * Math.cos(b.lat * r);
  const x = Math.cos(a.lat * r) * Math.sin(b.lat * r)
          - Math.sin(a.lat * r) * Math.cos(b.lat * r) * Math.cos((b.lng - a.lng) * r);
  return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

/* Gira el auto de un marcador hacia donde se está moviendo.
 * Solo si de verdad se movió (~5 m): entre dos lecturas de GPS casi iguales el
 * rumbo es ruido, y el auto se pondría a girar solo con el taxi parado. */
function faceMarker(marker, cls, from, to) {
  if (!marker || !from || !to) return;
  const a = { lat: from.lat != null ? from.lat : from[0], lng: from.lng != null ? from.lng : from[1] };
  const b = { lat: to.lat != null ? to.lat : to[0], lng: to.lng != null ? to.lng : to[1] };
  if (Math.abs(a.lat - b.lat) < 4.5e-5 && Math.abs(a.lng - b.lng) < 4.5e-5) return;
  const el = marker.getElement && marker.getElement();
  const box = el && el.querySelector('.' + cls);
  if (!box) return;
  // Sin esto, pasar de 350 a 10 grados hace que el auto gire casi una vuelta
  // entera hacia atrás en vez de los 20 grados que de verdad giró.
  let deg = bearingDeg(a, b);
  const prev = box._deg || 0;
  while (deg - prev > 180) deg -= 360;
  while (prev - deg > 180) deg += 360;
  box._deg = deg;
  box.style.transform = 'rotate(' + deg + 'deg)';
}

function icon(cls, html, size, anchor) {
  return L.divIcon({ className: '', html: '<div class="' + cls + '">' + (html || '') + '</div>', iconSize: size, iconAnchor: anchor });
}

function setDefaultOrigin() {
  if (!origin) setOrigin({ lat: MG.center[0], lng: MG.center[1] }, 'Mi ubicación');
  locate(false);
}

function locate(recenter) {
  if (!navigator.geolocation) return;
  if (recenter) { followMe = true; originPinned = false; } // el botón reactiva el seguimiento
  navigator.geolocation.getCurrentPosition((pos) => {
    const p = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    if (!isRiding() && !dest && !originPinned) setOrigin(p);
    updateMe(p);
    if (recenter || !dest) map.setView([p.lat, p.lng], 16);
    reverseGeocode(p).then((a) => { if (a) { origin.address = a; if (!isRiding()) renderPlanning(); } });
  }, () => {
    if (recenter) toast('No pudimos ubicarte. Arrastra el punto verde a tu ubicación.');
  }, { enableHighAccuracy: true, timeout: 8000 });
}

/* Rastreo GPS continuo del pasajero: mantiene su ubicación al día en tiempo real. */
function startMeWatch() {
  if (!navigator.geolocation || meWatchId !== null) return;
  meWatchId = navigator.geolocation.watchPosition(onMePos, () => {}, {
    enableHighAccuracy: true, maximumAge: 1000, timeout: 15000,
  });
}

function onMePos(pos) {
  const p = { lat: pos.coords.latitude, lng: pos.coords.longitude };
  updateMe(p);

  // mientras planifica (aún no fija destino ni ancla el origen), el punto de recojo sigue al usuario
  if (!isRiding() && !dest && !originPinned) {
    setOrigin(p);
    // recentrar solo si el usuario se sale de la zona central (evita "pelear" con el mapa)
    if (followMe && map && !map.getBounds().pad(-0.25).contains([p.lat, p.lng])) {
      map.panTo([p.lat, p.lng], { animate: true, duration: 0.6 });
    }
    // refrescar la dirección del origen por distancia/tiempo (sin geocodificar en cada tick)
    const moved = lastMeLL ? geoDist(lastMeLL, p) : 999;
    const now = performance.now();
    if (moved > 12 && now - lastGeoAt > 6000) {
      lastGeoAt = now;
      reverseGeocode(p).then((a) => {
        if (a && origin) { origin.address = a; const oIn = document.getElementById('oIn'); if (oIn) oIn.value = a; }
      });
    }
  }
  lastMeLL = p;
}

function updateMe(p) {
  // La ubicación actual del usuario se muestra con un icono de persona/pasajero (no un punto),
  // para que NO se confunda con el pin de origen (verde) ni con las referencias.
  if (!meMarker) meMarker = L.marker([p.lat, p.lng], { icon: L.divIcon({ className: '', html: ME_ICON, iconSize: [0, 0], iconAnchor: [0, 0] }), interactive: false, zIndexOffset: 500 }).addTo(map);
  else meMarker.setLatLng([p.lat, p.lng]);
}

/* Muestra el pin de origen (verde) SOLO cuando es un punto elegido a mano o hay un viaje.
   Mientras el recojo simplemente sigue tu ubicación en vivo, basta el icono de persona
   (evita el pin verde encimado sobre tu posición, que era la confusión reportada). */
function reflectOrigin() {
  if (!oMarker) return;
  const show = isRiding() || originPinned;
  oMarker.setOpacity(show ? 1 : 0);
  if (oMarker.dragging) { (show && !isRiding()) ? oMarker.dragging.enable() : oMarker.dragging.disable(); }
}

function geoDist(a, b) {
  const R = 6371000, r = Math.PI / 180;
  const dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r;
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(x));
}

function setOrigin(p, address) {
  origin = { lat: p.lat, lng: p.lng, address: address || (origin && origin.address) };
  if (!oMarker) {
    oMarker = L.marker(p, { icon: icon('pin o', '', [26, 26], [13, 26]), draggable: true }).addTo(map);
    oMarker.on('dragend', () => {
      originPinned = true; // el usuario eligió un punto de recojo a mano → dejamos de seguir el GPS
      const ll = oMarker.getLatLng(); origin = { lat: ll.lat, lng: ll.lng };
      reflectOrigin();
      reverseGeocode(ll).then((a) => { origin.address = a; refreshQuote(); });
    });
  } else oMarker.setLatLng(p);
  reflectOrigin();
}

function setDest(p, address) {
  dest = { lat: p.lat, lng: p.lng, address: address };
  if (!dMarker) {
    dMarker = L.marker(p, { icon: icon('pin d', '', [26, 26], [13, 26]), draggable: true }).addTo(map);
    dMarker.on('dragend', () => {
      const ll = dMarker.getLatLng(); dest = { lat: ll.lat, lng: ll.lng };
      reverseGeocode(ll).then((a) => { dest.address = a; refreshQuote(); });
    });
  } else dMarker.setLatLng(p);
  if (!address) reverseGeocode(p).then((a) => {
    // en zonas sin calle mapeada dos puntos pueden dar el mismo nombre: lo diferenciamos por dirección
    if (origin && origin.address && a && a.toLowerCase() === origin.address.toLowerCase()) {
      a = a + ' (hacia el ' + compassEs(bearingP(origin, dest)) + ')';
    }
    dest.address = a;
    renderPlanning();
  });
  refreshQuote();
}

/* ============ Elegir punto con pin central fijo (estilo Uber/InDrive) ============ */
let pickMode = null, pickGeoT = null;

function enterPick(which) {
  if (isRiding()) return;
  pickMode = which;
  followMe = false;
  let c;
  if (which === 'origin') c = origin ? [origin.lat, origin.lng] : (lastMeLL || MG.center);
  else c = dest ? [dest.lat, dest.lng] : (origin ? [origin.lat, origin.lng] : (lastMeLL || MG.center));
  map.setView(c, Math.max(map.getZoom(), 16), { animate: false });
  if (which === 'origin' && oMarker) oMarker.setOpacity(0);
  if (which === 'dest' && dMarker) dMarker.setOpacity(0);
  $('#sheet').classList.add('hidden');
  $('#btnLoc').classList.add('hidden');
  const cp = $('#centerPin'); if (cp) cp.className = 'centerpin ' + (which === 'origin' ? 'o' : 'd');
  const cd = $('#centerDot'); if (cd) cd.classList.remove('hidden');
  $('#confirmBar').classList.remove('hidden');
  $('#cbTitle').textContent = which === 'origin' ? 'Fija tu punto de recojo' : 'Fija tu destino';
  updatePickAddress();
  map.on('movestart', onPickMoveStart);
  map.on('moveend', onPickMoveEnd);
}
function onPickMoveStart() {
  const cp = $('#centerPin'); if (cp) cp.classList.add('lift');
  $('#cbAddr').textContent = 'Moviendo el mapa…';
}
function onPickMoveEnd() {
  const cp = $('#centerPin'); if (cp) cp.classList.remove('lift');
  updatePickAddress();
}
function updatePickAddress() {
  clearTimeout(pickGeoT);
  $('#cbAddr').textContent = 'Buscando dirección…';
  pickGeoT = setTimeout(() => {
    const c = map.getCenter();
    reverseGeocode({ lat: c.lat, lng: c.lng }).then((a) => {
      if (pickMode) $('#cbAddr').textContent = a || 'Ubicación marcada en el mapa';
    });
  }, 350);
}
function exitPick(confirmed) {
  const which = pickMode; pickMode = null;
  clearTimeout(pickGeoT);
  map.off('movestart', onPickMoveStart);
  map.off('moveend', onPickMoveEnd);
  $('#confirmBar').classList.add('hidden');
  const cp = $('#centerPin'); if (cp) cp.className = 'centerpin hidden';
  const cd = $('#centerDot'); if (cd) cd.classList.add('hidden');
  if (oMarker) oMarker.setOpacity(1);
  if (dMarker) dMarker.setOpacity(1);
  $('#sheet').classList.remove('hidden');
  $('#btnLoc').classList.remove('hidden');
  if (confirmed) {
    const c = map.getCenter();
    const p = { lat: c.lat, lng: c.lng };
    if (which === 'origin') {
      originPinned = true;
      setOrigin(p);
      reverseGeocode(p).then((a) => { if (origin) { origin.address = a; } refreshQuote(); renderPlanning(); });
    } else {
      setDest(p); // setDest ya hace reverseGeocode + refreshQuote + renderPlanning
    }
  } else {
    renderPlanning();
  }
}

/* ============ Geocoding: proxy (Google) con respaldo Nominatim (OSM) ============ */
async function reverseGeocode(p) {
  // 1) nuestro proxy (usa Google si la clave está activa)
  try {
    const g = await api('api/geocode/reverse?lat=' + p.lat + '&lng=' + p.lng);
    if (g && g.label) return g.label;
  } catch (e) { /* sin proxy → respaldo */ }
  // 2) respaldo Nominatim (OSM): zoom=18 + namedetails para el punto específico
  try {
    const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${p.lat}&lon=${p.lng}&format=jsonv2&zoom=18&addressdetails=1&namedetails=1&accept-language=es`);
    const d = await r.json();
    return placeLabel(d) || 'Punto en el mapa';
  } catch (e) { return 'Punto en el mapa'; }
}

/* Arma una etiqueta específica: POI/negocio + calle, o calle (+ número), o la zona. */
function placeLabel(d) {
  if (!d) return null;
  const a = d.address || {};
  const num  = a.house_number;
  const road = a.road || a.pedestrian || a.footway || a.path || a.residential || a.cycleway;
  const poi  = a.amenity || a.shop || a.tourism || a.leisure || a.office || a.building || a.craft || a.club;
  const zone = a.neighbourhood || a.quarter || a.city_block || a.hamlet || a.suburb || a.village;

  let primary = poi
    || (road ? (num ? road + ' ' + num : road) : null)
    || zone
    || (d.namedetails && d.namedetails.name)
    || a.town || a.city || null;

  let secondary = poi ? (road || zone) : (zone || a.town || a.city || a.county);
  if (secondary && primary && (secondary === primary || primary.includes(secondary))) secondary = null;

  const label = [primary, secondary].filter(Boolean).slice(0, 2).join(', ');
  if (label) return label;
  return d.display_name ? d.display_name.split(',').slice(0, 2).join(',').trim() : null;
}

/* Rumbo entre dos puntos (grados) y su punto cardinal en español. */
function bearingP(a, b) {
  const r = Math.PI / 180;
  const y = Math.sin((b.lng - a.lng) * r) * Math.cos(b.lat * r);
  const x = Math.cos(a.lat * r) * Math.sin(b.lat * r) - Math.sin(a.lat * r) * Math.cos(b.lat * r) * Math.cos((b.lng - a.lng) * r);
  return (Math.atan2(y, x) / r + 360) % 360;
}
function compassEs(brg) {
  return ['norte', 'noreste', 'este', 'sureste', 'sur', 'suroeste', 'oeste', 'noroeste'][Math.round((brg % 360) / 45) % 8];
}
function renderSearchResults(box, items) {
  if (!items.length) {
    box.innerHTML = '<div class="nohit">No encontramos ese lugar. Toca el punto en el mapa 👆 y lo marcamos exacto.</div>';
    return;
  }
  box.innerHTML = items.map((x) => `<div data-lat="${x.lat}" data-lon="${x.lng}"><div class="t">${esc(x.title)}</div><div class="s">${esc(x.sub || '')}</div></div>`).join('');
  box.querySelectorAll('div[data-lat]').forEach((el) => el.addEventListener('click', () => {
    const p = { lat: +el.dataset.lat, lng: +el.dataset.lon };
    setDest(p, el.querySelector('.t').textContent);
    map.setView([p.lat, p.lng], 16); box.innerHTML = '';
    if (origin) map.fitBounds(L.latLngBounds([[origin.lat, origin.lng], [p.lat, p.lng]]).pad(0.3));
  }));
}

// Un solo query a Nominatim (OSM). bias = usar el recuadro solo como preferencia (no recorta).
async function nominatim(q, biasOnly) {
  const vb = `${MG.center[1] - 0.16},${MG.center[0] + 0.13},${MG.center[1] + 0.16},${MG.center[0] - 0.13}`;
  const bounded = biasOnly ? '0' : '1';
  const r = await fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(q)}&format=json&limit=8&addressdetails=1&accept-language=es&countrycodes=pe&viewbox=${vb}&bounded=${bounded}`);
  return r.ok ? await r.json() : [];
}

let searchT, searchSeq = 0;
async function searchPlaces(q, box) {
  clearTimeout(searchT);
  if (q.length < 3) { box.innerHTML = ''; return; }
  const seq = ++searchSeq;
  searchT = setTimeout(async () => {
    box.innerHTML = '<div class="nohit">Buscando “' + esc(q) + '”…</div>';
    // 1) proxy (Google): mejores resultados en Perú (cuando la facturación esté activa)
    try {
      const g = await api('api/geocode/search?q=' + encodeURIComponent(q));
      if (seq !== searchSeq) return;
      if (g && g.results && g.results.length) {
        renderSearchResults(box, g.results.map((x) => ({ lat: x.lat, lng: x.lng, title: x.label, sub: x.full })));
        return;
      }
    } catch (e) { /* sin proxy → respaldo */ }
    // 2) respaldo Nominatim (OSM): local estricto → luego ampliado con contexto, y unimos.
    try {
      const seen = new Set(); const items = [];
      const push = (arr) => arr.forEach((x) => {
        const k = (+x.lat).toFixed(4) + ',' + (+x.lon).toFixed(4);
        if (seen.has(k)) return; seen.add(k);
        const parts = (x.display_name || '').split(',').map((s) => s.trim());
        items.push({ lat: +x.lat, lng: +x.lon, title: parts[0] || 'Lugar', sub: parts.slice(1, 3).join(', ') });
      });
      push(await nominatim(q, false));                                   // estricto dentro de Majes
      if (items.length < 4) push(await nominatim(q + ', Majes, Arequipa', true)); // ampliado con contexto (bias)
      if (seq !== searchSeq) return;
      renderSearchResults(box, items.slice(0, 8));
    } catch (e) { if (seq === searchSeq) renderSearchResults(box, []); }
  }, 400);
}

/* ============ Cotización ============ */
let quoteT;
function refreshQuote() {
  if (isRiding()) return; // durante un viaje el precio ya está pactado: no se vuelve a cotizar
  if (!origin || !dest) { quote = null; renderPlanning(); return; }
  clearTimeout(quoteT);
  quoteT = setTimeout(async () => {
    try {
      quote = await api('api/quote', { origin_lat: origin.lat, origin_lng: origin.lng, dest_lat: dest.lat, dest_lng: dest.lng });
      price = quote.suggested;
      drawRoute(quote.geometry, '#00C853');
      if (origin && dest) map.fitBounds(L.latLngBounds([origin, dest]).pad(0.35), { paddingBottomRight: [0, 260] });
      renderPlanning();
    } catch (e) { toast(e.message); }
  }, 350);
}

function drawRoute(coords, color) {
  if (routeLine) routeLine.remove();
  if (!coords || !coords.length) return;
  routeLine = L.polyline(coords, { color: color, weight: 5, opacity: .9 }).addTo(map);
}

/* ================= SHEET: PLANIFICAR ================= */
/**
 * Costo de aproximación estimado para el viaje que se está armando.
 *
 * El monto real depende del conductor que termine tomando la carrera; aquí se muestra el del
 * conductor libre más cercano ahora mismo, y se avisa que puede variar. El monto definitivo
 * aparece desglosado en la pantalla de confirmación, antes de aceptar.
 */
function approachFee() {
  const a = quote && quote.approach;
  return (a && a.enabled) ? (Number(a.fee) || 0) : 0;
}

/**
 * Aviso de que el total todavía puede moverse un poco.
 *
 * DECISIÓN DE NEGOCIO (2026-08-13, la central con sus socios): al pasajero NO se le detalla
 * el costo de aproximación. Ni el monto, ni los km del conductor. Se sigue cobrando igual y el
 * pasajero ve el TOTAL antes de aceptar; lo que desaparece es el renglón que lo desglosaba.
 * Por eso aquí no se nombra el recojo: solo se avisa que el total se confirma más adelante,
 * para que la cifra del botón no cambie de golpe sin explicación.
 */
function approachHint() {
  const a = quote && quote.approach;
  if (!a || !a.enabled || !approachFee()) return '';
  return `<div class="hintprice">El total final lo confirmas cuando aparezca tu conductor.</div>`;
}

/** Texto del botón principal: viaje + recojo estimado. Una sola fórmula para los dos sitios. */
function requestLabel() {
  const ap = approachFee();
  return `Buscar taxi · ${money(price + ap)}${ap > 0 ? ' aprox.' : ''}`;
}

/**
 * Lo que se le muestra al pasajero como "el viaje": su oferta CON el acercamiento ya sumado.
 *
 * No se puede simplemente borrar el renglón del recojo y dejar los demás: las cifras dejarían
 * de sumar (10.80 + 3.00 no da 14.30) y eso se lee como un cobro de más. Se integra en el
 * precio del viaje, que es lo que el pasajero entiende como la carrera.
 */
function tripLine(r) {
  return (Number(r.offered_price) || 0) + (Number(r.approach_fee) || 0);
}

function renderPlanning() {
  // Con un viaje en curso NUNCA se pinta la pantalla de "¿A dónde vamos?": taparía la oferta
  // o el seguimiento del conductor. Pasaba al abrir la app con un viaje ya empezado:
  // renderRide() coloca el destino en el mapa, eso dispara una cotización y, al volver,
  // repintaba la planificación encima del viaje. (resetAfterRide corta el sondeo antes
  // de llamar aquí, así que al terminar un viaje esto no estorba.)
  if (isRiding()) return;
  const b = $('#sheetBody');
  const hasRoute = quote && dest;
  if (hasRoute) sheetState = 'open'; // con ruta lista se abre para ver precio y "Buscar taxi"
  // Con ruta el botón «Buscar taxi» va fijo al pie del panel (ver .sheetcta): antes quedaba
  // debajo del corte en pantallas altas y el pasajero tenía que descubrir que se desplazaba.
  b.classList.toggle('hascta', !!hasRoute);
  const showRef = !!reference || refOpen;
  b.innerHTML = `
    <div id="planEssential">
      <h2>¿A dónde vamos?</h2>
      <div class="fieldgroup">
        <div class="fieldrow"><span class="dot o"></span><div class="fcol"><label class="flbl" for="oIn">¿Dónde te recogemos?</label><input id="oIn" value="${(origin && origin.address) ? esc(origin.address) : 'Mi ubicación'}" placeholder="Tu punto de recojo"></div><button class="mapbtn o" id="oMap" title="Elegir el recojo en el mapa" aria-label="Elegir el recojo en el mapa">${pinBtn('#00C853')}</button></div>
        <div class="sugg">
          <div class="fieldrow"><span class="dot d"></span><div class="fcol"><label class="flbl" for="dIn">¿A dónde vas?</label><input id="dIn" placeholder="Escríbelo o elígelo en el mapa" value="${dest && dest.address ? esc(dest.address) : ''}"></div><button class="mapbtn d" id="dMap" title="Elegir destino en el mapa" aria-label="Elegir destino en el mapa">${pinBtn('#ff5252')}</button></div>
          <div class="suggbox" id="sugg"></div>
        </div>
        ${showRef ? `<div class="fieldrow"><span class="dot" style="background:#FFC107"></span><div class="fcol"><label class="flbl" for="refIn">Referencia del recojo (opcional)</label><input id="refIn" placeholder="Casa, color, algo cercano…" value="${reference ? esc(reference) : ''}"></div></div>` : ''}
      </div>
      ${showRef ? '' : '<button type="button" class="linkbtn" id="refToggle">+ Agregar una referencia del recojo</button>'}
    </div>
    ${hasRoute ? `
      <div class="metaline">${(quote.distance_m / 1000).toFixed(1)} km · ${Math.max(1, Math.round(quote.duration_s / 60))} min aprox.</div>
      <div class="prow"><span class="lbl">Tu precio</span>
        <div class="stepper">
          <button id="minus">−</button>
          <span class="price" id="priceLbl">${money(price)}</span>
          <button id="plus">+</button>
        </div>
      </div>
      <div class="hintprice">Sugerido ${money(quote.suggested)} · desde ${money(quote.floor)}</div>
      ${approachHint()}
      <div class="pricelock">🔒 Precio fijo: pagas este monto al llegar.</div>
      <div class="pay">
        <button data-m="efectivo" class="${method === 'efectivo' ? 'on' : ''}">💵 Efectivo</button>
        <button data-m="yape" class="${method === 'yape' ? 'on' : ''}">💜 Yape</button>
      </div>
      <div class="sheetcta"><button class="btn" id="btnReq">${requestLabel()}</button></div>
    ` : `
      <button class="btn ghost" id="pickDest" style="margin-top:10px"><span class="btnpin">${pinBtn('#ff5252')}</span> Elegir destino en el mapa</button>
      <div class="hintprice" style="margin-top:8px">O escríbelo arriba, o toca el pin del campo Destino para elegirlo en el mapa.</div>`}
  `;

  const oMap = $('#oMap'); if (oMap) oMap.addEventListener('click', () => enterPick('origin'));
  const dMap = $('#dMap'); if (dMap) dMap.addEventListener('click', () => enterPick('dest'));
  const pickDest = $('#pickDest'); if (pickDest) pickDest.addEventListener('click', () => enterPick('dest'));
  const dIn = $('#dIn'); if (dIn) {
    dIn.addEventListener('focus', openSheet);   // al tocar el destino, se abre el panel (búsqueda + teclado)
    dIn.addEventListener('input', () => searchPlaces(dIn.value.trim(), $('#sugg')));
  }
  const oIn = $('#oIn'); if (oIn) {
    oIn.addEventListener('focus', openSheet);
    oIn.addEventListener('input', () => {
      originPinned = true;                 // si edita el texto, dejamos de sobrescribirlo con el GPS
      if (origin) origin.address = oIn.value;
      reflectOrigin();
    });
  }
  const refIn = $('#refIn'); if (refIn) refIn.addEventListener('input', () => { reference = refIn.value; });
  // La referencia es opcional y casi nadie la llena: se muestra solo si la piden (o si ya tiene texto)
  const refT = $('#refToggle'); if (refT) refT.addEventListener('click', () => {
    refOpen = true; openSheet(); renderPlanning();
    const el = $('#refIn'); if (el) el.focus();
  });
  if (hasRoute) {
    $('#minus').addEventListener('click', () => bump(-0.5));
    $('#plus').addEventListener('click', () => bump(0.5));
    b.querySelectorAll('.pay button').forEach((el) => el.addEventListener('click', () => {
      method = el.dataset.m; renderPlanning();
    }));
    $('#btnReq').addEventListener('click', doRequest);
  }
  requestAnimationFrame(() => applySheetSnap(false)); // reubica el panel según su nuevo alto/estado
}
function bump(d) {
  price = Math.max(quote.floor, Math.round((price + d) * 2) / 2);
  $('#priceLbl').textContent = money(price);
  // requestLabel() y no money(price) a secas: si no, tocar +/− borraba el recojo del botón
  // y el pasajero veía un total distinto al que iba a pagar.
  $('#btnReq').textContent = requestLabel();
}
function esc(s) { return (s || '').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

async function doRequest() {
  const btn = $('#btnReq'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  enablePush(); // pedir permiso de avisos al pedir el taxi (gesto del usuario)
  try {
    await api('api/rides', {
      origin_lat: origin.lat, origin_lng: origin.lng, origin_address: origin.address || 'Mi ubicación',
      reference: (reference || '').trim() || null,
      dest_lat: dest.lat, dest_lng: dest.lng, dest_address: dest.address || 'Destino',
      offered_price: price, payment_method: method,
    });
    startPolling();
  } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = requestLabel(); }
}

/* ================= POLLING / VIAJE ================= */
function isRiding() { return !!poll; }
const ACTIVE_ST = ['ofrecido', 'aceptado', 'en_camino', 'llego', 'a_bordo'];

function startPolling() {
  lastStatus = null;
  poll = true;
  clearTimeout(pollTimer);
  clearNearby(); // durante el viaje se muestra solo el conductor asignado
  tick();
}
function stopPolling() { poll = false; clearTimeout(pollTimer); }
function ackRide(r) { if (r && r.id) api('api/rides/ack', { ride_id: r.id }).catch(() => {}); }

async function tick() {
  if (!poll) return;
  let data;
  try { data = await api('api/rides/current'); }
  catch (e) { if (poll) pollTimer = setTimeout(tick, 2500); return; }
  const r = data.ride;
  if (!r) { stopPolling(); resetAfterRide(); return; }
  searchLeft = data.search ? data.search : null; // cuánto le queda a la búsqueda (si está buscando)
  renderRide(r);
  if (!poll) return;                         // renderRide pudo detener el sondeo (fin de viaje)
  // en viaje sondeamos más seguido para una ubicación más fluida
  pollTimer = setTimeout(tick, ACTIVE_ST.includes(r.status) ? 1600 : 2500);
}

function renderRide(r) {
  curRide = r;
  sheetState = 'open';   // durante el viaje el panel va abierto (info del conductor / estado)
  if (typeof r.last_message_id === 'number') rideLastMsgId = r.last_message_id;
  // dibujar rutas
  if (r.status === 'ofrecido' || r.status === 'aceptado' || r.status === 'en_camino' || r.status === 'llego') {
    if (r.route_to_pickup) drawRoute(r.route_to_pickup, '#FFC107');
  } else if (r.status === 'a_bordo') {
    if (r.route_trip) drawRoute(r.route_trip, '#00C853');
  } else {
    // Sin conductor asignado (volvió a buscar porque el pasajero lo rechazó, porque no confirmó
    // a tiempo, o porque se venció la búsqueda). Hay que BORRAR el auto y la ruta del conductor
    // anterior: el servidor deja de mandarlos, pero lo que ya está dibujado en el mapa se queda
    // ahí, y el pasajero ve un taxi acercándose que en realidad ya no viene por él.
    clearDriverFromMap();
  }
  // marcador del auto
  if (r.driver_pos && r.driver_pos.lat) moveCar(r.driver_pos);
  // marcadores origen/destino
  if (oMarker) { oMarker.setLatLng([r.origin.lat, r.origin.lng]).dragging.disable(); oMarker.setOpacity(1); }
  if (!dMarker) setDest({ lat: r.dest.lat, lng: r.dest.lng }, r.dest.address); else dMarker.setLatLng([r.dest.lat, r.dest.lng]);

  if (r.status !== 'ofrecido') { clearInterval(offerTimer); offerKey = null; }

  if (r.status === 'completado') { ackRide(r); renderCompleted(r); return; }
  // Se acabó el tiempo de búsqueda: pantalla propia con la opción de reintentar, en vez de
  // devolverlo a "¿A dónde vamos?" con un aviso que se va solo y perdiendo el destino.
  if (r.status === 'sin_conductor') { renderNoDriver(r); return; }
  if (r.status === 'cancelado') {
    ackRide(r); stopPolling();
    toast('El viaje fue cancelado.');
    resetAfterRide(); return;
  }
  if (r.status === 'solicitando') { renderSearching(r); return; }
  if (r.status === 'ofrecido') {
    const key = r.id + ':' + ((r.driver && r.driver.plate) || '');
    if (offerKey !== key) { offerKey = key; renderOffer(r); }  // no re-pintar en cada sondeo (no cortar el clic)
    return;
  }
  renderAssigned(r);
}

/* ====== Oferta: confirmar o buscar otro conductor ====== */
/**
 * Foto del vehículo para que el pasajero reconozca el auto que lo recoge.
 * Si el conductor todavía no la subió, no se muestra nada (la tarjeta queda como antes).
 */
/**
 * @param  {boolean} small  en la pantalla de oferta la foto va más baja: ahí el pasajero decide
 *                          contra reloj y lo que tiene que ver es el precio y los botones.
 *                          La foto grande sirve para reconocer el auto, y eso es después de aceptar.
 */
function vehiclePhoto(d, small) {
  if (!d || !d.vehicle_photo) return '';
  return `
    <div class="vehshot${small ? ' sm' : ''}" id="vehShot">
      <img src="${d.vehicle_photo}" alt="Vehículo de ${esc(d.name || 'tu conductor')}" loading="lazy">
      ${d.plate ? `<span class="vplate">${esc(d.plate)}</span>` : ''}
    </div>`;
}

/** Rostro del conductor (solo si la central lo aprobó); si no, la inicial de siempre. */
function driverAvatar(d) {
  if (d && d.photo) {
    return `<div class="av"><img src="${d.photo}" alt="Foto de ${esc(d.name || 'tu conductor')}" loading="lazy"></div>`;
  }
  return `<div class="av">${(d && d.initial) || '🚗'}</div>`;
}

/** Abre la foto a pantalla completa al tocarla (la placa se lee mejor en grande). */
function bindVehiclePhoto(d) {
  const box = $('#vehShot');
  if (!box || !d || !d.vehicle_photo) return;
  box.addEventListener('click', () => {
    const lb = document.createElement('div');
    lb.className = 'vlightbox';
    lb.innerHTML = `<img src="${d.vehicle_photo}" alt="Vehículo de ${esc(d.name || 'tu conductor')}">
      <div class="vcap">${esc(d.vehicle || '')}${d.plate ? ' · ' + esc(d.plate) : ''}${d.color ? ' · ' + esc(d.color) : ''}</div>`;
    lb.addEventListener('click', () => lb.remove());
    document.body.appendChild(lb);
  });
}

function renderOffer(r) {
  const d = r.driver || {}, off = r.offer || {};
  const timeout = off.timeout || 15;
  let left = (off.seconds_left != null) ? off.seconds_left : timeout;
  const eta = off.eta_min ? ('~' + off.eta_min + ' min') : '—';
  // Pase lo que pase, el TOTAL siempre se ve antes de aceptar: nadie confirma un monto que no vio.
  const cFee = Number(r.counter_offer) || 0;
  // El desglose solo aparece si hay algo que el pasajero deba entender: lo que pide el
  // conductor de más. El acercamiento va integrado en el precio del viaje (ver tripLine).
  const hasBreak = cFee > 0;
  // Botones SIEMPRE a la vista: aquí el pasajero decide con el reloj corriendo (15 s). Si tiene
  // que descubrir que el panel se desplaza para encontrar «Aceptar», pierde la carrera.
  $('#sheetBody').classList.add('hascta');
  $('#sheetBody').innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <span style="color:#00C853;font-weight:700;font-size:14px">✅ ¡Conductor encontrado!</span>
      <span id="offCd" style="font-weight:800;font-size:17px;color:#FFC107">${left}s</span>
    </div>
    <div style="height:5px;background:#2a3038;border-radius:3px;overflow:hidden;margin-bottom:12px">
      <i id="offBar" style="display:block;height:100%;background:#FFC107;width:${left / timeout * 100}%;transition:width 1s linear"></i>
    </div>
    ${vehiclePhoto(d, true)}
    <div class="drv">
      ${driverAvatar(d)}
      <div><div class="nm">${esc(d.name || 'Conductor')}</div><div class="car2">${esc(d.vehicle || '')} · ${esc(d.plate || '')} ${d.color ? '· ' + esc(d.color) : ''}</div></div>
      <div class="rate"><b>⭐ ${(d.rating || 5).toFixed(1)}</b><small>${d.trips || 0} viajes</small></div>
    </div>
    ${hasBreak
      // Con desglose, la tarjeta del precio repetía el mismo total dos veces: se deja una línea.
      ? `<div class="metaline">Llega en ${eta} · ${r.payment_method === 'yape' ? 'Yape' : 'Efectivo'}</div>`
      : `<div class="routeinfo">
           <div class="chip"><div class="v">${eta}</div><div class="l">Llega en</div></div>
           <div class="chip"><div class="v">${money(rideTotal(r))}</div><div class="l">${r.payment_method === 'yape' ? 'Yape' : 'Efectivo'}</div></div>
         </div>`}
    ${hasBreak ? `<div class="breakdown">
      <div><span>Viaje hasta tu destino</span><b>${money(tripLine(r))}</b></div>
      <div><span>Lo que pide el conductor</span><b>+ ${money(cFee)}</b></div>
      <div class="tot"><span>Total a pagar</span><b>${money(rideTotal(r))}</b></div>
    </div>` : ''}
    <div class="sub" style="text-align:center;margin:-4px 0 2px">${cFee > 0
      // "más que tu oferta" ya no calza: la línea del viaje trae el acercamiento sumado y no
      // coincide con lo que el pasajero tecleó. Se habla del cobro extra, no de la diferencia.
      ? `Este conductor pide ${money(cFee)} más por esta carrera. Si prefieres, busca otro.`
      : 'Si no respondes a tiempo, buscaremos otro automáticamente.'}</div>
    <div class="sheetcta">
      <div class="acts">
        <button class="btn ghost" id="btnOtro">Buscar otro</button>
        <button class="btn" id="btnAceptar">Aceptar ${money(rideTotal(r))}</button>
      </div>
    </div>`;
  bindVehiclePhoto(d);
  $('#btnAceptar').addEventListener('click', confirmOffer);
  $('#btnOtro').addEventListener('click', rejectOffer);
  clearInterval(offerTimer);
  offerTimer = setInterval(() => {
    left--;
    const cd = $('#offCd'), bar = $('#offBar');
    if (cd) cd.textContent = Math.max(0, left) + 's';
    if (bar) bar.style.width = Math.max(0, left / timeout * 100) + '%';
    if (left <= 0) clearInterval(offerTimer);
  }, 1000);
}

async function confirmOffer() {
  const b = $('#btnAceptar'); if (b) { b.disabled = true; b.innerHTML = '<span class="spin"></span>'; }
  clearInterval(offerTimer);
  try {
    const r = await api('api/rides/confirm-driver', {});
    offerKey = null;
    if (r.ride) renderRide(r.ride);
  } catch (e) { offerKey = null; toast(e.message || 'La oferta ya no está disponible.'); }
}

async function rejectOffer() {
  clearInterval(offerTimer); offerKey = null;
  const b = $('#btnOtro'); if (b) { b.disabled = true; b.textContent = 'Buscando…'; }
  // Borrar el auto y la ruta YA, sin esperar la respuesta ni el siguiente sondeo: el pasajero
  // acaba de decir que no quiere a ese conductor, verlo un segundo más acercándose confunde.
  clearDriverFromMap();
  try {
    const r = await api('api/rides/reject-driver', {});
    toast('Buscando otro conductor…');
    if (r.ride) renderRide(r.ride);
  } catch (e) { toast(e.message || 'No se pudo.'); }
}

/** mm:ss para la cuenta regresiva de la búsqueda */
function mmss(s) {
  s = Math.max(0, Math.round(s));
  return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

function renderSearching(r) {
  $('#sheetBody').classList.remove('hascta'); // sin botón fijo: estas pantallas no tienen acción principal al pie
  // La búsqueda tiene fin y el pasajero tiene que verlo: sin esto se quedaba 10 minutos
  // mirando "Buscando tu taxi…" un viaje que los conductores ya ni recibían.
  const left = searchLeft ? searchLeft.seconds_left : null;
  $('#sheetBody').innerHTML = `
    <div class="searching">
      <div class="radar"><span></span><span></span><span></span><b>🚕</b></div>
      <h2>Buscando tu taxi…</h2>
      <div class="sub">Avisando a los conductores cercanos con tu oferta de ${money(r.offered_price)}.</div>
      ${left != null ? `<div class="sub" style="margin-top:6px">Seguimos buscando <b id="schLeft">${mmss(left)}</b> más. Si nadie la toma, te avisamos.</div>` : ''}
    </div>
    <button class="btn danger" id="btnCancel">Cancelar</button>`;
  $('#btnCancel').addEventListener('click', cancelRide);
}

/**
 * Se acabó el tiempo y nadie tomó el viaje.
 *
 * No se usa resetAfterRide() a propósito: eso borra el destino y el pasajero tendría que
 * volver a escribirlo todo. Aquí se conserva el viaje para reintentarlo de un toque, que es
 * lo que la gente quiere hacer cuando no consiguió taxi.
 */
function renderNoDriver(r) {
  // No se marca como visto todavía: si el pasajero recarga la app, esta pantalla debe volver
  // a salir con su botón de reintentar. Se marca cuando toca una de las dos opciones.
  stopPolling();
  curRide = r;
  sheetState = 'open';
  $('#sheetBody').classList.add('hascta');
  $('#sheetBody').innerHTML = `
    <div class="searching">
      <div class="nodrv">🚕</div>
      <h2>No encontramos conductor</h2>
      <div class="sub">Ningún conductor tomó tu viaje a ${esc((r.dest && r.dest.address) || 'tu destino')} por ${money(r.offered_price)}.
        Puedes intentar de nuevo, o cambiar el viaje y subir un poco tu oferta para que sea más probable que lo tomen.</div>
    </div>
    <div class="sheetcta">
      <div class="acts">
        <button class="btn ghost" id="btnNuevoViaje">Cambiar viaje</button>
        <button class="btn" id="btnReintentar">Intentar de nuevo</button>
      </div>
    </div>`;
  $('#btnNuevoViaje').addEventListener('click', () => { ackRide(r); curRide = null; resetAfterRide(); });
  $('#btnReintentar').addEventListener('click', () => { ackRide(r); retryRide(r); });
  requestAnimationFrame(() => applySheetSnap(false));
}

/** Vuelve a pedir el MISMO viaje (mismo origen, destino, precio y forma de pago). */
async function retryRide(r) {
  const b = $('#btnReintentar');
  if (b) { b.disabled = true; b.innerHTML = '<span class="spin"></span>'; }
  try {
    await api('api/rides', {
      origin_lat: r.origin.lat, origin_lng: r.origin.lng, origin_address: r.origin.address || 'Mi ubicación',
      reference: r.reference || null,
      dest_lat: r.dest.lat, dest_lng: r.dest.lng, dest_address: r.dest.address || 'Destino',
      offered_price: r.offered_price, payment_method: r.payment_method,
    });
    // el destino vuelve al estado de la app para que el resto de pantallas lo tengan
    dest = { lat: r.dest.lat, lng: r.dest.lng, address: r.dest.address };
    price = Number(r.offered_price);
    startPolling();
  } catch (e) {
    toast(e.message || 'No se pudo pedir de nuevo.');
    if (b) { b.disabled = false; b.textContent = 'Intentar de nuevo'; }
  }
}

function renderAssigned(r) {
  const d = r.driver || {};
  const bands = {
    aceptado: ['Conductor asignado', 'Se dirige a recogerte'],
    en_camino: ['Tu conductor va en camino', 'Sigue su llegada en el mapa'],
    llego: ['¡Tu conductor llegó!', 'Sal al punto de encuentro'],
    a_bordo: ['En viaje', 'Rumbo a tu destino'],
  };
  const band = bands[r.status] || [r.status_label, ''];
  const canCancel = r.status !== 'a_bordo';
  const hasBreak = Number(r.counter_offer) > 0; // el acercamiento no se le detalla al pasajero
  // Chat y Cancelar también van fijos: con la foto del vehículo y el desglose, en pantallas
  // bajas quedaban por debajo del corte.
  $('#sheetBody').classList.add('hascta');
  $('#sheetBody').innerHTML = `
    ${r.is_demo ? '<div class="demo">🧪 Conductor de prueba (demo)</div>' : ''}
    <div class="statusband">${band[0]}<small>${band[1]}</small></div>
    ${vehiclePhoto(d)}
    <div class="drv">
      ${driverAvatar(d)}
      <div><div class="nm">${esc(d.name || 'Conductor')}</div><div class="car2">${esc(d.vehicle || '')} · ${esc(d.plate || '')} ${d.color ? '· ' + esc(d.color) : ''}</div></div>
      <div class="rate"><b>⭐ ${(d.rating || 5).toFixed(1)}</b><small>${d.trips || 0} viajes</small></div>
    </div>
    ${hasBreak
      // El total ya sale en el desglose y en el candado: la tarjeta del precio lo repetía
      ? `<div class="metaline">${(r.distance_m / 1000).toFixed(1)} km al destino · ${r.payment_method === 'yape' ? 'Yape' : 'Efectivo'}</div>`
      : `<div class="routeinfo">
           <div class="chip"><div class="v">${money(rideTotal(r))}</div><div class="l">${r.payment_method === 'yape' ? 'Yape' : 'Efectivo'}</div></div>
           <div class="chip"><div class="v">${(r.distance_m / 1000).toFixed(1)} km</div><div class="l">al destino</div></div>
         </div>`}
    ${hasBreak ? `<div class="breakdown">
      <div><span>Viaje hasta tu destino</span><b>${money(tripLine(r))}</b></div>
      <div><span>Ajuste del conductor</span><b>+ ${money(r.counter_offer)}</b></div>
    </div>` : ''}
    <div class="pricelock">🔒 Precio fijo pactado: ${money(rideTotal(r))}. No cambia por el tráfico.</div>
    <div class="sheetcta">
      <div class="acts">
        <button class="btn ghost" id="btnChat">💬 Chat${(rideLastMsgId > chatSeenId && !chatOpen) ? ' <span class="undot"></span>' : ''}</button>
        ${canCancel ? '<button class="btn danger" id="btnCancel">Cancelar</button>' : ''}
      </div>
    </div>`;
  bindVehiclePhoto(d);
  const c = $('#btnCancel'); if (c) c.addEventListener('click', cancelRide);
  $('#btnChat').addEventListener('click', () => openChat(d.name));
}

function renderCompleted(r) {
  stopPolling();
  $('#sheetBody').classList.remove('hascta'); // sin botón fijo: estas pantallas no tienen acción principal al pie
  $('#sheetBody').innerHTML = `
    <div style="text-align:center"><div style="font-size:44px">✅</div><h2>¡Llegaste!</h2><div class="sub">Gracias por viajar con MajesGo.</div></div>
    <div class="fare-big"><div class="n">${money(r.final_price || rideTotal(r))}</div><div class="l">${r.payment_method === 'yape' ? 'Pagas con Yape' : 'Pagas en efectivo'}</div></div>
    <div class="pricelock">🔒 Es el mismo precio que aceptaste al pedir el viaje.</div>
    <div class="sub" style="text-align:center">¿Cómo estuvo tu conductor?</div>
    <div class="stars" id="stars">${[1, 2, 3, 4, 5].map((n) => `<span data-n="${n}">★</span>`).join('')}</div>
    <button class="btn" id="btnDone">Listo</button>
    <button class="btn ghost" id="btnReport" style="margin-top:8px;color:#ff8a80">Tuve un problema con el conductor</button>`;
  let chosen = 0;
  const stars = $('#stars').querySelectorAll('span');
  stars.forEach((s) => {
    s.addEventListener('click', () => { chosen = +s.dataset.n; stars.forEach((x, i) => x.classList.toggle('on', i < chosen)); });
  });
  $('#btnDone').addEventListener('click', async () => {
    if (chosen) { try { await api('api/rides/rate', { code: r.code, rating: chosen }); } catch (e) {} }
    resetAfterRide();
  });
  // La denuncia no cierra la pantalla: si el pasajero además quiere calificar, puede.
  $('#btnReport').addEventListener('click', () => openReportModal(r.code));
}

function cancelRide() {
  const st = curRide && curRide.status;
  // conductor ya asignado / en camino → modal de advertencia con motivo
  if (['aceptado', 'en_camino', 'llego'].includes(st)) { openCancelModal(); return; }
  // aún buscando (sin conductor asignado) → cancelación simple, sin penalización
  if (!confirm('¿Cancelar la búsqueda de taxi?')) return;
  doCancel(null);
}

async function doCancel(reason) {
  try { await api('api/rides/cancel', { reason }); } catch (e) {}
  stopPolling(); toast('Viaje cancelado'); resetAfterRide();
}

/* ====== Modal de cancelación del pasajero (2 pasos: advertencia → motivo) ====== */
function openCancelModal() {
  paxCancelReason = null;
  renderCancelStep1();
  $('#cancelModal').classList.remove('hidden');
}
function closeCancelModal() {
  const m = $('#cancelModal'); m.classList.add('hidden'); m.innerHTML = '';
}
function renderCancelStep1() {
  $('#cancelModal').innerHTML = `
    <div class="modalcard">
      <div class="micon warn">⚠️</div>
      <h2>¿Seguro que deseas cancelar?</h2>
      <p class="msub">El conductor ya está en camino a tu punto de recojo. Cancelar este viaje afectará tu calificación de usuario y la prioridad con la que se acepten tus próximos pedidos.</p>
      <button class="btn" id="cxKeep">Continuar viaje</button>
      <button class="btn softred" id="cxCancel">Sí, cancelar carrera</button>
    </div>`;
  $('#cxKeep').addEventListener('click', closeCancelModal);
  $('#cxCancel').addEventListener('click', renderCancelStep2);
}
function renderCancelStep2() {
  $('#cancelModal').innerHTML = `
    <div class="modalcard">
      <h2>¿Por qué cancelas?</h2>
      <p class="msub">Cuéntanos el motivo (opcional). Nos ayuda a mejorar el servicio.</p>
      <div class="reasons" id="cxReasons">
        ${PAX_CANCEL_REASONS.map((t, i) => `<button class="reason" data-i="${i}">${esc(t)}</button>`).join('')}
      </div>
      <button class="btn danger" id="cxConfirm">Confirmar cancelación</button>
      <button class="btn ghost" id="cxBack">Volver</button>
    </div>`;
  const btns = [...document.querySelectorAll('#cxReasons .reason')];
  btns.forEach((b) => b.addEventListener('click', () => {
    const i = +b.dataset.i;
    if (paxCancelReason === PAX_CANCEL_REASONS[i]) { paxCancelReason = null; b.classList.remove('on'); }
    else { paxCancelReason = PAX_CANCEL_REASONS[i]; btns.forEach((x) => x.classList.remove('on')); b.classList.add('on'); }
  }));
  $('#cxConfirm').addEventListener('click', async () => {
    const b = $('#cxConfirm'); if (b) { b.disabled = true; b.innerHTML = '<span class="spin"></span>'; }
    await doCancel(paxCancelReason);
    closeCancelModal();
  });
  $('#cxBack').addEventListener('click', renderCancelStep1);
}

/* ====== Denuncia al conductor ======
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
      <div class="micon warn">⚠️</div>
      <h2>Denunciar al conductor</h2>
      <p class="msub">Cuéntanos qué pasó. La central revisa cada caso y puede suspender la cuenta del conductor.</p>
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
    const b = $('#rpSend'); b.disabled = true; b.innerHTML = '<span class="spin"></span>';
    try {
      await api('api/rides/report', { code, reason: reportReason, details });
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

function resetAfterRide() {
  stopPolling();
  clearInterval(offerTimer); offerKey = null;
  closeChat(); chatLastId = 0; chatSeenId = 0; rideLastMsgId = 0;
  dest = null; quote = null; price = null; reference = ''; refOpen = false;
  if (dMarker) { dMarker.remove(); dMarker = null; }
  clearDriverFromMap();
  curRide = null;
  originPinned = false;   // el recojo vuelve a seguir tu ubicación en vivo
  sheetState = 'peek';    // panel compacto otra vez para ver el mapa
  if (oMarker) oMarker.dragging.enable();
  reflectOrigin();
  setDefaultOrigin();
  renderPlanning();
}

/**
 * Quita del mapa el auto del conductor y su ruta.
 *
 * ⚠ Hay que cancelar la animación en curso ANTES de soltar el marcador: moveCar() deja un
 * requestAnimationFrame corriendo que llama a carMarker.setLatLng(), y si lo dejamos en null
 * a mitad de camino, ese frame revienta.
 */
function clearDriverFromMap() {
  if (routeLine) { routeLine.remove(); routeLine = null; }
  if (carMarker) {
    if (carMarker._anim) { cancelAnimationFrame(carMarker._anim); carMarker._anim = null; }
    carMarker.remove(); carMarker = null;
  }
  carFrom = null;
}

/* auto del conductor con interpolación suave entre polls */
function moveCar(pos) {
  const to = [pos.lat, pos.lng];
  if (!carMarker) { carMarker = L.marker(to, { icon: icon('car', carSvg('trip'), [30, 30], [15, 15]), interactive: false, zIndexOffset: 1000 }).addTo(map); carFrom = to; return; }
  const from = carFrom || carMarker.getLatLng();
  const a = L.latLng(from), b = L.latLng(to);
  faceMarker(carMarker, 'car', a, b);
  // no re-animar si prácticamente no se movió
  if (Math.abs(a.lat - b.lat) < 1e-6 && Math.abs(a.lng - b.lng) < 1e-6) { carFrom = to; return; }
  // deslizamiento continuo: la duración ~ el intervalo entre actualizaciones,
  // así el auto avanza sin congelarse entre un dato y otro (fluido tipo Uber)
  if (carMarker._anim) cancelAnimationFrame(carMarker._anim);
  const start = performance.now(), dur = 1700;
  (function step(t) {
    const k = Math.min(1, (t - start) / dur);
    const e = 1 - Math.pow(1 - k, 2);        // easing suave al llegar
    carMarker.setLatLng([a.lat + (b.lat - a.lat) * e, a.lng + (b.lng - a.lng) * e]);
    if (k < 1) carMarker._anim = requestAnimationFrame(step); else { carFrom = to; carMarker._anim = null; }
  })(start);
}

/* ================= HISTORIAL ================= */
async function openHistory() {
  $('#drawer').classList.add('open');
  try {
    const d = await api('api/rides/history');
    const cur = d.currency || CUR;
    $('#histBody').innerHTML = d.rides.length ? d.rides.map((r) => `
      <div class="hitem">
        <div class="top"><span>${r.code}</span><span>${r.date}</span></div>
        <div class="rt">📍 ${esc(r.origin || 'Origen')} → 🏁 ${esc(r.dest || 'Destino')}</div>
        <div class="top"><span>${r.status_label}</span><span class="pr">${cur} ${Number(r.price).toFixed(2)}</span></div>
      </div>`).join('') : '<p class="sub" style="text-align:center;color:var(--muted)">Aún no tienes viajes.</p>';
  } catch (e) { $('#histBody').innerHTML = '<p class="sub" style="text-align:center">No se pudo cargar.</p>'; }
}
$('#btnBack').addEventListener('click', () => $('#drawer').classList.remove('open'));
$('#btnLogout').addEventListener('click', async () => {
  try { await api('api/logout', {}); } catch (e) {}
  location.reload();
});

/* ================= CHAT con el conductor ================= */
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
  $('#chat').classList.remove('open');
  clearInterval(chatPoll); chatPoll = null;
}
async function loadChat(reset) {
  if (reset) { chatLastId = 0; $('#chatBody').innerHTML = ''; }
  let data;
  try { data = await api('api/rides/messages?after=' + chatLastId); } catch (e) { return; }
  const msgs = data.messages || [];
  if (reset && !msgs.length) {
    $('#chatBody').innerHTML = '<div class="cempty">Escríbele a tu conductor: dale una referencia, avísale que ya sales, etc.</div>';
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
    const r = await api('api/rides/messages', { body });
    if (r && r.msg) appendChat([r.msg]);
    $('#chatBody').scrollTop = $('#chatBody').scrollHeight;
  } catch (e) { toast(e.message || 'No se pudo enviar.'); inp.value = body; }
}
$('#chatBack').addEventListener('click', closeChat);
$('#chatReport').addEventListener('click', () => openReportModal(curRide && curRide.code));
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
