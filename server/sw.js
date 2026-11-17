// Service worker of Tablica: keeps only the app's own files, so the app opens without a network.
// Requests to api.php are never stored and never intercepted.
// A new app version needs a new cache name: the old cache is deleted on activation and the app offers a reload.
// v3 was the board settings, v4 the app language, v5 both together, v6 the Clawd animations,
// v7 the Clawd animations with the board settings, v8 the own listen interval, v9 the Dashboard,
// v11 push notifications.
// It also shows the push notifications (docs/SPEC.md, "Powiadomienia push"): one notification with the Claude
// sessions, replaced in place. The server writes the text; nothing here reads the token or calls the API.

const CACHE = 'tablica-v11';
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
  'lib/push.js',
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

// ---------- push notifications ----------

const PUSH_TAG = 'claude';
const PUSH_ICON = 'icons/icon-192.png';

/** The payload of push.php as notification parts, or null when it is not one. */
function pushStatus(data) {
  let d = null;
  try {
    d = data ? data.json() : null;
  } catch {
    d = null;
  }
  if (!d || typeof d.title !== 'string' || d.title === '' || typeof d.body !== 'string') return null;
  return {
    title: d.title,
    body: d.body,
    alert: d.alert === true,
    silent: d.silent !== false,
    rev: Number.isFinite(d.rev) ? d.rev : 0,
    ts: Number.isFinite(d.ts) ? d.ts : Date.now(),
  };
}

// Every push has to end in a visible notification, or the browser shows its own "updated in the background".
// A status older than the one on screen (two pushes that overtook each other) repeats the one on screen.
async function showStatus(status) {
  const [shown] = await self.registration.getNotifications({ tag: PUSH_TAG });
  let s = status;
  if (shown && (!s || s.rev < (shown.data?.rev ?? 0))) {
    s = { title: shown.title, body: shown.body, alert: false, silent: true, rev: shown.data?.rev ?? 0, ts: shown.timestamp };
  }
  if (!s) s = { title: 'Claude', body: '', alert: false, silent: true, rev: 0, ts: Date.now() };
  await self.registration.showNotification(s.title, {
    body: s.body,
    tag: PUSH_TAG,
    renotify: s.alert, // false: the text changes in place, without a new alert
    silent: s.silent,
    icon: PUSH_ICON,
    timestamp: s.ts,
    data: { rev: s.rev },
  });
}

self.addEventListener('push', (event) => {
  event.waitUntil(showStatus(pushStatus(event.data)));
});

// A tap opens the app (it starts on the Dashboard) or brings its window to the front.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const win = wins.find((w) => w.url.startsWith(self.registration.scope));
    if (win) await win.focus();
    else await self.clients.openWindow('./');
  })());
});
