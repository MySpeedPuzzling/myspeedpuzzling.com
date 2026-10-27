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

import base64
import json
import logging
import os
import re
import tempfile
import threading
import time
from pathlib import Path
from typing import Any, Callable

import cv2
import numpy as np
from PIL import Image

from ..analyzers import QWEN3_VL_4B, SIGLIP2
from ..imaging import iou, is_skewed, load_rgb, quad_to_box, xyxy_to_bbox
from ..payload import b64_to_vector
from . import artwork_refine
from .artwork_refine import first_pass_from, refine
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


FACE_PROMPT = """This image is the front of a jigsaw puzzle box, straightened (or a crop around a puzzle's picture).
Find the puzzle's picture only: the artwork the assembled puzzle shows.
Leave out title bands, brand strips, piece-count badges, printed frames or borders around the picture, box edges and sides, and the background.
Logos, titles or badges printed over the picture itself stay inside.
If the picture fills the whole image, answer the whole image.
Answer with JSON only: {"bbox_2d": [x1, y1, x2, y2]}"""

FACE_PROMPT_ASSEMBLED = """This image shows an assembled jigsaw puzzle, straightened (or a crop around it).
Find the puzzle's picture only: the area of the assembled puzzle.
Leave out the table, mat, frame, hands and background around the puzzle.
If the puzzle fills the whole image, answer the whole image.
Answer with JSON only: {"bbox_2d": [x1, y1, x2, y2]}"""

STRIP_PROMPT = """This is the edge of a photo of a jigsaw puzzle box, turned so that edge is at the top. A red line (edged in white) runs across it.
Look only at the part above the red line. Is it a printed band of the box - a plain coloured strip carrying the brand, title, piece count or other box text, or a plain border, the box's edge or the table - or is it a slice of the puzzle's picture itself (scenery, people, objects, patterns that continue below the line)?
Answer with JSON only: {"above": "artwork"} or {"above": "packaging"}"""

KIND_PROMPT = """What does this image of a jigsaw puzzle product show? Choose exactly one:
- "flat": a digital image, scan or painting of the puzzle's picture itself (a plain margin around it is fine)
- "box_front": a photo or scan of a puzzle box seen straight from the front
- "box_angled": a puzzle box seen at an angle, held in a hand, or a 3D render of a box
- "assembled": a photo of a finished, assembled jigsaw puzzle (piece outlines visible, on a table, in a frame, held up)
- "other": none of these (box back, several products, a leaflet, loose pieces)
Answer with JSON only: {"kind": "..."}"""


def strip_json_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {"above": {"enum": ["artwork", "packaging"]}}, "required": ["above"], "additionalProperties": False}


def face_json_schema() -> dict[str, Any]:
    coord = {"type": "integer", "minimum": 0, "maximum": 1000}
    return {"type": "object", "properties": {"bbox_2d": {"type": "array", "items": coord, "minItems": 4, "maxItems": 4}},
            "required": ["bbox_2d"], "additionalProperties": False}


def kind_json_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {"kind": {"enum": list(KINDS)}}, "required": ["kind"], "additionalProperties": False}


def parse_face(text: str) -> list[float] | None:
    """[u0, v0, u1, v1] (0..1) of the face, or None."""
    return parse_vlm(text)["box"]


def vlm_json_schema() -> dict[str, Any]:
    """The answer VLM_PROMPT asks for, for the constrained second try (coordinates 0..1000 like Qwen3-VL answers)."""
    coord = {"type": "integer", "minimum": 0, "maximum": 1000}
    point = {"type": "array", "items": coord, "minItems": 2, "maxItems": 2}
    return {
        "type": "object",
        "properties": {
            "kind": {"enum": list(KINDS)},
            "bbox_2d": {"type": "array", "items": coord, "minItems": 4, "maxItems": 4},
            "corners": {"type": "array", "items": point, "minItems": 4, "maxItems": 4},
        },
        "required": ["kind", "bbox_2d", "corners"],
        "additionalProperties": False,
    }


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
        if item.has_current("artwork"):
            continue  # 1.1 re-uses the stored first pass of this picture (any version) - no gate needed
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


def rules_from(version: str) -> tuple[int, int]:
    """`1.2.0+siglip2-q3vl4b` -> (1, 2); anything unparsable (an eval run's "eval") = the newest rules."""
    try:
        major, minor = version.split("+", 1)[0].split(".")[:2]
        return int(major), int(minor)
    except ValueError:
        return (99, 0)


FLAT_PROBE_PATH = Path(__file__).with_name("artwork_flat_probe.json")
_FLAT_PROBE: list[tuple[np.ndarray, float]] = []


