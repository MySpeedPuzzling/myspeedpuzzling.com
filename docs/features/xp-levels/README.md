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

XP is never purchasable. Levels gate nothing functional. Level 50 = 7,000 XP (curve v7) - about 1,300 logged puzzles including
the bonuses (`src/Value/LevelTable.php`, curve v5, Jan 2026-10-11; why and how it was measured: `launch-2026-10.md` §1
"XP balance").

## Architecture

### Ledger

- `xp_entry` — one row per receipt line (`src/Value/XpReason.php`), amounts are signed
  ints. **Every entry comes from a logged puzzle** - achievements grant Achievement Points
  only, no XP (`launch-2026-10.md`; the old achievement entries and the `badge_id` column
  were dropped at the launch). The solve reference is a **plain uuid column without FK** so
  deleted solves keep their audit history. `Player.xpTotal`/`level` are denormalized and always
  equal `SUM(xp_entry.amount)` / `LevelTable::levelForXp()` — every write goes through
  `src/Services/Xp/XpLedger.php`, which writes the entries in bulk and then reads the total back from the ledger
  (never carried forward), under the per-player lock (§Anti-farming).
- Idempotency anchor: unique partial index `custom_xp_entry_solve_reason
  (player_id, solving_time_id, reason) WHERE … reason != 'solve_compensation'` (mirrored
  in `tests/bootstrap.php`).
- `earned_at` carries the SOLVE's timestamp (`COALESCE(finished_at, tracked_at)`) for
  solve-derived entries, clock-now only for
  settlements (which are excluded from weekly deltas via `in_weekly_delta = false`).
- Leaderboards never aggregate at scale: both ladders read the denormalized
  `player.xp_total` / `player.achievement_points` columns (both indexed), and the weekly
  digest scans only its ISO-week slice via the partial covering index
  `custom_xp_entry_weekly_delta (earned_at, player_id, amount) WHERE in_weekly_delta`
  (mirrored in `tests/bootstrap.php`). `xp_entry.solving_time_id` is indexed for the
  receipt/delete/edit lookups.

### Formula (§1.2, v5 2026-10-11)

`core = base × difficulty × team × unboxed × occurrence`, decomposed into separate
entries (base / difficulty / unboxed), plus full-formula extras for solves tracked at or
after the formula cutoff (`xp_settings.full_formula_from`, read through `XpFormulaCutoff` - the
deploy moment, see "Formula cutoff" below): speed bonus (5/10/15% of core vs puzzle percentiles,
solo timed, median from ≥3 distinct solvers, PPM plausibility guard) and weekly boost
(+20% of core for the first 5 XP-earning solves per ISO week). Speed and weekly boost are taken from the unrounded
core. **Every part the solve qualifies for is worth at least 1 XP** (base, difficulty, unboxed, speed, weekly boost -
each rounded half-up, then floored at 1): "faster than the median" on a 500-piece puzzle was 5 % of 5 XP and rounded
to nothing. No daily warm-up (removed with the +50 % boost, launch-2026-10.md §1 "XP balance").
Occurrence positions count ALL solves of the (player, puzzle) pair in canonical order
`(COALESCE(finished_at, tracked_at), id)`; repeats earn 50%/25%, relax first 50%,
relax repeats zero. Suspicious solves earn nothing. All of it lives in the pure
`src/Services/Xp/XpCalculator.php` — exhaustively unit-tested.

### Live wiring

`src/Services/Xp/XpChainRecomputer.php` behind async messages:

- add → `AwardXpForSolvingTime` (every registered team participant earns; also an id that comes back - Undo of a
  removal, a resent form)
