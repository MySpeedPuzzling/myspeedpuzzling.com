# 11 — Marketplace at events

Sellers mark the puzzles they are **bringing** to an in-person event; buyers can **ask** a seller who is going to bring
something else from their list. Hand-over in person, no shipping. Design canvas (v3, decisions + mockups + all copy):
https://claude.ai/artifact/Ws1onDbJM24yKJYn4TECk5

This file is the build contract: shared class names, signatures, translation keys and the split of work. Anything
here that turns out wrong is fixed **here first**, then in code.

## Decisions (Jan, 2026-10-03)

| Topic | Decision |
|---|---|
| States of a listing at an event | **Bringing** = a `sell_swap_list_item_event` row exists. **Ask to bring it** = no row, but the seller is going and the listing is published (derived, nothing stored). |
| Opt-out from "ask to bring it" | Not in v1. |
| Picker default | Nothing ticked. |
| Event page | **E2** — one compact card, copy changes with the viewer. |
| Marketplace | A **select in the filter panel**; results as **one list, bringing first**, divider, "Only what they're bringing" checkbox; shipping filters off under an event. |
| After "I'm going" | **F1** members with ≥1 published listing → the picker page as a next step (`?joined=1`). **F2** everyone else → event page with the card highlighted in buyer wording ("just joined" flash). |
| Listing form | Friendly reminder always; event checkboxes when the seller goes to a qualifying event. |
| Chat | "Bring to event" seller action: *bring it* / *bring it and reserve it for {buyer}*. "Ask to bring it" pre-fills the contact message (buyer's locale). |
| Banner | `HintType::MarketplaceAtEvents`, dismissed once, on the marketplace + own sell/swap list. |
| Event-only offers, organizer switch | No, no. |
| Links after the event / after leaving | **Kept.** The read side hides them (attendance + date checks). |
| Locales | Every new text in **all 6 locales** (cs, de, en, es, fr, ja). Routes in all 6 locales like their neighbours. |
| Performance | Budget below, guarded by query-count tests. |

## Which events qualify ("marketplace event")

In person (`is_online = false`), publicly visible (`IsCompetitionPubliclyVisible::SQL_CONDITION`), dated
(`date_from IS NOT NULL`), not over (`COALESCE(date_to, date_from)::date >= today`, today from `ClockInterface`).
Standalone events and series editions alike.

A player **is going** = a `competition_participant` row with `player_id = player`, `deleted_at IS NULL` and not waitlisted - the one rule `CompetitionParticipantGoing::sql()` (a waitlisted row of a managed event is not going; rows of events without managed registration have no status, so nothing changed for them - [registration.md](../competitions-management/registration.md)).

## Data model

```
sell_swap_list_item_event
  sell_swap_list_item_id  uuid       PK, FK → sell_swap_list_item ON DELETE CASCADE
  competition_id          uuid       PK, FK → competition          ON DELETE CASCADE
  added_at                timestamp
  INDEX (competition_id)
```

No separate index on `sell_swap_list_item_id`: the primary key leads with it.

Rows are never deleted when the event ends or the seller leaves (decision: kept). Only `ChooseEventOffers` /
the listing form (unticking) and the FK cascades remove them.

## Shared foundation (committed first on `feature/marketplace-at-events`)

| Piece | Contract |
|---|---|
| `Entity\SellSwapListItemEvent` | `__construct(SellSwapListItem $sellSwapListItem, Competition $competition, DateTimeImmutable $addedAt)`; composite `#[Id]` on both ManyToOne (JoinColumn `onDelete: 'CASCADE'`, not nullable). |
| `Repository\SellSwapListItemEventRepository` | plain `readonly` class: `find(UuidInterface\|string $listItemId, UuidInterface\|string $competitionId): null\|SellSwapListItemEvent`, `save(SellSwapListItemEvent)` (persist only), `delete(SellSwapListItemEvent)`, `forPlayerAndCompetition(string $playerId, string $competitionId): list<SellSwapListItemEvent>`, `forListItem(string $listItemId): list<SellSwapListItemEvent>`. |
| `Query\GetMarketplaceEvents` | `SQL_QUALIFIES` (WHERE fragment, aliases `c` = competition, `cs` = competition_series LEFT JOINed on `cs.id = c.series_id`, binds `:marketplace_today` — use `todayParameter()` to get `['marketplace_today' => Y-m-d]`), `sqlPlayerGoing(string $competitionIdSql, string $playerIdSql): string` (an `EXISTS (…)` fragment), `qualifies(string $competitionId): bool`, `byId(string $competitionId): MarketplaceEvent` (throws `CompetitionNotEligibleForMarketplace` = 404 when it does not qualify; added by the foundation so a page can link/redirect to an event without another lookup), `isPlayerGoing(string $competitionId, string $playerId): bool` (attendance only, does not check qualifies), `forPlayer(string $playerId): list<MarketplaceEvent>` (qualifying events the player goes to, nearest first). `sqlPlayerGoing()` is `static`. |
| `Results\MarketplaceEvent` | `competitionId`, `name`, `shortName` (shortcut ?? name), `dateFrom`, `dateTo`, `location`, `countryCode` (`CountryCode\|null`), `reference` (`CompetitionReference` — use `routeName()`/`routeParameters()` for links and `displayName()` for prose). |
| `Message\ChooseEventOffers` | `(string $playerId, string $competitionId, list<string> $listItemIds)` — handler by the seller-side agent. |
| `Message\BringListingToEvent` | `(string $playerId, string $listItemId, string $competitionId, null\|string $reserveForPlayerId)` — handler by the seller-side agent. |
| Exceptions | `Exceptions\CompetitionNotEligibleForMarketplace`, `Exceptions\PlayerNotGoingToCompetition` - both extend `NotFoundHttpException` like `CompetitionNotFound` (so `UnwrapHttpExceptionMiddleware` turns them into a 404 when a handler throws them). |
| `Value\HintType::MarketplaceAtEvents` | `'marketplace_at_events'`. |
| `Value\EventJustJoined` | `const string FLASH = 'event_just_joined'` — flash value = competition id. Written by the join flow (F2), read by the event/edition controllers. Only success/danger/warning/info flashes render in `base.html.twig`, so a custom type is safe. |
| Route `event_offers_picker` | `Controller\Marketplace\EventOffersPickerController`, `/en/events/{competitionId}/what-i-bring` + 5 locales; foundation ships a working stub (auth + qualifies + going + membership checks, placeholder template), the seller-side agent builds the page. |
| Translations | The full English block `marketplace_events:` in `translations/messages.en.yml` (below). Other locales are filled by the translation pass at the end; agents add keys **only at the end of their own sub-block**. |

## Work split (one worktree + test DB each)

| Agent | Owns |
|---|---|
| **W — seller side** | Handlers for `ChooseEventOffers`, `BringListingToEvent`; `eventIds` on `AddPuzzleToSellSwapList` / `EditSellSwapListItem` (+ form, form data, templates incl. the reminder note); the picker page (F1 + edit mode, lazy Stimulus search); the join redirect (F1 / F2 flash) in `JoinCompetitionController`; the "Bring to event" chat action in `templates/messaging/_conversation_listing_actions.html.twig`. |
| **R — event page** | `Query\GetEventOffers` + result; `templates/_event_offers.html.twig` on `event_detail` and `edition_detail` (all card states incl. F2), the two controllers; blocklist/private-profile canaries. |
| **M — marketplace** | `GetMarketplaceListings` (event criterion, `onlyBringing`, bringing-first order, counts, labels); `MarketplaceListing` component + templates (select, context header, divider, labels, "Ask to bring it"); `Query\GetEventsWithSellersGoing` (cached); `?event=` noindex; contact pre-fill in `StartMarketplaceConversationController`; the banner (marketplace + own sell/swap list) incl. reading several hints in one query. |

Boundaries: W never edits the event/edition pages or the marketplace component; R never edits the marketplace or sell-swap
code; M never edits the join flow, the event pages or the chat action template.

## Rules

| Subject | Rule |
|---|---|
| Who can mark | The listing's seller with an **active membership** (checked in the controller, like the rest of sell/swap), only for qualifying events they are going to, only published listings. Handlers enforce qualifies + going + ownership + `marketplaceBanned`. |
| Unticking | Removes the row. The listing form only touches rows of currently qualifying events the seller goes to (history rows of past events stay). |
| Bring + reserve (chat) | Creates the row if missing (idempotent) and reserves the listing for the buyer with the same side effects as `MarkListingAsReservedHandler`. |
| F1 / F2 | Only for qualifying events. F1: active membership AND ≥1 published listing. F2: everyone else, flash `EventJustJoined::FLASH`. Online / non-qualifying: today's redirect. |
| Labels outside the event filter | "Bringing to …" for everyone; "Seller goes to …" only for a viewer going to that same event. |
| Shipping filters | Ignored while an event is chosen. |
| Privacy & blocks | Seller avatars/rows embed `HiddenPlayers::sqlExclude()` (the viewer's blocks; applied before the LIMIT of the avatar row) and the event page is in `BlocklistCanaryTest`. Private sellers are shown like on the marketplace and in the participant list of the same page (the player acts in public - see `docs/features/private-profile-allow-list.md`, "left as is, deliberately"), so the event page is not in `PrivateProfileCanaryTest`. Counts are aggregates and stay unfiltered. |
| Guests | See everything; "Ask to bring it" requires sign-in like "Contact seller". |
| SEO | Marketplace with `?event=` → `noindex, follow`. |

## Performance budget

| Page | Extra queries |
|---|---|
| Event / edition page, not a qualifying event | 0 |
| Event / edition page, qualifying | 1 (`GetEventOffers::summary()`, viewer line included) |
| Marketplace | +1 (event select options, cached 10 min, viewer-independent); labels + viewer's events inside the existing listing statement; the new hint read together with the disclaimer hint (no extra query) |
| Marketplace with an event | 0 beyond the above (EXISTS + `bringing` sort key in the same statements) |
| Listing add/edit form | 1 (`GetMarketplaceEvents::forPlayer`) |
| Join redirect | ≤1 (published-listing EXISTS, only for members on qualifying events) |
| Picker | 2 |
| Chat with a listing (seller) | 1 |
| Everything else | 0 — nothing rides on `PlayerProfile` |

Never a query per row. Query-count tests for the marketplace (with/without event, 1 vs many offers) and the event page
(qualifying vs online). Every new statement gets `EXPLAIN ANALYZE` on a production-sized clone before merge.

## Copy (English source)

Plurals: Symfony pipe syntax with **one** `%count%` per message. Sentences with several numbers pluralise on `%count%`
and take the other numbers as separately translated phrases (own keys, so each language can inflect them for that
sentence). Event names and dates are parameters, never translated text.

```yaml
marketplace_events:
    label:
        bringing_to: "Bringing to %event% · %date%"
        bringing: "Bringing"
        seller_goes_to: "Seller goes to %event% · %date%"
        reserved_for_you: "Reserved for you · %event% · %date%"
        ask_to_bring: "Ask to bring it"
    card:
        title: "Thinking about buying, selling or swapping a puzzle here? Great!"
        title_seller_empty: "Thinking about selling or swapping a puzzle here? Great!"
        title_just_joined: "Fancy taking a new puzzle home from the event?"
        # %count% = sellers going; %puzzles% = card.puzzles_phrase; %more% = card.more_phrase
        body_both: "%count% puzzler going to this event is bringing %puzzles%, and can bring any of %more% if you ask. Meet in person, no shipping.|%count% puzzlers going to this event are bringing %puzzles%, and they can bring any of %more% if you ask. Meet in person, no shipping."
        body_bringing_only: "%count% puzzler going to this event is bringing %puzzles%. Meet in person, no shipping.|%count% puzzlers going to this event are bringing %puzzles%. Meet in person, no shipping."
        body_ask_only: "Nobody has packed anything yet, but %count% puzzler going has %more% you can ask to bring. Meet in person, no shipping.|Nobody has packed anything yet, but %count% puzzlers going have %more% you can ask them to bring. Meet in person, no shipping."
        body_just_joined_suffix: "Find one you like and ask the seller to keep it for you."
        body_seller_empty: "Nobody has marked puzzles for this event yet. Choose what you'll bring, and people going will see it here before the event."
        puzzles_phrase: "%count% puzzle|%count% puzzles"
        more_phrase: "%count% more puzzle|%count% more puzzles"
        see_whats_coming: "See what's coming"
        choose_puzzles: "Choose puzzles"
        you_bring: "You're bringing %count% puzzle.|You're bringing %count% puzzles."
        change: "Change"
        nothing_marked: "Bringing any puzzles?"
        choose_what_you_pack: "Choose what you'll pack"
        seller_not_going: "Going too? Click “I'm going” and you can bring puzzles from your list."
        seller_empty_not_going: "Click “I'm going” first, then choose what you'll bring."
        non_member_line: "Want to sell or swap your own puzzles here too? That comes with %link%."
        non_member_link: "membership"
        member_no_listings: "Have puzzles to sell or swap? %link%"
        member_no_listings_link: "Add them to your sell/swap list."
    picker:
        title: "What will you bring to %event%?"
        joined_title: "You're going to %event%!"
        joined_text: "You're on the participant list."
        optional: "Optional"
        joined_heading: "Bringing any puzzles to sell or swap?"
        intro: "Tick the ones you'll pack. People going will see them, and they can ask you about anything else on your list. You can change this any time."
        search: "Search your %count% offer…|Search your %count% offers…"
        select_all: "Select all"
        clear: "Clear"
        selected: "%count% selected"
        skip: "Skip for now"
        save: "Save"
        cancel: "Cancel"
        also_at: "Also at %event%"
        no_listings: "You have no published offers yet."
        saved: "Done! %count% puzzle is marked for %event%.|Done! %count% puzzles are marked for %event%."
        saved_none: "Saved. Nothing is marked for %event%."
    form:
        field_label: "I'm bringing it to"
        reminder: "Heading to a puzzle event? Click “I'm going” on its page and you can bring this puzzle along. People there can pick it up from you in person, no shipping."
        reminder_going: "Going somewhere else too? Click “I'm going” on the event's page and it appears here."
        upcoming_events: "Upcoming events"
    chat:
        prefill: "Hi! Could you bring %puzzle% to %event% (%date%)? I'd love to buy it."
        bring_to_event: "Bring to event"
        bring_it: "Bring it to the event"
        bring_and_reserve: "Bring it and reserve it for %name%"
        other_going_too: "%name% is going too"
        brought: "Noted, you're bringing %puzzle% to %event%."
    marketplace:
        filter_label: "Pick up at an event"
        any_event: "Any event"
        coming: "%count% coming"
        to_ask: "%count% to ask"
        context_hint: "Pick up in person, shipping filters are off"
        only_bringing: "Only what they're bringing"
        divider: "Not packed yet · these sellers are going, ask them to bring it (%count%)"
    banner:
        seller_title: "Going to a puzzle event? Bring your puzzles along!"
        seller_text: "Click “I'm going” on the event's page and choose what you'll pack. People there can buy or swap with you in person, and ask you to bring anything else from your list."
        seller_button: "Upcoming events"
        going_title: "You're going to %event% on %date%."
        going_text: "Packing any puzzles? Choose which offers you'll bring, so people see them before the event."
        going_button: "Choose puzzles"
        buyer_title: "Looking for a puzzle at %event%?"
        buyer_text: "%count% is coming with puzzlers who are going. Have a look before you go.|%count% are coming with puzzlers who are going. Have a look before you go."
        buyer_button: "See what's coming"
```

## Testing notes

- Fixtures: see `.claude/fixtures.md`. Qualifying events need `is_online = false`, a date ≥ the test clock's today, and
  visibility. Add fixture rows only where needed and document them there.
- Worker mode: any service caching in properties implements `ResetInterface`.
- Full-page form POSTs never answer 200 (redirect or 422).

## As built (2026-10-03)

Phase 0 (`b3f54eb3`, on `main` before the feature): editions got the "I'm going" block (`_event_attendance.html.twig`,
`GetEventAttendance`), join/leave return through `CompetitionDetailUrl` (never `event_detail` with an edition slug).

| Area | Classes / templates |
|---|---|
| Write | `ChooseEventOffersHandler` (sync of one player × event pair, unknown ids dropped, unpublished never linked), `BringListingToEventHandler` (link + optional reserve for a conversation partner, never re-assigns a listing reserved for someone else), `Services\ListingEventLinks` (the listing form's `eventIds` sync - only currently qualifying events, history rows stay), `Services\ListingReservation` (reserve + `ListingReserved` system messages, shared with `MarkListingAsReservedHandler`). |
| Picker (F1 + editor) | `EventOffersPickerController` (`event_offers_picker`, GET/POST, stateless CSRF id `event_offers_picker`), `GetEventOfferChoices::forPicker()` (one statement: listings + this event's state + "also at"), `templates/marketplace/event_offers_picker.html.twig`, lazy `event_offers_picker_controller.js` (client-side search, select all/clear on the visible rows, counter via `browser_translation()`). |
| Join flow | `JoinCompetitionController::afterJoin()`: members with a published listing → picker `?joined=1` (no generic flash, the picker confirms); everyone else on a marketplace event → `EventJustJoined::FLASH` + the event page; switching the claimed participant is not a new join. `GetMarketplaceEvents::joinFollowUp()`. |
| Event card (E2) | `GetEventOffers::isMarketplaceEvent()` (PHP, no query) + `summary()` (one statement: counts, 4 avatars, the viewer's line), `Services\EventJustJoinedFlash`, `templates/_event_offers.html.twig` on `event_detail` and `edition_detail`. |
| Marketplace | `GetMarketplaceListings` (`event`, `onlyBringing`, `bringing` sort key, `countParts()`, labels `bringingTo` / `sellerGoesTo` via LATERAL joins on the page's rows only), `GetEventsWithSellersGoing` (cache pool `marketplace_events_cache`, 10 min, key per day), `MarketplaceListing` URL props `event` + `onlyBringing` + `clearEvent` action, `noindex, follow` with `?event=`. |
| Chat | "Ask to bring it" → `start_marketplace_conversation?event=…` pre-fills `marketplace_events.chat.prefill` in the buyer's locale. Seller action `sell_swap_bring_to_event` (`POST /en/sell-swap/{itemId}/bring-to-event`, CSRF id `sell_swap_bring_to_event`) in its own container `#conversation-bring-to-event-{itemId}` (`_conversation_bring_to_event.html.twig`) so the reserve streams don't wipe it; `GetMarketplaceEvents::forListingSeller()` (the "X is going too" flag in the same statement). |
| Banner | `GetMarketplaceEventsHintState` + `Value\MarketplaceEventsBanner` + `templates/marketplace/_events_banner.html.twig`; seller states need an active membership; no buyer banner while already filtering by that event; `IsHintDismissed::dismissedAmong()` reads the disclaimer + the events hint in one query. |
| Dates | `Services\EventDateFormatter` + Twig `event_dates(from, to)` - locale-aware short ranges ("Oct 10–11", "10.–11. 10.", "10月10日–11日") for every new label. |

Choices beyond the contract: "Ask to bring it" is not offered on a listing reserved for someone else; guests get a
sign-in link instead of it; the F1 path skips the generic join flash; private sellers are shown (see Rules).

### Measured on a production clone (2026-10-03)

WJPC 2026 moved into the future in the clone: 133 players going, 22 sellers, 479 published offers, 329 marked. Warm
`EXPLAIN ANALYZE`:

| Statement | Time |
|---|---|
| `GetMarketplaceEvents::forPlayer()` | 0.15 ms |
| `GetEventOffers::summary()` (after ranking the avatars before joining `player`; was 10.9 ms with a seq scan of 11k players) | ~2 ms |
| Marketplace page, 21 rows, signed-in viewer, both labels | 11–13 ms (the same page without labels: 13–22 ms - no measurable cost; the page itself hash-joins all puzzles, pre-existing) |
| Marketplace count with an event (both parts) | 4.2 ms |
| Picker, seller with 86 offers | 3.3 ms |

No index beyond the migration's (`PRIMARY KEY (sell_swap_list_item_id, competition_id)` + `competition_id`) was needed:
every new statement starts from `competition_participant(competition_id)`, `sell_swap_list_item(player_id)` or the
page's rows.

### Follow-ups

See `docs/TODO.md`, section "Marketplace at events".
