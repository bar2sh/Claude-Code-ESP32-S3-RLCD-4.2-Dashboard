// Image to board wallpaper: 400x300, 1 bit per pixel, rows from the top, 50 bytes per row,
// the most significant bit is the leftmost pixel, bit 1 = black (docs/SPEC.md, "Format tapety").
// The geometry, the grey formula, the dithering order and the bit packing follow tools/img2rlcd.py,
// so the same 400x300 grey canvas gives the same 15000 bytes. Pure functions only; canvas code lives in app.js.

export const W = 400;
export const H = 300;
export const ROW = W / 8;
export const BYTES = ROW * H; // 15000

/** Python's round(): halves go to the even neighbour. */
export function pyRound(x) {
  const f = Math.floor(x);
  const d = x - f;
  if (d > 0.5) return f + 1;
  if (d < 0.5) return f;
  return f % 2 === 0 ? f : f + 1;
}

/**
 * Where the image lands, as img2rlcd.py scaled_size() and place() compute it: the scaled size (nw, nh),
 * the first visible scaled pixel (sx, sy), its spot on the screen (dx, dy) and the visible size (cw, ch).
 */
export function layout(w, h, fit = 'cover') {
  const scale = fit === 'cover' ? Math.max(W / w, H / h) : Math.min(W / w, H / h);
  let nw = Math.max(1, pyRound(w * scale));
  let nh = Math.max(1, pyRound(h * scale));
  if (fit === 'cover') {
    nw = Math.max(nw, W);
    nh = Math.max(nh, H);
  } else {
    nw = Math.min(nw, W);
    nh = Math.min(nh, H);
  }
  return {
    nw,
    nh,
    sx: Math.max(0, Math.floor((nw - W) / 2)),
    sy: Math.max(0, Math.floor((nh - H) / 2)),
    dx: Math.max(0, Math.floor((W - nw) / 2)),
    dy: Math.max(0, Math.floor((H - nh) / 2)),
    cw: Math.min(nw, W),
    ch: Math.min(nh, H),
  };
}

/** Integer luma of img2rlcd.py read_bmp(): 0 black .. 255 white. */
export function luma(r, g, b) {
  return Math.floor((299 * r + 587 * g + 114 * b + 500) / 1000);
}

/** A grey value laid over white by its alpha, the integer formula of read_bmp(). */
export function overWhite(v, a) {
  return Math.floor((v * a + 255 * (255 - a) + 127) / 255);
}

/** -50..+50 percent of full scale, as a whole grey step (50 % is 128). */
export function brightnessShift(brightness) {
  const b = Number.isFinite(brightness) ? Math.max(-50, Math.min(50, brightness)) : 0;
  return Math.sign(b) * Math.round((Math.abs(b) * 255) / 100); // not * 2.55: that float makes 50 % only 127
}

/** Grey per source pixel: brightness first, then alpha over white, so transparency stays white. */
function greyOf({ width, height, data }, shift) {
  const out = new Float64Array(width * height);
  for (let i = 0, p = 0; i < out.length; i++, p += 4) {
    const v = Math.max(0, Math.min(255, luma(data[p], data[p + 1], data[p + 2]) + shift));
    out[i] = overWhite(v, data[p + 3]);
  }
  return out;
}

/**
 * Box-filter weights for scaled pixels first..first+count-1 when srcLen pixels stretch to dstLen:
 * each target pixel averages the source pixels it covers, in proportion to the covered part.
 */
function axisWeights(srcLen, dstLen, first, count) {
  const ratio = srcLen / dstLen;
  const rows = [];
  for (let u = first; u < first + count; u++) {
    const lo = u * ratio;
    const hi = Math.min(srcLen, (u + 1) * ratio);
    const taps = [];
    for (let k = Math.floor(lo); k < Math.ceil(hi); k++) {
      const wgt = Math.min(hi, k + 1) - Math.max(lo, k);
      if (wgt > 0) taps.push([k, wgt / (hi - lo)]);
    }
    rows.push(taps);
  }
  return rows;
}

