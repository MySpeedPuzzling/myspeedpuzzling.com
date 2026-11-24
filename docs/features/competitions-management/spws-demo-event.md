# SPWS 1:1 demo event on the sandbox (package C, 2026-11-24)

A made-up event on https://sandbox.myspeedpuzzling.com that runs the **confirmed** Speed Puzzle World Series format
(Toronto, 2-5 June 2027 - brief `cm-delivery/brief-spws-common.md`) 1:1, so the SPWS organisers can sign in on the demo call (~2 Dec 2026) and play every role: organiser, judge, competitor, commentator,
hall screen, stream. Built by the existing sandbox command `myspeedpuzzling:sandbox:demo-events`
(docs/features/sandbox/README.md "Demo events"), never by hand, so it can be rebuilt after a rehearsal.

The guide for the call: [toolkit/spws-demo-guide.md](toolkit/spws-demo-guide.md).

## 1. Options considered

| Option | Verdict |
|---|---|
| A. Change the existing `speed-puzzle-world-series-demo` to the confirmed format | No - it is the demo Jan already showed/linked; its rounds have recorded results (a re-run never rewrites). Brief: keep it. |
| B. Build the event by hand on the sandbox (browser + internal API) | No - not reproducible; a rehearsal leaves results behind and nobody could rebuild it the same way. |
| **C. A third definition in `SandboxDemoEventDefinitions` + small opt-in extensions of the command** | **Chosen.** Same path as the two existing demos: every write is the organiser's/judge's own message, idempotent by slug and names, tests on the fixtures. |

## 2. The event (definition `spwsToronto()`, slug `spws-toronto-2027-demo`)

Name "Speed Puzzle World Series Toronto 2027 - demo", Toronto (CA), `America/Toronto`, organization
"Speed Puzzle World Series (demo)" (slug `speed-puzzle-world-series-demo-organization`).

### Day mapping (`--date` = the demo day = the Teams day, "Thursday")

| Toronto day | Real date | Demo | Rounds |
|---|---|---|---|
| Wed - Pairs | 2 June | demo day − 1 | all 3 recorded + **published** |
| Thu - Teams | 3 June | **demo day** | Challenges 1 + 2 **running** (partial results), Challenges 3 + 4 **planned** with secret puzzles revealed at the stopwatch start |
| Fri - Individual | 4 June | demo day + 1 | C1, C2, C3 planned |
| Sat - Individual | 5 June | demo day + 2 | C4, C5 planned |

So on the call the organisers see published past rounds with standings (Pairs), a running block with live results
(Teams 1 + 2), and planned rounds to start themselves (Teams 3 + 4 with the reveal; any individual challenge).
The command is run with `--date=2026-12-02` (the planned call day). Another day: rebuild (README "Demo events").

### Rounds (times local, limits per block)

| Key | Round | Day | Start | Limit | Puzzles (stand-ins) | Notes |
|---|---|---|---|---|---|---|
| p12 | Pairs - Challenges 1 + 2 | −1 | 11:00 | 90 | C1 500 Speedmaster, C2 500 Boardwalk | Solve all 2 (order recorded) |
| p34 | Pairs - Challenges 3 + 4 | −1 | 13:30 | 90 | C3 500 Boardwalk ("random cut"), C4 250 Wentworth wooden | Solve all 2 |
| p5 | Pairs - Challenge 5 | −1 | 16:00 | 60 | C5 500 Buffalo Games | **unboxed** |
| t12 | Teams - Challenges 1 + 2 | 0 | 10:00 | 120 | C1 1000 Boardwalk, C2 1000 Speedmaster | Solve all 2, running |
| t34 | Teams - Challenges 3 + 4 | 0 | 15:00 | 150 | C3 1000 Boardwalk random cut, C4 1000 Buffalo Games - **new secret puzzles** | Solve all 2, **C4 only unboxed** (per puzzle, B1), reveal at the stopwatch start |
| i1-i3 | Individual - Challenge 1/2/3 | +1 | 11:00 / 13:30 / 15:45 | 75 | 500 Ravensburger | |
| i4 | Individual - Challenge 4 | +2 | 11:00 | 75 | 500 Boardwalk | |
| i5 | Individual - Challenge 5 | +2 | 13:30 | 75 | 500 Speedmaster | **unboxed** |

Puzzle labels "Challenge 1" … "Challenge 5" (per championship). Catalogue stand-ins are picked by **brand name +
pieces** (`SandboxDemoRound::$brands`, one per puzzle position; no match → any puzzle of that piece count, then any).
The two secret puzzles are created through the organiser's own `AddPuzzleToCompetitionRound` (new puzzle by brand
name, hidden entirely, `shownAutomaticRevealAt` = the round's automatic reveal as read) and switched to
`ChangeRoundPuzzleReveal(..., Stopwatch)`; names "Random Cut Challenge (demo stand-in)" / "Unboxed Challenge (demo
stand-in)". Only while the round has no puzzle yet (idempotent).

### People

