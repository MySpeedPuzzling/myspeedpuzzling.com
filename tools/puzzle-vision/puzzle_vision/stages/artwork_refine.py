"""`artwork` 1.1: a pass over the first-pass geometry (gate + Qwen3-VL-4B + quad snap of 1.0) that fixes what review
found (docs/features/insights/pipeline-driver.md "Findings"), CPU parts here, model parts in artwork.py:

1. a snapped quad is kept only when each of its sides is a real picture edge (artwork_check.side_measures), else the
   VLM box is used - a wrong quad cut triangles off the picture and sheared it;
2. a side of an axis-aligned crop across which the picture continues is moved out to the photo's border (boxes: at
   most BOX_WIDEN_MAX) - the face pass then sees the whole picture;
3. boxes: the box's own straight edges give tilt and perspective (artwork_check.fit_quad), and the face pass (the VLM
   on the straightened face) takes the VLM's lines - snapped to a real edge when one is near. Jan's pilot review
   (2026-10-21): box print left in is worse than a sliver of art lost; the first 1.1 rule "never cut" left box print
   in 45 of 82 tuning boxes;
4. front / angled from the outline's geometry, small originals, image quality, a confidence built from agreement.

The first pass is re-used from the stored result (`first_pass_from`): 1.0 results carry the gate scores, the VLM's
kind and box and the accepted quad; 1.1 results keep them under `firstPass` so later versions can do the same.
"""

from __future__ import annotations

from typing import Any, Sequence

import cv2
import numpy as np

from ..imaging import bbox_to_xyxy, iou, is_skewed, quad_to_box
from .artwork_check import box_quad, fit_quad, lab_image, quad_angles, side_measures

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
PAD_ONLY_UNCONFIRMED = False  # True left thin frames out but cut 3 boxes (one by 23 %) on the tuning set: a "confirmed"
                              # side was a line inside the picture - the pad on every side protects
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


BOX_WIDEN_MAX: float | None = 0.1   # box photos: a side moves out at most this share of the box's size (tuning set:
                                    # border 0.865 box mIoU, 0.1 -> 0.873, both 0 box cuts; 0.05 cut one box 3 %)


def widen_box(lab: np.ndarray, box: Sequence[float], max_out: float | None = None) -> tuple[list[float], dict[str, Any]]:
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
        if max_out is not None:
            size = (box[2] - box[0]) if idx in (0, 2) else (box[3] - box[1])
            pos = max(pos, out[idx] - max_out * size) if idx < 2 else min(pos, out[idx] + max_out * size)
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
    if kind != "flat":
        # Jan 2026-10-20: only digital artwork is a perfect catalogue picture - every photo (a box, an assembled
        # puzzle, a shop render of a box) needs a better one, however good the crop
        reasons.append("not_digital_artwork")
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


BOX_FIT = True  # a box whose quad was not kept: its own four straight edges near the VLM box (artwork_check.fit_quad)
               # give the tilt / perspective - Jan 2026-10-21: 1.1 lost 1.0's straightening of tilted boxes


def is_box_kind(fp: dict[str, Any]) -> bool:
    return (fp.get("kind") or "") in ("box_front", "box_angled")


TRIM_TOL: float | None = 4.0  # re-trim a flat picture's plain border this strictly (1.0: 10) - a painting's paper margin is the picture (pilot #67); the tuning set's letterboxes are all still trimmed


WIDEN_KINDS = ("assembled", "other", "flat", "box_front", "box_angled")  # first-pass kinds whose unconfirmed sides go out


