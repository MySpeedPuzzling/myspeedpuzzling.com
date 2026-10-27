"""`difficulty_prior` - picture-based difficulty estimates (implementation-plan.md §5.3, runbook image-pipeline.md §7).

Light learners ("rung b" of prediction-research.md §3: seconds to minutes on the CPU, no fine-tuning, no language model
for numbers) from the stored picture embeddings + the piece count to the puzzle's as-of log difficulty. Nightly on the
M3 after the daily pipeline (`derive`), during the backfill on whatever is embedded so far.

Contract: docs/features/internal-api.md § "Puzzle difficulty labels and estimates" (client in `puzzle_vision/api.py`).

Data
- Labels: `api.iter_difficulty_labels()` - `logDifficulty` (as-of), `indexCount`, `piecesCount`, `manufacturerId`,
  `addedAt`, `imageFingerprint`. Aggregates only; secret puzzles are never listed.
- Pictures: the work list (`iter_images(status=all)`, the puzzle's current fingerprint + piece count; secret pictures
  are never listed). Embeddings and the `artwork` result come from the bulk endpoint (`onlyCurrent=1`). **Every join is
  on (puzzleId, imageFingerprint)**: an embedding row or a label of another picture than the puzzle's current one is
  left out. Of every analyzer only its newest `analyzerVersion` is used (a version bump re-runs over days - vectors of
  two models are never mixed), and within it only the most common dimension. Everything left out is counted under
  `dropped`.

Two models, each with its own model record, push guard and estimates (`model` field):
1. `ridge-siglip2-dinov2` (the model of record): SigLIP 2 crop (`embed_siglip2`) + DINOv2 crop (`embed_dinov2`) +
   pieces + the crop's **extras** (1.1.0): the Apple Vision feature print (`vision_labels`), the DINOv2 patch mean
   (`embed_dinov2_patches`) and 45 numbers of `traits` / `colors` / `segmentation` / `vision_labels` (EXTRA_NAMES),
   that block repeated EXTRA_WEIGHT times in the ridge. A puzzle without an extra gets the corpus mean of it (counted
   under `extrasImputed`), so no puzzle loses its estimate to a missing analyzer. Estimates every puzzle with both crop
   embeddings.
   **Since 1.2.0 an ensemble**: 0.5 × that ridge + 0.5 × a **kernel ridge** (RBF) on the same inputs - the four
   embedding blocks each unit-normed (÷ 2, so the four together have norm 1), the numbers and the piece features
   standardised on the training rows and weighted 0.5 / √dim each (KERNEL_* constants: bandwidth 2, alpha 0.1 - fixed,
   chosen on the older puzzles of the 2026-10-24 data, recorded in every model record). Exact (Cholesky) up to
   NYSTROM_ABOVE training rows, a seeded Nyström approximation with NYSTROM_LANDMARKS landmarks above. Measured
   (prediction-model review 2026-10-26, image-model P1; again in this module's own validate, docs/features/insights/
   estimates.md "Picture estimate 1.2.0"): newer from older Spearman +0.02 at three time cuts, MAE -4 %.
   On top, a shrunk **brand × size offset** (BRAND_SIZE_PSEUDO_COUNT): Σ w r / (Σ w + 10) of the ensemble's
   out-of-fold residuals of the same manufacturer at the same piece count (the brand from the work list, else the
   label) - cut, print and finish the picture cannot show (data-mining review F1/F3; measured in validate: newer from
   older MAE -3 %, estimates.md). A new brand or size gets 0. For an out-of-fold estimate the residuals come from
   "pair models" trained without its fold and without the residual's own fold (nested_offsets) - its own label never
   reaches it, not even through other puzzles' residuals. The fallback model gets no offset.
   An optional `piece_scale` block (PIECE_SCALE_NAMES of its `real` block, another CPU analyzer) joins the numbers
   once PIECE_SCALE_MIN_COVERAGE of the training rows have it - the estimates then say `modelVersion`
   PIECE_SCALE_MODEL_VERSION (a new estimate generation: the app's gates close until it is evaluated).
2. `ridge-siglip2-full` (fallback, ridge only): the SigLIP 2 whole-image embedding (`embed_siglip2_full`, else the
   crop's) + pieces. It learns from the whole-image vectors because those are what it is applied to. It estimates the
   puzzles the model of record does not: those without crop embeddings and, while the model of record has fewer than
   MIN_TRAINING rows (phase A of the backfill), every puzzle it can.
A puzzle with no SigLIP 2 vector at all gets no estimate.
Pieces = ln(pieces), ln(pieces)², one-hot of the size group (≤300 / 500 / 1000 / 1500+ / odd - research Q1 groups);
the ridge standardises everything (StandardScaler), alpha by efficient leave-one-out CV (`RidgeCV`, GCV) inside every
fit.

Training: labelled puzzles with `indexCount` >= 3, weighted n/(n+10). A model with fewer than MIN_TRAINING (200) rows
records and pushes nothing (said in the summary).

Folds: **near-duplicate artwork stays in one fold** - 5-fold GroupKFold (shuffled, fixed seed) over the connected
components of cosine >= 0.95 between the training rows' SigLIP 2 vectors (the same picture in another edition, size or
brand). When one component holds more than 20 % of the rows the grouping is off (plain puzzles as groups) and the
record says so (`folds.grouping`).

Estimates:
- labelled training puzzles: **cross-fitted out-of-fold** (`outOfFold: true`) - the fold model never saw them;
- labelled puzzles with 1-2 indices: the full model, which was not trained on them either → `outOfFold: true`;
- unlabelled puzzles: the full model, `outOfFold: false`.

Intervals (1.2.0, image-model review P4): log_p10 / log_p25 / log_p75 / log_p90 = estimate + q × σ̂. σ̂ = a Huber
regression of |residual| (out-of-fold residuals of well measured puzzles, >= 10 indices) on SIGMA_FEATURES - the
estimate itself, how close and how alike its 10 nearest training pictures are (SigLIP 2 cosine; for a training row
only pictures of the other folds), the crop's flags (uncertain crop, box-face pass, trim, artwork share), ln
pixels-per-piece, size group and image kind - shrunk toward its mean (SIGMA_SHRINK) and floored at SIGMA_FLOOR; the
interval model describes the ensemble's residuals, the brand × size offset moves the whole window; q = quantiles of
the **normalised** residuals
r / σ̂ (σ̂ cross-fitted, never its own) per size group, falling back to all of them below 50 (normalised split-
conformal). Below MIN_SIGMA_ROWS well measured residuals: the 1.1.0 table (residual quantiles per size group × image
kind, then size group, then all). **A training puzzle's interval never contains its own residual**: rows of fold k
take the model fitted on the other folds' residuals only (the same in the brand holdout; the newer-from-older holdout
cross-fits inside the older puzzles). Ordered p10 <= p25 <= estimate <= p75 <= p90, clipped to ±5; a non-finite
number is never pushed.

Stored with every estimate (1.2.0, unused by the shown predictions): `pixelsPerPiece` (√(artwork width × height in
the original picture ÷ pieces) - the side of one piece in pixels), `cropTrust` (the `crop_trust` analyzer's `trust`
of the same picture when it exists, else null) and 16 **picture factors**: the SigLIP 2 crop vector projected on a
frozen, versioned PCA basis (`picture_factors/`, PICTURE_FACTORS_VERSION; whitened - each factor has unit variance
over the catalogue it was fitted on). The basis never changes between nights; a new basis is a new version and a new
file. A SigLIP 2 vector of another model than the basis was fitted on gets no factors.

Validation per run (the model record's `evaluation`): out-of-fold on well measured puzzles (`indexCount` >= 20, lower
thresholds 10 / 5 / 3 only while fewer than 30 puzzles reach it - `testMinIndices` says which), **out-of-fold on the
newest 30 % with >= 10 indices** (`newest30` - the population the app's gate measures, image-model review P6), brands
held out (GroupKFold over brand ∪ near-duplicate components), newer predicted from older (oldest 70 % by `addedAt` →
the rest, newer puzzles with a near-duplicate among the older ones left out; its Spearman = `holdoutSpearman`; with
each part of the ensemble alone and the 1.1.0 interval table next to it), per size group × image kind (`artwork`
kind: flat / box / assembled / other / unknown). Numbers: Spearman, MAE of log difficulty, tier exact / ±1
(DifficultyTier::fromScore cut points) next to always-"Average", 50 % / 80 % interval coverage + width.

Push guard (per model), two checks - either one blocks:
- **paired** (1.2.0, image-model review P3): every recorded run keeps its newer-from-older model (the ensemble fitted
  on the older puzzles + its interval model + the ids it was trained on) in the run-report directory
  (`difficulty_prior-<model>-holdout.pkl`, written only when the run is recorded). The next run scores that model and
  its own on tonight's newer test puzzles the stored one never trained on - the same puzzles, the same labels: blocked
  when Spearman falls more than GUARD_PAIRED_DROP or the MAE rises more than GUARD_PAIRED_MAE_RISE. Window coverage of
  both is recorded. Skipped (with the reason) without a stored model, with inputs of another shape, or with fewer than
  GUARD_MIN_TEST shared test puzzles.
- **against the record** (1.0.0): the model's current record (`current_difficulty_model(model)`), only like with
  like - same `holdoutSource` and `holdoutTestMinIndices`, >= 100 holdout test puzzles in both runs and a training size
  within 25 % of the previous one; blocked when the new holdout Spearman is more than 0.05 below. It catches what the
  paired check cannot: labels that broke (both models are then scored against the broken labels).
A blocked run records and pushes nothing for that model (it must not become the next night's baseline): an error in
the log, `blockedSince` in `difficulty_prior-last-run.json` (`status` shows it). A human overrides with
`derive --only difficulty_prior --force-model` (the record then says `guard.forced: true`).
Push order per model: record, then the estimates (chunks of 500). Outcomes `stale_fingerprint` / `hidden` /
`unknown_puzzle` are skipped quietly and counted; `invalid` is our bug (logged as a warning).

Reproducible: fixed fold seeds, deterministic solvers, puzzles in id order.
"""

from __future__ import annotations

import base64
import json
import logging
import math
import os
import pickle
import re
import time
import warnings
from collections import Counter
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Iterable

import numpy as np

from ..api import ApiError, DifficultyEstimate, DifficultyLabel, DifficultyModelRecord
from ..payload import as_map

log = logging.getLogger(__name__)

LABELS_PATH = "/internal-api/puzzle-difficulty-labels"
ESTIMATES_PATH = "/internal-api/puzzle-difficulty-estimates"
REPORT_FILE = "difficulty_prior-last-run.json"
HOLDOUT_FILE = "difficulty_prior-{model}-holdout.pkl"
HOLDOUT_FORMAT = 1

MODEL = "ridge-siglip2-dinov2"
FALLBACK_MODEL = "ridge-siglip2-full"
MODELS = (MODEL, FALLBACK_MODEL)
MODEL_VERSION = "1.2.0"
PIECE_SCALE_MODEL_VERSION = "1.2.1"  # the same learners with the `piece_scale` block in the numbers
VERSION = f"{MODEL_VERSION}+{MODEL}"  # bump MODEL_VERSION on any change of numbers

MIN_TRAIN_INDICES = 3
TEST_MIN_INDICES = (20, 10, 5, 3)
MIN_TEST = 30
MIN_HOLDOUT_TEST = 30
MIN_TRAINING = 200
INTERVAL_MIN_INDICES = 10
NEWEST_MIN_INDICES = 10
MIN_GROUP_RESIDUALS = 50
GUARD_DROP = 0.05
GUARD_MIN_TEST = 100
GUARD_MAX_TRAINING_CHANGE = 0.25
GUARD_PAIRED_DROP = 0.02  # ~3 SE of a paired Spearman difference on ~400 test puzzles (review: CI ±0.011 at 760)
GUARD_PAIRED_MAE_RISE = 0.03  # relative; ~3 SE of the paired MAE difference
NEAR_DUPLICATE_COSINE = 0.95
MAX_CLUSTER_SHARE = 0.2
FOLDS = 5
SEED = 0
TIME_SPLIT = 0.7
ALPHAS = np.logspace(-2, 6, 33)  # GCV picks one per fit; the record says when it sits on an end of the grid
PREDICT_CHUNK = 4096
SIMILARITY_BLOCK = 1024
LOG_LIMIT = 4.99
# src/Value/DifficultyTier.php fromScore(): `score < edge` is the easier tier. 1e-6 below the edge: a score exactly on
# a cut point whose ln was rounded to 6 decimals still lands in the harder tier, as the app's score does.
TIER_EDGES = np.log([0.75, 0.90, 1.10, 1.25, 1.45]) - 1e-6
AVERAGE_TIER = 3
SIZE_GROUPS = ("<=300", "500", "1000", "1500+", "odd")
KINDS = {"flat": "flat", "box_front": "box", "box_angled": "box", "assembled": "assembled", "other": "other"}
IMAGE_KINDS = ("flat", "box", "assembled", "other", "unknown")
EMBEDDINGS = ("embed_siglip2", "embed_dinov2", "embed_siglip2_full")
EXTRA_WEIGHT = 3  # the numbers block is repeated this many times in the ridge's input
SEGMENTATION_SHARES = {"sky": ("sky",), "water": ("sea", "river", "water"), "foliage": ("tree", "grass", "plant", "flower"),
                       "building": ("building", "house", "roof", "bridge"), "person": ("person",)}
