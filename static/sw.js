/* Service Worker — Causeries 1/4h Securite */
const CACHE = 'causeries-v1';
const STATIC = [
  '/',
  '/static/manifest.json',
  '/static/icon.svg'
];

/* Installation : cache les fichiers statiques */
self.addEventListener('install', ev => {
  self.skipWaiting();
  ev.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC))
  );
});

/* Activation : nettoie les anciens caches */
self.addEventListener('activate', ev => {
  ev.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

/* Stratégie :
 * - Network First pour les appels API (/api/)
 * - Cache First pour les statiques
 */
self.addEventListener('fetch', ev => {
  const url = new URL(ev.request.url);

  /* API : Network First avec fallback cache */
  if (url.pathname.startsWith('/api/')) {
    ev.respondWith(networkFirst(ev.request));
    return;
  }

  /* Statiques : Cache First */
  ev.respondWith(cacheFirst(ev.request));
});

async function networkFirst(req) {
  try {
    const net = await fetch(req);
    if (net.ok) {
      const cache = await caches.open(CACHE);
      cache.put(req, net.clone());
    }
    return net;
  } catch (e) {
    const cached = await caches.match(req);
    if (cached) return cached;
    /* Si pas de cache et hors-ligne, retourne une réponse JSON d'erreur */
    return new Response(
      JSON.stringify({ ok: false, error: 'Hors-ligne', offline: true }),
      { headers: { 'Content-Type': 'application/json' } }
    );
  }
}

async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    return await fetch(req);
  } catch (e) {
    /* En dernier recours : page d'accueil en cache */
    const fallback = await caches.match('/');
    return fallback || new Response('Hors-ligne', { status: 503 });
  }
}
