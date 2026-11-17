# Competition toolkit - delivery status (living record)

Started 2026-11-17 from Jan's request (`00-context.md`). Designs: `A-round-formats.md` (multi-puzzle rounds, sum
standings, brackets, reveal on stopwatch start), `B-qr-competitor-page.md` (QR modes, competitor page, time proposals),
`C-judge-page.md` (judge live entry: offline shell, speed, puzzle choice, proposals), `D-displays-overlays.md` (timer
screens + OBS overlays with an editor). **`R-review-and-plan.md` is binding where the four disagree** (§1 contracts,
§2 safety fixes, §3 cuts to v2, §4 packages and lanes).

## How it is delivered
- Integration branch `competition-toolkit` (worktree `.claude/worktrees/ct-integrate`), not main, until hardening (H)
  is done and Jan says go. Every new behaviour is opt-in per competition; organiser-facing settings behind
  an organiser flag (admins always) - retired 2026-11-17, every organiser gets them.
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

## Reliability hardening (2026-11-17, orchestrator: sub-session; brief `cm-delivery/brief-reliability-orchestrator.md`)
Jan: "reliability and performance are 2 key success requirements" - the judge page and the proposals extremely reliable,
fast and working offline (scanning too). Delivered on `main` (= production) from the evening of 2026-11-17, no flags.

| Pkg | State | What |
|---|---|---|
| T1 | on main | Time's up + missing pieces: the judge types MISSING pieces (placed = pieces − missing, per puzzle on pick rounds, several unfinished puzzles), the card opens in that mode once the round's time is up (a default - "Back to the time" one tap), desk too; format names "One result for the round" / "Solve all N (order recorded)" / "Pick K of N" |
| T2 | on main | Proposal timing: the recorded time is always the tap's (Jan); competitor heartbeat (`/-/competitor-heartbeat`, signed token, redis-state, 0 Postgres statements, p95 3-9 ms); evidence-based verdict on_time / offline / suspicious + clock note - the judge card warns ("Check this time", one-tap "Use received") only on evidence; threat model in `competitor-pages.md` "Timing you can trust" |
| T3 | on main (tool) | `tools/judge-chaos/`: 16 real-browser scenarios in prod mode (Selenium Chromium, own proxy for offline/flapping/500/429/hangs, CPU 6x, fake camera with QR videos of all 4 tag kinds, SW update, session expiry, two judges, clock jump, frozen tab) - DB-checked exactly-once |
| T4 | on main | `tools/competition-load/` (k6 over HTTP, prod-mode stack, lock sampler, judge timings); answers sent before the Mercure pushes (`AfterResponse`); lock analysis: no per-round key needed |
| T5 | on main | `judge-device-checklist.md` (real phones; PWA install prompt not recommended now) |
| T6 | on main | Standings: time + missing pieces + penalised total; "finished with N pieces missing" (`result_missing_pieces`, `RecordRoundResults` field `missing_pieces`) recorded by judge/desk; switch `penalise_finished_missing` (default off) |
| F1 | on main | fixes D1-D4, D6 (below) |
| F2 | on main | fix D7 (below) |
| TR | on main | 49 keys × cs/de/es/fr/ja |

Defects found by the chaos suite (`cm-delivery/rl-t3-defects.md`), all fixed:
- **D1 critical** - unsent results went out under ANOTHER account after a different player signed in on the phone → `expectedPlayerId` + 409 `another_account` (server), outbox stops until a fresh state names the same player (F1).
- **D2 high** - a phone clock change while the page was open shifted "Finished now" and the competitor's tap → the server-synced clock runs on the monotonic clock, re-sampled after sleep/jumps (F1).
- **D3/D4** - offline reload in the first seconds showed "No offline copy"; snapshot never written under a steady stream of updates → first state at once, max wait 2 s (F1).
- **D5** - a late proposal confirmed at the receipt time (every offline proposal) → tap time always (T2).
- **D6** - "Sign in again" banner shifted the card and ate the Save tap → banner below an open card (F1).
- **D7** - another judge's value arriving while the confirm step was open was silently overwritten → Save carries what the card showed as `from`; "Saved meanwhile by X … - Save replaces it" + "Replace <value>" (F2).

