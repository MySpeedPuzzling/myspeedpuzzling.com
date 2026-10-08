# Collections: select several puzzles (move, copy, remove)

Asked by a puzzler (MSP #107, follow-up F50, 2026-10): move many puzzles between collections without scanning the
boxes again. Before this, a puzzle moved one at a time (card ⋯ → Move, `MovePuzzleToCollectionController`), and
multiscan's "Scan into collection" only adds and refuses a batch with a puzzle already in the target.

## Decisions (Jan, 2026-10-08)

- Actions: **Move to…**, **Copy to…** (keep it here too - a puzzle may sit in several collections), **Lend to…**
  (added after Jan's first try, 2026-10-08) and **Remove from this collection** (asks to confirm).
- **Members only**, on their own collection pages (custom and system). Free players keep the one-by-one actions.
- Checkboxes on the cards, the floating bar appears with the first tick - no separate "Select" mode button.
- **One request, one message, one transaction** for any selection, set-based inside (not a handler per puzzle).
  Stale ids (already moved from another tab) are skipped and counted, never an error.
- **One toast** after success, with an "Open" link to the target collection. No undo (moves are reversible by hand;
  remove asks first).
- **The selection lives on the page only** - not kept across a reload, a session or a device.
- Moving is not re-adding: a moved item **keeps its id, added date and comment** - the single move too (it used to
  delete the item and create a new one dated now).
- Fixed on the way: the move handlers never checked that the source/target collection belongs to the player (the form
  field is free text, so a posted foreign collection id was accepted). `CollectionRepository::getOwnedBy()` now does.

## Sizes (production, 2026-10-08)

2,429 collections with items: median 7 puzzles, p90 99, p99 432, largest 1,347. The page renders every card, so
"Select all" on the largest one posts ~1,350 ids (~50 KB body). `CollectionSelection::MAX_PUZZLES` = 2,000.

## How it works

- **Page** (`collections/detail.html.twig`, `can_select` = owner with an active membership): every card gets a
  checkbox over its image's top left corner (`_puzzle_library_item.html.twig`, `selectable`), the list gets
  `collections/_selection_bar.html.twig`. The comparison launcher pill is hidden while anything is selected (it sat
  under the bar).
  `collection_selection_controller.js`:
  - first tick shows the bar ("N selected", plural from `browser_translation()`); while anything is selected a tap on
    a card toggles it instead of following its links, and the card menus are hidden (one action at a time);
  - shift-click selects a range; Esc clears (not while a modal is open);
  - "Select all" picks the cards the filters show; selected cards hidden by a later filter stay selected;
  - the bar is a form: Move / Copy / Remove post `puzzleIds[]` (+ stateless CSRF `collection_selection`) into
    `modal-frame`; after a successful modal submit (`data-collection-selection-form`) the selection is cleared.
  - Phones: the bar spans the screen; Select all, Copy, Lend and Remove sit behind ⋯.
  - **The bar is a form outside the modal frame** that targets it. Turbo fires that submission's fetch events on the
    form, never on the frame, so `dynamic_modal_controller.js` listens on the document for a request carrying
    `Turbo-Frame: modal-frame` from a form outside the frame - without that the modal never opened (the first release
    shipped so, 2026-10-08).
- **Modal**: `MoveSelectedPuzzlesController` (`collection_selected_move`, mode `move|copy`) renders the target picker
  (`CollectionPuzzleActionFormType` without the comment; typing a name creates a collection) carrying the ids as hidden
  inputs; `RemoveSelectedPuzzlesController` (`collection_selected_remove`) asks to confirm. Both refuse non-members
  (403) and another player's collection (404).
- **Lend**: `LendSelectedPuzzlesController` (`collection_selected_lend`): one person for the whole selection (the
  single lend's `LendPuzzleFormType` + favorites select), puzzles already lent out are left out up front
  (`MultiscanEligibility`) and named in the modal; dispatches the multiscan's `LendPuzzlesToPlayer`. The lent badge
  changes every card, so the answer closes the modal and refreshes the page (`<turbo-stream action="refresh">`) with
  the message as a flash.
- **Write**: `MovePuzzlesToCollection` / `CopyPuzzlesToCollection` / `RemovePuzzlesFromCollection` - two statements
  (`CollectionItemRepository::findByCollectionPlayerAndPuzzles()`: the selection's source items with their puzzles,
  then which of them the target holds), changes in memory, one flush. Each answers a `SelectedPuzzlesOutcome`
  (changed / already there / skipped) read from the `HandledStamp`.
  - Move: `CollectionItem::moveTo()`; an item whose puzzle the target holds only leaves the source.
  - Copy: a new item with the source item's added date and comment; skips puzzles the target holds and secret
    puzzles (`SecretPuzzleAccess`). Records `PuzzleAddedToCollection` per copy like any add.
  - Remove: this collection only.
- **Answer**: `CollectionSelectionResponder` - Turbo Streams (close the modal, remove the cards for move/remove,
  count, empty state, one toast with "Open"); without the `Turbo-Frame` header a redirect to the collection with a
  flash.

Tests: `tests/MessageHandler/SelectedCollectionPuzzlesHandlersTest.php`,
`tests/Controller/SelectedCollectionPuzzlesControllerTest.php`.
