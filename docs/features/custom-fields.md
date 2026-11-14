# Custom fields ("My fields")

A player's own private fields on their results and on the puzzles of their collection: notes, a number, a date,
yes/no, one of a list, tags, a rating of 1-5. Stage 1 of the data import (`docs/features/data-import/README.md`
"Custom fields" + "Ready-made fields", decision D16) - it ships alone and is free for everybody. **1a** (2026-11-14):
the fields and results. **1b**: collection items, the export, the puzzle merge, ratings ("What 1b added" at the end).

In the app and in every text the feature is called **My fields**; the code says custom fields.

## What a player can do

- **Manage** at `/{_locale}/custom-fields` (`my_fields`, a card on the edit-profile page links there): add an own
  field (name, type, choices for "one of a list", where it is filled in), add a **ready-made** field with one tap,
  rename an own field / change its choices, move fields up and down, archive and restore.
- **Fill them in on a new result:** a "My fields" button after the event section of the add form - only for a player
  who has fields for results.
- **Fill them in / change them on a saved result:** the result detail shows the owner their values with an
  "Edit" / "Fill in" link to the values form (`result_my_fields`, `/{_locale}/custom-fields/result/{timeId}`) - in
  the modal frame from the detail, a full page otherwise.
- **Fill them in on a puzzle of a collection:** in the item's edit form (card menu → Edit, the form that edits the
  comment) - the only page that shows them ("Collection items" below).
- **Download them:** both exports carry the player's own fields as extra columns (`docs/features/data-export.md`
  "Own fields").

Limits: 20 fields in use per player (`CustomField::MAX_ACTIVE_PER_PLAYER`; archived ones do not count), 60 rows in
all with the archived ones (`MAX_PER_PLAYER` - the page then says so and what to do: restore an archived field instead
of making a new one), a name of 1-60 characters once per player whatever its letter case (a ready-made field's English
name counts), 1-30 choices of at most 50 characters. The type of a field never changes.

**Archived** = hidden everywhere (add form, values form, result detail), the values stay under the field's id and are
back when it is restored. There is no delete.

## Types and stored values

`Value\CustomValues::normalise()` is the one parser of a submitted value (the forms and the handler; later the import
and My data):

| Type | Input | Stored |
|---|---|---|
| text | textarea, at most 500 characters | string (line breaks kept, shown with `white-space: pre-line`) |
| number | `12`, `12.5`, `12,5`, `1 250` | int, or float with a fraction |
| date | `<input type="date">` | `Y-m-d`, shown in the page's language (`EventsPageDates`, "1 Mar 2026", "1. 3. 2026") |
| yes / no | select: -, Yes, No | `true` / `false` (no answer = no value) |
| one of a list | select of the field's choices | the choice |
| tags | one text, commas between tags | list of strings |
| rating | five radios "1"-"5" and "No rating" | int 1-5, shown as ★★★★☆ (and "4 of 5" for a screen reader) |

**Tags are free** (decided here, the design left it open): a tags field has no fixed list - what the spreadsheets hold
are free labels, and the ready-made "My tags" has to take them. At most 20 tags per value, each at most 50 characters,
each once whatever its letter case.

**A rating is a whole number from 1 to 5 and nothing else** - not `4.5`, not `04`, not `0`. No rating = no value;
"No rating" in the form takes one away.

**A number that can be read two ways is refused, never guessed:** one comma or point with exactly three digits
behind a group of one to three (`1,250`, `12.500`) is a thousand for some and a fraction for others. `0,125` and
`1234,567` can only be fractions, `12,5` and `1 250` are plain; spaces and no-break spaces between thousands are fine.

**Text the database can hold:** valid UTF-8 without control characters (line breaks and tabs stay in a text) - in
values, tags, field names and choices (`CustomValues::isStorableText()`). A NUL byte or broken UTF-8 passes every length
check and is refused by jsonb as a *failed statement* (which closes the entity manager); here it is a wrong value like
any other, of every type.

**What comes back as it is stored is no change:** the values form sends every field back, and the handler does not
judge again a value equal to the stored one's input form - so a choice removed from the field stays on the results that
have it (the form offers it for that result only), a stored `1.125` is not asked about, and a form saved as it opened
changes nothing, not even the row's time.

## Ready-made fields

`Value\StandardCustomField` - the registry: key (stored in `standard_key`, never renamed), type, where it applies, the
label's translation key, the choices of the two "one of a list" fields (the condition's are the sell/swap list's
`PuzzleCondition`), and header words in six languages for the import. A ready-made field is an ordinary field row; its
name follows the page language (`my_field_label()`), it can not be edited, it is there once per player, and tapping
it while archived restores it with its id (and so its values).

