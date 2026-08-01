# Auth Hardening: Audit Trail + Social Login

Status: **planned** (scope confirmed by Jan 2026-07-31). Follow-up to the Auth0 → native migration (issue #147, `docs/features/auth-migration/`). Replaces the last two things Auth0 gave us that the native stack doesn't: a queryable auth event history and social login.

## Confirmed scope

| Feature | Decision |
|---|---|
| DB-persisted auth audit log | **YES** — foundation |
| User-facing "recent activity" page | **YES** |
| Social login: Google, Apple, Facebook | **YES** (all three) |
| Instagram login | **NO — not technically possible.** Meta shut down the Instagram Basic Display API (Dec 2024); Instagram has no OIDC identity provider. Facebook Login is Meta's offering. |
| Admin per-user login history view | Not now (future — trivial once audit log exists) |
| New-device alert emails | Not now |
| 2FA (TOTP), GeoIP enrichment, HIBP warn-at-login | Not now |
| Storage | **Postgres**, same DB. At ~10k users we expect 1–2k auth events/day — a few MB/year. A plain indexed table + retention prune beats any separate store (ClickHouse/Loki = ops burden for zero benefit at this scale), stays joinable to `user_account`, and rides the existing backup pipeline. |

## What already exists (do NOT rebuild)

- **Rate limiting** (`config/packages/rate_limiter.php`): login 5/min per email+IP + 100/min per IP (in `LoginFormAuthenticator`, deliberately NOT firewall-level `login_throttling` — see comments there), sign-in-link and password-reset requests 3/15min + 20/h, registration limits. This already covers brute force.
- **`AuthenticationAuditSubscriber`** (`src/EventSubscriber/AuthenticationAuditSubscriber.php`): Monolog lines for login success/failure/logout on the `main` firewall, writes `user_account.last_login_at` (documented flush exception D10). The gap: log lines are ephemeral (container logs/Sentry) — not queryable per user.
- **`email_audit_log`**: every outgoing email with SMTP status, bodies, bounce columns.
- **PAT / OAuth2 tokens**: `last_used_at` tracked.
- **`UserAccount`**: `password` is already nullable (sign-in-link-only accounts exist) — social-only accounts fit the existing model without schema surgery.

---

## Workstream A — Auth audit trail

### Entity: `AuthAuditEvent` → table `auth_audit_log`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | `Uuid::uuid7()` — time-ordered, good index locality and cheap range pruning |
| `user_account_id` | uuid, nullable, FK → `user_account` | nullable: failed logins for unknown emails have no account. FK `ON DELETE CASCADE` (GDPR: delete account ⇒ audit trail goes too) |
| `email` | string, nullable | lowercased attempted email — lets us see failures for an address before/without an account |
| `event_type` | string | PHP backed enum `AuthAuditEventType` (see catalog) |
| `authenticator` | string, nullable | short label: `form`, `login_link`, `social:google`, `social:apple`, `social:facebook`, `auth0_fallback` |
| `ip_address` | string, nullable | `Request::getClientIp()` |
| `user_agent` | string, nullable | truncate to 500 chars |
| `metadata` | jsonb, nullable | failure reason class, provider payload extras — never secrets, never passwords |
| `occurred_at` | timestamptz | via `ClockInterface` |

Indexes: `(user_account_id, occurred_at DESC)` (activity page query), `(occurred_at)` (prune), `(event_type, occurred_at)` (future internal statistics — see below).

**Future use — internal statistics (MAU etc., Jan 2026-07-31, not in scope now):** the table doubles as the source for unique-active-user metrics (`COUNT(DISTINCT user_account_id)` over `login_success`/`sign_in_link_used`/`oauth_login` in a window — hence the mandatory `(event_type, occurred_at)` index). Two caveats recorded for when this gets built: (1) sessions live 30 days (raised from ~15.6 on 2026-08-01), so login events are an even looser *proxy* for activity — an active user may log in only ~once a month; true MAU needs request-level activity tracking, which is a separate feature; (2) the 24-month prune caps how far back trends reach — if longer history is ever wanted, snapshot monthly aggregates into a tiny summary table before pruning.

### Event catalog (`AuthAuditEventType` enum)

- `login_success`, `login_failure`, `logout`
- `sign_in_link_requested`, `sign_in_link_used`
- `password_reset_requested`, `password_reset_completed`, `password_changed`
- `registration`
- `email_change_requested`, `email_verified` (the account email-change flow exists — `ChangeAccountEmailHandler`, `VerifyEmailHandler` — and is security-relevant)
- `oauth_login`, `oauth_registration`, `oauth_identity_linked`, `oauth_identity_unlinked` (Workstream B emits these; named after the settled `oauth_identity` table)
- `auth0_fallback_login` (fallback controller redirect — dies with the flag in Phase 6)

### Wiring — follow the `EmailAuditSubscriber` pattern

New message `RecordAuthAuditEvent` + handler (sync — deliberately unrouted, like `ImportAuth0User`; the `doctrine_transaction` middleware wraps the insert). Every dispatch site wraps in try/catch + `$this->logger->error(..., ['exception' => $e])` — **audit failure must never break login**.

Dispatch sites (**exact classes, verified 2026-07-31**):
1. **Extend `AuthenticationAuditSubscriber`** — keep the Monolog lines and the `last_login_at` write, additionally dispatch `RecordAuthAuditEvent` from `onLoginSuccess` / `onLoginFailure` / `onLogout`. `sign_in_link_used` = success with `LoginLinkAuthenticator` (already distinguished there).
2. **Message handlers** (already inside Messenger transactions — sub-dispatch from the handler): `RequestSignInLinkHandler` (`sign_in_link_requested`), `RequestPasswordResetHandler` (`password_reset_requested`), `ResetPasswordHandler` (`password_reset_completed`), `ChangeAccountPasswordHandler` **and** `SetAccountPasswordHandler` (`password_changed` — both doors change the hash), `RegisterUserHandler` (`registration`), `ChangeAccountEmailHandler` (`email_change_requested`), `VerifyEmailHandler` (`email_verified`).
3. `Auth0FallbackLoginController` — dispatch `auth0_fallback_login` before delegating.

Handler detail: resolve `user_account_id` from the email when the dispatch site only has an email (failed login) — lookup, never create. IP via `Request::getClientIp()` is reliable — `trusted_proxies`/`trusted_headers` are configured in `config/packages/framework.php`.

### Retention

Console command → `PruneAuthAuditLog` message → handler deletes rows `occurred_at < now - 24 months` (single SQL DELETE, no hydration). **Mirror the existing `CleanupEmailAuditLogs` message/handler/command trio exactly** — same shape, proven pattern. Cron on the box: daily. IP addresses are personal data — the retention window is the GDPR justification; 24 months matches the "investigate account issues" purpose.

### ⚠️ GDPR deletion gap (pre-existing, found during the reality check — DECIDED 2026-07-31: fix it)

`DeletePlayerHandler` deletes the `Player`, owned rows, membership, and the Listmonk record — but **never deletes the `user_account` row**: email + password hash survive a GDPR account deletion. This predates this feature (the handler predates `user_account`; in the Auth0 era the identity lived at Auth0 and survived player deletion the same way). It matters now because (a) `user_account` holds PII in *our* DB, and (b) both new tables FK-cascade off `user_account`, so the cascade only means something if the account row actually gets deleted. **Decision (Jan, 2026-07-31): `DeletePlayerHandler` also removes the `UserAccount` (lookup by `player.userId`) — the user can re-register.** Fix ships in PR 1.

### Recent activity page (user-facing)

- Route `/account/recent-activity` (single-action controller, logged-in users only, NOT membership-gated — it's a security feature).
- Query class `GetAuthAuditEvents` (raw SQL in `src/Query/`, Results DTO in `src/Results/`): last 50 events for the logged-in account, filtered to user-meaningful types: `login_success`, `sign_in_link_used`, `login_failure`, `password_changed`, `password_reset_completed`, `oauth_login`, `oauth_identity_linked/unlinked`. (Failures included deliberately — "someone tried to get in" is the point of the page.)
- Display: event label, relative + absolute time, IP, readable device label. Device label = tiny in-house UA parser service (regex for Chrome/Safari/Firefox/Edge + Windows/macOS/iOS/Android/Linux, fallback to truncated raw UA) — **no new dependency** for this.
- Link from the account/settings menu. Translations: EN only (project convention for new features).

---

## Workstream B — Social login (Google, Apple, Facebook)

**The data model and linking rules were ALREADY SETTLED in `docs/features/auth-migration/README.md` §Auth-method extensibility (decision D13, 2026-07-12) — that section is the authority; this plan implements it, it does not redesign it.** Read it before implementing. Summary of what it fixes: table `oauth_identity` (exact columns below), per-provider authenticators, the 5 account-linking rules, the ≥1-sign-in-method invariant, provider-agnostic `msp|<uuid7>` user ids, and the rejected alternatives (per-provider columns, generalized credential table).

### Library choice

`league/oauth2-client` provider packages — **plain composer libraries, NOT Symfony bundles**:
- `league/oauth2-google`
- `league/oauth2-facebook`
- `patrickbussmann/oauth2-apple` (league-style Apple provider; handles the ES256 client-secret JWT)

Note: the auth-migration README's post-launch-candidates line mentions `knpuniversity/oauth2-client-bundle` — that note (2026-07-12) **predates the no-third-party-bundles decision (2026-07-24)** and is superseded: KnpU is DI sugar over these same league providers, so we wire the providers as services in `config/services.php` directly. All auth logic stays in our authenticators + Messenger handlers.

### Entity: `OauthIdentity` → table `oauth_identity` (schema per D13, verbatim)

| Column | Notes |
|---|---|
| `id` | uuid7, PK |
| `user_account_id` | uuid FK → `user_account` (ManyToOne, unidirectional), `ON DELETE CASCADE` (GDPR) |
| `provider` | string-backed PHP enum: `google`, `apple`, `facebook` |
| `provider_user_id` | provider's stable subject id; **UNIQUE together with `provider`** |
| `email_at_link` | provider email at link time (support/debugging; Apple private-relay addresses land here as-is) |
| `linked_at` | datetimetz_immutable |
| `last_used_at` | datetimetz_immutable, nullable — house audit pattern, same as PAT/OAuth2 tokens |

`user_id` namespace: social accounts are **native `msp|<uuid7>` accounts** — provenance lives only in `oauth_identity`, so linking/unlinking never touches the `Player.userId` seam.

### Flow

- `GET /login/social/{provider}` — start controller: validate provider enum + flag, generate `state` (and PKCE for Google), redirect to authorization URL.
- `/login/social/{provider}/callback` — **per-provider authenticators** on the `main` firewall (settled: "adding a provider = new enum case + new authenticator"; Google/Facebook may share an abstract base, Apple's POST + cache-state flow differs anyway). Account resolution follows the **five settled linking rules** (auth-migration README §Auth-method extensibility, adopt verbatim):
  1. `(provider, provider_user_id)` found → log in, touch `last_used_at`.
  2. Not found, provider email **verified** and matches an existing `user_account.email` → auto-link (create identity row) + log in. (Google: `email_verified` claim; Apple: always verified, possibly relay; Facebook: returns only confirmed emails — a denied email permission counts as unverified.)
  3. Not found, provider email matches an existing account but is **unverified** → refuse: "sign in with your password and connect {provider} from settings" (account-takeover guard).
  4. No match → create `user_account` (`user_id = msp|<uuid7>`, `password = NULL`, `email_verified_at` from provider claim) + identity row via a `RegisterWithOauthIdentity` message; Player JITs on first login exactly like the existing flow.
  5. Explicit linking from settings (user already authenticated) → create the identity row for the **logged-in** account, no provider-email match required — ownership is already proven. The unique `(provider, provider_user_id)` constraint guarantees one identity never attaches to two accounts.
- **Invariant (settled):** every account keeps ≥1 sign-in method — `password IS NOT NULL OR ≥1 oauth_identity` — enforced in **both** the unlink handler and the remove-password handler ("set a password before disconnecting your last sign-in method"). This also protects Apple-private-relay users whose relay email can die after unlink.
- **Anti-enumeration (settled):** login errors stay generic regardless of which methods an account has (never "this account uses Google").
- Audit: every path emits the Workstream A events.

### Linking vs merging (scope clarification, Jan 2026-07-31)

**Different-email linking is a first-class flow, not an edge case**: the connect buttons in "Connected sign-in methods" (on the edit-profile page) run rule 5 — the logged-in user's identity is attached to *their current account* with **no provider-email match required**, so a Google account under a completely different address links fine. `email_at_link` records what the provider reported; all future logins via that provider resolve by `(provider, provider_user_id)` and land on the linked account **regardless of any email mismatch**. This is the primary defense against duplicate accounts: link from settings *before* ever using the social button on the login page.

**True account merging is explicitly OUT OF SCOPE**: if a user first signs in with a different-email social identity (creating a fresh account + Player via rule 4) and only then realizes they already had an account, we have two accounts with two Players — merging their solving times/collections/memberships is a large separate feature. The recovery path without it: delete the unwanted (fresh, empty) account via the existing GDPR deletion, then link the provider from the surviving account's settings.

**Prevention — rule 4 gets an interstitial instead of silent creation**: the callback must not create the account straight away (there'd be nowhere to warn). When no account matches, park the verified provider profile in the short-TTL cache (same pool as the OAuth state) and show a confirmation page: "Create a new account with {email}?" plus "Already have a MySpeedPuzzling account? Sign in first, then connect {provider} in your profile settings." Confirm → dispatch the registration message with the cached profile. Costs one click on social signup; it's the only moment the duplicate-account mistake can be prevented.

### Provider gotchas (read before implementing)

**Apple** (hardest — do last):
- Client secret is a self-signed **ES256 JWT** built from a `.p8` key (env: `APPLE_CLIENT_ID` = Services ID, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`). The league Apple provider handles generation.
- When requesting `name`/`email` scopes Apple **requires `response_mode=form_post`** → the callback arrives as a **cross-site POST**: with `SameSite=Lax` session cookies the session (and the state stored in it) is NOT sent. **Store state server-side in Symfony cache keyed by the state value (TTL 10 min) instead of the session** — validate by lookup+delete. The callback route must accept POST and be excluded from CSRF (state is the CSRF protection here).
- Name + email are delivered **only on the user's FIRST authorization** — capture them in that callback or lose them (re-consent requires the user to revoke at appleid.apple.com).
- Private relay emails (`@privaterelay.appleid.com`): Apple's relay only forwards mail from **domains registered in the Apple Developer console** (Certificates → Services → Sign in with Apple for Email Communication). **Register `mail.myspeedpuzzling.com` (and its SPF) there** or every transactional email to relay users silently bounces.
- Needs a paid Apple Developer account ($99/yr), a Services ID with the domain + return URLs verified.

**Google** (easiest — do first): standard OIDC; verify `email_verified`; use PKCE.

**Facebook**: `public_profile` + `email` permissions need no App Review; the app must be switched to Live mode. Some users deny the email permission → treat like unverified email (refuse with the "use email sign-in link" message). App must have a privacy policy URL configured.

### UI

- Buttons on `/login` and `/register` above/below the form, brand-guideline-compliant (each provider mandates logo/wording — "Continue with Google/Apple/Facebook"). Bootstrap-styled, no image CDNs (self-hosted SVGs in `assets/`).
- **"Connected sign-in methods"** settings section (the settled name): list linked providers, link/unlink, **and set-password** for social-only accounts (opens the email+password door; the password-reset flow works too since the email is verified).
- Translations: EN only.

### Feature flags + admin-only rollout stage (Jan 2026-07-31)

One flag per provider: `SOCIAL_LOGIN_GOOGLE_ENABLED`, `SOCIAL_LOGIN_APPLE_ENABLED`, `SOCIAL_LOGIN_FACEBOOK_ENABLED` (default `0` in repo `.env`; button rendering + start route + authenticator acceptance all gated). Allows shipping code dark and flipping per provider as Jan finishes each console setup.

**Plus `SOCIAL_LOGIN_ADMIN_ONLY` (default `1`)** — even with a provider enabled, social login stays invisible to the public until Jan flips this to `0` after verifying everything end-to-end in production. Admin = `player.isAdmin` via the existing `AdminAccessVoter` (`is_granted('ADMIN_ACCESS')`). While admin-only:

- **`/login` and `/register` render NO social buttons for anyone.** Two reasons: the viewer is anonymous (admin status unknowable), and those pages are anonymously cached (`public, s-maxage=60`, PR #164) — per-viewer conditional rendering would poison the shared cache. Rendering nothing keeps the cache uniform.
- **Admins test the logged-out login flow via direct URL** (`/login/social/google`). The start route stays reachable for anonymous visitors; enforcement happens in the **callback**, where identity is finally known: if the resolved account's player is not an admin → generic authentication failure (no hint the feature exists). Rule 4 (new-account creation) is **fully disabled** in admin-only mode.
- **Edit-profile "Connected sign-in methods" section renders only for `is_granted('ADMIN_ACCESS')`** (logged-in page, not publicly cached — safe), and the link/unlink handlers enforce the same check server-side.

Flip `SOCIAL_LOGIN_ADMIN_ONLY=0` → public launch. **Update `docs/features/feature_flags.md`** for all four flags (project rule).

### Env vars (all empty-default in repo `.env`; prod via Infisical)

```
SOCIAL_LOGIN_ADMIN_ONLY=1
SOCIAL_LOGIN_GOOGLE_ENABLED=0
GOOGLE_CLIENT_ID= / GOOGLE_CLIENT_SECRET=
SOCIAL_LOGIN_FACEBOOK_ENABLED=0
FACEBOOK_APP_ID= / FACEBOOK_APP_SECRET=
SOCIAL_LOGIN_APPLE_ENABLED=0
APPLE_CLIENT_ID= / APPLE_TEAM_ID= / APPLE_KEY_ID= / APPLE_PRIVATE_KEY=
```

---

## Reality check — implementation map (verified against the codebase 2026-07-31)

Concrete anchors so nothing gets invented during implementation:

| Plan item | Where it lands in the real codebase |
|---|---|
| `AuthAuditEventType`, provider enum | `src/Value/` — string-backed enums live there (`BounceType`, `ConversationStatus`, …) |
| Prune command/message/handler | Mirror `CleanupEmailAuditLogs` + `CleanupEmailAuditLogsHandler` (existing, proven) |
| Recent-activity + Connected-sign-in-methods links | Account section of `templates/edit-profile.html.twig` (~line 160, next to the change-password button) |
| New authenticators | Append to `custom_authenticators` on the `main` firewall, `config/packages/security.php` (~line 101, currently `[LoginFormAuthenticator, 'auth0.authenticator']` — the Auth0 entry leaves in Phase 6) |
| Apple OAuth state cache | New dedicated pool in `config/packages/cache.php` — `cache.app` is Redis, pools pattern already used (`auth0_token_cache`) |
| Social registration handler | Parallel `RegisterUser`/`RegisterUserHandler` (email + locale, no password) — account+player creation pattern to copy |
| Set-password for social-only accounts | **Reuse `SetAccountPassword`** — its handler has no current-password guard and already invalidates outstanding reset requests + sign-in links. Show it in settings only when `password === null`. `ChangeAccountPassword` (requires current password, refuses null-password accounts by design) stays the door for accounts that have one. |
| Client IP | `Request::getClientIp()` — `trusted_proxies` configured in `config/packages/framework.php` |
| Email-change audit events | `ChangeAccountEmailHandler` / `VerifyEmailHandler` exist — see event catalog |
| GDPR deletion | `DeletePlayerHandler` — see the gap box above (fix in PR 1) |

`UserAccount` itself needs **no schema changes** for Workstream A (it already carries `last_login_at`) and none for Workstream B either (password already nullable, email unique, `user_id` provider-agnostic — the migration deliberately left it social-ready).

## Rollout plan

Two PRs, in order:

**PR 1 — audit trail** (no user-visible risk, ship first):
1. `AuthAuditEventType` enum, `AuthAuditEvent` entity, generated migration (`docker compose exec web php bin/console doctrine:migrations:diff` — never hand-written)
2. `RecordAuthAuditEvent` message + handler + tests
3. Wire all dispatch sites (subscriber + the handlers named above + fallback controller) + tests
4. Prune command + message + handler + tests (mirror `CleanupEmailAuditLogs`); note the cron line for the box in the PR description
5. `GetAuthAuditEvents` query + recent-activity controller/template/UA-label service + tests
6. GDPR fix: `DeletePlayerHandler` also removes the `UserAccount` row (decided — see the gap box)

**PR 2 — social login** (flags default OFF, code ships dark):
1. Provider enum, `OauthIdentity` entity (settled schema), migration
2. Provider service wiring + composer deps
3. Start controller + per-provider authenticators + resolve/link/register handlers (five settled rules + invariant) + audit events
4. Google end-to-end first (mocked-HTTP tests), then Facebook, then Apple (cache-based state, POST callback); rule-4 interstitial before account creation
5. Login/register buttons + "Connected sign-in methods" settings UI: connect buttons (rule 5 — works with a different provider email), unlink with invariant, set-password for null-password accounts **reusing the existing `SetAccountPassword` message** (shown only when `password === null`)
6. `feature_flags.md`, CLAUDE.md feature pointer, fixtures if needed

**Jan's manual tasks** (not for the implementing agent) — **step-by-step guides: [`setup-google.md`](setup-google.md), [`setup-facebook.md`](setup-facebook.md), [`setup-apple.md`](setup-apple.md)** (do them in that order):
- Google Cloud console: OAuth consent screen + web credentials; redirect URIs for prod + dev
- Meta developers: app, Live mode, privacy policy URL
- Apple Developer: Services ID, domain verification, `.p8` key, **register `mail.myspeedpuzzling.com` for private-relay email**
- Infisical: all secrets; flip flags per provider when ready; verify each provider end-to-end as admin, then `SOCIAL_LOGIN_ADMIN_ONLY=0` = public launch
- Add prune cron on the box

Shared facts baked into the guides: one redirect URI per provider (`https://myspeedpuzzling.com/login/social/{provider}/callback` — link flows reuse it via the state payload's intent), Apple is untestable on localhost (verify in production behind the admin-only flag), rollback per provider = flip its flag to `0`.

## Explicitly out of scope (revisit later)

Admin login-history view, new-device alert emails, 2FA (TOTP), GeoIP enrichment, HIBP warn-at-login — all declined for now (2026-07-31). The audit log schema is designed so each of these can be added without migration churn.
