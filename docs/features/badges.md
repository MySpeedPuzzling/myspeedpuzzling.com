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

21 tiered achievements (Bronze → Diamond) + the single-tier Early Adopter (`supporter`, admin-granted, no automation).
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
| Muscle Memory | `muscle_memory` | 3 / 8 / 15 / 30 / 50 | Most solo solves of one 500-piece puzzle (relaxed ones too) |
| Muscle Memory (unboxed) | `muscle_memory_unboxed` | 2 / 4 / 8 / 15 / 25 | The same, only solves without looking at the box |
| Night Owl | `night_owl` | 10 / 30 / 75 / 150 / 250 | Own results logged 22:00-04:00 in the player's time zone on the finish day |
| Competitor | `competitor` | 1 / 3 / 6 / 12 / 25 | Ended public in-person competitions taken part in |
| Online Competitor | `online_competitor` | 2 / 6 / 12 / 25 / 50 | Results linked to public online competitions |

**Every solve metric counts the player's own results and the pair/team results they were in, whoever logged them** -
like the solved puzzles page (only the two Speed Demons look at the player's own solo times). Until 2026-10-12 seven of
them (Zen Puzzler, First Try, Unboxed, Brand Explorer, Marathoner, Photographer, Weekend Puzzler) counted only the
results the player logged themselves - a relaxed pair result a teammate logged was missing (a player saw 63 relax
solves in the achievements and 184 on the solved puzzles page). Fixed in `GetPlayerStatsSnapshot` and the history
replay (`AchievementReplayState`) together. Production, 2026-10-12 (undo CSVs `/root/achievements-recount-2026-10-12-undo.csv`
and `/root/achievements-redate-2026-10-12-undo.csv` on lily):

1. `myspeedpuzzling:recalculate-badges` (8,114 messages, drained in ~2.5 min): 2,126 new tiers for 1,108 players.
2. `myspeedpuzzling:date-achievements-from-history --earned-since="2026-10-12 05:18"` (the deploy): all 2,126 dated,
   every replay matched.
3. `--earned-since="2000-01-01"`: the tiers already held had been dated by the old rule, so a newly earned higher tier
   could lie before a lower one; 4,382 tiers moved earlier (first try 1,604, weekend 1,455, brand explorer 947,
   photographer 211, zen 91, the rest ≤ 14 each - including 55 of other types earned after the launch by results
   finished before they were logged), none later, afterwards no tier dated before the tier below it.
4. Catch-up, same day (a player saw "Weekend Puzzler → Diamond 600 / 600" and "Marathoner → Bronze 6 / 5" without the
   tiers): step 1 was dispatched while the deploy was still rolling out (05:20:56-05:22:25 UTC), so the old
   messenger-consumer took the first messages - the lowest player ids, `018bd93f…` to `018e7045…` - and evaluated them
   with the old own-results-only counting. 121 tiers of 73 players were missing (weekend 47, first try 31, brand
   explorer 18, photographer 16, zen 6, marathoner 2, unboxed 1), nobody held a tier too many. `recalculate-badges
   --player` for those 73, then `--earned-since` that run: all 121 dated, every replay matched (undo CSV
   `/root/achievements-catchup-2026-10-12-undo.csv` on lily). **Start a recount only after the deploy has finished**
   (`run-queue: END` in `/var/log/lily/deploy.log`): the consumer is restarted at its end, and a message the old one
   takes is evaluated by the old rule.

### The five added 2026-10-28

Jan's calls (2026-10-28), each ladder chosen against the holder shares of the existing achievements on production
(8,396 puzzlers = the rarity denominator). The SQL of the last three lives once in `Query\AchievementSqlRules`, read by
`GetPlayerStatsSnapshot` and the history replay alike.

- **Muscle Memory** - repeating the very same puzzle until the hands know it. Only exactly 500 pieces, so nobody gets an
  edge from small puzzles (below 500 pieces people repeat puzzles four times as often; 81 % of all repeat solves are
  500s anyway), and **solo only** - the player's own solo results, relaxed ones counted, never a suspicious one. The
  value is the most-solved puzzle's count. Holders at launch: 31.2 / 6.7 / 1.4 / 0.17 / 0.02 % (Diamond: 2 players).
- **Muscle Memory (unboxed)** - the same rule on the unboxed solo solves, the main ladder halved (Diamond 25 has one
  holder; 50 had none - the best was 47). 2.2 / 0.57 / 0.11 / 0.04 / 0.01 %, about Marathoner's rarity.
- **Night Owl** (GitHub #149) - only players with a time zone (`player.timezone`, docs/features/player-timezone.md)
  can earn it. A result has no solve clock - `finished_at` is the day the player typed - so the moment is the logging
  (`tracked_at`, UTC) converted to the player's zone, and it counts only when that local day is the finish day: logged
  right after finishing = the solve moment. 22:00-04:00, own results only (a pair result a teammate logged carries the
  teammate's moment). Shares of the 973 puzzlers with a time zone: 44 / 24.5 / 8.6 / 2.9 / 0.8 % - on the catalogue
  (all puzzlers) it looks rarer until more players set one. **Every zone `TimezoneIdentifier` lists must be known to
  Postgres** (`AT TIME ZONE` fails the whole snapshot otherwise): `testPostgresKnowsEveryTimeZoneAPlayerCanHave` guards
  it - dev's pinned `postgres:16.0` lacks `America/Coyhaique` (tzdata 2025b), production (16.14) and CI (`postgres:16`)
  have it.
- **Competitor** (GitHub #148) - public in-person competitions (`IsCompetitionPubliclyVisible`, drafts never) that
  **have ended** (the day after `COALESCE(date_to, date_from)`, UTC) and that the player took part in: connected as a
  participant ("I'm going", an organiser's import or a manual entry) **or** in a result linked to it (own or pair/team)
  - many past competitors were never connected and only linked their time. Only competitions: no kind set, or a kind
  list with "competition" (an edition without its own kinds has its series'); swaps, workshops and casual meetups do
  not count. Dated in the replay at the day after the end, or later when the connection/result came later.
  10.4 % at Bronze connected-only, 20.5 / 5.6 / 1.3 / 0.06 / 0 % with linked results (Jan: count both; Diamond 25 is
  a target, nobody has more than 11 events yet).
- **Online Competitor** - online events are mostly one undated entry holding every session ("Speed Puzzling.com",
  "Puzzle Rush"), and only 5 players were ever connected to one against 805 with results, so the unit is the
  **result**: every result linked to a public online competition of the competition kind, or to an online series
  without an edition. Twice the in-person ladder: 6.8 / 3.3 / 1.55 / 0.54 / 0.13 %.

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

### Real dates (history dating, 2026-10-12)

The launch backfill earned every tier of the history at once, so each carried the backfill's own run time ("Held at
launch", design-spec §8.1). `myspeedpuzzling:date-achievements-from-history` (one-off, `--dry-run` first; re-runs are
no-ops) gives each such tier the moment the player's records first met it:

- **How:** `AchievementFirstMetDates` replays the player's history in date order through the badge conditions
  themselves - their results (their own and the pair/team results they were in, never a suspicious one), accepted
  proposals, approved puzzles - with the predicates of `GetPlayerStatsSnapshot` (`AchievementReplayState`). Every metric
  only grows (a best time only falls), so the first moment a tier qualifies is its date. A result counts from
  `finished_at`, or from `tracked_at` when it has none, when it is later than the logging (a typo) or before 2000; a
  proposal from its review; a puzzle from `approved_at`, else `added_at`.
- **Trusted only when the replay ends at the player's current snapshot** (`==`); otherwise nothing of theirs is dated.
  `AchievementFirstMetDatesTest` pins that parity for every fixture player.
- **Writes:** `DateAchievementsFromHistory` (sync, per player, under `PlayerProgressionLock`) → `Badge::datedFromHistory()`
  - only the tiers still inside the backfill's window (`HeldAtLaunch`: from `xp_settings.full_formula_from` to
  `launch_ready_at`), only ever to an earlier date. Tiers, points and reveal state are untouched; a tier the replay
  cannot date keeps its stamp and stays "held at launch".
- **After a corrected rule** (`--earned-since="<UTC moment>"`): a recount (`myspeedpuzzling:recalculate-badges`) earns
  the newly met tiers with its own run time; the option dates the tiers earned from that moment on instead of the
  launch backfill's, by the same replay and the same rules (a tier a normal solve earned meanwhile is dated too - to
  the same moment it would get anyway).
- **Dev copy (2026-10-12):** 62,111 stamped tiers of 6,612 players, all dated, every replay matched, 170 s (dev env).
  The activity digest is unaffected - it counts from the launch switch, and every dated tier is older.

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

6. Add it to the history replay (`AchievementReplayState` + `AchievementFirstMetDates`) so its tiers can be dated -
   the replay must end exactly at the snapshot (`AchievementFirstMetDatesTest`).
7. Pick the ladder from production holder shares (rarity bands of the existing ones, "Calibration" below), texts in
   all 6 locales.
8. Put it in the month's drop (`AchievementDrops`, next section) and release it with the runbook there.

Page tests count achievements through `tests/AchievementCounts.php` (from `BadgeType`), so a new one breaks none of them.

## Achievement drops

A few new achievements a month (Jan 2026-10-29), each batch released as one **drop**. The award is retroactive and
dated from history, so by date nothing of a drop is news: the digest lists only tiers earned in its period, the shelf's
"New" only tiers earned in the last 7 days. A drop therefore announces itself through `Value\AchievementDrops` (one
entry per drop: the deploy moment in UTC + its types) and through the reveal state (a tier nobody revealed yet):

| Where | What | Who |
|---|---|---|
| Catalogue cards + shelf cells | "New" tag on a drop's achievements for `AchievementDrops::NEW_FOR_DAYS` (30) - Twig `achievement_is_new(type)`; on a cell the owner's own "New" (earned in 7 days) wins | everybody |
| Activity digest e-mail + "Your week" page | the period holding a drop: "N new achievements", their Bronze seals linked to the catalogue, and "You already hold N of them" (members) / "Members collect achievements…" (others) | everybody with activity in the period |
| Activity digest e-mail + "Your week" page | "N achievements are waiting for their reveal moment" + Reveal them, whenever they were earned (the reveal page's rule: the highest tier of a type unrevealed) | members |
| Hub | the same invite (`XpRevealInvite`, one statement) | members |

No modal and no notification, on purpose: the progression design says celebrate inline, a modal only for rare
milestones (xp-levels/ux-polish/design-spec.md P5) - a monthly modal would be the opposite. No e-mail of its own
either: achievements reach players in the app and in the digest (launch-2026-10.md).

### Runbook (one drop)

1. Ship the achievements (section above) with their `AchievementDrops` entry - `releasedAt` = the planned deploy
   moment; correct it to the real one in the same release or the next if it moved by days (it only decides the digest
   week and the 30 "New" days).
2. Wait for the deploy to finish: `run-queue: END … result=OK` (`journalctl -u lily-deploy-runner` on lily). Earlier,
   a consumer of the previous release could take messages (2026-10-12 catch-up below).
3. `bin/console myspeedpuzzling:recalculate-badges --sync` in the web container: every player evaluated in that
   process, one after another (~8,400 players); it prints the dating command with its own start moment. One process
   is slower than the queue's consumers (dev: ~3 players/s under load), so start it with `nohup … > /root/drop.log &`.
4. Keep an undo file: `\copy (SELECT id, earned_at FROM badge WHERE earned_at >= '<start>') TO '/root/achievements-<drop>-undo.csv' CSV HEADER`.
5. `bin/console myspeedpuzzling:date-achievements-from-history --earned-since="<start>" --dry-run`, then without
   `--dry-run`. Expect every player matched and none failed; tiers of the drop only (check with a `GROUP BY type`).
6. Holders per tier for the announcement (Instagram, newsletter): `SELECT type, tier, count(*) FROM badge WHERE type IN (…) GROUP BY 1, 2`.

### The 2026-10-29 drop (production)

Muscle Memory, Muscle Memory (unboxed), Night Owl, Competitor, Online Competitor - deployed 14:20 UTC, then
`recalculate-badges` (async, 8,408 messages, drained in ~3 min) and `date-achievements-from-history
--earned-since="2026-10-29 14:21"`: 9,217 tiers, all dated, every replay matched, none failed (undo CSV
`/root/achievements-new-five-2026-10-29-undo.csv` on lily). Holders, Bronze → Diamond: Muscle Memory 2,631 / 563 /
115 / 15 / 2; unboxed 187 / 48 / 9 / 3 / 1; Night Owl 434 / 242 / 84 / 28 / 8; Competitor 2,087 / 600 / 140 / 8 / 0;
Online Competitor 981 / 548 / 329 / 135 / 43. Competitor's Bronze came out at ~25 % against the 20.5 % measured
the day before. Its announcement (the surfaces above) was built the same day, after the award.

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
