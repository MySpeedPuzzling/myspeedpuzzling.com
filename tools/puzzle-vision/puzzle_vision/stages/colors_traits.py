"""`colors` and `traits` of the artwork crop - numpy / OpenCV / scikit-image, deterministic, CPU only.

Ported from the vision lab's features.py (vision-analysis.md §5, payloads §9.2 / §9.3 in camelCase) plus the
extract-once additions of image-taxonomy.md §11.1: `grid16` (16×16 cells: flat, lightness, chroma, gradient), the border
band stats, the piece-level numbers for the puzzle's real piece count and for 500 / 1000, a symmetry and a line-strength
score. Run as N single-threaded worker processes (OMP_NUM_THREADS=1) next to a GPU stage (§19).
"""

from __future__ import annotations

import math
import os
from typing import Any

import cv2
import numpy as np

from .base import Prepared, Stage

WORK_SIDE = 768
FAMILIES = ["red", "orange", "yellow", "green", "teal", "blue", "purple", "pink", "brown", "black", "grey", "white"]
FLAT_GRADIENT = 0.06


def single_thread() -> None:
    for var in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS", "VECLIB_MAXIMUM_THREADS", "NUMEXPR_NUM_THREADS"):
        os.environ[var] = "1"
    cv2.setNumThreads(1)


# --- colour space ---------------------------------------------------------------------------------------------------
def srgb_to_oklab(rgb: np.ndarray) -> np.ndarray:
    """uint8 (...,3) -> OKLab float32 (...,3), L in 0..1 (Björn Ottosson 2020)."""
    c = rgb.astype(np.float32) / 255.0
    c = np.where(c <= 0.04045, c / 12.92, ((c + 0.055) / 1.055) ** 2.4)
    m1 = np.array([[0.4122214708, 0.5363325363, 0.0514459929], [0.2119034982, 0.6806995451, 0.1073969566],
                   [0.0883024619, 0.2817188376, 0.6299787005]], np.float32)
    m2 = np.array([[0.2104542553, 0.7936177850, -0.0040720468], [1.9779984951, -2.4285922050, 0.4505937099],
                   [0.0259040371, 0.7827717662, -0.8086757660]], np.float32)
    return (np.cbrt(c @ m1.T) @ m2.T).astype(np.float32)


def family(lab: Any) -> str:
    L, a, b = (float(x) for x in lab)
    C = math.hypot(a, b)
    h = (math.degrees(math.atan2(b, a)) + 360) % 360
    if C < 0.035:
        return "black" if L < 0.30 else ("white" if L > 0.90 else "grey")
    if L < 0.22:
        return "black"
    if 40 <= h < 100 and L < 0.62 and C < 0.16:
        return "brown"
    if h < 15 or h >= 345:
        return "pink" if L > 0.72 else "red"
    if h < 40:
        return "red" if C > 0.17 and L < 0.62 else ("pink" if L > 0.75 else "orange")
    if h < 75:
        return "orange"
    if h < 115:
        return "yellow"
    if h < 165:
        return "green"
    if h < 215:
        return "teal"
    if h < 285:
        return "blue"
    if h < 325:
        return "purple"
    return "pink"


def lab_to_hex(lab: Any) -> str:
    L, a, b = (float(x) for x in lab)
    l_ = L + 0.3963377774 * a + 0.2158037573 * b
    m_ = L - 0.1055613458 * a - 0.0638541728 * b
    s_ = L - 0.0894841775 * a - 1.2914855480 * b
    l, m, s = l_ ** 3, m_ ** 3, s_ ** 3
    rgb = [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
           -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s]
    out = []
    for v in rgb:
        v = min(1.0, max(0.0, v))
        v = 12.92 * v if v <= 0.0031308 else 1.055 * v ** (1 / 2.4) - 0.055
        out.append(int(round(v * 255)))
    return "#%02x%02x%02x" % tuple(out)


def prepare(rgb: np.ndarray) -> np.ndarray:
    h, w = rgb.shape[:2]
    s = WORK_SIDE / max(h, w)
    if s < 1:
        rgb = cv2.resize(rgb, (max(1, round(w * s)), max(1, round(h * s))), interpolation=cv2.INTER_AREA)
    return np.ascontiguousarray(rgb)


