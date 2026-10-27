"""`piece_scale` - how ambiguous the artwork is at the size of one piece, on the cached crop (long side <= 1600 px),
numpy / OpenCV / scikit-learn, deterministic, CPU only, no model (image-taxonomy.md §11.1, insights/estimates.md).

Why: `traits` measures piece-level numbers on a 768 px crop (`pieceLevel`), where a 1000-piece cell is ~20 px averaged
to 8×8 - the texture inside a piece is gone. The prediction-model review (2026-10-26, data-mining F2) measured a
prototype of this stage on 10,391 puzzles: the biggest new picture signal (newer-from-older MAE 0.0884 -> 0.0856 as a
residual correction of difficulty_prior 1.1.0; strongest single number `hueEntropy` - few colour piles = harder than
the embeddings think). This module is that prototype (review `data-mining/scripts/a2_piecescale.py`), numbers
unchanged, plus what the review asked to store now (§3): the histogram of nearest-look-alike distances, the 16 pile
shares and three cell counts of the image-model review (§4.1).

The grid matches the real piece layout: `cols = round(sqrt(pieces × w / h))`, `rows = round(pieces / cols)` (at least
2 × 2). Each cell (one piece) is described by its mean OKLab colour and the colour of its four quarters (where in the
piece the colour sits), the OKLab SD, the fine Laplacian texture and a 6-bin gradient orientation histogram; the
nearest other cell in that description is the piece a puzzler could confuse it with. Per block (`real`, `p500`,
`p1000` - the latter two computed once more only when the real count differs):

- `tilePx`: the side of one cell in pixels (resolution adequacy);
- `nnMedian`, `nnP25`: nearest-look-alike distance quantiles; `nnShareBelow003/006/010`: cells with a look-alike closer
  than 0.03 / 0.06 / 0.10; `nnHistogram`: 16 shares of that distance, bins of 0.01 from 0 (the last bin = 0.15 and up);
- `logCandidates006`, `logCandidates010`: mean log(1 + other cells within 0.06 / 0.10) - how many places a piece could
  plausibly go; `logCandidatesColour006`: the same on colour alone;
- `colourNnMedian`, `textureNnMedian`: the nearest look-alike by colour alone / texture alone;
- `lowDetailShare`: cells with almost no fine texture and flat lightness; `fineTextureMedian`, `fineTextureP25`;
- sort piles = k-means on the colour description: `pileHhi16`, `pileEntropy16`, `largestPileShare16`, `pileHhi32`,
  `pileShares16` (the 16 shares, largest first);
- `hueEntropy`: entropy of the cells' hue in 12 sectors + one grey bin;
- `boundaryEdgeShare`: share of the vertical cell borders a strong edge crosses (matching neighbours is easier);
- `uniformShare`: cells of one flat colour (L SD < 0.02, chroma SD < 0.02); `lookalike3Share`: cells with >= 3 near
  identical cells (< 0.03) elsewhere; `largestUniformRegion`: the largest 4-connected area of uniform cells of one
  colour pile, as a share of all cells (a sky, a sea).

A block is null when its cells would be smaller than 8 px (a huge piece count on a small picture).
"""

from __future__ import annotations

import math
from typing import Any

import cv2
import numpy as np

from .colors_traits import _CpuStage, srgb_to_oklab

MIN_TILE_PX = 8
FALLBACK_PIECES = 500  # a puzzle without a piece count: the most common size
EXTRA_COUNTS = (500, 1000)
HIST_STEP = 0.01
HIST_BINS = 16
NEAR = 0.03  # "near identical" cell description distance
UNIFORM_SD = 0.02
CHANNEL_WEIGHTS = np.array([1, 2, 2], np.float32)  # chroma counts double: a puzzler sorts by colour first

# The prototype's names (review data-mining/scripts/a2_piecescale.py NAMES) -> payload keys, in the prototype's order.
PROTOTYPE_KEYS = {
    "tilePx": "tilePx", "nnMedian": "nnMedian", "nnP25": "nnP25", "share_nn_lt_0.03": "nnShareBelow003",
    "share_nn_lt_0.06": "nnShareBelow006", "share_nn_lt_0.10": "nnShareBelow010", "logCand_0.06": "logCandidates006",
    "logCand_0.10": "logCandidates010", "lowDetailShare": "lowDetailShare", "fineTexMedian": "fineTextureMedian",
    "fineTexP25": "fineTextureP25", "pileHHI16": "pileHhi16", "pileEntropy16": "pileEntropy16", "pileHHI32": "pileHhi32",
    "colourOnlyNnMedian": "colourNnMedian", "texOnlyNnMedian": "textureNnMedian", "largestPileShare16": "largestPileShare16",
    "boundaryEdgeShare": "boundaryEdgeShare", "logCandColour_0.06": "logCandidatesColour006", "hueEntropy": "hueEntropy",
}


