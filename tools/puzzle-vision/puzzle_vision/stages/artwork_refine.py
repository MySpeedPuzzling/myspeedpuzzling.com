"""`artwork` 1.1: a pass over the first-pass geometry (gate + Qwen3-VL-4B + quad snap of 1.0) that fixes what review
found (docs/features/insights/pipeline-driver.md "Findings"), CPU parts here, model parts in artwork.py:

1. a snapped quad is kept only when each of its sides is a real picture edge (artwork_check.side_measures), else the
   VLM box is used - a wrong quad cut triangles off the picture and sheared it;
2. a side of an axis-aligned crop across which the picture continues is moved out to the next real edge, else to the
   photo's border - never cut artwork; box print left in is the lesser evil;
3. front / angled from the outline's geometry, small originals, image quality, a confidence built from agreement.

The first pass is re-used from the stored result (`first_pass_from`): 1.0 results carry the gate scores, the VLM's
kind and box and the accepted quad; 1.1 results keep them under `firstPass` so later versions can do the same.
"""

from __future__ import annotations

from typing import Any, Sequence

import cv2
import numpy as np

from ..imaging import bbox_to_xyxy, iou, is_skewed, quad_to_box
from .artwork_check import box_quad, lab_image, quad_angles, side_measures

# a side is a real picture edge when it sits on a colour step along most of its length and the colours just
# outside are not the picture's own (tuned on the tuning set, never on the reference set)
QUAD_SIDE_SUPPORT = 0.8  # a snapped quad is kept only when every side (not on the photo border) sits on a step this often
SIDE_SUPPORT = 0.55       # below this a side of an axis-aligned crop is not on an edge
SMALL_ORIGINAL = 500      # long side of the original below this: fine for embeddings, never a crop proposal
LOW_RES_ARTWORK = 700     # the artwork's long side in the original below this: too small for a catalogue picture
                          # (found 47 of 48 labelled "low resolution" in the reference set, 4 false)
SMALL_IN_PHOTO = 0.25     # the artwork fills less than this share of the photo
ANGLED_DEG, ANGLED_RATIO = 6.0, 0.93
NO_CLEAN_CROP_SIDES = 2   # fewer confirmed sides than this on a photo = the crop could not be confirmed
HYBRID = True             # keep a rejected quad's confirmed sides (see hybrid_quad)
VLM_PAD = 0.02            # VLM-only crops move out by this share of their size per side: the VLM box is often 1-5 %
                          # tight (tuning set: cuts 19 -> 13, print left in 58 -> 75 - the face pass trims boxes)


# --- the first pass -------------------------------------------------------------------------------------------------
def first_pass_from(result: dict[str, Any] | None) -> dict[str, Any] | None:
    """The first-pass geometry of a stored `artwork` result (1.0 or later), or None when it carries none."""
    if not isinstance(result, dict):
        return None
    if isinstance(result.get("firstPass"), dict):
        return result["firstPass"]
    if not result.get("bbox") or not result.get("method"):
        return None
    s = result.get("signals") or {}
    method = result["method"]
    bbox = bbox_to_xyxy(result["bbox"])
    quad = None
    if method == "vlm+quad":
        quad = [[float(x), float(y)] for x, y in result["corners"]] if result.get("corners") else box_quad(bbox)
    vlm_bbox = bbox_to_xyxy(s["vlmBbox"]) if s.get("vlmBbox") else (bbox if method == "vlm" else None)
    return {
        "kind": result.get("kind"),
        "method": method,
        "bbox": [round(v, 5) for v in bbox],
        "quad": quad,
        "gateScores": result.get("kindScores"),
        "gateMargin": s.get("gateMargin"),
        "vlmKind": s.get("vlmKind"),
        "vlmBbox": [round(v, 5) for v in vlm_bbox] if vlm_bbox else None,
        "quadIou": s.get("quadIou"),
    }


# --- sides ----------------------------------------------------------------------------------------------------------
def quad_side_ok(m: dict[str, Any]) -> bool:
    return bool(m["photoEdge"] or m["support"] >= QUAD_SIDE_SUPPORT)


