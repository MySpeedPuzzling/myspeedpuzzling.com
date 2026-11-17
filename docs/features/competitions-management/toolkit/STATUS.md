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
| A1F | A | merged | e9e8ae934..0f5e336b6 delete round/remove puzzle during ticked countdown keeps old hide_until (cm-delivery/review-A1.md) |
| C2 | B | merged | f10ef1cef..84443b7b7 | lean layout, `live_entry` entry, fixed SW install precache (was a no-op: duplicate runtime.js). **On merge to main: restart js-watch.** Native-app (Hotwire Native) + precache scope -> C3 |
| C3 | B | merged | 2b9676263, 704d05ac4 | shell route (stateless firewall), msp_live_player cookie, precache skips live_entry*, native-app Turbo bootstrap `live_entry_native` (verify on real devices in H) |
| C4 | B | merged | 62049185d, 90cb5992c | snapshots (IndexedDB msp-live-entry), drafts (sessionStorage), device gate in app.js (wipe after sign-out), offline start from snapshot - browser-walked |
| C5 | B | merged | f9412f3e4, c09540536 | live_entry_route.js (all frozen paths), shell plan + neutral screens, going live; shared current-round fixture PHP<->JS. Open: ~20 s until offline-failed outbox items send after going live -> C7 |
| C6 | B | merged | 1c5c583a0..89931fce5 | SW live-shell:prepare + live-shell-v7 cache, network-first 4 s → shell, LIVE_ENTRY_PATHS mirrors parseLiveEntryRoute (shared fixture), self-hosted zbar first. Browser: offline reload → stored round → save → synced |
| C7 | B | merged | b0288c0f7, 9fe480b60 | taps (a) 5→4, (b) 11→10; table auto-open, confirm in place + 350 ms guard, outbox enqueueGroup (never split), instant send when back online; bundle limit 160→170 KB min (gzip 43.5/45) |
| C8 | B | merged | 2cfb97b6b, 196642133 | parseCompetitionTagUrl reuses parseLiveEntryRoute, live_entry_tag.js (uuid5 lineup id, PHP parity fixture), offline tag resolution. H: waitlisted member in lineup hash differs phone vs server; tag-URL 'Open their round' asks leave? (linkNavigation one-liner); bundle gzip 44.7/45 KB |
| D7 | B (swap) | merged | 26672aa49..91fc22f20 | upload `upload_competition_display_image` (multipart `file`, X-CSRF-Token official_results, 5 MB, 60/h), DisplayImages URL builder (DISPLAY_IMAGE_PRESET, default /original/), references + async cleanup. Lily: imgproxy preset `display=rs:fit:1920:1920` + env. H: ConvertCompetitionToSeries loses displays (blocker?) |
| C1 | B | merged | 1cbf47ff3, 1e1e8c729 | data-free live page, ?viewer=1/?entries=0, event-people endpoint (C3 skips it), browser-walked |
| D1 | C | merged | ea158c6b0, cb60963d7 | competition_display + 7 messages, hub card + list/settings pages; copy-link 404 until D4; not browser-tried |
| D2 | C | merged | 74c8bfa6c..96f494ab4 | DisplayDesignSanitizer + schema.js + 42 shared fixtures, 7 presets, save endpoint (409/422, liveConfirmed) |
| B2 | C | merged | 3a325cfdb, 0f03dec41 | CompetitionLineupId (uuid5), CompetitionTagResolver, CompetitionTagLanding, 3 frozen routes `live_results_{lineup,table,entry}_tag`(+_localized), PreferredLocale; competitor_page route contract for B4 |
| B3 | C | merged | d47b59b52, 5a682c9e7 | kinds people/lineups/tables/entries × tags/tents (separate template, default byte-identical). H: name_tags.hint text after B4; docs |
| D3 | C | merged | 1a3937522, 20d528728 | GetCompetitionDisplayState + CompetitionDisplayPayload, /display/{token}/state|design (stateless), per-type results gating, opaque keys, forDisplay() Mercure. Decision: live rows keep rank (organiser opted in) |
| D4 | C | merged | 46f059110, ec2255210 | /display/{token} standalone, render.js (15 types, XSS-safe), runtime.js (signals, polling, reload rules, leak check), server QR modules, self-hosted fonts; 7 presets walked + transparent overlay. Not tried: OBS, soak |
| D6 | C | merged | b40f91d85, 11887a63a | editor page `edit_competition_display` (lazy Stimulus), geometry/editor_state/sample_data pure modules, upload picker, 409/live confirm flows; browser-walked |
| D6F | C | started | | | polish: sticky toolbar under site header, truncated layer names, add → editor |
| B1 | C | merged | 9e3a38019, 2cd22d3ca | 4 competition columns, hub card form in CompetitionToolkitController (GET/POST). Open: copy on duplicate? (H) |

## Hardening list (H) - collected
- Duplicate competition: copy competitor-page settings (B1)? displays (D8). Rounds copied without puzzles stay `total`.
- PluralMessagesTest / XlsxSafetyTest OOM in local paratest workers (CI fine so far).
- SandboxSimulatedResultsTest error seen once in CI (passes locally) - watch.
- name_tags.hint text after competitor pages (B3).
- C8 edges: waitlisted member line-up hash; tag-URL link leave prompt.
- Real devices: native apps live page (C3), iPhone Safari offline, camera scan.
- Translations of every new key (5 locales).
