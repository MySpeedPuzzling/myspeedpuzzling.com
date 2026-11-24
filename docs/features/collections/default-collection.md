# Default collection

A member picks one collection that every "add to a collection" picker starts with. Without one it is the system
collection ("Puzzle Collection" / `Collection::SYSTEM_ID`, `collection_item.collection_id IS NULL`).

## Why

Own collections are members-only. Before this, a picker preselected a collection only when there was exactly one choice,
and a member with own collections always has two or more (system + own) - so nothing was preselected, the member
picked every time, and forgetting it ended in "This field is required". A member asked for "a checkbox for setting as
default in the collection attributes". Production, 2026-11-24 (read-only): 170 players have own collections; of the 149
who added ≥ 5 items in 180 days, 88 put ≥ 80 % into one collection, for 100 the most used one is the system collection
(inflated a little: multiscan and the recap's one-click add already defaulted to it).

Jan's decision: an explicit default, the system collection as the fallback. No new input in the add form (smart forms:
ask only what the system cannot infer).

## Storage and writes

- `player.default_collection_id` (nullable, FK to `collection`, `ON DELETE SET NULL`; Doctrine's index) - null = the
  system collection. `Player::changeDefaultCollection()` / `forgetDefaultCollection()`.
- One message `ChangeDefaultCollection(playerId, collectionId|null)` → `ChangeDefaultCollectionHandler` (the collection
  must be the player's own - `CollectionRepository::getOwnedBy()`, else `CollectionNotFound`).
- Deleting the default collection (`DeleteCollectionHandler`) clears it on the managed player before the delete;
  account deletion (`DeletePlayerHandler`) clears it before the bulk delete of the player's collections. The FK would
  set NULL anyway - the managed entity must not keep pointing at a removed row.

## Setting it

- **"Default collection" checkbox** on the collection create and edit forms (`CollectionFormData::$isDefault`, the
  form type's `with_default` option = members only). Ticking = this one; unticking while it is the default = back to
  the system collection; editing another collection never touches the default. The create/edit controllers dispatch
  `ChangeDefaultCollection` after `CreateCollection` / `EditCollection` (those messages are unchanged - the API and
  six other callers use them).
- **"Make default"** (`MakeDefaultCollectionController`, POST + CSRF `default_collection`, route
  `make_default_collection`, `?return=` validated by `ReturnUrl`): in the ⋯ menu of every own/system collection card on
  the puzzle library overview and as a button on a collection page; on the system collection page only while another
  collection is the default (that is the way back). Members only.
- **"Default" badge**: the library overview (members with at least one own collection), the collection page.

## Reading it - one rule

`Value\CollectionPreselection::pick(PlayerProfile, choices)` - used by every picker that adds puzzles:

1. a URL preset (`?collection=` of the add form, multiscan's `?collection=`) - the caller sets it first and calls
   `pick()` only for an empty field;
2. a submitted value, **even an empty one** - the preselection is put on the form data before `handleRequest()`, which
   replaces it with whatever was submitted (a 422 re-render shows the submitted value, an empty one stays empty);
3. the member's default - only while it is among the choices built for this request (own, membership active, the puzzle
   not in it already). Never trusted from the FK alone: a lapsed membership falls back;
4. the system collection, if it is a choice;
5. the only choice there is (the old behaviour), else nothing.

`defaultOf(PlayerProfile)` = the default or the system collection (lapsed membership = system). The default rides on the
signed-in player's own profile row (`GetPlayerProfile::byUserId()` selects `player.default_collection_id`, like the time
zone; `byId()` never does) - no statement more on any page (`PuzzleAddPageQueryBudgetTest` unchanged).

## Pickers

| Surface | Change |
|---|---|
| Add form "Puzzle to collection" (`PuzzleAddController`) | `pick()` for an empty field (non-members: system, as before) |
| Add-to-collection modal / page (`Collections/AddPuzzleToCollectionController`) | `pick()` over the choices without the collections already holding the puzzle |
| Bulk "Add to collection…" of the wishlist / unsolved lists (`PuzzleSelection/AddSelectedListPuzzlesToCollectionController`) | `pick()` on the new form data (the modal-opening POST carries no form data, so it is not a submit) |
| Multiscan (`MultiscanTray::mount()`) | a valid `presetCollectionId` wins, else `defaultOf()`; a restored tray (`restore`) still puts its own collection over it (browser-checked: the select follows the restore). Not when the tray opens as "Move to…" ("Scan to move or remove" of the default collection's page would start with target = source; `MultiscanEligibility` would skip them all as `same_collection`) |
| Recap one-click (`AddToCollectionCta`) | unchanged: members open the modal above, non-members have only the system collection |
| Puzzle picker | unchanged: its collection list is a filter, not a target |
| Move (`MovePuzzleToCollectionController`, `MoveSelectedPuzzlesController`) | unchanged on purpose: the target can equal the source, a preselected default would invite a no-op move |
| API v1 collection endpoints | unchanged: the API already calls the system collection `"default"` (`collectionId: "default"`, "Default Collection"), an `isDefault` flag there would read as the opposite; API callers name their target |
| Spreadsheet data import | unchanged: it adds to the system collection explicitly (its own target) |
| Data export | the library `about` section gets `default_collection_id` (a `collection_id` of the `collections` section) - a new column |

## Seeing which one is chosen

Jan: a silent preselection in a closed select is not enough - and (2026-11-24) a row of chips above the picker was
too much: two controls for one choice, and many collections would flood it. So there is one field only. The add form,
the add-to-collection modal and the bulk add render the collection row through `collections/_collection_quick_pick.html.twig`
(the whole row: label, picker, help, errors). For members with 2+ choices the picker input carries
`data-default-collection` + `data-default-label`, and `assets/tom_select_default_option.js` (an `autocomplete:pre-connect`
listener installed in `app.js`) lists the default first and marks it with a "Default" badge - in the dropdown and on
the chosen item - so the preselected value reads "My collection [Default]". "Change default" (next to the label) links
to the library overview. The picker is the submitted field as before; without JavaScript it works as a plain input.

The success messages name the collection the puzzle went to (`flashes.puzzle_added_to_named_collection`,
`collections.flash.puzzle_added_to`) - a forgotten selection now saves into the default instead of asking.

## Tests

`tests/Value/CollectionPreselectionTest.php` (the rule), `tests/MessageHandler/ChangeDefaultCollectionHandlerTest.php`
(own / foreign / delete fallback / account deletion), `tests/Controller/DefaultCollectionTest.php` (every picker,
precedence, the forms, "Make default", the flash), `tests/Component/MultiscanTrayDefaultCollectionTest.php`.
