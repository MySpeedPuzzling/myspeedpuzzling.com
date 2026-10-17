# Newsletter (Listmonk integration)

Newsletters are sent from a self-hosted [Listmonk](https://listmonk.app) (production: `https://listmonk.myspeedpuzzling.com`). **MySpeedPuzzling is the source of truth for who is subscribed; Listmonk is a mirror + send engine.**

## Audience model

| Who | Where subscription lives | How they subscribe |
|---|---|---|
| Registered players | `Player::$newsletterEnabled` | Unticked box at registration (since 2026-10-12, answer recorded in `auth_audit_log.metadata`; players registered earlier count as opted in - see [mass-mailing proposal §11](../mass-mailing/proposal-2026-10.md)), later Profile → Messaging & notifications |
| Guests (no account) | `NewsletterSubscriber` entity (`pending` → `confirmed` → `unsubscribed`) | Footer form on every page, **double opt-in** (confirmation e-mail, 48h token) |

One e-mail = one recipient. When an address belongs to both a player and a guest row, the player wins (`GetNewsletterRecipients`).

## Listmonk structure

- **6 private, single opt-in lists**, one per locale: `Newsletter EN/CS/DE/ES/FR/JA` (`ListmonkNewsletterLists`). Looked up by name, auto-created when missing — no ids configured anywhere.
- Subscriber attribs maintained by the sync: `locale`, `audience` (`player`/`guest`), `unsubscribe_url` (per-recipient signed MySpeedPuzzling URL), `manage_url` (players only — the standalone e-mail preferences page, see below).
- The campaign template (versioned at [`listmonk-campaign-template.html`](listmonk-campaign-template.html), uploaded to Listmonk as **"MySpeedPuzzling Newsletter"**, id 5 on production) renders a localized footer from those attribs. After editing the file, re-upload via `PUT /api/templates/5` (`name`, `subject`, `type` as they are + the new `body`; check `GET /api/templates/5/preview`) - last upload 2026-10-02, the previous body is backed up on the box in `/root/listmonk-template-5-backup-2026-10-02.json`.

## Sync — `myspeedpuzzling:sync-newsletter-subscribers` (cron */15)

`SyncNewsletterSubscribersHandler` reconciles both directions in one run:

- **Pull**: memberships unsubscribed in Listmonk (RFC 8058 one-click List-Unsubscribe header, archive page) → `newsletterEnabled=false` / guest `unsubscribed` in MySpeedPuzzling.
- **Push**: create missing subscribers (bulk CSV import above 50, e.g. the initial ~10k import), update drifted ones (name/locale list/attribs), mark MySpeedPuzzling unsubscribes as `unsubscribed` in Listmonk (kept as suppression rows, not deleted), delete subscribers whose e-mail no longer exists here at all (**deleted players are removed from Listmonk**).

Direction rules (enforced by `NewsletterSyncPlanner`, unit-tested):

- **An unsubscribe wins wherever it happened.**
- **The cron never flips a Listmonk unsubscribe back to confirmed.** Only explicit user actions do, via `PushNewsletterSubscriberToListmonk` (profile toggle re-enable, double opt-in confirm) — verified against Listmonk: `add`+`status=confirmed` flips an unsubscribed membership, plain PUT+preconfirm does not.
- Blocklisted subscribers (bounces) are never touched except player-deletion cleanup.
- Subscribers in non-newsletter lists are never fully deleted and foreign memberships are preserved.

Immediate pushes (async messages, cron is the safety net): profile newsletter toggle (`EditMessagingSettingsHandler`), opt-in confirm, unsubscribe page, and `DeletePlayerHandler` → `RemoveNewsletterSubscriberFromListmonk` (also wipes a guest row with the same address).

## Unsubscribe flow (email links)

Every newsletter footer links `attribs.unsubscribe_url` → `/{locale}/newsletter/unsubscribe/{token}`:

- Stateless HMAC token (`NewsletterTokenSigner`), bound to audience+id+e-mail, **no expiry** (old newsletters must keep working); dies automatically when the e-mail changes.
- The landing page changes nothing on GET (scanner-safe) and offers exactly two options: **one-click unsubscribe** (POST) and — for players — **manage e-mail preferences** (standalone page, see below).
- The unsubscribe POST (`…/{token}/confirm`) redirects to `…/{token}/done` (`NewsletterUnsubscribedController`, `no-store`) — it must never render the result itself, Turbo Drive discards a 200 answer to a form submission (visitors got unsubscribed, saw nothing and clicked again).
- Listmonk additionally sends its own `List-Unsubscribe`/`List-Unsubscribe-Post` headers pointing at itself (Gmail/Yahoo one-click); those unsubscribes reach MySpeedPuzzling via the cron pull within 15 minutes.

## E-mail preferences page (players)

Standalone, token-authenticated page at `/{locale}/email-preferences/{token}` — reachable **only from links in our e-mails** (newsletter footer `manage_url`, digest opt-out, unsubscribe landing page), never from site navigation. Rationale: the edit-profile page is crowded with forms; someone clicking "manage my settings" in an e-mail should land on a focused page that works without signing in.

- Token: `NewsletterTokenSigner` type `preferences` — deterministic, no expiry, dies on e-mail change (same semantics as unsubscribe tokens). URL built by `EmailPreferencesLinkGenerator`.
- Shows only the e-mail subset of settings: newsletter toggle + notification e-mails toggle + digest frequency. Saving dispatches `EditEmailPreferences` (deliberately narrower than `EditMessagingSettings` — cannot touch `allowDirectMessages`).
- Anonymous but personal: the GET response is `Cache-Control: no-store`; CSRF id `email-preferences` is stateless (no session cookie).
- Guests have no preferences page — their single preference (subscribed yes/no) is fully covered by the unsubscribe link and the footer form.

## Public signup (footer)

- Guests, two steps; **only the second one mails anybody**:
  1. Footer form → `POST newsletter_subscribe` (`NewsletterSubscribeController`, stateless CSRF `newsletter-subscribe` - the form is on every anonymous page, a session-backed token would kill shared caching; see `config/packages/csrf.php`). Sends nothing: answers the "Almost there - send the confirmation e-mail?" step, in the modal (the form targets `modal-frame`), as a page without JavaScript. The address stays editable there (typos).
  2. The step's form → `POST newsletter_subscribe_send` (`NewsletterSubscribeSendController`, native post): honeypot, the step's ticket, Turnstile, then the limits per address (**2 a day**) and IP (20/h) - consumed only after the checks, so a bot never uses up a real person's attempts - → confirmation e-mail (`emails/newsletter_confirmation.html.twig`, transactional stream) → `check-your-inbox`.
- The e-mail's link `newsletter_confirm` only **shows** the address and a "Yes, subscribe me" button; the button (`newsletter_confirm_submit`, stateless CSRF `newsletter-confirm`) confirms + pushes to Listmonk and redirects to `newsletter_confirmed` (`…/{token}/done`). Mail security scanners open every link of an incoming e-mail - on GET they confirmed addresses nobody had asked for. Links in confirmation e-mails sent before this change keep working (they now open the button page).
- Logged-in players see a link to notification settings instead of the form.

### Bots

Bots typed other people's addresses into the footer form ("subscription bombing" - burying a victim's inbox under sign-up mails). Found 2026-10-17 from the first hard bounces on SES (Sentry WEB-DK): all 3 hard and all 9 soft bounces since the move to SES were `newsletter_confirmation`; **68 of 75 guest signups were never confirmed**, from rotating datacenter IPs (at most 5 per IP, so the IP limit never tripped), addresses from old leaked lists (adelphia.net - an ISP gone since 2006 -, roadrunner.com, frontier.com), law firms, a state agency, `formtests.info`; one Gmail address got 6 confirmations in 10 hours and its provider answered "receiving mail too quickly". One bot-entered business address got *confirmed* 6 minutes after the signup - a link scanner, since the link confirmed on GET. Every confirmation to an address that never asked is a bounce or a complaint against the SES reputation of **all** our mail.

