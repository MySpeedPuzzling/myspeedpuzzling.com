# R - Review of designs A/B/C/D and the consolidated plan

Reviewer, 2026-11-17, against `main` at `0ad091b37`. Every "verified" below was read in the repo. This document
**overrides A/B/C/D wherever they disagree with it**. Implementation agents read this first and then the sections of the
design docs it points to.

---

## 0. Top 10 issues (details in §1-§3)

1. **Early reveal through a re-scheduled countdown (A §4.2).** Verified: `CompetitionRound::scheduleStopwatchStart()`
   accepts a new moment while the round is already `SCHEDULED` ("or to move a countdown"). Here is the failure: a start
   is scheduled for 10:00 with Reveal ticked, so `reveal_at` = 10:00. The organiser then moves the start to 10:30
   without the tick. `reveal_at` stays at 10:00, and the puzzle is public 30 minutes before the start. A only handles
   cancel and reset. **Fix (§2.1):** every schedule or start first withdraws a future stopwatch reveal, and only then
   applies the tick.
2. **A competitor can propose a time for someone else (B §5.1).** Verified: `ConnectCompetitionParticipantHandler`
   lets any signed-in player connect to any *unconnected* participant. A cheater connects to a friend's participant at
   the venue and proposes their time, which defeats Jan's "can not bypass and propose for someone else". **Fix (§2.4).**
3. **Three incompatible puzzle-choice contracts.**
   - A: `puzzleFormat{mode: every|pick, toSolve, puzzles[]}`, with the entry value `puzzles: [{roundPuzzleId, done, atSeconds, piecesPlaced}]`.
   - C: `round.puzzleChoice{mode: all|pick_one|pick_k, k, splits, required}`, with the entry value `puzzles: {ids, splits}`.
   - B: `puzzle_ids` holding **catalogue puzzle ids** on the proposal.

   **A wins (§1.1).** B's proposal puzzle choice is deferred.
4. **Group atomicity vs C's independent outbox items.**
   - A rule 7: `result` and `puzzles` for the same entry apply together or not at all.
   - C §2.3: they are separate items, and "a conflict on the choice never blocks the time".
   - The rule-6 consistency check depends on atomicity. A finished result on an entry whose record has `done:false`
     items is refused (`puzzles_mismatch`), so C *must* send the done-marking `puzzles` change in the same request.

   **A wins, with outbox batching rules (§1.1).**
5. **Two proposal protocols.**
   - B: a `proposals` state key, the `official_results.proposals` message, a separate reject endpoint, and the statuses
     `confirmed/rejected/superseded/withdrawn` with outcome `not_pending`.
   - C: `entry.proposal`, `round.proposalsPending`, a `field: "proposal"` change, and the outcomes
     `edited/stale/dismissed`.

   **Unified in §1.2.** B's data shape is used. C's offline-safe reject travels as a change.
6. **The QR paths conflict.**
   - B: locale-less `/live/{cid}/t/{n}`, `/e/{entryId}`, `/g/{lineupId}`.
   - C: `/{_locale}/live/{cid}/t/{tableKey}` and `/{_locale}/live/{cid}/r/{rid}/e/{entryRef}`. C has no line-up kind,
     so its service-worker regex would miss B's paths.

   **Printed paths are frozen in §1.3** before anything is printed.
7. **Two secret-puzzle rules.** A §6.1 sends names to organisers (per viewer, `SecretPuzzleAccess`). B, C and D use
   the public reveal for everybody. A judge's phone or a snapshot in IndexedDB on an organiser's phone would then hold
   hidden names. **One rule, one helper (§1.5).** A start that reveals must also push a refresh to the judge and
   display topics. Today nothing is published on a reveal (D verified this for `RevealRoundPuzzleNowController`).
8. **C's one-tap "✓ per row" proposal sweep (C §3.1)** lets a judge confirm times without standing at the table. That
   contradicts Jan ("confirm via single button **after choosing the right table by WHO**") and is the main anti-cheat
   control. **Cut:** a list row opens the card, and the card has the one Confirm button.
9. **Proposal endpoint leaks and caching.**
   - B makes proposals idempotent on a client-chosen UUID ("known id ⇒ answer its current state"). Replaying another
     entry's id returns that entry's proposal state.
   - Guest `competitor_state` is `public, max-age=5` while it carries `serverNowMs`. That is a stale clock hint behind
     any edge cache.

   **Fix (§2.4, §2.6).**
10. **"Byte-identical / statements unchanged" is claimed but not pinned.** Verified:
    `tests/MessageHandler/OfficialResults/RecordRoundResultsHandlerTest.php` has no statement-count assertion, and no
    golden test of the round state JSON exists. A3 and P1 both edit that handler. C adds `viewer` to every state
    answer. **F0 pins the baselines first (§4).**

Also important:
- The service worker precaches **every** entrypoint (`public/service-worker.js:55-66`, verified), so D's `display`
  entry would land on every visitor's device (§2.8).
- C's IndexedDB snapshots survive a logout (§2.7).

---

## 1. Contract conflicts and resolutions (binding)

### 1.1 Multi-puzzle rounds: model, wire, judge UI

