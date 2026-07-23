# XP / Levels / Achievements

The gamification bundle: XP + levels for everyone, tiered achievements with Achievement
Points for members, weekly digest as the recurring touchpoint. Business spec authority:
`implementation-plan.md` §1 (locked); this file documents what was actually built.

## The three currencies

| Currency | Measures | Audience |
|---|---|---|
| XP + Levels (1–50, numeric only) | Activity | Everyone, free forever |
| Achievement Points (Bronze 5 · Silver 10 · Gold 25 · Platinum 50 · Diamond 100; single-tier 25) | Completion | Members-only display |
| MSP Rating / Points | Skill/speed | Untouched, fully separate |

XP is never purchasable. Levels gate nothing functional. Level 50 = 3,160 XP
(`src/Value/LevelTable.php`, curve v4 locked).

## Architecture

### Ledger

- `xp_entry` — one row per receipt line (`src/Value/XpReason.php`), amounts are signed
  ints. References to solves/badges are **plain uuid columns without FKs** so deleted
  solves keep their audit history. `Player.xpTotal`/`level` are denormalized and always
  equal `SUM(xp_entry.amount)` / `LevelTable::levelForXp()` — every write goes through
  `src/Services/Xp/XpLedger.php`.
- Idempotency anchors: unique partial indexes `custom_xp_entry_solve_reason
  (player_id, solving_time_id, reason) WHERE … reason != 'solve_compensation'` and
  `custom_xp_entry_badge (badge_id)` (mirrored in `tests/bootstrap.php`).
- `earned_at` carries the SOLVE's timestamp (`COALESCE(finished_at, tracked_at)`) for
  solve-derived entries, the badge's `earned_at` for achievements, clock-now only for
  settlements (which are excluded from weekly deltas via `in_weekly_delta = false`).
- Leaderboards never aggregate at scale: all-time reads `player.xp_total` (indexed),
  the AP ladder reads `player.achievement_points` (indexed), and the weekly tab scans
  only the current ISO-week slice via the partial covering index
  `custom_xp_entry_weekly_delta (earned_at, player_id, amount) WHERE in_weekly_delta`
  (mirrored in `tests/bootstrap.php`). `xp_entry.solving_time_id` is indexed for the
  receipt/delete/edit lookups.

### Formula (locked §1.2)

`core = base × difficulty × team × unboxed × occurrence`, decomposed into separate
entries (base / difficulty / unboxed), plus full-formula extras for solves tracked at or
after `XpCalculator::FULL_FORMULA_FROM`: speed bonus (5/10/15% vs puzzle percentiles,
solo timed, median from ≥3 distinct solvers, PPM plausibility guard), weekly boost
(+50% core for the first 5 XP-earning solves per ISO week) and daily warm-up (+2 flat).
Occurrence positions count ALL solves of the (player, puzzle) pair in canonical order
`(COALESCE(finished_at, tracked_at), id)`; repeats earn 50%/25%, relax first 50%,
relax repeats zero. Suspicious solves earn nothing. All of it lives in the pure
`src/Services/Xp/XpCalculator.php` — exhaustively unit-tested.

### Live wiring

`src/Services/Xp/XpChainRecomputer.php` behind async messages:

- add → `AwardXpForSolvingTime` (every registered team participant earns)
- edit → `RecalculateXpChainForSolve` (semantic delete+re-add of the (player, puzzle) chain)
- delete → `CompensateXpForDeletedSolve` (per-entry negative mirrors + chain rebuild)
- 15-min cron → `SettleXpBonuses` (ex-post difficulty/speed settlements, frozen forever)

Full deterministic rebuild: `RecalculateXpForPlayer` / `myspeedpuzzling:recalculate-xp`
(`src/Services/Xp/XpRecomputer.php`) — wipes solve-derived entries, replays history,
preserves achievement entries. Proven idempotent by integration test. Use it to repair
any drift (e.g. after puzzle merges or ownership transfers, which are not live-wired).

### Achievements

16 tiered achievement types + admin-granted Early Adopter (DB value `supporter`).
Achievement Points are denormalized to `player.achievement_points` — BadgeEvaluator
re-anchors the absolute total on every evaluation (badge writes happen nowhere else),
so the 15-minute recalc cron self-heals any drift (e.g. manually granted badges); the
AP ladder and every AP display read the column, never aggregate the badge table.
Metrics live in `GetPlayerStatsSnapshot` (owner counters batched in one FILTER-aggregate
query), conditions in `src/BadgeConditions/`. `BadgeEvaluator` persists gap-filled tiers
and grants each new tier its XP once (`BadgeTier::points()`). First-click reveal:
`badge.revealed_at` + `RevealBadge` message (flips lower tiers along).

Adding an achievement: see `docs/features/badges.md` — plus translation keys and (if a
new metric) a snapshot field. Everything else (catalog, holders directory, AP, XP grant)
picks it up automatically.

### Surfaces

Recap receipt + celebration (`XpSolveReceipt` inside the `XpRecapCelebration`
LiveComponent — one poll bridges the async award), profile/header rings (`XpRing`,
CSS-only milestone styling), achievements catalog `/achievements` + holders directory
`/achievements/{type}`, XP leaderboard `/players/xp-leaderboard` (weekly ledger delta /
all-time / AP tabs), audit page `/my/xp-history`, explainer `/how-xp-works`, fair-play
`/fair-play-xp`, one-time launch reveal `/my/xp-reveal` (DismissedHint-backed), share
cards `/xp-card/{playerId}/{launch|level-up}`.

### Weekly digest

See `docs/features/content-digest/README.md` (Phases 1–2 built; daily digest + rating
block deferred). Default-on, XP/achievements headline, no-activity variant never twice
in a row, signed one-click unsubscribe, `experienceSystemOptedOut` excluded.

### Opt-out

`Player.experienceSystemOptedOut` (settings → features) hides level, receipts,
celebrations, leaderboards, share cards and digests for that player; XP accrues
silently and everything returns on re-enable. Deliberately NOT a generic
"gamification" flag.

## Cron (production — add to the spare.srv crontab)

```cron
# XP bonus settlements — every 15 min, AFTER the puzzle-intelligence recalc
*/15 * * * * docker compose --file /deployment/speedpuzzling/docker-compose.yml run --rm messenger-consumer sentry-cli monitors run --schedule "*/15 * * * *" settle-xp-bonuses -- bin/console myspeedpuzzling:settle-xp-bonuses

# Achievements recalc — every 15 min (existing badges command)
*/15 * * * * docker compose --file /deployment/speedpuzzling/docker-compose.yml run --rm messenger-consumer sentry-cli monitors run --schedule "*/15 * * * *" recalculate-badges -- bin/console myspeedpuzzling:recalculate-badges

# Weekly content digest — Sundays 17:00 UTC (content-digest README §13; digest-consumer
# compose service must exist before first ramp)
0 17 * * 0 docker compose --file /deployment/speedpuzzling/docker-compose.yml run --rm messenger-consumer sentry-cli monitors run --schedule "0 17 * * 0" send-content-digest-weekly -- bin/console myspeedpuzzling:send-content-digest weekly
```

## Feature flag

`xp-system` — `src/Services/Xp/XpFeatureGate.php`, admin-only visibility + full email
suppression while active. Surface checklist: `leak-inventory.md`. Registry:
`docs/features/feature_flags.md`. Removal = launch day (see `launch-runbook.md`).
