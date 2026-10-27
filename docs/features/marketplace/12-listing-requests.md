# 12 - Sell/swap requests: ask a seller for several puzzles at once

Feature request [019d1fda](https://myspeedpuzzling.com/en/feature-requests/019d1fda-4eee-7083-a3ae-cb1b11f77041),
issue #303. Decisions by Jan, 2026-10-27. Modelled on the wishlist's "Ask to borrow"
(`docs/features/wishlist-availability.md` §C).

A buyer ticks listings on somebody's sell/swap list or on the marketplace (several sellers at once) and sends each
seller **one request** for all of their ticked listings. The seller answers per puzzle; a yes reserves the listing for
the buyer and opens a chat. The sale itself is still the existing "Mark as sold" flow. Nothing is bought on
MySpeedPuzzling - never call it a cart or an order.

## Jan's decisions

| Question | Decision |
|---|---|
| Model | A structured request: `listing_request` per seller, `listing_request_item` per listing, answered per listing |
| Seller's yes | Reserves the listing for the buyer (`ListingReservation` - the system messages of a reservation by hand) |
| Where | The seller's sell/swap list page **and** the marketplace (across sellers, one request per seller) |
| Who | Every signed-in player - free like contacting a seller |
| Swaps | The buyer may offer puzzles of their own sell/swap list in exchange (`listing_request_offer`) + a note |
| Marketplace selection | Kept across pages, filters and "Show more" (sessionStorage) |
| Bulk add to the own list (the request's comment) | Follow-up, `docs/TODO.md` |

## What can be asked for - one rule

`GetRequestableListings::forListings(buyerId, listingIds)` - the sheet and `AskForListingsHandler` alike, the buyer's
id given explicitly (never a security token), one statement:

- the listing exists, its puzzle is not secret (`hide_until`), and there is **no block** between buyer and seller,
  either way - anything else is simply missing, so `unavailable` never tells a block apart
- `published_on_marketplace` is **not** required: the seller's own list page shows every listing to visitors, who can
  already contact the seller about it there
- flagged, not askable: the buyer's **own** listing, one **reserved for somebody else** (reserved for the buyer is
  fine), one the buyer **already asked** for (an open item - waiting or yes)
- a private seller the buyer may not see is named by code only (the allow list written out for that id - listed in
  `PrivateProfileQueryCoverageTest` and `BlocklistQueryCoverageTest`)

Limits: ≤ 10 sellers and ≤ 30 listings per send (`AskForListings`), ≤ 10 offered puzzles per seller, note ≤ 500
(`ListingRequest::NOTE_MAX_LENGTH`), rate limiter `listing_request` 10 sends/hour, idempotent per send id
(`listing_request_send_seller_unique`), one open ask per buyer and listing (`custom_listing_request_item_one_open`,
the race backstop). A buyer whose messaging is paused cannot ask (`MessagingMuted`).

## Data

- `listing_request`: buyer, seller (both cascade), `send_id`, note, `created_at`, `expires_at` (+14 days - listings move
  faster than collections), `replied_at` (the seller opened a chat from it).
- `listing_request_item`: request, buyer + seller copied, `listing_id` (**ON DELETE SET NULL** - the item stays as history
  when the listing is sold or removed), `puzzle_id` (merges move it), and **a copy of the listing as asked**: type,
  price, currency label (`customCurrency ?? currency`), condition - the detail page shows "now 25 EUR" when the price
  moved. `status`, `answered_at`, `closed_at`, `reserved_by_request` (only a reservation the request made is ever taken
  back by it).
- `listing_request_offer`: request, the buyer's listing (SET NULL) + puzzle, type and condition copied. Offers reserve
  nothing on the buyer's side.
- `player.listing_request_emails` (default on), `notification.target_listing_request_id`.

**Statuses** (`ListingRequestItemStatus`): `waiting`, `yes` (reserved for the buyer), `gone` ("I don't have it any
more"), `declined`, `withdrawn`, `expired`, `sold` (to this buyer), `unavailable` (sold to somebody else or left the
list while open). Open = waiting or yes.

## Flow

1. **Selecting.** A checkbox on every card a signed-in visitor may ask for - the seller's list page (not their own
   list - the owner keeps the members' bulk actions) and the marketplace (`buyer_select` on `_listing_card.html.twig`,
   only from the Live component). `listing_selection_controller.js` keeps the picks in sessionStorage
   (`assets/listing_selection_store.js`, key `msp:listing-selection:<playerId>`, ≤ 30, picks older than a day dropped,
   storage that throws = this page only), so they survive paging, filters, "Show more" and moving between a seller's
   list and the marketplace. On the marketplace the controller and the bar sit **outside** the Live component; each
   checkbox's label is `data-live-ignore` with a stable id, and a checkbox that (re)connects is set from the store.
   The bar: "5 puzzles from 2 sellers" + "Ask for these".