def side_ok(m: dict[str, Any]) -> bool:
    return bool(m["photoEdge"] or m["support"] >= SIDE_SUPPORT)


def picture_continues(m: dict[str, Any]) -> bool:
    """No colour step on the line: the crop may stop inside the picture, so the side moves out (never cut). Asking
    also for similar colours across the line (strip similarity >= 0.6) left 13 cuts on the tuning set, without it 4
    (print left in 75 -> 81)."""
    return not m["photoEdge"] and m["support"] < SIDE_SUPPORT


def widen_box(lab: np.ndarray, box: Sequence[float]) -> tuple[list[float], dict[str, Any]]:
    """Sides of an axis-aligned crop that are not on a real edge move out to the photo's border. Searching outward
    for the next edge instead stopped on edges inside the picture: 10 cuts on the tuning set against 4, for the same
    print left in (82 vs 81)."""
    measures = side_measures(lab, box_quad(box))
    out = list(box)
    moved: dict[str, Any] = {}
    for m in measures:
        if not picture_continues(m):
            continue
        idx = {"left": 0, "top": 1, "right": 2, "bottom": 3}[m["side"]]
        pos = 0.0 if idx < 2 else 1.0
        if out[idx] != pos:
            moved[m["side"]] = {"from": round(out[idx], 4), "to": pos}
            out[idx] = pos
    return out, {"sides": measures, "moved": moved}


# --- quality --------------------------------------------------------------------------------------------------------
def sharpness(rgb_crop: np.ndarray) -> float:
    """Variance of the Laplacian of the crop at 512 px long side, normalised by its contrast - low = blurry or upscaled."""
    if rgb_crop.size == 0:
        return 0.0
    g = cv2.cvtColor(rgb_crop, cv2.COLOR_RGB2GRAY).astype(np.float32)
    s = 512 / max(g.shape)
    if s < 1:
        g = cv2.resize(g, (max(1, int(g.shape[1] * s)), max(1, int(g.shape[0] * s))), interpolation=cv2.INTER_AREA)
    lap = cv2.Laplacian(g, cv2.CV_32F)
    contrast = float(g.std()) + 1.0
    return round(float(lap.var()) / (contrast * contrast), 4)


def exposure(rgb_crop: np.ndarray) -> dict[str, float]:
    g = cv2.cvtColor(rgb_crop, cv2.COLOR_RGB2GRAY)
    hsv = cv2.cvtColor(rgb_crop, cv2.COLOR_RGB2HSV)
    glare = (hsv[..., 2] >= 250) & (hsv[..., 1] <= 20)
    return {
        "lightness": round(float(g.mean()) / 255, 3),
        "contrast": round(float(g.std()) / 128, 3),
        "darkShare": round(float((g <= 20).mean()), 4),
        "brightShare": round(float((g >= 245).mean()), 4),
        "glareShare": round(float(glare.mean()), 4),
    }


def quality(kind: str, final_quad: Sequence[Sequence[float]], source: tuple[int, int], original: tuple[int, int],
            crop_rgb: np.ndarray, confirmed_sides: int) -> dict[str, Any]:
    q = np.asarray(final_quad, float)
    ow, oh = original
    p = q * [ow, oh]
    art_w = (np.linalg.norm(p[1] - p[0]) + np.linalg.norm(p[2] - p[3])) / 2
    art_h = (np.linalg.norm(p[3] - p[0]) + np.linalg.norm(p[2] - p[1])) / 2
    fill = float(abs(cv2.contourArea(q.astype(np.float32))))
    sharp = sharpness(crop_rgb)
    exp = exposure(crop_rgb) if crop_rgb.size else {}
    reasons = []
    if max(ow, oh) < SMALL_ORIGINAL:
        reasons.append("small_original")
    if max(art_w, art_h) < LOW_RES_ARTWORK:
        reasons.append("low_resolution_artwork")
    # no "blurry" reason: neither the Laplacian nor a scale-ratio measure told labelled blurry pictures from smooth
    # artwork on the tuning set - `sharpness` stays as a raw number for later use
    if kind != "flat" and fill < SMALL_IN_PHOTO:
        reasons.append("artwork_small_in_photo")
    if kind == "other":
        reasons.append("not_the_picture")
    if kind != "flat" and confirmed_sides < NO_CLEAN_CROP_SIDES:
        reasons.append("no_clean_crop")
    # no "glare" reason: near-white, unsaturated pixels were white backgrounds and white artwork more often than
    # glare on the tuning set (13 fine vs 7 labelled) - `glareShare` stays a raw number
    if exp.get("lightness", 0.5) < 0.15:
        reasons.append("dark")
    return {
        "needsBetterImage": bool(reasons),
        "reasons": reasons,
        "originalSize": {"width": int(ow), "height": int(oh)},
        "artworkPixels": {"width": int(round(art_w)), "height": int(round(art_h))},
        "artworkShare": round(fill, 4),
        "sharpness": sharp,
        **exp,
    }


