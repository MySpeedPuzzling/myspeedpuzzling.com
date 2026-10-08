# Select several puzzles: collections and the other lists

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

## Other lists: wishlist, sell/swap, unsolved, lend/borrow (shipped 2026-10-08)

Jan's calls: all four lists, the fuller set of actions, members only (owner of the list with an active membership).

| List | Actions | Answer |
|---|---|---|
| Wishlist | **Add to collection…** (picker; leaves the wishlist like the single add, `removeOnCollectionAdd`), **Remove from wishlist** (confirm) | add = page refresh, remove = streams |
| Sell/swap | **Mark as sold/swapped** (no buyer; also leaves collections + wishlist like the single one), **Reserve**, **Remove reservation**, **Remove from list** (confirm) | sold/remove = streams, reserve/unreserve = act at once + refresh |
| Unsolved | **Add to collection…**, **Lend to…**, **Remove from all my collections** (confirm; borrowed puzzles skipped) | add = toast only, lend = refresh, remove = streams |
| Lend/borrow (both tabs) | **Return** (confirm; owner and holder alike, `ReturnLentPuzzles`) | refresh |

Every action keeps the collection rules: one request, one message, stale ids skipped and counted, one toast.
Sell/swap messages run the single handlers per listing, so buyers in a conversation get the same system messages.

How it works:
- `Value\PuzzleSelection` reads the ids (`puzzleIds[]`, `MAX_PUZZLES`, CSRF id `collection_selection`, shared with
  collections - `CollectionSelection` uses it). `Value\PuzzleList` = the four lists (URL segment, page route, card id
  prefix + count/container ids + empty state for the streams); `Value\PuzzleListSelectionAction` = remove / sold /
  reserve / unreserve / return (which list has which, which asks first, which takes cards off).
- Pages: `can_select` = owner with an active membership; `_puzzle_library_item.html.twig` takes `selectable` from the
  page (collections keep their own default), the sell/swap card (`sell-swap/_item.html.twig` works it out itself, so
  the reserve streams keep the checkbox) has the checkbox on the card's corner, outside the puzzle link, and the
  "Reserved" badge moves aside. The bar is `_puzzle_selection_bar.html.twig` with a list of actions - the collection
  bar is one use of it. Lend/borrow: one selection over both tabs; "Select all" and shift-ranges take only shown
  cards (`getClientRects()`: filtered out or on the other tab = not shown).
- Routes (POST, members, 403 otherwise; an action the list does not have = 404): `puzzle_list_selected_action`
  (`/en/my-lists/{list}/selected/{action}`, `ApplyToSelectedListPuzzlesController`: confirm modal
  `puzzle_selection/confirm_modal.html.twig`, reserve/unreserve act at once), `puzzle_list_selected_add_to_collection`
  (wishlist + unsolved, the collection picker; `SelectedPuzzlesCollectionTarget` is the picker's "picked or typed"
  logic, shared with Move/Copy), `puzzle_list_selected_lend` (unsolved, the collection lend modal with `action_url`).
- Messages, each answering `SelectedPuzzlesOutcome`, touching the signed-in player's own rows only:
  `RemovePuzzlesFromWishList`, `MarkPuzzlesAsSoldOrSwapped`, `ReservePuzzleListings`,
  `RemovePuzzleListingReservations`, `RemovePuzzlesFromSellSwapList`, `RemovePuzzlesFromAllCollections`. The existing
  batch messages `AddPuzzlesToCollection`, `LendPuzzlesToPlayer`, `ReturnLentPuzzles` reject a whole batch on one
  ineligible puzzle, so the controllers filter first (`MultiscanEligibility`, `SecretPuzzleAccess::pendingRevealAmong()`
  for secret puzzles, borrowed puzzles left out of Lend).
- **Gotcha: `SystemMessageSender::sendToAllConversations()` flushes.** A batch that deletes listings must tell every
  conversation first and delete afterwards - a flush meeting a conversation whose listing was deleted earlier in the
  batch fails ("A new entity was found through the relationship Conversation#sellSwapListItem").
  `MarkPuzzleAsSoldOrSwappedHandler` is split into `recordSale()` + `removeEverywhere()` for that.
- Answer: `PuzzleListSelectionResponder` - streams for wishlist remove and sell/swap sold/remove (cards, count, empty
  state, toast), the page loaded again (`<turbo-stream action="refresh">` + flash) for the rest, a redirect to the
  list page without the modal frame.

Tests: `tests/MessageHandler/SelectedListPuzzlesHandlersTest.php`, `tests/Controller/SelectedListPuzzlesControllerTest.php`.
