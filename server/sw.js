// Service worker of Tablica: keeps only the app's own files, so the app opens without a network.
// Requests to api.php are never stored and never intercepted.
// A new app version needs a new cache name: the old cache is deleted on activation and the app offers a reload.
// v3 was the board settings, v4 the app language, v5 both together, v6 the Clawd animations,
// v7 the Clawd animations with the board settings, v8 the own listen interval, v9 the Dashboard.

const CACHE = 'tablica-v10';
const FILES = [
  './',
  'index.html',
  'app.css',
  'app.js',
  'lib/api.js',
  'lib/wav.js',
  'lib/dither.js',
  'lib/text.js',
  'lib/anim.js',
  'lib/dash.js',
  'lib/i18n.js',
  'manifest.webmanifest',
  'manifest-pl.webmanifest',
  'icons/icon.svg',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'icons/apple-touch-icon.png',
];
const APP = new Set(FILES.map((f) => new URL(f, self.location).href));

self.addEventListener('install', (event) => {
  // cache: 'reload' skips the HTTP cache, so a new version never stores an old file
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(FILES.map((f) => new Request(f, { cache: 'reload' })))),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k.startsWith('tablica-') && k !== CACHE).map((k) => caches.delete(k)),
      ))
      .then(() => self.clients.claim()),
  );
});

// The update banner ("New version available · Refresh"): the page asks the waiting version to take over.
self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  url.search = '';
  url.hash = '';
  if (!APP.has(url.href)) return; // api.php and anything else go straight to the network
  event.respondWith(
    caches.open(CACHE)
      .then((cache) => cache.match(req, { ignoreSearch: true }))
      .then((hit) => hit || fetch(req)),
  );
});
