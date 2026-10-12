# Mass mailing: how we send today, volumes, 3-year forecast (research 2026-10-12)

Research only - nothing changed. Scope: every e-mail MySpeedPuzzling sends (transactional, notifications,
digests, newsletter), the infrastructure behind it, measured volumes, and a forecast for the next 3 years
with **1 newsletter per month to all users + 4 digests per month to all users**.

## TL;DR

- **Everything goes through Seznam Email Profi** (`smtp.seznam.cz:587`), three mailboxes:
  `robot@mail.` (transactional), `notify@notify.` (notifications, digests, "Your results"), `newsletter@news.`
  (Listmonk). Nothing sends from our own IP.
- **Today's app mail is small**: ~3,200 e-mails / 30 days (~100/day, peaks 560/day), zero failures.
- **The newsletter is already over Seznam's limit.** 11,275 recipients; each of #1, #2, #3 was refused by
  Seznam (`450 4.7.0 sorry, sending rate limit exceeded`) and finished on a later day. #3 (today) was refused
  after **3,594 messages in 3 h 51 min**, resumed, refused again at 8,394, and is still paused.
- **Seznam's terms forbid it anyway**: Email Profi terms clause 3.1 - the address may not be used "k rozesílání
  hromadných obchodních sdělení" (for mass commercial messages). We are on borrowed time with the newsletter on
  Seznam, and its shared IPs already got us blocked by Apple once (July 2026, all three mailboxes at once).
- **The requested scenario (newsletter + 4 digests, all users) = ~70k e-mails/month today, ~140k-250k/month
  in 3 years**, with single-day waves of 12k now and 25k-45k by 2029. Seznam cannot carry a single one of those
  waves in one day.
- **As the digest is actually built** (only players who solved something that week) it is ~1,900/week today
  → ~4,200/week in 2029 (base). That fits Seznam's observed limits today, but not with headroom, and it shares
  `notify@` with the "Your results" e-mails.
- **Recommendation**: move **bulk** (newsletter, digests, "We miss you") to a real bulk/ESP provider on its own
  sending domain (`news.`), keep **transactional** separate. At our volumes every serious option costs
  **~$10-$120/month** (Amazon SES cheapest, Scaleway TEM the EU option, Postmark/Mailgun/Brevo in between).
  Listmonk stays as the campaign tool - it only needs a different SMTP server.

## 1. How we send today

```
                 ┌───────────────────────── Symfony app (web/api/consumers) ─────────────────────────┐
user action ───► │ handler → Mailer::send() → SendEmailMessage on `async` (Doctrine queue)            │
cron ──────────► │   X-Transport: transactional ──► MAILER_TRANSACTIONAL_DSN  robot@mail.  ─┐         │
                 │   X-Transport: notifications ──► MAILER_NOTIFICATIONS_DSN notify@notify. ─┤         │
                 │ content digest: own queue `content_digest_emails` + `digest-consumer`      │         │
                 │   (paced 07:00-19:00 Prague, circuit breaker) ─────────► notify@notify.  ─┤         │
                 └────────────────────────────────────────────────────────────────────────────┼─────────┘
Listmonk (own DB, campaigns by hand, sliding window 150/10 min) ──► newsletter@news.          ─┤
                                                                                                ▼
                                                              smtp.seznam.cz:587 (Email Profi, shared IPs)
```

- Transport choice per message: `X-Transport` header, `config/packages/mailer.php` (two named transports).
- Queue: Messenger Doctrine transport `async` carries **all** Mailer e-mails together with XP/badge recalculation
  messages (the badges safety net queues ~7,600 messages a day on the same queue). Retries 30 s / 2 m / 8 m / 30 m.
  The content digest has its own queue + consumer (`digest-consumer`, added 2026-10-12).
- Audit: `EmailAuditSubscriber` → `email_audit_log` (90 days, 30 for digests), admin `/admin/email-audit`.
  The `bounce_*` columns exist but nothing fills them - **no bounce handling for app mail** (VERP impossible:
  Seznam requires the envelope sender to equal the login). Listmonk has its own bounce processing (POP mailbox).
