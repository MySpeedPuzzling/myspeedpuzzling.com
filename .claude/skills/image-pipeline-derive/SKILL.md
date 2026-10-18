---
name: image-pipeline-derive
description: Recompute the derived image analyses (box_text, zeroshot, neighbours, tags; difficulty_prior from Phase 5) from the stored raw results and push the changed rows - no image touched. Use when the user says "/image-pipeline-derive", "recompute the tags", "refresh neighbours", after a taxonomy / prompt bank change, or when the backfill is complete.
argument-hint: "[--only box_text,zeroshot,neighbours,tags,difficulty_prior] [--dry-run]"
allowed-tools: Bash, Read
---

# Image pipeline - derive

Runbook §7. Runs on the M3 Max (`--profile m3-max`), from `tools/puzzle-vision/`:

```sh
uv run --extra ml puzzle-vision derive --profile m3-max --dry-run     # counts: computed / changed - nothing pushed
uv run --extra ml puzzle-vision derive --profile m3-max               # all four, pushes only changed rows
uv run --extra ml puzzle-vision derive --profile m3-max --only tags
```

- Inputs come from the bulk endpoint (current pictures only); outputs are pushed in batches of ≤ 200 with the
  derived versions of `profiles/m3-max.toml` (`…@tax1.0.0`).
- `zeroshot` loads the SigLIP 2 text encoder once (model lock; ≥ 40 % free memory) - do not run it while `describe`
  holds the lock; it waits. Prompt features are cached per prompt bank.
- `difficulty_prior` is a stub until Phase 5: `--only difficulty_prior` reports "not deployed yet" while
  `/internal-api/puzzle-difficulty-labels` does not exist. Do not implement it here - Phase 5 does (plan §5.3).
- After a taxonomy or prompt-bank change: bump the `zeroshot` / `tags` versions in **both** profiles first
  (`/image-pipeline-add-analyzer`), then derive.

Report the JSON summary (per analyzer: computed, changed, stored, stale_fingerprint, not_found, invalid, seconds).
`invalid` > 0 is a bug - read the errors before re-running.
