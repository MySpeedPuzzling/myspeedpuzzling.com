# Official round results

The organiser's own record of a round: who finished in what time, who placed how many pieces, who did not start, who
qualified, and at which table everybody sat. Decided 2026-10-07 ("best of both worlds"), replacing PR #136's
`official_round_result` table and its claiming of results onto players' profiles. Scenarios it serves:

1. mass participant management before the event (the participants spreadsheet, a later PR, reuses this write path),
2. **fast result entry live during the event** - a referee's phone: find by table / name / name-tag QR, enter, confirm,
3. **who qualified between rounds** - a results desk: rank, mark qualified, advance them into the next round(s), seat them.

This document is the design of record **as built for the core** (data model, write path, guards, ranking, read
models, JSON API) and the public round page. The organiser pages (live entry, results desk, seating, name tags) build
on it - see their own documents below.

- Live result entry on a phone + name tags with QR: [live-results.md](live-results.md)
- Results desk, results overview, qualification helpers, advancing and "Seat them now": [results-desk.md](results-desk.md)

Vocabulary: a **round entry** is `CompetitionParticipantRound` (a person in a solo round) or `CompetitionTeam` (a
pair/team of a pair/team round). An **official result** is what the organiser recorded for a round entry. A **time** is
a player's own `PuzzleSolvingTime` - official results never create, change or delete times.

## Data model

Columns of `HasOfficialResult` (a trait on both round entry entities - an embeddable cannot hold the `entered by`
relation):

| Column | Meaning |
|---|---|
| `result_seconds` int null | finished, total seconds (1..86399) |
| `result_pieces_placed` int null | did not finish: pieces placed when the time ran out (main's vocabulary, never "missing") |
| `result_did_not_start` bool | did not start |
| `result_entered_at`, `result_entered_by_id` (FK player, `SET NULL`) | last change of the result - also a clearing |
| `qualified_at` timestamp null | the organiser's qualified mark - always an explicit decision, never derived |
| `table_number` smallint null | the entry's table in this round (1..9999), unique within the round |

At most one of seconds / pieces placed / did not start; none = no result yet. `RoundEntryResult` is the one value
object for it (wire format below). In a pair/team round the team carries the result; the members' round entries keep
these columns empty.

- `competition_participant_round` has a unique `(participant_id, round_id)` (production 2026-10-07: 0 duplicates).
  Every writer (import applier, Live participant editor, join, quick add, `AdvanceQualified`) creates a row only for
  a person not in the round yet.
- `competition_round.results_published_at` (public while set), `results_first_published_at` (the first publish - the
  desk says whether players were told before), `table_numbers_off` (the organiser said the round does not use table
  numbers).
- `notification.target_competition_round_id` (`CASCADE`) for `NotificationType::OfficialResultPublished`.
- `official_result_notice` (`OfficialResultNotice`: player + round unique, both `CASCADE`, `notified_at`) - "this player
  was told about this round", the whole never-twice guarantee of the notifications (below).
- Table numbers are unique per round **in the write path, under the participants lock** - not by an index, so a swap
  or a renumbering in one change set works.

## Ranking (computed, never stored)

`OfficialResultsRanking` is the one implementation (organiser tools and the public page): finished by seconds, then
unfinished by pieces placed (most first); equal results share a rank and the next rank skips ("1, 2, 2, 4"); did not
start and no result yet are unranked (`null`). Rows hidden from a viewer are dropped **after** ranking, never renumbered.

**Advancement seed** (`AdvancementSeeding`, reused by seating): rank within the own round first (every winner before
every second); the same rank across rounds (or a tie) ordered finished before unfinished, then by the result relative
to that round's winner (time ÷ winner's time; unfinished: pieces placed ÷ piece count); then the organiser's order of
the rounds, name, id. Entries without a ranked result last.

## One write path

Every write of round entries (results, marks, table numbers, advancing, taking out) takes
`CompetitionParticipantsLock::key($competitionId)` (`SerializedByLock`; the same key as the participant import and
registrations) - publishing and the table numbers usage switch change only the round row; all of them validate everything
before changing anything and check that every round/entry belongs to the competition the caller was authorised on
(`CompetitionEditVoter`; `RecordRoundResults` also `CompetitionResultsEntryVoter` for the event's referees, with
`resultsOnly` - live-results.md "Referees").

