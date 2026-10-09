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
- Leaderboards never aggregate at scale: both ladders read the denormalized
  `player.xp_total` / `player.achievement_points` columns (both indexed), and the weekly
  digest scans only its ISO-week slice via the partial covering index
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
so the daily recalc cron self-heals any drift (e.g. manually granted badges); the
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
CSS-only milestone styling; the arc is a masked `::before`, so transparent avatars never
show a filled disc), achievements catalog `/achievements` + holders directory
`/achievements/{type}` + explainer modal `/achievements/{type}/info`
(`AchievementInfoController`, opened from every medallion on a profile through the shared
`modal-frame`), the XP + Achievement Points ladders `/players/xp-leaderboard`, audit page
`/my/xp-history`, explainer `/how-xp-works`, fair-play `/fair-play-xp`, one-time launch
reveal `/my/xp-reveal` (DismissedHint-backed), share cards
`/xp-card/{playerId}/{launch|level-up}`.

**Two ladders, two disciplines** (`GetXpLeaderboard::xp()` / `::achievementPoints()`,
both on `xp_leaderboard` as tabs `xp` + `achievement-points`): XP ranks activity and is
open to everyone, Achievement Points rank completion and list members only (free players
may look, logged-in only). Deliberately NOT merged — chasing XP and chasing AP are
different games and a player may play either or both. **XP is always shown, including past
Level 50**, where it keeps accruing even though the level stops moving. The weekly-delta
tab is gone (a 7-day slice was noise next to two all-time boards); `xp_entry.in_weekly_delta`
stays because the weekly digest still reads it.

Discovery: both boards are linked from `/ladder` (CTA banner + two entries in the ladder
switcher dropdown) and the footer leaderboards list; the achievements catalog links the AP
board; the audit page is linked from the owner's own profile next to the ring. Every one of
those links is wrapped in `xp_system_visible()` (`XpTwigExtension`) so nothing points at a
page that 404s while the flag is active.

### Weekly digest

See `docs/features/content-digest/README.md` (Phases 1–2 built; daily digest + rating
block deferred). Default-on, XP/achievements headline, no-activity variant never twice
in a row, signed one-click unsubscribe, `experienceSystemOptedOut` excluded.

### Opt-out

`Player.experienceSystemOptedOut` (settings → features) hides level, receipts,
celebrations, leaderboards, share cards and digests for that player; XP accrues
silently and everything returns on re-enable. Deliberately NOT a generic
"gamification" flag.

## Cron (production — lily.srv)

