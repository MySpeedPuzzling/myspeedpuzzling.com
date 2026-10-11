# Badges / Achievements System

Fully dynamic, tiered badge system. **Achievements follow the current records** (anti-farming, 2026-10-11,
`docs/features/xp-levels/README.md` §Anti-farming): every evaluation brings the tiers held to what the player's
results, proposals and puzzles qualify for now - new tiers are added, tiers that no longer qualify are removed (results
deleted, removed as duplicates, marked as needing verification, merged away). Logging fake times, earning a tier and
deleting the times keeps nothing.

Invariants:

- Silent: nobody is told about a removed tier - no e-mail, no notice, no digest line. The removed row takes its reveal
  state along; earned again later it is a new, unrevealed tier (the recap / reveal pages / digest treat it as new), and
  its Achievement Points count once - `player.achievement_points` is re-anchored to the tiers held on every evaluation.
- Never removed: an achievement no condition decides (Early Adopter, `supporter`, granted by an admin), or a type the
  code does not know (yet).
- The showcase, catalogue, holders lists, rarity and the digest read the rows held now, so a removed tier is gone from
  all of them at once (rarity on its 15-minute cache). The digest's "earned this period" lists only rows held when it
  is built (and never ones earned before the launch switch).
- Every write path that removes results queues the evaluation for every registered member (delete, automatic
  duplicate removal, "keep this copy", a suspicious mark via `RecalculateProgressionOnSuspicionChange`); the daily
  `recalculate-badges` run is the safety net for the rest (SQL, a puzzle's pieces corrected, merges).

## Achievements and their tiers

16 tiered achievements (Bronze → Diamond) + the single-tier Early Adopter (`supporter`, admin-granted, no automation).
The thresholds live in `src/BadgeConditions/*` (one class per type); the texts in `badges.requirement.<type>_<tier>`
(6 locales). Achievement Points per tier: 5 / 10 / 25 / 50 / 100.

| Achievement | Type value | Tiers (Bronze → Diamond) | Source metric (`GetPlayerStatsSnapshot`) |
|---|---|---|---|
| Puzzle Explorer | `puzzles_solved` | 10 / 100 / 500 / 1000 / 2000 | Distinct puzzles solved (counts team participation) |
| Piece Cruncher | `pieces_solved` | 10k / 100k / 500k / 1M / 2M pieces | Total pieces placed (full credit per team participant) |
| Speed Demon (500pc) | `speed_500_pieces` | < 2h / 1h30 / 1h / 45m / 30m | Player's fastest SOLO 500-piece solve |
| Speed Demon (1000pc) | `speed_1000_pieces` | < 5h / 3h30 / 2h30 / 1h45 / 1h15 | Player's fastest SOLO 1000-piece solve |
| On Fire | `streak` | 7 / 30 / 90 / 180 / 365 days | All-time longest consecutive-day solving streak |
| Team Spirit | `team_player` | 5 / 25 / 100 / 250 / 500 | Duo + team solves the player took part in |
| Zen Puzzler | `zen_puzzler` | 5 / 15 / 50 / 150 / 365 | Relax solves (no time) |
| First Try | `first_try` | 5 / 50 / 200 / 500 / 1000 | Solves logged as the first attempt |
| Unboxed | `unboxed` | 5 / 15 / 30 / 50 / 100 | Solves without looking at the box |
| Brand Explorer | `brand_explorer` | 5 / 15 / 30 / 50 / 100 | Distinct manufacturers solved |
| Marathoner | `marathoner` | 5 / 10 / 20 / 40 / 100 | Solves of puzzles with 2000+ pieces |
| Photographer | `photographer` | 5 / 25 / 100 / 500 / 1000 | Results with a photo of the finished puzzle |
| Steady Hands | `steady_hands` | 3 / 6 / 9 / 12 / 16 quarters | Longest run of consecutive calendar quarters with a solve |
| Librarian | `librarian` | 5 / 15 / 30 / 50 / 100 | Accepted catalog change proposals |
| Weekend Puzzler | `weekend_puzzler` | 10 / 50 / 150 / 300 / 600 | Solves on Saturdays and Sundays |
| Cataloger | `cataloger` | 5 / 15 / 50 / 150 / 300 | Approved puzzles the player added to the catalog |

### Calibration (2026-10-11)

Approved by Jan on 2026-10-11 after reviewing the full table with holder counts per tier.

Jan: "1 single will not be enough (for example 1 solving in team, i know it is encouraging but it loses its rarity) …
instead of 1 make it at least 5 and slightly move further to have the gaps". The rule applied:

- **No tier for a single action.** A count type's Bronze is at least 5 (Team Spirit, Zen Puzzler, Unboxed, Marathoner,
  Photographer, Librarian and Cataloger were 1, Brand Explorer 3).
