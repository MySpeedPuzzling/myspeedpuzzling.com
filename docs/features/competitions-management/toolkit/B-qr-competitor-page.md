# B - QR modes, the public competitor page, participant time proposals

Architect B, competition toolkit build, 2026-11-17. Design only - grounded in the code on `main` (0ad091b37).
Depends on: A (round puzzle choice / K-of-N data model, reveal "when the stopwatch starts"), C (judge page - consumes
the "Contract for C" section below). Cross-cutting rules of `context.md` apply throughout.

---

## 1. Findings (what exists, with file:line)

### QR / scan route / name tags
- **The printed URL** `live_results_scan` = `/{_locale}/live/{competitionId}/p/{participantId}`
  (`src/Controller/LiveResults/LiveResultsScanController.php:43-52`). Organisers and referees
  (`CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY`, `:55`) land on `live_results` of the person's current
  round with `?entrant=<participantId>` (`:73-77`); no round → rounds page / event page with a flash (`:69-82`);
  **everyone else is redirected to the event page** (`:84-92`, drafts → events list). `private, no-store`,
  `X-Robots-Tag: noindex` (`:95-97`).
- **"Current round"**: `LiveResultsCurrentRound::pick()` (`src/Services/LiveResultsCurrentRound.php:25-63`) - running
  stopwatch (latest started; parallel halls) → started within 12 h → next upcoming → last. The scan route applies it to
  the person's own rounds first (`LiveResultsScanController.php:61-66`).
- **QR drawing**: `NameTagQrCode` (`src/Services/NameTagQrCode.php`) - bacon/bacon-qr-code SVG, EC level M, 240 px,
  cached per URL 90 days in `name_tag_qr_cache` (`:30,52-57`); `url()` hard-wired to `live_results_scan` (`:38-45`).
  The ~110-char URL is a version 7 code (doc comment `:21`).
- **Name tags page** `competition_name_tags` (`src/Controller/LiveResults/CompetitionNameTagsController.php:38-50`):
  `COMPETITION_EDIT` only (`:53`), `?sort=name|table`, `?round=`, `?waitlist=1`; template
  `templates/live_results/name_tags.html.twig` (112 lines, A4 sheet). `GetCompetitionNameTags` prints a linked
  player's `#CODE` **only for public profiles, whoever prints** (`src/Query/GetCompetitionNameTags.php` doc block) - the
  rule I extend to every printed tag.
- **In-page scanner** `assets/official_results_scan.js:13` regex accepts only `/{locale?}/live/{uuid}/p/{uuid}`;
  `parseNameTagUrl()` (`:20-40`) returns `{competitionId, participantId}`. Any new tag path must be added here (C's
  judge page uses this parser).
- **Locale-less link precedent**: `compete_link` `/compete/{token}` picks profile locale → browser → `en`
  (`src/Controller/Compete/CompeteLinkController.php:33-42`).

### Entries, tables, layout
- Participant = per competition (`CompetitionParticipant`, `player` link `src/Entity/CompetitionParticipant.php:30-31`,
  `connectedAt`). Round entry = `CompetitionParticipantRound` (solo; unique `(participant_id, round_id)`,
  `src/Entity/CompetitionParticipantRound.php:23`) or `CompetitionTeam` (pair/team, **per round**,
  `src/Entity/CompetitionTeam.php:36-38`); members of a team are the `CompetitionParticipantRound` rows with `team`
  set (`CompetitionParticipantRound.php:39-40`). `changeRound()` (`:49`) can move a solo entry to another round with
  the same id.
- `HasOfficialResult` (`src/Entity/HasOfficialResult.php`): `resultSeconds/PiecesPlaced/DidNotStart`,
  `resultEnteredAt/By`, `qualifiedAt`, **`tableNumber` per round, 1..9999, unique within the round, checked by the
  write path** (`:52-56`). Used by both entry entities (`CompetitionParticipantRound.php:26`, `CompetitionTeam.php:24`).
- **The table layout is not linked to table numbers.** `TableRow` → `RoundTable(label, position)` → `TableSpot(player |
  playerName)` (`src/Entity/RoundTable.php:21-40`, `TableSpot.php:19-33`, `TableRow.php:23-40`) are per round,
  generated/edited by `RoundTableManager`; nothing maps a `RoundTable` to `HasOfficialResult::tableNumber`
  (grep: `round_table` appears in no seating query). **Decision: a "table" in this design is the seating table number**
  (what referees type and what the seating page assigns); layout labels are not used.
- Entry addressing on the wire: `RoundEntryRef` = `participant_round:<id>` / `team:<id>`
  (`src/Value/RoundEntryRef.php`).

### Official results write path, live updates
- `RecordRoundResults` (`src/Message/RecordRoundResults.php:21-40`) - `SerializedByLock` with
  `CompetitionParticipantsLock::key($competitionId)`, `resultsOnly` for referees, changes = `RoundResultChange`
  (`clientChangeId`, target, field, `from`, `to` - `src/Value/RoundResultChange.php:14-54`), parsed by
  `RoundResultChangesParser`. Handler `RecordRoundResultsHandler::__invoke` (`src/MessageHandler/RecordRoundResultsHandler.php:74`)
  keeps `RoundResultChangeReceipt` per `clientChangeId` (`:149-150`; entity `src/Entity/RoundResultChangeReceipt.php:21-28`
  - replay never re-applies).
- **Precedent for "confirm something the participant produced"**: `ConfirmOnlineResult`
  (`src/Message/ConfirmOnlineResult.php:17-40`) carries `seenResult` as the `from`, and its handler calls
  `RecordRoundResultsHandler` in the same transaction and lock (`src/MessageHandler/ConfirmOnlineResultHandler.php:68,95`).
- Mercure: `OfficialResultsLiveUpdates` (`src/Services/OfficialResultsLiveUpdates.php`) - private
  `/round-results/{roundId}` (`:46-49`) and `/round-results/{roundId}/referees` (`:51-54`, private players withheld via
  `RoundResultEntry::forReferee()`), payload types `official_results.entries|refresh|round` (`:28-31`), published from
  controllers after dispatch, failures logged as warning (`:127-131`). **Clients ignore unknown `type`s**:
  `live_results_controller.js:510-528`, `results_overview_controller.js:74-76`, `round_seating_controller.js:286-296`,
  `participants_sheet/sheet_live.js:139-157` (switch without default action) - a new `official_results.proposals` type
  is safe.
- Per-page subscriber tokens: `OfficialResultsSubscription::forTopics()` (`src/Services/OfficialResultsSubscription.php:27-80`),
  never the cookie, topics listed one by one, 1 h.
- Round state JSON for the live entry: `official_results_round_state` (`src/Controller/OfficialResults/RoundResultsStateController.php:46-90`)
  - `serverNowMs`, `round`, `rounds`, `entries` (`RoundResultEntry::jsonSerialize()`, `src/Results/RoundResultEntry.php:131-157`),
  `mercure`.

