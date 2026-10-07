# Test Fixtures Documentation

This document describes the test data structure defined in `tests/DataFixtures/`.

## Players (5 total)

| Const | Name | Email | Location | Special Attributes |
|-------|------|-------|----------|-------------------|
| `PLAYER_REGULAR` | John Doe | player1@speedpuzzling.cz | Prague, CZ | Regular user, no membership |
| `PLAYER_PRIVATE` | Jane Smith | player2@speedpuzzling.cz | New York, US | Private profile (`isPrivate: true`) |
| `PLAYER_ADMIN` | Admin User | admin@speedpuzzling.cz | Brno, CZ | Admin (`isAdmin: true`) |
| `PLAYER_WITH_FAVORITES` | Michael Johnson | player3@speedpuzzling.cz | Berlin, DE | Has favorite players |
| `PLAYER_WITH_STRIPE` | Sarah Williams | player4@speedpuzzling.cz | London, GB | **Has active membership**, Stripe customer, public collection |

### User accounts
Every fixture player has a `user_account` row (`UserAccountFixture`) with the e-mail from the table above - `user_account.email` is the only place a player's address lives (the `player` table has no e-mail column since release 2 of the single-source-of-truth change). The rows mirror an Auth0 import (`legacy_auth0 = true`, verified, no password), the state `TestingLogin`/`TestingViewer` used to create on demand.

### User IDs
Fixture players use the `auth0|…` format of accounts imported from Auth0; new registrations get `msp|<uuid7>`.
- `PLAYER_REGULAR`: `auth0|regular001`
- `PLAYER_WITH_STRIPE`: `auth0|stripe005`

## Membership

**`PLAYER_WITH_STRIPE` and `PLAYER_ADMIN` have active membership:**

| Player | Stripe Subscription ID | Started | Billing Period Ends |
|--------|----------------------|---------|-------------------|
| PLAYER_WITH_STRIPE | `sub_test_123456789` | 60 days ago | 30 days from now |
| PLAYER_ADMIN | `sub_admin_123456789` | 60 days ago | 30 days from now |

PLAYER_WITH_STRIPE also has Stripe customer ID: `cus_test_123456789`

## Lent/Borrowed Puzzles

Most lent puzzles are **owned by `PLAYER_WITH_STRIPE`**:

| Const | Puzzle | Owner | Current Holder | Status | Notes |
|-------|--------|-------|----------------|--------|-------|
| `LENT_01` | PUZZLE_2000 (2000 pcs) | PLAYER_WITH_STRIPE | PLAYER_REGULAR | Active | "Handle with care" |
| `LENT_02` | PUZZLE_1500_01 (1500 pcs) | PLAYER_WITH_STRIPE | "Jane Doe" (non-registered) | Active | - |
| `LENT_03` | PUZZLE_1000_01 (1000 pcs) | PLAYER_WITH_STRIPE | - | **Returned** | "Returned in good condition" |
| `LENT_04` | PUZZLE_500_03 (500 pcs) | PLAYER_WITH_STRIPE | PLAYER_WITH_FAVORITES | Active (passed) | "For testing purposes" |
| `LENT_05` | PUZZLE_1500_02 (1500 pcs) | PLAYER_REGULAR | PLAYER_WITH_STRIPE | Active | - |
| `LENT_06` | PUZZLE_3000 (3000 pcs) | PLAYER_REGULAR | PLAYER_WITH_STRIPE | Active | - |
| `LENT_07` | PUZZLE_500_05 (500 pcs) | PLAYER_REGULAR | PLAYER_ADMIN | Active | "Merge test puzzle" |
| `LENT_08` | PUZZLE_500_04 (500 pcs) | PLAYER_REGULAR | PLAYER_WITH_FAVORITES | Active | "Deduplication test puzzle" |

### Transfer History

**LENT_01**: `PLAYER_WITH_STRIPE → PLAYER_REGULAR` (30 days ago)

**LENT_02**: `PLAYER_WITH_STRIPE → "Jane Doe"` (20 days ago)

**LENT_03** (returned):
1. `PLAYER_WITH_STRIPE → PLAYER_REGULAR` (45 days ago) - Initial lend
2. `PLAYER_REGULAR → PLAYER_WITH_STRIPE` (40 days ago) - Return