def crop_geometry(a: np.ndarray, fp: dict[str, Any]) -> dict[str, Any]:
    """Steps 1-2 on the <= 1024 px working image: which outline the crop uses and why. Answers bbox [x0,y0,x1,y1],
    quad (TL, TR, BR, BL; None = the bbox), method, and the side measures as signals."""
    lab = lab_image(a)
    if fp.get("method") == "trim":
        if TRIM_TOL is None:
            return {"bbox": list(fp["bbox"]), "quad": None, "method": "trim", "signals": {}}
        from .artwork import trim_uniform_border
        h, w = a.shape[:2]
        x0, y0, x1, y1 = trim_uniform_border(a, TRIM_TOL)
        return {"bbox": [x0 / w, y0 / h, x1 / w, y1 / h], "quad": None, "method": "trim", "signals": {"trimTol": TRIM_TOL}}
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
        if HYBRID and sum(ok) >= 2 and is_skewed(quad) and not (BOX_FIT and is_box_kind(fp)):
            hq = hybrid_quad(quad, ok, box, a.shape[1] / a.shape[0])
            if hq is not None:
                signals["quad"] = "partly"
                return {"bbox": quad_to_box(hq), "quad": hq, "method": "vlm+quad", "signals": signals,
                        "confirmed": sum(ok), "quadRejected": True}
    if VLM_PAD > 0:
        pw, ph = VLM_PAD * (box[2] - box[0]), VLM_PAD * (box[3] - box[1])
        if PAD_ONLY_UNCONFIRMED:
            ok = {m["side"]: side_ok(m) for m in side_measures(lab, box_quad(box))}
            box = [box[0] if ok["left"] else max(0.0, box[0] - pw), box[1] if ok["top"] else max(0.0, box[1] - ph),
                   box[2] if ok["right"] else min(1.0, box[2] + pw), box[3] if ok["bottom"] else min(1.0, box[3] + ph)]
        else:
            box = [max(0.0, box[0] - pw), max(0.0, box[1] - ph), min(1.0, box[2] + pw), min(1.0, box[3] + ph)]
    if BOX_FIT and is_box_kind(fp):
        fitted, support = fit_quad(lab, box)
        signals["fit"] = {"quad": fitted, "support": support}
        if fitted is not None and is_skewed(fitted):
            signals["fit"]["used"] = True
            return {"bbox": quad_to_box(fitted), "quad": fitted, "method": "vlm+fit", "signals": signals, "confirmed": 4,
                    "quadRejected": quad is not None}
    if (fp.get("kind") or "other") in WIDEN_KINDS:
        is_box = (fp.get("kind") or "") in ("box_front", "box_angled")
        widened, info = widen_box(lab, box, BOX_WIDEN_MAX if is_box else None)
    else:
        widened, info = list(box), {"sides": side_measures(lab, box_quad(box)), "moved": {}}
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
TRIM_KIND_CHECK = True  # a trimmed "flat" picture gets the kind's second opinion; a box goes through the face pass ...
TRIM_BOX_MAX_SHARE = 0.9  # ... and stays a box only when the picture found in it covers less than this of the trimmed
                          # picture (a digital picture's own white margin is no box: pilot #166)


def quad_area(q: Sequence[Sequence[float]]) -> float:
    return float(abs(cv2.contourArea(np.asarray(q, np.float32))))


AGREE = True


def first_pass_in_face(box: Sequence[float], before: Sequence[float] | None) -> dict[str, float]:
    """Where the first pass's VLM box had each side, as a trim of the (axis-aligned) face `box`."""
    if before is None:
        return {}
    w, h = max(1e-6, box[2] - box[0]), max(1e-6, box[3] - box[1])
    return {"left": (before[0] - box[0]) / w, "top": (before[1] - box[1]) / h,
            "right": (box[2] - before[2]) / w, "bottom": (box[3] - before[3]) / h}


def face_caps(box: Sequence[float], before: Sequence[float] | None) -> dict[str, float]:
    """How deep the face pass may trim each side of an axis-aligned crop: FACE_MAX_TRIM of the face, plus whatever the
    1.1 pass added on that side (pad, widening) - so a widened side can come back to the VLM's own line and a bit."""
    w, h = max(1e-6, box[2] - box[0]), max(1e-6, box[3] - box[1])
    if before is None:
        return {}
    return {
        "left": FACE_MAX_TRIM + max(0.0, before[0] - box[0]) / w,
        "top": FACE_MAX_TRIM + max(0.0, before[1] - box[1]) / h,
        "right": FACE_MAX_TRIM + max(0.0, box[2] - before[2]) / w,
        "bottom": FACE_MAX_TRIM + max(0.0, box[3] - before[3]) / h,
    }
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


SNAP_OUT = 0.04     # a VLM line moves out to the outermost real edge within this share of the face ...
SNAP_IN = 0.0       # ... never further in than the VLM's line (allowing 1 % in cut art on 6 of 82 tuning boxes)
FACE_BACKOFF = 0.015  # every face trim stops this share of the face short of its edge: a sliver of band is left
                      # rather than art cut (the VLM's lines and the labels are good to ~1 %)
