# Opt-Out Features

Players can opt out of specific public-facing features via **Edit Profile → Features Options** form.

## Available Opt-Outs

### Streak Opt-Out (`streak_opted_out`)

Hides the "longest streak" card from the player's statistics page.

- **UI-only** — streak data is still calculated, just not displayed
- The card is completely hidden for all viewers (including the player themselves)
- No effect on queries or other players' data

**Affected templates:**
- `templates/components/PlayerStatistics.html.twig` — hides the longest streak card

### Ranking Opt-Out (`ranking_opted_out`)

Hides skill tier, MSP Rating ranking, and time predictions. The player is excluded from the MSP Rating ladder.

- **Query-level filtering** — `GetPlayerRatingRanking` excludes opted-out players via `WHERE ranking_opted_out = false`
- Player's data is still used in calculations for puzzle difficulty and other players' rankings
- Opted-out players can still view their own rating data privately via `allForPlayer()` query

**Affected queries:**
- `GetPlayerRatingRanking` — excludes from `ranking()`, `playerPosition()`, `totalCount()`, `distinctCountries()`
- `GetFastestPlayers`, `GetFastestPairs`, `GetFastestGroups`, `GetPuzzleSolvers`, `GetRecentActivity` — carry the flag for template display

**Affected templates:**
- `templates/player_profile.html.twig` — hides PlayerSkillProfile and PlayerRatingProfile components (own profile shows explanation message)
- `templates/components/PlayerHeader.html.twig` — hides skill tier rank icon
- `templates/components/PuzzleTimes.html.twig` — hides skill tier icons in puzzle time listings
- `templates/components/RecentActivity.html.twig` — hides skill tier icons in activity feed
- `templates/components/LadderTable.html.twig` — hides skill tier icons in fastest puzzle tables
- `templates/added_time_recap.html.twig` — hides ranking and progress sections
- `templates/added_time_recap/_performance_summary.html.twig` — hides time predictions
- `templates/msp_rating_ladder/index.html.twig` — opted-out players not listed

**Affected controllers:**
- `AddedTimeRecapController` — skips ranking data loading
- `PuzzleDetailController` — skips time prediction generation
- `MspRatingLadderController` — skips position/rating/progress retrieval

## Data Flow

1. Player toggles opt-out in Edit Profile form (`FeaturesOptionsFormType`)
2. Form submission dispatches `EditFeaturesOptions` message
3. `EditFeaturesOptionsHandler` updates `Player` entity (`changeStreakOptedOut()` / `changeRankingOptedOut()`)
4. `PlayerProfile` result DTO carries the flags from database
5. Templates and controllers check the flags to conditionally hide UI elements

## Database

Columns on `player` table (added in `Version20260330182405`):
- `streak_opted_out BOOLEAN DEFAULT false NOT NULL`
- `ranking_opted_out BOOLEAN DEFAULT false NOT NULL`

## Comparison

| Aspect | Streak Opt-Out | Ranking Opt-Out |
|--------|---------------|-----------------|
| Query filtering | None | Yes (excluded from rankings) |
| Own profile | Card hidden | Shows explanation message |
| Other viewers | Card hidden | All skill/rank info hidden |
| Affects ladders | No | Yes (excluded from MSP Rating) |
| Affects predictions | No | Yes (hidden) |
| Data still used | Yes | Yes (for difficulty/ranking calculations) |
