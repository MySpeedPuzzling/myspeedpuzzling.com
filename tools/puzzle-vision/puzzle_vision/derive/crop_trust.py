"""`crop_trust` - how far to trust a puzzle's artwork crop, and the split quality marks (derived from the stored
`artwork` + `embed_siglip2` rows - no image touched; image-taxonomy.md §11.2, image-pipeline.md §7).

Why (prediction-model review 2026-10-26, artwork F1/F5, proposals 1, 2, 4): the `artwork` payload's `confidence`
describes the crop *path*, not the crop (the trim path never scores < 0.85, the worst path - VLM + widen + face pass,
34 % cut on the held-out outlines - scores 0.97; AUC for a bad crop 0.61), and `quality.needsBetterImage` is set on
70 % of the catalogue, mostly because a photo is not digital artwork. Neither can rank a review queue.

**trust** = calibrated probability that the crop is within 5 % of the true artwork on every side (`artwork_eval`:
`cut <= 0.05` and `extra <= 0.05`). A standardised logistic regression on 8 stored signals, coefficients frozen in
`crop_trust_model.json` (versioned in the repo; refit with `fit_model` + `python -m puzzle_vision.derive.crop_trust`):

| signal | from | why |
|---|---|---|
| `iouFirstPass` | IoU(final bbox, `firstPass.bbox`) | the 1.0 and 1.1 passes agree |
| `iouVlm` | IoU(final bbox, `firstPass.vlmBbox`) | the VLM's box agrees |
| `meanSupportInner` | mean `support` of `signals.finalSides` not on the photo border | a colour step under the crop line |
| `maxSimilarityInner` | max `similarity` of those sides | the picture continues across a side (= cut) |
| `trim` | `method == "trim"` | the flat border-trim path (best measured) |
| `boxKind`, `assembled` | `kind` | box photos and assembled puzzles are cut more often |
| `flatlike` | a linear probe on the stored SigLIP 2 crop vector | "does the crop look like clean digital artwork" |

Trained on the tuning + feedback outlines (reference/, 213 pictures), measured on the reference outlines (the
validation set, 264): AUC 0.90 (stored confidence 0.65), Brier 0.110 vs 0.184 for the base rate (the numbers of the
model in use are in its JSON `evaluation`). The probe is trained on production pictures outside the labelled sets:
crop vectors of flat pictures vs whole-image vectors of box photos.

The marks (each its own field, so a queue can pick what it needs):
- `pictureKind`: `digital` (kind flat) or `photo` (a box photo, an assembled puzzle, other) - photos are the low-
  priority "replace with the digital artwork one day" list;
- `resolution`: the `artwork` quality reasons `low_resolution_artwork` / `small_original` (null when neither) - the one
  quality fact measured to move the difficulty estimate (+35 % error on low-resolution photos);
- `notThePicture`: the `not_the_picture` reason (the picture is not the puzzle's image);
- `cropNeedsLook`: trust < 0.5 (73 % of them measured bad on the validation set);
- `estimateLowConfidence`: notThePicture, or trust < 0.3 with the artwork under 30 % of the photo (review proposal 3);
- `priority`: floor(log2(1 + solvedCount)) - a review queue takes the most-solved puzzles first; the log keeps the
  row from changing with every new result.
"""

from __future__ import annotations

import base64
import json
import math
from pathlib import Path
from typing import Any, Iterable, Mapping

import numpy as np

from ..payload import as_map

MODEL_PATH = Path(__file__).with_name("crop_trust_model.json")
FEATURES = ("iouFirstPass", "iouVlm", "meanSupportInner", "maxSimilarityInner", "trim", "boxKind", "assembled", "flatlike")
NEEDS_LOOK_BELOW = 0.5
LOW_CONFIDENCE_TRUST = 0.3
LOW_CONFIDENCE_SHARE = 0.30
WITHIN = 0.05  # the target: crop within 5 % of the artwork on every side
RESOLUTION_REASONS = ("low_resolution_artwork", "small_original")
BOX_KINDS = ("box_front", "box_angled")


# --- signals --------------------------------------------------------------------------------------------------------
def _xyxy(b: Any) -> list[float] | None:
    """A payload box: `{x, y, w, h}` (the crop) or `[x0, y0, x1, y1]` (`firstPass.bbox`, `firstPass.vlmBbox`)."""
    if isinstance(b, (list, tuple)):
        return [float(v) for v in b] if len(b) == 4 else None
    b = as_map(b)
    try:
        return [float(b["x"]), float(b["y"]), float(b["x"]) + float(b["w"]), float(b["y"]) + float(b["h"])]
    except (KeyError, TypeError, ValueError):
        return None


