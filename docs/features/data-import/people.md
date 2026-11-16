# The people of a file (stage 6d)

Who the pair and team partners named in a spreadsheet are. Until this stage every partner became a guest (the import
took a leading `#` off, so nothing was ever resolved). Jan's decision (2026-11-15): **a code or an id resolves to the
real person; a plain-text name never does by itself - the importing player is asked, and their answer maps it.**

The add-result form is not touched. Results are still written only through `ImportResultQuestions` →
`ImportedResultWriter` ([writer.md](writer.md)); "never twice" and Undo are as in [adding.md](adding.md).

| Piece | What it is |
|---|---|
| `data_import_person` (`DataImportPerson`) | One per distinct person text of an import: the name as written, its folded key, written as a code / id or not, rows, two example rows, the decision (guest / player / me), how it was made, when |
| `import_person_decision` (`ImportPersonDecision`) | The importing player's remembered answer per folded name, for their later imports |
| `ImportPersonText` | How a person's text is read: `#code`, a player id, a profile address, or a name |
| `ImportPeopleBuilder` | Builds the import's people with its puzzles (`BuildImportPuzzlesHandler`) |
| `ImportPartnerEligibility` | Who may be put on a result - the add form's rule + the block safeguard |
| `ImportRowPeople` | The people of a row for the writer, read when the row is added |
| `ImportPartnerLimits` | The two limits that protect a registered partner |
| `DecideImportPerson` → `DecideImportPersonHandler` | The importing player's answer about one person |
| `GetImportPeople` | The page's read model and the suggestions (explicit player id, no security token) |
| `/{_locale}/import/{id}/people` (`data_import_people` GET, `data_import_people_decide` POST) | "People in your file" |
| `NotificationType::ImportResultsAddedWithYou` | The partner's one notification per batch |
| `data_import_daily_count` (`DataImportDailyCount`) | Results an importing player's imports **made** per registered person and UTC day - what the daily limits count (never lowered by an Undo), and the partner's one notification of the day |
| `import_partner_opt_out`, `LeaveImportedResults` → handler, `POST /{_locale}/imported-results/{importerId}/leave` | "These are not mine - take me off them" |
| `SolvingTypeWords` | The one list of words that say solo / pair / team (cells, sheet titles, layouts) |

## Who may be added - the add form's rule, copied

Read from the form's own code:

- `PuzzlersGrouping::getPuzzlerFromUserInput()` (`src/Services/PuzzlersGrouping.php:114-146`): an input that starts
  with `#` is looked up by `PlayerRepository::getByCode()` (`src/Repository/PlayerRepository.php:92-109`,
  case-insensitive). **Any player with that code is added** - private or not, nobody is asked, no block is checked; a
  code nobody has becomes a guest of that name; an input without `#` is always a guest. The player themself is
  filtered out of the group (`assembleGroup()`, lines 41-45).
- `AddPuzzleSolvingTimeHandler` (`src/MessageHandler/AddPuzzleSolvingTimeHandler.php:160`) calls exactly that and
  adds no check of its own. The code format is `PlayerCodeFormData`'s: `[a-zA-Z0-9]{3,8}`.
- What the partner is told by a typed result: **nothing addressed to them**. `NotifyWhenPuzzleSolved` tells
  *followers* of the result's people (`SubscribedPlayerAddedTime`); a co-member only hears of it if they follow the
  tracker. A deleted group result (`DeletePuzzleSolvingTimeHandler`) tells nobody anything; it recalculates the
  members' achievements and compensates XP.
- The picker's suggestions (`/{_locale}/my-co-puzzlers.json`, `GetCoPuzzlers`) and its search are conveniences; the
  rule is the code.

So the eligibility rule for an import is: **the code (or id) names an existing player.**

### Safeguards on top (nobody watches a bulk import)

Where the add form and this stage disagree, the add form's rule decides eligibility and these are added:

1. **A block between the two, in either direction, is never resolved** (`GetUserBlocks::blockedEitherWay()`): the
   person stays a guest of the name the file writes - `ImportPartnerEligibility` answers the same `null` as for a
   code nobody has, the page shows the same guest, a typed code is refused with the same message
   (`unknown_player`). The importing player can not tell a block from an unknown code. Checked when the people are
   built **and again when each row is added** (a block that came in between makes a guest; the page shows a guest
   then, too).
