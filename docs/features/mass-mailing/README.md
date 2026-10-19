# Mass mailing on Amazon SES

Living doc of what is built. Why SES, volumes and the decision: [`research-2026-10.md`](research-2026-10.md),
[`proposal-2026-10.md`](proposal-2026-10.md).

**AWS (2026-10-13):** account `858093958204` ("projects" sign-up with Google, Free plan; the Paid-plan upgrade fails
at the card's 3-D Secure hand-back, support case pending). Region **eu-north-1** (the only EU region the account
allows). Done: the three domain identities (Easy DKIM 2048 + MAIL FROM `bounce.<domain>`, DNS in Cloudflare),
configuration sets `transactional` / `notifications` / `newsletter`, SNS topics `ses-events-app` (event destination
`app` of transactional + notifications) and `ses-events-newsletter` (`news.` identity Bounce/Complaint feedback with
original headers), account suppression = BOUNCE. SMTP login for the app: IAM user `ses-smtp-app` (only
`ses:SendRawEmail`, only From `@mail.` / `@notify.`), in Infisical prod as `SES_SMTP_USERNAME` / `SES_SMTP_PASSWORD`
(nothing reads them yet; Listmonk gets its own user limited to `@news.` at step 7 - two logins, one per sending
program, not one per domain). Sandbox recipients verified: Jan's iCloud, Gmail and Seznam addresses. Not yet: SNS
subscriptions (after the deploy + `SES_EVENTS_TOPIC_ARNS`).

**Step 3 live (2026-10-13):** prod has `MAILER_SES_DSN` = `failover(SES, Seznam robot@)`, `SES_TEST_RECIPIENTS` = Jan's
three addresses + `bounce@` / `complaint@simulator.amazonses.com`, `SES_EVENTS_TOPIC_ARNS` = both topics; both SNS
subscriptions confirmed by `/-/ses-events`. Verified with `bin/console mailer:test` on prod: 4 test mails through SES
(eu-north-1), delivery events stamped, the simulator bounce → hard bounce + `email_suppression` row, the simulator
complaint → `complained_at`. Every other recipient stayed on Seznam (12 player mails in the same 45 minutes).
**Step 4 done (2026-10-13):** production access granted in eu-north-1 within minutes of the request (case
179192073900182, mail type Marketing, still on the Free plan): 50,000 / 24 h at 14 messages/s. Listmonk at step 7:
stay near 8/s so the app keeps room. Before step 5: the Paid plan (the Free plan stops the account when the credits
or the 6 months - 13 Apr 2027 - run out).
**2026-10-14 (Paid plan since this morning):**
- Configuration sets: TLS **OPTIONAL** on all three (REQUIRE would silently drop mail to the rare server without
  TLS; Seznam delivered opportunistically too). **No SES open/click tracking** (Jan: deliverability first) - the
  `notifications` event destination carries SEND, REJECT, BOUNCE, COMPLAINT, DELIVERY, DELIVERY_DELAY,
  RENDERING_FAILURE only; SES would otherwise rewrite every link to `awstrack.me`. Own first-party tracking = a
  TODO to research.
- Authentication verified with Port25 (`check-auth@verifier.port25.com`) for `notify.`, `mail.` and `news.`: SPF
  pass on `bounce.<domain>`, DKIM pass with `d=<domain>` (aligned → DMARC pass), iprev pass. A second DKIM
  signature `d=amazonses.com` is normal. List-Unsubscribe headers arrive intact and are DKIM-signed. Seznam files
  notification mail under "Newsletters", as before.
- Privacy policy lists Amazon Web Services EMEA SARL (sections 13 + 15, 6 locales, 0a01bd8d).
- **Listmonk on SES (step 7 done):** second SMTP server "Amazon SES" (IAM user `ses-smtp-listmonk`, From
  `@news.` only, key also in Infisical `SES_SMTP_LISTMONK_*`), header `X-SES-CONFIGURATION-SET: newsletter`,
  `max_conns` 4, HELO `news.myspeedpuzzling.com`; the Seznam server kept but **disabled** (rollback = enable it,
  disable SES, reload). Speed: concurrency 2 × `message_rate` 2, sliding window **1,500 / 10 min** (~2.5/s,
  11k in ~75 min, the app keeps ≥ 10/s of the 14/s quota). Bounces: `bounce.ses_enabled` + `webhooks_enabled`
  on, SNS topic `ses-events-newsletter` → `https://listmonk.myspeedpuzzling.com/webhooks/service/ses`
  (confirmed), the Seznam POP scanner **off** (it also deleted human replies). Written via the DB + `POST
  /api/admin/reload`, never a settings PUT; backup `/root/listmonk-smtp-settings-backup-2026-10-14.json`.
  Test campaign 32 to the test list: 6 sent, simulator bounce → hard + blocklisted, complaint → blocklisted
  (source `ses`). Port25's report is a reply to the Return-Path, so SES/Listmonk count it as a soft bounce.
