---
paths:
  - "src/Query/**"
  - "src/Results/**"
  - "src/Component/**"
  - "src/Repository/**"
  - "src/Api/**"
  - "src/Controller/Api/**"
  - "tests/*Canary*"
  - "tests/*CoverageTest.php"
  - "tests/**/*QueryBudgetTest.php"
  - "tests/**/*Canary*"
  - "tests/**/*CoverageTest.php"
---

# Read side: rules every query and listing page must keep

Loaded when you touch queries, results, Live components or the guard tests. These rules cross features: each one is
guarded by a coverage test (a statement or file that does not follow it must be listed with a reason) and/or a
canary test (renders every surface - **add every new surface to it**). The full rule lives in the named feature file
under `.claude/rules/features/` and in its doc.

| Rule | How | Guards | Detail |
|---|---|---|---|
| Blocked players never show | every `src/Query` statement returning other players' identity embeds `HiddenPlayers::sqlExclude()` / `sqlExcludeTeam()` (filter *inside* ranked subqueries); guests, cron, async handlers and `/admin` see everyone | `BlocklistCanaryTest`, `BlocklistQueryCoverageTest` | players.md "Player blocklist" |
| Private players stay masked | select `PrivateProfileAccess::sqlIsPrivate('alias')` (or `sqlIsPublic()`), never raw `is_private`; `PrivateProfileAccess` is the only unmasker | `PrivateProfileCanaryTest`, `PrivateProfileQueryCoverageTest` | players.md "Private profile allow list" |
| Suspicious times are neither counted nor timed | leaderboards, ranks, counts, medians, stats, feeds exclude `puzzle_solving_time.suspicious`; only the player's own results show it (badge) | `SuspiciousTimeQueryCoverageTest` | results.md "Suspicious times" |
| A result may have no tracker | `puzzle_solving_time.player_id` is NULL for imported event results (`created_via = event_import`) | `TrackerlessTimeQueryCoverageTest`, `TrackerlessTimeCanaryTest` | events.md "Event results import" |
| An unfinished result is not a solve | counts of solves filter `CountsAsSolve::condition()`, pieces / time sums use `piecesPuzzled()` / `secondsPuzzled()` (`pieces_placed`, `seconds_spent`); timed readers need nothing | `SolvingTimeReadersAreClassifiedTest`, `UnfinishedResultsAreInvisibleTest` | `docs/features/competitions-management/unfinished-results-plan.md` |
| A time's series can come from a series pick | join `cs ON cs.id = COALESCE(c.series_id, pst.competition_series_id)` | `SeriesPickQueryCoverageTest`, `SeriesPickCanaryTest` | events.md "High-frequency series" |
| Drafts and unapproved events never leak | `IsCompetitionPubliclyVisible::SQL_CONDITION` (`SQL_APPROVED` = approval only), `IsSeriesPubliclyVisible`, `IsOrganizationPubliclyVisible` | `DraftVisibilityCoverageTest`, `DraftCanaryTest` | events.md "Organizations + drafts" |
| Custom field values are private | only `GetCustomValues::forViewer()` / `GetCollectionItemCustomValues`; never in a shared read, a list, the API or a `collection_item` wildcard select | `CustomValuesCoverageTest`, `CustomValuesPrivacyCanaryTest` | my-data.md "Custom fields" |
| Puzzle text search goes through one place | `PuzzleTextSearch` / `PuzzleSearchQuery` (`condition()`, `score()`, `barcodeCondition()`), never `ILIKE` on puzzle columns; paged sorts end on the puzzle id | `LegacyAlternativeNameCoverageTest` (old column) | catalogue.md "Puzzle names, EANs and brand codes" |
| Notifications page first | every UNION branch of `GetNotifications::forPlayer()` reads `FROM page notification`, never `FROM notification` | - | players.md "Notifications" |
| Statement budgets | a page with a `*QueryBudgetTest` (`tests/**/*QueryBudgetTest.php` - Events page, detail pages, Players page, Insights, exports…) keeps its pinned statement count | the page's `*QueryBudgetTest` | the feature's entry |

Write-side rules that the same features depend on:

- A solving time is deleted only through `PuzzleSolvingTimeRepository::delete($time, $via)` (grave + tombstones) - `SolvingTimeDeletionCoverageTest`.
- Every participant / entry / team write takes `CompetitionParticipantsLock` - `SerializedByLockMessagesTest`.
- A new foreign key to `puzzle` needs a line in `PuzzleMergeForeignKeyCoverageTest` (what a merge does with it).
- Raw SQL never writes puzzle names or codes (`Puzzle::changeNames()` / `updateProductIdentifiers()` maintain the search keys).
- Performance of puzzle queries: `.claude/postgres-puzzle-query-optimization.md`; custom indexes: `.claude/rules/database.md`.
