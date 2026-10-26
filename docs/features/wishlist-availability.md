# Wishlist availability and "Ask to borrow"

Feature request [019d3e41](https://myspeedpuzzling.com/en/feature-requests/019d3e41-513b-7018-a8df-6bfd4f487b0c),
issue #287. Decisions by Jan, 2026-10-26.

The wishlist shows where each puzzle can be had: offered on the marketplace, or owned by one of your favorite
puzzlers - and you can ask several of them at once to lend you puzzles. Asking only asks: a collection can be out of
date, so the owner confirms first, and the box changing hands is recorded afterwards by the existing lend/borrow flow.

## A. Offers on the wishlist

- Every wishlist card: "N offers" (→ `marketplace_puzzle`, with `?return=` back) and "Ships to you" / "Not shipped to
  you". Everybody looking at a wishlist sees the counts (gift buying); a viewer without a profile country gets no
  shipping line.
- An offer counts like a row of the marketplace list - published, the viewer's own blocks hidden (the list's own rule,
  `HiddenPlayers`), never a secret puzzle - and also **not reserved** and **not the viewer's own listing** (so the
  marketplace can show one more row than the chip in that rare case). "Ships to you" = the seller's
  `shipping_countries` contain the viewer's profile country, the marketplace's "Ships to my country" rule.
- One statement for the page: `GetWishlistMarketplaceAvailability::forPuzzles()`.
- The owner's banner: "N of your M wishlist puzzles are on the marketplace" → `marketplace?onMyWishlist=1`
  (+ `shipToMyCountry=1` when some ship to them).
- Client-side filter chips (`collection_filter_controller.js`, `availability` target): On the marketplace, Ships to
  me, A favorite has it (owner only). Cards carry `data-offers` / `data-ships-to-me` / `data-favorites`.

## B. "On my wishlist" on the marketplace

- Signed-in checkbox, Live prop `onMyWishlist` (URL), an `EXISTS` on `wish_list_item` in the shared
  `GetMarketplaceListings::filters()` - the count and the list never disagree. A guest's value is ignored.
- Cards of puzzles on the viewer's wishlist say "On your wishlist" (`MarketplaceListingItem::$onViewerWishlist`,
  computed on the page's rows only, with or without an event).

## C. Ask to borrow

**Who can be asked** - one rule, `GetBorrowCandidates` (UI and handler alike, takes the asker's id, never a token):

- the asker's favorites (`candidate_owners` CTE - the one place to widen later, e.g. anybody with a public collection;
  nothing else assumes the favorite relation)
- the puzzle is in a collection anybody may see: the system collection while `puzzle_collection_visibility` is public,
  a custom collection while it is public
- a private owner only with the asker on their allow list; never with a block between the two, either way
- only puzzles on the asker's wishlist (a crafted POST cannot ask about anything else); never a secret puzzle; a puzzle the owner has lent out right now is shown ("lent out right now") but never asked for
- on the wishlist page, puzzles already in the asker's own collection (or borrowed by them) are not offered

**Flow**

1. The owner of the list ticks puzzles (the collection pages' selection bar - for free players too, with "Ask to
   borrow" as the only action) or taps "Ask the fewest people for the most puzzles" (`BorrowPlanBuilder`, a greedy
   cover: whoever can lend the most, then whoever adds the most of the rest; ties to whoever owns more, then by name).
2. "Ask to borrow" opens the sheet (`borrow_ask_sheet`, modal): one group per person, puzzles as chips (✕ leaves one
   out, "Ask … instead" moves it to another owner - `borrow_ask_sheet_controller.js`), an optional note ≤ 200.
3. Send (`borrow_ask_send`) dispatches `AskToBorrowPuzzles`: one `borrow_request` per owner with a
   `borrow_request_item` per puzzle. Everything is checked again; skipped puzzles get a reason - every failure of the
   candidate rule is "unavailable", so a block can never be told apart from a puzzle that left the collection. A
   puzzle goes to one owner only. Idempotent per send id; ≤ 10 people and ≤ 30 puzzles per send; rate limiter
   `borrow_request` 10 sends per hour; one waiting ask per asker, owner and puzzle (`custom_borrow_item_one_waiting`).
4. The owner gets a bell notification (`BorrowRequested`) and an e-mail (`SendBorrowRequestEmail`, async; switch
   `player.borrow_request_emails`, signed one-click unsubscribe `borrow_request_emails_unsubscribe`).
5. The owner answers on `borrow_request_detail` (GET only shows - mail scanners): per puzzle **Yes** / **I don't have it
   any more** (pre-ticked "also take it out of my collection" → the existing `RemovePuzzlesFromAllCollections`) /
   **Not this time**. Only waiting puzzles change. The asker is told (`BorrowRequestAnswered`).
6. A yes - or "Reply in chat" - opens a chat from the owner's side, accepted from the start even when the asker has
   direct messages off (`StartConversation::$borrowRequestId`, checked in the handler like `partnerRequestId`).
7. The hand-over is recorded with the existing lend/borrow forms, pre-filled from the request ("Lend to …" /
   "I've got it" - `?borrower=` / `?owner=`), still members-only like lending today. `CloseBorrowRequestWhenLent`
   (on `LendingTransferCompleted`) closes the asker's open ask for that puzzle at that owner as `lent` - also one
   still waiting.
8. The asker can withdraw a request or one puzzle (quiet). Requests close their open puzzles after 30 days
   (`myspeedpuzzling:expire-borrow-requests`, daily; the asker is told - `BorrowRequestExpired`).

**Statuses** (`BorrowRequestItemStatus`): waiting, yes, not_owned, declined, withdrawn, expired, lent. Open = waiting
or yes.

**Pages**: the wishlist (chips, banner, sheet), `borrow_requests` (`/en/borrow-requests`, Asked by me / Asked of me),
`borrow_request_detail`. Who asks is named to the owner even when private (asking was consent); a private owner the
asker can no longer see is shown by code; the viewer's own blocks hide the other side (`GetBorrowRequests`).

**Free for everybody** - wishlists are free, so asking and answering are too; recording the hand-over keeps today's
members-only lend/borrow rule.

**Merges**: `ApprovePuzzleMergeRequestHandler` moves items to the survivor (`BorrowRequestItem::puzzleMergedInto()`),
dropping one already in the same request or waiting at the same owner.

## Cost

Wishlist page: +1 statement for everybody (offers), +3 for its owner (offers, candidates, own open asks). Marketplace:
none (the filter is a condition, the label a column of the page's rows). Ask sheet: 3 (wishlist, candidates, open asks).
