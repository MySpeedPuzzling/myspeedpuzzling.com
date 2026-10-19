"""The artwork reference set: hand-marked artwork outlines that every `artwork` version is measured against first.

A truth row (reference/artwork-truth.jsonl, one JSON object per line):
- `puzzleId`, `fingerprint` (the picture the outline belongs to - a new picture makes the row void),
- `kind`: flat | box_front | box_angled | assembled | other,
- `corners`: the artwork's four corners (top-left, top-right, bottom-right, bottom-left), fractions of the upright image -
  the edge of the real picture: everything inside is artwork that must never be cut,
- `outer` (optional): per side (`top`, `right`, `bottom`, `left`) how far beyond `corners` a crop may still reach without
  counting as "box print / table left in" (a white border that belongs to the print, a soft vignette), fraction of the
  artwork's size on that axis; default 0,
- `flags`: e.g. `round`, `shaped`, `no_visible_edge`, `rotated`, `several_pictures`, `small`, `title_band`, `frame`,
- `note`.

The labelling sheet (`sheet`) draws a candidate outline on the image with a 5 % grid plus four corner zooms with a 1 % grid,
so an outline can be checked and moved to about 1 % of the side.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any, Sequence

import numpy as np
from PIL import Image, ImageDraw, ImageFont

ZOOM_HALF = 0.07  # a corner zoom shows ±7 % of the image around the candidate corner
ZOOM_PX = 330
MAIN_PX = 900
SIDES = ("top", "right", "bottom", "left")


def _font(size: int) -> ImageFont.ImageFont:
    for name in ("/System/Library/Fonts/Supplemental/Arial.ttf", "/System/Library/Fonts/Helvetica.ttc"):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def box_corners(x0: float, y0: float, x1: float, y1: float) -> list[list[float]]:
    return [[x0, y0], [x1, y0], [x1, y1], [x0, y1]]


def sheet(image_path: str, corners: Sequence[Sequence[float]], out: str, title: str = "",
          extra: Sequence[Sequence[Sequence[float]]] = ()) -> str:
    """Main view (outline cyan, `extra` outlines orange, 5 % grid ticks) + 4 corner zooms (1 % grid, crosshair)."""
    im = Image.open(image_path).convert("RGB")
    w, h = im.size
    scale = MAIN_PX / max(w, h)
    mw, mh = int(w * scale), int(h * scale)
    main = im.resize((mw, mh), Image.LANCZOS)
    pad = 34
    canvas = Image.new("RGB", (mw + 2 * pad + 2 * ZOOM_PX + 30, max(mh + 2 * pad + 30, 2 * ZOOM_PX + 120)), (235, 235, 235))
    canvas.paste(main, (pad, pad + 30))
    d = ImageDraw.Draw(canvas)
    f, fs = _font(13), _font(11)
    d.text((pad, 6), title[:150], fill=(0, 0, 0), font=_font(15))
    ox, oy = pad, pad + 30
    for k in range(0, 21):
        t = k / 20
        x, y = ox + t * mw, oy + t * mh
        major = k % 2 == 0
        L = 10 if major else 5
        d.line([(x, oy - L), (x, oy)], fill=(0, 0, 0)); d.line([(x, oy + mh), (x, oy + mh + L)], fill=(0, 0, 0))
        d.line([(ox - L, y), (ox, y)], fill=(0, 0, 0)); d.line([(ox + mw, y), (ox + mw + L, y)], fill=(0, 0, 0))
        if major:
            d.text((x - 6, oy - 24), f"{k * 5}", fill=(0, 0, 0), font=fs)
            d.text((ox - 30, y - 6), f"{k * 5}", fill=(0, 0, 0), font=fs)

    def poly(q: Sequence[Sequence[float]], color: tuple[int, int, int], width: int) -> None:
        pts = [(ox + x * mw, oy + y * mh) for x, y in q]
        d.line(pts + [pts[0]], fill=color, width=width)

    for e in extra:
        poly(e, (255, 140, 0), 2)
    poly(corners, (0, 220, 255), 2)
    names = ("TL", "TR", "BR", "BL")
    zx0 = ox + mw + pad
    for i, (cx, cy) in enumerate(corners):
        col, row = i % 2, i // 2
        if i == 2:
            col = 1
        if i == 3:
            col = 0
        zx, zy = zx0 + col * (ZOOM_PX + 10), 40 + row * (ZOOM_PX + 40)
        x0, y0 = cx - ZOOM_HALF, cy - ZOOM_HALF
        # crop with padding for corners near the image edge
        box_px = (int(round(x0 * w)), int(round(y0 * h)), int(round((cx + ZOOM_HALF) * w)), int(round((cy + ZOOM_HALF) * h)))
        region = Image.new("RGB", (box_px[2] - box_px[0], box_px[3] - box_px[1]), (255, 0, 255))
        region.paste(im.crop((max(0, box_px[0]), max(0, box_px[1]), min(w, box_px[2]), min(h, box_px[3]))),
                     (max(0, -box_px[0]), max(0, -box_px[1])))
        z = region.resize((ZOOM_PX, ZOOM_PX), Image.NEAREST if region.size[0] < ZOOM_PX / 3 else Image.LANCZOS)
        zd = ImageDraw.Draw(z)
        step = ZOOM_PX / (2 * ZOOM_HALF * 100)  # one percent of the image
        for k in range(-7, 8):
            p = ZOOM_PX / 2 + k * step
            c = (255, 255, 0) if k == 0 else (255, 255, 255)
            wdt = 1
            if k % 5 == 0 and k != 0:
                c = (255, 80, 80)
            zd.line([(p, 0), (p, ZOOM_PX)], fill=c, width=wdt)
            zd.line([(0, p), (ZOOM_PX, p)], fill=c, width=wdt)
        # the candidate edges through the corner
        for j in ((i - 1) % 4, (i + 1) % 4):
            nx, ny = corners[j]
            ex = ZOOM_PX / 2 + (nx - cx) * 100 * step
            ey = ZOOM_PX / 2 + (ny - cy) * 100 * step
            zd.line([(ZOOM_PX / 2, ZOOM_PX / 2), (ex, ey)], fill=(0, 220, 255), width=2)
        canvas.paste(z, (zx, zy + 18))
        d.text((zx, zy), f"{names[i]} ({cx * 100:.1f}, {cy * 100:.1f}) grid 1 %, red 5 %", fill=(0, 0, 0), font=f)
    canvas.save(out, quality=88)
    return out


def load_items(ref_dir: Path) -> dict[str, dict[str, Any]]:
    return {i["id"]: i for i in json.loads((ref_dir / "items.json").read_text())}


def stored_corners(item: dict[str, Any]) -> list[list[float]]:
    r = item.get("stored") or {}
    if r.get("corners"):
        return [[float(x), float(y)] for x, y in r["corners"]]
    b = r.get("bbox") or {"x": 0, "y": 0, "w": 1, "h": 1}
    return box_corners(b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"])


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="python -m puzzle_vision.artwork_reference")
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("sheet", help="render a labelling sheet")
    s.add_argument("--ref", default=str(Path.home() / "puzzle-vision-review/artwork-reference"))
    s.add_argument("puzzle_id")
    s.add_argument("--corners", help='JSON [[x,y]x4] in 0..1 (default: the stored v1.0 outline)')
    s.add_argument("--out")
    a = p.parse_args(argv)
    ref = Path(a.ref)
    items = load_items(ref)
    item = items.get(a.puzzle_id)
    if item is None:
        print(f"unknown puzzle {a.puzzle_id}", file=sys.stderr)
        return 2
    corners = json.loads(a.corners) if a.corners else stored_corners(item)
    if len(corners) != 4:
        print("corners: four [x, y] pairs", file=sys.stderr)
        return 2
    (ref / "sheets").mkdir(exist_ok=True)
    out = a.out or str(ref / "sheets" / f"{a.puzzle_id}.jpg")
    title = f"{a.puzzle_id[:8]} {item['width']}x{item['height']} (orig {item['originalWidth']}x{item['originalHeight']})"
    print(sheet(item["path"], corners, out, title))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