| For | Fields |
|---|---|
| results and collection puzzles | Private notes, My tags, How I liked it (rating) |
| results only | What to work on, How hard it felt (rating), Started on (date) |
| collection puzzles only | Condition, Missing pieces, Bought on, Bought from, Price paid, Where it is, Copies, Artist, Year of release, Material, Quality (rating), Sold for (number) |

How I liked it, How hard it felt, Quality, Started on and Sold for came with 1b - what another puzzle app's export
carries (`docs/features/data-import/research.md`).

## Storage

```
player_custom_field          id, player_id, name, standard_key, type, choices jsonb, for_results,
                             for_collection_items, position, archived_at, created_at
                             unique (player_id, name), unique (player_id, standard_key); player FK cascades
solving_time_custom_values   id, solving_time_id, player_id, custom_values jsonb (keyed by field id), updated_at
                             unique (solving_time_id, player_id); both FKs cascade
collection_item.custom_values   jsonb (keyed by field id), NULL = none - a column of the item: an item has one owner
```

- **Not `custom_field`:** Doctrine ignores every table and index whose name starts with `custom_` (the schema filter
  in `config/services.php`, the prefix of the hand-made indexes) - a table of that name would never be created.
- **A row per result and player** (D16): a pair's or team's result is one row of several players, each has their own
  values. An empty set of values is no row.
- **A column on the collection item:** the item is one player's, so its values need no table of their own. No values
  = `NULL`, never `{}`.

## Private by construction

Only the owner ever reads their fields and values. That is not a rule of each page - it is how the code is laid out:

1. **One reader of the values:** `Query\GetCustomValues::forViewer(timeIds, viewerPlayerId)` - every statement bound
   to the viewer's id. `Query\GetCustomFields` reads a player's fields for their own pages. Neither is joined into
   `GetPlayerSolvedPuzzles`, `GetPuzzleResultDetail`, the profile, any list or the API.
2. **A pair partner gets their own:** the values form of a shared result opens with the *viewer's* fields and values;
   a field id of somebody else sent to it is "no such field". The result detail's block is rendered per attempt the
   viewer is on, from the viewer's own row.
3. **Writing never goes through the result's edit:** `SetSolvingTimeCustomValues` has its own route, form and
   handler. `EditPuzzleSolvingTime` tells every member of a pair/team result and recalculates everybody's
   achievements and XP - a private field is nobody else's business. The values handler records no event, writes no
   notification and does not touch the result row.
4. **Who may write:** whoever tracked the result or is a registered member of it (`memberPlayerIds()`), their own row
   only. An imported event result nobody has claimed has no member; a claimer is one.

5. **The services a shared page calls take no player id:** `OwnCustomValuesOnResults::ofSignedInViewer()` (result
   detail) and `AddFormCustomFields::forForm()` / `saveSubmitted()` (add form) resolve the signed-in player themselves
   (`RetrieveLoggedUserProfile`) - there is nothing to pass the profile owner's id into.

### Guards

- `tests/CustomValuesCoverageTest.php` - what it covers, exactly:
  - **Which files it finds:** every file under `src/` whose code (comments aside) names the storage (the tables, the
    column `custom_values` - of the values table and of `collection_item` -, the entities `CustomField` /
    `SolvingTimeCustomValues`, their repositories, **the collection item's three methods** `customValues()` /
    `changeCustomValues()` / `fillCustomValueGapsFrom()`) **or anything that hands fields or values out** - the
    readers `GetCustomFields` / `GetCustomValues` / `GetCollectionItemCustomValues`, the services
    `ViewerResultFields`, `OwnCustomValuesOnResults`, `AddFormCustomFields`, `OwnCustomValuesInExport`,
    `CustomValuesOfRemovedCopy`, `SubmittedValuesChange`, `CustomFieldLabels`, and the objects that carry them
    (`CustomValues`, `OwnCustomValues`, `OwnCustomColumns`, `CustomFieldOverview`, `CustomFieldsForm`). Each is listed
    with a reason saying *whose* fields it touches; a new caller - a profile controller, an API resource, a list query,
    a collection page - is red until listed.
  - **No player id into the shared pages' services** (and the export's): their public methods are pinned and none
    takes a parameter named like a player, viewer, user or owner.
  - **Never known at all:** `GetPlayerSolvedPuzzles`, `GetPuzzleResultDetail`, `GetPlayerProfile`, the edit-time and
    profile controllers, the whole `src/Api`, and what reads or shows a collection: `GetCollectionItems`,
    `GetPlayerCollectionsWithCounts`, `GetPuzzleCollections`, `GetUnsolvedPuzzles`, `GetBorrowCandidates`,
    `GetExportableCollectionItems`, `CollectionItemOverview`, the two collection page controllers,
    `CollectionSelectionResponder`, `CollectionItemRepository`, `AddPuzzleToCollectionHandler`.
  - **What it can not see:** what a listed file does with the values - that is what the reasons are for, and what the
    canary checks from the outside. Templates are not scanned (only `templates/my_fields/` and the two includes use
    the Twig functions).
  - **This is why routes, texts, templates and form inputs are named `my_fields…`** - the storage names stay rare
    enough to guard.
