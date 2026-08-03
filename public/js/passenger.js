/* MajesGo — App del pasajero (Hito 2) */
'use strict';

const CUR = MG.currency || 'S/';
const $ = (s) => document.querySelector(s);
const money = (n) => CUR + ' ' + Number(n).toFixed(2);

/* ---------- API ---------- */
async function api(path, body, method) {
  const opt = {
    method: method || (body ? 'POST' : 'GET'),
    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': MG.csrf },
  };
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const res = await fetch('/app/' + path, opt);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw { status: res.status, message: data.message || 'Ocurrió un error', errors: data.errors };
  return data;
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
  try {
    const me = await api('api/me');
    if (me.csrf) MG.csrf = me.csrf;
    if (me.authenticated) { $('#auth').classList.add('hidden'); await boot(); }
    else { $('#auth').classList.remove('hidden'); }
  } catch (e) { $('#auth').classList.remove('hidden'); }
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
}

/* ================= MAPA ================= */
function initMap() {
  mapLight = initialLight();
  map = L.map('map', { zoomControl: false, attributionControl: true }).setView(MG.center, 15);
  baseTile = L.tileLayer(tileUrl(mapLight), {
    attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 20, subdomains: 'abcd',
  }).addTo(map);
  L.control.zoom({ position: 'bottomleft' }).addTo(map);
  const bm = $('#btnMapMode'); if (bm) bm.addEventListener('click', toggleMapMode);
  applyMapMode();

  map.on('click', (e) => {
    if (isRiding()) return;
    setDest({ lat: e.latlng.lat, lng: e.latlng.lng });
  });
  // si el usuario mueve el mapa a mano, dejamos de recentrar automáticamente
  map.on('dragstart', () => { followMe = false; });

  const ro = new ResizeObserver((en) => {
    const h = en[0].contentRect.height;
    document.documentElement.style.setProperty('--sheet-h', h + 'px');
  });
  ro.observe($('#sheet'));

  $('#btnLoc').addEventListener('click', () => locate(true));
  $('#btnMenu').addEventListener('click', openHistory);
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
  if (!meMarker) meMarker = L.marker([p.lat, p.lng], { icon: icon('medot', '', [16, 16], [8, 8]), interactive: false, zIndexOffset: 500 }).addTo(map);
  else meMarker.setLatLng([p.lat, p.lng]);
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
      reverseGeocode(ll).then((a) => { origin.address = a; refreshQuote(); });
    });
  } else oMarker.setLatLng(p);
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
function renderPlanning() {
  const b = $('#sheetBody');
  const hasRoute = quote && dest;
  b.innerHTML = `
    <h2>¿A dónde vamos?</h2>
    <div class="sub">Elige tu destino y propón tu precio.</div>
    <div class="fieldrow"><span class="dot o"></span><input id="oIn" value="${(origin && origin.address) ? esc(origin.address) : 'Mi ubicación'}" placeholder="Origen (puedes editarlo)"><small>Origen</small></div>
    <div class="sugg">
      <div class="fieldrow"><span class="dot d"></span><input id="dIn" placeholder="Escribe el destino o toca el mapa" value="${dest && dest.address ? esc(dest.address) : ''}"><small>Destino</small></div>
      <div class="suggbox" id="sugg"></div>
    </div>
    <div class="fieldrow"><span class="dot" style="background:#FFC107"></span><input id="refIn" placeholder="Referencia del recojo (opcional): casa, color, algo cercano…" value="${reference ? esc(reference) : ''}"><small>Referencia</small></div>
    ${hasRoute ? `
      <div class="routeinfo">
        <div class="chip"><div class="v">${(quote.distance_m / 1000).toFixed(1)} km</div><div class="l">Distancia</div></div>
        <div class="chip"><div class="v">${Math.max(1, Math.round(quote.duration_s / 60))} min</div><div class="l">Tiempo aprox.</div></div>
      </div>
      <div class="prow"><span class="lbl">Tu precio</span>
        <div class="stepper">
          <button id="minus">−</button>
          <span class="price" id="priceLbl">${money(price)}</span>
          <button id="plus">+</button>
        </div>
      </div>
      <div class="hintprice">Sugerido: ${money(quote.suggested)} · puedes ofrecer desde ${money(quote.floor)}</div>
      <div class="pay">
        <button data-m="efectivo" class="${method === 'efectivo' ? 'on' : ''}">💵 Efectivo</button>
        <button data-m="yape" class="${method === 'yape' ? 'on' : ''}">💜 Yape</button>
      </div>
      <button class="btn" id="btnReq">Buscar taxi · ${money(price)}</button>
    ` : `<div class="hintprice" style="margin-top:8px">Toca el mapa para marcar a dónde quieres ir 👆</div>`}
  `;

  const dIn = $('#dIn'); if (dIn) dIn.addEventListener('input', () => searchPlaces(dIn.value.trim(), $('#sugg')));
  const oIn = $('#oIn'); if (oIn) oIn.addEventListener('input', () => {
    originPinned = true;                 // si edita el texto, dejamos de sobrescribirlo con el GPS
    if (origin) origin.address = oIn.value;
  });
  const refIn = $('#refIn'); if (refIn) refIn.addEventListener('input', () => { reference = refIn.value; });
  if (hasRoute) {
    $('#minus').addEventListener('click', () => bump(-0.5));
    $('#plus').addEventListener('click', () => bump(0.5));
    b.querySelectorAll('.pay button').forEach((el) => el.addEventListener('click', () => {
      method = el.dataset.m; renderPlanning();
    }));
    $('#btnReq').addEventListener('click', doRequest);
  }
}
function bump(d) {
  price = Math.max(quote.floor, Math.round((price + d) * 2) / 2);
  $('#priceLbl').textContent = money(price);
  $('#btnReq').textContent = 'Buscar taxi · ' + money(price);
}
function esc(s) { return (s || '').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

async function doRequest() {
  const btn = $('#btnReq'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    await api('api/rides', {
      origin_lat: origin.lat, origin_lng: origin.lng, origin_address: origin.address || 'Mi ubicación',
      reference: (reference || '').trim() || null,
      dest_lat: dest.lat, dest_lng: dest.lng, dest_address: dest.address || 'Destino',
      offered_price: price, payment_method: method,
    });
    startPolling();
  } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = 'Buscar taxi · ' + money(price); }
}