SNAP_STEP_DE = 9.0
NO_EDGE_MAX: float | None = None  # a VLM line without an edge deeper than this share of the face ...
NO_EDGE_DEEP = "cap"              # ... is cut back to it ("cap") or dropped ("veto")
TRUST_BACKOFF = 0.0   # (tuning set: a 1-2 % back-off from the VLM's line traded box mIoU for print left in, no fewer
                      # big cuts; a colour-similarity veto on the cut-away strip did not tell art from print - kept as
                      # the `stripSim` / `stripInPalette` signals)
FACE_TRUST = True     # Jan's pilot review 2026-10-21: box print left in is worse than a sliver of art lost - the VLM's
                      # line is taken when no edge is near it, an edge within SNAP_OUT / SNAP_IN wins, no back-off
SNAP_NEAREST = True   # the edge nearest the VLM's line going out, not the outermost (that was a frame's outer line:
                      # tuning box print 52 -> 45, no new cut; the pilot's "frame kept" cases)


def edge_profile(lab: np.ndarray, side: int) -> np.ndarray:
    """Per position from the face's `side` (0 left, 1 top, 2 right, 3 bottom) inward: the share of the line (its middle
    84 %) on a colour step that stands out of its neighbourhood. The face is straightened, so lines are axis-aligned."""
    k = {0: 3, 1: 0, 2: 1, 3: 2}[side]  # np.rot90 k times brings that side to the top
    r = np.ascontiguousarray(np.rot90(lab, k))
    h, w = r.shape[:2]
    sm = cv2.GaussianBlur(r, (0, 0), 1.0)
    step = np.zeros((h, w), np.float32)
    step[2:-2] = np.linalg.norm(sm[4:] - sm[:-4], axis=2)
    c0, c1 = int(0.08 * w), int(0.92 * w)
    band = step[:, c0:c1]
    local = cv2.blur(band, (1, max(3, int(0.06 * h)) | 1))  # the typical step around each row, per column
    strong = (band >= SNAP_STEP_DE) & (band >= 2.2 * (local + 1.0))
    strong = cv2.dilate(strong.astype(np.uint8), np.ones((5, 1), np.uint8)).astype(bool)  # +-2 px
    return strong.mean(axis=1)


def snap_trim(lab: np.ndarray, side: int, t: float) -> tuple[float | None, float]:
    """The trim on a real edge within [t - SNAP_OUT, t + SNAP_IN] - the one nearest the VLM's line (FACE_TRUST) or,
    going out, the first one (SNAP_NEAREST) - or None."""
    prof = edge_profile(lab, side)
    n = len(prof)
    lo, hi = max(1, int((t - SNAP_OUT) * n)), min(n - 1, int(np.ceil((t + SNAP_IN) * n)))
    best = float(prof[lo:hi + 1].max()) if hi >= lo else 0.0
    if FACE_TRUST:
        order = sorted(range(lo, hi + 1), key=lambda y: abs(y / n - t))
    else:
        order = range(hi, lo - 1, -1) if SNAP_NEAREST else range(lo, hi + 1)
    for y in order:
        if prof[y] >= FACE_EDGE_SUPPORT:
            return y / n, round(best, 3)
    return None, round(best, 3)