def _iou(a: list[float] | None, b: list[float] | None) -> float | None:
    if a is None or b is None:
        return None
    ix = max(0.0, min(a[2], b[2]) - max(a[0], b[0]))
    iy = max(0.0, min(a[3], b[3]) - max(a[1], b[1]))
    inter = ix * iy
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def unit(vector: np.ndarray | None) -> np.ndarray | None:
    if vector is None or not np.asarray(vector).size:
        return None
    v = np.asarray(vector, dtype=np.float32).reshape(-1)
    n = float(np.linalg.norm(v))
    return v / n if n > 0 else None


def raw_signals(artwork: Mapping[str, Any]) -> dict[str, float | None]:
    """The artwork-only inputs as stored (None = not there, e.g. a crop without a first pass)."""
    fp = as_map(artwork.get("firstPass"))
    box = _xyxy(artwork.get("bbox"))
    sides = [as_map(m) for m in (as_map(artwork.get("signals")).get("finalSides") or []) if isinstance(m, dict)]
    inner = [m for m in sides if not m.get("photoEdge")]
    support = [float(m.get("support") or 0.0) for m in inner]
    sims = [float(m["similarity"]) for m in inner if m.get("similarity") is not None]
    kind = artwork.get("kind")
    return {
        "iouFirstPass": _iou(box, _xyxy(fp.get("bbox"))) if fp.get("bbox") else None,
        "iouVlm": _iou(box, _xyxy(fp.get("vlmBbox"))) if fp.get("vlmBbox") else None,
        "meanSupportInner": float(np.mean(support)) if support else None,
        "maxSimilarityInner": max(sims) if sims else None,
        "trim": 1.0 if artwork.get("method") == "trim" else 0.0,
        "boxKind": 1.0 if kind in BOX_KINDS else 0.0,
        "assembled": 1.0 if kind == "assembled" else 0.0,
    }


def flatlike(model: Mapping[str, Any], crop_vector: np.ndarray | None) -> float | None:
    v = unit(crop_vector)
    probe = model["probe"]
    if v is None or v.size != int(probe["dim"]):
        return None
    return float(v @ _probe_weights(probe) + float(probe["intercept"]))


def _probe_weights(probe: Mapping[str, Any]) -> np.ndarray:
    cached = probe.get("_w")
    if cached is None:
        cached = np.frombuffer(base64.b64decode(probe["coef"]), dtype="<f4").astype(np.float32)
        probe["_w"] = cached  # type: ignore[index]
    return cached


def design(model: Mapping[str, Any], raw: Mapping[str, float | None]) -> np.ndarray:
    """The 8 model inputs, missing values filled like the training did (`fill`)."""
    fill = model["fill"]
    return np.array([fill[k] if raw.get(k) is None else float(raw[k]) for k in FEATURES], dtype=float)  # type: ignore[arg-type]


def trust_of(model: Mapping[str, Any], x: np.ndarray) -> float:
    lr = model["logistic"]
    z = (x - np.asarray(lr["mean"], float)) / np.asarray(lr["scale"], float)
    logit = float(z @ np.asarray(lr["coef"], float) + float(lr["intercept"]))
    return 1.0 / (1.0 + math.exp(-logit))


# --- the payload ----------------------------------------------------------------------------------------------------
def priority(solved_count: int | None) -> int:
    return int(math.floor(math.log2(1 + max(0, int(solved_count or 0)))))


def payload(model: Mapping[str, Any], artwork: Mapping[str, Any], crop_vector: np.ndarray | None,
            solved_count: int | None, artwork_version: str | None = None) -> dict[str, Any]:
    raw = raw_signals(artwork) | {"flatlike": flatlike(model, crop_vector)}
    trust = trust_of(model, design(model, raw))
    q = as_map(artwork.get("quality"))
    reasons = [r for r in (q.get("reasons") or []) if isinstance(r, str)]
    resolution = [r for r in RESOLUTION_REASONS if r in reasons]
    not_the_picture = "not_the_picture" in reasons
    share = q.get("artworkShare")
    small_share = isinstance(share, (int, float)) and float(share) < LOW_CONFIDENCE_SHARE
    kind = artwork.get("kind")
    return {
        "trust": round(trust, 4),
        "cropNeedsLook": trust < NEEDS_LOOK_BELOW,
        "pictureKind": "digital" if kind == "flat" else "photo",
        "kind": kind,
        "resolution": resolution or None,
        "notThePicture": not_the_picture,
        "estimateLowConfidence": bool(not_the_picture or (trust < LOW_CONFIDENCE_TRUST and small_share)),
        "priority": priority(solved_count),
        "signals": {k: (None if v is None else round(float(v), 4)) for k, v in raw.items()},
        "artworkVersion": artwork_version,
        "model": model["version"],
    }


def load_model(path: Path = MODEL_PATH) -> dict[str, Any]:
    return json.loads(Path(path).read_text())