EXTRA_NAMES = tuple(sorted(
    [f"t.{k}" for k in ("detail", "symmetry", "flatShare", "textShare", "borderUniform", "interiorUniform", "lowGradientCells")]
    + [f"t.grid.{k}.{i}" for k in ("flat", "chroma", "gradient", "lightness") for i in range(4)]
    + [f"c.{k}" for k in ("darkShare", "chromaMean", "lightShare", "mutedShare", "colorfulness", "lightnessStd",
                          "paletteEntropy", "paletteTop", "familyEntropy", "familyCount")]
    + [f"s.{k}" for k in (*SEGMENTATION_SHARES, "top", "entropy", "classes", "instances")]
    + ["v.aesthetics", "v.saliency", "v.faces"]
))

# the kernel ridge half of the ensemble (image-model review P1: tuned once on the older puzzles, fixed and recorded)
KERNEL_SHARE = 0.5
KERNEL_BANDWIDTH = 2.0  # exp(-|a - b|² / h); the inputs have norm ~1
KERNEL_ALPHA = 0.1  # × 1/weight on the diagonal: Σ w (y - f)² + alpha ||f||²
KERNEL_NUMBERS_WEIGHT = 0.5  # standardised numbers × weight / √dim
KERNEL_PIECES_WEIGHT = 0.5
NYSTROM_ABOVE = 20_000  # an exact kernel on more rows needs > 3 GB and minutes per fit
NYSTROM_LANDMARKS = 6_000

# heteroscedastic intervals (image-model review P4)
SIGMA_FLOOR = 0.02
# σ̂' = mean + SIGMA_SHRINK × (σ̂ - mean): the fitted σ̂ over-corrects on newer puzzles (80 % coverage by doubt tercile
# 0.75 / 0.78 / 0.85 at shrink 1); 0.75 evens it (0.78 / 0.76 / 0.82) at an equal or better Winkler score at 60/70/80 %
SIGMA_SHRINK = 0.75
# brand × size offset on top of the ensemble (data-mining review F1/F3): Σ w r / (Σ w + k) of the out-of-fold residuals
# of the same brand at the same piece count; None = off
BRAND_SIZE_PSEUDO_COUNT: float | None = 10.0
MIN_SIGMA_ROWS = 200  # well measured residuals the σ̂ model needs, else the size × kind table
KNN_K = 10
KNN_CANDIDATES = 64  # neighbours kept per training row; enough after leaving out up to two folds
SIGMA_FEATURES = ("estimate", "knnTop1", "knnMean", "absEstimateMinusKnn", "knnLabelSd", "uncertainCrop", "faceCrop",
                  "trimCrop", "artworkShare", "logPixelsPerPiece",
                  *(f"size={g}" for g in SIZE_GROUPS), *(f"kind={k}" for k in IMAGE_KINDS))

# optional analyzers another workstream builds - read when they exist, never required
CROP_TRUST = "crop_trust"
PIECE_SCALE = "piece_scale"
PIECE_SCALE_NAMES = ("tilePx", "nnMedian", "nnP25", "nnShareBelow003", "nnShareBelow006", "nnShareBelow010",
                     "logCandidates006", "logCandidates010", "lowDetailShare", "fineTextureMedian", "fineTextureP25",
                     "pileHhi16", "pileEntropy16", "pileHhi32", "colourNnMedian", "textureNnMedian", "largestPileShare16",
                     "boundaryEdgeShare", "logCandidatesColour006", "hueEntropy")
PIECE_SCALE_MIN_COVERAGE = 0.9

# picture factors: a frozen PCA basis of the SigLIP 2 crop vector (data-mining review §3, the taste model's input)
PICTURE_FACTORS_VERSION = "1.0.0"
PICTURE_FACTORS = 16
PICTURE_FACTORS_DIR = Path(__file__).parent / "picture_factors"


class NotAvailableYet(RuntimeError):
    pass


# small pure helpers -------------------------------------------------------------------------------------------------
def size_group(pieces: int) -> str:
    if pieces <= 300:
        return "<=300"
    if pieces == 500:
        return "500"
    if pieces == 1000:
        return "1000"
    if pieces >= 1500:
        return "1500+"
    return "odd"


def image_kind(artwork_result: Any) -> str:
    return KINDS.get(str(as_map(artwork_result).get("kind") or ""), "unknown")


def tier(log_difficulty: Any) -> np.ndarray:
    """DifficultyTier::fromScore on exp(log difficulty): 1 very easy … 6 very hard (a score on a cut point is the
    harder tier, like `$score < 0.75`)."""
    return np.searchsorted(TIER_EDGES, np.asarray(log_difficulty, dtype=float), side="right") + 1


def piece_features(pieces: np.ndarray) -> np.ndarray:
    lp = np.log(np.maximum(np.asarray(pieces, dtype=float), 1.0))
    onehot = np.array([[size_group(int(p)) == g for g in SIZE_GROUPS] for p in pieces], dtype=float).reshape(len(lp), len(SIZE_GROUPS))
    return np.c_[lp, lp ** 2, onehot]


def weights(index_counts: np.ndarray) -> np.ndarray:
    n = np.asarray(index_counts, dtype=float)
    return n / (n + 10.0)


def spearman(a: np.ndarray, b: np.ndarray) -> float | None:
    from scipy.stats import spearmanr

    if len(a) < 3 or np.ptp(a) == 0 or np.ptp(b) == 0:
        return None
    value = float(spearmanr(a, b)[0])
    return None if np.isnan(value) else value


def _decode(data: Any) -> np.ndarray | None:
    if not isinstance(data, str) or not data:
        return None
    try:
        return np.frombuffer(base64.b64decode(data), dtype="<f2").copy()  # kept as float16: ~180 MB for the catalogue
    except (ValueError, TypeError):
        return None


def version_key(version: str, received: str) -> tuple[Any, ...]:
    """Newest first by the numeric `<major>.<minor>.<patch>` before the `+`, then by the latest row received."""
    nums = tuple(int(x) for x in re.findall(r"\d+", str(version).split("+", 1)[0])[:3])
    return nums, received or ""


def _entropy(shares: Iterable[Any]) -> float:
    s = np.array([float(x) for x in shares if isinstance(x, (int, float)) and x > 0])
    if s.size == 0:
        return 0.0
    s = s / s.sum()
    return float(-(s * np.log(s)).sum())


def _grid_stats(values: Any) -> list[float]:
    a = np.asarray(values if isinstance(values, list) else [], dtype=float)
    if a.size == 0:
        return [np.nan] * 4
    return [float(a.mean()), float(a.std()), float(np.percentile(a, 10)), float(np.percentile(a, 90))]


def _num(x: Any) -> float | None:
    return float(x) if isinstance(x, (int, float)) and not isinstance(x, bool) and math.isfinite(float(x)) else None


def unit_rows(v: np.ndarray) -> np.ndarray:
    v = np.asarray(v, dtype=np.float32)
    return v / np.maximum(np.linalg.norm(v, axis=1, keepdims=True), 1e-12)


def extra_numbers(traits: Any, colors: Any, segmentation: Any, labels: Any) -> np.ndarray:
    """The 45 numbers of the extras (EXTRA_NAMES order) from the four analyses of one picture; NaN where one is missing."""
    t, c, s, v = as_map(traits), as_map(colors), as_map(segmentation), as_map(labels)
    f: dict[str, float] = {}

    def num(x: Any) -> float:
        return float(x) if isinstance(x, (int, float)) and not isinstance(x, bool) else 0.0

    if t:
        for k in ("detail", "symmetry", "flatShare", "textShare"):
            f[f"t.{k}"] = num(t.get(k))
        border = as_map(t.get("borderBand"))
        f["t.borderUniform"] = num(border.get("uniformShare"))
        f["t.interiorUniform"] = num(border.get("interiorUniformShare"))
        grid = as_map(t.get("grid16"))
        for k in ("flat", "chroma", "gradient", "lightness"):
            for i, x in enumerate(_grid_stats(grid.get(k))):
                f[f"t.grid.{k}.{i}"] = x
        gradient = np.asarray(grid.get("gradient") if isinstance(grid.get("gradient"), list) else [], dtype=float)
        f["t.lowGradientCells"] = float((gradient < 20).mean()) if gradient.size else np.nan
    if c:
        for k in ("darkShare", "chromaMean", "lightShare", "mutedShare", "colorfulness", "lightnessStd"):
            f[f"c.{k}"] = num(c.get(k))
        palette = [as_map(x).get("share", 0) for x in (c.get("palette") if isinstance(c.get("palette"), list) else [])]
        f["c.paletteEntropy"] = _entropy(palette)
        f["c.paletteTop"] = max((num(x) for x in palette), default=0.0)
        families = as_map(c.get("families"))
        f["c.familyEntropy"] = _entropy(families.values())
        f["c.familyCount"] = float(len(families))
    if s:
        classes = {k: num(x) for k, x in as_map(s.get("classes")).items()}
        for name, prefixes in SEGMENTATION_SHARES.items():
            f[f"s.{name}"] = sum(x for k, x in classes.items() if k.startswith(prefixes))
        f["s.top"] = max(classes.values(), default=0.0)
        f["s.entropy"] = _entropy(classes.values())
        f["s.classes"] = float(len(classes))
        f["s.instances"] = sum(num(x) for x in as_map(s.get("instances")).values())
    if v:
        f["v.aesthetics"] = num(as_map(v.get("aesthetics")).get("overall"))
        f["v.saliency"] = num(as_map(v.get("saliency")).get("areaShare"))
        f["v.faces"] = float(len(v.get("faces") if isinstance(v.get("faces"), list) else []))
    return np.array([f.get(k, np.nan) for k in EXTRA_NAMES], dtype=np.float32)


def piece_scale_numbers(result: Any) -> np.ndarray | None:
    """PIECE_SCALE_NAMES of the `piece_scale` result's `real` block (the puzzle's own piece count); None without one."""
    real = as_map(as_map(result).get("real"))
    if not real:
        return None
    values = [_num(real.get(k)) for k in PIECE_SCALE_NAMES]
    return np.array([np.nan if x is None else x for x in values], dtype=np.float32)


@dataclass
class Crop:
    """What the `artwork` result says about the crop the embeddings were made from (artwork review: an "uncertain"
    crop = a quality reason other than not_digital_artwork, a box-face pass, or artwork < 90 % of the photo)."""

    uncertain: float = 0.0
    face: float = 0.0
    trim: float = 0.0
    share: float = 1.0
    pixels: float | None = None  # artwork width × height in the original picture

    def pixels_per_piece(self, pieces: int) -> float | None:
        if not self.pixels or pieces < 1:
            return None
        return float(math.sqrt(self.pixels / pieces))


def crop_of(artwork_result: Any) -> Crop:
    r = as_map(artwork_result)
    q = as_map(r.get("quality"))
    reasons = {str(x) for x in (q.get("reasons") if isinstance(q.get("reasons"), list) else [])}
    method = str(r.get("method") or "")
    share = _num(q.get("artworkShare"))
    px = as_map(q.get("artworkPixels"))
    w, h = _num(px.get("width")), _num(px.get("height"))
    uncertain = bool(reasons - {"not_digital_artwork"}) or "face" in method or (share is not None and share < 0.9)
    return Crop(uncertain=float(uncertain), face=float("face" in method), trim=float(method == "trim"),
                share=1.0 if share is None else min(1.0, max(0.0, share)),
                pixels=w * h if w and h and w > 0 and h > 0 else None)


# the corpus ---------------------------------------------------------------------------------------------------------
@dataclass
class Puzzle:
    puzzle_id: str
    fingerprint: str
    pieces: int
    kind: str = "unknown"
    label: DifficultyLabel | None = None
    crop: Crop = field(default_factory=Crop)
    brand: str | None = None  # the manufacturer id

    @property
    def size_group(self) -> str:
        return size_group(self.pieces)

    @property
    def brand_size(self) -> str | None:
        return f"{self.brand}|{self.pieces}" if self.brand else None

    @property
    def pixels_per_piece(self) -> float | None:
        return self.crop.pixels_per_piece(self.pieces)


@dataclass
class Design:
    """The inputs of a set of rows: the ridge's matrix `X` and the kernel's parts - `E` the unit-normed embedding blocks
    (÷ 2 each), `N` the numbers (imputed), `P` the piece features. Indexable like an array."""

    X: np.ndarray
    E: np.ndarray | None = None
    N: np.ndarray | None = None
    P: np.ndarray | None = None

    def __len__(self) -> int:
        return len(self.X)

    def __getitem__(self, idx: Any) -> "Design":
        return Design(self.X[idx], *(None if a is None else a[idx] for a in (self.E, self.N, self.P)))

    @property
    def kernel(self) -> bool:
        return self.E is not None

    def dims(self) -> list[int]:
        return [int(self.X.shape[1]), *(0 if a is None else int(a.shape[1]) for a in (self.E, self.N, self.P))]


