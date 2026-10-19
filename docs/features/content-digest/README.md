# Activity digest e-mail + "Your week" page

The recurring e-mail of the progression launch (decisions: `docs/features/xp-levels/launch-2026-10.md` §Digest, Jan
2026-10-10; they win over the July design kept below as history). Internally everything is still named **content
digest** (`ContentDigestLog`, `SendPlayerContentDigest`, `ContentDigestFrequency`, …) to stay apart from the
**unread-messages digest** (`emailNotificationsEnabled`, `PrepareDigestEmailForPlayer`, `digest_emails_*`), which is a
separate e-mail type with its own switch and does **not** gate this one any more.

Two e-mails:

- **Activity digest** - Monday, to everybody with activity in their period: the period first, progress second, one
  button to the "Your week" page, every line a link.
  **No activity in the period = no digest at all** (no "quiet" variant).
- **"We miss you"** - Tuesdays (from Tue 27 Oct 2026), to a player whose last logged result is **≥ 28 days** old (never a
  dormant account):
  friendly, lightly humorous ("have you been puzzling in secret?"), invites them to add the times they forgot and to
  come back. **Once per inactive spell**, never again until they have had activity again (Jan, 2026-10-11 - replaced
  the earlier "no-activity mail the day after").

## Frequency and periods

`player.content_digest_frequency` (`ContentDigestFrequency`): `weekly` (default) / `every_two_weeks` / `monthly` /
`off`. The old `none` / `daily` values are gone ("daily" never existed); the branch migration
`Version20260713151509` was rewritten in place (never deployed) - **a local database that ran the earlier version:
`doctrine:migrations:execute --down` then `--up` that version**.

Periods are **Europe/Prague** calendar periods (`DigestPeriod`, the site's home zone and the cron's zone); the
database stores UTC, queries use `startUtc()` / `endUtc()`:

| Frequency | Period | Key (also the page URL) | Sent (activity digest) |
|---|---|---|---|
| weekly | the previous ISO week, Mon 00:00 - Mon 00:00 | `2026-W42` | every Monday |
| every two weeks | the previous two ISO weeks, in a fixed rhythm: periods start every even number of weeks after Monday 12 Oct 2026 (`FIRST_PERIOD_START`) - counted in days, so 53-week years keep the rhythm | `2026-W42-W43` (`2032-W53-W01` across a year) | every other Monday (26 Oct, 9 Nov, …) |
| monthly | the previous calendar month | `2026-10` | the first Monday on or after the month's end (the 1st when that is a Monday) |

- `DigestPeriod::latestSentBy(length, now)` = the latest period whose send day has come; a run skips it when it is
  **before the first period** (`isBeforeFirstPeriod()`) or **stale** (`STALE_AFTER_DAYS` = 3 days after the send day -
  so on an off-Monday the two-week/monthly period found is stale and nothing is sent).
- **Launch pause**: nothing about a period starting before Monday 12 Oct 2026 - a guard in code (planner *and*
  handler), not only a cron date. First activity digest: **Mon 19 Oct 2026 about 12-18 Oct**; first two-week one
  Mon 26 Oct (12-25 Oct); first monthly one Mon 7 Dec (November - October started before the pause ended).
- **Launch switch**: on top of that nothing is planned or sent while `XpFeatureGate::isEmailSendingEnabled()` is false
  (the one XP launch switch; workstream A turns it into "launch ready").
- Keys are canonical (`fromKey()` re-derives and compares): week 54/00, a two-week key off the rhythm or a month 13 are
  a 404 on the page and dropped by the handler. Tests: `tests/Value/DigestPeriodTest.php` (DST both ways, year
  boundaries, 53-week years, every send-day rule).

## Who gets what

`GetContentDigestRecipients` (recipients and the per-player re-check before sending share one SQL). Both kinds need an
account e-mail, the frequency whose period it is, and no e-mail about the period yet (a `deferred` one may be retried);
one player per address; **most engaged first** (latest `player_activity_day`, then the oldest account).

- **Activity** = at least one result that counts (not awaiting verification) **solved** in the period
  (`COALESCE(finished_at, tracked_at)`), solo or as a registered member of the pair/team (`puzzling_team_member`).
  The solve moment, because XP (`xp_entry.earned_at` of a solve), streaks and the profile calendar use it - the
  e-mail's puzzles and its XP must agree, and every number must be explainable on the XP history.
  *Not* activity of their own: achievements and XP (they only come from results, so they would double count -
  and the launch backfill would make everybody "active"), opening the site (presence is not puzzling).
  **Karma** (accepted contributions, workstream F) is the one other activity planned: a player who only improves the
  database is active too - the hook is `GetContentDigestRecipients::activitySql()` (+ `PlayerPeriodActivityReader::karmaGained()`
  for the e-mail line); until F lands it is results only.
- No activity in the period → **no digest** (nothing is sent, nothing is logged).

## We miss you

- **Who**: the activity-digest switch on (any frequency but off - it rides on that preference and its one-click
  unsubscribe instead of a fourth switch: it is the same relationship, "tell me about my puzzling", and one switch is
  easier to understand), at least one result ever (players who never logged a time are not "missed"), and the **last
  logged result ≥ 28 days ago** (`GetContentDigestRecipients::MISS_YOU_AFTER_DAYS`; logged = `tracked_at`, any result,
  solo or pair/team, a back-dated or not yet verified one too), and **not dormant**: dormant = no result **and** no
  visit for more than 9 months (`MISS_YOU_DORMANT_AFTER_MONTHS`) - never mailed, left to the newsletter: old addresses
  bounce, hit spam traps or complain, and that hurts the deliverability of every other e-mail. A **visit** = a signed-in
  day on the website (`player_activity_day`, recorded since 2026-07-31, pruned after 24 months) or a sign-in
  (`user_account.last_login_at`, set by `AuthenticationAuditSubscriber` on every successful sign-in on the main
  firewall: password, e-mailed link or code, social login, and a remember-me cookie starting a new session). API and PAT
  calls are not visits. One set-based query (two LATERALs), no per-player lookups.
- **Once per inactive spell**: the spell is named by the last logged result - the log row's `period_key` is
  `miss-you:<last tracked_at>` (`MissYouRun::spellKey()`, `had_activity = false`), so the unique `(player_id,
  period_key)` claim makes it race-safe and once; the recipients query also skips anybody with a "We miss you" row
  sent after their last result. A new result restarts the 28-day clock (a new spell, a new key).
- **When**: weekly on Tuesday, all frequencies, runs keyed `miss-you-YYYY-MM-DD` (`MissYouRun::runKey()`); **not before
  Tue 27 Oct 2026** - two weeks after the launch newsletter (`MissYouRun::FIRST_DAY`, a guard in planner *and* handler), never while the launch switch is off;
  a queued message older than 2 days after its run day is dropped. Paced over 07:00-19:00, most recently active first,
  with a **stricter breaker** (≥ 10 failures and ≥ 3 %: these addresses have been quiet, the first run for years).
- **Numbers** (dev copy, data to 25 Sep 2026): last result 28 days-9 months ≈ 2,700; result older but visited within
  9 months ≈ 56; dormant (excluded) ≈ 2,254. Newly crossing 28 days ≈ 175 a week (111-248). 52 % of 4-week breaks end on
  their own within 12 weeks, 73 % eventually - hence the holdout: the mail has to beat that baseline.
