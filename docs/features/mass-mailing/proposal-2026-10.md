# Moving all e-mail off Seznam: proposal (2026-10-12)

Follows `research-2026-10.md` (volumes, forecast, why Seznam cannot stay). Scope agreed with Jan: move **all three
streams** - transactional, notifications/digests, newsletter. Listmonk stays as the campaign planner; only its
sender changes. Wanted as a bonus: reads, bounces and anything else we cannot see today. Must not be expensive.

## Decision

**Amazon SES, region eu-central-1 (Frankfurt), à la carte pricing ($0.10 / 1,000).** All three streams, over
**SMTP** (app keeps Symfony's SMTP transport, Listmonk keeps its SMTP server), each stream in its own
configuration set + tenant. Events (delivery, bounce, complaint, open, click, delay, reject) come back through SNS:
Listmonk consumes them natively, the app through its own small SNS parser into `email_audit_log` + suppression.
Transactional mail fails over to Seznam `robot@` (Symfony `failover()` transport) - free insurance while SES has
no support plan. **Lettermint is the fallback** if AWS refuses production access or grants too little.

Cost: **~$3-7/month now, ~$25/month at 250k/month (2029 high case)**. Lettermint would be €40-175.

Why SES won over Lettermint (first draft of this proposal picked Lettermint):
1. **Lettermint's broadcast route forces its own unsubscribe footer and `List-Unsubscribe` header**; removing them
   needs support's approval as an exception ([KB](https://lettermint.co/knowledge-base/product/why-automatic-unsubscribe-link-broadcast-emails)).
   Our newsletter (Listmonk) and digests already carry signed RFC 8058 headers - two unsubscribe systems would
   disagree about who unsubscribed from what.
2. Price: 6-10× cheaper at every volume in the forecast.
3. Maturity: SES carries a large part of the world's e-mail; Lettermint is ~20 months old, two founders, had a
   platform-wide outage on 23 Sep 2026 and an Outlook block of a shared broadcast IP on 12 Aug 2026.
4. SES's gaps (no message log, no support, SNS plumbing) are ones we already cover: `email_audit_log` is our
   message log, the failover covers transactional, and the webhook parser is small work.

## 1. Does Listmonk stay? Yes.

Listmonk is the campaign planner (lists, templates, per-locale campaigns, scheduling, subscriber preferences,
analytics) and the app is its source of truth through the 15-min sync. It needs only a new SMTP server. Verified
in the v6.2.0 source and on our production settings:

| Need | Listmonk v6.2.0 |
|---|---|
| Send through SES | SMTP server `email-smtp.eu-central-1.amazonaws.com:587`; per-server `email_headers` → `X-SES-CONFIGURATION-SET: newsletter` (+ tenant header) |
| Bounces/complaints back | **Built-in** `POST /webhooks/service/ses` (SNS signature verified, subscription confirmed automatically). Setting `bounce.ses_enabled` exists on prod, off. Use the **identity's feedback notifications** (Bounce + Complaint, "include original headers") - Listmonk's SES handler drops config-set `Complaint` events |
| Opens | Already tracked (`{{ TrackView }}`, individual tracking): #3 EN 3,523 unique opens of 7,159 (49 %, inflated by Apple Mail Privacy Protection) |
| Clicks | **Never used** - 0 clicks on all 18 campaigns, our template's links are not `@TrackLink`. Turn on in the template |
| One-click unsubscribe | Built in (`List-Unsubscribe` + `List-Unsubscribe-Post`), on. SES leaves our headers alone |
| Speed | concurrency × message_rate; must stay **under the SES per-second quota** |

**Listmonk gotchas:**
- It **never retries an SMTP 4xx** - SES answers over-quota sends with `454 Throttling failure` and that recipient
  silently misses the campaign. Keep Listmonk's rate below the SES per-second quota minus room for the app
  (e.g. quota 14/s → Listmonk concurrency 2 × rate 4 = 8/s → 45k in ~1.5 h).
- Bounce action stays **blocklist**, never delete (our sync would recreate deleted subscribers).
- One tracker per link: no SES open/click tracking on the newsletter configuration set; Listmonk tracks.
- The POP bounce scanner (Seznam mailbox) is switched off with the move.

## 2. SES vs Lettermint, feature by feature

| | Amazon SES (Frankfurt) | Lettermint |
|---|---|---|
| Company / data | US company, EU region. Message content only in transit; suppression list + identities stored; events only where we export them | NL, EU only, own IPs (AS213427). Keeps content, recipients and events 28 days |
| Price 25k / 70k / 150k / 250k per month | $2.50 / $7 / $15 / $25 (à la carte; new accounts default to "Essentials" $0.16/1k - cancel it in the console, immediate) | €40 / €70 / €118 / €175 (Starter) |
| SMTP + API | Both | Both |
| Stream isolation | Configuration sets + **tenants** (per-stream reputation and per-stream pause, $0.005/tenant/month + $0.005/1k) | Routes; transactional on separate infrastructure from broadcast |
| Suppression | Account list (hard bounces + complaints), override per config set/tenant, full API | Route/project/team scopes, API; removing a complaint needs support |
| Events | send, delivery, bounce, complaint, reject, **delivery delay**, subscription, open, click, rendering failure → SNS / EventBridge / Firehose | delivered, bounces, failed, policy_rejected, suppressed, complaint, unsubscribed, open, click → webhooks |
| Webhook security / retries | SNS certificate signature; 3 retries by default, configurable up to 100 within 1 h + dead-letter queue | HMAC; 12 attempts over ~14 h |
| Open/click tracking | Per configuration set; custom HTTPS tracking domain needs our own certificate/CDN (Cloudflare can do it) | Per route, override per message; custom tracking domain Pro only |
| Unsubscribe | Leaves our own `List-Unsubscribe` untouched (its own contact lists are optional) | **Forces its footer + header on broadcast routes** |
| Deliverability insight | Virtual Deliverability Manager dashboard ($0.07/1k, or included in Essentials) | Analytics per provider/domain; Spam Insights Pro only |
| Message log | **None** - ours is `email_audit_log` + events | Searchable, 28 days |
| Scheduling / idempotency | No / no | Yes (Growth+) / yes |
| Rate limit | Per-second quota; `454` on excess | No fixed limit |
| Support | None technical on Basic (Business Support from ~$29/month) | Tickets, e-mail, Discord |
| Onboarding | Sandbox → production access request (below) | Compliance form |
| Gmail complaints | None per message (Gmail gives them to nobody) - Google Postmaster Tools | Same |
| Listmonk | Native SES bounce webhook | Native Lettermint preset + webhook |
| Symfony | Mailer bridge `symfony/amazon-mailer`; **no webhook parser** (own SNS parser, Scaleway 8.2's is a template) | Community bridge with parser |

## 3. Getting SES approved (the 200/day sandbox)

- **Sandbox** (every new account, per region): 200 messages / 24 h, 1/s, only to verified addresses. **Everything
  else works in the sandbox** - domains, DKIM, MAIL FROM, configuration sets, tenants, SNS, Listmonk, app
  webhooks - so the whole setup is built and tested before approval.
- **Request** (SES console → Get set up → Request production access): mail type (marketing or transactional,
  "whichever applies to the majority"), website URL, contacts; AWS Trust & Safety usually follows up with
  questions, and the answer decides it. Officially **first response within 24 h**; real reports range from a day
  to 4+ days, and some new accounts were denied without a reason or for "no billing history" (anecdotal, re:Post).
- **How to pass the first time:** apply only after domains are verified with DKIM + custom MAIL FROM and the bounce
  /complaint pipeline is live; describe all three streams honestly (volumes, opt-in, one-click unsubscribe, our
  suppression handling, current bounce rate 0.3-0.6 %, complaint handling); ask for **≥ 50,000/day and ≥ 20/s**.
  Pick the mail type that is honestly the majority (with the digest as built: newsletter + digests ≈ 80 % → marketing).
- **Quota after approval** "varies by use case"; anecdotally 50k/day at 14/s, raised automatically when we send near
  it cleanly, or on request in ~24 h.
- **Plan B:** if denied or capped far below 50k/day, Lettermint Starter - same SMTP design, different headers.
- Also: AWS's new sign-up flow can set a monthly spend cap that **pauses** the account when hit - set it far above
  the expected ~$10.

## 4. US vs EU - is it a concern?

Not a deciding one for us.
- **Legal basis:** AWS's DPA with the EU standard contractual clauses applies automatically, and Amazon is
  certified under the **EU-US Data Privacy Framework**. The DPF is in force: the General Court upheld it
  (Latombe, 3 Sep 2025); the appeal (C-703/25 P) is pending; noyb is preparing a challenge after *Trump v.
  Slaughter* (June 2026). If the DPF falls, the SCCs remain.
- **What SES holds:** recipient addresses (suppression list), our identities; message content only in transit;
  events only where we send them (our own database). Lettermint keeps more (28 days of content).
- **CLOUD Act:** applies to AWS whatever the region - theoretical for e-mail addresses and puzzle notifications.
- **AWS European Sovereign Cloud** (GA 15 Jan 2026, Brandenburg) listed SES among planned services - an option if
  this ever matters; separate setup.
- **To do:** list AWS (SES) as a sub-processor in the privacy policy, write a one-paragraph transfer note, keep the
  provider swappable (SMTP + DNS - it is).

## 5. Target architecture

```
App (Symfony Mailer, SMTP transports, unchanged code paths)
  transactional  failover(SES config set "transactional", Seznam robot@)   no tracking
  notifications  SES config set "notifications"                           opens + clicks
     every message: X-SES-CONFIGURATION-SET, X-SES-TENANT, X-SES-MESSAGE-TAGS: audit_id=<uuid>, email_type=<type>
Listmonk         SES config set "newsletter"                               no SES tracking (Listmonk tracks)

SES identities: mail. / notify. / news.myspeedpuzzling.com - Easy DKIM 2048, custom MAIL FROM (SPF aligned)
Events:
  config sets "transactional" + "notifications"  → SNS topic → app  POST /webhook/ses  (own SNS parser)
  identity news. feedback notifications          → SNS topic → listmonk /webhooks/service/ses
                                                             → app (same topic, to apply the newsletter rules)
```

Message tags (`audit_id`, `email_type`) come back in every SES event (`mail.tags`), so events match their
`email_audit_log` row without a lookup.

## 6. What we gain (none of this exists today)

| Signal | Use |
|---|---|
| **Delivered** per message, **delivery delays** | `email_audit_log.delivered_at`; "did the sign-in mail arrive?" answered in admin |
| **Hard / soft bounces** for app mail | Suppression + banner (rules below) |
| **Spam complaints** (Microsoft, Yahoo and others' FBLs) | Rules below; alert above 0.1 % |
| **Opens + clicks on digests** | Engagement per player; measure the "We miss you" 10 % holdout properly |
| **Newsletter clicks** | Listmonk `@TrackLink` |
| Rejects, rendering failures | Per message, with the reason |
| Deliverability per mailbox provider | VDM dashboard if we want it ($0.07/1k) |
| Gmail (63 % of recipients) | No per-message complaints from Gmail to anyone - **Google Postmaster Tools** (free) for the root and the three subdomains |

Opens are a soft signal (Apple Mail Privacy Protection); clicks and complaints are the reliable ones.

## 7. Rules for complaints and bounces (Jan, 2026-10-12)

| Event | On | Action |
|---|---|---|
| Complaint | newsletter | Newsletter off (`player.newsletterEnabled` / guest unsubscribed) - the app is the source of truth, so the sync never re-enables it |
| Complaint | activity digest / "We miss you" | `contentDigestFrequency = off` |
| Complaint | unread-messages digest | `emailNotificationsEnabled = false` |
| Complaint | "Your results" | `resultEmailsEnabled = false` |
| Complaint | transactional | Recorded only |
| Hard bounce | any | Address suppressed for everything but transactional; **banner on the site**: "We were unable to deliver e-mails to {email}" + the reason when SES gives one (bounce subtype / diagnostic code), with a link to change the address. A changed (and verified) address clears it |
| Soft bounce | any | Recorded; repeated soft bounces left to SES |

## 8. App changes

1. **Env:** `MAILER_TRANSACTIONAL_DSN=failover(ses+smtp://…@default?region=eu-central-1 smtp://…seznam…)`,
   `MAILER_NOTIFICATIONS_DSN=ses+smtp://…` (or plain `smtp://email-smtp.eu-central-1.amazonaws.com:587`).
2. **Headers** in `EmailAuditSubscriber::onMessage`: configuration set + tenant from `X-Transport`,
   `X-SES-MESSAGE-TAGS` with `audit_id` and `email_type`.
3. **Webhook:** `symfony/webhook` + `symfony/remote-event`; own `SesSnsRequestParser` (SNS signature check against
   the `sns.eu-central-1.amazonaws.com` certificate, subscription confirmation, `Notification` → event) →
   `#[AsRemoteEventConsumer]` → message on `async`.
4. **`email_audit_log`:** `delivered_at`, `opened_at`, `clicked_at`, `complained_at` (bounce columns exist).
5. **`email_suppression`** (email, reason, source, since) checked by every non-transactional sender; covers guests.
6. **Rules of §7** in the event handler; banner for hard bounces.
7. **Messenger:** SES `454` → `TransportException` → `async` retries (30 s, 2 m, 8 m, 30 m) - fine for app mail.
8. **Metrics:** sent/delivered/bounced/complained per stream per day to Prometheus; alert on complaint rate and on a
   paused Listmonk campaign (not by e-mail).

## 9. Before approval: no "first 100 a day via SES" mode

Idea (Jan): send the first ~100 transactional e-mails a day through SES while in the sandbox, the rest through
Seznam. **The sandbox cannot do it**: besides 200/day and 1/s it delivers **only to verified recipient addresses
or domains** (and the mailbox simulator) - a sign-in link to a player would be refused. After approval the quota
(tens of thousands a day) makes a split pointless. What the sandbox *is* good for: building and testing the whole
pipeline with our own verified addresses (Jan's mailboxes, the canary `newsletter@news`), so production starts the
day access is granted.

### AWS setup checklist (Jan)

1. Create the AWS account (company details, card); enable MFA on root, create an admin IAM user / Identity Center
   user, never use root afterwards.
2. Billing: AWS Budgets alert (e.g. $20/month); if the new sign-up flow asks for a spend cap, set it far above
   expected (~$100) - hitting it pauses sending, sign-in links included.
3. SES console, region **Europe (Frankfurt) eu-central-1**: Pricing plan → **Cancel plan** (Essentials → à la
   carte $0.10/1k).
4. Identities: `mail.`, `notify.`, `news.myspeedpuzzling.com` (domain identities) - Easy DKIM 2048 (3 CNAMEs each)
   + custom MAIL FROM (e.g. `bounce.mail.myspeedpuzzling.com`: MX + SPF TXT). DNS in Cloudflare (I can add the
   records via the Cloudflare API from the box token once the values exist).
5. Verify Jan's own addresses as sandbox recipients for testing.
6. SMTP credentials (SES console → SMTP settings → create; one IAM user per consumer: app, Listmonk) → Infisical.
7. Configuration sets `transactional`, `notifications`, `newsletter` (+ tenants) and SNS topics - I can script
   this with the AWS CLI once there is an access key with SES/SNS rights.
8. Request production access when 4-7 are done and the webhook pipeline is live.

## 10. Rollout

| Step | What |
|---|---|
| 0 | AWS account; cancel Essentials → à la carte; spend cap far above expected. Identities with DKIM + MAIL FROM (Cloudflare DNS), configuration sets, tenants, SNS topics. Google Postmaster Tools. Let newsletter #3 finish on Seznam |
| 1 | Webhook pipeline live in the sandbox (SES mailbox simulator `bounce@` / `complaint@simulator.amazonses.com`) |
| 2 | **Request production access** (≥ 50k/day, ≥ 20/s) |
| 3 | Transactional with failover to Seznam; watch DMARC reports (Sendvery) for alignment |
| 4 | Notifications (the activity digest of Mon 19 Oct, ~1.9k over 12 h, is safe on Seznam if SES is not approved yet) |
| 5 | Listmonk → SES, SES bounce webhook on, POP scanner off, `@TrackLink` in the template, test campaign |
| 6 | Newsletter #4 (Nov) at Listmonk's rate under the SES quota |

## 11. Consent (done 2026-10-12)

Registration had no newsletter question, while privacy policy §10 names **consent** as the newsletter's legal
basis. Now both sign-up paths (e-mail form, social sign-up confirmation incl. Apple Hide My Email) carry an
**unticked** box "Keep me in the loop: one e-mail a month about what's new on MySpeedPuzzling. Strictly feature
updates – no marketing, no promotions. Unsubscribe anytime." (6 locales), and a **required** box "I agree to the
Terms of service and the Privacy policy" (links open in a new tab; checked by the form validator and, on the social
path, by the controller before the parked sign-up is used up). Unticked because a pre-ticked box is not consent (GDPR recital 32,
CJEU C-673/17 *Planet49*). Both answers are recorded in the registration's `auth_audit_log.metadata`
(`{"terms": true, "newsletter": true|false}`) - the consent record ESPs ask for. Note that `auth_audit_log` is pruned after 24
months; a longer-lived record would need its own column.

**Existing players (Jan's call, 2026-10-12):** the ~11k players who registered before this change and have not
unsubscribed are treated as having opted in at registration - they signed up through the earlier Auth0 flow, a
different registration page. They keep getting the newsletter, nobody is re-asked, and the consent basis in privacy
policy §10 stays as it is. Every unsubscribe stays honoured.

## Sources

SES: [pricing](https://aws.amazon.com/ses/pricing/) · [pricing plans](https://docs.aws.amazon.com/ses/latest/dg/pricing-plans.html) ·
[production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html) · [access FAQ](https://repost.aws/knowledge-center/ses-production-access-request-faq) ·
[quotas](https://docs.aws.amazon.com/ses/latest/dg/quotas.html) · [tenants](https://docs.aws.amazon.com/ses/latest/dg/tenants.html) ·
[event publishing](https://docs.aws.amazon.com/ses/latest/dg/monitor-using-event-publishing.html) · [suppression](https://docs.aws.amazon.com/ses/latest/dg/sending-email-suppression-list.html) ·
[tracking domains](https://docs.aws.amazon.com/ses/latest/dg/configure-custom-open-click-domains.html) · [throttling errors](https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas-errors.html) ·
[enforcement](https://docs.aws.amazon.com/ses/latest/dg/faqs-enforcement.html) · [Gmail complaints](https://aws.amazon.com/blogs/messaging-and-targeting/understanding-google-postmaster-tools-spam-complaints-for-amazon-ses-email-senders/) ·
[EU data protection](https://aws.amazon.com/compliance/eu-data-protection/) · [personal data in SES](https://docs.aws.amazon.com/ses/latest/dg/deleting-personal-data.html).
DPF: [CJEU appeal](https://www.dataguidance.com/news/eu-cjeu-admits-microsoft-intervener-appeal-concerning) · [noyb / Slaughter](https://www.activemind.legal/guides/dpf-supreme-court/) ·
[Sovereign Cloud GA](https://www.datacenterdynamics.com/en/news/aws-european-sovereign-cloud-now-generally-available/).
Lettermint: [pricing](https://lettermint.co/pricing.md) · [routes](https://lettermint.co/docs/platform/projects-and-routes/routes.md) ·
[SMTP](https://lettermint.co/docs/guides/send-email-with-smtp) · [forced unsubscribe](https://lettermint.co/knowledge-base/product/why-automatic-unsubscribe-link-broadcast-emails) ·
[acceptable use](https://lettermint.co/acceptable-use) · [status history](https://status.lettermint.co/history) · [terms](https://lettermint.co/terms-of-service.md).
Others (ruled out earlier): [Postmark streams](https://postmarkapp.com/message-streams) · [Scaleway FAQ](https://www.scaleway.com/en/docs/transactional-email/faq/) ·
[MailerSend limits](https://developers.mailersend.com/general.html). Listmonk verified in `knadh/listmonk` at tag v6.2.0
(`cmd/bounce.go`, `internal/bounce/webhooks/ses.go`, `internal/manager/manager.go`, `knadh/smtppool` `pool.go`).