def flat_probe(full: dict[str, Any] | None) -> float | None:
    """The whole-image probe's decision value (> 0 = looks like a flat digital picture) from the picture's stored
    `embed_siglip2_full` vector, or None without one. Review proposal 9 asked for a flat second opinion; on the labelled
    sets it cannot be validated as a decision (a confident value > 2 flips no tuning picture and one validation
    picture; lower values flip wrong ones too), so 1.2 stores it as a signal - a review list, never the kind."""
    vec = (full or {}).get("vector") if isinstance(full, dict) else None
    if not vec:
        return None
    if not _FLAT_PROBE:
        doc = json.loads(FLAT_PROBE_PATH.read_text())
        _FLAT_PROBE.append((np.frombuffer(base64.b64decode(doc["coef"]), dtype="<f4").astype(np.float32), float(doc["intercept"])))
    coef, intercept = _FLAT_PROBE[0]
    v = b64_to_vector(vec)
    if v.size != coef.size:
        return None
    n = float(np.linalg.norm(v))
    return round(float(v @ coef / n + intercept), 3) if n > 0 else None


class ArtworkStage(Stage):
    """1.1: the first pass (gate -> trim, else Qwen3-VL-4B + quad snap) - re-used from the picture's stored result when
    there is one - then the 1.1 pass (artwork_refine.py): quad check, widening, the kind's second opinion, the box face
    pass, geometry, quality and confidence."""

    analyzer = "artwork"
    batch_size = 1
    # this picture's stored result (1.0 or later): its first pass and (1.2) its VLM answers are re-used;
    # embed_siglip2_full (when there): the whole-image probe `signals.flatProbe` (1.2, a signal only)
    optional_upstream = ("artwork", "embed_siglip2_full")
    fetch_optional = ("artwork", "embed_siglip2_full")

    def __init__(self, version: str, workers: int = 1, workstate: Any = None) -> None:
        super().__init__(version, workers)
        self.workstate = workstate
        # the 1.2 rules only from version 1.2 on: a profile still on 1.1.x stores exactly what 1.1 stored, so the code
        # can be deployed before the version is switched (the switch is the profile's `version`)
        self.v12 = rules_from(version) >= (1, 2)
        if not self.v12:
            self.fetch_optional = ("artwork",)
        self._vlm = None
        self._sam: Any = None
        self._logits: dict[str, Any] = {}

    @property
    def model(self) -> str:
        return f"{SIGLIP2} (gate, 768 px, margin {GATE_MARGIN}) + {QWEN3_VL_4B} ({VLM_SIDE} px) + opencv quad snap + 1.1 checks"

    def load(self) -> None:
        pass  # the VLM loads on the first picture that needs it (a daily run may have none)

    def _generate(self, prompt: str, rgb: np.ndarray, schema: dict[str, Any] | None, key: str, max_tokens: int = 160) -> str:
        if self._vlm is None:
            from mlx_vlm import generate, load

            model, proc = load(QWEN3_VL_4B)
            self._vlm = (model, proc, generate, {})
        model, proc, generate, templates = self._vlm
        if prompt not in templates:
            from mlx_vlm.prompt_utils import apply_chat_template

            templates[prompt] = apply_chat_template(proc, model.config, prompt, num_images=1)
        extra: dict[str, Any] = {}
        if schema is not None:
            if key not in self._logits:
                from mlx_vlm.structured import build_json_schema_logits_processor

                tok = proc.tokenizer if hasattr(proc, "tokenizer") else proc
                self._logits[key] = build_json_schema_logits_processor(tok, schema)  # once per process
            extra["logits_processors"] = [self._logits[key].clone()]  # clone = a fresh matcher per image
        im = Image.fromarray(rgb)
        im.thumbnail((VLM_SIDE, VLM_SIDE), Image.LANCZOS)
        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as f:
            im.save(f.name)
            path = f.name
        try:
            out = generate(model, proc, templates[prompt], image=[path], max_tokens=max_tokens, temperature=0.0, verbose=False, **extra)
        finally:
            os.unlink(path)
        return out.text if hasattr(out, "text") else str(out)

    def _vlm_answer(self, rgb: np.ndarray, constrained: bool = False) -> str:
        return self._generate(VLM_PROMPT, rgb, vlm_json_schema() if constrained else None, "first")

    def ask_face(self, face_rgb: np.ndarray, kind: str = "box_front") -> list[float] | None:
        prompt = FACE_PROMPT_ASSEMBLED if kind == "assembled" else FACE_PROMPT
        return parse_face(self._generate(prompt, face_rgb, face_json_schema(), "face", max_tokens=40))

    def ask_strip(self, rgb: np.ndarray) -> str | None:
        """"artwork" / "packaging" for what lies above the red line of a face's edge strip (band_check)."""
        m = re.search(r"\{.*\}", self._generate(STRIP_PROMPT, rgb, strip_json_schema(), "strip", max_tokens=20) or "", re.S)
        try:
            v = json.loads(m.group(0)).get("above") if m else None
        except ValueError:
            v = None
        return v if v in ("artwork", "packaging") else None

    def ask_mask(self, face_rgb: np.ndarray, rect: Any) -> dict[str, Any] | None:
        """SAM 2.1's mask edges for the VLM's box on a face (artwork_sam; loaded on the first face that needs it)."""
        if self._sam is None:
            from .artwork_sam import SamMasker

            self._sam = SamMasker()
        return self._sam.edges(face_rgb, rect)

    def ask_kind(self, rgb: np.ndarray) -> str | None:
        kind = parse_vlm(self._generate(KIND_PROMPT, rgb, kind_json_schema(), "kind", max_tokens=20))["kind"]
        return kind

    def first_pass(self, p: Prepared, a: np.ndarray) -> dict[str, Any]:
        """The 1.0 first pass for a picture without a stored result (Defer until the gate has seen it)."""
        gate = self.workstate.gate(p.item.fingerprint, GATE_VERSION) if self.workstate is not None else None
        if gate is None:
            raise Defer("not gated yet")
        flat, margin = gate_decision(gate["scores"])
        if flat:
            h, w = a.shape[:2]
            x0, y0, x1, y1 = trim_uniform_border(a)
            return {"kind": "flat", "method": "trim", "bbox": [x0 / w, y0 / h, x1 / w, y1 / h], "quad": None,
                    "gateScores": gate["scores"], "gateMargin": margin, "vlmKind": None, "vlmBbox": None, "quadIou": None}
        answer = self._vlm_answer(p.rgb)
        vlm = parse_vlm(answer)
        if vlm["kind"] is None or vlm["box"] is None:
            # greedy decoding answers the same broken JSON on every retry (a stray token in a key or a number), so
            # ask once more constrained to the schema; a valid unconstrained answer is never re-asked, so the
            # results stay those of the version
            log.info("[artwork] %s: unparsable VLM answer, asking again constrained to the schema", p.item.puzzle_id)
            answer = self._vlm_answer(p.rgb, constrained=True)
            vlm = parse_vlm(answer)
        if vlm["kind"] is None or vlm["box"] is None:
            # never store a guess ("flat" + the whole image) for a picture the gate called a photo: a failure is
            # retried (3 attempts per version), then left for a later version
            raise ValueError(f"the VLM answer has no usable kind / box: {answer[:200]!r}")
        hy = hybrid(a, vlm)
        return {"kind": hy["kind"], "method": hy["method"], "bbox": [round(v, 5) for v in hy["box"]],
                "quad": [[round(x, 5), round(y, 5)] for x, y in hy["quad"]] if hy["quad"] is not None else None,
                "gateScores": gate["scores"], "gateMargin": margin, "vlmKind": vlm["kind"],
                "vlmBbox": [round(v, 5) for v in vlm["box"]], "quadIou": hy["quadIou"],
                # 1.2: the VLM's own corners, kept even when the quad snap is refused (review proposal 5)
                "vlmQuad": [[round(x, 5), round(y, 5)] for x, y in vlm["quad"]] if vlm.get("quad") else None}

    def analyse(self, p: Prepared) -> dict[str, Any]:
        a = p.rgb
        if max(a.shape[:2]) > SNAP_SIDE:
            im = Image.fromarray(a)
            im.thumbnail((SNAP_SIDE, SNAP_SIDE), Image.LANCZOS)
            a = np.asarray(im)
        fp = first_pass_from(p.upstream.get("artwork"))
        reused = fp is not None
        if fp is None:
            fp = self.first_pass(p, a)
        out = refine(p.rgb, a, fp, (p.image.width, p.image.height),
                     (p.image.original_width or p.image.width, p.image.original_height or p.image.height),
                     ask_kind=self.ask_kind, ask_face=self.ask_face, ask_strip=self.ask_strip,
                     prior=p.upstream.get("artwork") if reused else None,
                     ask_mask=self.ask_mask if artwork_refine.SAM_SIDES else None, v12=self.v12)
        out["signals"]["firstPassReused"] = reused
        if self.v12:
            out["signals"]["flatProbe"] = flat_probe(p.upstream.get("embed_siglip2_full"))
        else:
            out["firstPass"].pop("vlmQuad", None)
        return out

    def close(self) -> None:
        self._vlm = None
        self._sam = None
        self._logits = {}
