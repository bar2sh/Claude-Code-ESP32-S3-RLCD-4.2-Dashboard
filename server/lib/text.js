// Text rules shared with the board and the server.
// Pure functions only: the same module runs in the browser and in Node tests.
// Characters outside ASCII are built from code points, so the source stays plain ASCII.

const cp = (...points) => String.fromCodePoint(...points);
const span = (lo, hi) => Array.from({ length: hi - lo + 1 }, (_, i) => lo + i);

// Glyphs the board can draw in a message. draw_message() in firmware/main/views.c picks the largest of
// u8g2_font_helvB24_te, helvB18_te, helvB14_te, helvR12_te and helvR10_te that fits; all five carry the
// same 452 glyphs (read from firmware/components/u8g2/csrc/u8g2_fonts.c). '\n' breaks the line.
const GLYPH_RANGES = [
  [0x0a, 0x0a], [0x20, 0x7e], [0xa0, 0x17e], [0x186, 0x186], [0x189, 0x189], [0x18e, 0x18e],
  [0x192, 0x192], [0x197, 0x197], [0x19a, 0x19a], [0x19d, 0x19d], [0x19f, 0x1a1], [0x1a7, 0x1a8],
  [0x1ae, 0x1b0], [0x1b5, 0x1b6], [0x1bb, 0x1bc], [0x1c0, 0x1c0], [0x1c2, 0x1c3], [0x1cd, 0x1ed],
  [0x1f0, 0x1f0], [0x1f4, 0x1f5], [0x1f8, 0x21b], [0x21e, 0x21f], [0x226, 0x233], [0x250, 0x250],
  [0x254, 0x254], [0x258, 0x259], [0x25f, 0x25f], [0x265, 0x265], [0x275, 0x275], [0x279, 0x279],
  [0x287, 0x289], [0x28c, 0x28e], [0x29e, 0x29e], [0x2bb, 0x2bd], [0x2081, 0x2083], [0x20ac, 0x20ac],
  [0x2103, 0x2103], [0x2109, 0x2109],
];

export const MESSAGE_MAX = 280;   // api.php: text must have 1-280 chars
export const SOUND_NAME_MAX = 24; // sounds.php: SOUND_NAME_MAX

export function isDrawable(point) {
  for (const [lo, hi] of GLYPH_RANGES) {
    if (point < lo) return false;
    if (point <= hi) return true;
  }
  return false;
}

const REPLACEMENT_CHAR = cp(0xfffd);

/** Replace lone UTF-16 surrogates with U+FFFD (the server only takes valid UTF-8), then compose to NFC. */
export function normalizeInput(text) {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff) {
      const d = i + 1 < text.length ? text.charCodeAt(i + 1) : 0;
      if (d >= 0xdc00 && d <= 0xdfff) {
        out += text[i] + text[i + 1];
        i++;
      } else {
        out += REPLACEMENT_CHAR;
      }
    } else if (c >= 0xdc00 && c <= 0xdfff) {
      out += REPLACEMENT_CHAR;
    } else {
      out += text[i];
    }
  }
  return out.normalize('NFC');
}

const PHP_TRIM = ' \t\n\r\0\x0B';

/** PHP trim() with its default character list. */
export function phpTrim(s) {
  let a = 0;
  let b = s.length;
  while (a < b && PHP_TRIM.includes(s[a])) a++;
  while (b > a && PHP_TRIM.includes(s[b - 1])) b--;
  return s.slice(a, b);
}

/** The message text as api.php stores it: control characters except '\n' removed, then trim(). */
export function serverMessageText(text) {
  return phpTrim(normalizeInput(text).replace(/[\x00-\x09\x0B-\x1F\x7F]/g, ''));
}

/** Characters counted the way the server counts them: Unicode code points after cleaning. */
export function messageLength(text) {
  return [...serverMessageText(text)].length;
}

const INVISIBLE = /^[\p{Cf}\p{Z}\p{Cc}\p{M}]+$/u;

function clusters(text) {
  if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
    return Array.from(new Intl.Segmenter('pl', { granularity: 'grapheme' }).segment(text), (s) => s.segment);
  }
  return [...text];
}

function codeLabel(cluster) {
  return [...cluster].map((ch) => 'U+' + ch.codePointAt(0).toString(16).toUpperCase().padStart(4, '0')).join(' ');
}

/**
 * Characters the board cannot draw, each listed once in order of first appearance. A character is a
 * grapheme cluster (an emoji with skin tone counts once); invisible ones are shown by their code.
 */
export function unsupportedChars(text) {
  const seen = new Set();
  const out = [];
  for (const cluster of clusters(serverMessageText(text))) {
    if ([...cluster].every((ch) => isDrawable(ch.codePointAt(0)))) continue;
    if (seen.has(cluster)) continue;
    seen.add(cluster);
    out.push(INVISIBLE.test(cluster) ? codeLabel(cluster) : cluster);
  }
  return out;
}

