"""`artwork` - where the puzzle's picture is in the box image (vision-analysis.md §1, §4, §9.1).

Two passes, each its own process and model:
1. **gate** (SigLIP 2 so400m zero-shot, 768 px): flat artwork vs box photo / render / assembled puzzle. Gate scores go
   into the workstate only (walks the stale list, stores nothing).
2. **artwork** (claims): a gate-flat picture gets the deterministic border trim (OpenCV, ~5 ms); everything else goes
   to Qwen3-VL-4B-Instruct 4-bit (mlx-vlm, 672 px) for the picture's box + corners + kind, snapped to an OpenCV
   quadrilateral when one agrees (IoU > 0.75 -> exact edges + perspective corners). mIoU 0.915 on the lab sample.

Payload (the server validates `bbox` / `corners`): kind, bbox {x,y,w,h}, corners (only when skewed), method
(trim | vlm | vlm+quad), confidence (gate margin / quad IoU / 0.5), kindScores, source {width, height}, signals
(the raw agreement signals the crop proposals reuse).
"""

from __future__ import annotations

import json
import logging
import os
import re
import tempfile
import threading
import time
from typing import Any, Callable

import cv2
import numpy as np
from PIL import Image

from ..analyzers import QWEN3_VL_4B, SIGLIP2
from ..imaging import iou, is_skewed, load_rgb, quad_to_box, xyxy_to_bbox
from .base import Defer, Prepared, Stage

log = logging.getLogger(__name__)

GATE_VERSION = "siglip2-so400m-768-m0.01"
GATE_MARGIN = 0.01
GATE_SIDE = 768
VLM_SIDE = 672
SNAP_SIDE = 1024
KINDS = ("flat", "box_front", "box_angled", "assembled", "other")

GATE_LABELS: dict[str, list[str]] = {
    "flat": ["a flat digital image of a jigsaw puzzle picture", "a digital illustration", "a painting", "a photograph"],
    "box": ["a photo of a jigsaw puzzle box", "a 3D render of a cardboard box",
            "a product photo of a boxed jigsaw puzzle with logo and piece count"],
    "assembled": ["a photo of an assembled jigsaw puzzle lying on a table", "a close-up photo of jigsaw puzzle pieces"],
    "other": ["a photo of several products", "the back side of a box with text"],
}

VLM_PROMPT = """This image shows a jigsaw puzzle product. Find the puzzle's picture: the artwork that the assembled puzzle shows.
Classify the image as one of:
- "flat": the image is the picture itself (a plain margin around it is fine)
- "box_front": a straight-on photo or scan of the box front
- "box_angled": a box seen at an angle, held in a hand, or a 3D render of a box
- "assembled": a photo of the assembled puzzle (on a table, in a frame, held up)
- "other": anything else (box back, several products, leaflet, pieces only)
Then give the bounding box of the picture only - exclude box edges and sides, title or logo strips outside the picture, the table and the background. Logos or badges printed over the picture stay inside.
Also give the picture's four corners (top-left, top-right, bottom-right, bottom-left) - for an angled box these follow the perspective.
Answer with JSON only: {"kind": "...", "bbox_2d": [x1, y1, x2, y2], "corners": [[x, y], [x, y], [x, y], [x, y]]}"""


# --- classic OpenCV (detect_classic.py) -----------------------------------------------------------------------------
def trim_uniform_border(a: np.ndarray, tol: float = 10.0) -> tuple[int, int, int, int]:
    """Rows/columns at the edges in one flat colour (letterbox / white canvas) trimmed. Returns x0, y0, x1, y1 px."""
    h, w = a.shape[:2]
    g = a.astype(np.float32)

    def flat(line: np.ndarray, ref: np.ndarray) -> bool:
        return bool(line.std(axis=0).max() < tol and np.abs(line.mean(axis=0) - ref).max() < 2.5 * tol)

    ref = np.median(np.concatenate([g[0], g[-1], g[:, 0], g[:, -1]]), axis=0)
    y0 = 0
    while y0 < h * 0.45 and flat(g[y0], ref):
        y0 += 1
    y1 = h
    while y1 > h * 0.55 and flat(g[y1 - 1], ref):
        y1 -= 1
    x0 = 0
    while x0 < w * 0.45 and flat(g[y0:y1, x0], ref):
        x0 += 1
    x1 = w
    while x1 > w * 0.55 and flat(g[y0:y1, x1 - 1], ref):
        x1 -= 1
    return x0, y0, x1, y1


