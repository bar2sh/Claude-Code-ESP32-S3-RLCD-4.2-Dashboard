// Calls to api.php. The token travels only in the X-Token header: never in the address, never in the console.

import { t } from './i18n.js';

export class ApiError extends Error {
  constructor(message, { status = 0, serverMessage = '', offline = false } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.serverMessage = serverMessage;
    this.offline = offline;
  }
}

// The two notifications of POST notify, by the names the app shows.
const ALERT_KEYS = { done: 'notify.done', wait: 'notify.wait' };
const alertName = (id) => t(ALERT_KEYS[id]);
/** A message that ends with the advice to upload the sound as a file. */
const withFile = (key, params) => `${t(key, params)} ${t('api.useFile')}`;

// Server messages from api.php and sounds.php ("Kody błędów" in docs/SPEC.md), as dictionary keys of
// lib/i18n.js, so each one reads in the app language. 'write failed' stays untranslated on purpose:
// the spec shows it as the example of a message the app quotes as it is.
const EXACT = new Map([
  ['bad token', 'api.badToken'],
  ['config.php missing: copy config.example.php and fill it in', 'api.noConfig'],
  ['config needs at least one entry in users', 'api.noUsers'],
  ['each user needs a name and a token of 24+ chars', 'api.badUser'],
  ['state.json is corrupt', 'api.corruptState'],
  ['cannot create data dir', 'api.noDataDir'],
  ['cannot create sounds dir', 'api.noSoundsDir'],
  ['cannot lock state', 'api.noLock'],
  ['unknown action', 'api.unknownAction'],
  ['no wallpaper', 'wall.none'],
  ['no such sound', 'api.noSound'],
  ['text must be a UTF-8 string', 'api.textUtf8'],
  ['text must have 1-280 chars', 'api.textLength'],
  ['wallpaper must be exactly 15000 bytes', 'api.wallSize'],
  ['alias must match [a-z0-9-]{1,24}', 'api.alias'],
  ['sid must match [a-z0-9]{8}', 'api.sid'],
  ['hit must be true', 'api.usageHit'],
  ['ctx must be a whole number from 0 to 100', 'api.usageCtx'],
  ['nothing to report: send five, week, hit, or sid with model or ctx', 'api.usageEmpty'],
  ['model and ctx need the sid of their session', 'api.usageSid'],
  ['name must have 1-24 chars', 'api.nameLength'],
  ['id must look like s12', 'api.soundId'],
  ['url must be a myinstants.com instant page or a /media/sounds/*.mp3 link', 'api.url'],
  ['body must be a JSON object', 'api.body'],
  ['body too large', 'api.tooLarge'],
  ['settings need lang or poll_s', 'api.settingsEmpty'],
  ['sound library is full (50): delete a sound first', 'api.libraryFull'],
  ['cURL is not enabled on this hosting: download the sound and upload the file instead',
    () => withFile('api.noCurl')],
  ['no sound found on that page', 'api.noSoundOnPage'],
  ['myinstants did not return an MP3 file', 'api.notMp3'],
  ['myinstants redirected to another site', 'api.redirectAway'],
  ['too many redirects from myinstants', 'api.tooManyRedirects'],
  ['not a WAV file', 'api.notWav'],
  ['WAV fmt chunk is broken', 'api.wavFmt'],
  ['WAV data chunk comes before fmt', 'api.wavOrder'],
  ['WAV data chunk is truncated', 'api.wavTruncated'],
  ['WAV needs a fmt and a data chunk', 'api.wavParts'],
  ['WAV has no whole 16-bit samples', 'api.wavSamples'],
  ['sound is longer than 15 s', 'api.tooLong'],
]);