def compute(artwork_rows: Mapping[str, Mapping[str, Any]], crop_vectors: Mapping[str, np.ndarray],
            solved: Mapping[str, int], model: Mapping[str, Any] | None = None) -> dict[str, dict[str, Any]]:
    """`artwork_rows`: puzzleId -> the stored row (current picture), `crop_vectors`: puzzleId -> SigLIP 2 crop vector
    of the same picture, `solved`: puzzleId -> solvedCount."""
    model = dict(model) if model is not None else load_model()
    model["probe"] = dict(model["probe"])
    out = {}
    for pid in sorted(artwork_rows):
        row = artwork_rows[pid]
        result = as_map(row.get("result"))
        if not result.get("bbox"):
            continue
        out[pid] = payload(model, result, crop_vectors.get(pid), solved.get(pid), row.get("analyzerVersion"))
    return out


# --- fitting (offline, by hand; the result is the JSON in the repo) ----------------------------------------------
def fit_model(artwork: Mapping[str, Mapping[str, Any]], crop_vectors: Mapping[str, np.ndarray],
              full_vectors: Mapping[str, np.ndarray], fingerprints: Mapping[str, str],
              truth_sets: Mapping[str, Mapping[str, Mapping[str, Any]]], version: str,
              train_sets: Iterable[str] = ("tuning", "feedback"), validation_set: str = "reference",
              probe_per_class: int = 6000, seed: int = 0) -> dict[str, Any]:
    """`artwork`: puzzleId -> stored artwork result (the version to trust), `crop_vectors` / `full_vectors`: SigLIP 2
    of the crop / whole image, `fingerprints`: puzzleId -> current picture, `truth_sets`: name -> artwork_eval truth
    rows. A labelled outline counts only for the picture it was drawn on."""
    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import brier_score_loss, roc_auc_score
    from sklearn.preprocessing import StandardScaler

    from ..artwork_eval import score, stored_candidate

    labelled = {pid for rows in truth_sets.values() for pid in rows}
    # 1. the "looks like clean digital artwork" probe: crops of flat pictures vs whole images of box photos
    ids = sorted(p for p in set(crop_vectors) & set(full_vectors) if p in artwork)
    dim = max({unit(crop_vectors[p]).size for p in ids[:50]})  # type: ignore[union-attr]
    ids = [p for p in ids if np.asarray(crop_vectors[p]).size == dim and np.asarray(full_vectors[p]).size == dim]
    kinds = np.array([artwork[p].get("kind") for p in ids])
    free = ~np.isin(ids, sorted(labelled))
    rng = np.random.default_rng(seed)
    flat = np.where((kinds == "flat") & free)[0]
    photo = np.where(np.isin(kinds, BOX_KINDS) & free)[0]
    flat = rng.choice(flat, min(probe_per_class, len(flat)), replace=False)
    photo = rng.choice(photo, min(probe_per_class, len(photo)), replace=False)
    X = np.vstack([np.vstack([unit(crop_vectors[ids[i]]) for i in flat]), np.vstack([unit(full_vectors[ids[i]]) for i in photo])])
    y = np.r_[np.ones(len(flat)), np.zeros(len(photo))]
    probe = LogisticRegression(C=1.0, max_iter=3000).fit(X, y)
    model: dict[str, Any] = {
        "version": version,
        "target": f"crop within {WITHIN:.0%} of the hand-marked artwork outline on every side (artwork_eval cut and extra <= {WITHIN})",
        "features": list(FEATURES),
        "probe": {"dim": int(dim), "dtype": "f32", "coef": base64.b64encode(probe.coef_[0].astype("<f4").tobytes()).decode("ascii"),
                  "intercept": float(probe.intercept_[0]), "trainedOn": {"flatCrops": int(len(flat)), "boxPhotoWholeImages": int(len(photo)), "seed": seed}},
    }
    fl_all = [flatlike(model, crop_vectors.get(p)) for p in ids]
    fl_median = float(np.median([v for v in fl_all if v is not None]))
    # 2. the labelled crops: the stored crop scored against the outline, with its signals
    rows: list[tuple[str, np.ndarray, int]] = []
    model["fill"] = {"iouFirstPass": 1.0, "iouVlm": 1.0, "meanSupportInner": 1.0, "maxSimilarityInner": 0.0, "trim": 0.0,
                     "boxKind": 0.0, "assembled": 0.0, "flatlike": fl_median}
    for name, truth in truth_sets.items():
        for pid, t in truth.items():
            a = artwork.get(pid)
            if a is None or fingerprints.get(pid) != t.get("fingerprint") or not a.get("bbox"):
                continue
            src = as_map(a.get("source"))
            aspect = float(src.get("width") or 1) / float(src.get("height") or 1)
            s = score(stored_candidate(dict(a)), dict(t), aspect)
            good = int(s["cut"] <= WITHIN and s["extra"] <= WITHIN)
            raw = raw_signals(a) | {"flatlike": flatlike(model, crop_vectors.get(pid))}
            rows.append((name, design(model, raw), good))
    train = [(x, g) for n, x, g in rows if n in set(train_sets)]
    valid = [(x, g) for n, x, g in rows if n == validation_set]
    Xt, yt = np.vstack([x for x, _ in train]), np.array([g for _, g in train])
    scaler = StandardScaler().fit(Xt)
    lr = LogisticRegression(C=0.3, max_iter=5000).fit(scaler.transform(Xt), yt)
    model["logistic"] = {"mean": [float(v) for v in scaler.mean_], "scale": [float(v) for v in scaler.scale_],
                         "coef": [float(v) for v in lr.coef_[0]], "intercept": float(lr.intercept_[0]), "C": 0.3}
    model["trainedOn"] = {"sets": list(train_sets), "n": int(len(yt)), "within": float(yt.mean())}
    if valid:
        Xv, yv = np.vstack([x for x, _ in valid]), np.array([g for _, g in valid])
        p = np.array([trust_of(model, x) for x in Xv])
        bands = []
        for lo, hi in ((0, 0.3), (0.3, 0.5), (0.5, 0.7), (0.7, 0.85), (0.85, 0.95), (0.95, 1.0001)):
            m = (p >= lo) & (p < hi)
            bands.append({"from": lo, "to": min(hi, 1.0), "n": int(m.sum()), "within": round(float(yv[m].mean()), 3) if m.any() else None})
        flags = {}
        for thr in (0.3, 0.5, 0.7):
            f = p < thr
            flags[str(thr)] = {"flagged": int(f.sum()), "badAmongFlagged": round(float(1 - yv[f].mean()), 3) if f.any() else None,
                               "badCaught": int((1 - yv)[f].sum()), "bad": int((1 - yv).sum())}
        model["evaluation"] = {"set": validation_set, "n": int(len(yv)), "within": round(float(yv.mean()), 3),
                               "auc": round(float(roc_auc_score(yv, p)), 3), "brier": round(float(brier_score_loss(yv, p)), 4),
                               "brierBaseRate": round(float(brier_score_loss(yv, np.full(len(yv), yt.mean()))), 4),
                               "calibration": bands, "flagging": flags}
    return model


