"""`difficulty_prior` - Phase 5 (implementation-plan.md §5.3). A DOCUMENTED STUB: it refuses to run until the Phase 5
endpoints exist and the step is implemented.

Contract for Phase 5 (do not change without updating the plan):
- Labels: `GET /internal-api/puzzle-difficulty-labels?limit&after` - per puzzle `puzzleId`, `logDifficulty` (as-of,
  Phase 3), `indexCount`, `piecesCount`, `manufacturerId`, `addedAt`. Aggregates only; secret puzzles excluded.
- Features: SigLIP 2 crop embedding (`embed_siglip2`, else `embed_siglip2_full`) + DINOv2 (`embed_dinov2`) + log pieces,
  read through the bulk endpoint (`onlyCurrent=1`).
- Model: ridge regression; label = as-of log difficulty. Train on puzzles with >= 3 solves, weighted n/(n+10); test on
  puzzles with >= 20 solves. 5-fold **cross-fitted out-of-fold** estimates for labelled puzzles, the full model for
  unlabelled ones. Intervals (log_p10 / log_p90) from residual quantiles per (piece-count group × artwork kind).
- Validation per run: brands held out; newer puzzles predicted from older ones; per size group and per artwork kind
  (flat / box / assembled). **A push is blocked** when the holdout Spearman drops > 0.05 against the previous model.
- Push: `PUT /internal-api/puzzle-difficulty-estimates` in batches of <= 500 items {puzzleId, model, modelVersion,
  logDifficulty, logP10, logP90, outOfFold, trainingSize, imageFingerprint}; refused for hidden puzzles and stale
  fingerprints. Then `POST /internal-api/puzzle-difficulty-estimates/model` with the evaluation (the gate record input).
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
