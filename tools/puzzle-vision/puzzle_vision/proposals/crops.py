"""Crop proposals (implementation-plan.md §2.5): a cleaner catalogue picture is always a **change request** - the
pipeline never changes a puzzle picture itself. The original stays (production archives it on approval).

1. Candidates: current `artwork` results that are box photos / renders / assembled puzzles (kind != flat, method != trim),
   most-solved first when the work list carries a solve count, else in puzzle id order.
2. The perspective-corrected crop is rendered from the **full-resolution original**.
3. Confidence from **agreement signals**, never a raw model score:
   - IoU of the VLM box vs the OpenCV-snapped quad,
   - the flat-art gate's probability on the crop (a good crop looks like flat artwork),
   - edge straightness (a strong, straight edge right where the crop ends, on all four sides),
   - an aspect ratio plausible for a puzzle,
   - the artwork filling a reasonable part of the photo (and enough pixels).
4. `POST /internal-api/puzzle-change-requests` with the image, `source: image_pipeline`, `tags: ["artwork_crop"]`,
   `confidence`, `modelVersion`, `sourceImageFingerprint`; a 409 `already_proposed` is fine (never twice).
5. Below the propose threshold (0.6) nothing is proposed.
"""

from __future__ import annotations

import logging
import math
from dataclasses import dataclass
from typing import Any, Callable

import cv2
import numpy as np
from PIL import Image

from ..api import AlreadyProposed, Conflict, InternalApi, NotFound, WorkItem
from ..imaging import bbox_to_xyxy, crop, decode, normalise, to_jpeg_bytes

log = logging.getLogger(__name__)

CROP_PROPOSAL_VERSION = "1.0.0"
PROPOSE_THRESHOLD = 0.6
TAG = "artwork_crop"
MIN_CROP_SIDE = 600
MAX_OUTPUT_SIDE = 4000
MAX_IMAGE_BYTES = 15 * 1024 * 1024
WEIGHTS = {"iou": 0.35, "gate": 0.25, "straight": 0.2, "aspect": 0.1, "fill": 0.1}
# width / height of common puzzles (500 49×36, 1000 70×50, 1500 80×60, 3000/5000 ~1.5), square ones, panoramas
PLAUSIBLE_ASPECTS = ((1.0, 1.02), (1.22, 1.56), (2.6, 3.4))


def model_version(artwork_version: str) -> str:
    return f"artwork-crop {CROP_PROPOSAL_VERSION} ({artwork_version})"[:50]


def iou_signal(artwork: dict[str, Any]) -> float:
    s = artwork.get("signals") or {}
    if artwork.get("method") == "vlm+quad" and s.get("quadIou") is not None:
        return float(s["quadIou"])
    if s.get("quadIou") is not None:
        return float(s["quadIou"]) * 0.8  # a quad was found but disagreed
    return 0.4  # VLM box only, nothing confirms it


def gate_probability(flat_margin: float) -> float:
    """The gate's flat-vs-other margin on the crop as a probability (0.02 per logit step)."""
    return 1.0 / (1.0 + math.exp(-flat_margin / 0.02))


def aspect_plausibility(width: int, height: int) -> float:
    if width <= 0 or height <= 0:
        return 0.0
    a = math.log(max(width, height) / min(width, height))
    dist = min(0.0 if math.log(lo) <= a <= math.log(hi) else min(abs(a - math.log(lo)), abs(a - math.log(hi)))
               for lo, hi in PLAUSIBLE_ASPECTS)
    return float(math.exp(-((dist / 0.05) ** 2)))


def fill_score(bbox: dict[str, float], crop_long_side: int) -> float:
    area = float(bbox.get("w", 0)) * float(bbox.get("h", 0))
    s_area = min(1.0, max(0.0, (area - 0.12) / 0.33))
    s_px = min(1.0, max(0.0, (crop_long_side - MIN_CROP_SIDE) / 600))
    return s_area * (0.5 + 0.5 * s_px)


