# Plan: "Continue with Microsoft"

Status: **plan only, nothing implemented** (2026-09-29). Owner approved planning it; passkeys are deferred (2FA stays
delegated to the social providers). Design authority for everything not repeated here: [`README.md`](README.md)
(Workstream B, the five linking rules, §Hardening 2026-09-29). Console click-path for the owner:
[`setup-microsoft.md`](setup-microsoft.md).

## Why

Production account e-mails: ~63.5 % Gmail, **~13 % Microsoft consumer mailboxes** (hotmail / outlook / live / msn and
their country variants such as `hotmail.cz`, `outlook.cz`, `hotmail.co.uk`). Microsoft is the second-largest
mailbox provider among our players, bigger than any provider we already offer apart from Google. Every one of those
players has a personal Microsoft account (the mailbox *is* the account), so a Microsoft button gives them sign-in
with nothing to remember.

## Decisions at a glance

| # | Question | Recommendation |
|---|---|---|
| D1 | Tenant / endpoint | **`/consumers` only** (personal Microsoft accounts); app registered as "Personal accounts only". No work/school accounts. |
| D2 | Which claim is the identity | **`oid`** of the consumer tenant (stable across app registrations), `sub` kept as a fallback check. Never the e-mail. |
| D3 | E-mail trust | Trusted (rule 2 may auto-link) **only for Microsoft-owned consumer mailbox domains** (allowlist); any other address on a Microsoft account (e.g. a Gmail used as MSA username) counts as **unverified** → rule 3 refuses auto-link, rule 4 creates an unverified account + verification mail. |
| D4 | Library | **No new provider package.** league `GenericProvider` (already installed, honours `pkceMethod`) + our own id_token check against Microsoft's JWKS with `firebase/php-jwt` (already used by `AppleServerNotificationVerifier`). Reject `stevenmaguire/oauth2-microsoft` (dead Live API) and `thenetworg/oauth2-azure` (see §Library). |
| D5 | PKCE | **Yes, S256** (via GenericProvider's `pkceMethod` option — the one place league honours it). |
| D6 | Scopes | `openid profile email` — no `offline_access`, no Graph `User.Read`. |
| D7 | Credential | **Client secret, 24 months**, plus an expiry date in env and a warning 30 days before (§Secret rotation). Certificate credential is the upgrade path, not v1. |
| D8 | Publisher verification | **Not needed and not reachable for v1** (requires a Partner Center business account and a work-account-registered app). Accept the "unverified" line on Microsoft's consent screen. Configure the publisher domain anyway. |
| D9 | Button | Microsoft **light theme**: white, 1 px `#8C8C8C` border, `#5E5E5E` text, unmodified four-square logo. Text "Continue with Microsoft" (house wording) + a small hint "Outlook.com, Hotmail, Live, Xbox". |
| D10 | Order | Google, Apple, **Microsoft**, Facebook (Facebook stays last because of its hint line). Open question Q3. |
| D11 | Availability | Follow the new rule another agent is introducing: **available iff credentials are configured**, no Microsoft feature flag. |
| D12 | Rollout | Test the whole flow **on localhost** with a separate dev app registration (Microsoft allows `http://localhost` redirect URIs — unlike Apple), then put production credentials into Infisical = public launch, smoke-test within minutes, rollback = remove the credentials. |

---

## 1. Endpoint, tenant and claims (D1–D3)

### Research (learn.microsoft.com, checked 2026-09-29)

- Authority `https://login.microsoftonline.com/{tenant}/v2.0`; `{tenant}` = `common` (work/school **and** personal),
  `organizations` (work/school only), `consumers` (personal only) or a tenant id.
  Source: *OpenID Connect on the Microsoft identity platform* (`v2-protocols-oidc`).
- Personal-account tokens carry `tid` = **`9188040d-6c67-4c5b-b112-36a304b66dad`**, and the v2.0 issuer is
  `https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0`. Microsoft: "Your app should use
  the GUID portion of the claim to restrict the set of tenants that can sign in to the app."
  Source: *ID token claims reference*.
- `sub`: "a pairwise identifier and is unique to an application ID. If a single user signs into two different apps
  using two different client IDs, those apps receive two different values."
- `oid`: "The immutable identifier for an object … two different applications signing in the same user receives the
  same value in the `oid` claim." Requires the `profile` scope.
- `email`: "This value isn't guaranteed to be correct and is mutable over time. **Never use it for authorization or to
  save data for a user.**" Supported for MSA (optional-claims reference, User Type "MSA, Microsoft Entra ID").
- `xms_edov` (optional claim, "email domain owner verified") is documented for tenant users and guests; it is not
  documented as emitted for `/consumers` sign-ins. `verified_primary_email` / `verified_secondary_email` come from an
  Entra user's authoritative e-mail — not applicable to personal accounts.
- "nOAuth" (Descope 2023, Semperis 2025): an attacker sets any `mail` attribute on a user in *their own* Entra tenant,
  and apps that key or link on `email` from `/common` hand over the victim's account. Microsoft's answer: key on
  `sub` (or `oid`+`tid`), never on e-mail; since June 2023 new multi-tenant apps get unverified-domain e-mails
  removed by default, but the guidance stands.

### D1 — consumers only

`/consumers` + registration type "Personal accounts only":
- removes the whole nOAuth class (no Entra tenant can mint a token for our app at all);
- matches the audience — the 13 % are consumer mailboxes; work accounts are the employer's, and a puzzle hobby
  account tied to an employer's directory disappears when the job ends;
- Microsoft's own sign-in page tells a user who types a work address that it can't be used here.

Defence in depth in our code: reject any id_token whose `tid` ≠ `9188040d-…` or whose `iss` ≠ the consumer issuer,
even though Microsoft should never send one.

### D2 — identity key

Store **`oid`** as `oauth_identity.provider_user_id`, and assert `sub` is present. Reason: `sub` is pairwise per
*app registration*. An app registration cannot be moved between tenants ("Once created, you can't move the application
object between different tenants" — *Register an app*). If we ever have to re-create the registration (lost access to
the personal Default Directory, moving to a company tenant for publisher verification, an accidental delete), every
`sub` changes and every Microsoft user would fall through to rule 2/4 — duplicate accounts. The consumer-tenant `oid`
is the same for every app, so it survives. Microsoft explicitly allows "`sub` or `oid` alone". Privacy cost (another
app could correlate the same `oid`) is irrelevant for us — we never share it.

Still: **never delete the app registration** — also written in the setup guide.

### D3 — e-mail trust

The house policy is "trust the provider e-mail unless the provider says it is unverified" (README §Hardening). Microsoft
*does* say so, globally: the claim "isn't guaranteed to be correct". A personal Microsoft account can be created with
any existing address as its user name (Microsoft sends a code today, but documents no guarantee and older accounts
predate that). Recommendation:

- **Trusted** (`emailVerified = true`, rule 2 may auto-link): the address's domain is on an explicit allowlist of
  Microsoft-owned consumer mailbox domains (`outlook.com`, `hotmail.com`, `live.com`, `msn.com`, `passport.com`,
  `windowslive.com` + the country variants we actually see). For these the Microsoft account **is** the mailbox, so
  the e-mail is as good as Google's `email_verified` for Gmail.
- **Untrusted** (`emailVerified = false`): anything else (e.g. `someone@gmail.com` registered as a Microsoft account
  user name) and a missing claim. Rule 3 refuses auto-link with the existing "{provider} has not confirmed the address"
  message; rule 4 creates the account unverified and sends the verification mail (existing behaviour for untrusted
  provider e-mails). Settings-connect (rule 5) is unaffected.
- **Allowlist source:** generate it once from production (`SELECT split_part(email,'@',2) … GROUP BY 1` filtered by
  `hotmail|outlook|live|msn|passport|windowslive`), keep only domains whose MX is Microsoft's consumer mail
  (`*.olc.protection.outlook.com`), and put the result in a constant with a comment. Exact-match, lowercase. A regex
  like `^(hotmail|outlook|live)\.` is **not** acceptable: `outlook.xyz` is not Microsoft's.
- Optional, cheap: also request the `xms_edov` optional claim in the app manifest and log it (info) during the admin
  test. If Microsoft turns out to emit `xms_edov: true` for personal accounts, a follow-up can widen trust to it.
- A missing `email` claim (phone-number-only Microsoft accounts) → existing `no_email` path.

This loses nothing for the target group (every one of the 13 % is on the allowlist) and closes the only realistic
takeover path.

## 2. Library (D4, D5)

| Option | Verdict |
|---|---|
| `stevenmaguire/oauth2-microsoft` | **No.** Last release 2.2.0 (June 2017); talks to the retired Live Connect API (`login.live.com` / `apis.live.net`), not the identity platform v2.0. |
| `thenetworg/oauth2-azure` (v2.2.6, 2026-06) | Maintained, but: defaults to the v1.0 endpoint; fetches the discovery document **and** the JWKS over HTTP on every sign-in (per-object memo only, and our providers are built per request) — even the start route makes a network call; if the id_token has no signature segment it decodes the claims *without* verification; no nonce check; its `getResourceOwner()` reads id_token claims we then must re-check anyway. Adds a dependency for little. |
| **league `GenericProvider` + own id_token check** | **Chosen.** Already installed; `GenericProvider` is the one league class that honours the `pkceMethod` option (see `GoogleProviderWithPkce` for why the others don't). Endpoints are fixed constants for `/consumers`, so no discovery call. The token endpoint returns the `id_token` in the same response (`AccessToken::getValues()['id_token']`) — no userinfo call, no Graph token. |

`GenericProvider` configuration (in `SocialLoginProviders::create()`):

```
urlAuthorize            https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize
urlAccessToken          https://login.microsoftonline.com/consumers/oauth2/v2.0/token
urlResourceOwnerDetails https://graph.microsoft.com/oidc/userinfo   (required by the class, never called)
scopes                  ['openid', 'profile', 'email'],  scopeSeparator ' '
pkceMethod              S256
```

id_token validation — new `MicrosoftIdTokenVerifier` (`src/Services/SocialLogin/`), modelled on
`AppleServerNotificationVerifier`:
- keys from `https://login.microsoftonline.com/consumers/discovery/v2.0/keys`, cached in `social_login_state_cache`
  (24 h TTL, refetch only for an unknown `kid`, at most every 5 min — same numbers as Apple);
- RS256 only; `aud` = `MICROSOFT_CLIENT_ID`; `iss` = consumer issuer; `tid` = `9188040d-…`; `exp`/`nbf`/`iat` with
  60 s leeway (firebase `JWT::$leeway`); `oid` and `sub` non-empty strings;
- returns the claims; `SocialProfileFetcher` builds `SocialUserProfile` (`providerUserId = oid`, `email`,
  `emailVerified` per D3, `name` from `name`).
- Worth doing as part of this work: extract the JWKS fetch-and-cache part of `AppleServerNotificationVerifier` into a
  small shared `CachedJwks` service used by both (keeps one tested implementation of the refetch throttle).

Why verify the signature at all when the token came straight from Microsoft's token endpoint over TLS (OIDC Core
§3.1.3.7 allows skipping it)? Defence in depth for the cost of one cached HTTP call per day, and it makes the
`tid`/`iss` assertions meaningful. `firebase/php-jwt` becomes a **direct** `composer.json` requirement (today it is
transitive via `patrickbussmann/oauth2-apple`).

Nonce: not used. In the code flow the id_token is fetched by our server with our PKCE verifier, so it cannot be
injected; OIDC makes `nonce` optional for the code flow. (If a reviewer insists: derive it from the state value, no
storage needed.)

Extra authorize parameter (`SocialLoginProviders::authorizationOptions()`): **`prompt=select_account`**, so someone
with several Microsoft accounts (personal + a family one is common) always gets the account picker instead of being
silently signed in with whichever one the browser remembers.

Cancel handling: Microsoft returns `error=access_denied` on cancel → already mapped to `ProviderCancelled` by
`SocialLoginFailureReason::fromProviderError()`. Callback is a plain GET (`response_mode` = query, the default for
the code flow), so cookies arrive and nothing Apple-specific is needed.

Expired secret: the token endpoint answers `invalid_client` with `AADSTS7000222` in `error_description`. Log that one
at **error** with an explicit "Microsoft client secret expired" message (it is an outage, not a user problem).

## 3. App registration (owner's console work) — summary

Full click-path in [`setup-microsoft.md`](setup-microsoft.md). Key facts:

- **A tenant is required.** Since June 2024 Microsoft no longer lets a personal account register apps "outside of a
  directory". Options: (a) a Microsoft Entra tenant the owner already has (company Microsoft 365?), (b) sign up for a
  free Azure account with the personal Microsoft account → a "Default Directory" (Entra ID Free) is created; needs a
  card + phone for identity verification, costs nothing, and the app registration does not depend on the Azure
  subscription staying active. The Microsoft 365 Developer Program sandbox is **not** suitable (restricted
  eligibility, sandbox tenants expire). → Open question Q1.
- Supported account types: **"Personal accounts only"** (older UI label: "Personal Microsoft accounts only").
- Platform **Web**, redirect URI `https://myspeedpuzzling.com/login/social/microsoft/callback` (no `/en`, no trailing
  slash). "ID tokens (implicit/hybrid)" checkbox stays **off** — the code flow gets the id_token from the token
  endpoint.
- Client secret: max 24 months in the portal (Microsoft recommends < 12). Value shown **once**.
- API permissions: remove the default Graph `User.Read`, add delegated `openid`, `email`, `profile`.
- Branding: name "MySpeedPuzzling", logo, home page, terms, privacy URLs; **publisher domain** `myspeedpuzzling.com`
  via `https://myspeedpuzzling.com/.well-known/microsoft-identity-association.json` (served with
  `Content-Type: application/json`, may be removed after verification — keeping it is harmless).
- **Publisher verification** (blue "verified" badge): requires a verified Microsoft AI Cloud Partner Program (Partner
  Center) account for a business, an app registered with a *work/school* account in a tenant with a verified custom
  domain, and MFA. "Apps that are registered by using a Microsoft account can't be publisher verified." For
  multi-tenant apps registered after 30 Nov 2020 the consent screen therefore shows **"unverified"** instead of a
  domain. Sign-in still works and requests only basic sign-in scopes, so the risk-based step-up block (which applies to
  permissions *beyond* basic sign-in and profile) does not trigger. Recommendation: launch unverified, revisit if the
  consent-screen drop-off is visible in the audit log (cancel rate). → Q2.

## 4. Button (D9, D10)

Microsoft's "Sign in with Microsoft" branding guidelines (learn.microsoft.com, official SVGs checked 2026-09-29):
- light theme: background `#FFFFFF`, 1 px border `#8C8C8C`, text `#5E5E5E`, height 41 px at the reference size,
  Segoe UI Semibold; dark theme: background `#2F2F2F`, text `#FFFFFF`;
- logo: four 9 × 9 squares with a 1 px gap — `#F25022` (top-left), `#7FBA00` (top-right), `#00A4EF` (bottom-left),
  `#FFB900` (bottom-right) — "DON'T alter the Microsoft logo";
- allowed text: "Sign in with Microsoft" or short "Sign in". "Continue with Microsoft" is not listed. We use the house
  wording "Continue with …" for every provider (Apple permits only Sign in / Sign up / Continue with; Google and
  Meta list Continue with). Known, small deviation, same kind as the Apple title-size one in README §UI. → Q4.

Implementation: `microsoft` branch in `_social_provider_logo.html.twig` (the SVG above, inline, 21 × 21 viewBox),
`.btn-microsoft-signin` + `.social-identity-badge-microsoft` in `_social-signin.scss` (light theme only — the site has
no dark mode), `font-family: "Segoe UI", <site stack>` (use it when present, never download it — same rule as Google
Sans). Hint under the button, like Facebook's: **`auth.social.microsoft_hint`** = "Outlook.com, Hotmail, Live or Xbox
account" — people with a Hotmail address often do not know it is a "Microsoft account".

Order on `/login`, `/register` and in settings: Google, Apple, Microsoft, Facebook.

## 5. Code touch-points

Everything else (rules 1–5, interstitial, connect binding, security notice mail, audit events, unlink invariant,
failure messages) is provider-agnostic and needs nothing.

| Area | Change |
|---|---|
| `src/Value/OauthProvider.php` | `case Microsoft = 'microsoft'`; `displayName()` → `'Microsoft'`. No migration: `oauth_identity.provider` is a string column. |
| `src/Services/SocialLogin/SocialLoginProviders.php` | constructor args `$microsoftClientId`, `$microsoftClientSecret`; `create()` → `GenericProvider` (§2); `authorizationOptions()` → `['prompt' => 'select_account']`; `microsoftClientId()` getter for the audience check. |
| `src/Services/SocialLogin/MicrosoftIdTokenVerifier.php` (new) + optional `CachedJwks` extraction | §2. |
| `src/Services/SocialLogin/MicrosoftConsumerMailDomains.php` (new, or a constant on the verifier) | allowlist for D3. |
| `src/Services/SocialLogin/SocialProfileFetcher.php` | `Microsoft` branch before `getResourceOwner()` (like Apple): read `id_token` from the token values, verify, map. |
| `src/Security/MicrosoftLoginAuthenticator.php` (new) | 10-line subclass like `GoogleLoginAuthenticator`. |
| `config/packages/security.php` | append it to `custom_authenticators` on `main`. |
| `src/Controller/SocialRegisterConfirmController.php` | `match` arm → `MicrosoftLoginAuthenticator::class`. |
| `src/Services/SocialLogin/SocialLoginSettings.php` | availability: whatever the other agent's "available iff credentials configured" refactor looks like — Microsoft = both `MICROSOFT_CLIENT_ID` and `MICROSOFT_CLIENT_SECRET` non-empty. If the refactor has not landed, add it in the new style, never a `SOCIAL_LOGIN_MICROSOFT_ENABLED` flag. |
| `config/services.php`, `.env`, `config/packages/twig.php` | bind `$microsoftClientId`/`$microsoftClientSecret` (`%env(trim:string:…)%`), empty defaults in `.env`, `MICROSOFT_CLIENT_SECRET_EXPIRES_AT=` (§6); Twig availability global per the refactor. |
| `templates/_social_login_buttons.html.twig` | Microsoft button + hint between Apple and Facebook. |
| `templates/_social_provider_button.html.twig` | `provider_name` map (better: pass `OauthProvider::displayName()` so it cannot drift again). |
| `templates/_social_provider_logo.html.twig` | `microsoft` artwork. |
| `templates/edit-profile.html.twig` | the `social_provider_enabled` / `provider_names` maps (lines ~256–257). |
| `assets/styles/components/_social-signin.scss` | `.btn-microsoft-signin`, `.social-identity-badge-microsoft`. |
| Translations (auth UI = 6 locales) | `auth.social.microsoft_hint` in `messages.{en,cs,de,es,fr,ja}.yml`. `continue_with` and every failure/notice string already take `%provider%`. |
| Privacy policy (`privacy_policy.*` in `messages.*.yml`, 6 locales) | add Microsoft to: "we never see your … password"; the disconnect list — **Microsoft:** <https://account.live.com/consent/Manage> (Apps and services you've given access → Edit → Remove these permissions); the independent controllers list — **Microsoft (Microsoft Ireland Operations Limited)**: <https://privacy.microsoft.com/privacystatement>. |
| Data-deletion page (`data_deletion.content`, 6 locales) | sections 4 and 5 ("Only disconnect Google, Facebook or Apple" → add Microsoft, same consent link). |
| FAQ / getting-started mentions of the providers | grep `Google, Facebook or Apple` across translations and update. |
| `public/.well-known/microsoft-identity-association.json` | `{"associatedApplications":[{"applicationId":"<client id>"}]}` — needs the client id from the owner first; check the bot-blocker and Caddy serve it as `application/json` (bot-blocker lesson from Google brand verification). |
| Docs | README (provider list, gotchas, env vars), `feature_flags.md` if any gate is added, `docs/TODO.md`, CLAUDE.md feature pointer ("Google/Apple/Facebook" → add Microsoft). |

### Tests

- `SocialLoginFlowTest` (mocked Guzzle like the Google cases; the id_token is signed in-test with a throwaway RSA key
  whose JWK is served by the mocked JWKS response):
  - rule 1: known `oid` logs in (and a **different `sub` with the same `oid`** still logs in — pins D2);
  - rule 2: `@outlook.com` e-mail matching a verified account → auto-link + notice mail;
  - rule 3: `@gmail.com` e-mail on a Microsoft account matching an account → refused, "not confirmed" message;
  - rule 4: unknown `@hotmail.cz` → interstitial → account created **verified**; unknown `@gmail.com` → created
    **unverified** + verification mail;
  - no `email` claim → `no_email`;
  - `access_denied` → cancelled message.
- `MicrosoftIdTokenVerifierTest`: wrong `aud`, wrong `iss`, work-tenant `tid`, expired, unknown `kid` → refetch once,
  refetch throttle, `alg: none`/HS256 rejected, missing `oid`.
- `SocialLoginPkceTest`: Microsoft authorize URL carries `code_challenge` + `S256`, token request carries
  `code_verifier`; authorize URL carries `prompt=select_account` and the three scopes.
- `SocialProviderButtonsTest`: Microsoft button colours/logo/hint pinned.
- Availability test from the other agent's refactor: no credentials → no button, start route 404.

## 6. Secret rotation (D7)

Client secrets expire (max 24 months in the portal) and nothing warns us: an expired secret = every Microsoft sign-in
fails with `AADSTS7000222`, and Microsoft-only accounts are locked out (they can still use the e-mailed sign-in link —
the invariant saves them).

Recommended, all three (cheap):
1. **`MICROSOFT_CLIENT_SECRET_EXPIRES_AT`** (`YYYY-MM-DD`, copied from the portal's "Expires" column, stored in
   Infisical next to the secret). A daily check (message + handler behind a small console command in the existing
   daily cron, or folded into an existing daily job) logs a **warning** (→ Sentry) from 30 days before expiry, and
   an error once expired. Reading expiry from Microsoft instead would need a Graph application permission
   (`Application.Read.All`) — not worth a second credential.
2. **`docs/TODO.md`** line with the rotation date (secret creation + 23 months) when the secret is created.
3. **Rotation runbook** (in the setup guide): add a new secret *before* the old one expires (an app can hold several),
   put it in Infisical, deploy, confirm a sign-in, then delete the old secret in the portal.

Certificate credential (`private_key_jwt`: we sign a client assertion with a key only we hold; Microsoft stores the
public certificate) is the stronger option — no shared secret on the wire, and a self-signed certificate can be
uploaded with a longer validity. It needs client-assertion signing code (league has none; ~40 lines with
firebase/php-jwt). Not for v1; revisit at the first rotation.

## 7. Rollout (D11, D12)

With no admin-only mode any more, "add credentials" = "public". Recommended sequence:

1. Ship the code with empty credentials (Microsoft invisible everywhere).
2. Owner creates **two** app registrations in the same tenant: `MySpeedPuzzling` (prod redirect URI) and
   `MySpeedPuzzling Local` (`http://localhost:8080/login/social/microsoft/callback`). Localhost testing works with
   Microsoft (unlike Apple), so the **whole checklist runs locally first** with the dev registration: rules 1–5,
   cancel, a Gmail-based Microsoft account (rule 3), settings connect/disconnect, notice mail (Mailpit).
   Because we key on `oid` (D2), identities created with the dev app would even keep working with the prod app —
   but local DBs are local, so it does not matter.
3. Owner writes prod values to `~/.msp-secrets/social-login.env`; an agent moves them to Infisical (procedure as for
   Google), deploys, and immediately smoke-tests in production: connect from settings, sign out, sign in with
   Microsoft, recent-activity rows. The button is public from this deploy on (plus ≤ 60 s page cache).
4. Rollback = delete the two credential values from Infisical and redeploy (button, start route and callback vanish;
   linked identities stay and work again when the credentials return).

A temporary `SOCIAL_LOGIN_MICROSOFT_ADMIN_ONLY` flag would buy a few hours of production-only testing at the price of
re-introducing the machinery that is just being removed. Not recommended — the localhost run covers the same code
paths; only the prod redirect URI and secret are new, and the smoke test catches those in minutes. → Q5.

## 8. Effort and risks

**Effort** (agent time, one PR, docs included): ~1–1.5 days — provider wiring + verifier + JWKS extraction ½ day,
UI/translations/legal ½ day, tests ½ day. Owner: ~1 h of console work, plus the Azure sign-up if no tenant exists
(10–15 min, card + phone).

**Risks**
- *Secret expiry outage* — mitigated by §6.
- *Losing the app registration* — mitigated by keying on `oid` (D2); still never delete it, and add a second owner
  (a work account in the tenant) so the registration is not tied to one personal login.
- *"Unverified" on the consent screen* may cost some sign-ins — measurable through `provider_cancelled` rows in
  `auth_audit_log`; publisher verification needs a business Partner Center account (Q2).
- *E-mail trust too narrow* — someone who uses a Gmail as their Microsoft account user name cannot auto-link; they get
  the existing rule-3 message ("sign in first, then connect"). Acceptable; the allowlist can grow.
- *Work/school users* see Microsoft's "can't sign in here with a work account" page and may not come back — the hint
  text says personal accounts; failure is visible in logs only as a missing callback.
- *Microsoft changes endpoints/claims* — the `/consumers` v2.0 endpoints and the `9188040d-…` tenant id have been stable
  since 2016; the JWKS refetch-on-unknown-`kid` handles key rollover.
- *Parallel refactor* (credential-based availability) — Microsoft must follow whatever shape lands; implement after it
  merges to avoid a rebase fight in `SocialLoginSettings`/twig globals/templates.

## Open questions for the owner

1. **Tenant:** do you already have a Microsoft Entra tenant (e.g. a company Microsoft 365) you want the app in, or should
   the guide use "sign up for a free Azure account with your personal Microsoft account" (card + phone verification,
   no charges)? The app can never move tenants afterwards.
2. **Publisher verification:** OK to launch with "unverified" on Microsoft's consent screen? (Verification needs a
   Partner Center account for a registered business and a work account in the tenant — only worth it if you have a
   company that can pass Partner Center verification.)