~30 pairs, 16 teams of 4, 40 individuals: pairs (60 new people), teams (64 new people), individuals = the first 40
team people again (one participant in two championships, as in reality). Made-up players `test-user+N` first (linked:
competitor pages work), then made-up guests. Pair names = first names, team names = fun names.

### Tables (package A)

Each championship's first round gets the SPWS hall names before any table number is assigned:
`SetRoundTableLabels($competitionId, <every round of the championship>, TableLabelGenerator::generate('A-H', '1-22',
$seats, 'side', 'letters', 'H22'), $expected)` - 2 seats per table ("A1 L", "A1 R"; 350 places) for Pairs and
Individual, 1 for Teams (175). `$expected` = each round's current list (null = plain numbers), so a re-run sends the
same list or nothing. Then tables 1.. by name on the first round and "Use these tables in later rounds" as today -
every round of a championship has the same list, so the copy keeps the names. The two-places scheme is A's
recommendation until SPWS answers; the guide says how to change it in the seating "Table names" panel.

### Results (deterministic, `SandboxDemoResultPlanner`)

Unchanged rules (published: everyone has a result, last by name = no-show, second-to-last = time's up with pieces
remaining, 3rd and 6th = finished with 1 and 2 missing pieces; running: first third finished, next third first split
only) plus an opt-in **tie** (`SandboxDemoRound::$tie`): in a published round the 5th by name gets exactly the 4th's
result (same splits) → a shared place. Blocks record both splits (challenge 2 = split2 − split1).

### Standings

One sum standing per championship (existing step): 5 s/missing piece, total only, `as_zero_pieces`,
penalise finished-with-missing, published (provisional until every round counts). Per-challenge leaderboards =
the round pages' `?challenge=` (W4) + the challenge board display.

### Organisers, judge, competitor pages

Creator: `test-user+1` (made-up organiser). Extra maintainers from `--maintainer=<email>` (repeatable): the real
organiser e-mails are **never in the repo** - passed on the box only. They become event maintainers (at creation via
`AddCompetition::$maintainerIds`; later runs: nothing - the organization team covers them) and organization team
members (`AddOrganizationMaintainer`, when not yet). Referee `test-user+2` (existing option). Competitor pages + QR
per competitor + live results before publishing + time proposals on (existing step).

### Branding (opt-in `--logo=<png>`, never committed)

The logo file is given on the box: used as the **event logo** (AddCompetition, at creation) and the **organization
logo** (AddOrganization, at creation). Displays show it through the image element's `competition_logo` source - no
display upload needed. Without `--logo` everything else is built, the logo can be set on the edit page later.

### Displays (`SandboxDemoEvent::$brandedDisplays`, SPWS look: navy `#0a1a6b`/`#050d3d`, gold `#d4af37`/`#f3e3a0`)

| Name | Kind | Follows | Content |
|---|---|---|---|
| SPWS hall screen | screen | current round | logo, event name (gold), round badge, huge timer (Bebas), status, gold progress, finished count, live top 10 panel |
| SPWS stream overlay | overlay (transparent) | current round | top-right timer box (navy, gold border) + round name, right column live top 8, bottom-left latest finishers ticker, small logo |
| SPWS championship standings | screen | current round | logo + round name + the standing of the shown round's championship (`standingId: auto`, 15 rows, flag + total) |
| SPWS challenge board | screen | pinned Teams - Challenges 1 + 2 | a live leaderboard per challenge (`challenge: 1/2`) |
| SPWS lower third | overlay | current round | the latest finisher spotlight + the live-control message, for the commentators |

The generic "Hall timer screen"/"Stream overlay"/"Challenges board" are not added to this event. All are ordinary
designs the organisers can change in the editor.

## 3. Code changes (all opt-in; the two existing definitions build exactly as before)

- `SandboxDemoRound`: `brands` (list, per puzzle position), `piecesOfPuzzles` (per position), `secretPuzzles` (list of `{brand, name}`; reveal at the
  stopwatch start), `unboxedLabels` (per-puzzle unboxed via `ChangeRoundUnboxed(..., false, [ids])`), `seatsPerTable`
  (table names on a first round), `tie`.
- `SandboxDemoEvent`: `organization` (name + slug), `brandedDisplays`.
- `SandboxDemoEvents`: steps above; `setUp()` takes options (event slug filter, maintainer ids, logo path).
- `SandboxDemoEventsConsoleCommand`: `--event=<slug>`, `--maintainer=<email>` (repeatable, unknown e-mail = error
  before writing), `--logo=<path>` (must be a readable image).
- `GetSandboxDemoData`: `cataloguePuzzles(..., brand)`, `organizationBySlug()`, `organizationMaintainers()`,
  `roundTableLabels()`, `userIdOfPlayer()`, round `starts_at` + `reveal_delay_minutes` for the reveal contract.

## 4. Edge cases

- Re-run: rounds/puzzles/labels/table names/tables/results/standings/displays/organization found again - nothing
  duplicated, no recorded result overwritten; per-puzzle unboxed only sent when it differs.
- A rehearsal that started Teams 3 + 4 with the reveal: the puzzles are revealed for good; rebuilding means deleting
  the event (admin) and running the command again (README).
