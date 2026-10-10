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

The first five; the full lineup (16 tiered achievements + the single-tier Early Adopter, `supporter`) with its
thresholds is in `docs/features/xp-levels/launch-checklist.md`.

## Visibility rules

- A player's badges appear on their profile **only if the profile owner has an active membership** - for everyone
  allowed to see the profile (a private one: its owner only), as the **achievements showcase** near the top
  (`AchievementsShowcase`, 2026-10-10): one sideways-scrolling row, highest tier first, "11 of 17 · 640 AP ›" to the
  catalogue; the owner's unrevealed ones are "?" medallions leading to the reveal page. A free owner gets one quiet
  line ("You have earned achievements. Become a member to see them." - from their AP, no query), never a lock; nobody
  else sees anything.
- Badges are evaluated for *all* players regardless of membership — records accumulate silently. They become visible the moment the player's membership activates.
- The catalog `/en/achievements` (old `/en/badges` links 301 there) is public. Logged-in users see per-badge progress bars toward the next tier; logged-out users see the catalog without progress. A free player's earned tiers show locked with a "✓ 🔒" mark.
- While the `xp-system` flag is active, every one of these surfaces is admins-only (`docs/features/feature_flags.md`).

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
- No flag — immediate dispatch for every player (one async message each, ~7,600 on 2026-10-04); the daily safety-net cron.

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
5. Draw its badge art with the art pipeline - [`tools/badge-art/README.md`](../../tools/badge-art/README.md) "Add a
   badge": an icon drawing, one line in the registry, the build, the QA gate. It writes `public/img/badges/**` +
   `manifest.json`; commit them with the code. There is no fallback picture - `BadgeArtCoverageTest` fails for a
   `BadgeType` case without art, and the templates throw in debug (see "Badge art" below).

No other code changes needed.

## Badge art

The pictures are generated, committed files - F1 seal ladder v2 (the tier is the seal's outline: circle → scallops →
pleats and tails → sunburst and crown) around an illustrated icon per achievement; one-off achievements get the
single-tier base. How they are made, how to add or change one and the QA gate: [`tools/badge-art/README.md`](../../tools/badge-art/README.md).
Generation runs on a developer machine only, never in CI or the Docker build.

Files in `public/img/badges/` (`<type>` = the `BadgeType` value, `<tier>` = `t1`-`t5` for BadgeTier 1-5 or `single`
for a single-tier achievement, e.g. `streak-t3`, `supporter-single`):

| Path | Use |
|---|---|
| `svg/<type>-<tier>.svg` | vector master |
| `webp/<px>/<type>-<tier>.webp`, px 96 / 144 / 192 / 320 / 480 | the site - 2x of the display sizes 48 / 72 / 96 / 160 / 240 |
| `png/<px>/<type>-<tier>.png`, px 96 / 144 | e-mails (absolute URLs) |
| `manifest.json` | `version` (a short hash of all the files), the types with their tiers, every file with its sha256 |

Templates never build these paths themselves - only two Twig functions do (`src/Twig/BadgeArtTwigExtension.php`,
manifest read by `src/Services/Badges/BadgeArtManifest.php`):

- `badge_art(type, tier, display_px = 72, locked = false, attrs = {})` - the `<img>`: WebP at 2x the display size
  (`srcset` adds the 1x file when there is a smaller one), alt = achievement + tier, lazy. `locked = true` adds
  `badge-art-locked` (greyscale at 45 %, `assets/styles/components/_badge-art.scss`) - not earned yet, or earned but
  members-only. `attrs` adds classes / replaces attributes (`{alt: ''}` next to a label that says the same).
- `badge_art_url(type, tier, format = 'webp', px = 144, absolute = false)` - one file's URL. E-mails:
  `badge_art_url(type, tier, 'png', 96, absolute = true)` shown at 48 px (`templates/emails/_badge_row.html.twig`).

`type` = `BadgeType` or its value, `tier` = `BadgeTier`, its number, or null/0 for a single-tier achievement (`single`).
Every URL carries `?v=<manifest version>`, so a rebuilt badge gets a new URL (browsers and the service worker never keep
an old one); the manifest is read once per worker (deploy-static), in debug again whenever it changes. Display sizes in use:
profile 72, catalogue card 96 + its tier row 48, explainer modal 160 + its list 48, achievement page 160 + its tier
sections 72, toasts 48, holder chips 24. A picture that does not exist throws `BadgeArtMissing` in debug; production
logs a warning and leaves it out (never a broken image).

## Email template

`templates/emails/badges_earned.html.twig` — Inky-based, uses the shared `_header.html.twig` / `_footer.html.twig`. Subject via `emails.en.yml › badges_earned.subject`. Header `X-Transport: transactional` so it hits the transactional mail transport (not the bulk notifications transport).

Each achievement is a row with its badge (`templates/emails/_badge_row.html.twig`: the 96 px PNG at 48 px, absolute
URL, `alt=""` on purpose - the name and tier stand beside it, so the plain-text part and mail apps that block images
would otherwise say them twice). The weekly digest's earned and "within your reach" achievements use the same row.

## Opt-out

Not currently offered. If player frustration over email volume surfaces, add a `badgesOptedOut` boolean on `Player` (following the existing `streakOptedOut` / `rankingOptedOut` pattern) and short-circuit the mailer call at the top of the handler.

## Cron

A daily safety net - every add/edit/delete of a time already dispatches the player's recalculation, so the cron only
heals drift (manually granted badges, paths that are not live-wired). Not every 15 minutes: one run puts one message per
player with times on the `async` queue the transactional e-mails share. The production row (lily.srv) is in
`docs/features/xp-levels/README.md` §Cron.

On first deploy, seed existing players with (staggered, no e-mails - see `launch-runbook.md`):

```
docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:recalculate-badges --backfill
```

## UI handoff

What the UI already does, the owner decisions and the fine-tuning backlog (holo modal, animated shine, badge cabinet,
event passport): [`badges-ui-handoff.md`](badges-ui-handoff.md).