def order_quad(pts: Any) -> np.ndarray:
    pts = np.asarray(pts, dtype=np.float32).reshape(4, 2)
    s = pts.sum(1)
    d = np.diff(pts, axis=1).ravel()
    return np.array([pts[np.argmin(s)], pts[np.argmin(d)], pts[np.argmax(s)], pts[np.argmax(d)]], dtype=np.float32)


def largest_quad(a: np.ndarray, min_area: float = 0.12, max_area: float = 0.97) -> tuple[float, np.ndarray] | None:
    h, w = a.shape[:2]
    gray = cv2.cvtColor(a, cv2.COLOR_RGB2GRAY)
    best = None
    for blur in (5, 9):
        b = cv2.GaussianBlur(gray, (blur, blur), 0)
        v = float(np.median(b))
        edges = cv2.Canny(b, int(max(0, 0.66 * v)), int(min(255, 1.33 * v)))
        edges = cv2.dilate(edges, np.ones((3, 3), np.uint8), iterations=2)
        cnts, _ = cv2.findContours(edges, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
        for c in cnts:
            ap = cv2.approxPolyDP(c, 0.02 * cv2.arcLength(c, True), True)
            if len(ap) != 4 or not cv2.isContourConvex(ap):
                continue
            area = cv2.contourArea(ap) / (w * h)
            if not (min_area <= area <= max_area):
                continue
            q = order_quad(ap)
            ok = True
            for k in range(4):
                v1, v2 = q[k - 1] - q[k], q[(k + 1) % 4] - q[k]
                if abs(np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-6)) > 0.35:
                    ok = False
            if ok and (best is None or area > best[0]):
                best = (area, q)
    return best


# --- gate -----------------------------------------------------------------------------------------------------------
def gate_decision(scores: dict[str, float], margin: float = GATE_MARGIN) -> tuple[bool, float]:
    """Flat when the best flat prompt beats the best other prompt by more than `margin` (0.01: no non-flat image was
    ever sent to the trim path in the lab, 37 % of the sample skipped the VLM)."""
    other = max(scores.get("box", -1.0), scores.get("assembled", -1.0), scores.get("other", -1.0))
    m = float(scores.get("flat", -1.0)) - other
    return m > margin, round(m, 4)


class Gate:
    """SigLIP 2 zero-shot kind scores (max cosine per kind)."""

    def __init__(self) -> None:
        import torch
        from transformers import AutoModel, AutoProcessor

        self.torch = torch
        self.device = "mps" if torch.backends.mps.is_available() else "cpu"
        self.model = AutoModel.from_pretrained(SIGLIP2, dtype=torch.float16).to(self.device).eval()
        self.proc = AutoProcessor.from_pretrained(SIGLIP2)
        texts = [t for v in GATE_LABELS.values() for t in v]
        self.owner = [k for k, v in GATE_LABELS.items() for _ in v]
        with torch.no_grad():
            ti = self.proc(text=texts, padding="max_length", max_length=64, return_tensors="pt").to(self.device)
            tf = self.model.get_text_features(**ti)
            tf = getattr(tf, "pooler_output", tf)
            self.tf = tf / tf.norm(dim=-1, keepdim=True)

    def scores(self, im: Image.Image) -> dict[str, float]:
        torch = self.torch
        with torch.no_grad():
            ii = self.proc(images=im, return_tensors="pt").to(self.device)
            f = self.model.get_image_features(pixel_values=ii["pixel_values"].half())
            f = getattr(f, "pooler_output", f)
            f = f / f.norm(dim=-1, keepdim=True)
            s = (f @ self.tf.T)[0].float().cpu().numpy()
        out: dict[str, float] = {}
        for j, k in enumerate(self.owner):
            out[k] = round(max(out.get(k, -1.0), float(s[j])), 4)
        return out