Measured (Docker-on-Mac, prod mode, 16 FrankenPHP workers, 300 entrants, 6 judges, 100 proposals in 1-10 s + 300 phones' heartbeats/polls) - p95 before → after T4: save 83 → 42 ms, save result+puzzles 176 → 41 ms, proposal 40 → 22 ms (100-in-1-s burst 136 → 106 ms), heartbeat 9 → 4 ms; with the Mercure hub stalled 2,050 → 19-51 ms. The event lock is held ~20 ms per save, waited on in ≤ 0.3 % of samples. Judge page (production build, 375×812, CPU 4x, 300 entries): cold ready 2.7 s (Slow 4G) / 4.2 s (Fast 3G), warm 0.33-0.8 s, table → card p95 61 ms, Save → "All saved" p95 266 ms (Slow 4G); chaos suite at CPU 6x: table → card p95 111 ms, Save → sent p95 298 ms.

Proven by the suite: exactly-once under flapping networks, reloads mid-save, 500/429/hang bursts and lost answers; offline reload → enter → sync; SW update mid-event; session expiry without loss or wrong attribution; two judges' conflicts; tap moments kept offline (judge and competitor, 65-90 s later + reload); offline scanning of all 4 tag kinds via the self-hosted zbar from the worker's cache. Needs a real phone (`judge-device-checklist.md`): iOS Safari/WebKit, a real camera and native BarcodeDetector (Linux Chrome has none - shimmed), tab discarding, captive portals, a real deploy with new asset hashes.

Open for Jan: penalty 0 - missing pieces as a tie-break? (none now); sheet editing of "pieces missing" (desk/judge only now); load numbers on lily; a live-entry-only stylesheet would cut the cold open (137 KB gzip CSS) if that ever matters.

## Creation wizard (2026-11-17, orchestrator: sub-session; design `W-creation-wizard-research.md`)
Jan: rework creating competitions into a wizard (toggle features, one round without extra clicks, especially series)
and fix Duplicate (new dates). No flag (WF, 2026-11-17: `EVENT_CREATION_WIZARD` retired - the wizard is every player's
"Add event"). Organiser interviews (W0) did not happen - the documented defaults are used. Packages in worktrees `.claude/worktrees/wz-<pkg>`, branches `wz/<pkg>`.

| Pkg | State | Commit(s) | Notes |
|---|---|---|---|
| W4 | merged | 04210997e..545461e3d | Duplicate asks new date + name (GET form on `duplicate_competition`, edition prefill: name number +1, date + series gap); edit form "Move the rounds too" (ticked, shown when the start date changes; not offered once a round has stopwatch state, results or a secret puzzle), end date follows; `EventDateShift` shared with duplicate; `EditCompetition` now under the participants lock. Not behind a flag. Browser-walked |
| W1 | merged | 548060b5e..4b8b7b8aa | flag `EVENT_CREATION_WIZARD` (`EventCreationWizardFeature`), `add_competition` GET → 302 wizard unless `?classic=1`; entry (`/{_locale}/new-event`, copy list → duplicate form); Basics (`StartEventWizardDraft` = draft + its round, `SaveEventWizardBasics`); Puzzle & format (`SetUpEventWizardRound`: round edit → add puzzle → reveal → format, one transaction); Review (Publish → edit page). Step registry `EventWizardSteps`/`EventWizardPath`/`EventWizardStep`, resume `event_wizard_continue`. **`LockUntilCommittedMiddleware` is re-entrant per key** (nested dispatch under the same key passes through, ResetInterface). Round named after category, 90 min default. S1 = 5 clicks (was 8-10). Browser-walked 375/1280 |
| W1F | merged | 578a5d7f5, 313dcde3a | review agent (no 80+ bugs): Basics double submit idempotent (hidden draftId, DBAL check after a unique violation closes the EM), round created only once under the lock, round edit only when date/time/zone changed; CI phpstan fix in EventWizardTest |
| W2 | merged | 7d67404ce..ba60fb292 | "Get ready" checklist on the edit page (registry `GetReadyItem`, facts `GetEventGetReadyFacts` = 2 statements pinned, golden: flag off byte-identical), collapse in localStorage, "Published - here's what's left"; "Continue setting up" on edit page / draft banner / You organize. Name tags + judges are tips; "try" items stay tips (v2: timestamps). Browser-walked |
| W3 | merged | 7a740a58e..d3b9c546a | series path: series Basics (idempotent draft series) → Dates + each date's round (`EditionsPreview` extracted from AddEditionsController, composite under the `series-editions-*` lock, re-send creates nothing, DST-safe local start) → Review (publishes the series + its draft dates) → series page; "Add the next dates" (prefilled from the last dates) + per-date quick "Add puzzle" (`add_puzzle_to_round?return=` for everyone, ReturnUrl-validated). S2 = 6 clicks (was ~77). Browser-walked |
| W5+W7 | on main | 67698cb67..833f76498 | several rounds: entry follow-up (qualify yes/no/not sure) → Basics (draft only) → Structure (templates Qualification / Every round counts, schedule rule spread over the event's days with an automatic gap that fits the day, editable schedule table; `SetUpEventWizardStructure` composite; read-only once entries/results/stopwatch - re-checked under the lock) → Puzzles grid (`SetUpEventWizardPuzzles`, secret switch + reveal once, pick K, invariant as row errors) → Extras → Review. W7 (toolkit): stage top-N → advancement steps, "add up per category" → standings (shared `StandingsPerCategory`). Path rule: a draft with no round that is not an edition resumes on this path. Review agent: 3 fixes done. S3/S4 = 7 clicks (were ~67/~132). Browser-walked (before the review fixes) |
| W6 | on main | 36877949e | Extras step before Review (one round + several rounds; series: none - every feature belongs to a date): registration here, links elsewhere, judges, toolkit rows (QR, competitor pages, proposals, timer screen, overlay, standings); existing display/standing shown as "added - Manage"; Review lists them; checklist rows name tags with QR + OBS overlay tip; draft series "Continue setting up". Rows write in separate transactions (a later refusal keeps earlier ones, 422) |
| WF | on main | 719979f19, 17232c83b (rebased) | `EVENT_CREATION_WIZARD` retired (Jan: no flags) - the wizard is every signed-in player's "Add event"; flag-off tests dropped, `EditPageWithoutWizardGoldenTest` deleted (checklist cost now counted on its own: 2 statements) |
| TR + docs | on main | | 307 keys in cs/de/es/fr/ja (one translator per locale); feature doc `creation-wizard.md`, events rule bullet, TODO |

Open items:
- 2026-11-17 evening: PR #406 squash-merged; delivery continues on `main` (= production). Jan: no feature flags → WF removes `EVENT_CREATION_WIZARD`.
- W5: rounds added later in the schedule table do not join existing standings/advancement steps (toolkit pages do that).
- CI red after W2 (PluralMessagesTest: `{1}…|]1,Inf[…` has no count 0) - fixed on the branch. Agents: plural keys need every count.
- W3: publish publishes every draft date of a draft series; "When it happens" text not filled from the rule; draft series "Continue setting up" (W2 covers events only) → W6 follow-up.
- W4: a duplicate of a source without `dateFrom` now counts days from its first round (internal API too).

## Analytics Studio (2026-11-17, orchestrator: sub-session; design `../analytics-studio.md`)
Jan: a per-round page for commentators of a live stream - every entrant against the round's puzzle(s), compare,
puzzle statistics, difficulty, predictions; no membership gate. Shipped straight to main, no flag, on for every public
event with slugged rounds. Architect design → independent design review (1 blocker: a hidden puzzle leaking by
exclusion; 19 findings → §0a amendments R1-R20) → packages in worktrees `.claude/worktrees/st-<pkg>`, branches `st/<pkg>`.

| Pkg | State | Commit(s) on main | Notes |
|---|---|---|---|
| design | merged | af4bd0f5f, d1723d5f6 | §0a binding: no async prediction warm (predictions only for the selected + compared entrants), swap test for secrets, state cache keyed by stopwatch hash, no limiter on `state`, live results never a leaderboard |
| S2 | merged | 2a66108d3, 7fd39fbc7 | pure modules `assets/analytics-studio/` + harness (51 scenarios) |
| S1 | merged, code-reviewed (no blocker; 4 fixes applied) | bbf344209..bbe61ca41 | 4 stateless JSON answers, budgets pinned (state 2 warm; analytics 3 warm / 10 cold; entrant 4 / 17; predictions ×4 19); 300 entrants × 3 puzzles: 214 ms first cold, ~40 ms after; opt-out help text in 6 locales |
| CI fix | merged | 08c21fe63 | wizard's `GetEventWizardStructureLock` classified (red CI not from the studio) |
| S3 | merged | c3641ead4..786d25e2d | page + lean layout, plain Encore entry 63.5 KB / 21.2 KB gzip, organiser links via `analytics_studio_round`, 143 keys × 6 locales; walked 18 steps (laptop + phone, live reveal, live results) |
| S4 + S3F | merged | a6455e549..06444a96e | public links (round page, event/edition timeline; goldens unchanged, budgets unchanged); prod-walk fixes: right column scroll, one-line rows, past round shows its start |

Production: walked on WJPC 2026 Individual D (209 entrants) - puzzle stats, histogram, top 10 with "in this round"
marks, an entrant's rating/skill/prediction/similar history/recent form; no console errors, no new Sentry issues.
Open for Jan: §11 (public predictions of others - opt-out text updated; pair/team prediction none; organiser opt-out
none; blocklist exemption broader than displays) and R8 (unpublished confirmed results never sorted as a list).
