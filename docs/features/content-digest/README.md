# Content Digest Emails — Daily & Weekly

Personalized, scheduled digest emails to all opted-in players: a **weekly** "your week in puzzling"
digest (the centerpiece of the XP/levels launch — this document is the "sending mechanics" deliverable
referenced in `docs/features/xp-levels/launch-checklist.md`) and a **daily** "only when something
happened" digest. A monthly same-content newsletter is a future phase (see §15).

> **Scope update (2026-07-12, Jan):** v1 ships the **weekly digest only** — the daily digest is deferred
> completely (its design below stays as the blueprint for when it's picked up; Phase 3 in §16 moves
> behind a separate go decision). Also decided: weekly digest is **default-on** for existing players,
> and the unread-messages digest **stays separate**. The Sunday-overlap open question (§17.5) is moot for v1.

This is a separate system from the existing **unread-messages digest**
(`SendUnreadDigestEmailsCommand` / `PrepareDigestEmailForPlayer` / `DigestEmailLog`), which stays
untouched. To avoid naming collisions, everything in this feature is named **content digest**
(`ContentDigestLog`, `SendPlayerContentDigest`, `ContentDigestFrequency`, …). Transactional emails
are also untouched — they keep their immediate path through the `async` transport.

_Last updated: 2026-07-11. Verified against Symfony 8.0.4/8.0.5 vendor code and the production
deployment at `spare.srv:/deployment/speedpuzzling`._

---

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
  `sentry-cli monitors run` via `docker compose run --rm messenger-consumer`.
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
         with DelayStamp stagger (index × 250 ms) → queue `digest_emails`
            └─ dedicated digest-consumer container: messenger:consume digest_emails
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
`messenger:consume digest_emails --time-limit 3600 --memory-limit 256M` (§13). Rejected: consuming
`async digest_emails` from one worker (priority order) — workable, but a slow digest drain shares
one thread with everything async, memory/restart behavior couples the two, and D2's future rate
limiter would stall async messages.

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
    'digest_emails' => [
        'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%?auto_setup=false&queue_name=digest_emails',
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
    SendPlayerContentDigest::class => 'digest_emails',
],
```

`auto_setup=false` is harmless for a new `queue_name` — it only governs table creation, and
`messenger_messages` exists. Retry delays are honored by the Doctrine transport via `available_at`;
the 4h delay is safe (`redeliver_timeout` only applies to in-flight rows, not pending delayed ones).

`config/packages/dev/messenger.php` — route `SendPlayerContentDigest::class => 'sync'`
(mirrors `PrepareDigestEmailForPlayer`; dev runs no consumer).

`config/packages/test/messenger.php` — add `'digest_emails' => ['dsn' => 'in-memory://']` to
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

No signed-URL pattern exists in the codebase yet; use Symfony **`UriSigner`** (autowired, signs with
`APP_SECRET`, built-in expiry):

- Handler embeds
  `$uriSigner->sign($urlGenerator->generate('unsubscribe_content_digest', ['playerId' => …], ABSOLUTE_URL), new \DateInterval('P30D'))`.
- `src/Controller/UnsubscribeContentDigestController.php` — single-action, public
  (security config already grants `PUBLIC_ACCESS` to `^/`; no firewall changes), single
  non-localized path `/unsubscribe/content-digest/{playerId}`. Verifies `checkRequest()`, dispatches
  `ChangeContentDigestFrequency($playerId, None)`, renders a localized confirmation page. Accepts
  **POST** for RFC 8058 one-click; GET shows a confirm button (protects against link prefetchers
  unsubscribing users).
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

## 13. Production rollout (spare.srv)

**Throughput reality**: single-threaded consumer ≈ 150–450 ms/message (render + queries + SMTP) →
sustained 2–4 msg/s; plan capacity at 2/s. 20k drains in ~2h47m at 2/s. A second consumer becomes
necessary around ≥25k/day within a 2-hour window — at that point add the rate limiter (D2) so both
consumers share one Redis-backed window.

**New compose service** (`/deployment/speedpuzzling/docker-compose.yml`) — copy of
`messenger-consumer` with only the command changed:

```yaml
digest-consumer:
    image: ghcr.io/myspeedpuzzling/website:main
    restart: always
    command: "bash -c 'wait-for-it postgres:5432 -- sleep 5 && bin/console messenger:consume digest_emails -vv --time-limit 3600 --memory-limit 256M'"
    healthcheck:
        test: pgrep -f "messenger:consume" > /dev/null || exit 1
        start_period: 15s
        timeout: 5s
        interval: 30s
        retries: 3
    environment:
        # identical to messenger-consumer — copy the block verbatim
    networks:
        - internal
```

**`deploy.sh`** — the workers section only recreates `messenger-consumer`; without this change the
digest consumer would run a stale image after every deploy:

```bash
docker compose stop messenger-consumer digest-consumer
LOGS_START=$(expr $(date +%s))
docker compose up --detach --force-recreate --remove-orphans messenger-consumer digest-consumer || docker compose logs --since $LOGS_START messenger-consumer digest-consumer
```

**Cron** (`/deployment/speedpuzzling` host crontab, existing sentry-cli pattern):

```cron
0 16 * * * docker compose --file /deployment/speedpuzzling/docker-compose.yml run --rm messenger-consumer sentry-cli monitors run --schedule "0 16 * * *" send-content-digest-daily -- bin/console myspeedpuzzling:send-content-digest daily
0 17 * * 0 docker compose --file /deployment/speedpuzzling/docker-compose.yml run --rm messenger-consumer sentry-cli monitors run --schedule "0 17 * * 0" send-content-digest-weekly -- bin/console myspeedpuzzling:send-content-digest weekly
```

Times are UTC — the host runs `Etc/UTC` and its cron does not support `CRON_TZ` (both verified on
spare.srv), so Prague send times drift ±1h with DST (see D5). A wrapper script checking local time
is the escape hatch if exact Prague times ever matter.

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
- [x] `SendPlayerContentDigest` message + `digest_emails` transport + routing (base/dev/test config)
- [x] `SendPlayerContentDigestHandler` (eligibility re-check, staleness guard, direct transport send,
      failure classification per §6, log row) — note: 554 is treated as TRANSIENT (bubbles to retry)
      because it cannot be distinguished from MAIL FROM relay-denied at submission time
- [x] `GetPlayersForContentDigest` query (preferences + period log + no-activity rule + experienceSystemOptedOut exclusion)
- [x] `SendContentDigestConsoleCommand` with `DelayStamp` stagger (weekly only; refuses while xp-system flag active)
- [x] Preference in `MessagingSettingsFormType`/`FormData`/`EditMessagingSettings`(+Handler)/
      `PlayerProfile`/`GetPlayerProfile`/`EditProfileController`/edit-profile template + translations
      (field hidden while the xp-system flag is active)
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
   support; schedules are UTC with DST drift (D5, §13).
3. **Unread-messages digest consolidation** — fold into the daily content digest ("max one
   MySpeedPuzzling email per day") or keep separate? Recommended: keep separate for v1, revisit
   after complaint-rate data exists.
4. Weekly digest **default-on** for existing players (assumed here per the XP launch plan) — confirm,
   including whether the launch announcement email should mention it (it should).
5. **Sunday overlap for `daily` subscribers** — a daily subscriber with content on Sunday gets the
   daily digest at 16:00 UTC and the weekly an hour later. Content gating makes this rare;
   recommended v1 = allow both and monitor, alternative = skip the daily run for weekly-eligible
   players on the weekly send day.
