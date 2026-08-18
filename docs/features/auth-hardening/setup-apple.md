# Social login setup — Apple

Step-by-step console setup for "Continue with Apple" (auth hardening PR 2, #175). Code side is done and deployed dark; this guide covers the Apple Developer console and Infisical. Prerequisite: the paid Apple Developer Program membership (✔ you have it).

**Do this one last** — it has the most moving parts, and unlike Google/Facebook it **cannot be tested on localhost at all**: Apple requires https and a registered domain for return URLs. The `SOCIAL_LOGIN_ADMIN_ONLY` stage exists exactly for this — you verify Apple directly in production, invisibly to everyone else.

## Values you will need / will produce

| What | Value |
|---|---|
| Production return URL | `https://myspeedpuzzling.com/login/social/apple/callback` |
| `APPLE_CLIENT_ID` | the **Services ID** identifier you create below (e.g. `com.myspeedpuzzling.web`) — NOT the App ID |
| `APPLE_TEAM_ID` | 10-char Team ID from the membership page |
| `APPLE_KEY_ID` | 10-char Key ID of the Sign in with Apple key |
| `APPLE_PRIVATE_KEY` | full content of the downloaded `.p8` file |
| Flag | `SOCIAL_LOGIN_APPLE_ENABLED` |

All console work happens in **Certificates, Identifiers & Profiles**: <https://developer.apple.com/account/resources/identifiers/list>.

## 1. App ID (the anchor)

Sign in with Apple for web hangs off a *primary App ID*, even when there is no iOS app.

1. Identifiers → **+** → **App IDs** → type **App**.
2. Description `MySpeedPuzzling`, Bundle ID **explicit**: `com.myspeedpuzzling.app`.
3. In Capabilities, tick **Sign In with Apple** (leave "Enable as a primary App ID" selected).
4. Register.

## 2. Services ID (this is the client id)

1. Identifiers → **+** → **Services IDs**.
2. Description `MySpeedPuzzling Web`, identifier `com.myspeedpuzzling.web` → **this exact string is `APPLE_CLIENT_ID`**.
3. Register, then open it again and enable **Sign In with Apple** → **Configure**:
   - Primary App ID: the App ID from step 1
   - **Domains and Subdomains**: `myspeedpuzzling.com`
   - **Return URLs**: `https://myspeedpuzzling.com/login/social/apple/callback`
4. Save. If the console asks to verify the domain (older flows serve a file at `/.well-known/apple-developer-domain-association.txt`), say so and we'll add the file to `public/.well-known/` — current flows usually skip this for return URLs.

## 3. Signing key (.p8)

1. **Keys** → **+** → name `MySpeedPuzzling Sign in with Apple`.
2. Tick **Sign In with Apple** → Configure → primary App ID from step 1.
3. Register → **Download the `.p8` now — this is a ONE-TIME download.** Store it in the password manager as well. Note the **Key ID** shown next to it (`APPLE_KEY_ID`).
   - If it is ever lost: create a new key, update `APPLE_KEY_ID` + `APPLE_PRIVATE_KEY`, revoke the old one. No user impact.
4. **Team ID**: <https://developer.apple.com/account> → Membership details → 10-character Team ID (`APPLE_TEAM_ID`).

## 4. Private-relay email registration — DO NOT SKIP

Users who pick **"Hide My Email"** get an `@privaterelay.appleid.com` address, which becomes their MySpeedPuzzling account email. Apple's relay **only forwards mail from senders registered in the console** — skip this and every verification email, sign-in link, notification and newsletter to those users **silently bounces**.

1. Certificates, Identifiers & Profiles → **Services** → **Sign in with Apple for Email Communication** → Configure.
2. Register the sending domain: `mail.myspeedpuzzling.com` (our transactional From domain). Apple checks its SPF — it must pass (it does; Seznam SPF is in place).
3. If the console also offers individual email address registration, add the transactional From addresses used in production.
4. Wait for the green SPF check next to the domain before going live.

## 5. Secrets + flag flip (Infisical)

1. `APPLE_CLIENT_ID=com.myspeedpuzzling.web`, `APPLE_TEAM_ID`, `APPLE_KEY_ID` — plain strings.
2. `APPLE_PRIVATE_KEY` — the **entire** `.p8` content including the `-----BEGIN/END PRIVATE KEY-----` lines. Multi-line is fine; a single line with literal `\n` sequences is also fine (the app converts them back — `AppleProviderWithInlineKey`). Mind the box `.env` single-quote gotcha if it ever ends up there instead of Infisical.
3. `SOCIAL_LOGIN_APPLE_ENABLED=1`, leave `SOCIAL_LOGIN_ADMIN_ONLY=1`. Redeploy/restart web.

## 6. Admin-only verification checklist (production only)

Same drill as Google (`setup-google.md` §5) with `https://myspeedpuzzling.com/login/social/apple`. Apple-specific extras:

- Test **"Hide My Email"** once: sign in with relay email, then confirm an email actually arrives through the relay (this proves §4 end-to-end). Use a throwaway non-admin path check too: non-admin → generic failure.
- **Name and email arrive only on the very FIRST authorization.** To re-run a "first time" flow after a botched test: <https://account.apple.com> (or Settings on an Apple device) → Sign in with Apple / Apps using Apple ID → MySpeedPuzzling → **Stop using Apple ID** — the next sign-in counts as first again.
- The callback is a POST from `appleid.apple.com` (not a redirect like the others) — if a WAF/proxy rule ever blocks cross-site POSTs to `/login/social/apple/callback`, this is the symptom to remember.

Apple verified → all three providers done → flip `SOCIAL_LOGIN_ADMIN_ONLY=0` for the public launch. Rollback anytime = `SOCIAL_LOGIN_APPLE_ENABLED=0`.

## Gotchas recap

- `APPLE_CLIENT_ID` is the **Services ID**, not the App ID — the single most common misconfiguration.
- The `.p8` signs an ES256 client-secret JWT on every token exchange (the league provider does this); wrong Key ID / Team ID / key content shows up as `invalid_client` from Apple's token endpoint (visible in Sentry via the "Social login code exchange failed" log).
- Relay users who later unlink Apple keep their relay email as account email — the ≥1-sign-in-method invariant forces them to set a password first, but if they *stop* the relay at Apple, that address goes dead; nothing we can do beyond the existing change-email flow.

Docs: <https://developer.apple.com/documentation/signinwithapplerestapi>, web configuration: <https://developer.apple.com/documentation/signinwithapple/configuring-your-environment-for-sign-in-with-apple>.