### Stopwatch and the synced clock
- Public topic `/round-stopwatch/{roundId}` (`OfficialResultsSubscription::stopwatchTopic()` `:38-41`), published by
  `RoundStopwatchLiveUpdates::stopwatchChanged()` (`src/Services/RoundStopwatchLiveUpdates.php:40-60`, `private: false`).
  Payload `RoundStopwatchState` (`src/Results/RoundStopwatchState.php:12-27`): `status, startedAtMs, stoppedAtMs,
  minutesLimit, asOfMs, stateHash`. Public state endpoint `round_stopwatch_state` `/round-stopwatch-state/{roundId}`
  (`src/Controller/RoundStopwatchStateController.php:28-29`) adds `serverNowMs`.
- Browser clock: `assets/round_stopwatch_clock.js` - `createServerClock()` (`:150-205`: offset from the sample with
  the shortest RTT, 10-min window), `stopwatchView()` (phases idle/scheduled/running/times_up/stopped, `:60-…`),
  `isNewerState()` (asOfMs). Used by `round_stopwatch_controller.js:49-66` (hint from `serverNowMs`, two quick samples,
  Mercure + 3 s polling fallback). The live entry's "Finished now" = `floor((serverNow - startedAtMs)/1000)`
  (`live_results_controller.js:1060-1066`).
- Stopwatch semantics that matter for proposals (`docs/features/competitions-management/README.md` "Round Stopwatch"):
  a scheduled start is running once passed (nothing written); **a Stop in person is a pause and Start resumes by moving
  `startedAt` forward** - so "seconds" must be computed against the `startedAt` valid at the tap, not later.
- Online precedent for tap honesty: `OnlineEntryEvent` stores `clientMs` (phone's server-clock time), `perfMs`,
  `offsetMs`, `rttMs`, `receivedAtMs` (`src/Entity/OnlineEntryEvent.php:21-63`); offline finishes keep their time and
  are marked "arrived late" for the reviewer (online README §5.6 "Outbox").

### Secret puzzles
- `SecretPuzzleAccess` (`src/Services/SecretPuzzleAccess.php:21-33`) - viewer-based (organisers see their own secret
  puzzle). `CompetitionRoundPuzzle::isHiddenAt($now)` (`src/Entity/CompetitionRoundPuzzle.php:74-83`),
  `RoundPuzzleReveal` automatic/scheduled/manual with **the one moment computed only in `revealAt()` /
  `sqlRevealAt()` / `sqlHidden()`** (`src/Value/RoundPuzzleReveal.php`, guarded by `RoundRevealMomentComputedOnlyHereTest`).
  Public readers use `RoundPuzzleReveal::sqlHidden()` at `:now` + `puzzle.hide_until` (`src/Query/OccurrenceRounds.php:19-24,74-79`).

### Privacy on result surfaces
- `GetPublishedRoundResults` (`src/Query/GetPublishedRoundResults.php`): public only when `results_published_at` is set
  (`:96-99`), ranked with `OfficialResultsRanking` then hidden players dropped **without renumbering** (`:128-131`),
  pair/team with a hidden member hidden unless the viewer is a member (doc block), names = organiser's, private players
  keep the organiser's name and nothing of the profile. The competitor page follows exactly these rules.
- `HiddenPlayers` (`sqlExclude`, `isHidden`, empty for guests) and `PrivateProfileAccess` (`sqlIsPrivate` - the only
  unmasker) - `src/Services/HiddenPlayers.php:17-27`, `src/Services/PrivateProfileAccess.php:16-30`.
- Lock guard: `tests/Services/MessengerMiddleware/SerializedByLockMessagesTest.php:245-278` - any handler naming
  `CompetitionParticipant|ParticipantRound|Team|Round` must take the event lock or be in `NOT_LOCKED` with a reason.
- Canary tests present: `tests/BlocklistCanaryTest.php`, `PrivateProfileCanaryTest.php`, `DraftCanaryTest.php`,
  `TrackerlessTimeCanaryTest.php`, `SecretPuzzleRouteCanaryTest.php`, `SeriesPickCanaryTest.php`,
  `CustomValuesPrivacyCanaryTest.php`.
- Service worker: HTML navigations are network-only (`.claude/rules/service-worker.md:23`) - an open page survives
  offline, a reload offline does not (same gap as the live entry).

---

## 2. Settings (decided)

Four new columns on `competition` - all defaulting to today's behaviour, written only through one named method
`Competition::changeCompetitorPageSettings(CompetitorPageSettings $s)` (value object `src/Value/CompetitorPageSettings.php`):

| Column | Type / default | Meaning |
|---|---|---|
| `competitor_pages` | bool, `false` | Public competitor pages exist; scanning a tag as a non-organiser opens one instead of the event page |
| `competitor_pages_live_results` | bool, `false` | Confirmed results are shown on competitor pages **before** the round is published (rule 5: an explicit organiser choice). Ranks are never shown before publishing |
| `time_proposals` | bool, `false` | The linked competitor sees "Stop - propose my time" while their round runs. Requires `competitor_pages` (the handler normalises: off pages ⇒ off proposals) |
| `qr_tag_mode` | string(16) enum `QrTagMode`, `NULL` | `NULL` = today (person tags only, the name tags page unchanged). `entrant` / `table` / `round_entry` = the organiser's chosen mode: the default kind on the print page and the "QR mode" label on the settings page |

Why columns, not jsonb: the scan routes and the competitor page read them in the same statement as the competition row,
and `doctrine:schema:validate` covers them. Columns with defaults change no existing SQL result, no existing HTML.

**The mode never invalidates a printed tag.** Every tag kind's route always resolves (a person tag keeps working after
the organiser switches to table mode); the mode only decides what the print page offers first and what the settings page
explains. Per-round override of proposals is **not** in v1 (open question Q2).