- Volume controls: per-address/IP rate limiters on user-triggered mails; "Your results" paced 1/min, 1,000/day
  (env); content digest paced over 12 h with a circuit breaker; unread digest hard-coded 10 per run.
  **Nothing caps total volume per mailbox per day.**
- Monitoring: failed-queue age alerts and cron-failure alerts only. **No metric for sent/day per mailbox,
  quota use or bounce rate.** Listmonk's "campaign paused" alert goes through the same blocked mailbox, so it
  never arrives.
- Infra (`~/www/lily.srv/apps/myspeedpuzzling/`): `messenger-consumer` (async), `digest-consumer`
  (content_digest_emails), `listmonk` v6.2.0 + `listmonk-db`. DSNs/passwords from Infisical; Listmonk's SMTP
  settings live in its own DB. Hetzner opened outbound port 25 for lily on 2026-09-03 (unused).
- Other lily apps also use Seznam, but with their own mailboxes (limits are per mailbox, verified during the
  #1 block: robot@ and notify@ kept working).

## 2. Every e-mail we send, measured (production, 13 Sep - 12 Oct 2026)

| E-mail | Trigger | Mailbox | 30 days | Opt-out |
|---|---|---|---:|---|
| Sign-in link + code | user | robot@ | 563 | - |
| Subscription renewed | Stripe | robot@ | 555 | - |
| "Your results" (duplicates / verification) | cron, 1/min | notify@ | 544 | `resultEmailsEnabled` |
| Verify e-mail | registration | robot@ | 502 | - |
| Feature request completed (to upvoters) | admin, **unpaced burst** | robot@ | 219 | - |
| Free trial started / ending | event / daily cron | robot@ | 179 + 116 | - |
| Social login linked | user | robot@ | 172 | - |
| Password reset | user | robot@ | 117 | - |
| Unread-messages digest | cron */5 | notify@ | 60 | `emailNotificationsEnabled` + frequency |
| Membership, payment failed, cancelled | Stripe | robot@ | ~85 | - |
| Competitions, organizations, OAuth2, feedback, newsletter opt-in, account deletion | various | robot@ | ~130 | - |
| **Total app mail** | | | **3,224** (~107/day) | |
| Activity digest ("Your week") | Mon 07:01, paced to 19:00 | notify@ | **starts Mon 19 Oct** | `contentDigestFrequency` (weekly default) |
| "We miss you" | Tue 07:01 | notify@ | starts 27 Oct (waves ~1,100, ~1,650, then ~175/week) | `contentDigestFrequency` |
| Newsletter | Listmonk, by hand | newsletter@ | ~11,100 per issue | `newsletterEnabled` |

Daily app volume: 50-150 normally; peak 563 on 2 Oct (result-review backlog); peak hour 105. Zero failed sends
in the last 30 days.

## 3. Seznam: what we actually know

**Limits (not documented by Seznam for outbound Email Profi; the figures are ours):**

| Send | Pace | Refused after | Lifted |
|---|---|---|---|
| Newsletter #1, 2026-07-30 | ~600/h | ~8,900 in < 24 h | overnight (~02:00) |
| Newsletter #2, 2026-09-15 | ~520/h | ~6,400 in 12 h 20 min | next morning |
| Newsletter #3, 2026-10-12 | ~930/h | **3,594 in 3 h 51 min** | after ~53 min |
| ... resumed | ~910/h | 8,394 cumulative (10 h) | still refused 9 h later |

So the rule is **not** a plain "3,600 per clock hour" (we never sent more than ~1,000 in an hour) - it behaves
like a counter over a window of a few hours (~3,600) plus a daily one (~8,400-9,000), and #2 broke even that.
It is soft (`450`), per mailbox, and opaque. Seznam's published bulk limits
(o-seznam.cz "Limity pro velikost rozesílky": 100 msgs/connection, 1,500 connections and 100k msgs per IP per
5 min) are about mail **into** Seznam mailboxes and do not apply here.

**Terms:** Email Profi terms, clause 3.1 - the user "není oprávněn využít e-mailovou adresu k rozesílání
hromadných obchodních sdělení". A newsletter (and arguably a digest with membership upsell) is exactly that.
Worst case is not a throttle but a disabled account; we saw on 2026-10-02 that Seznam reports an account as
"disabled" after a burst of failed logins - suspension is a real switch they use.

**Shared reputation:** all three mailboxes leave through Seznam's shared outbound IPs (seen 77.75.76.89).
Apple blocked that IP in July (`554 5.7.1 [CS01]`) and Swisscom rate-limited it - we cannot fix someone else's
IP, and a bulk send of ours could equally hurt sign-in e-mails if Seznam acts per customer.

## 4. Audience today

| | Count |
|---|---:|
| Accounts with an e-mail | 11,660 |
| Newsletter recipients (Listmonk enabled; 84 blocklisted) | 11,275 |
| Digest opted in (`weekly`) | 11,646 (only 14 changed it) |
| Present on the site in the last 30 days | 3,665 |
| Solved something - per week (= digest recipients as built) | 1,800-1,950 |
| Solved something - per month | 2,950 (Sep 2026; Sep 2025: 2,221 → +33 %) |
| Recipient domains | Gmail 63 %, Microsoft ~11 %, Yahoo 4 %, GMX/web.de 5 %, Apple 2 %, Seznam 1 % |

Gmail at 63 % means every newsletter is > 5,000/day to Gmail → **Google's bulk-sender rules apply** (aligned
SPF + DKIM + DMARC, RFC 8058 one-click unsubscribe, spam rate < 0.3 %); Microsoft has the same rule since 2025.

