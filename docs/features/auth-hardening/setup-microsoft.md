# Social login setup — Microsoft

**The code shipped 2026-09-30, dark:** Microsoft is available iff `MICROSOFT_CLIENT_ID` and `MICROSOFT_CLIENT_SECRET`
are both set (`SocialLoginSettings::isEnabled()`, no feature flag) — production has neither yet, so nothing is visible
until the values reach Infisical. Plan and decisions: [`microsoft-plan.md`](microsoft-plan.md). Written against
Microsoft's documentation of 2026-09-29 (Microsoft Entra admin center: **Entra ID → App registrations**). Microsoft renames menu
items now and then; if a label differs, the section names (Authentication, Certificates & secrets, API permissions,
Branding & properties) are the stable part.

## Before you start

| What | Value |
|---|---|
| Account to use | Your personal Microsoft account (or a work account in the tenant — see step 1) |
| App name | `MySpeedPuzzling` |
| Supported account types | **Personal accounts only** (older label: *Personal Microsoft accounts only*) |
| Production redirect URI | `https://myspeedpuzzling.com/login/social/microsoft/callback` (platform **Web**) |
| Local dev redirect URI | `http://localhost:8080/login/social/microsoft/callback` (separate registration, step 8) |
| Home page | `https://myspeedpuzzling.com` |
| Terms of service | `https://myspeedpuzzling.com/en/terms-of-service` |
| Privacy statement | `https://myspeedpuzzling.com/en/privacy-policy` |
| Publisher domain | `myspeedpuzzling.com` |
| Permissions | Microsoft Graph, delegated: `openid`, `email`, `profile` — nothing else |
| Env vars the app reads | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_CLIENT_SECRET_EXPIRES_AT` |

The one redirect URI serves both sign-in and connecting Microsoft in settings. No language prefix, no trailing slash.

**Never delete the app registration.** It cannot be moved to another tenant, and a new one would be a different app to
Microsoft. (The code keys users on the tenant-wide `oid`, so a re-created app would still recognise people — but the
secret, branding and consent history would be gone.)

## 1. Get a tenant (one time)

Since June 2024 a personal Microsoft account can no longer register apps without a directory ("tenant").

- **You already have a Microsoft Entra tenant** (e.g. a company Microsoft 365): use it; you need at least the
  *Application Developer* role there. Skip to step 2.
- **You don't:** sign up for a free Azure account with your personal Microsoft account at
  <https://azure.microsoft.com/free/> → it asks for a phone number and a card for identity verification (no charge
  unless you later upgrade) → Azure creates a **Default Directory** (Microsoft Entra ID Free) with you as Global
  Administrator. The app registration lives in that directory and keeps working even if the Azure subscription
  itself lapses.
- Not suitable: the Microsoft 365 Developer Program sandbox (restricted eligibility, sandbox tenants expire).

Microsoft enforces MFA for admin center sign-in — have the Authenticator app (or a passkey) ready.

Recommended while you are there: **Entra ID → Users → New user → Create new user** `admin@<your-tenant>.onmicrosoft.com`
with MFA, role *Application Administrator*, and later add it as a second **Owner** of the app (step 9). That keeps the
app reachable if your personal login ever has a problem, and it is the account type publisher verification would need.

## 2. Register the app

1. Open <https://entra.microsoft.com>. If you see several directories: **Settings** (gear, top right) → **Directories +
   subscriptions** → switch to the one from step 1.
2. **Entra ID → App registrations → New registration**.
3. **Name:** `MySpeedPuzzling` (users see it on Microsoft's consent screen).
4. **Supported account types:** **Personal accounts only**.
5. **Redirect URI:** platform **Web**, value `https://myspeedpuzzling.com/login/social/microsoft/callback`.
6. **Register**.
7. On the **Overview** page copy **Application (client) ID** (a GUID) — that is `MICROSOFT_CLIENT_ID`. The *Directory
   (tenant) ID* is **not** needed (we use the `consumers` endpoint).

## 3. Authentication

**Authentication** (left menu of the app):

1. Under **Web → Redirect URIs** the production URI from step 2 is listed. Nothing else there.
2. **Implicit grant and hybrid flows:** leave **both** checkboxes (*Access tokens*, *ID tokens*) **unticked** — we use
   the authorization-code flow with PKCE; the ID token comes from the token endpoint.
3. **Front-channel logout URL:** empty.
4. **Allow public client flows:** **No**.
5. **Save**.

## 4. API permissions

**API permissions**:

1. A new registration lists Microsoft Graph **User.Read**. Click `…` → **Remove permission** → confirm (we never call
   Graph).
2. **Add a permission → Microsoft Graph → Delegated permissions** → tick `openid`, `email`, `profile` (under
   *OpenId permissions*) → **Add permissions**.
3. No admin consent is needed (personal accounts consent for themselves).