**LENT_04** (passed on):
1. `PLAYER_WITH_STRIPE → PLAYER_REGULAR` (10 days ago) - Initial lend
2. `PLAYER_REGULAR → PLAYER_WITH_FAVORITES` (5 days ago) - Pass

**LENT_05**: `PLAYER_REGULAR → PLAYER_WITH_STRIPE` (15 days ago) - Initial lend

## Collections

### Named Collections (Collection entity)

| Const | Name | Owner | Visibility | Description |
|-------|------|-------|------------|-------------|
| `COLLECTION_PUBLIC` | My Ravensburger Collection | PLAYER_WITH_STRIPE | Public | "All my favorite Ravensburger puzzles" |
| `COLLECTION_PRIVATE` | Wishlist | PLAYER_REGULAR | Private | "Puzzles I want to buy" |
| `COLLECTION_FAVORITES` | Completed Favorites | PLAYER_REGULAR | Private | - |
| `COLLECTION_STRIPE_TREFL` | My Trefl Collection | PLAYER_WITH_STRIPE | Public | "All my Trefl puzzles" |

### Collection Items Distribution

**COLLECTION_PUBLIC** (PLAYER_WITH_STRIPE): PUZZLE_500_01, PUZZLE_500_02, PUZZLE_1000_01, PUZZLE_1000_03, PUZZLE_1000_05, PUZZLE_300, PUZZLE_500_04, PUZZLE_500_05

**COLLECTION_PRIVATE** (PLAYER_REGULAR): PUZZLE_1500_01, PUZZLE_2000, PUZZLE_3000, PUZZLE_1500_02

**COLLECTION_FAVORITES** (PLAYER_REGULAR): PUZZLE_500_01, PUZZLE_500_02

**COLLECTION_STRIPE_TREFL** (PLAYER_WITH_STRIPE): PUZZLE_1000_04, PUZZLE_500_02, PUZZLE_1000_05

**General collection (no named collection / system collection):**
- PLAYER_REGULAR: PUZZLE_500_03, PUZZLE_1000_01, PUZZLE_1000_02
- PLAYER_WITH_STRIPE: PUZZLE_500_03, PUZZLE_1000_02, PUZZLE_1500_01, PUZZLE_2000, PUZZLE_500_02, PUZZLE_1500_02

## Connections Between Players

### Favorites
- `PLAYER_WITH_FAVORITES` favorites: `PLAYER_REGULAR`, `PLAYER_ADMIN`

### Team Solving
- `PLAYER_REGULAR` & `PLAYER_PRIVATE` are a pair (`puzzling_team`, unnamed) with two times: TIME_12 (PUZZLE_1000_01) and TIME_41 (PUZZLE_1000_03), both tracked by PLAYER_REGULAR. Nobody else has pair/team history. Note PLAYER_REGULAR blocks PLAYER_PRIVATE (`UserBlockFixture`)

### Comparison line-ups (`ComparisonSubjectFixture`)
Persistent "Compare" line-ups (`comparison_subject`, docs/features/player-comparison.md), ids `018d0013-…`:

| Const | Owner | Subject | Kind | Added |
|-------|-------|---------|------|-------|
| `REGULAR_SELF` | PLAYER_REGULAR (free) | PLAYER_REGULAR (self) | Solo | -5 days |
| `REGULAR_STRIPE` | PLAYER_REGULAR | PLAYER_WITH_STRIPE | Solo | -5 days |
| `STRIPE_SELF` | PLAYER_WITH_STRIPE (member) | PLAYER_WITH_STRIPE (self) | Solo | -4 days |
| `STRIPE_ADMIN` | PLAYER_WITH_STRIPE | PLAYER_ADMIN | Solo | -4 days |
| `STRIPE_REGULAR` | PLAYER_WITH_STRIPE | PLAYER_REGULAR | Solo | -2 days |
| `STRIPE_PAIR` | PLAYER_WITH_STRIPE | the PLAYER_REGULAR & PLAYER_PRIVATE pair (TIME_12's team) | Pairs | -1 day |

PLAYER_REGULAR's Solo line-up is exactly at the free cap (you + 1 other) - any further Solo add is "full" unless it swaps
`REGULAR_STRIPE`. PLAYER_ADMIN and PLAYER_WITH_FAVORITES start with empty line-ups. Rows sharing a second are ordered
by id, so `*_SELF` comes first. PLAYER_REGULAR cannot add the `STRIPE_PAIR` pair himself (he blocks PLAYER_PRIVATE).

### Lending Relationships
- `PLAYER_WITH_STRIPE` lends to: `PLAYER_REGULAR`, `PLAYER_WITH_FAVORITES`, "Jane Doe" (non-registered)
- `PLAYER_REGULAR` lends to: `PLAYER_WITH_STRIPE`

### Puzzle Pass Chain
- `PLAYER_WITH_STRIPE → PLAYER_REGULAR → PLAYER_WITH_FAVORITES` (PUZZLE_500_03)

## Sell/Swap Listings

Players with membership (`PLAYER_WITH_STRIPE` and `PLAYER_ADMIN`) have sell/swap items.

Seller settings: `PLAYER_WITH_STRIPE` has ISO currency **GBP** (listings eligible for schema.org structured-data offers), `PLAYER_ADMIN` has **custom** currency "Kč" (listings excluded from structured-data offers).

| Const | Puzzle | Owner | Type | Price | Condition | Reserved |
|-------|--------|-------|------|-------|-----------|----------|
| `SELLSWAP_01` | PUZZLE_500_01 | PLAYER_WITH_STRIPE | Sell | 25.00 | LikeNew | No |
| `SELLSWAP_02` | PUZZLE_500_02 | PLAYER_WITH_STRIPE | Swap | - | Normal | No |
| `SELLSWAP_03` | PUZZLE_1000_01 | PLAYER_WITH_STRIPE | Both | 45.00 | Normal | **Yes** |
| `SELLSWAP_04` | PUZZLE_500_03 | PLAYER_WITH_STRIPE | Sell | 15.00 | NotSoGood | **Yes** (for PLAYER_ADMIN) |
| `SELLSWAP_05` | PUZZLE_1000_02 | PLAYER_WITH_STRIPE | Swap | - | LikeNew | **Yes** |
| `SELLSWAP_06` | PUZZLE_1500_01 | PLAYER_WITH_STRIPE | Both | 60.00 | MissingPieces | No |
| `SELLSWAP_07` | PUZZLE_1000_03 | PLAYER_WITH_STRIPE | Sell | 35.00 | Normal | No |
| `SELLSWAP_08` | PUZZLE_500_05 | PLAYER_ADMIN | Sell | 20.00 | Normal | No |
| `SELLSWAP_09` | PUZZLE_500_04 | PLAYER_ADMIN | Swap | - | LikeNew | No |
| `SELLSWAP_10` | PUZZLE_500_01 | PLAYER_ADMIN | Both | 22.00 | Normal | No |
| `SELLSWAP_11` | PUZZLE_1000_01 | PLAYER_ADMIN | Sell | 40.00 | LikeNew | No |
| `SELLSWAP_12` | PUZZLE_1000_02 | PLAYER_ADMIN | Sell | 30.00 | Normal | **Yes** |
| `SELLSWAP_13` | PUZZLE_1500_01 | PLAYER_ADMIN | Sell | 30.00 | New | No |

### Puzzles with Multiple Offers
- **PUZZLE_500_01**: 2 offers (SELLSWAP_01 + SELLSWAP_10), none reserved
- **PUZZLE_1000_01**: 2 offers (SELLSWAP_03 reserved + SELLSWAP_11 not reserved) — mixed reservation status
- **PUZZLE_1000_02**: 2 offers (SELLSWAP_05 + SELLSWAP_12), **all reserved** — only-reserved puzzle

## Marketplace at events (`MarketplaceEventFixture`)

Sellers bringing listings to in-person events (`docs/features/marketplace/11-events.md`), ids `018d0014-…`. The roles:
**seller A** = `PLAYER_WITH_STRIPE` (member, SELLSWAP_01-07), **seller B** = `PLAYER_ADMIN` (member, SELLSWAP_08-13),
**buyer C** = `PLAYER_REGULAR` (no listings, no membership). All participant rows are self-joined (`source = self_joined`).

| Const | What |
|-------|------|
| `COMPETITION_SWAP_FAIR` | "Puzzle Swap Fair" - standalone, in person, approved, Olomouc (cz), +21 days 10:00-18:00, slug `COMPETITION_SWAP_FAIR_SLUG` = `puzzle-swap-fair`, no shortcut (short name = name). **Qualifies** |
| `PARTICIPANT_FAIR_SELLER_A` | A going to the fair - **brings SELLSWAP_01 + SELLSWAP_02** (`sell_swap_list_item_event` rows) |
| `PARTICIPANT_FAIR_SELLER_B` | B going to the fair - nothing marked (all 5 published listings are "ask to bring it"; SELLSWAP_10 is unpublished) |
| `PARTICIPANT_FAIR_BUYER_C` | C going to the fair |
| `PARTICIPANT_PAST_SELLER_A` | A going to `EDITION_PAST_ONLY_1` (in person, -45 days) - **SELLSWAP_07 still marked for it**: a history row that must stay invisible |
| `PARTICIPANT_ONLINE_SELLER_B` | B going to `EDITION_EJJ_69` (online, +30 days) - never a marketplace event |
| `PARTICIPANT_EDITION_SELLER_A` | A going to `EDITION_OFFLINE_1` (in person, +14 days, series `SERIES_OFFLINE` approved) - **qualifies**, nothing marked |

Marketplace events overall (`GetMarketplaceEvents::SQL_QUALIFIES`): `EDITION_OFFLINE_1` (+14), `COMPETITION_SWAP_FAIR` (+21),
`COMPETITION_WJPC_2024` (+30), `COMPETITION_CZECH_NATIONALS_2024` (+60). Not: everything online, `COMPETITION_UNAPPROVED`,
the unapproved series' edition, the past editions. `forPlayer()`: A = [EDITION_OFFLINE_1, SWAP_FAIR], B = [SWAP_FAIR],
C = [SWAP_FAIR, WJPC_2024] (C is also `PARTICIPANT_CONNECTED` of WJPC), PLAYER_WITH_FAVORITES = [WJPC_2024],
PLAYER_PRIVATE = [WJPC_2024]. Nobody but the players above goes to the fair.

`CompetitionSeriesFixture` registers `EDITION_EJJ_69`, `EDITION_OFFLINE_1` and `EDITION_PAST_ONLY_1` as references for this.

## Wishlists

| Player | Puzzles |
|--------|---------|
| PLAYER_REGULAR | PUZZLE_4000, PUZZLE_5000, PUZZLE_6000, PUZZLE_500_05, PUZZLE_500_04 |
| PLAYER_WITH_STRIPE | PUZZLE_9000, PUZZLE_3000, PUZZLE_500_01 |
| PLAYER_PRIVATE | PUZZLE_4000 |

## Puzzles (20 total)

### By Piece Count
- **300 pcs**: PUZZLE_300
- **500 pcs**: PUZZLE_500_01, PUZZLE_500_02, PUZZLE_500_03, PUZZLE_500_04, PUZZLE_500_05 (unavailable)
- **1000 pcs**: PUZZLE_1000_01, PUZZLE_1000_02, PUZZLE_1000_03, PUZZLE_1000_04, PUZZLE_1000_05
- **1500 pcs**: PUZZLE_1500_01, PUZZLE_1500_02
- **2000 pcs**: PUZZLE_2000
- **3000 pcs**: PUZZLE_3000
- **4000 pcs**: PUZZLE_4000
- **5000 pcs**: PUZZLE_5000
- **6000 pcs**: PUZZLE_6000
- **9000 pcs**: PUZZLE_9000
- **Unapproved**: PUZZLE_UNAPPROVED (1000 pcs, added by PLAYER_REGULAR)

### By Manufacturer
- **Ravensburger**: PUZZLE_500_01, PUZZLE_500_02, PUZZLE_500_03, PUZZLE_1000_01, PUZZLE_1000_03, PUZZLE_1000_05, PUZZLE_300, PUZZLE_1500_01, PUZZLE_2000, PUZZLE_4000, PUZZLE_5000, PUZZLE_9000
- **Trefl**: PUZZLE_500_04, PUZZLE_500_05, PUZZLE_1000_02, PUZZLE_1000_04, PUZZLE_1500_02, PUZZLE_3000, PUZZLE_6000
- **Unknown Brand** (unapproved): PUZZLE_UNAPPROVED

### Identification
- PUZZLE_500_01: ID number `RB-500-001`
- PUZZLE_500_02: EAN `4005556123456`
- PUZZLE_1000_01: ID number `RB-1000-001`
- PUZZLE_1000_03: EAN `4005556789012`
- PUZZLE_1000_05: two EANs `4005556174812, 4005556197484` (`EANS_PUZZLE_1000_05`) and two brand codes `17481, 19748-2` (`BRAND_CODES_PUZZLE_1000_05`)
- PUZZLE_1500_02: EAN `5900511101010` - ends in 0
- The other valid GS1 codes (`EAN_PUZZLE_*`, `EAN_SHARED_4000_5000`, `EAN_UNKNOWN`) are constants on `PuzzleFixture` (multiscan tests)

### Other names (docs/features/puzzle-names/)
- PUZZLE_1000_02 ("Puzzle 7"): `Kouzelná zahrada` (cs), `Zauberhafter Garten` (de)
- PUZZLE_300 ("Puzzle 11"): `Kouzelna zahrada` without a language - the Czech name of PUZZLE_1000_02 without accents
- PUZZLE_HIDDEN_IMAGE: `魔法の庭` (ja)
- Names as constants `PuzzleFixture::NAME_*`. Every puzzle's `search_names` / `search_codes` are built by the entity (`PuzzleFixtureSearchKeysTest` pins it)

## Manufacturers

| Const | Name | Approved | Added By |
|-------|------|----------|----------|
| `MANUFACTURER_RAVENSBURGER` | Ravensburger | Yes | PLAYER_ADMIN |
| `MANUFACTURER_TREFL` | Trefl | Yes | PLAYER_ADMIN |
| `MANUFACTURER_UNAPPROVED` | Unknown Brand | No | PLAYER_REGULAR |

## Competitions

### Standalone Competitions

| Const | Name | Location | Tag |
|-------|------|----------|-----|
| `COMPETITION_WJPC_2024` | WJPC 2024 | Prague, CZ | WJPC |
| `COMPETITION_CZECH_NATIONALS_2024` | Czech National Championship 2024 | Brno, CZ | National Championship |
| `COMPETITION_UNAPPROVED` | Unapproved Puzzle Event | Vienna, AT | none |
| `COMPETITION_RECURRING_ONLINE` | Euro Jigsaw Jam | Online | none (legacy recurring) |
| `MarketplaceEventFixture::COMPETITION_SWAP_FAIR` | Puzzle Swap Fair | Olomouc, CZ | none (see "Marketplace at events") |

### Competition Series

| Const | Name | Online | Country | Approved | Editions |
|-------|------|--------|---------|----------|----------|
| `SERIES_EJJ` | Euro Jigsaw Jam | Yes | - | Yes | one past, one upcoming |
| `SERIES_OFFLINE` | Puzzle Meetup Prague | No | cz | Yes | one upcoming |
| `SERIES_PAST_ONLY` | Berlin Puzzle Cup | No | de | Yes | only past — must never appear as "upcoming" |
| `SERIES_UNAPPROVED` | Pending Puzzle League | Yes | - | **No** (`approvedAt` null) | one upcoming — its edition must never be publicly visible (`IsCompetitionPubliclyVisible` false) |

### Series Editions (Competitions with series_id)

| Const | Series | Name | Date |
|-------|--------|------|------|
| `EDITION_EJJ_68` | SERIES_EJJ | EJJ #68 — February 2026 | -30 days |
| `EDITION_EJJ_69` | SERIES_EJJ | EJJ #69 — May 2026 | +30 days |
| `EDITION_OFFLINE_1` | SERIES_OFFLINE | Puzzle Meetup #1 | +14 days |
| `EDITION_PAST_ONLY_1` | SERIES_PAST_ONLY | Berlin Puzzle Cup 2026 | -45 days |
| `EDITION_UNAPPROVED_1` | SERIES_UNAPPROVED | Pending Puzzle League #1 | +7 days (no rounds) |

### Competition Rounds

| Const | Competition | Name | Time Limit | Puzzles |
|-------|-------------|------|------------|---------|
| `ROUND_WJPC_QUALIFICATION` | WJPC 2024 | Qualification Round | 60 min | PUZZLE_500_01, PUZZLE_500_02 |
| `ROUND_WJPC_FINAL` | WJPC 2024 | Final Round | 120 min | PUZZLE_1000_01, PUZZLE_1000_02 |
| `ROUND_CZECH_FINAL` | Czech Nationals 2024 | Final Round | 90 min | PUZZLE_500_01 |
| `ROUND_EJJ_68` | EDITION_EJJ_68 | EJJ #68 — February 2026 | 120 min | - |
| `ROUND_EJJ_69` | EDITION_EJJ_69 | EJJ #69 — May 2026 | 120 min | - |
| `ROUND_OFFLINE_SOLO` | EDITION_OFFLINE_1 | Solo Round | 60 min | - |
| `ROUND_OFFLINE_TEAM` | EDITION_OFFLINE_1 | Team Round | 90 min | - |
| `ROUND_PAST_ONLY` | EDITION_PAST_ONLY_1 | Berlin Puzzle Cup 2026 | 90 min | - |

## Tags

| Const | Name |
|-------|------|
| `TAG_WJPC` | WJPC |
| `TAG_NATIONAL` | National Championship |
| `TAG_ONLINE` | Online Competition |

## Puzzle Solving Times (40 total)

### Notable solving time scenarios:

1. **PUZZLE_500_01 statistics test** (5 different players):
   - MIN: 25 min (PLAYER_PRIVATE)
   - MAX: 50 min (PLAYER_WITH_FAVORITES)
   - AVG: ~36 min

2. **Personal records test** (PLAYER_REGULAR on PUZZLE_500_02):
   - First attempt: 36:40
   - Second: 31:40
   - Best: 28:20

3. **Competition times** (WJPC Qualification):
   - PLAYER_REGULAR, PLAYER_PRIVATE, PLAYER_ADMIN

4. **Team solving** (TIME_12):
   - Pair resolved by `PuzzlingTeamResolver` (the JSON snapshot's `team_id` is unused)
   - Players: PLAYER_REGULAR, PLAYER_PRIVATE
   - Puzzle: PUZZLE_1000_01

### Verified vs Unverified
- Most times are verified
- Unverified: TIME_15, TIME_23, TIME_30, TIME_31

## Quick Reference: Who Has What

| Feature | Player |
|---------|--------|
| Active membership | PLAYER_WITH_STRIPE, PLAYER_ADMIN |
| Admin privileges | PLAYER_ADMIN |
| Private profile | PLAYER_PRIVATE |
| On a private profile's allow list (`PrivateProfileViewerFixture`) | PLAYER_WITH_FAVORITES (allowed by PLAYER_PRIVATE; nobody else is) |
| Stripe customer | PLAYER_WITH_STRIPE |
| Owns lent puzzles | PLAYER_WITH_STRIPE, PLAYER_REGULAR |
| Holds borrowed puzzle | PLAYER_REGULAR, PLAYER_WITH_FAVORITES, PLAYER_WITH_STRIPE |
| Sell/swap listings | PLAYER_WITH_STRIPE, PLAYER_ADMIN |
| Going to a marketplace event | PLAYER_WITH_STRIPE (Swap Fair, bringing 2 + Meetup #1), PLAYER_ADMIN (Swap Fair, nothing marked), PLAYER_REGULAR (Swap Fair + WJPC) |
| Public collection | PLAYER_WITH_STRIPE |
| Favorite players set | PLAYER_WITH_FAVORITES |
| Team solving experience | PLAYER_REGULAR, PLAYER_PRIVATE |
| Comparison line-ups | PLAYER_REGULAR (Solo at the free cap), PLAYER_WITH_STRIPE (Solo + Pairs) |
| Multiple collections | PLAYER_WITH_STRIPE (2), PLAYER_REGULAR (2) |
| Puzzle in 3 collections | PLAYER_WITH_STRIPE: PUZZLE_500_02 (system + PUBLIC + STRIPE_TREFL) |
| Borrowed + in collection | PLAYER_WITH_STRIPE: PUZZLE_1500_02 (borrowed + in system collection) |

## Puzzle Merge Testing

PUZZLE_500_04 (survivor) and PUZZLE_500_05 (duplicate) are set up for puzzle merge testing:

### Deduplication Scenarios
These scenarios test that when a player has BOTH puzzles, only the survivor entry is kept:

| Entity Type | Player | Survivor Entry | Duplicate Entry | After Merge |
|-------------|--------|----------------|-----------------|-------------|
| CollectionItem | PLAYER_WITH_STRIPE | ITEM_21 (PUBLIC) | ITEM_27 (PUBLIC) | ITEM_27 removed |
| WishListItem | PLAYER_REGULAR | WISHLIST_09 | WISHLIST_08 | WISHLIST_08 removed |
| SellSwapListItem | PLAYER_ADMIN | SELLSWAP_09 | SELLSWAP_08 | SELLSWAP_08 removed |
| LentPuzzle | PLAYER_REGULAR | LENT_08 | LENT_07 | LENT_07 removed |

### Migration Scenarios
These entries migrate from duplicate to survivor (no deduplication needed):

| Entity Type | Entry | Player |
|-------------|-------|--------|
| CollectionItem | ITEM_25 | PLAYER_ADMIN |
| CollectionItem | ITEM_26 | PLAYER_PRIVATE |
| PuzzleSolvingTime | TIME_43 | (all solving times migrate) |
| PuzzleSolvingTime | TIME_44 | (all solving times migrate) |
| SoldSwappedItem | SOLD_01 | (all historical records migrate) |
| SoldSwappedItem | SOLD_02 | (all historical records migrate) |

## Duplicate Results (`DuplicateResultsFixture`)

Results saved twice (`docs/features/duplicate-results.md`), on their own players and puzzle so no other fixture's
counts change: `PLAYER_TWINS` (Dana Twin, `twins1`), `PLAYER_TWINS_TEAMMATE` (Tom Twin, `twins2`), both without a
user account, and `PUZZLE_TWINS` (Twins Puzzle, 108 pcs, Trefl, **not approved** - so the catalogue, brand lists and solve-time buckets never see it). All results are 40-50 days old.

| Pair | Times | Detection gives |
|------|-------|-----------------|
| `TIME_CERTAIN_A/B` | solo 1111 s, saved 7 s apart, identical | A `same_tracker` |
| `TIME_STRONG_A/B` | solo 2222 s, saved 2 min apart | B `same_tracker` |
| `TIME_TEAMMATE_A/B` | pair 3333 s, tracked by each member | B `teammate_copy`, one case per member |
| `TIME_PRACTICE_A/B` (+ `TIME_PRACTICE_OTHER` 500 s that day) | solo 444 s, 5 s apart, identical | C `same_tracker` |
| `TIME_OTHER_DAY_A/B` | solo 5555 s on two days, saved 6 days apart | nothing |

The detection at save time (`DetectDuplicateResultsOnSave`) runs while the fixtures load, so the test database
already holds these five cases - **open, `detected_by = save`**, the Tier A one too (only the daily detection removes
copies). Tests that need a clean slate delete them first (`DetectDuplicateResultsHandlerTest`); dispatching
`DetectDuplicateResults` removes `TIME_CERTAIN_B` automatically (a `result_auto_removal` row, Undo brings it back).

## Suspicious Times (`SuspiciousTimesFixture`)

Suspicious time review (`docs/features/suspicious-time-review.md`), on its own players and puzzles (UUID prefix
`018d0031-`, Trefl, **not approved**, piece counts without a brand page) so no other fixture's counts change. Sam's
baseline is at 4000 pieces on purpose: the full insights recalculation computes direct baselines only for piece
counts some approved puzzle has (`PUZZLE_4000`), the incremental one after a save for any - at another count the two
disagree (`PuzzleIntelligenceRecalculatorWritesTest`). Eda has 5 results on 3 puzzles: enough for the pace fallback,
too few first tries for a baseline. Players (no user account, log in with
`TestingLogin::asPlayer()`): `PLAYER_STEADY` (Sam Steady, `steady1`), `PLAYER_EDITION` (Eda Edition, `edition1`),
`PLAYER_GROUP` (Gina Group, `ginagr1`), `PLAYER_MARKED` (Mia Marked, `marked1`), `PLAYER_FLAGGED` (Fay Flagged,
`flagged1`), `PLAYER_PARTNER` (Pat Partner, `partner1`).

The test database is too small for community references (`SuspiciousTimeClassifier::REFERENCE_MIN_SAMPLE` = 30 per
piece-count range and puzzling type), so the fixture stores three in `suspicious_time_reference`: 1201-2000 solo
(median 3.5 PPM, p999 19), 2001-5000 solo (2.8 / 9) and 2001-5000 duo (4.0 / 12). The scan keeps a stored reference
whose range has too few results - **keep the 1201-2000 and 2001-5000 ranges below 30 results per type** (the 500-750
solo range has more than 30 in the fixtures, so the scan computes that one from the data).

| Time | What | The scan finds |
|------|------|----------------|
| `TIMES_STEADY_HISTORY` (5) | Sam, 4000 pcs (range 2001-5000), 9:26:40-9:36:40, first tries | clear; his `player_baseline` for 4000 ≈ 34200 s (9.5 h) |
| `TIME_STEADY_FAST` | Sam, Harbour Lights 4000, 2:30:00 | **pending case `CASE_PENDING_FAST`** (fixture) - fast, baseline, `faster_than_usual` + `hours_left_out` 7:30:00 |
| `TIME_STEADY_TYPO` | Sam, Quiet Orchard 520, 49:08:00, stored prediction 1:00:00 | **pending case `CASE_PENDING_SLOW`** (fixture) - slow, `slower_than_predicted` + `minutes_in_hours_box` 49:08 + `includes_breaks` |
| `TIMES_EDITION_HISTORY` (5) | Eda, 3 Cove Study puzzles of 1600 pcs (two solved twice) at 7 PPM (3:48:34) | clear; pace 2.0, no baseline |
| `TIME_EDITION_FAST` | Eda, Lighthouse Cove **3000** pcs, 2:40:00 | new case: fast, pace, `faster_than_usual` + `hours_left_out` + `other_edition` (`PUZZLE_LIGHTHOUSE_1600`) |
| `TIME_GROUP_SOLO` | Gina (new player), Mountain Meadow 3000, 25:00, comment "together with my team", a `suspicious_time_confirmation` (`CONFIRMATION_GROUP_SOLO`) | new case: fast, no expectation, `beyond_known_pace` + `teammates_saved_group` + `comment_mentions_group` + `new_player` + `confirmed_while_saving` |
| `TIME_PARTNERS_PAIR` | Pat + Fay pair, Mountain Meadow, 26:00 the same day | clear (pairs are never judged as fast) |
| `TIME_SLOW_PAIR` | Pat + Fay pair, Marathon Mosaic 3000, 150:00:00 | new case: slow, `below_slow_floor` (duo) + `includes_breaks` |
| `TIME_MARKED` | Mia, Silent Pier 520, 21:40, **flagged** | **marked case `CASE_MARKED`** (by `PlayerFixture::PLAYER_ADMIN`, note "Please check the hours.", reasons shown `faster_than_usual` + `hours_left_out`) with an unanswered notice `NOTICE_MARKED` (via run) |
| `TIME_SQL_FLAGGED` | Fay + Pat pair, Garden Gate 520, 40:00, **flagged "by SQL"**, no case | the reconciliation opens a marked case (origin manual, no reasons); the notice run then tells Fay and Pat |

No check rows are stored - the first scan in a test checks everything. Tests dispatch `DetectSuspiciousTimes` /
`NotifySuspiciousTimes` and clear the entity manager between runs (rows changed by SQL are read again).
