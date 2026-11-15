---
paths:
  - "src/**/*Mail*"
  - "src/**/*Email*"
  - "src/**/*Ses*"
  - "src/**/*Sns*"
  - "src/**/*Newsletter*"
  - "src/**/*Listmonk*"
  - "src/**/*Digest*"
  - "src/**/*Unsubscribe*"
  - "src/**/*Suppression*"
  - "src/**/*Bounce*"
  - "src/**/*Turnstile*"
  - "src/Services/Mailer/**"
  - "src/Services/Email/**"
  - "src/Services/Listmonk/**"
  - "src/Services/Digest/**"
  - "templates/emails/**"
  - "templates/newsletter/**"
  - "templates/digest_emails/**"
  - "config/packages/mailer.php"
  - "docs/features/mass-mailing/**"
  - "docs/features/newsletter/**"
  - "docs/features/transactional-emails.md"
  - "templates/**/*email*"
  - "assets/**/*email*"
  - "templates/**/*newsletter*"
  - "templates/**/*digest*"
  - "templates/**/*mail*"
  - "assets/**/*mail*"
---

# E-mail and newsletter

Loaded automatically when you read or edit files of this area (SES sending, bounces and complaints, the newsletter (Listmonk) and transactional e-mail templates). Read on purpose when planning work here before touching code. Each entry is the as-built summary; its doc under `docs/features/` is the design of record - read it before changing behaviour, and update both when behaviour changes.

- **Mass mailing on Amazon SES**: `docs/features/mass-mailing/README.md` (+ research/proposal) — every stream on SES since 2026-10-14. **The app sends over the SES API** (`MAILER_DSN` = `ses+https://…`, `symfony/amazon-mailer`; `mailer.transport_factory.amazon` redefined with `ses.http_client` = AsyncAws retries + bounded timeouts - Symfony's factory would hand AsyncAws the unretried framework client), Listmonk over SMTP (SES pool `idle_timeout` 5 s - SES closes idle SMTP connections after 10 s). **`MailStreamRouting`** decorates `mailer.transports`: **one transport `MAILER_DSN` for every stream, SES only since 2026-10-19** (no Seznam behind anything - Seznam blocked `notify@` for the digest), one retry, after two failures paused 60 s, then Messenger's retry sends it again - never put `failover(...)` in a DSN again (sticky + "No transports found": the 2026-10-19 digest incident, README "Reliability"). It also marks the stream and drops notifications to a hard-bounced address (transactional always tried); `EmailAuditSubscriber` adds `X-SES-CONFIGURATION-SET` (= stream) + tags `audit_id`/`email_type` only on an SES attempt (`SesMessageHeaders::isSes()`). Events: SNS → `POST /-/ses-events` (`SnsMessageVerifier`: signature, SNS-only certificate URL, topic in `SES_EVENTS_TOPIC_ARNS` - empty = 404) → `ProcessSesEvent` async → Jan's rules (complaint switches off only what was complained about; hard bounce → `email_suppression` + banner on every page via the profile row; a transient "Unable to lookup DNS" bounce a second time ≥ 24 h later = `source domain_not_found`, same rules; `source manual` = blocked by hand through the internal API, drops every stream)
- **Newsletter (Listmonk)**: `docs/features/newsletter/README.md` — MySpeedPuzzling is the source of truth, Listmonk mirrors it. 6 per-locale lists. Guests subscribe via footer form (double opt-in, `NewsletterSubscriber` entity) in two steps - the footer form sends nothing, the modal step mails after honeypot + 3 s ticket + Turnstile (edge widget keys `TURNSTILE_*`, empty = off) + 2 per address a day; the e-mail link only shows a "Yes, subscribe me" button (scanners) - README "Bots"; players via `player.newsletterEnabled`, recipients only once their account e-mail is verified (`GetNewsletterRecipients`; `VerifyEmailHandler` pushes them). **Cron `*/15 * * * * myspeedpuzzling:sync-newsletter-subscribers`** reconciles both ways: pulls Listmonk unsubscribes (one-click header) into MSP, pushes creates/updates/unsubscribes, and **removes deleted players from Listmonk** (`DeletePlayerHandler` also queues immediate removal). The cron never re-confirms a Listmonk unsubscribe — only explicit user actions do. Campaign template versioned at `docs/features/newsletter/listmonk-campaign-template.html`
- **Transactional e-mails**: `docs/features/transactional-emails.md` — Inky → `inline_css` → `email_document` (doctype, `lang` on `<html>` + body wrapper, subject as `<title>`, `role="presentation"`, `bgcolor` on coloured cells; locale/subject read from the rendered message). Slim `_header` (one link, `email-logo.png` with a white halo for dark mode) takes a `preheader` (key `<email>.preheader`, all 6 locales, < 90 chars). Colours: `#d63c42` links/buttons (4.58:1), `#fe696a` only for rules, `#6b7488` footer/small print; links underlined; buttons 44 px with the colour on the cell. Plain-text part by our own `EmailHtmlToTextConverter` (links as `text (URL)`). Subscription-like mails carry signed RFC 8058 `List-Unsubscribe` (results: `result_emails_unsubscribe`, digest: `digest_emails_unsubscribe`)