/* ================= POLLING / VIAJE ================= */
function isRiding() { return !!poll; }
const ACTIVE_ST = ['ofrecido', 'aceptado', 'en_camino', 'llego', 'a_bordo'];

function startPolling() {
  lastStatus = null;
  poll = true;
  clearTimeout(pollTimer);
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
  renderRide(r);
  if (!poll) return;                         // renderRide pudo detener el sondeo (fin de viaje)
  // en viaje sondeamos más seguido para una ubicación más fluida
  pollTimer = setTimeout(tick, ACTIVE_ST.includes(r.status) ? 1600 : 2500);
}

function renderRide(r) {
  if (typeof r.last_message_id === 'number') rideLastMsgId = r.last_message_id;
  // dibujar rutas
  if (r.status === 'ofrecido' || r.status === 'aceptado' || r.status === 'en_camino' || r.status === 'llego') {
    if (r.route_to_pickup) drawRoute(r.route_to_pickup, '#FFC107');
  } else if (r.status === 'a_bordo') {
    if (r.route_trip) drawRoute(r.route_trip, '#00C853');
  }
  // marcador del auto
  if (r.driver_pos && r.driver_pos.lat) moveCar(r.driver_pos);
  // marcadores origen/destino
  if (oMarker) oMarker.setLatLng([r.origin.lat, r.origin.lng]).dragging.disable();
  if (!dMarker) setDest({ lat: r.dest.lat, lng: r.dest.lng }, r.dest.address); else dMarker.setLatLng([r.dest.lat, r.dest.lng]);

  if (r.status !== 'ofrecido') { clearInterval(offerTimer); offerKey = null; }

  if (r.status === 'completado') { ackRide(r); renderCompleted(r); return; }
  if (r.status === 'cancelado' || r.status === 'sin_conductor') {
    ackRide(r); stopPolling();
    toast(r.status === 'cancelado' ? 'El viaje fue cancelado.' : r.status_label);
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
function renderOffer(r) {
  const d = r.driver || {}, off = r.offer || {};
  const timeout = off.timeout || 15;
  let left = (off.seconds_left != null) ? off.seconds_left : timeout;
  const eta = off.eta_min ? ('~' + off.eta_min + ' min') : '—';
  $('#sheetBody').innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <span style="color:#00C853;font-weight:700;font-size:14px">✅ ¡Conductor encontrado!</span>
      <span id="offCd" style="font-weight:800;font-size:17px;color:#FFC107">${left}s</span>
    </div>
    <div style="height:5px;background:#2a3038;border-radius:3px;overflow:hidden;margin-bottom:14px">
      <i id="offBar" style="display:block;height:100%;background:#FFC107;width:${left / timeout * 100}%;transition:width 1s linear"></i>
    </div>
    <div class="drv">
      <div class="av">${d.initial || '🚗'}</div>
      <div><div class="nm">${esc(d.name || 'Conductor')}</div><div class="car2">${esc(d.vehicle || '')} · ${esc(d.plate || '')} ${d.color ? '· ' + esc(d.color) : ''}</div></div>
      <div class="rate"><b>⭐ ${(d.rating || 5).toFixed(1)}</b><small>${d.trips || 0} viajes</small></div>
    </div>
    <div class="routeinfo">
      <div class="chip"><div class="v">${eta}</div><div class="l">Llega en</div></div>
      <div class="chip"><div class="v">${money(r.offered_price)}</div><div class="l">${r.payment_method === 'yape' ? 'Yape' : 'Efectivo'}</div></div>
    </div>
    <div class="acts">
      <button class="btn ghost" id="btnOtro">Buscar otro</button>
      <button class="btn" id="btnAceptar">Aceptar</button>
    </div>
    <div class="sub" style="text-align:center;margin:10px 0 0">Si no respondes a tiempo, buscaremos otro automáticamente.</div>`;
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
  try {
    const r = await api('api/rides/reject-driver', {});
    toast('Buscando otro conductor…');
    if (r.ride) renderRide(r.ride);
  } catch (e) { toast(e.message || 'No se pudo.'); }
}

function renderSearching(r) {
  $('#sheetBody').innerHTML = `
    <div class="searching">
      <div class="radar"><span></span><span></span><span></span><b>🚕</b></div>
      <h2>Buscando tu taxi…</h2>
      <div class="sub">Avisando a los conductores cercanos con tu oferta de ${money(r.offered_price)}.</div>
    </div>
    <button class="btn danger" id="btnCancel">Cancelar</button>`;
  $('#btnCancel').addEventListener('click', cancelRide);
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
  $('#sheetBody').innerHTML = `
    ${r.is_demo ? '<div class="demo">🧪 Conductor de prueba (demo)</div>' : ''}
    <div class="statusband">${band[0]}<small>${band[1]}</small></div>
    <div class="drv">
      <div class="av">${d.initial || '🚗'}</div>
      <div><div class="nm">${esc(d.name || 'Conductor')}</div><div class="car2">${esc(d.vehicle || '')} · ${esc(d.plate || '')} ${d.color ? '· ' + esc(d.color) : ''}</div></div>
      <div class="rate"><b>⭐ ${(d.rating || 5).toFixed(1)}</b><small>${d.trips || 0} viajes</small></div>
    </div>
    <div class="routeinfo">
      <div class="chip"><div class="v">${money(r.offered_price)}</div><div class="l">${r.payment_method === 'yape' ? 'Yape' : 'Efectivo'}</div></div>
      <div class="chip"><div class="v">${(r.distance_m / 1000).toFixed(1)} km</div><div class="l">al destino</div></div>
    </div>
    <div class="acts">
      <button class="btn ghost" id="btnChat">💬 Chat${(rideLastMsgId > chatSeenId && !chatOpen) ? ' <span class="undot"></span>' : ''}</button>
      ${canCancel ? '<button class="btn danger" id="btnCancel">Cancelar</button>' : ''}
    </div>`;
  const c = $('#btnCancel'); if (c) c.addEventListener('click', cancelRide);
  $('#btnChat').addEventListener('click', () => openChat(d.name));
}

function renderCompleted(r) {
  stopPolling();
  $('#sheetBody').innerHTML = `
    <div style="text-align:center"><div style="font-size:44px">✅</div><h2>¡Llegaste!</h2><div class="sub">Gracias por viajar con MajesGo.</div></div>
    <div class="fare-big"><div class="n">${money(r.final_price || r.offered_price)}</div><div class="l">${r.payment_method === 'yape' ? 'Pagas con Yape' : 'Pagas en efectivo'}</div></div>
    <div class="sub" style="text-align:center">¿Cómo estuvo tu conductor?</div>
    <div class="stars" id="stars">${[1, 2, 3, 4, 5].map((n) => `<span data-n="${n}">★</span>`).join('')}</div>
    <button class="btn" id="btnDone">Listo</button>`;
  let chosen = 0;
  const stars = $('#stars').querySelectorAll('span');
  stars.forEach((s) => {
    s.addEventListener('click', () => { chosen = +s.dataset.n; stars.forEach((x, i) => x.classList.toggle('on', i < chosen)); });
  });
  $('#btnDone').addEventListener('click', async () => {
    if (chosen) { try { await api('api/rides/rate', { code: r.code, rating: chosen }); } catch (e) {} }
    resetAfterRide();
  });
}

async function cancelRide() {
  if (!confirm('¿Cancelar el viaje?')) return;
  try { await api('api/rides/cancel', {}); } catch (e) {}
  stopPolling(); toast('Viaje cancelado'); resetAfterRide();
}

function resetAfterRide() {
  stopPolling();
  clearInterval(offerTimer); offerKey = null;
  closeChat(); chatLastId = 0; chatSeenId = 0; rideLastMsgId = 0;
  dest = null; quote = null; price = null; reference = '';
  if (dMarker) { dMarker.remove(); dMarker = null; }
  if (routeLine) { routeLine.remove(); routeLine = null; }
  if (carMarker) { carMarker.remove(); carMarker = null; }
  if (oMarker) oMarker.dragging.enable();
  setDefaultOrigin();
  renderPlanning();
}

/* auto del conductor con interpolación suave entre polls */
function moveCar(pos) {
  const to = [pos.lat, pos.lng];
  if (!carMarker) { carMarker = L.marker(to, { icon: icon('car', '🚕', [30, 30], [15, 15]), interactive: false, zIndexOffset: 1000 }).addTo(map); carFrom = to; return; }
  const from = carFrom || carMarker.getLatLng();
  const a = L.latLng(from), b = L.latLng(to);
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
$('#chatSend').addEventListener('click', sendChat);
$('#chatIn').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendChat(); });

/* ================= PWA ================= */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

start();
