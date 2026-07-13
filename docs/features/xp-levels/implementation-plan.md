# XP / Levels / Achievements — Implementation Plan (Executable Backlog)

> **Audience:** a Claude Code agent implementing this feature end-to-end.
> **Nature:** deterministic, resumable backlog. This file is the single source of progress truth.
> **Business spec authority:** this file + `docs/features/xp-levels/launch-checklist.md` (decisions) +
> `docs/features/badges.md` (shipped achievements architecture) + `docs/features/content-digest/README.md`
> (digest sending mechanics). If this plan and code conventions conflict, `CLAUDE.md` conventions win.

---

## 0. EXECUTION PROTOCOL (read first, every session)

### State tracking & resume

- **STATE line** (update after every completed task):

  ```
  STATE: phase=P2 last_completed=P1.T9 branch=feature/dynamic-badges-system
  ```

- Every task below is a `- [ ]` checkbox with a stable ID (`P2.T3`). Work strictly in order within a
  phase; phases strictly in order (P0 → P8) unless a task is marked `[parallel-ok]`.
- **Resume protocol:** read the STATE line, find the first unchecked task, verify the previous task's
  acceptance criteria actually hold (spot-check, don't trust blindly), continue.
- After completing a task: tick its checkbox, update the STATE line, commit (see below). Never batch
  more than one phase into one commit.

### Git & environment

- Branch: **continue on `feature/dynamic-badges-system`** (the badges PR #128 branch — this plan extends
  it into one end-to-end branch). Commit per task or small task group; message style follows repo
  history (`Area: change`). Do NOT push or open a PR unless asked.
- **The badges system already on this branch shipped UNFLAGGED** — P0 retrofits the feature gate onto
  it so the entire branch is silently deployable from the first commit of this plan.
- All PHP commands run inside docker: `docker compose exec web <cmd>`. JS: `docker compose exec js-watch <cmd>`.
  Port conflicts: see CLAUDE.md overrides (`POSTGRES_PORT=55432 …`).
- **Never run** `doctrine:migrations:migrate` (ask Jan). **Never hand-write migrations** — generate with
  `docker compose exec web php bin/console doctrine:migrations:diff` (manual SQL allowed only for
  custom indexes / data UPDATEs appended to a generated migration).
- **Quality gate — run after EVERY phase** (all must pass before ticking the phase-complete task):

  ```
  docker compose exec web composer run phpstan
  docker compose exec web composer run cs-fix
  docker compose exec web vendor/bin/phpunit --exclude-group panther
  docker compose exec web php bin/console doctrine:schema:validate
  docker compose exec web php bin/console cache:warmup
  ```

### Subagent orchestration (context management)

- Keep the main context for: this plan, integration decisions, migrations, wiring, verification.
- Delegate to subagents (general-purpose, one per batch) work that is **self-contained + spec-complete**:
  - P3 condition classes + their unit tests (spec table is exhaustive — perfect subagent batch).
  - P5 static content pages (explainer, fair-play) once copy exists.
  - Translation-file mechanical fills (P8).
- Give each subagent: the exact task IDs, the relevant spec tables from this file (copy them into the
  prompt), file paths, the convention pointers (`CLAUDE.md`, an example neighbor file), and require the
  quality-gate commands on their output. Verify their diff yourself before ticking tasks.
- Do NOT delegate: entity/migration work, messenger wiring, feature-gate plumbing, anything touching
  `AddPuzzleSolvingTimeHandler`/`EditPuzzleSolvingTimeHandler`/`DeletePuzzleSolvingTimeHandler`.

### Required reading before P0 (skim, don't deep-read)

`CLAUDE.md` · `docs/features/badges.md` · `docs/features/xp-levels/launch-checklist.md` ·
`docs/features/content-digest/README.md` · `docs/features/feature_flags.md` · `.claude/fixtures.md` ·
`.claude/symfony-ux-hotwire-architecture-guide.md` · `src/Services/Badges/BadgeEvaluator.php` ·
`src/Query/GetPlayerStatsSnapshot.php` · `src/Entity/Badge.php` · `src/Entity/Player.php` ·
`config/packages/messenger.php` · `templates/components/BadgesProfileSection.html.twig`

---

## 1. BUSINESS SPECIFICATION (complete, locked — do not re-litigate)

### 1.1 Three currencies

| Currency | Measures | Audience | Behavior |
|---|---|---|---|
| **XP + Levels** (new) | Activity | FREE for everyone | Never decreases except solve deletion/edit-down |
| **Achievement Points (AP)** (new) | Completion (sum of earned achievement-tier values) | Members-only display | Never decreases (cascade only on solve deletion affecting tiers — tiers are permanent per badges.md, so AP never decreases) |
| MSP Rating / MSP Points (existing) | Skill / speed | untouched | **No changes, stay fully separate** |

Locked principles: **XP boosts/multipliers are NEVER purchasable** — no paid XP of any kind, ever.
Levels gate nothing functional (no level-gated features); rewards are visual identity only.

Terminology (locked): the system is **"Achievements"**; "badge" = only the graphic medallion.
Existing admin-granted **Supporter → display-renamed "Early Adopter"** (keep DB enum value `supporter`).

### 1.2 XP formula

**Canonical solve ordering** (for occurrence/repeat logic and recompute determinism):
`ORDER BY COALESCE(finished_at, tracked_at), id` per (player, puzzle).

**Cutoff constant** `XP_FULL_FORMULA_FROM` (a `\DateTimeImmutable` const, set at deploy/launch):
solves with `tracked_at` **before** it use the *backfill formula*; at/after it, the *full formula*.

```
core = base × difficulty × team × unboxed × occurrence

base       = clamp(pieces_count / 100, 1, 60)        # pieces snapshot at log time (see 1.8)
difficulty = tier 1–2: 1.00 · tier 3: 1.15 · tier 4: 1.30 · tier 5: 1.40 · tier 6: 1.50
             (puzzle_difficulty.difficulty_tier; unrated: 1.00 now + one-time settlement later, §1.4)
team       = 0.75 when puzzling_type IN (duo, team) — every listed participant earns (incl. owner);
             1.00 for solo
unboxed    = 1.20 when unboxed flag, else 1.00
occurrence = timed 1st solve of puzzle: 1.00 · timed 2nd: 0.50 · timed 3rd+: 0.25
             relax (seconds_to_solve IS NULL) 1st: 0.50 · relax repeat: 0.00
             (NO personal-best exception — deliberately removed)

FULL formula additions (tracked_at ≥ cutoff only):
speed bonus   = core × 0.05 / 0.10 / 0.15 for faster-than-median / top-25% / top-10%
                conditions: solo + timed + puzzle median exists from ≥3 DISTINCT solvers
                + plausibility guard passed (§1.8). Duo/team & relax: never.
weekly boost  = core × 0.50 for the first 5 XP-earning solves per ISO week (UTC)
daily warm-up = flat +2 XP for the first XP-earning solve per UTC day

BACKFILL formula (tracked_at < cutoff): core only. Difficulty = tier at backfill run time,
settled immediately, frozen forever. NO speed/weekly/daily bonuses, NO pending settlements
for historical solves.
```

**Ledger decomposition (one entry per receipt line — base and extras are SEPARATE, locked UX
requirement).** Mathematically identical to the formula above, decomposed as:

```
base_part       = base × team × occurrence                    → SolveBase entry
difficulty_part = base_part × (difficulty − 1)                → SolveDifficultyBonus entry (tier ≥ 3 only)
unboxed_part    = (base_part + difficulty_part) × 0.20        → SolveUnboxedBonus entry (unboxed only)
core            = base_part + difficulty_part + unboxed_part  (not stored — derived)
speed_part      = core × {0.05|0.10|0.15}                     → SolveSpeedBonus entry
weekly_part     = core × 0.50                                 → SolveWeeklyBoost entry (first 5/week)
warmup          = flat 2                                      → SolveDailyWarmup entry (first of day)
```

Each part rounded half-up to int independently at persist; zero-valued parts create NO entry;
`base_part` min 1 when core > 0. BACKFILL solves persist only the first three entry kinds.
Receipt lines render 1:1 from ledger entries (repeat/relax discount folds into the base line's
LABEL, never a negative line).

### 1.3 Level curve v4 (locked — Level 50 = 3,160 XP total)

| Lv | Σ | Lv | Σ | Lv | Σ | Lv | Σ | Lv | Σ |
|---|---|---|---|---|---|---|---|---|---|
| 2 | 5 | 12 | 87 | 22 | 273 | 32 | 689 | 42 | 1628 |
| 3 | 10 | 13 | 100 | 23 | 301 | 33 | 753 | 43 | 1770 |
| 4 | 16 | 14 | 114 | 24 | 331 | 34 | 822 | 44 | 1924 |
| 5 | 22 | 15 | 129 | 25 | 363 | 35 | 897 | 45 | 2091 |
| 6 | 29 | 16 | 145 | 26 | 399 | 36 | 978 | 46 | 2272 |
| 7 | 37 | 17 | 162 | 27 | 438 | 37 | 1066 | 47 | 2468 |
| 8 | 45 | 18 | 181 | 28 | 480 | 38 | 1161 | 48 | 2680 |
| 9 | 54 | 19 | 201 | 29 | 526 | 39 | 1264 | 49 | 2910 |
| 10 | 64 | 20 | 223 | 30 | 576 | 40 | 1376 | 50 | 3160 |
| 11 | 75 | 21 | 247 | 31 | 630 | 41 | 1497 | | |

Σ = cumulative XP required to REACH that level. Level 1 = 0 XP. Level 50 is max; XP keeps accruing
in the ledger past 3,160 but no further levels. Levels are **numeric only — no names**.
Calibration invariant (verify in P7): backfill yields **~115 instant-Lv50 players (±10)**.

### 1.4 Ex-post settlement (go-forward solves only)

- Solve on unrated puzzle → `solve_base` entry now; when the puzzle FIRST gets a `puzzle_difficulty`
  row (cron runs every 15 min), a one-time `difficulty_settlement` entry lands using the tier at that
  moment. Same for speed: when the puzzle first reaches a ≥3-distinct-solver median, one-time
  `speed_settlement` for qualifying earlier solves. **Settled once, frozen forever** — later tier/median
  drift never revises entries.
- Settlement entries are **excluded from weekly-delta** (they are not "this week's activity").
- UI: pending state shown on receipt ("difficulty bonus pending — settles when this puzzle is rated").

### 1.5 Decrease rules (the ONLY ways XP drops)

- **Delete solve:** compensating negative entries for all that solve's XP (and recompute the remaining
  player+puzzle chain — occurrence promotions — deterministically). Delete dialog must warn:
  "you will lose N XP earned by this solve". Level may drop.
