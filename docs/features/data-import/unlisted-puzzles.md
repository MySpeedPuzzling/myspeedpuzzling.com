# Unlisted puzzles (stage 7, the first slice)

A puzzle of a player's spreadsheet that is not in the catalogue is kept as the player's own **unlisted puzzle**, with
its results and whether they own or wish for it. Only they see it, and nothing of it counts in any statistic, chart,
streak, ranking, feed, XP or achievement **until it is paired with a catalogue puzzle** - then its results become
ordinary results through the import's own writer, never twice, and the pairing can be taken back for 30 days. Free
for everybody. The owner's decisions: the name is "Unlisted puzzles" (README Q7), unlisted results "should not be in
charts and rankings until paired" (Q2).

The add-result form is not touched: nothing here calls its messages or handlers, and nothing of it calls this.

| Piece | What it is |
|---|---|
| `unlisted_puzzle`, `unlisted_result`, `unlisted_ownership`, `unlisted_pairing` | The tables - all cascade with the player |
| `UnlistedRowWriter` (called by `ImportRowAdder`, `UndoImportRowHandler`) | A row of an "Add" whose puzzle is kept as unlisted, and its Undo |
| `GetUnlistedPuzzles` | THE reader - every statement bound to the owner |
| `UnlistedPuzzlePage` + `src/Controller/Unlisted/` | The owner's pages and their actions |
| `PairUnlistedPuzzle` → `ProcessUnlistedPairing` → `ApplyUnlistedPairingStep` (`UnlistedPairer`) | "It is this catalogue puzzle", in turns on `imports` |
| `ImportResultQuestions` | **The one way a result of a spreadsheet reaches the profile** - the questions and the writer, called by an "Add" and by a pairing alike |
| `UndoUnlistedPairing` | Undo of a pairing - the same worker, the other way |
| `RemoveUnlistedPuzzle`, `AnswerUnlistedSuggestion` | "Remove", "Not this one" |
| `AddUnlistedPuzzleToCatalogueController` (+ `UnlistedCatalogueProposalFormType`) | "Add to the catalogue": dispatches the add form's `AddPuzzle` and then `PairUnlistedPuzzle`, both unchanged |
| `ViewerUnlistedPuzzles` (Twig global `viewer_unlisted`) | Whether the menu shows "Unlisted puzzles" - remembered in the session |
| `SuggestUnlistedPuzzlePairings`, `myspeedpuzzling:pair-unlisted-puzzles` | The daily look at the grown catalogue |
| `UnlistedPuzzleMatcher` | The import's own pairing rules, asked for unlisted puzzles |
| `NotificationType::UnlistedPuzzlesFound` | "Some of your unlisted puzzles may be in the catalogue now" |

## The privacy rule, and what holds it

**Unlisted data lives in its own tables** (README D2). An unlisted puzzle is never a row of `puzzle`, an unlisted
result never a row of `puzzle_solving_time`, an owned / wish mark never a row of `collection_item` / `wish_list_item`.
So no reader that exists - 180 files read `puzzle`, 152 `puzzle_solving_time` - and none written later can show or
count one by accident: nothing has to remember a filter. The tables are tied to nothing of the catalogue either:
`paired_puzzle_id` / `suggested_puzzle_id` are plain ids, not foreign keys (a puzzle merged since is followed through
`puzzle_redirect` when the id is used).

What holds it:

- **`UnlistedDataCoverageTest`**: every file of `src/` whose code names the tables, their entities, their repository,
  their reader or one of the feature's services is listed with the reason it may (whose data it touches). No file of
  `src/Query` (but the one reader), `src/Api`, `src/Component`, `src/Twig` or `src/Results` may name them at all.
  Every statement of `GetUnlistedPuzzles` carries `player_id = :ownerPlayerId`; `UnlistedPuzzlePage` takes no player
  id - it resolves the signed-in player itself.
