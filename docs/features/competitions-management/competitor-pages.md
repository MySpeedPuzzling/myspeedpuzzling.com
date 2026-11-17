# QR tags, public competitor pages and time proposals (competition toolkit B1-B5, B8 + P1/C9)

The competition toolkit's QR feature end to end: the organiser's settings (B1), printed tags of four kinds and where a
scan of each leads (B2, B3), the public competitor page a non-judge lands on (B4) with its live clock (B5), and the
competitor's "propose my time" (B8) that a judge confirms (P1 server, C9 judge panel). Design:
`toolkit/B-qr-competitor-page.md`; binding `toolkit/R-review-and-plan.md` §1.3 (frozen paths), §1.4 (voter, flag,
hub), §2.3, §2.4 (proposal ownership), §2.6. Build log: `toolkit/STATUS.md` (B and P1/C9 rows).

## Settings (B1)

Four `competition` columns, all defaulting to the behaviour before the toolkit; written only through
`Competition::changeCompetitorPageSettings()`, read as `competitorPageSettings()`:

| Column | Default | Meaning |
|---|---|---|
| `competitor_pages` | false | Public competitor pages exist; a non-judge's scan opens one |
| `competitor_pages_live_results` | false | "Show confirmed results before publishing" (never a place) |
| `time_proposals` | false | Competitors may propose their time (B8) |
| `qr_tag_mode` | NULL | `QrTagMode`: `entrant` / `table` / `round_entry`; NULL = not chosen (person name tags as before) |

- `CompetitorPageSettings` (value) is always normalised: **pages off ⇒ proposals off** (`timeProposals = competitorPages
  && timeProposals`). The live-results flag is kept as stored when pages are off (it does nothing then). Proposals are
  also never effective on an online event (readers and `ProposeRoundTimeHandler` add `NOT is_online`).
- Written by `ChangeCompetitorPageSettings` → `ChangeCompetitorPageSettingsHandler` (competition row only, no
  participants lock). Read for the hub by `GetCompetitorPageSettings` (one statement).
- **Form:** the "Competitor pages & QR codes" card of the toolkit hub (`competition_toolkit`,
  `/{_locale}/manage-event-toolkit/{competitionId}`, cs `/nastroje-udalosti/…`, GET/POST in
  `CompetitionToolkitController`; `CompetitorPageSettingsFormType` / `…FormData`, partial
  `templates/competition_toolkit/_card_competitor_pages.html.twig`): QR mode radios (with help per mode), the pages switch
  and, indented below it, live results + time proposals. Saved = flash + redirect, refused = hub with 422. An online
  event gets "in-person only" instead of the form. Below it "Print tags": one link per print kind, the saved mode's
  kind first (`CompetitionTagPrintKind::defaultFor()`).
- **Who:** the hub is behind `CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE` (= `COMPETITION_EDIT`: every
  organiser of the event, admins). **Using** what was switched on (pages, tags, proposals) needs no organiser rights.
- **Duplicate:** `DuplicateCompetitionHandler` copies all four settings to the copy (event settings, like registration;
  `DuplicateCompetitionHandlerTest::testDisplaysAndCompetitorPageSettingsAreCopiedOntoTheCopysRounds`).
- Tests: `CompetitorPageSettingsTest` (normalisation), `ChangeCompetitorPageSettingsHandlerTest`,
  `tests/Controller/CompetitionToolkit/CompetitorPageSettingsCardTest.php`.

## QR modes and tag routes (B2)

Jan's three modes (`QrTagMode`) only decide what the print page offers first - **every printed kind keeps resolving
whatever the mode**:

| Mode | Printed kind(s) | `CompetitionTagKind` / letter |
|---|---|---|
| `entrant` - a tag per competitor for the whole competition | people, or line-ups in an event of pairs/teams only | `p` person, `g` line-up |
| `table` - a card per table for the whole competition | tables | `t` |
| `round_entry` - a tag per competitor per round | entries of one round | `e` |
| NULL (not chosen) | people (the name tags of before) | `p` |