const PATTERNS = [
  [/^token '([a-z]+)' missing or shorter than 24 chars$/, (m) => t('api.tokenMissing', { name: m[1] })],
  [/^sound must be a built-in tone \(/, () => t('api.soundNotInLibrary')],
  [/^view must be one of: /, () => t('api.view')],
  [/^lang must be one of: /, () => t('api.lang')],
  [/^poll_s must be null \(default\) or whole seconds from (\d+) to (\d+)$/,
    (m) => t('api.poll', { min: m[1], max: m[2] })],
  [/^state must be one of: /, () => t('api.state')],
  [/^mode must be one of: /, () => t('api.mode')],
  [/^scope must be one of: /, () => t('api.scope')],
  [/^(done|wait) must be an object$/, (m) => t('api.notifyObject', { name: alertName(m[1]) })],
  [/^(done|wait)\.sound must be a built-in tone \(/, (m) => t('api.notifySound', { name: alertName(m[1]) })],
  [/^(done|wait)\.anim must be one of: /, (m) => t('api.notifyAnim', { name: alertName(m[1]) })],
  [/^file larger than (\d+) bytes$/, (m) => t('api.fileTooLarge', { n: m[1] })],
  [/^WAV must be PCM 16-bit mono 16000 Hz/, () => t('api.wavFormat')],
  [/^myinstants file is larger than (\d+) bytes$/, (m) => t('api.miTooLarge', { n: m[1] })],
  [/^myinstants did not answer: ([^\n]*)$/, (m) => t('api.miNoAnswer', { detail: m[1] })],
  [/^myinstants refused the request \(HTTP (\d+)\)/, (m) => withFile('api.miRefused', { code: m[1] })],
  [/^myinstants answered HTTP (\d+)$/, (m) => t('api.miHttp', { code: m[1] })],
];

/** A sentence in the app language for a server error; unknown messages are quoted as they are, with the HTTP code. */
export function translateError(serverMessage, status) {
  const msg = typeof serverMessage === 'string' ? serverMessage : '';
  if (EXACT.has(msg)) {
    const hit = EXACT.get(msg);
    return typeof hit === 'function' ? hit() : t(hit);
  }
  for (const [re, make] of PATTERNS) {
    const m = re.exec(msg);
    if (m) return make(m);
  }
  if (msg === '') return t('api.badAnswer', { status });
  return t('api.raw', { msg, status });
}

// An own interval in listen mode: whole seconds, the same bounds as in server/api.php and firmware/main/app.h.
export const POLL_MIN_S = 5;
export const POLL_MAX_S = 3600;

/** Seconds typed in by the user, or null: only the ASCII digits 0-9, so "45" and " 45 " pass, "4.5", "1e3" and "-10" do not. */
export function pollSeconds(text) {
  const s = typeof text === 'string' ? text.trim() : '';
  if (!/^[0-9]{1,4}$/.test(s)) return null;
  const n = Number(s);
  return n >= POLL_MIN_S && n <= POLL_MAX_S ? n : null;
}

/**
 * The body of POST notify: exactly the fields the server reads. Nothing is made up: a missing field
 * stays missing, so the server names it instead of the app saving a guess.
 */
export function notifyBody(n) {
  const alert = (a) => ({ sound: a?.sound, anim: a?.anim });
  return { mode: n?.mode, scope: n?.scope, done: alert(n?.done), wait: alert(n?.wait) };
}

/**
 * One client per token. onUnauthorized runs on every 401, onConnection(true|false) after every
 * request that reached (or failed to reach) the server.
 */
export function createApi({
  token,
  fetch: fetchFn = (...args) => globalThis.fetch(...args),
  base = 'api.php',
  onUnauthorized = () => {},
  onConnection = () => {},
}) {
  async function call(method, action, { query = {}, json, body, type, as = 'json' } = {}) {
    const params = new URLSearchParams({ a: action, ...query });
    const headers = { 'X-Token': token };
    let payload;
    if (json !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(json);
    } else if (body !== undefined) {
      headers['Content-Type'] = type;
      payload = body;
    }
    let res;
    try {
      res = await fetchFn(`${base}?${params}`, {
        method,
        headers,
        body: payload,
        cache: 'no-store',
        credentials: 'same-origin',
      });
    } catch {
      onConnection(false);
      throw new ApiError(t('offline'), { offline: true });
    }
    onConnection(true);
    if (!res.ok) {
      let serverMessage = '';
      try {
        const data = await res.json();
        if (data && typeof data.error === 'string') serverMessage = data.error;
      } catch {
        // not JSON: a hosting error page, for example
      }
      if (res.status === 401) onUnauthorized();
      throw new ApiError(translateError(serverMessage, res.status), { status: res.status, serverMessage });
    }
    try {
      return { data: as === 'json' ? await res.json() : await res.arrayBuffer(), res };
    } catch {
      throw new ApiError(t('api.badAnswer', { status: res.status }), { status: res.status });
    }
  }

  return {
    /** Name assigned to the token in config.php. */
    async me() {
      const { data } = await call('GET', 'me');
      if (!data || typeof data.name !== 'string') throw new ApiError(translateError('', 200), { status: 200 });
      return data.name;
    },
    /** The whole state plus the server clock (ms) from the Date header, or null without one. */
    async state() {
      const { data, res } = await call('GET', 'state');
      const date = Date.parse(res.headers?.get?.('Date') ?? '');
      return { state: data, serverTime: Number.isFinite(date) ? date : null };
    },
    async sendMessage(text, sound) {
      return (await call('POST', 'message', { json: { text, sound } })).data;
    },
    async setView(view) {
      return (await call('POST', 'view', { json: { view } })).data;
    },
    /** Partial update, e.g. {lang: 'pl'}, {poll_s: 45} or {poll_s: null} (the default); answers {ok, rev, settings}. */
    async setSettings(settings) {
      const json = {};
      if (settings?.lang !== undefined) json.lang = settings.lang;
      if (settings?.poll_s !== undefined) json.poll_s = settings.poll_s;
      return (await call('POST', 'settings', { json })).data;
    },
    /** Notification settings for the whole board: { mode, scope, done: { sound, anim }, wait: { ... } }. */
    async setNotify(settings) {
      return (await call('POST', 'notify', { json: notifyBody(settings) })).data;
    },
    /** 15000 bytes, or null when the server has no wallpaper yet. */
    async getWallpaper() {
      try {
        return new Uint8Array((await call('GET', 'wallpaper', { as: 'bytes' })).data);
      } catch (e) {
        if (e instanceof ApiError && e.status === 404 && e.serverMessage === 'no wallpaper') return null;
        throw e;
      }
    },
    async postWallpaper(bytes) {
      return (await call('POST', 'wallpaper', { body: bytes, type: 'application/octet-stream' })).data;
    },
    /** WAV bytes of a library sound. */
    async getSound(id) {
      return (await call('GET', 'sound', { query: { id }, as: 'bytes' })).data;
    },
    async postSound(name, wavBytes) {
      return (await call('POST', 'sound', { query: { name }, body: wavBytes, type: 'audio/wav' })).data;
    },
    async deleteSound(id) {
      return (await call('POST', 'sound_delete', { json: { id } })).data;
    },
    /** MP3 bytes behind a myinstants link, fetched by the server. */
    async soundSource(url) {
      return (await call('GET', 'sound_source', { query: { url }, as: 'bytes' })).data;
    },
  };
}