@dataclass
class Corpus:
    puzzles: dict[str, Puzzle]
    siglip: dict[str, np.ndarray] = field(default_factory=dict)  # crop
    dino: dict[str, np.ndarray] = field(default_factory=dict)  # crop
    full: dict[str, np.ndarray] = field(default_factory=dict)  # whole image - kept only where the fallback needs it
    # the model of record's extras: feature print, DINOv2 patch mean, the 45 numbers, piece_scale - each with its mean
    feature_print: dict[str, np.ndarray] = field(default_factory=dict)
    patches: dict[str, np.ndarray] = field(default_factory=dict)
    numbers: dict[str, np.ndarray] = field(default_factory=dict)
    piece_scale: dict[str, np.ndarray] = field(default_factory=dict)
    extra_means: dict[str, np.ndarray] = field(default_factory=dict)
    crop_trust: dict[str, float] = field(default_factory=dict)
    use_piece_scale: bool = False
    versions: dict[str, str] = field(default_factory=dict)  # the analyzer version each input was read in
    dropped: Counter = field(default_factory=Counter)

    def has_primary(self, pid: str) -> bool:
        return pid in self.siglip and pid in self.dino

    def has_fallback(self, pid: str) -> bool:
        return pid in self.full or pid in self.siglip

    def _pieces(self, pids: list[str]) -> np.ndarray:
        return piece_features(np.array([self.puzzles[p].pieces for p in pids]))

    def _extra(self, name: str, values: dict[str, np.ndarray], pids: list[str]) -> list[np.ndarray]:
        mean = self.extra_means.get(name)
        if mean is None:
            return []
        out = []
        for p in pids:
            x = values.get(p)
            if x is None:
                out.append(mean)
            else:
                bad = ~np.isfinite(x)
                out.append(np.where(bad, mean, x) if bad.any() else x)
        return [np.vstack(out).astype(np.float32)]

    def _numbers(self, pids: list[str]) -> list[np.ndarray]:
        blocks = self._extra("numbers", self.numbers, pids)
        if self.use_piece_scale:
            blocks += self._extra("piece_scale", self.piece_scale, pids)
        return [np.hstack(blocks)] if blocks else []

    def primary_x(self, pids: list[str]) -> Design:
        siglip = np.vstack([self.siglip[p] for p in pids]).astype(np.float32)
        dino = np.vstack([self.dino[p] for p in pids]).astype(np.float32)
        fprint = self._extra("feature_print", self.feature_print, pids)
        patches = self._extra("patches", self.patches, pids)
        numbers = self._numbers(pids)
        pieces = self._pieces(pids)
        X = np.hstack([siglip, dino, *fprint, *patches, *(numbers * EXTRA_WEIGHT), pieces]).astype(np.float32)
        E = np.hstack([unit_rows(b) / 2 for b in (siglip, dino, *fprint, *patches)]).astype(np.float32)
        N = numbers[0] if numbers else np.zeros((len(pids), 0), np.float32)
        return Design(X, E, N, pieces.astype(np.float32))

    def extras_imputed(self, pids: list[str]) -> int:
        def missing(p: str) -> bool:
            return (p not in self.numbers or p not in self.feature_print or p not in self.patches
                    or (self.use_piece_scale and p not in self.piece_scale))

        return sum(1 for p in pids if self.extra_means and missing(p))

    def primary_vectors(self, pids: list[str]) -> np.ndarray:
        return np.vstack([self.siglip[p] for p in pids]).astype(np.float32)

    def fallback_vectors(self, pids: list[str]) -> np.ndarray:
        return np.vstack([self.full[p] if p in self.full else self.siglip[p] for p in pids]).astype(np.float32)

    def fallback_x(self, pids: list[str]) -> Design:
        return Design(np.c_[self.fallback_vectors(pids), self._pieces(pids)].astype(np.float32))

    def crops(self, pids: list[str]) -> np.ndarray:
        """(n, 5): uncertain, face, trim, artwork share, ln pixels-per-piece (NaN unknown) - the σ̂ model's crop part."""
        out = np.zeros((len(pids), 5), dtype=float)
        for i, p in enumerate(pids):
            pz = self.puzzles[p]
            ppp = pz.pixels_per_piece
            out[i] = (pz.crop.uncertain, pz.crop.face, pz.crop.trim, pz.crop.share, np.log(ppp) if ppp else np.nan)
        return out


def _newest(analyzer: str, rows: Iterable[dict[str, Any]], corpus: Corpus, parse: Callable[[dict[str, Any], Puzzle], Any]) -> dict[str, Any]:
    """{puzzleId: parsed} of the analyzer's newest version (current pictures only); the rest counted under dropped."""
    per_version: dict[str, dict[str, Any]] = {}
    received: dict[str, str] = {}
    for row in rows:
        p = corpus.puzzles.get(row.get("puzzleId", ""))
        if p is None or row.get("imageFingerprint") != p.fingerprint:
            corpus.dropped[f"{analyzer}OfAnotherPicture"] += 1
            continue
        value = parse(row, p)
        if value is None:
            continue
        v = str(row.get("analyzerVersion") or "")
        per_version.setdefault(v, {})[p.puzzle_id] = value
        received[v] = max(received.get(v, ""), str(row.get("receivedAt") or ""))
    if not per_version:
        return {}
    newest = max(per_version, key=lambda v: version_key(v, received[v]))
    for v, values in per_version.items():
        if v != newest:
            corpus.dropped[f"{analyzer}OlderVersion"] += len(values)
    corpus.versions[analyzer] = newest
    return per_version[newest]


def _optional_rows(rows: Callable[[str], Iterable[dict[str, Any]]], analyzer: str, corpus: Corpus) -> list[dict[str, Any]]:
    """The rows of an analyzer another workstream builds - none (and never an error) while the app does not know it."""
    try:
        return list(rows(analyzer))
    except (ApiError, KeyError) as e:
        corpus.dropped[f"{analyzer}Unavailable"] += 1
        log.info("[difficulty_prior] optional analyzer %s not readable (%s) - left out", analyzer, e)
        return []


def build_corpus(labels: Iterable[DifficultyLabel], pictures: dict[str, tuple[str, int | None]],
                 rows: Callable[[str], Iterable[dict[str, Any]]], brands: dict[str, str] | None = None) -> Corpus:
    """`pictures`: puzzleId -> (current fingerprint, pieces) of every listed (= not secret) puzzle with a picture.
    `rows(analyzer)`: the current rows of one analyzer (bulk endpoint). Joins on (puzzleId, imageFingerprint).
    `brands`: puzzleId -> manufacturer id from the work list (a label's `manufacturerId` where it has none)."""
    labels = list(labels)
    by_label = {lb.puzzle_id: lb for lb in labels}
    corpus = Corpus(puzzles={})
    for pid in sorted(pictures):
        fp, pieces = pictures[pid]
        if not pieces and pid in by_label:
            pieces = by_label[pid].pieces_count
        if not fp or not pieces or pieces < 1:
            corpus.dropped["noPictureOrPieces"] += 1
            continue
        corpus.puzzles[pid] = Puzzle(pid, fp, int(pieces))
    for lb in labels:
        p = corpus.puzzles.get(lb.puzzle_id)
        if p is None:
            corpus.dropped["labelWithoutListedPicture"] += 1
        elif lb.fingerprint != p.fingerprint:
            corpus.dropped["labelOfAnotherPicture"] += 1
        else:
            p.label = lb
    for pid, p in corpus.puzzles.items():
        p.brand = (brands or {}).get(pid) or (p.label.manufacturer_id if p.label is not None else None) or None

    for pid, result in _newest("artwork", rows("artwork"), corpus, lambda row, p: as_map(row.get("result"))).items():
        corpus.puzzles[pid].kind = image_kind(result)
        corpus.puzzles[pid].crop = crop_of(result)

    def vector(analyzer: str) -> Callable[[dict[str, Any], Puzzle], Any]:
        def parse(row: dict[str, Any], p: Puzzle) -> np.ndarray | None:
            if analyzer == "embed_siglip2_full" and p.label is None and corpus.has_primary(p.puzzle_id):
                return None  # the whole-image vector serves only the fallback model: its training rows and its puzzles
            result = as_map(row.get("result"))
            vec = _decode(result.get("cls") if analyzer == "embed_dinov2" and result.get("cls") else result.get("vector"))
            if vec is None or vec.size == 0 or not np.all(np.isfinite(vec)):
                corpus.dropped[f"{analyzer}Unreadable"] += 1
                return None
            return vec
        return parse

    for analyzer in EMBEDDINGS:
        target = {"embed_siglip2": corpus.siglip, "embed_dinov2": corpus.dino, "embed_siglip2_full": corpus.full}[analyzer]
        values = _newest(analyzer, rows(analyzer), corpus, vector(analyzer))
        if values:
            sizes = Counter(v.size for v in values.values())
            dim = max(sizes, key=lambda d: (sizes[d], d))  # the most common dimension of that version
            for pid, vec in values.items():
                if vec.size == dim:
                    target[pid] = vec
                else:
                    corpus.dropped[f"{analyzer}OtherDimension"] += 1
    if corpus.full and corpus.siglip and len(next(iter(corpus.full.values()))) != len(next(iter(corpus.siglip.values()))):
        corpus.dropped["embed_siglip2_fullOtherDimension"] += len(corpus.full)
        corpus.full = {}  # the fallback mixes crop and whole-image vectors of one model - never two dimensions
    _read_extras(corpus, rows)
    return corpus


def _read_extras(corpus: Corpus, rows: Callable[[str], Iterable[dict[str, Any]]]) -> None:
    """The model of record's extras, each of its analyzer's newest version on the current picture, and the corpus mean of
    each (what a puzzle without it gets). An extra no puzzle has is left out of the input altogether. Also the optional
    analyzers: `piece_scale` (an extra once it covers PIECE_SCALE_MIN_COVERAGE of the training rows) and `crop_trust`
    (stored with the estimates only)."""
    def result(row: dict[str, Any], p: Puzzle) -> Any:
        return as_map(row.get("result")) or None

    def keep(vectors: dict[str, np.ndarray], name: str) -> dict[str, np.ndarray]:
        sizes = Counter(v.size for v in vectors.values())
        if not sizes:
            return {}
        dim = max(sizes, key=lambda d: (sizes[d], d))
        kept = {pid: v for pid, v in vectors.items() if v.size == dim and np.all(np.isfinite(v))}
        corpus.dropped[f"{name}OtherDimension"] += len(vectors) - len(kept)
        return kept

    labels = _newest("vision_labels", rows("vision_labels"), corpus, result)
    prints = {}
    for pid, r in labels.items():
        vec = _decode(as_map(r.get("featurePrint")).get("vector"))
        if vec is not None and vec.size:
            prints[pid] = vec
    corpus.feature_print = keep(prints, "featurePrint")
    patches = {}
    for pid, r in _newest("embed_dinov2_patches", rows("embed_dinov2_patches"), corpus, result).items():
        vec = _decode(r.get("mean"))
        if vec is not None and vec.size:
            patches[pid] = vec
    corpus.patches = keep(patches, "dinov2Patches")
    traits = _newest("traits", rows("traits"), corpus, result)
    colors = _newest("colors", rows("colors"), corpus, result)
    segmentation = _newest("segmentation", rows("segmentation"), corpus, result)
    for pid in set(traits) | set(colors) | set(segmentation) | set(labels):
        corpus.numbers[pid] = extra_numbers(traits.get(pid), colors.get(pid), segmentation.get(pid), labels.get(pid))

    scale = _newest(PIECE_SCALE, _optional_rows(rows, PIECE_SCALE, corpus), corpus, lambda row, p: piece_scale_numbers(row.get("result")))
    corpus.piece_scale = {pid: v for pid, v in scale.items() if v is not None}
    trust = _newest(CROP_TRUST, _optional_rows(rows, CROP_TRUST, corpus), corpus,
                    lambda row, p: _num(as_map(row.get("result")).get("trust")))
    corpus.crop_trust = {pid: min(1.0, max(0.0, v)) for pid, v in trust.items() if v is not None}

    for name, values in (("feature_print", corpus.feature_print), ("patches", corpus.patches), ("numbers", corpus.numbers),
                         ("piece_scale", corpus.piece_scale)):
        if values:
            M = np.vstack(list(values.values())).astype(np.float64)
            with warnings.catch_warnings():
                warnings.simplefilter("ignore", RuntimeWarning)  # a column no puzzle has a number in: its mean is 0
                corpus.extra_means[name] = np.nan_to_num(np.nanmean(M, axis=0)).astype(np.float32)


def piece_scale_coverage(corpus: Corpus, train_ids: list[str]) -> float:
    return sum(p in corpus.piece_scale for p in train_ids) / len(train_ids) if train_ids else 0.0


# folds --------------------------------------------------------------------------------------------------------------
def near_duplicate_groups(vectors: np.ndarray, extra: list[str] | None = None) -> tuple[np.ndarray, dict[str, Any]]:
    """Group label per row: connected components of cosine >= NEAR_DUPLICATE_COSINE (+ rows sharing an `extra` label,
    e.g. the brand). A component of more than MAX_CLUSTER_SHARE of the rows switches the picture grouping off."""
    from scipy.sparse import coo_matrix
    from scipy.sparse.csgraph import connected_components

    n = len(vectors)
    v = unit_rows(vectors)
    rows_, cols_ = [], []
    for start in range(0, n, SIMILARITY_BLOCK):
        sims = v[start:start + SIMILARITY_BLOCK] @ v.T
        r, c = np.nonzero(sims >= NEAR_DUPLICATE_COSINE)
        keep = c > r + start
        rows_.append(r[keep] + start)
        cols_.append(c[keep])
    pr = np.concatenate(rows_) if rows_ else np.zeros(0, int)
    pc = np.concatenate(cols_) if cols_ else np.zeros(0, int)
    count, labels = connected_components(coo_matrix((np.ones(len(pr)), (pr, pc)), shape=(n, n)), directed=False)
    largest = int(np.bincount(labels).max()) if n else 0
    info: dict[str, Any] = {"grouping": f"near-duplicate components (SigLIP 2 cosine >= {NEAR_DUPLICATE_COSINE})",
                            "nearDuplicatePairs": int(len(pr)), "components": int(count), "largestComponent": largest}
    if n and largest > MAX_CLUSTER_SHARE * n:
        info["grouping"] = f"off: one near-duplicate component holds {largest} of {n} rows - plain puzzles as groups"
        pr, pc = np.zeros(0, int), np.zeros(0, int)
    if extra is not None:
        first: dict[str, int] = {}
        er, ec = [], []
        for i, key in enumerate(extra):
            j = first.setdefault(key, i)
            if j != i:
                er.append(j)
                ec.append(i)
        pr, pc = np.concatenate([pr, np.array(er, int)]), np.concatenate([pc, np.array(ec, int)])
    if extra is not None or info["grouping"].startswith("off"):
        count, labels = connected_components(coo_matrix((np.ones(len(pr)), (pr, pc)), shape=(n, n)), directed=False)
        info["groups"] = int(count)
    return labels, info


