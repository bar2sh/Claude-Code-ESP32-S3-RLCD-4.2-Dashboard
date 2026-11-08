#!/usr/bin/env python3
"""Turns a photo or drawing into a wallpaper for the RLCD board: 400x300, 1 bit per pixel, 15000 bytes.

Byte layout (what firmware/main/views.c draws): rows top to bottom, 50 bytes per row,
the most significant bit is the leftmost pixel, bit 1 = black.
Needs only the macOS `sips` tool and the Python standard library, so nothing has to be installed.

    python3 -I tools/img2rlcd.py photo.jpg wall.bin --preview wall.bmp
    curl -fsS -H @"$HOME/.config/rlcd/user-header" -H 'Content-Type: application/octet-stream' \
        --data-binary @wall.bin "$RLCD_URL?a=wallpaper"
"""
import argparse
import os
import struct
import subprocess
import sys
import tempfile

W, H = 400, 300
ROW = W // 8
SIZE = ROW * H  # 15000


class Fail(Exception):
    pass


def sips(*args):
    try:
        return subprocess.run(['sips', *args], capture_output=True, text=True, check=True).stdout
    except FileNotFoundError:
        raise Fail('needs the macOS sips tool') from None
    except subprocess.CalledProcessError as e:
        raise Fail('sips failed: ' + (e.stderr or e.stdout).strip()) from None


def upright_bmp(path, out):
    """Decodes any image sips reads into a BMP turned the way it is meant to be seen. Returns its size.

    Phone photos store their pixels sideways plus a rotation tag. `sips -g pixelWidth` reports the
    stored size, but the conversion applies the tag, so the real proportions come from this copy.
    It is capped at 1600 px, which is plenty for a 400x300 screen.
    """
    if not os.path.isfile(path):
        raise Fail(f'{path}: no such file')
    try:
        sips('-s', 'format', 'bmp', '--resampleHeightWidthMax', '1600', path, '--out', out)
    except Fail as e:
        raise Fail(f'{path} is not an image sips can read ({e})') from None
    head = b''
    if os.path.isfile(out):
        with open(out, 'rb') as f:
            head = f.read(26)
    if len(head) < 26 or head[:2] != b'BM':
        raise Fail(f'{path} is not an image sips can read')
    w, h = struct.unpack_from('<ii', head, 18)
    return w, abs(h)


def scaled_size(w, h, fit):
    """'cover' fills 400x300 and crops the overflow; 'contain' keeps the whole image and pads it white."""
    scale = (max if fit == 'cover' else min)(W / w, H / h)
    nw, nh = max(1, round(w * scale)), max(1, round(h * scale))
    if fit == 'cover':
        return max(nw, W), max(nh, H)
    return min(nw, W), min(nh, H)


def _byte_of(mask):
    for k in range(4):
        if mask == 0xFF << (8 * k):
            return k
    return None


