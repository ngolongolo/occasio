const CACHE_VERSION = 'okesheni-v3';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const STATIC_ASSETS = [
  '/offline.html',
  '/assets/style.css',
  '/assets/guests.css',
  '/assets/cobrand.css',
  '/assets/landing.css',
  '/assets/auth.css',
  '/assets/pwa.css',
  '/assets/venue-picker.css',
  '/assets/venue-picker.js',
  '/assets/logo/favicon.png',
  '/assets/logo/logo_main.png',
  '/assets/logo/logo_large.png',
  '/assets/logo/logo_vertical.png'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(STATIC_CACHE).then(cache => cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('okesheni-') && key !== STATIC_CACHE).map(key => caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const sensitive = ['/api/', '/rsvp/', '/join/', '/occasion/', '/check-in/', '/login', '/register', '/forgot-password', '/reset-password', '/events/', '/dashboard', '/admin/'];
  if (sensitive.some(path => url.pathname.startsWith(path))) {
    if (request.mode === 'navigate') event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(fetch(request).then(response => {
      if (response.ok) caches.open(STATIC_CACHE).then(cache => cache.put(request, response.clone()));
      return response;
    }).catch(() => caches.match(request)));
  }
});