def group_folds(groups: np.ndarray) -> tuple[list[tuple[np.ndarray, np.ndarray]], np.ndarray]:
    """Shuffled (fixed seed) GroupKFold: (splits, fold id per row)."""
    from sklearn.model_selection import GroupKFold

    n_groups = len(set(groups.tolist()))
    fold = np.zeros(len(groups), dtype=int)
    if n_groups < 2:
        return [], fold
    splits = list(GroupKFold(n_splits=min(FOLDS, n_groups), shuffle=True, random_state=SEED).split(np.zeros(len(groups)), groups=groups))
    for k, (_, te) in enumerate(splits):
        fold[te] = k
    return splits, fold


# learners -----------------------------------------------------------------------------------------------------------
def _new_model() -> Any:
    from sklearn.linear_model import RidgeCV
    from sklearn.pipeline import make_pipeline
    from sklearn.preprocessing import StandardScaler

    return make_pipeline(StandardScaler(), RidgeCV(alphas=ALPHAS))


def _sq_norms(A: np.ndarray) -> np.ndarray:
    return np.einsum("ij,ij->i", A, A, dtype=np.float64)


def rbf(A: np.ndarray, B: np.ndarray, bandwidth: float, a2: np.ndarray | None = None, b2: np.ndarray | None = None) -> np.ndarray:
    """exp(-|a - b|² / bandwidth) as float64 (n_a, n_b)."""
    a2 = _sq_norms(A) if a2 is None else a2
    b2 = _sq_norms(B) if b2 is None else b2
    K = (A @ B.T).astype(np.float64)
    K *= -2.0
    K += a2[:, None]
    K += b2[None, :]
    np.maximum(K, 0.0, out=K)
    K *= -1.0 / bandwidth
    np.exp(K, out=K)
    return K


class KernelRidge:
    """Weighted kernel ridge regression (RBF) on a Design's kernel parts: minimises Σ w (y - f)² + alpha ||f||² (dual:
    (K + alpha W⁻¹) c = y - ȳ_w, Cholesky). Above NYSTROM_ABOVE rows a Nyström approximation on NYSTROM_LANDMARKS seeded
    landmarks: (K_nmᵀ W K_nm + alpha K_mm) c = K_nmᵀ W (y - ȳ_w). The numbers and piece features are standardised on the
    training rows; every fit is deterministic."""

    def __init__(self, bandwidth: float = KERNEL_BANDWIDTH, alpha: float = KERNEL_ALPHA) -> None:
        self.bandwidth, self.alpha = bandwidth, alpha
        self.nystrom = False

    def _features(self, D: Design) -> np.ndarray:
        assert D.E is not None and D.N is not None and D.P is not None
        parts = [D.E]
        for M, mean, sd, w in ((D.N, self.n_mean, self.n_sd, KERNEL_NUMBERS_WEIGHT), (D.P, self.p_mean, self.p_sd, KERNEL_PIECES_WEIGHT)):
            if M.shape[1]:
                parts.append(((M - mean) / sd * (w / math.sqrt(M.shape[1]))).astype(np.float32))
        return np.hstack(parts).astype(np.float32)

    def fit(self, D: Design, y: np.ndarray, w: np.ndarray) -> "KernelRidge":
        assert D.N is not None and D.P is not None
        y, w = np.asarray(y, dtype=float), np.asarray(w, dtype=float)
        self.n_mean, self.p_mean = D.N.mean(0), D.P.mean(0)
        self.n_sd = np.where(D.N.std(0) > 0, D.N.std(0), 1.0)
        self.p_sd = np.where(D.P.std(0) > 0, D.P.std(0), 1.0)
        F = self._features(D)
        self.y_mean = float((w * y).sum() / w.sum())
        target = y - self.y_mean
        from scipy.linalg import cho_factor, cho_solve, solve

        if len(F) > NYSTROM_ABOVE:
            self.nystrom = True
            pick = np.sort(np.random.default_rng(SEED).choice(len(F), NYSTROM_LANDMARKS, replace=False))
            self.basis = F[pick]
            b2 = _sq_norms(self.basis)
            Kmm = rbf(self.basis, self.basis, self.bandwidth, b2, b2)
            A = self.alpha * Kmm
            rhs = np.zeros(len(pick))
            for start in range(0, len(F), PREDICT_CHUNK):
                Knm = rbf(F[start:start + PREDICT_CHUNK], self.basis, self.bandwidth, None, b2)
                ww = w[start:start + PREDICT_CHUNK]
                A += Knm.T @ (Knm * ww[:, None])
                rhs += Knm.T @ (ww * target[start:start + PREDICT_CHUNK])
            A[np.diag_indices_from(A)] += 1e-8 * float(np.trace(A)) / len(A)
            self.coef = solve(A, rhs, assume_a="pos", check_finite=False)
        else:
            self.basis = F
            K = rbf(F, F, self.bandwidth)
            K[np.diag_indices_from(K)] += self.alpha / w
            self.coef = cho_solve(cho_factor(K, overwrite_a=True, check_finite=False), target, check_finite=False)
            del K
        self.b2 = _sq_norms(self.basis)
        return self

    def predict(self, D: Design) -> np.ndarray:
        F = self._features(D)
        out = np.zeros(len(F))
        for start in range(0, len(F), PREDICT_CHUNK):
            out[start:start + PREDICT_CHUNK] = rbf(F[start:start + PREDICT_CHUNK], self.basis, self.bandwidth, None, self.b2) @ self.coef
        return out + self.y_mean


class Ensemble:
    """The model of a run: the ridge (StandardScaler + RidgeCV, exactly 1.1.0) and - when the Design has kernel parts -
    (1 - KERNEL_SHARE) × ridge + KERNEL_SHARE × KernelRidge."""

    def __init__(self, kernel: bool) -> None:
        self.ridge = _new_model()
        self.kernel = KernelRidge() if kernel else None

    def fit(self, D: Design, y: np.ndarray, w: np.ndarray) -> "Ensemble":
        self.ridge.fit(D.X, y, ridgecv__sample_weight=w)
        if self.kernel is not None:
            self.kernel.fit(D, y, w)
        self.dims = D.dims()
        return self

    def parts(self, D: Design) -> tuple[np.ndarray, np.ndarray | None]:
        r = self.ridge.predict(D.X)
        return r, (self.kernel.predict(D) if self.kernel is not None else None)

    def predict(self, D: Design) -> np.ndarray:
        r, k = self.parts(D)
        return r if k is None else (1.0 - KERNEL_SHARE) * r + KERNEL_SHARE * k

    @property
    def alpha(self) -> float:
        return float(self.ridge[-1].alpha_)


def fit(X: Any, y: np.ndarray, w: np.ndarray) -> Ensemble:
    D = X if isinstance(X, Design) else Design(np.asarray(X, dtype=float))
    return Ensemble(kernel=D.kernel).fit(D, y, w)


def cross_fit(X: Any, y: np.ndarray, w: np.ndarray, splits: Iterable[tuple[np.ndarray, np.ndarray]],
              parts: bool = False) -> Any:
    """Out-of-fold predictions: each row predicted by a model fitted without it. Returns (predictions, the training size
    of the model that predicted each row) - with `parts` also the ridge's and the kernel's own predictions."""
    D = X if isinstance(X, Design) else Design(np.asarray(X, dtype=float))
    pred = np.full(len(y), np.nan)
    ridge = np.full(len(y), np.nan)
    kern = np.full(len(y), np.nan)
    sizes = np.zeros(len(y), dtype=int)
    for tr, te in splits:
        if len(te) == 0:
            continue
        m = fit(D[tr], y[tr], w[tr])
        r, k = m.parts(D[te])
        ridge[te] = r
        kern[te] = r if k is None else k
        pred[te] = r if k is None else (1.0 - KERNEL_SHARE) * r + KERNEL_SHARE * k
        sizes[te] = len(tr)
    if parts:
        return pred, sizes, ridge, kern
    return pred, sizes


def kfold(n: int) -> list[tuple[np.ndarray, np.ndarray]]:
    from sklearn.model_selection import KFold

    return list(KFold(n_splits=min(FOLDS, n), shuffle=True, random_state=SEED).split(np.zeros(n)))


def predict_chunks(model: Ensemble, build_x: Callable[[list[str]], Design], pids: list[str]) -> np.ndarray:
    out = np.zeros(len(pids))
    for start in range(0, len(pids), PREDICT_CHUNK):
        chunk = pids[start:start + PREDICT_CHUNK]
        out[start:start + len(chunk)] = model.predict(build_x(chunk))
    return out


def chosen_alpha(model: Ensemble) -> float:
    return model.alpha


class BrandSizeOffsets:
    """A shrunk offset per brand × piece count from out-of-fold residuals: Σ w r / (Σ w + BRAND_SIZE_PSEUDO_COUNT) -
    a brand × size the residuals never saw (a new brand, a size new to it, no brand) gets 0."""

    def __init__(self, keys: list[str | None], residuals: np.ndarray, w: np.ndarray, k: float) -> None:
        sums: dict[str, float] = {}
        weights_: dict[str, float] = {}
        for key, r, ww in zip(keys, np.asarray(residuals, dtype=float), np.asarray(w, dtype=float)):
            if key is not None and np.isfinite(r):
                sums[key] = sums.get(key, 0.0) + ww * r
                weights_[key] = weights_.get(key, 0.0) + ww
        self.k = k
        self.table = {key: sums[key] / (weights_[key] + k) for key in sums}
        self.weights = weights_

    def __call__(self, keys: list[str | None]) -> np.ndarray:
        return np.array([self.table.get(key, 0.0) if key is not None else 0.0 for key in keys])

    def describe(self) -> dict[str, Any]:
        values = np.array(list(self.table.values())) if self.table else np.zeros(1)
        return {"pseudoCount": self.k, "groups": len(self.table), "groupsWith10Weight": int(sum(w >= 10 for w in self.weights.values())),
                "p10": round(float(np.percentile(values, 10)), 4), "p90": round(float(np.percentile(values, 90)), 4)}


def nested_offsets(D: Design, y: np.ndarray, w: np.ndarray, fold: np.ndarray, keys: list[str | None], k: float) -> np.ndarray:
    """The brand × size offset of every training row from residuals no model of its fold's labels shaped: for fold a,
    the residual of a row of fold b comes from a model trained without folds a and b ("pair model", one per pair of
    folds - the same model serves both folds). So an out-of-fold estimate never depends on its own label, not even
    through the residuals of other puzzles."""
    folds = sorted(set(fold.tolist()))
    out = np.zeros(len(y))
    if len(folds) < 3:
        return out
    pair: dict[tuple[int, int], np.ndarray] = {}
    for i, a in enumerate(folds):
        for b in folds[i + 1:]:
            tr = (fold != a) & (fold != b)
            te = np.where((fold == a) | (fold == b))[0]
            pred = np.full(len(y), np.nan)
            pred[te] = fit(D[np.where(tr)[0]], y[tr], w[tr]).predict(D[te])
            pair[(a, b)] = pred
    for a in folds:
        residuals = np.full(len(y), np.nan)
        for b in folds:
            if b != a:
                rows = fold == b
                residuals[rows] = y[rows] - pair[(min(a, b), max(a, b))][rows]
        inside = np.where(fold == a)[0]
        others = np.where(fold != a)[0]
        table = BrandSizeOffsets([keys[j] for j in others], residuals[others], w[others], k)
        out[inside] = table([keys[j] for j in inside])
    return out


# intervals ----------------------------------------------------------------------------------------------------------
QUANTILES = (0.10, 0.25, 0.75, 0.90)


def _quantiles(r: np.ndarray) -> tuple[float, float, float, float]:
    q10, q25, q75, q90 = (float(x) for x in np.quantile(r, QUANTILES))
    return min(q10, 0.0), min(q25, 0.0), max(q75, 0.0), max(q90, 0.0)


class IntervalTable:
    """Residual quantiles (label - out-of-fold estimate) per (size group × image kind), then per size group, then all
    (the 1.1.0 intervals; since 1.2.0 the fallback below MIN_SIGMA_ROWS and the comparison in the record)."""

    method = "size x kind table"

    def __init__(self, residuals: np.ndarray, index_counts: np.ndarray, sizes: list[str], kinds: list[str]) -> None:
        residuals = np.asarray(residuals, dtype=float)
        mask = np.asarray(index_counts) >= INTERVAL_MIN_INDICES
        self.source = f"out-of-fold residuals of puzzles with >= {INTERVAL_MIN_INDICES} indices"
        if mask.sum() < MIN_GROUP_RESIDUALS:
            mask = np.ones(len(residuals), dtype=bool)
            self.source = "out-of-fold residuals of every training puzzle (too few well measured ones)"
        r = residuals[mask]
        s = [x for x, m in zip(sizes, mask) if m]
        k = [x for x, m in zip(kinds, mask) if m]
        self.count = int(len(r))
        self.pooled = _quantiles(r) if len(r) else (0.0, 0.0, 0.0, 0.0)
        self.by_size: dict[str, tuple[tuple[float, ...], int]] = {}
        self.by_group: dict[tuple[str, str], tuple[tuple[float, ...], int]] = {}
        for g in sorted(set(s)):
            sel = np.array([x == g for x in s])
            if sel.sum() >= MIN_GROUP_RESIDUALS:
                self.by_size[g] = (_quantiles(r[sel]), int(sel.sum()))
        for key in sorted(set(zip(s, k))):
            sel = np.array([(a, b) == key for a, b in zip(s, k)])
            if sel.sum() >= MIN_GROUP_RESIDUALS:
                self.by_group[key] = (_quantiles(r[sel]), int(sel.sum()))

    def quantiles(self, size: str, kind: str) -> tuple[float, ...]:
        if (size, kind) in self.by_group:
            return self.by_group[(size, kind)][0]
        if size in self.by_size:
            return self.by_size[size][0]
        return self.pooled

    def bounds(self, pred: np.ndarray, sizes: list[str], kinds: list[str], raw: np.ndarray | None = None) -> np.ndarray:
        """(n, 4): log_p10, log_p25, log_p75, log_p90 around each estimate."""
        q = np.array([self.quantiles(s, k) for s, k in zip(sizes, kinds)]).reshape(len(sizes), 4)
        return np.asarray(pred, dtype=float)[:, None] + q

    def describe(self) -> dict[str, Any]:
        def entry(q: tuple[float, ...], n: int, size: str, kind: str) -> dict[str, Any]:
            return {"sizeGroup": size, "imageKind": kind, "count": n, "q10": round(q[0], 4), "q90": round(q[3], 4)}

        return {"source": self.source, "minGroupSize": MIN_GROUP_RESIDUALS,
                "pooled": entry(self.pooled, self.count, "all", "all"),
                "groups": [entry(q, n, s, k) for (s, k), (q, n) in self.by_group.items()]
                + [entry(q, n, s, "all") for s, (q, n) in self.by_size.items()]}


