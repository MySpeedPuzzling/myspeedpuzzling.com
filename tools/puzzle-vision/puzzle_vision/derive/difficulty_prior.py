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
  left out (counted under `dropped`).

Two feature sets, each with its own model (a puzzle uses the first it has):
1. `ridge-siglip2-dinov2` (the model of record): SigLIP 2 crop (`embed_siglip2`) + DINOv2 crop (`embed_dinov2`) +
   pieces. Its holdout Spearman is what the push guard compares.
2. `ridge-siglip2-full` (fallback): the SigLIP 2 whole-image embedding (`embed_siglip2_full`, else the crop's) +
   pieces, for puzzles without the crop embeddings yet (no `artwork` yet: phase A of the backfill, new puzzles before
   the M1 embedded their crop). It learns from the whole-image vectors because those are what it is applied to; its
   estimates carry its own model name. A puzzle with no SigLIP 2 vector at all gets no estimate.
Pieces = ln(pieces), ln(pieces)², one-hot of the size group (≤300 / 500 / 1000 / 1500+ / odd - research Q1 groups);
everything standardised (StandardScaler), ridge alpha by efficient leave-one-out CV (`RidgeCV`, GCV) inside every fit.

Training: labelled puzzles with `indexCount` >= 3, weighted n/(n+10). Fewer than MIN_TRAINING (200) rows for the model
of record = the run records and pushes nothing (said in the summary). Estimates:
- labelled training puzzles: **5-fold cross-fitted out-of-fold** (`outOfFold: true`) - the fold model never saw them;
- labelled puzzles with 1-2 indices: the full model, which was not trained on them either → `outOfFold: true`;
- unlabelled puzzles: the full model, `outOfFold: false`.
Intervals: log_p10 / log_p90 = estimate + the 10th / 90th percentile of out-of-fold residuals of well measured
puzzles (>= 10 indices, so the interval describes the difficulty and not label noise; all training puzzles while fewer
than 50 exist) per (size group × image kind), falling back to the size group, then to all of them, below 50 residuals.
Never narrower than the estimate itself (p10 <= estimate <= p90), clipped to ±5.

Validation per run (the model record's `evaluation`): out-of-fold on well measured puzzles (`indexCount` >= 20, lower
thresholds 10 / 5 / 3 only while fewer than 30 puzzles reach it - `testMinIndices` says which), brands held out
(GroupKFold by manufacturer), newer predicted from older (oldest 70 % by `addedAt` → the rest; its Spearman =
`holdoutSpearman`), per size group × image kind (`artwork` kind: flat / box / assembled / other / unknown). Numbers:
Spearman, MAE of log difficulty, tier exact / ±1 (DifficultyTier::fromScore cut points) next to always-"Average",
50 % / 80 % interval coverage and width.

Push guard: the current record of the model (`current_difficulty_model`) - when the new holdout Spearman is more than
0.05 below it, nothing is recorded and nothing pushed (a blocked run must not become the next night's baseline); the
warning names both numbers and the run's evaluation is written to `difficulty_prior-last-run.json`. A human overrides
with `derive --only difficulty_prior --force-model` (the record then says `guard.forced: true`).
Push order: record the model, then the estimates (chunks of 500). Outcomes `stale_fingerprint` / `hidden` /
`unknown_puzzle` are skipped quietly and counted; `invalid` is our bug (logged as a warning).

Reproducible: fixed fold seeds, deterministic solvers, puzzles in id order.
"""

from __future__ import annotations

import base64
import json
import logging
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

MODEL = "ridge-siglip2-dinov2"
FALLBACK_MODEL = "ridge-siglip2-full"
MODEL_VERSION = "1.0.0"
VERSION = f"{MODEL_VERSION}+{MODEL}"  # "difficulty_prior 1.0.0+ridge-siglip2-dinov2" - bump on any change of numbers

MIN_TRAIN_INDICES = 3
TEST_MIN_INDICES = (20, 10, 5, 3)
MIN_TEST = 30
MIN_HOLDOUT_TEST = 10
MIN_TRAINING = 200
INTERVAL_MIN_INDICES = 10
MIN_GROUP_RESIDUALS = 50
GUARD_DROP = 0.05
FOLDS = 5
SEED = 0
TIME_SPLIT = 0.7
ALPHAS = np.logspace(-2, 6, 33)  # GCV picks one per fit; the record says when it sits on an end of the grid
PREDICT_CHUNK = 4096
LOG_LIMIT = 4.99
TIER_EDGES = np.log([0.75, 0.90, 1.10, 1.25, 1.45])  # src/Value/DifficultyTier.php fromScore(): score < edge
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
    dropped: Counter = field(default_factory=Counter)

    def has_primary(self, pid: str) -> bool:
        return pid in self.siglip and pid in self.dino

    def has_fallback(self, pid: str) -> bool:
        return pid in self.full or pid in self.siglip

    def primary_x(self, pids: list[str]) -> np.ndarray:
        emb = np.vstack([np.concatenate([self.siglip[p], self.dino[p]]) for p in pids]).astype(np.float32)
        return np.c_[emb, piece_features(np.array([self.puzzles[p].pieces for p in pids]))]

    def fallback_x(self, pids: list[str]) -> np.ndarray:
        emb = np.vstack([self.full[p] if p in self.full else self.siglip[p] for p in pids]).astype(np.float32)
        return np.c_[emb, piece_features(np.array([self.puzzles[p].pieces for p in pids]))]


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

    for row in rows("artwork"):
        p = corpus.puzzles.get(row.get("puzzleId", ""))
        if p is not None and row.get("imageFingerprint") == p.fingerprint:
            p.kind = image_kind(row.get("result"))

    dims: dict[str, int] = {}
    for analyzer in EMBEDDINGS:
        target = {"embed_siglip2": corpus.siglip, "embed_dinov2": corpus.dino, "embed_siglip2_full": corpus.full}[analyzer]
        for row in rows(analyzer):
            pid = row.get("puzzleId", "")
            p = corpus.puzzles.get(pid)
            if p is None or row.get("imageFingerprint") != p.fingerprint:
                corpus.dropped[f"{analyzer}OfAnotherPicture"] += 1
                continue
            if analyzer == "embed_siglip2_full" and p.label is None and corpus.has_primary(pid):
                continue  # the whole-image vector serves only the fallback model: its training rows and its puzzles
            result = as_map(row.get("result"))
            vec = _decode(result.get("cls") if analyzer == "embed_dinov2" and result.get("cls") else result.get("vector"))
            if vec is None or vec.size == 0 or not np.all(np.isfinite(vec)):
                corpus.dropped[f"{analyzer}Unreadable"] += 1
                continue
            dims.setdefault(analyzer, vec.size)
            if vec.size != dims[analyzer]:
                corpus.dropped[f"{analyzer}OtherDimension"] += 1
                continue
            target[pid] = vec
    if corpus.full and corpus.siglip and len(next(iter(corpus.full.values()))) != len(next(iter(corpus.siglip.values()))):
        corpus.full = {}  # the fallback mixes crop and whole-image vectors of one model - never two dimensions
        corpus.dropped["embed_siglip2_fullOtherDimension"] += 1
    return corpus


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
    full: Any
    table: IntervalTable
    sizes: list[str]
    kinds: list[str]


def train(name: str, corpus: Corpus, ids: list[str], build_x: Callable[[list[str]], np.ndarray]) -> tuple[Fitted, np.ndarray]:
    X = build_x(ids)
    y = np.array([corpus.puzzles[p].label.log_difficulty for p in ids])  # type: ignore[union-attr]
    n = np.array([corpus.puzzles[p].label.index_count for p in ids])  # type: ignore[union-attr]
    w = weights(n)
    oof, oof_sizes = cross_fit(X, y, w, kfold(len(ids)))
    full = fit(X, y, w)
    sizes = [corpus.puzzles[p].size_group for p in ids]
    kinds = [corpus.puzzles[p].kind for p in ids]
    table = IntervalTable(y - oof, n, sizes, kinds)
    return Fitted(name, ids, y, n, oof, oof_sizes, full, table, sizes, kinds), X


def validate(fitted: Fitted, X: np.ndarray, corpus: Corpus) -> dict[str, Any]:
    """The run's evaluation of the model of record (stored as the model record's `evaluation`)."""
    y, n, sizes, kinds = fitted.y, fitted.n, fitted.sizes, fitted.kinds
    w = weights(n)
    out: dict[str, Any] = {}

    t = pick_threshold(n, MIN_TEST)
    ev = n >= t
    b = fitted.table.bounds(fitted.oof, sizes, kinds)
    sel_s = [s for s, e in zip(sizes, ev) if e]
    sel_k = [k for k, e in zip(kinds, ev) if e]
    out["testMinIndices"] = t
    out["overall"] = metrics(y[ev], fitted.oof[ev], b[ev])
    out["groups"] = grouped(y[ev], fitted.oof[ev], b[ev], sel_s, sel_k)

    # brands held out: no fold model saw any puzzle of the brand it predicts
    from sklearn.model_selection import GroupKFold

    brands = np.array([corpus.puzzles[p].label.manufacturer_id or f"none:{p}" for p in fitted.train_ids])  # type: ignore[union-attr]
    n_groups = len(set(brands))
    brand_out: dict[str, Any] = {"brands": int(n_groups)}
    if n_groups >= 2:
        splits = list(GroupKFold(n_splits=min(FOLDS, n_groups)).split(X, groups=brands))
        bp, _ = cross_fit(X, y, w, splits)
        bt = IntervalTable(y - bp, n, sizes, kinds)
        bb = bt.bounds(bp, sizes, kinds)
        brand_out |= {"testMinIndices": t, "overall": metrics(y[ev], bp[ev], bb[ev]), "groups": grouped(y[ev], bp[ev], bb[ev], sel_s, sel_k)}
    out_holdouts: dict[str, Any] = {"brands": brand_out}

    # newer predicted from older (`addedAt`): its Spearman is the push guard's number
    added = [corpus.puzzles[p].label.added_at for p in fitted.train_ids]  # type: ignore[union-attr]
    dated = sorted((a, i) for i, a in enumerate(added) if a)
    newer: dict[str, Any] = {"dated": len(dated)}
    holdout = None
    if len(dated) >= MIN_TRAINING // 2:
        cut = int(len(dated) * TIME_SPLIT)
        old_idx = np.array([i for _, i in dated[:cut]])
        new_idx = np.array([i for _, i in dated[cut:]])
        tn = pick_threshold(n[new_idx], MIN_TEST)
        test = new_idx[n[new_idx] >= tn]
        if len(test) >= MIN_HOLDOUT_TEST:
            m = fit(X[old_idx], y[old_idx], w[old_idx])
            pred = m.predict(X[test])
            older_table = IntervalTable(y[old_idx] - fitted.oof[old_idx], n[old_idx], [sizes[i] for i in old_idx], [kinds[i] for i in old_idx])
            ts, tk = [sizes[i] for i in test], [kinds[i] for i in test]
            tb = older_table.bounds(pred, ts, tk)
            newer |= {"trainSize": int(len(old_idx)), "trainAddedUntil": dated[cut - 1][0], "testMinIndices": tn,
                      "overall": metrics(y[test], pred, tb), "groups": grouped(y[test], pred, tb, ts, tk)}
            holdout = newer["overall"].get("spearman")
    out_holdouts["newerFromOlder"] = newer
    out["holdouts"] = out_holdouts
    if holdout is None:  # too few dated puzzles: the cross-fitted Spearman stands in (said so in the record)
        holdout = out["overall"].get("spearman") or 0.0
        out["holdoutSource"] = "cross_fit"
    else:
        out["holdoutSource"] = "newer_from_older"
    out["holdoutSpearman"] = round(float(holdout), 4)
    return out


# the run ------------------------------------------------------------------------------------------------------------
def _clip(v: float) -> float:
    return float(min(LOG_LIMIT, max(-LOG_LIMIT, v)))


def _estimate(pid: str, puzzle: Puzzle, model: str, value: float, bounds: np.ndarray, oof: bool, size: int, at: str) -> DifficultyEstimate:
    ld = _clip(value)
    return DifficultyEstimate(puzzle_id=pid, model=model, model_version=MODEL_VERSION, log_difficulty=round(ld, 5),
                              log_p10=round(min(ld, _clip(bounds[0])), 5), log_p90=round(max(ld, _clip(bounds[3])), 5),
                              out_of_fold=oof, training_size=int(max(1, size)), fingerprint=puzzle.fingerprint, computed_at=at)


def estimates_for(fitted: Fitted, corpus: Corpus, others: list[str], build_x: Callable[[list[str]], np.ndarray], at: str) -> list[DifficultyEstimate]:
    """Out-of-fold for the training rows, the full model for `others` (labelled 1-2 indices → out of fold too)."""
    out = []
    b = fitted.table.bounds(fitted.oof, fitted.sizes, fitted.kinds)
    for i, pid in enumerate(fitted.train_ids):
        out.append(_estimate(pid, corpus.puzzles[pid], fitted.model_name, fitted.oof[i], b[i], True, fitted.oof_sizes[i], at))
    if others:
        pred = predict_chunks(fitted.full, build_x, others)
        ob = fitted.table.bounds(pred, [corpus.puzzles[p].size_group for p in others], [corpus.puzzles[p].kind for p in others])
        for i, pid in enumerate(others):
            unseen_label = corpus.puzzles[pid].label is not None
            out.append(_estimate(pid, corpus.puzzles[pid], fitted.model_name, pred[i], ob[i], unseen_label, len(fitted.train_ids), at))
    return out


def _write_report(report_dir: Path | None, report: dict[str, Any]) -> None:
    if report_dir is None:
        return
    try:
        report_dir.mkdir(parents=True, exist_ok=True)
        (report_dir / "difficulty_prior-last-run.json").write_text(json.dumps(report, indent=1, default=str))
    except OSError as e:
        log.warning("[difficulty_prior] could not write the run report: %s", e)


def run(api: Any, *, meta: dict[str, Any] | None = None, rows: Callable[[str], Iterable[dict[str, Any]]] | None = None,
        force_model: bool = False, dry_run: bool = False, report_dir: Path | None = None,
        now: Callable[[], datetime] | None = None) -> dict[str, Any]:
    """One run: labels + embeddings → models → validation → guard → record → estimates. Returns the summary."""
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

    ids = sorted(corpus.puzzles)
    labelled_ids = [p for p in ids if corpus.puzzles[p].label is not None]
    trainable = [p for p in labelled_ids if corpus.puzzles[p].label.index_count >= MIN_TRAIN_INDICES]  # type: ignore[union-attr]
    primary_train = [p for p in trainable if corpus.has_primary(p)]
    summary: dict[str, Any] = {
        "version": VERSION, "labels": len(labels), "labelledWithPicture": len(labelled_ids), "puzzles": len(ids),
        "withPrimaryFeatures": sum(corpus.has_primary(p) for p in ids),
        "withFallbackOnly": sum(corpus.has_fallback(p) and not corpus.has_primary(p) for p in ids),
        "trainingSize": len(primary_train), "dropped": dict(corpus.dropped),
    }
    if len(primary_train) < MIN_TRAINING:
        summary["skipped"] = (f"only {len(primary_train)} labelled puzzles (>= {MIN_TRAIN_INDICES} indices) have both crop "
                              f"embeddings - the model needs {MIN_TRAINING}. Nothing recorded, nothing pushed.")
        log.info("[difficulty_prior] %s", summary["skipped"])
        summary["seconds"] = round(time.monotonic() - started, 1)
        return summary

    primary, X = train(MODEL, corpus, primary_train, corpus.primary_x)
    evaluation = validate(primary, X, corpus)
    del X
    in_training = set(primary_train)
    primary_others = [p for p in ids if corpus.has_primary(p) and p not in in_training]
    estimates = estimates_for(primary, corpus, primary_others, corpus.primary_x, at)

    fallback_ids = [p for p in ids if corpus.has_fallback(p) and not corpus.has_primary(p)]
    fallback_train = [p for p in trainable if corpus.has_fallback(p)]
    fallback_eval: dict[str, Any] = {"model": FALLBACK_MODEL, "puzzles": len(fallback_ids), "trainingSize": len(fallback_train)}
    if fallback_ids and len(fallback_train) >= MIN_TRAINING:
        fb, Xf = train(FALLBACK_MODEL, corpus, fallback_train, corpus.fallback_x)
        del Xf
        t = pick_threshold(fb.n, MIN_TEST)
        ev = fb.n >= t
        bounds = fb.table.bounds(fb.oof, fb.sizes, fb.kinds)
        fallback_eval |= {"alpha": chosen_alpha(fb.full), "testMinIndices": t, "overall": metrics(fb.y[ev], fb.oof[ev], bounds[ev]),
                          "intervals": fb.table.describe()}
        # its own training rows that lack the crop embeddings get its out-of-fold estimate; the rest its full model
        own = set(fallback_ids)
        oof_rows = [i for i, p in enumerate(fb.train_ids) if p in own]
        b = fb.table.bounds(fb.oof, fb.sizes, fb.kinds)
        for i in oof_rows:
            p = fb.train_ids[i]
            estimates.append(_estimate(p, corpus.puzzles[p], FALLBACK_MODEL, fb.oof[i], b[i], True, fb.oof_sizes[i], at))
        fb_training = set(fb.train_ids)
        rest = [p for p in fallback_ids if p not in fb_training]
        if rest:
            pred = predict_chunks(fb.full, corpus.fallback_x, rest)
            rb = fb.table.bounds(pred, [corpus.puzzles[p].size_group for p in rest], [corpus.puzzles[p].kind for p in rest])
            for i, p in enumerate(rest):
                estimates.append(_estimate(p, corpus.puzzles[p], FALLBACK_MODEL, pred[i], rb[i], corpus.puzzles[p].label is not None, len(fb.train_ids), at))
    elif fallback_ids:
        fallback_eval["skipped"] = f"fewer than {MIN_TRAINING} training puzzles - these puzzles get no estimate tonight"

    holdout = float(evaluation["holdoutSpearman"])
    previous = api.current_difficulty_model(MODEL)
    prev_hs = float(previous["holdoutSpearman"]) if previous and previous.get("holdoutSpearman") is not None else None
    regressed = prev_hs is not None and holdout < prev_hs - GUARD_DROP
    record_eval = {
        "version": VERSION, "features": "siglip2 crop 1152 + dinov2 crop 1024 + ln pieces, ln pieces^2, size group one-hot; standardised",
        "alpha": chosen_alpha(primary.full), "alphaAtGridEnd": chosen_alpha(primary.full) in (float(ALPHAS[0]), float(ALPHAS[-1])), "seed": SEED, "folds": FOLDS, "trainMinIndices": MIN_TRAIN_INDICES,
        "weights": "n/(n+10)", **evaluation, "intervals": primary.table.describe(), "fallback": fallback_eval,
        "estimates": {"total": len(estimates), "outOfFold": sum(e.out_of_fold for e in estimates)},
        "guard": {"previousHoldoutSpearman": prev_hs, "previousModelVersion": (previous or {}).get("modelVersion"),
                  "maxDrop": GUARD_DROP, "forced": bool(force_model and regressed)},
    }
    summary |= {"holdoutSpearman": holdout, "previousHoldoutSpearman": prev_hs, "overall": evaluation["overall"],
                "estimates": len(estimates), "outOfFold": record_eval["estimates"]["outOfFold"], "fallback": len(fallback_ids)}
    report = {"computedAt": at, "summary": summary, "evaluation": record_eval}

    if regressed and not force_model:
        summary["blocked"] = (f"holdout Spearman {holdout:.3f} is more than {GUARD_DROP} below the current model's {prev_hs:.3f} - "
                              "nothing recorded, nothing pushed. Read difficulty_prior-last-run.json; a human may override "
                              "with `derive --only difficulty_prior --force-model`.")
        log.warning("[difficulty_prior] PUSH BLOCKED: %s", summary["blocked"])
        _write_report(report_dir, report)
        summary["seconds"] = round(time.monotonic() - started, 1)
        return summary
    if regressed:
        log.warning("[difficulty_prior] push guard overridden by --force-model (%.3f vs previous %.3f)", holdout, prev_hs)
    if dry_run:
        summary["dryRun"] = "nothing recorded, nothing pushed"
        _write_report(report_dir, report)
        summary["seconds"] = round(time.monotonic() - started, 1)
        return summary

    record = api.record_difficulty_model(DifficultyModelRecord(MODEL, MODEL_VERSION, len(primary_train), holdout, record_eval))
    summary["recorded"] = record.get("id")
    counts: Counter = Counter()
    for o in api.store_difficulty_estimates(estimates):
        counts[o.status] += 1
        if o.status == "invalid":
            log.warning("[difficulty_prior] estimate refused as invalid for %s: %s", o.puzzle_id, o.errors or o.message)
    summary["pushed"] = {k: counts.get(k, 0) for k in ("stored", "stale_fingerprint", "hidden", "unknown_puzzle", "invalid")}
    log.info("[difficulty_prior] recorded %s (holdout Spearman %.3f), estimates %s", summary["recorded"], holdout, summary["pushed"])
    _write_report(report_dir, report)
    summary["seconds"] = round(time.monotonic() - started, 1)
    return summary
