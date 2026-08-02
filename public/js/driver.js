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
let reqCode = null, reqTimer = null, poll = null, lastPostAt = 0, commission = 0.5;

const ACTIVE = ['aceptado', 'en_camino', 'llego', 'a_bordo'];

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
}

/* ================= MAPA ================= */
function initMap() {
  map = L.map('map', { zoomControl: false, attributionControl: true }).setView(MG.center, 15);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 20, subdomains: 'abcd',
  }).addTo(map);
  L.control.zoom({ position: 'bottomleft' }).addTo(map);
  $('#btnMenu').addEventListener('click', openDrawer);
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
    if (!meMarker) meMarker = L.marker(myPos, { icon: icon('medot', '', [16, 16], [8, 8]), interactive: false, zIndexOffset: 900 }).addTo(map);
    else meMarker.setLatLng(myPos);
    // primera vez, centrar
    if (!map._centeredOnce) { map.setView(myPos, 16); map._centeredOnce = true; }
    pushLocation();
  }, () => {}, { enableHighAccuracy: true, maximumAge: 4000, timeout: 12000 });
}
function pushLocation() {
  if (!myPos) return;
  const now = Date.now();
  if (now - lastPostAt < 4000) return;          // como máximo cada 4s
  if (!online && !(ride && ACTIVE.includes(ride.status))) return;
  lastPostAt = now;
  api('api/location', { lat: myPos.lat, lng: myPos.lng }).then((r) => {
    if (r && typeof r.saldo === 'number') updateSaldo(r.saldo, r.can_receive);
  }).catch(() => {});
}

/* ================= HOME (conectar/desconectar) ================= */
function renderHome() {
  clearTrip();
  const canR = me.can_receive;
  const lowSaldo = me.saldo < commission;
  const b = $('#sheetBody');
  b.innerHTML = `
    <div class="connectrow">
      <button class="bigswitch ${online ? 'on' : 'off'}" id="bigSw">
        ${online ? '<span class="pulse"></span>' : ''}
        <span class="ic">${online ? '🟢' : '⏻'}</span>
        ${online ? 'EN LÍNEA' : 'CONECTAR'}
      </button>
      <div class="statetxt">${online ? 'Conectado · buscando viajes' : 'Estás desconectado'}</div>
      <div class="statesub">${online ? 'Te avisaremos apenas haya un viaje cerca.' : 'Conéctate para empezar a recibir viajes.'}</div>
    </div>
    ${lowSaldo ? `<div class="warn red" style="margin-top:14px">⚠️ Tu saldo (${money(me.saldo)}) no alcanza para la comisión de ${money(commission)}. Recarga para poder recibir viajes.</div>
      <button class="btn amber" id="btnRecharge">Recargar saldo</button>` : `
      <div class="routeinfo" style="margin-top:14px">
        <div class="chip"><div class="v a">${money(me.saldo)}</div><div class="l">Saldo</div></div>
        <div class="chip"><div class="v">⭐ ${(me.rating || 5).toFixed(1)}</div><div class="l">Calificación</div></div>
        <div class="chip"><div class="v">${me.trips || 0}</div><div class="l">Viajes</div></div>
      </div>`}
  `;
  $('#bigSw').addEventListener('click', toggleOnline);
  const rc = $('#btnRecharge'); if (rc) rc.addEventListener('click', openDrawer);
}

async function toggleOnline() {
  const sw = $('#bigSw'); sw.disabled = true;
  try {
    if (!online) {
      const body = { online: true };
      if (myPos) { body.lat = myPos.lat; body.lng = myPos.lng; }
      await api('api/connect', body);
      online = true; toast('Conectado. Buscando viajes cercanos…');
    } else {
      await api('api/connect', { online: false });
      online = false; toast('Te desconectaste.');
    }
    me.status = online ? 'disponible' : 'desconectado';
    renderHome();
  } catch (e) {
    toast(e.message);
    if (e.status === 422) openDrawer();
  } finally { sw.disabled = false; }
}

