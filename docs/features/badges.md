# Badges / Achievements System

Fully dynamic, tiered badge system. Once earned, a badge is permanent — the logic can only award new badges, never revoke them.

## Launch badges

| Badge | Type value | Tiers (Bronze → Diamond) | Source metric |
|---|---|---|---|
| Puzzle Explorer | `puzzles_solved` | 10 / 100 / 500 / 1000 / 2000 | Distinct puzzles solved (counts team participation) |
| Piece Cruncher | `pieces_solved` | 10k / 100k / 500k / 1M / 2M pieces | Total pieces placed (full credit per team participant) |
| Speed Demon (500pc) | `speed_500_pieces` | < 5h / 2h / 1h / 45m / 30m | Player's fastest SOLO 500-piece solve |
| On Fire | `streak` | 7 / 30 / 90 / 180 / 365 days | All-time longest consecutive-day solving streak |
| Team Spirit | `team_player` | 1 / 5 / 25 / 100 / 500 | Count of duo + team solves the player participated in |
| Supporter (single-tier) | `supporter` | — | Admin-granted, no automation |

## Visibility rules

- A player's badges appear on their public profile **only if the profile owner has an active membership**. Non-members have no badges section rendered.
- Badges are evaluated for *all* players regardless of membership — records accumulate silently. They become visible the moment the player's membership activates.
- The `/en/badges` catalog page is public. Logged-in users see per-badge progress bars toward the next tier; logged-out users see the catalog without progress.

## Architecture

### Plug-in contract

```
SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface
    badgeType(): BadgeType
    qualifiedTiers(PlayerStatsSnapshot): list<BadgeTier>
    progressToNextTier(PlayerStatsSnapshot, ?BadgeTier $highestEarned): ?BadgeProgress
    requirementForTier(BadgeTier): int
```

Implementations are auto-tagged via `#[AutoconfigureTag('badge.condition')]` on the interface. The evaluator and catalog query consume them as `iterable<BadgeConditionInterface>`.

Most badges share an ascending-threshold structure; they extend `AbstractAscendingThresholdCondition` and only declare `thresholds()` + `currentValue()`. The Speed 500pc badge implements the interface directly because lower seconds = higher tier.

### Data flow

#### Live (per-user action)

```
User adds/edits/deletes a PuzzleSolvingTime
  → Add/Edit/DeletePuzzleSolvingTimeHandler
  → dispatch RecalculateBadgesForPlayer($playerId) → ASYNC messenger transport
  → RecalculateBadgesForPlayerHandler
      → BadgeEvaluator.recalculateForPlayer()
          → GetPlayerStatsSnapshot (4–5 small SQLs)
          → GetBadges (1 SQL)
          → for each condition: compute qualifiedTiers and persist gaps
      → if new badges were earned, send ONE TemplatedEmail (highest tier per type)
```

#### Backfill / cron

```
bin/console myspeedpuzzling:recalculate-badges [--backfill]
  → GetAllPlayerIdsWithSolveTimes.execute()
  → for each player (index i):
      dispatch RecalculateBadgesForPlayer($id) [+ DelayStamp(i * 2000 ms) when --backfill]
```

- `--player=UUID` — single player, no stagger.
- `--backfill` — 2-second stagger between players via `DelayStamp` so outbound email volume is spread out smoothly.
- No flag — immediate dispatch for every player; fitting for a 15-minute cron.

### Data model

Table `badge` (existing since `Version20240408184034`, extended by `Version20260416210601`):

| Column | Type | Notes |
|---|---|---|
| id | UUID | PK |
| player_id | UUID | FK → player.id |
| type | VARCHAR | `BadgeType` enum value |
| earned_at | TIMESTAMP | Immutable |
| tier | SMALLINT null | `BadgeTier` enum value (1 Bronze → 5 Diamond). NULL for single-tier badges (Supporter) |

Two partial unique indexes (created manually in the migration with `custom_` prefix so Doctrine does not manage them):

- `UNIQUE (player_id, type, tier) WHERE tier IS NOT NULL` — tiered badges
- `UNIQUE (player_id, type) WHERE tier IS NULL` — single-tier badges

Both indexes are mirrored in `tests/bootstrap.php`.

### Gap-filling

When a player first qualifies for, say, tier 3 of a badge without having earned tiers 1 or 2 previously (typical for backfill), the evaluator persists all three rows with the same `earnedAt` timestamp. The email, however, mentions only the highest tier per badge type in that recalc pass.

### Performance

- Per-player recalc runs a fixed 4–5 SQLs regardless of history size.
- Backfill fans out via Messenger — natural parallelism at the worker level, crash isolation.
- `DelayStamp(i * 2000ms)` during backfill spreads email dispatches across ~67 minutes for 2000 players. Tune `BACKFILL_DELAY_MS` in `RecalculateBadgesConsoleCommand` if cohort grows.

## Adding a new badge

1. Add a case to `src/Value/BadgeType.php`, e.g. `case NightOwl = 'night_owl';`.
2. Create `src/BadgeConditions/NightOwlCondition.php`:

   ```php
   readonly final class NightOwlCondition extends AbstractAscendingThresholdCondition
   {
       public function badgeType(): BadgeType { return BadgeType::NightOwl; }

       protected function currentValue(PlayerStatsSnapshot $snapshot): int
       {
           return $snapshot->nightOwlSolves;
       }

       protected function thresholds(): array
       {
           return [1 => 10, 2 => 50, 3 => 200, 4 => 500, 5 => 1000];
       }
   }
   ```

3. Add the metric to `PlayerStatsSnapshot` + load it in `GetPlayerStatsSnapshot`.
4. Add translation keys under `badges.badge.night_owl`, `badges.description.night_owl`, and `badges.requirement.night_owl_{1..5}` in `translations/messages.en.yml`.
5. (Optional) Drop `public/img/badges/night_owl_1.png` through `_5.png` when art is ready. Template falls back to a tier-colored medallion otherwise.

No other code changes needed.

## Email template

`templates/emails/badges_earned.html.twig` — Inky-based, uses the shared `_header.html.twig` / `_footer.html.twig`. Subject via `emails.en.yml › badges_earned.subject`. Header `X-Transport: transactional` so it hits the transactional mail transport (not the bulk notifications transport).

## Opt-out

Not currently offered. If player frustration over email volume surfaces, add a `badgesOptedOut` boolean on `Player` (following the existing `streakOptedOut` / `rankingOptedOut` pattern) and short-circuit the mailer call at the top of the handler.

## Cron

Schedule the same way as the puzzle-intelligence recalc (every 15 minutes):

```
*/15 * * * * docker compose exec web php bin/console myspeedpuzzling:recalculate-badges
```

On first deploy, seed existing players with:

```
docker compose exec web php bin/console myspeedpuzzling:recalculate-badges --backfill
```