- `tests/CustomValuesPrivacyCanaryTest.php` - A and B on one pair result, A has values: nothing the partner, another
  player or a guest opens (result detail page + modal, B's values form, B's edit form, recap, profiles, puzzle page,
  recent activity, the add form, the manage page, B's export, API v1) carries A's values, field names or field ids.
  **Add every new surface that shows a result or a player there.** For collection puzzles: the owner of a PUBLIC
  collection has values on its items - another player and a guest open the collection's page, the main collection,
  the library, the sell/swap list, the profile, the puzzle pages and the marketplace, another player also their own
  pages and exports, API v1 answers (`/players/{id}/collections…`, `/me/collections…`, `/me/library`) for another
  player's and the owner's own token: no value, field name or field id anywhere. The owner gets them in the item's
  edit form and the library export - and on no collection page either. **Add every new surface that shows a
  collection, its puzzles or a list made from them there.**

## The add form

The add-result form is the most important form of the app. The fields are **beside it, never part of it**
(Jan, 2026-11-14):

- **Plain inputs, no Symfony form children:** `my_fields[<field id>]` next to the form, like the co-puzzlers. A
  child could make the form invalid (a transformation failure, an invalid choice, "extra fields" when the block was
  rendered but is absent on the submit) - a plain input can not. `PuzzleAddFormType` and `PuzzleAddFormData` are
  untouched; a refused submit re-renders the block from the request, so nothing typed is lost.
- **Saved after the result, by a step that can not fail it:** `AddPuzzleSolvingTime`, `AddPuzzleTracking`, their
  handlers and the edit are untouched. After the add was dispatched successfully - and also when the form was sent
  again and answered with the saved result - `AddFormCustomFields::saveSubmitted()` dispatches
  `SetSolvingTimeCustomValues` (the same values twice store once). It never throws: anything that goes wrong is
  logged as a warning (`Custom fields of a saved result could not be saved`) and the player lands on the same recap
  with "Your result is saved. Your own fields could not be saved - you can fill them in on the result."
- **It is the last thing the controller does, on both paths:** the answer (the recap redirect; on a resend
  `answerResend()` with its own dispatch) is built first, then the fields are saved, then the answer is returned.
  A write that failed at the database closes Doctrine's entity manager - nothing after it may need one.
- **Nothing of a value in the log:** the warning carries a `CustomValuesWriteFailure` - the classes of the exception
  chain and the SQLSTATE, never their messages. A driver's message quotes the statement's data (Postgres:
  `CONTEXT: JSON data, line 1: {"…`), and warnings go to Loki and Sentry. The values form of a result rethrows a
  failed write the same way.
- **A field never refuses a result:** a value that can not be stored (not a number, a bad date, an unknown choice,
  too long) is left out, the rest is saved, and the flash names the fields that were not. No 422 can come from a field
  in the add form. (The values form of a result validates normally and answers 422.)
- **Loading fails open:** any error while loading the fields = the form of a player without fields, logged as a
  warning (`Custom fields could not be loaded for the add form`).
- **Nothing for a player without fields:** the block is its own partial (`my_fields/_add_form_block.html.twig`),
  included by one inline expression of `_solving_time_form.html.twig` that prints nothing otherwise - the form's HTML
  is byte for byte what it was (`AddFormMyFieldsTest` compares it with the feature switched off).
- **Collection mode:** the block hides with the comment/photo card through a CSS sibling rule in the partial
  (`:has()`), so the form's Stimulus controller is untouched; values posted from a hidden block are ignored.
- **Kill switch:** `CUSTOM_FIELDS_IN_ADD_FORM` (`docs/features/feature_flags.md`).

The edit form has no fields and no link - values are edited from the result detail.

### What it costs

`Services\CustomFields\ViewerResultFields` loads the fields for the add form and the result detail: one statement.
"This player has none" is remembered in the player's session for **two minutes**.

- **Why the memo exists:** the add form's statement budget is pinned exactly (`PuzzleAddPageQueryBudgetTest` - "a
  higher number is a bug to explain, not a new number"; it measures the second request and is unchanged), and a
  feature most players never use must not put a statement on every add form and every result detail.
- **What it costs:** a player without custom fields pays the statement once per two minutes of use; a player with
  fields pays it every time, plus the values statement on the result detail. A save without `my_fields` in the
  request runs no statement of this feature.
- **Its one downside:** a first field made on another device shows up here up to two minutes late. On the same device
  at once: every change on the manage page clears the memo, and so does opening the manage page with fields.

## When a player leaves a result

Their values row of that result goes too:

| How | Where |
|---|---|
| the result is deleted | the foreign key cascades |
| the account is deleted | `DeletePlayerHandler::deleteCustomFields()` - fields and values on every result, by player id (first, as bulk statements); the foreign keys cascade as well |
| a group edit takes a member off | `RemoveCustomValuesOfFormerMembers` on `GroupSolvingTimeEdited` - handled asynchronously, after the edit committed (the edit handler is untouched) |
| a member is replaced by a guest (internal API) | `ReplaceGroupMemberWithGuestHandler` |
| a claimed spot of an imported event result is released | `EventResultSlotRelease` |

Nothing of this is about privacy - the row of somebody who left could never be read by anybody (the reader is bound
to the viewer, and only on results the viewer is on).

## When a copy of a result is removed

Two results that are one (`docs/features/duplicate-results.md`): "Keep this one" on the review page
(`KeepDuplicateCopyHandler`) and the automatic removal of the same form sent twice
(`AutoRemoveCertainDuplicateHandler`) delete a copy - and the foreign key would take its custom values along. Before
the copy is deleted, `Services\CustomFields\CustomValuesOfRemovedCopy::moveTo()` moves them onto the kept result
(objects through the repository, no SQL):

- each player's row moves to the kept result when the kept result has none for that player;
- when both have a row, **the kept result's values stay** and only the fields it does not hold are filled from the
  copy (several copies: the oldest first);
- a player who is not on the kept result has nothing there - their row goes with the copy.

**Undo of an automatic removal** brings the copy back under its id, without custom values: they were moved and stay
on the kept result. That is accepted - nothing is lost, the values are on the result the player kept; whoever undoes
the removal can fill the fields in on the restored copy.

## The values form in the modal

Saved from the modal, the answer depends on what is under it - the link says so (`?context=`) and the form keeps it:

- **`page`** (the default; the result detail is the page under the modal): a Turbo Stream that closes the modal and
  refreshes the page (`_values_saved_stream.html.twig` - the answer of the edit-time modal), gated on the
  `Turbo-Frame: modal-frame` header *and* the stream format. Cancel only closes the modal.
- **`modal`** (the result detail is itself in the modal, opened from a list): a redirect Turbo follows inside the
  frame - the detail comes back into the modal and its block shows the new values. Cancel goes back to the detail.
- As a full page: a redirect to the result with a flash.

The block's button on the result is a 44 px target; it exists only for a player with fields, so nobody else's rows
grow.

## Selected options

`_inputs.html.twig` compares with `is same as` (and a strict membership check for a value from before the list
changed): the choices `1`, `1.0` and `01` are three choices and never render selected together.

## Collection items

A field "for collection puzzles" is filled in on each puzzle of the player's collections (the main one and custom
ones; a puzzle in two collections is two items with their own values).