- **Edit solve:** semantically delete+re-add → recompute that solve's chain, both directions.
- Achievements/AP are never revoked (badges.md invariant), so achievement XP entries are never compensated.

### 1.6 Achievements — locked launch lineup (16 tiered + Early Adopter)

XP & AP per tier (locked): **Bronze 5 · Silver 10 · Gold 25 · Platinum 50 · Diamond 100**; single-tier
(Early Adopter) 25. Each earned tier row grants its XP once (ledger `achievement` entry, gap-filled
tiers each grant). AP total = same values summed over earned tiers.

Existing 5 (shipped, PR #128 — no threshold changes): Puzzle Explorer 10/100/500/1000/2000 ·
Piece Cruncher 10k/100k/500k/1M/2M · Speed Demon 500 <5h/2h/1h/45m/30m · On Fire 7/30/90/180/365-day
streak · Team Spirit 1/5/25/100/500.

New 11 (metric semantics EXACTLY as below; "owner" = `pst.player_id` rows only; "participant" =
owner OR team JSON member, the `GetPlayerStatsSnapshot` pattern; always `suspicious = false`):

| # | BadgeType case | Metric | Tiers B/S/G/P/D |
|---|---|---|---|
| 6 | `ZenPuzzler = 'zen_puzzler'` | owner solves with `seconds_to_solve IS NULL` | 1/10/50/150/365 |
| 7 | `FirstTry = 'first_try'` | owner solves with `first_attempt = true` | 5/50/200/500/1000 |
| 8 | `Unboxed = 'unboxed'` | owner solves with `unboxed = true` | 1/5/25/50/100 |
| 9 | `BrandExplorer = 'brand_explorer'` | owner distinct `puzzle.manufacturer_id` | 3/10/25/50/100 |
| 10 | `Marathoner = 'marathoner'` | owner solves of puzzles `pieces_count >= 2000` | 1/5/15/40/100 |
| 11 | `Photographer = 'photographer'` | owner solves with `finished_puzzle_photo IS NOT NULL` | 1/25/100/500/1000 |
| 12 | `SteadyHands = 'steady_hands'` | participant longest run of consecutive quarters with ≥1 solve (dates ≥ 2000-01-01) | 2/4/8/12/16 quarters |
| 13 | `Librarian = 'librarian'` | approved `puzzle_change_request` + `puzzle_merge_request` rows by `reporter_id` (`status = 'approved'`) | 1/5/20/50/100 |
| 14 | `SpeedDemon1000 = 'speed_1000_pieces'` | owner fastest SOLO timed 1000pc (like Speed Demon 500) | <8h/4h/2.5h/1h45m/1h15m |
| 15 | `WeekendPuzzler = 'weekend_puzzler'` | owner solves with ISODOW of `COALESCE(finished_at, tracked_at)` IN (6,7) | 10/50/150/300/600 |
| 16 | `Cataloger = 'cataloger'` | `puzzle` rows with `approved = true AND added_by_user_id = player` | 1/10/50/150/300 |

Deferred (do NOT implement; GitHub issues in P8): Competitor, Night Owl (timezone problem), quest
board, leagues, Puzzle Passport, Year in Puzzling, secret achievements, abuse admin tooling, daily
digest, SVG badge frames.

### 1.7 Visibility matrix (enforce everywhere; leak inventory in P0.T3)

| Surface | Free user (self) | Free user (public) | Member (self/public) |
|---|---|---|---|
| Level, XP, progress, receipt | ✔ full | ✔ (unless opted-out/private) | ✔ |
| Achievements detail/medallions | 🔒 locked strip + count teaser "N achievements waiting for you" | ✖ nothing | ✔ (+ first-click confetti reveal, §1.9) |
| AP total / AP ladder | 🔒 teaser + read-only ladder at Lv50 | ✖ | ✔ |
| Per-achievement congratulation email | ✖ never | — | ✔ (existing badges email) |
| Holders directory lists | members only listed; counts include everyone ("+N more puzzlers") | | ✔ listed |

- Private profiles & XP-opted-out players: excluded from ALL public lists/leaderboards.
- New opt-out: `Player.experienceSystemOptedOut` (follow `streakOptedOut` pattern; deliberately
  NOT a generic "gamification" flag — future gamification features like quests get their own
  separate opt-outs) — hides level/receipt/
  celebrations/leaderboards/digest for that player; XP accrues silently; reversible.
- At Level 50 the post-solve XP receipt is NOT shown; its slot shows nearest-achievement progress
  (member) / waiting-achievements teaser (free).

### 1.8 Anti-abuse guards (all automatic, all silent)

1. Per-solve XP cap: base clamp 60 (≈6000pc).
2. Speed bonus requires median from ≥3 distinct solvers.
3. `pieces_count` snapshotted onto `puzzle_solving_time` at log time (new column; backfill from
   current puzzle values in the same generated migration).
4. Relax repeats earn 0.
5. Plausibility guard: pieces-per-minute above threshold (constant `MAX_PLAUSIBLE_PPM = 30` for
   ≥500pc timed solo — comfortably ABOVE world-record pace ≈17–20 PPM, so no legitimate solve is
   ever affected) → no speed bonus + `warning` log (Sentry visible), zero user-facing accusation.
6. No pending window for new catalog puzzles (full trust, decided); junk cleanup = solve deletion
   cascade already removes XP.
7. Fair-play policy page ships at launch (copy from Jan-approved draft).

### 1.9 UX moments (build to this spec; copy drafts arrive separately)

**Design quality bar (locked, applies to EVERY surface in this plan):**
- **Mobile-first** — most solves are logged from phones. Design every screen at ~360px width first,
  then scale up; thumb-reachable primary actions; ≥44px tap targets; no horizontal scroll.
- **Friendly, modern, clean** — soft pastel MSP brand language (coral #EC726F, sky blue #69b3fe,
  indigo #4e54c8, navy outlines #2b3445, rounded geometry, puzzle-piece motifs). Bootstrap 5 base,
  match existing component styling (`assets/styles/`), generous whitespace, no visual clutter.
- **Clarity over confusion** — every number on screen answers "what is this and why do I have it"
  within one tap (tooltip/ⓘ or inline label). Copy voice: warm puzzle-friend at the same table —
  one sentence of feeling + one concrete stat; never guilt, never gamer jargon, never corporate.
  Zero states always name the one next action and its reward.
- **Performance discipline** — CSS-only animation where possible, no CLS (skeleton heights per
  the performance doctrine in `docs/performance-optimizations.md`), `prefers-reduced-motion`
  respected everywhere, celebrations always skippable (tap anywhere).

- **Post-solve receipt** (recap page, mobile-first): additive lines, never negative ("repeat solve"
  folds into base line label), staggered CSS entrance, progress bar fills after total. Confetti
  scarcity: normal solve none · level-up full-screen (brand colors) · Diamond-tier achievement
  interstitial · queue, never two at once. `prefers-reduced-motion` honored. Free users additionally
  get one quiet teaser line when the solve progressed a hidden achievement: "This solve counted
  toward N waiting achievements 🔒".
- **Lazy achievement/level check**: lazy Live Component on recap page polls once for async-granted
  achievements/level-ups (bridges sync render ↔ async messenger evaluation), pops celebration.
- **First-click badge reveal**: earned badge medallions start "unrevealed"; first click flips with
  confetti (new nullable `revealed_at` on `badge` + endpoint). Membership-activation reveal page
  reuses this (all pending reveals in sequence).
- **Profile**: thin XP ring around avatar + level chip + progress bar; achievements strip per matrix.
- **Header**: same ring/chip component, ambient (no numbers).
- **Leaderboard** `/players/xp-leaderboard`: tabs This week (default) / All time; weekly delta from
  ledger (excluding settlements/backfill); country + favorites filters (reuse ladder patterns);
  pinned self-row; Lv50 players show "Lv 50 · {AP} AP" (AP only if member).
- **Puzzle detail**: "Solving this earns ~N XP + up to M bonus" + personalized repeat/PB-free note +
  unrated pending note.
- **XP audit page** (`/my/xp-history`): every ledger entry with reason, amount, link to solve —
  user-facing auditability is a locked requirement.
- **Explainer page** (public) + **fair-play page** (public): static, from approved copy.
- **Launch reveal page** (one-time per player): medallion assembly animation, XP counter spin,
  share CTA. Hero assets: `docs/features/xp-levels/assets/xp-hero-1200.{png,webp}`.
- **Share cards**: level-up + launch cards via `ResultImageController` pipeline pattern; background
  `docs/features/xp-levels/assets/share-card-background-800.png` (129 KB) — overlay level medallion +
  name + text in the empty center.

### 1.10 Weekly digest (v1 = weekly only; daily deferred)

Implement per `docs/features/content-digest/README.md` **Phases 1–2 only**, with these locked deltas:
default-on (`ContentDigestFrequency` default `weekly`), XP/achievements block is the headline
(member full detail / free teaser), no-activity variant sends but never twice in a row, footer =
notification-settings link + signed one-click unsubscribe, digest disableable in messaging settings,
suppressed entirely while feature flag active, unread-messages digest untouched,
**`experienceSystemOptedOut` players excluded from digest eligibility** (add to the eligibility SQL).

### 1.11 Feature flag & rollout

- Flag: single gate service `src/Services/Xp/XpFeatureGate.php` — `isVisibleFor(?PlayerProfile): bool`
  → while flag is ON, true only for admins (`ADMIN_ACCESS` / existing admin check); document in
  `docs/features/feature_flags.md` (convention). ALL surfaces in §1.7/§1.9 check it — including the
  badge surfaces ALREADY SHIPPED on this branch (retrofitted in P0.T4). Emails/digests/notifications
  suppressed while ON; persistence (badges, XP ledger) runs for everyone silently.
  **Exempt (OK to leak): public API + Swagger.**
- Launch sequence: merge flagged → deploy → silent backfill on prod → admin verification (+ P7
  distribution check ≈115 max-level) → remove flag (deploy) → run reveal-email command same day.

---

## 2. BACKLOG

### P0 — Preflight & scaffolding

- [x] **P0.T1** Check out `feature/dynamic-badges-system`, pull latest. Read the required-reading list (§0).
- [x] **P0.T2** Feature gate: `XpFeatureGate` service + entry in `docs/features/feature_flags.md`
  (flag name `xp-system`, gated files list grows as phases land, removal condition = launch day).
- [x] **P0.T3** Copy §1.7 + §1.9 surfaces into `docs/features/xp-levels/leak-inventory.md` as a
  checklist — INCLUDING the badge surfaces already shipped on this branch (BadgesProfileSection
  component, badges overview/catalog page + route, badge congratulation email path). Every UI task
  below must tick its row there when gated.
- [x] **P0.T4** Retrofit the gate onto the already-shipped badge surfaces: `BadgesProfileSection`
  renders nothing for non-admins while flagged; badges catalog route/page admin-only while flagged;
  `RecalculateBadgesForPlayerHandler` → `SendBadgeNotificationEmail` dispatch short-circuited while
  flagged (badge PERSISTENCE keeps running for everyone — silent accumulation is intended; only
  visibility + emails are gated). WebTestCase proving non-admin sees nothing + no email dispatched.
- [x] **P0.T5** Phase gate: quality-gate commands pass (baseline green). Update STATE.
  NOTE (environment, pre-existing): dev DB has pending branch migration `Version20260416210601`
  (badge.tier) — `doctrine:schema:validate` sync check fails until Jan runs dev migrations; mappings
  validate clean (`--skip-sync`), drift == exactly the pending migrations. Panther suite has
  pre-existing env flakiness (verified identical on baseline); non-panther suite = green
  (`--testsuite "Project Test Suite"` — note: `--exclude-group panther` does NOT exclude them,
  Panther classes carry no group attribute; exclusion is testsuite-based).

### P1 — XP domain core (pure logic first, exhaustively unit-tested)

- [x] **P1.T1** `src/Value/XpReason.php` string enum: `SolveBase`, `SolveDifficultyBonus`,
  `SolveUnboxedBonus`, `SolveSpeedBonus`, `SolveWeeklyBoost`, `SolveDailyWarmup`,
  `DifficultySettlement`, `SpeedSettlement`, `Achievement`, `SolveCompensation` — 1:1 with the §1.2
  ledger decomposition.
- [x] **P1.T2** `src/Value/LevelTable.php` (pure static): the §1.3 curve; `levelForXp(int): int`,
  `xpForLevel(int): int`, `progressToNext(int): ?float`. Unit tests incl. boundaries (0, 4, 5, 3159, 3160, 99999).
- [x] **P1.T3** Entity `src/Entity/XpEntry.php`, table `xp_entry`: id (uuid7), player_id (uuid, indexed),
  amount (int, signed), reason (XpReason string), solving_time_id (uuid NULL, **plain column, no FK**),
  badge_id (uuid NULL, plain), in_weekly_delta (bool), earned_at (datetime_immutable), created_at (Clock).
  **`earned_at` semantics (CRITICAL — weekly delta correctness):** solve-derived entries =
  `COALESCE(solve.finished_at, solve.tracked_at)`; achievement entries = `badge.earned_at`;
  settlement entries = settlement run time (they're `in_weekly_delta = false` anyway). NEVER
  clock-now for solve-derived entries — backfill/recompute would otherwise dump 450k historical
  entries into launch week's delta leaderboard.
  Unique partial indexes: `custom_xp_entry_solve_reason` ON (solving_time_id, reason) WHERE
  solving_time_id IS NOT NULL AND reason != 'solve_compensation'; `custom_xp_entry_badge` ON
  (badge_id) WHERE badge_id IS NOT NULL — both idempotency anchors. Index (player_id, earned_at).
  Repository persist-only. Generated migration (+ custom indexes appended manually, mirrored in
  `tests/bootstrap.php`).
- [x] **P1.T4** `Player`: add `xpTotal` (int, default 0) + `level` (smallint, default 1) +
  `experienceSystemOptedOut` (bool, default false, `changeExperienceSystemOptedOut()`), generated migration.
- [x] **P1.T5** `puzzle_solving_time.pieces_count_snapshot` (int NULL) — generated migration + manual
  UPDATE backfilling from current `puzzle.pieces_count`. New solves set it at creation
  (Add/Edit handlers). XP always reads the snapshot (fallback to puzzle value when NULL).
- [x] **P1.T6** `src/Services/Xp/XpCalculator.php` — PURE service: input = solve context DTO (pieces,
  difficulty tier, type, unboxed, timed?, occurrence index, cutoff side, week/day counters, median
  percentile), output = list of (XpReason, int amount). Encodes ENTIRE §1.2 incl. rounding. Unit tests:
  every multiplier, occurrence ladder, relax, team, backfill-vs-full, caps, PPM guard exclusion,
  boundary rounding. Aim for the exhaustive table-driven style of `tests/BadgeConditions/*`.
- [x] **P1.T7** `src/Services/Xp/XpLedger.php` — persistence orchestrator: award entries + update
  `Player.xpTotal`/`level` in same transaction (messenger middleware handles flush); returns
  level-up info. `src/Query/GetXpProfile.php` (total, level, progress), `GetXpEntriesForSolve.php`,
  `GetXpHistory.php` (paginated, for audit page).
- [x] **P1.T8** Recompute: message+handler `RecalculateXpForPlayer` — rebuilds a player's FULL ledger
  deterministically from solve history (canonical ordering §1.2): deletes that player's solve-derived
  entries, recreates, preserves `Achievement` entries, restores totals. Console command
  `myspeedpuzzling:recalculate-xp [--player=UUID] [--all]` (thin, dispatches). Integration test:
  seed solves → recompute twice → identical ledger (idempotency proof).
- [x] **P1.T9** Phase gate: quality gates + review §1.2 against `XpCalculator` line by line. STATE.
  IMPLEMENTATION NOTES (P1): `custom_xp_entry_solve_reason` is `(player_id, solving_time_id, reason)` —
  player_id HAD to join the key because every team participant earns entries for the same solve (§1.2);
  the plan's original `(solving_time_id, reason)` collides on team solves (proven by integration test).
  Occurrence position counts ALL solves of (player, puzzle) regardless of mode ("relax repeat" = any
  prior solve of the puzzle). Suspicious solves earn no XP (consistent with badges/statistics/intelligence).
  Recompute reproduces difficulty/speed as regular bonus parts from CURRENT tier/median (settlement
  entries exist only between live-award and the next recompute). `pieces_count_snapshot` is set in the
  PuzzleSolvingTime constructor (covers Add + Tracking handlers automatically). xp_entry cleanup added
  to DeletePlayerHandler (plain-column reference, no FK).

### P2 — Live wiring (award, edit/delete, settlement)

- [ ] **P2.T1** `AddPuzzleSolvingTimeHandler`: after persist, dispatch (async) `AwardXpForSolvingTime`
  (new message+handler → XpCalculator+XpLedger; computes occurrence via canonical ordering; snapshot
  pieces). Mirror existing `RecalculateBadgesForPlayer` dispatch pattern.
- [ ] **P2.T2** `EditPuzzleSolvingTimeHandler` + `DeletePuzzleSolvingTimeHandler`: dispatch chain
  recompute (delete = compensations + chain recompute; edit = same). Delete confirmation dialog
  (template where delete is offered): warn with the solve's current XP sum (`GetXpEntriesForSolve`).
- [ ] **P2.T3** Achievement XP: in `BadgeEvaluator` (or a listener on its persist path), for each newly
  persisted tier create `Achievement` xp_entry (values §1.6) — including gap-filled tiers. NO xp for
  re-evaluations (unique badge rows already guarantee once-only).
- [ ] **P2.T4** Settlement: console command `myspeedpuzzling:settle-xp-bonuses` — for go-forward solves
  lacking `DifficultySettlement` whose puzzle now has difficulty: settle; same for speed. Frozen via
  the P1.T3 unique index. Wire into cron docs (same 15-min cadence, AFTER intelligence recalc).
  `in_weekly_delta = false` on settlement entries. Tests.
- [ ] **P2.T5** Weekly boost / daily warm-up counters: computed inside `AwardXpForSolvingTime` from
  ledger (count this ISO-week/day `SolveBase` entries for player, UTC). Deterministic under recompute
  (recompute replays canonical order). Tests: 6 solves in a week → 5 boosted; midnight boundaries.
- [ ] **P2.T6** Email rules on the existing `SendBadgeNotificationEmail` path: (a) short-circuit while
  `XpFeatureGate` flag ON (done in P0.T4 — verify it composes); (b) **post-launch rule: send ONLY to
  players with active membership** (free users never receive per-achievement emails — they can't see
  the badges; they get the digest teaser instead, §1.7). Tests prove both.
  **Email inventory rule (locked): the weekly digest + the members-only achievement email are the
  ONLY recurring emails this system sends. NO level-up emails, no per-XP emails — do not invent any.**
- [ ] **P2.T7** Phase gate: quality gates; fixture doc `.claude/fixtures.md` updated if fixtures grew. STATE.

### P3 — Achievements expansion `[parallel-ok — ideal subagent batch]`

- [ ] **P3.T1** Extend `PlayerStatsSnapshot` + `GetPlayerStatsSnapshot` with the 11 metrics of §1.6
  (owner vs participant semantics EXACT; quarterly streak = island detection like the existing
  streak calculator but quarter-granular, dates ≥ 2000-01-01 guard for the year-0024 data bug).
  Keep it a fixed number of queries (batch metrics into few SQLs, not 11).
- [ ] **P3.T2** 11 new `BadgeType` cases (§1.6 values) + 11 condition classes in `src/BadgeConditions/`
  (10 extend `AbstractAscendingThresholdCondition`; `SpeedDemon1000Condition` mirrors
  `Speed500PiecesCondition` descending logic). Unit tests per class (copy existing test style).
- [ ] **P3.T3** Early Adopter rename: translations only (`badges.badge.supporter` → "Early Adopter"
  label text), keep enum value. Grep templates for hardcoded "Supporter".
- [ ] **P3.T4** Translation keys (EN only now): `badges.badge.*`, `badges.description.*`,
  `badges.requirement.*_{1..5}` for all 11 + rule-clarifying descriptions for non-obvious ones
  (Steady Hands "no gaps, quarters", Librarian "accepted only", First Try vs Unboxed distinction).
- [ ] **P3.T5** AP: `BadgeTier::points()` (5/10/25/50/100) + single-tier constant 25;
  `src/Query/GetAchievementPoints.php` (player AP total); wire achievement XP values (P2.T3) to same source.
- [ ] **P3.T6** Phase gate: quality gates + run `myspeedpuzzling:recalculate-badges --player=<fixture>`
  in dev proving new conditions evaluate. STATE.

### P4 — UI phase A: solve loop surfaces (all behind XpFeatureGate)

- [ ] **P4.T1** Post-solve receipt component on recap page (`templates/added_time_recap.html.twig`
  area): server-rendered lines from `GetXpEntriesForSolve` + progress bar (LevelTable), §1.9 rules
  (Lv50 replacement slot, pending-bonus dim line, opted-out hides). CSS-only animations.
- [ ] **P4.T2** Lazy Live Component `XpRecapCelebration` (src/Component/): polls once (or defers via
  `loading="lazy"` livecomponent idiom) for async results; level-up interstitial + confetti (small
  vendored lib or CSS, ≤3KB, reduced-motion). Queueing rule: level-up before achievement toast.
  **Level 50 variant:** golden full celebration for EVERYONE (never paywalled), then a fork screen —
  member: enter the AP ladder; free: the same AP ladder READ-ONLY (real names, real totals) +
  membership CTA. No free-month grant (explicitly decided against).
- [ ] **P4.T3** Profile: avatar XP ring + level chip + progress (in `PlayerHeader` region of
  `templates/player_profile.html.twig`), achievements strip states per §1.7 incl. free-user locked
  strip + "N waiting" teaser; `revealed_at` column (generated migration) + reveal endpoint
  (single-action controller, POST) + first-click confetti flip; membership-activation reveal page
  reusing it. Respect private/opted-out branches (mirror existing `rankingOptedOut` template pattern).
  **Milestone ring styling is CSS-ONLY** (locked): gradient ring variants intensifying at levels
  10/20/30/40, golden at 50 — brand palette, zero image assets.
- [ ] **P4.T4** Header avatar ring (shared Twig component with P4.T3; no numbers).
- [ ] **P4.T5** Puzzle detail XP estimate line (+ personalized repeat note, unrated pending note).
- [ ] **P4.T6** Phase gate: quality gates + leak-inventory rows ticked for every touched surface
  (verify as non-admin in dev: NOTHING visible). STATE.

### P5 — UI phase B: pages

- [ ] **P5.T1** Achievements catalog rework: rename UI "Badges"→"Achievements" (route alias/redirect
  from `/badges`), AP chips per tier, §1.7 free-user "Earned ✓ — waiting 🔒" states, progress bars stay.
- [ ] **P5.T2** Holders directory: catalog cards link to `/achievements/{type}` detail page — per-tier
  holder lists (avatar, name, country flag, earned date), country filter, "first to earn" highlight,
  newest earners, member-only lists + full counts ("+N more puzzlers"), private/opted-out excluded.
  Query with proper indexes (check EXPLAIN on badge table).
- [ ] **P5.T3** XP leaderboard page per §1.9 (weekly default from ledger `in_weekly_delta`, all-time
  from `player.xp_total`, pinned self-row, filters, Lv50→AP display) + an **Achievement Points tab**
  (members ranked by AP total; viewable by all logged-in users — this is the "read-only AP ladder"
  free Lv50 players are pointed to).
- [ ] **P5.T4** XP audit page `/my/xp-history` (paginated ledger with reasons + solve links).
- [ ] **P5.T5** Explainer + fair-play pages (static controllers+templates). DRAFT real EN copy from
  §1 of this plan (three-currency table, formula with worked examples, level table, FAQ; fair-play:
  trust principles + what's automatically unrewarded — never publish exact guard thresholds), and
  mark both templates `<!-- COPY:pending-jan-approval -->` at top for Jan's review pass.
- [ ] **P5.T6** Launch reveal page (one-time: `revealed_launch_at` on Player or reuse DismissedHint
  pattern — pick DismissedHint-style row, no Player column) + level share-card route in
  `ResultImageController` style using the 800×800 background asset + launch/level-up card variants.
- [ ] **P5.T7** Phase gate: quality gates + full leak-inventory pass as non-admin. STATE.

### P6 — Weekly digest (content-digest Phases 1–2, weekly only)

Follow `docs/features/content-digest/README.md` §16 Phase 1 + Phase 2 checklists verbatim, with
§1.10 deltas. Key tasks (tick the README's boxes too):

- [ ] **P6.T1** README Phase 1 complete (enum default `weekly`, `ContentDigestLog`, message+transport+
  routing incl. dev/test config, handler with staleness/eligibility/failure classification, query,
  console command with stagger, messaging-settings preference UI, unsubscribe controller + signed
  URLs + headers, tests, audit adjustments §12).
- [ ] **P6.T2** Weekly template + blocks: XP/achievements headline (member/free variants per §1.7),
  week-in-numbers, streak recap (honor streakOptedOut), favorites roundup, next-achievement progress
  (member), no-activity variant + never-twice-in-a-row eligibility (README §7). Skip daily-only
  blocks entirely. Footer: notification settings link + unsubscribe.
- [ ] **P6.T3** Digest suppressed while feature flag ON (gate inside dispatch command + handler).
- [ ] **P6.T4** Phase gate: quality gates; send test digest to Mailpit in dev (both variants + teaser
  variant), verify rendering. STATE.

### P7 — Launch tooling & verification

- [ ] **P7.T1** Backfill orchestration command `myspeedpuzzling:xp-backfill`: dispatches
  `RecalculateXpForPlayer` for all players with solves (DelayStamp stagger pattern from
  `RecalculateBadgesConsoleCommand`), then achievements recalc (`--backfill` suppressed-email mode
  already exists — verify email suppression composes with P2.T6 flag check).
- [ ] **P7.T2** Verification command `myspeedpuzzling:xp-distribution`: prints level pyramid + instant-
  max count + top-20 totals. Acceptance: dev fixtures sane; prod expectations documented inline —
  the hard calibration invariants are **≈115 players at Level 50 (±10, = 1.6%)** and **median player
  around Level 13–14**; rank-115 total ≈ 3,190+. (Do not invent per-bracket percentage targets —
  they were not calibrated for the final curve; the two invariants above are the acceptance test.)
- [ ] **P7.T3** One-time reveal email: message+handler+command `myspeedpuzzling:send-xp-reveal-emails`
  (staggered, transactional transport, hero asset embedded, per-player level/stats, List-Unsubscribe
  headers, one-per-player idempotency log — mirror ContentDigestLog pattern with type `xp_reveal`).
- [ ] **P7.T4** Cron documentation block in `docs/features/xp-levels/README.md` (see P8.T1): settle
  command cadence, digest weekly cron (from content-digest README §13), recalc integration.
- [ ] **P7.T5** Launch-day runbook `docs/features/xp-levels/launch-runbook.md`: exact ordered commands
  (backfill → verify → flag removal deploy → reveal emails → digest ramp start), rollback notes
  (flag re-add), Jan's manual steps (cron entries, FB posts).
- [ ] **P7.T6** Phase gate: quality gates. STATE.

### P8 — Hardening, i18n, docs, deferred issues

- [ ] **P8.T1** Write `docs/features/xp-levels/README.md` — the feature's living doc (business rules
  §1 condensed, architecture actually built, adding-an-achievement how-to, cron, flag). Update
  `CLAUDE.md` Feature Planning section pointer + `docs/features/feature_flags.md` final state.
- [ ] **P8.T2** Leak functional test (WebTestCase): as anonymous + as logged non-admin non-member,
  request every §1.7/§1.9 surface (profile, leaderboard, catalog, holders, explainer, audit page,
  share-card routes, recap) → assert zero XP/level/achievement traces while flag ON. This test is
  DELETED on launch day together with the flag (note in feature_flags.md).
- [ ] **P8.T3** Anti-abuse verification tests: cap, <3-solver median, PPM guard, relax-repeat-zero.
- [ ] **P8.T4** Translations: EN complete → run missing-translations workflow to fill cs/de/es/fr/ja
  for ALL new keys (messages + emails). Mark cs achievement names for Jan's native review in the
  launch checklist.
- [ ] **P8.T5** Create GitHub issues (gh CLI) for every §1.6-deferred item + SVG frames + timezone
  handling + daily digest + abuse tooling; link them in launch-checklist §6.
- [ ] **P8.T6** Final full gate: all five quality commands + `vendor/bin/phpunit` full suite green +
  leak test green + STATE line set to `phase=DONE`.
- [ ] **P8.T7** Update `docs/features/xp-levels/launch-checklist.md`: tick implementation-complete,
  leave Jan's items (images, copy approvals, ops) clearly outstanding.

---

## 3. OUT OF SCOPE (do not build)

Jan's deliverables: badge tier-frame + icon images (fallback medallions cover absence), final copy
approvals, cs native review, Seznam inquiry, FB posts, prod cron entries, running prod backfill/
migrations. Deferred features: everything listed in §1.6-deferred. MSP Points/Rating: untouched.
Listmonk: untouched. Daily digest: untouched (design exists, build later).

## 4. KNOWN TRAPS (learned during design — respect these)

- `finished_at` data contains year-0024 rows (pre-2000 guard REQUIRED in any date-window SQL).
- Difficulty tiers run 1–6 (not 1–5) — multipliers in §1.2 are per-tier explicit.
- `doctrine_transaction` middleware wraps handlers — never throw after persisting what must commit
  (see content-digest README §6 for the exact pattern).
- FrankenPHP worker mode: any new service caching per-request state implements `ResetInterface`.
- Modal-frame forms need explicit `action:`; stream responses gate on `Turbo-Frame` header (CLAUDE.md).
- Template changes need container restart in dev (`docker compose restart web`) — PHP changes don't.
- Tests test handlers/services directly, never console commands.
- The badge email uses `X-Transport: transactional`; digests use `notifications`.