def strip_measures(lab: np.ndarray, side: int, t: float, rect: Sequence[float]) -> dict[str, float]:
    """What the face pass would cut away on `side` (the strip from the face's edge to the VLM's line at `t`) against
    the picture the VLM marked (`rect`): colour similarity with the picture's band just inside the line, and the share
    of the strip's pixels in a colour of the picture. Box print = colours the picture does not have."""
    k = {0: 3, 1: 0, 2: 1, 3: 2}[side]
    r = np.ascontiguousarray(np.rot90(lab, k))
    h, w = r.shape[:2]
    # the VLM's rect in the rotated frame: its columns span; rows from t inward
    u0, v0, u1, v1 = rect
    span = {0: (v0, v1), 1: (u0, u1), 2: (v0, v1), 3: (u0, u1)}[side]
    if side in (0, 3):  # rot90 by 3 (left) / 2 (bottom) reverses the along-side order
        span = (1 - span[1], 1 - span[0])
    c0, c1 = int(max(0.0, span[0]) * w), max(int(max(0.0, span[0]) * w) + 1, int(min(1.0, span[1]) * w))
    cut = r[: max(1, int(t * h)), c0:c1]
    depth = max(2, int(max(t, 0.06) * h))
    inner = r[int(t * h): int(t * h) + depth, c0:c1]
    from .artwork_check import palette as _palette
    from .artwork_check import palette_share, similarity
    pq = np.float32([[c0, int(t * h)], [c1, int(t * h)], [c1, h - 1], [c0, h - 1]])
    pal = _palette(r, pq)
    px = cut.reshape(-1, 3)
    if len(px) > 20000:
        px = px[:: len(px) // 20000]
    return {"stripSim": round(similarity(cut, inner), 3), "stripInPalette": round(palette_share(px, pal), 3)}


AGREE_TOL = 0.02    # the face pass and the first pass put a side within this share of the face of each other ...
AGREE_PAD = 0.02    # ... = trusted without a visible edge, with this margin outward


def face_pass(rgb: np.ndarray, quad: Sequence[Sequence[float]], ask: Any,
              max_trim: dict[str, float] | None = None,
              first_at: dict[str, float] | None = None) -> tuple[list[list[float]] | None, dict[str, Any]]:
    """The picture inside a box face. `ask(face_rgb)` = the VLM's [u0, v0, u1, v1] (0..1 of the face) or None.
    Each side moves in only when the VLM wants at least FACE_MIN_TRIM, at most FACE_MAX_TRIM, and its line sits on a
    real colour step on the face (art crossing the line = no step = veto: never cut artwork). Answers the new
    outline (TL, TR, BR, BL of the image) or None when nothing moved, and what happened per side."""
    face, h_f2i = straighten(rgb, quad)
    fh, fw = face.shape[:2]
    info: dict[str, Any] = {"face": {"width": fw, "height": fh}, "outline": [[round(float(x), 5), round(float(y), 5)] for x, y in quad]}
    rect = ask(face)
    if rect is None:
        info["answer"] = None
        return None, info
    u0, v0, u1, v1 = (float(v) for v in rect)
    info["answer"] = [round(u0, 4), round(v0, 4), round(u1, 4), round(v1, 4)]
    want = {"left": u0, "top": v0, "right": 1.0 - u1, "bottom": 1.0 - v1}
    lab = lab_image(face)
    trims: dict[str, float] = {}
    sides: dict[str, Any] = {}
    for k, (side, t) in enumerate(want.items()):
        cap = (max_trim or {}).get(side, FACE_MAX_TRIM)
        entry: dict[str, Any] = {"want": round(t, 4)}
        if t >= FACE_MIN_TRIM:
            entry.update(strip_measures(lab, ("left", "top", "right", "bottom").index(side), t, (u0, v0, u1, v1)))
        if t < FACE_MIN_TRIM:
            why = "none wanted"
        elif t > cap:
            why = "too deep"
        else:
            snapped, sup = snap_trim(lab, ("left", "top", "right", "bottom").index(side), t)
            entry["support"] = sup
            before = (first_at or {}).get(side)
            if snapped is None and AGREE and not FACE_TRUST and before is not None and abs(before - t) <= AGREE_TOL:
                tt = min(t, before) - AGREE_PAD
                if tt >= FACE_MIN_TRIM:
                    trims[side] = tt
                    entry["trim"] = round(tt, 4)
                    entry["result"] = "both passes agree"
                    sides[side] = entry
                    continue
            if snapped is None and FACE_TRUST and t - TRUST_BACKOFF >= FACE_MIN_TRIM:
                tt = t - TRUST_BACKOFF
                if NO_EDGE_MAX is not None and tt > NO_EDGE_MAX:
                    tt = NO_EDGE_MAX if NO_EDGE_DEEP == "cap" else 0.0
                if tt >= FACE_MIN_TRIM:
                    trims[side] = tt
                    entry["trim"] = round(tt, 4)
                why = "the VLM's line" if tt == t - TRUST_BACKOFF else f"the VLM's line, too deep without an edge ({NO_EDGE_DEEP})"
            elif snapped is None and FACE_TRUST:
                why = "the VLM's line at the face's own border"
            elif snapped is None:
                why = "no edge on the line"
            elif BORDER_FIX and FACE_TRUST and snapped < FACE_MIN_TRIM and t >= FACE_MIN_TRIM:
                # the edge nearest the line is the face's own outline: the VLM's line it is
                trims[side] = t
                entry["trim"] = round(t, 4)
                why = "the VLM's line"
            elif snapped - (0.0 if (BORDER_FIX and FACE_TRUST) else FACE_BACKOFF) < FACE_MIN_TRIM:
                why = "edge at the face's own border"
            else:
                trims[side] = snapped - (0.0 if FACE_TRUST else FACE_BACKOFF)
                entry["trim"] = round(trims[side], 4)
                why = "trimmed"
        entry["result"] = why
        sides[side] = entry
    info["sides"] = sides
    if not trims:
        return None, info
    r = [trims.get("left", 0.0), trims.get("top", 0.0), 1.0 - trims.get("right", 0.0), 1.0 - trims.get("bottom", 0.0)]
    return map_rect(r, (fw, fh), h_f2i, (rgb.shape[1], rgb.shape[0])), info


BORDER_FIX = True     # a VLM line whose nearest edge is the face's own outline is the VLM's line (it was dropped:
                      # held-out boxes print kept 57 -> 48)
# Tried 2026-10-21 and left out (tuning + feedback sets): the face pass again on its own crop (fewer bands, more big
# cuts), a second VLM view of the face framed in grey (the same cut / print trade-off whichever way combined), an edge
# search further in than the VLM's line, a tilted refit of the face pass's upright crop, Qwen3.5-9B for the face (its
# lines are within ~1 % of the 4B's on most faces - the models agree, they do not see the band).
TILT_FIRST = True     # straighten the face along the box's own lines before the VLM looks (held-out boxes: print kept
                      # 48 -> 45, visible bands 33 -> 30, no more big cuts)
TILT_MIN_DEG = 3.0     # a side leaning less than this stays upright (1 degree grew the face for nothing: more print)
TILT_MAX_DEG = 20.0    # segments leaning more than this from the side are not its edges
TILT_EVIDENCE = 0.5    # segments near a side must add up to this share of its length to set its angle


def side_angles(a: np.ndarray, quad: Sequence[Sequence[float]]) -> list[float | None]:
    """The lean (degrees) of the long straight segments near each side (top, right, bottom, left) of an upright `quad`
    - the picture's and the box's edges on a box photographed at a slant. None where there is not enough of them."""
    h, w = a.shape[:2]
    q = np.asarray(quad, float) * [w, h]
    x0, y0 = q.min(0)
    x1, y1 = q.max(0)
    bw, bh = max(1.0, x1 - x0), max(1.0, y1 - y0)
    segs = cv2.createLineSegmentDetector().detect(cv2.cvtColor(a, cv2.COLOR_RGB2GRAY))[0]
    segs = np.zeros((0, 4)) if segs is None else segs.reshape(-1, 4)
    out: list[float | None] = []
    for side in range(4):
        horiz = side in (0, 2)
        span = bw if horiz else bh
        angs, lens = [], []
        for ax, ay, bx, by in segs:
            dx, dy = bx - ax, by - ay
            length = float(np.hypot(dx, dy))
            if length < 0.1 * span:
                continue
            if horiz:
                if dx < 0:
                    dx, dy = -dx, -dy
                ang = float(np.degrees(np.arctan2(dy, dx)))
                mid, along, lo, hi = (ay + by) / 2, (ax + bx) / 2, x0, x1
                near = (y0 - 0.1 * bh <= mid <= y0 + 0.4 * bh) if side == 0 else (y1 - 0.4 * bh <= mid <= y1 + 0.1 * bh)
            else:
                if dy < 0:
                    dx, dy = -dx, -dy
                ang = float(np.degrees(np.arctan2(-dx, dy)))  # lean of a vertical line, same sign convention
                mid, along, lo, hi = (ax + bx) / 2, (ay + by) / 2, y0, y1
                near = (x1 - 0.4 * bw <= mid <= x1 + 0.1 * bw) if side == 1 else (x0 - 0.1 * bw <= mid <= x0 + 0.4 * bw)
            if abs(ang) > TILT_MAX_DEG or not near or not (lo <= along <= hi):
                continue
            angs.append(ang)
            lens.append(length)
        if sum(lens) < TILT_EVIDENCE * span:
            out.append(None)
            continue
        order = np.argsort(angs)
        cum = np.cumsum(np.asarray(lens)[order])
        out.append(float(np.asarray(angs)[order][int(np.searchsorted(cum, cum[-1] / 2))]))  # length-weighted median
    for k in range(4):  # a side without its own evidence takes the opposite side's lean
        if out[k] is None and out[(k + 2) % 4] is not None:
            out[k] = out[(k + 2) % 4]
    return out


def tilted_outline(a: np.ndarray, quad: Sequence[Sequence[float]], angles: Sequence[float | None]) -> list[list[float]]:
    """The smallest quad with sides at `angles` that holds the upright `quad` whole (nothing of it is cut away)."""
    h, w = a.shape[:2]
    q = np.asarray(quad, float) * [w, h]
    lines = []
    for side, ang in enumerate(angles):
        t = np.radians(ang or 0.0)
        # direction along the side and the outward normal: top/bottom run left->right, left/right run top->bottom
        if side in (0, 2):
            d = np.array([np.cos(t), np.sin(t)])
            n = np.array([d[1], -d[0]]) if side == 0 else np.array([-d[1], d[0]])
        else:
            d = np.array([-np.sin(t), np.cos(t)])
            n = np.array([d[1], -d[0]]) if side == 1 else np.array([-d[1], d[0]])
        c = max(float(n @ p) for p in q)
        lines.append(np.array([n[0], n[1], -c]))
    pts = []
    for k in range(4):  # TL = left x top, TR = top x right, BR = right x bottom, BL = bottom x left
        p = np.cross(lines[k - 1], lines[k])
        pts.append(p[:2] / p[2])
    r = np.asarray(pts) / [w, h]
    return [[round(float(x), 5), round(float(y), 5)] for x, y in r]


BAND_CHECK = False     # off: the line comes from the pixels, but no VLM here tells a printed band from a slice of art -
                       # Qwen3-VL-4B answered "packaging" to 109 of 110 strips with the first wording; on 60 labelled
                       # strips (48 packaging, 11 art) the wording below gets 6 of 11 art strips (Qwen3.5-35B-A3B 7);
                       # held-out boxes with it on: print kept 45 -> 41 but big cuts 16 -> 23
BAND_DEPTH = 0.15      # straight lines up to this share of the face in from a side are band candidates ...
BAND_SUPPORT = 0.75    # ... when they sit on a colour step this often (a printed band's inner edge is long and straight)
BAND_ROUNDS = 2        # per side: a band, then maybe a frame inside it
BAND_THIN = 0.06       # a strip deeper than this ...
BAND_UNIFORM = 0.3     # ... is a band only when one colour covers at least this share of it (a band's background;
                       # the VLM calls deep strips of busy art "packaging" too)


def dominant_share(lab: np.ndarray, side: int, t: float) -> float:
    """The share of the strip from the face's `side` to `t` in its most common colour (Lab bins of 12)."""
    k = {0: 3, 1: 0, 2: 1, 3: 2}[side]
    r = np.rot90(lab, k)
    strip = r[: max(1, int(round(t * r.shape[0])))].reshape(-1, 3)
    q = np.floor(strip / 12.0).astype(np.int32)
    _, counts = np.unique(q[:, 0] * 10000 + q[:, 1] * 100 + q[:, 2], return_counts=True)
    return float(counts.max() / max(1, len(q)))


def strip_view(face: np.ndarray, side: int, t: float) -> np.ndarray:
    """The face's `side` turned to the top, cut to show what lies above the line at `t` and as much again below it,
    the line drawn in red - what the VLM is asked about."""
    k = {0: 3, 1: 0, 2: 1, 3: 2}[side]
    r = np.ascontiguousarray(np.rot90(face, k))
    h = r.shape[0]
    y = int(round(t * h))
    out = np.ascontiguousarray(r[: max(int(0.12 * h), min(h, 2 * y + int(0.03 * h)))].copy())
    lw = max(2, out.shape[1] // 300)
    out[max(0, y - lw // 2 - lw): y + lw - lw // 2 + lw] = (255, 255, 255)  # a white-edged line shows on any colour
    out[max(0, y - lw // 2): y + lw - lw // 2] = (255, 0, 0)
    return out


def band_check(rgb: np.ndarray, quad: Sequence[Sequence[float]], ask: Any) -> tuple[list[list[float]] | None, dict[str, Any]]:
    """Per side of a face: the nearest long straight line inside it, and the VLM's yes/no on whether the strip beyond
    it is packaging. The line comes from the pixels (precise), only the question from the VLM (what it answers well)."""
    face, h_f2i = straighten(rgb, quad)
    fh, fw = face.shape[:2]
    lab = lab_image(face)
    trims: dict[str, float] = {}
    info: dict[str, Any] = {}
    for side, name in enumerate(("left", "top", "right", "bottom")):
        prof = edge_profile(lab, side)
        n = len(prof)
        t0, steps = 0.0, []
        for _ in range(BAND_ROUNDS):
            ys = [y for y in range(int((t0 + FACE_MIN_TRIM) * n) + 1, int(BAND_DEPTH * n) + 1) if prof[y] >= BAND_SUPPORT]
            if not ys:
                break
            run = [ys[0]]
            for y in ys[1:]:
                if y != run[-1] + 1:
                    break
                run.append(y)
            t = (run[0] + run[-1]) / 2 / n
            answer = ask(strip_view(face, side, t))
            share = dominant_share(lab, side, t)
            steps.append({"at": round(t, 4), "answer": answer, "uniform": round(share, 3)})
            if answer != "packaging" or (t > BAND_THIN and share < BAND_UNIFORM):
                break
            t0 = t
        if steps:
            info[name] = steps
        if t0 > 0:
            trims[name] = t0
    if not trims:
        return None, info
    r = [trims.get("left", 0.0), trims.get("top", 0.0), 1.0 - trims.get("right", 0.0), 1.0 - trims.get("bottom", 0.0)]
    return map_rect(r, (fw, fh), h_f2i, (rgb.shape[1], rgb.shape[0])), info


FACE_GROW = True
GROW_TOUCH = 0.04   # the VLM's picture reaches within this share of the face's side = it may go on beyond


def grow_sides(box: Sequence[float], widened: dict[str, Any], info: dict[str, Any]) -> dict[str, float]:
    """Sides to move out to the photo's border: widened ones (no edge there) the widening cap stopped short of the
    border, which the VLM's picture reaches."""
    sides = info.get("sides") or {}
    out = {}
    for side, idx, border in (("left", 0, 0.0), ("top", 1, 0.0), ("right", 2, 1.0), ("bottom", 3, 1.0)):
        if side in widened and abs(box[idx] - border) > 0.005 and (sides.get(side) or {}).get("want", 1.0) < GROW_TOUCH:
            out[side] = border
    return out


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
    """Three opinions: the gate (group only), the first VLM answer, the second. The second wins only when it agrees
    with the gate. `other` is never left: on the tuning set it was right where the second opinion called a picture-
    less product, a box side or a 3-pack a box (the face pass then cut into a sub-picture)."""
    first = fp.get("vlmKind") or fp.get("kind") or "other"
    if second is None:
        return first, "no second answer"
    if second == first:
        return first, "confirmed"
    if first == "other":
        return first, "other kept"
    if KIND_GROUPS.get(second) == gate_group(fp.get("gateScores")):
        return second, "second agrees with the gate"
    return first, "kept"


# --- everything together ---------------------------------------------------------------------------------------------
def _crop_rgb(rgb: np.ndarray, box: Sequence[float], quad: Sequence[Sequence[float]] | None) -> np.ndarray:
    from ..imaging import crop

    out, _ = crop(rgb, box, quad)
    return out


def refine(rgb: np.ndarray, a: np.ndarray, fp: dict[str, Any], source: tuple[int, int], original: tuple[int, int],
           ask_kind: Any = None, ask_face: Any = None, ask_strip: Any = None) -> dict[str, Any]:
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
    if TRIM_KIND_CHECK and fp.get("method") == "trim" and ask_kind is not None:
        # the gate called it a digital picture; a box photographed straight on passes for one (pilot #97)
        second = ask_kind(rgb)
        signals["kindCheck"] = {"first": "flat", "second": second, "gate": "flat"}
        if second in FACE_KINDS:
            kind = second
            signals["kindCheck"]["result"] = "box photo"
            g = {**g, "bbox": [0.0, 0.0, 1.0, 1.0], "method": "kind", "quad": None}
        else:
            signals["kindCheck"]["result"] = "kept"
    elif needs_kind_check(fp) and ask_kind is not None:
        second = ask_kind(rgb)
        kind, why = decide_kind(fp, second)
        signals["kindCheck"] = {"first": fp.get("vlmKind"), "second": second, "gate": gate_group(fp.get("gateScores")), "result": why}
    box, quad, method = list(g["bbox"]), g["quad"], g["method"]
    outline = quad if quad is not None else box_quad(box)
    unsure = g["signals"].get("quad") in ("rejected", "partly") or bool(g["signals"].get("widened"))
    if ask_face is not None and (kind in FACE_KINDS or (kind == "assembled" and unsure)):
        caps = face_caps(box, fp.get("vlmBbox")) if quad is None else None
        first_at = first_pass_in_face(box, fp.get("vlmBbox")) if quad is None else None
        if TILT_FIRST and quad is None and kind in FACE_KINDS:
            angles = side_angles(a, outline)
            signals["tilt"] = {"angles": [None if v is None else round(v, 2) for v in angles]}
            if any(v is not None and abs(v) >= TILT_MIN_DEG for v in angles):
                # straighten the face along the box's own lines first: the VLM then marks an upright picture
                outline = tilted_outline(a, outline, [v if v is not None and abs(v) >= TILT_MIN_DEG else 0.0 for v in angles])
                caps, first_at = None, None
                signals["tilt"]["outline"] = outline
        new, info = face_pass(rgb, outline, lambda face: ask_face(face, kind), caps, first_at)
        grow = grow_sides(box, g["signals"].get("widened") or {}, info) if quad is None and FACE_GROW else {}
        if grow:
            # the picture runs on past a widened side that stopped short of the photo's border (BOX_WIDEN_MAX) and
            # the VLM saw it reach that edge: show it the photo up to the border there and ask again (pilot #111)
            box = [grow.get("left", box[0]), grow.get("top", box[1]), grow.get("right", box[2]), grow.get("bottom", box[3])]
            outline = box_quad(box)
            caps = face_caps(box, fp.get("vlmBbox"))
            first_at = first_pass_in_face(box, fp.get("vlmBbox"))
            new, info = face_pass(rgb, outline, lambda face: ask_face(face, kind), caps, first_at)
            info["grown"] = sorted(grow)
            method = f"{method}+grow"
        signals["face"] = info
        if g["method"] == "kind" and (new is None or quad_area(new) > TRIM_BOX_MAX_SHARE * quad_area(box_quad(fp["bbox"]))):
            # the second opinion said "box" but no smaller picture was found in it: a digital picture after all (2 of
            # 18 flats on the tuning set were called boxes)
            kind, new = "flat", None
            box, quad, method, outline = list(fp["bbox"]), None, "trim", box_quad(fp["bbox"])
            signals["kindCheck"]["result"] = "no picture inside, kept"
        if new is not None:
            outline = new
            quad = new if is_skewed(new) else None
            box = quad_to_box(new)
            method = f"{method}+face"
    if BAND_CHECK and ask_strip is not None and kind in FACE_KINDS:
        bq, binfo = band_check(rgb, outline, ask_strip)
        signals["bands"] = binfo
        if bq is not None:
            outline = bq
            quad = bq if is_skewed(bq) else None
            box = quad_to_box(bq)
            method = f"{method}+band"
    lab = lab_image(a)
    final_sides = side_measures(lab, outline)
    # a side the face pass put on an edge (then backed off it) or where both passes agree is confirmed too - the
    # back-off itself leaves no step on the final line
    by_face = {side for side, v in ((signals.get("face") or {}).get("sides") or {}).items()
               if v.get("result") in ("trimmed", "both passes agree", "the VLM's line")} if "+face" in method else set()
    widened = set(g["signals"].get("widened") or {})  # moved out for want of an edge: a photo border there proves nothing
    confirmed = sum(1 for m in final_sides
                    if m["side"] in by_face or (side_ok(m) and m["side"] not in widened))
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
