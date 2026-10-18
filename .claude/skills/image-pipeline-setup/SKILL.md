---
name: image-pipeline-setup
description: Prepare this Mac to run the MySpeedPuzzling image pipeline (tools/puzzle-vision) for a machine profile - uv environment with the model stack, model downloads, the internal API token in the Keychain (Jan pastes it himself), setup-check, and the launchd job for daily mode (loaded only when asked). Use when the user says "set up the image pipeline", "/image-pipeline-setup m3-max", "prepare the M1 Air for the backfill" or setup-check fails.
argument-hint: "<m3-max|m1-air>"
allowed-tools: Bash, Read
---

# Image pipeline - setup

Runbook: `docs/features/insights/image-pipeline.md` §1-§3 (read it first). Profile = the argument (`m3-max` on the
MacBook Pro M3 Max, `m1-air` on the MacBook Air M1). If it is missing, ask which machine this is.

## Rules

- **Never print, echo, log or write the token.** Jan pastes it into the `security` prompt himself. Never pass it on
  a command line you run.
- Check `memory_pressure | tail -1` before downloads; never load a model here (setup-check only looks into the cache).
- All commands run from `tools/puzzle-vision/` of this checkout.

## Steps

1. `git pull` (on `main`); confirm `tools/puzzle-vision/` and the runbook exist - otherwise stop and tell Jan (Phase 2
   is not merged yet).
2. `brew list uv >/dev/null || brew install uv`; free disk: `df -h ~` - need ≥ 60 GB.
3. `cd tools/puzzle-vision && uv sync --extra ml` (pinned `uv.lock`, Python 3.12).
4. Token: run `uv run puzzle-vision setup-check --profile <p> --skip-api` and look at the `token` line. If it FAILs,
   ask Jan to run in his terminal (he pastes the token at the prompt):
   `security add-generic-password -U -s msp-internal-api -a "$USER" -w`
   Wait for him to confirm; never ask him to paste it into the chat.
5. Models - only the ones the profile needs (runbook §2 table; setup-check lists the missing ones with sizes):
   - m3-max: `google/siglip2-so400m-patch14-384`, `mlx-community/Qwen3-VL-4B-Instruct-4bit`, `mlx-community/Qwen3.5-9B-MLX-4bit` (~13.6 GB)
   - m1-air: `google/siglip2-so400m-patch14-384`, `facebook/dinov2-large`, `tue-mps/coco_panoptic_eomt_large_640` (~8 GB);
     `mlx-community/Qwen3.5-9B-MLX-4bit` only later, before it joins `describe`
   `uv run --extra ml hf download <repo>` for each missing one (one at a time; report sizes).
6. `uv run --extra ml puzzle-vision setup-check --profile <p>` - every line must PASS (python, uv, disk, ml extra,
   models, token, API 200, memory). Report the output (it never contains the token).
7. launchd (daily mode after the backfill) - **only when Jan asks or the backfill is complete**:
   `uv run puzzle-vision launchd-plist --profile <p> --install`, then show him
   `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.myspeedpuzzling.puzzle-vision.<p>.plist`
   and run it only on his yes. m1-air = KeepAlive nonstop (`run --follow`); m3-max = nightly window (`run --daily --hours 6`).
8. Tell Jan the next step: `/image-pipeline-run`.