### Collection pages are often public - the values are on none of them

**A value of a collection item is never rendered or selected on a collection page, list, card, API response, share
page, the marketplace or a lending surface - not for anybody, not for the owner either** (those lists are shared
between viewers and cached). The owner sees and changes them in the item's edit form, and gets them in their own
library export; "My data" will be the third place.

How the code keeps it:

- The property is **private** in `CollectionItem` - nothing that holds an item (half the app does) reads it by
  accident. The three methods `customValues()`, `changeCustomValues()`, `fillCustomValueGapsFrom()` are needles of
  `CustomValuesCoverageTest`: whoever calls one is on its list with a reason.
- **One reader:** `Query\GetCollectionItemCustomValues::forViewer(itemIds, viewerPlayerId)` /
  `allOfViewer(viewerPlayerId)` - `WHERE player_id = :viewerPlayerId` in every statement. No other query selects the
  column; `GetCollectionItems` and `GetExportableCollectionItems` are on the guard's "never known" list.
- **One writer:** `SetCollectionItemCustomValues` (owner only - somebody else's item is `CollectionItemNotFound`, the
  comment's rule) with the same rule as the result's writer (`SubmittedValuesChange`: the player's own fields for
  collection puzzles, one refused value refuses them all, what comes back as stored is no change).
- The canary opens the public pages as another player and as a guest ("Guards").

### The item's edit form

`EditCollectionItemCommentController` (`edit_collection_item_comment`, a full page - it has no modal) shows the
comment as before and, under it, the owner's fields for collection puzzles (`my_fields/_inputs.html.twig`, plain
`my_fields[<field id>]` inputs beside the Symfony form) with one quiet line: "Only you see these fields. They are
never shown on your collection's page - not even when the collection is public." A player without such fields gets
one quiet line with a link to "My fields" instead.