- SES also forwards bounce/complaint mails to the From mailboxes (email feedback forwarding) - harmless extra
  copies in notify@ / robot@ / newsletter@.
- **Notifications (step 6) LIVE 2026-10-14:** `MAILER_NOTIFICATIONS_DSN` = `failover(SES, Seznam notify@)` in web + both queue workers; the first real "Your results" mail went through SES and came back delivered.
- **Transactional (step 5) LIVE 2026-10-14 08:28 UTC:** `MAILER_TRANSACTIONAL_DSN` = `failover(SES, Seznam robot@)`
  (Jan: earlier than planned, the same pipeline as notifications). First real mail (password reset, 08:44) delivered.
  The Seznam DSNs before the switch are kept in Infisical as `MAILER_TRANSACTIONAL_DSN_SEZNAM` /
  `MAILER_NOTIFICATIONS_DSN_SEZNAM` - **rollback** = copy one back, re-run the `deploy` job, recreate the workers.
- **Step 8 done:** `MAILER_SES_DSN` = `null://null` (test mode off; Infisical refuses an empty value, so the inert
  `SES_TEST_RECIPIENTS` list stays). The app's SES SMTP key was rotated the same day (new key in both DSNs and
  `SES_SMTP_USERNAME`/`_PASSWORD`; the old one deactivated - delete it after a clean day).
- **Alerts:** every hard bounce / complaint = Sentry warning (30d1f880, see Rules); CloudWatch alarms
  `ses-bounce-rate-4pct` and `ses-complaint-rate-0.08pct` → SNS `ses-alarms` → e-mail to Jan (`aws-setup.sh alarms`).
**Gotcha:** an env change needs a redeploy (re-run the `deploy` job: `dump_secrets` renders `/srv/myspeedpuzzling/.env`)
**and** `docker compose up -d --no-deps --force-recreate messenger-consumer digest-consumer` - the same-image redeploy
recreates only the web containers, the queue workers keep the old env.

**State (2026-10-14): every stream on SES**, Seznam behind the app's two streams as failover, Listmonk's Seznam server
kept disabled for rollback. (Built switched off 2026-10-12, moved one stream at a time.)

