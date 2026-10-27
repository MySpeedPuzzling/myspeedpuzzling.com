"""The per-puzzle "second view" from the stored `finished_photo` analyses (docs/features/insights/finished-photos.md) -
what `difficulty_prior` reads next to the catalogue picture's own analyses. Computed here, from the stored per-photo
rows, so a change of the aggregation never re-runs a photo.

A photo counts when:
- the assembled puzzle was **registered** in it (the catalogue artwork matched into the photo - its own identity check),
  or found by the edge-quad path and its SigLIP 2 crop vector agrees with the catalogue crop's (cosine >=
  QUAD_MIN_COSINE: the quad path has no identity check of its own);
- at least MIN_VISIBLE of the puzzle is in the frame;
- it is not the catalogue picture itself (`printCorrelation` >= SAME_AS_CATALOGUE: a catalogue picture made from a
  player's photo, or a player uploading the catalogue picture - no second view);
- it has both crop embeddings (a payload without them is from a test run).
Each counted photo weighs its visible share × its resolution (pixels per piece / 20, at most 1): a sharp, whole photo
says more than a corner of a small one. The view = the weighted mean of the unit embedding vectors (re-normalised),
the weighted mean of the numbers (NaN-aware: seams and the piece grid exist only for whole puzzles).
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Any, Iterable

import numpy as np

from ..payload import as_map, b64_to_vector

ANALYZER = "finished_photo"
MIN_VISIBLE = 0.6
SAME_AS_CATALOGUE = 0.995
QUAD_MIN_COSINE = 0.8  # SigLIP 2 crop vs catalogue crop; registered photos of the same puzzle: see finished-photos.md
RESOLUTION_PPP = 20.0
PIECE_SCALE_NAMES = ("tilePx", "nnMedian", "nnP25", "nnShareBelow003", "nnShareBelow006", "nnShareBelow010",
                     "logCandidates006", "logCandidates010", "lowDetailShare", "fineTextureMedian", "fineTextureP25",
                     "pileHhi16", "pileEntropy16", "pileHhi32", "colourNnMedian", "textureNnMedian",
                     "largestPileShare16", "boundaryEdgeShare", "logCandidatesColour006", "hueEntropy")
NUMBER_NAMES = (*(f"photo.{k}" for k in PIECE_SCALE_NAMES), "glare", "glareVsArtwork", "gridX", "gridY",
                "gridXVsArtwork", "gridYVsArtwork", "logSharpness", "printCorrelation", "logPixelsPerPiece", "visibleShare")


@dataclass
class PhotoView:
    photos: int  # counted photos
    siglip: np.ndarray
    dino: np.ndarray
    numbers: np.ndarray  # NUMBER_NAMES, NaN where no counted photo had it
    weight: float  # the summed photo weights


def _num(x: Any) -> float:
    try:
        v = float(x)
    except (TypeError, ValueError):
        return math.nan
    return v if math.isfinite(v) else math.nan


def numbers(result: dict[str, Any]) -> np.ndarray:
    """NUMBER_NAMES of one photo's payload (NaN where it has none)."""
    ps = as_map(result.get("pieceScale"))
    glare = as_map(result.get("glare"))
    seams = as_map(result.get("seams"))
    quality = as_map(result.get("quality"))
    rect = as_map(result.get("rectified"))
    gx, gy = _num(seams.get("gridX")), _num(seams.get("gridY"))
    sharp, ppp = _num(quality.get("sharpness")), _num(rect.get("pixelsPerPiece"))
    return np.array([
        *(_num(ps.get(k)) for k in PIECE_SCALE_NAMES),
        _num(glare.get("share")), _num(glare.get("vsArtwork")), gx, gy,
        gx - _num(seams.get("artworkGridX")), gy - _num(seams.get("artworkGridY")),
        math.log1p(sharp) if sharp >= 0 else math.nan, _num(quality.get("printCorrelation")),
        math.log(ppp) if ppp > 0 else math.nan, _num(rect.get("visibleShare")),
    ], dtype=np.float64)