def read_bmp(data):
    """Grayscale pixels (0 black .. 255 white, top row first) of an uncompressed 8, 24 or 32-bit BMP."""
    if len(data) < 54 or data[:2] != b'BM':
        raise Fail('not a BMP file')
    offset, = struct.unpack_from('<I', data, 10)
    hsize, w, h, _, bpp, comp = struct.unpack_from('<IiiHHI', data, 14)
    top_down, h = h < 0, abs(h)
    stride = (w * bpp + 31) // 32 * 4
    if w <= 0 or h == 0 or offset + stride * h > len(data):
        raise Fail('empty or truncated BMP')
    lut = ri = gi = bi = ai = None
    if bpp == 8 and comp == 0:
        colors = struct.unpack_from('<I', data, 46)[0] or 256
        pal = data[14 + hsize:14 + hsize + 4 * colors]
        lut = [(299 * pal[i + 2] + 587 * pal[i + 1] + 114 * pal[i] + 500) // 1000 for i in range(0, len(pal) - 3, 4)]
        lut += [0] * (256 - len(lut))
    elif bpp == 24 and comp == 0:
        pass
    elif bpp == 32 and comp == 0:
        ri, gi, bi, ai = 2, 1, 0, 3
    elif bpp == 32 and comp == 3:
        rm, gm, bm = struct.unpack_from('<III', data, 54)
        am = struct.unpack_from('<I', data, 66)[0] if hsize >= 56 else 0
        ri, gi, bi = _byte_of(rm), _byte_of(gm), _byte_of(bm)
        ai = _byte_of(am) if am else None
        if None in (ri, gi, bi):
            raise Fail('unsupported BMP colour masks')
    else:
        raise Fail(f'unsupported BMP ({bpp}-bit, compression {comp})')
    gray, alpha = [], []
    for y in range(h):
        start = offset + (y if top_down else h - 1 - y) * stride
        px = data[start:start + stride]
        if lut is not None:
            gray.extend(lut[i] for i in px[:w])
        elif bpp == 24:
            n = 3 * w
            gray.extend((299 * r + 587 * g + 114 * b + 500) // 1000
                        for b, g, r in zip(px[0:n:3], px[1:n:3], px[2:n:3]))
        else:
            n = 4 * w
            gray.extend((299 * r + 587 * g + 114 * b + 500) // 1000
                        for r, g, b in zip(px[ri:n:4], px[gi:n:4], px[bi:n:4]))
            if ai is not None:
                alpha.extend(px[ai:n:4])
    if alpha and max(alpha) > 0:  # all-zero alpha means the writer did not use it
        gray = [(v * a + 255 * (255 - a) + 127) // 255 for v, a in zip(gray, alpha)]
    return w, h, gray


def place(w, h, gray):
    """Centres the image on a white 400x300 canvas and crops what sticks out."""
    out = [255] * (W * H)
    sx, dx = max(0, (w - W) // 2), max(0, (W - w) // 2)
    sy, dy = max(0, (h - H) // 2), max(0, (H - h) // 2)
    cw, ch = min(w, W), min(h, H)
    for y in range(ch):
        src, dst = (sy + y) * w + sx, (dy + y) * W + dx
        out[dst:dst + cw] = gray[src:src + cw]
    return out


def floyd_steinberg(gray):
    """Error diffusion in serpentine order. Returns 0/1 per pixel, 1 = black."""
    buf = [float(v) for v in gray]
    bits = [0] * (W * H)
    for y in range(H):
        step = -1 if y & 1 else 1
        for x in (range(W - 1, -1, -1) if step < 0 else range(W)):
            i = y * W + x
            old = buf[i]
            if old < 128:
                bits[i] = 1
                err = old
            else:
                err = old - 255
            ahead = 0 <= x + step < W
            if ahead:
                buf[i + step] += err * 0.4375
            if y + 1 < H:
                j = i + W
                if 0 <= x - step < W:
                    buf[j - step] += err * 0.1875
                buf[j] += err * 0.3125
                if ahead:
                    buf[j + step] += err * 0.0625
    return bits


def threshold(gray):
    return [1 if v < 128 else 0 for v in gray]


def pack(bits):
    out = bytearray(SIZE)
    for i in range(SIZE):
        b = 0
        for bit in bits[8 * i:8 * i + 8]:
            b = (b << 1) | bit
        out[i] = b
    return bytes(out)


def preview_bmp(packed):
    """1-bit BMP of the wallpaper (palette 0 = white, 1 = black, so the bits are copied as they are)."""
    rows = b''.join(packed[y * ROW:(y + 1) * ROW] + b'\0\0' for y in range(H - 1, -1, -1))  # bottom-up, 52-byte rows
    palette = bytes((255, 255, 255, 0, 0, 0, 0, 0))
    offset = 14 + 40 + len(palette)
    return (struct.pack('<2sIHHI', b'BM', offset + len(rows), 0, 0, offset)
            + struct.pack('<IiiHHIIiiII', 40, W, H, 1, 1, 0, len(rows), 2835, 2835, 2, 2)
            + palette + rows)


def convert(path, fit='cover', dither=True):
    with tempfile.TemporaryDirectory() as tmp:
        upright, scaled = os.path.join(tmp, 'upright.bmp'), os.path.join(tmp, 'scaled.bmp')
        nw, nh = scaled_size(*upright_bmp(path, upright), fit)
        sips('--resampleHeightWidth', str(nh), str(nw), upright, '--out', scaled)
        with open(scaled, 'rb') as f:
            bw, bh, gray = read_bmp(f.read())
    canvas = place(bw, bh, gray)
    return pack(floyd_steinberg(canvas) if dither else threshold(canvas))


def main(argv=None):
    p = argparse.ArgumentParser(description='Convert an image to a 400x300 1-bit RLCD wallpaper (15000 bytes).')
    p.add_argument('image', help='any format sips reads: JPEG, PNG, HEIC, PDF...')
    p.add_argument('out', help='output .bin file')
    p.add_argument('--fit', choices=('cover', 'contain'), default='cover',
                   help='cover: fill the screen and crop (default); contain: whole image on white')
    p.add_argument('--no-dither', action='store_true', help='plain threshold, better for text and line art')
    p.add_argument('--preview', metavar='BMP', help='also write a BMP preview that Quick Look opens')
    a = p.parse_args(argv)
    try:
        packed = convert(a.image, a.fit, not a.no_dither)
    except Fail as e:
        sys.exit(f'img2rlcd: {e}')
    with open(a.out, 'wb') as f:
        f.write(packed)
    if a.preview:
        with open(a.preview, 'wb') as f:
            f.write(preview_bmp(packed))
    black = sum(bin(b).count('1') for b in packed) * 100 // (W * H)
    print(f'{a.out}: {len(packed)} bytes, {black}% black')


if __name__ == '__main__':
    main()