- **`RecordRoundResults`** (`competitionId`, `roundId`, `actingPlayerId`, list of `RoundResultChange`, `dryRun`) -
  results, table numbers, qualified marks and entrants typed in at the venue. Each change: `clientChangeId` (UUID, for
  idempotent replays), an existing entry (`participant_round:<id>` / `team:<id>`) **or** a new one (`clientEntryId` +
  kind + name + members; created with that id, never matched by name - but a participant of the event may be put in by
  id: a person's `participantId`, a member's `{participantId}`; they must be of this competition, not removed, and no
  entry of the round yet - in a pair/team round somebody in the round without a pair/team joins with their row;
  refused: `participant_not_found` / `participant_already_in_round` / `duplicate_entry`), `field` (`result` |
  `table_number` | `qualified`), `from` (what the device last saw), `to`. Three-way per change against the working
  state of the round: current = `to` → `unchanged` (a replay); current = `from` → `applied`; otherwise `conflict`
  (current value + who/when returned). Invalid → `rejected` with a reason key (`official_results.reason.*`). Table
  numbers are checked after the whole set; a change making a number shared is refused and the set planned again. A new
  entry is created only when one of its changes goes through. Returns `RecordedRoundResults` (HandledStamp).
  **Change ids are kept** (`round_result_change_receipt`: id = `clientChangeId`, round, status, `received_at`) for
  every change that is applied or found `to` there already: a change sent again with a known id is answered
  `unchanged` with the current value and never applied again - so a replay whose answer got lost cannot bring back a
  value corrected since (A→B, corrected B→A, replay A→B would pass the three-way check alone). A known id of another
  round is refused. Refused and conflicting changes leave no receipt (the device sends a fix under a new id). Receipts
  go with their round and are pruned after 90 days by `myspeedpuzzling:prune-round-result-change-receipts` (cron, see
  `docs/TODO.md`); a phone replaying after that falls back to the three-way check. A request the server cannot read
  as a whole (no list, more than 500 changes, a change without an id or the same id twice) is a 400 `invalid_changes`
  with `reason` and the translated `message` - never the parser's developer text; a round that no longer exists is a
  JSON 404 `round_not_found`.
- **`AssignTableNumbers`** (`competitionId`, `roundId`, `[{entry, from, number|null}]`) - seating in one write,
  validated as a whole (entries of the round, listed once, 1..9999, no shared number afterwards) or refused entirely
  (`InvalidTableNumbers` with problems). `from` (required) is the number the device last saw: an entry whose number is
  neither `from` nor the new one any more was changed by another organiser meanwhile → `changed_meanwhile` (with the
  `current` number) and **nothing** of the write is applied - a renumbering is never half applied and never written over
  somebody else's numbers; the page fetches the round again and says so. Returns the refs whose number changed.
- **`ChangeRoundTableNumbersUsage`** (`off`) - "this round doesn't use table numbers" and back.
- **`PublishRoundResults` / `UnpublishRoundResults`** - `published_at`; the first publish also sets
  `first_published_at`. **Every** publish records `OfficialRoundResultsPublished` (async) →
  `NotifyWhenOfficialRoundResultsPublished`: an in-app notification (no e-mail) to every player linked to an entry with a
  **finished** result (solo: the connected participant; pair/team: connected members) who was not told about the round
  yet. The same event is recorded when a finished result is recorded or corrected on a published round
  (`HasOfficialResult::recordResult()` - a referee's phone syncing late, a did-not-finish corrected) and dispatched for the
  already published rounds when the event (`ApproveCompetitionHandler`) or its series (`ApproveCompetitionSeriesHandler`)
  is approved. The handler tells nobody while the results are off the page or the event is not publicly visible
  (`IsCompetitionPubliclyVisible` - the link would 404); the next publish or the approval runs it again. **Never twice**:
  each recipient's `official_result_notice` row is claimed with `INSERT .. ON CONFLICT DO NOTHING` in the notification's
  transaction (`OfficialResultNoticeRepository::claim()`), so a publish → unpublish → publish before the worker ran, a late
  result and an approval running at the same moment all meet on the unique index - one of them tells the player.
- **`AdvanceQualified`** (`sourceRoundIds[]`, `targetRoundIds[]`, `distribution` `single` | `balanced` | `by_source`,
  `targetBySource`, `bestOfEachCountry`, `dryRun`, `planHash`) - qualified entries of the sources into the targets, same
  category only. Solo: the person joins the target round; pair/team: a new team with the same people and name. Skipped:
  `already_in_target` (the person / that exact set of people is there), `member_already_in_target`,
  `member_already_planned` (a person in two qualified pairs/teams goes once, with the better-seeded one - never a
  unique-index failure, never two parallel rounds), `qualified_twice`. `balanced` = serpentine by seed (1→T1, 2→T2, 3→T2,
  4→T1, …). The dry run returns the exact plan + `planHash` (assignments, skips, target entries, distribution, the country
  rule); applying requires the hash and re-plans under the lock - any difference → `AdvancementPlanChanged` (409),
  nothing written. Advancing twice adds nobody; unmarking never removes. The people of a plan are loaded in one
  statement.
  **The country rule** (`bestOfEachCountry` 1..99, WJPC's "the best of every country advances whatever their time"):
  the plan also takes the best K ranked entries of every country **over all the source rounds**, by the advancement
  seed - a pair/team counts for each of its members' countries, entries already marked count too (a country whose best
  is in already gets nobody extra). Those not marked yet are flagged `byCountryRule` and listed in
  `markedByCountryRule`; applying the plan marks them qualified in their own round first (one confirmation, one
  transaction, one `planHash`), so the marks always say who advanced. Ranked entries without a country are listed
  (`withoutCountry`) for the organiser to mark by hand - never taken. Decided over a page-side pre-selection: the seed
  lives in PHP (`AdvancementSeeding`) and the plan is the review step organisers already confirm.
- **`TakeEntryOutOfRound`** (`competitionId`, `roundId`, `entry`) - "Take out of this round" on the results desk, for an
  entry put in by mistake (a wrong advance): a person's `CompetitionParticipantRound` goes (they stay in the event and its
  other rounds); a pair/team goes with its members' places in that round. Refused (`OfficialResultsProtected`
  `round_entry_has_result` / `team_has_result`) while the entry has a result or a qualified mark.

