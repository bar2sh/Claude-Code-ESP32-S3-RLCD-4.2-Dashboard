// Run: node server/tests/app_test.mjs   (no network: fetch and Web Audio are fakes, nothing is downloaded)
// Tests the PWA modules in server/lib. Where the browser must compute exactly what the server or the
// converter computes, the same input also goes through PHP (sounds.php and the lines of api.php that clean
// a message) or Python (tools/img2rlcd.py) on this machine, and the results must be identical.

import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import * as anim from '../lib/anim.js';
import * as dash from '../lib/dash.js';
import * as api from '../lib/api.js';
import * as dither from '../lib/dither.js';
import * as i18n from '../lib/i18n.js';
import * as text from '../lib/text.js';
import * as wav from '../lib/wav.js';

const LANG_AT_START = i18n.lang(); // before any test switches the language

const SERVER = dirname(dirname(fileURLToPath(import.meta.url)));
const ROOT = dirname(SERVER);
const API_PHP = join(SERVER, 'api.php');
const SOUNDS_PHP = join(SERVER, 'sounds.php');
const TOOLS = join(ROOT, 'tools');
const LIBS = { 'anim.js': anim, 'api.js': api, 'dash.js': dash, 'dither.js': dither, 'i18n.js': i18n, 'text.js': text, 'wav.js': wav };

const read = (...parts) => readFileSync(join(SERVER, ...parts), 'utf8');
const apiSrc = readFileSync(API_PHP, 'utf8');
const soundsSrc = readFileSync(SOUNDS_PHP, 'utf8');
/** The strings of a const list in api.php (or another PHP source), e.g. phpList('NOTIFY_MODES'); null when it is not there. */
const phpList = (name, src = apiSrc) => {
  const m = new RegExp(`const ${name} = \\[([^\\]]*)\\]`).exec(src);
  return m ? [...m[1].matchAll(/'([^']+)'/g)].map((x) => x[1]) : null;
};

/** Runs fn once in every app language and comes back to the default one, also after a failure. */
async function inEachLang(fn) {
  try {
    for (const code of i18n.LANGS) {
      i18n.setLang(code);
      await fn(code);
    }
  } finally {
    i18n.setLang(i18n.DEFAULT_LANG);
  }
}

/** A stand-in for localStorage: the tests never touch the real one. */
function fakeStorage(items = {}, { readFails = false, writeFails = false, dropsWrites = false } = {}) {
  const data = new Map(Object.entries(items));
  return {
    data,
    getItem(key) {
      if (readFails) throw new Error('SecurityError');
      return data.has(key) ? data.get(key) : null;
    },
    setItem(key, value) {
      if (writeFails) throw new Error('QuotaExceededError');
      if (!dropsWrites) data.set(key, String(value));
    },
  };
}
const cp = (...points) => String.fromCodePoint(...points); // keeps this file free of escaped code points

let failed = 0;

function check(name, ok, detail = '') {
  if (!ok) failed++;
  console.log((ok ? '  ok   ' : '  FAIL ') + name + (!ok && detail ? '\n         ' + detail : ''));
}

function same(name, got, want) {
  const g = JSON.stringify(got);
  const w = JSON.stringify(want);
  check(name, g === w, `got ${g}, want ${w}`);
}

/** One line for a whole table of cases; the detail lists only the cases that differ. */
function sameEach(name, inputs, got, want) {
  const bad = inputs
    .map((input, i) => [input, got[i], want[i]])
    .filter(([, g, w]) => JSON.stringify(g) !== JSON.stringify(w))
    .map(([input, g, w]) => `${JSON.stringify(input)}: got ${JSON.stringify(g)}, want ${JSON.stringify(w)}`);
  check(`${name} (${inputs.length} cases)`, inputs.length === want.length && bad.length === 0, bad.join('\n         '));
}

async function section(title, fn) {
  console.log(title);
  try {
    await fn();
  } catch (e) {
    check(`${title}: runs without an exception`, false, e && e.stack ? e.stack : String(e));
  }
}

async function failure(promise) {
  try {
    await promise;
    return null;
  } catch (e) {
    return e;
  }
}

/** PHP code with sounds.php loaded; the input goes to STDIN, stdout comes back as text. */
function php(code, input = '') {
  const r = spawnSync('php', ['-r', 'require $argv[1];\n' + code, '--', SOUNDS_PHP], { input, maxBuffer: 1 << 26 });
  if (r.error) throw r.error;
  if (r.status !== 0) throw new Error(`php exited with ${r.status}: ${r.stderr}${r.stdout}`);
  return r.stdout.toString('utf8');
}

/** Python code with tools/img2rlcd.py imported as m; the input goes to stdin, stdout comes back as bytes. */
function python(code, input = '') {
  const prelude = 'import sys, json\nsys.path.insert(0, sys.argv[1])\nimport img2rlcd as m\n';
  const r = spawnSync('python3', ['-I', '-B', '-c', prelude + code, TOOLS], { input, maxBuffer: 1 << 26 });
  if (r.error) throw r.error;
  if (r.status !== 0) throw new Error(`python3 exited with ${r.status}: ${r.stderr}`);
  return r.stdout;
}

const PHP_JSON_MAP = (fn) => '$in = json_decode(stream_get_contents(STDIN), true);\n'
  + `echo json_encode(array_map(fn($x) => ${fn}($x), $in));`;

// ---------- text.js ----------

await section('text.js: what the board can draw', () => {
  const sample = 'Zażółć ' + cp(0x2014) + ' gęślą ' + cp(0x201e) + 'jaźń' + cp(0x201d) + ' ' + cp(0x2026) + ' '
    + cp(0x1f44d, 0x1f3fd) + ' ok' + cp(0x200b);
  same('message length counts code points', text.messageLength(sample), 30);
  same('unsupported characters: each once, an emoji with skin tone as one, invisible ones by code',
    text.unsupportedChars(sample + cp(0x2014)),
    [cp(0x2014), cp(0x201e), cp(0x201d), cp(0x2026), cp(0x1f44d, 0x1f3fd), 'U+200B']);
  const fixed = text.replaceUnsupported(sample);
  same('replacing gives plain punctuation and drops the rest', fixed, 'Zażółć - gęślą "jaźń" ...  ok');
  same('nothing unsupported is left after replacing', text.unsupportedChars(fixed), []);
  same('compatibility forms, other spaces, single quotes and bullets',
    text.replaceUnsupported(cp(0xfb01) + 'lm' + cp(0x2122) + cp(0x2009) + cp(0x2018) + 'x' + cp(0x2019) + cp(0x2022)),
    "filmTM 'x'" + cp(0xb7));
  same('a line separator becomes a line break', text.replaceUnsupported('a' + cp(0x2028) + 'b'), 'a\nb');
  same('Polish text needs no replacing',
    text.unsupportedChars('Zażółć gęślą jaźń. ZAŻÓŁĆ GĘŚLĄ JAŹŃ! 0-9 ' + cp(0x20ac) + ' 21' + cp(0xb0) + 'C\nok'), []);

  let drawable = 0;
  for (let p = 0; p <= 0x10ffff; p++) if (text.isDrawable(p)) drawable++;
  same('453 drawable code points: the 452 glyphs of the fonts and the line break', drawable, 453);
  check('the line break is drawable, a tab is not', text.isDrawable(0x0a) && !text.isDrawable(0x09));

  same('control characters except the line break go, then trim()', text.serverMessageText('\x00 a\tb\x7f \n'), 'ab');
  same('lone surrogates become U+FFFD',
    text.normalizeInput('a' + String.fromCharCode(0xd800) + 'b' + String.fromCharCode(0xdc00)),
    'a' + cp(0xfffd) + 'b' + cp(0xfffd));
  same('a valid surrogate pair stays', text.normalizeInput(cp(0x1f600)), cp(0x1f600));
  same('text is composed to NFC', text.normalizeInput('e' + cp(0x301)), cp(0xe9));
  same('MESSAGE_MAX matches api.php', text.MESSAGE_MAX, Number(/const MESSAGE_MAX_CHARS = (\d+);/.exec(apiSrc)?.[1]));
});

await section('text.js: message text exactly as api.php stores it', () => {
  const CLEAN = "$text = trim(preg_replace('/[\\x00-\\x09\\x0B-\\x1F\\x7F]/u', '', $text));";
  const LEN = "$len = mb_strlen($text, 'UTF-8');";
  const lines = apiSrc.split('\n').map((l) => l.trim());
  check('api.php still cleans a message with the two lines text.js copies', lines.includes(CLEAN) && lines.includes(LEN));
  const MESSAGES = [
    'Hej',
    '  Obiad gotowy!  ',
    '\x00 a\tb\x7f \n',
    '\n\nA\nB\n\n',
    '\x0b\x0c x \r\n',
    'a\r\nb',
    cp(0x1f44d, 0x1f3fd) + ' ok',
    'a' + cp(0x85) + 'b',
    cp(0xa0) + 'x' + cp(0xa0),
    'e' + cp(0x301),
    cp(0x200b),
    '\x00',
    'ą'.repeat(281),
    'Zażółć gęślą jaźń',
    'a' + String.fromCharCode(0xd800) + 'b',
  ];
  const sent = MESSAGES.map((m) => text.normalizeInput(m)); // what the app puts in the request
  const code = '$in = json_decode(stream_get_contents(STDIN), true);\n$out = [];\nforeach ($in as $text) {\n'
    + CLEAN + '\n' + LEN + '\n$out[] = [$text, $len];\n}\necho json_encode($out);';
  const fromPhp = JSON.parse(php(code, JSON.stringify(sent)));
  sameEach('cleaned text and its length like api.php', MESSAGES,
    MESSAGES.map((m) => [text.serverMessageText(m), text.messageLength(m)]), fromPhp);
});

