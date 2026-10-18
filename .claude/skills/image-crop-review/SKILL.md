---
name: image-crop-review
description: Review the image pipeline's artwork crop proposals (change requests with source image_pipeline, tag artwork_crop) - earn the auto-approval threshold from human decisions (Wilson lower bound >= 99 %, nothing below ~300 human decisions), auto-approve the band above it through the internal API, keep a 5 % audit for humans, stop below 98 %, optionally check the middle band by eye. Use when the user says "/image-crop-review", "review the crop proposals", or daily during/after the backfill.
argument-hint: "[--apply] [--look]"
allowed-tools: Bash, Read
---

# Artwork crop review

Plan §2.5, runbook §8. **The arithmetic is done by the tool, never by hand.** From `tools/puzzle-vision/`, profile
`m3-max` (or the machine's profile - only the API is used). Token handling: the pipeline reads it from the Keychain;
never print it.

## 1. Plan (dry run)

```sh
uv run puzzle-vision crop-review-plan --profile m3-max            # summary
uv run puzzle-vision crop-review-plan --profile m3-max --json     # to_approve, audit_held, middle_band with URLs, bands
```

It reads every pipeline crop request (pending and decided) and applies the rules:
- **Earned threshold:** fewer than 300 human decisions → nothing is auto-approved (it says "threshold not earned yet").
  Human = approved / rejected by a moderator in the web review (`decision.source == "admin_ui"`). Decisions through the
  internal API (`auto:` notes from this plan, `claude-review:` notes from you, anything scripted) and `outdated`
  closures never count toward the 300 or the precision. A list item missing a field the plan relies on stops it with a
  contract error - report it, do not work around it.
- A crop is **right only when it was taken**: approved with `decision.image == "proposed"`. Approved while keeping the
  old picture or uploading another (`keep` / `upload`) counts as wrong, like a rejection.
- **Per crop model version:** only decisions on the current `modelVersion` (the newest proposal's; `--model-version`
  to pick another) count - a new crop model earns its threshold anew.
- Then the auto band starts at the lowest confidence where the human precision of all decisions at or above it has a
  **Wilson lower bound ≥ 99 %** (≥ 50 decisions).
- **Audit:** a deterministic 5 % of would-be-auto items stays pending for moderators (`audit_held`).
- **Auto-stop:** human precision on the most recent 200 decided audit items < 98 % (≥ 20 of them) → `AUTO-APPROVAL STOPPED`, exit code 2,
  nothing is approved. **Report that to Jan immediately** and do not approve anything this run.

## 2. Apply the auto band

Only when the plan says the threshold is earned and not stopped:

```sh
uv run puzzle-vision crop-review-plan --profile m3-max --apply
```

Each approval carries `selectedFields: ["image"]` and the decisionNote the tool built
(`auto: confidence 0.987, band >= 0.980 (human precision Wilson LB 0.991, n 412), model artwork-crop 1.0.0 (…)`).
A `409` (decided meanwhile, `image_changed` = a crop of an older picture, `original_not_archived` = try again later) is
listed under `applyFailed` - fine, skip it; an `image_changed` one is left for moderators to reject.

## 3. Middle band (optional, `--look`)

`middle_band` = pending items between 0.6 and the auto threshold (all pending ≥ 0.6 before it is earned). Look at
some of them: Read `originalImageUrl` and `proposedImageUrl` (images). Approve **only when certain** that the
proposal is the complete printed picture - no box edges, table, title strip outside the picture, nothing cut off -
and better than the original:

```sh
uv run puzzle-vision crop-review-approve --profile m3-max <changeRequestId> [<id> …]
```

(note `claude-review: …`, decided through the internal API - these never count as human decisions). The command
refuses audit-sample ids and anything outside the middle band, approves at most **20 a day** while the threshold is
not earned, and skips a `409` (decided meanwhile). Anything unsure stays for moderators. Never reject:
rejections are the moderators' (they teach the threshold).

## 4. Summary

Print: human decisions so far (approved / rejected), earned or not and the threshold with its Wilson bound, audit
precision, counts per confidence band (the plan's `bands`), how many were auto-approved now, how many you approved
after a look, how many wait for moderators (review grid: `/admin/puzzle-change-requests`, filter source image
pipeline / tag artwork_crop).