def edge_straightness(rgb: np.ndarray, box: list[float], quad: list[list[float]] | None = None, margin: float = 0.04) -> float:
    """How clearly the picture ends exactly where the crop ends: the region is warped with a margin around it, then for
    each side the strongest perpendicular gradient per position must be strong and sit on one straight line."""
    h, w = rgb.shape[:2]
    if quad is not None and len(quad) == 4:
        q = np.asarray(quad, np.float32) * [w, h]
    else:
        x0, y0, x1, y1 = box
        q = np.float32([[x0 * w, y0 * h], [x1 * w, y0 * h], [x1 * w, y1 * h], [x0 * w, y1 * h]])
    c = q.mean(0)
    big = c + (q - c) * (1 + 2 * margin)
    tw, th = 400, max(40, int(400 * (np.linalg.norm(q[3] - q[0]) / max(1.0, np.linalg.norm(q[1] - q[0])))))
    th = min(th, 1200)
    m = cv2.getPerspectiveTransform(big.astype(np.float32), np.float32([[0, 0], [tw, 0], [tw, th], [0, th]]))
    warped = cv2.warpPerspective(rgb, m, (tw, th), flags=cv2.INTER_AREA, borderMode=cv2.BORDER_REPLICATE)
    gray = cv2.GaussianBlur(cv2.cvtColor(warped, cv2.COLOR_RGB2GRAY).astype(np.float32), (3, 3), 0)
    gx = np.abs(cv2.Sobel(gray, cv2.CV_32F, 1, 0, ksize=3))
    gy = np.abs(cv2.Sobel(gray, cv2.CV_32F, 0, 1, ksize=3))
    mx, my = margin / (1 + 2 * margin), margin / (1 + 2 * margin)
    scores = []
    for side in ("top", "bottom", "left", "right"):
        if side in ("top", "bottom"):
            e = int(round((my if side == "top" else 1 - my) * th))
            band = int(max(4, my * th))
            lo, hi = max(0, e - band), min(th, e + band + 1)
            prof = gy[lo:hi, :]  # rows across the edge, columns along it
        else:
            e = int(round((mx if side == "left" else 1 - mx) * tw))
            band = int(max(4, mx * tw))
            lo, hi = max(0, e - band), min(tw, e + band + 1)
            prof = gx[:, lo:hi].T
        if prof.shape[0] < 5:
            scores.append(0.0)
            continue
        centre = e - lo
        # the gradient right on the expected line (±2 px) against the band's typical gradient, position by position:
        # an edge inside the picture or a crop 2 % off misses the line, noise is strong everywhere
        on_line = prof[max(0, centre - 2):centre + 3].max(axis=0)
        typical = np.median(prof, axis=0) + 1.0
        scores.append(float((on_line > 3.0 * typical).mean()))
    return round(float(np.mean(scores)), 4)


def combine(signals: dict[str, float]) -> float:
    """Weighted geometric mean of the agreement signals (each 0..1): one weak signal pulls the confidence down."""
    total = sum(WEIGHTS.values())
    log_sum = sum(w * math.log(max(1e-4, min(1.0, float(signals.get(k, 0.0))))) for k, w in WEIGHTS.items())
    return round(math.exp(log_sum / total), 4)


@dataclass
class Proposal:
    puzzle_id: str
    fingerprint: str
    confidence: float
    signals: dict[str, float]
    image: bytes
    width: int
    height: int


def render(original: bytes, artwork: dict[str, Any]) -> tuple[np.ndarray, np.ndarray]:
    """(full-resolution oriented original, the artwork crop of it)."""
    rgb = np.asarray(normalise(decode(original)))
    box = bbox_to_xyxy(artwork["bbox"])
    corners = artwork.get("corners")
    if corners and isinstance(corners[0], dict):
        corners = [[c["x"], c["y"]] for c in corners]
    out, _ = crop(rgb, box, corners, inset=0.005)
    return rgb, out


