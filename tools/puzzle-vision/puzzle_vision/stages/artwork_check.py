"""`artwork` 1.1 checks on CPU (no model): is each side of a crop a real picture edge?

v1.0 snapped the VLM box to the largest OpenCV quadrilateral whenever their IoU passed 0.75. On assembled photos the
quad often locked onto lines inside the picture or the table, and the perspective warp then cut triangles off the
artwork (pipeline-driver.md, finding 1). A side of an outline is believed only when, along most of its length:
- the colour steps right on the line (a real edge, not texture somewhere near it), and
- what lies just outside does not look like what lies just inside (the picture does not continue across it),
or the side lies on the photo's own border (nothing beyond it to keep).

All measures run on the <= 1024 px working image in Lab; outlines are TL, TR, BR, BL fractions of the upright image.
"""

from __future__ import annotations

import math
from typing import Any, Sequence

import cv2
import numpy as np

SIDES = ("top", "right", "bottom", "left")
SAMPLES = 48            # positions along a side (the outer 8 % at each end left out: corners are rounded / shadowed)
END_SKIP = 0.08
LINE_TOL = 2            # px either side of the line where the step may sit
STEP = 2                # colour step measured between pixels 2*STEP apart across the line
MIN_STEP_DE = 9.0       # delta E that counts as a colour step at all
STEP_OVER_TYPICAL = 2.2  # ... and it must stand out of the profile's typical step this much
PHOTO_EDGE = 0.012      # a side within this share of the image border is the photo's own edge
STRIP = 0.035           # inside / outside strips this share of the outline's size across the side
SEGMENTS = 6


