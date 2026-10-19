"""`difficulty_prior` - picture-based difficulty estimates (implementation-plan.md §5.3, runbook image-pipeline.md §7).

Ridge regression ("rung b" of prediction-research.md §3: light training, seconds on the CPU, no fine-tuning, no
language model for numbers) from the stored picture embeddings + the piece count to the puzzle's as-of log difficulty.
Nightly on the M3 after the daily pipeline (`derive`), during the backfill on whatever is embedded so far.

Contract: docs/features/internal-api.md § "Puzzle difficulty labels and estimates" (client in `puzzle_vision/api.py`).

Data
- Labels: `api.iter_difficulty_labels()` - `logDifficulty` (as-of), `indexCount`, `piecesCount`, `manufacturerId`,
  `addedAt`, `imageFingerprint`. Aggregates only; secret puzzles are never listed.
- Pictures: the work list (`iter_images(status=all)`, the puzzle's current fingerprint + piece count; secret pictures
  are never listed). Embeddings and the `artwork` kind come from the bulk endpoint (`onlyCurrent=1`). **Every join is
  on (puzzleId, imageFingerprint)**: an embedding row or a label of another picture than the puzzle's current one is
  left out. Of every analyzer only its newest `analyzerVersion` is used (a version bump re-runs over days - vectors of
  two models are never mixed), and within it only the most common dimension. Everything left out is counted under
  `dropped`.

Two models, each with its own model record, push guard and estimates (`model` field):
1. `ridge-siglip2-dinov2` (the model of record): SigLIP 2 crop (`embed_siglip2`) + DINOv2 crop (`embed_dinov2`) +
   pieces. Estimates every puzzle with both crop embeddings.
2. `ridge-siglip2-full` (fallback): the SigLIP 2 whole-image embedding (`embed_siglip2_full`, else the crop's) +
   pieces. It learns from the whole-image vectors because those are what it is applied to. It estimates the puzzles
   the model of record does not: those without crop embeddings (no `artwork` yet, or the M1 has not embedded the crop)
   and, while the model of record has fewer than MIN_TRAINING rows (phase A of the backfill), every puzzle it can.
A puzzle with no SigLIP 2 vector at all gets no estimate.
Pieces = ln(pieces), ln(pieces)², one-hot of the size group (≤300 / 500 / 1000 / 1500+ / odd - research Q1 groups);
everything standardised (StandardScaler), ridge alpha by efficient leave-one-out CV (`RidgeCV`, GCV) inside every fit.

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
Intervals: log_p10 / log_p90 = estimate + the 10th / 90th percentile of out-of-fold residuals of well measured
puzzles (>= 10 indices, so the interval describes the difficulty and not label noise; all training puzzles while fewer
than 50 exist) per (size group × image kind), falling back to the size group, then to all of them, below 50 residuals.
**A training puzzle's interval never contains its own residual**: rows of fold k take the quantiles of the other folds'
residuals (the same in the brand holdout; the newer-from-older holdout cross-fits inside the older puzzles). Never
narrower than the estimate itself (p10 <= estimate <= p90), clipped to ±5; a non-finite number is never pushed.

Validation per run (the model record's `evaluation`): out-of-fold on well measured puzzles (`indexCount` >= 20, lower
thresholds 10 / 5 / 3 only while fewer than 30 puzzles reach it - `testMinIndices` says which), brands held out
(GroupKFold over brand ∪ near-duplicate components), newer predicted from older (oldest 70 % by `addedAt` → the rest,
newer puzzles with a near-duplicate among the older ones left out; its Spearman = `holdoutSpearman`), per size group ×
image kind (`artwork` kind: flat / box / assembled / other / unknown). Numbers: Spearman, MAE of log difficulty,
tier exact / ±1 (DifficultyTier::fromScore cut points) next to always-"Average", 50 % / 80 % interval coverage + width.

Push guard (per model): the model's current record (`current_difficulty_model(model)`). It compares only like with
like - same `holdoutSource` and `holdoutTestMinIndices`, >= 100 holdout test puzzles in both runs and a training size
within 25 % of the previous one; otherwise the run is recorded with `guard.skipped` naming the reason (a lucky early
run on 31 test puzzles must not lock the model). When compared and the new holdout Spearman is more than 0.05 below,
nothing is recorded and nothing pushed for that model (a blocked run must not become the next night's baseline): an
error in the log, `blockedSince` in `difficulty_prior-last-run.json` (`status` shows it). A human overrides with
`derive --only difficulty_prior --force-model` (the record then says `guard.forced: true`).
Push order per model: record, then the estimates (chunks of 500). Outcomes `stale_fingerprint` / `hidden` /
`unknown_puzzle` are skipped quietly and counted; `invalid` is our bug (logged as a warning).

Reproducible: fixed fold seeds, deterministic solvers, puzzles in id order.
"""

