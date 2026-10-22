# Partner finder (pairs and teams)

Feature request: https://myspeedpuzzling.com/en/feature-requests/019e6d4a-d7c7-735d-a003-4d7c69e98ebd · issue #283 ·
proposal with mockups: https://claude.ai/artifact/6iA6NVEmPFCbe5jfSaeBq6

Puzzlers going to an in-person event list themselves as looking for a pair partner, a team, or more people for their
group. Others browse the listings of that event, filter them (kind, country, "good at"), and send "I'd like to puzzle with
you". The listed puzzler gets an in-app notification and an e-mail naming who it is; nobody's e-mail address is ever
shared, a reply opens a chat. **Free for everyone**; members see more about speed.

## Jan's decisions (2026-10-22, don't re-litigate)

| # | Decision |
|---|---|
| D1 | A listing answers two questions: **"I am"** on my own / a group of N (optional registered co-members) and **"Looking for"** a pair partner and/or a team (multi-select). A group says "we need M more". Offered kinds come from the event's rounds (`competition_round.category` duo → pair, team → team, `team_size` shown); an event without rounds offers both; an event whose rounds are all solo has no finder. |
| D2 | Browsing is open. **Listing marks you going in the same step** (plain events). Events with managed registration: listing allowed **without a spot** (pairs often can't register without a partner) - the row shows "Registered" / "Not registered yet"; waitlisted may list. Sending a request also marks the sender going on plain events. Leaving the event closes the listing. |
| D3 | "Good at" = preset chips (`PartnerStrength`, translated, ≤ 4) **plus up to 5 custom chips** (≤ 30 chars, shown as typed, never translated, dedup by `SearchText::fold()`; a custom chip folding equal to a preset label in any of the 6 locales becomes that preset). Custom chips on ≥ 2 open listings of the event join the filter row. Plus languages (≤ 4 ISO 639-1 codes), goal (`PartnerGoal`), one note ≤ 200. |
| D4 | Signed-in viewers see best 500 (else best 1000) + puzzles solved (`community_player_stats`) + MSP rating when on the ladder. **Members** also see the skill tier, a "Similar to you / Faster / Slower" badge and the "Closest to my speed" sort. Ranking opt-out = no rating, no tier. Non-members see the locked tier chip. |
| D5 | Guests see a **teaser** only (counts per kind, flags per country, faces) + "Sign in to see who". Signed-in see every listing. Page `noindex`, not in the sitemap. Private players may list: name, flag and listing shown, no stats, no profile link (unless `PrivateProfileAccess` reveals them). Blocks hide both ways. |
| D6 | "I'd like to puzzle with you" = a `partner_request` (kind + optional note ≤ 200) → in-app notification + **async** e-mail naming who. The receiver's **Reply** starts a conversation from their side (works with direct messages off). The sender only ever sees "Sent". One request per listing per sender; rate limiter 10/day; silent when blocked (the sender still sees "Sent"). New switch `player.partner_finder_emails` (default on) with signed one-click unsubscribe. |
| D7 | "Found my partner" closes the listing (a group with `needed > 1` counts down instead). Auto-close: leaving the event, the event over (daily cron), being entered in a pair/team entry of the event. |
| D8 | The event page card is dismissed **once for good** (`HintType::PartnerFinderCard`); the "Find a partner (N)" button always stays. No card for a listed player (status line instead) or a player already in a pair/team entry. |
| D9 | After "I'm going": the marketplace "What I bring" picker keeps priority; everybody else gets the partner card highlighted (the `EventJustJoined` flash). |
| Perf | Jan: "keep in mind the performance" - budgets below are pinned by tests. |

## Data model

`partner_listing` (`Entity\PartnerListing`)

| column | type | notes |
|---|---|---|
| id | uuid | UUIDv7, client-generated in the form (a resend is a no-op) |
| competition_id | uuid FK → competition, CASCADE | one-time event or series edition |
| player_id | uuid FK → player, CASCADE | |
| group_size | smallint | 1 = on my own, 2..20 = a group |
| looking_for | jsonb | list of `PartnerKind` values (`pair`, `team`), ≥ 1 |
| needed | smallint null | groups only: how many more people |
| co_member_ids | jsonb | registered players in the group (≤ group_size - 1) |
| goal | string null | `PartnerGoal`: `top_result` / `either` / `fun_first` |
| strengths | jsonb | `PartnerStrength` values, ≤ 4 |
| custom_strengths | jsonb | strings, ≤ 5, each ≤ 30 |
| languages | jsonb | ISO 639-1 codes, ≤ 4 |
| note | varchar(200) null | |
| created_at, updated_at | timestamptz | |
| closed_at | timestamptz null | null = open |
| closed_reason | string null | `PartnerListingClosedReason`: `found` / `withdrawn` / `not_going` / `event_over` / `entered` |

`partner_request` (`Entity\PartnerRequest`): id, listing_id (CASCADE), from_player_id (CASCADE), kind (`PartnerKind`),
note varchar(200) null, created_at, replied_at null. Unique `(listing_id, from_player_id)`.

`notification.target_partner_request_id` (CASCADE) + `NotificationType::PartnerRequest`, actor = the sender.
`player.partner_finder_emails` bool default true.

Indexes (custom, `docs/database-indexes.md`): `custom_partner_listing_one_open` unique `(competition_id, player_id) WHERE
closed_at IS NULL`; `custom_partner_listing_open_by_event` `(competition_id) WHERE closed_at IS NULL`;
`partner_request (from_player_id, created_at)`.

## Shared names (the contract between the parts)

- Values: `PartnerKind`, `PartnerGoal`, `PartnerStrength`, `PartnerListingClosedReason`.
- Eligibility: `Query\PartnerFinderEligibility` - `SQL_QUALIFIES` (in person, dated, not over, publicly visible incl.
  drafts rule - built on `GetMarketplaceEvents::SQL_QUALIFIES`) + "offers pair or team" (`EXISTS` duo/team round, or no
  rounds at all); `offeredKinds(competitionId)`; PHP pre-check `mightQualify(...)` that needs no query (like
  `JoinCompetitionController::mightBeMarketplaceEvent`), pinned by a test against the SQL.
- Going: `Services\PartnerFinder\PartnerFinderAttendance::ensureGoing(Competition, Player)` - plain events: the same
  effect as `JoinCompetitionHandler` self-join (shared code, no nested dispatch); managed registration: nothing. Callers
  are `SerializedByLock` on `CompetitionParticipantsLock`.
- In a pair/team entry: `PartnerFinderEligibility::sqlPlayerEntered($competitionIdSql, $playerIdSql)` = EXISTS a
  not-deleted `competition_participant` of the player in the competition with a `competition_participant_round.team_id`.
- Strengths: `Services\PartnerFinder\PartnerStrengthNormalizer::normalize(list<string> $presets, list<string> $custom)`
  → `PartnerStrengthsNormalized` (throws `Exceptions\PartnerListingInvalid`), label lookup in
  `PartnerStrengthCatalogue` (all 6 locales, built lazily once per process, `ResetInterface` not needed - deterministic).
- Routes (all 6 locales):
  - `event_partners` `/en/events/{slug}/partners` and `edition_partners` `/en/series/{seriesSlug}/{editionSlug}/partners`
    (slug + edition redirect + draft rules like the round results pages)
  - `partner_listing_form` GET/POST `/en/partners/{competitionId}/list-me` (modal on `Turbo-Frame: modal-frame`, else page)
  - `partner_listing_close` POST `/en/partners/listing/{listingId}/close` (`reason` = found / withdrawn)
  - `partner_request_send` POST `/en/partners/listing/{listingId}/request`
  - `partner_request_reply` POST `/en/partners/request/{requestId}/reply`
  - `partner_finder_emails_unsubscribe` (signed, one click, like `digest_emails_unsubscribe`)
- CSRF: stateless tokens `partner_listing`, `partner_request`.
- Translations: `messages` → `partner_finder.page.*`, `partner_finder.form.*`, `partner_finder.listing.*` (part A),
  `partner_finder.card.*`, `partner_finder.events_tag.*` (part B), `partner_finder.request.*`,
  `partner_finder.notification.*`, `partner_finder.settings.*` (part C), `partner_finder.strength.*`,
  `partner_finder.goal.*`, `partner_finder.kind.*` (foundation); `emails` → `partner_request.*` (part C). Build in
  English; all 6 locales at the end.
- Styles: `assets/styles/_partner-finder.scss` (page + listing, part A), `assets/styles/_partner-finder-card.scss`
  (event page card, part B), both imported from `app.scss` by the foundation.

## Performance budgets (pinned by tests)

| Surface | Guest | Signed-in |
|---|---|---|
| `/en/events` | +0 (`listing_count` column on `GetEventGoingCounts`) | +0 |
| event / edition page, cannot qualify (PHP pre-check) | +0 | +0 |
| event / edition page, qualifying | +0 (plain "Find a partner" link, no count) | ≤ +1 (`GetPartnerCardData`: count, faces, own listing, entered) - hint dismissed rides on `IsHintDismissed::dismissedAmong` |
| finder page | 1 teaser statement + the event load | exactly 1 listings statement + the event load |

The listings statement: CTE of open listings for the event with `HiddenPlayers::sqlExclude()` and
`PrivateProfileAccess::sqlIsPrivate()` inside, `LIMIT 100` newest first, then primary-key joins to
`community_player_stats`, `player_elo` (500 pieces), `player_skill` (members only, else NULL in SQL), and an `EXISTS` on
`partner_request` for the viewer's "sent". Filtering, sorting and the custom filter chips run over the loaded rows (PHP +
one Stimulus controller) - no further statements. E-mails are sent by an async message, never in the request.
Pair/team result counts per puzzler are **phase 2** (they need precomputed columns on `community_player_stats`).

