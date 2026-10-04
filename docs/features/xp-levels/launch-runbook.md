# XP / Levels — Launch-Day Runbook

Everything below runs on production (`lily.srv`, app dir `/srv/myspeedpuzzling`) unless noted. `$DC` below stands for
`docker compose --file /srv/myspeedpuzzling/compose.yaml`; one-off commands always run with `--no-deps` (never let a
`compose run` start or re-create the `db` service).
The whole branch deploys SILENTLY first — while the `xp-system` flag is active, only
admins see anything and no emails leave the system.

## 0. Prerequisites (before launch day)

- [ ] Branch merged + deployed (flag active) — migrations run automatically on container boot
- [ ] Jan: badge tier-frame + icon images dropped into `public/img/badges/` (optional — medallion fallback covers absence)
- [ ] Jan: copy approvals — explainer + fair-play pages (`<!-- COPY:pending-jan-approval -->` markers), reveal email, launch reveal page
- [ ] Jan: cs native review of achievement names (after P8 translation fill)
- [ ] Cron rows from `README.md` §Cron added to `apps/myspeedpuzzling/cron.d/myspeedpuzzling` in the lily.srv repo
- [ ] `digest-consumer` service added to `apps/myspeedpuzzling/compose.yaml` + `deploy.sh` restarts it (content-digest README §13)
- [ ] Deliverability: FBL + Google Postmaster registrations (content-digest README §14)

## 1. Silent backfill (flag still ACTIVE — safe, invisible, no emails)

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:xp-backfill
```

Dispatches an XP ledger rebuild for every player with solves, then achievement
evaluation in backfill mode (no congratulation emails, achievement XP excluded from the
weekly delta). Watch the queue drain:

```bash
$DC exec -T db psql -U speedpuzzling -d speedpuzzling -P pager=off -c "SELECT queue_name, COUNT(*) FROM messenger_messages GROUP BY 1;"
```

Re-running is safe (deterministic recompute + gap-filling evaluator).

## 2. Verify calibration (flag still ACTIVE)

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:xp-distribution
```

Hard invariants (production data, ~7,004 players with solves):

- **≈115 players at Level 50 (±10, ~1.6%)**
- **median player around Level 13–14**
- rank-115 XP total ≈ 3,190+

Admins additionally review in production: profile ring/receipt/catalog/leaderboard/
holders/audit/explainer/reveal page + a test solve end-to-end.

If numbers are off → investigate BEFORE removing the flag; the public saw nothing yet.
Fixes + `myspeedpuzzling:xp-backfill` re-runs are cheap at this stage.

## 3. Launch = flip the flag (no code change)

1. Set `XP_SYSTEM_ADMIN_ONLY=0` in the production env (Infisical) — no code change, and
   rolling it back is the same one-line change. On lily.srv the Infisical secrets reach the
   containers only through `dump_secrets` in `deploy.sh`, so re-run the deploy of the current
   image afterwards (webhook re-POST with `tag=main`, or a job file in `/srv/deploy/queue/`). Then (separately, at leisure)
   remove the gate + call sites entirely per `feature_flags.md` (also DELETE the leak WebTestCases:
   `XpPagesTest`, `XpSurfacesTest`, flag-specific tests in
   `BadgesOverviewControllerTest` / `RecalculateBadgesForPlayerHandlerTest` /
   `DigestSettingsVisibilityTest` — they assert 404s that stop existing).
2. Confirm `XpCalculator::FULL_FORMULA_FROM` is set to the intended cutoff (solves
   tracked BEFORE it use the backfill formula; set it to launch-day midnight UTC).
3. Deploy. Smoke-check as a NON-admin: profile ring visible, catalog public, receipt
   renders after a solve.

## 4. Same day: reveal emails

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:send-xp-reveal-emails
```

Refuses to run while the flag is active. One email per player forever (idempotency log),
2s stagger (~4h for 7k recipients), transactional identity.

## 5. Digest ramp start

Follow content-digest README §14 ramp plan (~1–2k most-engaged first). The weekly cron
sends to everyone eligible — for the ramp weeks, either keep the cron off and dispatch
manually, or accept full volume once deliverability prerequisites are green. First send:

```bash
$DC run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:send-content-digest weekly
```

(Requires the `digest-consumer` compose service + deploy.sh change from content-digest
README §13.)

## Rollback

Set `XP_SYSTEM_ADMIN_ONLY=1` again and re-run the deploy of the current image (no code change) — every surface disappears for
non-admins again, emails stop. Data (ledger, badges, logs) stays intact and keeps
accruing silently; nothing else to undo. Reveal emails already sent cannot be unsent —
that is why verification (step 2) happens before flag removal.

## Jan's manual list (collected)

- Run steps 1–5 above on launch day
- Cron entries (README §Cron) + `digest-consumer` compose service + deploy.sh workers line
- FB group posts (T-7 teaser + launch)
- Seznam Email Profi outbound-ceiling inquiry (blocks full digest volume only)