from __future__ import annotations

import base64
import json
import logging
import re
import time
from collections import Counter
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Iterable

import numpy as np

from ..api import DifficultyEstimate, DifficultyLabel, DifficultyModelRecord
from ..payload import as_map

log = logging.getLogger(__name__)

LABELS_PATH = "/internal-api/puzzle-difficulty-labels"
ESTIMATES_PATH = "/internal-api/puzzle-difficulty-estimates"
REPORT_FILE = "difficulty_prior-last-run.json"

MODEL = "ridge-siglip2-dinov2"
FALLBACK_MODEL = "ridge-siglip2-full"
MODELS = (MODEL, FALLBACK_MODEL)
MODEL_VERSION = "1.0.0"
VERSION = f"{MODEL_VERSION}+{MODEL}"  # "difficulty_prior 1.0.0+ridge-siglip2-dinov2" - bump on any change of numbers

MIN_TRAIN_INDICES = 3
TEST_MIN_INDICES = (20, 10, 5, 3)
MIN_TEST = 30
MIN_HOLDOUT_TEST = 30
MIN_TRAINING = 200
INTERVAL_MIN_INDICES = 10
MIN_GROUP_RESIDUALS = 50
GUARD_DROP = 0.05
GUARD_MIN_TEST = 100
GUARD_MAX_TRAINING_CHANGE = 0.25
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
EMBEDDINGS = ("embed_siglip2", "embed_dinov2", "embed_siglip2_full")


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


# the corpus ---------------------------------------------------------------------------------------------------------
@dataclass
class Puzzle:
    puzzle_id: str
    fingerprint: str
    pieces: int
    kind: str = "unknown"
    label: DifficultyLabel | None = None

    @property
    def size_group(self) -> str:
        return size_group(self.pieces)


@dataclass
class Corpus:
    puzzles: dict[str, Puzzle]
    siglip: dict[str, np.ndarray] = field(default_factory=dict)  # crop
    dino: dict[str, np.ndarray] = field(default_factory=dict)  # crop
    full: dict[str, np.ndarray] = field(default_factory=dict)  # whole image - kept only where the fallback needs it
    versions: dict[str, str] = field(default_factory=dict)  # the analyzer version each input was read in
    dropped: Counter = field(default_factory=Counter)

    def has_primary(self, pid: str) -> bool:
        return pid in self.siglip and pid in self.dino

    def has_fallback(self, pid: str) -> bool:
        return pid in self.full or pid in self.siglip

    def _pieces(self, pids: list[str]) -> np.ndarray:
        return piece_features(np.array([self.puzzles[p].pieces for p in pids]))

    def primary_x(self, pids: list[str]) -> np.ndarray:
        emb = np.vstack([np.concatenate([self.siglip[p], self.dino[p]]) for p in pids]).astype(np.float32)
        return np.c_[emb, self._pieces(pids)]

    def primary_vectors(self, pids: list[str]) -> np.ndarray:
        return np.vstack([self.siglip[p] for p in pids]).astype(np.float32)

    def fallback_vectors(self, pids: list[str]) -> np.ndarray:
        return np.vstack([self.full[p] if p in self.full else self.siglip[p] for p in pids]).astype(np.float32)

    def fallback_x(self, pids: list[str]) -> np.ndarray:
        return np.c_[self.fallback_vectors(pids), self._pieces(pids)]


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