## Delivery (who owns what)

- **Foundation**: entities, values, repositories, migration + custom indexes, `PartnerFinderEligibility`,
  `PartnerFinderAttendance`, strength normalizer + catalogue, `HintType`/`NotificationType` cases, `player` column,
  shared fixtures, empty SCSS partials, the `partner_finder.strength|goal|kind` copy.
- **Part A - listing + finder page**: `ListForPartner`, `EditPartnerListing`, `ClosePartnerListing` (+ group countdown),
  `GetPartnerListings` (listings + teaser), finder controllers/templates, listing form (modal), `partner_finder_controller.js`
  (filter/sort rows), `partner_strengths_controller.js`, country select options from loaded rows, canaries + coverage +
  `PartnerFinderQueryBudgetTest`. Renders the request button posting to `partner_request_send` and the Sent state.
- **Part B - event pages + agenda**: card `templates/event_parts/_partner_card.html.twig` (4 states), "Find a partner (N)"
  button, `GetPartnerCardData`, `listing_count` on `GetEventGoingCounts` + `RowTagType::PartnerListings`, `afterJoin()`
  flash, `HintType` dismissal, detail/events budget tests.
- **Part C - contact + lifecycle**: `SendPartnerRequest`, async `SendPartnerRequestEmail`, notification type rendering,
  e-mail template + unsubscribe + settings switch, rate limiter `partner_request`, `ReplyToPartnerRequest` +
  `StartConversation` bypass (`partnerRequestId`, ownership checked in the handler), close on `LeaveCompetition`, cron
  `myspeedpuzzling:close-partner-listings` (event over + entered).