Qualified marks are written only through `RecordRoundResults` changes, so helper pre-selections (Top N, best of each
country) made in a page surface conflicts like any other change.

## Guards - official data never disappears as a side effect

`OfficialResultsGuard` answers who holds official data (a result or a qualified mark - own, or the pair's/team's).
Refusals throw `OfficialResultsProtected` (409, reason `official_results.guard.*`):

| Change | Rule |
|---|---|
| Delete a pair/team (`DeleteCompetitionTeamHandler`, teams page) | refused while it has a result or qualified mark |
| Take a person out of a round (Live participant editor, `EditCompetitionParticipantHandler`) | refused when they hold data in that round; nothing of the save is written |
| Remove a person from the event (`SoftDeleteCompetitionParticipantHandler`) | refused when they hold data anywhere in the event |
| Import (planner `SiteSnapshot::hasAnyResult()` / `teamHasOfficialResult()`) | official results count like players' times (D11): people kept, entries kept, emptied pairs/teams with data kept (warning) |
| `LeaveCompetition`, switching identity in `JoinCompetition` | a self-joined row holding data is disconnected, not deleted |
| Change a round's category (`EditCompetitionRoundHandler`, also internal API PATCH) | refused while any entry has a result or a qualified mark (`countEntriesWithOfficialDataInRound()`) |
| Delete a round | internal API: 409 when it has player times **or official results / qualified marks**; web: a confirmation listing the official results, bound to the list (`confirmedOfficialResultsHash`, re-checked under the lock) |
| Delete an event (internal API `DELETE /internal-api/competitions/{id}`, `refuseWhenItHasResults`) | 409 when it has player times **or official results / qualified marks** (`countEntriesWithOfficialDataInCompetition()`); the web delete asks its own way |
| Take an entry out of its round (results desk, `TakeEntryOutOfRound`) | refused while it has a result or a qualified mark |
| Move a person between pairs/teams (`AssignParticipantToTeamController`) | allowed; a warning that the result now belongs to the new line-up |

Every change in this table takes the event's `CompetitionParticipantsLock` like the result writes do (`SerializedByLock`,
competitionId resolved from the authorised entity and re-checked by the handler), and its guard reads the database under
it - so a result recorded at the same moment is either seen by the check or recorded after the change, never lost while
both sides report success. `SerializedByLockMessagesTest` fails for any handler touching participants, entries, teams or
rounds whose message does not take the lock (or carry a listed reason).

## Read models

- `GetRoundResultEntries::forRound()` / `byRefs()` - every entry of a round (organiser tooling: participant names as
  recorded, no blocklist), ranked and ordered (rank, did not start, no result; ties by table number, name), with
  members, countries, linked player, table, result, qualified, entered by/at. `RoundResultEntry::jsonSerialize()` is the
  JSON every endpoint and Mercure update uses.