- **Line-up** (`CompetitionLineupId::of()`): pairs/teams are per-round `CompetitionTeam` rows, so "the same pair for the
  whole competition" is derived, never stored: **uuid5 in the competition id's namespace over the sorted, lower-cased,
  de-duplicated member participant ids joined by `,`**. Same people in rounds 1-6 = one id; a changed member (or one
  removed and re-added - a new participant id) = another line-up. JS twin `lineupIdOf()` (`assets/live_entry_tag.js`), shared
  cases `tests/fixtures/competition-lineup-id-cases.json`.
- **Frozen printed paths** (R §1.3 - never change them), each with an optional language prefix:

| Route (+ `_localized`) | Printed path | Localized path |
|---|---|---|
| `live_results_scan` (exists, never touched) | `/{_locale}/live/{cid}/p/{participantId}` | - (always has a locale) |
| `live_results_lineup_tag` | `/live/{cid}/g/{lineupId}` | `/{_locale}/live/{cid}/g/{lineupId}` |
| `live_results_table_tag` | `/live/{cid}/t/{tableNumber}` (`[1-9]\d{0,3}`) | `/{_locale}/live/{cid}/t/{n}` |
| `live_results_entry_tag` | `/live/{cid}/e/{entryId}` (bare `CompetitionParticipantRound` / `CompetitionTeam` id, no round id) | `/{_locale}/live/{cid}/e/{entryId}` |

