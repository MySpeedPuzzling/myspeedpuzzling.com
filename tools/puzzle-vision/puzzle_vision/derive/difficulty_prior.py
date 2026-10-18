"""`difficulty_prior` - Phase 5 (implementation-plan.md §5.3). A DOCUMENTED STUB: the server side is built (stream 5b),
the step itself is stream 5c - until then it refuses to run.

Contract (docs/features/internal-api.md § "Puzzle difficulty labels and estimates"; do not change without updating
the plan). The client methods exist in `puzzle_vision/api.py`, the fake server in `tests/conftest.py`:
- Labels: `api.iter_difficulty_labels()` (`GET /internal-api/puzzle-difficulty-labels?limit=..5000&after=`) - per
  puzzle `puzzleId`, `logDifficulty`, `labelSource`, `meanLogIndex`, `indexCount`, `piecesCount`, `manufacturerId`,
  `addedAt`, `imageFingerprint`. `logDifficulty` = ln of the as-of difficulty: ln(median index) where the app keeps
  one (>= 5 indices, "median"), else the mean ln index (1-4, "mean_log"). Aggregates only; secret puzzles excluded.
  Join to embeddings on (puzzleId, imageFingerprint) - a label whose fingerprint differs from the embedding's (or is
  null) has no usable picture.
- Features: SigLIP 2 crop embedding (`embed_siglip2`, else `embed_siglip2_full`) + DINOv2 (`embed_dinov2`) + log pieces,
  read through the bulk endpoint (`onlyCurrent=1`).
- Model: ridge regression; label = `logDifficulty`. Train on puzzles with `indexCount` >= 3, weighted n/(n+10); test on
  puzzles with >= 20. 5-fold **cross-fitted out-of-fold** estimates for labelled puzzles, the full model for
  unlabelled ones. Intervals (log_p10 / log_p90) from residual quantiles per (piece-count group × artwork kind).
- Validation per run: brands held out; newer puzzles predicted from older ones (`addedAt`); per size group and per
  artwork kind (flat / box / assembled). **A push is blocked** when the holdout Spearman drops > 0.05 against
  `api.current_difficulty_model(model)["holdoutSpearman"]` (None = the first run, nothing to compare).
- Push order: `api.record_difficulty_model(DifficultyModelRecord(model, modelVersion, trainingSize, holdoutSpearman,
  evaluation))` (the evaluation: overall, groups per size group × image kind, holdouts), then
  `api.store_difficulty_estimates([DifficultyEstimate(...)])` - `PUT /internal-api/puzzle-difficulty-estimates` in
  batches of <= 500 {puzzleId, model, modelVersion, logDifficulty, logP10, logP90, outOfFold, trainingSize,
  imageFingerprint, computedAt?}, log values within ±5, logP10 <= logDifficulty <= logP90. Outcomes per item: stored /
  stale_fingerprint / hidden / unknown_puzzle / invalid - only `invalid` is our bug. Idempotent upsert (current value).
- Runs nightly on the M3 after the daily pipeline; during the backfill on whatever is embedded so far. No fine-tuning,
  no language models for numbers (prediction-research.md §3).
"""

from __future__ import annotations

from typing import Any

LABELS_PATH = "/internal-api/puzzle-difficulty-labels"
ESTIMATES_PATH = "/internal-api/puzzle-difficulty-estimates"


class NotAvailableYet(RuntimeError):
    pass


def run(api: Any, **_: Any) -> None:
    if not api.endpoint_exists(LABELS_PATH):
        raise NotAvailableYet(
            "difficulty_prior needs the Phase 5 endpoints (GET /internal-api/puzzle-difficulty-labels, "
            "PUT /internal-api/puzzle-difficulty-estimates) - not deployed yet. Nothing was computed or pushed."
        )
    raise NotAvailableYet(
        "the Phase 5 endpoints exist, but the difficulty_prior step is still a stub - implement it per the contract in "
        "tools/puzzle-vision/puzzle_vision/derive/difficulty_prior.py (implementation-plan.md §5.3) first."
    )