def run_gate(api: Any, downloader: Any, workstate: Any, version: str, stop: threading.Event,
             deadline: float | None = None, max_images: int | None = None,
             gate_factory: Callable[[], Any] = Gate) -> int:
    """Pass 1: gate every picture still to do for this artwork version (the stale list - read-only, stores nothing)."""
    gate = None
    done = 0
    for item in api.iter_images(analyzer="artwork", version=version, status="stale", limit=500):
        if stop.is_set() or (deadline and time.time() >= deadline) or (max_images and done >= max_images):
            break
        if workstate.gate(item.fingerprint, GATE_VERSION) is not None:
            continue
        try:
            local = downloader.get(item.puzzle_id, item.fingerprint, item.image_url)
            im = Image.fromarray(load_rgb(local.path, GATE_SIDE))
        except Exception as e:  # noqa: BLE001 - the claim pass retries it
            log.warning("[artwork-gate] %s: %s", item.puzzle_id, e)
            continue
        if gate is None:
            gate = gate_factory()
        scores = gate.scores(im)
        flat, _ = gate_decision(scores)
        workstate.put_gate(item.fingerprint, GATE_VERSION, scores, flat)
        done += 1
    log.info("[artwork-gate] gated %d pictures", done)
    return done


# --- VLM ------------------------------------------------------------------------------------------------------------
def parse_vlm(text: str) -> dict[str, Any]:
    """Qwen3-VL answers 0..1000 relative coordinates; returns kind, box [x0,y0,x1,y1] (0..1) and quad or None."""
    m = re.search(r"\{.*\}", text or "", re.S)
    j: dict[str, Any] = {}
    if m:
        try:
            j = json.loads(m.group(0))
        except ValueError:
            j = {}
    kind = j.get("kind") if j.get("kind") in KINDS else None
    box = None
    try:
        b = [float(v) / 1000 for v in j["bbox_2d"]][:4]
        box = [max(0.0, b[0]), max(0.0, b[1]), min(1.0, b[2]), min(1.0, b[3])]
        if box[2] - box[0] <= 0.01 or box[3] - box[1] <= 0.01:
            box = None
    except (KeyError, TypeError, ValueError, IndexError):
        box = None
    quad = None
    try:
        quad = [[min(1.0, max(0.0, float(c[0]) / 1000)), min(1.0, max(0.0, float(c[1]) / 1000))] for c in j["corners"]][:4]
        if len(quad) != 4:
            quad = None
    except (KeyError, TypeError, ValueError, IndexError):
        quad = None
    return {"kind": kind, "box": box, "quad": quad}


def hybrid(a: np.ndarray, vlm: dict[str, Any]) -> dict[str, Any]:
    """The lab's hybrid.py on a <= 1024 px image: VLM box snapped to an OpenCV quad when one agrees."""
    h, w = a.shape[:2]
    x0, y0, x1, y1 = trim_uniform_border(a)
    trim = [x0 / w, y0 / h, x1 / w, y1 / h]
    box = vlm.get("box") or [0.0, 0.0, 1.0, 1.0]
    kind = vlm.get("kind") or "flat"
    if kind == "flat":
        agree = iou(trim, box) > 0.8
        return {"kind": kind, "box": trim if agree else box, "quad": None, "method": "trim" if agree else "vlm", "quadIou": None}
    m = 0.06
    X0, Y0, X1, Y1 = max(0.0, box[0] - m), max(0.0, box[1] - m), min(1.0, box[2] + m), min(1.0, box[3] + m)
    sub = a[int(Y0 * h):int(Y1 * h), int(X0 * w):int(X1 * w)]
    q = largest_quad(sub, min_area=0.35, max_area=0.99) if sub.shape[0] > 32 and sub.shape[1] > 32 else None
    out = {"kind": kind, "box": box, "quad": None, "method": "vlm", "quadIou": None}
    if q is not None:
        sh, sw = sub.shape[:2]
        quad = [[float(X0 + p[0] / sw * (X1 - X0)), float(Y0 + p[1] / sh * (Y1 - Y0))] for p in q[1]]
        agreement = iou(quad_to_box(quad), box)
        out["quadIou"] = round(agreement, 4)
        if agreement > 0.75:
            out.update(box=quad_to_box(quad), quad=quad, method="vlm+quad")
    return out


