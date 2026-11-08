#!/usr/bin/env python3
"""Run: python3 -I tools/tests/img2rlcd_test.py   (the end-to-end case needs macOS sips)."""
import importlib.util
import os
import shutil
import struct
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.dont_write_bytecode = True  # loading the module must not leave tools/__pycache__ behind
spec =importlib.util.spec_from_file_location('img2rlcd', os.path.join(HERE, '..', 'img2rlcd.py'))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
N = m.W * m.H
fails = 0


def check(name, ok):
    global fails
    print(('  ok   ' if ok else '  FAIL ') + name)
    fails += 0 if ok else 1


def bmp24(w, h, pixel):
    """Bottom-up 24-bit BMP; pixel(x, y) -> (r, g, b) with y = 0 at the top."""
    pad = b'\0' * ((w * 3 + 3) // 4 * 4 - w * 3)
    rows = b''.join(b''.join(bytes(pixel(x, y)[::-1]) for x in range(w)) + pad for y in range(h - 1, -1, -1))
    return (struct.pack('<2sIHHI', b'BM', 54 + len(rows), 0, 0, 54)
            + struct.pack('<IiiHHIIiiII', 40, w, h, 1, 24, 0, len(rows), 2835, 2835, 0, 0) + rows)


# byte layout the firmware draws: MSB = leftmost pixel, bit 1 = black
canvas = [255] * N
canvas[0] = canvas[N - 1] = 0
packed = m.pack(m.threshold(canvas))
check('wallpaper is 15000 bytes', len(packed) == 15000)
check('top-left pixel is the top bit of byte 0', packed[0] == 0x80)
check('bottom-right pixel is the low bit of the last byte, nothing else set', packed[-1] == 0x01 and sum(packed) == 0x81)

# BMP reading and fitting
w, h, gray = m.read_bmp(bmp24(3, 2, lambda x, y: (0, 0, 0) if (x, y) == (0, 0) else (255, 255, 255)))
check('bottom-up BMP comes out top row first', (w, h) == (3, 2) and gray == [0, 255, 255, 255, 255, 255])
check('pure red, green, blue map to luma', m.read_bmp(bmp24(3, 1, lambda x, y: [(255, 0, 0), (0, 255, 0), (0, 0, 255)][x]))[2] == [76, 150, 29])
check('cover size of a 4:3 photo', m.scaled_size(4000, 3000, 'cover') == (400, 300))
check('cover size of a tall image', m.scaled_size(1000, 2000, 'cover') == (400, 800))
check('contain size of a tall image', m.scaled_size(1000, 2000, 'contain') == (150, 300))
p = m.place(150, 300, [0] * (150 * 300))
check('contain centres the image on white', p[124] == 255 and p[125] == 0 and p[274] == 0 and p[275] == 255)
check('cover crops the middle', set(m.place(400, 800, [0] * 400 * 250 + [128] * 400 * 300 + [255] * 400 * 250)) == {128})

# dithering
share = sum(m.floyd_steinberg([128] * N)) / N
check('mid grey dithers to about half black', 0.45 < share < 0.55)
check('black and white stay solid', sum(m.floyd_steinberg([0] * N)) == N and sum(m.floyd_steinberg([255] * N)) == 0)

# preview is a valid 1-bit BMP with the same pixels
pv = m.preview_bmp(packed)
check('preview BMP size and first pixel', len(pv) == 62 + 52 * 300 and pv[62 + 52 * 299] == 0x80)

if shutil.which('sips'):
    with tempfile.TemporaryDirectory() as tmp:
        src, png = os.path.join(tmp, 'half.bmp'), os.path.join(tmp, 'half.png')
        with open(src, 'wb') as f:
            f.write(bmp24(800, 600, lambda x, y: (0, 0, 0) if x < 400 else (255, 255, 255)))
        subprocess.run(['sips', '-s', 'format', 'png', src, '--out', png], capture_output=True, check=True)
        out = m.convert(png)
        solid = all(out[r * 50:r * 50 + 23] == b'\xff' * 23 and out[r * 50 + 27:r * 50 + 50] == b'\0' * 23 for r in range(300))
        check('end to end via sips: left half black, right half white', len(out) == 15000 and solid)

        # Phone photos keep their pixels sideways plus a rotation tag. sips -g reports the stored size,
        # while the converted pixels come out upright, so the scale must come from the upright copy.
        side, turned = os.path.join(tmp, 'side.jpg'), os.path.join(tmp, 'turned.jpg')
        with open(src, 'wb') as f:
            f.write(bmp24(400, 200, lambda x, y: (0, 0, 0) if x < 100 else (255, 255, 255)))  # left quarter black
        subprocess.run(['sips', '-s', 'format', 'jpeg', src, '--out', side], capture_output=True, check=True)
        with open(side, 'rb') as f:
            data = f.read()
        tiff = b'MM\0\x2a' + struct.pack('>IH', 8, 1) + struct.pack('>HHIHH', 0x0112, 3, 1, 6, 0) + struct.pack('>I', 0)
        exif = b'\xff\xe1' + struct.pack('>H', 8 + len(tiff)) + b'Exif\0\0' + tiff  # Orientation 6: show turned 90 degrees clockwise
        at = 4 + struct.unpack('>H', data[4:6])[0] if data[2:4] == b'\xff\xe0' else 2  # after the JFIF segment
        with open(turned, 'wb') as f:
            f.write(data[:at] + exif + data[at:])
        out = m.convert(turned, 'contain')  # upright 200x400, black top quarter -> 150x300 at x 125-274, black above y 75
        band = all(out[r * 50 + 17:r * 50 + 33] == b'\xff' * 16 for r in range(5, 66))
        below = all(out[r * 50:r * 50 + 50] == bytes(50) for r in range(90, 300))
        sides = all(out[r * 50:r * 50 + 15] == bytes(15) and out[r * 50 + 35:r * 50 + 50] == bytes(15) for r in range(300))
        check('rotation-tagged photo comes out upright with the right proportions', band and below and sides)

        junk = os.path.join(tmp, 'notes.txt')
        with open(junk, 'w') as f:
            f.write('not a picture\n')
        try:
            m.convert(junk)
            check('a file that is not an image fails with a clear message', False)
        except m.Fail as e:
            check('a file that is not an image fails with a clear message', 'not an image' in str(e))
else:
    print('  skip end to end (no sips)')

print('ALL PASS' if fails == 0 else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