- **Keep the gaps.** Where the new Bronze ran into Silver (Team Spirit, Unboxed, Marathoner, Librarian were 1 → 5 with
  Silver at 5), the tiers above moved up just enough that every step stays a clear one (roughly 2-5×, like the old
  ladders); a tier whose rarity already fit stayed (every Diamond kept its threshold, so nothing became unreachable -
  each still has at least one holder).
- **The same spirit for time spans and times**, judged on the data:
  - Speed Demon 500 Bronze was "under 5 hours" - 99.6 % of everybody with a 500-piece solo time had it, 83 % of all
    puzzlers: a time almost everybody has is no achievement. Bronze/Silver became 2h / 1h30 (Silver was 2h); Gold
    1h, Platinum 45m and Diamond 30m stay - they are the community's milestones.
  - Speed Demon 1000 Bronze was "under 8 hours" (96 % of everybody with a 1000-piece time): 5h / 3h30, Gold+ unchanged.
    Both speed ladders now step by ~1.4× per tier, and the two ladders match (1000-piece = ~2.5× the 500-piece limit).
  - Steady Hands Bronze was 2 quarters in a row - 61 % of puzzlers, and two solves a day apart (31 March, 1 April)
    were enough. 3 / 6 / 9 quarters (at least 3 months between the first and the last solve); 12 and 16 stay.
  - On Fire (7 days), First Try (5), Puzzle Explorer (10), Piece Cruncher (10k pieces), Weekend Puzzler (10) already
    meet the rule and their ladders fit the data - unchanged.

Holders on the production copy (dev DB, 7,878 puzzlers = players with XP, the rarity denominator), B / S / G / P / D:

| Achievement | Before | After |
|---|---|---|
| Speed Demon 500 | 6,549 (83.1 %) / 5,872 (74.5 %) / 2,952 / 1,459 / 334 | 5,872 (74.5 %) / 4,958 (62.9 %) / 2,952 / 1,459 / 334 |
| Speed Demon 1000 | 2,328 (29.6 %) / 1,659 (21.1 %) / 753 / 221 / 22 | 1,990 (25.3 %) / 1,422 (18.1 %) / 753 / 221 / 22 |
| Team Spirit | 4,275 (54.3 %) / 2,822 / 1,371 / 358 (4.5 %) / 8 | 2,822 (35.8 %) / 1,371 / 358 / 54 (0.7 %) / 8 |
| Zen Puzzler | 1,170 (14.9 %) / 288 / 48 / 5 / 2 | 479 (6.1 %) / 208 / 48 / 5 / 2 |
| Unboxed | 1,320 (16.8 %) / 360 / 41 / 12 / 3 | 360 (4.6 %) / 88 / 27 / 12 / 3 |
| Brand Explorer | 4,235 (53.8 %) / 1,805 (22.9 %) / 580 / 114 / 9 | 3,064 (38.9 %) / 1,215 (15.4 %) / 388 / 114 / 9 |
| Marathoner | 468 (5.9 %) / 47 / 9 / 1 / 1 | 47 (0.6 %) / 16 / 3 / 1 / 1 |
| Photographer | 3,316 (42.1 %) / 447 / 140 / 8 / 1 | 1,366 (17.3 %) / 447 / 140 / 8 / 1 |
| Steady Hands | 4,808 (61.0 %) / 2,429 (30.8 %) / 858 / 166 / 29 | 3,290 (41.8 %) / 1,512 (19.2 %) / 607 / 166 / 29 |
| Librarian | 239 (3.0 %) / 58 / 12 / 3 / 1 | 58 (0.7 %) / 15 / 6 / 3 / 1 |
| Cataloger | 3,627 (46.0 %) / 901 (11.4 %) / 140 / 31 / 5 | 1,523 (19.3 %) / 615 (7.8 %) / 140 / 31 / 5 |