/** The 400x300 grey canvas (integers, white around the image) for an RGBA image of any size. */
export function toCanvas(image, { fit = 'cover', brightness = 0 } = {}) {
  const { width, height, data } = image;
  if (!(width >= 1 && height >= 1 && Number.isInteger(width) && Number.isInteger(height))) {
    throw new RangeError('image needs a positive whole width and height');
  }
  if (!data || data.length < width * height * 4) throw new RangeError('image data is shorter than width x height x 4');
  const L = layout(width, height, fit);
  const grey = greyOf(image, brightnessShift(brightness));
  const xs = axisWeights(width, L.nw, L.sx, L.cw);
  const ys = axisWeights(height, L.nh, L.sy, L.ch);

  // horizontal pass over the source rows the visible part needs, then the vertical pass
  const rowCache = new Map();
  const rowOf = (k) => {
    let row = rowCache.get(k);
    if (!row) {
      row = new Float64Array(L.cw);
      const base = k * width;
      for (let x = 0; x < L.cw; x++) {
        let s = 0;
        for (const [i, wgt] of xs[x]) s += grey[base + i] * wgt;
        row[x] = s;
      }
      rowCache.set(k, row);
    }
    return row;
  };

  const canvas = new Uint8Array(W * H).fill(255);
  for (let y = 0; y < L.ch; y++) {
    const acc = new Float64Array(L.cw);
    for (const [k, wgt] of ys[y]) {
      const row = rowOf(k);
      for (let x = 0; x < L.cw; x++) acc[x] += row[x] * wgt;
    }
    const dst = (L.dy + y) * W + L.dx;
    for (let x = 0; x < L.cw; x++) canvas[dst + x] = Math.max(0, Math.min(255, Math.round(acc[x])));
    // rows above the current window are no longer needed
    for (const k of rowCache.keys()) {
      if (ys[y].length && k < ys[y][0][0]) rowCache.delete(k);
    }
  }
  return canvas;
}

/** Floyd-Steinberg in serpentine order, operation for operation as img2rlcd.py. 1 = black. */
export function ditherFS(grey) {
  const buf = Float64Array.from(grey);
  const bits = new Uint8Array(W * H);
  for (let y = 0; y < H; y++) {
    const step = y & 1 ? -1 : 1;
    for (let k = 0; k < W; k++) {
      const x = step < 0 ? W - 1 - k : k;
      const i = y * W + x;
      const old = buf[i];
      let err;
      if (old < 128) {
        bits[i] = 1;
        err = old;
      } else {
        err = old - 255;
      }
      const ahead = x + step >= 0 && x + step < W;
      if (ahead) buf[i + step] += err * 0.4375;
      if (y + 1 < H) {
        const j = i + W;
        if (x - step >= 0 && x - step < W) buf[j - step] += err * 0.1875;
        buf[j] += err * 0.3125;
        if (ahead) buf[j + step] += err * 0.0625;
      }
    }
  }
  return bits;
}

/** Plain 50% threshold: below 128 is black. */
export function thresholdBits(grey) {
  const bits = new Uint8Array(W * H);
  for (let i = 0; i < bits.length; i++) bits[i] = grey[i] < 128 ? 1 : 0;
  return bits;
}

/** Eight pixels per byte, the leftmost in the most significant bit. */
export function packBits(bits) {
  const out = new Uint8Array(BYTES);
  for (let i = 0; i < BYTES; i++) {
    let b = 0;
    for (let k = 0; k < 8; k++) b = (b << 1) | (bits[8 * i + k] ? 1 : 0);
    out[i] = b;
  }
  return out;
}

/** RGBA image ({width, height, data}) to the 15000 wallpaper bytes. */
export function rgbaToWallpaper(image, { fit = 'cover', dither = true, brightness = 0 } = {}) {
  const canvas = toCanvas(image, { fit, brightness });
  return packBits(dither ? ditherFS(canvas) : thresholdBits(canvas));
}

/** The wallpaper as RGBA pixels for a 400x300 canvas: black and white, fully opaque. */
export function wallpaperToRgba(bytes) {
  if (!bytes || bytes.length !== BYTES) throw new RangeError(`wallpaper must be ${BYTES} bytes`);
  const out = new Uint8ClampedArray(W * H * 4);
  for (let i = 0; i < W * H; i++) {
    const v = bytes[i >> 3] & (0x80 >> (i & 7)) ? 0 : 255;
    out[4 * i] = v;
    out[4 * i + 1] = v;
    out[4 * i + 2] = v;
    out[4 * i + 3] = 255;
  }
  return out;
}