/* ================= POLLING ================= */
function startPoll() { if (poll) clearInterval(poll); tick(); poll = setInterval(tick, 3000); }

async function tick() {
  // viaje en curso → seguir su estado
  if (ride && ACTIVE.includes(ride.status)) {
    try { const d = await api('api/current'); handleCurrent(d.ride); } catch (e) {}
    return;
  }
  // libre y en línea → buscar solicitudes
  if (online && !reqCode) {
    try {
      const d = await api('api/pending');
      commission = d.commission || commission;
      if (d.requests && d.requests.length && !ride && !reqCode) showRequest(d.requests[0]);
    } catch (e) {}
  }
}

function handleCurrent(r) {
  if (!r) { return; }             // sin cambios relevantes
  if (r.status === 'cancelado') {
    ackRide(r); ride = null; toast('El pasajero canceló el viaje.');
    online = true; me.status = 'disponible'; renderHome(); return;
  }
  if (r.status === 'completado') { ride = r; renderCompleted(r); return; }
  ride = r; renderRide(r);
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
    <div class="addr"><span class="dot o"></span><div class="tx">${esc(req.origin.address || 'Punto de recojo')}<small>Recojo · a ${km(req.to_pickup_m)}</small></div></div>
    <div class="addr"><span class="dot d"></span><div class="tx">${esc(req.dest.address || 'Destino')}<small>Destino · ${km(req.trip_distance_m)} · ${mins(req.trip_duration_s)}</small></div></div>
    <div class="acts">
      <button class="btn ghost" id="reqNo">Rechazar</button>
      <button class="btn" id="reqYes">Aceptar</button>
    </div>`;
  // vista previa en el mapa
  setPin('o', [req.origin.lat, req.origin.lng]);
  setPin('d', [req.dest.lat, req.dest.lng]);
  map.fitBounds(L.latLngBounds([[req.origin.lat, req.origin.lng], [req.dest.lat, req.dest.lng]]).pad(0.35), { paddingBottomRight: [0, 340] });

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
function hideRequest() {
  clearInterval(reqTimer); reqTimer = null; reqCode = null;
  $('#reqwrap').classList.add('hidden');
}
async function acceptRequest(req) {
  const btn = $('#reqYes'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
  try {
    const r = await api('api/accept', { code: req.code });
    hideRequest();
    ride = r.ride; online = true; me.status = 'ocupado';
    toast('¡Viaje aceptado! Ve a recoger al pasajero.');
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
  // rutas
  if (r.status === 'a_bordo') drawRoute(r.route_trip, '#00C853');
  else drawRoute(r.route_to_pickup, '#FFC107');
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
    <div class="addr"><span class="dot d"></span><div class="tx">${esc(r.dest.address || 'Destino')}<small>Destino · ${km(r.distance_m)} · ${money(r.offered_price)} ${r.payment_method === 'yape' ? '(Yape)' : '(efectivo)'}</small></div></div>
    ${primary}
    <div class="acts">
      <button class="btn ghost" id="btnNav">🧭 Navegar</button>
      ${goingToDest ? '' : '<button class="btn danger" id="btnCancel">Cancelar</button>'}
    </div>`;

  const a = $('#btnArrive'); if (a) a.addEventListener('click', () => act('api/arrive', 'Marcado: llegaste al punto.'));
  const s = $('#btnStart'); if (s) s.addEventListener('click', () => act('api/start', 'Viaje iniciado. Buen camino.'));
  const c = $('#btnComplete'); if (c) c.addEventListener('click', completeRide);
  const cc = $('#btnCancel'); if (cc) cc.addEventListener('click', cancelRide);
  $('#btnNav').addEventListener('click', () => {
    window.open('https://www.google.com/maps/dir/?api=1&destination=' + navTarget.lat + ',' + navTarget.lng + '&travelmode=driving', '_blank');
  });
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
  $('#saldoPill').classList.remove('hidden');
  $('#saldoVal').textContent = money(saldo);
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