def write_model(model: Mapping[str, Any], path: Path = MODEL_PATH) -> None:
    clean = dict(model)
    clean["probe"] = {k: v for k, v in as_map(model["probe"]).items() if not k.startswith("_")}
    Path(path).write_text(json.dumps(clean, indent=1) + "\n")


def main(argv: list[str] | None = None) -> int:
    """Refit the frozen model from production (read-only bulk reads) and the labelled outlines in reference/:
    `python -m puzzle_vision.derive.crop_trust --profile m3-solo --version 1.0.0 [--out FILE]` - then read the
    `evaluation` it prints, commit the JSON and bump the `crop_trust` version in every profile."""
    import argparse

    from ..artwork_eval import REFERENCE_DIR, load_truth
    from ..config import DEFAULT_BASE_URL
    from ..payload import b64_to_vector

    p = argparse.ArgumentParser(prog="python -m puzzle_vision.derive.crop_trust")
    p.add_argument("--profile", default="m3-solo")
    p.add_argument("--base-url", default=DEFAULT_BASE_URL)
    p.add_argument("--version", required=True, help="the model's version, e.g. 1.0.0")
    p.add_argument("--out", default=str(MODEL_PATH))
    a = p.parse_args(argv)
    from ..cli import Context

    ctx = Context(argparse.Namespace(profile=a.profile, base_url=a.base_url))
    meta = {i.puzzle_id: i.fingerprint for i in ctx.api.iter_images(status="all", limit=1000)}

    def current(analyzer: str) -> dict[str, dict[str, Any]]:
        return {r["puzzleId"]: r for r in ctx.api.iter_analyses(analyzer, only_current=True, limit=1000)
                if meta.get(r["puzzleId"]) == r.get("imageFingerprint")}

    def vectors(analyzer: str) -> dict[str, np.ndarray]:
        return {pid: b64_to_vector(r["result"]["vector"]) for pid, r in current(analyzer).items() if as_map(r["result"]).get("vector")}

    artwork = {pid: as_map(r["result"]) for pid, r in current("artwork").items()}
    truth = {name: load_truth([REFERENCE_DIR / f"artwork-truth-{name}.jsonl"]) for name in ("reference", "tuning", "feedback")}
    model = fit_model(artwork, vectors("embed_siglip2"), vectors("embed_siglip2_full"), meta, truth, a.version)
    write_model(model, Path(a.out))
    print(json.dumps(model.get("evaluation"), indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