def build_corpus(labels: Iterable[DifficultyLabel], pictures: dict[str, tuple[str, int | None]],
                 rows: Callable[[str], Iterable[dict[str, Any]]]) -> Corpus:
    """`pictures`: puzzleId -> (current fingerprint, pieces) of every listed (= not secret) puzzle with a picture.
    `rows(analyzer)`: the current rows of one analyzer (bulk endpoint). Joins on (puzzleId, imageFingerprint)."""
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

    for pid, kind in _newest("artwork", rows("artwork"), corpus, lambda row, p: image_kind(row.get("result"))).items():
        corpus.puzzles[pid].kind = kind

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
    return corpus


# folds --------------------------------------------------------------------------------------------------------------
def near_duplicate_groups(vectors: np.ndarray, extra: list[str] | None = None) -> tuple[np.ndarray, dict[str, Any]]:
    """Group label per row: connected components of cosine >= NEAR_DUPLICATE_COSINE (+ rows sharing an `extra` label,
    e.g. the brand). A component of more than MAX_CLUSTER_SHARE of the rows switches the picture grouping off."""
    from scipy.sparse import coo_matrix
    from scipy.sparse.csgraph import connected_components

    n = len(vectors)
    v = np.asarray(vectors, dtype=np.float32)
    v = v / np.maximum(np.linalg.norm(v, axis=1, keepdims=True), 1e-12)
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


# fitting ------------------------------------------------------------------------------------------------------------
def _new_model() -> Any:
    from sklearn.linear_model import RidgeCV
    from sklearn.pipeline import make_pipeline
    from sklearn.preprocessing import StandardScaler

    return make_pipeline(StandardScaler(), RidgeCV(alphas=ALPHAS))


def fit(X: np.ndarray, y: np.ndarray, w: np.ndarray) -> Any:
    m = _new_model()
    m.fit(X, y, ridgecv__sample_weight=w)
    return m


def cross_fit(X: np.ndarray, y: np.ndarray, w: np.ndarray, splits: Iterable[tuple[np.ndarray, np.ndarray]]) -> tuple[np.ndarray, np.ndarray]:
    """Out-of-fold predictions: each row predicted by a model fitted without it. Returns (predictions, the training size
    of the model that predicted each row)."""
    pred = np.full(len(y), np.nan)
    sizes = np.zeros(len(y), dtype=int)
    for tr, te in splits:
        if len(te) == 0:
            continue
        pred[te] = fit(X[tr], y[tr], w[tr]).predict(X[te])
        sizes[te] = len(tr)
    return pred, sizes


def kfold(n: int) -> list[tuple[np.ndarray, np.ndarray]]:
    from sklearn.model_selection import KFold

    return list(KFold(n_splits=min(FOLDS, n), shuffle=True, random_state=SEED).split(np.zeros(n)))


def predict_chunks(model: Any, build_x: Callable[[list[str]], np.ndarray], pids: list[str]) -> np.ndarray:
    out = np.zeros(len(pids))
    for start in range(0, len(pids), PREDICT_CHUNK):
        chunk = pids[start:start + PREDICT_CHUNK]
        out[start:start + len(chunk)] = model.predict(build_x(chunk))
    return out


def chosen_alpha(model: Any) -> float:
    return float(model[-1].alpha_)


# intervals ----------------------------------------------------------------------------------------------------------
QUANTILES = (0.10, 0.25, 0.75, 0.90)


def _quantiles(r: np.ndarray) -> tuple[float, float, float, float]:
    q10, q25, q75, q90 = (float(x) for x in np.quantile(r, QUANTILES))
    return min(q10, 0.0), min(q25, 0.0), max(q75, 0.0), max(q90, 0.0)


