# Social login setup — Google

The exact click-path for "Continue with Google", from an empty Google Cloud account to the public launch. The code is done and deployed dark (auth hardening PR #175 + hardening 2026-09-29); what is left is the Google console, the secrets hand-off and the flag flips.

Click-path checked against Google's documentation on 2026-09-29 (Google Auth Platform: **Branding**, **Audience**, **Data access**, **Clients**). Google renames things now and then; if a label differs, the section names above are the stable part.

## Before you start

| What | Value |
|---|---|
| Google account to use | The one that is **Owner of the `myspeedpuzzling.com` property in Google Search Console** (brand verification requires the domain to be verified by an Owner/Editor of the Cloud project — using the same account avoids adding people later) |
| App name | `MySpeedPuzzling` |
| Home page | `https://myspeedpuzzling.com` |
| Privacy policy | `https://myspeedpuzzling.com/en/privacy-policy` |
| Terms of service | `https://myspeedpuzzling.com/en/terms-of-service` |
| Authorized domain | `myspeedpuzzling.com` |
| Scopes | `openid`, `email`, `profile` — nothing else (all non-sensitive: no Google app review) |
| Production redirect URI | `https://myspeedpuzzling.com/login/social/google/callback` |
| Local dev redirect URI | `http://localhost:8080/login/social/google/callback` (other port if you run the stack with `WEB_PORT=…`) |
| Env vars the app reads | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `SOCIAL_LOGIN_GOOGLE_ENABLED`, `SOCIAL_LOGIN_ADMIN_ONLY` |

The one redirect URI serves both sign-in and connecting Google in settings — the app tells them apart by its own state, so Google needs no second URI. It has no language prefix (`/en/…`) and no trailing slash.

**Timing:** the only wait is brand verification (step 4). Google says the automated check usually finishes in a few minutes; if it goes to manual review it takes 2–3 business days. Until it passes, Google's consent screen shows the domain instead of "MySpeedPuzzling" — sign-in still works, so you can test in the meantime.

## 1. Create the Google Cloud project

1. Open <https://console.cloud.google.com/> signed in with the account from the table above.
2. Top bar → project picker → **New project**.
3. Project name `MySpeedPuzzling`, Organization/Location: leave as offered → **Create**.
4. Make sure the new project is selected in the top bar for every following step. No billing account is needed.

## 2. Start Google Auth Platform (the consent screen)

1. Open <https://console.cloud.google.com/auth/overview> (menu ☰ → **Google Auth Platform** → **Overview**) → **Get started**.
2. **App information**: App name `MySpeedPuzzling`; User support email: pick from the list (it is shown to users on the consent screen — a role address is nicer than a personal one; the list offers your own address and Google Groups you manage) → **Next**.
3. **Audience**: **External** → **Next**.
4. **Contact information**: your e-mail (Google writes here about the project) → **Next**.
5. **Finish**: tick *I agree to the Google API Services: User Data Policy* → **Continue** → **Create**.

## 3. Branding

<https://console.cloud.google.com/auth/branding> (Google Auth Platform → **Branding**)

1. App name and User support email are already filled from step 2.
2. **App logo**: optional. If you want one: square PNG/JPG, 120 × 120 px, max 1 MB. It shows only after brand verification.
3. **App domain**:
   - Application home page: `https://myspeedpuzzling.com`
   - Application privacy policy link: `https://myspeedpuzzling.com/en/privacy-policy`
   - Application terms of service link: `https://myspeedpuzzling.com/en/terms-of-service`
4. **Authorized domains** → **Add domain** → `myspeedpuzzling.com`.
5. **Developer contact information**: your e-mail.
6. **Save**.

## 4. Search Console + brand verification

Brand verification is what makes the consent screen say "MySpeedPuzzling" (and show the logo). It needs the domain verified in Search Console by an account that is Owner or Editor of this Cloud project, and it can only be submitted once the app is **In production** — so do step 5 first, then come back here.

1. Open <https://search.google.com/search-console> with the same Google account. If `myspeedpuzzling.com` is listed (as a *Domain* property or `https://myspeedpuzzling.com/`) and you are **Owner**, nothing to do. If it is not: **Add property** → **Domain** → `myspeedpuzzling.com` → Google shows a `google-site-verification=…` TXT record → add it at Cloudflare DNS for the apex → **Verify**. (Claude can add the TXT record if you pass it the value.)
2. Back in **Branding**, after publishing (step 5): click **Verify branding**. Google checks: home page reachable and describes the app, privacy policy on the same domain and linked from the home page (it is, in the footer), domains verified, app name not impersonating anyone.
3. When the status says *Ready to publish* → **Publish branding**. (A passed check is valid for 7 days; branding cannot be edited while a check runs.)

## 5. Audience → publish

<https://console.cloud.google.com/auth/audience> (Google Auth Platform → **Audience**)

1. User type: External (from step 2).
2. Publishing status **Testing** → **Publish app** → confirm. Status becomes **In production**.

Leave it in Testing and only listed test users can sign in (and their sign-ins expire after 7 days). Because we ask for non-sensitive scopes only, publishing needs no app verification and shows no "unverified app" warning.

## 6. Data access (scopes)

<https://console.cloud.google.com/auth/scopes> (Google Auth Platform → **Data access**)

1. **Add or remove scopes**.
2. Tick exactly these three (filter the list by typing them):
   - `openid`
   - `.../auth/userinfo.email`
   - `.../auth/userinfo.profile`
3. **Update** → **Save**. They must appear under *Your non-sensitive scopes*; the sensitive and restricted sections stay empty.

The app requests these three anyway; listing them here keeps the consent screen and a future review honest.

## 7. Create the OAuth client — and copy the secret NOW

<https://console.cloud.google.com/auth/clients> (Google Auth Platform → **Clients**)

1. **+ Create client**.
2. Application type: **Web application**. Name: `MySpeedPuzzling Web` (only you see it).
3. **Authorized JavaScript origins**: leave empty (we redirect server-side, no Google JavaScript on the page).
4. **Authorized redirect URIs** → **+ Add URI** → `https://myspeedpuzzling.com/login/social/google/callback`
5. **Create**.
6. A dialog **OAuth client created** opens with **Client ID** (ends in `.apps.googleusercontent.com`) and **Client secret** (starts with `GOCSPX-`), each with a copy icon, plus **Download JSON**. **Copy both now, or click Download JSON.** Since 2025 Google shows the secret *only in this dialog*; afterwards the console shows just its last four characters.
   - Lost it? Open the client → **Add secret** → copy the new one → paste that instead → once production runs on it, **disable** and delete the old secret there.
7. The Client ID stays visible later: Clients list → click `MySpeedPuzzling Web` → *Client ID* on the right.

Google notes that a new or changed redirect URI can take from 5 minutes to a few hours to take effect — a `redirect_uri_mismatch` right after creating the client may just need a coffee.

### Optional: a separate client for local development

Keeps the production secret off your laptop. Same page → **+ Create client** → Web application, name `MySpeedPuzzling Local`, redirect URI `http://localhost:8080/login/social/google/callback` (Google allows plain `http` for `localhost` only; if you start the stack with `WEB_PORT=8090`, add `http://localhost:8090/login/social/google/callback` as well). Put its values in your local `.env.local`:

```
GOOGLE_CLIENT_ID=…
GOOGLE_CLIENT_SECRET=…
SOCIAL_LOGIN_GOOGLE_ENABLED=1
```

## 8. Hand the secrets to Claude

Never paste the secret into a chat.

1. Open (create if missing) `~/.msp-secrets/social-login.env` — a file on your Mac **outside any git repository**, shared by all three providers — and add:

   ```
   GOOGLE_CLIENT_ID=123456789-abc….apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-…
   ```

   (One `KEY=value` per line, no quotes, no spaces around `=`; the app trims stray whitespace anyway. `chmod 600` the file.)
2. Tell Claude: "Google secrets are in ~/.msp-secrets/social-login.env, put them in Infisical and enable Google admin-only."

What the agent then does (procedure: memory `reference_production_access.md`, "Infisical admin from the box"):

1. Pipes the two values over ssh stdin to the box and, with the box admin login, writes `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` to Infisical project **myspeedpuzzling**, environment **prod**, path `/` — **before** any flag changes.
2. Sets `SOCIAL_LOGIN_GOOGLE_ENABLED=1` there. Leaves `SOCIAL_LOGIN_ADMIN_ONLY=1` (repo default; if Infisical holds it, it must stay `1`).
3. Logs out of Infisical on the box, queues a deploy (`/srv/deploy/queue/…job`, `tag=main`) so `dump_secrets` renders the new `.env` and web/api restart.
4. Confirms the running web container sees non-empty values (checks lengths, never prints the secret).

Once that is confirmed you may delete the two lines from `~/.msp-secrets/social-login.env` (Infisical is the source of truth from then on).

## 9. Admin-only test checklist (production)

While `SOCIAL_LOGIN_ADMIN_ONLY=1` the public sees nothing; only admins can use Google, via settings or the direct URL.

1. **Nothing public yet**: open `https://myspeedpuzzling.com/login` in a private window → no Google button.
2. **Connect from settings**: signed in as your admin → **Edit profile** → card **Connected sign-in methods** → white **Continue with Google** button with the coloured G → pick your Google account → back on edit profile with "Connected!" and Google in the list. This works even when the Google address differs from your MySpeedPuzzling e-mail.
3. **Security notice e-mail**: your MySpeedPuzzling address receives "Google sign-in was connected to your MySpeedPuzzling account" within a minute or two.
4. **Sign in via Google**: sign out → open `https://myspeedpuzzling.com/login/social/google` directly → choose the same Google account → you are signed in.
5. **Recent activity**: `https://myspeedpuzzling.com/en/account/recent-activity` shows "Sign-in method connected" and "Signed in with a connected account"; the settings card shows the last-used date.
6. **Cancel at consent**: sign out → open `/login/social/google` → on Google's account chooser/consent click **Cancel** (or close with Back) → you are back on the sign-in page, not signed in, nothing created. Also once from settings: **Continue with Google** → cancel → "Connection cancelled — nothing changed."
7. **Existing account with an unverified e-mail (rule 3)**: needs a second Google account you own (e.g. a spare Gmail). In a private window register a normal MySpeedPuzzling account with that Gmail address and **do not** click the verification link in the e-mail. Sign out → `/login/social/google` → pick that Google account → you must see "There is already an account with this email address, but that address has not been verified yet. … please sign in to that account first …, then connect Google in your profile settings …" and stay signed out. Afterwards delete that test account (Edit profile → Delete my account).
8. **Non-admin is refused**: with a Google account whose e-mail matches a verified *non-admin* account → generic sign-in failure, nothing linked. A Google account that matches no account at all → also refused (sign-ups are off in the admin-only stage).
9. **Disconnect**: settings → disconnect Google → it disappears; reconnect it again if you want to keep it.

Anything wrong → rollback below, tell Claude what you saw (and the time, so the logs can be found).

## 10. Public launch

1. `SOCIAL_LOGIN_ADMIN_ONLY` is **one switch for all providers**: setting it to `0` makes *every enabled* provider public. So flip it only when every provider with `SOCIAL_LOGIN_*_ENABLED=1` has passed its checklist (or disable the ones that have not).
2. Claude sets `SOCIAL_LOGIN_ADMIN_ONLY=0` in Infisical and redeploys.
3. Check `/login` and `/register` in a private window: the white "Continue with Google" button is there (the pages are cached for up to 60 s, so give it a minute). One sign-up with a fresh Google account → confirmation page "Create a new account with …?" → account created and signed in.

## Rollback

Set `SOCIAL_LOGIN_GOOGLE_ENABLED=0` in Infisical and redeploy. The button, the start route and the callback all go away at once. Linked identities stay in the database and work again when the flag returns; players who only ever used Google can still get in with the e-mailed sign-in link or by resetting a password. Rolling back only the public stage = `SOCIAL_LOGIN_ADMIN_ONLY=1`.

## Gotchas

- `redirect_uri_mismatch`: the URI must match character for character — `https`, no `www`, no `/en`, no trailing slash. Also wait a few minutes after editing it.
- `invalid_client`: wrong or old secret (e.g. a disabled one) — check the last four characters on the client page against Infisical.
- The button follows Google's [Sign in with Google branding guidelines](https://developers.google.com/identity/branding-guidelines) — white fill, `#747775` border, `#1F1F1F` text, the unmodified four-colour G (`templates/_social_provider_button.html.twig` — the one partial for every provider button on `/login`, `/register` and in settings — `assets/styles/components/_social-signin.scss`, pinned by `tests/Security/SocialProviderButtonsTest.php`). Never a monochrome G, never a dark hover. Google asks for the Google Sans font; we use it (or Roboto) only when the device already has it, to avoid a font download.
- We trust Google's `email_verified === true` for every domain (Gmail and Workspace alike) — owner decision 2026-09-29, see `README.md` §Hardening 2026-09-29.
- The app always requests `openid email profile` with PKCE (S256) on top of the client secret (`GoogleProviderWithPkce`, built by `SocialLoginProviders`).
