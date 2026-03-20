# Unreleased Puzzle — Hide Image Until Release Date

## Purpose

Users sometimes add unreleased puzzles to the app. The `hideImageUntil` field on the `Puzzle` entity allows hiding the puzzle image until its official release date. This protects embargoed images from leaking through the platform.

## How It Works

### Entity Field

```php
// src/Entity/Puzzle.php
#[Column(nullable: true)]
public null|DateTimeImmutable $hideImageUntil = null,
```

This field is **set manually via database** — there is no PHP setter, form field, or admin UI for it.

### SQL-Level Image Nullification

All SQL queries that select `puzzle.image` use a `CASE WHEN` expression:

```sql
CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > NOW()
  THEN NULL ELSE puzzle.image END AS puzzle_image
```

When `hideImageUntil` is in the future, the image column is returned as `NULL`. Since the existing Twig extensions (`PuzzleImageTwigExtension`, `LazyImageTwigExtension`) already return the placeholder image (`/img/placeholder-puzzle.jpg`) when the image is `null`, **no template changes are needed for image display**.

This also automatically handles:
- OG image meta tag (checks `puzzle.puzzleImage is not null`)
- Gallery link in puzzle detail (checks `puzzle.puzzleImage is not null`)

### SEO Protection

When the image is hidden:
- **Puzzle detail page**: Returns `<meta name="robots" content="noindex, nofollow">` instead of `index, follow`
- **Sitemap**: Excludes puzzles with active `hideImageUntil` from both `allApproved()` and `withMarketplaceOffers()` sitemap queries

The SEO check uses `ClockInterface` in `PuzzleDetailController` for testability:

```php
$isImageHidden = $puzzle->hideImageUntil !== null && $puzzle->hideImageUntil > $this->clock->now();
```

## Affected Files

### Entity & DTO
- `src/Entity/Puzzle.php` — `hideImageUntil` field
- `src/Results/PuzzleOverview.php` — `hideImageUntil` property + `fromDatabaseRow()` mapping

### Controller & Template
- `src/Controller/PuzzleDetailController.php` — injects `ClockInterface`, computes `is_image_hidden`
- `templates/puzzle_detail.html.twig` — overrides `{% block robots %}` based on `is_image_hidden`

### Sitemap
- `src/Query/GetPuzzleIdsForSitemap.php` — excludes hidden puzzles

### SQL Queries (CASE WHEN applied)
Every query that selects a puzzle image was updated. The full list:

**Tier 1 (user-facing pages):**
- `GetPuzzleOverview.php` (3 methods, also selects `hide_image_until`)
- `SearchPuzzle.php` (3 methods, also selects `hide_image_until`)
- `GetFastestPlayers.php`, `GetFastestPairs.php`, `GetFastestGroups.php`
- `GetRecentActivity.php` (3 methods)
- `GetPlayerSolvedPuzzles.php` (6 methods)
- `GetRanking.php` (2 methods)
- `GetSellSwapListItems.php` (2 methods)
- `GetCollectionItems.php` (2 methods)
- `GetMarketplaceListings.php` (2 methods)
- `GetMostSolvedPuzzles.php` (2 methods)
- `GetNotifications.php` (6 UNION sections)
- `GetLastSolvedPuzzle.php` (3 methods)

**Tier 2 (consistency):**
- `GetPuzzlesOverview.php`, `GetPuzzleTracking.php`
- `GetLentPuzzles.php`, `GetBorrowedPuzzles.php`
- `GetWishListItems.php`, `GetUnsolvedPuzzles.php`
- `GetSoldSwappedHistory.php`, `GetConversations.php`
- `GetPuzzleChangeRequests.php`, `GetPuzzleMergeRequests.php`
- `GetPendingPuzzleProposals.php`, `GetTransactionRatings.php`

### Tests & Fixtures
- `tests/DataFixtures/PuzzleFixture.php` — `PUZZLE_HIDDEN_IMAGE` constant with `hideImageUntil = 2099-12-31`
- `tests/Controller/PuzzleDetailControllerTest.php` — tests for `noindex, nofollow` and `index, follow`

## Usage

To hide a puzzle image until release:

```sql
UPDATE puzzle SET hide_image_until = '2025-06-15 00:00:00' WHERE id = '<puzzle-uuid>';
```

To remove the restriction:

```sql
UPDATE puzzle SET hide_image_until = NULL WHERE id = '<puzzle-uuid>';
```

The image will automatically appear once `NOW()` passes the `hide_image_until` timestamp — no code deployment or cache clear needed.