def fold_bounds(pred: np.ndarray, residuals: np.ndarray, n: np.ndarray, sizes: list[str], kinds: list[str], fold: np.ndarray) -> np.ndarray:
    """Table bounds of out-of-fold rows from the OTHER folds' residuals only - a row's interval never contains its own
    residual (else coverage is circular and a stored interval would carry its own label)."""
    out = np.zeros((len(pred), 4))
    for k in sorted(set(fold.tolist())):
        inside = fold == k
        other = ~inside
        if other.sum() == 0:
            other = inside
        table = IntervalTable(residuals[other], n[other], [s for s, o in zip(sizes, other) if o], [x for x, o in zip(kinds, other) if o])
        out[inside] = table.bounds(pred[inside], [s for s, i in zip(sizes, inside) if i], [x for x, i in zip(kinds, inside) if i])
    return out


class Neighbours:
    """The KNN_CANDIDATES most similar training pictures (SigLIP 2 cosine) of every training row, itself left out -
    filtered per use to the rows a model may know (the other folds, the older puzzles)."""

    def __init__(self, vectors: np.ndarray) -> None:
        v = unit_rows(vectors)
        n = len(v)
        m = min(KNN_CANDIDATES, max(0, n - 1))
        self.idx = np.zeros((n, m), dtype=np.int64)
        self.sim = np.zeros((n, m), dtype=np.float32)
        for start in range(0, n, SIMILARITY_BLOCK):
            s = v[start:start + SIMILARITY_BLOCK] @ v.T
            rows = np.arange(len(s))
            s[rows, rows + start] = -np.inf
            if m:
                top = np.argpartition(-s, m - 1, axis=1)[:, :m]
                order = np.argsort(-np.take_along_axis(s, top, 1), axis=1, kind="stable")
                top = np.take_along_axis(top, order, 1)
                self.idx[start:start + len(s)] = top
                self.sim[start:start + len(s)] = np.take_along_axis(s, top, 1)

    def stats(self, rows: np.ndarray, y: np.ndarray, allowed: np.ndarray, col_fold: np.ndarray | None = None,
              row_fold: np.ndarray | None = None) -> np.ndarray:
        """(len(rows), 4) of knn_stats over each row's first KNN_K allowed candidates (and, with folds, not of its own)."""
        cand = self.idx[rows]
        ok = allowed[cand]
        if col_fold is not None and row_fold is not None:
            ok &= col_fold[cand] != np.asarray(row_fold)[:, None]
        return knn_stats(self.sim[rows], y[cand], ok)


def knn_stats(sim: np.ndarray, labels: np.ndarray, ok: np.ndarray) -> np.ndarray:
    """Per row from its candidates sorted by similarity (ok = usable): top-1 similarity, mean similarity, label mean and
    label SD of the first KNN_K usable ones. A row without any: 0, 0, NaN, 0."""
    take = ok & (np.cumsum(ok, axis=1) <= KNN_K)
    count = take.sum(1)
    safe = np.maximum(count, 1)
    first = np.argmax(ok, axis=1)
    top1 = np.where(count > 0, sim[np.arange(len(sim)), first], 0.0)
    mean = (sim * take).sum(1) / safe
    ymean = (labels * take).sum(1) / safe
    ysd = np.sqrt((((labels - ymean[:, None]) ** 2) * take).sum(1) / safe)
    return np.c_[top1, mean, np.where(count > 0, ymean, np.nan), ysd]


def knn_direct(targets: np.ndarray, reference: np.ndarray, labels: np.ndarray) -> np.ndarray:
    """knn_stats of target pictures (unit rows) against a reference set (unit rows) - nothing left out."""
    out = np.zeros((len(targets), 4))
    k = min(KNN_K, len(reference))
    if k == 0:
        out[:, 2] = np.nan
        return out
    ref = np.asarray(reference, dtype=np.float32)
    for start in range(0, len(targets), SIMILARITY_BLOCK):
        s = np.asarray(targets[start:start + SIMILARITY_BLOCK], dtype=np.float32) @ ref.T
        top = np.argpartition(-s, k - 1, axis=1)[:, :k]
        order = np.argsort(-np.take_along_axis(s, top, 1), axis=1, kind="stable")
        top = np.take_along_axis(top, order, 1)
        out[start:start + len(s)] = knn_stats(np.take_along_axis(s, top, 1), labels[top], np.ones(top.shape, dtype=bool))
    return out


def sigma_raw(pred: np.ndarray, knn: np.ndarray, crops: np.ndarray, sizes: list[str], kinds: list[str]) -> np.ndarray:
    """SIGMA_FEATURES before imputation / scaling (NaN = unknown)."""
    pred = np.asarray(pred, dtype=float)
    diff = np.abs(pred - knn[:, 2])
    onehots = [[s == g for g in SIZE_GROUPS] + [k == x for x in IMAGE_KINDS] for s, k in zip(sizes, kinds)]
    return np.c_[pred, knn[:, 0], knn[:, 1], diff, knn[:, 3], crops, np.array(onehots, dtype=float).reshape(len(pred), -1)]


class IntervalModel:
    """Heteroscedastic intervals: σ̂ = Huber regression of |residual| on SIGMA_FEATURES (well measured rows), and the
    QUANTILES of the normalised residual r / σ̂ (σ̂ cross-fitted by the reference rows' own folds - never its own) per
    size group with >= MIN_GROUP_RESIDUALS rows, else of all of them. bounds = estimate + q × σ̂. Keeps the reference
    pictures (unit SigLIP 2 rows, float16) and labels for the kNN features of new puzzles."""

    method = "heteroscedastic: huber sigma-hat x normalised conformal per size group"

    def __init__(self, raw: np.ndarray, residuals: np.ndarray, n: np.ndarray, sizes: list[str], fold: np.ndarray,
                 ref_vectors: np.ndarray, ref_labels: np.ndarray) -> None:
        residuals = np.asarray(residuals, dtype=float)
        well = np.asarray(n) >= INTERVAL_MIN_INDICES
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", RuntimeWarning)
            fill = np.nanmedian(raw, axis=0)
        self.fill = np.nan_to_num(fill)
        F = self._filled(raw)
        self.mu = F[well].mean(0)
        sd = F[well].std(0)
        self.sd = np.where(sd > 1e-12, sd, 1.0)
        Z = (F - self.mu) / self.sd
        target = np.abs(residuals)
        sigma_cf = np.zeros(len(F))
        for k in sorted(set(fold.tolist())):
            tr = well & (fold != k)
            te = fold == k
            if tr.sum() < 20 or te.sum() == 0:
                tr = well
            sigma_cf[te] = self._huber(Z[tr], target[tr]).predict(Z[te])
        self.shrink = SIGMA_SHRINK
        self.sigma_mean = float(np.mean(sigma_cf[well]))
        sigma_cf = self._shrunk(sigma_cf)
        self.model = self._huber(Z[well], target[well])
        z = residuals[well] / sigma_cf[well]
        s = np.array(sizes)[well]
        self.count = int(well.sum())
        self.pooled = _quantiles(z)
        self.by_size = {g: (_quantiles(z[s == g]), int((s == g).sum())) for g in sorted(set(s.tolist())) if (s == g).sum() >= MIN_GROUP_RESIDUALS}
        self.ref_vectors = np.asarray(ref_vectors, dtype=np.float16)
        self.ref_labels = np.asarray(ref_labels, dtype=float)
        self.sigma_spearman = spearman(sigma_cf[well], target[well])

    @staticmethod
    def _huber(X: np.ndarray, y: np.ndarray) -> Any:
        from sklearn.linear_model import HuberRegressor

        with warnings.catch_warnings():
            warnings.simplefilter("ignore")  # lbfgs may stop at max_iter on a flat problem - the fit is still usable
            return HuberRegressor(max_iter=500).fit(X, y)

    def _filled(self, raw: np.ndarray) -> np.ndarray:
        F = np.array(raw, dtype=float, copy=True)
        bad = ~np.isfinite(F)
        if bad.any():
            F[bad] = np.broadcast_to(self.fill, F.shape)[bad]
        return F

    def _shrunk(self, sigma: np.ndarray) -> np.ndarray:
        return np.maximum(self.sigma_mean + self.shrink * (np.asarray(sigma) - self.sigma_mean), SIGMA_FLOOR)

    def sigma(self, raw: np.ndarray) -> np.ndarray:
        return self._shrunk(self.model.predict((self._filled(raw) - self.mu) / self.sd))

    def bounds(self, pred: np.ndarray, sizes: list[str], kinds: list[str], raw: np.ndarray | None = None) -> np.ndarray:
        assert raw is not None, "the heteroscedastic intervals need the σ̂ features"
        s = self.sigma(raw)
        q = np.array([self.by_size.get(g, (self.pooled, 0))[0] for g in sizes]).reshape(len(sizes), 4)
        return np.asarray(pred, dtype=float)[:, None] + q * s[:, None]

    def describe(self) -> dict[str, Any]:
        def entry(q: tuple[float, ...], n: int, size: str) -> dict[str, Any]:
            return {"sizeGroup": size, "count": n, **{f"z{int(p * 100)}": round(x, 4) for p, x in zip(QUANTILES, q)}}

        return {"method": self.method, "source": f"out-of-fold residuals of puzzles with >= {INTERVAL_MIN_INDICES} indices",
                "count": self.count, "sigmaFloor": SIGMA_FLOOR, "sigmaShrink": self.shrink, "sigmaMean": round(self.sigma_mean, 4), "knn": KNN_K,
                "sigmaSpearman": None if self.sigma_spearman is None else round(self.sigma_spearman, 4),
                "sigmaCoefficients": {name: round(float(c / s), 5) for name, c, s in zip(SIGMA_FEATURES, self.model.coef_, self.sd)},
                "pooled": entry(self.pooled, self.count, "all"),
                "groups": [entry(q, n, g) for g, (q, n) in self.by_size.items()]}


@dataclass
class Context:
    """What the interval models need about the training rows (in train_ids order)."""

    y: np.ndarray
    n: np.ndarray
    sizes: list[str]
    kinds: list[str]
    crops: np.ndarray
    vectors: np.ndarray  # unit rows, float16
    neighbours: Neighbours


def fit_intervals(ctx: Context, ref: np.ndarray, pred: np.ndarray, residuals: np.ndarray, fold: np.ndarray) -> Any:
    """The interval model of the reference rows `ref` (indices into the training rows) from their out-of-fold estimates
    and residuals and their own fold ids - the heteroscedastic model, or the table below MIN_SIGMA_ROWS well measured."""
    ref = np.asarray(ref, dtype=int)
    if int((ctx.n[ref] >= INTERVAL_MIN_INDICES).sum()) < MIN_SIGMA_ROWS:
        return IntervalTable(residuals, ctx.n[ref], [ctx.sizes[i] for i in ref], [ctx.kinds[i] for i in ref])
    allowed = np.zeros(len(ctx.y), dtype=bool)
    allowed[ref] = True
    col_fold = np.full(len(ctx.y), -1)
    col_fold[ref] = fold
    knn = ctx.neighbours.stats(ref, ctx.y, allowed, col_fold, fold)
    raw = sigma_raw(pred, knn, ctx.crops[ref], [ctx.sizes[i] for i in ref], [ctx.kinds[i] for i in ref])
    return IntervalModel(raw, residuals, ctx.n[ref], [ctx.sizes[i] for i in ref], fold, ctx.vectors[ref], ctx.y[ref])


def interval_bounds_inside(model: Any, ctx: Context, ref: np.ndarray, rows: np.ndarray, pred: np.ndarray) -> np.ndarray:
    """Bounds of training rows `rows` the model's reference `ref` does not hold (kNN among `ref` only)."""
    sizes, kinds = [ctx.sizes[i] for i in rows], [ctx.kinds[i] for i in rows]
    if not isinstance(model, IntervalModel):
        return model.bounds(pred, sizes, kinds)
    allowed = np.zeros(len(ctx.y), dtype=bool)
    allowed[ref] = True
    knn = ctx.neighbours.stats(np.asarray(rows, dtype=int), ctx.y, allowed)
    return model.bounds(pred, sizes, kinds, sigma_raw(pred, knn, ctx.crops[rows], sizes, kinds))