2. **A plain name is never resolved by MySpeedPuzzling** - not even when exactly one player has that name.
3. **A player who took themself off this importing player's imports** (`import_partner_opt_out`, below) is never
   resolved again by that importing player - the same `null`, the same guest.
4. The limits below.
5. A typed `#code` on the page follows the add form's rule (any player, also a private one - shown by code only).
   A player **picked from the offered ones** must be one of the importing player's own people: a posted id of
   anybody else is refused.

Known, and left so: **the block safeguard is a tell for somebody who knows a code is real** - the add form would
resolve it, the import does not (the alternative, adding a player to results of somebody they blocked, is worse).
**First tries follow the add form's hidden-partner rule** (first-try-integrity.md: a partner hidden from the
importing player is left out of the assessment).

## Building

`ImportPeopleBuilder::build()` runs in `BuildImportPuzzlesHandler`, under the import's lock, after the exact pairing:
one statement reads every distinct text of `parsed.people` of the result rows with its row count and first two rows
(`DataImportPersonRepository::distinctPeopleOfRows()`); texts are folded to keys in PHP (`ImportPersonText`: a name
like a pair's/team's guest - `TeamComposition::guestMemberKey()`, so "Žofie ", "zofie" and "ŽOFIE" are one person;
`#abc12` in lower case; `id:<uuid>` for a bare id or the last part of a profile address).

