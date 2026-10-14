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
- `templates/player/_standing.html.twig` (on Statistics, `player_statistics` - the profile page showed these cards until the navigation redesign, PROF-04) — hides PlayerSkillProfile and PlayerRatingProfile components (own profile shows explanation message)
- `templates/components/PlayerHeader.html.twig` — no tier chip at all (not even the members' lock), `PlayerHeader::tierChip()`
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
- `PuzzlePickerController` — no prediction row / insights-with-predictions filters (`predictionsAllowed`)
- `CollectionDetailController` / `SystemCollectionDetailController` via `ResolveCollectionDisplay` — a persisted collection display mode `times_predictions` is served as `times` (no predictions loaded), and `ChangeCollectionDisplayModeController` downgrades the choice on save

**Affected templates:**
- `templates/added_time_recap/_performance_summary.html.twig` — replaces the prediction card with an info banner + "Change in settings" link
- `templates/puzzle/_difficulty_section.html.twig` — replaces the Time Prediction block in Puzzle Insights with an info banner + "Change in settings" link
- `templates/puzzle_picker/_card.html.twig` — opt-out notice instead of the prediction row
- `templates/collections/_display_mode.html.twig` — "My times + predictions" is disabled with the opt-out notice + "Change in settings" link (`player.collection_display_mode`, see `docs/features/puzzle-picker/README.md` §5)

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
- `achievements_opted_out BOOLEAN DEFAULT false NOT NULL` (added in `Version20261012053303`)

## Comparison

| Aspect | Streak Opt-Out | Ranking Opt-Out | Time Predictions Opt-Out |
|--------|---------------|-----------------|--------------------------|
| Query filtering | None | Yes (excluded from rankings) | None |
| Own profile | Card hidden | Shows explanation message | Banner shown on prediction blocks |
| Other viewers | Card hidden | All skill/rank info hidden | Prediction block hidden |
| Affects ladders | No | Yes (excluded from MSP Rating) | No |
| Affects predictions | No | No | Yes (hidden) |
| Data still used | Yes | Yes (for difficulty/ranking calculations) | Yes |

### Karma Opt-Out (`karma_opted_out`)

"Hide my karma" - hides the player's karma from others (docs/features/karma.md). Independent of the XP and ranking
opt-outs.

- **Data keeps counting** - karma is still derived and stored on `player.karma`; switching back shows everything again
- **Query-level filtering** - `GetXpLeaderboard::karma()` leaves opted-out players out of the karma list (every kind and period)
- **UI** - `templates/components/PlayerHeader.html.twig`: no karma chip for anybody, owner included;
  `templates/karma/history.html.twig`: the owner still sees everything, with a "hidden from others" note
- **Not affected** - review queues and admin pages (moderation context), the owner's own hub / activity / digest lines
- **Form** - `FeaturesOptionsFormType::karmaOptedOut`, shown together with the XP switch (karma launches with XP);
  `EditFeaturesOptions::$karmaOptedOut` null = keep the stored value


### Achievements Opt-Out (`achievements_opted_out`)

"Hide my achievements" - hides the player's achievements everywhere, **for the player too** (added 2026-10-12 after a
player asked for it: the XP switch deliberately leaves achievements on, since they are not XP). Independent of the XP and
karma opt-outs.

- **Data keeps counting** - `BadgeEvaluator` still evaluates and stores the tiers and Achievement Points; switching it
  back shows everything again, nothing is re-revealed
- **Query-level filtering** - `GetXpLeaderboard::board()` leaves the player off the Achievement Points board,
  `GetAchievementHolders` out of the holder lists (counts still include everyone). Both still also leave out XP opt-outs,
  as before
- **Player pages** - no achievements showcase on the profile (`AchievementsShowcase`) and no Achievements tab
  (`PlayerHeader`) for anybody; `PlayerAchievementsController` sends others to the profile and shows the owner one line
  with a "Change in settings" link (`player_achievements_hidden.html.twig`); the detail sheet ignores `?player=` of such a
  player (`AchievementInfoController::visibleSubject()`)
- **The player's own surfaces** - no achievements on the recap (`XpRecapCelebration::achievements()`; the XP lines stay),
  in the activity digest and on "Your week" (`PlayerPeriodActivityReader`), no reveal invite / reveal page
  (`XpRevealInvite`, `BadgeRevealsController` → their achievements page), no XP launch reveal modal
  (`XpLaunchRevealRule`), and the catalogue / detail pages show no personal line or medal (`BadgesOverviewController`,
  `AchievementDetailController`, `AchievementInfoController`) - they stay browsable as the public catalogue
- **API** - `GET /api/v1/players/{id}` returns `badges: []`; `/me` still lists the caller's own
- **Form** - `FeaturesOptionsFormType::achievementsOptedOut`, shown together with the XP switch (achievements launch with
  XP); `EditFeaturesOptions::$achievementsOptedOut` null = keep the stored value