def interval_bounds_outside(model: Any, pred: np.ndarray, vectors: np.ndarray, crops: np.ndarray, sizes: list[str], kinds: list[str]) -> np.ndarray:
    """Bounds of pictures outside the training rows (kNN against the model's stored reference pictures)."""
    if not isinstance(model, IntervalModel):
        return model.bounds(pred, sizes, kinds)
    knn = knn_direct(unit_rows(vectors), model.ref_vectors, model.ref_labels)
    return model.bounds(pred, sizes, kinds, sigma_raw(pred, knn, crops, sizes, kinds))


def oof_bounds(ctx: Context, pred: np.ndarray, fold: np.ndarray) -> np.ndarray:
    """Bounds of out-of-fold rows from an interval model of the OTHER folds only (never the row's own residual)."""
    residuals = ctx.y - pred
    out = np.zeros((len(pred), 4))
    everything = np.arange(len(pred))
    for k in sorted(set(fold.tolist())):
        inside = everything[fold == k]
        ref = everything[fold != k]
        if len(ref) == 0:
            ref = inside
        model = fit_intervals(ctx, ref, pred[ref], residuals[ref], fold[ref])
        out[inside] = interval_bounds_inside(model, ctx, ref, inside, pred[inside])
    return out


# evaluation ---------------------------------------------------------------------------------------------------------
def metrics(y: np.ndarray, pred: np.ndarray, bounds: np.ndarray) -> dict[str, Any]:
    y, pred = np.asarray(y, dtype=float), np.asarray(pred, dtype=float)
    n = int(len(y))
    if n == 0:
        return {"count": 0}
    t, p = tier(y), tier(pred)
    rho = spearman(pred, y) if n >= 5 else None
    var = float(np.var(y))
    return {
        "count": n,
        "spearman": None if rho is None else round(rho, 4),
        "mae": round(float(np.mean(np.abs(pred - y))), 4),
        "r2": round(1.0 - float(np.mean((pred - y) ** 2)) / var, 4) if var > 0 else None,
        "tierExact": round(float(np.mean(p == t)), 4),
        "tierWithinOne": round(float(np.mean(np.abs(p - t) <= 1)), 4),
        "averageTierExact": round(float(np.mean(t == AVERAGE_TIER)), 4),
        "averageTierWithinOne": round(float(np.mean(np.abs(t - AVERAGE_TIER) <= 1)), 4),
        "window50Hit": round(float(np.mean((y >= bounds[:, 1]) & (y <= bounds[:, 2]))), 4),
        "window80Hit": round(float(np.mean((y >= bounds[:, 0]) & (y <= bounds[:, 3]))), 4),
        "window50Width": round(float(np.median(bounds[:, 2] - bounds[:, 1])), 4),
        "window80Width": round(float(np.median(bounds[:, 3] - bounds[:, 0])), 4),
    }


def grouped(y: np.ndarray, pred: np.ndarray, bounds: np.ndarray, sizes: list[str], kinds: list[str]) -> list[dict[str, Any]]:
    keys = sorted(set(zip(sizes, kinds))) + [(s, "all") for s in sorted(set(sizes))] + [("all", k) for k in sorted(set(kinds))]
    out = []
    for s, k in keys:
        sel = np.array([(s in ("all", a)) and (k in ("all", b)) for a, b in zip(sizes, kinds)])
        out.append({"sizeGroup": s, "imageKind": k} | metrics(y[sel], pred[sel], bounds[sel]))
    return out


def pick_threshold(index_counts: np.ndarray, minimum: int) -> int:
    for t in TEST_MIN_INDICES:
        if int((index_counts >= t).sum()) >= minimum:
            return t
    return TEST_MIN_INDICES[-1]


@dataclass
class Fitted:
    """One model's results over its training rows (labelled, >= 3 indices, in id order)."""

    model_name: str
    train_ids: list[str]
    y: np.ndarray
    n: np.ndarray
    oof: np.ndarray
    oof_sizes: np.ndarray
    oof_bounds: np.ndarray
    fold: np.ndarray
    groups: np.ndarray
    folds_info: dict[str, Any]
    full: Ensemble
    table: IntervalTable
    intervals: Any  # IntervalModel, or the table below MIN_SIGMA_ROWS
    sizes: list[str]
    kinds: list[str]
    context: Context
    oof_ridge: np.ndarray | None = None
    oof_kernel: np.ndarray | None = None
    keys: list[str | None] = field(default_factory=list)  # brand × size of every training row
    oof_base: np.ndarray | None = None  # the ensemble's out-of-fold estimate before the brand × size offset
    offsets: BrandSizeOffsets | None = None  # for every puzzle the full model estimates; None = off


@dataclass
class Holdout:
    """The newer-from-older part of a run: the model fitted on the older puzzles, its interval model and brand × size
    offsets, the newer test rows (indices into the training rows) - the paired push guard's material."""

    model: Ensemble
    intervals: Any
    train_ids: list[str]
    test: np.ndarray
    pred: np.ndarray
    bounds: np.ndarray
    offsets: BrandSizeOffsets | None = None


def with_offset(pred: np.ndarray, bounds: np.ndarray, offset: np.ndarray | None) -> tuple[np.ndarray, np.ndarray]:
    """The estimate and its bounds moved by the brand × size offset (the interval model describes the ensemble's
    residuals; the offset shifts the whole window)."""
    if offset is None:
        return pred, bounds
    return pred + offset, bounds + offset[:, None]


def train(name: str, corpus: Corpus, ids: list[str], build_x: Callable[[list[str]], Design],
          vectors: Callable[[list[str]], np.ndarray]) -> tuple[Fitted, Design, np.ndarray]:
    D = build_x(ids)
    V = vectors(ids)
    y = np.array([corpus.puzzles[p].label.log_difficulty for p in ids])  # type: ignore[union-attr]
    n = np.array([corpus.puzzles[p].label.index_count for p in ids])  # type: ignore[union-attr]
    w = weights(n)
    groups, info = near_duplicate_groups(V)
    splits, fold = group_folds(groups)
    oof, oof_sizes, oof_ridge, oof_kernel = cross_fit(D, y, w, splits, parts=True)
    full = fit(D, y, w)
    sizes = [corpus.puzzles[p].size_group for p in ids]
    kinds = [corpus.puzzles[p].kind for p in ids]
    ctx = Context(y, n, sizes, kinds, corpus.crops(ids), unit_rows(V).astype(np.float16), Neighbours(V))
    table = IntervalTable(y - oof, n, sizes, kinds)
    intervals = fit_intervals(ctx, np.arange(len(ids)), oof, y - oof, fold)
    ob = oof_bounds(ctx, oof, fold)
    keys = [corpus.puzzles[p].brand_size for p in ids]
    offsets = None
    base = oof
    if BRAND_SIZE_PSEUDO_COUNT is not None and D.kernel:  # the model of record only (measured there)
        offsets = BrandSizeOffsets(keys, y - oof, w, BRAND_SIZE_PSEUDO_COUNT)
        oof, ob = with_offset(oof, ob, nested_offsets(D, y, w, fold, keys, BRAND_SIZE_PSEUDO_COUNT))
    fitted = Fitted(name, ids, y, n, oof, oof_sizes, ob, fold, groups, info, full, table, intervals, sizes, kinds, ctx,
                    oof_ridge if D.kernel else None, oof_kernel if D.kernel else None, keys, base, offsets)
    return fitted, D, V


def validate(fitted: Fitted, X: Design, V: np.ndarray, corpus: Corpus) -> tuple[dict[str, Any], Holdout | None]:
    """The run's evaluation of one model (stored as its model record's `evaluation`) and its newer-from-older holdout."""
    y, n, sizes, kinds = fitted.y, fitted.n, fitted.sizes, fitted.kinds
    w = weights(n)
    ctx = fitted.context
    out: dict[str, Any] = {}

    t = pick_threshold(n, MIN_TEST)
    ev = n >= t
    sel_s = [s for s, e in zip(sizes, ev) if e]
    sel_k = [k for k, e in zip(kinds, ev) if e]
    out["testMinIndices"] = t
    out["overall"] = metrics(y[ev], fitted.oof[ev], fitted.oof_bounds[ev])
    out["groups"] = grouped(y[ev], fitted.oof[ev], fitted.oof_bounds[ev], sel_s, sel_k)

    # brands held out (with near-duplicate artwork of other brands in the same fold)
    brands = [corpus.puzzles[p].label.manufacturer_id or f"none:{p}" for p in fitted.train_ids]  # type: ignore[union-attr]
    bgroups, binfo = near_duplicate_groups(V, extra=brands)
    splits, bfold = group_folds(bgroups)
    brand_out: dict[str, Any] = {"brands": len(set(brands)), "foldGroups": binfo.get("groups")}
    if splits:
        bp, _ = cross_fit(X, y, w, splits)
        bb = oof_bounds(ctx, bp, bfold)
        brand_out |= {"testMinIndices": t, "overall": metrics(y[ev], bp[ev], bb[ev]), "groups": grouped(y[ev], bp[ev], bb[ev], sel_s, sel_k)}
    holdouts: dict[str, Any] = {"brands": brand_out}

    # newer predicted from older (`addedAt`): its Spearman is the push guard's number
    added = [corpus.puzzles[p].label.added_at for p in fitted.train_ids]  # type: ignore[union-attr]
    dated = sorted((a, i) for i, a in enumerate(added) if a)
    newer: dict[str, Any] = {"dated": len(dated)}
    holdout, holdout_count, holdout_t = None, 0, None
    held: Holdout | None = None
    if len(dated) >= MIN_TRAINING // 2:
        cut = int(len(dated) * TIME_SPLIT)
        old_idx = np.array([i for _, i in dated[:cut]])
        new_idx = np.array([i for _, i in dated[cut:]])
        old_groups = set(fitted.groups[old_idx].tolist())
        twin = np.array([fitted.groups[i] in old_groups for i in new_idx], dtype=bool)
        new_idx = new_idx[~twin]  # a newer edition of an older picture would only test memory
        out["newest30"] = newest_30(fitted, new_idx)
        tn = pick_threshold(n[new_idx], MIN_TEST)
        test = new_idx[n[new_idx] >= tn]
        newer |= {"testDroppedNearDuplicates": int(twin.sum()), "testMinIndices": tn}
        if len(test) >= MIN_HOLDOUT_TEST:
            old_splits, old_fold = group_folds(fitted.groups[old_idx])
            old_oof, _ = cross_fit(X[old_idx], y[old_idx], w[old_idx], old_splits)  # residuals the newer never shaped
            old_s, old_k = [sizes[i] for i in old_idx], [kinds[i] for i in old_idx]
            older_table = IntervalTable(y[old_idx] - old_oof, n[old_idx], old_s, old_k)
            older_intervals = fit_intervals(ctx, old_idx, old_oof, y[old_idx] - old_oof, old_fold)
            older = fit(X[old_idx], y[old_idx], w[old_idx])
            ridge_pred, kernel_pred = older.parts(X[test])
            base = older.predict(X[test])
            ts, tk = [sizes[i] for i in test], [kinds[i] for i in test]
            base_b = interval_bounds_inside(older_intervals, ctx, old_idx, test, base)
            older_offsets = None
            if fitted.offsets is not None:  # the older puzzles' own residuals - nothing of the newer
                older_offsets = BrandSizeOffsets([fitted.keys[i] for i in old_idx], y[old_idx] - old_oof, w[old_idx], fitted.offsets.k)
            offset = older_offsets([fitted.keys[i] for i in test]) if older_offsets is not None else None
            pred, tb = with_offset(base, base_b, offset)
            table_b = older_table.bounds(pred, ts, tk)
            newer |= {"trainSize": int(len(old_idx)), "trainAddedUntil": dated[cut - 1][0],
                      "overall": metrics(y[test], pred, tb), "groups": grouped(y[test], pred, tb, ts, tk),
                      "intervalTable": {"overall": _windows(metrics(y[test], pred, table_b)),
                                        "groups": [{"sizeGroup": g["sizeGroup"], "imageKind": g["imageKind"]} | _windows(g)
                                                   for g in grouped(y[test], pred, table_b, ts, tk)]}}
            if kernel_pred is not None:
                newer["components"] = {"ridge": _ranking(metrics(y[test], ridge_pred, tb)),
                                       "kernelRidge": _ranking(metrics(y[test], kernel_pred, tb)),
                                       "ensembleWithoutBrandSizeOffset": _ranking(metrics(y[test], base, base_b))}
            holdout, holdout_count, holdout_t = newer["overall"].get("spearman"), int(len(test)), tn
            held = Holdout(older, older_intervals, [fitted.train_ids[i] for i in old_idx], test, pred, tb, older_offsets)
    holdouts["newerFromOlder"] = newer
    out["holdouts"] = holdouts
    if holdout is None:  # too few dated puzzles: the cross-fitted Spearman stands in (said so in the record)
        holdout = out["overall"].get("spearman") or 0.0
        out |= {"holdoutSource": "cross_fit", "holdoutCount": int(ev.sum()), "holdoutTestMinIndices": t}
    else:
        out |= {"holdoutSource": "newer_from_older", "holdoutCount": holdout_count, "holdoutTestMinIndices": holdout_t}
    out["holdoutSpearman"] = round(float(holdout), 4)
    out["folds"] = fitted.folds_info | {"count": int(len(set(fitted.fold.tolist())))}
    return out, held


def _windows(m: dict[str, Any]) -> dict[str, Any]:
    return {k: m.get(k) for k in ("count", "window50Hit", "window80Hit", "window50Width", "window80Width")}


def _ranking(m: dict[str, Any]) -> dict[str, Any]:
    return {k: m.get(k) for k in ("count", "spearman", "mae", "r2", "tierExact", "tierWithinOne")}


