"""Scores an `artwork` version against hand-marked outlines (artwork_reference.py) - run first for every new version.

Per picture, from the crop exactly as `imaging.crop()` makes it (perspective warp for a skewed quad, else the bbox,
both with the 1 % inset) against the truth outline:
- `cut`: how deep the crop reaches into real artwork - the largest distance of a truth edge point outside the crop,
  as a share of the artwork's size across that edge. Above CUT_LIMIT = artwork cut (Jan: never);
- `extra`: how far the crop reaches beyond the truth (+ its `outer` allowance) - box print / table / background left
  in, same measure. Above EXTRA_LIMIT = print kept;
- `iou` of crop and truth;
- `wrongQuad`: a perspective crop that cuts artwork (a quad that locked onto lines inside the picture);
- kind: exact (5 kinds) and grouped (flat / box / assembled / other).
CUT_LIMIT sits above the crop's own 1 % inset plus labelling noise (~1 %), so a crop on the true edge never counts.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Iterable, Sequence

import cv2
import numpy as np

from .imaging import is_skewed

CUT_LIMIT = 0.025
BIG_CUT = 0.05
WRONG_SUBJECT_IOU = 0.3
EXTRA_LIMIT = 0.03
INSET = 0.01
RASTER = 600
EDGE_SAMPLES = 40
KIND_GROUP = {"flat": "flat", "box_front": "box", "box_angled": "box", "assembled": "assembled", "other": "other"}


@dataclass
class Candidate:
    """What a version answers for one picture: the crop region (as stored: bbox + optional corners) and the kind."""
    kind: str
    bbox: Sequence[float]                      # x0, y0, x1, y1 (0..1)
    corners: Sequence[Sequence[float]] | None  # TL, TR, BR, BL (0..1) or None
    info: dict[str, Any] | None = None


def crop_polygon(c: Candidate, aspect: float) -> tuple[np.ndarray, str]:
    """The region `imaging.crop()` cuts, as a polygon in image fractions (TL, TR, BR, BL). `aspect` = width / height
    of the image (the inset is applied in pixels, like the crop)."""
    if c.corners is not None and len(c.corners) == 4 and is_skewed(c.corners):
        q = np.asarray(c.corners, float) * [aspect, 1.0]
        centre = q.mean(0)
        q = centre + (q - centre) * (1 - 2 * INSET)
        return q / [aspect, 1.0], "perspective"
    x0, y0, x1, y1 = c.bbox
    dx, dy = (x1 - x0) * INSET, (y1 - y0) * INSET
    return np.array([[x0 + dx, y0 + dy], [x1 - dx, y0 + dy], [x1 - dx, y1 - dy], [x0 + dx, y1 - dy]], float), "box"


def outer_polygon(corners: np.ndarray, outer: dict[str, float] | None) -> np.ndarray:
    """The truth outline moved out per side by its `outer` allowance (share of the artwork's size across the side)."""
    if not outer:
        return corners
    q = corners.copy()
    centre = corners.mean(0)
    width = (np.linalg.norm(corners[1] - corners[0]) + np.linalg.norm(corners[2] - corners[3])) / 2
    height = (np.linalg.norm(corners[3] - corners[0]) + np.linalg.norm(corners[2] - corners[1])) / 2
    for side, (a, b), size in (("top", (0, 1), height), ("right", (1, 2), width), ("bottom", (2, 3), height), ("left", (3, 0), width)):
        f = float(outer.get(side) or 0.0)
        if f <= 0:
            continue
        p, r = corners[a], corners[b]
        d = r - p
        n = np.array([d[1], -d[0]]) / (np.linalg.norm(d) + 1e-12)
        if np.dot(n, (p + r) / 2 - centre) < 0:
            n = -n
        q[a] = q[a] + n * f * size
        q[b] = q[b] + n * f * size
    return q


def _edge_points(q: np.ndarray, n: int = EDGE_SAMPLES) -> list[tuple[int, np.ndarray]]:
    out = []
    for k in range(4):
        a, b = q[k], q[(k + 1) % 4]
        for t in np.linspace(0.0, 1.0, n):
            out.append((k, a + (b - a) * t))
    return out


def _depth_outside(points: Iterable[tuple[int, np.ndarray]], poly: np.ndarray, sizes: Sequence[float], aspect: float) -> float:
    """Largest distance of `points` outside `poly`, each as a share of sizes[side] (computed in pixel-like units)."""
    contour = (poly * [aspect, 1.0] * 1000).astype(np.float32).reshape(-1, 1, 2)
    worst = 0.0
    for side, p in points:
        d = cv2.pointPolygonTest(contour, (float(p[0] * aspect * 1000), float(p[1] * 1000)), True)
        if d < 0:
            worst = max(worst, -d / 1000 / max(1e-6, sizes[side]))
    return worst


def _sizes(q: np.ndarray, aspect: float) -> list[float]:
    """Per side (top, right, bottom, left) the outline's size across it (height for top/bottom, width for sides)."""
    p = q * [aspect, 1.0]
    width = (np.linalg.norm(p[1] - p[0]) + np.linalg.norm(p[2] - p[3])) / 2
    height = (np.linalg.norm(p[3] - p[0]) + np.linalg.norm(p[2] - p[1])) / 2
    return [height, width, height, width]


def _iou(a: np.ndarray, b: np.ndarray, aspect: float) -> float:
    w, h = (RASTER, max(1, int(RASTER / aspect))) if aspect >= 1 else (max(1, int(RASTER * aspect)), RASTER)
    ma = np.zeros((h, w), np.uint8)
    mb = np.zeros((h, w), np.uint8)
    cv2.fillPoly(ma, [np.round(a * [w, h]).astype(np.int32)], 1)
    cv2.fillPoly(mb, [np.round(b * [w, h]).astype(np.int32)], 1)
    union = int((ma | mb).sum())
    return float((ma & mb).sum()) / union if union else 0.0


NO_SINGLE_PICTURE = ("not_the_picture", "several_pictures")


def score(candidate: Candidate, truth: dict[str, Any], aspect: float) -> dict[str, Any]:
    t = np.asarray(truth["corners"], float)
    o = outer_polygon(t, truth.get("outer"))
    c, how = crop_polygon(candidate, aspect)
    cut = _depth_outside(_edge_points(t), c, _sizes(t, aspect), aspect)
    extra = _depth_outside(_edge_points(c), o, _sizes(t, aspect), aspect)
    tk, ck = truth.get("kind"), candidate.kind
    return {
        "how": how,
        "crop": [[round(float(x), 4), round(float(y), 4)] for x, y in c],
        "cut": round(float(cut), 4),
        "extra": round(float(extra), 4),
        "iou": round(_iou(c, t, aspect), 4),
        "artworkCut": bool(cut > CUT_LIMIT),
        "printKept": bool(extra > EXTRA_LIMIT),
        "wrongQuad": bool(how == "perspective" and cut > CUT_LIMIT),
        "bigCut": bool(cut > BIG_CUT),
        "kindExact": tk == ck,
        "kindGroup": KIND_GROUP.get(tk or "") == KIND_GROUP.get(ck or ""),
        "truthKind": tk,
        "kind": ck,
        "noSinglePicture": any(f in NO_SINGLE_PICTURE for f in (truth.get("flags") or []) + (truth.get("quality") or [])),
        "truthQuality": truth.get("quality") or [],
    }


def load_truth(paths: Iterable[Path]) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    for path in paths:
        for line in Path(path).read_text().splitlines():
            line = line.strip()
            if line:
                row = json.loads(line)
                out[row["puzzleId"]] = row
    return out


def stored_candidate(result: dict[str, Any]) -> Candidate:
    b = result["bbox"]
    return Candidate(result.get("kind") or "other", [b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]], result.get("corners"))