def geometry(quad: Sequence[Sequence[float]], source: tuple[int, int]) -> dict[str, Any]:
    a = quad_angles(quad, source)
    a["view"] = "angled" if a["cornerDeg"] > ANGLED_DEG or a["sideRatio"] < ANGLED_RATIO else "front"
    a["aspect"] = round(_aspect(quad, source), 4)
    return a


def _aspect(quad: Sequence[Sequence[float]], source: tuple[int, int]) -> float:
    p = np.asarray(quad, float) * [source[0], source[1]]
    w = (np.linalg.norm(p[1] - p[0]) + np.linalg.norm(p[2] - p[3])) / 2
    h = (np.linalg.norm(p[3] - p[0]) + np.linalg.norm(p[2] - p[1])) / 2
    return float(w / max(h, 1e-6))


def _line(p: np.ndarray, q: np.ndarray) -> np.ndarray:
    return np.cross(np.append(p, 1.0), np.append(q, 1.0))


def hybrid_quad(quad: Sequence[Sequence[float]], ok: Sequence[bool], box: Sequence[float], aspect: float) -> list[list[float]] | None:
    """The quad with every unconfirmed side replaced by the matching side of `box` (an outer, axis-aligned box):
    confirmed sides keep the perspective, the others can only move out. None when the result is not a sane convex
    quad inside the photo that contains the old one's confirmed part."""
    q = np.asarray(quad, float) * [aspect, 1.0]
    x0, y0, x1, y1 = box[0] * aspect, box[1], box[2] * aspect, box[3]
    outer = {0: ((x0, y0), (x1, y0)), 1: ((x1, y0), (x1, y1)), 2: ((x1, y1), (x0, y1)), 3: ((x0, y1), (x0, y0))}
    lines = []
    for k in range(4):
        a, b = (q[k], q[(k + 1) % 4]) if ok[k] else (np.asarray(outer[k][0]), np.asarray(outer[k][1]))
        lines.append(_line(np.asarray(a, float), np.asarray(b, float)))
    pts = []
    for k in range(4):
        p = np.cross(lines[k - 1], lines[k])  # corner k = previous side x this side
        if abs(p[2]) < 1e-9:
            return None
        pts.append(p[:2] / p[2])
    out = np.asarray(pts) / [aspect, 1.0]
    if out.min() < -0.01 or out.max() > 1.01:
        return None
    out = np.clip(out, 0.0, 1.0)
    if not cv2.isContourConvex(out.astype(np.float32).reshape(-1, 1, 2)):
        return None
    return [[round(float(x), 5), round(float(y), 5)] for x, y in out]