def grid_shape(pieces: int, width: int, height: int) -> tuple[int, int]:
    """(cols, rows) of a puzzle with `pieces` pieces cut from a picture of width × height."""
    cols = max(2, round(math.sqrt(pieces * width / height)))
    rows = max(2, round(pieces / cols))
    return cols, rows


def _r(x: float) -> float:
    return round(float(x), 5)


def block(lab_full: np.ndarray, pieces: int) -> dict[str, Any] | None:
    """One piece count's numbers from the OKLab crop (H, W, 3). None when a cell would be under MIN_TILE_PX."""
    from sklearn.cluster import KMeans
    from sklearn.neighbors import NearestNeighbors

    h, w = lab_full.shape[:2]
    cols, rows = grid_shape(pieces, w, h)
    th, tw = h // rows, w // cols
    if th < MIN_TILE_PX or tw < MIN_TILE_PX:
        return None
    lab = lab_full[:th * rows, :tw * cols]
    L = np.ascontiguousarray(lab[..., 0])
    lap = np.abs(cv2.Laplacian(cv2.GaussianBlur(L, (0, 0), 0.8), cv2.CV_32F))
    gx = cv2.Sobel(L, cv2.CV_32F, 1, 0)
    gy = cv2.Sobel(L, cv2.CV_32F, 0, 1)
    mag = np.hypot(gx, gy)
    ang = ((np.arctan2(gy, gx) % np.pi) / np.pi * 6).astype(int).clip(0, 5)

    def tiles(a: np.ndarray) -> np.ndarray:
        return a.reshape(rows, th, cols, tw, *a.shape[2:]).swapaxes(1, 2).reshape(rows * cols, th, tw, *a.shape[2:])

    T = tiles(lab)
    mean = T.mean(axis=(1, 2))
    std = T.std(axis=(1, 2))
    h2, w2 = th // 2, tw // 2
    sub = np.concatenate([T[:, :h2, :w2].mean((1, 2)), T[:, :h2, w2:2 * w2].mean((1, 2)),
                          T[:, h2:2 * h2, :w2].mean((1, 2)), T[:, h2:2 * h2, w2:2 * w2].mean((1, 2))], 1)
    fine = tiles(lap).mean(axis=(1, 2))
    M, An = tiles(mag), tiles(ang)
    ori = np.stack([(M * (An == b)).sum((1, 2)) for b in range(6)], 1)
    ori = ori / (ori.sum(1, keepdims=True) + 1e-6)
    W = CHANNEL_WEIGHTS
    colour = np.concatenate([mean * W, (sub - np.tile(mean, 4)) * np.tile(W, 4) * 0.5], 1)
    tex = np.concatenate([std * W * 1.5, np.log1p(fine * 50)[:, None] * 0.05, ori * 0.1], 1)
    desc = np.concatenate([colour, tex], 1).astype(np.float32)
    n = len(desc)

    def nearest(d: np.ndarray) -> np.ndarray:
        dist, _ = NearestNeighbors(n_neighbors=2).fit(d).kneighbors(d)
        return dist[:, 1]

    def others_within(d: np.ndarray, eps: float) -> np.ndarray:
        nb = NearestNeighbors(radius=eps).fit(d)
        return np.array([len(x) - 1 for x in nb.radius_neighbors(d, return_distance=False)])

    near = nearest(desc)
    colour_near = nearest(colour)
    texture_near = nearest(tex)

    def piles(k: int) -> np.ndarray:
        import warnings

        with warnings.catch_warnings():  # a picture of few colours has fewer distinct piles than k - that is the answer
            warnings.simplefilter("ignore", category=UserWarning)
            return KMeans(n_clusters=min(k, n - 1), n_init=2, random_state=0).fit(colour).labels_

    labels16 = piles(16)
    s16 = np.bincount(labels16) / n
    s16 = s16[s16 > 0]
    s32 = np.bincount(piles(32)) / n
    s32 = s32[s32 > 0]
    low_detail = float(((fine < 0.004) & (std[:, 0] < 0.02)).mean())
    edges = cv2.Canny((np.clip(L, 0, 1) * 255).astype(np.uint8), 40, 100) > 0
    crossed = np.stack([edges[:, c * tw - 1:c * tw + 1].any(1) for c in range(1, cols)], 1)
    chroma = np.hypot(mean[:, 1], mean[:, 2])
    hue = np.arctan2(mean[:, 2], mean[:, 1])
    hue_bin = np.where(chroma < 0.03, 12, ((hue + np.pi) / (2 * np.pi) * 12).astype(int).clip(0, 11))
    hs = np.bincount(hue_bin, minlength=13) / n
    hs = hs[hs > 0]
    hist = np.bincount(np.minimum((near / HIST_STEP).astype(int), HIST_BINS - 1), minlength=HIST_BINS) / n
    uniform = (std[:, 0] < UNIFORM_SD) & (np.hypot(std[:, 1], std[:, 2]) < UNIFORM_SD)
    return {
        "pieces": int(pieces),
        "grid": [int(cols), int(rows)],
        "tilePx": _r(math.sqrt(th * tw)),
        "nnMedian": _r(np.median(near)),
        "nnP25": _r(np.percentile(near, 25)),
        "nnShareBelow003": _r((near < 0.03).mean()),
        "nnShareBelow006": _r((near < 0.06).mean()),
        "nnShareBelow010": _r((near < 0.10).mean()),
        "logCandidates006": _r(np.mean(np.log1p(others_within(desc, 0.06)))),
        "logCandidates010": _r(np.mean(np.log1p(others_within(desc, 0.10)))),
        "lowDetailShare": _r(low_detail),
        "fineTextureMedian": _r(np.median(fine)),
        "fineTextureP25": _r(np.percentile(fine, 25)),
        "pileHhi16": _r((s16 ** 2).sum()),
        "pileEntropy16": _r(-(s16 * np.log(s16)).sum()),
        "pileHhi32": _r((s32 ** 2).sum()),
        "colourNnMedian": _r(np.median(colour_near)),
        "textureNnMedian": _r(np.median(texture_near)),
        "largestPileShare16": _r(s16.max()),
        "boundaryEdgeShare": _r(crossed.mean()),
        "logCandidatesColour006": _r(np.mean(np.log1p(others_within(colour, 0.06)))),
        "hueEntropy": _r(-(hs * np.log(hs)).sum()),
        "nnHistogram": [_r(v) for v in hist],
        "pileShares16": [_r(v) for v in sorted(s16, reverse=True)],
        "uniformShare": _r(uniform.mean()),
        "lookalike3Share": _r((others_within(desc, NEAR) >= 3).mean()),
        "largestUniformRegion": _r(largest_region(uniform.reshape(rows, cols), labels16.reshape(rows, cols)) / n),
    }


