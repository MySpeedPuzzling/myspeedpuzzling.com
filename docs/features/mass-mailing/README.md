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
**Gotcha:** an env change needs a redeploy (re-run the `deploy` job: `dump_secrets` renders `/srv/myspeedpuzzling/.env`)
**and** `docker compose up -d --no-deps --force-recreate messenger-consumer digest-consumer` - the same-image redeploy
recreates only the web containers, the queue workers keep the old env.

**State (2026-10-12): built, switched off.** Every stream still goes through Seznam. Nothing changes until the env
values below are set. The order is **slow and careful** (Jan): AWS account → tests with verified addresses only →
production access → one stream at a time.

## How it works

All three streams go to SES over **SMTP** (`email-smtp.eu-north-1.amazonaws.com:587`, SES SMTP credentials). There
is no SES API client and no new package. The app keeps Symfony's SMTP transports and Listmonk keeps its SMTP server.

| Stream | Sent by | Mailer transport (env) | SES configuration set | Tracking |
|---|---|---|---|---|
| transactional (sign-in links, verification, password reset, membership…) | app | `transactional` (`MAILER_TRANSACTIONAL_DSN`) | `transactional` | none |
| notifications (unread digest, activity digest / "We miss you", "Your results") | app | `notifications` (`MAILER_NOTIFICATIONS_DSN`) | `notifications` | opens + clicks |
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
  - **test mode:** when every recipient is in `SES_TEST_RECIPIENTS` and `MAILER_SES_DSN` is set, the message goes
    through the `ses` transport. Everyone else stays on their stream's transport.
- **`EmailAuditSubscriber`** (existing) adds the SES headers when, and only when, the attempt goes to an
  `*.amazonaws.com` host. `SesMessageHeaders` sets them:
  - `X-SES-CONFIGURATION-SET: <stream>`;
  - `X-SES-MESSAGE-TAGS: audit_id=<email_audit_log id>, email_type=<template name>`.

  The tags come back in every event, so an event finds its audit row without a lookup. Inside a `failover()`
  transport the decision is made per attempt, so the Seznam attempt gets none of it. A failed SES attempt followed
  by a Seznam one leaves two audit rows (failed + sent).

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
| `MAILER_TRANSACTIONAL_DSN` | Seznam `robot@` | **The switch for transactional.** SES: `failover(smtp://<ses-user>:<ses-pass>@email-smtp.eu-north-1.amazonaws.com:587 smtp://<seznam robot@>)` |
| `MAILER_NOTIFICATIONS_DSN` | Seznam `notify@` | **The switch for notifications** (`smtp://<ses-user>:<ses-pass>@email-smtp.eu-north-1.amazonaws.com:587`) |
| `MAILER_SES_DSN` | `null://null` | SES for test mode only |
| `SES_TEST_RECIPIENTS` | empty | comma-separated, verified in SES; empty = no test mode |
| `SES_EVENTS_TOPIC_ARNS` | empty | comma-separated SNS topic ARNs allowed to post events; empty = endpoint off |

SMTP passwords must be URL-encoded in a DSN, because SES SMTP passwords contain `/` and `+`.

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

## Open (docs/TODO.md)

- Metrics per stream (sent / delivered / bounced / complained per day) and an alert on the complaint rate.
- Un-suppress an address: admin action + SES suppression list removal ("my mailbox works again").
- SES tenants per stream (reputation isolation, `X-SES-TENANT`) - only after the configuration sets work.
- List AWS (SES) as a sub-processor in the privacy policy before step 5.
