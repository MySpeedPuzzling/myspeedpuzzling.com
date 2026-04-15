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

Hides skill tier and MSP Rating ranking. The player is excluded from the MSP Rating ladder.

- **Query-level filtering** — `GetPlayerRatingRanking` excludes opted-out players via `WHERE ranking_opted_out = false`
- Player's data is still used in calculations for puzzle difficulty and other players' rankings
- Opted-out players can still view their own rating data privately via `allForPlayer()` query
- Independent of the Time Predictions opt-out — predictions are no longer gated by this flag

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
- `templates/msp_rating_ladder/index.html.twig` — opted-out players not listed

**Affected controllers:**
- `AddedTimeRecapController` — skips ranking data loading (ranking, puzzle history, player skill, rating data/progress)
- `MspRatingLadderController` — skips position/rating/progress retrieval

### Time Predictions Opt-Out (`time_predictions_opted_out`)

Hides personal time predictions on puzzle detail pages and added-time recaps. Independent of the Ranking opt-out.

- **Controller-level gating** — prediction queries skipped when the flag is true
- Predictions are never calculated or exposed for opted-out players on the affected pages
- Ranking, skill tier, and MSP Rating remain visible (unless the player has also opted out of ranking)

**Affected controllers:**
- `AddedTimeRecapController` — skips `GetPlayerPrediction` call
- `PuzzleDetailController` — skips `GetPlayerPrediction` call

**Affected templates:**
- `templates/added_time_recap/_performance_summary.html.twig` — replaces the prediction card with an info banner + "Change in settings" link
- `templates/puzzle/_difficulty_section.html.twig` — replaces the Time Prediction block in Puzzle Insights with an info banner + "Change in settings" link

## Data Flow

1. Player toggles opt-out in Edit Profile form (`FeaturesOptionsFormType`)
2. Form submission dispatches `EditFeaturesOptions` message
3. `EditFeaturesOptionsHandler` updates `Player` entity (`changeStreakOptedOut()` / `changeRankingOptedOut()` / `changeTimePredictionsOptedOut()`)
4. `PlayerProfile` result DTO carries the flags from database
5. Templates and controllers check the flags to conditionally hide UI elements

## Database

Columns on `player` table:
- `streak_opted_out BOOLEAN DEFAULT false NOT NULL` (added in `Version20260330182405`)
- `ranking_opted_out BOOLEAN DEFAULT false NOT NULL` (added in `Version20260330182405`)
- `time_predictions_opted_out BOOLEAN DEFAULT false NOT NULL` (added in `Version20260415180106`)

## Comparison

| Aspect | Streak Opt-Out | Ranking Opt-Out | Time Predictions Opt-Out |
|--------|---------------|-----------------|--------------------------|
| Query filtering | None | Yes (excluded from rankings) | None |
| Own profile | Card hidden | Shows explanation message | Banner shown on prediction blocks |
| Other viewers | Card hidden | All skill/rank info hidden | Prediction block hidden |
| Affects ladders | No | Yes (excluded from MSP Rating) | No |
| Affects predictions | No | No | Yes (hidden) |
| Data still used | Yes | Yes (for difficulty/ranking calculations) | Yes |
