# Competition toolkit - delivery status (living record)

Started 2026-11-17 from Jan's request (`00-context.md`). Designs: `A-round-formats.md` (multi-puzzle rounds, sum
standings, brackets, reveal on stopwatch start), `B-qr-competitor-page.md` (QR modes, competitor page, time proposals),
`C-judge-page.md` (judge live entry: offline shell, speed, puzzle choice, proposals), `D-displays-overlays.md` (timer
screens + OBS overlays with an editor). **`R-review-and-plan.md` is binding where the four disagree** (§1 contracts,
§2 safety fixes, §3 cuts to v2, §4 packages and lanes).

## How it is delivered
- Integration branch `competition-toolkit` (worktree `.claude/worktrees/ct-integrate`), not main, until hardening (H)
  is done and Jan says go. Every new behaviour is opt-in per competition; organiser-facing settings behind
  `COMPETITION_TOOLKIT_FOR_ORGANISERS` (admins always).
- One agent per package in its own worktree (`.claude/worktrees/ct-<pkg>`), own DB `speedpuzzling_ct<pkg>_test` via
  `.claude/worktrees/cm-delivery/run.sh` / `heavy.sh`; at most 3 at once (lanes A, B, C of R §4.3).
- Orchestrator reviews each diff (+ a review agent on big packages), cherry-picks onto the branch, runs gates.

## Decisions taken with defaults (for Jan to confirm - R §5)
1. "Every puzzle" = pick K of N with K = N ("Solve all 3" / "Pick 2 of 3" / "One result for the round").
2. Toolkit surfaces (desk included) show puzzle labels, not names, before the public reveal - even to organisers.
3. Proposals only from a participant linked before the round's start; a late proposal defaults to the receipt time;
   no confirming from a list without opening the entry.
4. Deferred to v2: R §3.

## Packages
| Pkg | Lane | State | Commit(s) on branch | Notes |
|---|---|---|---|---|
| F0 | A | merged | 17a4fefc4..0ab0e5422 | flag+voter, hub, RoundPuzzlesVisibleNow, golden pins |
| A1 | A | merged, reviewed: no early-reveal path; fixes queued as A1F | d8f21b453..b15c8ad40 | reveal mode `stopwatch`, RoundStopwatchReveal, checkbox unticked; NOT browser-tried yet (checkpoint 1); PluralMessagesTest OOM in paratest workers (H) |
| A2 | A | merged | a4a63e971..cc79c3704 | puzzle_format total/pick K, position+label (computed on read), guards, puzzleFormat via GetRoundResultsOverview (pick only). Duplicate copies rounds without puzzles => total |
| A1F | A | started | | delete round/remove puzzle during ticked countdown keeps old hide_until (cm-delivery/review-A1.md) |
| C2 | B | merged | f10ef1cef..84443b7b7 | lean layout, `live_entry` entry, fixed SW install precache (was a no-op: duplicate runtime.js). **On merge to main: restart js-watch.** Native-app (Hotwire Native) + precache scope -> C3 |
| C3 | B | merged | 2b9676263, 704d05ac4 | shell route (stateless firewall), msp_live_player cookie, precache skips live_entry*, native-app Turbo bootstrap `live_entry_native` (verify on real devices in H) |
| C4 | B | merged | 62049185d, 90cb5992c | snapshots (IndexedDB msp-live-entry), drafts (sessionStorage), device gate in app.js (wipe after sign-out), offline start from snapshot - browser-walked |
| C5 | B | started | | |
| C1 | B | merged | 1cbf47ff3, 1e1e8c729 | data-free live page, ?viewer=1/?entries=0, event-people endpoint (C3 skips it), browser-walked |
| D1 | C | merged | ea158c6b0, cb60963d7 | competition_display + 7 messages, hub card + list/settings pages; copy-link 404 until D4; not browser-tried |
| D2 | C | started | | |
| B1 | C | merged | 9e3a38019, 2cd22d3ca | 4 competition columns, hub card form in CompetitionToolkitController (GET/POST). Open: copy on duplicate? (H) |