def _vector(payload: Any, key: str) -> np.ndarray | None:
    data = as_map(payload).get(key)
    if not isinstance(data, str) or not data:
        return None
    try:
        v = b64_to_vector(data).astype(np.float64)
    except (ValueError, TypeError):
        return None
    norm = float(np.linalg.norm(v))
    return v / norm if norm > 0 and np.all(np.isfinite(v)) else None


def counted(result: dict[str, Any], catalogue_siglip: np.ndarray | None) -> tuple[np.ndarray, np.ndarray, float] | None:
    """(siglip, dino, weight) of a photo that counts, else None (module docstring)."""
    loc = as_map(result.get("locate"))
    rect = as_map(result.get("rectified"))
    method = loc.get("method")
    if method not in ("register", "quad") or not rect:
        return None
    visible = _num(rect.get("visibleShare"))
    if not visible >= MIN_VISIBLE:
        return None
    if _num(as_map(result.get("quality")).get("printCorrelation")) >= SAME_AS_CATALOGUE:
        return None
    emb = as_map(result.get("embeddings"))
    siglip, dino = _vector(emb.get("siglip2"), "vector"), _vector(emb.get("dinov2"), "cls")
    if siglip is None or dino is None:
        return None
    if method == "quad":
        if catalogue_siglip is None or catalogue_siglip.shape != siglip.shape:
            return None
        cat = catalogue_siglip / max(float(np.linalg.norm(catalogue_siglip)), 1e-12)
        if float(siglip @ cat) < QUAD_MIN_COSINE:
            return None
    ppp = _num(rect.get("pixelsPerPiece"))
    weight = visible * (min(1.0, ppp / RESOLUTION_PPP) if ppp > 0 else 0.5)
    return siglip, dino, max(weight, 1e-3)


def view(results: Iterable[dict[str, Any]], catalogue_siglip: np.ndarray | None) -> PhotoView | None:
    """The puzzle's second view from its photos' payloads; None when no photo counts."""
    sig, dino, nums, ws = [], [], [], []
    for r in results:
        c = counted(r, catalogue_siglip)
        if c is None:
            continue
        sig.append(c[0])
        dino.append(c[1])
        ws.append(c[2])
        nums.append(numbers(r))
    if not ws:
        return None
    w = np.array(ws)
    s = (np.vstack(sig) * w[:, None]).sum(0)
    d = (np.vstack(dino) * w[:, None]).sum(0)
    N = np.vstack(nums)
    ok = np.isfinite(N)
    with np.errstate(invalid="ignore", divide="ignore"):
        mean = np.where(ok.any(0), (np.where(ok, N, 0) * w[:, None]).sum(0) / (ok * w[:, None]).sum(0), np.nan)
    return PhotoView(len(ws), s / np.linalg.norm(s), d / np.linalg.norm(d), mean, float(w.sum()))


def views(rows: Iterable[dict[str, Any]], fingerprints: dict[str, str], catalogue_siglip: dict[str, np.ndarray]) -> dict[str, PhotoView]:
    """{puzzleId: PhotoView} from the bulk read (`onlyCurrent=1`): only rows matched against the puzzle's current
    catalogue picture (`fingerprints`: puzzleId -> current fingerprint) and of the newest analyzer version seen."""
    by_version: dict[str, dict[str, list[dict[str, Any]]]] = {}
    for row in rows:
        pid = row.get("puzzleId")
        if not isinstance(pid, str) or fingerprints.get(pid) != row.get("imageFingerprint") or row.get("current") is False:
            continue
        result = as_map(row.get("result"))
        if result:
            by_version.setdefault(str(row.get("analyzerVersion") or ""), {}).setdefault(pid, []).append(result)
    if not by_version:
        return {}
    newest = max(by_version, key=lambda v: tuple(int(x) if x.isdigit() else 0 for x in v.split("+", 1)[0].split(".")))
    out = {}
    for pid, results in by_version[newest].items():
        v = view(results, catalogue_siglip.get(pid))
        if v is not None:
            out[pid] = v
    return out