- `GetRoundResultsOverview::forCompetition()` / `forRound()` - every round with stopwatch, publication, piece count of
  a single-puzzle round, `tableNumbersOff` and the counts (entries, with table number, with result, qualified) - the
  seating readiness line "Tables: 180 / 200 assigned" - and whether that line shows now (`showsTablesReadiness`, JSON
  `tablesReadiness`: THE rule, `SeatingReadiness`, see [seating.md](seating.md)). One statement.
- `GetOfficialResultRecipients` - the notification fan-out (players not told about the round yet).

## Public round page (as built)

A round whose results are published leads its public page (`event_round_results` / `edition_round_results`,
`RoundResultsPageBuilder`) with the organiser's ranking; everything else stays main's round page.

- **Not published - or published with nothing ranked: the page is exactly as before**, byte for byte, and runs the same
  statements. `EditionRoundDetail::$resultsPublished` rides on the rounds statement the page runs anyway
  (`GetEditionRounds`); every expression of `round_results.html.twig` that depends on official results sits inside an
  existing line or tag, and the parts the published layout reuses (`round_results/_puzzle_card.html.twig`,
  `round_results/_player_times.html.twig`) are included right after the indentation of their old lines and end without
  a newline, so they render exactly what the inline markup did.
- **Published** (`round_results/_official.html.twig`): the puzzle card(s), then **Official results** - rank (ties share
  it, 1-3 on a medal tint), the entrant (the person, or the pair's/team's members with the name as a pill - the
  leaderboard's `_leaderboard_player` rows), the result (`official_results/_result.html.twig`: "1:23:45", "479 / 500 pcs")
  and a **Q** pill for a qualified entry with a legend. Did not start, no result yet and table numbers are the
  organiser's - never shown. Below it, folded in a `<details>` "Times added by puzzlers (N)", main's list of times with
  its note ("not the official placings"). The round's external results link turns into **Organiser's results** (the MSP
  table is the official result now). The meta description says "Official results of …"; the round has no JSON-LD.
- **Read model `GetPublishedRoundResults::forRound()`**: entries with a ranked result (people removed from the event left
  out, like the organiser's tools), ranked with `OfficialResultsRanking` over all of them, then the rows hidden from the
  viewer (`HiddenPlayers`) dropped **without renumbering** - a solo row of a hidden player; a pair/team with a hidden
  linked member unless the viewer is a linked member too (main's group rule). Names are always the organiser's participant
  names; a linked player links to their profile (avatar, flag) only when the viewer may see them
  (`PrivateProfileAccess::sqlIsPrivate()`, the viewer themselves included) - a private player otherwise keeps just the
  organiser's name (`PublishedRoundEntrant`: `playerId` for display, `linkedPlayerId` / `linkedPlayerCode` never rendered).
  One statement (a pair/team round brings its members as JSON; it also says whether the viewer is linked to any entry
  of the round, and their name and country), plus one for the viewer's own times in the round when a row could offer
  "Add to my profile". Pinned: an unpublished page +0, a published one +1 (guest, or a viewer with no offer) / +2 (an
  offer) (`OfficialRoundResultsPageTest`). The meta description counts every ranked entry (`rankedCount`), whatever the
  viewer's blocks hide.
- **Add to my profile**: offered to the signed-in viewer on their own entry. When the organiser linked them to **no
  entry of the round at all** (did not start and no result yet count as entries): on a **pair/team nobody is linked
  to** (team names only, the Minnesota case), and on an **unlinked person whose name is the viewer's**
  (`ParticipantNameKey` - case, accents, spaces and dashes do not matter - and no other country) - never on every
  unlinked row for every visitor (an imported WJPC group is mostly unlinked). Such a viewer gets one line under the table
  instead, "Is your name here? Connect it to your profile" → the event's join flow (`join_competition`), when some name
  of the round is nobody's. Offered for a **finished** result, in a round with **exactly one puzzle** that the viewer
  sees revealed (not left out by the reveal rules, picture not hidden), until they have a time in the round
  (`competition_round_id` = the round, tracker or in the group). Then the own entry - or the unlinked entry with the
  viewer's time - says "On your profile". Derived on every read, nothing stored. Organisers also get the round's tool
  links on a round page with published results (`official_results/_organiser_round_links.html.twig`) - never on an
  untouched round page, which runs exactly main's statements for every viewer (no permission check; pinned by
  `OfficialRoundResultsPageTest`).
  The link is `puzzle_add` with `?competition=<id>&official_entry=<participant_round|team>:<id>`;
  `OfficialEntryTimePrefill` (GET only) re-runs the very same read model for the viewer and fills the form in only for an
  entry it offers - anything else is ignored silently: the puzzle, the time, the finished date (the round's start day in
  the round's zone), the competition, and for a pair/team the co-puzzlers (linked members by `#CODE`, the others as guest
  names) and the pair's/team's name when the form may still set it (no puzzling team of these exact people yet, or an
  unnamed one - `PuzzlingTeam::nameIfUnnamed()`; with nobody filled in, the name comes along and the save decides). A
  pair/team result **never opens as a solo time**: `OfficialEntryTime` carries the round's category, the co-puzzler
  picker opens in Pair/Team mode without Solo (a "pair" recorded with more people opens as a team), and when the people
  are fewer than the category needs (pair 2, team 3, the viewer included) the form says "Add the person/people you
  puzzled with" - a team typed by name only, a linked viewer whose partner was not recorded. In a pair/team nobody is
  linked to, the member named like the viewer (one match) is left out; with no clear match nobody is filled in (one of
  the names is the viewer - all of them would make a pair of three) and the form asks "Which one of them are you?" with
  one link per name (`&official_member=<position>`, read back the same way). The save is an ordinary time: first try,
  duplicates, secret puzzles and privacy run as for any other.
- **Event and edition pages**: `CompetitionEvent::$hasPublishedOfficialResults` (an EXISTS in `GetCompetitionEvents::byId()`,
  the statement every competition page runs anyway) turns on the official counts in `CountCompetitionResults::forCompetition()`
  / `perRound()` - folded into their existing statements, so even an event with official results pays no extra
  statement. They drive `EventTitle::saysResults()` ("Results" in the title), the "Results by round" buttons, and the
  meta descriptions say "the official results round by round" instead of counting times. The sitemap lists a round page
  with published ranked results too. One SQL rule for all of them: `GetPublishedRoundResults::sqlShowsOfficialResults()`
  / `sqlRankedEntriesCount()`.
- **Guards**: `BlocklistCanaryTest` / `PrivateProfileCanaryTest` cover the published table (the queries pass
  `BlocklistQueryCoverageTest` / `PrivateProfileQueryCoverageTest` by using `HiddenPlayers` and `PrivateProfileAccess`).

## JSON API (organiser pages)

Routes under `/{_locale}/official-results/`, JSON in and out, `Cache-Control: private, no-store`, never a redirect
to the login page (401 `sign_in_required`), 403 `forbidden` without `COMPETITION_EDIT` (the round state and
`changes` ask `COMPETITION_RESULTS_ENTRY`, which the event's referees have too - their `table_number` / `qualified`
changes are refused with reason `results_only`, live-results.md "Referees"), writes need
`Content-Type: application/json` (415) and the stateless CSRF token `csrf_token('official_results')` in the
`X-CSRF-Token` header (403 `invalid_csrf_token`) - `OfficialResultsApi`.

| Method + path | Message | Answer |
|---|---|---|
| `GET rounds/{roundId}` | - | `serverNow`, `topic`, `competition`, `round`, `rounds`, `entries`, `mercure` |
| `GET competitions/{competitionId}` (organisers only - the results overview) | - | `rounds` (every round's progress), `mercure` |
| `POST rounds/{roundId}/changes` | `RecordRoundResults` | `dryRun`, `outcomes`, `entries` (400 unreadable set) |
| `POST rounds/{roundId}/publish` / `unpublish` | `PublishRoundResults` / `UnpublishRoundResults` | `round` |
| `POST rounds/{roundId}/table-numbers` | `AssignTableNumbers` | `changed`, `entries` (422 `problems`, `changed_meanwhile` with `current`; 400 without `from`) |
| `POST rounds/{roundId}/table-numbers-usage` | `ChangeRoundTableNumbersUsage` | `round` |
| `POST rounds/{roundId}/take-out` | `TakeEntryOutOfRound` | `removed`, `round` (409 `entry_protected`, 404 `entry_not_found`) |
| `POST competitions/{competitionId}/advance` | `AdvanceQualified` | the plan (409 `plan_changed`, 422 `reason`) |

Live updates: after the commit the controller publishes a **private** Mercure update on `/round-results/{roundId}`
(`OfficialResultsLiveUpdates`; a Mercure failure is a logged warning, never a failed write): `official_results.entries`
(the changed entries + the round), `official_results.refresh` (more than 50 entries changed, or an entry taken out of
the round - fetch the state again),
`official_results.round` (publication / table numbers usage).

### Subscribing: a token per page, never the cookie

Every organiser page - live entry, results desk, seating, results overview - follows its rounds on a stream of its own
(`assets/official_results_events.js`, `OfficialResultsEvents`) authorised by a short-lived subscriber JWT it gets with
its state: `mercure: {url, topics, token, expiresAt, expiresIn}` (`OfficialResultsSubscription`). The token is minted
only after the voter let the request in (the round state and the live entry: `COMPETITION_RESULTS_ENTRY`, so referees
get their round's; desk, seating, overview and `GET competitions/{id}`: `COMPETITION_EDIT`), lists its topics one by
one (`/round-results/{id}` + the public `/round-stopwatch/{id}` for a round's page; every round's `/round-results/{id}`
for the overview - never a URI template), may subscribe only, lasts an hour, and is signed with the hub key through
MercureBundle's token factory (`null` + a warning when none can be made: the page then lives on its state refreshes).

Why not the `mercureAuthorization` cookie: `MercureSubscribeCookieListener` rewrites it on **every** signed-in response
with that request's topics only (the chat topics), so any other request - another tab, a JSON call - dropped the round
topic from it, and the stream's next reconnect (a Wi-Fi drop, a sleeping laptop, the hub's write timeout every ~10 min)
silently stopped receiving private updates (review 2 M1). A token sent with the connection beats the cookie (checked on
Mercure 0.24.2, the image dev and production run), so the cookie now carries the chat topics only - no official
results controller adds a topic to `MercureTopicCollector` any more, and the base layout's `mercure-hub` subscription
(unread counts, conversations) is untouched.

`OfficialResultsEvents`:
- reads the stream with `fetch()` and parses the server-sent events itself (`EventStreamParser`), because EventSource
  cannot send a header: the token goes as `Authorization: Bearer` - **never in the URL** (Traefik logs the request
  path with its query of slow 2xx answers, which every stream is, and Mercure 1.0 drops the `authorization` query
  parameter), with `credentials: 'omit'` and `Last-Event-ID` after a drop;
- reopens after the stream ended (the hub's write timeout), a network error or a 5xx after 1 s, 2 s, 5 s, 15 s, then
  every minute (never sooner than the hub's `retry:`; a stream that stayed open 30 s starts from 1 s again), and
  fetches the page's state once it is open again (catch-up - whatever was published meanwhile);
- treats a stream silent for 100 s as dead (the hub sends a heartbeat comment every 40 s; a phone woken from sleep, a
  Wi-Fi switch) and opens it again;
- on 401 (an ended or refused token - the hub closes a token's stream ~7 s before `exp` and refuses it after) and when a
  token has less than 15 minutes left, asks the page for its state again for a fresh token; an open stream is replaced
  by opening the new one first, then closing the old one (updates arriving on both are handed over once, by event id).
  The pages fetch their state every minute while shown anyway, so the renewal normally rides on that; a page in the
  background renews by itself;
- stops (`suspend()`) when a state answers signed out / no rights - a token is for whoever may still use the page - and
  resumes with the next state that answers (the banner's Retry, signing in again);
- hands every update over parsed; the pages filter by `type` / `roundId` as before. Turbo leaving the page closes it.

The pages keep their periodic state fetch (once a minute while shown, and when the tab comes back or the network
returns) as the last safety net. Dev: the hub runs on another port, so the browser sends a CORS preflight for
`authorization, last-event-id` - Mercure allows both for `cors_origins` (compose.yml); production is same-origin and
needs no hub change. Pinned by `tests/official-results-events-harness.mjs` (`OfficialResultsEventsScriptsTest`: parser,
backoff, catch-up, 401, renewal, silence, suspend, close), `OfficialResultsSubscriptionTest` (topics, `exp`, the
signature) and `LiveUpdatesSubscriptionTest` (who gets a token, the pages carry it, the cookie carries no round topic).

## Seating

Table numbers before each in-person round - the seating page (`round_seating`: type, swap, drag and renumber, clear,
"this round doesn't use table numbers"), auto-assign as a proposal first (earlier rounds through `AdvancementSeeding`,
MySpeedPuzzling times, a draw, by name), the printed lists and the readiness line: [seating.md](seating.md).

## Not built yet (follow-ups in docs/TODO.md)

Unfinished results onto profiles (after unfinished-results phase 1b), several puzzles per round, deriving table numbers
from the table layout tool, results from stopwatch-less timing devices.