## Phase 2 (docs/TODO.md)

"Close yours too?" for the people you teamed up with, a reminder 14 days before the event, a line for followers of the
event, the "N pairs found here" count, pair/team result counts on the cards, a cross-event finder, API v1 read access.

## Foundation as built (2026-10-22)

What the parts can rely on. Migration `Version20261022172651`; fixtures in `.claude/fixtures.md` "Partner finder".

**Values** (`src/Value/`): `PartnerKind` (`pair`, `team`; `fromRoundCategory(RoundCategory): ?self` - solo → null;
`translationKey()`), `PartnerGoal` (`top_result`, `either`, `fun_first`; `translationKey()`), `PartnerStrength` (10 cases;
`translationKey()` = `partner_finder.strength.<value>`), `PartnerListingClosedReason` (`found`, `withdrawn`, `not_going`,
`event_over`, `entered`). `HintType::PartnerFinderCard`, `NotificationType::PartnerRequest`.

**Entities**
- `PartnerListing` - constructor `(UuidInterface $id, Competition $competition, Player $player, int $groupSize,
  list<PartnerKind> $lookingFor, ?int $needed, list<string> $coMemberIds, ?PartnerGoal $goal,
  PartnerStrengthsNormalized $strengths, list<string> $languages, ?string $note, DateTimeImmutable $createdAt)`;
  `edit(` same values from `$groupSize` to `$note`, `DateTimeImmutable $now)`; `close(PartnerListingClosedReason, $now)`
  (closing a closed listing keeps the first reason); `reduceNeeded($now)` (a group with `needed > 1` counts down, anything
  else closes as `found` - this is "Found my partner"); `isOpen()`, `isGroup()`; typed getters `lookingFor()`,
  `strengths()`, `customStrengths()`, `languages()`, `coMemberIds()`; public read-only props `groupSize`, `needed`, `goal`,
  `note`, `createdAt`, `updatedAt`, `closedAt`, `closedReason`. Constants `GROUP_SIZE_MAX` (20), `MAX_LANGUAGES` (4),
  `NOTE_MAX_LENGTH` (200).
  Rules checked in the entity (before anything changes, throws `PartnerListingInvalid`): group size 1..20; ≥ 1 kind; **a
  group (≥ 2) never looks for a pair**; on my own → `needed` null, a group → `needed` ≥ 1 and `groupSize + needed` ≤ 20;
  co-members valid UUIDs, lower-cased, deduplicated, the player himself dropped, ≤ `groupSize - 1`; languages
  `/^[a-z]{2}$/` after trim + lower case, deduplicated, ≤ 4; note trimmed, `''` → null, ≤ 200; ≤ 4 presets / ≤ 5 customs.
  **Not** checked there (the handler's job): the event offers the kinds (`PartnerFinderEligibility`), co-members exist /
  are not blocked, language codes are real ISO 639-1 codes.
- `PartnerRequest` - `(UuidInterface $id, PartnerListing $listing, Player $fromPlayer, PartnerKind $kind, ?string $note,
  DateTimeImmutable $createdAt)` (note trimmed, `''` → null, > 200 = `InvalidArgumentException` - validate in the form);
  `markReplied($now)` (keeps the first moment), `isReplied()`. Unique `partner_request_listing_sender_unique`
  (listing_id, from_player_id); the per-sender index is a normal Doctrine index `partner_request_sender_created`
  (from_player_id, created_at), not a custom one.
- `Notification::$targetPartnerRequest` (CASCADE), `Player::$partnerFinderEmails` + `changePartnerFinderEmails(bool)`.
- Timestamps are `DATETIME_IMMUTABLE` (timestamp without time zone, UTC) like every other entity, not timestamptz.

**Repositories**: `PartnerListingRepository::save()`, `get(string): PartnerListing` (throws `PartnerListingNotFound`,
404), `findOpenFor(string $competitionId, string $playerId): ?PartnerListing`. `PartnerRequestRepository::save()`,
`get(string): PartnerRequest` (throws `PartnerRequestNotFound`, 404), `findFor(string $listingId, string $fromPlayerId):
?PartnerRequest`. Persist only.

**`Exceptions\PartnerListingInvalid`** (extends `UnprocessableEntityHttpException`, 422): `$field` (one of `FIELD_STRENGTHS`,
`FIELD_CUSTOM_STRENGTHS`, `FIELD_GROUP_SIZE`, `FIELD_NEEDED`, `FIELD_LOOKING_FOR`, `FIELD_CO_MEMBERS`, `FIELD_LANGUAGES`,
`FIELD_NOTE`), `$translationKey` (`partner_finder.invalid.*`, all 6 locales), `$translationParameters`,
`trans(TranslatorInterface)`. Attach it to the form field named by `$field`.

**`Query\PartnerFinderEligibility`**
- `SQL_QUALIFIES` = `GetMarketplaceEvents::SQL_QUALIFIES` AND `SQL_OFFERS_PAIR_OR_TEAM` (aliases `c` + `cs` LEFT JOINed on
  `cs.id = c.series_id`; binds `:marketplace_today` - pass `todayParameter()`).
- `todayParameter(): array{marketplace_today: string}`
- `static sqlPlayerEntered(string $competitionIdSql, string $playerIdSql): string` - `EXISTS` a not deleted
  `competition_participant` of the player with a `competition_participant_round.team_id IS NOT NULL`.
- `mightQualify(CompetitionEvent $competition, list<RoundCategory> $roundCategories): bool` - no query; false = never a
  finder (online, undated, over, draft, rejected, unapproved one-time event, all rounds solo). The event pages already
  load their rounds (`EditionRoundDetail::$category`). Pinned against the SQL by `PartnerFinderEligibilityTest`.
- `qualifies(string $competitionId): bool`
- `offeredKinds(string $competitionId): Results\PartnerFinderOffer` - by the rounds alone, qualifying or not.
- `qualifyingOffer(string $competitionId): ?Results\PartnerFinderOffer` - both in one statement, null = no finder.
- `Results\PartnerFinderOffer`: `list<PartnerKind> $kinds` (PartnerKind order), `?int $teamSize` (largest `team_size` of the
  team rounds, null when none set or no team kind), `offersAny()`, `offers(PartnerKind)`, static
  `fromRoundCategories(list<RoundCategory>, ?int $teamSize = null)` ([] = no rounds = both kinds).

**Going**: `Services\PartnerFinder\PartnerFinderAttendance::ensureGoing(Competition, Player): bool` (true = made going
now). Managed registration → nothing; **already connected to any row of the event (own or the organiser's) → nothing**
(unlike "I'm going", which lets an organiser's row go - we never take a player off the organiser's list). Otherwise exactly
JoinCompetitionHandler's self-join: the shared code is `Services\CompetitionSelfJoin` (`takeOwnRow()`, `handOwnLink()`,
`releaseOtherParticipants()`), which `JoinCompetitionHandler` now calls too. The caller checks eligibility (visibility
included) first. `SerializedByLockMessagesTest` detects `PartnerFinderAttendance` as a participant writer: every message
whose handler uses it must be `SerializedByLock` with `CompetitionParticipantsLock::key($this->competitionId)` and have a
`competitionId` property. Whether somebody is going: `GetMarketplaceEvents::isPlayerGoing()` / `sqlPlayerGoing()`.

**Strengths**: `Services\PartnerFinder\PartnerStrengthNormalizer::normalize(list<string> $presets, list<string> $custom):
Results\PartnerStrengthsNormalized` (`list<PartnerStrength> $presets`, `list<string> $custom`, `presetValues()`, constants
`MAX_PRESETS` 4, `MAX_CUSTOM` 5, `MAX_CUSTOM_LENGTH` 30). Presets are enum values (unknown → invalid); customs: whitespace
collapsed + trimmed, empty dropped, `<` `>` or control/format characters → invalid, > 30 characters → invalid, a label of
any locale or the value (also with `_` as a space) → that preset (appended after the picked ones, once), deduplicated by
`SearchText::fold()` keeping the first spelling; over the limits → invalid. `PartnerStrengthCatalogue::find(string):
?PartnerStrength` (lazy, once per process; loads all 6 catalogues - ~23 MB without opcache, shared memory with it).

**Translations**: each locale file ends with a `partner_finder:` block with one comment line per part - add your subtrees
right below your own line. Foundation keys: `strength.*`, `goal.*`, `kind.*` (Pair / Team), `invalid.*`.

**Styles**: `assets/styles/_partner-finder.scss` (part A), `_partner-finder-card.scss` (part B), imported after the
event-detail partials in `app.scss`.

## Part A as built (2026-10-22)

**Routes** (`src/Controller/PartnerFinder/`): `event_partners` (`EventPartnersController`; an edition's slug 301s to
`edition_partners`) and `edition_partners` (`EditionPartnersController`) in all 6 locales (cs `…/partneri`, es
`…/companeros`, fr `…/partenaires`, de `…/partner`, en/ja `…/partners`), GET only, sharing
`AbstractPartnerFinderPageController::renderFinder()`; a draft is a `DraftNotVisible` 404 for everybody (its team too -
it has no finder), an event without a finder `PartnerFinderNotAvailable` (404); `X-Robots-Tag: noindex` + `noindex,
follow`. `partner_listing_form` GET/POST `/{_locale}/partners/{competitionId}/list-me` (`PartnerListingFormController`:
modal on `Turbo-Frame: modal-frame` → success = close + `refresh` stream with the flash; full page → 303 to the finder;
`PartnerListingInvalid` → error on the field it names, 422). `partner_listing_close` POST
`/{_locale}/partners/listing/{listingId}/close` (`ClosePartnerListingController`, stateless CSRF `partner_listing`,
`reason` = `found` | anything else = withdrawn, `return` validated by `ReturnUrl`, PRG with a flash). URLs of an event's
finder/page: `Services\PartnerFinder\PartnerFinderUrls`.

**Messages**: `ListForPartner` (`SerializedByLock` on `CompetitionParticipantsLock::key($competitionId)`; calls
`PartnerFinderAttendance::ensureGoing()` after saving), `EditPartnerListing` (`coMemberIds: null` = keep),
`ClosePartnerListing` (owner only, `found` → `reduceNeeded()`, `withdrawn` → `close()`). Checks before anything changes,
shared in `Services\PartnerFinder\PartnerListingRules`: the event qualifies (`qualifyingOffer()`), every kind is offered
(else `PartnerListingInvalid::lookingFor()` - the form offers only the event's kinds), strengths
(`PartnerStrengthNormalizer`), co-members (`Query\GetAllowedPartnerCoMembers`: exist, no block either way, public or
the lister on their allow list - else `FIELD_CO_MEMBERS`), languages (`Symfony\Component\Intl\Languages::exists()`, two
letters). **Decisions**: the same listing id again = no-op (a resent form); "List me" with another id while an open
listing exists **edits the open one** (two tabs, an old form - the newest values win) instead of failing on the unique
index. Both throw `PartnerListingInvalid` (unwrapped by `UnwrapHttpExceptionMiddleware`) - catch it directly.

**Read model** `Query\GetPartnerListings` (statement counts pinned by `PartnerFinderQueryBudgetTest`: guest 2 = the event
by slug + the teaser; signed in 6 = the event + the listings + the 4 statements of every signed-in request):
- `forEvent(string $competitionId, string $viewerId, bool $viewerIsMember): ?Results\PartnerListings` - ONE statement:
  `event` CTE (`PartnerFinderEligibility::SQL_QUALIFIES` + the rounds' categories/team size) → `listing` CTE (open,
  `HiddenPlayers::sqlExclude()` + "the lister blocks the viewer", `PrivateProfileAccess::sqlIsPrivate()` as
  `private_to_viewer`, own first then newest, `LIMIT 100`, `COUNT(*) OVER ()` = `totalOpen`) → joins
  `community_player_stats` (best 500/1000, solved - NULL for a masked private player), `player_elo` 500 (NULL when opted
  out), `player_skill` 500 **only for members** (else `NULL` and the table is not joined), registered (the going rule,
  managed registration only), `sent` (EXISTS), `requests_count` (own row only), co-members as JSON (masked/blocked left
  out), the viewer's own best 500. null = no finder.
- `teaserForEvent(string $competitionId): ?Results\PartnerFinderTeaser` - ONE statement: total, pairs / teams (on their
  own), groups, per country, 8 newest faces (avatar only when public). No names, notes or links reach guest HTML.
- `Results\PartnerListings`: `others()`, `ownRow()`, `isCapped()`, `kindCounts()`, `countryCounts()`,
  `strengthFilters()` (presets at least one other listing has, in `PartnerStrength` order + custom chips of ≥ 2 listings
  folded equal, first spelling), `customChipSuggestions()`. `Results\PartnerListingRow::speedComparedTo($viewerBest500)`
  = `similar` (±10 %) / `faster` / `slower`, `speedDistance()` for "Closest to my speed" - both in PHP, members only.

**Page** (`templates/partner_finder/`): `finder.html.twig` (crumbs, header + "List me", own listing under "Your listing",
`_filters.html.twig`, rows, "Showing the newest 100 of N", privacy line), `_teaser.html.twig`, `_listing_row.html.twig`,
`_listing_action.html.twig` (`<turbo-frame id="partner-listing-{id}">`: own = "N got in touch" + Edit / Found my partner
(Found one more for a group needing > 1) / Remove; others = Going | Registered | Not registered yet + `✓ Sent` or a
`<details>` "I'd like to puzzle with you" panel posting `_token` / `kind` (radios only for two kinds) / `note` (≤ 200) /
`return` to `partner_request_send`). Row data attributes for `assets/controllers/partner_finder_controller.js`:
`data-kinds` (`pair` / `team` on one's own, `group`), `data-country` (lower case), `data-strengths` (preset values +
`c:` + 12 hex of md5 of the folded custom chip - `PartnerListingRow::customKey()`), `data-order` (server order),
`data-best500`, `data-best1000` (seconds, empty = unknown), `data-distance` (members). The own row carries none (it is
never filtered). The country filter is the site's `country-select` with only the countries present ("Name (count)").
Non-members see the tier as the lock (`#membersExclusiveModal`) and "Closest to my speed" disabled with a Members badge.

**Form** `FormType\PartnerListingFormType` / `FormData\PartnerListingFormData` + `_listing_form.html.twig` (modal
`_listing_form_modal.html.twig`, page `listing_form.html.twig`): hidden `listingId` (UUIDv7, kept on a 422), "I am" (only
when the event has a team round), group size + "we need" (prefilled `teamSize - 2`), "Looking for" (offered kinds only;
`partner_listing_form_controller.js` turns "a pair partner" off for a group), goal, preset chips (≤ 4), "your own words"
= one comma-separated text input (`partner_strengths_controller.js` makes chips of it; suggestions = the event's chips
from the finder page's `data-partner-finder-custom-chips` + preset labels), languages (TomSelect, ≤ 4, prefilled from
`CountryLanguage` + English), note with counter, consent line (going / managed registration / edit).

**Deviations**: no co-member picker in the form yet (`docs/TODO.md`) - the messages and checks support co-members, an edit
keeps them. Styles only in
`_partner-finder.scss`; it reuses the player card's tier chips (`players-card-chip*`).
