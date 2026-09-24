#!/usr/bin/env python3
"""
Block wordmarks in the Northlatch estate's own letter face.

Every stroke is a square on a 10-unit grid, the same square the estate's moving logos are made of.
FACE is copied from the estate's blocks.js (5 x 9 letters, rows 7-8 hold descenders, one empty column
between letters); `f` and `m` are added in the same hand, after `t` and `n`. The name is set in two
tones the way Xlaunch sets its X and its letters: the first word in the accent, the rest in the word
colour.

    python3 scripts/brand/block_logo.py gridframes 4 public/brand/gridframes

writes logo.svg (on dark), logo-on-light.svg, favicon.svg and app-icon.svg (the two initials).
"""
import sys
from pathlib import Path

FACE = {
    'a': "...........XXX.....X.XXXXX...X.XXXX..........",
    'c': "...........XXX.X...XX....X...X.XXX...........",
    'd': "....X....X.XXXXX...XX...XX...X.XXXX..........",
    'e': "...........XXX.X...XXXXXXX.....XXX...........",
    'f': "..XX..X..XXXXX..X....X....X....X.............",
    'g': "...........XXXXX...XX...X.XXXX....XX...X.XXX.",
    'h': "X....X....X.XX.XX..XX...XX...XX...X..........",
    'i': "..X........XX....X....X....X...XXX...........",
    'k': "X....X....X..X.X.X..XX...X.X..X..X...........",
    'l': ".XX....X....X....X....X....X...XXX...........",
    'm': "..........XX.X.X.X.XX.X.XX.X.XX.X.X..........",
    'n': "..........X.XX.XX..XX...XX...XX...X..........",
    'o': "...........XXX.X...XX...XX...X.XXX...........",
    'p': "..........XXXX.X...XX...XX...XXXXX.X....X....",
    'r': "..........X.XX.XX..XX....X....X..............",
    's': "...........XXXXX.....XXX.....XXXXX...........",
    't': ".X....X...XXXX..X....X....X..X..XX...........",
    'u': "..........X...XX...XX...XX..XX.XX.X..........",
    'w': "..........X...XX...XX.X.XXX.XXX...X..........",
    'x': "..........X...X.X.X...X...X.X.X...X..........",
}
assert all(len(f) == 45 for f in FACE.values())

CELL, PITCH, INSET = 8.4, 10, 0.8


def letter_cells(ch, x0):
    f = FACE[ch]
    return [(x0 + c, r) for r in range(9) for c in range(5) if f[r * 5 + c] == 'X']


def rects(points, pitch=PITCH, cell=CELL, inset=INSET, ox=0.0, oy=0.0):
    return ''.join(f'<rect x="{ox + x * pitch + inset:.2f}" y="{oy + y * pitch + inset:.2f}" width="{cell:.2f}" height="{cell:.2f}"/>' for x, y in points)


def wordmark(name, split, accent, word):
    first = [c for i, ch in enumerate(name[:split]) for c in letter_cells(ch, i * 6)]
    rest = [c for i, ch in enumerate(name[split:], start=split) for c in letter_cells(ch, i * 6)]
    width = (len(name) * 6 - 1) * PITCH
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} 90" role="img" aria-label="{name}"><title>{name}</title>'
            f'<g fill="{accent}">{rects(first)}</g><g fill="{word}">{rects(rest)}</g></svg>')


def icon(name, split, size, radius, accent, word, background):
    # The two initials, one per word of the name, in the name's own two tones. A lone first letter was
    # tried and read as a digit (a block g is a 9).
    pitch = size * 0.068
    cell = pitch * 0.84
    ox, oy = (size - 11 * pitch) / 2, (size - 9 * pitch) / 2
    first, second = letter_cells(name[0], 0), letter_cells(name[split], 6)
    inset = (pitch - cell) / 2
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}"><title>{name}</title>'
            f'<rect width="{size}" height="{size}" rx="{radius}" fill="{background}"/>'
            f'<g fill="{accent}">{rects(first, pitch=pitch, cell=cell, inset=inset, ox=ox, oy=oy)}</g>'
            f'<g fill="{word}">{rects(second, pitch=pitch, cell=cell, inset=inset, ox=ox, oy=oy)}</g></svg>')


def main(name, split, out, accent_dark='#8f63ff', accent_light='#6b16ed', word_dark='#e4ece8', word_light='#0a0f0c', background='#0a0f0c'):
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    for stale in ('mark.svg',):
        (out / stale).unlink(missing_ok=True)
    (out / 'logo.svg').write_text(wordmark(name, split, accent_dark, word_dark))
    (out / 'logo-on-light.svg').write_text(wordmark(name, split, accent_light, word_light))
    (out / 'favicon.svg').write_text(icon(name, split, 64, 14, accent_dark, word_dark, background))
    (out / 'app-icon.svg').write_text(icon(name, split, 1024, 228, accent_dark, word_dark, background))


if __name__ == '__main__':
    main(sys.argv[1], int(sys.argv[2]), sys.argv[3])
