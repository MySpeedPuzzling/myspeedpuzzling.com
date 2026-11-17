# Standings and bracket (competition toolkit A6-A9)

Jan's two ways of deciding a competition over several rounds, both opt-in per event
([toolkit/STATUS.md](toolkit/STATUS.md); design `toolkit/A-round-formats.md` §3; binding `toolkit/R-review-and-plan.md`
§2.2, §2.3, §2.9, §3):

- **Sum up** - a *standing* adds every entrant's results over chosen rounds of one category; the lowest sum wins
  (World Series: 6 solo + 6 pair + 6 team rounds = three standings, "Create one standing per category" in one click).
  Organiser preview live, a public page once published.
- **Spider** - *advancement steps* declare who moves from which rounds to which (groups → semifinals → final); the
  results desk turns a step into presets for the organiser's own qualification, and the event gets a *bracket* page.

Both are managed from the toolkit hub's "Standings & bracket" card (`competition_toolkit`), behind
`COMPETITION_TOOLKIT_MANAGE` (= `COMPETITION_EDIT`: every organiser of the event, admins). Public pages work for
everybody once published / declared. Public surfaces read official entries **only** through `OfficialEntrantsView`
(published rounds, ranked results, hidden players dropped without renumbering, private players masked - see
[round-formats.md](round-formats.md) "Public round page of `pick` rounds + the entrants view"); a "Did not start" is
never shown - it counts as missing in a standing and names nobody in a bracket box. An event without standings or steps renders its event, round and overview pages byte-identically and
pays no statement (`DetailPagesQueryBudgetTest`).

## Standings - sum of rounds (competition toolkit A6)

Design: `toolkit/A-round-formats.md` §3.1-3.4, §3.6; binding `toolkit/R-review-and-plan.md` §3 (no stages
classification, no standings Mercure topic, no public JSON in v1). Jan's World Series: 6 solo + 6 pair + 6 team rounds,
no qualification, the winner per category has the lowest sum.