- Locale-less paths pick the language with `PreferredLocale` (profile locale, else the browser's, else `en`).
- `CompetitionTagResolver` (stateless, `GetCompetitionTagEntries`): `resolve()` = which round and entry the tag means now -
  person/line-up/table take `LiveResultsCurrentRound::pick()` among the tag's rounds, else among all rounds; an entry tag
  always its own round. `known` = a participant of the event / a line-up or entry some round has. `knows()` for the
  public landing: a table is always known.
- `CompetitionTagLanding::redirect()` - all four routes (the old name tag included) land through it, every answer
  `private, no-store` + `X-Robots-Tag: noindex, nofollow`:
  1. **Organiser or referee** (`COMPETITION_RESULTS_ENTRY`) → `live_results` of the resolved round with the entry open:
     `?entrant=` (person), `?table=` (table), `?entry=participant_round:…|team:…` (line-up, entry); a tag of nobody →
     current round `?notice=tag_unknown`. No round at all: organiser → rounds page, referee → event page, both with a
     "no rounds" flash.
  2. **Anybody else, competitor pages on** and the tag known → `competitor_page` (`CompetitorPageUrl::of()`).
  3. Otherwise the event page; a draft (for anybody off its team) or unknown event → events list.
  Pages off = exactly as before the toolkit.
- On the judge side the one parser is `parseCompetitionTagUrl()` (`assets/official_results_scan.js`, C8,
  `live-results.md`).
- Goldens (`tests/Controller/LiveResults/golden/`): `live-results-scan-matrix.txt` / `-competitor-pages.txt`
  (`LiveResultsScanRedirectMatrixTest`, old name-tag path), `live-results-tag-matrix-off.txt` / `-on.txt`
  (`LiveResultsTagRoutesTest`, viewer × every tag case). Also `CompetitionTagResolverTest`, `CompetitionTagLandingTest`,
  `CompetitorPageUrlTest`, `LiveResultsLinksTest`.

## Printing (B3)

`competition_name_tags` (`/{_locale}/name-tags/{cid}`, cs `/jmenovky-ucastniku/…`, `COMPETITION_EDIT`). The toolkit page
(`live_results/competition_tags.html.twig`, variables from `CompetitionTagSheet`) renders once the competition has a QR
mode or competitor pages, or for whom `COMPETITION_TOOLKIT_MANAGE` grants when they ask for `?kind=`/`?layout=`.
Otherwise it is the old name tags page **byte for byte** (`name_tags.html.twig`, `CompetitionNameTagsGoldenTest`).

- `?kind=` (`CompetitionTagPrintKind`, default from the mode) × `?layout=` (`CompetitionTagPrintLayout`: `tags` = the A4
  2 × 5 sheet of 90 × 55 mm, `tents` = one fold-in-half table tent per A4 landscape page, upper half upside down;
  tables default to tents, the rest to tags). Sort by name/table (tables and entries default to table).
  - `people`: `GetCompetitionNameTags` (as before, `?round=`, `?waitlist=1`), QR `NameTagQrCode::url()` (locale path).
  - `lineups`: `GetCompetitionLineupTags` - one tag per member set, table of its first round (or of `?round=`); QR
    `lineupUrl()`. **Line-up change warning:** line-ups whose people play in another line-up in a later round of the
    same category are listed ("print a tag for the new pair or team too" - the old tag no longer shows those rounds).
  - `tables`: `GetCompetitionTableTags::usedNumbers()` - every number 1..highest used (gaps get a card), or
    `?from=`/`?to=` (max 300 per page, 1-9999); no names; QR `tableUrl()` ("Scan to follow this table").
  - `entries`: `GetRoundEntryTags` for `?round=` (default the first by schedule); QR `entryUrl()`.
- `#CODE` only for a **public** profile, whoever prints, in every query; names as
  the organiser recorded them, no blocklist (organiser tooling). Each query one statement.
- Hints: per kind `name_tags.toolkit.hint_{kind}` + per layout `hint_tags|hint_tents`, followed by
  `competitor_page.print.scan_page` ("Anybody else who scans it opens the public competitor page.") or `scan_event`
  ("… lands on the event page.") by the pages setting. The old `name_tags.hint` ("anybody else … lands on the event
  page") stays true: the old page shows only while pages are off.
- Tests: `CompetitionTagsPrintTest`, `CompetitionNameTagsTest`, `CompetitionNameTagsGoldenTest`.

## Competitor page (B4)

Where a scanned tag leads anybody but the event's organisers and referees while "Public competitor pages" is on.

### Routes

| Route | Path | Notes |
|---|---|---|
| `competitor_page` | `/en/competitor/{competitionId}/{kind}/{id}` (cs `/soutezici/…`, es `/es/competidor/…`, fr `/fr/concurrent/…`, de `/de/teilnehmer/…`, ja `/ja/competitor/…`) | `kind` = the tag's letter: `p` person (participant id), `g` pair/team line-up (`CompetitionLineupId`), `t` table (1-9999), `e` one round's entry (`CompetitionParticipantRound` / `CompetitionTeam` id). The contract of `CompetitorPageUrl` - the scan landing (`CompetitionTagLanding`, B2) redirects here. |
| `competitor_state` | `GET /competitor-state/{competitionId}/{kind}/{id}` | The same view as JSON for the live part. |

- **404** (`CompetitorPageNotFound`, the page and the JSON alike, `CompetitorPageLoader`): competitor pages off - for
  organisers too, nothing public by accident; a draft (or an edition of a draft series) for anybody off its team; a
  kind/id that do not fit (`/t/<uuid>`, `/p/12`); a person not in the event or removed from it; a line-up no round has;
  an unknown entry; **a person, line-up or entry hidden from the viewer** (blocklist, the group rule - a member viewing
  their own pair is never hidden). A table always has a page: "No round uses this table yet".
- Both answers are `private, no-store` and `X-Robots-Tag: noindex, nofollow`; the page has `<meta name="robots"
  content="noindex, nofollow">` and no canonical/hreflang - viewer-dependent pages of people, never indexed.
- The tag routes' "pages on" goldens (`live-results-tag-matrix-on.txt`, `live-results-scan-matrix-competitor-pages.txt`)
  show every public row of a known tag landing here.

### What it shows (`GetCompetitorPage` → `CompetitorPageView`)

- **Header:** the person (`p`), the pair/team name + members (`g`, the latest round's team name), the entry's people
  (`e`), or "Table N" (`t`). Names are the organiser's, masked like the public round page
  (`OfficialEntrantsView::entrant()`): avatar, profile link and `#CODE` only for a profile the viewer may see; a
  private player keeps the organiser's name only. "Your competitor page" when the viewer is the person / a member.
- **Rounds:** the tag's rounds in schedule order (person: every round they are in - solo entry, their pair/team, or
  "No pair or team yet"; line-up: rounds the exact member set plays; table: whoever sits there per round; entry: its
  round). The **current round** (`LiveResultsCurrentRound::pick()` among the tag's rounds - the scan landing's rule) is
  marked "Now"; a table page lists it first. Each card: name, category, start in the event's zone (`event_time`), time
  limit, table, stopwatch phase badge (in progress / starting soon / paused), who plays (table pages and a person's
  pair/team rounds), puzzles, result.
- **Puzzles:** `RoundPuzzlesVisibleNow` only - the public rule for every viewer, organisers included. A one-result round
  lists the puzzles everybody may see (picture unless under wraps), otherwise "Puzzle revealed at the start" until the
  round is published - never how many are secret. A `pick` round: "Pick K of N" / "Solve all N" and its places by
  label, a puzzle named once out. Nothing of a hidden puzzle (name, picture, catalogue or round puzzle id, EAN) in the
  HTML or the JSON.
- **Results** (`CompetitorResultState`):
  - published round: the official result, **official place** and qualified mark from `OfficialEntrantsView`
    (`forRounds()` over the published rounds shown - the round page's rows and ranks, ties included, never computed
    here); a pick entry's chips (`PublishedEntryPuzzle::listOf()`). **"Did not start" is shown** from the entry itself
    (official, no place - `GetCompetitorPage::entry()`, `CompetitorPageControllerTest` asserts it), although STATUS
    decided public pages never show it - open for H;
  - not published + "Show confirmed results before publishing" (`competitor_pages_live_results`): the confirmed result,
    "Confirmed by the judge - not official yet", **never a place or qualified mark** (`live`; a "Did not start" too);
  - not published, setting off: nothing - except **for the competitor themself** (the linked person or a linked member
    of the pair/team, signed in): "Your time, confirmed by the judge - not published yet" (`own`), no place;
  - a masked entry (table page / a person's pair round with a player hidden from the viewer): "—", nothing of it.

### `competitor_state` JSON

`{serverNowMs, kind, id, rounds: [{roundId, name, category, startsAtMs, minutesLimit, tableNumber, current, topic,
stopwatch (RoundStopwatchState), published, puzzles, puzzleFormat? (pick only), nextRevealAtMs, masked, entry: {isTeam,
teamName, entrants: [{name, country}], own, unpaired, result: null | {state, seconds, piecesPlaced, piecesCount,
didNotStart, rank, qualified, puzzles}, entry? (the viewer's own entry ref only)}}], mercure: {hubUrl, topics}}`.
Topics are the public `/round-stopwatch/{roundId}` of the tag's rounds. No player ids or codes (names as on the page).
`private, no-store` for everybody (R §2.6). The page's root carries `data-competitor-page-state-url-value`,
`-server-now-ms-value`, `-mercure-url-value`, `-topics-value` for B5's controller.

## Live clock (B5)

`assets/controllers/competitor_page_controller.js` (lazy Stimulus, `data-controller="competitor-page"` on the page root)
is the DOM adapter of the pure module `assets/competitor_page_live.js` (`CompetitorPageLive`, node harness
`tests/competitor-page-live-harness.mjs` → `CompetitorPageLiveScriptTest`).

- **First state inline:** the root's `data-competitor-page-state-value` is the view's JSON - exactly the
  `competitor_state` answer without `mercure` (pinned by `CompetitorPageControllerTest`), so a running round's clock
  shows at once, without a fetch. Labels in `data-competitor-page-labels-value` (`competitor_page.round.*`,
  `competitor_page.clock.*`).
- **The clock** is `round_stopwatch_clock.js` unchanged (`stopwatchView()` - the projector's arithmetic): countdown to a
  scheduled start ("Starts in 0:00:09"), elapsed + "1:29:47 left" while running, `times_up` at the limit, frozen when
  stopped (shown as a clock only on the current round - a round stopped at its end is history). A scheduled start turns
  into the running clock locally at the moment. Drawn at each whole second (`nextChangeMs()`), not every frame.
  Each card gets `data-competitor-round-phase` + `is-phase-*`, its badge (`[data-competitor-round-badge]`) and the
  clock (`[data-competitor-clock]`) updated in place.
- **Server time:** `createServerClock()` - hint from `serverNowMs`, two samples at start (0 s, 1.5 s), then every state
  fetch; the shortest round trip wins. On the monotonic clock (`monotonicNow()`, F1 D2): a change of the phone's clock
  while the page is open moves neither the card's clock nor `tapNowMs()`; a sleep / clock change seen (wall and
  monotonic clock disagree by > 2 s) drops the samples and asks for the state (a sleep while hidden is counted at once).
- **Mercure:** the public `/round-stopwatch/{roundId}` topics of the shown rounds that are started/counting down or
  start within ±12 h (nearest first, at most 12), one anonymous stream through `official_results_events.js` with a
  *public subscription* (`{url, topics, public: true}`: no token, no renewal, other topics → new stream first; refused
  → no stream, the page polls). A push is applied at once (newest `asOfMs`).
- **State fetches (300 phones):** on a push within 0-1 s (random; several pushes = one fetch), at each
  `nextRevealAtMs` + 300 ms + 0-1 s, 1 s after a scheduled start + 0-1 s, after the stream reopens 0-3 s, when the page
  is shown again 0-0.5 s. Polling: 5 min with a live stream (clock sample), else 15 s while a shown round runs/counts
  down and 60 s otherwise, ±20 %, doubled per failed fetch up to 5 min, nothing while hidden; 404 (pages switched
  off) → no stream, a look every 5 min. Visibility of a puzzle is never computed in the browser.
- **Changed cards** (puzzles, results, who plays, round list - `contentKey()`; the stopwatch is not content) are
  re-drawn from the page's own HTML (one GET of the page, `[data-competitor-rounds]` / the changed
  `[data-competitor-round]` swapped) - the markup lives in Twig only.
- **Wake lock** (`navigator.wakeLock`) while a round of the page's entrant (not masked, not unpaired, an entry) runs or
  starts within 5 minutes; re-acquired when the page is shown again.
- **For B8:** `tapNowMs()` on the controller reads the server's time synchronously from the same clock (read it first
  in the tap handler), `roundView(roundId)` the card's phase, `competitor-page:phase` events ({roundId, phase}) on the
  root, and an empty `[data-competitor-round-actions]` slot in each card for the Stop button.
- Dev: the local hub refuses anonymous subscribers (no `anonymous` directive; production allows it) and its CORS list
  is `localhost:8080` only - locally the page lives on the refetch path.

## Propose my time (B8)

Jan: the competitor, signed in as the person linked to the tag, taps Stop on their own page → a **proposal** that a
judge confirms (C9), never official on its own. Server side: P1 (`official-results.md` "Time proposals").

- **Who sees the button:** only with the event's time proposals on (`competitor_pages AND time_proposals AND NOT
  is_online`), only on the viewer's **own** entry (the linked solo entrant or a member of the pair/team - person, line-up,
  table or entry page alike): the round JSON then carries `canPropose: true` + `proposal` (their latest proposal of
  the entry: the pending one, else the last tapped, `TimeProposalView::forCompetitor()` - no judge identity, no link
  moment; `GetTimeProposals::latestOfEntries()`, one statement, only then). Somebody else, a guest or an organiser
  looking at the page gets neither key, nor the page's endpoints/CSRF token. Without time proposals the JSON and the
  HTML are unchanged. The browser shows the button while the round's phase is `running`, the entry has no result and
  nothing waits for the judge (`assets/competitor_proposals_view.js`); the server checks everything again
  (`linked_after_start`, `times_up`, … shown plainly as the server's translated message).
- **A guest** on a page whose entrant has a linked profile: "Sign in to propose your time" (`signInToPropose: true`
  in the round JSON, the hint in the card before the round starts only - the controller hides it once the round runs;
  login with `?return=` back to the page). It never says who is linked.
- **The tap:** the page root carries `propose-url`/`withdraw-url` (route templates with a nil UUID), the stateless CSRF
  token `time_proposal`, the viewer's id and the labels. The click handler reads `tapNowMs()` first, synchronously;
  "Propose 0:49:12?" (the time of the tap, not of the confirm) → Propose stores it in the phone's outbox
  (`assets/competitor_proposals_outbox.js`, IndexedDB `msp_competitor_proposals`, the id = a UUIDv7 made from the tap
  moment, `tappedAtMs` + clock offset/RTT + the stopwatch `stateHash`; resent unchanged until answered; offline
  backoff 1 s … 30 s and at once on `online`; 429/5xx back off; 401/stale CSRF stop sending and keep the item; every
  request names the tap's player (`expectedPlayerId`) - another player signed in on the same browser meanwhile answers
  409 `another_account`, kept like signed out, never stored for them (F1, D1);
  refusals drop it and show why; one tab sends - Web Locks; `beforeunload` warns while something is unsent).
- **States in the card's slot:** Stop / "Propose?" sheet / sending / saved on this phone (offline) / sign in again /
  reload / waiting for the judge (+ Undo) / taking back / confirmed / "Confirmed as X - you proposed Y" / not accepted
  (+ reason, Stop again while it runs) / withdrawn (Stop again) / clock reset / recorded otherwise / confirmation taken
  back by the judge (`result_cleared` without a result: "The judge took back the confirmed time X", Stop again while it
  runs). Undo = withdraw:
  a tap still on the phone is dropped (the server never hears of it), one on its way is withdrawn after its answer, a
  sent one gets a withdraw item. The answer's proposal and the state's are merged (`currentProposal()`: a decided
  answer beats a stale pending state, a later tap beats an older one).
- **Polling:** while something waits for the judge (pending / sending / offline / taking back) the state is asked for
  every 5 s ±20 % (visible tab only, doubled per failed fetch - `CompetitorPageLive` `fastPoll` + `replan()`); no
  private per-entry Mercure topic in v1 (R §1.2).
- **The slot is redrawn on every clock draw** when its descriptor changes, so a card drawn again from the page's HTML
  gets its slot back at once.
- **Current round first on phones:** on person and line-up pages the current round's card (`.is-current`) comes first
  below 768 px (CSS `order`); the HTML order is unchanged (a table page already lists it first).
- Tests: `tests/competitor-proposals-harness.mjs` → `CompetitorProposalsScriptTest`,
  `tests/Controller/CompetitorPage/CompetitorPageProposalsTest.php`.

## Proposals end to end (B8 → P1 → C9 → B8)

1. **Competitor** (B8 above): signed in as the person linked to the tag, on their own entry, taps Stop while the round
   runs → outbox → `POST /{_locale}/competitor-proposals/rounds/{roundId}`.
2. **Server** (P1, `official-results.md` "Time proposals"): `ProposeRoundTime` / `WithdrawTimeProposal` (under
   `CompetitionParticipantsLock`, `TimeProposalApi`, limiter `time_proposal` 10 per 10 min) write a pending
   `competition_time_proposal`. `TimeProposalOwnership`: only the linked solo entrant / member, **linked before the
   round's first stopwatch start** (`linked_after_start` otherwise); a known `proposalId` answers only its own entry and
   sender. The timing verdict ("Timing you can trust" below) is stored with it. A stopwatch reset supersedes pending
   proposals (`stopwatch_reset`).
   Pushed as `official_results.proposals`.
3. **Judge** (C9, `live-results.md` "Time proposals"): the entry card's panel - Confirm / Change / Not right; the value is
   **always the tap's time** - only a `suspicious` tap shows "Check this time" (+ "Use received"); "Proposals waiting (N)" rows only **open the card - nothing is confirmed
   from a list**. A confirmation is the `result` change with `proposalId` (`TimeProposalResolver` in
   `RecordRoundResults`); any other result on the entry confirms (same seconds) or supersedes it.
4. **Back to the competitor**: the 5 s poll returns the decided proposal - "Confirmed", "Confirmed as X - you proposed
   Y", "Not accepted" (+ reason), clock reset; the result itself follows the B4 rules.

Decided behaviours, edges:
- **Undo of a confirmation** on the live entry (toolkit H3): the result is cleared and the proposal turns `superseded`
  (`result_cleared`) in the same transaction - the judge page, the competitor page and `official_results.proposals`
  agree. The competitor page shows "The judge took back the confirmed time X" with the Stop button again while the round
  runs (the text alone after it), and a new proposal goes through (`ProposeRoundTime` refuses only a pending one or a
  standing result). **Decided:** a confirmed result the judge later changes to another value supersedes the proposal too
  (`result_changed`) - "Confirmed as X" would no longer be the official result; the page shows "The judge recorded your
  result." The page learns of it with its next ordinary poll (5 s polling is only while something waits for the judge).
- **Load (P1)**: `tools/time-proposals-load/run.php` - 40 simultaneous proposals p95 ≈ 0.8 s on Docker-on-Mac (≈ 19 ms
  each under the event lock; 100 ≈ 1.9 s vs the 500 ms goal of R §2.4). Accepted for v1 (finishes spread, the phone's
  outbox retries); to be measured on prod-like hardware; a per-round lock key needs Jan's OK.
- A pre-tap "as insurance" cannot be stopped by software - the judge looks at the puzzle (R §2.4).

## Timing you can trust (reliability hardening T2)

Jan: "+5 s can make a huge difference" - **the recorded time is the moment of the tap on the server-synced clock,
never the moment we received it**. An honest competitor whose tap reached us late (venue Wi-Fi) keeps it; the judge
sees no "late" badge (Jan: useless information for the judge). What the server knows for sure is only **when it
received something** (the proposal, a heartbeat); the tap moment, the clock offset, "I was offline" are the phone's
word. The honest page always tells the truth, so a warning is shown **only where the phone's own words contradict the
tap** - and even then the tap stays the default.

Threat model:

| Case | What happens | Verdict / judge's card |
|---|---|---|
| Honest phone, online | sent at the tap, received within 5 s | `on_time` - the tap time |
| Honest phone, offline at the tap | the tap waits in the outbox (IndexedDB); its heartbeats, if any get through, say "I hold an unsent tap from T" | `offline` - the tap time, nothing shown |
| The confirm sheet left open | "Propose 0:49:12?" read for 8 s - the heartbeats say "confirming a tap from T" | `offline` - the tap time, nothing shown |
| Page reloaded after the tap | the outbox survives the reload and resends with the same id and the original `tappedAtMs`; heartbeats start only after the outbox is read | as the first send would have been (a resend is answered `known`) |
| Several taps | a resend of the same id is answered `known`; a second tap while one is pending is `already_proposed` (one pending per entry) | - |
| Dishonest: taps late on purpose with a faked "offline" story (a request crafted with an earlier `tappedAtMs`) | the page's heartbeats after the claimed tap said "nothing unsent" - the phone was online and held no tap | `suspicious` - "Check this time" with both times and when the phone was online; the tap time stays the default, "Use received 0:49:52" is one tap away |
| Dishonest and thorough: crafted heartbeats too, or airplane mode around a pre-tap ("insurance") | indistinguishable from an honest offline phone | `offline` - **the judge's eyes at the table are the final check** (Confirm needs WHO first) |
| Clock offset jumps / the phone's clock runs ahead | the offset behind the tap differs from the last heartbeat's by > 2 s, or the phone claims to have sent the proposal > 2 s after we received it | `clockUncertain` - a note on the card, the default unchanged; only the impossible (`clock_ahead`, a tap > 2 s after the receipt) is refused |

**Why the tap stays the default even when `suspicious`:** the evidence proves only that the phone held no tap while
it was online - not when the competitor finished (an honest second device, a tab that crashed before storing the tap
could look the same). The judge stands at the table and looks at the puzzle; the warning and the one-tap "Use
received" give them what they need without software overruling Jan's rule.

Rules (server: `TimeProposalTiming::judge()`, pure; evidence read by the controller before the dispatch and carried in
the message):

- **Heartbeat** - only on the signed-in competitor's own entry with proposals on (`canPropose`): every 15 s ±20 % while
  the page is visible and the round runs (or the outbox still holds a tap of it), at once on `online` /
  `visibilitychange` (at most one per 2 s). `POST /-/competitor-heartbeat` (stateless firewall: no session, no user,
  no ORM, no Postgres - `CompetitorHeartbeatControllerTest` pins 0 statements) with the round's signed token from the
  state JSON (`heartbeat` on the round, HMAC of round + entry + player + expiry, `CompetitorHeartbeatToken`, 6 h; 403
  `expired_token` → the page fetches the state) + the phone's clock (`atMs` its server-synced now, `offsetMs`,
  `rttMs`, `sampleAgeMs`) + its outbox (`unsent`, `oldestUnsentTapMs` - read from IndexedDB for every beat, so another
  tab's tap counts, and a tap still in the confirm sheet counts). One Lua call to redis-state (`CompetitorHeartbeats`,
  hash per round, TTL 6 h; limiter `competitor_heartbeat` 30/min per player and round); answer `{serverNowMs}` = a
  clock sample for the page.
- A heartbeat is **clean** when it reports no unsent tap. Its evidence moment is the phone's `atMs`, clamped to
  [receipt − 30 s, receipt]. The store keeps the last beat and the last clean beat per entry and player.
- **Verdict** on receipt R of a tap T: R − T ≤ 5 s → `on_time`; else the last clean beat's moment > T + 2 s and
  R − its receipt > 5 s → `suspicious`; else `offline`. A heartbeat that says "I hold an unsent tap from T" never counts
  against T - the phone was trying. No heartbeat at all (an old page, a phone that never got through) = `offline`.
- Every send of the outbox says how the tap travelled (`proposalBody()`): `offsetSampleAgeMs`, `queuedOffline`,
  `attempts`, `sentAtMs` - stored with `timing_verdict`, `last_heard_at_ms`, `clock_uncertain` (diagnostics:
  `offset_sample_age_ms`, `queued_offline`, `send_attempts`). `late` / `lateByMs` stay in the data, no judge UI.
- Known limits: two devices of the same player on the same entry share one heartbeat record (a clean beat of the
  tablet can mark the phone's offline tap `suspicious` - a warning only, the tap stays the default); a hidden page
  sends no heartbeats (no evidence = nothing shown).

## Statements (competitor page)

`CompetitorPageQueryBudgetTest` pins: guest page/JSON **4** (the competition with its rounds, the tag's entries with
their people, the round puzzles, the published rounds' official entries - none when nothing shown is published, two
when published solo and pair/team rounds are both shown); signed in page 8 / JSON 6 (account, profile, the layout's
unread counts).

## Tests

`tests/Controller/CompetitorPage/CompetitorPageControllerTest.php` (each kind, published / not × live setting × own
view, pick rounds, hidden puzzles, 404s, drafts, blocklist, JSON), `CompetitorPageQueryBudgetTest`, the competitor
cases in `BlocklistCanaryTest`, `PrivateProfileCanaryTest`, `DraftCanaryTest`, `SecretPuzzleRoutesTest` (manual
reveal and a stopwatch reveal at scheduled − 1 s). `TrackerlessTimeCanaryTest` n/a: the pages read official results on
the entries, never `puzzle_solving_time`.