Production is `lily.srv` (`/srv/myspeedpuzzling`, spare.srv is decommissioned). App crons live in the
lily.srv infra repo, `apps/myspeedpuzzling/cron.d/myspeedpuzzling` (installed by `deploy.sh`, `CRON_TZ=Europe/Prague`,
every job a one-off `compose run --rm --no-deps messenger-consumer` wrapped in `lily-cron-run` + `sentry-cli monitors
run`). Add these rows **in the same change that deploys this branch** - before that the commands do not exist in the
production image and every run would fail. Minutes are picked so no two one-off containers start together (see the
file's header; free minutes as of 2026-10-04: :01 :06 :12 :16 :21 :27 :31 :36 :42 :46 :51 :57).

```cron
# XP bonus settlements (docs/features/xp-levels/README.md in the app repo) - every 15 min, 4 min AFTER
# recalculate-puzzle-intelligence (:02/:17/:32/:47), whose difficulty/speed numbers it settles against
6-59/15 * * * * root lily-cron-run myspeedpuzzling settle-xp-bonuses -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "6-59/15 * * * *" --timezone "Europe/Prague" --failure-issue-threshold 2 settle-xp-bonuses -- bin/console myspeedpuzzling:settle-xp-bonuses >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
# Achievements safety net - once a day. Every add/edit/delete of a time already dispatches the player's
# recalculation; a run dispatches one async message per player with times (~7,600 on 2026-10-04), so
# every 15 min would put ~730k messages a day on the queue the transactional e-mails share
16 3 * * * root lily-cron-run myspeedpuzzling recalculate-badges -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "16 3 * * *" --timezone "Europe/Prague" recalculate-badges -- bin/console myspeedpuzzling:recalculate-badges >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
# Weekly content digest - Sundays 18:01 Prague (CRON_TZ is supported on lily, so no DST drift any more);
# needs the digest-consumer service (content-digest README §13) before the first run
1 18 * * 0 root lily-cron-run myspeedpuzzling send-content-digest-weekly -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "1 18 * * 0" --timezone "Europe/Prague" send-content-digest-weekly -- bin/console myspeedpuzzling:send-content-digest weekly >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
```

## Feature flag

`xp-system` — `src/Services/Xp/XpFeatureGate.php`, admin-only visibility + full email
suppression while active. Surface checklist: `leak-inventory.md`. Registry:
`docs/features/feature_flags.md`. Removal = launch day (see `launch-runbook.md`).

## Rebase onto main 2026-10-04 — what changed, what is open

Main gained ~330 commits while this branch waited. Adapted in the rebase (own commits on top of the branch):

- **E-mail address**: `player.email` is gone on main - the three mail handlers ask `PlayerAccountEmail`, the recipient
  queries join `user_account` (the only address a player has).
- **Result write paths main added** now keep the ledger and achievements in step (`XpAndBadgesWiringTest`):
  automatic duplicate removal and "keep this copy" compensate the deleted copies, Undo of a removal and an accepted
  guest link run full rebuilds (`RecalculateXpForPlayer`), a puzzle merge rebuilds the chain of every moved result, an
  edit that moves a result to another puzzle rebuilds its members fully (the chain it left changes too), add / edit
  recalculate achievements of every registered member (any member may edit a group time now), and
  `moveToPuzzle()` / `migrateToPuzzle()` take the new puzzle's pieces count into `pieces_count_snapshot`.
- **Privacy**: XP / AP ladders and the holders lists leave out players the viewer blocks (`HiddenPlayers`, inside
  the ranking) and stay closed to private players for everybody (global rankings); the XP ring follows the allow
  list (`PrivateProfileAccess::sqlIsPrivate()`); the digest's favourites block applies the recipient's blocks and the
  allow list in SQL; the public share card of a private player carries `#CODE`, never the name (`-hidden` path).
- **Auth / Turbo**: `IS_AUTHENTICATED_REMEMBERED` instead of `_FULLY` (remember-me visitors were bounced to the login,
  the reveal POST was silently lost); links in the achievement modal leave the frame, a non-frame visit of the modal
  URL redirects to the achievement page.
- **E-mails**: `email_document` + preheaders (6 locales); the unsubscribe follows main's contract
  (`ContentDigestUnsubscribeUrl`, never expires, `/{_locale}/weekly-digest/unsubscribe/{id}`, one-click 200 / button
  303, `no-store`); the reveal mail goes through the `notifications` transport; the queue is `content_digest_emails`
  (main's unread-messages digest owns `digest_emails_*`); routine bounces and the plausibility guard log at `info`.

**Decided by Jan 2026-10-04 - to build in the follow-up sessions** (this session only caught up with main):

1. **Per-type e-mail settings.** The weekly digest is its own e-mail type with its own switch and its own
   unsubscribe, separate from the unread-messages digest (`emailNotificationsEnabled`), which stops gating it. XP and
   achievements are content *of* the weekly digest. → Digest eligibility = the digest switch only (drop
   `email_notifications_enabled` from `GetPlayersForContentDigest` / `SendPlayerContentDigestHandler`); the digest
   switch also appears on main's per-type token page (`email_preferences`, `EditEmailPreferences`) next to the
   newsletter, the unread-messages digest and the result e-mails.
2. **No per-achievement e-mails.** One initial e-mail at launch (the XP reveal), afterwards new achievements reach
   players only through the weekly digest (its "achievements earned" block / members-only teaser already exists).
   → Remove `SendBadgeNotificationEmail` + handler + `emails/badges_earned.html.twig` + the `badges_earned.*` keys;
   `RecalculateBadgesForPlayerHandler` keeps persisting badges and stops mailing. The reveal mail is gated by the
   digest switch (its unsubscribe link is the digest's).
3. **Monday, ~12k recipients, the whole Monday.** Puzzlers are busy over weekends, so the digest goes out on Monday
   about the week that just ended.
   - **Period bug to fix first:** `SendContentDigestConsoleCommand` builds `DigestPeriod::weeklyFor(now)` - the
     *current* ISO week. Run on a Monday it would mail an empty digest for the week that just started. It must use
     the previous, completed week (`weeklyFor(now - 7 days)`); the staleness TTL (week end + 3 days) still fits.
   - **Pacing over the day instead of 250 ms bursts:** the command spreads the `DelayStamp`s evenly over a send window
     (e.g. 07:00-19:00 Prague, 12 h for ~12k = one every ~3.6 s, ~1,000/h), computed from the recipient count, on the
     dedicated `content_digest_emails` transport/consumer. Most engaged first (README §14 ramp), so early bounces /
     complaints show on the best addresses.
   - **Quality guard:** a circuit breaker in the handler - when permanent failures (550-553) or deferrals of the
     current run cross a threshold, stop sending (remaining messages ack as skipped, logged at warning once) instead
     of burning the domain's reputation; per-run counts from `content_digest_log` / `email_audit_log` on an admin
     line.
   - **Open input:** Seznam Email Profi's fair-use ceiling per day (content-digest README, open question 1). If 12k/day
     is above it, the bulk stream needs its own sending provider; the window logic stays the same.
   - Cron: `1 7 * * 1` (Monday 07:01 Prague) instead of Sunday 18:01.
4. **Duplicate avatar under the profile header** - a leftover, not an avatar-rework bug as such: the branch's profile
   wraps its *own* 52 px avatar in `XpRing` below main's new `PlayerHeader`, which renders the avatar itself. → Put
   `XpRing` around the header's avatar (`player-head-avatar` in `components/PlayerHeader.html.twig`, also the
   compact bar if wanted) and delete the extra block in `player_profile.html.twig`; needs a visual check → feedback
   session.
5. **Launch fully on day 1 of the deploy** - see "Formula cutoff" below.
6. **Weekly digest only, never daily.** `ContentDigestFrequency` (none / daily / weekly) was built for a daily digest
   that never came (the command refuses `daily`, but the settings form offers it, and "daily" players are treated as
   weekly). → Replace it with a boolean `player.weekly_digest_enabled` (default true) - fits item 1. The column is
   not in production yet, so the branch migration `Version20260713151509` can simply be changed (local databases that
   already ran it need it re-run by hand). Remove the `daily` argument, `DigestPeriod` stays weekly-only.
7. **Badges recalculation** - a separate low-priority queue, or a cron if a full run stays under 15 minutes: to be
   measured on the local near-production database. The cheap queue: a `low_priority` Doctrine transport consumed by
   the existing worker as `messenger:consume async low_priority` (Symfony drains the transports in that order, so
   transactional mail on `async` always goes first) - no new container. Measure on a *scratch copy* of the dev
   database (a run writes badge rows): wall time of `myspeedpuzzling:recalculate-badges` with the messages routed
   `sync`, plus the same for `myspeedpuzzling:xp-backfill`.

**Formula cutoff (`XpCalculator::FULL_FORMULA_FROM`).** XP for history is deliberately smaller than XP for new
solves: a solve *logged* (`tracked_at`) before the cutoff earns the base + difficulty + unboxed parts only, one logged
from the cutoff on also the incentives - speed bonus, weekly boost (+50 % for the first 5 solves of an ISO week),
daily warm-up (+2) and the ex-post difficulty / speed settlements (`SettleXpBonuses` only looks at solves after it).
The incentives reward behaviour the player could see and react to, so they must not be paid retroactively - otherwise
every past week of every veteran gets boosts and the launch ladder is flooded. Today the cutoff is a hard-coded
`2026-08-01`, i.e. already two months of history would count as "new". Proposal for "fully live from the deploy": the
cutoff becomes the moment the feature reaches production, set by the deploy itself - a migration stores `NOW()` once
(e.g. `xp_settings.full_formula_from`, read through a small cached service instead of the constant; tests insert
their own value). Every solve logged before the deploy is history, every solve after it gets the full formula, the
launch backfill (`myspeedpuzzling:xp-backfill`) can run any time after the deploy, and nobody has to remember to edit a
constant. With "fully live", `XP_SYSTEM_ADMIN_ONLY` ships as `0` (or the gate is removed before the merge) and the
reveal mail goes out the same day.

**Open - follow-ups (no decision needed):**

- Production: `content_digest_emails` consumer + `deploy.sh` restart + the cron rows above, in lily.srv, with the merge.
- Per-page cost after launch: the header ring runs `GetXpProfile` on every signed-in page; the estimate / receipt /
  celebration re-query the opt-out. Carry `xp_total`, `level`, `experience_system_opted_out` on the viewer's
  `PlayerProfile` row (main's zero-query pattern).
- One subscriber on main's result events (`PuzzleSolvingTimeDeleted` / `Modified` / `MovedToOtherPuzzle`) would cover
  future write paths without per-handler dispatches; plus an XP safety-net cron that rebuilds players with entries on
  results that are gone, suspicious or no longer theirs (nothing heals XP today).
- A result id that comes back after deletion (Undo, a resent form, an API idempotency key) has compensated history:
  the award skips it and a chain rebuild keeps the compensation. Undo uses full rebuilds now; the proper fix drops a
  net-zero history with a compensation line in `awardForNewSolve()` / `rebuildPair()`.
- `XpLedger::append()` reads and writes `player.xp_total` without a lock - a recompute next to the consumer can lose
  an update (`SerializedByLock`).
- First-try achievement counts the tracker's rows only; main makes a pair/team first try everybody's (one per person
  per puzzle). Piece-count achievements read the live pieces count, XP the snapshot. Moderator piece-count fixes and
  SQL-set `suspicious` flags never touch XP (runbook: `myspeedpuzzling:recalculate-xp --player`).
- API returns badges regardless of membership / opt-out, the web shows them to members only.
- E-mail polish: Inky buttons for the calls to action, `p.small-print`, the digest's settings link to the token
  preferences page (`EmailPreferencesLinkGenerator`), a hosted hero image instead of the 185 KB inline PNG, no stored
  body for `xp_reveal` in the audit log, a real plural in `badges_earned.title`.
- `CleanupEmailAuditLogsHandler` re-dispatches its next batch inside the open transaction, so the batches are still
  one transaction - loop in the command instead.
- Canary tests get `xp_leaderboard` + `achievement_detail` on launch day (`feature_flags.md`).
