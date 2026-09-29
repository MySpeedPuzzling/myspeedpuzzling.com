# Social login setup — Apple

Exact setup for "Sign in with Apple" (auth hardening PR 2 #175, hardening 2026-09-29, Apple go-live prep 2026-09-29). The code is done and deployed dark; this guide is the Apple Developer portal, the secret hand-off and the rollout. Prerequisite: the paid Apple Developer Program membership (you have it).

**Apple cannot be tested on localhost.** Apple only accepts https return URLs on a registered domain. Verify it in production while `SOCIAL_LOGIN_ADMIN_ONLY=1` (the default): only admins can use it and nobody else sees a button.

Click paths checked against Apple's help pages on 2026-09-29 ([Services ID for the web](https://developer.apple.com/help/account/capabilities/configure-sign-in-with-apple-for-the-web), [server-to-server notifications](https://developer.apple.com/help/account/capabilities/enabling-server-to-server-notifications), [private email relay](https://developer.apple.com/help/account/capabilities/configure-private-email-relay-service)). Apple renames buttons from time to time; the structure stays the same.

## What you will produce

| What | Where it goes | Example |
|---|---|---|
| App ID (bundle id) | `APPLE_APP_ID` | `com.myspeedpuzzling.app` |
| Services ID identifier | `APPLE_CLIENT_ID` (**the Services ID, not the App ID**) | `com.myspeedpuzzling.web` |
| Team ID (10 characters) | `APPLE_TEAM_ID` | `AB12CD34EF` |
| Key ID (10 characters) | `APPLE_KEY_ID` | `XYZ987WVU6` |
| `.p8` private key file | `APPLE_PRIVATE_KEY` (its content) | `AuthKey_XYZ987WVU6.p8` |

Fixed URLs (from the code, do not change):

| | URL |
|---|---|
| Return URL (sign-in callback, route `social_login_callback`) | `https://myspeedpuzzling.com/login/social/apple/callback` |
| Server-to-server notification endpoint (route `apple_sign_in_notification`) | `https://myspeedpuzzling.com/webhook/apple-sign-in` |

All portal work happens in **Certificates, Identifiers & Profiles**: <https://developer.apple.com/account/resources/identifiers/list>.

**Keep everything under this one team.** The Apple user id (`sub`) that we store for every Apple sign-in belongs to the *team*. If the Services ID ever moves to another team (or a new team is created), every existing Apple user gets a different `sub` and can no longer sign in to their account. Never delete the Services ID or the App ID.

## 1. App ID (the primary App ID)

Sign in with Apple for the web hangs off a *primary App ID*, even without an iOS app.

1. **Identifiers** (sidebar) → **+** (top left) → **App IDs** → **Continue** → type **App** → **Continue**.
2. Description `MySpeedPuzzling`. Bundle ID **Explicit**: `com.myspeedpuzzling.app`. This is `APPLE_APP_ID`.
3. Under **Capabilities** tick **Sign In with Apple** and leave **Enable as a primary App ID** selected.
4. **Continue** → **Register**.
5. Open the new App ID again → next to **Sign In with Apple** click **Configure** (or **Edit**). In **Server-to-Server Notification Endpoint** enter exactly:
   `https://myspeedpuzzling.com/webhook/apple-sign-in`
   → **Save** (top right) → confirm.

## 2. Services ID (the client id)

1. **Identifiers** → **+** → **Services IDs** → **Continue**.
2. Description `MySpeedPuzzling Web`, Identifier `com.myspeedpuzzling.web` → **Continue** → **Register**. This identifier is `APPLE_CLIENT_ID`.
3. Click the Services ID in the list → tick **Sign In with Apple** → **Configure**.
4. In the dialog:
   - **Primary App ID**: `com.myspeedpuzzling.app` (step 1).
   - **Domains and Subdomains**: `myspeedpuzzling.com`
   - **Return URLs**: `https://myspeedpuzzling.com/login/social/apple/callback` (exactly, no trailing slash, no `www`).
5. **Done** → **Continue** → **Save**.

No file needs to be uploaded to the website. Apple dropped domain verification for Sign in with Apple.

## 3. Signing key (.p8)

1. **Keys** (sidebar) → **+**.
2. Key Name `MySpeedPuzzling Sign in with Apple`, tick **Sign in with Apple** → **Configure** → Primary App ID `com.myspeedpuzzling.app` → **Save**.
3. **Continue** → **Register**.
4. **Download** the `.p8` file. **You can download it only once.** Put a copy in the password manager. Write down the **Key ID** shown on that page (`APPLE_KEY_ID`; it is also in the file name `AuthKey_<KEY ID>.p8`).
5. **Team ID**: <https://developer.apple.com/account> → **Membership details** → **Team ID** (`APPLE_TEAM_ID`).

The key does not expire and needs no rotation. The app builds a fresh short-lived client secret from it on every sign-in. If the file is ever lost: create a new key, update `APPLE_KEY_ID` and `APPLE_PRIVATE_KEY`, then revoke the old key. Users are not affected.

## 4. Private email relay: register every sending domain (do not skip)

Visitors who pick **Hide My Email** get an `…@privaterelay.appleid.com` address, and it becomes their MySpeedPuzzling account e-mail. Apple's relay **drops mail from senders that are not registered**, without telling anyone: sign-in links, verification mails, notifications and newsletters to those people all vanish.

Apple checks one of two things for each message:
- **SPF**: the domain of the *envelope sender* (Return-Path) must be registered, match exactly, and pass SPF;
- **DKIM**: the DKIM signature domain (`d=`) must equal the `From:` domain, and that domain must be registered.

What we send, and from where (checked in the code and DNS 2026-09-29):

| Mail | `From:` | Envelope sender / Return-Path | Sent by |
|---|---|---|---|
| Transactional: sign-in links, verification, password reset, security notices, membership, … (`config/packages/mailer.php` default) | `robot@mail.myspeedpuzzling.com` | same address (VERP is off in production — `BOUNCE_EMAIL_DOMAIN` unset, see `docs/features/emails-tracking.md`; if it is ever switched on it becomes `bounce+<id>@mail.myspeedpuzzling.com`, same domain) | app → `smtp.seznam.cz` |
| Notification digests (`PrepareDigestEmailForPlayerHandler`) | `notify@notify.myspeedpuzzling.com` | same address | app → `smtp.seznam.cz` |
| Newsletter (Listmonk, `docs/features/newsletter/README.md`) | `newsletter@news.myspeedpuzzling.com` | same address | Listmonk → `smtp.seznam.cz` |

DNS for each of `mail.`, `notify.`, `news.myspeedpuzzling.com` and the apex (read-only `dig` 2026-09-29):
- SPF `v=spf1 include:spf.seznam.cz ~all` — Seznam's servers are covered, so **SPF passes**.
- DKIM selectors `szn1/szn2/szn3._domainkey.<domain>` → CNAME to Seznam's keys, which resolve.
- DMARC `_dmarc.<domain>` → sendvery.com.

Nothing is sent from the apex `myspeedpuzzling.com` today (`jan@…` only appears as a reply address and in page text).

Register them:

1. Certificates, Identifiers & Profiles → **Services** (sidebar) → **Sign in with Apple for Email Communication** → **Configure**.
2. Under **Email Sources** click **+**. Enter the domains as a comma-separated list:
   `mail.myspeedpuzzling.com, notify.myspeedpuzzling.com, news.myspeedpuzzling.com, myspeedpuzzling.com`
   → **Next** → check → **Register**. (The apex is there so a future sender on it works too. A registered domain does **not** cover its subdomains — each one needs its own entry.)
3. Click **+** again and register the three addresses as well (harmless, and it covers the DKIM path by address):
   `robot@mail.myspeedpuzzling.com, notify@notify.myspeedpuzzling.com, newsletter@news.myspeedpuzzling.com`
4. The table shows an SPF status per domain. **Wait until all four show a passed/green SPF check** before the public flip. If one fails, send me the domain — do not change DNS by hand.

If a new sender is ever added (another From address, a new subdomain, turning on VERP on a different domain, a new mail provider), it must be added here too.

Mail that does **not** come from us is not covered: Stripe receipts go to the Stripe customer e-mail, which is the account e-mail (relay address included), and come from Stripe's own domain. See Open questions.

## 5. Hand the secrets over

Keep the secrets **outside every git repository**, in `~/.msp-secrets/` (never commit, never paste them into chat):

1. `~/.msp-secrets/social-login.env`: add these lines (plain values, no quotes):
   ```
   APPLE_APP_ID=com.myspeedpuzzling.app
   APPLE_CLIENT_ID=com.myspeedpuzzling.web
   APPLE_TEAM_ID=AB12CD34EF
   APPLE_KEY_ID=XYZ987WVU6
   ```
2. The downloaded key file itself, unchanged, next to it: `~/.msp-secrets/AuthKey_XYZ987WVU6.p8`. Do **not** paste the key content into the env file.

Then tell Claude "Apple secrets are in ~/.msp-secrets". An agent then:
1. reads the `.p8` and prepares its whole content (including the `-----BEGIN/END PRIVATE KEY-----` lines) as `APPLE_PRIVATE_KEY`, **on one line with a literal `\n` for each line break**. The production `.env` is a Docker env file, and those cannot hold values that span several lines; `AppleProviderWithInlineKey` turns the `\n` back into line breaks;
2. writes `APPLE_APP_ID`, `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID` and `APPLE_PRIVATE_KEY` to Infisical project **myspeedpuzzling**, environment **prod** (procedure: memory `reference_production_access.md`, "Infisical admin from the box");
3. only after that sets `SOCIAL_LOGIN_APPLE_ENABLED=1` and leaves `SOCIAL_LOGIN_ADMIN_ONLY=1`;
4. queues a redeploy so `dump_secrets` renders the new `.env`.

The env var names the app reads are `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` and `APPLE_APP_ID` (`config/services.php`). `APPLE_APP_ID` is only used to check the audience of server-to-server notifications: those carry the App ID, not the Services ID.

## 6. Admin-only test in production (checklist)

Signed in to Apple as yourself (an admin player), in a private window:

1. **Real e-mail → automatic link.** Open `https://myspeedpuzzling.com/login/social/apple`, choose **Share My Email** with the address of your existing MySpeedPuzzling admin account. Expected: you are signed straight into that account (rule 2, verified address) and a "Apple sign-in was connected" security mail arrives.
2. **Disconnect.** Edit profile → Connected sign-in methods → Apple → **Disconnect**. It disappears from the list.
3. **Fresh first authorization.** Apple sends name and e-mail only the first time. To get a first time again: <https://account.apple.com> → **Sign-In and Security** → **Sign in with Apple** → MySpeedPuzzling → **Stop using Sign in with Apple** (on an iPhone: Settings → your name → Sign in with Apple). This also sends us a `consent-revoked` notification. It changes nothing (Apple is already disconnected), but it proves the endpoint is reachable: ask Claude to check that Traefik logged a `POST /webhook/apple-sign-in` answered 200, not 400.
4. **Connect with Hide My Email.** Edit profile → Connect Apple → choose **Hide My Email**. It connects to your account.
5. **Relay delivery, from every sender.** Send one test mail to that `…@privaterelay.appleid.com` address from each of `robot@mail.`, `notify@notify.` and `newsletter@news.myspeedpuzzling.com`. Ask Claude to do it from the box, or use a Listmonk test campaign for the newsletter. All three must arrive in your real inbox. Open one and check the headers: `Authentication-Results` should show `spf=pass` and `dkim=pass header.d=<the sending subdomain>`.
6. **Cancel.** Start `/login/social/apple` and cancel at Apple. You land back on the login page, still signed out, and nothing changes.

Everything green → the public flip.

## 7. Public flip and fresh-Apple-ID test

1. Tell Claude to set `SOCIAL_LOGIN_ADMIN_ONLY=0` (this is the public launch of *all* enabled providers, so coordinate with Google/Facebook) and redeploy.
2. With an Apple ID that has **never** been used with MySpeedPuzzling (a family member's, or a new one), open `/login/social/apple` and choose **Hide My Email**. Expected:
   - the page **"Do you already have a MySpeedPuzzling account?"** explains that Apple hides the address, with **"I already have an account"** as the big primary button;
   - **"I'm new here — create my account"** creates the account, and the player name is the name Apple sent (first authorization only).
3. Repeat with an Apple ID that is new to us but **shares** a real address: the normal "Create a new account?" page appears.

**Rollback at any time:** `SOCIAL_LOGIN_APPLE_ENABLED=0` + redeploy. The button disappears and Apple callbacks are refused. Linked identities stay in the database and work again after re-enabling. Server-to-server notifications keep being processed even with the flag off, so a deleted Apple ID still loses its identity.

## Server-to-server notifications (what the endpoint does)

`POST /webhook/apple-sign-in` (`AppleSignInNotificationController`, stateless firewall, no CSRF, no session). Apple posts `{"payload": "<JWT>"}`. The token is verified (RS256 against Apple's JWKS, `iss` = `https://appleid.apple.com`, `aud` = `APPLE_APP_ID` or `APPLE_CLIENT_ID`, expiry) by `AppleServerNotificationVerifier`. All cheap checks run before Apple's keys are fetched, and the keys are cached: refetched only for an unknown key id, and then at most every 5 minutes. A flood of forged posts therefore cannot hammer Apple. Answers: 200 when processed or ignored, 400 when the token is not Apple's for us. `ProcessAppleSignInEventHandler` then acts:

| Event | Effect |
|---|---|
| `account-delete` / `account-deleted` | Apple ID deleted → the Apple identity is removed, always. If it was the account's only sign-in method, a **warning** goes to Sentry: the account is now reachable only by an e-mailed sign-in link, which never arrives when that address is a dead relay. |
| `consent-revoked` | The user disconnected us at Apple → the identity is removed, like the settings "Disconnect", **unless it is the only sign-in method**. Then it is kept: Apple will not sign them in without new consent anyway, and when they consent again Apple returns the same `sub`, which signs them straight into this account instead of creating a second one. |
| `email-disabled` / `email-enabled` | Relay forwarding switched off/on → info log only, nothing stored. |
| anything else | Acknowledged (200) and ignored. |

Every removal writes an `oauth_identity_unlinked` audit row with `source: apple_server_notification`. Sessions that are already signed in are not ended: remember-me cookies are signed from e-mail + password and cannot be revoked per provider. The consequence is small, because the user disconnected Apple, not MySpeedPuzzling.

If notifications never arrive: the endpoint sits behind the same Traefik router as the pages (CrowdSec + bot-blocker), like `/webhook/stripe`, which works. Check Traefik's access log for `/webhook/apple-sign-in` before suspecting the app.

## Gotchas recap

- `APPLE_CLIENT_ID` is the **Services ID**, not the App ID — the most common misconfiguration. A wrong one shows up as the log line "Apple id_token was not issued to this client".
- Wrong Key ID / Team ID / key content → `invalid_client` from Apple's token endpoint (Sentry: "Social login code exchange failed").
- The callback is a cross-site **POST** from `appleid.apple.com` (form_post). If a WAF or proxy rule ever blocks cross-site POSTs to `/login/social/apple/callback`, that is the symptom.
- A relay user who later turns forwarding off at Apple has a dead account e-mail. Nothing can be done beyond the existing change-email flow; `email-disabled` shows up in the logs.

## Open questions

- **Stripe mails to relay addresses.** Stripe customers are created with the account e-mail (`CreatePlayerStripeCustomerHandler`), so a relay user's receipts and payment mails come from Stripe's domain and will probably be dropped by the relay. Options: Stripe's custom e-mail domain (send Stripe mails from our own domain, then register it in §4), or accept the loss.
- Listmonk: confirm in Listmonk's SMTP settings that no separate Return-Path/bounce address on another domain is configured. The table above assumes the envelope sender is `newsletter@news.myspeedpuzzling.com`.

Docs: <https://developer.apple.com/documentation/signinwithapple>, <https://developer.apple.com/documentation/signinwithapple/communicating-using-the-private-email-relay-service>, <https://developer.apple.com/documentation/signinwithapple/processing-changes-for-sign-in-with-apple-accounts>.