**2026-10-19: hardened after the first weekly digest** - see [Reliability](#reliability-2026-10-19). The app sends
through the SES **API** (`ses+https`), SES first for every stream with one retry and a pause, Seznam behind
transactional mail only; Listmonk's SES pool closes idle connections after 5 s.

## How it works

The app sends to SES over its **HTTPS API** (`ses+https`, `symfony/amazon-mailer` + `async-aws/ses`, IAM access key)
since 2026-10-19 - over SMTP before, see [Reliability](#reliability-2026-10-19). Listmonk speaks SMTP only and keeps
its SMTP server (`email-smtp.eu-north-1.amazonaws.com:587`).

| Stream | Sent by | Mailer transport (env) | SES configuration set | Tracking |
|---|---|---|---|---|
| transactional (sign-in links, verification, password reset, membership…) | app | `ses` (`MAILER_SES_DSN`), fallback `transactional` (`MAILER_TRANSACTIONAL_DSN`, Seznam) | `transactional` | none |
| notifications (unread digest, activity digest / "We miss you", "Your results") | app | `ses` (`MAILER_SES_DSN`), no fallback | `notifications` | none |
| newsletter | Listmonk | Listmonk's SMTP server setting | `newsletter` | Listmonk's own (no SES tracking) |

The stream is the message's `X-Transport` header (none = transactional). `MailStream` is the enum.

### Why three sending subdomains (checked 2026-10-13)

`robot@mail.`, `notify@notify.` and `newsletter@news.` already exist, with their own SPF, DKIM, DMARC `p=reject` and
mailboxes. They stay, each one its own SES identity:
- **Reputation by DKIM domain.** Each SES domain identity signs with its own `d=`, so Gmail (Postmaster Tools)
  and others keep each stream's domain reputation apart. A newsletter complaint wave lands on `news.`, not on
  sign-in links. The isolation is **partial**: Gmail counts the bulk-sender volume (5,000/day) and the compliance
  status over the primary domain and all its subdomains, so subdomains never dodge a rule.
- **The root domain is people's mail** (`jan.mikes@`, Seznam MX). Bulk mail never signs as `myspeedpuzzling.com`.
- **SES settings are per identity.** `news.`'s Bounce/Complaint feedback goes to the topic Listmonk reads. With one
  root identity, the subdomains would inherit its settings and its `d=`.
- **No change for recipients.** Same From addresses, so filters, contacts and DMARC alignment stay as they are.

A separate registered domain (e.g. `myspeedpuzzling-mails.com`) was considered and rejected. It starts with no
reputation and needs warm-up. A From domain that differs from the domain of every link in the mail is a phishing
pattern to filters and to people. It throws away the history the subdomains already have. It would also change the
From addresses. A separate domain protects a brand from cold mailing, and all our mail is opt-in.

Cost: 15 DNS records (3 DKIM CNAMEs + MAIL FROM MX and SPF TXT per identity). Dedicated IPs would isolate more,
but they need warm-up and steady volume we don't have. Every stream stays on SES's shared pool.

### Sending (`src/Services/Mailer/`)

- **`MailStreamRouting`** decorates `mailer.transports`, so every app e-mail passes it before a transport is picked:
  - it marks the stream on the message (`X-MSP-Stream`, removed again before sending);
  - it **drops notifications to a suppressed address** (`email_suppression`, see below). Transactional mail is
    always tried, so a sign-in link is never held back by us;
  - it **picks the transport** - SES first, one retry, the pause, the transactional fallback ([Reliability](#reliability-2026-10-19)).
    The pre-approval test mode (`SES_TEST_RECIPIENTS`) is gone (2026-10-19).
- **`EmailAuditSubscriber`** (existing) adds the SES headers when, and only when, the attempt goes to SES
  (`SesMessageHeaders::isSes()`: an `*.amazonaws.com` host or a `ses+…` transport). It stores the transport's name
  without the API's access key id (`transportLabel()`). `SesMessageHeaders` sets:
  - `X-SES-CONFIGURATION-SET: <stream>`;
  - `X-SES-MESSAGE-TAGS: audit_id=<email_audit_log id>, email_type=<template name>`.

  The tags come back in every event, so an event finds its audit row without a lookup. Over the API they travel in
  the raw message too (SES reads them there, AWS "Specify your configuration set"; the transport also passes the
  configuration set as `ConfigurationSetName`). The decision is made per attempt, so a Seznam attempt gets none of
  it. Every attempt is its own audit row (a failed SES attempt + its retry + a Seznam fallback = three rows).

### Events back (`/-/ses-events`)

1. **SNS topics** post to `POST /-/ses-events` (`SesEventsController`):
   - the configuration sets `transactional` and `notifications` publish to one topic;
   - the `news.` identity's feedback notifications publish to another. Listmonk is subscribed to that topic too.
2. **`SnsMessageVerifier`** checks every message:
   - the signature (SignatureVersion 1 or 2);
   - the certificate, which is only ever fetched from `https://sns.<region>.amazonaws.com/…pem` and cached in
     `sns_certificate_cache`;
   - the topic, which must be one in `SES_EVENTS_TOPIC_ARNS`.

   Anything else gets 403. An empty `SES_EVENTS_TOPIC_ARNS` makes the endpoint 404.
3. A **subscription confirmation** is confirmed by fetching its SubscribeURL, which must be an SNS URL.
4. A **notification** becomes `ProcessSesEvent` on `async`. SNS gets its 204 at once, and SNS retries any non-2xx.
5. **`ProcessSesEventHandler`** applies the rules below (`SesEvent` parses both event shapes).

### Rules (Jan, 2026-10-12)

| Event | Action |
|---|---|
| Delivery / Open / Click | first time stamped on `email_audit_log.delivered_at` / `opened_at` / `clicked_at` |
| Bounce, permanent | `email_audit_log` bounce columns + **`email_suppression`** row for the address: no more notifications, **banner** for the player (below). Transactional still tried |
| Bounce, transient | bounce columns only (soft). Repeated soft bounces are left to SES |
| Complaint, newsletter | `player.newsletter_enabled = false` / guest unsubscribed + pushed to Listmonk (the sync never re-enables it) |
| Complaint, `content_digest_activity` / `content_digest_miss_you` | `content_digest_frequency = off` |
| Complaint, `unread_digest` | `email_notifications_enabled = false` |
| Complaint, `result_review` | `result_emails_enabled = false` |
| Complaint, anything transactional | `complained_at` only |
| Reject / Rendering Failure | warning (→ Sentry) |

**Every hard bounce and every complaint is also a warning** (→ Sentry: "E-mail hard bounced" / "E-mail marked as
spam", context `stream`, `email_type`, `audit_log_id`; addresses are cut out of the server's answer). Jan wants to
know when one appears (2026-10-14). Sentry groups them, so a campaign's bounces are one issue with a count. A newsletter
bounce can arrive twice (configuration set event + `news.` identity feedback).

Events can arrive twice or out of order, so every action is idempotent. An event for an audit row that was already
pruned only applies the rules.

**Banner:** the signed-in player's profile row carries their suppression; a LEFT JOIN in `GetPlayerProfile::byUserId()`
adds no extra query. Every page then shows "We can't deliver e-mails to {email}" (`_email_undeliverable_banner.html.twig`)
with:
- the reason in plain words (`NoEmail` / `General` / `Suppressed`);
- the server's answer;
- a link to change the address.

A new address is not suppressed, so changing it clears the banner.

## Env

| Variable | Default | Meaning |
|---|---|---|
| `MAILER_SES_DSN` | `null://null` | **The switch.** Set = every stream through SES first. Prod: `ses+https://<access key id>:<secret>@default?region=eu-north-1` (IAM user `ses-api-app`). Over SMTP instead it must carry `?ping_threshold=5`. `null://null` = SES off, every stream on its own DSN (the rollback) |
| `MAILER_TRANSACTIONAL_DSN` | Seznam `robot@` | Transactional's fallback when SES fails twice (and its transport while SES is off). Never `failover(...)` again - `MailStreamRouting` decides |
| `MAILER_NOTIFICATIONS_DSN` | Seznam `notify@` | Notifications' transport **only while SES is off** - with SES on, notifications have no fallback |
| `SES_EVENTS_TOPIC_ARNS` | empty | comma-separated SNS topic ARNs allowed to post events; empty = endpoint off |

Secrets in a DSN must be URL-encoded (SES SMTP passwords and AWS secret keys contain `/` and `+`). Rollback keys in
Infisical: `MAILER_SES_SMTP_DSN` (SES over SMTP with `ping_threshold=5` - copy it into `MAILER_SES_DSN`).

## Reliability (2026-10-19)

**What happened.** The first weekly activity digest (Mon 19 Oct, 1,915 recipients, one every 22 s) stopped after 468
with Sentry WEB-E5 "No transports found" and the digest breaker's warning. `email_audit_log` told the story:

1. **SES closes an idle SMTP connection after about 10 s** ("451 4.4.2 Timeout waiting for data from client",
   answered straight to `MAIL FROM`). Symfony's SMTP transport checks a kept connection (NOOP) only after
   `ping_threshold` = 100 s of quiet, so every gap of ~10-100 s between two e-mails of one worker hit a dead
   connection. Symfony's mailer docs say it for `ses+smtp` with Messenger: `ping_threshold` below 10. It had been
   failing 3-7 times a day since 14 Oct, unnoticed (each one fell back to Seznam).
2. **`failover()` is sticky.** After one SES failure Symfony's FailoverTransport stays on the second transport until
   the worker restarts - the digest went to **Seznam** (471 mails from 05:01 to 07:53 UTC).
3. **Seznam then refused the account** (`525` at AUTH for `notify@`) after ~470 mails in 3 h - its sending limit.
   Both transports failed within the 60 s dead period → "No transports found" (no real error in the report), 25
   deferrals → the breaker stopped the run.

**What changed.**

- **App → SES API (`ses+https`).** No connection to keep alive, so nothing to time out; each send is one HTTPS
  request answered with the SES message id. Raw MIME, so every header stays (List-Unsubscribe, Message-ID, the
  `X-SES-*` ones). `ses+api` (fields instead of raw MIME) would drop custom headers; `ses+smtp` adds nothing for
  reliability over plain `smtp://` (no `ping_threshold` default either). AsyncAws retries throttling / 5xx / dropped
  connections only on a client it creates itself, and Symfony's factory hands it the framework client - so
  `mailer.transport_factory.amazon` is redefined in `config/services.php` with `ses.http_client`
  (RetryableHttpClient + AwsRetryStrategy, 2 retries, 10 s timeout, 20 s per request).
- **`MailStreamRouting` decides, not `failover()`:**
  - SES first for **every message** (never sticky);
  - a failed attempt is **tried once more** at once (a fresh connection over SMTP - AWS asks senders to retry: the
    endpoint's machines are replaced behind its load balancer);
  - SES failing twice in a row → **paused 60 s** (`SES_PAUSE_SECONDS`, one warning → Sentry) so an outage costs one
    wait, not one per e-mail; then SES is first again;
  - **transactional** then falls back to Seznam (`MAILER_TRANSACTIONAL_DSN`) - a sign-in link must arrive;
  - **notifications never fall back**: they fail and are tried later (Messenger's `async` retry 30 s → 30 min; the
    digest's own deferral 10 / 30 min + breaker). Bulk mail must never hit Seznam's limit again - that is what took
    the transactional fallback down with it;
  - no retry for a recipient rejection (SMTP 550-553) or an API refusal (HTTP 4xx but 429); a refusal still lets
    transactional mail fall back, neither pauses SES;
  - both failing = one exception naming both causes, never "No transports found".
- **Listmonk:** SES server `idle_timeout` 15 s → **5 s** (below SES's 10 s), `max_msg_retries` 3 kept. Written in its
  `settings` row + restart (no campaign running); backup `/root/listmonk-smtp-settings-backup-2026-10-19.json`.
- **Preview:** `myspeedpuzzling:send-content-digest-preview <email> [--player=] [--period=] [--kind=]` sends a
  player's real digest through the same path (content-digest README "Sending").

## Rollout (one step at a time, each reversible by an env change)

Steps 1-2 on AWS are scripted: [`aws-setup.sh`](aws-setup.sh) `identities` (prints the Cloudflare records), `streams`
(prints `SES_EVENTS_TOPIC_ARNS`), `subscribe` (after the step-3 deploy), `show`.

| Step | Who | What | Back out |
|---|---|---|---|
| 0 | Jan | AWS account (new "projects" sign-up with Google, done 2026-10-13), Paid plan with a generous spend limit, no SES "Essentials" plan (à la carte $0.10/1k). CLI access via `aws login` (short-term credentials, no access keys) | - |
| 1 | Jan + Claude | Domain identities `mail.`, `notify.`, `news.myspeedpuzzling.com`: Easy DKIM + custom MAIL FROM (DNS in Cloudflare). Verify Jan's own addresses. SMTP credentials (app, Listmonk) → Infisical | delete the records |
| 2 | Claude (access key with SES + SNS rights) | Configuration sets `transactional`, `notifications` (open + click tracking), `newsletter`; event destinations → SNS topics; subscriptions to `https://myspeedpuzzling.com/-/ses-events`; account-level suppression list for **BOUNCE only** - never COMPLAINT, or a complaint about a digest would also block sign-in links | - |
| 3 | deploy | `MAILER_SES_DSN` + `SES_TEST_RECIPIENTS` (Jan's addresses) + `SES_EVENTS_TOPIC_ARNS`. Sign in by link, digests, the SES mailbox simulator (`bounce@` / `complaint@simulator.amazonses.com`, added to the test list) → check audit rows, suppression, banner | empty the two vars |
| 4 | Jan | Request production access (≥ 50k/day, ≥ 20/s; describe the streams, consent at registration, one-click unsubscribe, bounce/complaint handling = this doc) | - |
| 5 | deploy | Transactional: `MAILER_TRANSACTIONAL_DSN` = failover(SES, Seznam). Watch DMARC reports + audit rows for a day | old DSN |
| 6 | deploy | Notifications: `MAILER_NOTIFICATIONS_DSN` = SES | old DSN |
| 7 | Listmonk | SMTP server → SES (+ `X-SES-CONFIGURATION-SET: newsletter` header), SES bounce webhook on, POP scanner off, keep the rate under the SES per-second quota (Listmonk never retries a 454) | old SMTP server |
| 8 | cleanup | `SES_TEST_RECIPIENTS` / `MAILER_SES_DSN` emptied | - |
| 9 | 2026-10-19 | Reliability: `MAILER_SES_DSN` = SES API, stream DSNs = Seznam only, Listmonk `idle_timeout` 5 s | `MAILER_SES_DSN` = `MAILER_SES_SMTP_DSN` (SES over SMTP) or `null://null` + the old `failover(...)` DSNs |

## Open (docs/TODO.md)

- Metrics per stream (sent / delivered / bounced / complained per day) and an alert on the complaint rate.
- Un-suppress an address: admin action + SES suppression list removal ("my mailbox works again").
- SES tenants per stream (reputation isolation, `X-SES-TENANT`) - only after the configuration sets work.
- List AWS (SES) as a sub-processor in the privacy policy before step 5.
