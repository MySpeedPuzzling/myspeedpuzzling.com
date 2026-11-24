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

Final run on main (incl. F2, 2026-11-17 20:00, `QUICK=1`): **16/16 scenarios pass**; s10 at CPU 6x: table → card p50 61 / p95 80 ms, Save → sent p50 165 / p95 213 ms, page ready 0.5 s.

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
| WG | on main | 944226bce, eefe44479 | Jan: organisers schedule every round - templates make the structure, every round's day + start typed (one GET preview → one POST), no computed gaps; edit form "Also move the rounds by the same number of days" + the concrete effect line; 6 locales |
| WB | on main | (7 commits + TR) | Jan's most common use: **backfill a past event and link my results** - entry "What do you want to add?", The event (inline series/organization, "Is it one of these?" duplicate prompt `GetPastEventMatches`), Puzzles → one round per row (no start time, no limit), Link my results (`LinkSolvingTimesToEvent`, `SolvingTimeEventDerivation` shared with the edit-time handler); links to a not-yet-public event wait in `waiting_event_link` and are applied at approval (`WaitingEventLinksApplier`, cron safety net). ~14+ → 5 clicks. Review agent: 3 fixes done. Wizard format choices = the round form's (One result / Solve all / Pick K) |

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

## Participant linking (2026-11-17, orchestrator: sub-session; design `../participant-linking.md`)
Jan: organisers import participant lists with or without MSP player ids; players connect themselves before or during
the event. Architect design → independent design review (2 blockers: approving a claimant who already self-joined was
refused; a renamed-profile self-join could ride a pre-ticked suggestion onto someone's row; 7 should-fix) → §0a
amendments R1-R13 → packages in worktrees `.claude/worktrees/lk-<pkg>`, branches `lk/<pkg>`, straight to main.

| Pkg | State | Commit(s) on main | Notes |
|---|---|---|---|
| design | merged | bbb232549 | §0a binding; order L1a → L1b → L2a → L2b → L4a → L3 → L5 → L4b (+ DV divisions in sheet/import for the real-events team, after their #421) |
| L1a | merged | fd689e4fa..c72030417 | one link path `ParticipantLinks` (+ `connected_via`, append-only `participant_link_log`), every connect/disconnect call site moved (`ParticipantLinkCoverageTest`); account deletion unlinks, soft-deletes own self-joined rows without official data, logs `account_deleted` (fixes self-joined names showing up as unclaimed); sheet suffix "· by themselves / · import / · personal link"; join/leave/compete_connect push the sheet's live topic |
| L1b | merged | 74089487f, 5f1d870b6 | import player column takes `#code`, bare code, profile URL or id (`PlayerReference`); a code that ever belonged to 2+ players is refused (`code_changed_owner`, R5); same player on rows of different people links none (`player_in_file_twice`); "name differs" warning; preview "→ #CODE · Name" (masked for private/blocked); export `msp_code` |
| TR | merged | 8f6f7afac | L1a/L1b keys in 5 locales |
| (CI fix) | merged | 7ca812c0b | main's phpstan red from another session's `PluralMessagesTest` - fixed |
| L2a | merged (PR #415, squash) | d138b8d98 | **no-Docker mode from here (Jan): PR + CI as the gate.** Claim policy per event (`participant_claims`, automatic = approval while proposals are on and not online), `participant_link_request`, `ParticipantClaims` (open → link, approval → request, dispute, refused pair / 3 refusals → ask organiser), sheet Approve (R1 releases the claimant's other rows) / Decline / Not this person (both irreversible), filters + chips, hub card "Participants & profiles", R3 late proof links may propose, R11 join GET never links under approval. Independent code review: 4 SHOULD fixed (refused match loop, undo of Not this person, requests read once per batch, index use). 51 keys × 5 locales |
| L2b | merged (PR #431) | 47a7ba077 | in-app notifications: link requested (organisers, one while unread per event), approved, declined (also superseded), "You're on the participant list" (organiser/import links on public events) + "Not me" → unlink + refused pair; notification target columns (hand-written migration) |
| L4a | merged (PR #433) | 902b1546f | personal claim links (`claim_token`, R6), claim page `/claim/{token}` (every §6.5 state, no-store, noindex, limiter per player / per IP for guests), R4 redaction, sheet row ⋯ copy / QR / new link, CSV, print kind `claims`, check-in "Show QR", hub buttons; security review: no blocker, 6 fixes (paid registration on switch/dispute, venue-friendly limits, no_profile, slips referrer). Loki redaction on lily = docs/TODO.md |
| L3 | merged (PR #438) | 70263a51b | "Is this you?" card (in `GetEventAttendance`'s statement), `?participant=` preselection, "This is me" on the participants list and the competitor page (`claim_participant`), tag scan with pages off → the card |
| L5 | merged (PR #441) | 5fcba1677 | link suggestions (tiers joined by themselves / linked at another date / name + country ≤ 3 players), `ParticipantNameKey::sql()` + index `custom_player_name_key` (join match + card share it), sheet "Suggestions (N)" dialog + atomic merge of a self-joined row, hub counter, import flash; review: 2 blockers fixed (renamed self-join pre-ticked at the next edition - only organiser-named rows pre-tick; quadratic tier 1 → hash joins), R7 server-side, hidden candidates not tickable |
| L4b | merged (PR #442) | bc147e6da | participant e-mail (import / sheet / export, erased 60 days after the event - cron 03:53 on lily, lily.srv 8873ef2), claim-link invitations (one per address per 24 h, "no" per address survives remove + re-add, suppressed addresses silent, own e-mail type, one-click refusal + RFC 8058, complaint → `invitations_complained_at`); review: 1 blocker (re-add bypass) + 3 fixed |
| DV | merged (PR #443) | cfe629237 | for the real-events team (St. Paul / World Series demo), on their divisions model #421: one matching rule `DivisionMatch` (id / name / short label, accents + spaces folded; also `SetRoundEntryDivisionsHandler`), sheet Division column on round tabs (three-way, refusals, "N without a division", "Fill empty with…", phone line; same statement count), import `Division` / `division_in_round:<roundId>` (`division(s)` stays Rounds without a division list), export columns |
| TR | merged (PR #446) | a959a5bce | import one row per team (Ticket Tailor-style): numbered member columns, ticket type → round chosen on the preview, team block in the preview, too many members keeps the row out, Division on the team entry; the buyer's e-mail never copied to members. Real export headers to confirm with the organisers (docs/TODO.md) |

## Gaps 2026-11-17 (orchestrator: sub-session; brief `cm-delivery/brief-gaps-orchestrator.md`)
Small gaps of the shipped toolkit + creation wizard, three packages in parallel (worktrees `.claude/worktrees/gp-<pkg>`,
branches `gp/<pkg>`, Docker serialised through one lock `cm-delivery/gp.sh`), reviewed (diffs + screenshots
`cm-delivery/shots/gp-*`), straight to main. Translations cs/de/es/fr/ja by the orchestrator (46 keys).

| Pkg | State | Commit(s) on main | Notes |
|---|---|---|---|
| G1 | merged | a25b0e987..d80cc154b | "Were you there? Link my results" on event/edition pages (`LinkMyResultsEntry`, guests +0, signed-in +1 statement - `DetailPagesQueryBudgetTest` 19 → 20 on purpose; counts only results the Link page lists: unlinked, of a visible event puzzle from the first day to 7 days after, or on the event's days without puzzles; `?from=event` returns to the event); the waiting-link note on result detail + My data (rides on existing statements, only for who may change the time; `WaitingEventLinkCanaryTest` extended) |
| CI fix | merged | a096b595c + 7ca812c0b (main session) | `PluralMessagesTest` ran CI's 512 MB worker out of memory through the profiler's collecting translator - renders through the inner translator |
| G3 | merged | this PR (squash) | Duplicate says pick rounds' formats reset (form notice + flash; copying puzzles not built - secret reveal moments); desk "Still hidden: the stopwatch was never started with Reveal" (`stopwatchRevealMissed` in the round JSON only when > 0, no statement more) + publish dialog "Also reveal the hidden puzzle(s) now" (unticked; `RevealPuzzlesTheStopwatchMissed` after the publish in its own transaction, all rows or none under the round's locks, confirmed hash - a changed list publishes and reveals nothing); `display_public` limiter (token + IP, sliding 1200/min, 429 + Retry-After, the runtime keeps its picture and waits); toolkit reason translation tests read every locale's own catalogue |
| G2 | merged | this PR (squash) | Wizard puzzle pickers: one "Find the puzzle" field (name / EAN / brand code across brands, `puzzle_search_autocomplete` = the site's `SearchPuzzle::byUserInput`, never a secret puzzle), brand path kept; Extras all or nothing (`SaveEventWizardExtras`, `SerializedByLocks` - own key first, the others sorted); a round added later in the schedule joins every unpublished standing that holds all rounds of its category (steps never guessed, the table says so); a series' empty "When it happens" filled from the Dates rule in the creator's language |

Decisions: the entry's window looks forward only (results logged after the event are common, solves before are practice);
Duplicate keeps the copy `total` instead of relaxing the K ≤ N guard; the reveal at publish is one message, not
`RevealRoundPuzzleNow` per row (the hash is checked under the lock); 1200/min instead of 120 (a hall of 20 screens on
one link behind one NAT). Not browser-tried: a real 429 on a TV, the "changed meanwhile" toast, a camera scan in the
wizard's search, "Add the next dates" filling the series text.

Open items (participant linking):
- lily.srv Alloy: redact `/claim/<token>` in Loki (docs/TODO.md "Participant linking") - before claim links are handed out at scale.
- Phone list / person editor have no claim-link, suggestion or invitation actions (desktop grid only) - docs/TODO.md.
- Jan's call: whether managed-registration events should also default to "Ask me first" (designed: no); whether the organiser should ever learn that an invitation could not be delivered (built: never - privacy).
- `EXPLAIN ANALYZE` of the suggestions statement on a big production event once one uses it.

## Real events: SPWS, Carnival (2026-11-17/18; orchestrator: sub-session; brief `cm-delivery/brief-real-events-orchestrator.md`)
Two real events to run end to end by the organisers' demo on ~2 Dec 2026: the **Speed Puzzle World Series** (Toronto,
2-5 June 2027: Individual / Pairs / Teams championships, cumulative time, back-to-back challenges, penalties, no-shows,
the same table all day) and the **St. Paul Winter Carnival** (6-7 Feb 2027: one puzzle across Teams CHALLENGE / OPEN #1
/ OPEN #2, divisions Regular / Senior 55+ / Junior, parallel Sunday rounds). Designs of record (architect + independent
review each; "Review amendments" binding): [shared-puzzles-and-divisions.md](../shared-puzzles-and-divisions.md),
[world-series-rules.md](../world-series-rules.md). Delivered to main = production; from 2026-11-17 ~23:00 under
**no-Docker mode** (Jan): PRs with CI as the gate, nothing run locally beyond node harnesses and `php -l`.

| Pkg | PR | What |
|---|---|---|
| W1 | #417 | Standings no-show `missing_rule = as_zero_pieces` (limit + penalty × all pieces, stays ranked; DNS at once, no result once published), substitute warning, publish-dialog no-show line, desk "only penalised pieces" help line |
| W2 | #418 | Unboxed rounds: the box picture hidden on every surface until the round's results are first published (`RoundPuzzleReveal::sqlImageHidden()`) |
| W3 | #419 | Seating "Same tables as round X" + "Use these tables in later rounds" (one write, all or nothing); tents print once for the event |
| W5 | #420 | Judge "Finished now" per puzzle on solve-all/pick rounds (split at the tap, offline outbox, K-th split + result in one group) |
| W4 | #425 | Challenges of a back-to-back block: own times per puzzle (split − previous), `?challenge=` rankings, desk/standing cells |
| S1 | #416 | One round rule for solving times when a puzzle is in several rounds of a category (`SolvingTimeRoundMatch`, exclusions → entries → result, never a guess; **A3b sticky link**: an ambiguous time keeps the round it holds) + `round_chosen` |
| S3 | #427 | Explicit round: Add to my profile names it, unfinished results, "This is my time - link it", the round picker on the result detail |
| S2 | #432 | Rounds of one category may share a puzzle (refusal lifted; notices; internal API `sharedWithRounds`) |
| D1+D2 | #421 | Divisions: event list + round subsets, division per round entry, organiser page + hub card, internal API (list + bulk entry divisions), division ranks on the desk + export |
| D5 | #444 | Divisions on the public round page (`?division=`, division ranks before hidden rows are dropped), competitor page, tags/tents |
| L1 | - | Results desk at 375 px: compact record chips (label, ✓ split, own time wrap inside a rounded chip; title = whole text), Country/Entered folded into the row on a phone |
| DM | #445 | `myspeedpuzzling:sandbox:demo-events` - the two demo events on the sandbox (below) |
| TR | in each PR | cs/de/es/fr/ja for every new key |

Moved to the participant-linking orchestrator (their files): the sheet Division column (D3), the import Division field
(D4) and Ticket Tailor-style team rows (handoff: `cm-delivery/linking-handoff-divisions.md`). Built for v2 by this lane:
`as_zero_pieces` (agreed with v2) and v2's item 5 (judge split times, W5).

**Production data:** the 2026 Carnival event (`019a11a9-…`) had an empty slug → `st-paul-winter-carnival-2026` (internal
API PATCH, page answers 200). Its `link` points at wintercarnival.com's `/events/2025/puzzle-competition` page; the
`/events/2026/…` URL is a 404 there - left as is (for Jan). S1 dry run on production (read-only SQL
`../shared-puzzles-dry-run.sql`): one event already shares a puzzle (Dutch Jigsaw Nationals 2026, Pairs round 1/2, from
a puzzle merge) - with the sticky rule **0 links change**; its 50 times stay in Pairs round 1 and their players can move
a time to round 2 from the result detail (S3).

**Chaos suite (in a short Docker window, 2026-11-18):** `s14_puzzle_splits` PASS after a scenario fix (it read the
pinned toast wrong - no product bug): split 1 tapped offline at 603 s, sent 8 s later after an offline reload, recorded
602; split 2 = result 614; s13/s02/s08/s04 PASS; the full run passed s01-s12c (15/15; s10 at CPU 6x: ready 0.6 s, table →
card p95 96 ms, Save → sent p95 266 ms) and was stopped before s13/s14 when Docker was paused again.

**Demo events on the SANDBOX** (`myspeedpuzzling:sandbox:demo-events --date=2026-12-02`, run 2026-11-18 on the box; a
re-run changes nothing; how to run + the 5-minute demo scripts: `docs/features/sandbox/README.md` "Demo events"; sign in
as the made-up organiser `test-user+1@myspeedpuzzling.com` / `sandbox`, or Jan's own admin account):
- World Series: https://sandbox.myspeedpuzzling.com/en/events/speed-puzzle-world-series-demo - standings
  `/standings/pairs-championship` (+ individual/teams), a block round with `?challenge=1`
  (`/results/pairs-challenges-1-2?challenge=1`), toolkit hub `/en/manage-event-toolkit/01a1edde-97b5-7066-ae78-ced0d83a70a2`,
  displays `/display/Fnj3Tpess6BxRoBMcBSKgr` (hall timer) and `/display/QTwsp1pqPxYjG8XVp1v6RP` (stream overlay).
- Winter Carnival: https://sandbox.myspeedpuzzling.com/en/events/winter-carnival-jigsaw-demo - shared puzzle
  `/results/500-teams-challenge`, `/results/500-teams-open-1`, `/results/500-teams-open-2`; divisions
  `/results/500-solo?division=S`; toolkit hub `/en/manage-event-toolkit/01a1edde-9efd-7991-a57e-73768a176fd5`.
- 5-minute script (both): event page → round results (`?challenge=` / `?division=`) → standing → toolkit hub (divisions,
  standings with the no-show rule, round formats incl. unboxed) → seating ("Same tables as") → results desk → judge page
  on a phone (Finished now per puzzle) → competitor page → displays → analytics studio. Press Start on a running
  round's stopwatch page on the day (no stopwatch is started by the command).

**Unverified (no Docker):** the rest of the full chaos run (s13, s14_* in the full suite); every browser walk (each PR body lists what to look at); the load test of item 8 (350 entrants
per round, 3 rounds × 150+ teams at once) with `tools/competition-load/` - T4's numbers stand (300 entrants, 6 judges:
save p95 42 ms, 100 proposals in 1 s p95 106 ms); the S1 reconcile timing on a production-size copy (+20 % budget).

**Defaults taken - open for Jan / the organisers:** back to back = ONE "Solve all 2" round on one stopwatch, challenge 2
= split₂ − split₁ (taking puzzle 1 apart counts; a per-round deduction or a "bag opened" tap only if SPWS says so; one
time limit per block); the chained-round design (two rounds, per-entrant start) is kept as v2 in world-series-rules.md
with the review's blockers; defect pieces (2+ adjacent missing) = the judge simply doesn't record them (help line, no
field); a no-show counts once the round is published; pick-round no-show pieces = the K largest puzzles; unboxed =
until the first publish (catalogue pictures stay - use a new secret puzzle); same tables = copy per category (separate
table ranges when championships overlap); divisions per round entry (a person may be Senior in Solo and Regular in
Pairs), no eligibility check, standings per division after the demo; `?challenge=` and `?division=` exclusive; ties
undefined by SPWS = shared rank.

**v3 / left:** judge-card help line for penalised pieces; a desk Division cell for walk-ins; displays: finished count per
division, ticker per challenge, the challenge label on the element; chained rounds (above); the results desk's no-show
line counts every entry even for a division standing; a site-wide `X-Frame-Options: SAMEORIGIN` from the app (Traefik
already sends DENY outside `/embed/`).
### Leftovers round + Docker catch-up (2026-11-18)
| Pkg | PR | What |
|---|---|---|
| L1 | #453 | Results desk at phone width: compact per-puzzle chips (label / ✓ split / own), Country + Entered folded below sm/md |
| L4 | #454 | "Show country flags" on the tags print page (`?flags=1`, default off; profile country only for public profiles) |
| L2a | #455 | Standings per division (D7): `competition_standing.division_id`, cell ranks within the division, "one standing per category and division" |
| L2b | #456 | Displays: `division` view (leaderboard + ticker) and `challenge` ranking (leaderboard) on results elements |
| L3 | #457 | Embeddable standing `/embed/standing/{id}(/{locale})?theme=dark` + organiser snippet; lily.srv 16c9e0e: Traefik routers for `GET /embed/` without `frameDeny` (prod + sandbox), every other page keeps `X-Frame-Options: DENY` |
| S1 perf | #461 | Reconcile LINK select on production 221 → 64 ms (before S1: 42 ms): evidence only for times with several candidates; same answers (8,071 rows, identical md5) |
| W5 fix | #462 | After an offline reload the per-puzzle "Finished now" waits for the outbox (no second split); a tap never overwrites a split - found by v2's chaos run |
| L5 | this PR | Dark embed rows readable; desk Division column folded at phone width |
| L6 | this PR | 375 px polish: standing tabs wrap (`.standing-tabs`, desktop one row as before); judge pick chips show the label on one line, the name in up to 2 lines (full name in `title`), the order badge on the picture; per-puzzle split rows keep the label, the time wraps below |
| load | (tools) | `tools/competition-load` `MIX=real` |

**Chaos (full run, main 4c55c16a7):** all PASS (s01-s13, s14_continuous_scanning) except s14_puzzle_splits = the W5 bug; on
the fix (re/w5fix, QUICK) s14_puzzle_splits / s13 / s02 / s08 PASS.
**Load (local prod mode, MIX=real: 350 solo "Solve all 2" + 2 × 150 teams of 4, 18 judges, 1,550 phones, 180 proposals,
6 displays):** ~49,000 requests, 0 failures. p95: save 79 ms, save + puzzles 103 ms, split 79 ms, proposal 52 ms (180 in
1 s: 208 ms), heartbeat 5 ms, judge state 84 ms (155 in the burst), competitor state 39 ms, display state 16 ms; lock
waits 0.4 % (1.2 % in the burst, max queue 14). Saves ≈ 2 × T4 (three rounds share one event lock) - all far below 500 ms.
**Sandbox walk (Selenium, 1280 + 375 px, `cm-delivery/shots/re-walk/`):** both demos OK except the dark embed's even
rows and the SOLO desk's Division column at 375 px (both fixed in L5). Notes for the demo: the pairs standing's
championship tabs clip at 375 px and the judge page's puzzle picker cards cut long names at 375 px (both fixed in L6,
`cm-delivery/shots/re-l6/`); OPEN #2 shows "starts in
14 days" (demo date 2 Dec - re-run the command with `--date` of the demo day).

## v2 - the deferred items (2026-11-17/18; orchestrator: sub-session; brief `cm-delivery/brief-v2-orchestrator.md`)
Jan: "leftovers/unfinished - continue on implementation"; reliability and performance first; no flags. Design notes per
area (architect agents, `cm-delivery/design-v2/{judge-speed,displays-standings,studio,competitors-splits,editor}.md`);
the main session moved standings-on-the-stream to the front for the Speed Puzzle World Series demo (~2 Dec); the real
events team took `as_zero_pieces` (W1) and judge split times (W5). Delivered as PRs with CI as the gate (most of the
night was no-Docker mode); independent review agent on every risky package; a Docker window later ran the chaos suite
and browser walks (`cm-delivery/shots/v2/verify/`).

| Pkg | PR / commit | What |
|---|---|---|
| J1 | 2db77ef48 (direct) | Referees page: activity per referee ("23 results · last 10:42 (Group A)"), same statement count |
| J2 | 2db77ef48 (direct, reviewed) | Round state `entriesKnown=<hash>` → `entriesUnchanged` without entries (quiet 300-entry poll 171 KB → 4.8 KB); no HTTP 304 (a 3xx means "signed out" to the page); anything writing `serverEntries` calls `entriesValidator.changed()` |
| S1 | f89f89fe0 + ba960909b | Cached guest snapshot of a published standing (`PublicStandingSnapshots`, generation per event, `asGuest`: no blocklist, no allow-list unmasking) + public JSON `GET /api/competition-standings/{standingId}` (ETag/304, no ids, statuses passed through) |
| S2 | #423 | `standings` display element (auto pick, provisional/final, per-round columns, penalised total + missing pieces, row diff) |
| S4 | #428 (reviewed) | Public data-free topic `/competition-standings/{competitionId}`, live public standings table (fragment `public_standing_table`, guest HTML per generation, jitter 0-4 s, 5-min safety poll while live, 60 s without stream) |
| V1 | #424 (reviewed) | Analytics Studio public data-free signal `/analytics-studio-signal/{roundId}`, only for watched rounds; poll 30 s live / 10 s without |
| V2 | #426 + #434 (reviewed) | Round-wide prediction warm `WarmAnalyticsStudioPredictions` on its OWN queue `analytics_studio` (never `imports` = players' spreadsheet imports, never `async` = e-mails) + `round-predictions` answer; lily.srv `studio-consumer` (88696ca) |
| V3 | #430 | Studio prediction column + sort (solo, offered when `ready`) |
| C1 | #429 + #437 (reviewed) | Public competitor list `/{_locale}/competitor/{competitionId}` (search, 2 statements, canaries); unknown tag → list; competitor pages, list and proposals of events that are not publicly visible (draft, waiting for approval, rejected) are their team's only (closed a gap: unapproved events had public competitor pages) |
| J3 | #435 | "My tables" per phone + event: chip, "To do at my tables", Recent filtered; proposals/Puzzle missing never hidden |
| C5 | #439 | Participants sheet per-puzzle popover (read-only) + results desk deep link `?entry=` |
| J4 | #440 (reviewed) | Continuous scanning: warm camera, back to the scanner after a scanned Save, just-saved tag ignored, ambiguous frames ignored, released after 90 s idle / hidden; chaos `s14_continuous_scanning` |
| S5 | #447 + #450 (reviewed) | Standing mode `stages` (final classification across the bracket) + `numbered_across_stages`; displays show stage places/headers (S5b) |
| C2 | #436 (reviewed) | Proposal puzzle choice server: `puzzles` with the tap (a bad choice never costs the tap), `ChangeTimeProposalPuzzles` + endpoint (owner, pending, proposals on, before end + grace, `chosenAtMs` replay guard) |
| E1 | #449 + #463 | Displays editor: multi-select (shift/ctrl, marquee, Ctrl+A), align ×6 / distribute ×2, snapping to siblings, 3 px dead zone |
| L1+L2 | #451 (reviewed) | Live control: message per event + spotlight per round (chosen / latest finisher), phone page `competition_display_control`, stopwatch-page card, data-free `control` signal (overlay ~0.1 s after a tap); displays lock, never the participants lock |
| S6 | #460 | `bracket` display element (guest rule, SVG connectors) |
| fixes | #458, #463 | Scanner close button X visible; sheet Puzzles icon clickable; editor Arrange row fits at 1280 |
| C3 | #459 (reviewed) | Proposal puzzle choice on the competitor page (chips, outbox changes) + judge card pre-select (nothing saved until Confirm) |

Verified on main (Docker window): chaos QUICK all scenarios - s14_continuous_scanning PASS (scanner back in 4-16 ms,
getUserMedia once per page), s07 a flake (3/3 alone), s14_puzzle_splits fails on a scenario race (reported to real
events); walks OK for S2/S4 (live table 2.1 s after Publish), C1, J3, J4, V3, C5, E1, L1+L2 (private player masked).
All 6 locales for every new key (translated by the orchestrator).

Not verified: real phones (iOS camera warmth, Low Power), OBS over a colour source, the 500-viewer standings herd
(`tools/competition-load/`), a 300-entrant studio warm on production, C3 + S6 in a browser.

**v3 (consciously deferred, with reason):** organiser-assigned table ranges (per-phone My tables first; needs a referee
table model); typed per-puzzle split times on the judge card (Finished-now splits ship); editable per-puzzle record in the
sheet (the desk is the editor; a second editor = a second conflict path); a server-side revision counter for the round
state (the hash validator needed no migration); a studio signal dedupe (a 1 s dedupe could drop a burst's last save);
pair/team predictions (analytics-studio §11 Q2); per-display message/spotlight overrides and message expiry; referees
using the live control; copy/paste and grouping in the editor; `every` as its own round format; internal API writes of
format/labels; "drop worst round" / season standings; the `cards` tag layout; a 90-day prune of decided proposals; a
per-entry private Mercure topic for competitor pages (polling works); the one-tap proposal sweep (Jan ruled out
one-tap confirm from a list).

## Follow-ups 2026-11-18 (orchestrator: sub-session; brief `cm-delivery/brief-followups-orchestrator.md`)

Jan's decisions of 2026-11-18, delivered under no-Docker mode (CI as the gate), then walked + chaos-checked once Docker
was back.

| Pkg | PR | What |
|---|---|---|
| FU-W | #471 (reviewed) | Classic "add event" create retired: `add_competition` (incl. `?classic=1`, `?recurring=1`), `add_edition`, `add_editions` redirect to the wizard (GET 302, POST 303, nothing created; `?organization=` and the series kept); no "Use the classic form"; every create entry point → wizard; "Add edition"/"Add several dates" = the wizard's "Add the next dates" (already several dates); new calendar path for "Something else" (Basics → Review, no rounds, derived - no new column); dead classic create code removed; the classic form = edit form only (`creation-wizard.md` "Classic create retired" + "Calendar path") |
| FU-S | #469 | Analytics studio: an unpublished round shows the live ranking of the recorded (judge/desk-entered) results (`OfficialResultsRanking`, ties share), whatever the competitor page settings; no official/live labels; proposals never read; every result write signals; `round.liveResults` removed; public pages unchanged (`analytics-studio.md` "As built: FU-S") |
| FU-L | #470 | Link suggestions on the phone People list (filter, dialog, card line) and in the person editor ([Link] per candidate, R7-refused disabled), one read per page; "Help people link their profiles" = a Get ready tip (`ParticipantLinksTip`, ≤ 14 days, someone not linked → hub `#participant-links`) - not the sheet's setup checklist, which is gone by the time it applies (`participant-linking.md` as built) |
| FU-M | #468 | `myspeedpuzzling:prune-decided-time-proposals` (90 days by `decided_at`, pending never; lily cron 03:27, lily.srv `6851a4f`); JS twin `shapeRefusal()` = `RoundEntryPuzzles::fromWire()` shape rules (parity test; float `100.0` and object-as-list stay server-only, `round-formats.md`); chaos `s14_puzzle_splits` waits for an enabled "Finished now" |

Note for Jan: the studio needs no sign-in, so a running round's judge-entered times are visible there for every event
(read as his intent: "what judges entered is at the moment considered to be official").

Verified with Docker back (2026-11-18): judge-chaos `all` on main with #468-#471 - 18/18 PASS, incl.
`s14_puzzle_splits` (split 1 at 602 s for a tap at 603, sent 30 s later after an outage) and `s12c` (10 min frozen,
caught up at once).

**Round 2 (Jan's answers, 2026-11-18, PR FU-R2):** the studio's live ranking of an unpublished round follows the event's
competitor-pages live-results setting (pages + live results on → live ranking and result signals; off → nothing until
publish, no result signal; switch in the state cache key). UI fixes from the walks: studio names readable at 1280 px
(list column grows, names wrap); the person editor opened from a round tab pages through that round's people; the People
grid re-measures the sticky first column on resize (Name no longer covers Country); the place reads "City, Country"
without a stray " ," and wraps in the series About box / Next card. Walked both ways at 375 + 1280 px
(`cm-delivery/shots/fu/r2/`).

**FU-ST (Jan, 2026-11-19, #476, reviewed):** signed-in event staff (`COMPETITION_RESULTS_ENTRY`: organisers, team,
referees, admins) always see the live judge-entered ranking of unpublished rounds in the studio, whatever the
live-results setting - `analytics_studio_staff_state` on the main firewall, own cache key, `private, no-store`,
viewer-free; non-staff get the public answer. The public rule = `LiveResultsBeforePublishing`, shared with the competitor
page (the only public page that shows judge-entered times before publishing), parity test. Walked at 375 + 1280
(`cm-delivery/shots/fu/st/`). Open question: stream displays/overlays with live elements stay outside this rule (the
organiser's broadcast).

## SPWS 1:1 demo (2026-11-24; brief `cm-delivery/brief-spws-common.md`)
The Speed Puzzle World Series format reachable and configurable 1:1 for the organisers' demo (~2 Dec). One package per
row; each delivered to main = production.

| Pkg | PR | What |
|---|---|---|
| B1 | #484 | Unboxed per puzzle: `competition_round_puzzle.unboxed` (round switch OR row flag, moment = the round's first publish), page choice No / Every puzzle / Only some, hub "Unboxed: N puzzles", merge keeps it, "Add to my profile" ticks unboxed ([spws-unboxed-per-puzzle.md](../spws-unboxed-per-puzzle.md)) |
| A1 | #485 | Table names + name plates: optional per-round `table_labels` (A1, A1 L …; the number stays the identity, QR paths unchanged), seating "Table names" panel with a hall plan generator + "Use for" rounds, internal API, every surface shows/takes names (seating + print, desk, sheet, judge "a1l" opens without Enter + My tables, tags/tents, competitor page, displays, studio, export); tents "Time boxes for" a championship (a box per challenge + "missing") ([table-labels-and-plates.md](../table-labels-and-plates.md)) |
| B2 | #486 | Creation wizard: Challenges template (World Series rows, rounds named after challenges, standings with the World Series rules prefilled), labels + Unboxed per new puzzle on the Puzzles grid, standing rules on Every round counts + Extras, Review lines ([spws-wizard.md](../spws-wizard.md)) |
| C | #487 + walk fixes | The SPWS demo event on the sandbox: `myspeedpuzzling:sandbox:demo-events --event=spws-toronto-2027-demo` builds the confirmed format 1:1 (rounds, labels, table names A1-H22, secret puzzles + stopwatch reveal, per-puzzle unboxed, standings, organization, five navy + gold displays) + the guide for the call ([spws-demo-event.md](../spws-demo-event.md), [spws-demo-guide.md](spws-demo-guide.md)) |
| D | #488 | Configurable everywhere: wizard Extras "Name the tables like your hall plan" (one hall, places per table per category), a block "decided later" keeps "Challenge k" + "Solve all" when the Puzzles step is opened again (plan read from the round's name, 6 locales), duplicate page says what each round keeps and which puzzle labels / own Unboxed marks go with the puzzles, toolkit hub "· Tables A1 … ", internal API: round `PATCH` format, `PUT …/arrangement`, `PUT …/unboxed`, reads ([spws-wizard.md](../spws-wizard.md) "Package D") |
| UX | #491 | Fresh-eyes UX walk of every role on the sandbox (organiser, judge, competitor, displays, studio, wizard; 1440 + 375 px): name plates with time boxes keep each face to its half (printed team plates of 4 lost the table and every time box), the hub's claim-policy radios printed each label twice, competitor page rounds name their day + a challenge's own time, judge: "Didn't finish" on a block reads "1960 / 2000 pcs", one "Pieces missing" count for all puzzles said so, the finished-missing hint/error no longer show the Didn't-finish texts (two keys of the same name), the split-order error says "the round's clock, not the puzzle's own time"; studio "Solve all 2"; plates label a one-puzzle round's box by its puzzle label ("Challenge 5"), the seating hall-plan generator opens with the saved places (A1 L / A1 R = 2, left / right), the demo standings screen drops its "After <round>" line (rebuilds only); demo guide corrected (Teams C1+2 stopwatch not started, `a1` + Enter, no "Your competitor page" link). Leftovers: `docs/TODO.md` "SPWS demo UX walk" |
| UX2 | #493 | SPWS demo UX leftovers ([spws-demo-ux-leftovers.md](../spws-demo-ux-leftovers.md)): event page "Your competitor page" for a signed-in competitor (Taking part + next to Competitors, pages on only, +0 statements); judge: a typed table name opens after a 700 ms pause (`live_entry_table_pause.js`), Didn't finish on a solve-all block starts in the round's order (`solveAllOrder()`), "Order solved" / "Save order"; displays: visibility "When results are in" (`when.results: has_rows`) + leaderboard `nameLines`, SPWS overlay + hall screen use them (no empty panels at the start, names on 2 lines, wider board column); sandbox: the running block's stopwatch from its start (`StartSandboxDemoRoundStopwatch`, seeded within 60 min) - a future `--date` (the real dates) leaves it without results and stopwatch; hub claim policy wording; unboxed reveal note; studio team columns; wizard day carry-down. Rehearsal fixes: displays open behind the sandbox gate, a "Didn't finish" never counted as finished nor on the live board while the round runs, display field "Puzzle label" (challenge board headings), an early stopwatch start beats the schedule (timeline, Next round, round results page, competitor badge), studio no-image tile, made-up box pictures for the demo's secret puzzles |
| UX3 | #495 | A published round is done ([spws-demo-ux-leftovers.md](../spws-demo-ux-leftovers.md) §9): never "Next round" nor the round a following display picks, even with its schedule ahead (the demo on the real June 2027 dates) - `LiveResultsCurrentRound` + its offline port skip published rounds in "the next one", `EditionRoundDetail::isOverAt()` for the timeline (a round published while running stays Live) |
| CL | #496 | Competitor list: search by table ([competitor-pages.md](../competitor-pages.md) "Competitor list - search by table"): table names (`a1`, `A1 L`, `a1l`, `h21 r`) and plain numbers find people (their solo rounds' tables) and pairs/teams (their rounds' tables), any round; an exact table name first (A1 L before A10 L), "Table A1 L · <round>" on a row matched by its table; every row with a table shows it ("Table A1 L"); every team name a line-up played under is searchable; placeholder "Search by name, #code or table" (6 locales). No start numbers: `externalId` is the organiser's reference, never public. Still 2 statements |