- **Slicing the first wave**: `--last-activity-after=<date|age>` / `--last-activity-before=<date|age>` (by the last
  result; a date `2026-04-27` = Prague midnight, or an age `28 days` / `3 months` / `1 year` counted back from now),
  `--max-recipients N` (all frequencies together, most recently active first, the holdout included), `--dry-run` (counts
  per frequency, the holdout, and per bucket: 28d-3m / 3-9m / older-but-visited).
- **Wave plan** (proposal, Jan 2026-10-11):

| Tuesday | Slice | ≈ recipients | Command |
|---|---|---|---|
| 27 Oct 2026 | last result 28 days - 3 months, **plus** the visitors with an older result | 1,100 | `send-content-digest miss-you --last-activity-after="3 months"` and `send-content-digest miss-you --last-activity-before="9 months"` |
| 3 Nov 2026 | last result 3-9 months | 1,650 | `send-content-digest miss-you --last-activity-after="9 months" --last-activity-before="3 months"` |
| from 10 Nov | the weekly cron (the 28-day crossers) | 175 | `send-content-digest miss-you` |

  The cron row goes in from Tue 10 Nov; the first two Tuesdays are run by hand. Dormant accounts: never (code).

## Holdout

A deterministic **10 %** (`MissYouHoldout::PERCENT`; sha256 of a fixed salt + the player id) of the eligible players is
**not mailed** but logged in the same log, status **`holdout`**, once per spell exactly like a send (the recipients query
treats it as "told"). Holdout messages are queued without delay and take no pacing slot. The comparison - who logged a
result within 4 / 8 weeks after the spell's mail (or holdout) date:

```sql
SELECT
    l.status = 'holdout' AS holdout,
    COUNT(*) AS players,
    ROUND(100.0 * COUNT(*) FILTER (WHERE back.at < l.sent_at + INTERVAL '4 weeks') / COUNT(*), 1) AS back_within_4_weeks_pct,
    ROUND(100.0 * COUNT(*) FILTER (WHERE back.at < l.sent_at + INTERVAL '8 weeks') / COUNT(*), 1) AS back_within_8_weeks_pct
FROM content_digest_log l
LEFT JOIN LATERAL (
    SELECT MIN(pst.tracked_at) AT TIME ZONE 'UTC' AS at
    FROM puzzle_solving_time pst
    WHERE (pst.player_id = l.player_id
        OR pst.puzzling_team_id IN (SELECT ptm.team_id FROM puzzling_team_member ptm WHERE ptm.player_id = l.player_id))
      AND pst.tracked_at AT TIME ZONE 'UTC' > l.sent_at
) back ON true
WHERE l.period_key LIKE 'miss-you:%'
  AND l.status IN ('sent', 'holdout')
  AND l.sent_at < NOW() - INTERVAL '8 weeks'   -- only spells whose 8 weeks are over
GROUP BY 1;
```
- **Content** (`templates/emails/content_digest_miss_you.html.twig`, keys `activity_digest.miss_you.*`): "Your puzzles
  miss you" / "We miss your times" - maybe a break, maybe puzzling in secret; times can still be added with the day they
  were finished ("Add a time" button → `puzzle_add`), then: let us pick your next puzzle (Puzzle Picker), see what the
  community is puzzling (Hub), your profile; the community's most solved of last week (no favourites since 2026-10-19). No guilt, no
  blame, no streak talk. Humour that survives translation (no puns).
- Opt-outs: the digest has its own switch only. `experience_system_opted_out` no longer stops the e-mail - it leaves
  out XP and level lines (e-mail and page). `streak_opted_out` hides the streak, `ranking_opted_out` the MSP Rating.

## Sending

```
cron Mon 07:01 → myspeedpuzzling:send-content-digest activity      (Tue 07:01 → … miss-you; --dry-run, --max-recipients N)
  → PlanContentDigestRuns (sync handler, one transaction): per frequency due → ContentDigestRun + one
    SendPlayerContentDigest per recipient with DelayStamp(firstDelay + i × spacing)  → queue content_digest_emails
      → digest-consumer: SendPlayerContentDigestHandler
           gate / pause / stale / run stopped / player, e-mail, frequency, isRecipient() re-check → else drop
           claim content_digest_log (INSERT … ON CONFLICT (player_id, period_key), a deferred row is taken over)
           read PlayerPeriodActivity, render, TransportInterface::send() (notifications transport, README D1)
           sent → run.sent++ | 550-553 → failed_permanent, run.failed++ | any other transport error → deferred,
           run.deferred++, our own retry after 10 / 30 min (MAX_ATTEMPTS 3) | breaker → stop run, one warning
```

- **One e-mail per player and period**: unique `(player_id, period_key)` on `content_digest_log`, claimed *before*
  sending inside the handler's transaction - a redelivered message waits for the first delivery and gets nothing, a
  crash rolls the claim back. Transport failures do not throw (so the outcome commits); anything else (template,
  database) bubbles to Messenger's retry with nothing left behind.
- The command only dispatches `PlanContentDigestRuns` and prints a table per frequency: `planned` (+ recipients,
  spacing), `dry_run`, `not_ready` (launch switch), `paused` (before the first digest period / before the first "We miss you" day), `stale`.
  Re-running it the same day queues nobody twice.
- **Preview** before a run: `myspeedpuzzling:send-content-digest-preview <email> [--player=<id|code|e-mail>]
  [--period=2026-W42] [--kind=activity|miss_you]` - a player's real e-mail for the period (default: the player of
  `<email>`, last week) to one address, through the same factory (`ContentDigestEmailFactory`) and transport; handled
  at once (not routed), writes nothing, ignores the player's switches. An activity digest of a period the player did
  nothing in is refused (`NoActivityInDigestPeriod`) - a real one is never sent, so its preview would show an e-mail
  nobody gets.
- **Delivery** goes through `MailStreamRouting` (mass-mailing README "Reliability"): SES with one retry, no Seznam
  fallback for the digest - a failed send is deferred by the handler (10 / 30 min) and counted by the breaker. The
  first run (Mon 19 Oct 2026) stopped at 468 of 1,915 because the digest had silently moved to Seznam and Seznam
  refused the account; a re-run the same day sends to whoever got nothing.
- The XP reveal e-mail (removed by workstream A) used the log as its once-only marker (`period_key = 'xp_reveal'`)
  until it is deleted.

## Pacing and the circuit breaker

- `DigestPacing`: the run's e-mails spread **evenly over 07:00-19:00 Prague** of the run's day, from the recipient
  count - ~12,000 recipients = one every ~3.6 s (~1,000/h); a run before 07:00 waits for the window, one inside it
  uses what is left, one after it (manual re-run) goes at the minimum spacing (1 s). Most engaged first, so early
  trouble shows on the best addresses. Reused idea of main's `DelayedEmailQueue` (result-review e-mails): a DelayStamp
  per message; here on the dedicated `content_digest_emails` queue because the e-mail is rendered at send time.