def payload(kind: str, box: list[float], quad: list[list[float]] | None, method: str, gate_scores: dict[str, float],
            gate_margin: float, source: tuple[int, int], vlm: dict[str, Any] | None = None, quad_iou: float | None = None) -> dict[str, Any]:
    if method == "trim":
        confidence = max(0.0, min(1.0, gate_margin))  # vision-analysis.md §9.1: the gate margin
    elif method == "vlm+quad":
        confidence = float(quad_iou or 0.0)
    else:
        confidence = 0.5
    corners = None
    if quad is not None and is_skewed(quad):
        corners = [[round(min(1.0, max(0.0, x)), 5), round(min(1.0, max(0.0, y)), 5)] for x, y in quad]
    return {
        "kind": kind,
        "bbox": xyxy_to_bbox(box),
        "corners": corners,
        "method": method,
        "confidence": round(confidence, 4),
        "kindScores": gate_scores,
        "source": {"width": int(source[0]), "height": int(source[1])},
        "signals": {
            "gateMargin": gate_margin,
            "vlmKind": (vlm or {}).get("kind"),
            "vlmBbox": xyxy_to_bbox((vlm or {})["box"]) if (vlm or {}).get("box") else None,
            "quadIou": quad_iou,
        },
    }


def trim_payload(a: np.ndarray, gate_scores: dict[str, float], gate_margin: float, source: tuple[int, int]) -> dict[str, Any]:
    h, w = a.shape[:2]
    x0, y0, x1, y1 = trim_uniform_border(a)
    return payload("flat", [x0 / w, y0 / h, x1 / w, y1 / h], None, "trim", gate_scores, gate_margin, source)


class ArtworkStage(Stage):
    analyzer = "artwork"
    batch_size = 1

    def __init__(self, version: str, workers: int = 1, workstate: Any = None) -> None:
        super().__init__(version, workers)
        self.workstate = workstate
        self._vlm = None

    @property
    def model(self) -> str:
        return f"{SIGLIP2} (gate, 768 px, margin {GATE_MARGIN}) + {QWEN3_VL_4B} ({VLM_SIDE} px) + opencv quad snap"

    def load(self) -> None:
        pass  # the VLM loads on the first non-flat picture (a daily run may have none)

    def _vlm_answer(self, rgb: np.ndarray) -> str:
        if self._vlm is None:
            from mlx_vlm import generate, load
            from mlx_vlm.prompt_utils import apply_chat_template

            model, proc = load(QWEN3_VL_4B)
            prompt = apply_chat_template(proc, model.config, VLM_PROMPT, num_images=1)
            self._vlm = (model, proc, prompt, generate)
        model, proc, prompt, generate = self._vlm
        im = Image.fromarray(rgb)
        im.thumbnail((VLM_SIDE, VLM_SIDE), Image.LANCZOS)
        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as f:
            im.save(f.name)
            path = f.name
        try:
            out = generate(model, proc, prompt, image=[path], max_tokens=160, temperature=0.0, verbose=False)
        finally:
            os.unlink(path)
        return out.text if hasattr(out, "text") else str(out)

    def analyse(self, p: Prepared) -> dict[str, Any]:
        gate = self.workstate.gate(p.item.fingerprint, GATE_VERSION) if self.workstate is not None else None
        if gate is None:
            raise Defer("not gated yet")
        flat, margin = gate_decision(gate["scores"])
        a = p.rgb
        if max(a.shape[:2]) > SNAP_SIDE:
            im = Image.fromarray(a)
            im.thumbnail((SNAP_SIDE, SNAP_SIDE), Image.LANCZOS)
            a = np.asarray(im)
        source = (p.image.width, p.image.height)
        if flat:
            return trim_payload(a, gate["scores"], margin, source)
        answer = self._vlm_answer(p.rgb)
        vlm = parse_vlm(answer)
        if vlm["kind"] is None or vlm["box"] is None:
            # never store a guess ("flat" + the whole image) for a picture the gate called a photo: a failure is
            # retried (3 attempts per version), then left for a later version
            raise ValueError(f"the VLM answer has no usable kind / box: {answer[:200]!r}")
        h = hybrid(a, vlm)
        return payload(h["kind"], h["box"], h["quad"], h["method"], gate["scores"], margin, source, vlm, h["quadIou"])

    def close(self) -> None:
        self._vlm = None
