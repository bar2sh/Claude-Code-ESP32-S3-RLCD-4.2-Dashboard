// Push notifications of the app on this device (docs/SPEC.md, "Powiadomienia push"): the choices kept in
// localStorage and the body of POST push. Nothing here touches the page, the service worker or the network
// on import, so the Node tests run it as it is.

export const PUSH_KEY = 'tablica.push';
/** What a device may be alerted about: a turn started, a session waits for the user, a session finished. */
export const PUSH_EVENTS = ['working', 'waiting', 'done'];
/** The same defaults as PUSH_PREFS in server/push.php. */
export const PUSH_DEFAULTS = Object.freeze({ working: false, waiting: true, done: true, sound: true });

const storeOf = (storage) => (storage === undefined ? globalThis.localStorage : storage);

/** The choices with every field a boolean; anything else falls back to the default of that field. */
export function pushPrefs(raw) {
  const out = {};
  for (const [k, def] of Object.entries(PUSH_DEFAULTS)) out[k] = typeof raw?.[k] === 'boolean' ? raw[k] : def;
  return out;
}

export function loadPushPrefs(storage) {
  try {
    return pushPrefs(JSON.parse(storeOf(storage).getItem(PUSH_KEY) || 'null'));
  } catch {
    return pushPrefs(null);
  }
}

/** True when the browser kept the choices. */
export function savePushPrefs(prefs, storage) {
  try {
    const text = JSON.stringify(pushPrefs(prefs));
    storeOf(storage).setItem(PUSH_KEY, text);
    return storeOf(storage).getItem(PUSH_KEY) === text;
  } catch {
    return false;
  }
}

/** The server's public key (base64url, 65 bytes) as bytes for pushManager.subscribe, or null when it is not one. */
export function pushKeyBytes(key) {
  if (typeof key !== 'string' || !/^[A-Za-z0-9_-]+$/.test(key)) return null;
  let bin;
  try {
    bin = atob(key.replace(/-/g, '+').replace(/_/g, '/'));
  } catch {
    return null;
  }
  if (bin.length !== 65 || bin.charCodeAt(0) !== 4) return null;
  return Uint8Array.from(bin, (c) => c.charCodeAt(0));
}

/**
 * The body of POST push from PushSubscription.toJSON(): exactly the fields the server reads, or null when the
 * browser gave no endpoint or keys (nothing is made up).
 */
export function pushBody(sub, lang, prefs) {
  const endpoint = sub?.endpoint;
  const p256dh = sub?.keys?.p256dh;
  const auth = sub?.keys?.auth;
  if (typeof endpoint !== 'string' || typeof p256dh !== 'string' || typeof auth !== 'string') return null;
  return { endpoint, keys: { p256dh, auth }, lang, prefs: pushPrefs(prefs) };
}

/**
 * Why push cannot be switched on here, or 'ok': 'insecure' (no https), 'unsupported' (the browser has no push;
 * on an iPhone the app must be added to the Home Screen first), 'denied' (notifications are blocked for the app).
 */
export function pushSupport(env = globalThis) {
  if (env.isSecureContext === false) return 'insecure';
  if (!env.navigator || !('serviceWorker' in env.navigator) || !('PushManager' in env) || !('Notification' in env)) {
    return 'unsupported';
  }
  return env.Notification.permission === 'denied' ? 'denied' : 'ok';
}
