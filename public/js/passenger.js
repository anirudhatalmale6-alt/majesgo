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
let origin = null, dest = null;
let quote = null, price = null, method = 'efectivo';
let poll = false, pollTimer = null, lastStatus = null, carFrom = null;

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
  // ¿hay un viaje activo?
  const cur = await api('api/rides/current').catch(() => ({ ride: null }));
  if (cur.ride) { startPolling(); }
  else { setDefaultOrigin(); renderPlanning(); }
}

/* ================= MAPA ================= */
function initMap() {
  map = L.map('map', { zoomControl: false, attributionControl: true }).setView(MG.center, 15);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 20, subdomains: 'abcd',
  }).addTo(map);
  L.control.zoom({ position: 'bottomleft' }).addTo(map);

  map.on('click', (e) => {
    if (isRiding()) return;
    setDest({ lat: e.latlng.lat, lng: e.latlng.lng });
  });

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
  navigator.geolocation.getCurrentPosition((pos) => {
    const p = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    setOrigin(p);
    if (!meMarker) meMarker = L.marker(p, { icon: icon('medot', '', [16, 16], [8, 8]), interactive: false }).addTo(map);
    else meMarker.setLatLng(p);
    if (recenter || !dest) map.setView(p, 16);
    reverseGeocode(p).then((a) => { if (a) { origin.address = a; if (!isRiding()) renderPlanning(); } });
  }, () => {
    if (recenter) toast('No pudimos ubicarte. Arrastra el punto verde a tu ubicación.');
  }, { enableHighAccuracy: true, timeout: 8000 });
}

function setOrigin(p, address) {
  origin = { lat: p.lat, lng: p.lng, address: address || (origin && origin.address) };
  if (!oMarker) {
    oMarker = L.marker(p, { icon: icon('pin o', '', [26, 26], [13, 26]), draggable: true }).addTo(map);
    oMarker.on('dragend', () => {
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
  if (!address) reverseGeocode(p).then((a) => { dest.address = a; renderPlanning(); });
  refreshQuote();
}

/* ============ Geocoding (Nominatim) ============ */
async function reverseGeocode(p) {
  try {
    const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${p.lat}&lon=${p.lng}&format=json&zoom=17&accept-language=es`);
    const d = await r.json();
    return shortAddr(d.address) || 'Punto en el mapa';
  } catch (e) { return 'Punto en el mapa'; }
}
function shortAddr(a) {
  if (!a) return null;
  const road = a.road || a.pedestrian || a.neighbourhood || a.suburb;
  const area = a.suburb || a.village || a.town || a.city || a.county;
  return [road, area].filter(Boolean).slice(0, 2).join(', ') || null;
}
let searchT;
async function searchPlaces(q, box) {
  clearTimeout(searchT);
  if (q.length < 3) { box.innerHTML = ''; return; }
  searchT = setTimeout(async () => {
    try {
      const vb = `${MG.center[1] - 0.15},${MG.center[0] + 0.12},${MG.center[1] + 0.15},${MG.center[0] - 0.12}`;
      const r = await fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(q)}&format=json&limit=6&accept-language=es&viewbox=${vb}&bounded=1`);
      let d = await r.json();
      if (!d.length) { const r2 = await fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(q + ', Arequipa, Peru')}&format=json&limit=6&accept-language=es`); d = await r2.json(); }
      box.innerHTML = d.map((x) => `<div data-lat="${x.lat}" data-lon="${x.lon}"><div class="t">${(x.display_name || '').split(',')[0]}</div><div class="s">${(x.display_name || '').split(',').slice(1, 3).join(',')}</div></div>`).join('');
      box.querySelectorAll('div[data-lat]').forEach((el) => el.addEventListener('click', () => {
        const p = { lat: +el.dataset.lat, lng: +el.dataset.lon };
        setDest(p, el.querySelector('.t').textContent);
        map.setView(p, 16); box.innerHTML = '';
        if (origin) map.fitBounds(L.latLngBounds([origin, p]).pad(0.3));
      }));
    } catch (e) { box.innerHTML = ''; }
  }, 450);
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
    <div class="fieldrow"><span class="dot o"></span><input id="oIn" value="${(origin && origin.address) ? esc(origin.address) : 'Mi ubicación'}" readonly><small>Origen</small></div>
    <div class="sugg">
      <div class="fieldrow"><span class="dot d"></span><input id="dIn" placeholder="Escribe el destino o toca el mapa" value="${dest && dest.address ? esc(dest.address) : ''}"><small>Destino</small></div>
      <div class="suggbox" id="sugg"></div>
    </div>
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
      dest_lat: dest.lat, dest_lng: dest.lng, dest_address: dest.address || 'Destino',
      offered_price: price, payment_method: method,
    });
    startPolling();
  } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = 'Buscar taxi · ' + money(price); }
}

/* ================= POLLING / VIAJE ================= */
function isRiding() { return !!poll; }
const ACTIVE_ST = ['aceptado', 'en_camino', 'llego', 'a_bordo'];

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
  // dibujar rutas
  if (r.status === 'aceptado' || r.status === 'en_camino' || r.status === 'llego') {
    if (r.route_to_pickup) drawRoute(r.route_to_pickup, '#FFC107');
  } else if (r.status === 'a_bordo') {
    if (r.route_trip) drawRoute(r.route_trip, '#00C853');
  }
  // marcador del auto
  if (r.driver_pos && r.driver_pos.lat) moveCar(r.driver_pos);
  // marcadores origen/destino
  if (oMarker) oMarker.setLatLng([r.origin.lat, r.origin.lng]).dragging.disable();
  if (!dMarker) setDest({ lat: r.dest.lat, lng: r.dest.lng }, r.dest.address); else dMarker.setLatLng([r.dest.lat, r.dest.lng]);

  if (r.status === 'completado') { ackRide(r); renderCompleted(r); return; }
  if (r.status === 'cancelado' || r.status === 'sin_conductor') {
    ackRide(r); stopPolling();
    toast(r.status === 'cancelado' ? 'El viaje fue cancelado.' : r.status_label);
    resetAfterRide(); return;
  }
  if (r.status === 'solicitando') { renderSearching(r); return; }
  renderAssigned(r);
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
      <button class="btn ghost" id="btnCallish">💬 Contactar</button>
      ${canCancel ? '<button class="btn danger" id="btnCancel">Cancelar</button>' : ''}
    </div>`;
  const c = $('#btnCancel'); if (c) c.addEventListener('click', cancelRide);
  $('#btnCallish').addEventListener('click', () => toast('En la app real: llamada o chat con el conductor (Hito 4).'));
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
  dest = null; quote = null; price = null;
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

/* ================= PWA ================= */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

start();
