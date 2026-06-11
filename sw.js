const CACHE_NAME = 'mypos-cache-v4';
const STATIC_ASSETS = [
  '/manifest.webmanifest'
];
const IS_LOCAL_DEV = self.location.hostname === 'localhost' || self.location.hostname === '127.0.0.1';

if (IS_LOCAL_DEV) {
  self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
      caches.keys().then((cacheNames) => Promise.all(cacheNames.map((name) => caches.delete(name))))
    );
  });

  self.addEventListener('activate', (event) => {
    event.waitUntil(
      caches.keys()
        .then((cacheNames) => Promise.all(cacheNames.map((name) => caches.delete(name))))
        .then(() => self.registration.unregister())
        .then(() => self.clients.matchAll())
        .then((clients) => Promise.all(clients.map((client) => client.navigate(client.url))))
    );
  });

  self.addEventListener('fetch', () => {});
} else {

// Instalar y pre-cachear estáticos mínimos
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS);
    })
  );
});

// Limpiar caches antiguos
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    })
      .then(() => self.clients.claim())
      .then(() => self.clients.matchAll({ type: 'window' }))
      .then((clients) => Promise.all(clients.map((client) => client.navigate(client.url))))
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // 1. API - Network Only (Nunca cachear peticiones de la API)
  if (url.pathname.startsWith('/api/')) {
    return; // deja que el navegador la maneje normal (fallará si no hay red, lo cual es esperado)
  }

  // 2. Navegación (HTML) - Network First, fallback a caché
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request, { cache: 'no-store' }).catch(() => {
        return caches.match('/index.html');
      })
    );
    return;
  }

  // 3. Assets estáticos (JS, CSS, Imágenes, Fuentes) - Cache First, fallback a Network
  if (
    event.request.destination === 'script' ||
    event.request.destination === 'style' ||
    event.request.destination === 'image' ||
    event.request.destination === 'font'
  ) {
    event.respondWith(
      fetch(event.request).then((networkResponse) => {
          // Guardar en cache para la próxima vez
          if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, responseToCache);
            });
          }
          return networkResponse;
        }).catch(() => caches.match(event.request))
    );
  }
});
}