class IntervalTable:
    """Residual quantiles (label - out-of-fold estimate) per (size group × image kind), then per size group, then all."""

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

    def bounds(self, pred: np.ndarray, sizes: list[str], kinds: list[str]) -> np.ndarray:
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
    """Bounds of out-of-fold rows from the OTHER folds' residuals only - a row's interval never contains its own
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
    full: Any
    table: IntervalTable
    sizes: list[str]
    kinds: list[str]


def train(name: str, corpus: Corpus, ids: list[str], build_x: Callable[[list[str]], np.ndarray],
          vectors: Callable[[list[str]], np.ndarray]) -> tuple[Fitted, np.ndarray, np.ndarray]:
    X = build_x(ids)
    V = vectors(ids)
    y = np.array([corpus.puzzles[p].label.log_difficulty for p in ids])  # type: ignore[union-attr]
    n = np.array([corpus.puzzles[p].label.index_count for p in ids])  # type: ignore[union-attr]
    w = weights(n)
    groups, info = near_duplicate_groups(V)
    splits, fold = group_folds(groups)
    oof, oof_sizes = cross_fit(X, y, w, splits)
    full = fit(X, y, w)
    sizes = [corpus.puzzles[p].size_group for p in ids]
    kinds = [corpus.puzzles[p].kind for p in ids]
    table = IntervalTable(y - oof, n, sizes, kinds)
    ob = fold_bounds(oof, y - oof, n, sizes, kinds, fold)
    return Fitted(name, ids, y, n, oof, oof_sizes, ob, fold, groups, info, full, table, sizes, kinds), X, V


def validate(fitted: Fitted, X: np.ndarray, V: np.ndarray, corpus: Corpus) -> dict[str, Any]:
    """The run's evaluation of one model (stored as its model record's `evaluation`)."""
    y, n, sizes, kinds = fitted.y, fitted.n, fitted.sizes, fitted.kinds
    w = weights(n)
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
        bb = fold_bounds(bp, y - bp, n, sizes, kinds, bfold)
        brand_out |= {"testMinIndices": t, "overall": metrics(y[ev], bp[ev], bb[ev]), "groups": grouped(y[ev], bp[ev], bb[ev], sel_s, sel_k)}
    holdouts: dict[str, Any] = {"brands": brand_out}

    # newer predicted from older (`addedAt`): its Spearman is the push guard's number
    added = [corpus.puzzles[p].label.added_at for p in fitted.train_ids]  # type: ignore[union-attr]
    dated = sorted((a, i) for i, a in enumerate(added) if a)
    newer: dict[str, Any] = {"dated": len(dated)}
    holdout, holdout_count, holdout_t = None, 0, None
    if len(dated) >= MIN_TRAINING // 2:
        cut = int(len(dated) * TIME_SPLIT)
        old_idx = np.array([i for _, i in dated[:cut]])
        new_idx = np.array([i for _, i in dated[cut:]])
        old_groups = set(fitted.groups[old_idx].tolist())
        twin = np.array([fitted.groups[i] in old_groups for i in new_idx], dtype=bool)
        new_idx = new_idx[~twin]  # a newer edition of an older picture would only test memory
        tn = pick_threshold(n[new_idx], MIN_TEST)
        test = new_idx[n[new_idx] >= tn]
        newer |= {"testDroppedNearDuplicates": int(twin.sum()), "testMinIndices": tn}
        if len(test) >= MIN_HOLDOUT_TEST:
            old_splits, old_fold = group_folds(fitted.groups[old_idx])
            old_oof, _ = cross_fit(X[old_idx], y[old_idx], w[old_idx], old_splits)  # residuals the newer never shaped
            old_s, old_k = [sizes[i] for i in old_idx], [kinds[i] for i in old_idx]
            older_table = IntervalTable(y[old_idx] - old_oof, n[old_idx], old_s, old_k)
            pred = fit(X[old_idx], y[old_idx], w[old_idx]).predict(X[test])
            ts, tk = [sizes[i] for i in test], [kinds[i] for i in test]
            tb = older_table.bounds(pred, ts, tk)
            newer |= {"trainSize": int(len(old_idx)), "trainAddedUntil": dated[cut - 1][0],
                      "overall": metrics(y[test], pred, tb), "groups": grouped(y[test], pred, tb, ts, tk)}
            holdout, holdout_count, holdout_t = newer["overall"].get("spearman"), int(len(test)), tn
    holdouts["newerFromOlder"] = newer
    out["holdouts"] = holdouts
    if holdout is None:  # too few dated puzzles: the cross-fitted Spearman stands in (said so in the record)
        holdout = out["overall"].get("spearman") or 0.0
        out |= {"holdoutSource": "cross_fit", "holdoutCount": int(ev.sum()), "holdoutTestMinIndices": t}
    else:
        out |= {"holdoutSource": "newer_from_older", "holdoutCount": holdout_count, "holdoutTestMinIndices": holdout_t}
    out["holdoutSpearman"] = round(float(holdout), 4)
    out["folds"] = fitted.folds_info | {"count": int(len(set(fitted.fold.tolist())))}
    return out


def guard(evaluation: dict[str, Any], training_size: int, previous: dict[str, Any] | None) -> tuple[bool, str | None]:
    """(compare?, why not). Compares only like with like - see the module docstring."""
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


# estimates ----------------------------------------------------------------------------------------------------------
def _clip(v: float) -> float:
    return float(min(LOG_LIMIT, max(-LOG_LIMIT, v)))


def _estimate(pid: str, puzzle: Puzzle, model: str, value: float, bounds: np.ndarray, oof: bool, size: int, at: str) -> DifficultyEstimate | None:
    """None for a non-finite number - never pushed (counted as `nonFinite`)."""
    if not np.isfinite(value) or not np.all(np.isfinite(np.asarray(bounds, dtype=float))):
        return None
    ld = _clip(value)
    return DifficultyEstimate(puzzle_id=pid, model=model, model_version=MODEL_VERSION, log_difficulty=round(ld, 5),
                              log_p10=round(min(ld, _clip(bounds[0])), 5), log_p90=round(max(ld, _clip(bounds[3])), 5),
                              out_of_fold=oof, training_size=int(max(1, size)), fingerprint=puzzle.fingerprint, computed_at=at)


def estimates_for(fitted: Fitted, corpus: Corpus, domain: list[str], build_x: Callable[[list[str]], np.ndarray], at: str) -> tuple[list[DifficultyEstimate], int]:
    """Estimates of the puzzles in `domain`: out-of-fold for its training rows, the full model for the rest (a labelled
    puzzle with 1-2 indices was not trained on either → out of fold too). Returns (estimates, non-finite skipped)."""
    out: list[DifficultyEstimate | None] = []
    in_domain = set(domain)
    for i, pid in enumerate(fitted.train_ids):
        if pid in in_domain:
            out.append(_estimate(pid, corpus.puzzles[pid], fitted.model_name, fitted.oof[i], fitted.oof_bounds[i], True, fitted.oof_sizes[i], at))
    training = set(fitted.train_ids)
    others = [p for p in domain if p not in training]
    if others:
        pred = predict_chunks(fitted.full, build_x, others)
        ob = fitted.table.bounds(pred, [corpus.puzzles[p].size_group for p in others], [corpus.puzzles[p].kind for p in others])
        for i, pid in enumerate(others):
            out.append(_estimate(pid, corpus.puzzles[pid], fitted.model_name, pred[i], ob[i], corpus.puzzles[pid].label is not None, len(fitted.train_ids), at))
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
            out.append(f"difficulty_prior blocked since {m.get('blockedSince')} ({name}: holdout Spearman "
                       f"{m.get('holdoutSpearman')} vs {m.get('previousHoldoutSpearman')}) - see {REPORT_FILE}, runbook §7")
    return out


# the run ------------------------------------------------------------------------------------------------------------
def _run_model(api: Any, name: str, corpus: Corpus, train_ids: list[str], domain: list[str],
               build_x: Callable[[list[str]], np.ndarray], vectors: Callable[[list[str]], np.ndarray], features: str,
               at: str, force_model: bool, dry_run: bool, previous_report: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any]]:
    """Fit, validate, guard, record, push one model. Returns (summary, report entry)."""
    fitted, X, V = train(name, corpus, train_ids, build_x, vectors)
    evaluation = validate(fitted, X, V, corpus)
    del X, V
    estimates, non_finite = estimates_for(fitted, corpus, domain, build_x, at)
    holdout = float(evaluation["holdoutSpearman"])
    previous = api.current_difficulty_model(name)
    prev_hs = float(previous["holdoutSpearman"]) if previous and previous.get("holdoutSpearman") is not None else None
    compare, skipped = guard(evaluation, len(train_ids), previous)
    regressed = compare and prev_hs is not None and holdout < prev_hs - GUARD_DROP
    alpha = chosen_alpha(fitted.full)
    record_eval = {
        "version": f"{MODEL_VERSION}+{name}", "features": features, "inputVersions": dict(corpus.versions),
        "alpha": alpha, "alphaAtGridEnd": alpha in (float(ALPHAS[0]), float(ALPHAS[-1])), "seed": SEED,
        "trainMinIndices": MIN_TRAIN_INDICES, "weights": "n/(n+10)", **evaluation, "intervals": fitted.table.describe(),
        "estimates": {"total": len(estimates), "outOfFold": sum(e.out_of_fold for e in estimates), "nonFinite": non_finite},
        "guard": {"previousHoldoutSpearman": prev_hs, "previousModelVersion": (previous or {}).get("modelVersion"),
                  "maxDrop": GUARD_DROP, "compared": compare, "skipped": skipped, "forced": bool(force_model and regressed)},
    }
    summary: dict[str, Any] = {"trainingSize": len(train_ids), "holdoutSpearman": holdout, "previousHoldoutSpearman": prev_hs,
                               "guardSkipped": skipped, "overall": evaluation["overall"], "estimates": len(estimates),
                               "outOfFold": record_eval["estimates"]["outOfFold"], "nonFinite": non_finite}
    entry: dict[str, Any] = {"holdoutSpearman": holdout, "previousHoldoutSpearman": prev_hs, "evaluation": record_eval}

    if regressed and not force_model:
        prev_entry = as_map(as_map(previous_report.get("models")).get(name))
        since = prev_entry.get("blockedSince") if prev_entry.get("status") == "blocked" else at
        summary["blocked"] = (f"{name}: holdout Spearman {holdout:.3f} is more than {GUARD_DROP} below the current record's "
                              f"{prev_hs:.3f} - nothing recorded, nothing pushed (blocked since {since}). Read {REPORT_FILE}; a "
                              "human may override with `derive --only difficulty_prior --force-model`.")
        log.error("[difficulty_prior] PUSH BLOCKED: %s", summary["blocked"])
        return summary, entry | {"status": "blocked", "blockedSince": since}
    if regressed:
        log.warning("[difficulty_prior] %s: push guard overridden by --force-model (%.3f vs previous %.3f)", name, holdout, prev_hs)
    if skipped:
        log.info("[difficulty_prior] %s: guard not compared - %s", name, skipped)
    if dry_run:
        summary["dryRun"] = "nothing recorded, nothing pushed"
        return summary, entry | {"status": "dryRun"}

    record = api.record_difficulty_model(DifficultyModelRecord(name, MODEL_VERSION, len(train_ids), holdout, record_eval))
    summary["recorded"] = record.get("id")
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
    corpus = build_corpus(labels, pictures, rows)
    previous_report = read_report(report_dir)

    ids = sorted(corpus.puzzles)
    trainable = [p for p in ids if corpus.puzzles[p].label is not None and corpus.puzzles[p].label.index_count >= MIN_TRAIN_INDICES]  # type: ignore[union-attr]
    primary_train = [p for p in trainable if corpus.has_primary(p)]
    fallback_train = [p for p in trainable if corpus.has_fallback(p)]
    primary_runs = len(primary_train) >= MIN_TRAINING
    primary_domain = [p for p in ids if corpus.has_primary(p)] if primary_runs else []
    fallback_domain = [p for p in ids if corpus.has_fallback(p) and not (primary_runs and corpus.has_primary(p))]
    summary: dict[str, Any] = {
        "version": VERSION, "labels": len(labels), "labelledWithPicture": sum(corpus.puzzles[p].label is not None for p in ids),
        "puzzles": len(ids), "withPrimaryFeatures": sum(corpus.has_primary(p) for p in ids),
        "withFallbackOnly": sum(corpus.has_fallback(p) and not corpus.has_primary(p) for p in ids),
        "trainingSize": len(primary_train), "fallback": len(fallback_domain), "dropped": dict(corpus.dropped),
        "inputVersions": dict(corpus.versions),
    }
    plans = [
        (MODEL, primary_train, primary_domain, corpus.primary_x, corpus.primary_vectors,
         "siglip2 crop + dinov2 crop + ln pieces, ln pieces^2, size group one-hot; standardised"),
        (FALLBACK_MODEL, fallback_train, fallback_domain, corpus.fallback_x, corpus.fallback_vectors,
         "siglip2 whole image (else crop) + ln pieces, ln pieces^2, size group one-hot; standardised"),
    ]
    report: dict[str, Any] = {"computedAt": at, "summary": summary, "models": {}}
    blocked = []
    for name, train_ids, domain, build_x, vectors, features in plans:
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
                                          force_model, dry_run, previous_report)
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