def crop_geometry(a: np.ndarray, fp: dict[str, Any]) -> dict[str, Any]:
    """Steps 1-2 on the <= 1024 px working image: which outline the crop uses and why. Answers bbox [x0,y0,x1,y1],
    quad (TL, TR, BR, BL; None = the bbox), method, and the side measures as signals."""
    lab = lab_image(a)
    if fp.get("method") == "trim":
        return {"bbox": list(fp["bbox"]), "quad": None, "method": "trim", "signals": {}}
    signals: dict[str, Any] = {}
    quad = fp.get("quad")
    if quad is not None:
        measures = side_measures(lab, quad)
        signals["quadSides"] = measures
        if all(quad_side_ok(m) for m in measures):
            signals["quad"] = "accepted"
            return {"bbox": quad_to_box(quad), "quad": quad, "method": "vlm+quad", "signals": signals, "confirmed": 4}
        signals["quad"] = "rejected"
    box = list(fp.get("vlmBbox") or fp["bbox"])
    if quad is not None:
        # a rejected quad still marks where the VLM box was not enough: the crop takes both, never the smaller
        qb = quad_to_box(quad)
        box = [min(box[0], qb[0]), min(box[1], qb[1]), max(box[2], qb[2]), max(box[3], qb[3])]
        ok = [quad_side_ok(m) for m in signals["quadSides"]]
        if HYBRID and sum(ok) >= 2 and is_skewed(quad):
            hq = hybrid_quad(quad, ok, box, a.shape[1] / a.shape[0])
            if hq is not None:
                signals["quad"] = "partly"
                return {"bbox": quad_to_box(hq), "quad": hq, "method": "vlm+quad", "signals": signals,
                        "confirmed": sum(ok), "quadRejected": True}
    if VLM_PAD > 0:
        pw, ph = VLM_PAD * (box[2] - box[0]), VLM_PAD * (box[3] - box[1])
        box = [max(0.0, box[0] - pw), max(0.0, box[1] - ph), min(1.0, box[2] + pw), min(1.0, box[3] + ph)]
    widened, info = widen_box(lab, box)
    signals["boxSides"] = info["sides"]
    if info["moved"]:
        signals["widened"] = info["moved"]
    confirmed = sum(1 for m in info["sides"] if side_ok(m) and m["side"] not in info["moved"])
    method = "vlm+widen" if info["moved"] else "vlm"
    return {"bbox": widened, "quad": None, "method": method, "signals": signals, "confirmed": confirmed,
            "quadRejected": quad is not None}


def confidence(method: str, confirmed: int, fp: dict[str, Any], final_box: Sequence[float]) -> float:
    """One number from the agreement signals (0..1): sides confirmed as real edges, VLM box vs final crop, and the
    gate agreeing with a flat picture. Calibrated on the tuning set's labels."""
    if method == "trim":
        m = fp.get("gateMargin") or 0.0
        return round(min(1.0, 0.85 + 2.0 * max(0.0, m)), 4)
    agree = iou(final_box, fp["vlmBbox"]) if fp.get("vlmBbox") else 0.5
    sides = confirmed / 4
    return round(float(0.15 + 0.55 * sides + 0.3 * agree), 4)


def is_quad_skewed(quad: Sequence[Sequence[float]] | None) -> bool:
    return quad is not None and is_skewed(quad)


# --- the box face pass (finding 2) ------------------------------------------------------------------------------------
FACE_KINDS = ("box_front", "box_angled")
FACE_SIDE = 1024          # the straightened face the VLM sees (it resizes to its own 672 px)
FACE_MAX_TRIM = 0.35      # never trim more than this share of a side
FACE_MIN_TRIM = 0.015     # thinner than this is the crop's own inset - leave it
FACE_EDGE_SUPPORT = 0.55  # the VLM's line must sit on a real colour step this often to be believed


def straighten(rgb: np.ndarray, quad_rel: Sequence[Sequence[float]], side: int = FACE_SIDE) -> tuple[np.ndarray, np.ndarray]:
    """The outline warped to an upright rectangle like imaging.crop() sizes it; answers it and face px -> image px."""
    h, w = rgb.shape[:2]
    q = (np.asarray(quad_rel, float) * [w, h]).astype(np.float32)
    tw = max(np.linalg.norm(q[1] - q[0]), np.linalg.norm(q[2] - q[3]))
    th = max(np.linalg.norm(q[3] - q[0]), np.linalg.norm(q[2] - q[1]))
    s = min(1.0, side / max(tw, th, 1.0))
    fw, fh = max(16, int(round(tw * s))), max(16, int(round(th * s)))
    m = cv2.getPerspectiveTransform(q, np.float32([[0, 0], [fw, 0], [fw, fh], [0, fh]]))
    face = cv2.warpPerspective(rgb, m, (fw, fh), flags=cv2.INTER_AREA, borderMode=cv2.BORDER_REPLICATE)
    return face, np.linalg.inv(m)


