#!/usr/bin/env node
// Samples the Clawd animations in assets/clawd/*.svg (MIT, Copyright (c) 2026 Marcio Granzotto Rodrigues,
// github.com/marciogranzotto/clawd-tank) into the black-and-white 64x64 frames of server/lib/anim.js
// (docs/SPEC.md, "Animacje"). Neither the app nor the board can draw SVG, so this runs only when the
// art or a recipe below changes, and its result is committed: the board gets the same bytes through
// node tools/anim2c.mjs, the app reads them from anim.js.
//
// Needs Node 22+ and Google Chrome, which it drives headless over the DevTools protocol: every
// animation is paused at a moment t, the 32x32-unit window of the art is captured at 16 screen px per
// unit, and each 8x8 block of the capture becomes one frame pixel, black when its mean is darker than
// middle grey. One unit of the pixel art is therefore exactly 2x2 frame pixels, nothing is smeared.
//
//     node tools/clawd2anim.mjs                  writes the frames into server/lib/anim.js
//     node tools/clawd2anim.mjs --sheet <png>    also writes every frame side by side, for a look
//
// CHROME=<path> picks another Chrome or Chromium binary.
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { crc32, deflateSync, inflateSync } from 'node:zlib';

const SIZE = 64; // frame px, as SIZE in server/lib/anim.js
const UNIT = 2; // frame px per SVG unit
const K = 8; // screen px per frame px
const SHOT = SIZE * K;
const ART = fileURLToPath(new URL('../assets/clawd/', import.meta.url));
const ANIM_JS = fileURLToPath(new URL('../server/lib/anim.js', import.meta.url));
const CHROME = process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

// How each SVG becomes 1-bit frames. x, y: the top-left corner of the 32x32-unit window in the SVG's
// units, with the 15-unit body centred (x = -8.5). colors: replaced in the SVG text, in this order:
// the eyes (#000000) become white gaps in the body, the body (#DE886D) and the props black; the ground
// shadow is then white on white, so it drops out. css: added to the SVG, to tune a detail or to make
// a part loop with the rest (the frames of one loop must join up). step: ms between samples, from 0
// to period. keyAt: the moment shown where nothing may move. Equal frames in a row merge into one.
const EYES = ['#000000', '#FFFFFF'];
const BODY = ['#DE886D', '#000000'];
const RECIPES = [
  {
    id: 'typing', file: 'clawd-working-typing.svg', x: -8.5, y: -11, step: 100, period: 1200, keyAt: 0,
    colors: [EYES, BODY, ['#40C4FF', '#000000'], ['#546E7A', '#000000'], ['#78909C', '#FFFFFF']],
    // the laptop's lid is white with a black edge, so the body behind it stays a shape
    css: '#laptop rect:nth-of-type(2){stroke:#000;stroke-width:.5px} .logo-glow{display:none}'
      + ' .data-bit{animation-duration:1.2s}',
  },
  {
    id: 'away', file: 'clawd-going-away.svg', x: -8.5, y: -9, step: 100, period: 3000, keyAt: 0,
    colors: [EYES, BODY, ['#5D4037', '#000000']],
    css: '#ground-shadow{fill:#000}', // the hole Clawd digs into stays
  },
  {
    id: 'happy', file: 'clawd-happy.svg', x: -8.5, y: -13, step: 150, period: 3000, keyAt: 0,
    colors: [EYES, BODY, ['#FFD700', '#000000'], ['#FFA000', '#000000'], ['#FFF59D', '#000000'], ['#FFC107', '#000000']],
    css: '',
  },
  {
    id: 'alert', file: 'clawd-notification.svg', x: -8.5, y: -10, step: 160, period: 4000, keyAt: 640,
    colors: [EYES, BODY, ['#FF3D00', '#000000']],
    css: '',
  },
  {
    id: 'idle', file: 'clawd-idle-living.svg', x: -8.5, y: -9, step: 200, period: 16000, keyAt: 0,
    colors: [EYES, BODY, ['#40C4FF', '#FFFFFF']], // the yawn's tear is a gap in the face, like the eyes
    css: '',
  },
  {
    id: 'sleep', file: 'clawd-sleeping.svg', x: -8.5, y: -13, step: 250, period: 6000, keyAt: 1500,
    colors: [EYES, BODY, ['#90A4AE', '#000000'], ['#B0BEC5', '#000000'], ['#CFD8DC', '#000000']],
    css: '.breathe{animation-duration:6s}', // breathing in step with the 6 s of the letters Z
  },
];