- `SetRoundTableLabels` refuses when somebody sits at a number the list doesn't name - can't happen (max 40 seats used
  of 350) - and when a round's list changed meanwhile (then the command says so and goes on).
- Sandbox only (`SandboxMode::assertOn()`), unchanged.

## 5. Test plan

`SandboxDemoEventsTest`: a new test building only the new event (`--event` filter) on fixtures: 10 rounds with the
confirmed limits/times, labels, unboxed (round + per puzzle), secret puzzles in Stopwatch mode, table labels on every
round of each championship and the same names after the copy, a tie in a published round, three standings with the
SPWS rules, the five branded displays, extra maintainers, the organization; a re-run adds nothing. Existing test
unchanged (old definitions untouched). Then the real run on the sandbox + a browser walk of every role (guide).

## Review amendments (independent review 2026-11-24 - binding)

| # | Finding | Applied |
|---|---|---|
| H1 | A secret puzzle added after its round's start would be public at once; add + reveal are two transactions | Secret puzzles are added only while the round's start is ahead (else a `TODO:` line); the switch to the stopwatch reveal is a repair pass on every run (any secret row not yet `stopwatch`), a refused switch (stopwatch already started, revealed) is a `NOTE:` |
| H2 | A brand typed by name that matches nothing creates a new brand | Kept by exact name: `Boardwalk`, `Buffalo Games` exist on the sandbox (checked 2026-11-24); in the tests' catalogue a new brand is harmless |
| H3 | The organization must own the event (rights) and be approved without an admin e-mail | `AddOrganization(approve: true, storedLogo: the event's logo)` + `AssignEventToOrganization` whenever the event is not in it |
| H4 | Re-sending the names with `$expected` = current would overwrite a scheme the organisers chose | Names are sent only while every round of the championship still has plain numbers |
| M1 | Results elements default to `published` | Every results element of the SPWS displays is `source: live`, saved with `liveConfirmed: true` |
| M2 | `viewDisplays()` would add the generic "Challenges board" | Skipped for `brandedDisplays` |
| M3 | Round times are Toronto local - a call at 03:00 Toronto shows Teams 1 + 2 "upcoming" with results | The guide states it and has the organiser start the stopwatch themselves; `--date` picks the Toronto day |
| M4 | A default run (no `--event`) would create the SPWS event without logo/maintainers | `all()` = the two old events (unchanged); the SPWS event only with `--event=spws-toronto-2027-demo` (`known()`); `--maintainer`/`--logo` require `--event` |
| L1 | Real names/e-mails/logo must stay out of the public repo; the optimizer rewrites the logo in place | No organiser name or e-mail in code or docs (passed on the box); the logo is copied to a temp file before the handlers see it |
| L2 | Pairs C4 has 250 pieces | `SandboxDemoRound::$piecesOfPuzzles` (per position) |
| L3 | Plates URL; two judges | The command prints the plates (tents with time boxes) URL per championship; second judge on the call = an organiser's phone (organisers can enter results) |
| L4 | `ChangeCompetitorPageSettings` on every run resets the organisers' switches | Sent only when the SPWS event is created (old demos unchanged) |

Found while testing: the puzzle adds persist rows without touching the round's loaded collection, so in one long
command `ArrangeRoundPuzzles` saw none (old demo: "Pairs - Challenge 5" got its label only on a second run). The
builder now clears the EntityManager after adding a round's puzzles.

## As built (PR, 2026-11-24)

As designed above plus the amendments: `SandboxDemoRound` `brands`, `piecesOfPuzzles`, `secretPuzzles`,
`unboxedLabels`, `seatsPerTable`, `tie`; `SandboxDemoEvent` `organization`, `brandedDisplays`;
`SandboxDemoDisplays` (5 designs); `SandboxDemoEventDefinitions::known()`; command options `--event`, `--maintainer`
(repeatable), `--logo`. Test `SandboxDemoEventsTest::testBuildsTheSpwsTorontoDemoOnRequest`.

## Sandbox build + walk (2026-11-24, PR #487 + follow-up)

Built on the sandbox with `--date=2026-12-02` (URLs: the guide §6). The walk found and the follow-up PR fixed:
- **Puzzle order on public pages**: `RoundPuzzlesVisibleNow` listed a round's visible puzzles by name, so a block
  showed "Challenge 2" before "Challenge 1" on the event page and the round page. Now `crp.position NULLS LAST,
  p.name` - a round nobody arranged lists exactly as before.
- **Analytics studio stuck on "Loading…" on the sandbox**: its JSON is fetched without cookies, so the sandbox welcome
  gate answered 401. `SandboxGate` leaves `/analytics-studio/` (data only) open, like `/embed/`.
- **No-show left out of the Pairs standing**: the same pair (last by name) did not start in all three rounds, and an
  only-no-show entrant is left out. `SandboxDemoRound::$noShowFromEnd` puts each round's no-show on another pair (a
  rebuild shows it; the current sandbox event keeps its recorded results).
