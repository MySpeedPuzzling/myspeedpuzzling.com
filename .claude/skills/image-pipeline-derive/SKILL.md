---
name: image-pipeline-derive
description: Recompute the derived image analyses (box_text, zeroshot, neighbours, tags) from the stored raw results and push the changed rows, then train the difficulty_prior ridge model and push picture-based difficulty estimates - no image touched. Use when the user says "/image-pipeline-derive", "recompute the tags", "refresh neighbours", after a taxonomy / prompt bank change, or when the backfill is complete.
argument-hint: "[--only box_text,zeroshot,neighbours,tags,difficulty_prior] [--dry-run] [--force-model]"
allowed-tools: Bash, Read
---

# Image pipeline - derive

Runbook §7. Runs on the M3 Max (`--profile m3-max`), from `tools/puzzle-vision/`:

```sh
uv run --extra ml puzzle-vision derive --profile m3-max --dry-run     # counts: computed / changed - nothing pushed
uv run --extra ml puzzle-vision derive --profile m3-max               # all five, pushes only changed rows
uv run --extra ml puzzle-vision derive --profile m3-max --only tags
```

- Inputs come from the bulk endpoint (current pictures only); outputs are pushed in batches of ≤ 200 with the
  derived versions of `profiles/m3-max.toml` (`…@tax1.0.0`).
- `zeroshot` loads the SigLIP 2 text encoder once (model lock; ≥ 40 % free memory) - do not run it while `describe`
  holds the lock; it waits. Prompt features are cached per prompt bank.
- `difficulty_prior` runs last (runbook §7 "difficulty_prior"): ridge on the SigLIP 2 + DINOv2 crop embeddings + pieces
  → as-of log difficulty, out-of-fold estimates for labelled puzzles, a model record with its validation, then the
  estimates. It needs no model (`uv run puzzle-vision derive --profile m3-max --only difficulty_prior` works without
  `--extra ml`). Its summary: `skipped` (< 200 labelled puzzles with crop embeddings - normal early in the backfill),
  `blocked` (exit 3: the holdout Spearman fell > 0.05 below the current record - nothing recorded or pushed), else
  `holdoutSpearman`, `overall`, `pushed` counts. **Never pass `--force-model` on your own**: report the blocked run
  with both numbers and `~/Library/Application Support/puzzle-vision/difficulty_prior-last-run.json`
  (`evaluation.holdouts`, `evaluation.groups`) and let Jan decide.
- The first full run after the backfill: `--only difficulty_prior --dry-run` first, read the run report, then the real
  run; report `evaluation.overall`, `holdouts.newerFromOlder`, `holdouts.brands` and every group with ≥ 50 puzzles.
- After a taxonomy or prompt-bank change: bump the `zeroshot` / `tags` versions in **both** profiles first
  (`/image-pipeline-add-analyzer`), then derive.

Report the JSON summary (per analyzer: computed, changed, stored, stale_fingerprint, not_found, invalid, seconds;
for difficulty_prior: trainingSize, holdoutSpearman, previousHoldoutSpearman, pushed, skipped / blocked).
`invalid` > 0 is a bug - read the errors before re-running.
