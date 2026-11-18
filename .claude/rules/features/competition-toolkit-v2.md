---
paths:
  - "src/**/*PublicStandingState*"
  - "src/**/PublicStandingSnapshot*"
  - "src/**/PublicStandingTable*"
  - "src/**/CompetitionStandingsLiveUpdates.php"
  - "src/**/DisplayStandings.php"
  - "src/**/DisplayBracket.php"
  - "src/**/*CompetitorList*"
  - "src/**/*DisplayControl*"
  - "src/**/*RoundSpotlight*"
  - "src/**/AnalyticsStudioSignals.php"
  - "src/**/*WarmAnalyticsStudioPredictions*"
  - "src/**/RoundEntriesHash.php"
  - "src/**/*ChangeTimeProposalPuzzles*"
  - "src/**/GetCompetitionReferees.php"
  - "assets/standing_live.js"
  - "assets/live_entry_my_tables.js"
  - "assets/live_entry_scan_session.js"
  - "assets/displays/arrange.js"
  - "assets/displays/control_picker.js"
  - "assets/competitor_list_filter.js"
---

# Competition toolkit v2 (the deferred items, 2026-11-18)

The as-built summary of the toolkit's v2 packages (record: `docs/features/competitions-management/toolkit/STATUS.md`
"v2"; the main toolkit rule is `.claude/rules/features/competition-toolkit.md`). Each line names its doc of record.

- **Public standings - one guest snapshot** (`standings.md` "Public JSON", "Live push"): `PublicStandingSnapshots`
  caches a published standing as a guest sees it (`GetStandingResults::publicView(..., asGuest: true)` - no viewer, so
  no blocklist and no allow-list unmasking; private players masked) per event **generation**; every signalled write
  bumps it through `CompetitionStandingsLiveUpdates` (forwarded from `CompetitionDisplayLiveUpdates`; an event without a
  published standing pays 0 statements warm - `CompetitionStandingsPresence`, pinned in `CompetitionDisplayLiveUpdatesTest`).
  Readers: the public JSON `GET /api/competition-standings/{standingId}` (ETag/304, no ids, statuses + `challengeTimes`
  passed through - never a closed list), the `standings` and `bracket` display elements, the public page's live table
  (`public_standing_table` fragment, guest HTML cached per generation + locale; `private, max-age=0, no-cache` - the
  `AnonymousCacheHeadersSubscriber` would otherwise make it shareable). Push: public data-free topic
  `/competition-standings/{competitionId}` `{"type":"standing.signal"}` after the response; `assets/standing_live.js`
  refetches with 0-4 s jitter, 5-min safety poll while live, 60 s without the stream. Unsignalled edits heal within 60 s.
- **`stages` standings** (`standings.md`): mode `stages` + `numbered_across_stages`; rounds from the category's bracket
  (`BracketLayout`), a stage counts once all its rounds are published (public) - never classify a half-published stage.
- **Displays live control** (`displays.md` "Live control"): `competition_display_control` (message per event, ≤ 200
  chars, 5 recent) + `competition_round_spotlight` (per round, chosen or latest finisher); writes under
  `CompetitionDisplaysLock`, never the participants lock; data-free `control` signal makes displays fetch at once;
  time/rank on a spotlight only when results may show (published or `source: live`). Phone page
  `competition_display_control`, stopwatch-page card (only for events with displays, cached presence).
- **Displays editor** (`displays.md` "Editor"): multi-select via `EditorState.selectedIds` (`selectedId` = one id or
  null - single-element code never acts on one of several); group maths in pure `assets/displays/arrange.js`; sibling
  snapping in `geometry.js` `options.lines`; one undo step per gesture.
- **Analytics studio v2** (`analytics-studio.md` R21-R34): signal `/analytics-studio-signal/{roundId}` `{type, roundId}`
  only for rounds watched in the last 180 s (`AnalyticsStudioWatch`), gated at publish time (public, published or live).
  Round-wide predictions: `WarmAnalyticsStudioPredictions` on its **own** `analytics_studio` queue (lily `studio-consumer`;
  never `imports` = players' spreadsheet imports, never `async` = e-mails), ≤ 8 s turns; `round-predictions` reads the
  same per-player cache as the entrant detail. Solo rounds only; nothing before a reveal.
- **Judge page v2** (`live-results.md`, `judge-offline.md`): referees-page activity (J1); round state `entriesKnown` →
  `entriesUnchanged` (J2; **anything writing the controller's `serverEntries` calls `entriesValidator.changed()`**);
  My tables per phone + event (J3, `live_entry_my_tables.js`, never hides proposals / Puzzle missing; typed or scanned
  tables always found); continuous scanning (J4, `live_entry_scan_session.js`: one camera per page, back to the scanner
  after a scanned Save, the just-saved tag ignored while in view, a frame with 2+ actionable codes acts on none, released
  after 90 s idle / hidden / pagehide; chaos `s14_continuous_scanning`).
- **Competitor list + visibility** (`competitor-pages.md`): `competitor_list` (2 statements, canaries); competitor
  pages, the list, tag landings and proposals of an event that is not publicly visible (draft / waiting for approval /
  rejected) are its team's only (`IsCompetitionPubliclyVisible`; the team check only for such events).
- **Proposal puzzle choice** (`competitor-pages.md`): `puzzles: [{position,label}]` with the tap - a bad choice never
  costs the tap (`puzzlesRefused`); `ChangeTimeProposalPuzzles` (owner, pending, proposals on, before end + grace,
  `chosenAtMs` replay guard); judges get round-puzzle ids, competitors labels only; the judge card pre-selects, nothing
  saved until Confirm.
- **Sheet per-puzzle popover** (`participants-spreadsheet.md`): read-only, "Edit on the results desk" → `?entry=` opens
  that entry's editor.