// ---------- Chrome over the DevTools protocol ----------

async function launch() {
  const profile = mkdtempSync(join(tmpdir(), 'clawd2anim-'));
  const proc = spawn(CHROME, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', '--force-color-profile=srgb', 'about:blank'],
  { stdio: ['ignore', 'ignore', 'pipe'] });
  const url = await new Promise((resolve, reject) => {
    let err = '';
    proc.on('error', reject);
    proc.on('exit', (code) => reject(new Error(`Chrome exited with ${code}: ${err.slice(-400)}`)));
    proc.stderr.on('data', (d) => {
      err += d;
      const m = /DevTools listening on (ws:\/\/\S+)/.exec(err);
      if (m) resolve(m[1]);
    });
  });
  const ws = new WebSocket(url);
  await new Promise((resolve, reject) => {
    ws.onopen = resolve;
    ws.onerror = () => reject(new Error('no connection to Chrome'));
  });
  let next = 0;
  const waiting = new Map();
  ws.onmessage = (e) => {
    const m = JSON.parse(e.data);
    const w = waiting.get(m.id);
    if (!w) return;
    waiting.delete(m.id);
    if (m.error) w.reject(new Error(`${w.method}: ${m.error.message}`));
    else w.resolve(m.result);
  };
  const send = (method, params = {}, sessionId = undefined) => new Promise((resolve, reject) => {
    const id = ++next;
    waiting.set(id, { resolve, reject, method });
    ws.send(JSON.stringify({ id, method, params, sessionId }));
  });
  const close = async () => {
    ws.close();
    proc.removeAllListeners('exit');
    const gone = new Promise((resolve) => proc.once('exit', resolve));
    proc.kill();
    await gone; // Chrome writes its profile until it exits
    rmSync(profile, { recursive: true, force: true, maxRetries: 5 });
  };
  return { send, close };
}

async function page(chrome) {
  const { targetId } = await chrome.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await chrome.send('Target.attachToTarget', { targetId, flatten: true });
  const send = (method, params) => chrome.send(method, params, sessionId);
  await send('Emulation.setDeviceMetricsOverride', { width: SHOT, height: SHOT, deviceScaleFactor: 1, mobile: false });
  const { frameTree } = await send('Page.getFrameTree');
  const evaluate = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? r.exceptionDetails.text);
    return r.result.value;
  };
  return { send, evaluate, frameId: frameTree.frame.id };
}

// ---------- PNG ----------

function chunks(png) {
  const out = [];
  for (let at = 8; at < png.length;) {
    const len = png.readUInt32BE(at);
    out.push([png.toString('latin1', at + 4, at + 8), png.subarray(at + 8, at + 8 + len)]);
    at += 12 + len;
  }
  return out;
}