**Growth** (new accounts per month): 2025: 250-550; 2026: 323 (Feb) → 596 (Sep). Last 12 months 5,003
(+13 % on the 12 before), last 6 months +56 % on the same months a year ago. Accounts 6,424 → 11,427 in a year
(+78 %), active solvers +33 %.

## 5. Forecast: Nov 2026 - Oct 2029

Assumptions: 85 % of new accounts stay mailable (bounces, unsubscribes, deletions); newsletter = 1× list per
month; "digest all users" = 4.33 × accounts per month; "digest as built" = weekly active solvers × 4.33;
other app mail grows with active users (today 3,300/month).

| Scenario | New accounts / month | Active users / year |
|---|---|---|
| Low | flat 450 | +15 % |
| Base | 500, +25 % a year | +30 % |
| High | 550, +50 % a year | +45 % |

**Monthly volume - the requested model (newsletter + 4 digests to all users):**

| Month | Low | Base | High | List size (base) |
|---|---:|---:|---:|---:|
| Dec 2026 | 69k | 70k | 71k | 12.1k |
| Oct 2027 | 90k | 97k | 104k | 17.0k |
| Oct 2028 | 115k | 137k | 162k | 24.3k |
| Oct 2029 | 140k | 186k | 250k | 33.3k |

**Monthly volume - digest as built (active solvers only):**

| Month | Low | Base | High |
|---|---:|---:|---:|
| Dec 2026 | 24k | 24k | 25k |
| Oct 2027 | 29k | 32k | 35k |
| Oct 2028 | 36k | 44k | 53k |
| Oct 2029 | 43k | 59k | 80k |

**The number that matters for a provider is the single-day wave, not the month:**

| Wave (one day, one mailbox) | Today | Oct 2027 base | Oct 2029 base | Oct 2029 high |
|---|---:|---:|---:|---:|
| Newsletter | 11.3k | 17k | 33k | 45k |
| Digest Monday, all users | 11.7k | 17k | 34k | 45k |
| Digest Monday, as built | 1.9k | 2.5k | 4.2k | 5.8k |
| Seznam observed ceiling | ~3.6k per few hours, ~8.4-9k per day | | | |

Yearly totals (base, all-users model): ~1.0M e-mails in year 1, ~1.4M in year 2, ~1.9M in year 3.

## 6. What breaks, and when

- **Now:** the newsletter (11.3k) needs 2 days on Seznam and a watcher to resume it; every issue gets refused.
- **Digest to all users:** impossible on Seznam from day one (11.7k in 12 h ≈ 1,000/h on one mailbox - the pace
  that tripped the limit today after 3.6k).