2. **The sheet** (`listing_ask_sheet`, POST `listingIds[]` into `modal-frame`, stateless CSRF `listing_selection`):
   `ListingAskSheetBuilder` (2 statements: the listings, the buyer's own list only when a swap is among them). One group
   per seller: avatar, "Ships to you" / "Doesn't list your country for shipping" (a hint, never a block), shipping cost,
   the listings with type/price/condition and ✕, the total of the priced ones in the seller's currency (never summed
   across sellers), "Offer puzzles from my sell/swap list in exchange" (when the group holds a swap), a note. Skipped
   picks are listed with their reason. `listing_ask_sheet_controller.js` keeps the total and the Send label in step.
3. **Send** (`listing_ask_send`, CSRF `listing_request`) → `AskForListings` → `ListingAskOutcome`. Per seller with
   anything askable: the request, its items and offers, notification `ListingRequested` (actor = buyer) and
   `SendListingRequestEmail` (async, after the bus). The modal closes with one toast linking to the sent requests, and
   the selection is cleared (`data-listing-selection-form`).
4. **The seller answers** on `listing_request_detail` (GET only shows - mail scanners; POST = the seller,
   `SerializedByLock` per request) per listing:
   - **Yes, reserve it** → `yes` + `ListingReservation::reserve(listing, buyer)`. A listing reserved for somebody else
     meanwhile is refused and stays waiting (flash; the radio is disabled on the page) - the seller removes that
     reservation first. Already reserved for the buyer = yes without a new reservation.
   - **I don't have it any more** → `gone`, pre-ticked "also take it off my sell/swap list" → the existing
     `RemovePuzzlesFromSellSwapList` (every other buyer's open ask of it becomes unavailable).
   - **Not this time** → `declined`.
   The buyer gets `ListingRequestAnswered` when something changed. A yes - or "Reply in chat" - opens an **accepted**
   chat from the seller's side even when the buyer has direct messages off (`StartConversation::$listingRequestId`,
   checked in the handler like `borrowRequestId`): a general conversation (reused when the two already have one) whose
   first message lists the yes puzzles and links to the request.
5. **Completing.** On the request page each `yes` listing has "Mark as sold to {buyer}" (the existing
   `sellswap_mark_sold_to_player` → `MarkPuzzleAsSoldOrSwapped`: the sold record, rating prompts, system messages).
6. **Closing when a listing goes** - `ListingRequestCloser::listingsGoing()`, called by every handler that sells or
   deletes a listing **before** the delete: single and batch sold, single and batch remove, admin remove, a merge's
   dropped duplicate listing. Sold to the item's buyer → `sold` (named, or - nobody named, as in the bulk "Mark as sold" - the buyer the listing was reserved for; a name typed by hand is somebody else); anything else → `unavailable` + one
   `ListingRequestUnavailable` to the buyer per request (not when a block came between them). Batch handlers pass all
   their listings in one call.
7. **Withdraw** (buyer, a request or one listing, quiet): a `yes` whose reservation the request made and is still the
   buyer's is released through `RemoveListingReservation` (system message).
8. **Expiry** - daily `myspeedpuzzling:expire-listing-requests`: waiting items past `expires_at` → `expired`, the buyer is
   told (`ListingRequestExpired`). A `yes` never expires - it holds a reservation the seller manages. The same run closes
   open items whose listing is gone without the closer (a warning = something deleted a listing around it).

A seller removing a request's reservation by hand elsewhere leaves the item `yes` ("not reserved any more" on the page).

## Pages

- `listing_requests` (`/{_locale}/sell-swap-requests`, tabs Sent / Received with the waiting count) - linked from the
  marketplace header, the owner's own sell/swap list ("Requests (2 waiting)") and the notifications.
- `listing_request_detail` (`/{_locale}/sell-swap-requests/{id}`): the listings as asked + now, totals, offers, note,
  answers (seller) / withdraw (buyer). Anybody else: 404.
- Who asks is named to the seller even when private (asking was consent); the viewer's own blocks hide the other side
  in the lists (`HiddenPlayers`) and make the detail 404 for the blocker.
- E-mail `emails/listing_request.html.twig` (subject "{buyer} asks for 3 puzzles on your sell/swap list", listings with
  price, offers, note, Answer button), signed one-click unsubscribe `listing_request_emails_unsubscribe`, switch in the
  messaging settings. All 6 locales.

## Merges, deletion, guards

- `ApprovePuzzleMergeRequestHandler` moves items and offers to the survivor (`puzzleMergedInto()`); a dropped duplicate
  listing closes its asks first. `PuzzleMergeForeignKeyCoverageTest` lists both FKs.
- Account deletion: buyer/seller FKs cascade.
- `GetNotifications` has its branch (+ NULL padding everywhere, the `notHidden()` clause).

## Cost

Seller's list page: +1 statement for its owner only (waiting count). Marketplace: none (the checkbox is markup, the
selection lives in the browser). Sheet: 2. Requests page: 2. Detail: 1.