def map_rect(rect_face: Sequence[float], face_wh: tuple[int, int], h_face_to_img: np.ndarray, img_wh: tuple[int, int]) -> list[list[float]]:
    """Relative face rectangle [u0, v0, u1, v1] -> relative image quad TL, TR, BR, BL."""
    fw, fh = face_wh
    u0, v0, u1, v1 = rect_face
    pts = np.float32([[[u0 * fw, v0 * fh]], [[u1 * fw, v0 * fh]], [[u1 * fw, v1 * fh]], [[u0 * fw, v1 * fh]]])
    out = cv2.perspectiveTransform(pts, h_face_to_img.astype(np.float64)).reshape(4, 2)
    out = np.clip(out / [img_wh[0], img_wh[1]], 0.0, 1.0)
    return [[round(float(x), 5), round(float(y), 5)] for x, y in out]


def face_pass(rgb: np.ndarray, quad: Sequence[Sequence[float]], ask: Any) -> tuple[list[list[float]] | None, dict[str, Any]]:
    """The picture inside a box face. `ask(face_rgb)` = the VLM's [u0, v0, u1, v1] (0..1 of the face) or None.
    Each side moves in only when the VLM wants at least FACE_MIN_TRIM, at most FACE_MAX_TRIM, and its line sits on a
    real colour step on the face (art crossing the line = no step = veto: never cut artwork). Answers the new
    outline (TL, TR, BR, BL of the image) or None when nothing moved, and what happened per side."""
    face, h_f2i = straighten(rgb, quad)
    fh, fw = face.shape[:2]
    info: dict[str, Any] = {"face": {"width": fw, "height": fh}}
    rect = ask(face)
    if rect is None:
        info["answer"] = None
        return None, info
    u0, v0, u1, v1 = (float(v) for v in rect)
    info["answer"] = [round(u0, 4), round(v0, 4), round(u1, 4), round(v1, 4)]
    want = {"left": u0, "top": v0, "right": 1.0 - u1, "bottom": 1.0 - v1}
    lab = lab_image(face)
    measures = {m["side"]: m for m in side_measures(lab, box_quad([u0, v0, u1, v1]))}
    trims: dict[str, float] = {}
    sides: dict[str, Any] = {}
    for side, t in want.items():
        m = measures[side]
        if t < FACE_MIN_TRIM:
            why = "none wanted"
        elif t > FACE_MAX_TRIM:
            why = "too deep"
        elif m["support"] < FACE_EDGE_SUPPORT:
            why = "no edge on the line"
        else:
            trims[side] = t
            why = "trimmed"
        sides[side] = {"want": round(t, 4), "support": m["support"], "result": why}
    info["sides"] = sides
    if not trims:
        return None, info
    r = [trims.get("left", 0.0), trims.get("top", 0.0), 1.0 - trims.get("right", 0.0), 1.0 - trims.get("bottom", 0.0)]
    return map_rect(r, (fw, fh), h_f2i, (rgb.shape[1], rgb.shape[0])), info


# --- the kind's second opinion (finding 3) -----------------------------------------------------------------------------
KIND_GROUPS = {"flat": "flat", "box_front": "box", "box_angled": "box", "assembled": "assembled", "other": "other"}


def gate_group(scores: dict[str, float] | None) -> str | None:
    """The gate's best kind group among the non-flat ones (the gate already decided 'not flat' for a VLM picture)."""
    if not scores:
        return None
    cands = {k: float(scores[k]) for k in ("box", "assembled", "other") if k in scores}
    return max(cands, key=lambda k: cands[k]) if cands else None


