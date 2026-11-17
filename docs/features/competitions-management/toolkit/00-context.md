# Competition toolkit - shared context for every agent (orchestrator: main session, 2026-11-17)

Repo: /Users/janmikes/www/speedpuzzling.cz (Symfony 8, PHP 8.5, FrankenPHP worker mode, Postgres, Doctrine, Mercure,
Stimulus/Turbo/Live Components, Bootstrap 5). Read `CLAUDE.md` and `.claude/rules/features/events.md` +
`.claude/rules/queries.md` + `.claude/rules/frontend.md` + `.claude/rules/service-worker.md` (when relevant) first.
Existing competition docs: `docs/features/competitions-management/` (README, official-results.md, live-results.md,
results-desk.md, seating.md, round-results.md, registration.md, public-page.md) and
`docs/features/online-competitions/` (the round clock / stopwatch with `scheduled` status, Mercure after commit).

## Jan's request (verbatim, the owner of the product)

> We will be improving our competition management based on the feedback and usecases that must be covered.
> Note that these changes all must be configurable and we are not making these new defaults for every one.
> [Wizard for creating competitions = step 2, needs UX research - NOT part of this build, only a research doc later.]
>
> - competition timer designs - this page must be completely customizable maybe with some editor, putting background
>   and/or images - themed by themselves adding logos for example around the timer while still running, allow
>   multiple styling
> - stream overlays designs - several variants could be created with its own URL, that could be put for example to the
>   software like OBS - think about what could be added there, again maybe with some kind of editor with elements
> - QR codes - either for the competitor for whole tournament or per competitor per round (usually for a table for
>   this variant). the variant one would be the competitor QR code for whole tournament and basically his profile with
>   results for the whole tournament with details about the competitor and the puzzle he did. there are 3 scenarios
>   -> 1) player/pair/team will have dedicated QR code for the whole competition, 2) table will have dedicated QR code
>   for whole competition, no matter who sits there it updates in time based on the current round, 3) unique
>   combination player + table -> it is specific for that round and will always show the same player/pair/team not a
>   dynamic based on round and time
> - competitor "page" -> the page the QR code will result to. it is public profile page, with results of that player
>   or for that specific table per round (based on the QR code mode configured in the competition) -> basically name
>   tags with a QR codes is new module. note the page can be solo/pair/team. This page should listen to the mercure
>   events of the competition and if round where this participant (competitor) is competing and is part of it, starts
>   a timer and there will be the timer following exactly the competition timer and the participant would have a stop
>   timer button there that would "propose a time" -> proposing a time saves his time but that must be confirmed by
>   the judge or admin - until then it is just a proposal stored and not confirmed (all of this just from the browser
>   and if the competitor is signed in as the user paired to the qr code -> basically if i would be looking of my own
>   public competition page, so i can not bypass and propose time for someone else by scanning his time).
> - there will be judge page -> competition admin can pick any number of judges (they must have MSP account as well) -
>   the judges must have extremely reliable page working even offline storing in sessions/cookies or local storage or
>   something - basically the judge is going around and either typing table number or scanning the QR code -> this
>   whole page must be optimized for performance and extremely fast process of typing the time - multiple options and
>   scenarios for judges: 1) WHO? scanning QR code OR typing the table number (note that in some rounds there can be
>   multiple puzzle and the participant picks which puzzle they will solve so even choosing a puzzle is part of this)
>   2) TIME -> either pressing stop or typing manually. pressing stop will take current timestamp and time it from the
>   global running (when it started) competition timer stopwatch OR when the participant used his own "propose a time"
>   they can confirm via single button after choosing the right table by WHO, this whole process of WHO and TIME must
>   be extremely fast and with a confirm button (they are able to edit it as well), because it must be optimized for
>   doing this operation quickly. the judges usually go around tables and just write the results and move on quickly
>   so this must be very fast and reliable operation, even working offline and submitting the results once coming
>   online and must survive refresh of page etc -> writing down results "on field" by judges is critical part of the
>   competition and the UI must be compact and clear, no footer etc.
>
> there might be rounds where participant (solo, duo, team, apply to all) can pick one puzzle from multiple and the
> other scenario is that they will be solving multiple puzzles in one round - example they get 3 puzzle boxes and
> decide which 2 of 3 will be solving and order is completely up to them, all of this must be supported
>
> there will be some events with qualifications and we can set up like round A->B->C and the qualified will move
> forward so basically set up a spider-like? and there will be as well competitions with multiple rounds with no
> qualification and in the end we sum up times from all the rounds (example World Series is 6 rounds solo, 6 rounds
> pairs, 6 rounds teams) -> the winner is per category solo/pair/teams summing up together all results from all 6
> rounds and the fastest is winner - so we have this 2 different concepts -> spider vs sum up. and again both of this
> must be configurable and supported because the competitions can vary very much
>
> we must support reveal puzzle at the time when stopwatch timer starts -> until that the puzzle stays hidden on the
> app (we have now i think exact time or round time + some gap, so we need to add this new option "when timer is
> started" -> when starting a timer there should be checkbox to reveal the puzzle because they might want to try in
> advance to test everything out before competition without revealing the puzzle - some backoffice or admin usually
> does this).
>
> carefully read all of this, brainstorm about the impact - these are scenarios that must be supported and carefully
> tested, fully configurable not forcing that this is the way to do it but the organizer always chooses what and how
> his competition works.

