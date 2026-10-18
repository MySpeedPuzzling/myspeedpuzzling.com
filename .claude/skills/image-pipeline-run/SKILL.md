---
name: image-pipeline-run
description: Start or resume this Mac's image pipeline profile (tools/puzzle-vision) - backfill until done, a time-capped run, one phase, or daily mode - and report progress. Use when the user says "/image-pipeline-run", "start the backfill", "run the image pipeline for 6 hours", "resume the pipeline", "stop the pipeline".
argument-hint: "[--profile m3-max|m1-air] [--hours N] [--phase X] [--daily|--follow]"
allowed-tools: Bash, Read
---

# Image pipeline - run

Runbook: `docs/features/insights/image-pipeline.md` §3-§5. Commands from `tools/puzzle-vision/`. The profile is the
machine (`m3-max` / `m1-air`); if not given, ask or read `$PUZZLE_VISION_PROFILE`.

## Before starting

1. `uv run --extra ml puzzle-vision setup-check --profile <p>` must pass (else `/image-pipeline-setup`).
2. `memory_pressure | tail -1` ≥ 40 % free and nothing else holding a large model (`ls -la /tmp/msp-model-lock`;
   never delete someone else's lock).
3. m1-air `describe` phase only runs after `uv run --extra ml puzzle-vision verify --profile m1-air --describe-parity`
   passed (the runner skips it otherwise) - run the parity check only after phase B is done.

## Start

Run in the background so the session stays usable, with the log as the progress record:

```sh
cd tools/puzzle-vision
nohup uv run --extra ml puzzle-vision run --profile <p> [--hours N] [--phase X] > ~/Library/Logs/puzzle-vision/run-$(date +%Y%m%d-%H%M).log 2>&1 &
# the run writes its own (python) pid - `$!` would be uv's
cat "$HOME/Library/Application Support/puzzle-vision/run-<p>.pid"
```

- Backfill (default): every phase until nothing is left; it sleeps and retries while it waits on the other Mac
  (`describe` on OCR, phase B on artwork). m3-max: `artwork` (~24 h) then `describe` (~5 days); m1-air: A + B (~2.4 days).
- `--hours N` when Jan needs the machine; `--daily` for one pass + derive + crop proposals; `--follow` never ends.
- Expected speeds (M3, idle): artwork ~2 s/puzzle, describe 10.75 s/img; 20-50 % slower with other GPU work.

## Progress

Every few hours: `tail -n 40` of the run log and `/image-pipeline-status`. Report briefly: stored per stage, s/img,
ETA, failures. `stale_fingerprint` / `not_found` are normal (the picture changed / puzzle gone). Repeated failures of
one stage: read the log; fix only a clear pipeline bug (on a branch with a PR, tell Jan), otherwise report.

## Stop

`kill $(cat "$HOME/Library/Application Support/puzzle-vision/run-<p>.pid")` (SIGTERM to the python run, not uv) -
every stage stores what it finished and releases its claims; the run exits 143 (124 when `--hours` ran out) and a
`--daily` run then skips derive and crop proposals. Confirm in the log ("released"). Resume = start again; production
is the truth.