**Where**: a new organiser page `competition_live_settings` - "Competitor pages & QR"
(`/en/competitor-pages-settings/{competitionId}`, localized, `COMPETITION_EDIT`, in-person events only), linked from the
participants sheet's Tools menu next to "Name tags" (`templates/participants_sheet/_tools_menu.html.twig`) and from the
rounds page. Form: QR mode (4 radios with a one-line example each), "Public competitor pages" switch, under it
"Show confirmed results before publishing" and "Let competitors propose their time" (disabled while pages are off).
Message `ChangeCompetitorPageSettings(competitionId, actingPlayerId, settings)` - touches only `Competition`, so no
participants lock is needed (`Competition` is not in the guard's entity list). Shown only to admins while
the organiser flag=0 (retired 2026-11-17) (rule 2) - the page 404s for others, the Tools menu link is hidden. If A or C
introduce a common "competition toolkit settings" hub, this form becomes one card of it (orchestrator to merge).

---

## 3. QR tags: kinds, URLs, resolution

### 3.1 The four tag kinds

| Kind (print) | URL encoded in the QR | Resolves to | Lifetime |
|---|---|---|---|
| Person (exists) | `/{_locale}/live/{competitionId}/p/{participantId}` - **unchanged** | the person: their current round's entry (solo or the pair/team they are in that round) | whole competition |
| Line-up (new) | `/live/{competitionId}/g/{lineupId}` | a pair/team **by its exact member set**, across rounds | whole competition, as long as the same people play together |
| Table (new) | `/live/{competitionId}/t/{tableNumber}` | whoever sits at table N in the current round (dynamic) | whole competition |
| Round entry (new) | `/live/{competitionId}/e/{entryId}` | that entry (`CompetitionParticipantRound.id` or `CompetitionTeam.id`) of that round, with its table | one round (static) |

New paths are **locale-less** (like `compete_link`): the redirect picks the language (profile → browser → `en`;
extract `CompeteLinkController`'s logic into a small `PreferredLocale` service and reuse it). Optional locale prefix is
also accepted (`/{_locale}/live/...`) so the scanner regex stays uniform.

QR sizes at EC level M (byte mode capacity v4 = 62, v5 = 84, v6 = 106, v7 = 122): table tag
`https://myspeedpuzzling.com/live/<uuid>/t/12` = 74 chars → **v5** (37×37 modules, far easier to read across a table than
today's v7); line-up and entry tags ~108 chars → v7, same as today's name tags. Shorter tokens (a 10-char base62 key in a
lookup table, → v3) were considered and rejected: they need a lookup table and break offline resolution on the judge's
phone, which today reads ids straight from the URL.

### 3.2 "A pair/team for the whole competition" = the line-up

Pairs/teams are per-round `CompetitionTeam` rows; they are deleted and rebuilt by the sheet, and the same pair is a
different row in every round. So the line-up id is **derived, never stored**:
`lineupId = Uuid::uuid5(competitionId, implode(',', sorted lower-case participant ids of the members))`
(`src/Value/CompetitionLineupId.php`, one static function + a SQL twin is not needed - the resolver loads the
competition's teams with their member ids in one statement and hashes in PHP).

- Same people in rounds 1-6 → one line-up id → one tag works all competition, even if the organiser rebuilt the team
  rows in between.
- The pair changes in round 4 (A+B → A+C): the A+B tag shows rounds 1-3 (and any later round where A+B play together);
  the page lists "A also played round 4 with C" as links to the members' person pages. The organiser prints new
  line-up tags for A+C if they want - the print page warns when a line-up of an earlier round no longer exists in later
  rounds ("3 line-ups changed after round 3").
- A line-up id no round has (any more) → public: competitor list of the event with a notice; organiser/referee:
  current round's live entry with `?notice=tag_unknown` (existing notice).
- Consistent with the site's exact-set rule for pairs & teams (`puzzling_team`).
- The person tag of a member keeps working for pair/team rounds (resolves to their team of the current round) - for
  most organisers person tags are enough; the line-up tag is for one card per table pair/team.

### 3.3 What a scan does (all kinds)

`LiveResultsTagController` (one single-action controller per new route: `LiveResultsTableTagController`,
`LiveResultsEntryTagController`, `LiveResultsLineupTagController`, sharing `ScanTarget` resolution in
`src/Services/CompetitionTagResolver.php`):

1. **Organiser or referee** (`COMPETITION_RESULTS_ENTRY`) → the live entry (or C's judge page, if C replaces it as the
   referee landing - C decides; the contract is the query string):
   - person: unchanged (`?entrant=<participantId>`).
   - table: round = `LiveResultsCurrentRound::pick()` over rounds that **have** an entry at table N, else over all;
     `live_results?roundId=&table=12` (new query param - the live entry opens table 12's entry, exactly what typing
     `12` + Enter does today, `live-results.md:37`).
   - entry: its own round (no picking) - `?entry=participant_round:<id>|team:<id>` (new param).
   - line-up: `pick()` over rounds where the line-up plays → `?entry=team:<id>` of that round.
2. **Anyone else, competitor pages on** → the matching competitor page (§4), `302`, `private, no-store`, `noindex`.
3. **Anyone else, competitor pages off** → exactly today: the event page (draft → events list). For the new kinds too.
4. Draft competition: as today - only editors get anything; everybody else the events list.

`live_results_scan`'s path and its organiser/referee branch are untouched; the only change is branch 2 (guarded by the
column), so an untouched competition behaves byte-identically (`LiveResultsLinksTest` stays green unchanged).

`assets/official_results_scan.js`: replace the single regex by `parseCompetitionTagUrl(text)` →
`{competitionId, kind: 'participant'|'table'|'entry'|'lineup', id}`; keep `parseNameTagUrl()` as a wrapper returning the
old shape for `kind === 'participant'` (existing callers and `tests/OfficialResultsEventsScriptsTest.php` untouched).

### 3.4 Printing - extending `competition_name_tags`

Same route, same controller, more query parameters (no new URL to learn):

| Param | Values | Default |
|---|---|---|
| `kind` | `people` (today) / `lineups` / `tables` / `entries` | `people`; with `qr_tag_mode` set: `entrant` → `people` (or `lineups` in pair/team-only events), `table` → `tables`, `round_entry` → `entries` |
| `layout` | `tags` (today's A4 grid, 2×5) / `cards` (A6, 4 per A4: table cards lying on the table) / `tents` (A4 landscape folded in half, the second half rotated 180° so both sides read from across the table) | `tags` for people/lineups/entries, `tents` for tables |
| `round` | round id - **required for `entries`**, optional for the others (people: only that round's people and its table numbers, as today; tables: only numbers used in that round) | first round by schedule for `entries` |
| `sort` | `name` / `table` | `table` for tables/entries |
| `waitlist` | as today (people only) | |

Card contents:
- **Table** (tent): "Table 12" huge (the number, no layout label - §1), event name + logo (`competition.logo`), QR (v5),
  small line "Scan to follow this table" when competitor pages are on, else nothing. No name: dynamic.
- **Entry** (per round): round name + start time, "Table 12", name(s) (organiser's), flags, `#CODE` only for public
  profiles (the existing name-tag rule), QR.
- **Line-up**: team name or "A & B", members with flags, QR.

The kind/layout switches render only when `qr_tag_mode IS NOT NULL` **or** `competitor_pages` is on; otherwise the
template output is today's byte for byte (pinned: `CompetitionNameTagsTest` unchanged + a new snapshot test with the
settings on). Queries: `GetCompetitionNameTags::forCompetition()` unchanged; new `GetCompetitionTableTags` (distinct
table numbers of a round or of all rounds - 1 statement), `GetRoundEntryTags` (a round's entries with members, table,
public-only codes - 1 statement), `GetCompetitionLineupTags` (teams with member ids → grouped by line-up id - 1
statement). `NameTagQrCode` gets `tableUrl()`, `entryUrl()`, `lineupUrl()`; `svg()` and its cache unchanged (cache key
= sha1 of the URL, so different kinds never collide).

---

## 4. The public competitor page

### 4.1 Routes

| Route | Path (en; cs and others translated like every page) | Notes |
|---|---|---|
| `competitor_person` | `/en/competitor/{competitionId}/p/{participantId}` | person |
| `competitor_lineup` | `/en/competitor/{competitionId}/g/{lineupId}` | pair/team line-up |
| `competitor_table` | `/en/competitor/{competitionId}/t/{tableNumber}` | dynamic table |
| `competitor_entry` | `/en/competitor/{competitionId}/e/{entryId}` | one round entry |
| `competitor_list` | `/en/competitor/{competitionId}` | everyone going (search box, client-side filter) - linked from the event page as "Competitors" only when pages are on |
| `competitor_state` | `GET /competitor-state/{competitionId}/{kind}/{id}` (locale-less JSON) | the page's live state (§4.4) |

All: 404 (`CompetitionNotFound`-style, the friendly one) when `competitor_pages` is off - **also for organisers**, so
nothing public exists by accident; drafts: 404 for non-editors (`isHiddenAsDraft()`); `noindex, nofollow`; HTML
`private, no-store` (the viewer's own Stop button and blocklist make it viewer-dependent). Guest `competitor_state` JSON:
`public, max-age=5` (guests have no blocklist / allow list - `HiddenPlayers` is empty for them), signed-in: `private,
no-store`.

### 4.2 What it shows

**Header** (one entrant: person / line-up / the entry; table page: "Table 12" + the current occupant block):
- Name(s) as the organiser recorded them, country flags, pair/team name.
- A linked player: avatar + `#CODE` + link to the profile **only when public to this viewer**
  (`PrivateProfileAccess::sqlIsPrivate()` in the query); a private one keeps the organiser's name and nothing else.
- Event name + link, "Back to competitors".

**Rounds** (the entrant's rounds in schedule order; table page: the current round first, then the other rounds where
table N was used, collapsed):
- Round name, start time in the event's zone (`event_time()`), category badge, table number (when the round uses them).
- Puzzle(s): only those the round **no longer keeps secret** - read with `RoundPuzzleReveal::sqlHidden('crp', 'cr',
  ':now')` + `puzzle.hide_until` like `OccurrenceRounds`, **for every viewer, organisers included** (a competitor
  page may be on a projector or in the organiser's hand at the table; the organiser's own view is the round puzzles
  page). A hidden round puzzle renders as "Puzzle revealed at the start" with no name, image, brand, pieces or id - not
  even in `data-` attributes or the state JSON. Once A ships "reveal when the stopwatch starts", it must be inside
  `sqlHidden()` (A's job, RoundRevealMomentComputedOnlyHereTest) - this page needs nothing else.
- Pick-one / K-of-N rounds (A's model): the revealed choices; once the entry's choice is recorded (A's entry-puzzle
  write path, or a confirmed proposal carrying it) "Solved: X (+ Y)".
- **Result**:
  - Round published (`results_published_at`): official result + **official rank** (`OfficialResultsRanking` over the
    round's ranked rows - same as `GetPublishedRoundResults`, rank kept even when other rows are hidden from the
    viewer), "Qualified" mark.
  - Not published, `competitor_pages_live_results` on: confirmed result, labelled "Not official yet", no rank.
  - Not published, setting off: nothing for the public. **The competitor themself** (a linked player of the entry)
    sees their own confirmed time labelled "Confirmed by the judge - not published yet" and their proposal status - it
    is their own time, which they watched being entered; never a rank.
  - Did not start / unfinished: as on the public round page.
- Blocklist: a person page of a player hidden from the viewer, and a line-up/entry page whose entry has a hidden linked
  member (unless the viewer is a member - `GetPublishedRoundResults`' group rule) → 404 for that viewer. Table page:
  the round block keeps the table and the clock but the occupant becomes "—" (no name, no result).

**Live part** (§4.3).

### 4.3 The live timer

Stimulus controller `competitor_page_controller.js` (lazy), using `round_stopwatch_clock.js` unchanged:
- Subscribes (anonymous EventSource, public hub URL) to `/round-stopwatch/{roundId}` of **the entrant's rounds within
  ±12 h** (table page: every round of the event within ±12 h - parallel halls), at most 12 topics.
- Clock offset: `createServerClock()`; `hint()` from the page's `serverNowMs`, two samples from
  `round_stopwatch_state` (exactly `round_stopwatch_controller.js:55-66`), refreshed on `visibilitychange` and every
  5 min.
- When a round of this entrant becomes `running` (push, or a scheduled start reached locally - `stopwatchStatusAt()`),
  the round's card turns into the clock: big `elapsed` (`stopwatchView()` phases incl. countdown "Starts in 0:00:09",
  `times_up` at the limit, `stopped` = paused), rAF rendering, nothing accumulates (same arithmetic as the projector -
  the page follows the round stopwatch to the frame). Screen Wake Lock while running on the competitor's own page.
- On every stopwatch push, and once at each round puzzle's known reveal moment (`nextRevealAtMs` in the state - a
  moment, never the puzzle), the page fetches `competitor_state` (debounced 0-1 s random spread to protect the server
  from 300 phones at a start) and re-renders the round cards from it. Mercure down: poll state every 15 s while a round
  is running/scheduled and the tab is visible, else every 60 s.
- Never anything secret on the public topic: the stopwatch payload is unchanged.

### 4.4 Queries (statement budget)

`GetCompetitorPage` (`src/Query/GetCompetitorPage.php`), returning `CompetitorPageView`:
1. Competition + settings + draft flag (1 statement; through `IsCompetitionPubliclyVisible` or listed in
   `DraftVisibilityCoverageTest` with "editor-aware 404" reason).
2. The entrant's entries over all rounds - person: `competition_participant_round` of the participant (+ its team,
   members as JSON); line-up: teams with member ids, filtered by line-up id in PHP; table: entries with
   `table_number = :n` of all rounds; entry: one row - with `HiddenPlayers::sqlExcludeTeam()`/`sqlExclude()` and
   `PrivateProfileAccess::sqlIsPrivate()` fragments (1 statement).
3. Round puzzles of those rounds, `sqlHidden` at `:now` decides `revealed` (1 statement; hidden rows return only
   `round_id` + reveal moment).
4. Ranks: new `GetPublishedRoundResults::ranksOf(list<roundId>)` - every ranked row of the published rounds among them
   in one statement, ranked in PHP with `OfficialResultsRanking` (only when a round is published).
5. Signed-in viewer linked to an entry shown: their proposals of those rounds (1 statement, §5).

Budget pinned by `CompetitorPageQueryBudgetTest`: guest ≤ 4, signed-in ≤ 5, `competitor_state` the same.
`competitor_state` returns the same view as JSON (`CompetitorStateView`): `serverNowMs`, `rounds[] {roundId, name,
startsAt, category, tableNumber, stopwatch (RoundStopwatchState), puzzles[] (revealed only), nextRevealAtMs, result,
rank, published, entry (ref - only for the viewer's own entries), proposal (own only)}`, `mercure` (own private token,
§5.6) and `canPropose`.

---

## 5. Propose a time

### 5.1 Who and when

The Stop button ("Done - propose my time") renders when **all** hold:
- `time_proposals` (and `competitor_pages`) on; the event is in person (online rounds have their own finish flow).
- The viewer is signed in and is the `player` of a non-deleted participant of **this entry** (solo: the participant;
  pair/team: any member's participant) - the URL kind does not matter: a table card or an entry card at your own seat
  works the same as your person page. Somebody else's page never shows it, and the server checks again (§5.3).
- The entry's round stopwatch is `running` at the server clock (not scheduled, not paused, not past the limit).
- The entry has no official result yet and no pending proposal.

Not signed in but the page belongs to a linked participant? A small "Sign in to propose your time" (return URL through
`ReturnUrl`) - shown only before the round starts, never as a nag during it.

### 5.2 Data model - `competition_time_proposal`

Entity `CompetitionTimeProposal` (`src/Entity/CompetitionTimeProposal.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | **the phone's UUIDv7, made at the tap** - idempotency: a resend is acknowledged, never stored twice |
| `round_id` | FK `competition_round`, `ON DELETE CASCADE` | |
| `participant_round_id` | FK nullable, cascade | solo entry |
| `team_id` | FK nullable, cascade | pair/team entry (exactly one of the two - CHECK constraint `custom_time_proposal_one_entry`) |
| `proposed_by_participant_id` | FK `competition_participant` | who tapped (the participant, not the player - survives disconnect, safe for the referee topic) |
| `proposed_by_player_id` | FK `player`, `SET NULL` | audit |
| `status` | string(16) enum `TimeProposalStatus` | `pending` / `confirmed` / `rejected` / `superseded` / `withdrawn` |
| `tapped_at_ms` | bigint | the phone's server-clock time of the tap (`clock.serverNow()`) |
| `received_at_ms` | bigint | server receipt |
| `clock_offset_ms`, `clock_rtt_ms` | float nullable | what the phone's clock used (diagnostics, like `OnlineEntryEvent`) |
| `stopwatch_state_hash` | string(32) | `RoundClock::stateHash()` the phone timed against |
| `started_at_ms` | bigint | the stopwatch start the server used for `proposed_seconds` |
| `proposed_seconds` | int | `floor((tapped_at_ms - started_at_ms) / 1000)` |
| `receipt_seconds` | int | `floor((received_at_ms - started_at_ms) / 1000)` - what a server-only rule would say |
| `late_by_ms` | int | `received_at_ms - tapped_at_ms` (≥ 0) |
| `clock_changed` | bool | the phone's stateHash ≠ the stored one at receipt (pause/resume, reset) |
| `puzzle_ids` | jsonb list, default `[]` | pick-one / K-of-N choice (A's model), may be filled after the tap |
| `decided_at` | timestamp nullable | |
| `decided_by_player_id` | FK `player` nullable, `SET NULL` | the judge/organiser |
| `decided_seconds` | int nullable | the official seconds written at confirmation (may differ - "changed by the judge") |
| `decision_reason` | string(32) nullable | `rejected`: `not_finished` / `wrong_entry` / `other`; `superseded`: `result_recorded` |

Indexes (generated by `doctrine:migrations:diff`, plus two custom ones per `.claude/rules/database.md`):
`custom_time_proposal_pending_solo` UNIQUE `(participant_round_id) WHERE status = 'pending'`,
`custom_time_proposal_pending_team` UNIQUE `(team_id) WHERE status = 'pending'` - the backstop of "one pending per
entry"; plain index `(round_id, status)` for the judge's state. Rows are kept with the round (cascade); a prune of
decided rows older than 90 days goes into the existing receipts prune command.

Statuses:
- `pending` - waiting for the judge. **One per entry**: a second tap (same phone or a teammate's) while one is pending
  is refused `already_proposed` and the existing one is shown on both phones.
- `withdrawn` - "Undo" by any linked member while pending. The global clock never stopped, so the competitor simply taps
  again when really done. No time window - undo is possible until the judge decides.
- `confirmed` - the judge wrote the official result through this proposal (`decided_seconds` may differ).
- `rejected` - the judge said no (puzzle not complete, wrong table...). The competitor may propose again if the round
  still runs.
- `superseded` - an official result was recorded for the entry by another path (desk, sheet, typed time) while this
  was pending; also set when the round's stopwatch was reset.

### 5.3 Timing honesty (decided)

The phone computes nothing official. At the tap it freezes the shown time, writes the proposal into its outbox with
`tappedAtMs = clock.serverNow()` + `offsetMs`, `rttMs`, `stateHash`, and sends. The server (`ProposeRoundTimeHandler`)
at receipt (`receivedAtMs` = `ClockInterface::now()` in ms - taken in the controller **before** the dispatch waits for the
lock, passed in the message):

1. Refuse `clock_ahead` when `tappedAtMs > receivedAtMs + 2000` (a tap in the future is impossible beyond a sane clock
   error; 2 s covers the offset estimate's error of RTT/2).
2. Refuse `not_running` when the stopwatch (as stored) was not running at `tappedAtMs` (`RoundStopwatchStatus::at()`
   with the tap moment), `times_up` when `tappedAtMs > startedAt + minutesLimit` (unfinished results are the judge's
   job - pieces count).
3. `proposed_seconds = floor((tappedAtMs - startedAtMs)/1000)` against the **stored** `startedAt`; if the phone's
   `stateHash` differs (a pause/resume moved `startedAt` since the phone's last state), set `clock_changed = true` -
   the judge sees a warning; nothing is refused.
4. `late_by_ms = receivedAtMs - tappedAtMs`. **Tolerance 5 s**: up to 5 s is a normal mobile network (plus the
   outbox's first retry) and is shown to nobody; above it the judge sees "Sent 2 min 10 s after the tap (offline?)" and
   both times (`proposed_seconds` and `receipt_seconds`). A tampering client can therefore gain at most ~5 s unnoticed,
   and a judge standing at the table confirms against the puzzle in front of them - a proposal is never official on its
   own. Offline taps are accepted up to the round end + 30 min; later = refused `too_late` (the judge has the result by
   then).
5. Why tap time and not receipt time: on a venue's congested Wi-Fi, a receipt-time rule would systematically punish
   honest players by seconds and make the proposals useless for the judge; tap time with a visible lateness flag keeps
   both honesty and accuracy (the online rounds made the same call, `OnlineEntryEvent.clientMs`/`receivedAtMs`).

Further refusals: `proposals_off`, `not_your_entry` (403), `entry_not_in_round`, `result_recorded` (the entry has an
official result - the phone then shows it), `already_proposed`, `rate_limited` (Symfony RateLimiter
`time_proposal`: 10 per player per round per 10 min - a stuck button cannot flood the judge).

### 5.4 Messages and handlers

All `SerializedByLock` with `CompetitionParticipantsLock::key($competitionId)` - they read entries and must not race
`RecordRoundResults` ("no proposal once a result exists", "one pending" checked under the same lock as the result write).
The lock is held ~5-10 ms per proposal; 150 taps in the last minutes of a round queue for well under a second total.
Measure in WP-5 (100 concurrent proposals, p95 < 500 ms) - if not met, fall back to a per-round lock key and list the
messages in `NOT_LOCKED` with the reason "the partial unique index + a re-check inside RecordRoundResultsHandler keep
the invariants".

| Message | Who dispatches | Does |
|---|---|---|
| `ProposeRoundTime(competitionId, roundId, entryRef, proposalId, actingPlayerId, tappedAtMs, receivedAtMs, offsetMs, rttMs, stateHash, puzzleIds)` | `competitor_propose` controller | §5.3; creates `pending`; idempotent on `proposalId` (known id ⇒ answer its current state) |
| `WithdrawTimeProposal(competitionId, proposalId, actingPlayerId)` | competitor | pending → withdrawn (a linked member only); not pending ⇒ answer the state, no error |
| `ChangeTimeProposalPuzzles(competitionId, proposalId, actingPlayerId, puzzleIds)` | competitor | while pending; puzzle ids must be **revealed** puzzles of the round (`SecretPuzzleAccess` not involved - the round puzzle's `isHiddenAt(now)` decides; a hidden one = `unknown_puzzle`, never "hidden") and fit A's rule (pick-one: 1, K-of-N: ≤ K) |
| `RejectTimeProposal(competitionId, roundId, proposalId, actingPlayerId, clientChangeId, reason)` | judge (C) | pending → rejected; replay-safe through `RoundResultChangeReceipt`-like idempotency: status already rejected ⇒ "unchanged" |
| (confirmation) | judge (C) | through `RecordRoundResults` - see §5.5 |

Handlers only persist; Mercure from the controllers after `dispatch()` (§5.6). `ResetInterface` not needed (no state).

### 5.5 Confirmation = an ordinary official result change

Confirmation is **not** a separate write path: it is a `RecordRoundResults` change with one new optional wire field,
`proposalId`, so C's offline outbox, receipts, three-way check, referee `resultsOnly` and every existing guarantee apply
unchanged.

- `RoundResultChange` gains `null|string $proposalId` (parsed by `RoundResultChangesParser` for field `result` only;
  anything else with it = `unreadable`).
- `RecordRoundResultsHandler`, after applying the plan, calls `TimeProposalResolver::afterResults($round, $outcomes,
  $actor, $now)` (new service, same transaction):
  - a change **with** `proposalId` that went through (`applied` / `unchanged`): that proposal, if `pending` and of that
    entry, → `confirmed`, `decided_seconds` = the written `resultSeconds`, `decided_by` = the acting player. If the
    result written is not a finished time (judge turned it into pieces/DNS) → `rejected` with `not_finished`. If the
    proposal was withdrawn meanwhile, the result still stands (the judge saw the puzzle) and the outcome carries
    `proposal: "not_pending"`. Puzzle choice: if the change also carries `puzzleIds` (A's field on the entry), A's
    entry-puzzle write runs in the same handler.
  - any other applied result change (desk, sheet, typed time, import) on an entry with a `pending` proposal: same seconds
    ⇒ `confirmed` (judge typed the same), otherwise `superseded` (`result_recorded`). A result **cleared** leaves
    proposals alone.
  - the stopwatch reset handler sets the round's pending proposals `superseded`.
- "Entered by" on the entry stays the judge (`recordResult(..., enteredBy: judge)`); the proposal row is the "proposed
  by" trail (`proposed_by_participant_id`, `tapped_at_ms`). The results desk / sheet may show a small "proposed by the
  competitor" mark later (not in this build).
- `RecordedRoundResults` outcomes gain an optional `proposal: {id, status}` per change, so C's client knows the result.

### 5.6 Live updates

`TimeProposalLiveUpdates` (`src/Services/TimeProposalLiveUpdates.php`, controller-called after commit, warning on
failure like `OfficialResultsLiveUpdates`):
- **Judges/organisers**: `official_results.proposals` on `/round-results/{roundId}` **and** `/round-results/{roundId}/referees`
  (identical payload - proposals carry participant ids and organiser-recorded names only, nothing of a private profile),
  shape in §6.
- **The competitor's own phones**: a private topic per entry `/competitor-entry/{roundId}/{entryRef}`, payload
  `{type: "competitor.proposal", proposal: TimeProposalView}` and `{type: "competitor.refresh"}` (result recorded
  without a proposal). The token comes in the competitor state (`OfficialResultsSubscription::forTopics()` with the
  viewer's own entry topics only, 1 h, issued only after the server checked the viewer is linked to those entries).
- `RecordRoundResultsController` publishes the resolver's touched proposals the same way (both topics) - only when the
  resolver touched something, so untouched competitions publish exactly what they publish today.

### 5.7 What the competitor sees

1. Running round: big clock + "Done - propose my time" (full-width, 64 px, confirm-free: one tap - a mistaken tap is
   undone with "Undo").
2. After the tap: the clock freezes at the tapped value, "45:12 - waiting for the judge", "Undo" link; if the round has
   a puzzle choice: chips of the revealed puzzles, "Which did you solve?" (optional; the tap was already sent).
   Offline: "Saved on this phone - it will be sent when you are online" (outbox state).
3. Confirmed: "Your time 45:12 - confirmed by the judge" (or "Confirmed as 45:15 - you proposed 45:12").
   Rejected: "The judge did not accept this time" + the Stop button again while the round runs. Superseded: "The judge
   recorded 45:20".
4. After publishing: official result + rank (§4.2).

### 5.8 Offline / poor network on the competitor's phone

`assets/competitor_proposals_outbox.js` - a thin instance of the existing `official_results_outbox.js` pattern
(IndexedDB, one sending tab via Web Locks, backoff, `beforeunload` warning while unsent) with its own database name
(`msp_competitor_proposals`), items keyed by the proposal UUIDv7. The tap moment is fixed at the tap (`tappedAtMs` from
the synced clock captured synchronously in the click handler, before any `await`). Resent until acknowledged; an answer
`result_recorded` / `already_proposed` / refusal drops the item and shows why. A reload while offline loses the page
(service worker is network-only for HTML - unchanged) but not the proposal: the next load sends it. The phone's clock
offset is refreshed before the round (state fetches) so an offline tap still has a fresh offset; a stale offset
(> 10 min old) is sent with the tap and shown to the judge as part of the lateness line.

---

## 6. Contract for C (judge page) - mandatory

### 6.1 Proposals in the round state

`GET official_results_round_state` (`RoundResultsStateController`) gets a key `proposals` **only when the competition
has `time_proposals` on** (absent otherwise - state of untouched competitions unchanged): the round's `pending`
proposals plus those decided in the last 10 minutes (so a judge sees "confirmed by Petra" right after another judge
did it).

```json
"proposals": [
  {
    "id": "0193f6c2-7c1e-7a4b-9d2e-6f1a2b3c4d5e",
    "roundId": "0193f6aa-…",
    "entry": "team:0193f6b0-…",
    "tableNumber": 12,
    "status": "pending",
    "proposedSeconds": 2712,
    "receiptSeconds": 2713,
    "tappedAtMs": 1763390000123,
    "receivedAtMs": 1763390001010,
    "lateByMs": 887,
    "late": false,
    "clockChanged": false,
    "puzzleIds": ["0192…"],
    "proposedBy": { "participantId": "0193…", "name": "Jana Nováková" },
    "decided": null
  }
]
```

- `entry` = `RoundEntryRef::toString()`; `tableNumber` duplicated for C's table-number-first UI (may be null).
- `late` = `lateByMs > 5000`; when true C must show both `proposedSeconds` and `receiptSeconds` and the lateness.
- `proposedBy.name` = the organiser-recorded participant name (never a profile name - safe on the referee topic).
- `puzzleIds` only ever contains revealed puzzles (the handler refuses hidden ones).
- `decided` when not pending: `{ "at": ATOM, "byPlayerId": "…", "byName": "…", "seconds": 2715|null, "reason": null|"not_finished"|"wrong_entry"|"other"|"result_recorded" }`.
- Status values: `pending | confirmed | rejected | superseded | withdrawn`.

### 6.2 Mercure

Topic: the round's existing private topics - organisers `/round-results/{roundId}`, referees
`/round-results/{roundId}/referees` (C's page already holds a token for one of them via
`OfficialResultsSubscription::forRound()`). Payload:

```json
{ "type": "official_results.proposals", "roundId": "…", "proposals": [ /* TimeProposalView as above, the changed ones */ ] }
```

Sent on: created, withdrawn, puzzles changed, confirmed, rejected, superseded. Merge by `id`; a proposal whose `status`
is not `pending` leaves C's "to confirm" list. Missing/late pushes: C's periodic state fetch is authoritative
(`asOf` ordering as for entries). More than 50 changed at once (a stopwatch reset) ⇒ `official_results.refresh` instead.

### 6.3 Confirm = `official_results_record` with `proposalId`

`POST /{_locale}/official-results/rounds/{roundId}/changes` (existing `RecordRoundResultsController`), one change:

```json
{
  "changes": [
    {
      "clientChangeId": "0193f6d0-…",
      "target": "team:0193f6b0-…",
      "field": "result",
      "from": null,
      "to": { "seconds": 2712 },
      "proposalId": "0193f6c2-7c1e-7a4b-9d2e-6f1a2b3c4d5e"
    }
  ]
}
```

- `from` = the result the judge's state showed (normally `null`) - the existing three-way rule; a result entered
  meanwhile comes back as a conflict, as today.
- `to` may differ from `proposedSeconds` (the judge edited it) ⇒ proposal `confirmed` with `decided.seconds` = `to`.
- Works from C's offline outbox unchanged; replay = receipt "unchanged", the proposal is not touched twice.
- Referees (`resultsOnly`) may confirm (it is a `result` change).
- Answer per change as today plus `"proposal": {"id": "…", "status": "confirmed"|"rejected"|"not_pending"|"unknown"}`.
- Puzzle choice (A): if A's wire adds `puzzleIds` to a result change, C sends the proposal's `puzzleIds` (or the
  judge's correction) in the same change.

### 6.4 Reject

`POST /{_locale}/official-results/rounds/{roundId}/proposals/{proposalId}/reject` (new
`RejectTimeProposalController`, `OfficialResultsApi` conventions: JSON, 401 `sign_in_required`, 403 `forbidden`, CSRF as
the changes endpoint, `CompetitionResultsEntryVoter`):

```json
{ "clientChangeId": "0193f6d1-…", "reason": "not_finished" }
```

Answer `{ "proposal": TimeProposalView }` (200 also when it was already rejected - idempotent; 409 `not_pending` with
the view when it was confirmed/withdrawn/superseded). C may queue it in its outbox.

### 6.5 Scanner and landing

- `parseCompetitionTagUrl(text)` in `assets/official_results_scan.js` →
  `{competitionId, kind: 'participant'|'table'|'entry'|'lineup', id}` (`id` = participant id / table number as string /
  entry id / line-up id). C resolves offline from its cached state: participant → its entry in the round, table →
  `tableNumber`, entry → `ref` by id, line-up → needs the member ids: the round state's team entries get a `lineupId`
  field (computed, `CompetitionLineupId`) - added to `RoundResultEntry::jsonSerialize()` for team entries only when the
  competition has competitor pages or a QR mode set.
- A scan URL opened by the phone camera lands on `live_results` with `?entrant=` (existing), `?table=12` or
  `?entry=<ref>` (new). If C replaces the landing page, it must accept the same three parameters.

---

## 7. Privacy, secrets, canaries

| Surface | Blocklist | Private profiles | Drafts | Secret puzzles |
|---|---|---|---|---|
| Competitor pages + `competitor_state` | hidden player's person/entry/line-up page = 404; table page masks the occupant; ranks not renumbered | `sqlIsPrivate()`: no avatar/#code/link, organiser name only | 404 for non-editors | `sqlHidden` at `:now`, for everybody; hidden puzzle = no name/id/image anywhere in HTML or JSON |
| `competitor_list` | hidden players left out | same | same | n/a |
| Printed tags | organiser tooling, no blocklist (as name tags) | `#CODE` only for public profiles | editors only | no puzzle on any tag |
| Proposal views (judge topics) | n/a (organiser tooling) | participant names only | n/a | revealed puzzle ids only |
| Competitor's private topic | own entry only | own data | n/a | revealed only |

Canary tests to extend:
- `BlocklistCanaryTest` - `competitor_person` / `competitor_lineup` / `competitor_entry` (404) and `competitor_table`,
  `competitor_list`, `competitor_state` (absent names).
- `PrivateProfileCanaryTest` - all competitor routes + `competitor_state`: a private linked player's `#code`, profile
  name, avatar URL and id never appear.
- `DraftCanaryTest` - every competitor route and the new scan routes for a draft event (no 200 for a guest, no redirect
  into the draft).
- `SecretPuzzleRouteCanaryTest` - competitor pages + state with a secret round puzzle (manual reveal and automatic in
  the future): its name, id, image path and EAN absent; and the state 1 s after the reveal moment contains it.
- `TrackerlessTimeCanaryTest` - **not applicable** (no `puzzle_solving_time` read; the page reads official results on
  entries). State this in the test's exclusion list if it enumerates routes.
- `SeriesPickCanaryTest`, `CustomValuesPrivacyCanaryTest` - not applicable (no times, no custom fields).
- `DraftVisibilityCoverageTest` - `GetCompetitorPage`, `GetCompetitionTableTags`, `GetRoundEntryTags`,
  `GetCompetitionLineupTags` either use `IsCompetitionPubliclyVisible` or are listed with their reason.
- `SerializedByLockMessagesTest` - the four proposal messages take the lock (no `NOT_LOCKED` entry);
  `ChangeCompetitorPageSettings` touches only `Competition` (not in the guard's list).

## 8. Byte-identity for untouched competitions

- `live_results_scan`: same path, same answers for every viewer while `competitor_pages = false`.
- `competition_name_tags`: identical HTML while `qr_tag_mode IS NULL` and pages off (no switches rendered).
- Event/edition pages: the "Competitors" link renders only with pages on (`DetailPagesQueryBudgetTest` unchanged - the
  setting rides on the competition row already loaded; verify the event detail query selects `c.*` or add the column
  to it).
- `official_results_round_state`: no `proposals` key unless `time_proposals`; `RoundResultEntry` no `lineupId` unless
  configured; `RecordRoundResults` outcomes no `proposal` key unless the change carried `proposalId` or the resolver
  touched one (it can only when a proposal exists).
- `RecordRoundResultsHandler`: the resolver runs one indexed `SELECT … WHERE round_id = ? AND status = 'pending'` -
  **skipped entirely when the competition's `time_proposals` is false** (already loaded with the round), so the
  statement count of untouched results writes is unchanged (pin in the handler's existing statement-count test if one
  exists, else add one).
- No Mercure publish of a new type for untouched competitions.

## 9. Test plan

Unit / handler (`vendor/bin/phpunit <file>`, then paratest):
- `CompetitionLineupIdTest` - order-independent, competition-scoped, stable.
- `CompetitionTagResolverTest` - each kind × organiser/referee/guest/linked player × pages on/off × draft; table
  picking across parallel running rounds; line-up gone; entry moved by `changeRound()`.
- `ProposeRoundTimeHandlerTest` - happy path solo and team; idempotent resend; not linked (403); a teammate while
  pending (`already_proposed`); after a result (`result_recorded`); scheduled-not-yet (`not_running`); paused
  stopwatch; past limit (`times_up`); `clock_ahead` at +2.1 s; late at 5.1 s flagged; `too_late`; `clock_changed`;
  proposals off; rate limit.
- `TimeProposalResolverTest` (through `RecordRoundResultsHandler`): confirm with `proposalId` (same / edited seconds),
  pieces ⇒ rejected, withdrawn meanwhile ⇒ result stands + `not_pending`, typed result without id ⇒ confirmed (same
  seconds) / superseded, replay ⇒ untouched, resolver skipped when proposals off (statement count).
- `RejectTimeProposal`, `WithdrawTimeProposal`, `ChangeTimeProposalPuzzles` (hidden puzzle ⇒ `unknown_puzzle`).
- Partial unique indexes: a raw double insert fails (backstop test).

Controller / query:
- `CompetitorPageControllerTest` per kind: published / unpublished × live-results setting × own entry; ranks equal
  to the public round page's; hidden puzzle; 404s.
- `CompetitorPageQueryBudgetTest` (guest 4, signed-in 5).
- `CompetitionNameTagsTest` unchanged + `CompetitionTagsPrintTest` (kinds, layouts, round filter, sort, `#CODE` rule,
  byte-identical default).
- `LiveResultsLinksTest` unchanged + new tag routes.
- Canary additions §7.

JavaScript (node harnesses like `tests/round-stopwatch-harness.mjs`):
- `parseCompetitionTagUrl` (every kind, locale/no locale, other hosts, garbage) - extend
  `tests/OfficialResultsEventsScriptsTest.php`.
- Competitor clock: running/scheduled/paused/times-up rendering from states (pure functions in
  `assets/competitor_page.js`), tap captures `serverNow()` synchronously, outbox resend keeps `tappedAtMs`.

Browser walk (before merge, dev Selenium): organiser starts a round on the stopwatch page; a guest phone on a table tent
QR sees the clock start within 1 s; the linked competitor taps Stop; the judge (C's page or the live entry) sees the
proposal, confirms with an edit; the competitor sees "Confirmed as …"; offline tap (DevTools offline) then online.
Load: 100 concurrent proposals against the lock (p95 < 500 ms), 300 guest pages on one stopwatch start (state fetch
spread).

## 10. Risks

1. **Lock contention at round end** (proposals under the event lock alongside the judges' writes) - measured in WP-5;
   fallback per-round key documented in §5.4.
2. **Thundering herd** on a stopwatch start (hundreds of public pages fetching state) - random 0-1 s spread, guest JSON
   `max-age=5`, the state is ≤ 4 statements; Mercure carries the clock itself, so the fetch only refreshes cards.
3. **Early reveal** through the competitor page - mitigated by using `sqlHidden` for everybody (no viewer exemption)
   and the SecretPuzzleRouteCanary; A's new reveal mode must land inside `sqlHidden`.
4. **Line-up semantics surprise** an organiser who changes pairs between rounds - the print page warns; person tags
   always work.
5. **Tap-time tampering** (≤ 5 s unnoticed) - judges confirm at the table; lateness and receipt time shown.
6. **Entry tags die** when the organiser rebuilds a pair/team after printing (team id changes) - entry/line-up tags
   prefer line-up semantics; the entry page of a deleted team answers "This card is from an earlier seating" + the
   event's competitor list; organisers/referees get `tag_unknown`.
7. **Phones asleep** - Wake Lock only on the competitor's own page while running; the clock recomputes on wake.

## 11. Open questions (with the default I chose)

- Q1. Should the competitor see their own confirmed time before publishing? **Default: yes, own entry only, no rank.**
- Q2. Per-round opt-out of proposals (e.g. a final judged only by referees)? **Default: not in v1**; add
  `competition_round.time_proposals_off` later if asked.
- Q3. Lateness tolerance 5 s, offline acceptance until round end + 30 min. **Default as stated.**
- Q4. A teammate's second tap while one is pending: **refused** (first tap wins, anybody may undo it).
- Q5. Organisers on the competitor page see secret puzzles? **Default: no, never** (the page is public by nature).

## 12. Work packages (≤ 1-2 days each)

| WP | Content | Depends on |
|---|---|---|
| B1 | Settings: 4 columns + `CompetitorPageSettings` + `ChangeCompetitorPageSettings` + settings page + Tools-menu link + feature flag gate + `feature_flags.md` + migration diff | - |
| B2 | `CompetitionLineupId`, `CompetitionTagResolver`, three locale-less tag routes + `live_results_scan` branch 2, `PreferredLocale` extraction, `?table=` / `?entry=` on `live_results`, `parseCompetitionTagUrl` + JS tests | B1 |
| B3 | Printing: `NameTagQrCode` URLs, `kind`/`layout`/`round`/`sort` on name tags, three tag queries, tent/card CSS, line-up change warning, byte-identity test | B2 |
| B4 | Competitor page read side: `GetCompetitorPage`, `ranksOf()`, five routes + `competitor_list`, templates, privacy/secret rules, statement budget, canary additions | B1 (B2 for links) |
| B5 | Live part: `competitor_page_controller.js` (clock, Mercure, state refresh, reveal refetch, Wake Lock), `competitor_state` JSON + caching headers, herd spread | B4 |
| B6 | Proposals write side: entity + migration (+ 2 custom indexes, CHECK), `ProposeRoundTime` / `Withdraw` / `ChangePuzzles` + handlers + controllers, rate limiter, lock test, load measurement | B1, A's puzzle-choice model (only for `puzzleIds` validation - stub "any revealed round puzzle" until A lands) |
| B7 | Confirmation integration: `proposalId` in `RoundResultChange` + parser, `TimeProposalResolver` in `RecordRoundResultsHandler`, stopwatch-reset supersede, `RejectTimeProposal` endpoint, `proposals` in round state, `lineupId` on team entries, `TimeProposalLiveUpdates` (both topics + competitor topic) | B6 |
| B8 | Competitor UI for proposals: Stop button, outbox (`competitor_proposals_outbox.js`), states pending/confirmed/rejected/superseded, puzzle chips, private-topic token; browser walk end to end with C | B5, B7, C's judge page |
| B9 | Docs: `docs/features/competitions-management/competitor-pages.md` (design of record), `live-results.md` (tag kinds, scanner), `official-results.md` (proposals, `proposalId`), events rule summary line, `docs/TODO.md` loose ends; English translations (all 6 locales at the end per rule 3) | all |

Order: B1 → (B2 ∥ B4 ∥ B6) → B3, B5, B7 → B8 → B9. C can build against §6 from the start; B7 is the integration point.