def summarise(rows: list[dict[str, Any]]) -> dict[str, Any]:
    def block(rs: list[dict[str, Any]]) -> dict[str, Any]:
        n = len(rs)
        if not n:
            return {"n": 0}
        return {
            "n": n,
            "artworkCut": sum(r["artworkCut"] for r in rs),
            "printKept": sum(r["printKept"] for r in rs),
            "wrongQuad": sum(r["wrongQuad"] for r in rs),
            "bigCut": sum(r["bigCut"] for r in rs),
            "wrongSubject": sum(r["iou"] < WRONG_SUBJECT_IOU for r in rs),
            "meanIou": round(float(np.mean([r["iou"] for r in rs])), 4),
            "iou80": round(float(np.mean([r["iou"] >= 0.8 for r in rs])), 3),
            "kindExact": round(float(np.mean([r["kindExact"] for r in rs])), 3),
            "kindGroup": round(float(np.mean([r["kindGroup"] for r in rs])), 3),
            "maxCut": round(max(r["cut"] for r in rs), 4),
        }

    groups: dict[str, list[dict[str, Any]]] = {}
    for r in rows:
        groups.setdefault(KIND_GROUP.get(r["truthKind"] or "", "other"), []).append(r)
    single = [r for r in rows if not r["noSinglePicture"]]
    out = {"all": block(rows), "single": block(single), **{g: block(rs) for g, rs in sorted(groups.items())}}
    flagged = [r for r in rows if "quality" in (r.get("info") or {})]
    if flagged:
        nsp = [r for r in flagged if r["noSinglePicture"]]
        bad = [r for r in flagged if r["truthQuality"]]
        good = [r for r in flagged if not r["truthQuality"]]
        flag = lambda r: bool(r["info"]["quality"])  # noqa: E731
        out["quality"] = {
            "noSinglePicture": len(nsp), "noSinglePictureFlagged": sum(flag(r) for r in nsp),
            "labelledProblems": len(bad), "labelledProblemsFlagged": sum(flag(r) for r in bad),
            "labelledFine": len(good), "labelledFineFlagged": sum(flag(r) for r in good),
        }
    return out