| Topic | Decision |
|---|---|
| Modes | `competition_round.puzzle_format` = `total` (default, today's behaviour) or `pick`. `puzzles_to_solve` K is 1 ≤ K ≤ N. **A's `every` is folded into `pick` with K = N**: one code path, and the form label reads "Solve all N" when K = N. A's rule "a pick round needs N > K" becomes N ≥ K, and `every`'s N ≥ 2 warning goes away. |
| Round JSON key | A §6.1 `puzzleFormat: {mode: "pick", toSolve, puzzles: [...]}`, present only when not `total`. C renames its `state.round.puzzleChoice`/`state.round.puzzles` to this. There is no `splits` and no `required` flag in v1: the choice is never required before Save (C's "Puzzle missing (N)" filter stays). |
| Per-puzzle item | A §6.1 fields: `roundPuzzleId, position, label, hidden, revealsAt, puzzleId, name, brand, imageUrl, piecesCount`. `hidden` and the nulling follow §1.5 (public rule for **every** viewer), not "hidden from THIS viewer". `piecesCount` goes to organisers and referees only (the parity with today's round-level `piecesCount`). |
| Entry value | A §6.2 `puzzles: null \| [{roundPuzzleId, done, atSeconds?, piecesPlaced?}]`, with list order = solving order. The key is present only on `pick` rounds. Ids are **`competition_round_puzzle.id`** everywhere, never catalogue puzzle ids (B's `puzzle_ids` is dropped). |
| Write | A §6.3 `field: "puzzles"`. Refusal reasons as A lists them. C's `invalid_puzzle_choice` is dropped and the client renders A's reasons. |
| Atomicity | **A rule 7 holds.** The outbox (C10) enqueues the `puzzles` and `result` items of one Save with a shared `groupId`, and `wire()` never splits a group across requests. On a group conflict both items show in the existing conflict sheet ("Keep mine" resends both). Conflicts need two judges on the same entry, which is rare. |
| Judge flow on a K-of-N round | A choice tapped before the finish → `puzzles` with `done:false` items. Finished now → **one** group: `puzzles` with the K items `done:true` (the last item's `atSeconds` omitted or = seconds) + `result`. Unfinished → items with `piecesPlaced` + `result.piecesPlaced` = the rule-6 sum, computed by the shared `assets/official_results_puzzles.js` (A §6.3). Judge-side split entry is **deferred** (§3). |
| Pure JS helper | `assets/official_results_puzzles.js` + node parity test, built in A3. C10 and A4 import it. |

### 1.2 Time proposals (B's data model, C's offline transport)

- **Entity, statuses, timing:** as in B §5.2-5.3, with two changes. `puzzle_ids` is dropped (v1 proposals carry no
  puzzle choice: the judge picks it, which is Jan's WHO step). The ownership checks of §2.4 are added.
  Statuses: `pending | confirmed | rejected | superseded | withdrawn`. A judge's edited value is still `confirmed`, with
  `decided_seconds ≠ proposed_seconds`. The UI shows "Confirmed as 45:15 (proposed 45:12)", and there is no separate
  `edited` status.
- **Round state:** B §6.1, a top-level `proposals` array, present only while `time_proposals` is on. C indexes it by
  `entry` on the client. There is **no `proposal` key on entries** and no `proposalsPending` (C computes the count), so
  the entry JSON keeps one shape for every consumer.
- **Mercure:** B §6.2, `official_results.proposals` on both private round topics (the referee topic carries only
  organiser-recorded names). C handles the type. Verified that old clients ignore unknown types.
- **Confirm:** a `result` change with `proposalId` (B §6.3 = C §3.2). It is allowed for `resultsOnly`.
- **Reject:** C's transport, so it works offline through the outbox:
  `{clientChangeId, entry, field: "proposal", from: {"id": "<proposalId>"}, to: {"status": "rejected", "reason": "not_finished|wrong_entry|other"}}`.
  B's `RejectTimeProposalController` is **not built**. `RoundResultField::Proposal` is allowed for `resultsOnly`
  referees: extend the check at `RecordRoundResultsHandler.php:285` together with A's `Puzzles`. Outcome: `applied`
  (rejected now) or `unchanged` (no longer pending).
- **Outcome annotation:** `"proposal": {"id", "status": "confirmed|rejected|superseded|not_pending|unknown"}`, present
  only when the change carried `proposalId` / `field: proposal`, or when the resolver touched a proposal.
- **Resolver:** B §5.5 (`TimeProposalResolver` inside `RecordRoundResultsHandler`). A result without `proposalId` gives
  equal → confirmed, different → superseded. It is skipped entirely when the competition's `time_proposals` is false
  (no statement).
- **Competitor's own updates:** B's per-entry private topic `/competitor-entry/...` is **deferred**. While its own
  proposal is pending, the competitor page polls `competitor_state` every 5 s (visible tab only, jittered). That is at
  most ~150 pending entries × 0.2 rps for a few minutes, at ≤ 5 statements each.

### 1.3 QR tags: frozen printed paths

| Kind | Printed URL (frozen) | Notes |
|---|---|---|
| Person (exists) | `/{_locale}/live/{cid}/p/{participantId}` | Unchanged, never touched. |
| Line-up (pair/team, whole competition) | `/live/{cid}/g/{lineupId}` | `lineupId` = uuid5 over the sorted member participant ids (B §3.2). |
| Table (dynamic) | `/live/{cid}/t/{tableNumber}` | Digits 1-9999. There are no layout keys (B's decision: table = the seating number; `RoundTable` labels are unused). |
| Round entry (static) | `/live/{cid}/e/{entryId}` | The bare `CompetitionParticipantRound.id` or `CompetitionTeam.id`. **No round id in the path** (C's `/r/{rid}/e/{ref}` is rejected): it would raise the QR version from 7 to about 9, and the server resolves the round from the entry. Offline, the judge looks the id up across the competition's snapshots. |

- New routes accept an optional locale prefix as well (`/{_locale}/live/{cid}/t/…`). Printed codes are locale-less.
- **One parser**, `parseCompetitionTagUrl(text) → {competitionId, kind: participant|lineup|table|entry, id}` in
  `assets/official_results_scan.js`. `parseNameTagUrl()` stays as the wrapper. C's `parseLiveQr` name is dropped. C8
  writes the parser and B2's PHP routes are tested against it (`LiveResultsLinksTest` + the node harness).
- Judge landing query parameters on `live_results`: `?entrant=` (exists), `?table=<n>`, `?entry=<participant_round:id|team:id>`.
  C3 implements them; B2's redirects produce them.
- Service-worker regexes (C6) cover `/{l}/live/…/p/…` plus both forms (with and without a locale) of `g|t|e`.
  `ServiceWorkerLiveRoutesTest` generates the paths from the router.
- A non-judge scanning offline gets the shell (§2.7). Its no-cookie screen must read neutrally ("You are offline -
  open this page again with a connection"), never like a judge tool.

### 1.4 One voter, one flag, one settings hub

- `CompetitionToolkitFeature` + `CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE` (D's name; A's
  `TOOLKIT_SETTINGS` is dropped). It is `COMPETITION_EDIT` and (admin, or `COMPETITION_TOOLKIT_FOR_ORGANISERS=1`), a
  copy of `OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE` (verified pattern). It is built in **F0** and nobody else creates
  it.
- **One "Competition toolkit" hub page:** `competition_toolkit`, `/{_locale}/manage-event-toolkit/{competitionId}`,
  shown only while the voter grants it. It is linked once from the competition management page. Cards:
  1. **Competitor pages & QR**: B's form (QR mode, public pages, live results before publishing, time proposals), plus
     "Print tags" (→ `competition_name_tags?kind=…`).
  2. **Displays & overlays**: D's list inline (name, kind, copy URL, open, edit) + "New display". D's
     `competition_displays` route stays for the full list, and the hub embeds its table partial.
  3. **Standings & bracket**: the list + link to `competition_standings_manage`.
  4. **Round formats & reveals**: one line per round ("Round 2 · pick 2 of 3 · reveal at stopwatch start") linking to
     the round form / round puzzles page. Per-round settings stay on the round pages.
  5. **Judges**: a link to the referees page + the "before the event" checklist (C §5.4).

  B's separate `competition_live_settings` page and Tools-menu link are not built.
- **Gating:** settings and editors are behind the voter. **Use** is never gated: recording `puzzles`, the start
  checkbox, competitor pages, displays by token and public standings work once the competition uses them (context
  rule 2).

### 1.5 Secret puzzles: one rule, one helper

- **`RoundPuzzlesVisibleNow`** (`src/Query/`, D's extraction from `GetEditionRounds.php:77-150`, verified there) is
  the only read of round-puzzle display data for the new surfaces. It covers `RoundPuzzleReveal::sqlHidden`,
  `PuzzleHideMode` (`image_only` = the name without the image) and the `hide_until` / `hide_image_until` embargo. It
  returns, per round, the revealed rows, `nextRevealAtMs` (a moment, never the puzzle) and `anyRevealed`. It is built
  in **F0**, and `GetEditionRounds` is refactored to use it, with its tests green unchanged.
- **The public rule applies to every viewer** on the judge page and its snapshots (round state `puzzleFormat`),
  competitor pages, displays, overlays and the editor's live preview. **Only** the round puzzles management page (and
  other existing organiser pages) keep `SecretPuzzleAccess`. The results desk shows labels before the reveal, organiser
  included. That is an accepted cost and the safer one.
- `RoundPuzzleReveal::Stopwatch` is A's §4.1. It lives inside `sqlRevealAt`/`revealAt` only
  (`RoundRevealMomentComputedOnlyHereTest`), so every reader above follows automatically.
- **Reveal pushes** (missing today): after a dispatch that reveals (stopwatch start/resume with the tick, "Reveal now",
  a reveal-settings change), the controller publishes `official_results.round` (or `refresh`) on both round topics and
  D's `puzzles` signal. Competitor pages and displays already refetch on any stopwatch push. A scheduled reveal moment
  is covered by `nextRevealAtMs` / `refreshAtMs`.
- `ChangeRoundPuzzleReveal` to `stopwatch` while running is refused (A §4.3). Keep it.

### 1.6 Mercure topics and message types (complete list)

| Topic | Type | Owner | Added |
|---|---|---|---|
| `/round-results/{roundId}` + `/referees` | `official_results.entries/refresh/round` (exist) | - | entries carry `puzzles` on pick rounds |
| same | `official_results.proposals` | P1 | only while `time_proposals` is on |
| `/round-stopwatch/{roundId}` (public) | unchanged payload | - | **never** carries reveal lists or puzzle data (A §4.3; a test asserts the keys) |
| `/competition-display/{competitionId}` (private, signal-only) | `display.signal` | D5 | only when the competition has displays (`CompetitionDisplaysPresence`, memoised, `ResetInterface`) |
| standings | **none in v1** | - | A's `official_results.standing` is deferred. Organiser and public standings pages refetch (60 s visible-only, plus on `official_results.entries` of an included round on the organiser view) |
| `/competitor-entry/...` | **none in v1** | - | §1.2 |

### 1.7 Encore entries

- `live_entry` (C2) and `display` (D4).
- Both entries edit `webpack.config.js`, so they are sequenced (C2 first).
- The SW must not precache `display` for every visitor (§2.8).
- The editor uses lazy controllers in `app` (no entry).
- The competitor page uses a lazy controller in `app`.

### 1.8 `official_results_round_state` keys (final)

Top-level keys:
- `serverNowMs`, `round`, `rounds`, `entries`, `mercure` (exist).
- `proposals`: present only while `time_proposals` is on.
- `viewer`: present **only when requested with `?viewer=1`**. The new live entry page always asks for it; other
  consumers (desk, sheet, seating) and the existing tests see unchanged JSON.

`?entries=0` drops `entries` (C3). Round: `puzzleFormat` only on `pick` rounds. Entry: `puzzles` only on `pick`
rounds; `lineupId` only on team entries while `competitor_pages` is on or `qr_tag_mode` is set (B §6.5).

---

## 2. Correctness and safety holes

### 2.1 Stopwatch reveal (A §4)

1. **Re-schedule (Top issue 1).** In `ScheduleRoundStartHandler` and `StartRoundStopwatchHandler`, when the round has
   mode-`stopwatch` rows with `reveal_at > now`, take the lock order of `SecretPuzzleHides::lockRoundsForChange()`, run
   `withdrawStopwatchReveal(now)` on them, then apply the tick (if any) at the new moment, then `resync`. The cheap
   `EXISTS` pre-check keeps untouched rounds on today's statements.
   Tests:
   - schedule 10:00 ticked → reschedule 10:30 unticked → hidden at 10:15;
   - reschedule 10:30 ticked → `reveal_at` = 10:30;
   - reschedule earlier ticked → `reveal_at` = the new moment.
2. **A scheduled start with the tick writes a future `reveal_at`.** Every surface already treats "moment in the
   future" as hidden, but `SecretPuzzleHides::resync` sets the site-wide `hide_until` to that moment. Correct.
   Add a `SecretPuzzleRouteCanaryTest` case at `now = scheduled − 1 s`.
3. **The hash check (`revealListHash`) must cover the moment as well as the rows**, so a page opened before a
   re-schedule cannot confirm a stale preview.
4. **Pause → resume with the tick** reveals at the resume moment (A). That is fine. A resume **without** the tick
   never reveals.
5. **`PublishRoundResults` "reveal now?" prompt (A §4.4):** the reveal goes in a separate dispatch from the
   controller after the publish succeeds (A says so). Keep it, and publish §1.5's reveal push.
6. **Blue-green (A §7):** an old pod reading a `stopwatch` row would throw in `RoundPuzzleReveal::from()`. The flag
   keeps the option admin-only, so **nobody chooses it in the deploy that introduces it**. Note it in the PR and
   docs/TODO.

### 2.2 Unpublished results

- B: the public sees live results only with `competitor_pages_live_results`. The competitor themself sees their own
  confirmed time without a rank. OK.
- D: per element `source: live`, default `published`, server-side gating. OK.
- Rank before publishing is never shown anywhere public (B and D both, keep).
- C's snapshots hold unpublished results on judge devices (the judge sees them anyway). See §2.7 for clean-up.
- A: public standings include only rounds with `results_published_at` set (A §3.4). OK.
- The bracket's qualified marks are shown publicly only for published rounds (A §3.5). OK.

### 2.3 Blocked and private players, drafts

- B §7 and A §3.4 are right. A's `OfficialEntrantsView` extraction (row masking shared by the round page, standings
  and the competitor page) is **mandatory and lands in A5 before B4 builds its query**. B4 then reuses it instead of
  re-implementing the group rule. If B4 is earlier, B4 does the extraction (whichever is first), and the other agent is
  told.
- D: no viewer, organiser names, opaque keys. These are documented exemptions in `BlocklistCanaryTest` /
  `DraftCanaryTest`. OK. D's display state must also leave out soft-deleted participants.
- B's table page masks a hidden occupant as "—". OK.
- Drafts: competitor pages 404 for non-editors. Displays work by token (rehearsal, documented). Standings 404.
- The tag routes keep today's draft branch.

### 2.4 Proposals: anti-cheat and ownership

1. **Self-connect bypass (Top issue 2).** `canPropose` additionally requires the participant's `connectedAt` to be
   **before the round's stopwatch start** (for a scheduled start, before the scheduled moment). The proposal view for
   judges shows "linked to this name on {date}" when the link is younger than 24 h. Document that organisers who want
   a hard guarantee confirm links on the sheet (a later setting, §3).
2. **Idempotency on a client id (Top issue 9).**
   - `ProposeRoundTime` with a known `proposalId` answers its state **only** if the row's entry and
     `proposed_by_player_id` match the request. Otherwise it is `invalid_proposal_id` (and nothing leaks).
   - The id must parse as UUIDv7 with a timestamp within ±24 h of `receivedAtMs`.
3. **Tap-time trust:** B §5.3 is accepted (2 s ahead, 5 s tolerance, lateness shown).
   - The judge card shows **the age of the tap** ("tapped 3 min ago") and, when `late`, both times.
   - With `late`, the card's default value is the **receipt time**, not the proposed time.
   - The one-tap sweep is cut (Top issue 8).
4. **A pre-tap as "insurance"** (tap early, then finish) cannot be prevented by software. The judge must look at the
   puzzle, which is why Confirm needs WHO first. The organiser guide in H says so.
5. **Rate limit** `time_proposal` (B) stays. The partial unique indexes stay (custom `custom_time_proposal_pending_*`).
   **Drop the CHECK constraint:** enforce one-of-two in the entity constructor (house style; no DB-side logic).
6. **The lock:** `ProposeRoundTime` / `WithdrawTimeProposal` take `CompetitionParticipantsLock` (B §5.4).
   - `SerializedByLockMessagesTest` lists them.
   - Load measurement in P1 (100 concurrent, p95 < 500 ms) stays.
   - If it fails, the per-round key fallback needs Jan's OK. Do not switch silently.

### 2.5 Transactions, Mercure, worker mode

- All publishes happen in controllers after `dispatch()`, never in handlers (A, B, C and D all comply).
- Missing so far: the reveal push (§1.5), and the stopwatch reset supersede publish (B's reset handler sets proposals
  `superseded` → `ResetRoundStopwatchController` publishes `official_results.proposals`).
- `ConfirmOnlineResultHandler` calls `RecordRoundResultsHandler`, so the resolver runs there too. That is harmless
  (online competitions have `time_proposals` off). Add a test that confirms the resolver is skipped.
- Worker mode: `CompetitionDisplaysPresence` (D) is the only memo and implements `ResetInterface`. `StandingCalculator`,
  `TimeProposalResolver` and `CompetitionTagResolver` must stay stateless (a `readonly` class, no array props).

### 2.6 Caching

- `competitor_state`: `private, no-store` for everybody (B's guest `public, max-age=5` is dropped). The page is
  ≤ 4 statements, and B's jittered refetch protects the server.
- Display state: `private, no-store` (D). OK.
- Public standings JSON (deferred with the overlay element) is n/a in v1.

### 2.7 Service worker and offline shell (C)

C's shell design is sound: a data-free document, per-player snapshots, no cached real page. Add these:
1. **Logout and a different user:** on logout (the existing logout page JS, plus `LogoutEvent` clearing the cookie),
   delete that player's **snapshots and drafts**. Unsent outbox items stay: they are the judge's own work and are only
   sent by the same player. On shell start, snapshots of any player ≠ the cookie are deleted.
2. **The cookie `msp_live_player`** is set only by the live page / state endpoint for organisers and referees. Never
   set it for other users.
3. **`ServiceWorkerBehaviourTest`'s "no document ever cached"** is changed intentionally to "no document except
   `/__live-shell/*`, and the shell body contains no user data". Update `.claude/rules/service-worker.md` in C6.
   `CACHE_VERSION` stays `v7` (C's argument holds: a new cache name, nothing evicted).
4. The **4 s lie-fi timeout** is limited to the live paths (C). OK.
5. **The shell must not include `msp-rendered-at`** (C §8). Pin it in a test.

### 2.8 Precaching new entries

`service-worker.js` precaches every `entrypoints` entry on install (verified). `live_entry` (~35 KB gzip) is
acceptable, and it is what makes the judge page work offline after any visit. `display` (renderer, fonts CSS) must
**not** be precached for every visitor. The install loop skips entries listed in a `PRECACHE_SKIP_ENTRIES = ['display']`
constant, plus a harness case. D4 coordinates with C6 (same file, lane order §4).

### 2.9 Byte-identity and statement budgets

F0 adds the baselines **before** any package changes code:
- a statement-count pin of `RecordRoundResultsHandler` for a plain result change set;
- a golden JSON of `official_results_round_state` for an untouched competition (fixture);
- the golden HTML of `competition_name_tags` (B says `CompetitionNameTagsTest` "unchanged", but nothing pins bytes);
- the redirect matrix of `live_results_scan`;
- `DetailPagesQueryBudgetTest` stays as is.

Each package then proves "unchanged" against the pins. C's lean layout intentionally changes the live entry page for
all competitions (page-level, not competition behaviour). That is accepted and named in the PR.

### 2.10 Migrations

Additive only, generated per package with `doctrine:migrations:diff` against a **scratch DB built from committed
migrations** (memory: local drift), never run on the dev DB. Two packages never generate migrations from unmerged
parallel branches at the same time. The lane order below serialises the entity-touching packages: lane A owns
`competition_round*` and entries; lane C owns `competition`, `competition_display` and later
`competition_time_proposal`. Verify that `DeleteCompetitionHandler` (explicit SQL deletes, verified) still works:
the new tables need FKs with `ON DELETE CASCADE` on `competition_id`/`round_id`, plus a test deleting a competition
that has a standing, a display and a proposal.

### 2.11 Smaller holes

- **D's image path rule:** add a `GetStoredFileReferences` test with display images referenced twice (duplicate
  display), and confirm that deleting one display keeps the file.
- **A `RoundPuzzleHasResults` guard:** also apply it to `MoveRoundToCompetition` and to the internal API PUT.
- **B line-up id via uuid5 over participant ids:** a soft-deleted and re-added participant gets a new id, so the
  line-up changes. Document it. The print page warning covers it.
- **C draft restore:** never auto-save. OK. Restore requires the same `playerId` (C says so).

---

## 3. Over-engineering: cut or defer (goes to `docs/TODO.md` "Competition toolkit v2")

**Deferred (v2 list):**

| Area | Deferred item |
|---|---|
| A | `every` as its own mode (folded into pick K=N); judge-side split times (desk can still enter `atSeconds`); "Add to my profile" per puzzle for K ≥ 2 (K = 1 kept); participants-sheet per-puzzle popover (the sheet shows read-only chips and the `puzzles_mismatch` message points to the desk); the `stages` standing classification and `numbered_across_stages` (the bracket view ships; final classification later); `missing_rule = as_zero_pieces`; standings Mercure; `public_standing_state` JSON; internal API **writes** of format/labels (read-only output ships); "drop worst round" / season standings (A's Q4). |
| B | `cards` layout (keep `tags` + `tents`); `competitor_list` page (fallback = the event page); proposal puzzle choice (`ChangeTimeProposalPuzzles`); the per-entry private Mercure topic (polling instead); the 90-day prune of decided proposals (TODO). |
| C | ETag/304 on the state; JS port of `LiveResultsCurrentRound::pick` with shared fixtures (the shell uses the round from the URL, else `preferredRound()`, else the newest snapshot of that competition); continuous scanning with a warm camera; "My tables" filter; referees-page activity column; organiser-assigned table ranges; the one-tap proposal sweep. |
| D | multi-select / align / distribute; snapping to siblings (grid + canvas centre/edges kept); live control (spotlight + message elements and page); "Copy from another event" (duplicate within the event and `DuplicateCompetition` copying stay); ticker `marquee`; fonts cut from 7 to 4 (Rubik, Bebas Neue, Roboto Mono, Courier Prime); the standings/bracket overlay element (cheap after A7 + public JSON: first v2 item); scenes, animations, 4:3/portrait, a series library (D already lists these). |

**Kept (Jan's scenarios need them):**
- A: reveal on start with the checkbox; pick K-of-N for solo/duo/team; desk editor; public chips; the sum standing
  with penalty and order rule; advancement steps; the bracket view.
- B: 4 tag kinds (= Jan's 3 modes, mode 1 split into person + line-up); printing; the competitor page with the live
  clock; proposals with offline outbox.
- C: offline shell, snapshots, drafts, lean layout, speed changes, QR kinds, proposal card, puzzle chips, and
  prefetching rounds within ±12 h (max 4).
- D: screens + overlays, the editor (add/move/resize/style/layers/undo/presets/upload), the 12 remaining element
  types, the token URL, live signals, images.

**Coverage check of Jan's list:**

| Scenario | Package |
|---|---|
| Timer design editor with backgrounds, logos, several styles | D1-D7 (`screen`, several displays per event) |
| Stream overlays with their own URL | D (`overlay`, token URL, transparent) |
| QR mode 1: player / pair / team for the whole competition | B2/B3 person + line-up |
| QR mode 2: table, dynamic by round | B2/B3 table |
| QR mode 3: player + table for one round | B2/B3 entry |
| Competitor page: solo/pair/team, live timer, propose a time (own entry, signed in) | B4/B5/B8 + P1 + §2.4 |
| Judges: any number with an account | exists (cap 200) |
| Judges: offline, survive refresh | C1-C6 |
| Judges WHO: table or QR + puzzle choice | C7/C8/C10 |
| Judges TIME: stop, type, confirm a proposal, edit | C7/C9 |
| Compact page, no footer | C2 |
| Pick 1 of N and K of N, any order, solo/duo/team | A2/A3/A4/C10 |
| Spider (bracket) | A8/A9 |
| Sum-up per category | A6/A7 |
| Reveal on stopwatch start + checkbox at start | A1 |

---

## 4. Consolidated implementation plan

### 4.1 Rules for the build

- **At most 3 implementation agents at once**, one per lane, each in its own worktree and its own `docker compose run`
  container (one container per agent, memory).
- Packages merge into the integration branch **`competition-toolkit`** (not main) in the order they finish. Each
  package is rebased on it before its merge. The CI gates (phpstan, cs, paratest, schema validate, cache warmup) pass
  per package.
- The orchestrator reviews every diff, client side included, before the merge.
- **Hot-file ownership:** while a lane holds a file, no other lane edits it. When a package needs a hot file owned
  elsewhere, it waits or hands the change to the owner lane.

| Hot file / area | Owner lane |
|---|---|
| `RecordRoundResultsHandler`, `RoundResultChange*`, `RoundResultChangesParser`, `RoundResultField`, `RecordedRoundResults` | A |
| `RoundResultsStateController`, `RoundResultsOverview`, `RoundResultEntry`, `GetRoundResultEntries` | A (C3's `viewer`/`entries=0` lands **before** A2, in F0+C3 order: see below) |
| `CompetitionRound`, `CompetitionRoundPuzzle`, `RoundPuzzleReveal`, stopwatch handlers/controllers, `SecretPuzzleHides` | A |
| `OfficialResultsLiveUpdates` | A (D5's hook goes in after P1) |
| `live_results_controller.js`, `live_results.html.twig`, `official_results_outbox.js`, `official_results_scan.js`, `public/service-worker.js`, `webpack.config.js` | B |
| `Competition` entity, `competition_name_tags`, `NameTagQrCode`, `GetPublishedRoundResults` (after A5), the competitor page, displays | C |
| `DuplicateCompetitionHandler` | A (A6 standings), then C (D8), sequenced |
| `results_desk_controller.js`, `advance_qualified_controller.js` | A (A4), then B (A8) |
| `messages.en.yml` | everyone, but **each package appends one contiguous block under its own prefix** (`competition_toolkit.*`, `competitor_page.*`, `competition_display.*`, `live_results.*` additions grouped). The 5 other locales only in H. |

### 4.2 Packages

Sizes are in agent-days. "Read" points at the design sections each agent must read besides this doc.

| Id | Scope | Depends on | Size | Key tests | Read |
|---|---|---|---|---|---|
| **F0 Foundation** | `CompetitionToolkitFeature` + `CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE` + env flag + `config/services.php` + `feature_flags.md`; hub page `competition_toolkit` skeleton (cards with placeholders) + link; `RoundPuzzlesVisibleNow` extracted from `GetEditionRounds`; baseline pins (§2.9) | - | 1.5 | voter matrix (admin / organiser × flag); `GetEditionRounds` tests unchanged; new pins green on main | R §1.4, §1.5, §2.9; D §9.3, §9.5 |
| **A1 Reveal on stopwatch start** | A §4 entirely + **§2.1 fixes** (reschedule withdraw, hash covers the moment) + reveal push (§1.5) + round puzzles page option + manage-stopwatch and control-room checkbox (unticked) | F0 | 2 | A §10 reveal parts; reschedule matrix; public stopwatch JSON key assertion; `SecretPuzzleRouteCanaryTest` scheduled−1 s | A §1.4, §4; R §2.1 |
| **A2 Round format model** | `puzzle_format total\|pick`, K 1..N, round puzzle `position`/`label`, round form (flag-gated), guards, online-round refusal, round JSON `puzzleFormat` via `RoundPuzzlesVisibleNow` (public rule), internal API read-only output | A1 | 1.5 | `puzzleFormat` absent on `total` (golden unchanged); hidden names null for organiser and referee; guards | A §2.1, §2.5, §2.6 (round form + puzzles page), §6.1; R §1.1 |
| **A3 Results protocol** | `RoundEntryPuzzles`, `result_puzzles` jsonb, `RoundResultField::Puzzles`, rules 1-7 + group pass + locks, `hasOfficialData`, entry JSON, removal/format guards, `official_results_puzzles.js` + parity test, seeding bound | A2 | 2 | A §10 handler list; statement pin unchanged without `puzzles`; group atomicity | A §2.2-2.5, §6.2-6.3 |
| **C1 Live page data-free** | C §1.2 + `?viewer=1` / `?entries=0` / `?table=` / `?entry=` on state & page (C3's server bits that touch the state controller - **done here, before A2 starts editing that controller**; if A2 is already in, coordinate via the lane-A agent) | F0 | 2 | existing `LiveResultsPageTest` green; golden state unchanged without `viewer=1` | C §0, §1.2, §1.3 rows 2,4,6; R §1.8 |
| **C2 Lean layout + `live_entry`** | C §1.1, shared self-heal partials, no Turbo, `LiveEntryBundleTest`, SW precache-skip constant for future `display` | C1 | 1.5 | bundle budget; other pages' HTML byte-identical (self-heal partials) | C §1.1, §4.3; R §2.8 |
| **C3 Shell server side** | `live_results_shell`, `msp_live_player` cookie + logout subscriber, event-people endpoint | C1 | 1 | shell has no user data / no Set-Cookie / no `msp-rendered-at`; cookie set only for organisers/referees | C §1.3; R §2.7 |
| **C4 Snapshots + drafts** | `live_entry_snapshot.js`, `live_entry_draft.js`, prefetch ±12 h max 4, retention, logout/other-player wipe | C1 | 1 | node harness C §7.1 (snapshot, draft parts) | C §1.5, §1.7; R §2.7 |
| **C5 Shell mode** | controller shell mode, route parsing (`live_entry_route.js`), no-snapshot/neutral screens, offline strip, going live | C2, C3, C4 | 1.5 | harness; route parsing for all frozen paths | C §1.6; R §1.3 |
| **C6 Service worker** | `live-shell:prepare`, live navigation + 4 s timeout, prune keeps shell assets, `/barcode-scanner/` cache-first, self-hosted zbar first, `ServiceWorkerLiveRoutesTest`, rule file update | C3, C5 | 1.5 | SW harness C §7.2; `ServiceWorkerBehaviourTest` changed intentionally | C §1.4; R §2.7, §2.8 |
| **C7 Speed** | unique-table auto-open, confirm in place + 350 ms guard, `enterkeyhint`, O(1) lookup, outbox grouping, perf budget | C1 | 1 | harness perf (300 + 300 < 5 ms); `uniqueTable` cases | C §2.1, §4 |
| **C8 QR kinds (judge)** | `parseCompetitionTagUrl` (all 4 kinds, frozen paths), table/entry/line-up handling incl. offline lookup across snapshots | C5 | 1 | parser harness (locale/no-locale, other hosts, garbage, printed name tag unchanged) | R §1.3; C §2.2 (minus continuous scan) |
| **B1 Competitor settings** | 4 `competition` columns, `CompetitorPageSettings`, `ChangeCompetitorPageSettings`, form as a **hub card** | F0 | 1 | defaults = today; normalisation (pages off ⇒ proposals off) | B §2; R §1.4 |
| **B2 Tag routes** | `CompetitionLineupId`, `CompetitionTagResolver`, three locale-less routes (+ optional locale), `live_results_scan` branch 2, `PreferredLocale` extraction | B1, C8 (parser exists; or B2 adds the PHP side only and tests against C8's parser when merged) | 1.5 | resolver matrix (B §9); `LiveResultsLinksTest` unchanged; scan redirect pins | B §3.1-3.3; R §1.3 |
| **B3 Printing** | `kind` (people/lineups/tables/entries) × `layout` (tags/tents), three tag queries, `NameTagQrCode` URL builders, line-up change warning | B2 | 1 | name-tags golden unchanged by default; print test per kind; `#CODE` only for public profiles | B §3.4 (minus cards) |
| **A5 Public round page + entrants view** | `OfficialEntrantsView` extraction (**before B4**), per-puzzle chips (public rule), K=1 "Add to my profile" | A3 | 1.5 | `OfficialRoundResultsPageTest` unchanged for `total`; canaries for pick rounds | A §2.7, §3.4 (extraction) |
| **B4 Competitor page (read)** | `GetCompetitorPage` using `OfficialEntrantsView` + `RoundPuzzlesVisibleNow` + `ranksOf()`, 4 routes, templates, 404 rules, `competitor_state` (`no-store`) | B1, A5 | 2 | `CompetitorPageQueryBudgetTest` (4/5); canaries (Blocklist, PrivateProfile, Draft, SecretPuzzleRoute); ranks = round page | B §4.1, §4.2, §4.4, §7; R §2.3, §2.6 |
| **B5 Competitor live clock** | `competitor_page_controller.js`, clock module reuse, stopwatch topics ±12 h, jittered refetch, wake lock | B4 | 1.5 | harness (phases, tap captured synchronously) | B §4.3 |
| **P1 Proposals server** (B6 + B7 merged, in lane A) | entity + migration + custom partial unique indexes (no CHECK), `ProposeRoundTime`/`WithdrawTimeProposal` + controllers + rate limiter, **§2.4 ownership rules**, `proposalId` + `field: proposal` in the change protocol, `TimeProposalResolver`, reset supersede, `proposals` in the state, `lineupId` on team entries, `official_results.proposals` publishes, load measurement | A3, B1 | 3 | B §9 handler list + §2.4 cases (self-connect after start refused, foreign id replay refused); resolver skipped when off (statement pin); lock test | B §5, §6 (with R §1.2 edits); C §3.2 |
| **C9 Proposals in the judge page** | proposal panel on the card (Confirm / Change / Not right), "Proposals waiting" list (row opens the card), late → receipt default, outbox `proposalId` + `proposal` reject item | P1, C7 | 1.5 | harness: outbox fields, ordering, late default | C §3 (minus sweep); R §1.2, §2.4 |
| **C10 Puzzle choice in the judge page** | chips (labels before reveal, public rule), pick-K order, grouped `puzzles`+`result` saves, "Puzzle missing" filter | A3, C7 | 1.5 | harness: preselect, group never split, hidden-name defence | C §2.3 (minus splits); R §1.1 |
| **B8 Competitor proposal UI** | Stop button, `competitor_proposals_outbox.js`, states, 5 s polling while pending, sign-in hint | P1, B5 | 1.5 | harness: outbox resend keeps `tappedAtMs`; end-to-end with C9 in the browser walk | B §5.1, §5.7, §5.8 |
| **A4 Desk per-puzzle editor** | desk editor (group writes), row chips, export "Puzzles" columns | A3 | 1.5 | desk controller test; export test | A §2.6 (desk + export only) |
| **A6 Standings (sum)** | `CompetitionStanding`, messages, `StandingCalculator::sum` (penalty, `order_rule`, `missing_rule=incomplete`), organiser page, organiser JSON, duplicate support, delete-round prune | A5 | 2 | `StandingCalculatorTest`; duplicate handler test | A §3.1-3.4, §3.6 (minus public JSON) |
| **A7 Public standings** | `event_standing` / `edition_standing`, links (event parts, round page, overview, sitemap), publishing rules, canaries + budgets | A6 | 1.5 | `DetailPagesQueryBudgetTest` +0; canaries; draft 404 | A §3.4, §3.6, §8 |
| **A8 Advancement steps** | `CompetitionAdvancementStep`, graph validation, desk step banner, Top N preset, `advance_qualified_controller.js` prefill | A4 (desk JS) | 1.5 | `AdvancementStepGraphTest`; prefill test | A §3.5 (minus stages standing) |
| **A9 Bracket view** | organiser + public bracket (CSS grid + SVG), published-only qualified marks | A8, A7 | 1.5 | canaries; 375 px render | A §3.5 bracket |
| **D1 Displays core** | entity + migration, messages (add, rename, locale, follow, duplicate, delete, regenerate), list partial on the hub + full list page | F0 | 1.5 | handler + voter tests; cap 20 | D §2, §3, §8.1 |
| **D2 Schema + presets** | `DisplayDesignSanitizer` + `schema.js` + shared fixtures, 7 presets, save endpoint (409/422) | D1 | 1.5 | parity fixtures; preset idempotence | D §4, §6 |
| **D3 Display read side** | `GetCompetitionDisplayState` (uses `RoundPuzzlesVisibleNow`), state/design endpoints, `forDisplay()` token | D2, A2 (pick rounds' puzzles) | 1.5 | secret-puzzle matrix incl. `stopwatch` mode; opaque keys; statement pin | D §7.2, §9.3, §9.4 |
| **D4 Renderer** | standalone template, `display` entry (precache-skipped), `render.js`, `runtime.js`, overlay transparency | D3, C2 (webpack + SW constant) | 2 | render/runtime harnesses incl. XSS corpus and leak check | D §7 |
| **D5 Display signals** | `CompetitionDisplayLiveUpdates`, `CompetitionDisplaysPresence`, hooks (results, reveal push, round edit, design) | D3, P1 merged (`OfficialResultsLiveUpdates` free) | 1 | hub spy: nothing new without displays; no publish on rollback | D §7.3 |
| **D6 Editor** | editor + stage controllers, `geometry.js` (grid/centre snap only), layers, property panel, preview phases + sample data, save flow, undo | D2, D4 | 2 | geometry harness | D §8.2 (minus multi-select, sibling snapping) |
| **D7 Display images** | upload + limiter, URL builder with `/original/` fallback (+ parameter), `GetStoredFileReferences` branches, `DeleteUnreferencedStoredFiles` extraction | D2 | 1 | references test incl. duplicates | D §3.2, §10.1-10.3 |
| **D8 Duplicate + links + fonts** | `DuplicateCompetitionHandler::copyDisplays()` (after A6's change), flag-gated links, 4 fonts | D1, A6 | 1 | duplicate test with remapped pinned round | D §3.3, §8.1, §10.4 |
| **H Hardening** | see §4.4 | all | 3 | - | everything |

### 4.3 Lanes (3 agents in parallel; order within a lane is binding)

| Lane | Packages, in order | Days |
|---|---|---|
| **A - results core** (owns the write path, state JSON, rounds, stopwatch) | F0 → A1 → A2 → A3 → P1 → A5 → A4 → A6 → A7 → D7 → D8 | ≈ 18.5 |
| **B - judge page** (owns live entry JS, outbox, scanner, SW, webpack) | (after F0) C1 → C2 → C3 → C4 → C5 → C6 → C7 → C8 → C10 (after A3) → C9 (after P1) → A8 (after A4) → A9 | ≈ 18 |
| **C - public surfaces** (owns `competition` settings, tags, competitor page, displays) | (after F0) B1 → D1 → D2 → B2 (after C8, else PHP side first) → B3 → D3 (after A2) → B4 (after A5) → B5 → D4 (after C2) → D5 (after P1) → B8 (after P1) → D6 | ≈ 18.5 |

Notes:
- **F0 runs alone (lane A) for ~1.5 days.** Lanes B and C start C1/B1 as soon as F0 merges.
- C1 touches `RoundResultsStateController` (the `viewer`/`entries`/`table`/`entry` params). It **must merge before A2
  starts editing that controller**. In practice A1 (2 days, no state-controller change) runs meanwhile, so the order is
  natural. If C1 slips, A2 waits on its JSON part.
- Lane C interleaves B and D so that it never idles on lane A's dependencies (A2 → D3, A5 → B4, P1 → D5/B8).
- When a lane frees up early, it takes the next **dependency-ready** package of another lane only if that package
  touches no hot file the other lane holds. D7 and A9 are the usual swaps.
- Integration checkpoints (orchestrator, browser):
  - (1) after A1 + C6: the reveal checkbox, and offline refresh on the judge page;
  - (2) after P1 + C9 + B8: the full proposal loop on two phones;
  - (3) after D6: the editor → display → OBS.

### 4.4 H - final hardening (1 agent, then the orchestrator; ≈ 3 days)

1. **Canary sweep:** every new surface is in the right canary or in its documented exemption list.
   - Blocklist: competitor pages/state, standings, bracket, pick-round page; displays are an exemption.
   - PrivateProfile: competitor pages/state, displays, standings.
   - Draft and DraftVisibilityCoverage: all new readers; displays are an exemption.
   - SecretPuzzleRoute: competitor, display state, judge state, standings, the `stopwatch` mode before and after.
   - Trackerless / SeriesPick / CustomValues: n/a lists.
   - `SerializedByLockMessagesTest`: the proposal messages.
2. **Docs:**
   - new `docs/features/competitions-management/` files: `round-formats.md`, `standings.md`, `competitor-pages.md`,
     `displays.md`, `judge-offline.md`;
   - updates to `live-results.md`, `official-results.md`, `README.md` (stopwatch reveal), `internal-api.md`
     (`stopwatch` reveal mode, `puzzleFormat` output), `online-competitions/obs-youtube.md` (overlays replace the crop
     trick);
   - an as-built summary per feature in `.claude/rules/features/events.md` (budget ≤ 35,000; `ClaudeInstructionsTest`);
   - `.claude/rules/service-worker.md` (already done in C6, re-check);
   - `docs/features/feature_flags.md`;
   - `docs/TODO.md` "Competition toolkit v2" = §3's deferred list, plus the lily imgproxy `display` preset ops step and
     the proposals prune.
3. **Translations:** every new key in cs, de, es, fr, ja (skill `missing-translations`). Respect
   `.claude/rules/translations.md` wording (never "suspicious", never "rival").
4. **Full CI gates** on the integration branch: phpstan, cs, paratest, schema validate, cache warmup, plus a Panther run
   of `LiveEntryOfflineTest`.
5. **Browser walk of every scenario** (dev Selenium + real phones where noted):
   1. Timer screen preset → edit → open on the second screen → start with countdown.
   2. Overlay in OBS 30+ over a colour source, transparent.
   3. Print person, line-up, table and entry tags; scan each as a guest, as a linked competitor and as a judge.
   4. Competitor page: the clock follows the start to within 1 s, propose, undo, propose again, offline tap → online.
   5. Judge on iPhone Safari and Android Chrome:
      - table auto-open, Finished now, typed time, confirm a proposal from the card (late case shows both);
      - reject offline;
      - airplane-mode refresh → shell → save → back online → synced;
      - logout clears the snapshots.
   6. Pick-1-of-3 round (solo) and pick-2-of-3 round (team): chips are labels before the reveal; the group save;
      an unfinished sum; the desk editor; the public chips.
   7. Reveal at the stopwatch start: a test run without the tick (still hidden everywhere), reset, a scheduled start
      with the tick, **re-schedule later without the tick (still hidden)**, the real start → the puzzle appears on the
      judge chips, competitor page and display within 2 s.
   8. Sum standing over 6 rounds per category (solo/pair/team), provisional while rounds are unpublished.
   9. Bracket groups → semis → final with step presets and Advance prefilled.
   10. An untouched competition: the golden pins are green, and a manual look at the event page, live entry, desk and
       name tags.
6. **Load:** 100 concurrent proposals (p95 < 500 ms), 300 competitor pages on one stopwatch start, and a 1 h display
   soak with live results (memory flat).

---

## 5. Decisions for Jan

These are only the ones that change behaviour. The defaults are in place and the build proceeds with them.

1. **`every` is folded into "pick K of N" with K = N.** Organisers see "Solve all 3" / "Pick 2 of 3" / "One result for
   the round".
2. **The results desk shows puzzle labels (not names) before the reveal, even to organisers.** One rule for every
   toolkit surface.
3. **Proposals:**
   - only from a participant linked before the round's start;
   - a late proposal defaults to the receipt time on the judge's card;
   - no confirming from a list without opening the entry.
4. **Deferred to v2:**
   - final classification across bracket stages;
   - split times entered by judges;
   - the standings overlay element;
   - display live control (spotlight/message);
   - the competitor list page;
   - proposal puzzle choice.