await section('text.js: sound names and myinstants links', () => {
  same('whitespace runs become one space, the ends are trimmed', text.soundName('  a\tb  '), 'a b');
  same('24 characters fit', text.soundName('x'.repeat(24)), 'x'.repeat(24));
  same('25 characters are refused', text.soundName('x'.repeat(25)), null);
  same('an empty name is refused', text.soundName(' \t '), null);
  same('a non-string is refused', text.soundName(42), null);
  same('SOUND_NAME_MAX matches sounds.php', text.SOUND_NAME_MAX, Number(/const SOUND_NAME_MAX = (\d+);/.exec(soundsSrc)?.[1]));

  same('name hint from an instant page',
    text.nameHint('https://www.myinstants.com/pl/instant/dzwonek-do-drzwi-12345/'), 'Dzwonek do drzwi');
  same('name hint from an MP3 link', text.nameHint('https://www.myinstants.com/media/sounds/ding-dong.mp3'), 'Ding dong');
  same('name hint from an encoded MP3 name',
    text.nameHint('https://www.myinstants.com/media/sounds/dzwi%C4%99k_2.mp3'), 'Dzwięk 2');
  same('name hint from a file name', text.nameHint('/Users/ja/Nagrania/nagranie.m4a'), 'Nagranie');
  same('a long name hint is cut to 24 characters',
    text.nameHint('bardzo-dlugi-dzwiek-do-testowania-nazw.mp3'), 'Bardzo dlugi dzwiek do t');
  same('no name hint from nothing', [text.nameHint(''), text.nameHint(null)], ['', '']);

  const LINKS = [
    'https://www.myinstants.com/pl/instant/dzwonek-do-drzwi-12345/',
    'https://www.myinstants.com/instant/test-only',
    'https://myinstants.com/en-us/instant/test_only-1/',
    'http://www.myinstants.com/instant/test-only/',
    'HTTPS://WWW.MYINSTANTS.COM/instant/test-only/',
    'https://www.myinstants.com/PL/instant/test-only/',
    'https://www.myinstants.com/instant/test-only//',
    'https://www.myinstants.com//instant/test-only/',
    'https://www.myinstants.com/instant/test-only/?utm_source=x#top',
    'https://www.myinstants.com/instant/test-only/#top',
    'https://www.myinstants.com/instant//',
    'https://www.myinstants.com/',
    'https://www.myinstants.com/media/sounds/test-only.mp3',
    'https://www.myinstants.com/media/sounds/Test%20Only_(1)~v2.MP3',
    'https://www.myinstants.com/media/sounds/test-only.mp3?v=2',
    'https://www.myinstants.com/media/sounds/sub/test-only.mp3',
    'https://www.myinstants.com/media/sounds/test-only.wav',
    'https://example.com/instant/test-only/',
    'https://www.myinstants.com.example.com/instant/test-only/',
    'https://example.com/?next=https://www.myinstants.com/instant/test-only/',
    'ftp://www.myinstants.com/instant/test-only/',
    '  https://www.myinstants.com/instant/test-only/ \n',
    '\x0b\x00https://www.myinstants.com/instant/test-only/\t',
    'https://www.myinstants.com/instant/test-only/\nhttps://example.com/',
    'https://www.myinstants.com/instant/test-only/?a\nb',
    'https://www.myinstants.com/instant/test-only/?a\rb',
    'https://www.myinstants.com/instant/te\x00st/',
    'https://www.myinstants.com/instant/ą/',
    'https://www.myinstants.com/instant/test-only/' + cp(0xa0),
    '',
  ];
  const ours = LINKS.map((l) => text.myinstantsSource(l));
  sameEach('myinstants links accepted and rewritten like sounds.php', LINKS, ours,
    JSON.parse(php(PHP_JSON_MAP('myinstantsSource'), JSON.stringify(LINKS))));
  const kinds = ours.map((s) => (s ? s.kind : 'none'));
  check('the link table has pages, MP3 files and refusals',
    kinds.filter((k) => k === 'page').length >= 5 && kinds.filter((k) => k === 'mp3').length >= 3
      && kinds.filter((k) => k === 'none').length >= 10, kinds.join(' '));
  check('a rewritten link is accepted as it is',
    ours.filter(Boolean).every((s) => JSON.stringify(text.myinstantsSource(s.url)) === JSON.stringify(s)));

  const NAMES = [
    'Dzwonek do drzwi',
    '  a\tb  ',
    'a \t\n b',
    'a' + cp(0xa0) + 'b',
    'a' + cp(0x85) + 'b',
    'a' + cp(0x180e) + 'b',
    'a' + cp(0xfeff) + 'b',
    'a' + cp(0x2028) + 'b',
    'a' + cp(0x3000) + 'b',
    'a' + cp(0x200b) + 'b',
    'a' + cp(0x1680) + 'b',
    'a' + cp(0x202f) + cp(0x205f) + 'b',
    '\t' + cp(0xa0) + 'x' + cp(0x3000),
    'x\x00y\x7fz' + cp(0x9f),
    'b\x00 \x00 a',
    '\x00\x01',
    '   ',
    '',
    'ą'.repeat(24),
    'ą'.repeat(25),
    '  ' + 'b'.repeat(24) + '  ',
    cp(0x1f600).repeat(24),
    cp(0x1f600).repeat(25),
    'Zażółć gęślą jaźń',
  ];
  const names = NAMES.map((n) => text.soundName(n));
  sameEach('sound names cleaned like sounds.php soundName()', NAMES, names,
    JSON.parse(php(PHP_JSON_MAP('soundName'), JSON.stringify(NAMES))));
  // The app sends the cleaned name and sounds.php cleans it once more. That second pass may only merge the
  // spaces a removed control character left behind ("b\x00 \x00 a" is "b  a", then "b a"), never refuse it.
  const sentNames = names.filter((n) => n !== null);
  const again = JSON.parse(php(PHP_JSON_MAP('soundName'), JSON.stringify(sentNames)));
  check('sounds.php accepts every name the app sends',
    again.every((n) => typeof n === 'string') && JSON.stringify(again) === JSON.stringify(sentNames.map((n) => text.soundName(n))),
    JSON.stringify(again));
});

await section('text.js: one-letter words do not end a line', () => {
  const nb = cp(0xa0);
  const inputs = ['W trybie Na', 'a i w domu', 'pies (z kotem)', 'ma 300 znaków, a płytka', 'x\ni tak', 'to i', 'i  tak',
    'ab cd', '', 'Z' + nb + 'a b'];
  sameEach('glue', inputs, inputs.map((s) => text.glue(s)),
    ['W' + nb + 'trybie Na', 'a' + nb + 'i' + nb + 'w' + nb + 'domu', 'pies (z' + nb + 'kotem)', 'ma 300 znaków, a' + nb + 'płytka',
      'x\ni' + nb + 'tak', 'to i', 'i  tak', 'ab cd', '', 'Z' + nb + 'a' + nb + 'b']);
});

// ---------- wav.js ----------

const tone = (seconds, hz, rate, amp = 0.5) =>
  Float32Array.from({ length: Math.round(seconds * rate) }, (_, i) => amp * Math.sin((2 * Math.PI * hz * i) / rate));

function wavHeader(w) {
  const v = new DataView(w.buffer, w.byteOffset, w.byteLength);
  const tag = (o) => String.fromCharCode(...w.subarray(o, o + 4));
  return [tag(0), v.getUint32(4, true), tag(8), tag(12), v.getUint32(16, true), v.getUint16(20, true),
    v.getUint16(22, true), v.getUint32(24, true), v.getUint32(28, true), v.getUint16(32, true),
    v.getUint16(34, true), tag(36), v.getUint32(40, true)];
}

function peakOf(w) {
  const v = new DataView(w.buffer, w.byteOffset, w.byteLength);
  let max = 0;
  for (let o = wav.HEADER_BYTES; o + 1 < w.length; o += 2) max = Math.max(max, Math.abs(v.getInt16(o, true)));
  return max;
}

/**
 * A stand-in for OfflineAudioContext. Like the real one it decodes straight to its own sample rate.
 * minRate: contexts below it throw (older Safari); promises: false gives the old callback API.
 */
function fakeAudio({ rate, channels, minRate = 0, promises = true, decodeFails = false }) {
  const log = { rates: [], rendered: 0 };
  const later = (fn) => setTimeout(fn, 0);
  const audioBuffer = (data, sampleRate) => ({
    sampleRate,
    length: data.length ? data[0].length : 0,
    numberOfChannels: data.length,
    getChannelData: (c) => data[c],
  });
  class FakeOfflineAudioContext {
    constructor(channelCount, frames, sampleRate) {
      log.rates.push(sampleRate);
      if (sampleRate < minRate) throw new RangeError(`sample rate ${sampleRate} is not supported`);
      this.frames = frames;
      this.sampleRate = sampleRate;
      this.destination = {};
      this.oncomplete = null;
      this.source = null;
    }

    decodeAudioData(data, ok, fail) {
      const result = new Promise((resolve, reject) => {
        later(() => {
          if (decodeFails || !(data instanceof ArrayBuffer) || data.byteLength === 0) {
            reject(new Error('EncodingError'));
          } else {
            resolve(audioBuffer(channels.map((c) => wav.resampleLinear(c, rate, this.sampleRate)), this.sampleRate));
          }
        });
      });
      result.then(ok, fail); // new browsers call the callbacks and return the promise as well
      return promises ? result : undefined;
    }

    createBufferSource() {
      const src = { buffer: null, connect() {}, start: () => { this.source = src; } };
      return src;
    }

    startRendering() {
      const b = this.source.buffer;
      const data = Array.from({ length: b.numberOfChannels }, (_, c) => b.getChannelData(c));
      const mono = wav.resampleLinear(wav.mixToMono(data), b.sampleRate, this.sampleRate, this.frames);
      log.rendered++;
      const rendered = audioBuffer([mono], this.sampleRate);
      if (promises) return Promise.resolve(rendered);
      later(() => this.oncomplete({ renderedBuffer: rendered }));
      return undefined;
    }
  }
  return { Ctx: FakeOfflineAudioContext, log };
}

const phpWav = (bytes) => JSON.parse(php('$raw = stream_get_contents(STDIN);\n$r = wavCanonical($raw);\n'
  + 'echo json_encode(isset($r["error"]) ? $r : ["ms" => $r["ms"], "same" => $r["wav"] === $raw]);', bytes));