## 5. Token configuration (optional, for the test)

Skip it. (The plan considered the `xms_edov` optional claim to widen e-mail trust one day; the code does not read
it — trust is the Microsoft-mailbox domain list, plan §1 D3.)

## 6. Branding & properties

**Branding & properties**:

1. **Name:** `MySpeedPuzzling`.
2. **Logo:** square PNG/JPG, max 100 KB (215 × 215 px recommended) — the MySpeedPuzzling square logo (the same
   artwork as for Google's branding).
3. **Home page URL:** `https://myspeedpuzzling.com`
4. **Terms of service URL:** `https://myspeedpuzzling.com/en/terms-of-service`
5. **Privacy statement URL:** `https://myspeedpuzzling.com/en/privacy-policy`
6. **Save**.
7. **Publisher domain** → **Configure a domain** → **Verify a new domain** → `myspeedpuzzling.com`. Microsoft wants a
   file at `https://myspeedpuzzling.com/.well-known/microsoft-identity-association.json`:

   ```json
   {"associatedApplications": [{"applicationId": "<Application (client) ID from step 2>"}]}
   ```

   **Nothing to add to the repo:** the app serves this file itself from `MICROSOFT_CLIENT_ID`
   (`MicrosoftIdentityAssociationController`, `application/json`, 404 while the variable is empty). So the order is:
   finish steps 7–10 first (production client id in Infisical + deployed), check
   `curl -s https://myspeedpuzzling.com/.well-known/microsoft-identity-association.json` shows your client id, then
   click **Verify and save domain**. The bot-blocker lets this exact path through unconditionally (Microsoft's
   verifier is a server-side fetch from Azure with an unknown User-Agent — bot-blocker commit `7effa7c`, live after
   `docker compose pull bot-blocker && docker compose up -d bot-blocker` on the box). The file may stay forever.

**About "unverified":** Microsoft's consent screen will say *unverified* next to the app name. Removing it needs
*publisher verification* (Partner Center business account + an app registered by a work account in a tenant with
`myspeedpuzzling.com` as a verified custom domain). Not needed to launch — see plan Q2.

## 7. Client secret — copy it NOW

**Certificates & secrets → Client secrets → New client secret**:

1. **Description:** `prod 2026-10` (month you create it — helps at rotation).
2. **Expires:** **730 days (24 months)** (or 365 days — plan Q7). The portal allows at most 24 months.
3. **Add**.
4. Copy the **Value** column immediately — that is `MICROSOFT_CLIENT_SECRET`. It is shown **only now**; after leaving
   the page you see only its first characters. (The *Secret ID* column is **not** the secret.)
5. Note the **Expires** date shown in the list — that is `MICROSOFT_CLIENT_SECRET_EXPIRES_AT` (`YYYY-MM-DD`).

Lost it? Create another secret, use that one, delete the unused one.

## 8. Separate registration for local testing

Repeat steps 2–7 as `MySpeedPuzzling Local` with redirect URI
`http://localhost:8080/login/social/microsoft/callback` (Microsoft allows `http` for `localhost`; add
`http://localhost:<port>/…` too if you run the stack with `WEB_PORT=…`). Skip step 6's publisher domain (not needed
locally). Secret expiry: 90 days is plenty.

Hand the local values over as `MICROSOFT_LOCAL_CLIENT_ID` / `MICROSOFT_LOCAL_CLIENT_SECRET` in
`~/.msp-secrets/social-login.env` (step 10). Locally they go into the checkout's `.env.local` under the **normal**
names — the app only ever reads `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET`:

```
MICROSOFT_CLIENT_ID=<MICROSOFT_LOCAL_CLIENT_ID>
MICROSOFT_CLIENT_SECRET=<MICROSOFT_LOCAL_CLIENT_SECRET>
```

Then `docker compose restart web` (FrankenPHP workers read env at boot). The Microsoft button appears on
`http://localhost:8080/login`. Leave `MICROSOFT_CLIENT_SECRET_EXPIRES_AT` out locally.

## 9. Owners

App → **Owners → Add owners** → add the work account from step 1 (if you created it). Two owners = no single point of
failure.

## 10. Hand the secrets to Claude

Never paste the secret into a chat.

1. Add to `~/.msp-secrets/social-login.env` (outside any git repository, `chmod 600`):

   ```
   MICROSOFT_CLIENT_ID=00000000-0000-0000-0000-000000000000
   MICROSOFT_CLIENT_SECRET=…
   MICROSOFT_CLIENT_SECRET_EXPIRES_AT=2028-10-01
   ```

2. Tell Claude: "Microsoft secrets are in ~/.msp-secrets/social-login.env — test locally first, then put them in
   Infisical." (Local test uses the `MySpeedPuzzling Local` values from step 8.)