def evaluate(items: dict[str, dict[str, Any]], truth: dict[str, dict[str, Any]],
             candidate_for: Callable[[dict[str, Any]], Candidate | None]) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    """`items` = artwork_reference items (id -> path, width, height, fingerprint, stored); a truth row of another picture
    is skipped. Answers per-picture rows and the summary per truth kind group."""
    rows = []
    for pid, t in sorted(truth.items()):
        item = items.get(pid)
        if item is None or item.get("fingerprint") != t.get("fingerprint"):
            continue
        c = candidate_for(item)
        if c is None:
            continue
        r = score(c, t, item["width"] / item["height"])
        r["puzzleId"] = pid
        if c.info:
            r["info"] = c.info
        rows.append(r)
    return rows, summarise(rows)


# --- command line: python -m puzzle_vision.artwork_eval ---------------------------------------------------------------
def v11_geometry_candidate(item: dict[str, Any]) -> Candidate | None:
    """The 1.1 CPU geometry (quad check + widening) on the stored first pass - no model."""
    from .imaging import load_rgb
    from .stages.artwork_refine import crop_geometry, first_pass_from

    fp = first_pass_from(item.get("stored"))
    if fp is None:
        return None
    a = load_rgb(item["path"], 1024)
    g = crop_geometry(a, fp)
    return Candidate(item["stored"].get("kind") or "other", g["bbox"], g["quad"],
                     {"method": g["method"], **{k: v for k, v in g["signals"].items() if k in ("quad", "widened")}})


def v11_candidate(item: dict[str, Any], ask_kind: Any = None, ask_face: Any = None) -> Candidate | None:
    """The whole 1.1 pass on the stored first pass; without the VLM callables only its CPU part."""
    from .imaging import bbox_to_xyxy, load_rgb
    from .stages.artwork_refine import first_pass_from, refine

    fp = first_pass_from(item.get("stored"))
    if fp is None:
        return None
    rgb = load_rgb(item["path"])
    a = load_rgb(item["path"], 1024)
    out = refine(rgb, a, fp, (item["width"], item["height"]), (item["originalWidth"], item["originalHeight"]), ask_kind, ask_face)
    return Candidate(out["kind"], bbox_to_xyxy(out["bbox"]), out["corners"],
                     {"method": out["method"], "confidence": out["confidence"], "quality": out["quality"]["reasons"],
                      "sharpness": out["quality"]["sharpness"], "view": out["geometry"]["view"],
                      **{k: out["signals"][k] for k in ("quad", "widened", "kindCheck", "face") if k in out["signals"]}})


_STAGE: list[Any] = []


def v11_vlm_candidate(item: dict[str, Any]) -> Candidate | None:
    """The whole 1.1 pass with Qwen3-VL-4B for the kind's second opinion and the face pass - loads the model under
    the shared model lock (never next to another GPU job)."""
    if not _STAGE:
        from .lock import ModelLock
        from .stages.artwork import ArtworkStage

        lock = ModelLock("artwork-eval")
        lock.acquire(timeout=3600)
        _STAGE.extend([ArtworkStage("eval"), lock])
    stage = _STAGE[0]
    memo = _memo()

    def cached(kind: str, fn: Any) -> Any:
        def ask(im: np.ndarray, *args: Any) -> Any:
            import hashlib

            key = f"{item['id']}:{kind}:{args}:{hashlib.md5(np.ascontiguousarray(im).tobytes()).hexdigest()}"
            if key not in memo:
                memo[key] = fn(im, *args)
                _MEMO_PATH.write_text(json.dumps(memo))
            return memo[key]
        return ask

    return v11_candidate(item, cached("kind", stage.ask_kind), cached("face", stage.ask_face))