def largest_region(uniform: np.ndarray, piles: np.ndarray) -> int:
    """Cells in the largest 4-connected area of uniform cells that share one colour pile."""
    from scipy import ndimage

    best = 0
    for pile in np.unique(piles[uniform]):
        regions, count = ndimage.label(uniform & (piles == pile))
        if count:
            best = max(best, int(np.bincount(regions.reshape(-1))[1:].max()))
    return best


def piece_scale(rgb: np.ndarray, pieces: int | None) -> dict[str, Any]:
    """The payload: `real` (the puzzle's own piece count), `p500`, `p1000` (the same block when the count is 500 /
    1000), `cropSize` [w, h] of the crop measured."""
    real = int(pieces) if pieces and pieces > 1 else FALLBACK_PIECES
    lab = srgb_to_oklab(np.ascontiguousarray(rgb))
    real_block = block(lab, real)
    out: dict[str, Any] = {"cropSize": [int(rgb.shape[1]), int(rgb.shape[0])], "real": real_block,
                           "pieceCountKnown": bool(pieces and pieces > 1)}
    for count in EXTRA_COUNTS:
        out[f"p{count}"] = real_block if count == real else block(lab, count)
    return out


def _job(args: tuple[np.ndarray, int | None]) -> dict[str, Any] | Exception:
    try:
        return piece_scale(args[0], args[1])
    except Exception as e:  # noqa: BLE001 - per-image failure
        return e


class PieceScaleStage(_CpuStage):
    """CPU only, no model lock: runs in single-threaded worker processes beside a GPU stage or in a CPU phase of its own
    while `describe` holds the GPU."""
    analyzer = "piece_scale"
    _job = staticmethod(_job)

    @property
    def model(self) -> str:
        return "numpy/opencv/scikit-learn piece grid, oklab, kmeans 16/32 seed 0, cached crop <= 1600 px"