await section('wav.js: sound for the board', async () => {
  const half = wav.encodeWav(wav.normalizePeak(tone(0.5, 880, 16000)));
  same('44-byte header: PCM, mono, 16 kHz, 16-bit', wavHeader(half),
    ['RIFF', 16036, 'WAVE', 'fmt ', 16, 1, 1, 16000, 32000, 2, 16, 'data', 16000]);
  same('the loudest sample sits at -1 dBFS', [peakOf(half), Math.round(wav.PEAK * 32767)], [29204, 29204]);
  same('stereo to mono averages the channels',
    [...wav.mixToMono([Float32Array.of(0, 1, 1, 1), Float32Array.of(0, 0, 1, 1)])], [0, 0.5, 1, 1]);
  same('one channel stays as it is', [...wav.mixToMono([Float32Array.of(0, 1, 1, 1)])], [0, 1, 1, 1]);
  same('half the rate keeps every other sample', [...wav.resampleLinear(Float32Array.of(0, 1, 2, 3), 32000, 16000)], [0, 2]);
  same('a higher rate interpolates and repeats the last sample',
    [...wav.resampleLinear(Float32Array.of(0, 1), 8000, 16000, 4)], [0, 0.5, 1, 1]);
  const norm = wav.normalizePeak(Float32Array.of(NaN, Infinity, -0.5, 0.25));
  check('broken samples become 0 and the loudest one goes to -1 dBFS',
    norm[0] === 0 && norm[1] === 0 && Math.abs(norm[2] + wav.PEAK) < 1e-6 && Math.abs(norm[3] - wav.PEAK / 2) < 1e-6);
  check('silence stays silent', wav.normalizePeak(new Float32Array(8)).every((v) => v === 0));

  const stereo = fakeAudio({ rate: 48000, channels: [tone(1.5, 440, 48000, 0.3), tone(1.5, 440, 48000, 0.6)] });
  const r1 = await wav.convertToWav(new Uint8Array(64), { OfflineAudioContext: stereo.Ctx });
  same('1.5 s stereo 48 kHz: decoded and rendered by Web Audio at 16 kHz', [stereo.log.rates, stereo.log.rendered],
    [[16000, 16000], 1]);
  same('1.5 s stereo 48 kHz: 24000 samples, 1500 ms', [r1.wav.length, r1.ms, r1.seconds, r1.trimmed], [48044, 1500, 1.5, false]);

  const old = fakeAudio({ rate: 32000, channels: [tone(0.25, 440, 32000)], promises: false });
  const r2 = await wav.convertToWav(new Uint8Array(64).buffer, { webkitOfflineAudioContext: old.Ctx });
  same('old Safari callbacks (webkitOfflineAudioContext): 0.25 s', [r2.wav.length, r2.ms, old.log.rendered], [8044, 250, 1]);

  const picky = fakeAudio({ rate: 44100, channels: [tone(20, 440, 44100, 0.3), tone(20, 440, 44100, 0.6)], minRate: 22050 });
  const r3 = await wav.convertToWav(new Uint8Array(64), { OfflineAudioContext: picky.Ctx });
  same('no 16 kHz context: decoded at 44.1 kHz, mixed and resampled in JavaScript', [picky.log.rates, picky.log.rendered],
    [[16000, 44100, 16000], 0]);
  same('20 s is cut to 15 s', [r3.wav.length, r3.ms, r3.seconds, r3.trimmed], [480044, 15000, 20, true]);
  same('the cut sound still peaks at -1 dBFS', peakOf(r3.wav), 29204);

  const env = { OfflineAudioContext: fakeAudio({ rate: 16000, channels: [tone(0.1, 440, 16000)] }).Ctx };
  const REFUSED = [ // [the case, the call, the dictionary key of the sentence]
    ['a file over 20 MB', () => wav.convertToWav(new ArrayBuffer(wav.MAX_INPUT_BYTES + 1), env), 'wav.tooBig'],
    ['an empty file', () => wav.convertToWav(new ArrayBuffer(0), env), 'wav.empty'],
    ['a browser without Web Audio', () => wav.convertToWav(new Uint8Array(8), {}), 'wav.noAudio'],
    ['a file the browser cannot decode', () => wav.convertToWav(new Uint8Array(8),
      { OfflineAudioContext: fakeAudio({ rate: 16000, channels: [], decodeFails: true }).Ctx }), 'wav.undecodable'],
    ['a file that decodes to no samples', () => wav.convertToWav(new Uint8Array(8),
      { OfflineAudioContext: fakeAudio({ rate: 16000, channels: [new Float32Array(0)] }).Ctx }), 'wav.empty'],
    ['something that is not a file', () => wav.convertToWav('abc', env), 'wav.notAudio'],
  ];
  await inEachLang(async (code) => {
    const got = [];
    for (const [, run] of REFUSED) {
      const e = await failure(run());
      got.push(e instanceof wav.SoundError ? e.message : `not a SoundError: ${e && e.message}`);
    }
    sameEach(`${code}: refused with a sentence in the app language`, REFUSED.map(([name]) => name), got,
      REFUSED.map(([, , key]) => i18n.DICT[code][key]));
  });

  same('sounds.php keeps the 1.5 s WAV byte for byte', phpWav(r1.wav), { ms: r1.ms, same: true });
  same('sounds.php keeps the cut 15 s WAV byte for byte', phpWav(r3.wav), { ms: r3.ms, same: true });
  const full = wav.encodeWav(new Float32Array(wav.MAX_SAMPLES));
  same('15 s is exactly the server limit', [full.length, phpWav(full)], [480044, { ms: 15000, same: true }]);
  same('one sample more is refused by sounds.php', phpWav(wav.encodeWav(new Float32Array(wav.MAX_SAMPLES + 1))),
    { code: 413, error: 'sound is longer than 15 s' });
  same('rate and size limit match sounds.php', [wav.RATE, wav.MAX_SAMPLES * 2],
    JSON.parse(php('echo json_encode([SOUND_RATE, SOUND_MAX_DATA]);')));
});

// ---------- dither.js ----------

function image(width, height, pixel) {
  const data = new Uint8ClampedArray(width * height * 4);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) data.set(pixel(x, y), 4 * (y * width + x));
  }
  return { width, height, data };
}

const grey = (v) => [v, v, v, 255];
const BLACK = grey(0);
const WHITE = grey(255);
const flat = (v) => image(400, 300, () => grey(v));
const everyRow = (bytes, row) => Array.from({ length: 300 }, (_, y) => bytes.subarray(50 * y, 50 * y + 50))
  .every((r) => r.every((v, i) => v === row[i]));
const sameBytes = (a, b) => a.length === b.length && Buffer.compare(Buffer.from(a), Buffer.from(b)) === 0;

/** 32-bit BMP, BGRA, rows from the top (negative height): what sips writes for an image with alpha. */
function bmp32({ width, height, data }) {
  const out = new Uint8Array(54 + width * height * 4);
  const v = new DataView(out.buffer);
  out[0] = 0x42;
  out[1] = 0x4d;
  v.setUint32(2, out.length, true);
  v.setUint32(10, 54, true);
  v.setUint32(14, 40, true);
  v.setInt32(18, width, true);
  v.setInt32(22, -height, true);
  v.setUint16(26, 1, true);
  v.setUint16(28, 32, true);
  v.setUint32(30, 0, true);
  v.setUint32(34, width * height * 4, true);
  for (let i = 0; i < width * height; i++) {
    out[54 + 4 * i] = data[4 * i + 2];
    out[55 + 4 * i] = data[4 * i + 1];
    out[56 + 4 * i] = data[4 * i];
    out[57 + 4 * i] = data[4 * i + 3];
  }
  return out;
}