def newest_30(fitted: Fitted, newest: np.ndarray) -> dict[str, Any]:
    """Out-of-fold on the newest 30 % (by `addedAt`, near-duplicates of older puzzles left out) with >= 10 indices, per
    size group × image kind - the population the app's gate measures (image-model review F1 / P6)."""
    rows = newest[fitted.n[newest] >= NEWEST_MIN_INDICES]
    if len(rows) == 0:
        return {"testMinIndices": NEWEST_MIN_INDICES, "overall": {"count": 0}}
    s, k = [fitted.sizes[i] for i in rows], [fitted.kinds[i] for i in rows]
    y, p, b = fitted.y[rows], fitted.oof[rows], fitted.oof_bounds[rows]
    return {"testMinIndices": NEWEST_MIN_INDICES, "overall": metrics(y, p, b), "groups": grouped(y, p, b, s, k)}


def guard(evaluation: dict[str, Any], training_size: int, previous: dict[str, Any] | None) -> tuple[bool, str | None]:
    """(compare?, why not) against the previous record. Compares only like with like - see the module docstring."""
    if not previous or previous.get("holdoutSpearman") is None:
        return False, "no previous record of this model"
    prev = as_map(previous.get("evaluation"))
    for key in ("holdoutSource", "holdoutTestMinIndices"):
        if prev.get(key) != evaluation.get(key):
            return False, f"{key} changed ({prev.get(key)} → {evaluation.get(key)})"
    if int(evaluation.get("holdoutCount") or 0) < GUARD_MIN_TEST:
        return False, f"only {evaluation.get('holdoutCount')} holdout test puzzles tonight (< {GUARD_MIN_TEST})"
    if int(prev.get("holdoutCount") or 0) < GUARD_MIN_TEST:
        return False, f"the previous record had only {prev.get('holdoutCount')} holdout test puzzles (< {GUARD_MIN_TEST})"
    prev_size = int(previous.get("trainingSize") or 0)
    if prev_size <= 0 or abs(training_size - prev_size) / prev_size >= GUARD_MAX_TRAINING_CHANGE:
        return False, f"training size changed from {prev_size} to {training_size} (>= {GUARD_MAX_TRAINING_CHANGE:.0%})"
    return True, None


# the paired push guard ----------------------------------------------------------------------------------------------
def holdout_path(report_dir: Path | None, model: str) -> Path | None:
    return None if report_dir is None else Path(report_dir) / HOLDOUT_FILE.format(model=model)


def save_holdout(report_dir: Path | None, model: str, model_version: str, held: Holdout, at: str) -> None:
    """Keeps tonight's newer-from-older model for tomorrow's paired guard - written atomically, only for a recorded run."""
    path = holdout_path(report_dir, model)
    if path is None:
        return
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(".tmp")
        with open(tmp, "wb") as f:
            pickle.dump({"format": HOLDOUT_FORMAT, "model": model, "modelVersion": model_version, "computedAt": at,
                         "trainIds": held.train_ids, "ensemble": held.model, "intervals": held.intervals,
                         "offsets": held.offsets}, f, protocol=5)
        os.replace(tmp, path)
    except OSError as e:
        log.warning("[difficulty_prior] could not keep the holdout model for the paired guard: %s", e)


def load_holdout(report_dir: Path | None, model: str) -> dict[str, Any] | None:
    path = holdout_path(report_dir, model)
    if path is None or not path.exists():
        return None
    try:
        with open(path, "rb") as f:
            data = pickle.load(f)  # our own file in the profile's state directory
    except (OSError, pickle.UnpicklingError, EOFError, AttributeError, ImportError, ValueError) as e:
        log.warning("[difficulty_prior] the stored holdout model is unreadable (%s) - paired guard skipped", e)
        return None
    return data if isinstance(data, dict) and data.get("format") == HOLDOUT_FORMAT and data.get("model") == model else None


def paired_verdict(old: dict[str, Any], new: dict[str, Any]) -> str | None:
    """Why tonight's model is a regression against the stored one on the same puzzles, or None."""
    if old.get("spearman") is not None and new.get("spearman") is not None and new["spearman"] < old["spearman"] - GUARD_PAIRED_DROP:
        return f"paired holdout Spearman {new['spearman']:.4f} vs the stored model's {old['spearman']:.4f} (more than {GUARD_PAIRED_DROP} lower)"
    if old.get("mae") and new.get("mae") is not None and new["mae"] > old["mae"] * (1 + GUARD_PAIRED_MAE_RISE):
        return f"paired holdout MAE {new['mae']:.4f} vs the stored model's {old['mae']:.4f} (more than {GUARD_PAIRED_MAE_RISE:.0%} higher)"
    return None


def paired_guard(stored: dict[str, Any] | None, fitted: Fitted, held: Holdout | None, X: Design, corpus: Corpus) -> dict[str, Any]:
    """Scores the stored newer-from-older model and tonight's on tonight's newer test puzzles the stored one never
    trained on. Returns the record's `guard.paired`: compared, skipped (why not), both models' numbers, regression."""
    if stored is None:
        return {"compared": False, "skipped": "no stored holdout model of this model (first run, or another machine)"}
    if held is None:
        return {"compared": False, "skipped": "no newer-from-older holdout tonight"}
    old_model: Ensemble = stored["ensemble"]
    if getattr(old_model, "dims", None) != X.dims():
        return {"compared": False, "skipped": f"inputs changed shape ({getattr(old_model, 'dims', None)} → {X.dims()})",
                "storedModelVersion": stored.get("modelVersion")}
    seen = set(stored.get("trainIds") or [])
    keep = np.array([fitted.train_ids[i] not in seen for i in held.test], dtype=bool)
    rows = held.test[keep]
    base = {"storedModelVersion": stored.get("modelVersion"), "storedComputedAt": stored.get("computedAt"), "count": int(len(rows))}
    if len(rows) < GUARD_MIN_TEST:
        return base | {"compared": False, "skipped": f"only {len(rows)} shared holdout test puzzles (< {GUARD_MIN_TEST})"}
    y = fitted.y[rows]
    s, k = [fitted.sizes[i] for i in rows], [fitted.kinds[i] for i in rows]
    old_base = old_model.predict(X[rows])
    old_bounds = interval_bounds_outside(stored["intervals"], old_base, fitted.context.vectors[rows].astype(np.float32),
                                         fitted.context.crops[rows], s, k)
    stored_offsets = stored.get("offsets")
    offset = stored_offsets([fitted.keys[i] for i in rows]) if stored_offsets is not None and fitted.keys else None
    old = metrics(y, *with_offset(old_base, old_bounds, offset))
    new = metrics(y, held.pred[keep], held.bounds[keep])
    regression = paired_verdict(old, new)
    return base | {"compared": True, "stored": old, "tonight": new, "regression": regression,
                   "maxSpearmanDrop": GUARD_PAIRED_DROP, "maxMaeRise": GUARD_PAIRED_MAE_RISE}


# picture factors ----------------------------------------------------------------------------------------------------
def fit_picture_factor_basis(vectors: np.ndarray, model: str, analyzer_version: str, components: int = PICTURE_FACTORS) -> dict[str, Any]:
    """A PCA basis of unit SigLIP 2 crop vectors (whitened). Fitted once offline over the whole catalogue and committed
    as `picture_factors/siglip2-crop-<version>.npz` - never refitted by a nightly run."""
    V = unit_rows(vectors).astype(np.float64)
    mean = V.mean(0)
    _, s, vt = np.linalg.svd(V - mean, full_matrices=False)
    scale = s[:components] / math.sqrt(max(1, len(V) - 1))
    comps = vt[:components]
    signs = np.sign(comps[np.arange(components), np.argmax(np.abs(comps), axis=1)])  # deterministic sign per factor
    return {"version": PICTURE_FACTORS_VERSION, "analyzer": "embed_siglip2", "model": model, "analyzerVersion": analyzer_version,
            "dim": int(V.shape[1]), "fittedOn": int(len(V)), "mean": mean.astype(np.float32),
            "components": (comps * signs[:, None]).astype(np.float32), "scale": scale.astype(np.float32),
            "explainedVariance": float((s[:components] ** 2).sum() / (s ** 2).sum())}


def load_picture_factor_basis(version: str = PICTURE_FACTORS_VERSION) -> dict[str, Any] | None:
    path = PICTURE_FACTORS_DIR / f"siglip2-crop-{version}.npz"
    if not path.exists():
        return None
    with np.load(path, allow_pickle=False) as z:
        return {k: (z[k].item() if z[k].ndim == 0 else z[k]) for k in z.files}


def picture_factors(basis: dict[str, Any] | None, corpus: Corpus, pids: list[str]) -> tuple[dict[str, list[float]], str | None]:
    """{puzzleId: 16 factors} of the puzzles with a SigLIP 2 crop vector, and why none when the basis does not fit."""
    if basis is None:
        return {}, f"no basis file for version {PICTURE_FACTORS_VERSION}"
    version = corpus.versions.get("embed_siglip2", "")
    model = version.split("+", 1)[1] if "+" in version else ""
    if model != str(basis["model"]):
        return {}, f"embed_siglip2 {version or 'missing'} is not the basis' model {basis['model']}"
    have = [p for p in pids if p in corpus.siglip and corpus.siglip[p].size == int(basis["dim"])]
    out: dict[str, list[float]] = {}
    for start in range(0, len(have), PREDICT_CHUNK):
        chunk = have[start:start + PREDICT_CHUNK]
        V = unit_rows(np.vstack([corpus.siglip[p] for p in chunk]))
        F = ((V - basis["mean"]) @ basis["components"].T) / basis["scale"]
        for p, row in zip(chunk, F):
            out[p] = [round(float(x), 4) for x in row]
    return out, None


# estimates ----------------------------------------------------------------------------------------------------------
def _clip(v: float) -> float:
    return float(min(LOG_LIMIT, max(-LOG_LIMIT, v)))


def _estimate(pid: str, puzzle: Puzzle, model: str, value: float, bounds: np.ndarray, oof: bool, size: int, at: str,
              model_version: str = MODEL_VERSION, crop_trust: float | None = None, factors: list[float] | None = None) -> DifficultyEstimate | None:
    """None for a non-finite number - never pushed (counted as `nonFinite`). Bounds ordered p10 <= p25 <= estimate <=
    p75 <= p90."""
    if not np.isfinite(value) or not np.all(np.isfinite(np.asarray(bounds, dtype=float))):
        return None
    ld = _clip(value)
    p25 = min(ld, _clip(bounds[1]))
    p75 = max(ld, _clip(bounds[2]))
    p10 = min(p25, _clip(bounds[0]))
    p90 = max(p75, _clip(bounds[3]))
    ppp = puzzle.pixels_per_piece
    return DifficultyEstimate(puzzle_id=pid, model=model, model_version=model_version, log_difficulty=round(ld, 5),
                              log_p10=round(p10, 5), log_p90=round(p90, 5), out_of_fold=oof, training_size=int(max(1, size)),
                              fingerprint=puzzle.fingerprint, computed_at=at, log_p25=round(p25, 5), log_p75=round(p75, 5),
                              pixels_per_piece=None if ppp is None else round(ppp, 2),
                              crop_trust=None if crop_trust is None else round(crop_trust, 4),
                              picture_factors=factors, picture_factors_version=PICTURE_FACTORS_VERSION if factors else None)


def estimates_for(fitted: Fitted, corpus: Corpus, domain: list[str], build_x: Callable[[list[str]], Design], at: str,
                  vectors: Callable[[list[str]], np.ndarray], model_version: str = MODEL_VERSION,
                  factors: dict[str, list[float]] | None = None) -> tuple[list[DifficultyEstimate], int]:
    """Estimates of the puzzles in `domain`: out-of-fold for its training rows, the full model for the rest (a labelled
    puzzle with 1-2 indices was not trained on either → out of fold too). Returns (estimates, non-finite skipped)."""
    factors = factors or {}
    out: list[DifficultyEstimate | None] = []
    in_domain = set(domain)

    def make(pid: str, value: float, bounds: np.ndarray, oof: bool, size: int) -> DifficultyEstimate | None:
        return _estimate(pid, corpus.puzzles[pid], fitted.model_name, value, bounds, oof, size, at, model_version,
                         corpus.crop_trust.get(pid), factors.get(pid))

    for i, pid in enumerate(fitted.train_ids):
        if pid in in_domain:
            out.append(make(pid, fitted.oof[i], fitted.oof_bounds[i], True, fitted.oof_sizes[i]))
    training = set(fitted.train_ids)
    others = [p for p in domain if p not in training]
    for start in range(0, len(others), PREDICT_CHUNK):
        chunk = others[start:start + PREDICT_CHUNK]
        base = fitted.full.predict(build_x(chunk))
        sizes, kinds = [corpus.puzzles[p].size_group for p in chunk], [corpus.puzzles[p].kind for p in chunk]
        base_b = interval_bounds_outside(fitted.intervals, base, vectors(chunk), corpus.crops(chunk), sizes, kinds)
        offset = fitted.offsets([corpus.puzzles[p].brand_size for p in chunk]) if fitted.offsets is not None else None
        pred, ob = with_offset(base, base_b, offset)
        for i, pid in enumerate(chunk):
            out.append(make(pid, pred[i], ob[i], corpus.puzzles[pid].label is not None, len(fitted.train_ids)))
    kept = [e for e in out if e is not None]
    return kept, len(out) - len(kept)


# the run report (status reads it) -----------------------------------------------------------------------------------
def read_report(report_dir: Path | None) -> dict[str, Any]:
    if report_dir is None:
        return {}
    try:
        data = json.loads((Path(report_dir) / REPORT_FILE).read_text())
    except (OSError, ValueError):
        return {}
    return data if isinstance(data, dict) else {}