def lab_image(rgb: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(rgb.astype(np.float32) / 255.0, cv2.COLOR_RGB2LAB)


def _sample(lab: np.ndarray, pts: np.ndarray) -> np.ndarray:
    """Bilinear Lab values at float pixel points (N, 2) -> (N, 3); points outside are clamped."""
    h, w = lab.shape[:2]
    x = np.clip(pts[..., 0], 0, w - 1.001)
    y = np.clip(pts[..., 1], 0, h - 1.001)
    x0, y0 = np.floor(x).astype(int), np.floor(y).astype(int)
    fx, fy = (x - x0)[..., None], (y - y0)[..., None]
    a, b = lab[y0, x0], lab[y0, x0 + 1]
    c, d = lab[y0 + 1, x0], lab[y0 + 1, x0 + 1]
    return (a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy


def _inside(p: np.ndarray, w: int, h: int) -> np.ndarray:
    return (p[..., 0] >= 0) & (p[..., 0] <= w - 1) & (p[..., 1] >= 0) & (p[..., 1] <= h - 1)


PALETTE_BIN = (5.0, 6.0, 6.0)  # Lab bin sizes of the artwork palette
PALETTE_MIN = 0.002            # a bin holding at least this share of the inside pixels is "a colour of the picture"


def _bins(px: np.ndarray) -> np.ndarray:
    q = np.stack([px[..., 0] / PALETTE_BIN[0], (px[..., 1] + 128) / PALETTE_BIN[1], (px[..., 2] + 128) / PALETTE_BIN[2]], -1)
    return np.floor(q).astype(np.int32)


def palette(lab: np.ndarray, quad_px: np.ndarray) -> set[tuple[int, int, int]]:
    """The colours of the picture: Lab bins holding >= PALETTE_MIN of the pixels inside the outline (plus neighbours)."""
    h, w = lab.shape[:2]
    mask = np.zeros((h, w), np.uint8)
    cv2.fillPoly(mask, [np.round(quad_px).astype(np.int32)], 1)
    px = lab[mask.astype(bool)]
    if len(px) > 60000:
        px = px[:: len(px) // 60000]
    b = _bins(px)
    keys, counts = np.unique(b, axis=0, return_counts=True)
    common = keys[counts >= PALETTE_MIN * len(px)]
    out: set[tuple[int, int, int]] = set()
    for k in common:
        for dl in (-1, 0, 1):
            for da in (-1, 0, 1):
                for db in (-1, 0, 1):
                    out.add((int(k[0] + dl), int(k[1] + da), int(k[2] + db)))
    return out


def palette_share(px: np.ndarray, pal: set[tuple[int, int, int]]) -> float:
    if len(px) == 0:
        return 0.0
    b = _bins(px.reshape(-1, 3))
    return float(np.mean([tuple(v) in pal for v in b.tolist()]))


HIST_BINS = (6, 6, 6)
HIST_RANGE = ((0.0, 100.0), (-64.0, 64.0), (-64.0, 64.0))


def colour_hist(px: np.ndarray) -> np.ndarray:
    px = px.reshape(-1, 3)
    h, _ = np.histogramdd(px, bins=HIST_BINS, range=HIST_RANGE)
    h = h.ravel()
    return h / max(1.0, h.sum())


def similarity(a: np.ndarray, b: np.ndarray) -> float:
    """Histogram intersection (0 = no colour in common, 1 = the same colours in the same amounts)."""
    if a.size == 0 or b.size == 0:
        return 0.0
    return float(np.minimum(colour_hist(a), colour_hist(b)).sum())


def side_measures(lab: np.ndarray, quad: Sequence[Sequence[float]]) -> list[dict[str, Any]]:
    """Per side (top, right, bottom, left): `support` = share of positions with a real colour step on the line,
    `continues` = share of segments where the outside strip looks like the inside strip, `outsideInPalette` = share
    of the outside strip's pixels in a colour of the picture, `photoEdge`, `length`."""
    h, w = lab.shape[:2]
    q = np.asarray(quad, float) * [w, h]
    pal = palette(lab, q)
    centre = q.mean(0)
    width = (np.linalg.norm(q[1] - q[0]) + np.linalg.norm(q[2] - q[3])) / 2
    height = (np.linalg.norm(q[3] - q[0]) + np.linalg.norm(q[2] - q[1])) / 2
    out = []
    for k, name in enumerate(SIDES):
        a, b = q[k], q[(k + 1) % 4]
        d = b - a
        length = float(np.linalg.norm(d))
        if length < 8:
            out.append({"side": name, "support": 0.0, "continues": 1.0, "photoEdge": False, "length": 0.0})
            continue
        t = d / length
        n = np.array([t[1], -t[0]])
        if np.dot(n, (a + b) / 2 - centre) < 0:
            n = -n  # outward
        rel = np.asarray(quad, float)[[k, (k + 1) % 4]]
        border = {"top": rel[:, 1].max() <= PHOTO_EDGE, "bottom": rel[:, 1].min() >= 1 - PHOTO_EDGE,
                  "left": rel[:, 0].max() <= PHOTO_EDGE, "right": rel[:, 0].min() >= 1 - PHOTO_EDGE}[name]
        across = height if name in ("top", "bottom") else width
        ts = np.linspace(END_SKIP, 1 - END_SKIP, SAMPLES)
        base = a[None, :] + d[None, :] * ts[:, None]                       # (S, 2)
        span = max(8, int(round(0.03 * across)))
        offs = np.arange(-span, span + 1)                                  # across the line, outward positive
        prof_pts = base[:, None, :] + n[None, None, :] * offs[None, :, None]  # (S, O, 2)
        vals = _sample(lab, prof_pts)                                      # (S, O, 3)
        valid = _inside(prof_pts, w, h)
        steps = np.zeros(vals.shape[:2], np.float32)
        steps[:, STEP:-STEP] = np.linalg.norm(vals[:, 2 * STEP:] - vals[:, :-2 * STEP], axis=2)
        centre_idx = span
        on_line = steps[:, centre_idx - LINE_TOL:centre_idx + LINE_TOL + 1].max(axis=1)
        typical = np.median(steps[:, STEP:-STEP], axis=1)
        line_ok = valid[:, centre_idx]
        support = (on_line >= MIN_STEP_DE) & (on_line >= STEP_OVER_TYPICAL * (typical + 1.0)) & line_ok
        # inside vs outside strips, per segment: mean colour and texture (mean step) must differ
        sw = max(4, int(round(STRIP * across)))
        ins = base[:, None, :] - n[None, None, :] * np.arange(3, 3 + sw)[None, :, None]
        outs = base[:, None, :] + n[None, None, :] * np.arange(3, 3 + sw)[None, :, None]
        vin, vout = _sample(lab, ins), _sample(lab, outs)
        ok_out = _inside(outs, w, h)
        cont = []
        sims: list[float] = []
        seg = np.array_split(np.arange(SAMPLES), SEGMENTS)
        for idx in seg:
            mo = ok_out[idx]
            if mo.mean() < 0.5:
                continue  # the outside is mostly beyond the photo
            ci = vin[idx].reshape(-1, 3)
            co = vout[idx][mo]
            de = float(np.linalg.norm(ci.mean(0) - co.mean(0)))
            ti = float(np.abs(np.diff(vin[idx], axis=1)).mean())
            to = float(np.abs(np.diff(vout[idx], axis=1)).mean()) if vout[idx].shape[1] > 1 else 0.0
            ratio = (to + 0.3) / (ti + 0.3)
            cont.append(de < 8.0 and 0.6 <= ratio <= 1.6)
            sims.append(similarity(vin[idx][mo], co))
        outside_px = vout[ok_out]
        out.append({
            "side": name,
            "support": round(float(support.mean()), 3),
            "continues": round(float(np.mean(cont)), 3) if cont else 0.0,
            "outsideInPalette": round(palette_share(outside_px, pal), 3) if len(outside_px) > 20 else None,
            "insideInPalette": round(palette_share(vin.reshape(-1, 3), pal), 3),
            "similarity": round(float(np.median(sims)), 3) if sims else None,
            "photoEdge": bool(border),
            "length": round(length / max(w, h), 4),
        })
    return out


def quad_angles(quad: Sequence[Sequence[float]], source: tuple[int, int]) -> dict[str, float]:
    """Worst corner angle off 90 deg and the opposite-side ratio (front vs angled from the geometry)."""
    q = np.asarray(quad, float) * [source[0], source[1]]
    worst = 0.0
    for k in range(4):
        v1, v2 = q[k - 1] - q[k], q[(k + 1) % 4] - q[k]
        c = float(np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-9))
        worst = max(worst, abs(math.degrees(math.acos(max(-1.0, min(1.0, c)))) - 90.0))
    sides = [float(np.linalg.norm(q[(k + 1) % 4] - q[k])) for k in range(4)]
    ratio = min(min(sides[0], sides[2]) / max(sides[0], sides[2], 1e-9), min(sides[1], sides[3]) / max(sides[1], sides[3], 1e-9))
    return {"cornerDeg": round(worst, 2), "sideRatio": round(ratio, 4)}


def box_quad(box: Sequence[float]) -> list[list[float]]:
    x0, y0, x1, y1 = box
    return [[x0, y0], [x1, y0], [x1, y1], [x0, y1]]