- **Circuit breaker** (`SendPlayerContentDigestHandler::breakerTrips()`): once a run has **≥ 20 failures** (permanent
  + deferrals, counted per attempt) **and** they are **≥ 5 %** of its attempts, the run is stopped (`stopped_at`),
  exactly one `warning` is logged (→ Sentry) and every message of the run still in the queue is dropped without a log
  row - so after fixing the cause, re-running the command the same day (or the next, until stale) sends to whoever got
  nothing. Counters live on `content_digest_run` (atomic `UPDATE … + 1`): `sent`, `failed_permanently`, `deferred`.

## Content

`PlayerPeriodActivityReader` → `PlayerPeriodActivity`, shared by the e-mail and the page (same numbers in both). In the
e-mail, in order (`templates/emails/content_digest_activity.html.twig`):

| Line | Shown when | Links to |
|---|---|---|
| Subject "Your week 12–18 Oct on MySpeedPuzzling" / "Your two weeks …" / "Your October on MySpeedPuzzling" (ICU skeleton `MMMd` / `MMMM`, e-mail locale; no numbers - Jan 2026-10-19) | always | - |
| "Your week, Name" + the dates (ICU skeleton, e-mail locale) | always | - |
| puzzles · pieces (each with "+3 vs last week" against the period before, `previousPeriodTotals()`; nothing when that one was empty or equal) · time puzzling (`compactTime`, when > 0) · day streak | always (streak > 1, not opted out) | - |
| Level block: "Level N → M" · "+X XP this week", a bar toward the next level (table cells) and "N XP to Level M+1" (no bar at Level 50) | XP visible | XP history (`xp_history`) |
| New: achievement · tier · +N AP (members, the 3 with the most points, then "+N more achievements") / "You earned an achievement. Become a member to find out which." | earned in the period | the achievement page / the "Your week" page / membership |
| Next up: achievement · tier, "37 of 50" (or "your best 1:05:00, goal 59:59") · % (members) | an achievement with progress (closest to its next tier) | the achievement page |
| MSP Rating: #rank of total · up/down N places | moved (snapshots before vs in the period, 500 pc) | the ladder |
| New skill tier for N pieces (members) | tier changed | the ladder |
| Karma thanks | karma gained (workstream F hook) | (F's karma history) |
| Highlights: up to 3 results, PB / first try first, each with its place on the puzzle's solo leaderboard ("#4 of 210", `GetRanking::forPuzzles()` for the period's puzzles - one statement) | results | the puzzle |
| **See your week** (44 px Inky button) | always | `my_activity` with the period key |
| Most solved by the community | ≥ 3 puzzlers solved one puzzle | the puzzle |
| You get this every Monday · Change e-mails · Unsubscribe | always | token preferences page (no sign-in) / one-click unsubscribe |

Preheader = the best thing of the period: a member's new achievement > "you earned an achievement" > a level-up > a
personal best > MSP Rating places gained > "N puzzles, N pieces". Subjects per period length. Achievements give no XP
any more (launch decision); AP only, and only members see which.

**Known launch caveat**: achievements count by `badge.earned_at`; the launch badge backfill stamps the run time. If it
runs on/after Mon 12 Oct 00:00 Prague, the first digest (12-18 Oct) shows every backfilled achievement as new - run
it before, or tell the orchestrator to filter (see `docs/TODO.md`). XP is safe: backfill lines are `in_weekly_delta = false`.

## "Your week" page

Route `my_activity` - `/en/my/activity/{period}` (cs `/moje/aktivita/…`, all 6 locales), `{period}` = a week, two
weeks or a month key; without it the current week so far. Owner-only by construction: no player id in the URL, it is
always the signed-in player's own (another player opening the link sees *their* week); `IS_AUTHENTICATED_REMEMBERED`
(works from the e-mail with remember-me, otherwise the login redirect carries `?return=`); follows the XP launch switch
for non-admins; invalid / future / pre-2020 periods are 404; `no-store`.

What it shows (one column, 375 px first, 44 px tap targets): the period and its dates, previous / next (no next
while the period runs), a Week / 2 weeks / Month switch on the same start date; numbers (puzzles, pieces, puzzling
time, streak); level + XP → XP history; achievements (members: badge + tier + AP → achievement page; others: the
teaser + membership); MSP Rating place and movement, skill tier changes (members); karma (hook); **every result** of the
period - puzzle, brand, pieces, time, the current place on the puzzle ("#3 of 40"), Personal best / First try / Pair /
Team / Awaiting verification marks, its XP; favourites' activity; the community's most solved; **what next** - the
member's nearest achievement tiers with progress, and for everybody "Pick my next puzzle" (Puzzle Picker) - and a
Share button (public profiles: "My week on MySpeedPuzzling: N puzzles, N pieces" + the profile link). Empty period:
"Add a time" / "Pick my next puzzle".

Cost: **24 statements, constant** whatever the period holds (`MyActivityControllerTest::testThePageCostsTheSameWhateverThePeriodHolds`):
results with each one's XP and personal-best mark through LATERALs, places from one `GetRanking::allForPlayer()`.

Why it is worth having:

- **For players**: the e-mail stops being a dead end - every line lands somewhere real, and "what did I do this
  week?" gets one honest answer with every number explained (XP per result → XP history). It is a gentle review ritual
  (a week, two, a month - the player's rhythm), with a reason to come back (next week, the next tier), a natural
  share moment, and a place to see what a free player is missing without nagging.
- **For MSP**: one landing page for every recurring touch (the e-mail now, later the hub, notifications, the
  recap's "see your week"), return visits on Monday, a membership door that shows instead of tells, and a cheap
  surface for future period content (events attended, karma) without growing the e-mail.
- **What it must not become**: another dashboard. No charts, no league tables, no comparison with others (the
  leaderboards do that), no settings, no pressure ("you missed …", streak alarms). Only things that happened in the
  period, plus one "what next" block. New sections must earn a line in the e-mail first.

## Settings and unsubscribe

- Edit profile → "Messaging & notifications": **Activity e-mail** select (weekly / every two weeks / monthly / off),
  shown once the launch switch is on (admins before). The XP opt-out label is now "Hide XP and levels" with a help text
  that says what it really does (pages, e-mails, XP + AP leaderboards, achievement holder lists).
- Token preferences page `email_preferences` (no sign-in; linked from every digest as "Change e-mails"): a "Your
  activity" card with the same select next to the newsletter, the unread-messages digest and "Your results"; saved by
  `EditEmailPreferences::$contentDigestFrequency` (null = not shown, kept).
- One-click unsubscribe `content_digest_unsubscribe` - **`/{_locale}/activity-digest/unsubscribe/{playerId}`** (was
  `weekly-digest`, never deployed), signed, never expires, RFC 8058 POST → 200, button → 303, GET only shows the page,
  which also offers "Choose every two weeks or monthly" (the token preferences page). Sets the frequency to `off`.

## Production (orchestrator - lily.srv infra repo, not this repo)

1. **Consumer**: `digest-consumer` compose service (copy of `messenger-consumer`, command
   `messenger:consume content_digest_emails --time-limit 3600 --memory-limit 256M`) + `restart_consumers
   messenger-consumer digest-consumer` in `deploy.sh` (§13 below). Without it nothing is delivered.
2. **Cron** (`apps/myspeedpuzzling/cron.d/myspeedpuzzling`, `CRON_TZ=Europe/Prague`), replacing the old Sunday row:

```cron
# Activity digest (docs/features/content-digest/README.md) - Monday: the activity digests of every frequency due,
# spaced 07:00-19:00 Prague by the app; Tuesday: "We miss you" (last result 28+ days ago, once per quiet spell).
# Paused in code until the period of 12-18 Oct 2026 (first send Mon 19 Oct; first "We miss you" Tue 27 Oct) and
# while the XP launch switch is off.
1 7 * * 1 root lily-cron-run myspeedpuzzling send-content-digest-activity -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "1 7 * * 1" --timezone "Europe/Prague" send-content-digest-activity -- bin/console myspeedpuzzling:send-content-digest activity >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
1 7 * * 2 root lily-cron-run myspeedpuzzling send-content-digest-miss-you -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "1 7 * * 2" --timezone "Europe/Prague" send-content-digest-miss-you -- bin/console myspeedpuzzling:send-content-digest miss-you >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
```

3. Before Mon 19 Oct: `bin/console myspeedpuzzling:send-content-digest activity --dry-run` on the box (counts per
   frequency) - and `miss-you --dry-run` before Tue 27 Oct. The first two "We miss you" Tuesdays follow the wave plan
   (§"We miss you") by hand; the Tuesday cron row goes in from Tue 10 Nov.
4. Watch: the breaker's warning in Sentry, `content_digest_run` counters, `email_audit_log` for `content_digest_*`.

---

# History: the July 2026 design (superseded where it disagrees with the part above)

## 1. Volume & provider limits

| | Today (10k users) | +1 year (20k users) |
|---|---|---|
| Daily digest (ceiling: all opted-in) | 10,000/day | 20,000/day |
| Weekly digest | 10,000/week (~43k/mo) | 20,000/week (~87k/mo) |
| Monthly newsletter (future) | 10,000/mo | 20,000/mo |
| **Total per month (ceiling)** | **~353,000** | **~707,000** |
| Worst-case single day (all aligned) | 30,000 | 60,000 |

Actual daily volume will be well below the ceiling: the daily digest only sends when the player has
content (see §9), and the weekly digest skips players after a no-activity send (see §7).

**Seznam limits** ([inbound bulk limits](https://o-seznam.cz/napoveda/email/pro-odesilatele-hromadnych-zprav/limity-pro-velikost-rozesilky/)):
max 100 messages per SMTP connection (Symfony's `SmtpTransport` restarts the connection every
100 messages by default — no action needed), max 1,500 connections/IP/5 min, max 100,000 messages
or 1 GB per IP per 5 min, and bulk sends should be spread over tens of minutes to hours. At our
target pace of ~2–4 msg/s (single consumer's natural throughput) we sit at ~1% of these caps.

Two caveats:

1. **That page governs delivery TO Seznam mailboxes.** We relay outbound through `smtp.seznam.cz`
   (Email Profi), whose per-account outbound fair-use ceiling is not publicly documented.
   **Open question: confirm with Seznam support before exceeding ~10k/day** through one identity.
   Mitigation exists already: two separate sender identities (`notify@notify.myspeedpuzzling.com`,
   `news@news.myspeedpuzzling.com`).
2. **Most recipients are not on Seznam.** Gmail's bulk-sender rules (≥5k/day to Gmail) are the
   stricter regime: aligned SPF+DKIM+DMARC, RFC 8058 one-click unsubscribe, spam-complaint rate
   < 0.3% (target < 0.1%). §10 and §14 cover both.

Because we relay through Seznam's IPs, **our DKIM domain is our reputation** — there is no IP
warm-up, only domain warm-up (§14).

## 2. What already exists (reused, not rebuilt)

- **Mailer**: two named transports in `config/packages/mailer.php` — `transactional` (default) and
  `notifications`; per-message selection via the `X-Transport` header. Digests use `notifications`
  (`MAILER_NOTIFICATIONS_DSN` = Seznam identity `notify@notify.myspeedpuzzling.com`), same as the
  unread-messages digest (`PrepareDigestEmailForPlayerHandler`).
- **Messenger**: single `command_bus` with `ClearEntityManagerMiddleware` + `doctrine_transaction`;
  Doctrine transport on Postgres (`messenger_messages`, composite index
  `(queue_name, available_at, delivered_at, id)` since `Version20260213165323`); `failed` failure
  transport. Consumers poll with `FOR UPDATE SKIP LOCKED`; ack deletes the row, so the table stays
  near-empty between runs. A second queue on the same table is the established pattern
  (`doctrine://default?queue_name=failed`).
- **Email audit**: `EmailAuditSubscriber` transparently logs every send (works with the direct
  transport send in §3/D1 — it skips `queued=true` events and correlates by original message
  object). Needs digest-specific adjustments before launch (§12).
- **Templates**: Inky (`twig/inky-extra`) + `inline_css`, `emails` translation domain, per-player
  `->locale()`, `_header`/`_footer` partials, `_base_newsletter.html.twig` starter.
- **Preferences UI**: `MessagingSettingsFormType` on the edit-profile page; `Player.newsletterEnabled`
  exists (unused by any sender — reserved for the future newsletter).
- **Cron pattern**: host crontab on spare.srv wrapping console commands in
  `sentry-cli monitors run` via `docker compose run --rm messenger-consumer` (since 2026-07: lily.srv's
  `apps/myspeedpuzzling/cron.d/myspeedpuzzling`, same pattern plus `lily-cron-run` and `--no-deps`, see §13).
- **DNS**: SPF (`include:spf.seznam.cz`) on all three domains; DMARC `p=quarantine` on root,
  `p=none` on `notify.`/`news.` subdomains (tightening plan in §14).
- **Absolute URLs from workers**: `config/packages/routing.php` sets `default_uri` from `APP_URL` —
  email templates already emit absolute links from CLI/worker context.

## 3. Architecture overview

```
host cron (17:00 daily / Sun 18:00 weekly, Sentry-monitored)
  └─ myspeedpuzzling:send-content-digest <daily|weekly>
       • eligibility SQL (preferences + NOT EXISTS content_digest_log for period + no-activity rule)
       • dispatches SendPlayerContentDigest(playerId, type, periodKey) per player
         with DelayStamp stagger (index × 250 ms) → queue `content_digest_emails`
            └─ dedicated digest-consumer container: messenger:consume content_digest_emails
                 SendPlayerContentDigestHandler:
                   1. re-check eligibility (player, email, preference, period log)
                   2. staleness guard (drop silently if period too old)
                   3. gather content blocks (per-user queries)
                   4. render TemplatedEmail (locale-aware, Inky)
                   5. TransportInterface->send() — synchronous SMTP, X-Transport: notifications
                   6. persist ContentDigestLog row (same transaction, unique per player+type+period)
                 failure: 4xx → rethrow (Messenger retry w/ backoff) · permanent 55x → log + commit, no retry
```

### Key decisions (and rejected alternatives)

**D1 — SMTP happens inside the digest handler via direct `TransportInterface->send()`, not
`MailerInterface->send()`.** `MailerInterface` enqueues a `SendEmailMessage` routed to `async`
(`config/packages/messenger.php`), so the actual SMTP would run in the shared async consumer:
unthrottled, interleaved with transactional mail for hours during a drain, and retried with the
default strategy (3×, seconds) instead of ours. Direct send puts pacing, retry policy, and failure
classification exactly where SMTP happens, and keeps the transactional path completely isolated.
Verified against vendor: `Transports::send()` honors `X-Transport` (and removes/re-adds it);
`AbstractTransport::send()` fires `MessageEvent(queued=false)` → `SentMessageEvent`/`FailedMessageEvent`,
so `EmailAuditSubscriber` records exactly one audit row; `TransportInterface` autowires to the
`Transports` aggregate. Trade-off vs the mailer path: we lose "log row and email enqueue commit
atomically" — accepted, because Messenger's ack semantics are at-least-once anyway (a crash between
SMTP `250 OK` and ack can duplicate one digest; rare and harmless), and re-rendering on retry means
*fresher* content, not stale.

**D2 — Pacing via `DelayStamp` staggering, not a rate limiter (v1).** `symfony/rate-limiter` is NOT
installed. The dispatch command stamps each message with `new DelayStamp($index * 250)` — the exact
pattern of `RecalculateBadgesConsoleCommand::BACKFILL_DELAY_MS` — so 20k messages become available
over ~83 minutes and the Doctrine transport (which honors `available_at`) releases them gradually.
Note the stagger releases at 4 msg/s while the consumer realistically sustains 2–4 msg/s — so the
actual drain time is bounded by consumer throughput (2h47m at the 2/s planning rate for 20k), not
by the release window; the stagger's job is to cap the *maximum* SMTP rate, and the consumer's
natural ceiling is itself far below any Seznam limit. **Upgrade path** when a second consumer
becomes necessary (when volume exceeds what one consumer clears in the acceptable send window —
~14k per 2h at the 2/s planning rate, see §13): `composer require symfony/rate-limiter`, define a token-bucket limiter
under `framework.rate_limiter`, and set `'rate_limiter' => '<name>'` as a **transport-level key**
(sibling of `dsn`/`retry_strategy` — NOT inside `options`, the Doctrine transport rejects unknown
options). Limiter storage defaults to the `cache.rate_limiter` pool (parent `cache.app` = Redis in
this app), so multiple consumers share one window out of the box. Note: a rate-limited transport
blocks its whole worker while throttled — which is fine only because the digest consumer is dedicated (D3).

**D3 — Dedicated `digest-consumer` container in production.** Copy of `messenger-consumer` running
`messenger:consume content_digest_emails --time-limit 3600 --memory-limit 256M` (§13). Rejected: consuming
`async content_digest_emails` from one worker (priority order) — workable, but a slow digest drain shares
one thread with everything async, memory/restart behavior couples the two, and D2's future rate
limiter would stall async messages.

_Queue rename (2026-10-04 rebase): the queue was `digest_emails` until main shipped the unread-messages
digest's own `digest_emails_*` routes and services - `content_digest_emails` keeps the two apart._

**D4 — No suppression table in v1.** With a relay, recipient-stage failures (mailbox unknown/full)
surface asynchronously as bounce emails to the sender mailbox, not at SMTP submission — a
suppression table would stay empty until bounce-mailbox processing exists. v1 gates on preferences
and handles synchronous permanent failures per §6; Phase 4 (§14) adds bounce polling + FBL and
suppresses via the existing `EmailAuditLog` bounce fields (`bounceType`, `bouncedAt`, `recordBounce()`).

**D5 — Fixed send time.** `Player` has `locale` and `country` but no timezone column. The
production host runs `Etc/UTC` and its cron (Ubuntu 22.04 vixie-cron) does **not** support
`CRON_TZ` (verified on spare.srv), so schedules are expressed in UTC with accepted ±1h DST drift:
daily at 16:00 UTC (= 17:00 Prague winter / 18:00 summer), weekly Sunday 17:00 UTC (= 18:00/19:00
Prague). Per-user timezone segmentation is a possible later enhancement (needs a new column;
country→timezone is ambiguous).

## 4. Data model

### `ContentDigestLog` (new entity, table `content_digest_log`)

One row per attempted/sent digest — the idempotency anchor and the state for the no-activity rule.

| column | type | notes |
|---|---|---|
| `id` | uuid | `Uuid::uuid7()` |
| `player_id` | uuid FK | indexed |
| `digest_type` | string enum | `daily` \| `weekly` |
| `period_key` | string | `2026-07-11` (daily) / `2026-W28` (weekly) — computed by the dispatch command, ISO week |
| `sent_at` | datetimetz_immutable | from `ClockInterface` |
| `had_activity` | bool | false = the "we haven't seen a solve" variant was sent — drives the never-two-in-a-row rule |
| `status` | string enum | `sent` \| `failed_permanent` |

Class-level `#[UniqueConstraint(columns: ['player_id', 'digest_type', 'period_key'])]` (convention:
`DismissedHint`, `PlayerSkillHistory`). Migration is **generated** (`doctrine:migrations:diff` in
the web container), never hand-written.

### `Player.contentDigestFrequency` (new preference)

New string-backed enum `src/Value/ContentDigestFrequency.php`: `None = 'none'`, `Daily = 'daily'`,
`Weekly = 'weekly'`. Semantics: `Daily` subscribes to **both** digests (the weekly run targets
`frequency IN ('daily', 'weekly')`, the daily run targets `frequency = 'daily'` — spelled out in §8
because a naive equality match would silently drop daily subscribers from the weekly send). Column
follows the `Player.php` enum convention:
`#[Column(type: Types::STRING, enumType: ContentDigestFrequency::class, options: ['default' => 'weekly'])]`
+ `changeContentDigestFrequency()` mutator. The existing `EmailNotificationFrequency` is NOT reused —
it throttles the unread-messages digest and has different semantics.

Default for existing players: **`weekly`** (the XP launch checklist expects the weekly digest to
reach players by default, with opt-out). The ramp-up plan (§14) controls actual exposure, not the
default value. `emailNotificationsEnabled = false` is respected as a global kill-switch for the
player regardless of digest frequency.

## 5. Messenger configuration (exact edits)

`config/packages/messenger.php` — add transport + routing (array-style `App::config`, matching the
existing file):

```php
'transports' => [
    // ... existing sync / failed / async ...
    'content_digest_emails' => [
        'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%?auto_setup=false&queue_name=content_digest_emails',
        'retry_strategy' => [
            'max_retries' => 5,
            'delay' => 60_000,        // 1 min
            'multiplier' => 4,        // 1m → 4m → 16m → 64m → 4h (capped; ±10% default jitter)
            'max_delay' => 14_400_000, // 4 h
        ],
    ],
],
'routing' => [
    // ... existing ...
    SendPlayerContentDigest::class => 'content_digest_emails',
],
```

`auto_setup=false` is harmless for a new `queue_name` — it only governs table creation, and
`messenger_messages` exists. Retry delays are honored by the Doctrine transport via `available_at`;
the 4h delay is safe (`redeliver_timeout` only applies to in-flight rows, not pending delayed ones).

`config/packages/dev/messenger.php` — route `SendPlayerContentDigest::class => 'sync'`
(mirrors `PrepareDigestEmailForPlayer`; dev runs no consumer).

`config/packages/test/messenger.php` — add `'content_digest_emails' => ['dsn' => 'in-memory://']` to
transports and `SendPlayerContentDigest::class => 'sync'` to routing.

## 6. Retry & failure handling

The automatic-retry requirement is satisfied by the transport `retry_strategy` above; exhausted
retries land on the existing `failed` transport (`messenger:failed:retry` for manual replay,
failures visible in Sentry).

Inside `SendPlayerContentDigestHandler`, failures are classified — this interacts with the
`doctrine_transaction` middleware, which **rolls back the whole handler transaction on any throw**
(including `UnrecoverableMessageHandlingException`), so the pattern is:

- **Transient (retry)** — connection errors (`TransportException`, code 0) and SMTP **4xx**
  (`UnexpectedResponseException::getCode()` in 400–499, e.g. greylisting/throttling): let the
  exception bubble. Rollback discards the log row; Messenger re-dispatches with backoff; the retry
  re-renders with fresh data. Note: the rollback also discards the `EmailAuditLog` row that
  `EmailAuditSubscriber` created synchronously inside the same transaction — transiently-failed
  attempts leave no audit record, only Sentry; the eventual successful retry creates a fresh one.
  (Permanent failures commit, so their audit rows survive.)
- **Permanent (no retry)** — SMTP **55x recipient-stage** codes (550/551/552/553/554): **catch,
  persist `ContentDigestLog` with `status = failed_permanent`, and return normally** so the
  transaction commits and the message is acked. Never throw after persisting — the row would roll
  back AND the message would not retry (verified against `DoctrineTransactionMiddleware` +
  `SendFailedMessageForRetryListener`). Caution: 5xx also occurs at non-recipient stages (535 auth,
  554 relay-denied at MAIL FROM) — those indicate a sender-side problem, treat as transient (bubble)
  so Sentry alerts fire rather than silently marking players failed.
- **Staleness guard** — before doing any work: if `now` is past the period end + TTL (daily: 20h,
  weekly: 3 days), log a skip and return normally. Nobody wants yesterday's daily digest delivered
  after today's.
- **Eligibility re-check** — re-fetch the player and bail (return normally) on `email === null`,
  preference off, or an existing log row for the period. Hours pass between dispatch and consume;
  preferences change. (Same pattern as `PrepareDigestEmailForPlayerHandler`.)

Delivery semantics are **at-least-once**: a consumer crash between SMTP `250 OK` and ack can
duplicate one digest for one player (row redelivered after `redeliver_timeout`, default 1h). The
unique constraint prevents duplicate log rows; the duplicate email itself is accepted as rare and harmless.

## 7. Idempotency & the no-activity rules (from the XP launch checklist)

- **One digest per player per period**, enforced three times: eligibility SQL
  (`NOT EXISTS content_digest_log …`), handler re-check, DB unique constraint. The dispatch command
  is safe to re-run after a crash — it only picks up players without a log row for the period.
- **Weekly digest sends even on a no-activity week** (warm, encouraging tone — never guilt), and the
  log row records `had_activity = false`.
- **Never two no-activity digests in a row**: the eligibility SQL excludes players whose most recent
  weekly `content_digest_log` row has `had_activity = false` AND who have logged no activity since
  that send. Once they log a solve, the next weekly digest resumes.
- The **daily** digest is strictly content-gated: if all daily blocks are empty, the handler writes
  no log row and sends nothing (cheap; the "did anything happen" check runs before rendering).
  No-activity messaging is a weekly-only concept. Honesty note on re-runs: because empty dailies
  leave no log row, a *manual* same-day re-run of the daily dispatch re-dispatches (and re-checks)
  most of the daily audience — no duplicate emails result, just queue churn, and players whose
  content appeared between the runs get a late digest. Cron runs once per day, so this only matters
  for manual replays.

## 8. Dispatch command

`src/ConsoleCommands/SendContentDigestConsoleCommand.php` —
`myspeedpuzzling:send-content-digest <daily|weekly>`, thin per convention (parse input → query →
dispatch; logic lives in the handler):

- Eligibility from a raw-SQL query class `src/Query/GetPlayersForContentDigest.php`
  (heredoc SQL, DBAL `Connection`, `ClockInterface` — copy `GetPlayersWithUnreadMessages` style):
  `email IS NOT NULL AND email_notifications_enabled` AND — **weekly run**:
  `content_digest_frequency IN ('daily', 'weekly')`; **daily run**:
  `content_digest_frequency = 'daily'` — minus `NOT EXISTS` period log, minus the no-activity
  rule (§7), dedup by email.
- Computes `period_key` once, dispatches `SendPlayerContentDigest` per player with
  `new DelayStamp($index * self::STAGGER_MS)` (250 ms default, constant).
- Dispatching 20k messages is **not** one giant transaction: each `dispatch()` from a console
  command is its own small `BEGIN → INSERT → COMMIT` (verified: `DoctrineTransactionMiddleware`
  wraps per-envelope; commands are never transaction-wrapped). No EM chunking needed — eligibility
  is raw SQL, nothing hydrates into the EM. Precedent: `RecalculateBadgesConsoleCommand --backfill`.
- Tests target the handler and the query class directly, not the command.

## 9. Content blocks

The pipeline is content-agnostic; blocks are composed per digest type. Personalization = shared
layout + per-block Twig partials + translated texts (English first, other locales via the
missing-translations workflow before launch).

### Weekly — "Your week in puzzling" (sends to everyone opted in)

Ordered: personal wins → social → discovery. Members-only blocks render a teaser for free users.

| # | Block | Source | Cost | Gating |
|---|---|---|---|---|
| 1 | **XP gained, levels gained, achievements earned** (headline, per XP launch rules: members = full detail, free = "X achievements are waiting" teaser) | XP/levels feature (pending build); badges roundup meanwhile via `GetBadges::forPlayer` filtered to the week | cheap | achievement detail members-only |
| 2 | Your week in numbers (solves, pieces, time vs last week) | windowed variant of `GetPlayerChartData`/`GetPlayerStatistics` | moderate (new windowed query) | free |
| 3 | Streak recap | `ActivityCalendarStreakCalculator` | moderate | honor `streak_opted_out` |
| 4 | Favorites' activity roundup | `GetRecentActivity::ofPlayerFavorites` + week window | cheap (exists) | free; only public solves |
| 5 | Progress to next achievement ("2 solves to Silver") | `Results/BadgeProgress` | moderate | members-only |
| 6 | MSP rating / skill-tier movement this week | **new query** over `player_rating_snapshot` (daily snapshots already written by `PuzzleIntelligenceRecalculator::recordRatingSnapshots`; no read query exists yet) — today's row minus ~7-days-ago row | new-agg | members-only; honor `ranking_opted_out` |
| 7 | Upcoming competitions | `GetCompetitionEvents::allUpcoming` | cheap, global (compute once per run) | free |
| 8 | Most-solved puzzle of the week | week variant of `GetMostSolvedPuzzles::topInMonth` | cheap, global | free |

The weekly digest ships with whatever subset is ready — block 1 becomes the headline when XP/levels
lands; until then blocks 2–4 carry the narrative. No-activity variant: replaces blocks 1–3 with the
encouraging community-voice message.

### Daily — strictly "only if something happened" (all blocks empty most days)

| # | Block | Source | Trigger |
|---|---|---|---|
| 1 | Streak-at-risk nudge | streak calculator: last active day = yesterday, nothing today | streak in jeopardy |
| 2 | Favorites' new solves today | `GetRecentActivity::ofPlayerFavorites`, today window | any favorite solved |
| 3 | Wishlist ↔ new marketplace listings match | **new query** joining wishlist puzzle ids × new `sell_swap_list_item` rows | new match |
| 4 | Pending to-dos (rate transaction, borrowed-unsolved reminder) | `GetTransactionRatings` pending, `GetBorrowedPuzzles::unsolvedByHolderId` | anything pending |

All four empty → no email, no log row.

**Do not duplicate existing emails**: the immediate badge email, unread-messages digest, and
transactional notifications keep their channels; the weekly digest only *rolls up* (badges) or
surfaces never-emailed data (favorites' activity — currently in-app notification only).

## 10. Unsubscribe & headers (required before first bulk send)

Brought in line with main's signed-unsubscribe pattern on the 2026-10-04 rebase (`DigestEmailsUnsubscribeUrl`,
`ResultEmailsUnsubscribeUrl` - docs/features/transactional-emails.md):

- `ContentDigestUnsubscribeUrl::forPlayer($playerId, $locale)` signs route `content_digest_unsubscribe`
  (`/{_locale}/weekly-digest/unsubscribe/{playerId}`, uuid requirement) with `UriSigner` and **no expiry** - an
  unsubscribe link in an old e-mail must keep working. The XP reveal e-mail carries the same link.
- `UnsubscribeContentDigestController` - same contract as `DigestEmailsUnsubscribeController`: verifies
  `checkRequest()`; **POST** dispatches `ChangeContentDigestFrequency($playerId, None)` and answers the RFC 8058
  one-click POST (`List-Unsubscribe=One-Click`) with a plain-text 200 (RFC 8058 forbids a redirect) and the page's
  button with a 303 back to the page; **GET** only shows the page (subscribed: confirm button, else done) with
  `Cache-Control: no-store` - mail scanners open links and must never unsubscribe anybody.
- Every digest email carries:
  - `List-Unsubscribe: <https://…signed url…>` (+ optional `mailto:`)
  - `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  - `Precedence: bulk` (explicitly recommended by [Seznam](https://o-seznam.cz/napoveda/email/pro-odesilatele-hromadnych-zprav/doporucene-nastaveni-odesilacich-domen/))
- Footer: visible unsubscribe link (same signed URL) + link to edit-profile preferences
  (Czech law 480/2004 + Gmail/Yahoo requirements).
- Caveat: `UriSigner` validates the full URL incl. scheme/host — generation uses canonical
  `APP_URL`, so keep trusted-proxy handling intact in production.

## 11. Templates & translations

- `templates/emails/content_digest_weekly.html.twig` + `content_digest_daily.html.twig` — standard
  wrapper (`{% trans_default_domain 'emails' %}`, `inky_to_html|inline_css(source('@styles/foundation-emails.css'))`,
  `_header`/`_footer` includes); one Twig partial per content block so blocks compose cleanly.
- Subjects translated in PHP (`$translator->trans(key, domain: 'emails', locale: $player->locale)`),
  body locale via `->locale($player->locale ?? 'en')`.
- English first (project convention); fill cs/de/es/fr/ja via the missing-translations workflow
  before ramp-up (launch-checklist §5 applies to digest copy too).
- From: `notify@notify.myspeedpuzzling.com`, `X-Transport: notifications` (copy the unread digest).
- Consistent From name across all sends (reputation: recipients recognize the sender).

## 12. Email-audit adjustments (required before ramp-up)

At 350–700k sends/month the current audit layer becomes the bottleneck — `EmailAuditSubscriber`
stores the **full HTML body** per send (~10–25 GB/month raw at scale) and the cleanup handler runs
one unbatched DELETE in a single transaction (WAL burst; xmin horizon blocks vacuum everywhere,
including `messenger_messages`, for minutes):

1. **Skip `body_html` (and `smtp_debug_log`) for digest email types** — store template name + params
   digest instead.
2. **Shorter retention for digest types** (14–30 days) vs 90 days for the rest — extend
   `CleanupEmailAuditLogs` with an optional email-type filter.
3. **Batch the cleanup DELETE** (`… WHERE id IN (SELECT id … LIMIT 10000)` per committed batch; one
   message per batch or a non-wrapped DBAL path — the `doctrine_transaction` middleware otherwise
   re-wraps everything in one transaction).
4. **Indexes**: single-column indexes on `sent_at`, `status`, `recipient_email`, `message_id`
   already exist — add composite `(email_type, sent_at)` (`email_type` is unindexed today) and
   consider `(status, sent_at)` for filtered+ordered admin queries; the admin UI's
   `distinctEmailTypes()` (unindexed `email_type`) and whole-table counts need caching (~60s) or
   removal from the default render path; consider a `custom_` GIN trigram index for the
   recipient ILIKE search (existing `custom_*` index pattern, mirror in `tests/bootstrap.php`).

`messenger_messages` itself is fine: ~20k inserts+deletes/day is two orders of magnitude below
queue-bloat territory; optionally set per-table autovacuum
(`autovacuum_vacuum_scale_factor = 0.01, threshold = 1000`) and watch `n_dead_tup`.

## 13. Production rollout (lily.srv)

_Rewritten 2026-10-04 for lily.srv (spare.srv, which the original plan targeted, is decommissioned). Infra changes live
in the lily.srv repo, `apps/myspeedpuzzling/` - never hand-edit `/srv/myspeedpuzzling` on the box._

**Throughput reality**: single-threaded consumer ≈ 150–450 ms/message (render + queries + SMTP) →
sustained 2–4 msg/s; plan capacity at 2/s. 20k drains in ~2h47m at 2/s. A second consumer becomes
necessary around ≥25k/day within a 2-hour window — at that point add the rate limiter (D2) so both
consumers share one Redis-backed window.

**New compose service** (`apps/myspeedpuzzling/compose.yaml`) — a copy of `messenger-consumer`
(same image, `env_file` and `environment` block) with only the command changed. Without it
`SendPlayerContentDigest` messages pile up in the `content_digest_emails` queue - `messenger-consumer`
consumes `async` only:

```yaml
digest-consumer:
    image: ghcr.io/myspeedpuzzling/website:${IMAGE_TAG:-main}
    restart: always
    command: "bash -c 'php bin/wait-for-database 60 && exec bin/console messenger:consume content_digest_emails --time-limit 3600 --memory-limit 256M'"
    env_file:
        - .env
    environment:
        # identical to messenger-consumer — copy the block verbatim
```

**`deploy.sh`** — step 6 restarts the workers with the new image and a fresh `.env`; the digest
consumer must be in that list or it keeps running the previous release:

```bash
restart_consumers messenger-consumer digest-consumer
```

**Cron** (`apps/myspeedpuzzling/cron.d/myspeedpuzzling`): the row is in
`docs/features/xp-levels/README.md` §Cron. lily's cron file sets `CRON_TZ=Europe/Prague`, so the
weekly digest fires at 18:01 Prague all year (D5's ±1h DST drift was a spare.srv limitation).
The daily digest (Phase 3, not built) would follow the same pattern.

## 14. Reputation plan

Relaying through Seznam means domain reputation (DKIM `d=notify.myspeedpuzzling.com`) is everything.
Per Seznam, reputation is driven by recipient reactions — opens, deleted-unread, spam markings
([user-signal rules](https://o-seznam.cz/napoveda/email/pro-odesilatele-hromadnych-zprav/zasady-prace-s-databazi-kontaktu-a-vyhodnoceni-uzivatelskeho-signalu/)).

**Phase order matters — hard requirements before the first bulk send:**

1. One-click unsubscribe + headers (§10). Non-negotiable for Gmail at ≥5k/day.
2. Register both DKIM domains in [Seznam FBL](https://o-seznam.cz/napoveda/email/pro-odesilatele-hromadnych-zprav/feedback-loop-fbl/)
   (fbl.seznam.cz — needs an `abuse@`/`postmaster@` confirmation address on the domain). Reports are
   per-spam-marking ARF emails with original headers; a small cron command polls that mailbox and
   flips the player's digest preference to `none` (v1) / feeds suppression (Phase 4).
3. Register `myspeedpuzzling.com` in Google Postmaster Tools — the spam-rate dashboard is the
   primary early-warning during ramp-up. Abort ramp if spam rate approaches 0.3%.

**Ramp-up (domain warm-up), ~4–6 weeks:**

| Week | Weekly digest audience |
|---|---|
| 1 | ~1–2k most-engaged players (recent login/solve) |
| 2 | ~4k |
| 3 | ~8k |
| 4+ | everyone opted-in |

Start the daily digest ~2 weeks after the weekly is at full volume (its content gating keeps volume
low anyway). Implementation: the eligibility query takes a `--limit` + engagement ordering during
ramp; remove after.

**Ongoing hygiene:**

- Engagement gating: daily digest only sends with content (§7/§9); consider downgrading players
  inactive >90 days from daily→weekly automatically, and pausing weekly after ~180 days of zero
  engagement (the never-two-no-activity rule already approximates this).
- DMARC: after 2–4 weeks of clean `rua` reports at full volume, move `notify.` (and later `news.`)
  from `p=none` to `p=quarantine`, then `p=reject`.
- Watch the audit dashboard: sent/failed per type per day; Sentry alerts on cron failure and
  `failed`-transport arrivals.

**Phase 4 — bounce processing (after launch):** Seznam Email Profi forces envelope sender = login,
so VERP stays disabled (`docs/features/emails-tracking.md`). Instead: poll the `notify@` mailbox
(POP3 `pop.seznam.cz:995`) for DSNs with a console command, correlate via the SMTP `message_id`
already stored in `EmailAuditLog`, call `recordBounce()`, and exclude hard-bounced addresses in the
eligibility SQL (`NOT EXISTS (… email_audit_log WHERE bounce_type = 'hard' …)`). For the future
Listmonk newsletter: enable the `[bounce]` section in `listmonk.toml` (scaffold exists, commented out).

## 15. Future: monthly newsletter (out of scope now)

Same-content localized campaigns go through **Listmonk** (already deployed at
`listmonk.myspeedpuzzling.com`, `news@news.myspeedpuzzling.com` SMTP configured, zero code
integration today): sync `Player.newsletterEnabled` into per-locale lists via the Listmonk API
(`LISTMONK_API_USER/TOKEN` env vars already provisioned), pull unsubscribes back, enable bounce
processing, set the sliding-window throttle (~10k/hour). Gets campaign editor, archive, and
open/click tracking for free. Decide in ~6 months whether it earns its keep or the in-app pipeline
absorbs the newsletter too.

## 16. Implementation checklist

**Phase 1 — pipeline (no emails sent yet)**
- [x] `ContentDigestFrequency` enum + `Player.contentDigestFrequency` column + generated migration
- [x] `ContentDigestLog` entity + repository (persist-only) + generated migration
- [x] `SendPlayerContentDigest` message + `content_digest_emails` transport + routing (base/dev/test config)
- [x] `SendPlayerContentDigestHandler` (eligibility re-check, staleness guard, direct transport send,
      failure classification per §6, log row) — note: 554 is treated as TRANSIENT (bubbles to retry)
      because it cannot be distinguished from MAIL FROM relay-denied at submission time
- [x] `GetPlayersForContentDigest` query (preferences + period log + no-activity rule + experienceSystemOptedOut exclusion)
- [x] `SendContentDigestConsoleCommand` with `DelayStamp` stagger (weekly only; refuses before the XP launch switch is on)
- [x] Preference in `MessagingSettingsFormType`/`FormData`/`EditMessagingSettings`(+Handler)/
      `PlayerProfile`/`GetPlayerProfile`/`EditProfileController`/edit-profile template + translations
      (field hidden before the XP launch switch is on)
- [x] Unsubscribe controller + `ChangeContentDigestFrequency` message + signed URLs +
      `List-Unsubscribe`/`Precedence` headers
- [x] Tests: handler (KernelTestCase, direct invoke), query, unsubscribe controller, form flow (settings visibility)
- [x] Audit adjustments §12 (skip body+debug for content_digest types, 30-day digest retention, batch-per-message delete, (email_type, sent_at) index)

**Phase 2 — weekly digest**
- [x] Weekly template + content-block partials + English translations (other locales before full ramp)
- [x] Content queries: windowed week-in-numbers, favorites-week window, week variant of most-solved
      (in `WeeklyDigestDataProvider`); the `player_rating_snapshot` delta block (block 6) is NOT
      shipped in v1 — "ships with whatever subset is ready" clause applied
- [ ] FBL + Postmaster registration, deliverability smoke test (Gmail/Seznam/Outlook inbox placement)
- [ ] Prod: compose service + deploy.sh + weekly cron (after cron-TZ check)
- [ ] Ramp-up per §14; XP/achievements block joins as headline when XP/levels ships

**Phase 3 — daily digest**
- [ ] Daily template + streak-at-risk / favorites-today / wishlist-match / to-dos queries
- [ ] Daily cron; monitor volume & spam rate for 2 weeks

**Phase 4 — reputation hardening**
- [ ] Bounce-mailbox polling command + eligibility exclusion; FBL ingestion automated
- [ ] DMARC tightening; engagement downgrade/sunset rules

## 17. Open questions

1. **Email Profi outbound cap** — ask Seznam support for the fair-use ceiling per identity/day
   (blocking only for full 20k volume, not for ramp-up).
2. ~~Host cron timezone~~ — resolved: spare.srv runs `Etc/UTC`, installed cron has no `CRON_TZ`
   support; schedules are UTC with DST drift (D5, §13). Moot on lily.srv: its cron file sets
   `CRON_TZ=Europe/Prague`, so the weekly digest fires at a fixed Prague time.
3. **Unread-messages digest consolidation** — fold into the daily content digest ("max one
   MySpeedPuzzling email per day") or keep separate? Recommended: keep separate for v1, revisit
   after complaint-rate data exists.
4. Weekly digest **default-on** for existing players (assumed here per the XP launch plan) — confirm,
   including whether the launch announcement email should mention it (it should).
5. **Sunday overlap for `daily` subscribers** — a daily subscriber with content on Sunday gets the
   daily digest at 16:00 UTC and the weekly an hour later. Content gating makes this rare;
   recommended v1 = allow both and monitor, alternative = skip the daily run for weekly-eligible
   players on the weekly send day.
