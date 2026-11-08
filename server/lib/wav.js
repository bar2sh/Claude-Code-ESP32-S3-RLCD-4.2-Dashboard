// Sound for the board: WAV PCM 16-bit, mono, 16 kHz, at most 15 s, with a plain 44-byte header.
// mixToMono, resampleLinear, normalizePeak and encodeWav are pure, so Node tests run them without Web Audio.
// Error messages come from lib/i18n.js, in the app language.

import { t } from './i18n.js';

export const RATE = 16000;
export const MAX_SECONDS = 15;
export const MAX_SAMPLES = RATE * MAX_SECONDS; // 240000 samples = 480000 B, the server limit
export const HEADER_BYTES = 44;
export const PEAK = 10 ** (-1 / 20); // -1 dBFS, about 89% of full scale
export const MAX_INPUT_BYTES = 20 * 1024 * 1024; // the whole file is decoded, so a long recording could exhaust a phone

/** Average of the channels (what Web Audio does for stereo to mono). */
export function mixToMono(channels) {
  if (channels.length === 0) return new Float32Array(0);
  const n = Math.min(...channels.map((c) => c.length));
  const out = new Float32Array(n);
  for (const c of channels) {
    for (let i = 0; i < n; i++) out[i] += c[i];
  }
  if (channels.length > 1) {
    for (let i = 0; i < n; i++) out[i] /= channels.length;
  }
  return out;
}

/** Linear interpolation from one sample rate to another; positions past the end repeat the last sample. */
export function resampleLinear(samples, fromRate, toRate, outLength = Math.round((samples.length * toRate) / fromRate)) {
  const out = new Float32Array(Math.max(0, outLength));
  const n = samples.length;
  if (n === 0) return out;
  const step = fromRate / toRate;
  for (let i = 0; i < out.length; i++) {
    const pos = i * step;
    const j = Math.floor(pos);
    if (j >= n - 1) {
      out[i] = samples[n - 1];
    } else {
      out[i] = samples[j] + (samples[j + 1] - samples[j]) * (pos - j);
    }
  }
  return out;
}

/** Scales the loudest sample to the peak (-1 dBFS). Broken samples become 0, silence stays silent. */
export function normalizePeak(samples, peak = PEAK) {
  const out = new Float32Array(samples.length);
  let max = 0;
  for (let i = 0; i < samples.length; i++) {
    const v = Number.isFinite(samples[i]) ? samples[i] : 0;
    out[i] = v;
    if (Math.abs(v) > max) max = Math.abs(v);
  }
  if (max > 0) {
    const gain = peak / max;
    for (let i = 0; i < out.length; i++) out[i] *= gain;
  }
  return out;
}

/** 44-byte header plus little-endian 16-bit samples, the same bytes sounds.php writes. */
export function encodeWav(samples, rate = RATE) {
  const n = samples.length;
  const bytes = new Uint8Array(HEADER_BYTES + 2 * n);
  const v = new DataView(bytes.buffer);
  const tag = (offset, s) => {
    for (let i = 0; i < 4; i++) v.setUint8(offset + i, s.charCodeAt(i));
  };
  tag(0, 'RIFF');
  v.setUint32(4, 36 + 2 * n, true);
  tag(8, 'WAVE');
  tag(12, 'fmt ');
  v.setUint32(16, 16, true); // fmt chunk size
  v.setUint16(20, 1, true); // PCM
  v.setUint16(22, 1, true); // mono
  v.setUint32(24, rate, true);
  v.setUint32(28, rate * 2, true); // bytes per second
  v.setUint16(32, 2, true); // bytes per frame
  v.setUint16(34, 16, true); // bits per sample
  tag(36, 'data');
  v.setUint32(40, 2 * n, true);
  for (let i = 0; i < n; i++) {
    const x = Math.max(-1, Math.min(1, samples[i] || 0));
    v.setInt16(HEADER_BYTES + 2 * i, Math.round(x * 32767), true);
  }
  return bytes;
}

export class SoundError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SoundError';
  }
}

function toArrayBuffer(input) {
  if (input instanceof ArrayBuffer) return input;
  if (ArrayBuffer.isView(input)) return input.buffer.slice(input.byteOffset, input.byteOffset + input.byteLength);
  throw new SoundError(t('wav.notAudio'));
}

/** Old Safari has only the callback form of decodeAudioData and startRendering, new browsers the promise one. */
function decodeWith(Ctx, rate, buffer) {
  return new Promise((resolve, reject) => {
    let done = false;
    const ok = (b) => {
      if (!done) {
        done = true;
        resolve(b);
      }
    };
    const fail = (e) => {
      if (!done) {
        done = true;
        reject(e || new Error('decode failed'));
      }
    };
    try {
      const ctx = new Ctx(1, 1, rate);
      // decodeAudioData takes the buffer over (detaches it), so every attempt gets its own copy
      const p = ctx.decodeAudioData(buffer.slice(0), ok, fail);
      if (p && typeof p.then === 'function') p.then(ok, fail);
    } catch (e) {
      fail(e);
    }
  });
}

async function renderMono(Ctx, decoded, frames) {
  const ctx = new Ctx(1, frames, RATE); // throws in browsers that cannot render at 16 kHz
  const src = ctx.createBufferSource();
  src.buffer = decoded;
  src.connect(ctx.destination);
  src.start(0);
  const rendered = await new Promise((resolve, reject) => {
    ctx.oncomplete = (e) => resolve(e.renderedBuffer);
    try {
      const p = ctx.startRendering();
      if (p && typeof p.then === 'function') p.then(resolve, reject);
    } catch (e) {
      reject(e);
    }
  });
  return Float32Array.from(rendered.getChannelData(0).subarray(0, frames));
}

/**
 * Any audio file the browser can decode (MP3, M4A, WAV...) as a WAV for the board.
 * Returns {wav: Uint8Array, ms, seconds: original length, trimmed: true when cut to 15 s}.
 */
export async function convertToWav(input, env = globalThis) {
  const buffer = toArrayBuffer(input);
  if (buffer.byteLength === 0) throw new SoundError(t('wav.empty'));
  if (buffer.byteLength > MAX_INPUT_BYTES) throw new SoundError(t('wav.tooBig'));
  const Ctx = env.OfflineAudioContext || env.webkitOfflineAudioContext;
  if (typeof Ctx !== 'function') throw new SoundError(t('wav.noAudio'));

  let decoded;
  try {
    decoded = await decodeWith(Ctx, RATE, buffer);
  } catch {
    try {
      decoded = await decodeWith(Ctx, 44100, buffer); // older Safari refuses contexts below 22050 Hz
    } catch {
      throw new SoundError(t('wav.undecodable'));
    }
  }
  const seconds = decoded.length / decoded.sampleRate;
  const frames = Math.min(MAX_SAMPLES, Math.ceil(Math.min(seconds, MAX_SECONDS) * RATE));
  if (!(frames > 0)) throw new SoundError(t('wav.empty'));

  let mono;
  try {
    mono = await renderMono(Ctx, decoded, frames);
  } catch {
    const channels = [];
    for (let c = 0; c < decoded.numberOfChannels; c++) channels.push(decoded.getChannelData(c));
    mono = resampleLinear(mixToMono(channels), decoded.sampleRate, RATE, frames);
  }
  const wav = encodeWav(normalizePeak(mono));
  return { wav, ms: Math.floor((mono.length * 1000) / RATE), seconds, trimmed: seconds > MAX_SECONDS };
}