# --- colors ---------------------------------------------------------------------------------------------------------
def colors(rgb: np.ndarray, k: int = 8, seed: int = 0) -> dict[str, Any]:
    from sklearn.cluster import KMeans

    rgb = prepare(rgb)
    lab = srgb_to_oklab(rgb).reshape(-1, 3)
    rng = np.random.default_rng(seed)
    px = lab[rng.choice(len(lab), min(len(lab), 40000), replace=False)]
    k = min(k, len(np.unique(px.round(3), axis=0)))
    km = KMeans(n_clusters=max(1, k), n_init=3, random_state=seed).fit(px)
    shares = np.bincount(km.labels_, minlength=max(1, k)) / len(px)
    order = np.argsort(-shares)
    cents, shares = km.cluster_centers_[order], shares[order]
    palette = [{"hex": lab_to_hex(c), "share": round(float(s), 4), "family": family(c), "oklab": [round(float(x), 4) for x in c]}
               for c, s in zip(cents, shares)]
    fam: dict[str, float] = {}
    for p in palette:
        fam[p["family"]] = round(fam.get(p["family"], 0.0) + p["share"], 4)
    q = np.stack([np.clip(lab[:, 0] * 10, 0, 9), np.clip((lab[:, 1] + 0.3) / 0.6 * 12, 0, 11),
                  np.clip((lab[:, 2] + 0.3) / 0.6 * 12, 0, 11)], 1).astype(int)
    hist = np.bincount(q[:, 0] * 144 + q[:, 1] * 12 + q[:, 2], minlength=1440).astype(float)
    pr = hist[hist > 0] / hist.sum()
    R, G, B = (rgb[..., i].astype(float) for i in range(3))
    rg, yb = R - G, 0.5 * (R + G) - B
    colorfulness = math.hypot(rg.std(), yb.std()) + 0.3 * math.hypot(rg.mean(), yb.mean())
    L = lab[:, 0]
    C = np.hypot(lab[:, 1], lab[:, 2])
    sh = shares[shares > 0]
    return {
        "palette": palette,
        "families": dict(sorted(fam.items(), key=lambda x: -x[1])),
        "dominantShare": round(float(shares[0]), 4),
        "paletteEffectiveColors": round(float(np.exp(-(sh * np.log(sh)).sum())), 3),
        "colorEntropyBits": round(float(-(pr * np.log2(pr)).sum()), 3),
        "colorfulness": round(colorfulness, 2),
        "lightnessMean": round(float(L.mean()), 4),
        "lightnessStd": round(float(L.std()), 4),
        "darkShare": round(float((L < 0.35).mean()), 4),
        "lightShare": round(float((L > 0.85).mean()), 4),
        "chromaMean": round(float(C.mean()), 4),
        "mutedShare": round(float((C < 0.04).mean()), 4),
    }