What the agent then does: writes the three values to Infisical project **myspeedpuzzling**,
environment **prod**, path `/` over ssh stdin (procedure: memory `reference_production_access.md`, "Infisical admin from
the box"), queues a deploy, confirms the web container sees non-empty values (lengths only), adds the rotation reminder
to `docs/TODO.md`. **From that deploy on, the Microsoft button is public** — there is no admin-only stage (plan §7).

## 11. Test checklist

Run it on **localhost** first (dev registration, Mailpit at `localhost:8025` for the mails), then the short
production smoke test (items 1, 2, 4, 5) right after the deploy.

1. **Button:** `/login` and `/register` in a private window → white "Continue with Microsoft" button with the
   four-colour squares and the small "Outlook.com, Hotmail, Live or Xbox account" line; order Google, Microsoft,
   Apple, Facebook (on `/register` after "Continue with email").
2. **Connect from settings:** Edit profile → **Connected sign-in methods** → **Continue with Microsoft** → Microsoft
   account picker → consent screen (shows "MySpeedPuzzling", *unverified*, and the requested permissions) → **Accept**
   → back on edit profile with "Connected!".
3. **Security notice e-mail** "Microsoft sign-in was connected to your MySpeedPuzzling account".
4. **Sign in with Microsoft:** sign out → `/login` → Continue with Microsoft → pick the same account → signed in.
5. **Recent activity** (`/en/account/recent-activity`): "Sign-in method connected" + "Signed in with a connected
   account".
6. **Cancel:** on Microsoft's consent screen click **Cancel** → back on the sign-in page, nothing created.
7. **Auto-link (rule 2):** an `@outlook.com`/`@hotmail.*` Microsoft account whose address matches an existing, verified
   MySpeedPuzzling account → signed straight into that account + notice e-mail.
8. **Not auto-linked (rule 3):** a Microsoft account whose user name is a **Gmail** (or other non-Microsoft) address
   that matches an existing account → "Microsoft has not confirmed the address … sign in first, then connect" and
   you stay signed out.
9. **New account (rule 4):** a Microsoft account matching nothing → "Create a new account with …?" → confirm → signed
   in; a Gmail-based Microsoft account gets the e-mail verification mail as well. ("Trusted" = the exact domain list in
   `MicrosoftConsumerMailDomains` — `outlook.com`, `hotmail.*`, `live.*`, `msn.com`, … MX-verified; `outlook.cz` /
   `hotmail.cz` are *not* Outlook.com mailboxes and count as unconfirmed.)
10. **Work account refused:** try a work/school address → Microsoft itself says it can't be used here.
11. **Disconnect** in settings → gone; reconnect if you want to keep it.

To re-test the consent screen: remove the app at <https://account.live.com/consent/Manage> (Edit → Remove these
permissions).

## Rollback

Delete `MICROSOFT_CLIENT_ID` and `MICROSOFT_CLIENT_SECRET` in Infisical and redeploy: button, start route and callback
disappear together. Linked identities stay in the database and work again when the values return; Microsoft-only
players can still get in with the e-mailed sign-in link or a password reset.

## Secret rotation (every ≤ 24 months)

The app warns in Sentry from 30 days before `MICROSOFT_CLIENT_SECRET_EXPIRES_AT` (a warning at most once a day,
logged by `MicrosoftClientSecretExpiryCheck` at the end of a request — no cron), and logs an error daily once the date
has passed; a malformed date is reported the same way. Then:

1. **Certificates & secrets → New client secret** (description `prod YYYY-MM`) — the old one keeps working meanwhile.
2. Put the new value + new expiry date in `~/.msp-secrets/social-login.env`, ask Claude to update Infisical and deploy.
3. After a successful Microsoft sign-in in production, **delete the old secret** in the portal.

If it expired anyway: every Microsoft sign-in fails — Sentry shows the error "Microsoft client secret expired - rotate
MICROSOFT_CLIENT_SECRET" (`AADSTS7000222`) — until steps 1–2 are done; nobody is locked out for good (sign-in link,
password reset).

## Gotchas

- `AADSTS50011` (redirect URI mismatch): character-for-character — `https`, no `www`, no `/en`, no trailing slash, and
  platform **Web** (not SPA).
- `AADSTS700016` / `unauthorized_client`: wrong client id, or the registration is not "Personal accounts only" while
  the app calls the `consumers` endpoint.
- `AADSTS7000215` (invalid secret): you copied the *Secret ID* instead of the *Value*.
- `AADSTS7000222`: the secret expired — see rotation.
- Button follows Microsoft's [Sign in with Microsoft branding guidelines](https://learn.microsoft.com/en-us/entra/identity-platform/howto-add-branding-in-apps):
  white, `#8C8C8C` border, `#5E5E5E` text, the unmodified four-square logo; wording is our shared "Continue with …".
