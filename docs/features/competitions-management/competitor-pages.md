# Public competitor pages (competition toolkit B4)

Where a scanned competition tag leads anybody but the event's organisers and referees, once the organiser switched
"Public competitor pages" on (toolkit hub card, B1). Design: `toolkit/B-qr-competitor-page.md` §4, §7; binding
`toolkit/R-review-and-plan.md` §2.3, §2.6. The live clock (B5) and "propose my time" (B8) build on this read side.

## Routes

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

## What it shows (`GetCompetitorPage` → `CompetitorPageView`)

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
    here); "Did not start" from the entry itself (official, no place); a pick entry's chips
    (`PublishedEntryPuzzle::listOf()`);
  - not published + "Show confirmed results before publishing" (`competitor_pages_live_results`): the confirmed result,
    "Confirmed by the judge - not official yet", **never a place or qualified mark** (`live`);
  - not published, setting off: nothing - except **for the competitor themself** (the linked person or a linked member
    of the pair/team, signed in): "Your time, confirmed by the judge - not published yet" (`own`), no place;
  - a masked entry (table page / a person's pair round with a player hidden from the viewer): "—", nothing of it.

## `competitor_state` JSON

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
  fetch; the shortest round trip wins.
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

## Statements

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