def _write_report(report_dir: Path | None, report: dict[str, Any]) -> None:
    if report_dir is None:
        return
    try:
        Path(report_dir).mkdir(parents=True, exist_ok=True)
        (Path(report_dir) / REPORT_FILE).write_text(json.dumps(report, indent=1, default=str))
    except OSError as e:
        log.warning("[difficulty_prior] could not write the run report: %s", e)


def blocked_lines(report_dir: Path | None) -> list[str]:
    """For `status`: one line per model whose last run was blocked by the push guard."""
    out = []
    for name, m in as_map(read_report(report_dir).get("models")).items():
        if isinstance(m, dict) and m.get("status") == "blocked":
            out.append(f"difficulty_prior blocked since {m.get('blockedSince')} ({name}: {m.get('blockedBecause') or 'holdout Spearman ' + str(m.get('holdoutSpearman')) + ' vs ' + str(m.get('previousHoldoutSpearman'))}) - see {REPORT_FILE}, runbook §7")
    return out


# the run ------------------------------------------------------------------------------------------------------------
def _run_model(api: Any, name: str, corpus: Corpus, train_ids: list[str], domain: list[str],
               build_x: Callable[[list[str]], Design], vectors: Callable[[list[str]], np.ndarray], features: str,
               at: str, force_model: bool, dry_run: bool, previous_report: dict[str, Any], report_dir: Path | None,
               model_version: str, factors: dict[str, list[float]]) -> tuple[dict[str, Any], dict[str, Any]]:
    """Fit, validate, guard, record, push one model. Returns (summary, report entry)."""
    fitted, X, V = train(name, corpus, train_ids, build_x, vectors)
    evaluation, held = validate(fitted, X, V, corpus)
    paired = paired_guard(load_holdout(report_dir, name), fitted, held, X, corpus)
    del X, V
    estimates, non_finite = estimates_for(fitted, corpus, domain, build_x, at, vectors, model_version, factors)
    holdout = float(evaluation["holdoutSpearman"])
    previous = api.current_difficulty_model(name)
    prev_hs = float(previous["holdoutSpearman"]) if previous and previous.get("holdoutSpearman") is not None else None
    compare, skipped = guard(evaluation, len(train_ids), previous)
    reasons = []
    if compare and prev_hs is not None and holdout < prev_hs - GUARD_DROP:
        reasons.append(f"holdout Spearman {holdout:.3f} is more than {GUARD_DROP} below the current record's {prev_hs:.3f}")
    if paired.get("regression"):
        reasons.append(paired["regression"])
    regressed = bool(reasons)
    alpha = chosen_alpha(fitted.full)
    kernel = fitted.full.kernel
    record_eval = {
        "version": f"{model_version}+{name}", "features": features, "inputVersions": dict(corpus.versions),
        "alpha": alpha, "alphaAtGridEnd": alpha in (float(ALPHAS[0]), float(ALPHAS[-1])), "seed": SEED,
        "trainMinIndices": MIN_TRAIN_INDICES, "weights": "n/(n+10)",
        "learners": {"ridge": "StandardScaler + RidgeCV (GCV)"} | ({"kernelRidge": {
            "share": KERNEL_SHARE, "kernel": "rbf exp(-|a-b|^2/h)", "bandwidth": KERNEL_BANDWIDTH, "alpha": KERNEL_ALPHA,
            "inputs": "siglip2, dinov2, feature print, patch mean each unit-normed / 2; numbers and pieces standardised "
                      f"x {KERNEL_NUMBERS_WEIGHT} / sqrt(dim), x {KERNEL_PIECES_WEIGHT} / sqrt(dim)",
            "nystrom": kernel.nystrom, "nystromAbove": NYSTROM_ABOVE, "nystromLandmarks": NYSTROM_LANDMARKS}} if kernel else {}),
        "brandSizeOffset": fitted.offsets.describe() if fitted.offsets is not None else "off",
        **evaluation, "intervals": fitted.table.describe(), "intervalModel": fitted.intervals.describe() | {"method": fitted.intervals.method},
        "estimates": {"total": len(estimates), "outOfFold": sum(e.out_of_fold for e in estimates), "nonFinite": non_finite,
                      "withCropTrust": sum(e.crop_trust is not None for e in estimates),
                      "withPixelsPerPiece": sum(e.pixels_per_piece is not None for e in estimates),
                      "withPictureFactors": sum(e.picture_factors is not None for e in estimates)},
        "guard": {"previousHoldoutSpearman": prev_hs, "previousModelVersion": (previous or {}).get("modelVersion"),
                  "maxDrop": GUARD_DROP, "compared": compare, "skipped": skipped, "paired": paired,
                  "forced": bool(force_model and regressed)},
    }
    summary: dict[str, Any] = {"trainingSize": len(train_ids), "holdoutSpearman": holdout, "previousHoldoutSpearman": prev_hs,
                               "guardSkipped": skipped, "pairedGuard": {k: paired.get(k) for k in ("compared", "skipped", "regression")},
                               "overall": evaluation["overall"], "estimates": len(estimates),
                               "outOfFold": record_eval["estimates"]["outOfFold"], "nonFinite": non_finite}
    entry: dict[str, Any] = {"holdoutSpearman": holdout, "previousHoldoutSpearman": prev_hs, "evaluation": record_eval}

    if regressed and not force_model:
        prev_entry = as_map(as_map(previous_report.get("models")).get(name))
        since = prev_entry.get("blockedSince") if prev_entry.get("status") == "blocked" else at
        because = "; ".join(reasons)
        summary["blocked"] = (f"{name}: {because} - nothing recorded, nothing pushed (blocked since {since}). Read {REPORT_FILE}; a "
                              "human may override with `derive --only difficulty_prior --force-model`.")
        log.error("[difficulty_prior] PUSH BLOCKED: %s", summary["blocked"])
        return summary, entry | {"status": "blocked", "blockedSince": since, "blockedBecause": because}
    if regressed:
        log.warning("[difficulty_prior] %s: push guard overridden by --force-model (%s)", name, "; ".join(reasons))
    if skipped:
        log.info("[difficulty_prior] %s: guard not compared - %s", name, skipped)
    if dry_run:
        summary["dryRun"] = "nothing recorded, nothing pushed"
        return summary, entry | {"status": "dryRun"}

    record = api.record_difficulty_model(DifficultyModelRecord(name, model_version, len(train_ids), holdout, record_eval))
    summary["recorded"] = record.get("id")
    if held is not None:
        save_holdout(report_dir, name, model_version, held, at)
    counts: Counter = Counter()
    for o in api.store_difficulty_estimates(estimates):
        counts[o.status] += 1
        if o.status == "invalid":
            log.warning("[difficulty_prior] estimate refused as invalid for %s: %s", o.puzzle_id, o.errors or o.message)
    summary["pushed"] = {k: counts.get(k, 0) for k in ("stored", "stale_fingerprint", "hidden", "unknown_puzzle", "invalid")}
    log.info("[difficulty_prior] %s recorded %s (holdout Spearman %.3f), estimates %s", name, summary["recorded"], holdout, summary["pushed"])
    return summary, entry | {"status": "recorded", "recordId": summary["recorded"]}


def run(api: Any, *, meta: dict[str, Any] | None = None, rows: Callable[[str], Iterable[dict[str, Any]]] | None = None,
        force_model: bool = False, dry_run: bool = False, report_dir: Path | None = None,
        now: Callable[[], datetime] | None = None) -> dict[str, Any]:
    """One run: labels + embeddings → both models (fit, validate, guard, record, push). Returns the summary."""
    started = time.monotonic()
    if not api.endpoint_exists(LABELS_PATH):
        raise NotAvailableYet(
            "difficulty_prior needs the Phase 5 endpoints (GET /internal-api/puzzle-difficulty-labels, "
            "PUT /internal-api/puzzle-difficulty-estimates) - not deployed yet. Nothing was computed or pushed."
        )
    at = (now or (lambda: datetime.now(timezone.utc)))().strftime("%Y-%m-%dT%H:%M:%SZ")
    labels = list(api.iter_difficulty_labels())
    if meta is None:
        meta = {i.puzzle_id: i for i in api.iter_images(status="all", limit=1000)}
    pictures = {pid: (m.fingerprint, m.pieces_count) for pid, m in meta.items()}
    if rows is None:
        rows = lambda a: api.iter_analyses(a, only_current=True, limit=1000)  # noqa: E731
    brands = {pid: str(as_map(getattr(m, "manufacturer", None)).get("id") or "") for pid, m in meta.items()}
    corpus = build_corpus(labels, pictures, rows, {pid: b for pid, b in brands.items() if b})
    previous_report = read_report(report_dir)

    ids = sorted(corpus.puzzles)
    trainable = [p for p in ids if corpus.puzzles[p].label is not None and corpus.puzzles[p].label.index_count >= MIN_TRAIN_INDICES]  # type: ignore[union-attr]
    primary_train = [p for p in trainable if corpus.has_primary(p)]
    fallback_train = [p for p in trainable if corpus.has_fallback(p)]
    primary_runs = len(primary_train) >= MIN_TRAINING
    primary_domain = [p for p in ids if corpus.has_primary(p)] if primary_runs else []
    fallback_domain = [p for p in ids if corpus.has_fallback(p) and not (primary_runs and corpus.has_primary(p))]
    coverage = piece_scale_coverage(corpus, primary_train)
    corpus.use_piece_scale = bool(corpus.piece_scale) and coverage >= PIECE_SCALE_MIN_COVERAGE
    primary_version = PIECE_SCALE_MODEL_VERSION if corpus.use_piece_scale else MODEL_VERSION
    basis = load_picture_factor_basis()
    factors, factors_skipped = picture_factors(basis, corpus, [*primary_domain, *fallback_domain])
    summary: dict[str, Any] = {
        "version": f"{primary_version}+{MODEL}", "labels": len(labels), "labelledWithPicture": sum(corpus.puzzles[p].label is not None for p in ids),
        "puzzles": len(ids), "withPrimaryFeatures": sum(corpus.has_primary(p) for p in ids),
        "withFallbackOnly": sum(corpus.has_fallback(p) and not corpus.has_primary(p) for p in ids),
        "trainingSize": len(primary_train), "fallback": len(fallback_domain), "dropped": dict(corpus.dropped),
        "extrasImputed": corpus.extras_imputed(primary_domain),
        "inputVersions": dict(corpus.versions),
        "pieceScale": {"puzzles": len(corpus.piece_scale), "trainingCoverage": round(coverage, 4), "inModel": corpus.use_piece_scale,
                       "minCoverage": PIECE_SCALE_MIN_COVERAGE},
        "cropTrust": {"puzzles": len(corpus.crop_trust)},
        "pictureFactors": {"version": PICTURE_FACTORS_VERSION, "puzzles": len(factors), "skipped": factors_skipped},
    }
    numbers = f"45 traits/colors/segmentation/vision_labels numbers{' + ' + str(len(PIECE_SCALE_NAMES)) + ' piece_scale numbers' if corpus.use_piece_scale else ''}"
    plans = [
        (MODEL, primary_train, primary_domain, corpus.primary_x, corpus.primary_vectors, primary_version,
         f"ensemble {1 - KERNEL_SHARE:g} ridge + {KERNEL_SHARE:g} rbf kernel ridge on siglip2 crop + dinov2 crop + apple feature "
         f"print + dinov2 patch mean + {numbers} (ridge: x{EXTRA_WEIGHT}; corpus mean where missing) + ln pieces, ln pieces^2, "
         f"size group one-hot{'; + shrunk brand x size offset (k ' + format(BRAND_SIZE_PSEUDO_COUNT, 'g') + ')' if BRAND_SIZE_PSEUDO_COUNT is not None else ''}"),
        (FALLBACK_MODEL, fallback_train, fallback_domain, corpus.fallback_x, corpus.fallback_vectors, MODEL_VERSION,
         "ridge on siglip2 whole image (else crop) + ln pieces, ln pieces^2, size group one-hot; standardised"),
    ]
    report: dict[str, Any] = {"computedAt": at, "summary": summary, "models": {}}
    blocked = []
    for name, train_ids, domain, build_x, vectors, model_version, features in plans:
        if not domain and (len(train_ids) >= MIN_TRAINING or name != MODEL):
            summary[name] = {"skipped": "no puzzle for this model tonight"}
            continue
        if len(train_ids) < MIN_TRAINING:
            what = "both crop embeddings" if name == MODEL else "a SigLIP 2 vector"
            summary[name] = {"skipped": f"only {len(train_ids)} labelled puzzles (>= {MIN_TRAIN_INDICES} indices) have {what} - "
                                        f"the model needs {MIN_TRAINING}. Nothing recorded, nothing pushed for it."}
            log.info("[difficulty_prior] %s: %s", name, summary[name]["skipped"])
            continue
        model_summary, entry = _run_model(api, name, corpus, train_ids, domain, build_x, vectors, features, at,
                                          force_model, dry_run, previous_report, report_dir, model_version, factors)
        summary[name] = model_summary
        report["models"][name] = entry
        if "blocked" in model_summary:
            blocked.append(model_summary["blocked"])
    if not report["models"]:
        summary["skipped"] = (f"no model has {MIN_TRAINING} training puzzles yet ({len(primary_train)} with both crop embeddings, "
                              f"{len(fallback_train)} with a SigLIP 2 vector). Nothing recorded, nothing pushed.")
        log.info("[difficulty_prior] %s", summary["skipped"])
    if blocked:
        summary["blocked"] = " | ".join(blocked)
    if dry_run:
        summary["dryRun"] = "nothing recorded, nothing pushed"
    if report["models"]:
        _write_report(report_dir, report)
    summary["seconds"] = round(time.monotonic() - started, 1)
    return summary