- **Digest as built:** fine today (1.9k over 12 h ≈ 160/h). Crosses ~3.6k per Monday around early 2029 (base) /
  mid-2028 (high) - and earlier when a "We miss you" wave, a result-review backlog or a feature-request burst
  lands on `notify@` the same day.
- **Transactional** (~2,500/month → ~7,000/month in 2029) stays far below any limit; its risk is reputation
  (shared Seznam IPs, Apple), not volume.

## 7. Options

| Option | How | Est. cost at 70k / 190k / 250k per month | Notes |
|---|---|---|---|
| Stay on Seznam | split sends over days, more mailboxes | 0 | Against clause 3.1; limits opaque; no bounce data for app mail; shared IPs. Not viable for bulk |
| **Amazon SES** (eu-central-1) | SMTP or `symfony/amazon-mailer`; Listmonk has SES SMTP | ~$7-11 / $19-30 / $25-40 (+$25/IP/month for a dedicated IP, not needed yet) | Cheapest; good deliverability; bounce/complaint webhooks via SNS; new accounts start in sandbox → production access request; new accounts are on the $0.16/1k "Essentials" plan since 2026-07 (check) |
| **Scaleway TEM** (France) | SMTP or `symfony/scaleway-mailer` | ~€17 / €47 / €62 (Essential €0.25/1k); Scale plan €80 incl. 100k + dedicated IP + SLA | EU company + EU data - easiest GDPR story; bills per recipient |
| Postmark | Broadcast stream (separate from transactional stream) | ~$55 (50k) / ~$115-180 / ~$200+ | Best transactional reputation; strict about bulk content quality |
| Mailgun (EU region) | SMTP/API | ~$90 (100k) / ~$190 / ~$255 | Mid-price; dedicated IP ~$60 |
| Brevo / Mailjet (EU) | SMTP relay or their campaign tool | ~$70-140 per 100k | Would duplicate Listmonk; fine as pure SMTP relay |
| Own Postfix on lily | port 25 open since 2026-09 | 0 + our time | Cold Hetzner IP, warm-up, blocklists, Microsoft/Apple filtering, abuse handling. Not recommended for bulk |

Prices are from third-party 2026 pricing summaries, not quotes - check the vendors' pages before deciding.

## 8. Recommendation

1. **Bulk to an ESP, now** - newsletter, activity digest, "We miss you" (and "Your results" when it runs in
   waves). Sender stays `news.myspeedpuzzling.com` (DKIM/SPF of the ESP added there; DMARC already via
   Sendvery). Listmonk: replace its single SMTP server; the app: point `MAILER_NOTIFICATIONS_DSN` at the ESP
   or add a third `bulk` transport for the digests. Pick **SES** (price) or **Scaleway TEM** (EU) - both are
   ~€20-50/month at the 3-year base volume of the all-users model.
2. **Transactional separate** - keep `robot@mail.` on its own stream; either stay on Seznam (volume is tiny) or,
   better for the Apple/Swisscom problem, put it on the same ESP in a separate configuration set / stream or
   on Postmark's transactional stream. Never mix it with bulk reputation.
3. **Use what an ESP gives back**: bounce + complaint webhooks → fill `email_audit_log.bounce_*`, suppress
   hard bounces in the app (today nothing does), alert on complaint rate (Gmail's 0.3 %).
4. **Warm up the new sending path**: the digest's natural ramp (active players only, ~2k/week) is a good warm-up
   before the first newsletter goes through it.
5. **Metrics**: sent/day per stream + bounce/complaint rate in Prometheus; alert on Listmonk paused campaigns
   through a channel that is not the mailbox that just got blocked.

## 9. Open questions (for Jan)

- Digest audience: "all users" (this research's model) or the shipped "only players who solved something"?
  It is a 6× difference in volume (and engagement - mail to people who did nothing is what drives complaints).
- EU-only provider required (Scaleway/Brevo/Mailjet), or is SES Frankfurt fine?
- Move transactional too, or only bulk?
- Ask Seznam support for the exact outbound rule and their view on clause 3.1 - worth one e-mail even if we leave,
  since `robot@` stays there.
