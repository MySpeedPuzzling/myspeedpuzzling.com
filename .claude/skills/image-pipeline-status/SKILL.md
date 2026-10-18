---
name: image-pipeline-status
description: Report the image pipeline's coverage per analyzer and version from the production API (done / stale / missing / waiting / claimed), throughput per machine, failures and ETA. Use when the user says "/image-pipeline-status", "how far is the backfill", "image pipeline progress", "what is left to analyse".
argument-hint: "[--profile m3-max|m1-air] [--all]"
allowed-tools: Bash, Read
---

# Image pipeline - status

Read-only. From `tools/puzzle-vision/` (light environment is enough, no models):

```sh
uv run puzzle-vision status --profile <p> --all          # table: every analyzer of both machines
uv run puzzle-vision status --profile <p> --all --json   # details: claimedBy, received counts, recent failures
```

Columns: `done` (current picture, profile version) · `%` · `stale` (another version or another picture) · `missing`
· `waiting` (missing, but its `requires` - e.g. artwork / ocr - is not there yet) · `claimed` (leases held now; JSON
`claimedBy` names the worker = `<hostname>-<profile>`) · `/h (6h)` results per hour over the last 6 h from **all**
machines · `ETA h`. "this machine" = stored here in the last hour / 24 h, local failures, image cache size.

Report briefly, per machine and analyzer: percent done, rate, ETA; anything stuck (no results received in 6 h while
work remains and nothing is `waiting`), failures with their errors (`--json` → `thisMachine.recentFailures`). Backfill
complete = every raw analyzer of both profiles at 100 % (minus pictures that failed 3 times - list them) → then
`/image-pipeline-derive` and tell Jan. Interpretation help: runbook `docs/features/insights/image-pipeline.md` §6, §10.