/** Grey levels 0..255 of an 8-bit RGB or RGBA PNG without interlace, as Chrome writes them. */
function decodePng(png) {
  const all = chunks(png);
  const ihdr = all.find(([t]) => t === 'IHDR')[1];
  const w = ihdr.readUInt32BE(0);
  const h = ihdr.readUInt32BE(4);
  const bpp = { 2: 3, 6: 4 }[ihdr[9]];
  if (ihdr[8] !== 8 || !bpp || ihdr[12] !== 0) throw new Error('unexpected PNG from Chrome');
  const raw = inflateSync(Buffer.concat(all.filter(([t]) => t === 'IDAT').map(([, d]) => d)));
  const stride = w * bpp;
  const px = Buffer.alloc(stride * h);
  for (let y = 0; y < h; y++) {
    const f = raw[y * (stride + 1)];
    const src = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
    for (let i = 0; i < stride; i++) {
      const a = i >= bpp ? px[y * stride + i - bpp] : 0;
      const b = y > 0 ? px[(y - 1) * stride + i] : 0;
      const c = i >= bpp && y > 0 ? px[(y - 1) * stride + i - bpp] : 0;
      const p = a + b - c;
      const pa = Math.abs(p - a);
      const pb = Math.abs(p - b);
      const pc = Math.abs(p - c);
      const pred = [0, a, b, (a + b) >> 1, pa <= pb && pa <= pc ? a : pb <= pc ? b : c][f];
      px[y * stride + i] = (src[i] + pred) & 255;
    }
  }
  const grey = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) {
    const [r, g, b, al] = [px[i * bpp], px[i * bpp + 1], px[i * bpp + 2], bpp === 4 ? px[i * bpp + 3] : 255];
    const v = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    grey[i] = Math.round((v * al + 255 * (255 - al)) / 255); // on white
  }
  return { w, h, grey };
}

function encodePng(w, h, grey) {
  const raw = Buffer.alloc((w + 1) * h);
  for (let y = 0; y < h; y++) raw.set(grey.subarray(y * w, (y + 1) * w), y * (w + 1) + 1);
  const chunk = (type, data) => {
    const head = Buffer.alloc(8);
    head.writeUInt32BE(data.length, 0);
    head.write(type, 4, 'latin1');
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0);
    return Buffer.concat([head, data, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // grey, 8 bit
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

// ---------- sampling ----------

function svgFor(recipe) {
  let svg = readFileSync(join(ART, recipe.file), 'utf8');
  for (const [from, to] of recipe.colors) svg = svg.replaceAll(new RegExp(from, 'gi'), to);
  const open = /<svg\b[^>]*>/.exec(svg)[0];
  const view = SIZE / UNIT;
  const tag = open.replace(/\s(viewBox|width|height)="[^"]*"/g, '')
    .replace('<svg', `<svg viewBox="${recipe.x} ${recipe.y} ${view} ${view}" width="${SHOT}" height="${SHOT}"`
      + ' style="display:block" shape-rendering="crispEdges"');
  return svg.replace(open, tag).replace(/<\/svg>\s*$/, `<style>${recipe.css}</style></svg>`);
}

/** A capture as a frame: rows from the top, 8 px per byte, the most significant bit leftmost, 1 = black. */
function toFrame({ w, grey }) {
  const frame = new Uint8Array(SIZE * SIZE / 8);
  for (let y = 0; y < SIZE; y++) {
    for (let x = 0; x < SIZE; x++) {
      let sum = 0;
      for (let dy = 0; dy < K; dy++) {
        for (let dx = 0; dx < K; dx++) sum += grey[(y * K + dy) * w + x * K + dx];
      }
      if (sum < 128 * K * K) frame[y * (SIZE / 8) + (x >> 3)] |= 0x80 >> (x & 7);
    }
  }
  return frame;
}

async function sample(tab, recipe) {
  await tab.send('Page.setDocumentContent', {
    frameId: tab.frameId,
    html: `<!doctype html><html><body style="margin:0;background:#fff">${svgFor(recipe)}</body></html>`,
  });
  const frames = [];
  const ms = [];
  let key = 0;
  for (let t = 0; t < recipe.period; t += recipe.step) {
    const count = await tab.evaluate(`(async () => {
      const all = document.getAnimations();
      for (const a of all) { a.pause(); a.currentTime = ${t}; }
      await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
      return all.length;
    })()`);
    if (count === 0) throw new Error(`${recipe.file}: no animations`);
    const shot = await tab.send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: SHOT, height: SHOT, scale: 1 } });
    const frame = toFrame(decodePng(Buffer.from(shot.data, 'base64')));
    const last = frames.at(-1);
    if (last && last.every((b, i) => b === frame[i])) {
      ms[ms.length - 1] += recipe.step;
    } else {
      frames.push(frame);
      ms.push(recipe.step);
    }
    if (t <= recipe.keyAt) key = frames.length - 1;
  }
  if (frames.length > 255) throw new Error(`${recipe.id}: ${frames.length} frames, at most 255`);
  return { id: recipe.id, ms, key, frames };
}

