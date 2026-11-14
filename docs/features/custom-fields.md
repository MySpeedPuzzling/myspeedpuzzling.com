# Custom fields ("My fields")

A player's own private fields on their results: notes, a number, a date, yes/no, one of a list, tags. Stage 1a of the
data import (`docs/features/data-import/README.md` "Custom fields" + "Ready-made fields", decision D16) - it ships
alone and is free for everybody. Stage 1b adds collection items, the export and the puzzle merge.

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

**Tags are free** (decided here, the design left it open): a tags field has no fixed list - what the spreadsheets hold
are free labels, and the ready-made "My tags" has to take them. At most 20 tags per value, each at most 50 characters,
each once whatever its letter case.

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

For results: Private notes, What to work on, My tags. The rest (Condition, Missing pieces, Bought on, Bought from,
Price paid, Where it is, Copies, Artist, Year of release, Material) is for collection items - **they can already be
added and an own field can already be marked "on collection puzzles"; the page says plainly that filling them in
arrives soon** (1b).

## Storage

```
player_custom_field          id, player_id, name, standard_key, type, choices jsonb, for_results,
                             for_collection_items, position, archived_at, created_at
                             unique (player_id, name), unique (player_id, standard_key); player FK cascades
solving_time_custom_values   id, solving_time_id, player_id, custom_values jsonb (keyed by field id), updated_at
                             unique (solving_time_id, player_id); both FKs cascade
```

- **Not `custom_field`:** Doctrine ignores every table and index whose name starts with `custom_` (the schema filter
  in `config/services.php`, the prefix of the hand-made indexes) - a table of that name would never be created.
- **A row per result and player** (D16): a pair's or team's result is one row of several players, each has their own
  values. An empty set of values is no row.

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
    column `custom_values`, the entities `CustomField` / `SolvingTimeCustomValues`, their repositories) **or anything
    that hands fields or values out** - the readers `GetCustomFields` / `GetCustomValues`, the services
    `ViewerResultFields`, `OwnCustomValuesOnResults`, `AddFormCustomFields`, `CustomValuesOfRemovedCopy`,
    `CustomFieldLabels`, and the objects that carry them (`CustomValues`, `OwnCustomValues`, `CustomFieldOverview`,
    `CustomFieldsForm`). Each is listed with a reason saying *whose* fields it touches; a new caller - a profile
    controller, an API resource, a list query - is red until listed.
  - **No player id into the shared pages' services:** their public methods are pinned and none takes a parameter named
    like a player, viewer, user or owner.
  - **Never known at all:** `GetPlayerSolvedPuzzles`, `GetPuzzleResultDetail`, `GetPlayerProfile`, the edit-time and
    profile controllers and the whole `src/Api`.
  - **What it can not see:** what a listed file does with the values - that is what the reasons are for, and what the
    canary checks from the outside. Templates are not scanned (only `templates/my_fields/` and the two includes use
    the Twig functions).
  - **This is why routes, texts, templates and form inputs are named `my_fields…`** - the storage names stay rare
    enough to guard.
- `tests/CustomValuesPrivacyCanaryTest.php` - A and B on one pair result, A has values: nothing the partner, another
  player or a guest opens (result detail page + modal, B's values form, B's edit form, recap, profiles, puzzle page,
  recent activity, the add form, the manage page, B's export, API v1) carries A's values, field names or field ids.
  **Add every new surface that shows a result or a player there.**

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

## What 1b adds

- `collection_item.custom_values` and the fields in the collection item's edit form; copying an item copies them.
- The export (extra columns / nested object / `<custom name="…">`), everything through `SpreadsheetSafeValue`.
- The puzzle merge: an item moving onto the survivor keeps its values.
- The "arrives soon" wording of the manage page goes away.