const REPLACEMENTS = new Map([
  // dashes and minus
  ...[0x2010, 0x2011, 0x2012, 0x2013, 0x2014, 0x2015, 0x2043, 0x2212].map((p) => [p, '-']),
  [0x2026, '...'],
  // double and single quotes, primes
  ...[0x201c, 0x201d, 0x201e, 0x201f, 0x2033].map((p) => [p, '"']),
  ...[0x2018, 0x2019, 0x201a, 0x201b, 0x2032].map((p) => [p, "'"]),
  // bullets become the middle dot the fonts have
  ...[0x2022, 0x2219].map((p) => [p, cp(0xb7)]),
  // line and paragraph separators
  [0x2028, '\n'], [0x2029, '\n'],
  // spaces of other widths
  ...[0x1680, ...span(0x2000, 0x200a), 0x202f, 0x205f, 0x3000].map((p) => [p, ' ']),
]);

/**
 * Make the text drawable: dashes become -, the ellipsis ..., curly quotes ", unusual spaces a space,
 * compatibility forms such as the fi ligature or TM their plain letters; anything else is removed.
 */
export function replaceUnsupported(text) {
  let out = '';
  for (const ch of normalizeInput(text)) {
    const point = ch.codePointAt(0);
    if (isDrawable(point)) {
      out += ch;
    } else if (REPLACEMENTS.has(point)) {
      out += REPLACEMENTS.get(point);
    } else {
      const compat = ch.normalize('NFKC');
      if (compat !== ch && [...compat].every((c) => isDrawable(c.codePointAt(0)))) out += compat;
    }
  }
  return out.normalize('NFC');
}

// PHP's \s with the /u flag (PCRE2 with UCP). It differs from JavaScript's \s: no U+FEFF, but U+0085 and U+180E.
const PHP_SPACES = [...span(0x09, 0x0d), 0x20, 0x85, 0xa0, 0x1680, 0x180e, ...span(0x2000, 0x200a),
  0x2028, 0x2029, 0x202f, 0x205f, 0x3000];
const PHP_SPACE_RUN = new RegExp('[' + cp(...PHP_SPACES) + ']+', 'g');

/** The library name as sounds.php soundName() cleans it, or null when it would be refused. */
export function soundName(name) {
  if (typeof name !== 'string') return null;
  const clean = phpTrim(normalizeInput(name).replace(PHP_SPACE_RUN, ' ').replace(/\p{Cc}/gu, ''));
  const len = [...clean].length;
  return len >= 1 && len <= SOUND_NAME_MAX ? clean : null;
}

/** First 24 code points of a hint, cut on a whole character. */
function clampName(s) {
  const points = [...s];
  return points.length > SOUND_NAME_MAX ? phpTrim(points.slice(0, SOUND_NAME_MAX).join('')) : s;
}

function capitalize(s) {
  const [first, ...rest] = [...s];
  return first === undefined ? '' : first.toLocaleUpperCase('pl') + rest.join('');
}

function safeDecode(s) {
  try {
    return decodeURIComponent(s);
  } catch {
    return s;
  }
}

/**
 * A name proposal for the library: from a myinstants link (page slug or MP3 file name) or a file name.
 * Returns '' when nothing usable is left; the user types the name then.
 */
export function nameHint(source) {
  if (typeof source !== 'string') return '';
  let base;
  const link = myinstantsSource(source);
  if (link && link.kind === 'page') {
    base = link.url.replace(/\/+$/, '').split('/').pop().replace(/-\d{3,}$/, '');
  } else if (link) {
    base = safeDecode(link.url.split('/').pop()).replace(/\.mp3$/i, '');
  } else {
    base = source.split(/[\\/]/).pop().replace(/\.[A-Za-z0-9]{1,5}$/, '');
  }
  const words = phpTrim(base.replace(/[-_]+/g, ' ').replace(PHP_SPACE_RUN, ' '));
  return soundName(clampName(capitalize(words))) ?? '';
}

// sounds.php myinstantsSource(): the same three patterns. PHP's '.' without /s stops at '\n' only.
const MYINSTANTS_URL = /^https?:\/\/(?:www\.)?myinstants\.com(\/[^?#]*)(?:[?#][^\n]*)?$/i;
const MYINSTANTS_PAGE = /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?instant\/[A-Za-z0-9_-]+\/?$/;
const MYINSTANTS_MP3 = /^\/media\/sounds\/[A-Za-z0-9_.%()~-]+\.mp3$/i;

/** {kind: 'page'|'mp3', url} for a link the server accepts, or null. */
export function myinstantsSource(url) {
  const m = MYINSTANTS_URL.exec(phpTrim(String(url)));
  if (!m) return null;
  const path = m[1];
  if (MYINSTANTS_PAGE.test(path)) {
    return { kind: 'page', url: 'https://www.myinstants.com' + path.replace(/\/+$/, '') + '/' };
  }
  if (MYINSTANTS_MP3.test(path)) {
    return { kind: 'mp3', url: 'https://www.myinstants.com' + path };
  }
  return null;
}

const NBSP = cp(0xa0);
const ONE_LETTER_WORD = /(^|[\s(])[aiouwz]$/i;

/**
 * Polish line breaking for text on screen: the space after a one-letter word (a, i, o, u, w, z) does not
 * break, so such a word never ends a line. Display only; what goes to the server stays as typed.
 */
export function glue(text) {
  const words = String(text).split(' ');
  let out = words[0];
  for (let i = 1; i < words.length; i++) {
    out += (words[i] !== '' && ONE_LETTER_WORD.test(words[i - 1]) ? NBSP : ' ') + words[i];
  }
  return out;
}