3. **Button order:** Google, Apple, Microsoft, Facebook — or Microsoft before Apple, since Microsoft mailboxes (13 %)
   likely outnumber iCloud ones? (A one-line prod query on `icloud.com`/`me.com`/`mac.com` would settle it.)
4. **Wording:** keep the house "Continue with Microsoft" (consistent across providers) or Microsoft's own "Sign in with
   Microsoft"?
5. **Rollout:** OK with "test on localhost, then credentials = public" (no admin-only stage), or do you want a short-lived
   Microsoft-only flag?
6. **E-mail trust:** OK with trusting only Microsoft-owned mailbox domains (Gmail-as-Microsoft-account users can't
   auto-link and must connect from settings), or treat every Microsoft e-mail as trusted like Facebook?
7. **Secret lifetime:** 24 months (fewer rotations) or Microsoft's recommended ≤ 12 months?

## Sources

- [ID token claims reference](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference)
- [Optional claims reference](https://learn.microsoft.com/en-us/entra/identity-platform/optional-claims-reference) (`email`, `xms_edov`)
- [OpenID Connect on the Microsoft identity platform](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc)
- [Register an app](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app)
- [Add and manage app credentials](https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-credentials) (24-month limit)
- [Publisher verification overview](https://learn.microsoft.com/en-us/entra/identity-platform/publisher-verification-overview)
- [Configure an app's publisher domain](https://learn.microsoft.com/en-us/entra/identity-platform/howto-configure-publisher-domain)
- [Sign in with Microsoft branding guidelines](https://learn.microsoft.com/en-us/entra/identity-platform/howto-add-branding-in-apps)
- [Microsoft Q&A: registering apps outside a directory deprecated](https://learn.microsoft.com/en-us/answers/questions/5508903/how-can-i-register-an-app-for-microsoft-authentica)
- [Semperis nOAuth research (2025)](https://www.semperis.com/press-release/research-risk-noauth-vulnerability-microsoft-entra-id-enterprise-saas-applications/)
- [Revoke app access to a Microsoft account](https://learn.microsoft.com/en-us/answers/questions/4660579/remove-apps-from-microsoft-account) (`account.live.com/consent/Manage`)