Idempotent, and it survives a re-parse: a key still named keeps its row and its decision, a key no row names goes
(the remembered answer stays), a new key arrives. A person nobody decided anything about is decided only by what
needs no person: the importing player's remembered **"keep as a guest"** (`remembered` - the safe default, applied
silently) and **a code / id that resolves** (`code`; the importing player's own code is "me"). Everything else is a
guest (`default`).

**A name never becomes a player - or "me" - without the importing player's word in THIS import**:

- a remembered name → player (or "me") of an earlier file is **offered, not applied**: the page shows "Last time
  Anna was <player> - still right?" with that answer preselected, and the name stays a guest for adding until one
  tap says yes (`DecideImportPerson::$asRemembered`; "Yes, all as last time" - `$allRemembered` - when several).
  A remembered player who may not be added any more is not offered at all;
- a name equal to the importing player's own name is **not assumed to be them** (a namesake - father and son): the
  page asks "is this you?", and until answered the name stays as written, a guest. A profile address that does not
  resolve is a guest called "Player <first 8 of the id>", never the address.

An import whose puzzles were built before this stage has no people rows: its rows add guests as before, except that
a code is resolved when the row is added (below).

## "People in your file"

A card on the review before "Add" (people · players · guests, one statement - `GetImportPeople::counts()`) and the
page `…/import/{id}/people`: every person with the number of rows, two example rows, who they are, and one plain form
(no JavaScript, 44 px targets):

- "It is <player>" - one of the **suggestions**;
- "Not on MySpeedPuzzling - keep as a guest" (the default);
- "It is the player with this code:" + a field (resolved by the rule above);
- "That is me" (a nickname the file uses for the importing player).

A decision is per name per import, so it goes for all rows of that name. Taken → 302 back to the person; not taken
→ the page with a 422 and the notice at the person; without the token → 403; another player's import → 404.

### Answers in place

To Turbo (and the import's script - pairing-screens.md "Answers in place" describes both) the answer is shown where
it was given instead: `DecideImportPersonController::shown()` answers with Turbo Streams
(`_people_answer.stream.html.twig`) - `replace #person-<id>` with the person's card as it stands after the answer
(`_person.html.twig`: who they are now - or, for an answer that could not be taken, the same card with the notice;
nothing was written), `update #import-people-list` with every card for "Yes, still right - for all of them",
`remove #import-people-remembered` once fewer than two remembered names wait, and "Saved." in the line that stays in
view (`#import-answer-said`). What is written is what the redirect's answer writes; a plain post still gets the 302
/ 422 above. The consent form "Ask <player>" posts to Pairs & teams and is not part of it.

The answer that is selected on a card is **the one that is stored** - never one an earlier file remembered (that
one is offered above the form with its own button; it used to pre-select its radio, which showed "That is me"
selected on a guest). The card in place is the card a reload renders: the answer that stands is the selected one - also "It is the player
with this code", with the code in its field, when the person is a player who is none of those offered - and a code
that could not be taken stays in its field (`refused_code`) with its answer selected. **A typed code is never
dropped:** typing in the field selects its answer (the script's `pick`), and the server reads a non-empty code that
arrives beside "keep as a guest" (the answer selected by itself) as the code answer - taken, or refused with its
notice.

### Suggestions

`GetImportPeople::suggestable()` - **only among players the importing player is already connected to**:

- registered members of the importing player's existing `puzzling_team`s;
- their favourites (`player.favorite_players`);
- (a code of the file is no suggestion - it resolved by itself).

Of those: no block either way, and a private player only when the importing player is on their allow list
(`private_profile_viewer`) - the rules of `HiddenPlayers` and `PrivateProfileAccess`, written out for the player id
the query is given (the handler shares it and has no security token; both coverage guards list it with that reason).
Matching is in PHP on the folded names: **exact**, or **the first name only** - shown as "maybe". At most 5 per
person, exact first, then people they already solved with. **Never a search by name over everybody** - the same
name is many people, and a private player would be found by name.

### Remembered answers

Only the importing player's own answer is remembered (`import_person_decision`, one per player and key - never a
code that resolved by itself). In a later import a remembered "guest" applies silently (shown as "remembered from an
earlier import", changeable); a remembered player or "me" is offered and waits for the yes (above).

## The engine

`ImportRowAdder::resultSaid()` asks `ImportRowPeople::of($row)` for the row's people **as they are decided when the
row is added** (the `#`-stripping is gone from the path of an "Add"):

- a person decided to be a player, still allowed → `#code`, which the writer reads as that registered member exactly
  like the add form reads a code;
- "me" (the importing player's answer, or their own code) → left out. A kind that was counted from the named people
  moves with them (`ImportRowPeople::typeWithout()`: "Jan & Ana" imported by Jan, who said "that is me", is a pair,
  not a team); a kind a column said stays, and `people_do_not_fit_type` speaks up as before. **A pair row never
  becomes solo on an assumption**: with nobody left it is solo only when "that is me" was the importing player's own
  answer; their own code as the only partner leaves a pair that names nobody - refused and asked, like the add form
  refuses a group of oneself;
- everybody else → a guest of the name as written. **No entry leaves with a `#` that `ImportRowPeople` did not put
  there**, so the writer never resolves a code on its own;
- a code that has no person row (an answer typed after the people were built, an import from before this stage) is
  resolved on the spot by the same rule.

A row without people costs no statement; a row with people two (its people, the blocks), a registered partner two
more (the limits).

Everything else is the writer's and the questions' business, unchanged: the team is the exact set of people
(`PuzzlingTeamResolver`); a first try is assessed for every registered member with the importing player as the
viewer (`assessAsActor`) - a partner who holds a first try on the puzzle refuses the row (`first_try_taken`);
"never twice": a result **the partner already tracked with the importing player on it** is the importing player's
already (`ImportNeverTwice` reads results they are a member of) - the row is settled `already_yours` / `profile`
and linked, never a second result; a partner's pair/team result of that day and time the importing player is *not*
on refuses the row with `somebody_elses_result` (the existing question).

Unlisted puzzles keep guests (`ImportRowAdder::people()`, used by `UnlistedRowWriter` / `UnlistedPairer`): an
unlisted result is private, and its pairing writes what it kept.

### After the Add

A changed decision **does not rewrite results that are added** - the page says so. For those, a person mapped to a
player who is a guest on the importing player's results gets "Ask <player> to confirm they are <guest>": a form to
the existing `pairs_and_teams_link_guest` (`RequestGuestLink`, the consent flow of "Pairs & teams → Guests",
pre-filled with the guest's key and the player's code). Nothing new was invented for mapping after the fact.

## What the partner is told, and gets

- **ONE notification per importing player, partner and day**, whatever the number of results and of "Add"s (every
  press is a batch): "<importer> added N results with you from a spreadsheet" (`ImportResultsAddedWithYou`; target =
  the day's first batch - existing column -, actor = the importing player; N is the day's count,
  `data_import_daily_count.notification_id`). A later batch of the same day moves that notification - unread
  again, on top - instead of adding one. In the app only, no e-mail. It names the
  importing player (masked like any private player, hidden by the reader's blocks through the page's `notHidden()`
  rule) and nothing of the import - not its id, not its file's name. It links to the partner's "Pairs & teams".
  The partner is told **unless they block the importing player**; the importing player's own block does not
  silence it. (A block by either side stops further results onto that partner - the write-time check.) Not for a
  batch an Undo stopped. On the notification: "These are not mine - take me off them" (below).
- **XP**: `AwardXpForSolvingTime` per result (6a's follow-up) brings the ledger of every registered person of the
  result to what a typed result gives - the partner included.
- **Achievements**: `RecalculateBadgesForPlayer` once per batch for the importing player **and for every registered
  partner** (`FinishImportBatchHandler`).
- **Undo** deletes the results for everybody (the importing player is the tracker): XP is compensated per result
  as before, and achievements are recalculated **once per partner per Undo** (`data_import_undo.partner_player_ids`;
  6b-1 did it once per result). The partner's notification stays and no second one is sent: a normal delete of a
  group result tells its other people nothing either.
- Followers are still told nothing (`PuzzleSolved` is taken off by the writer).
- Deleting the importing player's account treats an imported group result like a typed one (the test compares the
  two).

## Limits

**The daily numbers count what was MADE, not what is still there**: `data_import_daily_count` (importing player,
registered person, UTC day - the importing player's own row is their total) is raised by one statement in the
transaction that writes a result (`ImportResultQuestions::ask()`, so an "Add" and the pairing of an unlisted puzzle
alike) and **never lowered by an Undo**: add 200 with a partner, undo, add again → the day's 200 are used up; the
same for the importing player's 2,000 (`countResultsAddedOrQueuedSince()` = queued rows + the day's count; for
results made before the count existed, the rows that still name them - whichever is more).

`DataImportLimits`, checked per row right before the writer (`ImportPartnerLimits`, the questions' `lastCheck` - so a
row that is already the player's is never refused by them), under the importing player's lock:

| Limit | Number | When reached |
|---|---|---|
| Results of a player's imports a day (unchanged) | 2,000 | the rest is ready tomorrow |
| `MAX_PARTNERS_PER_BATCH` - registered partners one "Add" puts results on | 20 | a row naming one more waits with `too_many_partners` |
| `MAX_RESULTS_PER_PARTNER_PER_DAY` - results one partner gets from one importing player a day (UTC) | 200 | the rest of that partner's rows wait with `partner_daily_limit` |
| `MAX_RESULTS_PER_PARTNER_PER_DAY_FROM_EVERYBODY` - imported results onto one player a day from every importing player together | 500 | the same reason, the same wording |

A row that waits with a reason is added again by its id (the not-added list), like any refused row.

## A partner can get out

A registered player who was put on results by somebody's import has, on that notification, **"These are not mine -
take me off them"** - one action (POST + CSRF `imported_results_leave`, the signed-in player is who leaves):

- every result that importing player's imports made with them on it **keeps existing**, tracked by the importing
  player, with the same number of people - the player is replaced by **a guest carrying their name** (the mechanism
  of the internal API's replace-with-guest: `PuzzleSolvingTime::replaceMistakenMember()`, the pair/team resolved
  anew, their own custom values of the result go with them);
- XP through the edit's own message per result (`RecalculateXpChainForSolve`), achievements recalculated **once**
  for the player and once for the importing player;
- the mark `import_partner_opt_out` (player, importer): **no further import of that importing player resolves this
  player** - by code, by a remembered answer or picked from the suggestions. For the importing player it is
  indistinguishable from a code nobody has (the same guest, the same refusal). Nobody is told;
- results the two typed together are not touched. Pressed again it finds nothing.

## A sheet called "Pairs"

Found on the sandbox: a sheet titled "500 piece Pairs" with no people column was imported as **solo** results - 58
pair results on solo leaderboards, silently.

- **One word list**: `SolvingTypeWords` - pair, pairs, duo, doubles, couple, partner(s), duet, trio, team, teams,
  group, solo, individual and the Czech, German, Spanish, French and Japanese words (`2人`, `デュオ`, `ダブルス` too),
  folded; phrases ("zu zweit", "à deux", "ve dvou"), German compounds by their stem (Paarpuzzle, Teamwertung) and
  "2 player" / "2-player" (read before the digits are taken out). Read by a cell of a solo/pair/team column
  (`ImportRowParser`, which had its own list), by a sheet's title, and by the remembered layouts.
- **The sheet asks** (`ImportQuestionType::SheetSolvingType`, built in `ImportColumnDecisions::settle()`): a results
  sheet whose title holds **any** pair or team word - also beside other words ("Pairs with Sam", "Pairs and Teams",
  "Doubles - single sitting"; the question suggests the one kind the title says, nothing when it says both; a solo
  word alone never asks) -, with no column mapped to "solved with" and nothing said yet, gets the question
  "This sheet is called '<title>'. Are these pair or team results?" → Solo / Pair / Team, and "Each row says it"
  when a solo/pair/team column exists. Nothing is preselected: never silently solo, never silently pair. Until it is
  answered the sheet's results that say nothing themselves **wait** (`type_open`, `waiting: true` -
  `PendingQuestionRows`): they are not ready. The answer is the sheet's `solvingType`
  (`options.solvingTypeAnswered` keeps "solo" and "each row says" from being asked again).
- **A people column wins**: a sheet with one keeps deriving the kind per row from the number of people, and asks
  nothing. A title that says solo or nothing asks nothing.
- **"Who did you solve these with?"** on the same card (and on the sheet's "every row is" card afterwards):
  `options.partner` - a name or `#code`, several separated by commas, for every pair/team row of the sheet that names
  nobody. It becomes the rows' `parsed.people` and goes through the people above: a code resolves, a name is a guest
  unless mapped. Left empty ("different partners"), the rows wait with the existing group card
  (`people_do_not_fit_type` → "Who did you solve these with?", adding-screens.md).
- **Layouts**: "every row is a pair result" is carried only onto a sheet of the same name whose title does not say
  another kind (`SolvingTypeWords::titleDisagrees()` in `ImportLayoutMatcher`).

### The review's line

Under every sheet of the review: what its results will be added as - "500 piece Pairs: 58 results · pair · with
Ana", "300 piece: 208 results · solo", "12 solo · 3 pair" when mixed - from the rows as parsed
(`GetImportAddScreen::summary()`, same statement). A wrong kind is visible before the tap.

**A sheet of somebody else's results can not be detected** (a sheet titled with a person's name, "Jared"): that line
("Jared: 1 result · solo") is what lets the player catch it - "who solved these?" is theirs to answer, on the
sheet's page, with the sheet's kind "Somebody else's" (columns-screen.md); the review's hint says to check that the
results are the player's own.

## Tests

`tests/MessageHandler/DataImport/ImportPeopleTest` (a code → member, the partner has the results and one
notification, achievements once each, Undo for both with one recalculation; unknown code / block either way → guests
alike, also a block after the code resolved; a name → guest, suggestions only among the importing player's people
- never the stranger of the same name, the private one, the blocked one -, mapped → member, remembered in a second
import and changeable; own name / code left out; the partner's own result not added twice; a partner's first try;
20 partners; 200 a day; account deletion), `tests/Controller/DataImport/ImportPeopleScreenTest` (owner only, token,
302 / 422, the consent form after an Add, the partner's notification row, 5 more locales; the same answers shown
in place - the card's stream, a refusal with nothing written, all cards for the one answer about all, 403 / 404),
`ImportAddScreensTest` (a "Pairs" sheet asks, waits, is added as pair results with the partner, the review's line; a
people column wins; solo is not asked again), `tests/Value/DataImport/SolvingTypeWordsTest` (titles in 6 languages).

## Loose ends

- Unlisted puzzles keep their people as guests (pairing an unlisted result writes guests).
- Imports whose puzzles were built before this stage have no people rows until their pairing runs again.
- "Take me off them" goes through all results in one transaction - fine for the 200 a day an importing player can
  put on somebody; a partner on thousands of results over many days would want it in turns.
- A row refused by a partner limit is not re-queued by "add everything that is ready" (like every refused row) - it
  is added by its id from the not-added list.
- The public guide's sentence about guests is stage 8's to change.
