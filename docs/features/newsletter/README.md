# Newsletter (Listmonk integration)

Newsletters are sent from a self-hosted [Listmonk](https://listmonk.app) (production: `https://listmonk.myspeedpuzzling.com`). **MySpeedPuzzling is the source of truth for who is subscribed; Listmonk is a mirror + send engine.**

## Audience model

| Who | Where subscription lives | How they subscribe |
|---|---|---|
| Registered players | `Player::$newsletterEnabled` (opt-out, default `true`) | Profile → Messaging & notifications |
| Guests (no account) | `NewsletterSubscriber` entity (`pending` → `confirmed` → `unsubscribed`) | Footer form on every page, **double opt-in** (confirmation e-mail, 48h token) |

One e-mail = one recipient. When an address belongs to both a player and a guest row, the player wins (`GetNewsletterRecipients`).

## Listmonk structure

- **6 private, single opt-in lists**, one per locale: `Newsletter EN/CS/DE/ES/FR/JA` (`ListmonkNewsletterLists`). Looked up by name, auto-created when missing — no ids configured anywhere.
- Subscriber attribs maintained by the sync: `locale`, `audience` (`player`/`guest`), `unsubscribe_url` (per-recipient signed MySpeedPuzzling URL), `manage_url` (players only).
- The campaign template (versioned at [`listmonk-campaign-template.html`](listmonk-campaign-template.html), uploaded to Listmonk as **"MySpeedPuzzling Newsletter"**) renders a localized footer from those attribs. After editing the file, re-upload via `PUT /api/templates/{id}`.

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
- The landing page changes nothing on GET (scanner-safe) and offers exactly two options: **one-click unsubscribe** (POST) and — for players — **manage notification settings** (edit profile).
- Listmonk additionally sends its own `List-Unsubscribe`/`List-Unsubscribe-Post` headers pointing at itself (Gmail/Yahoo one-click); those unsubscribes reach MySpeedPuzzling via the cron pull within 15 minutes.

## Public signup (footer)

- Guests: e-mail form → `POST newsletter_subscribe` (stateless CSRF `newsletter-subscribe` — the form is on every anonymous page, a session-backed token would kill shared caching; see `config/packages/csrf.php`), rate-limited per address (3/15min) and IP (20/h), → confirmation e-mail (`emails/newsletter_confirmation.html.twig`, transactional transport) → `newsletter_confirm` → confirmed + pushed to Listmonk.
- Logged-in players see a link to notification settings instead of the form.

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
