// Service worker MajesGo — habilita instalacion PWA y carga offline del cascaron.
const CACHE = 'majesgo-v3';
// Clave pública VAPID (es pública; se usa para re-suscribir si el navegador rota la suscripción).
const VAPID_PUBLIC = 'BDV6J4XobtuFG8SljAasHxOSM_t_Pwn-iAGJUaL3ycL_W4wLMpSYJ6-dKw7LK50IUXrIHBwuI5MpC_oVbGPGo50';
function vapidKey() {
  const pad = '='.repeat((4 - VAPID_PUBLIC.length % 4) % 4);
  const b = (VAPID_PUBLIC + pad).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b);
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}
const ASSETS = [
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/manifest.webmanifest',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first para navegacion (siempre datos frescos del panel); cache-first para iconos.
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(req)));
    return;
  }
  if (url.pathname.startsWith('/icons/')) {
    e.respondWith(caches.match(req).then((r) => r || fetch(req)));
  }
});

// ---- Notificaciones push ----
self.addEventListener('push', (e) => {
  let data = {};
  try { data = e.data ? e.data.json() : {}; } catch (_) {}
  const title = data.title || 'MajesGo';
  const url = data.url || '/';
  e.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    tag: data.tag || 'majesgo',
    renotify: true,
    vibrate: [130, 60, 130],
    data: { url: url },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cs) => {
      for (const c of cs) {
        if (c.url.indexOf(url) !== -1 && 'focus' in c) return c.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});

// El navegador rota/expira la suscripción → re-suscribir y reenviar al servidor,
// así el conductor/pasajero no deja de recibir avisos silenciosamente.
self.addEventListener('pushsubscriptionchange', (e) => {
  e.waitUntil((async () => {
    try {
      const sub = await self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: vapidKey(),
      });
      const opt = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub.toJSON()),
        credentials: 'include',
      };
      // No sabemos si el dispositivo es conductor o pasajero: intentamos en ambos
      // (el que tenga sesión válida lo guarda; el otro responde 401 y se ignora).
      await fetch('/conductor/api/push/subscribe', opt).catch(() => {});
      await fetch('/app/api/push/subscribe', opt).catch(() => {});
    } catch (_) {}
  })());
});
