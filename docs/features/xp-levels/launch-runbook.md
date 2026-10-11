# XP / Levels — Launch-Day Runbook

The all-in launch of October 2026 (decisions: [`launch-2026-10.md`](launch-2026-10.md)). Everything below runs on
production (`lily.srv`, app dir `/srv/myspeedpuzzling`) unless noted. `$DC` below stands for
`docker compose --file /srv/myspeedpuzzling/compose.yaml`; one-off commands always run with `--no-deps` (never let a
`compose run` start or re-create the `db` service).

There is no feature flag to flip. The deploy ships everything, but XP / levels / achievements stay **admin-only until
the history is calculated and verified** - the launch switch `xp_settings.launch_ready_at`
([`feature_flags.md`](../feature_flags.md) `xp-launch`). The backfill command sets it itself, and only when the check
passes. Nobody ever sees "Level 1, 1 XP, no achievements" because the backfill was not finished.

Order: **deploy → karma backfill → XP backfill (verifies, switches on) → newsletter.**

## 0. Prerequisites (before launch day)

- [ ] `pg_dump -Fc` of production, kept on the box until the launch is confirmed
- [x] Badge art committed with the branch: `public/img/badges/**` + `manifest.json`, built by `tools/badge-art/build.py`
  ([`tools/badge-art/README.md`](../../../tools/badge-art/README.md)). Required, not optional - there is no fallback
  picture; `BadgeArtCoverageTest` must be green (every achievement × tier in every format/size)
- [ ] Jan: copy approvals — explainer + fair-play pages (`<!-- COPY:pending-jan-approval -->` markers), launch reveal page + launch modal (`xp.launch_modal.*`)
- [ ] Jan: cs native review of achievement names (after the translation fill)
- [ ] Cron rows from `README.md` §Cron added to `apps/myspeedpuzzling/cron.d/myspeedpuzzling` in the lily.srv repo -
  **the weekly digest row only from Mon 19 Oct 2026** (the digest is paused until then, `launch-2026-10.md`)
- [ ] `digest-consumer` service added to `apps/myspeedpuzzling/compose.yaml` + `deploy.sh` restarts it (content-digest README §13)
- [ ] Deliverability: FBL + Google Postmaster registrations (content-digest README §14)

## 1. Deploy

Merge + deploy as usual. The migrations run on container boot; `Version20261010212501` stores the **formula cutoff**
(`xp_settings.full_formula_from` = `NOW()` of the migration = the deploy moment: solves logged before it earn the
history formula, solves logged after it the full one with the weekly boost and the speed bonus) and creates the launch
switch, off.

From now on new solves earn XP and achievements for everybody, silently: non-admins see nothing yet.

```bash
$DC exec -T db psql -U speedpuzzling -d speedpuzzling -P pager=off -c "SELECT * FROM xp_settings;"
```

### 1b. Karma backfill (before the XP backfill - karma launches behind the same switch)

`player.karma` must be filled before the switch flips (docs/features/karma.md):

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:recalculate-karma
```

One set-based statement, in this process (not queued): ~0.15 s on the dev copy (41k puzzles, 2,952 holders). Idempotent -
re-running changes nothing; the daily cron row keeps it right afterwards. Check:
`SELECT COUNT(*) FILTER (WHERE karma > 0), SUM(karma) FROM player;` (dev copy: 2,952 / 23,841).

## 2. Backfill - recalculates, verifies, switches on

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:xp-backfill
```

Runs in its own process (no queue): every player with solves gets their XP ledger rebuilt from their whole history
and their achievements evaluated (Achievement Points only - achievements grant no XP, nothing is mailed), one after the
other with a progress bar. Then it verifies - every player with a counted solving time has ledger rows, every
`xp_total` is the sum of its ledger, every level matches its XP, every `achievement_points` the sum of its
achievements - and **only if that passes** sets `launch_ready_at` and drops the cached switch: XP, levels and
achievements are live for everybody within a request, and the launch modal starts for every player with a solving
time.

- A player that fails is listed and the switch stays off: fix, re-run (the rebuild is deterministic, the achievement
  evaluation brings the tiers to the records - re-running changes nothing).
- `--no-launch`: recalculate and verify, leave the switch off (a dry run of the launch).
- `--verify-only`: recalculate nothing, verify and switch on when the check passes (e.g. after fixing the one player
  that failed by `myspeedpuzzling:recalculate-badges --player=<id>` / a single XP rebuild).

Duration: measured 2026-10-10 on a scratch copy of the local near-production database (523k solving times,
7,878 players with solves, `APP_ENV=prod`, Docker on a laptop): **13.4 minutes** for the recalculation, verification
passed for 7,597 players. Expect the same order on lily; `--no-launch` on a scratch copy of production is the dry run.

Deeper check (read-only, ~5 s on the dev copy): `bin/console myspeedpuzzling:verify-progression` must report no drift
in any check (README §Anti-farming) - the same command runs nightly with `--repair`.

## 3. Check as a NON-admin

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:xp-distribution
```

- Achievements no longer add XP; formula v5 + curve v7 (2026-10-11, `launch-2026-10.md` §1 "XP balance", "Curve v7").
  The dev copy of production with v7 gives **8 players at Level 50**, 88 at Level 40+, a median level of 5 and p90
  Level 24 (7,878 players with XP; zero drift in `myspeedpuzzling:verify-progression`) - Level 50 is about 1,300
  logged puzzles. Note the production numbers.
- Profile ring, catalogue, a receipt after a test solve, the launch modal on the first page view (once), the reveal
  page `/en/my/xp-reveal`.

## 4. Newsletter

The launch is announced in the regular newsletter (Listmonk) with one link for everybody:
`https://myspeedpuzzling.com/en/my/xp-reveal` (Czech: `/moje/xp-odhaleni`; es/fr/de/ja have their own paths, route
`xp_launch_reveal`). A guest is sent through the sign-in and back to it; a player who already saw it (from the modal)
sees it again - it never dead-ends; a player who switched XP off lands on their profile. Opening the page uses the
launch modal up, so the modal never offers a reveal already seen.

## 5. Digest (from Mon 19 Oct 2026)

The activity digest (content-digest README, part above "History") is not sent while the switch is off, and nothing
goes out about a period before 12 Oct 2026 (first send Mon 19 Oct). The Monday cron paces every run over 07:00-19:00
Prague, most engaged first, with a circuit breaker. Achievements earned before the switch went on (the ones the
backfill stamped) are never presented as new. Check the counts first:

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:send-content-digest activity --dry-run
```

(Requires the `digest-consumer` compose service + deploy.sh change from content-digest README §13.)

## Rollback

```sql
UPDATE xp_settings SET launch_ready_at = NULL;
```

then `$DC exec -T web bin/console cache:pool:clear xp_launch_readiness_cache` (or wait for the 5-minute TTL). Every
surface disappears for non-admins again, the digest stops; data (ledger, achievements) stays and keeps accruing.
Players who already saw the launch modal will not see it again.

## Jan's manual list (collected)

- Run steps 1–4 above on launch day
- Cron entries (README §Cron) + `digest-consumer` compose service + deploy.sh workers line
- FB group posts (T-7 teaser + launch)
- Seznam Email Profi outbound-ceiling inquiry (blocks full digest volume only)
