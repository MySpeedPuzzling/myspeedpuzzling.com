# Social login setup — Facebook (Meta)

Step-by-step console setup for "Continue with Facebook" (auth hardening PR 2, #175). Code side is done and deployed dark; this guide covers the Meta developer console and Infisical.

**Do this one second**, after Google.

One thing up front: **Instagram sign-in does not exist anymore** — Meta shut down the Instagram Basic Display API in December 2024 and Instagram has no identity provider. Facebook Login is Meta's offering. The MySpeedPuzzling Instagram page cannot be used for auth; the **Facebook page** is useful though — link it to the app (and to a Meta Business portfolio if asked) for trust and as the app's public face.

## Values you will need

| What | Value |
|---|---|
| Production redirect URI | `https://myspeedpuzzling.com/login/social/facebook/callback` |
| Local dev | works automatically while the app is in Development Mode — Meta exempts `localhost` from the redirect allowlist there |
| Permissions the app requests | `public_profile`, `email` (both default-access — **no App Review needed**) |
| Privacy policy URL | `https://myspeedpuzzling.com/en/privacy-policy` |
| Terms URL | `https://myspeedpuzzling.com/en/terms-of-service` |
| Env vars (Infisical) | `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`, `SOCIAL_LOGIN_FACEBOOK_ENABLED` |

## 1. Create the app

1. Open <https://developers.facebook.com/apps/> (log in with the account that admins the MySpeedPuzzling Facebook page).
2. **Create app**. Meta's wizard changes wording every year; the thing to pick is the **"Authenticate and request data from users with Facebook Login"** use case (older UIs call this a *Consumer* app type). Avoid the business-asset/marketing use cases.
3. App name `MySpeedPuzzling`, contact email. If the wizard offers to connect a **business portfolio**, connecting the one that owns the Facebook page is fine (and helps credibility); it is not required for `email` + `public_profile`.

## 2. Basic settings

**App settings → Basic**:

- **App domains**: `myspeedpuzzling.com`
- **Privacy policy URL** and **Terms of service URL**: the two links above (both are required before the app can go Live)
- **User data deletion**: choose *Data deletion instructions URL* and point it at `https://myspeedpuzzling.com/en/privacy-policy` (the policy describes account deletion; users delete themselves in profile settings — full GDPR wipe including the linked identity)
- **App icon** (1024×1024) + **Category** (e.g. *Entertainment* or *Lifestyle*)
- **Website**: add platform *Website* with `https://myspeedpuzzling.com` if the console asks for a platform

## 3. Facebook Login settings

**Products → Facebook Login → Settings** (add the product first if the use-case wizard did not):

- Client OAuth login: **ON**
- Web OAuth login: **ON**
- Enforce HTTPS: **ON**
- **Valid OAuth Redirect URIs**: `https://myspeedpuzzling.com/login/social/facebook/callback`
- Everything else (embedded browser, device login, deauthorize callback) stays off/empty.

## 4. Permissions

Nothing to do: `public_profile` and `email` have default access and skip App Review entirely. You can confirm under **App Review → Permissions and features** — both should show as available/granted automatically.

## 5. Go Live

Flip the **App Mode** toggle (top bar) from Development to **Live**. The console will refuse until §2 is complete (privacy policy + data deletion). Business verification is **not** required for these permissions.

## 6. Secrets + flag flip (Infisical)

1. **App settings → Basic**: copy **App ID** and **App secret** (Show → re-enter password).
2. Infisical: `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`, then `SOCIAL_LOGIN_FACEBOOK_ENABLED=1`. Leave `SOCIAL_LOGIN_ADMIN_ONLY=1`.
3. Redeploy/restart web.

## 7. Admin-only verification checklist

Same drill as Google (see `setup-google.md` §5), with the direct URL `https://myspeedpuzzling.com/login/social/facebook`. Facebook-specific extras to verify:

- On the consent dialog, use **"Edit access"** and *uncheck* the email permission once → MySpeedPuzzling must refuse with the "did not share an email address" message. That is expected product behavior, not a bug.
- Connect from settings with a Facebook account whose email differs from the admin account email (rule 5 — must link fine).

## Gotchas

- The Graph API version is pinned to `v23.0` in `src/Services/SocialLogin/SocialLoginProviders.php`. Meta retires versions after ~2 years; when the deprecation emails arrive, bump the constant there — one-line change.
- Some users legitimately have **no** email on their Facebook account (phone-only signups) — they get the same "did not share an email" refusal. The rescue is the normal email sign-in link.
- The app secret is shown only after password re-auth; it can be reset in the console (rotating it = update Infisical + restart, old sessions stay valid — the secret is only used server-side for the code exchange).

Docs: <https://developers.facebook.com/docs/facebook-login/web>, permission reference: <https://developers.facebook.com/docs/permissions>.
