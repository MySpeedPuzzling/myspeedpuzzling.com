"""Players' finished-puzzle photos as a second view of the puzzle (docs/features/insights/finished-photos.md).

Why: 85 % of puzzles have < 5 measured first tries, so the picture estimate decides their prediction - and most of
those catalogue pictures are box photos (difficulty_prior 1.1.0 error: box 0.097 vs flat 0.083, data-mining review F9).
A player's photo of the assembled puzzle shows the real print, and sometimes the cut (grid or random) and the finish
(glare). This module is the CPU part of the `finished_photo` analyzer: find the assembled puzzle in the photo, crop and
straighten it, and measure it. The embeddings of the straightened crop (SigLIP 2, DINOv2 - GPU) are added by the stage
(finished_photo_stage.py). Nothing here is ever shown; the photos are never stored by the pipeline beyond its cache.

Finding the puzzle - cheapest first, every step deterministic, CPU only:
1. **register**: SIFT features of the catalogue artwork crop (the puzzle's own picture, cut by the stored `artwork`
   result) matched into the photo (ratio test, RANSAC homography). The artwork's rectangle mapped through the
   homography is the assembled puzzle in the photo - exact edges even when the photo is skewed, a table, timer or hand
   cover parts of it, or the puzzle runs out of the frame. Accepted only with enough inliers and a sane quad
   (REGISTER_* constants). It doubles as the identity check: a photo of something else (a team photo, the box) does
   not register.
2. **quad**: the largest convex quadrilateral of strong edges (the artwork stage's `largest_quad`) whose aspect ratio
   agrees with the artwork's (either way round) - for puzzles whose picture has too little texture to register (a
   gradient, one colour: exactly the hard ones). It has no identity check of its own (a colour histogram was tried:
   58 % of right photos kept at 17 % wrong ones accepted - useless), so the estimate uses a quad-path photo only when
   its SigLIP 2 crop vector agrees with the catalogue crop's (derive/finished_photos.py).
3. **none**: neither - nothing is measured (the payload says why); the stage still stores the row so the photo is not
   tried again for this version.
A VLM is not used: on 1,022 real photos (2026-10-27) 94.5 % registered, 0.5 % took the quad path, 5 % neither (team
photos, the box, a part of the puzzle too small) - ~1 s a photo on one core, most of it SIFT and the piece-scale block.

Measured on the straightened crop (`rectified`, at most RECTIFY_MAX_SIDE px, only the part inside the photo):
- `pieceScale`: stages/piece_scale.py's block for the real piece count (the look-alike pieces / colour piles of the
  real print - box photos blur and glare them);
- `seams`: how regular the cut is - the dark gaps between pieces (black-hat) projected onto each axis, per band of
  rows / columns; the power at the expected piece frequency (cols / rows of the grid for the piece count) over the
  power around it (log ratio). A grid ("ribbon") cut lines the seams up into straight columns and rows -> a strong peak;
  a random cut does not. The same number on the catalogue crop (`artworkGridX/Y`) is the artwork's own periodicity at
  that frequency (a picture of tiles or shelves), so a model can tell the cut from the picture;
- `glare`: share of specular highlights (bright, colourless, much brighter than the surroundings) - the finish
  (glossy vs matt) and the lighting; with registration also where the artwork itself is not white (`vsArtwork`);
- `quality`: sharpness (variance of the Laplacian at 1024 px), pixels per piece, the visible share of the puzzle, and
  how well the photo's colours follow the catalogue picture (`printCorrelation`, registered photos only).
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Any

import cv2
import numpy as np

from .imaging import artwork_crop
from .stages.artwork import largest_quad, order_quad
from .stages.colors_traits import srgb_to_oklab
from .stages.piece_scale import FALLBACK_PIECES, block, grid_shape

ART_SIDE = 800  # the catalogue crop's side for SIFT
PHOTO_SIDE = 1200  # the photo's side for SIFT
SIFT_FEATURES_ART = 3000
SIFT_FEATURES_PHOTO = 6000
RATIO = 0.75
RANSAC_PX = 6.0  # at PHOTO_SIDE
REGISTER_MIN_INLIERS = 25
REGISTER_MIN_INLIER_SHARE = 0.15
QUAD_MAX_ASPECT_ERROR = 0.15  # |ln(quad aspect / artwork aspect)|, either way round
QUAD_MIN_AREA = 0.2
MIN_AREA = 0.05  # the puzzle's share of the photo
MIN_VISIBLE = 0.6
FULL_VISIBLE = 0.97  # piece-scale and seams need the whole puzzle in the frame
RECTIFY_MAX_SIDE = 1600
SHARPNESS_SIDE = 1024
SEAM_BANDS = 4
SEAM_SEARCH = (0.85, 1.18)  # around the expected piece frequency
SEAM_BACKGROUND = (0.5, 2.0)
SEAM_MIN_PERIOD_PX = 6.0


@dataclass
class Located:
    method: str  # register | quad | none
    quad: np.ndarray | None = None  # (4, 2) photo px, TL TR BR BL in the artwork's orientation (register) / photo's (quad)
    inliers: int = 0
    matches: int = 0
    aspect_error: float | None = None
    reason: str | None = None

    @property
    def found(self) -> bool:
        return self.quad is not None


def _fit_side(rgb: np.ndarray, side: int) -> tuple[np.ndarray, float]:
    h, w = rgb.shape[:2]
    s = min(1.0, side / max(h, w))
    if s >= 1.0:
        return rgb, 1.0
    return cv2.resize(rgb, (max(1, round(w * s)), max(1, round(h * s))), interpolation=cv2.INTER_AREA), s


def _gray(rgb: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(np.ascontiguousarray(rgb), cv2.COLOR_RGB2GRAY)


def quad_ok(q: np.ndarray, w: int, h: int) -> tuple[bool, str | None]:
    """A plausible outline of a flat rectangle: convex, no angle under 35°, opposite sides within 2.2×, not tiny."""
    q = np.asarray(q, np.float64)
    if not np.all(np.isfinite(q)):
        return False, "not finite"
    if not cv2.isContourConvex(q.astype(np.float32).reshape(-1, 1, 2)):
        return False, "not convex"
    for k in range(4):
        v1, v2 = q[k - 1] - q[k], q[(k + 1) % 4] - q[k]
        cos = abs(float(np.dot(v1, v2)) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-9))
        if cos > math.cos(math.radians(35)):
            return False, "angle"
    sides = [np.linalg.norm(q[(k + 1) % 4] - q[k]) for k in range(4)]
    if max(sides[0], sides[2]) > 2.2 * min(sides[0], sides[2]) or max(sides[1], sides[3]) > 2.2 * min(sides[1], sides[3]):
        return False, "perspective"
    if cv2.contourArea(q.astype(np.float32)) < MIN_AREA * w * h:
        return False, "too small"
    return True, None


def register(photo: np.ndarray, art: np.ndarray) -> Located:
    """The artwork's rectangle in the photo through a SIFT homography (photo px of `photo`), or `none` with a reason."""
    p_small, ps = _fit_side(photo, PHOTO_SIDE)
    a_small, as_ = _fit_side(art, ART_SIDE)
    sift_a = cv2.SIFT_create(nfeatures=SIFT_FEATURES_ART)
    sift_p = cv2.SIFT_create(nfeatures=SIFT_FEATURES_PHOTO)
    ka, da = sift_a.detectAndCompute(_gray(a_small), None)
    kp, dp = sift_p.detectAndCompute(_gray(p_small), None)
    if da is None or dp is None or len(ka) < 8 or len(kp) < 8:
        return Located("none", reason="too few features")
    pairs = cv2.BFMatcher(cv2.NORM_L2).knnMatch(da, dp, k=2)
    good = [m for m, *rest in pairs if rest and m.distance < RATIO * rest[0].distance]
    if len(good) < REGISTER_MIN_INLIERS:
        return Located("none", matches=len(good), reason="too few matches")
    src = np.float32([ka[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
    dst = np.float32([kp[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)
    H, mask = cv2.findHomography(src, dst, cv2.RANSAC, RANSAC_PX, maxIters=4000, confidence=0.999)
    inliers = int(mask.sum()) if mask is not None else 0
    if H is None or inliers < REGISTER_MIN_INLIERS or inliers < REGISTER_MIN_INLIER_SHARE * len(good):
        return Located("none", inliers=inliers, matches=len(good), reason="too few inliers")
    ah, aw = a_small.shape[:2]
    corners = np.float32([[0, 0], [aw, 0], [aw, ah], [0, ah]]).reshape(-1, 1, 2)
    q = cv2.perspectiveTransform(corners, H).reshape(4, 2) / ps
    ok, why = quad_ok(q, photo.shape[1], photo.shape[0])
    if not ok:
        return Located("none", inliers=inliers, matches=len(good), reason=f"registered quad: {why}")
    return Located("register", q.astype(np.float64), inliers, len(good))


def edge_quad(photo: np.ndarray, art_aspect: float) -> Located:
    """The largest quadrilateral of strong edges whose aspect matches the artwork's (either way round)."""
    small, s = _fit_side(photo, 1024)
    found = largest_quad(small, min_area=QUAD_MIN_AREA, max_area=0.995)
    if found is None:
        return Located("none", reason="no quadrilateral")
    q = order_quad(found[1]).astype(np.float64) / s
    w = (np.linalg.norm(q[1] - q[0]) + np.linalg.norm(q[2] - q[3])) / 2
    h = (np.linalg.norm(q[3] - q[0]) + np.linalg.norm(q[2] - q[1])) / 2
    err = min(abs(math.log((w / h) / art_aspect)), abs(math.log((h / w) / art_aspect)))
    if err > QUAD_MAX_ASPECT_ERROR:
        return Located("none", aspect_error=round(err, 4), reason="quadrilateral of another aspect ratio")
    ok, why = quad_ok(q, photo.shape[1], photo.shape[0])
    if not ok:
        return Located("none", aspect_error=round(err, 4), reason=f"quadrilateral: {why}")
    return Located("quad", q, aspect_error=round(err, 4))


def locate(photo: np.ndarray, art: np.ndarray) -> Located:
    reg = register(photo, art)
    if reg.found:
        return reg
    ah, aw = art.shape[:2]
    quad = edge_quad(photo, aw / ah)
    if quad.found:
        quad.inliers, quad.matches = reg.inliers, reg.matches
        return quad
    return Located("none", inliers=reg.inliers, matches=reg.matches, aspect_error=quad.aspect_error,
                   reason=f"{reg.reason}; {quad.reason}")


def rectify(photo: np.ndarray, loc: Located, art_shape: tuple[int, int]) -> tuple[np.ndarray, np.ndarray, float]:
    """The puzzle straightened: (crop, valid mask, visible share). Registered photos come out in the artwork's
    orientation and aspect (so they line up with the catalogue crop); quad ones in the photo's."""
    assert loc.quad is not None
    q = loc.quad.astype(np.float32)
    if loc.method == "register":
        ah, aw = art_shape
        side_w = (np.linalg.norm(q[1] - q[0]) + np.linalg.norm(q[2] - q[3])) / 2
        side_h = (np.linalg.norm(q[3] - q[0]) + np.linalg.norm(q[2] - q[1])) / 2
        scale = math.sqrt(max(side_w * side_h, 1.0) / (aw * ah))
        tw, th = aw * scale, ah * scale
    else:
        tw = (np.linalg.norm(q[1] - q[0]) + np.linalg.norm(q[2] - q[3])) / 2
        th = (np.linalg.norm(q[3] - q[0]) + np.linalg.norm(q[2] - q[1])) / 2
    shrink = min(1.0, RECTIFY_MAX_SIDE / max(tw, th))
    tw, th = max(16, int(round(tw * shrink))), max(16, int(round(th * shrink)))
    dst = np.float32([[0, 0], [tw, 0], [tw, th], [0, th]])
    m = cv2.getPerspectiveTransform(q, dst)
    out = cv2.warpPerspective(photo, m, (tw, th), flags=cv2.INTER_AREA, borderMode=cv2.BORDER_CONSTANT)
    valid = cv2.warpPerspective(np.full(photo.shape[:2], 255, np.uint8), m, (tw, th), flags=cv2.INTER_NEAREST,
                                borderMode=cv2.BORDER_CONSTANT) > 0
    return out, valid, float(valid.mean())


def inner_box(valid: np.ndarray) -> tuple[int, int, int, int]:
    """(x0, y0, x1, y1) of an axis-aligned rectangle with only valid pixels: trims the side with the most invalid
    pixels until none is left (the part of the puzzle outside the photo)."""
    y0, x0 = 0, 0
    y1, x1 = valid.shape
    while y1 - y0 > 16 and x1 - x0 > 16:
        v = valid[y0:y1, x0:x1]
        bad = {"top": (~v[0]).sum(), "bottom": (~v[-1]).sum(), "left": (~v[:, 0]).sum(), "right": (~v[:, -1]).sum()}
        side = max(bad, key=lambda k: bad[k])
        if bad[side] == 0:
            break
        step = max(1, int(0.01 * (y1 - y0 if side in ("top", "bottom") else x1 - x0)))
        if side == "top":
            y0 += step
        elif side == "bottom":
            y1 -= step
        elif side == "left":
            x0 += step
        else:
            x1 -= step
    return x0, y0, x1, y1


# --- measures -------------------------------------------------------------------------------------------------------
def seam_map(rgb: np.ndarray, period: float) -> np.ndarray:
    """Dark thin gaps between pieces: black-hat of the lightness with a kernel of ~1/6 of a piece, normalised."""
    g = _gray(rgb).astype(np.float32) / 255.0
    k = int(max(3, min(15, round(period / 6)))) | 1
    bh = cv2.morphologyEx(g, cv2.MORPH_BLACKHAT, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k)))
    return bh / (float(np.percentile(bh, 99)) + 1e-6)


def periodicity(profiles: np.ndarray, expected: float) -> tuple[float | None, float | None]:
    """log(power at the expected count ± search window / median power around it) of 1-D profiles (bands × length),
    their spectra averaged; and the count with the peak. None when the period would be under SEAM_MIN_PERIOD_PX."""
    n = profiles.shape[1]
    if expected < 2 or n / expected < SEAM_MIN_PERIOD_PX:
        return None, None
    x = profiles - profiles.mean(axis=1, keepdims=True)
    x = x * np.hanning(n)[None, :]
    power = (np.abs(np.fft.rfft(x, axis=1)) ** 2).mean(axis=0)
    freq = np.arange(len(power))  # cycles over the length = pieces along that axis
    lo, hi = expected * SEAM_SEARCH[0], expected * SEAM_SEARCH[1]
    win = (freq >= lo) & (freq <= hi)
    around = (freq >= expected * SEAM_BACKGROUND[0]) & (freq <= min(expected * SEAM_BACKGROUND[1], len(power) - 1)) & ~win
    if not win.any() or around.sum() < 3:
        return None, None
    peak = int(freq[win][np.argmax(power[win])])
    return round(float(np.log((power[win].max() + 1e-12) / (np.median(power[around]) + 1e-12))), 4), peak


def grid_regularity(rgb: np.ndarray, pieces: int) -> dict[str, Any]:
    h, w = rgb.shape[:2]
    cols, rows = grid_shape(pieces, w, h)
    s = seam_map(rgb, min(w / cols, h / rows))
    bands = SEAM_BANDS
    col_profiles = np.stack([s[i * h // bands:(i + 1) * h // bands].mean(axis=0) for i in range(bands)])
    row_profiles = np.stack([s[:, i * w // bands:(i + 1) * w // bands].mean(axis=1) for i in range(bands)])
    gx, px = periodicity(col_profiles, cols)
    gy, py = periodicity(row_profiles, rows)
    return {"cols": int(cols), "rows": int(rows), "gridX": gx, "gridY": gy, "peakCols": px, "peakRows": py}


def glare(rgb: np.ndarray, art: np.ndarray | None = None) -> dict[str, Any]:
    """Specular highlights: lightness > 0.9, chroma < 0.04 and > 0.12 above the blurred surroundings."""
    small, _ = _fit_side(rgb, 800)
    lab = srgb_to_oklab(small)
    L = lab[..., 0]
    C = np.hypot(lab[..., 1], lab[..., 2])
    around = cv2.GaussianBlur(L, (0, 0), max(small.shape[:2]) / 40)
    spec = (L > 0.9) & (C < 0.04) & (L - around > 0.12)
    out: dict[str, Any] = {"share": round(float(spec.mean()), 5)}
    if art is not None:
        art_small = cv2.resize(art, (small.shape[1], small.shape[0]), interpolation=cv2.INTER_AREA)
        art_l = srgb_to_oklab(art_small)[..., 0]
        out["vsArtwork"] = round(float((spec & (art_l < 0.85)).mean()), 5)
    return out


def sharpness(rgb: np.ndarray) -> float:
    small, _ = _fit_side(rgb, SHARPNESS_SIDE)
    return round(float(cv2.Laplacian(_gray(small).astype(np.float32) / 255.0, cv2.CV_32F).var() * 1000), 4)


def print_correlation(rgb: np.ndarray, art: np.ndarray) -> float:
    """Mean per-channel correlation of the blurred photo crop and the catalogue crop at the same size."""
    small, _ = _fit_side(rgb, 512)
    a = cv2.resize(art, (small.shape[1], small.shape[0]), interpolation=cv2.INTER_AREA).astype(np.float32)
    p = small.astype(np.float32)
    a = cv2.GaussianBlur(a, (0, 0), 2)
    p = cv2.GaussianBlur(p, (0, 0), 2)
    cs = [np.corrcoef(a[..., c].ravel(), p[..., c].ravel())[0, 1] for c in range(3)]
    cs = [c for c in cs if np.isfinite(c)]
    return round(float(np.mean(cs)), 4) if cs else 0.0


def _quad_fraction(q: np.ndarray, w: int, h: int) -> list[list[float]]:
    return [[round(float(x) / w, 5), round(float(y) / h, 5)] for x, y in q]


def measure(photo: np.ndarray, catalogue: np.ndarray, artwork: dict[str, Any] | None, pieces: int | None
            ) -> tuple[dict[str, Any], np.ndarray | None]:
    """The CPU payload of one photo (EXIF-oriented RGB, <= 1600 px) and the straightened crop the embeddings are made
    from (None when the puzzle was not found). `catalogue`: the puzzle's catalogue picture (the cached image the
    `artwork` result's fractions refer to), `artwork`: that result."""
    real = int(pieces) if pieces and pieces > 1 else FALLBACK_PIECES
    art, _ = artwork_crop(catalogue, artwork)
    ph, pw = photo.shape[:2]
    loc = locate(photo, art)
    out: dict[str, Any] = {
        "photo": {"width": int(pw), "height": int(ph)},
        "locate": {"method": loc.method, "inliers": loc.inliers, "matches": loc.matches,
                   "aspectError": loc.aspect_error, "reason": loc.reason,
                   "quad": _quad_fraction(loc.quad, pw, ph) if loc.quad is not None else None},
        "pieces": real,
    }
    if loc.quad is None:
        return out, None
    rect, valid, visible = rectify(photo, loc, art.shape[:2])
    x0, y0, x1, y1 = inner_box(valid)
    crop = rect[y0:y1, x0:x1]
    area = float(cv2.contourArea(loc.quad.astype(np.float32))) / (pw * ph)
    full = visible >= FULL_VISIBLE
    registered = loc.method == "register"
    art_aligned = None
    if registered:  # the catalogue crop over the same part of the puzzle as the photo crop
        ah, aw = art.shape[:2]
        rh, rw = rect.shape[:2]
        art_aligned = art[int(y0 / rh * ah):max(int(y0 / rh * ah) + 1, int(y1 / rh * ah)),
                          int(x0 / rw * aw):max(int(x0 / rw * aw) + 1, int(x1 / rw * aw))]
    ch, cw = crop.shape[:2]
    cols, rows = grid_shape(real, cw, ch)
    out["rectified"] = {"width": int(cw), "height": int(ch), "visibleShare": round(visible, 4),
                        "areaShare": round(area, 4), "pixelsPerPiece": round(math.sqrt(cw * ch / real), 2)}
    out["quality"] = {"sharpness": sharpness(crop),
                      "printCorrelation": print_correlation(crop, art_aligned) if art_aligned is not None else None}
    out["glare"] = glare(crop, art_aligned)
    if full:
        seams = grid_regularity(crop, real)
        if art_aligned is not None:
            art_seams = grid_regularity(cv2.resize(art_aligned, (cw, ch), interpolation=cv2.INTER_AREA), real)
            seams |= {"artworkGridX": art_seams["gridX"], "artworkGridY": art_seams["gridY"]}
        out["seams"] = seams
        out["pieceScale"] = block(srgb_to_oklab(np.ascontiguousarray(crop)), real)
    else:
        out["seams"] = None
        out["pieceScale"] = None  # a cell grid of a part of the puzzle would not be the real piece layout
    return out, crop
