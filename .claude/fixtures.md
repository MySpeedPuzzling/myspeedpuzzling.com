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

### Auth0 User IDs
- `PLAYER_REGULAR`: `auth0|regular001`
- `PLAYER_WITH_STRIPE`: `auth0|stripe005`

## Membership

**Only `PLAYER_WITH_STRIPE` has active membership:**
- Stripe subscription ID: `sub_test_123456789`
- Stripe customer ID: `cus_test_123456789`
- Started: 60 days ago
- Billing period ends: 30 days from now

## Lent/Borrowed Puzzles

Most lent puzzles are **owned by `PLAYER_WITH_STRIPE`**:

| Const | Puzzle | Owner | Current Holder | Status | Notes |
|-------|--------|-------|----------------|--------|-------|
| `LENT_01` | PUZZLE_2000 (2000 pcs) | PLAYER_WITH_STRIPE | PLAYER_REGULAR | Active | "Handle with care" |
| `LENT_02` | PUZZLE_1500_01 (1500 pcs) | PLAYER_WITH_STRIPE | "Jane Doe" (non-registered) | Active | - |
| `LENT_03` | PUZZLE_1000_01 (1000 pcs) | PLAYER_WITH_STRIPE | - | **Returned** | "Returned in good condition" |
| `LENT_04` | PUZZLE_500_03 (500 pcs) | PLAYER_WITH_STRIPE | PLAYER_WITH_FAVORITES | Active (passed) | "For testing purposes" |
| `LENT_05` | PUZZLE_1500_02 (1500 pcs) | PLAYER_REGULAR | PLAYER_WITH_STRIPE | Active | - |

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

**COLLECTION_PUBLIC** (PLAYER_WITH_STRIPE): PUZZLE_500_01, PUZZLE_500_02, PUZZLE_1000_01, PUZZLE_1000_03, PUZZLE_1000_05, PUZZLE_300, PUZZLE_500_04

**COLLECTION_PRIVATE** (PLAYER_REGULAR): PUZZLE_1500_01, PUZZLE_2000, PUZZLE_3000, PUZZLE_1500_02

**COLLECTION_FAVORITES** (PLAYER_REGULAR): PUZZLE_500_01, PUZZLE_500_02

**COLLECTION_STRIPE_TREFL** (PLAYER_WITH_STRIPE): PUZZLE_1000_04, PUZZLE_500_02

**General collection (no named collection / system collection):**
- PLAYER_REGULAR: PUZZLE_500_03, PUZZLE_1000_01, PUZZLE_1000_02
- PLAYER_WITH_STRIPE: PUZZLE_500_03, PUZZLE_1000_02, PUZZLE_1500_01, PUZZLE_2000, PUZZLE_500_02, PUZZLE_1500_02

## Connections Between Players

### Favorites
- `PLAYER_WITH_FAVORITES` favorites: `PLAYER_REGULAR`, `PLAYER_ADMIN`

### Team Solving
- `PLAYER_REGULAR` & `PLAYER_PRIVATE` solved PUZZLE_1000_01 together (team-001)

### Lending Relationships
- `PLAYER_WITH_STRIPE` lends to: `PLAYER_REGULAR`, `PLAYER_WITH_FAVORITES`, "Jane Doe" (non-registered)
- `PLAYER_REGULAR` lends to: `PLAYER_WITH_STRIPE`

### Puzzle Pass Chain
- `PLAYER_WITH_STRIPE → PLAYER_REGULAR → PLAYER_WITH_FAVORITES` (PUZZLE_500_03)

## Sell/Swap Listings

**Only `PLAYER_WITH_STRIPE`** has sell/swap items (requires membership):

| Const | Puzzle | Type | Price | Condition |
|-------|--------|------|-------|-----------|
| `SELLSWAP_01` | PUZZLE_500_01 | Sell | 25.00 | LikeNew |
| `SELLSWAP_02` | PUZZLE_500_02 | Swap | - | Normal |
| `SELLSWAP_03` | PUZZLE_1000_01 | Both | 45.00 | Normal |
| `SELLSWAP_04` | PUZZLE_500_03 | Sell | 15.00 | NotSoGood |
| `SELLSWAP_05` | PUZZLE_1000_02 | Swap | - | LikeNew |
| `SELLSWAP_06` | PUZZLE_1500_01 | Both | 60.00 | MissingPieces |
| `SELLSWAP_07` | PUZZLE_1000_03 | Sell | 35.00 | Normal |

## Wishlists

| Player | Puzzles |
|--------|---------|
| PLAYER_REGULAR | PUZZLE_4000, PUZZLE_5000, PUZZLE_6000 |
| PLAYER_WITH_STRIPE | PUZZLE_9000, PUZZLE_3000 |
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

## Manufacturers

| Const | Name | Approved | Added By |
|-------|------|----------|----------|
| `MANUFACTURER_RAVENSBURGER` | Ravensburger | Yes | PLAYER_ADMIN |
| `MANUFACTURER_TREFL` | Trefl | Yes | PLAYER_ADMIN |
| `MANUFACTURER_UNAPPROVED` | Unknown Brand | No | PLAYER_REGULAR |

## Competitions

| Const | Name | Location | Tag |
|-------|------|----------|-----|
| `COMPETITION_WJPC_2024` | WJPC 2024 | Prague, CZ | WJPC |
| `COMPETITION_CZECH_NATIONALS_2024` | Czech National Championship 2024 | Brno, CZ | National Championship |

### Competition Rounds

| Const | Competition | Name | Time Limit | Puzzles |
|-------|-------------|------|------------|---------|
| `ROUND_WJPC_QUALIFICATION` | WJPC 2024 | Qualification Round | 60 min | PUZZLE_500_01, PUZZLE_500_02 |
| `ROUND_WJPC_FINAL` | WJPC 2024 | Final Round | 120 min | PUZZLE_1000_01, PUZZLE_1000_02 |
| `ROUND_CZECH_FINAL` | Czech Nationals 2024 | Final Round | 90 min | PUZZLE_500_01 |

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
   - Team ID: `team-001`
   - Players: PLAYER_REGULAR, PLAYER_PRIVATE
   - Puzzle: PUZZLE_1000_01

### Verified vs Unverified
- Most times are verified
- Unverified: TIME_15, TIME_23, TIME_30, TIME_31

## Quick Reference: Who Has What

| Feature | Player |
|---------|--------|
| Active membership | PLAYER_WITH_STRIPE |
| Admin privileges | PLAYER_ADMIN |
| Private profile | PLAYER_PRIVATE |
| Stripe customer | PLAYER_WITH_STRIPE |
| Owns lent puzzles | PLAYER_WITH_STRIPE, PLAYER_REGULAR |
| Holds borrowed puzzle | PLAYER_REGULAR, PLAYER_WITH_FAVORITES, PLAYER_WITH_STRIPE |
| Sell/swap listings | PLAYER_WITH_STRIPE |
| Public collection | PLAYER_WITH_STRIPE |
| Favorite players set | PLAYER_WITH_FAVORITES |
| Team solving experience | PLAYER_REGULAR, PLAYER_PRIVATE |
| Multiple collections | PLAYER_WITH_STRIPE (2), PLAYER_REGULAR (2) |
| Puzzle in 3 collections | PLAYER_WITH_STRIPE: PUZZLE_500_02 (system + PUBLIC + STRIPE_TREFL) |
| Borrowed + in collection | PLAYER_WITH_STRIPE: PUZZLE_1500_02 (borrowed + in system collection) |