- **`UnlistedPuzzlesPagesTest`**: another player and a guest get **404** for every address (the same answer as for an
  unlisted puzzle that does not exist - a guest is not sent to the sign-in page, which would say "there is something
  here"); the owner's profile, statistics, library, collections and unsolved list, the catalogue's search and the
  feed show none of the names to another player or a guest.
- **`UnlistedPuzzlesTest`**: adding unlisted rows moves no count of `puzzle`, `puzzle_solving_time`,
  `collection_item`, `wish_list_item`; no XP award, no notification but the batch's own.
- The pages are `noindex` (meta + `X-Robots-Tag`) and `Cache-Control: private, no-store`. No player id is in any
  address. What a file wrote never stands in an address (the search is a POST).
- Logs name ids only - never a name or a value of the file.

**The one way out is pairing** - the player's own tap, through `ImportedResultWriter`. Nothing else turns an unlisted
result into a public one: not the daily look (it only suggests), not a later import, not an admin.

## The tables

```
unlisted_puzzle     id, player_id, puzzle_key, name, brand_text, pieces_count, eans jsonb, brand_codes jsonb,
                    picture_url (text only - never fetched), created_at, created_by_import_id,
                    paired_puzzle_id, paired_at, suggested_puzzle_id, suggested_at, refused_suggestion_puzzle_id,
                    match_checked_at                                        unique (player_id, puzzle_key)
unlisted_result     id, unlisted_puzzle_id, player_id, seconds_to_solve (null = solved, no time),
                    solved_on (`2024-03-09` / `2024-03` / `2024` - as the row has it), date_precision,
                    puzzling_type, people jsonb (names), first_attempt (as the file said), comment,
                    import_row_id (unique), source_key, row_number, created_at,
                    said_already_on_profile, counted_on_its_own ("these are two"),
                    pairing_id, converted_solving_time_id, converted_at, converted_created, made jsonb, kept_reason
unlisted_ownership  id, unlisted_puzzle_id, player_id, kind (owned | wish_list), comment, collection_label,
                    import_row_id, created_at, pairing_id, converted_item_id, converted_at, converted_created,
                    made jsonb, kept_reason                                 unique (unlisted_puzzle_id, kind)
unlisted_pairing    id, request_id (unique), player_id, unlisted_puzzle_id (SET NULL - the record outlives the
                    puzzle), unlisted_puzzle_key (copied), puzzle_id, state
                    (running | finished | undoing | undone), started_at, finished_at, undo_requested_at, undone_at,
                    last_activity_at, daily_limit_reached_at, results_added, results_already_yours, results_kept, items_added,
                    items_already_yours, results_taken_back, results_kept_by_undo
```

Also `data_import_row.result_made_at`: when the result a row names was **made** - by an "Add" or by a pairing - written
with the link (`DataImportRowRepository::linkSolvingTime()`). It is what the daily guard counts.

`comment` is the column the player mapped to "Public comment": it is public only once the result is a real one.
Notes and the values of the player's own fields are **not** copied here (below, "Left for later").

Account deletion: every table cascades with the player (foreign keys) - `DeletePlayerHandler` needs no step of its
own (tested). There is no list of a player's data for an export to add them to; the export sections are left for
later.

## Adding

A row is **ready** also when its puzzle is decided as `unlisted` (`DataImportRowRepository::sqlReadyRows()` - the one
definition) - dated or not: nothing public is made of it, and what the file says of the date is kept as it says
it (a day, a month, a year, nothing). The row goes through the same batch and turn machinery as any other
(`AddImportRows` → `ProcessImportBatch` → `ApplyImportRow`); in `ImportRowAdder::add()` it is handed to
`UnlistedRowWriter::write()` instead of being refused `unlisted_not_yet` (the code stays for batches made before).

- One unlisted puzzle per player and `puzzle_key`; a later row of the same key that says more (a barcode, a brand, a
  picture link) updates it, never doubles it.
- **Never twice**: a row makes one result (`import_row_id` is unique); the same data in another import (the same
  source key) is "already yours" (`earlier_import`) - whether it is an unlisted result or became a real one since;
  a result the unlisted puzzle already has with the same day, time and solo/pair/team is "already yours" too (unless
  the player said "these are two"); equal rows of a file are one result (`equal_row`); one owned and one wish mark
  per unlisted puzzle (`owned`); a wish for a puzzle that is owned or has a result is nothing to wish for.
- Refused, as for a catalogue row: a comment longer than a result (500) or a collection item (500) takes - said now,
  not when the puzzle is paired.
- The row is `added` with `made = {"unlisted": {puzzleId, puzzleCreated, resultId, ownershipId}}` - ids only. It names
  no result (`solving_time_id` null, `result_made` false).
- **Nothing is owed for an unlisted result**: no event is recorded and nothing is queued - no XP, no statistics, no
  achievements, no follower notification, no feed entry, no duplicate case, no time verification (the pre-check is
  skipped: `idsOfTimedReady()` / `timedRows()` leave unlisted rows out). The batch's one notification and e-mail count
  the row as added.
- **Undo** (`UndoImportRowHandler` → `UnlistedRowWriter::takeBack()`): the row's unlisted result and mark are
  removed, and the unlisted puzzle with them when nothing hangs on it any more and it is not paired; the row waits
  again. **Not when its puzzle was paired since and the result or the mark is a real one now**: the whole row stays
  `added` (`made.keptByUndo`, counted with the results "changed since") - the pairing's own Undo comes first.
- **The daily guard** (2,000 results a day, README Q4) is about what becomes **public**: rows kept as unlisted are
  not counted - not while they are queued, not once they are added - and an "Add" takes them whatever is left of the
  day. What counts is counted when it becomes public: see "The daily guard" below.

The screens (6b-2) say it: the review and the ready card have a line "Rows kept as unlisted puzzles: N - only you see
them", they are not among "what will be added to your profile", and the done page links to the list.

## The pages

Owner only, all 6 locales, mobile first, no JavaScript of their own.

- **`/{_locale}/unlisted-puzzles`** (`unlisted_puzzles`): the list, 50 a page, the suggested ones first, then by
  name - name, brand as written, pieces, results, owned / wished; two sentences on top say what an unlisted puzzle
  is and what can be done with it; **each row that is not matched yet has the two actions** "Match" (its page, at
  `#match`) and "Add to the catalogue" (the form, in the site's modal) - and the plain line "Only you see these. They do
  not count in statistics, charts or rankings until you pair them with a catalogue puzzle." On the list: an unlisted
  puzzle that is not paired, or a paired one something unlisted is still left on. **Two statements** whatever the
  size (tested with 1 and with 61 puzzles).
- **`/{_locale}/unlisted-puzzles/{id}`** (`unlisted_puzzle`): what the file said, its results (time, day as the file
  has it, solo/pair/team, people, first try, comment), its marks, the file it came from, its pairings with what came
  of them, and:
  - **"It is this catalogue puzzle"**: MySpeedPuzzling's suggestion (if any) with "Not this one"; "Is it one of
    these?" - what the import's exact rules find for the key, else the catalogue puzzles with a similar name
    (`UnlistedPuzzleMatcher::candidatesOf()`); and the import's own search (`GetImportPuzzleScreen::search()`), a POST
    answered 422 (`unlisted_puzzle_search`). Every one is the import's catalogue card
    (`data_import/_catalogue_puzzle.html.twig`) as a button "It is this one".
  - **"Remove"** - two steps, like the import's "Take back": a link to `/{_locale}/unlisted-puzzles/{id}/remove`,
    which says what would go for good (so many unlisted results, so many marks; what is a real result by now
    stays), then a POST with its CSRF token. The puzzle goes with its unlisted results and marks; nothing on the
    profile is touched; reading the same file again does not bring it back (its rows keep saying "added").
  - **"Add to the catalogue"** - a link to the form's own page (below).

## Where it is linked from

"Where does 'Keep just for me' go?" (the first tester, 2026-11): Imports home, the done page of an "Add", My data
and the notification - and since then also

- **the import's overview** (`data_import/detail.html.twig` includes `unlisted_puzzles/_import_link.html.twig`): one
  line when this import will keep rows as unlisted (`add.unlisted > 0`) or the player has unlisted puzzles;
- **the public guide** (`#unlisted`): where they are found later and what can be done there - the words for
  everybody, the link only for a signed-in reader (a guest gets 404 on the page);
- **the user menu**, only for a player who has at least one (`viewer_unlisted.hasAny`).

The menu is on every page and nearly nobody has unlisted puzzles, so `ViewerUnlistedPuzzles` remembers the answer
in the player's session, like `ViewerResultFields`: "none" for 2 minutes, "some" for 10 - one small statement
(`GetUnlistedPuzzles::has()`) per 2 minutes of use for a player without any, never one per page; the pinned page
budgets count a second request and did not move. The list page tells the service what it just read
(`remember()`), so the item is right there at once. Its one downside: the first puzzles an import keeps show in the
menu up to 2 minutes late - the import's own pages link to the list meanwhile.

## Add to the catalogue

`/{_locale}/unlisted-puzzles/{id}/add-to-catalogue` (`unlisted_puzzle_add_to_catalogue`, GET + POST, owner only -
404 for anybody else and for a guest). For an unlisted puzzle that is not matched and has nothing at work
(`can_choose`); otherwise it redirects to the puzzle's page.

**One source of truth for "a player proposes a new puzzle": the add form's own message.** The controller dispatches
`AddPuzzle` unchanged - as the scanner's quick-add does - and then `PairUnlistedPuzzle` unchanged. Nothing of the
add-result form (controllers, form types, messages, handlers) is touched, and there is no schema change.

- **The form** (`UnlistedCatalogueProposalFormType`, plain inputs, no JavaScript): name, brand, pieces (10 - 99,999,
  the add form's range), **a photo of the box - required, `PuzzleBoxPhoto`, the rule of every place a new puzzle is
  made**, and optionally a barcode and a brand code by the catalogue's one rule for codes (`EanList`,
  `BrandCodeList`). Filled from what the file said: the brand as **the catalogue's own name of it** when the file's
  brand is one of ours (`UnlistedPuzzleMatcher` → it is that brand), else as written; of the file's barcodes only
  those the catalogue would take.
- **Before anything is created** the form shows the catalogue puzzles it may already be - MySpeedPuzzling's
  suggestion and what `UnlistedPuzzleMatcher::candidatesOf()` finds (the import's exact rules, else the same brand
  and pieces with a similar name; never a hidden or secret puzzle) - each a button "It is this one" that is the
  unlisted page's own matching (`unlisted_puzzle_pair`).
- **The brand** goes through `ManufacturerResolver` inside `AddPuzzleHandler`: a typed name that is a brand we have
  (ignoring case and spacing, approved or not) is that brand; only otherwise a new, unapproved brand - exactly what
  the add form allows.
- **The puzzle** is `approved = false`, added by the player, with the photo stored by `AddPuzzleHandler` - in the
  moderators' approvals queue like any puzzle a player adds, with everything that follows from there.
- **The results and marks move by the pairing** "It is this one" starts: the same worker, the same questions
  (`ImportResultQuestions`), the same daily guard, the same record (`unlisted_pairing`) and **the same Undo, 30
  days**. `GetImportPuzzleCandidates::choosable()` lets a player choose a puzzle waiting for approval that they
  added themselves. An Undo makes the results unlisted again; the catalogue puzzle stays in the queue, like any
  puzzle a player added. **The import's own Undo** behaves as after any pairing: rows whose results are real ones
  now stay `added` until the pairing is undone (tested).
- **What becomes public**: results on a puzzle waiting for approval are ordinary results - on the profile, counted
  like any other; the puzzle itself is out of the catalogue's search and lists until approved. The form says so in
  one sentence before anything is sent.
- **Never twice**: the form carries `new_puzzle_id` and `request_id`; sent again `AddPuzzle` creates nothing and the
  pairing answers the same - the answer is the same success.
- **No puzzle without its match**: the two messages are two transactions, so everything that would refuse the match
  is asked **before** `AddPuzzle` - a match at work (`can_choose`), the safety switch, the rate limit of "Add"
  (looked at with `consume(0)`, never used up twice: `PairUnlistedPuzzleHandler` takes the one token). Should the
  match be refused all the same, the form says "The puzzle is in the catalogue; we could not move your results yet -
  try again in a minute", the controller remembers that puzzle for this unlisted puzzle in the session, and the
  form leads with it.
- **The player's own puzzle leads** (`catalogueProposal()`'s `own_puzzle`): a catalogue puzzle they added that still
  waits for approval and that is this unlisted puzzle's - the one of a match they took back (the pairing's record),
  the remembered one, or one of theirs with exactly this name and pieces - is shown first with "It is this one"
  (no photo, no second puzzle), the form folded under it. The form then carries **that puzzle's id**: sent, it
  corrects that puzzle (`AddPuzzleHandler`, while nothing uses it) and matches onto it - Undo and "Add to the
  catalogue" again never makes a duplicate (tested).