await section('dither.js: wallpaper bytes', () => {
  const corners = image(400, 300, (x, y) => ((x === 0 && y === 0) || (x === 399 && y === 299) ? BLACK : WHITE));
  for (const d of [true, false]) {
    const b = dither.rgbaToWallpaper(corners, { dither: d });
    check(`corner pixels are the first and the last bit, 1 = black (${d ? 'dithering' : 'threshold'})`,
      b.length === 15000 && b[0] === 0x80 && b[14999] === 0x01 && b.subarray(1, 14999).every((v) => v === 0));
  }
  const wide = dither.rgbaToWallpaper(image(800, 300, (x) => (x < 400 ? BLACK : WHITE)), { fit: 'cover', dither: false });
  check('cover crops the middle of a wide image', everyRow(wide, [...Array(25).fill(0xff), ...Array(25).fill(0)]));
  const narrow = dither.rgbaToWallpaper(image(200, 300, () => BLACK), { fit: 'contain', dither: false });
  check('contain centres a narrow image on white',
    everyRow(narrow, [...Array(12).fill(0), 0x0f, ...Array(24).fill(0xff), 0xf0, ...Array(12).fill(0)]));
  const clear = image(320, 240, () => [0, 0, 0, 0]);
  check('a transparent image is white with any method and brightness',
    [true, false].every((d) => dither.rgbaToWallpaper(clear, { dither: d, brightness: -50 }).every((v) => v === 0)));
  check('threshold: grey 127 is black', dither.rgbaToWallpaper(flat(127), { dither: false }).every((v) => v === 0xff));
  check('threshold: grey 128 is white', dither.rgbaToWallpaper(flat(128), { dither: false }).every((v) => v === 0));
  const bits = dither.ditherFS(new Uint8Array(400 * 300).fill(128));
  const share = bits.reduce((a, b) => a + b, 0) / bits.length;
  check('dithering grey 128 makes about half of the pixels black', Math.abs(share - 127 / 255) < 0.02, `share ${share}`);

  check('brightness -1 turns grey 128 black',
    dither.rgbaToWallpaper(flat(128), { dither: false, brightness: -1 }).every((v) => v === 0xff));
  check('brightness +10 turns grey 120 white',
    dither.rgbaToWallpaper(flat(120), { dither: false, brightness: 10 }).every((v) => v === 0));
  same('brightness in grey steps: 50 % is 128', [50, -50, 999, NaN, 1, 10, 0].map((b) => dither.brightnessShift(b)),
    [128, -128, 128, 0, 3, 26, 0]);
  check('brightness comes before alpha, so a half-transparent black at +50 is 191',
    dither.toCanvas(image(400, 300, () => [0, 0, 0, 128]), { brightness: 50 }).every((v) => v === 191));
  check('halving the size averages 2x2 blocks',
    dither.toCanvas(image(800, 600, (x, y) => ((x + y) & 1 ? WHITE : BLACK))).every((v) => v === 128));
  same('grey of pure red, green and blue', [dither.luma(255, 0, 0), dither.luma(0, 255, 0), dither.luma(0, 0, 255)], [76, 150, 29]);

  const size = (w, h, fit) => {
    const L = dither.layout(w, h, fit);
    return `${L.nw}x${L.nh}`;
  };
  same('layout rounds halves to even like Python',
    [size(160, 1, 'contain'), size(11, 8, 'cover'), size(800, 5, 'contain'), size(800, 7, 'contain')],
    ['400x2', '412x300', '400x2', '400x4']);
  same('pyRound', [2.5, 3.5, -0.5, -1.5, 0.49].map((x) => dither.pyRound(x)), [2, 4, 0, -2, 0]);
  same('wallpaper size matches api.php', dither.BYTES, Number(/const WALLPAPER_BYTES = (\d+);/.exec(apiSrc)?.[1]));

  const b = new Uint8Array(15000);
  b[0] = 0x80;
  b[14999] = 0x01;
  const px = dither.wallpaperToRgba(b);
  same('wallpaper preview pixels: black, white ... white, black', [...px.subarray(0, 8), ...px.subarray(px.length - 8)],
    [0, 0, 0, 255, 255, 255, 255, 255, 255, 255, 255, 255, 0, 0, 0, 255]);
  let refused = false;
  try {
    dither.wallpaperToRgba(new Uint8Array(14999));
  } catch (e) {
    refused = e instanceof RangeError;
  }
  check('a wallpaper of 14999 bytes is refused', refused);
  const noise = Uint8Array.from({ length: 15000 }, (_, i) => (Math.imul(i + 1, 2654435761) >>> 13) & 255);
  check('a wallpaper read back and converted again stays the same',
    sameBytes(dither.rgbaToWallpaper({ width: 400, height: 300, data: dither.wallpaperToRgba(noise) }), noise));
});

await section('dither.js: the same result as tools/img2rlcd.py', () => {
  const SIZES = [[4032, 3024, 'cover'], [3024, 4032, 'cover'], [4032, 3024, 'contain'], [3024, 4032, 'contain'],
    [1920, 1080, 'cover'], [1920, 1080, 'contain'], [1, 1, 'cover'], [1, 1, 'contain'], [160, 1, 'contain'],
    [11, 8, 'cover'], [800, 5, 'contain'], [800, 7, 'contain'], [401, 301, 'contain'], [399, 299, 'cover'],
    [1000, 1, 'cover'], [1, 1000, 'contain'], [333, 250, 'cover']];
  const fromPy = JSON.parse(python('print(json.dumps([m.scaled_size(w, h, fit) for w, h, fit in json.load(sys.stdin)]))',
    JSON.stringify(SIZES)).toString());
  sameEach('scaled size like scaled_size()', SIZES, SIZES.map(([w, h, fit]) => {
    const L = dither.layout(w, h, fit);
    return [L.nw, L.nh];
  }), fromPy);

  // one canvas with a gradient, noise and the 127/128 edge
  const canvas = new Uint8Array(400 * 300);
  let seed = 12345;
  for (let i = 0; i < canvas.length; i++) {
    const x = i % 400;
    const y = Math.floor(i / 400);
    if (y < 100) {
      canvas[i] = Math.round((x * 255) / 399);
    } else if (y < 200) {
      seed = (Math.imul(seed, 1103515245) + 12345) >>> 0;
      canvas[i] = seed >>> 24;
    } else {
      canvas[i] = x < 200 ? 127 : 128;
    }
  }
  const py = python('g = list(sys.stdin.buffer.read())\n'
    + 'sys.stdout.buffer.write(m.pack(m.floyd_steinberg(g)) + m.pack(m.threshold(g)))', canvas);
  check('Floyd-Steinberg gives the bytes of floyd_steinberg() + pack()',
    py.length === 30000 && sameBytes(dither.packBits(dither.ditherFS(canvas)), py.subarray(0, 15000)));
  check('threshold gives the bytes of threshold() + pack()',
    py.length === 30000 && sameBytes(dither.packBits(dither.thresholdBits(canvas)), py.subarray(15000)));

  // At scale 1 nothing is resized (img2rlcd.py resizes with sips, the app with a box filter), so grey,
  // alpha over white, cropping and centring must match read_bmp() + place() byte for byte.
  for (const [w, h, fit] of [[400, 300, 'cover'], [800, 300, 'cover'], [400, 500, 'cover'], [200, 300, 'contain'],
    [400, 100, 'contain']]) {
    const img = image(w, h, (x, y) => [(x * 3 + y) & 255, (x + y * 5) & 255, (x * y) & 255, 1 + ((x * 7 + y * 13) % 255)]);
    const fromBmp = python('w, h, g = m.read_bmp(sys.stdin.buffer.read())\nsys.stdout.buffer.write(bytes(m.place(w, h, g)))',
      bmp32(img));
    check(`grey canvas like read_bmp() + place(): ${w}x${h}, ${fit}, with alpha`,
      sameBytes(dither.toCanvas(img, { fit }), fromBmp));
  }
});

// ---------- api.js ----------

const TOKEN = 'test-token-' + 'x'.repeat(32);
const TEST_LINK = 'https://www.myinstants.com/instant/test-only/'; // goes to the fake fetch only

const jsonResponse = (body, status = 200, headers = {}) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });

function fakeFetch(answer) {
  const calls = [];
  return {
    calls,
    fn: async (url, init) => {
      calls.push({ url, init });
      return answer(url, init);
    },
  };
}

await section('api.js: requests', async () => {
  const f = fakeFetch((url, init) => {
    const action = new URLSearchParams(url.split('?')[1]).get('a');
    if (action === 'me') return jsonResponse({ ok: true, name: 'Ja' });
    if (action === 'state') return jsonResponse({ rev: 3, sessions: [] }, 200, { Date: 'Fri, 06 Nov 2026 12:00:00 GMT' });
    if (init.method === 'GET') return new Response(Uint8Array.of(1, 2, 3));
    return jsonResponse({ ok: true, rev: 4 });
  });
  const online = [];
  const client = api.createApi({ token: TOKEN, fetch: f.fn, onConnection: (up) => online.push(up) });
  const last = () => f.calls[f.calls.length - 1];

  same('me() returns the name', await client.me(), 'Ja');
  const me = f.calls[0];
  check('the token goes in X-Token and never in the address',
    me.url === 'api.php?a=me' && me.init.headers['X-Token'] === TOKEN && !me.url.includes(TOKEN));
  check('requests skip the HTTP cache and stay on this site',
    me.init.method === 'GET' && me.init.cache === 'no-store' && me.init.credentials === 'same-origin');
  same('a server that answered counts as online', online, [true]);

  await client.sendMessage('Hej', 'chime');
  check('message: POST with a JSON body', last().url === 'api.php?a=message' && last().init.method === 'POST'
    && last().init.headers['Content-Type'] === 'application/json' && last().init.body === '{"text":"Hej","sound":"chime"}');
  await client.setView('wallpaper');
  check('view: POST {"view": ...}', last().url === 'api.php?a=view' && last().init.body === '{"view":"wallpaper"}');
  await client.setSettings({ lang: 'pl' });
  check('settings: POST only the field that changed', last().url === 'api.php?a=settings' && last().init.method === 'POST'
    && last().init.body === '{"lang":"pl"}');
  await client.setSettings({ lang: 'en', poll_s: 45 });
  check('settings: both fields, the interval as a number', last().init.body === '{"lang":"en","poll_s":45}');
  await client.setSettings({ poll_s: null });
  check('settings: back to the default interval is poll_s null, not a missing field', last().init.body === '{"poll_s":null}');
  const typed = [['45', 45], [' 45 ', 45], ['5', 5], ['3600', 3600], ['0090', 90], ['4', null], ['0', null],
    ['3601', null], ['10000', null], ['', null], ['   ', null], ['4.5', null], ['45.0', null], ['1e3', null],
    ['-10', null], ['+10', null], ['45 s', null], ['4 5', null], ['0x10', null], [cp(0x664) + cp(0x665), null],
    [cp(0xff14) + cp(0xff15), null], [45, null], [null, null], [undefined, null]];
  same('pollSeconds(): only whole seconds from POLL_MIN_S to POLL_MAX_S in ASCII digits',
    typed.map(([v]) => api.pollSeconds(v)), typed.map(([, want]) => want));
  same('pollSeconds() bounds', [api.POLL_MIN_S, api.POLL_MAX_S], [5, 3600]);
  const settings = { mode: 'ready', scope: 'sessions', done: { sound: 's7', anim: 'happy' },
    wait: { sound: 'chime', anim: 'away' } };
  await client.setNotify({ ...settings, rev: 9, ev: { all: 2 }, done: { ...settings.done, extra: 1 } });
  check('notify: POST with the whole form and nothing else', last().url === 'api.php?a=notify'
    && last().init.method === 'POST' && last().init.headers['Content-Type'] === 'application/json'
    && last().init.body === JSON.stringify(settings), last().init.body);
  same('notifyBody() makes nothing up: a missing field stays missing, so the server names it',
    api.notifyBody({ mode: 'each', done: { anim: 'alert' } }), { mode: 'each', done: { anim: 'alert' }, wait: {} });
  same('notifyBody() of nothing is empty', [api.notifyBody(null), api.notifyBody(undefined)], [{ done: {}, wait: {} },
    { done: {}, wait: {} }]);
  await client.deleteSound('s12');
  check('sound_delete: POST {"id": ...}', last().url === 'api.php?a=sound_delete' && last().init.body === '{"id":"s12"}');
  const sound = wav.encodeWav(new Float32Array(160));
  await client.postSound('Dzwonek do drzwi', sound);
  check('sound upload: the name in the address, the WAV as the body',
    last().url === 'api.php?a=sound&name=Dzwonek+do+drzwi' && last().init.body === sound
      && last().init.headers['Content-Type'] === 'audio/wav');
  const picture = new Uint8Array(15000);
  await client.postWallpaper(picture);
  check('wallpaper upload: the raw 15000 bytes', last().url === 'api.php?a=wallpaper' && last().init.body === picture
    && last().init.headers['Content-Type'] === 'application/octet-stream');
  same('getSound() returns the bytes', [...new Uint8Array(await client.getSound('s3'))], [1, 2, 3]);
  check('getSound() asks for the id', last().url === 'api.php?a=sound&id=s3');
  await client.soundSource(TEST_LINK);
  const source = new URLSearchParams(last().url.split('?')[1]);
  check('sound_source carries the link as a query parameter', source.get('a') === 'sound_source' && source.get('url') === TEST_LINK);
  same('getWallpaper() returns the bytes', [...(await client.getWallpaper())], [1, 2, 3]);

  const st = await client.state();
  check('state() returns the data and the server clock from Date',
    st.state.rev === 3 && st.serverTime === Date.UTC(2026, 10, 6, 12, 0, 0));
  const noDate = await api.createApi({ token: TOKEN, fetch: async () => jsonResponse({ rev: 1 }) }).state();
  same('without a Date header the server clock is unknown', noDate.serverTime, null);
});