What stops them now, in order (`NewsletterSubscribeSendController`):

| Check | Where | A failing request |
|---|---|---|
| Honeypot `homepage` (`newsletter/_honeypot.html.twig`, hidden, `tabindex=-1`) | both steps | answered like a success (redirect to check-your-inbox), nothing sent, `info` log |
| Ticket (`NewsletterSignupTicket`: HMAC-signed time the step was shown) at least **3 s** and at most 1 h old | send | the step again (422) with a fresh ticket |
| **Cloudflare Turnstile** (`TurnstileVerifier`, action `newsletter`, our hostname, single-use token) | send | the step again (422); siteverify not answering = refused too (fails closed) + `warning` |
| Limits: per address 2 a day, per IP 20/h | send | the step again (422) |

Turnstile reuses the edge's widget (lily.srv D48 - the bot-blocker's and CrowdSec's challenge pages): `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`, already rendered into the web container's `.env` from Infisical. Either empty = Turnstile off (dev, CI), the other checks still apply. Its script (`turnstile_controller.js`, explicit render) loads **only when the step is shown** - never site-wide; the send button stays disabled until the check passed. Cloudflare's test keys (`.env` comment) pass locally: their answer carries `metadata.result_with_testing_key`, which a real secret never returns, so the action/hostname check is skipped only for them.

Measuring it: `newsletter_subscriber` rows created vs confirmed per day, and `email_audit_log` bounces with `email_type = 'newsletter_confirmation'` (queries in the 2026-10-17 analysis: per-day mails/bounces, repeated addresses, signups per IP).

## Configuration

```
LISTMONK_API_URL=http://listmonk:9000     # empty token disables the whole integration
LISTMONK_API_USER=api-dev                 # prod: api-msp-web via Infisical
LISTMONK_API_TOKEN=...
```

Dev: the `listmonk-seed` compose service seeds the `api-dev` API user; admin UI `localhost:8090` (admin/adminadmin). Dev Listmonk SMTP points at Mailpit via the DB `settings` table (the `LISTMONK_smtp__*`/config values are only install-time seeds — same as production, the live SMTP config is the DB row). Tests run with the integration disabled (`.env.test`).

## Sending a campaign (checklist)

1. Content per locale → one campaign per locale list, template "MySpeedPuzzling Newsletter", content type HTML.
2. Use `{{ if .Subscriber.FirstName }}` greeting guard (guests have no name); CTA via `<a class="button" href="...">`.
3. Test send to yourself first (`POST /api/campaigns/{id}/test` — payload must repeat the campaign fields incl. `messenger: "email"`).
4. Production send throttle is already configured conservatively (600/h); campaigns per locale can run simultaneously — the sliding window is global.
