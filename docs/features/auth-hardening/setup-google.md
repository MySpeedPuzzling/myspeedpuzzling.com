# Social login setup — Google

Step-by-step console setup for "Continue with Google" (auth hardening PR 2, #175). Code side is done and deployed dark; this guide covers only what must happen in the Google Cloud console and Infisical.

**Do this one first** — it is the easiest of the three and validates the whole pipeline (flags → Infisical → admin-only test) before Facebook and Apple.

## Values you will need

| What | Value |
|---|---|
| Production redirect URI | `https://myspeedpuzzling.com/login/social/google/callback` |
| Local dev redirect URI | `http://localhost:8080/login/social/google/callback` |
| Scopes the app requests | `openid`, `email`, `profile` (non-sensitive — no Google review needed) |
| Env vars (Infisical) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `SOCIAL_LOGIN_GOOGLE_ENABLED` |

## 1. Create / pick the Google Cloud project

1. Open <https://console.cloud.google.com/> and sign in with the Google account that should own the credentials long-term.
2. Create a project (top bar → project picker → **New project**), name it `MySpeedPuzzling`. No billing needed for OAuth.

## 2. Configure the consent screen (Google Auth Platform → Branding)

Google moved OAuth setup under **Google Auth Platform** (formerly "OAuth consent screen"). Direct link: <https://console.cloud.google.com/auth/branding>.

1. If it is the first time: **Get started** wizard — App name `MySpeedPuzzling`, user support email, audience **External**, developer contact email.
2. Branding page:
   - **Authorized domain**: `myspeedpuzzling.com`
   - **Privacy policy**: `https://myspeedpuzzling.com/en/privacy-policy`
   - **Terms of service**: `https://myspeedpuzzling.com/en/terms-of-service`
   - **Logo**: skip it for now. Uploading a logo triggers Google's brand verification review; without a logo, basic-scope apps go live with zero review. Add it later if the plain consent screen bothers you.
3. **Audience** page: publish the app (**Testing → In production**). With only `openid/email/profile` this needs no verification — the "unverified app" warning applies to sensitive scopes, which we do not request.

## 3. Create the OAuth client

Direct link: <https://console.cloud.google.com/auth/clients> (or APIs & Services → Credentials → Create credentials → OAuth client ID).

1. Type: **Web application**, name `MySpeedPuzzling Web`.
2. **Authorized redirect URIs** — add both:
   - `https://myspeedpuzzling.com/login/social/google/callback`
   - `http://localhost:8080/login/social/google/callback` (Google explicitly allows http for localhost — this makes local testing possible)
3. Authorized JavaScript origins: leave empty (server-side redirect flow, no JS SDK).
4. Create → copy the **Client ID** and **Client secret** immediately.

## 4. Secrets + flag flip (Infisical)

1. In Infisical set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`.
2. Set `SOCIAL_LOGIN_GOOGLE_ENABLED=1`. Leave `SOCIAL_LOGIN_ADMIN_ONLY=1`.
3. Redeploy/restart web so the workers pick up the new env.

## 5. Admin-only verification checklist

While `SOCIAL_LOGIN_ADMIN_ONLY=1` nothing renders publicly — that is by design; you test via direct URLs:

1. `/login` and `/register` still show **no** Google button (cache stays uniform). ✔️
2. Logged in as your admin account → edit profile → the **Connected sign-in methods** card is visible (admins only) → **Connect Google**. Works even if your Google email differs from the account email (rule 5).
3. Log out → visit `https://myspeedpuzzling.com/login/social/google` directly → consent → you land signed in on your profile.
4. `/en/account/recent-activity` shows a "Signed in with a connected account" event; the settings card shows the linked identity with last-used date.
5. Negative test: repeat step 3 with a Google account belonging to a **non-admin** test account → generic sign-in failure, nothing linked, nothing created.
6. Disconnect/reconnect once to exercise unlink.

Google done — leave `SOCIAL_LOGIN_ADMIN_ONLY=1` until Facebook and Apple are verified too, then flip it to `0` once for the public launch (see the rollout section in `README.md`). Rollback at any point = `SOCIAL_LOGIN_GOOGLE_ENABLED=0`.

## Gotchas

- The button wording/logo in our templates follows <https://developers.google.com/identity/branding-guidelines> — don't restyle it casually.
- The consent screen shows the project's support email; use a role address, not a personal one.
- If Google ever shows "redirect_uri_mismatch": the URI must match **character-for-character** including scheme and no trailing slash.