# --- traits ---------------------------------------------------------------------------------------------------------
def traits(rgb: np.ndarray, pieces: int | None = None, seed: int = 0, text: tuple[float, int] | None = None) -> dict[str, Any]:
    from skimage import graph
    from skimage.segmentation import slic

    rgb = prepare(rgb)
    h, w = rgb.shape[:2]
    lab = srgb_to_oklab(rgb)
    L = lab[..., 0]
    gray = (np.clip(L, 0, 1) * 255).astype(np.uint8)
    gx = cv2.Sobel(lab, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(lab, cv2.CV_32F, 0, 1, ksize=3)
    gmag = np.sqrt((gx ** 2 + gy ** 2).sum(axis=2))
    edges = cv2.Canny(cv2.GaussianBlur(gray, (3, 3), 0), 40, 100)
    lap = cv2.Laplacian(cv2.GaussianBlur(L, (3, 3), 0), cv2.CV_32F)
    # flat 16 px blocks joined into regions; a region whose colours spread is a smooth gradient
    B = 16
    hb, wb = max(1, h // B), max(1, w // B)
    gm = cv2.resize(gmag, (wb, hb), interpolation=cv2.INTER_AREA) if (h < B or w < B) else gmag[:hb * B, :wb * B].reshape(hb, B, wb, B).mean(axis=(1, 3))
    bcol = cv2.resize(lab, (wb, hb), interpolation=cv2.INTER_AREA).reshape(hb, wb, 3) if (h < B or w < B) else lab[:hb * B, :wb * B].reshape(hb, B, wb, B, 3).mean(axis=(1, 3))
    flat = (gm < FLAT_GRADIENT).astype(np.uint8)
    n, lbl, st, _ = cv2.connectedComponentsWithStats(flat, connectivity=4)
    total = hb * wb
    uni = grad = largest_uni = largest_flat = 0.0
    n_uni = n_grad = 0
    for k in range(1, n):
        a = st[k, cv2.CC_STAT_AREA] / total
        if a < 0.005:
            continue
        cols = bcol[lbl == k]
        spread = float(np.linalg.norm(np.percentile(cols, 95, 0) - np.percentile(cols, 5, 0)))
        largest_flat = max(largest_flat, a)
        if spread < 0.06:
            uni += a
            n_uni += 1
            largest_uni = max(largest_uni, a)
        else:
            grad += a
            n_grad += 1
    small = cv2.resize(lab, (max(2, w // 2), max(2, h // 2)), interpolation=cv2.INTER_AREA)
    seg = slic(small, n_segments=400, compactness=8, start_label=0, channel_axis=-1, convert2lab=False)
    if seg.max() > 0:
        merged = graph.cut_threshold(seg, graph.rag_mean_color(small * 100, seg, mode="distance"), 4.0)
    else:
        merged = seg
    _, counts = np.unique(merged, return_counts=True)
    region_shares = np.sort(counts)[::-1] / merged.size
    real = int(pieces) if pieces and pieces > 1 else 500
    out: dict[str, Any] = {
        "edgeDensity": round(float((edges > 0).mean()), 4),
        "detail": round(float(np.abs(lap).mean()) * 100, 3),
        "gradientMean": round(float(gmag.mean()), 4),
        "flatShare": round(float(flat.mean()), 4),
        "uniformShare": round(uni, 4),
        "largestUniformRegion": round(largest_uni, 4),
        "uniformRegions": n_uni,
        "smoothGradientShare": round(grad, 4),
        "gradientRegions": n_grad,
        "largestFlatRegion": round(largest_flat, 4),
        "colorRegions": int(len(counts)),
        "colorRegions80": int(np.searchsorted(np.cumsum(region_shares), 0.8) + 1),
        "largestColorRegion": round(float(region_shares[0]), 4),
        "textShare": None if text is None else round(float(text[0]), 4),
        "textChars": None if text is None else int(text[1]),
    }
    real_level = piece_level(lab, gmag, real, seed)
    out.update({k: v for k, v in real_level.items() if k != "border"})
    out["pieceLevel"] = {
        "real": {"pieces": real, **real_level},
        "p500": piece_level(lab, gmag, 500, seed),
        "p1000": piece_level(lab, gmag, 1000, seed),
    }
    out["borderBand"] = real_level["border"]
    out["regularity"] = regularity(L)
    out["symmetry"] = symmetry(L)
    out["lineStrength"] = line_strength(gray)
    out["grid16"] = grid16(lab, gmag)
    return out


def piece_level(lab: np.ndarray, gmag: np.ndarray, pieces: int, seed: int = 0) -> dict[str, Any]:
    """The crop cut into ~`pieces` cells (one cell ~ one piece): featureless cells, cells with a look-alike, how
    distinct a typical piece is, and the uniform share of the outer ring of cells (edge-start trait) vs the interior."""
    from sklearn.neighbors import NearestNeighbors

    h, w = lab.shape[:2]
    cols = max(2, round(math.sqrt(pieces * w / h)))
    rows = max(2, round(pieces / cols))
    k = 8
    ang = np.arctan2(cv2.Sobel(lab[..., 0], cv2.CV_32F, 0, 1), cv2.Sobel(lab[..., 0], cv2.CV_32F, 1, 0))
    bins = ((ang % np.pi) / np.pi * 6).astype(int).clip(0, 5)
    size = (cols * k, rows * k)
    cl = cv2.resize(lab, size, interpolation=cv2.INTER_AREA).reshape(rows, k, cols, k, 3)
    g = cv2.resize(gmag, size, interpolation=cv2.INTER_AREA).reshape(rows, k, cols, k)
    o = np.stack([cv2.resize((gmag * (bins == b)).astype(np.float32), size, interpolation=cv2.INTER_AREA)
                  .reshape(rows, k, cols, k).mean(axis=(1, 3)).reshape(-1) for b in range(6)], 1)
    mean = cl.mean(axis=(1, 3)).reshape(-1, 3)
    std = cl.std(axis=(1, 3)).reshape(-1, 3)
    tex = g.mean(axis=(1, 3)).reshape(-1)
    desc = np.concatenate([mean * [1, 2, 2], std * [2, 3, 3], np.minimum(tex, 0.6)[:, None], o / (o.sum(1, keepdims=True) + 1e-6) * 0.15], 1)
    flat = (tex < FLAT_GRADIENT) & (std[:, 0] < 0.03)
    d, _ = NearestNeighbors(n_neighbors=2).fit(desc).kneighbors(desc)
    near = d[:, 1]
    ring = np.zeros((rows, cols), bool)
    ring[0, :] = ring[-1, :] = ring[:, 0] = ring[:, -1] = True
    ring = ring.reshape(-1)
    interior = ~ring
    return {
        "pieceGrid": [cols, rows],
        "pieceFlatShare": round(float(flat.mean()), 4),
        "pieceLookalikeShare": round(float((near < 0.035).mean()), 4),
        "pieceDistinctiveness": round(float(np.median(near)), 4),
        "border": {
            "uniformShare": round(float(flat[ring].mean()), 4),
            "interiorUniformShare": round(float(flat[interior].mean()), 4) if interior.any() else None,
        },
    }


def regularity(L: np.ndarray) -> float:
    """Strongest off-centre peak of the normalised autocorrelation of the high-passed lightness (PoC `repetition`)."""
    g = cv2.resize(L, (256, 256), interpolation=cv2.INTER_AREA)
    hp = g - cv2.GaussianBlur(g, (0, 0), 6)
    hp -= hp.mean()
    F = np.fft.fft2(hp)
    ac = np.real(np.fft.ifft2(F * np.conj(F)))
    ac = np.fft.fftshift(ac) / (ac.max() + 1e-9)
    c = 128
    ac[c - 12:c + 13, c - 12:c + 13] = 0
    return round(float(ac.max()), 4)


def symmetry(L: np.ndarray) -> float:
    """Best of left-right / top-bottom mirror similarity (correlation of the lightness with its mirror), 0..1."""
    g = cv2.resize(L, (128, 128), interpolation=cv2.INTER_AREA).astype(np.float64)
    g -= g.mean()
    denom = float((g * g).sum())
    if denom <= 1e-9:
        return 1.0
    lr = float((g * g[:, ::-1]).sum()) / denom
    tb = float((g * g[::-1, :]).sum()) / denom
    return round(max(0.0, lr, tb), 4)


def line_strength(gray: np.ndarray) -> float:
    """Share of edge pixels that lie on long straight segments (Hough, segments >= 8 % of the short side)."""
    h, w = gray.shape[:2]
    s = 512 / max(h, w)
    if s < 1:
        gray = cv2.resize(gray, (max(1, round(w * s)), max(1, round(h * s))), interpolation=cv2.INTER_AREA)
    edges = cv2.Canny(cv2.GaussianBlur(gray, (3, 3), 0), 50, 150)
    n_edges = int((edges > 0).sum())
    if n_edges == 0:
        return 0.0
    min_len = max(10, int(0.08 * min(gray.shape[:2])))
    lines = cv2.HoughLinesP(edges, 1, np.pi / 180, threshold=40, minLineLength=min_len, maxLineGap=3)
    if lines is None:
        return 0.0
    mask = np.zeros_like(edges)
    for x0, y0, x1, y1 in np.asarray(lines).reshape(-1, 4):
        cv2.line(mask, (int(x0), int(y0)), (int(x1), int(y1)), 255, 2)
    return round(float(((mask > 0) & (edges > 0)).sum()) / n_edges, 4)


def grid16(lab: np.ndarray, gmag: np.ndarray) -> dict[str, list[int]]:
    """16×16 cells as 4 arrays of 256 small integers: flat 0/1, mean OKLab L ×100, chroma ×500 (cap 255),
    gradient magnitude ×500 (cap 255)."""
    cl = cv2.resize(lab, (16, 16), interpolation=cv2.INTER_AREA)
    gm = cv2.resize(gmag, (16, 16), interpolation=cv2.INTER_AREA)
    chroma = np.hypot(cl[..., 1], cl[..., 2])
    return {
        "flat": [int(v) for v in (gm < FLAT_GRADIENT).reshape(-1)],
        "lightness": [int(v) for v in np.clip(np.round(cl[..., 0] * 100), 0, 100).reshape(-1)],
        "chroma": [int(v) for v in np.clip(np.round(chroma * 500), 0, 255).reshape(-1)],
        "gradient": [int(v) for v in np.clip(np.round(gm * 500), 0, 255).reshape(-1)],
    }


def text_regions(rgb: np.ndarray, cpu_only: bool = True) -> tuple[float, int] | None:
    """Apple Vision fast text recognition on the crop: (share of area in text boxes, characters). CPU-only by default:
    it runs next to GPU stages, and Apple Vision stalls when it shares the GPU (image-taxonomy.md §19)."""
    try:
        import Vision  # type: ignore[import-not-found]
        from Foundation import NSData  # type: ignore[import-not-found]
    except Exception:  # noqa: BLE001 - not on a Mac / ml extra not installed
        return None
    from ..imaging import to_png_bytes

    b = to_png_bytes(rgb)
    req = Vision.VNRecognizeTextRequest.alloc().init()
    req.setRecognitionLevel_(1)  # fast
    req.setUsesLanguageCorrection_(False)
    if cpu_only:
        try:
            req.setUsesCPUOnly_(True)
        except Exception:  # noqa: BLE001 - deprecated on newer macOS
            pass
    handler = Vision.VNImageRequestHandler.alloc().initWithData_options_(NSData.dataWithBytes_length_(b, len(b)), None)
    handler.performRequests_error_([req], None)
    mask = np.zeros((200, 200), np.uint8)
    chars = 0
    for o in req.results() or []:
        cand = o.topCandidates_(1)
        if not cand or cand[0].confidence() < 0.4:
            continue
        chars += len(str(cand[0].string()).strip())
        bb = o.boundingBox()
        x0, y0 = bb.origin.x, 1 - bb.origin.y - bb.size.height
        mask[int(y0 * 200):int((y0 + bb.size.height) * 200) + 1, int(x0 * 200):int((x0 + bb.size.width) * 200) + 1] = 1
    return float(mask.mean()), chars


# --- the stages -------------------------------------------------------------------------------------------------------
def _colors_job(args: tuple[np.ndarray, int | None]) -> dict[str, Any] | Exception:
    try:
        return colors(args[0])
    except Exception as e:  # noqa: BLE001
        return e


def _traits_job(args: tuple[np.ndarray, int | None]) -> dict[str, Any] | Exception:
    try:
        return traits(args[0], args[1], text=text_regions(args[0]))
    except Exception as e:  # noqa: BLE001
        return e


class _CpuStage(Stage):
    upstream = ("artwork",)
    uses_crop = True
    needs_model_lock = False
    batch_size = 24
    _job = staticmethod(_colors_job)

    def __init__(self, version: str, workers: int = 1) -> None:
        super().__init__(version, workers)
        self._pool = None

    def load(self) -> None:
        single_thread()
        if self.workers > 1:
            import multiprocessing as mp

            self._pool = mp.get_context("spawn").Pool(self.workers, initializer=single_thread)

    def close(self) -> None:
        if self._pool is not None:
            self._pool.terminate()
            self._pool = None

    def analyse_batch(self, ps: list[Prepared]) -> list[dict[str, Any] | Exception]:
        jobs = [(p.subject, p.item.pieces_count) for p in ps]
        if self._pool is not None:
            return self._pool.map(self._job, jobs, chunksize=1)
        return [self._job(j) for j in jobs]

    def analyse(self, p: Prepared) -> dict[str, Any]:
        r = self._job((p.subject, p.item.pieces_count))
        if isinstance(r, Exception):
            raise r
        return r


class ColorsStage(_CpuStage):
    analyzer = "colors"
    _job = staticmethod(_colors_job)

    @property
    def model(self) -> str:
        return "numpy/opencv oklab k-means k=8 seed=0, 768 px"


class TraitsStage(_CpuStage):
    analyzer = "traits"
    _job = staticmethod(_traits_job)

    @property
    def model(self) -> str:
        return "numpy/opencv/scikit-image 768 px + apple-vision fast text (cpu)"
