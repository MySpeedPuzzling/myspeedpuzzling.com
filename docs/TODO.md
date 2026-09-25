# TODO

Open follow-ups, one place to come back to. Tick an item when it ships, delete a section when it is empty.
Feature-sized plans keep their own checklist in `docs/features/<feature>/` - this file is for the loose ends
that would otherwise be forgotten. Newest section on top.

## Puzzle approvals

Shipped 2026-09-25. Design: [`features/puzzle-approvals.md`](features/puzzle-approvals.md).

- [ ] Tell the adder when their puzzle is approved or merged (notification type + `GetNotifications` branch)
- [ ] Count approvals in `GetModerators` (reads `reviewed_by_id` today; `puzzle_moderation_decision` has everything)
- [ ] A read-only history page over `puzzle_moderation_decision` (who decided what, filter by player)
- [ ] 196 unapproved brands on production, 158 of them held as merge candidates (`/root/brand-merge-proposal-2026-09-17.json` on the box) - the queue now handles them one puzzle at a time

## Image storage (bucket audit 2026-09-22)

Full scan of the bucket against every DB image column; fixes shipped in lily.srv (imgproxy source limit 30 → 60 MP,
images-cache re-resolves imgproxy on Docker DNS). Lists in `~/Downloads/msp-image-audit-2026-09-22/` on Jan's Mac,
raw scan on the box in `/root/bucket-scan.csv` + `/root/db-image-refs.tsv`.

- [ ] Result share images (`players/<id>/results/<id>.png`, 800×800): 490k objects ≈ 420 GB, one per solving time, never
      pruned, regenerated on demand by `GetResultImage` - a lifecycle rule or a prune cron (e.g. older than 30 days)
- [ ] Handlers never delete the previous object when a puzzle image / finished photo / logo is replaced or the time is
      deleted - 6,862 orphans ≈ 16 GB today (`3-orphaned-objects-not-referenced.csv`); delete the old key in the handler
- [ ] One-off: delete the existing orphans after a spot check
- [ ] Optional: downscale the 84 pre-`ImageOptimizer` originals above 30 MP to 2,000 px like today's uploads
      (`2-oversized-originals-over-30mp.csv`); not needed for serving since the limit covers them

## Multiscan

Shipped 2026-09-22. Design and plan: [`features/multiscan/README.md`](features/multiscan/README.md).

- [ ] Aggregated lending notification ("Anna lent you 6 puzzles") instead of one per puzzle
- [ ] Tray persistence across a reload (`sessionStorage` mirror + `restore()` action)
- [ ] Native apps: a multi-mode scanner in the iOS/Android shells (today the single-shot native scanner is re-opened per code)
- [ ] More actions: mark solved without a time (shared date), list for swap/free, remove from library
- [ ] "Undo last batch" for lend/return
- [ ] Watch the numbers after a few weeks: not-found rate, links created (`puzzle_change_request` rows with `proposed_ean` only), quick-adds

## Getting started / newcomer onboarding

Shipped 2026-09-20 (`1f59d530`). Design, research and rules: [`features/getting-started-guide.md`](features/getting-started-guide.md).
Mirrored in [#212](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/212).

**Not built yet**

- [ ] First-time celebration: after the very first saved time, a small "Nice!" moment (reuse the picker's confetti) with
      links to *My statistics*, *Leaderboard* and *Finish my profile* - the best moment to introduce those features
- [ ] Empty states with one sentence + one button on the owner's own empty profile, collections and wishlist
      (only empty statistics has one today); link to the guide's anchors `#track`, `#library`
- [ ] Welcome e-mail with the same first steps + the guide link (today only the verification mail goes out)
- [ ] Edit profile: basic form (name, photo, country…) to the top, developer cards (tokens, applications) folded at the bottom
- [ ] `membership.full_description` is out of date: no Insights / picker filters, "coming soon" items that shipped

**Verify / decide**

- [ ] One real registration on production end to end: name → welcome screen → Hub card at "1 of 5" → guide, click
      *My statistics* and check the step gets its tick (the `mark-seen` beacon was never clicked in a real browser)
- [ ] Native-speaker read of the es / fr / de / ja texts (`onboarding.*`), Japanese first
- [ ] Should Statistics / Leaderboard ticks also count visits through the menu, not only a click from the guide?
      (costs a check on every view of those pages)
- [ ] Remove the NEW badges on "Getting started" and "Pairs & Teams" when they stop being new (no expiry built in)

**Measure**

- [ ] Compare activation (share of registrations with a solving time within 7 days) before vs after 2026-09-20 -
      SQL in the feature doc. If people stall at the fifth checklist step, drop "Add a favorite puzzler"