def needs_kind_check(fp: dict[str, Any]) -> bool:
    """`other` (wrong in 8 of 9 reviewed) and every picture whose VLM kind disagrees with the gate's best group."""
    if fp.get("method") == "trim" or not fp.get("vlmKind"):
        return False
    vk = fp["vlmKind"]
    return vk == "other" or KIND_GROUPS.get(vk) != gate_group(fp.get("gateScores"))


def decide_kind(fp: dict[str, Any], second: str | None) -> tuple[str, str]:
    """Three opinions: the gate (group only), the first VLM answer, the second. Two that agree win; else the first."""
    first = fp.get("vlmKind") or fp.get("kind") or "other"
    if second is None:
        return first, "no second answer"
    if second == first:
        return first, "confirmed"
    if KIND_GROUPS.get(second) == gate_group(fp.get("gateScores")):
        return second, "second agrees with the gate"
    if first == "other" and second != "other":
        return second, "second names it"
    return first, "kept"


# --- everything together ---------------------------------------------------------------------------------------------
def _crop_rgb(rgb: np.ndarray, box: Sequence[float], quad: Sequence[Sequence[float]] | None) -> np.ndarray:
    from ..imaging import crop

    out, _ = crop(rgb, box, quad)
    return out


def refine(rgb: np.ndarray, a: np.ndarray, fp: dict[str, Any], source: tuple[int, int], original: tuple[int, int],
           ask_kind: Any = None, ask_face: Any = None) -> dict[str, Any]:
    """The 1.1 payload from the first pass `fp`. `rgb` = the cached image (<= 1600 px), `a` = the <= 1024 px working
    copy; `ask_kind(rgb)` / `ask_face(face_rgb)` = the VLM's second opinions (None = not asked)."""
    g = crop_geometry(a, fp)
    signals: dict[str, Any] = {
        "gateMargin": fp.get("gateMargin"),
        "vlmKind": fp.get("vlmKind"),
        "vlmBbox": _bbox(fp["vlmBbox"]) if fp.get("vlmBbox") else None,
        "quadIou": fp.get("quadIou"),
        **g["signals"],
    }
    kind = fp.get("kind") or "other"
    if needs_kind_check(fp) and ask_kind is not None:
        second = ask_kind(rgb)
        kind, why = decide_kind(fp, second)
        signals["kindCheck"] = {"first": fp.get("vlmKind"), "second": second, "gate": gate_group(fp.get("gateScores")), "result": why}
    box, quad, method = list(g["bbox"]), g["quad"], g["method"]
    outline = quad if quad is not None else box_quad(box)
    if kind in FACE_KINDS and ask_face is not None:
        new, info = face_pass(rgb, outline, ask_face)
        signals["face"] = info
        if new is not None:
            outline = new
            quad = new if is_skewed(new) else None
            box = quad_to_box(new)
            method = f"{method}+face"
    lab = lab_image(a)
    final_sides = side_measures(lab, outline)
    confirmed = sum(1 for m in final_sides if side_ok(m))
    signals["finalSides"] = [{k: m[k] for k in ("side", "support", "similarity", "photoEdge")} for m in final_sides]
    crop_rgb = _crop_rgb(rgb, box, quad)
    corners = None
    if quad is not None and is_skewed(quad):
        corners = [[round(min(1.0, max(0.0, x)), 5), round(min(1.0, max(0.0, y)), 5)] for x, y in quad]
    gs = fp.get("gateScores") or {}
    return {
        "kind": kind,
        "bbox": _bbox(box),
        "corners": corners,
        "method": method,
        "confidence": confidence(method.split("+")[0] if method == "trim" else method, confirmed, fp, box),
        "kindScores": gs,
        "source": {"width": int(source[0]), "height": int(source[1])},
        "geometry": geometry(outline, source),
        "quality": quality(kind, outline, source, original, crop_rgb, confirmed),
        "signals": signals,
        "firstPass": fp,
    }


def _bbox(b: Sequence[float]) -> dict[str, float]:
    from ..imaging import xyxy_to_bbox

    return xyxy_to_bbox(b)