- **Model:** `competition_standing` (`CompetitionStanding`, FK `competition_id` ON DELETE CASCADE): name (≤ 80), slug
  (unique per event, kept on rename), `category`, `mode` (`StandingMode::Sum` only), `round_ids` jsonb (ordered,
  validated on write by `CompetitionStandingRounds`: ≥ 1, ≤ 50, rounds of the event and the standing's category, a
  repeated id kept once), `unfinished_penalty_seconds_per_piece` 0..600, `order_rule` (`finished_rounds_first` default /
  `total_only`), `missing_rule` (`incomplete` only - `as_zero_pieces` is v2), `position`, `published_at` (the public
  page's switch, A7). At most 20 per event.
- **Writes:** `CreateCompetitionStanding` / `EditCompetitionStanding` / `DeleteCompetitionStanding`, all under
  `CompetitionParticipantsLock` (deviation from A §3.6 "no lock": they take turns with `DeleteCompetitionRound` /
  `MoveRoundToCompetition`, which prune standings, so a round going away is never saved into one). Refusals
  `InvalidCompetitionStanding::$reason` (`no_rounds`, `too_many_rounds`, `unknown_round`, `wrong_category`,
  `limit_reached`, `invalid_name`); a standing of another event = `CompetitionStandingNotFound`. Deleting or moving a
  round prunes it from the event's standings (+1 statement on a round delete); readers also skip ids that are no round
  of the event and category any more (a round whose category changed - the page says so).
- **The rule:** `StandingCalculator::sum()` (pure, stateless): finished = seconds (+ penalty × pieces missing from it
  when `penalise_finished_missing`); unfinished = limit × 60 + penalty × missing pieces (unknown pieces = no penalty part; a round without a limit cannot score it - missing); DNS / no result
  / not entered = missing. Complete entrants first, ranked (`finished_rounds_first`: rounds finished desc, then total;
  `total_only`: total; equal keys share a rank, "1, 2, 2, 4"); incomplete ones after without a rank ("5 of 6 rounds"),
  by rounds scored, then the rule. An entrant with no score at all is left out. Entrants across rounds: the participant
  (solo) or the exact line-up (`CompetitionLineupId` - a substitute is another entrant); name and people of the last
  round they are in. A round that does not count scores nobody and makes nobody missing.
- **Missing pieces and the penalty** (competition toolkit T6, Jan 2026-11-17: "an unfinished round in a sum shows BOTH the
  total time and the total missing pieces; an organiser-configured penalty per missing piece (may be 0) converts missing
  pieces to time"): every round score keeps its parts - `timeSeconds` (finished seconds / the limit of an unfinished
  result), `missingPieces` (not placed at the time's up, or missing from a finished puzzle - official-results.md
  "Finished with pieces missing") and `penaltySeconds`; `scoreSeconds` = time + penalty. A row sums them
  (`totalTimeSeconds`, `totalMissingPieces`, `totalPenaltySeconds`, `totalSeconds` = the penalised total, the ranking
  key). Pieces missing from a **finished** puzzle cost the penalty only with the standing's switch
  `penalise_finished_missing` on (**default off** - shown, never timed); a finished round with pieces missing is still a
  finished round for `finished_rounds_first`. Penalty 0 = the missing pieces are shown, the order is by time alone (no
  hidden tie-break). Pages: a round cell shows the time with "· N pieces missing"; the total cell the penalised total and,
  when pieces are missing, "time + penalty · N pieces missing" under it (`standing/_total.html.twig`, shared by the
  organiser preview and the public page); the rule line (`standing/_rules.html.twig`) "+30 s per missing piece; pieces
  missing from a finished puzzle are (not) penalised". Organiser JSON: the new keys on `perRound[]`, the rows and
  `rules.penaliseFinishedMissing` (additive). The form has the switch; "Create one standing per category" uses the
  default (off); duplicate copies it. A standing where nothing is missing renders as before.
- **Organiser view** (`GetStandingResults::organiserView()`): every round of the standing, published or not, through
  `GetRoundResultEntries` (people going to the event, the official in-round rank shown per cell); a round counts once
  anything is recorded in it; "provisional" while a round does not count or is unpublished. Piece totals: a `pick`
  round's entry piece count, else the sum of the round's puzzles.
- **Pages:** hub card "Standings & bracket" (list + link); `competition_standings_manage`
  (`/en/manage-event-standings/{competitionId}`): each standing with its live preview, "Create one standing per
  category" (all rounds of each category without one, default rules - the World Series in one click), New / Edit
  (`add_competition_standing`, `edit_competition_standing` - form + preview) / Delete (CSRF). All behind
  `COMPETITION_TOOLKIT_MANAGE`. Organiser JSON `official_results_standing_state`
  (`GET /{_locale}/official-results/standings/{standingId}`, `COMPETITION_RESULTS_ENTRY`, `no-store`; referees get no
  `playerCode` of private players) - shape `StandingTable::toArray()` (A §6.4 with `includesUnpublished`, `perRound[].status`).
- The bracket: "Bracket (competition toolkit A9)" below.
- Tests: `tests/Services/CompetitionToolkit/StandingCalculatorTest.php`, `tests/CompetitionToolkit/CompetitionStandingsTest.php`
  (World-Series fixture `WorldSeriesSetup`: handlers, prune, duplicate, cascade), `CompetitionStandingsPagesTest.php`.

## Public standings (competition toolkit A7)

Design: `toolkit/A-round-formats.md` §3.4, §3.6, §8; R §2.2, §2.3, §2.9 (no public JSON, no Mercure topic in v1).

- **Publishing:** `PublishCompetitionStanding` / `UnpublishCompetitionStanding` (under `CompetitionParticipantsLock` like
  every standing write; publishing again keeps the first moment), `publish_competition_standing` (POST, field
  `publish` 1/0, CSRF `_publish_token` = `publish-standing-{id}`, `COMPETITION_TOOLKIT_MANAGE`) from buttons on the
  standings page, which also shows Published / Not published and a "Public page" link.
- **Pages:** `event_standing` `/{_locale}/events/{slug}/standings/{standingSlug}` (cs `/eventy/{slug}/poradi/…`; an
  edition's event URL redirects 301) and `edition_standing` `/{_locale}/series/{s}/{e}/standings/{standingSlug}`, both
  through `StandingPageBuilder`: 404 for an event that is not public (drafts, unapproved - its team too, like the round
  results pages) and for an unpublished standing. Tabs to the event's other published standings (a 6+6+6 World Series
  has three). `noindex` while no round counts.
- **Computation:** `GetStandingResults::publicView()` = `StandingCalculator::sum()` over the standing's rounds whose
  official results are published and have something ranked, entries ONLY from `OfficialEntrantsView::forRounds(...,
  keepHidden: true)` (ranked results, private players masked, removed people left out); the rows hidden from the viewer
  (blocklist group rule) are dropped **after** the standing's ranking, without renumbering. Unpublished rounds show "–"
  and make nobody missing; "Provisional · after 3 of 6 rounds" until all count, then "Final". With every round
  published its keys, ranks and totals equal the organiser preview (pinned by `PublicStandingsTest`). For this,
  `PublishedRoundEntry` carries `participantIds` (lower case, never rendered) and `hiddenFromViewer` - the round page's
  output is unchanged (goldens). Entrant keys: `p:{participantId}` / `l:{CompetitionLineupId}` / `t:{teamId}`, like the
  organiser view; never in the HTML. Statements: event lookup, visibility, the event, the standing, its rounds, ≤ 2 for
  the entries.
- **Links:** the published standings ride on `GetCompetitionEvents::byId()` as JSON (`CompetitionEvent::$publishedStandings`,
  `PublishedStandingRef`) - no statement of their own (`DetailPagesQueryBudgetTest::testPublishedStandingsAddNoStatement…`).
  Event/edition page: a "Standings:" row under the rounds heading (`event_parts/_standings_links.html.twig`, appended to
  the heading's line, so untouched events render byte-identically; public pages only); round page: "Part of … →" for
  each published standing that includes the round (`round_results/_standings_links.html.twig`); results overview: a
  Standings button once one is published (toolkit voter); sitemap: published standings of public events with at least
  one published round (`GetCompetitionSlugsForSitemap::standingSlugs()`).
- **Layout:** mobile first - rank, name (the round page's `_leaderboard_player`), total; the round columns from `lg`
  up, below that a compact "R1 … R2 …" line per row and a legend naming the rounds.
- Known edges: the public view counts people with `deleted_at IS NULL` (the organiser view: people going to the event),
  so a waitlisted person with a published result is in the public sum only; the penalty per missing piece of an
  unfinished pick-round entry uses its chosen puzzles' pieces (a number inside the total, never a puzzle name).
- Tests: `tests/CompetitionToolkit/PublicStandingsTest.php` (publish/unpublish, organiser buttons, provisional, ranks =
  preview, drafts/unapproved 404, links, sitemap, edition URL), canaries `BlocklistCanaryTest`,
  `PrivateProfileCanaryTest`, `DraftCanaryTest` (`…InAPublicStanding`, `…StandingOfADraft…`), `DetailPagesQueryBudgetTest`.

## Advancement steps (competition toolkit A8)

As built in [results-desk.md](results-desk.md) "Declared advancement steps". In short: `competition_advancement_step`
(`CompetitionAdvancementStep`) declares once what the "Advance the qualified" dialog takes - source rounds, target
rounds, distribution (`balanced` / `single` / `by_source`), the by-source map, the country rule - plus how many qualify
(top N of each source round, top N of all source rounds together, or empty = by hand). `AdvancementStepGraph` checks
every write (one category, sources and targets disjoint, one feeding step per round, no cycles) and gives the stages
(longest path: groups 1 → semifinals 2 → final 3). Pages `competition_advancement_steps_manage`
(`/en/manage-event-advancement/{competitionId}`), `add_/edit_/delete_competition_advancement_step`, behind
`COMPETITION_TOOLKIT_MANAGE`. **A step is a preset, never a write path** (Jan, PR #136): the desk of a source round shows
a banner with "Mark top N" (the Top N dialog preset, ties as checkboxes; overall: `official_results_advancement_step_preset`,
`AdvancementSeeding::topOverall()`) and "Advance" (the dialog prefilled, `prefillFromStep()`); the organiser still applies.
Rounds deleted or moved are pruned; `DuplicateCompetition` copies the steps (`copyAdvancementSteps()`); a round feeding no
step renders the desk byte-identically. Tests: `AdvancementStepGraphTest`, `AdvancementStepsTest`,
`AdvancementStepsPagesTest`, `AdvancementSeedingTest::testTopOverall*`.

## Bracket (competition toolkit A9)

Design: `toolkit/A-round-formats.md` §3.5 (bracket part); R §2.2, §2.3, §3 (no final classification across stages in v1).
Built from the declared advancement steps (A8, "Advancement steps" above) - nothing to switch on:
an event with steps has a bracket.

- **Layout:** `BracketLayout::of(steps, rounds)` (pure, `src/Services/CompetitionToolkit/`) → one `Bracket` per category
  (by its earliest round): `stages` = columns by `AdvancementStepGraph::stages()` (groups 1 → semifinals 2 → final 3), the
  rounds of a stage in schedule order, `gaps` = the lines between neighbouring stages (`BracketConnector`, y in a 0-1000
  viewBox = (index + ½) / rounds; `balanced`/`single` = every source to every target, `by_source` = its own target), `feeds`
  = the step feeding each round ("Top 8 of each round: Group A, Group B"). A step a later round change broke is left
  out (the steps page marks it); steps clashing with each other give no bracket. A round feeding past a stage gets no line
  (its target's "from" line names it).
- **Rendering** (`templates/bracket/_bracket.html.twig`, `_round.html.twig`, `assets/styles/_bracket.scss`): CSS grid,
  stage columns with equal slots (rows `1fr`, columns stretched), an inline SVG per gap (`preserveAspectRatio="none"`,
  non-scaling strokes) - no JS, no library. Below md the stages stack with an arrow between them (375 px: no horizontal
  scroll). Box: name, start (round's zone), status (`BracketRoundStatus`: upcoming / live now / results coming / results
  via `RoundStopwatchStatus::at()`), the feed line.
- **Public page:** `event_bracket` `/{_locale}/events/{slug}/bracket` (cs `/eventy/{slug}/pavouk`; an edition's event URL
  redirects 301) and `edition_bracket` `/{_locale}/series/{s}/{e}/bracket`, through `BracketPageBuilder`: 404 for an event
  that is not public (drafts, unapproved - its team too) and for an event without steps; every step broken = an empty
  page. A box names entrants **only once its round's results are published**: its qualified (✓) - or the top 3 of a round
  that qualified nobody (a final) - at most 32, from `OfficialEntrantsView::forRounds()` only (hidden players dropped
  without renumbering, private players by the organiser's name), and links the round page. `noindex` until a round is
  published. Statements: visibility, the event, the steps, the rounds (`GetBracketRounds`, no puzzles), ≤ 2 for entrants.
- **Organiser view:** the steps page (`competition_advancement_steps_manage`) shows the bracket instead of A8's stage strip,
  each box with "Results x / y · qualified n" (`GetRoundResultsOverview`, published or not), Results desk / public
  results links, and a "Public page" link (not for a draft). Only with steps: an event without them asks nothing more.
- **Links:** `GetCompetitionEvents::byId()` brings the rounds of the steps along (`CompetitionEvent::$bracketRoundIds`,
  `hasBracket()`, `bracketIncludesRound()`) - no statement of its own (`DetailPagesQueryBudgetTest::testDeclaredAdvancement…`).
  Event/edition page: a "Bracket" button in the standings row under the rounds heading (public pages only; untouched events
  byte-identical); round page: "Bracket of the event →" on rounds that take part in a step; sitemap: public events with steps
  and at least one of their rounds published (`GetCompetitionSlugsForSitemap::bracketSlugs()`).
- Tests: `tests/Services/CompetitionToolkit/BracketLayoutTest.php`, `tests/CompetitionToolkit/PublicBracketTest.php`
  (published-only marks, top 3, 404s, links, organiser view, sitemap, edition URL, statement budget), canaries
  `BlocklistCanaryTest`, `PrivateProfileCanaryTest`, `DraftCanaryTest` (`…Bracket…`), `DetailPagesQueryBudgetTest`.

## Deferred to v2 (R §3, docs/TODO.md "Competition toolkit v2")

The `stages` standing classification and `numbered_across_stages` (a final classification across the bracket's stages),
`missing_rule = as_zero_pieces`, a standings Mercure topic (pages are static, refreshed on load), a public standing JSON
(`public_standing_state`), "drop the worst round" and season standings, a standings/bracket overlay element for the
displays.
