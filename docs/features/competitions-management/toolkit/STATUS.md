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
| D6F | C | merged | 306fcbfe1, 028e5debf, 86adaded1 | toolbar below header (--header-height), viewport-sized work surface, two-line layer labels, add → editor, placeFrame() avoids overlaps; stage 16:9 verified at 5 sizes |
| B1 | C | merged | 9e3a38019, 2cd22d3ca | 4 competition columns, hub card form in CompetitionToolkitController (GET/POST). Open: copy on duplicate? (H) |
| A3 | A | merged, reviewed (no data-loss; total rounds unaffected) | b035ed2b5..e4e333f72 | RoundEntryPuzzles + result_puzzles jsonb, field `puzzles` (group pass, group_refused), locks only on puzzle requests, guards, official_results_puzzles.js + parity; referees may send puzzles |
| P1 | A | merged, reviewed: 3 fixes queued as P1F (pause/resume link bypass, stopped-round late taps, re-link resets connectedAt) | 25ca1e978..8c869401e | competition_time_proposal, ProposeRoundTime/Withdraw (event lock), TimeProposalOwnership (linked before start, id/sender bound), late flag, proposalId confirm + `proposal` field reject, resolver off = 0 statements, reset supersedes, `proposals` in state, lineupId when toolkit on, official_results.proposals push |
| A6 | A | merged | f9bb105a9..db7a0acd4 | competition_standing, StandingCalculator::sum (limit+penalty, order rules, incomplete after all, ties share rank, lineup identity), organiser page + live preview, 'one per category', JSON, prune on round delete/move, duplicate copyStandings() |
| A7 | A | merged | 1dfc4ce79, 480ef17b6, 690c8b447 | publish/unpublish, event_standing/edition_standing (tabs per category, provisional until all published), OfficialEntrantsView keepHidden + participantIds, links (event parts +0 statements, round page, overview, sitemap) |
| D8 | A | merged | 4cd3219a1, 7c20537d1, 8b2f40873 | duplicate copies displays (new tokens, images into the copy's prefix, rounds remapped) + competitor-page settings; convert-to-series refuses displays; Displays links on stopwatch page + control room |
| A8 | B | merged | 96b5d75c7..dfed7f715 | competition_advancement_step + graph check, management pages, desk banner (Mark top N preset incl. ties as checkboxes, Advance prefilled), top-N-overall preset endpoint, duplicate copyAdvancementSteps(); merge resolved with D8 by hand |
| D5 | C | merged | 315569b21, 47dfa5531 | CompetitionDisplayLiveUpdates (display.signal results/puzzles/round/design after commit), CompetitionDisplaysPresence (cached, 0 statements once cached), hooks in controllers; browser walk pending (dev hub origin) → H |
| A9 | B | merged | d3b7f2a08, 495c266b1, 67acf9fb3 | BracketLayout (stages, SVG connectors), event_bracket/edition_bracket public pages (published-only marks), organiser bracket on the steps page, links +0 statements, sitemap |
| H1 | - | merged | 8808b09da..73a39307f | polish list (8 items) |
| H2 | - | merged | efa3637ab | round-formats.md, standings.md, displays.md, judge-offline.md, competitor-pages.md; rule file competition-toolkit.md; flags; TODO v2 |
| TR | - | merged | d06a1d893, ac74acd5a | 816 keys × cs/de/es/fr/ja + 13 English source fixes |
| H3 | - | merged | 12dd06cf3..56680753f | undo of a confirmed proposal → superseded; doc pointers |

**2026-11-17: all packages merged; CI green on a5d64f0ab (PR #406, draft). Waiting for Jan: review, real-device walks, go to merge main (see docs/TODO.md "Competition toolkit v2" ops steps).**
| C10 | B | merged | 3698eb8b3..3916fe168 | puzzle chips (labels before reveal, order taps), grouped result+puzzles saves, Puzzle missing list, Save puzzle choice; bundle 180 KB/50 KB gzip (now 47.6). Not tried: reveal while card open, offline group save |
| A5 | C (swap) | merged | a67e67ecf..e31a67273 | OfficialEntrantsView (forRound/forRounds, rankOf), public chips (label before reveal), per-puzzle Add to my profile (`round_puzzle` param, only on the official_entry path), golden pins |
| B4 | C | merged | 4809c8704, 00d8e47bc, 114c81d9c | competitor_page p/g/t/e + competitor_state, 404 rules, published/live/own results, guest 4 statements, canaries, B2 goldens regenerated; docs competitor-pages.md |
| P1F | A (extra slot) | merged | 48360480f, 19db0c38c | stopwatch_first_started_at (stopwatchOriginalStartAt()), taps before Stop accepted (too_late from the round's end), re-pick keeps connectedAt |
| B5 | C | merged | 6248122d2, ad14ea84a | competitor_page_live.js (phases, offset, jittered refetch, wake lock, tapNowMs), token-less public stream mode; walked via refetch path (dev hub refuses anonymous) |
| B8 | C | merged | 1568afc75, 4c572485e | Stop button + confirm sheet, competitor_proposals_outbox.js (UUIDv7 id + tappedAtMs kept offline), states incl. confirmed/changed/rejected, sign-in hint, current round first on phones. **Checkpoint 2 (proposal loop) walked end to end** |
| A4 | B (swap) | merged | 973884dc5, 41725625e, 89d05d343 | desk per-puzzle editor (grouped saves), sheet recompute/clear+undo + Puzzles column, export Puzzle N columns (total pinned) |
| C9 | B | merged | 870b6023b | proposal panel (Confirm focused / Replace / Change / Not right + reason), Proposals waiting list opens the card, outbox proposalId + `proposal` reject (offline ok), late → receipt default; bundle 190 KB/52 KB gzip (50.8) |
| A8 | B | started | | |

## Hardening list (H) - collected
- ~~Duplicate competition: copy competitor-page settings (B1)? displays (D8).~~ **Resolved** by D8 (duplicate copies both). Still true: rounds copied without puzzles stay `total`.
- PluralMessagesTest / XlsxSafetyTest OOM in local paratest workers (CI fine so far).
- SandboxSimulatedResultsTest error seen once in CI (passes locally) - watch.
- ~~name_tags.hint text after competitor pages (B3).~~ **Resolved**: no change needed - the hint still fits.
- ~~C8 edges: waitlisted member line-up hash; tag-URL link leave prompt.~~ **Resolved** in H1.
- Real devices: native apps live page (C3), iPhone Safari offline, camera scan.
- Translations of every new key (5 locales).
- A3 review: translate official_results.reason.* toolkit reasons + loop testEveryNewReasonIsTranslated over 6 locales; JS twin lacks shape rules (doc or add); live puzzle.pieces_count changes can block a stored record (doc + a clear path).
- **A4 scope add:** the participants SHEET (not only the desk) must handle pick rounds - send/clear `puzzles` with results, else result edits on entries with a record are refused `puzzles_mismatch`.
- P1 load: 40 simultaneous proposals p95 ≈ 0.8 s on Docker-on-Mac (≈19 ms each under the event lock) → 100 ≈ 1.9 s vs 500 ms goal. Accepted for v1 (finishes spread, outbox); measure on prod-like HW; per-round lock key needs Jan's OK.
- ~~B4 open: show 'Did not start' on public competitor pages? (round page omits it) - decide in H.~~ **Resolved** in H1 (public pages never show it).
- ~~C9 polish: proposer name only on pair/team cards; document 'undo of a confirmation clears the result, proposal stays confirmed'.~~ **Resolved**: proposer name in H1; the undo dead end fixed in H3 (the proposal turns `superseded` `result_cleared`, the competitor gets Stop back).
- Decisions: public pages never show 'Did not start' (round page, competitor page, standings); drop the category badge when the standing name already says it (polish).
- Orchestrator: CI was red from the A6 merge to D8 (3 guard tests) - fixed. Check CI after EVERY merge.
- B8 polish: pending/sending boxes use a neutral style (not $primary pink).
- ~~C5: ~20 s until offline-failed outbox items send after going live.~~ **Resolved** by C7 (instant send when back online).
- ~~D7: ConvertCompetitionToSeries loses displays (blocker?).~~ **Resolved** by D8 (convert-to-series refuses an event with displays).
- ~~D1: copy-link 404 until D4.~~ **Resolved** by D4 (`/display/{token}` exists).

## Creation wizard (2026-11-17, orchestrator: sub-session; design `W-creation-wizard-research.md`)
Jan: rework creating competitions into a wizard (toggle features, one round without extra clicks, especially series)
and fix Duplicate (new dates). Flag `EVENT_CREATION_WIZARD` (admins always). Organiser interviews (W0) did not happen -
the documented defaults are used. Packages in worktrees `.claude/worktrees/wz-<pkg>`, branches `wz/<pkg>`.

| Pkg | State | Commit(s) | Notes |
|---|---|---|---|
| W4 | merged | 04210997e..545461e3d | Duplicate asks new date + name (GET form on `duplicate_competition`, edition prefill: name number +1, date + series gap); edit form "Move the rounds too" (ticked, shown when the start date changes; not offered once a round has stopwatch state, results or a secret puzzle), end date follows; `EventDateShift` shared with duplicate; `EditCompetition` now under the participants lock. Not behind a flag. Browser-walked |
| W1 | merged (review agent running) | 548060b5e..4b8b7b8aa | flag `EVENT_CREATION_WIZARD` (`EventCreationWizardFeature`), `add_competition` GET → 302 wizard unless `?classic=1`; entry (`/{_locale}/new-event`, copy list → duplicate form); Basics (`StartEventWizardDraft` = draft + its round, `SaveEventWizardBasics`); Puzzle & format (`SetUpEventWizardRound`: round edit → add puzzle → reveal → format, one transaction); Review (Publish → edit page). Step registry `EventWizardSteps`/`EventWizardPath`/`EventWizardStep`, resume `event_wizard_continue`. **`LockUntilCommittedMiddleware` is re-entrant per key** (nested dispatch under the same key passes through, ResetInterface). Round named after category, 90 min default. S1 = 5 clicks (was 8-10). Browser-walked 375/1280 |
| W1F | merged | 578a5d7f5, 313dcde3a | review agent (no 80+ bugs): Basics double submit idempotent (hidden draftId, DBAL check after a unique violation closes the EM), round created only once under the lock, round edit only when date/time/zone changed; CI phpstan fix in EventWizardTest |
| W2 | merged | 7d67404ce..ba60fb292 | "Get ready" checklist on the edit page (registry `GetReadyItem`, facts `GetEventGetReadyFacts` = 2 statements pinned, golden: flag off byte-identical), collapse in localStorage, "Published - here's what's left"; "Continue setting up" on edit page / draft banner / You organize. Name tags + judges are tips; "try" items stay tips (v2: timestamps). Browser-walked |
| W3 | merged | 7a740a58e..d3b9c546a | series path: series Basics (idempotent draft series) → Dates + each date's round (`EditionsPreview` extracted from AddEditionsController, composite under the `series-editions-*` lock, re-send creates nothing, DST-safe local start) → Review (publishes the series + its draft dates) → series page; "Add the next dates" (prefilled from the last dates) + per-date quick "Add puzzle" (`add_puzzle_to_round?return=` for everyone, ReturnUrl-validated). S2 = 6 clicks (was ~77). Browser-walked |
| W5 | in progress | | several rounds + W7 toolkit wiring |

Open items:
- CI red after W2 (PluralMessagesTest: `{1}…|]1,Inf[…` has no count 0) - fixed on the branch. Agents: plural keys need every count.
- W3: publish publishes every draft date of a draft series; "When it happens" text not filled from the rule; draft series "Continue setting up" (W2 covers events only) → W6 follow-up.
- W4: a duplicate of a source without `dateFrom` now counts days from its first round (internal API too).