- A tampered `new_puzzle_id`: somebody else's puzzle gets a fresh id before anything is dispatched; one the handler
  refuses (`PuzzleIdTaken` - a secret puzzle of the player's own) is a refused form with a fresh id, logged at
  `info`.
- **A list that went stale**: asked for inside the modal for a puzzle that is matched or at work by now, the answer
  is a small frame body with one sentence and a link to the puzzle's page (a redirect would empty the frame); as a
  page it redirects.
- **The photo** is a plain file input: the add form's background upload could not be reused without changing it
  (`submit_prevention_controller.js` is built around that form's markup, and "a photo that can not be left out" is
  its field name `puzzlePhoto`). So the size is smaller here - `UnlistedCatalogueProposalFormType::MAX_PHOTO_BYTES`
  (20 MB; the add form's 60 MB assumes its shrinking) - and a request above PHP's `post_max_size`, which arrives
  without any field, is answered with the form and "The photo is too large", not with the token check's 403.
- **Limits**: at most `UnlistedPuzzlePage::MAX_CATALOGUE_ADDITIONS_PER_DAY` (10) new puzzles per player in 24 hours,
  counted over every way a player adds a puzzle (`CountPuzzlesAddedByPlayer` - there was no limiter for added
  puzzles to reuse; the add form itself is not limited by it); nothing is created while the import's safety switch
  is on (pairing would refuse afterwards); the pairing's own rate limit (`data_import_add`) applies.
- **In place**: opened from the list the form is the site's modal (`Turbo-Frame: modal-frame`; every form in it
  names its action). A success asked for as a Turbo Stream closes the modal and replaces the row
  (`#unlisted-puzzle-{id}`) with "Added to the catalogue - waiting for approval" and a link to the puzzle. Without
  JavaScript, and from the puzzle's own page, the same address is a page of its own that redirects to the unlisted
  puzzle's page. A refused form is a 422 both ways (the photo has to be picked again - a plain file input).
- A failed store of the photo (`AddPuzzleHandler` throws) is caught: nothing was created, the form says "try
  again", the exception is logged as an error.

## Pairing ("It is this catalogue puzzle")

1. **`PairUnlistedPuzzle`** (request id, unlisted puzzle, player, catalogue puzzle) - serialized with the player's
   "Add"s, one transaction, every check before anything is written: the unlisted puzzle is the player's (else "not
   found") → the same request id answers the same pairing → the import's safety switch (`paused`) → no pairing or
   Undo of it at work (all read under the player's import lock, below) → **the catalogue puzzle is one the player
   may choose**
   (`GetImportPuzzleCandidates::choosable()`: never hidden or secret, never waiting for approval unless they added
   it - whatever the form sent) → it was not paired with another catalogue puzzle whose results are still there →
   the rate limit of "Add" (`data_import_add`) → something unlisted is left. Then the pairing is recorded, the
   unlisted results and marks are handed to it, the puzzle is marked paired and **the player's remembered decision
   for the key says this catalogue puzzle** (their next file pairs directly). Nothing is converted in the request.
2. **`ProcessUnlistedPairing`** on `imports`, outside a transaction, at most 50 subjects or 8 seconds a turn, then it
   queues itself again. Each result and each mark is one `ApplyUnlistedPairingStep` - its own transaction, under the
   player's import lock (`ImportWriteLock`), the results first. Everything is driven by the subjects' state, so a
   second delivery or a restart converts nothing twice. A step that throws is rolled back and its subject marked
   (`error`); the pairing goes on. The safety switch is asked before every step of a pairing; the sweeper
   (`myspeedpuzzling:continue-import-batches`) queues a pairing that stands still again.
3. **A result** (`UnlistedPairer::convertResult()`):
   - the catalogue puzzle may still be chosen and written to (`puzzle_not_available`, `puzzle_not_revealed`) - **a
     puzzle merged between the tap and the step is its survivor** (`puzzle_redirect`);
   - **a result that knows only its month or year becomes a result of that precision** (6c, `date-precision.md`:
     the first day of the period + `finished_at_precision`); only a result **without any date** stays unlisted
     (`needs_exact_day` - the code kept its name). The results that know their day are converted first, so "Sept
     2021" is the result its day of the same file made, never a second one - what an "Add" does by adding the finer
     row first;
   - then **`ImportResultQuestions::ask()` - the very method an "Add" calls for a row** (`ImportRowAdder::resultOf()`),
     not a copy of it, with what the unlisted result kept of its row: the row's own result or the same data from
     another import exists → it is that result; the player has exactly this result on the profile, not spoken for →
     it is that one, and the row speaks for it from now on; people that do not fit the kind
     (`people_do_not_fit_type`); somebody else's pair/team result of that day and time (`somebody_elses_result`); a
     similar result on the profile (`similar_result_exists`); a time the time verification finds unusual
     (`unusual_time`); the file says the result is on MySpeedPuzzling and none was found
     (`said_already_on_profile`); then `ImportedResultWriter::write()` - guests only, the first-try assessment, the
     too-fast and too-long limits, the wish list's "remove when solved", `created_via = spreadsheet_import` - and
     with it "these are two" told to the duplicate job (`ResultDuplicatePrevention` `saved_anyway`) for a result the
     player counted on its own. **A refusal keeps the result unlisted, with the reason shown.** Nothing is waved
     through on the unlisted page - whatever would be a question to the player publishes nothing.
   `ImportResultQuestionsParityTest` runs the same cases through an "Add" and through a pairing and compares what
   came of them.
4. **A mark** (`convertOwnership()`): owned → an item of the system collection unless one is there (never touched),
   honouring the wish list's "remove when added to my collection" (written down for Undo); wish → a wish list item
   unless the puzzle is on the list, owned or solved.
5. **The end** (`finish()`): what is owed for the results the pairing made - `ImportedResultsFollowUp`, as a batch
   owes it (the puzzle's statistics, `AwardXpForSolvingTime` per result on `imports`, achievements once, the
   prediction backfill) - and the pairing's numbers. No notification: the player pressed the button and the page
   says what came of it.

### The lock

"It is this catalogue puzzle", its Undo, "Remove", every step of the worker and every row of an "Add" take **the
player's import lock** (`ImportWriteLock`, until their transaction ends) before they read anything: a "Remove" in
one tab and a pairing in another take turns, each sees what the other committed, and neither ends in a foreign-key
error.

### The daily guard

What a pairing makes public counts like what an "Add" makes public: at most
`DataImportLimits::MAX_RESULTS_ADDED_PER_DAY` results a day and player (UTC).
`DataImportRowRepository::countResultsAddedOrQueuedSince()` counts the rows whose result was **made** today
(`data_import_daily_count`, raised when the result is made - whenever the row itself was added) or is queued for
the profile; **a result that was undone since still counts** (stage 6d: the count is what was made that day, so
making public and taking back can not churn - people.md "Limits"), a row kept as unlisted never. Before a pairing converts a result it asks, under the lock, what is left
of the day: nothing left → the result is left as it is (no reason on it), the pairing is marked
(`daily_limit_reached_at`) and **stays at work** - the page says "Today's limit … is reached. The rest is added
tomorrow, by itself" - and the sweeper (`myspeedpuzzling:continue-import-batches`, every 5 minutes) goes on with it
once the day has turned. Marks are not held: only results use the day up.

A paired puzzle with something still unlisted on it stays on the list ("Still unlisted here: N", each with its
reason) and offers "Try to add the rest again" - a new pairing with the same catalogue puzzle. Rows a later "Add"
keeps as unlisted for the same key hang on the same unlisted puzzle and wait there the same way.

### Undo of a pairing

`UndoUnlistedPairing` - the player's own, a finished pairing not older than 30 days (`UnlistedPairing::UNDO_DAYS`),
never held by the safety switch. The same worker, the other way, exactly what the pairing recorded:

- a result it made is deleted through `PuzzleSolvingTimeRepository::delete(…, ImportUndo)` (graveyard, tombstones,
  statistics by the delete's event, `CompensateXpForDeletedSolve`, achievements) - **unless it was changed since**
  (time, day, people, puzzle - a merge is not a change): then it stays on the profile and is counted
  (`edited_since`);
- a result that was the player's already is only let go of;
- the collection / wish list item it created is removed (kept when moved to another collection since), a wish list
  item it removed comes back;
- the results and marks are unlisted again; when nothing of the puzzle is real any more it is unpaired and the
  remembered decision says "unlisted" again;
- **the catalogue puzzle is "not this puzzle" for the daily look** (`refused_suggestion_puzzle_id`): it is not
  suggested - and the player not told - again. Pairing by hand stays open.

The pairing's record stays for good (who, when, which catalogue puzzle, what came of it, when it was taken back) -
**also when the unlisted puzzle is removed afterwards**: `unlisted_pairing.unlisted_puzzle_id` is set to null by the
database, the puzzle's key is copied on the record, and the results that were public for a while are whole in
`solving_time_graveyard`. It goes with the player's account only.
After 30 days the results are ordinary results the player deletes like any other.

## When the catalogue grows

`myspeedpuzzling:pair-unlisted-puzzles` (daily; `--limit`, default 5,000 puzzles a run) dispatches
`SuggestUnlistedPuzzlePairings`: for the unlisted puzzles that are not paired - the longest not asked first, each at
most once in 20 hours - the import's **exact** rules are asked again (`UnlistedPuzzleMatcher::exact()`, a player's
puzzles in one lookup; the player's results on the profile are not looked at). Only "found" - one catalogue puzzle
by a rule strong enough to be ticked for a player - counts, and even that **converts nothing**: pairing makes
results public, and that takes the player's tap. The unlisted puzzle is marked "We think it is this catalogue
puzzle" and its player is told **once per run**, in the app, whatever the number (`UnlistedPuzzlesFound`, no target,
no e-mail); the same suggestion is never told twice. "Not this one" - here, or "not this puzzle" on a pairing screen
of an import - is never suggested again.

Cron (lily): `17 5 * * * … php bin/console myspeedpuzzling:pair-unlisted-puzzles`.

## Where the README / plan and the code disagree

| README / plan | Built | Why |
|---|---|---|
| Tables `unlisted_solving_time`, `unlisted_collection_item` | `unlisted_result`, `unlisted_ownership` | The stage's brief names them; an owned / wish mark is not a collection item (no collection, no custom values) |
| `unlisted_puzzle.manufacturer_id`, `resolved_puzzle_id` as a foreign key the merge handler moves (`PuzzleMergeForeignKeyCoverageTest`) | Brand as text only; `paired_puzzle_id` / `suggested_puzzle_id` plain ids, followed through `puzzle_redirect` | The tables are tied to nothing of the catalogue; `ApprovePuzzleMergeRequestHandler` is not touched |
| "No picture" | `picture_url` kept as text | The brief; never fetched |
| `custom_values` on the unlisted tables | Not stored | Left for later - see below |
| A pairing is "one batch in the log" (`data_change_batch`) | `unlisted_pairing` + what it made on the results and marks themselves | 6b-1 built no change log; Undo is exact and bounded by the subject, as for rows |
| One locked message that moves everything | The request records, the worker converts in turns | The brief; no message works longer than a few seconds |
| Pairing "moves" results - a question (similar result, unusual time) is asked | The same questions by the same code (`ImportResultQuestions`); such a result stays unlisted with the reason; nothing is waved through | A choice that affects what becomes public publishes less; the answers need a screen |
| Undo of a pairing: no limit named | 30 days, the player's own | The brief |
| A weekly job `match-unlisted-puzzles` that tells "3 of your unlisted puzzles are in the catalogue now" | Daily `pair-unlisted-puzzles`; the notification names no number | The brief; a number would need a target column in the notifications' UNION - the list page says it |
| A 404 for somebody else, a sign-in redirect for a guest (as the import's pages) | 404 for both | The brief |
| Owner-only blocks on the profile's results and the collection pages, the banner line (`HintType::UnlistedPuzzles`), the totals line, export sections, "These are the same", "Fine as it is", edit | Not built | Left for later |
| "Add to the catalogue" as a proposal of its own | The add form's `AddPuzzle` + the unlisted page's pairing, both unchanged | One source of truth for a player's new puzzle; the move, its questions and its Undo already exist |
| `UnlistedPuzzleCoverageTest`, `UnlistedPuzzleCanaryTest` | `UnlistedDataCoverageTest`; the canary is part of `UnlistedPuzzlesPagesTest` | One guard file per the brief |

## Left for later

- Pictures fetched from the file's links; editing an unlisted result by hand; adding an unlisted puzzle outside the
  import; joining two spellings ("These are the same"); "Fine as it is"; the
  owner-only blocks on the profile and the collection pages, the banner line and the totals line.
- An undated unlisted result can not be given a date on the unlisted page (editing is left for later): it stays
  unlisted when its puzzle is paired.
- **Answers to the pairing's questions**: a result kept for `similar_result_exists`, `somebody_elses_result`,
  `unusual_time`, `first_try_taken` has no "add it anyway" on the unlisted page yet.
- **The player's own fields and notes** of an unlisted row are not stored with the unlisted result and are not
  written when it is paired (they are still on the import's row while its content is kept).
- **Export**: no sections `unlisted_puzzles` / `unlisted_results` / `unlisted_collection_items` in the library export.
- **Admins**: no page for pairings and no admin Undo; a pairing is on `unlisted_pairing`, what it made by
  `unlisted_result.pairing_id` / `converted_solving_time_id`, a deleted result whole in `solving_time_graveyard`.
- A pairing that waits for tomorrow can not be undone or removed before it is through.
- After an Undo of a pairing only the remembered decision goes back to "unlisted"; a `data_import_puzzle` of an
  import that was decided as the catalogue puzzle through that remembered decision keeps its decision.
- The import's "what this import added" list shows a row kept as unlisted without a badge of its own.
- **Add to the catalogue**: no adding of several at once; the photo is a plain file input (no background upload, no
  shrinking in the browser as on the add form - a big photo on a slow connection holds a request); a 422 loses the
  picked photo; other names / languages of the box are not asked.
- Progress of a pairing is a "Refresh the page" button, not the live line of an "Add".
- The cron row for `myspeedpuzzling:pair-unlisted-puzzles` on lily.

## The tests

`tests/MessageHandler/DataImport/UnlistedPuzzlesTest` (adding: results with and without a time, a pair, a month-only
day, owned, wish, both - the unlisted tables only; the same data again and equal rows; Undo; pairing: new results
once, the result the player had, a similar one and an undated one kept, month and year results paired with their precision, the mark, the remembered decision, a
resent request, the rest again, the import's Undo after a pairing, Undo of the pairing; a result changed since; who
may pair and with what; paused; Remove; the daily look; the daily guard and "the rest tomorrow"; a puzzle merged
between the tap and the step; the lock of pairing, Undo and Remove; an undone pairing is not suggested again and its
record outlives the puzzle; account deletion), `tests/Services/DataImport/ImportResultQuestionsParityTest` (the
same cases through an "Add" and through a pairing), `tests/Controller/Unlisted/
UnlistedPuzzlesPagesTest` (owner only, the canary, the list's statements, the flow from the page, every language),
`tests/UnlistedDataCoverageTest`, `tests/Controller/Unlisted/AddUnlistedPuzzleToCatalogueTest` (the two actions on
a row, the form filled from the file, the photo required, 422 / 403, the modal's Turbo Stream and the page's
redirect, the puzzle waiting for approval in the queue with the right brand, a new brand, the moved results, the
form sent again, the likely catalogue puzzle → matching, the daily ten, the safety switch, the import's Undo
afterwards, owner only, the menu / overview / guide links and their cost, every language).