_MEMO_PATH = Path.home() / "Library/Caches/puzzle-vision/artwork-reference/vlm-answers.json"
_MEMO: list[dict[str, Any]] = []


def _memo() -> dict[str, Any]:
    """VLM answers of earlier eval runs (same picture, prompt and pixels = same greedy answer) - re-scoring a rule
    change does not ask the model again."""
    if not _MEMO:
        _MEMO.append(json.loads(_MEMO_PATH.read_text()) if _MEMO_PATH.exists() else {})
    return _MEMO[0]


CANDIDATES: dict[str, Callable[[dict[str, Any]], Candidate | None]] = {
    "v11": v11_vlm_candidate,
    "v11-cpu": v11_candidate,
    "stored": lambda item: stored_candidate(item["stored"]) if item.get("stored") else None,
    "v11-geometry": v11_geometry_candidate,
}


REFERENCE_DIR = Path(__file__).resolve().parent.parent / "reference"
SETS = ("reference", "tuning", "feedback")  # reference = held out: measure only, never tune on it; feedback = Jan's pilot review (tuned on)


def load_set(name: str, cache_dir: Path) -> tuple[dict[str, dict[str, Any]], dict[str, dict[str, Any]]]:
    """A labelled set from the repo (reference/artwork-items-<name>.json + artwork-truth-<name>.jsonl) with its
    pictures in `cache_dir` - downloaded once, the pipeline's own image cache format (the same pixels)."""
    from .download import Downloader
    from .workstate import WorkState

    cache_dir.mkdir(parents=True, exist_ok=True)
    dl = Downloader(cache_dir / "images", WorkState(cache_dir / "workstate.sqlite"))
    items = {}
    for i in json.loads((REFERENCE_DIR / f"artwork-items-{name}.json").read_text()):
        local = dl.get(i["id"], i["fingerprint"], "https://img.myspeedpuzzling.com/original/" + i["image"])
        items[i["id"]] = i | {"path": local.path}
    return items, load_truth([REFERENCE_DIR / f"artwork-truth-{name}.jsonl"])


def main(argv: list[str] | None = None) -> int:
    import argparse

    p = argparse.ArgumentParser(prog="python -m puzzle_vision.artwork_eval",
                                description="Score an artwork version against the hand-marked outlines - run it first for every new version.")
    p.add_argument("--set", action="append", choices=SETS, help="labelled set(s) from reference/ (default: reference)")
    p.add_argument("--dir", action="append", help="or a local labelled set (items.json with paths + truth/*.jsonl)")
    p.add_argument("--cache", default=str(Path.home() / "Library/Caches/puzzle-vision/artwork-reference"))
    p.add_argument("--candidate", default="stored", choices=sorted(CANDIDATES))
    p.add_argument("--rows", help="write per-picture rows (JSON lines) here")
    p.add_argument("--json", action="store_true")
    a = p.parse_args(argv)
    items: dict[str, dict[str, Any]] = {}
    truth: dict[str, dict[str, Any]] = {}
    for name in a.set or ([] if a.dir else ["reference"]):
        i, t = load_set(name, Path(a.cache))
        items.update(i)
        truth.update(t)
    for d in a.dir or []:
        items.update({i["id"]: i for i in json.loads((Path(d) / "items.json").read_text())})
        truth.update(load_truth(sorted((Path(d) / "truth").glob("*.jsonl"))))
    try:
        rows, summary = evaluate(items, truth, CANDIDATES[a.candidate])
    finally:
        if _STAGE:
            _STAGE[1].release()
    if a.rows:
        Path(a.rows).write_text("".join(json.dumps(r) + "\n" for r in rows))
    if a.json:
        print(json.dumps(summary, indent=2))
    else:
        for g, b in summary.items():
            if g == "quality":
                print("quality   ", b)
            elif b.get("n"):
                print(f"{g:10s} n={b['n']:4d} cut={b['artworkCut']:3d} big={b['bigCut']:3d} subj={b['wrongSubject']:3d} wrongQuad={b['wrongQuad']:3d} print={b['printKept']:3d} "
                      f"mIoU={b['meanIou']:.3f} iou>=.8={b['iou80']:.2f} kind={b['kindExact']:.2f}/{b['kindGroup']:.2f} maxCut={b['maxCut']:.3f}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