- edit → `RecalculateXpChainForSolve` (+ the members before the edit)
- delete → `CompensateXpForDeletedSolve` (per-entry negative mirrors, then the rebuild; + its members)
- suspicious mark set or cleared (moderator, the player's fix, SQL reconciled by the scan) →
  `PuzzleSolvingTimeSuspicionChanged` → `RecalculateProgressionOnSuspicionChange` (XP + achievements)
- 15-min cron → `SettleXpBonuses` (ex-post difficulty/speed settlements)

Each of them rebuilds the **scope** a change can reach, from the one replay (`XpHistoryReplay`) the full rebuild uses
too: the player's whole chain on the puzzle plus every solve of the ISO weeks the touched solves are in now or were in
(their old entries' `earned_at`) - the weekly boost goes to the first solves of the week. Only
solves whose entries differ from the replay are rewritten, so the XP history of the others keeps its dates.

Full deterministic rebuild: `RecalculateXpForPlayer` / `myspeedpuzzling:recalculate-xp`
(`src/Services/Xp/XpRecomputer.php`) — wipes the ledger, replays history. Proven idempotent by integration test;
moves to another puzzle, guest links and the repair of the nightly check use it.

### Achievements

16 tiered achievement types + admin-granted Early Adopter (DB value `supporter`).
Achievement Points are denormalized to `player.achievement_points` — BadgeEvaluator
re-anchors the absolute total on every evaluation (badge writes happen nowhere else),
so the daily recalc cron self-heals any drift (e.g. manually granted badges); the
AP ladder and every AP display read the column, never aggregate the badge table.
Metrics live in `GetPlayerStatsSnapshot` (owner counters batched in one FILTER-aggregate
query), conditions in `src/BadgeConditions/`. `BadgeEvaluator` brings the tiers to what the current records
qualify for: new tiers gap-filled, tiers that no longer qualify removed (§Anti-farming), AP only. First-click reveal:
`badge.revealed_at` + `RevealBadge` message (flips lower tiers along).

Adding an achievement: see `docs/features/badges.md` — plus translation keys and (if a
new metric) a snapshot field. Everything else (catalog, holders directory, AP, XP grant)
picks it up automatically.

### Surfaces

Recap after logging a time = **one progress card** (launch-2026-10.md "After logging a time"): the live rows come
from the `XpRecapCelebration` LiveComponent (achievements this time earned + the level line with the receipt one tap
away in a `<details>`), the page adds the place on the puzzle and - for piece counts with a rating (500) - MSP Rating /
skill tier (members; free players one locked line). **The save settles the saving player's achievements and XP before
it redirects** (`SavedTimeProgression`, add form + relax, achievements first): measured 2026-10-11 on the production
copy at 3-9 ms for a typical player and at most 90 ms for the heaviest (2,300 results), so the recap shows both at its
first render - Jan's rule: under 300 ms it belongs on the result page, at once. The messages the save queued stay the
safety net (other pair/team members, a failure - both recompute from the records, so a second run changes nothing).
Only when the XP is still missing does the component poll, with a growing delay (`POLL_DELAYS_MS`, ~30 s), and then
say the XP is on its way; the render showing the XP is the last one. Dev runs the queue in its own `messenger-consumer`
service (compose.yml), like production.
"This time earned an achievement" = badge rows stamped within 10 minutes after the time's
`tracked_at` and before the player's next time (`GetBadges::earnedWithSolve()` / `countTiersEarnedWithSolve()`;
badges do not point at a solve; the badge message is queued before the XP one). Members reveal it
in place with the last-piece puzzle (`reveal_badge`, so a reload shows the badge); free players read "You earned an
achievement with this time. Become a member to find out which." Level-up: the "Level up!" pill always; the motion
(bar fill, number tick, burst) and the milestone bottom sheet (10/20/30/40/50) only on a fresh recap
(`RecapProgress::isFresh`, 60 min) and once per time id, remembered in localStorage by
`recap_celebration_controller.js` - no server state, closing needs no request, nothing replays on reload or Back.
Both recap routes are quiet routes (no announcement modal). The level ring around the player header's
avatar and the navbar avatar (`XpRing`, read from the profile row - no query; the arc is a masked
`::before` outside the avatar, one colour per 10 levels - lime, aqua, violet, magenta, obsidian, gold at 50 - on the ring, its track
and the "Lv 12" tab: `.xp-level-band-*`, `XpRing::band()`, design-spec §9; the recap's level row shows the same ring around the
player's face), achievements catalog `/achievements` + holders directory
`/achievements/{type}` + explainer modal `/achievements/{type}/info`
(`AchievementInfoController`, opened from every badge on a profile through the shared
`modal-frame`; `?tier=` = the clicked badge, shown big at the top - badge art:
`docs/features/badges.md` §Badge art), the XP + Achievement Points ladders `/players/xp-leaderboard`, audit page
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
page that 404s before the launch switch is on (wrappers to be removed after the launch).

### Weekly digest

Reworked 2026-10-10 into the **activity digest** + the "Your week" page - see `docs/features/content-digest/README.md`
(the part above "History"): own switch with a frequency (weekly default / every two weeks / monthly / off), Monday sends
about the completed period paced over the day with a circuit breaker, no digest without activity, "We miss you" on Tuesdays after 28 quiet days (once per spell), paused until the period of 12-18 Oct 2026 and while the launch switch is off. `experienceSystemOptedOut`
no longer stops the e-mail; it leaves out the XP and level lines.

### Opt-out

`Player.experienceSystemOptedOut` (settings → features) hides level, receipts,
celebrations, leaderboards, share cards and digests for that player; XP accrues
silently and everything returns on re-enable. Deliberately NOT a generic
"gamification" flag.
Achievements are not XP and stay on with it: they have their own switch,
`Player.achievementsOptedOut` ("Hide my achievements", docs/features/opt-out.md).

## Anti-farming: path independence

Jan, 2026-10-11: adding a time, deleting it and adding it again (or any other repeated action) must earn nothing.
**The invariant:** after ANY sequence of adds, edits, deletions, re-adds, moves to another puzzle, duplicate removals
and their Undo, guest links, merges and suspicious marks set or cleared, a player's ledger total, level, achievements
(tiers) and Achievement Points - and karma - equal a fresh recomputation from their current records.

Where it is enforced:

| What | How |
|---|---|
| XP ledger | One replay for everything (`XpHistoryReplay`): live wiring rebuilds the reachable scope with it (§Live wiring), the full rebuild all of it. Back-dating a solve into the past never makes a repeat a first solve, nor hands out a 6th weekly boost: the slots belong to the first XP-earning solves by solve time. An id that comes back after deletion drops its compensated history and earns once. Solves logged before the formula cutoff earn the history formula however they are replayed (cutoff by `tracked_at`). |
| Suspicious marks | A mark after the award takes the XP and the tiers it was needed for away, clearing gives them back (event `PuzzleSolvingTimeSuspicionChanged`, recorded by every flag change incl. `suspicionChangedOutsideTheApp()`). |
| Achievements | Follow the current records: `BadgeEvaluator` removes tiers the records no longer qualify for (silently - no e-mail, no notice; the removed row takes its reveal state along, earned again it is new and unrevealed), AP re-anchored. Every write path that removes results queues the evaluation for every registered member (delete, automatic duplicate removal, "keep this copy", suspicion). Never removed: an achievement without a condition (Early Adopter, `supporter`, admin-granted). |
| Concurrency | `PlayerProgressionLock` (`SELECT … FOR UPDATE` on the player rows, in id order) at the start of every XP / achievement write - award, rebuild, compensation, settlement, full rebuild, evaluation - so two consumers never read the same total or hand out the same slot; the totals are written from the ledger / tiers read under the lock. Karma's recalculation is one set-based `UPDATE … FROM (aggregate)`; two concurrent runs can at worst write the older aggregate, which the daily `recalculate-karma` heals. |
| Karma | Derived from the records (`docs/features/karma.md`) - path independent by construction, proven by `KarmaPathIndependenceTest`. |

Tests: `ProgressionPathIndependenceTest` (every loop through the real handlers ends equal to a fresh recomputation
and to the nightly checks), `KarmaPathIndependenceTest`, `ProgressionSafetyNetTest`.

What "equal" cannot mean: difficulty tiers and speed percentiles move with the community. A live award reads them at
the time, a settlement settles once, and a rebuild reads them as of the rebuild - so XP of an untouched solve may
differ from a fresh rebuild after the puzzle's tier changed. That is not farming and the nightly check ignores it.

### Nightly safety net

`myspeedpuzzling:verify-progression [--repair]` (`ProgressionSafetyNet`, checks in `GetProgressionDrift`): set-based,
one statement per check, nothing recomputed for players without drift - totals vs ledger, level, AP vs tiers held,
deleted solves compensated, no compensation line on a solve that exists, no XP without participation (marked, left the
group), every base line = the formula's base part from the current records (missing awards included), exactly one
weekly boost on each of the week's first 5 solves and none after. `--repair` rebuilds only the players found (full XP rebuild / achievement
evaluation, in the command's process). Anything found is logged as a **warning** with the counts per check (Sentry):
the live wiring should leave nothing behind, so somebody looks at which write path did. Dev copy (523k results, 588k
ledger rows): **5.3 s** for all checks. Whether the tiers held still qualify is not set-based - the daily
`recalculate-badges` run evaluates everybody and removes what no longer qualifies.

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
# Progression safety net - nightly, after recalculate-badges (3:16) and recalculate-karma (3:41): finds players whose
# XP / level / AP disagree with their current records and rebuilds only them; drift = a warning in Sentry
51 3 * * * root lily-cron-run myspeedpuzzling verify-progression -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "51 3 * * *" --timezone "Europe/Prague" verify-progression -- bin/console myspeedpuzzling:verify-progression --repair >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
# Activity digest - Monday 07:01 activity digests, Tuesday 07:01 "We miss you"; the rows (and the
# digest-consumer service they need) are in docs/features/content-digest/README.md §"Production"
```

## Launch switch

No feature flag (the `xp-system` env flag was removed at the launch, `launch-2026-10.md`). The launch switch
`xp_settings.launch_ready_at` keeps XP / levels / achievements admin-only until `myspeedpuzzling:xp-backfill` has
calculated and verified the history, then flips itself (`XpLaunchReadiness`, `XpFeatureGate` = switch on OR admin).
Registry + rollback: `docs/features/feature_flags.md` (`xp-launch`). Order of the launch: `launch-runbook.md`.

### The launch reveal

Two chapters, so no screen repeats another (Jan 2026-10-11: "the same texts, just a different screen"):

- **The level's chapter = the launch modal** (`XpLaunchRevealModal`, `xp/_level_reveal.html.twig`): "Your puzzling
  history has a level" - the player puts the last piece into their level medallion **with a tap** (the puzzle or the
  big "Reveal my level" button, `xp_level_reveal_controller.js`), the XP counts up, "N XP to level N+1", then what
  comes next: members on to their achievements, free players the member tease. Tap-started because browsers play
  sound only in answer to a gesture and this is the moment ("sound always on" - there is no sound switch anywhere the
  last-piece reveal plays; every sound answers a tap and lasts about a second).
- **The achievements' chapter = `/{_locale}/my/xp-reveal`** (`xp_launch_reveal`): members meet their achievements one
  by one in crescendo order, each card with its facts (`GetXpLaunchRevealBadges::withFacts()`: the tier with its pips
  and the Achievement Points its tiers brought, what it took, how many puzzlers hold the tier - the rarity word only
  when it is not common -, below Diamond the next tier's requirement with the bar and the numbers, and at most one
  highlight: Early Adopter's thank-you, Diamond's top tier, the deck's rarest tier, the next tier within reach), then
  a summary of everything, the level included. The XP shown is the XP from puzzles.
  - **The level card leads the deck only when the level was not revealed yet**; once revealed (in the modal, or on
    this page before) the deck is the achievements only and the intro says "Now, your achievements". The signal is
    the launch modal's `seen_at`: for this one modal "seen" = the level was revealed - the level's puzzle POSTs
    `xp_launch_level_revealed` (`/-/xp-launch-level-revealed` → `MarkAnnouncementModalSeen`) on its first tap, and the
    modal itself sends no report on opening (a modal closed before the last piece = not revealed). Read by
    `GetAnnouncementModalSeen` (one lookup). A lost report = the level card comes once more, nothing worse.
  - **From the modal's "Reveal my achievements" the page opens on the first card** (`?start=1`, Jan 2026-10-12): no
    intro with a second button to the same thing. The server renders the deck open (intro hidden, first card shown),
    `xp_launch_reveal_controller.js` starts it as soon as the lazy reveal controller says `last-piece-reveal:ready`.
    The sound plays without a tap of its own: the reveal's AudioContext is one per visit, kept across the Turbo visit
    (`assets/last_piece_reveal/sfx.js`), so the modal's tap unlocked it. A direct visit (newsletter, bookmark) keeps
    the intro and its "Start" button.
  - Free players (and members without achievements): before the level is revealed the page is the level's chapter
    (they never had the modal's moment); after, it is "Level N, and counting" - the medallion as it stands, the next
    level, How XP works, the count of waiting achievements (never which) and the membership button.

Ways in:

- the **launch announcement modal** (`AnnouncementModal::XpLaunchReveal`, `XpLaunchRevealRule`): every player with at
  least one counted solving time, not opted out, once ever, after the switch is on. It goes first and ignores the
  pause between announcement modals (`AnnouncementModal::ignoresCooldown()`); quiet routes still apply. "Has a solving
  time" rides on the viewer's own profile row (`PlayerProfile::$hasSolvingTime`, an `EXISTS` probe in
  `GetPlayerProfile::byUserId()`), the switch on the shared cache - no query per page view.
- the **newsletter link** (one URL for everybody): a guest goes through the sign-in and back (`LoginEntryPoint`,
  `?return=`); the page stays viewable after it was seen; opening it claims the launch modal's impression, so the modal
  never offers a reveal already seen. Before the switch non-admins are sent to their profile.
- newly joined members: Stripe checkout lands on `/membership` (`XpRevealInvite` → `badge_reveals`), the free trial's
  welcome page has the invite and an "Your achievements" card.

The reveal page and `badge_reveals` are quiet routes (no other announcement modal on top of them).

### Achievements for free players

Achievements are evaluated and stored for everybody, shown to members only. A free player is teased without learning
which: `GetBadges::countTiersEarnedWithSolve(playerId, solvingTimeId)` = how many tiers were earned with that solve
(earned within 10 minutes after the solve was logged, before the player's next one; 0 until the async evaluation ran) for the
recap teaser; `GetBadges::earnedWithSolve()` is the same set for members (highest new tier per type, best first) - the recap (`XpRecapCelebration`) uses it.

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
  (`ContentDigestUnsubscribeUrl`, never expires, `/{_locale}/activity-digest/unsubscribe/{id}` since 2026-10-10, one-click 200 / button
  303, `no-store`); the reveal mail goes through the `notifications` transport; the queue is `content_digest_emails`
  (main's unread-messages digest owns `digest_emails_*`); routine bounces and the plausibility guard log at `info`.

**Decided by Jan 2026-10-04 - to build in the follow-up sessions** (this session only caught up with main):

1. **Per-type e-mail settings.** The weekly digest is its own e-mail type with its own switch and its own
   unsubscribe, separate from the unread-messages digest (`emailNotificationsEnabled`), which stops gating it. XP and
   achievements are content *of* the weekly digest. → Digest eligibility = the digest switch only (drop
   `email_notifications_enabled` from `GetPlayersForContentDigest` / `SendPlayerContentDigestHandler`); the digest
   switch also appears on main's per-type token page (`email_preferences`, `EditEmailPreferences`) next to the
   newsletter, the unread-messages digest and the result e-mails.
2. **No per-achievement e-mails** (done at the launch - and the XP reveal e-mail was dropped too, `launch-2026-10.md`).
   One initial e-mail at launch (the XP reveal), afterwards new achievements reach
   players only through the weekly digest (its "achievements earned" block / members-only teaser already exists).
   → Remove `SendBadgeNotificationEmail` + handler + `emails/badges_earned.html.twig` + the `badges_earned.*` keys;
   `RecalculateBadgesForPlayerHandler` keeps persisting badges and stops mailing. The reveal mail is gated by the
   digest switch (its unsubscribe link is the digest's).
3. **Monday, ~12k recipients, the whole Monday.** Puzzlers are busy over weekends, so the digest goes out on Monday
   about the week that just ended.
   - *(Built 2026-10-10: Monday about the completed period, DigestPeriod::latestSentBy(); pacing 07:00-19:00 Prague;
     breaker ≥ 20 failures and ≥ 5 % of a run's attempts - content-digest README.)*
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
6. *(Superseded 2026-10-10: a frequency weekly / every two weeks / monthly / off instead of a boolean - built, see the
   content-digest README.)* **Weekly digest only, never daily.** `ContentDigestFrequency` (none / daily / weekly) was built for a daily digest
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

**Formula cutoff = the deploy moment (built at the launch).** XP for history is deliberately smaller than XP for new
solves: a solve *logged* (`tracked_at`) before the cutoff earns the base + difficulty + unboxed parts only, one logged
from the cutoff on also the incentives - speed bonus, weekly boost (+20 % for the first 5 solves of an ISO week)
and the ex-post difficulty / speed settlements (`SettleXpBonuses` only looks at solves after it).
The incentives reward behaviour the player could see and react to, so they must not be paid retroactively - otherwise
every past week of every veteran gets boosts and the launch ladder is flooded. The cutoff is
`xp_settings.full_formula_from`, stored once by the migration that created the table (`NOW()` of the deploy), read
through `XpFormulaCutoff` (cached per worker process - it never changes). Tests use their own value
(`XpSettingsFixture`, 2026-08-01). The launch backfill runs after the deploy (`launch-runbook.md`).

**Open - follow-ups (no decision needed):**

- Production: `content_digest_emails` consumer + `deploy.sh` restart + the cron rows above, in lily.srv, with the merge.
- Per-page cost after launch: the estimate / receipt / celebration re-query the opt-out. (Done for the rings,
  2026-10-10: `xp_total`, `level`, `achievement_points` ride on both `PlayerProfile` rows - `XpRing` and the navbar no
  longer run `GetXpProfile`.)
- One subscriber on main's result events (`PuzzleSolvingTimeDeleted` / `Modified` / `MovedToOtherPuzzle`) would cover
  future write paths without per-handler dispatches. (The safety net exists since 2026-10-11 - §Anti-farming; a result
  id that comes back after deletion and the unlocked `XpLedger::append()` are fixed there.)
- First-try achievement counts the tracker's rows only; main makes a pair/team first try everybody's (one per person
  per puzzle). Piece-count achievements read the live pieces count, XP the snapshot. A suspicious flag set by SQL follows once the
  scan reconciles it (or the nightly check repairs it); runbook for a single player:
  `myspeedpuzzling:recalculate-xp --player`.
- API returns badges regardless of membership / opt-out, the web shows them to members only.
- E-mail polish: Inky buttons for the calls to action, `p.small-print`, the digest's settings link to the token
  preferences page (`EmailPreferencesLinkGenerator`), a hosted hero image instead of the 185 KB inline PNG, no stored
  body for `xp_reveal` in the audit log, a real plural in `badges_earned.title`.
- `CleanupEmailAuditLogsHandler` re-dispatches its next batch inside the open transaction, so the batches are still
  one transaction - loop in the command instead.
- Canary tests get `xp_leaderboard` + `achievement_detail` on launch day (`feature_flags.md`).