// ---------- server/lib/anim.js ----------

// Frames are stored packed: each one XOR the frame before it (the first as it is), then PackBits
// (a byte n of 0..127 copies the next n + 1 bytes, of 129..255 repeats the next byte 257 - n times),
// then base64. unpack() in anim.js undoes it.
function packBits(bytes) {
  const out = [];
  for (let i = 0; i < bytes.length;) {
    let run = 1;
    while (i + run < bytes.length && run < 128 && bytes[i + run] === bytes[i]) run++;
    if (run >= 2) {
      out.push(257 - run, bytes[i]);
      i += run;
      continue;
    }
    let lit = 1;
    while (i + lit < bytes.length && lit < 128 && !(i + lit + 1 < bytes.length && bytes[i + lit] === bytes[i + lit + 1])) lit++;
    out.push(lit - 1, ...bytes.subarray(i, i + lit));
    i += lit;
  }
  return Uint8Array.from(out);
}

function pack(frames) {
  const all = new Uint8Array(frames.length * frames[0].length);
  frames.forEach((f, n) => all.set(n === 0 ? f : f.map((b, i) => b ^ frames[n - 1][i]), n * f.length));
  return Buffer.from(packBits(all)).toString('base64');
}

const BEGIN = '// BEGIN frames from tools/clawd2anim.mjs: do not edit by hand, run the tool.';
const END = '// END frames from tools/clawd2anim.mjs';

function block(anims) {
  const lines = [BEGIN, 'const BAKED = {'];
  for (const a of anims) {
    const data = pack(a.frames).match(/.{1,100}/g).map((s) => `    '${s}',`);
    lines.push(`  ${a.id}: {`, `    ms: [${a.ms.join(', ')}],`, `    key: ${a.key},`, '    data: [', ...data.map((s) => `  ${s}`), '    ].join(\'\'),', '  },');
  }
  lines.push('};', END);
  return lines.join('\n');
}

// Up to 12 frames per line at 2x, each animation starting on a new line.
function sheet(anims) {
  const gap = 4;
  const zoom = 2;
  const cols = 12;
  const cell = SIZE * zoom + gap;
  const lines = anims.flatMap((a) => Array.from({ length: Math.ceil(a.frames.length / cols) },
    (_, n) => a.frames.slice(n * cols, (n + 1) * cols)));
  const w = cols * cell + gap;
  const h = lines.length * cell + gap;
  const grey = new Uint8Array(w * h).fill(160);
  lines.forEach((line, row) => line.forEach((f, col) => {
    for (let y = 0; y < SIZE * zoom; y++) {
      for (let x = 0; x < SIZE * zoom; x++) {
        const px = x / zoom | 0;
        const on = f[(y / zoom | 0) * (SIZE / 8) + (px >> 3)] & (0x80 >> (px & 7));
        grey[(gap + row * cell + y) * w + gap + col * cell + x] = on ? 0 : 255;
      }
    }
  }));
  return encodePng(w, h, grey);
}

const chrome = await launch();
let anims;
try {
  const tab = await page(chrome);
  anims = [];
  for (const r of RECIPES) anims.push(await sample(tab, r));
} finally {
  await chrome.close();
}
const src = readFileSync(ANIM_JS, 'utf8');
const from = src.indexOf(BEGIN);
const to = src.indexOf(END);
if (from < 0 || to < from) throw new Error(`server/lib/anim.js: no block between "${BEGIN}" and "${END}"`);
writeFileSync(ANIM_JS, src.slice(0, from) + block(anims) + src.slice(to + END.length));
const sheetAt = process.argv.indexOf('--sheet');
if (sheetAt > 0) writeFileSync(process.argv[sheetAt + 1], sheet(anims));
for (const a of anims) {
  console.log(`${a.id}: ${a.frames.length} frames, ${a.ms.reduce((s, m) => s + m, 0)} ms, key ${a.key}`);
}
console.log('wrote server/lib/anim.js; now run node tools/anim2c.mjs');