Tier rows held in total: 80,150 → 62,079. Marathoner's Bronze is now rarer than most Golds (0.6 %): five 2000+ piece
puzzles is a real feat, and Jan's rule is explicit - one marathon is no longer an achievement of its own.
The earlier calibration (2026-07-12, production figures of that time) is kept in
`docs/features/xp-levels/launch-checklist.md` §1 as history.

Tiers follow the current records (`BadgeEvaluator` removes what no longer qualifies), so a change of thresholds needs
no migration: the next evaluation of each player (the daily `recalculate-badges` run, or any result they log) brings
their tiers and Achievement Points to the new ladder. Nothing is told to anyone when a tier goes.

## Visibility rules

- A player's badges appear on their profile **only if the profile owner has an active membership** - for everyone
  allowed to see the profile (a private one: its owner only), as the **achievements showcase** near the top
  (`AchievementsShowcase`, 2026-10-10): one sideways-scrolling row, highest tier first, "11 of 17 · 640 AP ›" to the
  catalogue; the owner's unrevealed ones are "?" medallions leading to the reveal page. A free owner gets one quiet
  line ("You have earned achievements. Become a member to see them." - from their AP, no query), never a lock; nobody
  else sees anything.
- Badges are evaluated for *all* players regardless of membership — records accumulate silently. They become visible the moment the player's membership activates.
- The catalog `/en/achievements` (old `/en/badges` links 301 there) is public. Logged-in users see per-badge progress bars toward the next tier; logged-out users see the catalog without progress. A free player's earned tiers show locked with a "✓ 🔒" mark.
- Until the XP launch switch is on (`xp-launch`, `docs/features/feature_flags.md`) every one of these surfaces is admins-only.
- Achievements grant **Achievement Points only, never XP** (`docs/features/xp-levels/launch-2026-10.md`), and nobody gets an e-mail per achievement - they reach players in the app (post-solve recap, profile, reveal pages) and in the activity digest. A free player learns *that* a solve earned an achievement, never which (`GetBadges::countTiersEarnedWithSolve()`).

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
          → for each condition: compute qualifiedTiers, persist gaps, remove tiers no longer qualified
      → re-anchor player.achievement_points (under PlayerProgressionLock)
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
- No flag — immediate dispatch for every player (one async message each, ~7,600 on 2026-10-04); the daily safety-net
  cron. It is also what removes tiers that no longer qualify after changes no live path sees.

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

## In e-mails

There is no per-achievement e-mail (removed at the launch). The activity digest shows achievements as rows with their
badge (`templates/emails/_badge_row.html.twig`: the 96 px PNG at 48 px, absolute
URL, `alt=""` on purpose - the name and tier stand beside it, so the plain-text part and mail apps that block images
would otherwise say them twice). The weekly digest's earned and "within your reach" achievements use it.

## Cron

A daily safety net - every add/edit/delete of a time already dispatches the player's recalculation, so the cron only
heals drift (manually granted badges, paths that are not live-wired), in both directions: missing tiers are added,
tiers the records no longer qualify for are removed. `myspeedpuzzling:verify-progression` checks the points nightly. Not every 15 minutes: one run puts one message per
player with times on the `async` queue the transactional e-mails share. The production row (lily.srv) is in
`docs/features/xp-levels/README.md` §Cron.

On first deploy, seed existing players with (staggered, no e-mails - see `launch-runbook.md`):

```
docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:recalculate-badges --backfill
```

## UI handoff

What the UI already does, the owner decisions and the fine-tuning backlog (holo modal, animated shine, badge cabinet,
event passport): [`badges-ui-handoff.md`](badges-ui-handoff.md).