await section('api.js: errors in the app language', async () => {
  const answering = (respond, extra = {}) => api.createApi({ token: TOKEN, fetch: async () => respond(), ...extra });
  const WANT = { // whole sentences, so a broken {placeholder} or a lost half of a sentence shows up
    en: {
      raw: 'Server: write failed (500)',
      refused: 'myinstants refused (HTTP 429). Download the file yourself and use ' + cp(0x201c) + 'Choose file'
        + cp(0x201d) + '.',
      page: 'The server did not answer properly (HTTP 503).',
      broken: 'The server did not answer properly (HTTP 200).',
    },
    pl: {
      raw: 'Serwer: write failed (500)',
      refused: 'myinstants odmówiło (HTTP 429). Pobierz plik sam i użyj ' + cp(0x201e) + 'Wybierz plik'
        + cp(0x201d) + '.',
      page: 'Serwer nie odpowiedział poprawnie (HTTP 503).',
      broken: 'Serwer nie odpowiedział poprawnie (HTTP 200).',
    },
  };
  same('the expected sentences cover every app language', Object.keys(WANT), i18n.LANGS);

  await inEachLang(async (code) => {
    const want = WANT[code] ?? {};
    let e = await failure(answering(() => jsonResponse({ ok: false, error: 'write failed' }, 500)).me());
    check(`${code}: an untranslated server message is quoted with the HTTP code`, e instanceof api.ApiError
      && e.message === want.raw && e.status === 500 && e.serverMessage === 'write failed', String(e && e.message));
    e = await failure(answering(() => jsonResponse({ ok: false,
      error: 'myinstants refused the request (HTTP 429): download the sound and upload the file instead' }, 502))
      .soundSource(TEST_LINK));
    same(`${code}: a myinstants refusal says what to do instead`, e && e.message, want.refused);
    e = await failure(answering(() => new Response('<html><body>Service Unavailable</body></html>',
      { status: 503, headers: { 'Content-Type': 'text/html' } })).me());
    same(`${code}: a hosting error page`, e && e.message, want.page);
    e = await failure(answering(() => new Response('not json')).me());
    same(`${code}: a broken answer`, e && e.message, want.broken);

    let unauthorized = 0;
    e = await failure(answering(() => jsonResponse({ ok: false, error: 'bad token' }, 401),
      { onUnauthorized: () => unauthorized++ }).state());
    check(`${code}: 401 calls onUnauthorized once and says the token is wrong`, unauthorized === 1
      && e instanceof api.ApiError && e.status === 401 && e.message === i18n.DICT[code]['api.badToken'],
    String(e && e.message));
    const online = [];
    e = await failure(api.createApi({ token: TOKEN, fetch: async () => { throw new TypeError('Failed to fetch'); },
      onConnection: (up) => online.push(up) }).me());
    check(`${code}: no network: an offline error and onConnection(false)`, e instanceof api.ApiError
      && e.offline === true && e.message === i18n.DICT[code].offline && JSON.stringify(online) === '[false]',
    String(e && e.message));
  });
  same('no wallpaper yet gives null',
    await answering(() => jsonResponse({ ok: false, error: 'no wallpaper' }, 404)).getWallpaper(), null);
  const e = await failure(answering(() => jsonResponse({ ok: false, error: 'unknown action' }, 404)).getWallpaper());
  check('any other 404 is still an error', e instanceof api.ApiError && e.status === 404);

  // every message api.php and sounds.php can send has a sentence in every app language
  const phpSrc = apiSrc + soundsSrc;
  const fixed = new Set();
  for (const m of phpSrc.matchAll(/fail\(\d+, '([^']+)'\)/g)) fixed.add(m[1]);
  for (const m of phpSrc.matchAll(/'error' => '([^']+)'/g)) fixed.add(m[1]);
  fixed.delete('write failed'); // quoted as it is on purpose, see api.js
  const BUILT = [ // [the template in the PHP source, the message it makes]
    ["'text must have 1-' . MESSAGE_MAX_CHARS", 'text must have 1-280 chars'],
    ["'sound must be a built-in tone ('", 'sound must be a built-in tone (none, chime, bell, dinner, alert) or an id from the sound library'],
    ["'wallpaper must be exactly ' . WALLPAPER_BYTES", 'wallpaper must be exactly 15000 bytes'],
    ["'view must be one of: '", 'view must be one of: dashboard, message, wallpaper, sessions'],
    ["'lang must be one of: '", 'lang must be one of: en, pl'],
    ["'poll_s must be null (default) or whole seconds from '", 'poll_s must be null (default) or whole seconds from 5 to 3600'],
    ["'state must be one of: '", 'state must be one of: working, waiting, done, end'],
    ["'name must have 1-' . SOUND_NAME_MAX", 'name must have 1-24 chars'],
    ["'file larger than ' . SOUND_UPLOAD_MAX", 'file larger than 600000 bytes'],
    ["'sound library is full (' . SOUND_LIBRARY_MAX", 'sound library is full (50): delete a sound first'],
    ['"myinstants file is larger than $maxBytes bytes"', 'myinstants file is larger than 600000 bytes'],
    ['"myinstants did not answer: $error"', 'myinstants did not answer: Could not resolve host: www.myinstants.com'],
    ['"myinstants refused the request (HTTP $status): download the sound and upload the file instead"',
      'myinstants refused the request (HTTP 403): download the sound and upload the file instead'],
    ['"myinstants answered HTTP $status"', 'myinstants answered HTTP 500'],
    ['"token \'$role\' missing or shorter than 24 chars"', "token 'device' missing or shorter than 24 chars"],
    ["'WAV must be PCM 16-bit mono 16000 Hz, got format %d",
      'WAV must be PCM 16-bit mono 16000 Hz, got format 1, 2 channel(s), 44100 Hz, 16-bit'],
    ["'mode must be one of: '", 'mode must be one of: quiet, ready, each'],
    ["'scope must be one of: '", 'scope must be one of: sessions, all'],
    ['"$kind must be an object"', 'wait must be an object'],
    ['"$kind.sound must be a built-in tone ("',
      'done.sound must be a built-in tone (none, chime, bell, dinner, alert) or an id from the sound library'],
    ['"$kind.anim must be one of: "', 'wait.anim must be one of: away, alert, happy, typing, idle, sleep'],
  ];
  const gone = BUILT.filter(([template]) => !phpSrc.includes(template)).map(([template]) => template);
  check('the built messages still exist in the PHP source', gone.length === 0, gone.join(' | '));
  const messages = [...fixed, ...BUILT.map(([, msg]) => msg)];
  await inEachLang((code) => {
    // a fallback quotes the server, a missing key comes back as the key, a missing parameter as {name}
    const untranslated = messages.filter((msg) => {
      const out = api.translateError(msg, 400);
      return out === i18n.t('api.raw', { msg, status: 400 }) || out === i18n.t('api.badAnswer', { status: 400 })
        || /\{\w+\}/.test(out) || /^[a-z]+(?:\.[A-Za-z0-9]+)+$/.test(out);
    });
    check(`${code}: every server message has a sentence (${fixed.size} fixed, ${BUILT.length} built)`,
      fixed.size >= 25 && untranslated.length === 0, untranslated.join(' | '));
    const poll = api.translateError('poll_s must be null (default) or whole seconds from 7 to 1800', 400);
    check(`${code}: the interval error names the bounds the server sent, not the app's`,
      poll === i18n.t('api.poll', { min: 7, max: 1800 }) && poll.includes('1800'), poll);
  });
});

// ---------- anim.js ----------

await section('anim.js: the board animations and the notification settings', () => {
  same('the same animations as api.php accepts', anim.ANIMS.map((a) => a.id), phpList('NOTIFY_ANIMS'));
  const html = read('index.html');
  const radios = (name) => [...html.matchAll(new RegExp(`name="${name}" value="([^"]+)"`, 'g'))].map((m) => m[1]);
  same('the form offers exactly the modes api.php accepts', radios('notify-mode'), phpList('NOTIFY_MODES'));
  same('the form offers exactly the scopes api.php accepts', radios('notify-scope'), phpList('NOTIFY_SCOPES'));

  same('a frame is 64 x 64 pixels, 8 bytes a row, 512 bytes', [anim.SIZE, anim.ROW, anim.BYTES], [64, 8, 512]);
  const broken = anim.ANIMS.filter((a) => !(typeof a.name === 'string' && a.name.trim() !== ''
    && a.frames.length > 0 && a.frames.length === a.ms.length
    && a.frames.every((f) => f instanceof Uint8Array && f.length === anim.BYTES)
    && a.ms.every((ms) => Number.isInteger(ms) && ms >= 1 && ms <= 65535)
    && Number.isInteger(a.key) && a.key >= 0 && a.key < a.frames.length)).map((a) => a.id);
  check('every animation has a name, 512-byte frames, one time per frame and a key frame', broken.length === 0,
    broken.join(', '));
  check('no animation is blank on its key frame', anim.ANIMS.every((a) => a.frames[a.key].some((b) => b !== 0)));
  check('animById finds each animation and nothing else',
    anim.ANIMS.every((a) => anim.animById(a.id) === a) && anim.animById('nope') === null && anim.animById('') === null);

  const corner = [[], ['done'], ['done', 'working'], ['working', 'waiting', 'done'], Array(6).fill('done'),
    Array(anim.CORNER_MAX_ROWS + 1).fill('working')];
  sameEach('the corner animation follows the session list, none while a session works or the list fills the screen',
    corner, corner.map((states) => anim.cornerAnim(states.map((state, i) => ({ id: `s${i}`, state })))),
    ['sleep', 'idle', null, 'alert', 'idle', null]);

  const px = anim.frameToRgba(anim.ANIMS[0].frames[0]);
  let onlyInk = px instanceof Uint8ClampedArray && px.length === anim.SIZE * anim.SIZE * 4;
  for (let i = 0; onlyInk && i < px.length; i += 4) {
    onlyInk = px[i] === px[i + 1] && px[i] === px[i + 2] && (px[i] === 0 || px[i] === 255) && px[i + 3] === 255;
  }
  check('frameToRgba gives 64 x 64 opaque pixels, each black or white', onlyInk);
  const ends = new Uint8Array(anim.BYTES);
  ends[0] = 0x80;
  ends[anim.BYTES - 1] = 0x01;
  const e = anim.frameToRgba(ends);
  same('a set bit is black, the high bit is the left pixel, as on the board', [e[0], e[4], e[16380], e[16376]],
    [0, 255, 0, 255]);

  // A 3 x 3 ring at (1..3, 1..3) with a hole in the middle, and a bit touching the edge at (0, 63).
  const ring = new Uint8Array(anim.BYTES);
  const put = (x, y) => { const i = y * anim.SIZE + x; ring[i >> 3] |= 0x80 >> (i & 7); };
  for (const [x, y] of [[1, 1], [2, 1], [3, 1], [1, 2], [3, 2], [1, 3], [2, 3], [3, 3], [0, 63]]) put(x, y);
  const cut = anim.frameToCutout(ring, [193, 95, 60], [13, 23, 27]);
  const pxAt = (x, y) => [...cut.slice((y * anim.SIZE + x) * 4, (y * anim.SIZE + x) * 4 + 4)];
  sameEach('the Dashboard cut-out: ink in orange, an enclosed hole in the eye colour, the rest transparent',
    ['ink', 'hole', 'corner', 'next to the ring', 'ink on the edge'],
    [pxAt(1, 1), pxAt(2, 2), pxAt(0, 0), pxAt(4, 2), pxAt(0, 63)],
    [[193, 95, 60, 255], [13, 23, 27, 255], [0, 0, 0, 0], [0, 0, 0, 0], [193, 95, 60, 255]]);
  const clawd = anim.frameToCutout(anim.animById('idle').frames[0], [1, 2, 3], [4, 5, 6]);
  let eyes = 0;
  for (let i = 0; i < clawd.length; i += 4) if (clawd[i] === 4 && clawd[i + 3] === 255) eyes++;
  check('Clawd keeps its eyes in the cut-out (white enclosed by the body)', eyes > 0, `${eyes} eye pixels`);

  const gen = spawnSync(process.execPath, [join(ROOT, 'tools', 'anim2c.mjs'), '--check'], { encoding: 'utf8' });
  check('the board firmware has the same frames (node tools/anim2c.mjs --check)', gen.status === 0,
    (gen.stdout + gen.stderr).trim());
});

// ---------- i18n.js ----------

await section('dash.js: limits and session facts the Dashboard shows', () => {
  const r = (extra = {}) => ({ p: 63.4, left: 4800, age: 120, expired: false, ...extra });
  same('no reading: none', dash.limitBar(undefined), { kind: 'none' });
  same('a damaged reading: none', [dash.limitBar({ p: 'x', left: 1, age: 1 }), dash.limitBar({ p: 101, left: 1, age: 1 }), dash.limitBar({ p: 5, left: -1, age: 1 }), dash.limitBar(7)],
    [{ kind: 'none' }, { kind: 'none' }, { kind: 'none' }, { kind: 'none' }]);
  same('a reading: rounded percent, time left and age', dash.limitBar(r()), { kind: 'bar', pct: 63, level: 'ok', left: 4800, age: 120 });
  same('time counts on after the fetch', dash.limitBar(r(), 60), { kind: 'bar', pct: 63, level: 'ok', left: 4740, age: 180 });
  same('80 % warns, 100 % is full', [dash.limitBar(r({ p: 79.4 })).level, dash.limitBar(r({ p: 80 })).level, dash.limitBar(r({ p: 100 })).level], ['ok', 'warn', 'full']);
  same('past the reset: renewed, no percent', dash.limitBar(r({ left: 30 }), 31), { kind: 'renewed', age: 151 });
  same('the server saying expired: renewed', dash.limitBar(r({ expired: true, left: 0 })), { kind: 'renewed', age: 120 });
  same('span words', [dash.span(30), dash.span(60), dash.span(4800), dash.span(3600), dash.span(2 * 86400 + 5 * 3600 + 59)],
    [[], ['min', 1], ['h', 1, 'min', 20], ['h', 1, 'min', 0], ['d', 2, 'h', 5]]);
  same('task progress: done of total only', [dash.taskProgress({ tasks: { d: 2, t: 5 } }), dash.taskProgress({}), dash.taskProgress({ tasks: { d: 0, t: 0 } }), dash.taskProgress({ tasks: { d: 3, t: 2 } }), dash.taskProgress({ tasks: { d: 1.5, t: 2 } })],
    [{ d: 2, t: 5, pct: 40 }, null, null, null, null]);
  same('facts: only what was sent, in order', dash.sessionFacts({ state: 'working', turns: 3, turn_age: 100, agents: 2, ctx: 41, model: 'Opus 5.5' }, 20),
    [{ id: 'turn', n: 3 }, { id: 'turnTime', seconds: 120 }, { id: 'agents', n: 2 }, { id: 'ctx', n: 41 }, { id: 'model', text: 'Opus 5.5' }]);
  same('facts: nothing sent, nothing shown; no turn clock when done; markup in a model is dropped',
    [dash.sessionFacts({ state: 'working' }), dash.sessionFacts({ state: 'done', turns: 1, turn_age: 5 }), dash.sessionFacts({ state: 'done', model: '<b>x</b>' })],
    [[], [{ id: 'turn', n: 1 }], []]);
  same('wait kind: only a known kind of a waiting row', [dash.waitKind({ state: 'waiting', kind: 'plan' }), dash.waitKind({ state: 'working', kind: 'plan' }), dash.waitKind({ state: 'waiting', kind: 'x' })], ['plan', null, null]);
  const row = (state, age = 5) => ({ state, age });
  same('Clawd: waiting beats working beats happy', [anim.dashboardAnim([row('working'), row('waiting')]), anim.dashboardAnim([row('done'), row('working')]), anim.dashboardAnim([row('done', 10)])],
    ['alert', 'typing', 'happy']);
  same('Clawd: happy ends on its own, then idle; no sessions sleep', [anim.dashboardAnim([row('done', 80)], 5), anim.dashboardAnim([row('done', 80)], 11), anim.dashboardAnim([])], ['happy', 'idle', 'sleep']);
});

await section('i18n.js: two dictionaries, English by default', async () => {
  const DEF = i18n.DEFAULT_LANG;
  same('the app starts in English: the default, this page load and a device that chose nothing',
    [DEF, LANG_AT_START, i18n.loadLang(fakeStorage())], ['en', 'en', 'en']);
  same('the language codes of the board setting (en, pl), English first', i18n.LANGS, ['en', 'pl']);
  const board = phpList('LANGS');
  check('the board languages in api.php, once there, are the same codes', board === null
    || JSON.stringify(board) === JSON.stringify(i18n.LANGS), JSON.stringify(board));
  same('every language has a dictionary, a locale and a name',
    [Object.keys(i18n.DICT), Object.keys(i18n.LOCALES), Object.keys(i18n.LANG_NAMES)], [i18n.LANGS, i18n.LANGS, i18n.LANGS]);

  const base = Object.keys(i18n.DICT[DEF]);
  for (const code of i18n.LANGS.filter((c) => c !== DEF)) {
    const missing = base.filter((k) => !Object.hasOwn(i18n.DICT[code], k));
    const extra = Object.keys(i18n.DICT[code]).filter((k) => !Object.hasOwn(i18n.DICT[DEF], k));
    check(`${code} has exactly the keys of ${DEF} (${base.length} keys)`, base.length > 100 && missing.length === 0
      && extra.length === 0, `missing in ${code}: ${missing.join(', ')}; only in ${code}: ${extra.join(', ')}`);
  }
  const empty = i18n.LANGS.flatMap((code) => Object.entries(i18n.DICT[code])
    .filter(([, v]) => typeof v !== 'string' || v.trim() === '').map(([k]) => `${code}: ${k}`));
  check(`no value is empty (${i18n.LANGS.join(', ')})`, empty.length === 0, empty.join(', '));
  const holes = (s) => JSON.stringify([...new Set(String(s).match(/\{\w+\}/g) ?? [])].sort());
  const mismatched = i18n.LANGS.flatMap((code) => base
    .filter((k) => holes(i18n.DICT[code][k]) !== holes(i18n.DICT[DEF][k])).map((k) => `${code}: ${k}`));
  check('every sentence has the same {placeholders} in every language', mismatched.length === 0, mismatched.join(', '));

  const PLURAL = /\.(?:zero|one|two|few|many|other)$/;
  const plurals = [...new Set(base.filter((k) => PLURAL.test(k)).map((k) => k.replace(PLURAL, '')))];
  const lacking = i18n.LANGS.flatMap((code) => {
    const forms = new Intl.PluralRules(i18n.LOCALES[code]).resolvedOptions().pluralCategories;
    return plurals.flatMap((b) => forms.filter((f) => !Object.hasOwn(i18n.DICT[code], `${b}.${f}`))
      .map((f) => `${code}: ${b}.${f}`));
  });
  check(`every plural has the forms its language needs (${plurals.join(', ')})`, plurals.length > 0
    && lacking.length === 0, lacking.join(', '));
  const FORMS = { en: [[1, 'one'], [2, 'other'], [281, 'other']],
    pl: [[1, 'one'], [2, 'few'], [5, 'many'], [12, 'many'], [22, 'few'], [281, 'many'], [282, 'few']] };
  await inEachLang((code) => {
    const cases = FORMS[code] ?? [];
    sameEach(`${code}: tn picks the plural form and fills in the numbers`, cases.map(([n]) => n),
      cases.map(([n]) => i18n.tn('send.tooLong', n, { max: 280 })),
      cases.map(([n, form]) => i18n.DICT[code][`send.tooLong.${form}`].replaceAll('{n}', String(n))
        .replaceAll('{max}', '280')));
  });
  i18n.setLang('pl');
  const polish = [1, 2, 5].map((n) => i18n.tn('send.tooLong', n, { max: 280 }));
  i18n.setLang(DEF);
  check('pl: 1 znak, 2 znaki, 5 znaków', polish[0].includes('1 znak,') && polish[1].includes('2 znaki,')
    && polish[2].includes('5 znaków,'), polish.join(' | '));

  same('t: an unknown key comes back as it is, a missing parameter stays visible, $ in a value is kept',
    [i18n.t('no.such.key'), i18n.t('api.raw', { msg: 'x' }), i18n.t('api.raw', { msg: '$& $1', status: 0 })],
    ['no.such.key', 'Server: x ({status})', 'Server: $& $1 (0)']);
  const switched = [i18n.setLang('pl'), i18n.lang(), i18n.locale(), i18n.t('app.name'),
    i18n.setLang('de'), i18n.lang(), i18n.locale(), i18n.t('app.name'), i18n.setLang(undefined)];
  i18n.setLang(DEF);
  same('setLang switches the dictionary and the locale; an unknown code means English', switched,
    ['pl', 'pl', 'pl-PL', i18n.DICT.pl['app.name'], 'en', 'en', 'en-GB', i18n.DICT.en['app.name'], 'en']);

  const KEY = i18n.LANG_KEY;
  const STORED = [['nothing saved', fakeStorage()], ['pl', fakeStorage({ [KEY]: 'pl' })],
    ['en', fakeStorage({ [KEY]: 'en' })], ['de', fakeStorage({ [KEY]: 'de' })], ['empty', fakeStorage({ [KEY]: '' })],
    ['PL', fakeStorage({ [KEY]: 'PL' })], ['unreadable', fakeStorage({ [KEY]: 'pl' }, { readFails: true })],
    ['no storage', null]];
  sameEach('loadLang reads the choice of this device, anything else means English', STORED.map(([name]) => name),
    STORED.map(([, s]) => i18n.loadLang(s)), ['en', 'pl', 'en', 'en', 'en', 'en', 'en', 'en']);
  const kept = fakeStorage();
  const untouched = fakeStorage();
  same('saveLang keeps a known code and says when the browser did not keep it', [
    i18n.saveLang('pl', kept), kept.data.get(KEY),
    i18n.saveLang('de', untouched), untouched.data.has(KEY),
    i18n.saveLang('pl', fakeStorage({}, { writeFails: true })),
    i18n.saveLang('pl', fakeStorage({}, { dropsWrites: true })),
    i18n.saveLang('pl', null),
  ], [true, 'pl', false, false, false, false, false]);
  same('after the reload the saved choice is read back', i18n.loadLang(kept), 'pl');

  same('each language is named in its own words, the same in every dictionary',
    i18n.LANGS.flatMap((x) => i18n.LANGS.map((c) => i18n.DICT[x][`lang.${c}`])),
    i18n.LANGS.flatMap(() => i18n.LANGS.map((c) => i18n.LANG_NAMES[c])));
  same('pl: the animation names are the firmware names from anim.js',
    anim.ANIMS.map((a) => i18n.DICT.pl[`anim.${a.id}`]), anim.ANIMS.map((a) => a.name));

  const list = (name, src) => phpList(name, src) ?? [`(${name} not found)`];
  const built = [ // the keys app.js puts together from these lists
    ...list('SOUND_BUILTIN', soundsSrc).flatMap((id) => [`tone.${id}`, `tone.${id}.desc`]),
    ...list('VIEWS').map((id) => `view.${id}`),
    ...list('MODES').map((id) => `mode.${id}`),
    ...list('SESSION_STATES').filter((s) => s !== 'end').map((id) => `session.${id}`), // end removes the row
    ...anim.ANIMS.map((a) => `anim.${a.id}`),
  ];
  const notBuilt = built.filter((k) => !Object.hasOwn(i18n.DICT[DEF], k));
  check(`the keys built from the server lists exist (${built.length})`, notBuilt.length === 0, notBuilt.join(', '));

  // every key written out in the code: t('…'), tn('…') and the key tables of api.js
  const namespaces = new Set(base.map((k) => k.split('.')[0]));
  const FILE = /\.(?:js|mjs|php|css|html|json|webmanifest|png|svg|wav|mp3)$/;
  const knownKey = (k) => Object.hasOwn(i18n.DICT[DEF], k) || Object.hasOwn(i18n.DICT[DEF], `${k}.other`);
  const unknown = [];
  let written = 0;
  for (const f of ['app.js', 'lib/api.js', 'lib/wav.js']) {
    const src = read(f);
    const keys = [...src.matchAll(/\btn?\(\s*'([^']+)'/g)].map((m) => m[1]);
    for (const m of src.matchAll(/'([a-z]+(?:\.[A-Za-z0-9]+)+)'/g)) {
      if (namespaces.has(m[1].split('.')[0]) && !FILE.test(m[1])) keys.push(m[1]);
    }
    written += keys.length;
    unknown.push(...new Set(keys.filter((k) => !knownKey(k)).map((k) => `${f}: ${k}`)));
  }
  check(`every key the code writes out is in the dictionary (${written} uses)`, written > 100 && unknown.length === 0,
    unknown.join(', '));
});

await section('i18n.js: the page and the manifests', () => {
  const html = read('index.html');
  const DEF = i18n.DEFAULT_LANG;
  const attrs = [...html.matchAll(/\sdata-i18n(-[a-z-]+)?="([^"]*)"/g)];
  const odd = [...new Set(attrs.map((m) => m[1] ?? '').filter((s) => !['', '-aria-label', '-placeholder', '-title'].includes(s)))];
  check('index.html uses only the data-i18n attributes app.js fills in', odd.length === 0, odd.join(', '));
  const unknown = [...new Set(attrs.map((m) => m[2]).filter((k) => !i18n.LANGS.every((c) => Object.hasOwn(i18n.DICT[c], k))))];
  check(`every data-i18n key of index.html is in every dictionary (${attrs.length} uses)`, attrs.length > 50
    && unknown.length === 0, unknown.join(', '));
  const filled = [...html.matchAll(/<([a-z][a-z0-9-]*)\b[^>]*\sdata-i18n="([^"]*)"[^>]*>/g)]
    .filter((m) => !html.startsWith(`</${m[1]}>`, m.index + m[0].length)).map((m) => m[2]);
  check('every element with data-i18n is empty, so its text never replaces a child', filled.length === 0,
    filled.join(', '));

  const body = (/<body[^>]*>([\s\S]*)<\/body>/.exec(html)?.[1] ?? '')
    .replace(/<!--[\s\S]*?-->/g, '').replace(/<script\b[\s\S]*?<\/script>/gi, '');
  const words = body.replace(/<[^>]*>/g, ' ').replace(/&(?:[a-z]+|#\d+|#x[0-9a-f]+);/gi, ' ')
    .match(/\S*\p{L}\S*/gu) ?? [];
  check('the page body has no words of its own: every text comes from the dictionary', body.length > 0
    && words.length === 0, words.join(' '));
  const labels = [...body.matchAll(/\s(?:aria-label|placeholder|title|alt)="([^"]*)"/g)]
    .filter((m) => /\p{L}/u.test(m[1])).map((m) => m[0].trim());
  check('no aria-label, placeholder, title or alt with words of its own', labels.length === 0, labels.join(' '));
  const head = /<head>([\s\S]*?)<\/head>/.exec(html)?.[1] ?? '';
  const en = i18n.DICT[DEF];
  same('before app.js runs, the page is the English one', [
    /<html lang="([^"]+)"/.exec(html)?.[1],
    /<title>([^<]*)<\/title>/.exec(head)?.[1],
    /<meta name="description" content="([^"]*)"/.exec(head)?.[1],
    /<meta name="apple-mobile-web-app-title" content="([^"]*)"/.exec(head)?.[1],
    /<link rel="manifest" href="([^"]*)"/.exec(head)?.[1],
  ], [DEF, en['app.name'], en['app.description'], en['app.name'], 'manifest.webmanifest']);
  same('the language switch offers exactly the app languages',
    [...html.matchAll(/name="app-lang"[^>]*value="([^"]+)"/g)].map((m) => m[1]), i18n.LANGS);
  same('the first-run language choice offers exactly the app languages',
    [...html.matchAll(/name="first-lang"[^>]*value="([^"]+)"/g)].map((m) => m[1]), i18n.LANGS);
  // two pickers share one markup, so a copied id would point a label at the other group
  const ids = [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
  const twice = ids.filter((id, i) => ids.indexOf(id) !== i);
  check('no id appears twice in the page', ids.length > 0 && twice.length === 0, twice.join(' '));

  // the same names as applyLanguage() in app.js: manifest.webmanifest, then manifest-<code>.webmanifest
  const file = (code) => (code === DEF ? 'manifest.webmanifest' : `manifest-${code}.webmanifest`);
  const withoutWords = ({ name, short_name: shortName, description, lang, ...rest }) => rest;
  const first = JSON.parse(read(file(DEF)));
  for (const code of i18n.LANGS) {
    if (!existsSync(join(SERVER, file(code)))) {
      check(`${file(code)} exists`, false);
      continue;
    }
    const m = JSON.parse(read(file(code)));
    const d = i18n.DICT[code];
    same(`${file(code)}: the name, the description and the language of ${code}`,
      [m.name, m.short_name, m.description, m.lang], [d['app.name'], d['app.name'], d['app.description'], code]);
    if (code !== DEF) same(`${file(code)}: everything else as in ${file(DEF)}`, withoutWords(m), withoutWords(first));
  }
});

// ---------- deploy guards ----------

await section('deploy: offline files, icons, CSP-safe markup', () => {
  const sw = read('sw.js');
  const kept = [...(/const FILES = \[([\s\S]*?)\];/.exec(sw)?.[1] ?? '').matchAll(/'([^']+)'/g)].map((m) => m[1]);
  const libs = readdirSync(join(SERVER, 'lib')).filter((f) => f.endsWith('.js')).map((f) => 'lib/' + f);
  const icons = readdirSync(join(SERVER, 'icons')).filter((f) => !f.startsWith('.')).map((f) => 'icons/' + f);
  const manifests = readdirSync(SERVER).filter((f) => f.endsWith('.webmanifest')); // one for each app language
  same('the service worker keeps exactly the app files', [...kept].sort(),
    ['./', 'index.html', 'app.css', 'app.js', ...manifests, ...libs, ...icons].sort());
  check('every file the service worker keeps exists', kept.every((f) => f === './' || existsSync(join(SERVER, f))));
  check('the cache name starts with tablica-, the prefix activation cleans up', /const CACHE = 'tablica-[^']+';/.test(sw));

  // The app reads its files from the cache first, so a phone shows new features only after sw.js gets a new CACHE
  // name (the app then offers "New version available · Refresh"). The ledger pairs every cache name with the digest
  // of the app files published under it (the files named in FILES, in that order; sw.js itself carries the name).
  // Change an app file and the last check fails until CACHE has the next name and the ledger a line for it.
  // The failure message prints that line. Names only go up, and an old line is never edited.
  const LEDGER = {
    'tablica-v7': '4b62d6bddeddfc8466b8f85c79eeb92b9258370ad4fbe8b7a5c718fb8bb46c02',
    'tablica-v8': '407557728890df01c6f07beda4f1200d02cddc5cb7d8e0ee197736f358f515ff',
    'tablica-v9': '3883ed1fc6e9969a4a36e5014720a324c7d4224bc51008a12efc874d19c7ed60',
    'tablica-v10': '5440c6a010ec4c9ee09d7489d254edffd53556c189cc953c2524d19319c40b85',
  };
  const sha = (data) => createHash('sha256').update(data).digest('hex');
  const digest = sha(kept.filter((f) => f !== './').map((f) => `${f} ${sha(readFileSync(join(SERVER, f)))}\n`).join(''));
  const cacheName = /const CACHE = '([^']+)';/.exec(sw)?.[1] ?? '';
  const ledgerNames = Object.keys(LEDGER);
  const numbers = ledgerNames.map((n) => Number(/^tablica-v(\d+)$/.exec(n)?.[1]));
  check('the cache ledger holds names like tablica-v7 with rising numbers',
    numbers.length > 0 && numbers.every((v, i) => Number.isInteger(v) && (i === 0 || v > numbers[i - 1])));
  let stale = '';
  if (cacheName !== ledgerNames.at(-1)) {
    stale = `sw.js says '${cacheName}', the newest ledger line is '${ledgerNames.at(-1)}'. Add to LEDGER: '${cacheName}': '${digest}'`;
  } else if (LEDGER[cacheName] !== digest) {
    stale = `the app files changed but sw.js still says '${cacheName}', so phones keep showing the old files. `
      + `Give CACHE the next name and add to LEDGER: '<next name>': '${digest}'`;
  }
  check('the cache name changed with the app files (a phone keeps the old files until it does)', stale === '', stale);

  const manifest = JSON.parse(read('manifest.webmanifest'));
  const pngSize = (rel) => {
    const b = readFileSync(join(SERVER, rel));
    return b.toString('latin1', 1, 4) === 'PNG' ? `${b.readUInt32BE(16)}x${b.readUInt32BE(20)}` : null;
  };
  check('manifest icons exist and have the sizes they claim', manifest.icons.length > 0 && manifest.icons.every(
    (i) => existsSync(join(SERVER, i.src)) && (i.type !== 'image/png' || pngSize(i.src) === i.sizes)));
  check('the manifest offers a maskable icon', manifest.icons.some((i) => String(i.purpose).split(' ').includes('maskable')));
  same('the apple-touch-icon is 180 px', pngSize('icons/apple-touch-icon.png'), '180x180');
  check('the manifest stays inside the app folder', manifest.start_url === './' && manifest.scope === './');

  const html = read('index.html');
  check('index.html has no style attributes and no <style> block', !/\sstyle\s*=/i.test(html) && !/<style[\s>]/i.test(html));
  check('index.html has no inline event handlers', !/\son[a-z]+\s*=/i.test(html));
  const phpList = (name) => new RegExp(`const ${name} = \\[([^\\]]*)\\]`).exec(apiSrc)?.[1]
    .split(',').map((v) => v.trim().replace(/'/g, ''));
  const radios = (name) => [...html.matchAll(new RegExp(`name="${name}"[^>]*value="([^"]+)"`, 'g'))].map((m) => m[1]);
  same('language buttons are exactly LANGS from api.php', radios('lang'), phpList('LANGS'));
  same('the interval form offers the default and an own value', radios('poll'), ['default', 'custom']);
  const appH = readFileSync(join(ROOT, 'firmware', 'main', 'app.h'), 'utf8');
  const bound = (name) => [new RegExp(`const ${name} = (\\d+);`).exec(apiSrc)?.[1],
    new RegExp(`#define ${name} (\\d+)\\b`).exec(appH)?.[1]].map(Number);
  same('api.php and firmware/main/app.h: the same default interval and bounds, the app the same bounds',
    [bound('POLL_DEFAULT_S'), bound('POLL_MIN_S'), bound('POLL_MAX_S')],
    [[10, 10], [api.POLL_MIN_S, api.POLL_MIN_S], [api.POLL_MAX_S, api.POLL_MAX_S]]);
  same('the seconds field takes as many digits as POLL_MAX_S has',
    Number(/id="poll-seconds"[^>]*maxlength="(\d+)"/.exec(html)?.[1]), String(api.POLL_MAX_S).length);
  const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)];
  check('index.html loads only app.js as a module, no inline script', scripts.length === 1
    && /\bsrc="app\.js"/.test(scripts[0][1]) && /\btype="module"/.test(scripts[0][1]) && scripts[0][2].trim() === '');
  const refs = [...html.matchAll(/\b(?:href|src)="([^"]+)"/g)].map((m) => m[1]).filter((u) => !u.startsWith('#'));
  check('everything index.html links is a file of the app', refs.length > 0
    && refs.every((u) => !/^[a-z][a-z0-9+.-]*:|^\/\//i.test(u) && existsSync(join(SERVER, u))), refs.join(' '));
  const css = read('app.css');
  check('app.css loads nothing from outside (no @import, no remote url())',
    !/@import/i.test(css) && !/url\(\s*['"]?(?:[a-z]+:)?\/\//i.test(css));

  const BANNED = [/\.innerHTML\b/, /\.outerHTML\b/, /insertAdjacentHTML/, /setAttribute\(\s*['"]style['"]/,
    /document\.write/, /\beval\(/, /\bnew Function\b/];
  const hits = ['app.js', 'sw.js', ...libs].flatMap((f) => BANNED.filter((re) => re.test(read(f))).map((re) => `${f}: ${re}`));
  check('scripts build no HTML from strings, set no style attribute and never eval', hits.length === 0, hits.join(', '));

  const app = read('app.js');
  const missing = [];
  let imported = 0;
  for (const m of app.matchAll(/import\s*\{([^}]*)\}\s*from\s*'\.\/lib\/([a-z0-9]+\.js)'/g)) {
    for (const name of m[1].split(',').map((s) => s.trim().split(/\s+as\s+/)[0]).filter(Boolean)) {
      imported++;
      if (!LIBS[m[2]] || !(name in LIBS[m[2]])) missing.push(`${m[2]}: ${name}`);
    }
  }
  check(`app.js imports only names the libraries export (${imported} names)`, imported > 0 && missing.length === 0,
    missing.join(', '));
  const sources = [...app.matchAll(/^\s*import\b[^'"]*['"]([^'"]+)['"]/gm)].map((m) => m[1]);
  check('app.js imports nothing from outside server/lib', sources.length > 0 && sources.every((s) => s.startsWith('./lib/')),
    sources.join(' '));

  const ht = read('.htaccess');
  const csp = /Content-Security-Policy "([^"]+)"/.exec(ht)?.[1] ?? '';
  check(".htaccess sends a CSP with default-src 'self' and nothing unsafe", csp.includes("default-src 'self'")
    && !csp.includes('unsafe-'));
  check('.htaccess keeps the old rules', ht.includes('Options -Indexes') && /config\\\.php[\s\S]*Require all denied/.test(ht));
  check('.htaccess gives the manifest its type and keeps sw.js out of the cache',
    ht.includes('AddType application/manifest+json .webmanifest') && /sw\\\.js[\s\S]*no-cache/.test(ht));
});

console.log(failed ? `${failed} FAILED` : 'ALL PASS');
process.exitCode = failed ? 1 : 0;