## What already exists (verify in code - this is the orchestrator's first read, not gospel)

- Referees = Jan's "judges": `CompetitionReferee`, `CompetitionResultsEntryVoter`, the referees page; the live entry
  page `live_results` (docs/features/competitions-management/live-results.md) with Find (table/name/#code), Scan
  (name-tag QR `/{_locale}/live/{competitionId}/p/{participantId}` - path is printed, never change), Finished now
  (server-synced now − stopwatch `startedAt`), type time, Didn't finish (pieces), DNS, Confirm → Save, Undo, Quick
  add, an IndexedDB outbox (`assets/official_results_outbox.js`) with retries/conflicts, sync pill. **Gap:** the page
  itself needs the network to load ("No service worker changes") - a refresh while offline loses the page.
- Results live on the round entries: `CompetitionParticipantRound` (solo) / `CompetitionTeam` (pair/team), trait
  `HasOfficialResult` (resultSeconds, resultPiecesPlaced, resultDidNotStart, resultEnteredAt/By, qualifiedAt,
  tableNumber). One write path `RecordRoundResults` (three-way field changes with `from`, receipts) under
  `CompetitionParticipantsLock` (`SerializedByLockMessagesTest`).
- Round puzzles: `CompetitionRoundPuzzle` (several per round possible; secret puzzle hide/reveal model with
  `RoundPuzzleReveal` automatic (start + delay) / scheduled / manual, `SecretPuzzleHides::resync`, confirmation hashes).
- Round stopwatch on `CompetitionRound` (`stopwatchStartedAt/Status/StoppedAt`, scheduled starts), public Mercure topic
  `/round-stopwatch/{roundId}`, public view `round_stopwatch.html.twig`, manage view `manage_round_stopwatch.html.twig`.
- Qualification: results desk, manual qualification, `AdvanceQualified` (groups → semifinals → final, plan hash).
- Seating: table numbers per round per entry, table layout (`TableRow`/`RoundTable`/`TableSpot`), auto-assign.
- Name tags: `competition_name_tags` A4 print page with QR (`NameTagQrCode`, cached SVG).
- Public round results page with official ranks once published (`resultsPublishedAt`).

## Cross-cutting rules for this whole build (orchestrator's decisions)

1. **Opt-in, never a new default.** Every new behaviour is chosen by the organiser per competition (or per round). An
   untouched competition renders and behaves byte-identically (existing byte-identity / statement-count tests must stay
   green unchanged unless a test pins something we intentionally only *add* behind a setting).
2. **Feature flag for the rollout**: one env flag `COMPETITION_TOOLKIT_FOR_ORGANISERS` (default 0 = only admins see
   the new organiser settings/editors; documented in `docs/features/feature_flags.md`). Public surfaces of a
   competition that an admin switched on work for everybody (so Jan can run a real event before the flag is on).
3. Architecture: CQRS (messages + handlers, controllers only dispatch), repositories never flush, raw SQL queries in
   `src/Query/`, single-action controllers, `Uuid::uuid7()`, FrankenPHP `ResetInterface`, Mercure published from the
   controller after `dispatch()` (post-flush is still inside the transaction), every participant/entry/team write under
   `CompetitionParticipantsLock`, the read-side guarantees (blocked players, private profiles masked, drafts hidden,
   canary tests), Turbo form gotchas, translations in all 6 locales at the end (agents add English only), migrations
   generated by `doctrine:migrations:diff`, never run on the dev DB by agents.
4. Secret puzzles: anything new that shows a puzzle (displays, overlays, competitor page, judge puzzle choice) goes
   through `SecretPuzzleAccess` / reveal state - **an early reveal would be a disaster** (Jan).
5. Unpublished official results are not public. A surface that shows live results before publishing does so only
   because the organiser explicitly chose it (a setting on that surface), and never to the public by default.
6. Mobile-first, fast, compact for the judge; big-screen/OBS-friendly (transparent background, 1920×1080, no site
   chrome) for displays and overlays.