def build(item: WorkItem, artwork: dict[str, Any], original: bytes, gate_margin: Callable[[np.ndarray], float]) -> Proposal | None:
    rgb, out = render(original, artwork)
    h, w = out.shape[:2]
    if max(w, h) < MIN_CROP_SIDE:
        return None
    small = rgb
    if max(rgb.shape[:2]) > 1600:
        im = Image.fromarray(rgb)
        im.thumbnail((1600, 1600), Image.LANCZOS)
        small = np.asarray(im)
    corners = artwork.get("corners")
    if corners and isinstance(corners[0], dict):
        corners = [[c["x"], c["y"]] for c in corners]
    signals = {
        "iou": round(iou_signal(artwork), 4),
        "gate": round(gate_probability(gate_margin(out)), 4),
        "straight": edge_straightness(small, bbox_to_xyxy(artwork["bbox"]), corners),
        "aspect": round(aspect_plausibility(w, h), 4),
        "fill": round(fill_score(artwork["bbox"], max(w, h)), 4),
    }
    image = out
    if max(w, h) > MAX_OUTPUT_SIDE:
        im = Image.fromarray(out)
        im.thumbnail((MAX_OUTPUT_SIDE, MAX_OUTPUT_SIDE), Image.LANCZOS)
        image = np.asarray(im)
    data = to_jpeg_bytes(image, 92)
    if len(data) > MAX_IMAGE_BYTES:
        data = to_jpeg_bytes(image, 85)
    return Proposal(item.puzzle_id, item.fingerprint, combine(signals), signals, data, image.shape[1], image.shape[0])


def solve_count(item: WorkItem) -> int:
    for key in ("solvedCount", "solveCount", "timesSolved", "solvedTimes"):
        if isinstance(item.raw.get(key), int):
            return int(item.raw[key])
    return 0


def already_proposed(api: InternalApi) -> set[tuple[str, str]]:
    out = set()
    for cr in api.iter_change_requests(source="image_pipeline", tag=TAG, status="all"):
        pid = cr.get("puzzleId")
        fp = cr.get("sourceImageFingerprint")
        if pid and fp:
            out.add((pid, fp))
    return out


def propose(api: InternalApi, fetch_original: Callable[[str], bytes], gate_margin: Callable[[np.ndarray], float],
            artwork_version: str, limit: int, dry_run: bool = False, threshold: float = PROPOSE_THRESHOLD) -> dict[str, Any]:
    done = already_proposed(api)
    meta = {i.puzzle_id: i for i in api.iter_images(status="all", limit=1000)}
    candidates: list[tuple[WorkItem, dict[str, Any]]] = []
    for row in api.iter_analyses("artwork", only_current=True, limit=1000):
        item = meta.get(row["puzzleId"])
        r = row.get("result") or {}
        if item is None or row.get("imageFingerprint") != item.fingerprint:
            continue
        if r.get("kind") in (None, "flat") or r.get("method") == "trim" or not r.get("bbox"):
            continue
        if (item.puzzle_id, item.fingerprint) in done:
            continue
        candidates.append((item, r))
    candidates.sort(key=lambda c: (-solve_count(c[0]), c[0].puzzle_id))
    summary: dict[str, Any] = {"candidates": len(candidates), "proposed": 0, "low_confidence": 0, "too_small": 0,
                               "already_proposed": 0, "refused": 0, "failed": 0, "items": []}
    for item, artwork in candidates:
        if summary["proposed"] >= limit:
            break
        try:
            prop = build(item, artwork, fetch_original(item.image_url), gate_margin)
        except Exception as e:  # noqa: BLE001 - one bad image never stops the run
            summary["failed"] += 1
            log.warning("[propose-crops] %s: %s", item.puzzle_id, e)
            continue
        if prop is None:
            summary["too_small"] += 1
            continue
        if prop.confidence < threshold:
            summary["low_confidence"] += 1
            continue
        entry = {"puzzleId": item.puzzle_id, "confidence": prop.confidence, "signals": prop.signals}
        if dry_run:
            summary["proposed"] += 1
            summary["items"].append(entry)
            continue
        try:
            answer = api.propose_image_change(item.puzzle_id, prop.image, confidence=prop.confidence,
                                              model_version=model_version(artwork_version),
                                              source_image_fingerprint=item.fingerprint, tags=[TAG])
        except AlreadyProposed:
            summary["already_proposed"] += 1
            continue
        except (Conflict, NotFound) as e:  # picture changed, a pending request, a hidden puzzle
            summary["refused"] += 1
            log.info("[propose-crops] %s refused: %s", item.puzzle_id, e)
            continue
        summary["proposed"] += 1
        summary["items"].append(entry | {"changeRequestId": answer.get("changeRequestId")})
    return summary