- Saved: the fields first (`SetCollectionItemCustomValues`), then the comment (`EditCollectionItemComment`,
  unchanged), then the answer the form always gave (flash + redirect to the collection).
- A value that can not be stored: **422**, the message at its field, everything typed still in the form, **nothing
  saved** - not the other fields, not the comment. A comment that is too long: 422 as before, the fields keep what was
  typed.
- Only the fields the form shows are taken from the request; a rating whose radios are all unchecked sends nothing
  and keeps what is stored.
- The form names its `action` explicitly; the answer is `Cache-Control: private, no-store`.
- Adding a puzzle to a collection is untouched (`AddPuzzleToCollection*`): the fields are filled in afterwards.

### Copy, move, merge, remove

| What happens | The values |
|---|---|
| **Copy** to another collection (`CopyPuzzlesToCollection`) | the copy starts with the source item's values; two items from then on |
| **Move** (`MovePuzzlesToCollection`, `MovePuzzleToCollection`) | the item is the same row - everything on it moves |
| Move or copy **into a collection that already holds the puzzle** | copy: nothing changes. Move: the source item leaves, and what it holds **fills the gaps** of the item that stays (`CollectionItem::fillCustomValueGapsFrom()`); what the staying item holds stays |
| **Puzzle merge** (`ApprovePuzzleMergeRequestHandler`), the player has only the merged puzzle | the item moves onto the survivor with its values |
| Puzzle merge, the player has both puzzles in the same collection | the two items become one: the survivor's item keeps its values, its gaps are filled from the merged one. The handler keeps the survivor's item object per collection and player for that (it used to keep only "there is one") |
| The merge audit (`puzzle_merge_audit`) | holds the ids of the items moved and dropped, as before - **never a value** (the audit outlives items and players) |
| **Remove** from a collection, sold/swapped, "remove from all collections" | the values are a column of the row - they go with it, nothing else to clean |
| **Account deletion** | `DeletePlayerHandler` already deletes the player's collection items (and their fields) - nothing new |

## The rating input

Five radios styled as star buttons ("★ 1" … "★ 5", Bootstrap's `btn-check`) and a sixth, "No rating", which is
checked while there is none and takes a rating away. **No JavaScript:** a group of radios in every form that includes
`_inputs.html.twig` (the add form's block, the values form of a result, the collection item's edit form). Every
button is at least 44 px. The stars up to the chosen one are lit by a `:has()` rule in the partial's own `<style>`
(only rendered when a rating field is in the form - the add form of a player without fields is still byte for byte
what it was); a browser without `:has()` lights the chosen star only, and its number says which.

In the add form a rating can not refuse a result either: a value that is no rating is left out and named in the flash
(`AddFormMyFieldsTest`).

## What 1b added

- The type `rating` and five more ready-made fields (How I liked it, How hard it felt, Quality, Started on, Sold for).
- `collection_item.custom_values` (migration `Version20261114152154`), the fields in the collection item's edit
  form, the reader and the writer bound to the owner; the "arrives soon" wording is gone.
- Copy / move / merge keep the values ("Copy, move, merge, remove").
- The exports: extra columns in XLSX and CSV, an object `custom` in JSON, `<custom name="…">` in XML - shapes, labels
  and cost in `docs/features/data-export.md` "Own fields".
- `SubmittedValuesChange` - the one rule of both writers, taken out of `SetSolvingTimeCustomValuesHandler`.
