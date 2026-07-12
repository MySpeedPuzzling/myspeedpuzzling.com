# Auth0 → Native Symfony Authentication Migration

**Status:** Research complete, implementation not started
**Researched:** 2026-07-11 (all facts below verified on that date — live production DB queries, Auth0 docs/pricing, Packagist metadata, empirical tests in this repo's PHP 8.5.5 / Symfony 8.0.5 container)
**Documents:**
- [README.md](README.md) — analysis, target architecture, decisions, risks (this file)
- [implementation-plan.md](implementation-plan.md) — step-by-step technical plan, cutover runbook, decommission checklist
- [communication-plan.md](communication-plan.md) — user communication timeline, email/banner copy, FAQ, support playbook

## Why migrate

- Auth0 free tier limitations and vendor lock-in (password hashes held hostage behind a paid support ticket).
- Full ownership of the login/registration/reset UX (hosted Universal Login is unbrandable on free tier, lives on `speedpuzzling.eu.auth0.com`).
- Dependency hygiene: the app currently pins `auth0/symfony: dev-main` from a personal fork (`janmikes/auth0-symfony`) under `minimum-stability: dev`, partly to bypass a `roave/security-advisories` block on `auth0/symfony <= 5.5`. Removing Auth0 removes this fragile posture.
- Unlocks native features later: passkeys/WebAuthn, magic links, 2FA, per-device sessions.

## Current state (verified)

### Production data (live query, 2026-07-11)

| Fact | Value |
|---|---|
| Registered players (`user_id IS NOT NULL`) | 9,953 |
| Connection types | **100% `auth0\|` (email+password database connection). Zero social logins.** |
| Active in last 90 days (by solving times) | 3,301 |
| Players with `user_id` but no stored email | 3 |
| Duplicate-email pairs (same email, two player rows) | 7 pairs / 14 rows — likely deleted-and-re-registered Auth0 accounts; the stale row's Auth0 user no longer exists |
| Auth0 tenant | `speedpuzzling.eu.auth0.com` (EU, no custom domain), client `miVihwBrsB47LxhQpLYEf22ySrDq9Ra9` |
| Sessions | Symfony `PdoSessionHandler` in Postgres, cookie lifetime ~15.6 days, no remember-me |
| Transactional email | `smtp.seznam.cz` (all three MAILER DSNs) |

**Zero social logins is the single most important finding** — it eliminates the entire social-identity-portability problem (Google sub mapping, Facebook app-scoped IDs, Apple team-scoped subs). The migration is purely email+password.

### How Auth0 is wired in (codebase inventory)

Auth0 touches exactly **one** of four firewalls — `main`. The `api` (PAT + OAuth2 server), `internal_api`, and `stateless` firewalls are Player/token-based and have zero Auth0 dependency.

- **Bundle:** forked `auth0/symfony` 5.5.0 (`janmikes/auth0-symfony@0785fc5`) — fork is only a Symfony 8 / PHP 8.4+ compat bump, no behavior change. Credentials stored inside the Symfony session; `getUserIdentifier()` returns the Auth0 `sub`.
- **App glue:** `src/Security/Auth0EntryPoint.php` (redirect-target cookie), `src/EventSubscriber/Auth0RedirectSubscriber.php` (cookie-based post-login redirect), `src/Services/Auth0SdkResetListener.php` (FrankenPHP worker-mode safety — reflection-nulls the SDK each request), routes `login`/`callback`/`logout`/`app_logout` in `config/routes.php`, SDK config in `config/packages/auth0.php`.
- **Identity seam:** `Player.userId` (unique, nullable string = Auth0 sub) is the real identity across the whole write path. 13+ Messenger handlers resolve/auto-create players via `PlayerRepository::getByUserIdCreateIfNotExists($userId)`; API processors reach back into `getPlayer()->userId`. **Any new auth must keep producing the same identity string.**
- **Read chokepoint:** `src/Services/RetrieveLoggedUserProfile.php` — `security->getUser()` → `instanceof Auth0\Symfony\Models\User` → `getUserIdentifier()` → `GetPlayerProfile::byUserId()`; JIT-registers via `RegisterUserToPlay` (consumes only `sub`, `email`, `name`). Exposed to ~153 template references as the `logged_user` Twig global.
- **Compile-time coupling:** 46 controllers type-hint the concrete `Auth0\Symfony\Models\User` in `#[CurrentUser]` (mechanical swap); 25 already hint `UserInterface` (portable). `EventSubscriber/OAuth2AuthorizationSubscriber.php` does runtime `instanceof` on the Auth0 user classes.
- **Admin:** DB flag `player.is_admin` via `AdminAccessVoter` — **no Auth0 roles/claims involved.** Untouched by migration.
- **No Auth0 Management API usage anywhere.** No in-app password reset, email verification, or account email change — 100% delegated to Auth0's hosted pages today. These flows are **new builds**, not ports. `email_verified` is consumed nowhere in the app.
- **Tests:** all auth funnels through two helpers (`tests/TestingLogin.php`, `src/Controller/Test/TestLoginController.php` + `tests/Panther/AbstractPantherTestCase.php`) that fabricate an Auth0 `User`. Fixtures use `auth0|regular001`-style ids. ~40+ test files need no per-file change.
- **Logout is two-legged** (Auth0 `/v2/logout` → local firewall logout at `/app-logout`); collapses to single local logout.

## Getting the password hashes out of Auth0 (verified facts)

1. **Free tier can export everything except hashes**: Bulk User Export job (`POST /api/v2/jobs/users-exports`, NDJSON/CSV) includes `email`, `email_verified`, `name`, `created_at`, `last_login`, `logins_count`, `identities`, metadata. ([docs](https://auth0.com/docs/manage-users/user-migration/bulk-user-exports))
2. **Hash export requires a paid plan**, only because support tickets do — official docs: password hashes are "not available for our Free subscription tier", obtainable via support ticket. Auth0 staff explicitly suggested upgrading "even if only for a brief period" ([docs](https://auth0.com/docs/troubleshoot/customer-support/manage-subscriptions/export-data), [community, 2026](https://community.auth0.com/t/need-help-exporting-password-hashes/198638)).
3. **Cheapest path: B2C Essentials, monthly self-service billing — $35/mo at ≤500 MAU** ($70 @ 1,000, $175 @ 2,500). MAU = monthly active *logins*; our real login MAU is very likely ≤500 even with 3.3k quarterly-active players. Downgrading back to Free self-service cancels billing. ([pricing](https://auth0.com/pricing), [manage subscriptions](https://auth0.com/docs/troubleshoot/customer-support/manage-subscriptions))
4. **Ticket process:** support.auth0.com → "I have a question regarding my Auth0 account" → "I would like to obtain an export of my tenant password hashes." **No SLA** — reports say up to a week or more. Keep the subscription active until the file is delivered and verified.
5. **Export format:** NDJSON with `_id` (our `user_id` = `auth0|<_id>`), `email`, `email_verified`, `passwordHash`, `password_set_date`, `connection`. Hashes are **bcrypt `$2b$` cost 10**. ([support KB](https://support.auth0.com/center/s/article/How-to-Use-the-Password-Hashes-Export-from-Auth0))
6. **PHP compatibility — empirically verified in this repo's container:** `password_verify()` on PHP 8.5 accepts `$2b$` hashes directly. Symfony's `migrate_from: ['bcrypt']` verifies them and transparently re-hashes to argon2id on first successful login. **Users keep their exact passwords with zero friction.**
7. **Free fallback that needs no payment:** Auth0's Resource Owner Password Grant (`POST /oauth/token`, `grant_type=password` or `password-realm`) is available on the free plan (per-application grant toggle + tenant Default Directory). Our own login form can validate credentials against it and hash locally on success ("trickle migration"). Gotchas: server IP triggers brute-force protection (mitigate via `auth0-forwarded-for` header with "Trust Token Endpoint IP Header", or IP allowlist); breached passwords return 401 `password_leaked`. ([ROPG docs](https://auth0.com/docs/get-started/authentication-and-authorization-flow/resource-owner-password-flow), [attack-protection gotchas](https://auth0.com/docs/get-started/authentication-and-authorization-flow/avoid-common-issues-with-resource-owner-password-flow-and-attack-protection))

**Strategy: do both.** Pay ~$35 for one month, get the full hash export (covers all 9,953 users at once). Keep the ROPG trickle as a fallback branch inside the login authenticator during a transition window — it rescues users whose hash is stale (changed password after the export), users who registered on Auth0 after the export snapshot, and acts as insurance if the ticket drags.

## Target architecture

### New entity: `UserAccount`

Separate auth entity (do **not** put credentials on `Player`):

```
user_account
  id                 uuid (uuid7, PK)
  user_id            string, unique — THE identity string; equals player.user_id
                     (auth0|xxx for migrated users, msp|<uuid7> for new registrations)
  email              string, unique (citext / lower-indexed)
  password           string|null — argon2id (imported rows start as bcrypt $2b$)
  email_verified_at  datetimetz_immutable|null
  registered_at      datetimetz_immutable
  last_login_at      datetimetz_immutable|null
  legacy_auth0       bool — true for imported rows (enables trickle fallback + reporting)
```

- Implements `UserInterface` + `PasswordAuthenticatedUserInterface`. **`getUserIdentifier()` returns `user_id`**, preserving the exact string every Messenger handler and `Player.userId` lookup already keys on — the entire write path, API firewall, Mercure, and Stripe stay untouched.
- Symfony 8 note: `eraseCredentials()` no longer exists on `UserInterface` — don't implement it.
- Load-bearing nuance: the login form authenticates by **email**, so the authenticator's `UserBadge` uses a custom loader (email → `UserAccount`), while the user provider's `loadUserByIdentifier()` resolves the **`user_id` string** (used by session refresh and remember-me). Badge identifier ≠ user identifier is supported and intentional.
- `Player` entity stays as-is; registration creates `UserAccount` + `Player` atomically in one handler with the same `user_id` string.

### Auth-method extensibility (decided 2026-07-12 — Google/Facebook, maybe Apple, planned later)

Design principle: **one table per credential shape, not per provider.** `user_account` is the account; each way of proving ownership lives where its shape belongs:

| Credential shape | Storage | Why |
|---|---|---|
| Password ("something you know") | `user_account.password` (nullable; NULL = social-only account) | Symfony's password machinery (`PasswordAuthenticatedUserInterface`, `PasswordCredentials`, `migrate_from` rehash, `PasswordUpgraderInterface`, remember-me `signature_properties: ['password']`) is built around `getPassword()` on the security user — moving it into a rows-table fights the framework |
| Third-party identity (Google, Facebook, Apple, any OIDC) | `oauth_identity` — one row per linked identity | Adding a provider = new enum case + new authenticator. Zero schema changes |
| Passkey ("something you have", later) | own `webauthn_credential` table | Multiple credentials per account, key material + sign counter — bundle-owned shape, NOT oauth_identity rows |
| TOTP second factor (later) | nullable columns on `user_account` | scheb/2fa expects `TwoFactorInterface` on the user entity — account-level, not an identity |

```
oauth_identity
  id                uuid (uuid7, PK)
  user_account_id   uuid FK → user_account (ManyToOne, unidirectional)
  provider          string — string-backed PHP enum (google|facebook|apple|…)
  provider_user_id  string — UNIQUE together with provider
  email_at_link     string — provider email at link time (support/debugging)
  linked_at         datetimetz_immutable
  last_used_at      datetimetz_immutable|null — house audit pattern, same as PAT/OAuth2 tokens
```

**Account-linking rules (OAuth callback):**
1. `(provider, provider_user_id)` found → log in, touch `last_used_at`.
2. Not found, provider email **verified** and matches an existing `user_account.email` → auto-link (create identity row) + log in.
3. Not found, provider email matches an existing account but is **unverified** → refuse: "sign in with your password and connect {provider} from settings" (account-takeover guard; `email` is unique, so a silent second account is impossible anyway).
4. No match → create `user_account` (`user_id = msp|<uuid7>`, `password = NULL`, `email_verified_at` from provider claim) + `Player` + identity row, log in.

**Invariants:**
- Every account keeps ≥1 sign-in method: `password IS NOT NULL OR ≥1 oauth_identity`. Enforced in the unlink and remove-password handlers ("set a password before disconnecting your last sign-in method"). A trivial COUNT with this design — with per-provider columns it's a null-check chain that grows with every provider.
- `user_id` is never derived from a provider (always `msp|<uuid7>` for new accounts) — provenance lives in `oauth_identity`, so linking/unlinking never touches the `Player.userId` seam.
- Login errors stay generic regardless of which methods an account has (no "this account uses Google" — enumeration leak). The universal rescue is the magic login link, which works for any account with a verified email.

`oauth_identity` is deliberately **not created during the migration** (zero social users; no code would use it). The migration only guarantees nothing blocks it: password on the account, unique email, provider-agnostic `user_id`. The table, per-provider authenticators, and the settings-page "Connected sign-in methods" UI (list / link / unlink / set-password) ship with the first provider.

Rejected alternatives, for the record: **(a)** per-provider columns on `user_account` (`google_id`, `facebook_id`, …) — workable for two providers, but every addition is a migration + unique index + edits to the invariant check, settings UI, and fixtures, and audit metadata multiplies columns (`google_linked_at`, `google_last_used_at`, …); **(b)** one generalized `auth_credential` table holding password + OAuth + passkeys as typed rows — uniform on paper, but password-in-a-row fights Symfony's hasher/upgrader/remember-me integration and passkeys need bundle-specific columns regardless. Note on Meta: consumer "Sign in with Instagram" no longer exists (Basic Display API shut down end of 2024) — Facebook Login is the Meta option.

### Auth features at launch

| Feature | Implementation | Verified Symfony 8 support |
|---|---|---|
| Login form | One custom authenticator extending `AbstractLoginFormAuthenticator` (local hash → `PasswordCredentials`; no local hash + `legacy_auth0` → `CustomCredentials` ROPG trickle). Single authenticator because Symfony runs authenticators sequentially and the **first** `AuthenticationException` short-circuits — there is no fallback chain between authenticators | core |
| Password hashing | `argon2id` + `migrate_from: ['bcrypt']` (sodium present; `'auto'` would mean bcrypt cost 13, NOT argon2id) | verified empirically |
| Registration | New form → `RegisterUser` command → handler creates UserAccount + Player; `NotBlank` + `Length(min: 12)` + `PasswordStrength` + `NotCompromisedPassword(skipOnError: true)` as a `Compound` constraint | core |
| Email verification | `symfonycasts/verify-email-bundle` ≥ 1.18.0 (Symfony 8 since 2025-11-29), signed URLs, no DB table | [releases](https://github.com/SymfonyCasts/verify-email-bundle/releases) |
| Password reset | `symfonycasts/reset-password-bundle` ≥ 1.24.0 (Symfony 8 since 2025-11-29), hashed selector/verifier tokens, built-in enumeration protection | [releases](https://github.com/SymfonyCasts/reset-password-bundle/releases) |
| Magic login link | Symfony native `login_link` — "email me a sign-in link" on the login page. **Primary mitigation for the password-manager-domain problem** | core |
| Remember me | Signature-based (no storage, auto-invalidates on password change), `secure: true`, `samesite: lax` | core |
| Login throttling | `login_throttling` (requires `symfony/rate-limiter`), default 5/min per username+IP | core |
| Audit logging | Listeners on `LoginSuccessEvent` / `LoginFailureEvent` / `LogoutEvent` → Monolog/Sentry | core |

Post-launch candidates (not blocking): Google/Facebook login (design settled — see "Auth-method extensibility" above; `knpuniversity/oauth2-client-bundle` v2.20+ supports Symfony 8), 2FA via `scheb/2fa-bundle` (v8.6.1 supports Symfony 8 + PHP 8.5), passkeys/WebAuthn, per-device session management.

### FrankenPHP worker-mode safety

Native Symfony auth uses the request-scoped token storage (reset per request by the kernel) — no equivalent of `Auth0SdkResetListener` is needed. Any new service caching user state must implement `ResetInterface` (existing project rule). The trickle ROPG HTTP client must be stateless.

## Key decisions (with recommendations)

| # | Decision | Recommendation |
|---|---|---|
| D1 | Hash algorithm | `argon2id` explicit + `migrate_from: ['bcrypt']` |
| D2 | Identity model | Separate `user_account` entity; `getUserIdentifier()` = `user_id` string; new users `msp\|<uuid7>` |
| D3 | Pay for hash export? | **Yes** — one month B2C Essentials (~$35). Start the ticket early; build in parallel |
| D4 | Trickle ROPG fallback | Yes, as a branch inside the single login authenticator, active during transition window only |
| D5 | Dual-auth UX | One login form; Auth0 fallback is invisible (no "old login" button). Satisfies the "both methods" period without UX complexity |
| D6 | Magic login link at launch | Yes — biggest UX rescue for users whose password manager saved credentials under `auth0.com` |
| D7 | `email_verified` handling | Import the flag (`email_verified_at = now()` where true). Don't block unverified legacy users (app never checked it anyway). Require verification for **new** registrations |
| D8 | Registration enumeration | Accept the unique-email form error + rate-limit the endpoint (standard tradeoff for a community site) |
| D9 | Cutover force-logout | Delete rows from the Postgres `sessions` table at deploy (sessions are `PdoSessionHandler`, not Redis). Communicated in advance |
| D10 | `upgradePassword()` flush | Allow the documented Symfony exception to the "no flush in repositories" rule (runs inside the security listener, not a handler) — with an explaining comment |
| D11 | 2FA | Post-launch, not blocking |
| D12 | Email deliverability | Before cutover, verify seznam.cz SMTP limits/SPF/DKIM; auth emails (reset/verify/login-link) become critical-path. Consider a dedicated transactional provider if limits are tight |
| D13 | Social-auth data model | `oauth_identity` table (one row per linked provider identity); password stays on `user_account`. Design settled now (see "Auth-method extensibility"), table ships with the first provider — not during migration |

## What stays untouched (reassurance list)

- All Messenger handlers and the CQRS write path (keyed on the preserved `user_id` string)
- `api` firewall: PAT auth, OAuth2 server (league bundle), all `/api/v1/*` — token-based, Auth0-free
- `internal_api` firewall
- Admin authorization (`player.is_admin` + voters)
- Stripe, Mercure, GDPR deletion handler, statistics, fixtures data model

## Risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| Hash export ticket delayed (no SLA) | Medium | Open ticket at phase start; build in parallel; ROPG trickle works without the export |
| Hashes stale (password changed after export) or users registered after snapshot | Certain for some users | Fresh free bulk export at cutover for the user *list* delta; trickle fills missing/stale hashes on first login |
| Users can't find their password (manager saved it under `*.auth0.com`) | High for a subset | Magic login link + prominent reset + explicit copy on login page + FAQ ("search your password manager for auth0") |
| Email wave overwhelms seznam.cz SMTP / lands in spam | Medium | D12; send announcement via listmonk (already used for newsletters); rate-limit via Messenger |
| Password > 72 bytes can't verify against bcrypt (`NativePasswordHasher` refuses) | Very low | Log distinctly; user resets password |
| Duplicate-email pairs break unique constraint on import | Certain (7 pairs) | Import keys on `user_id`, not email; stale rows (Auth0 user deleted) simply get no account. Pre-cutover cleanup script verifies |
| ROPG false-positive brute-force blocks (server IP) | Medium | `auth0-forwarded-for` + "Trust Token Endpoint IP Header", or allowlist server IP in Attack Protection |
| Native auth bug at cutover | Low | Blue-green rollback to previous image restores Auth0 login (tenant stays intact until decommission); sessions already truncated either way |
| OAuth2 `/oauth2/authorize` deep-link return regression (bespoke redirect cookie replaced by `TargetPathTrait`) | Medium | Explicit Panther test for the authorize→login→authorize round-trip |
| All users logged out at cutover | Certain | Communicated in advance; one-time event; same credentials work immediately |

## Cost & effort estimate

- **Money:** ~$35–70 (one month Auth0 B2C Essentials) + $0 extra infra (everything self-hosted already).
- **Build:** roughly 10–15 dev-days: entities/migrations + import command (2), authenticator/provider/registration/reset/verify/login-link + templates + translations (5–7), `#[CurrentUser]` sweep + chokepoint rewrites + tests (2–3), comms + cutover + monitoring (2).
- **Calendar:** ~6–8 weeks end-to-end, dominated by the communication lead time and the transition window, not by engineering.

## Success criteria

- ≥ 90% of 90-day-active players (~3,300) have logged in natively within 6 weeks of cutover
- Login failure rate back to pre-migration baseline within 1 week
- Support requests about login < 1% of active players
- Auth0 tenant deleted; `auth0/symfony` fork removed from `composer.json`; `minimum-stability: dev` reviewed

## Sources

- Auth0 export policy: https://auth0.com/docs/troubleshoot/customer-support/manage-subscriptions/export-data
- Hash export KB (format): https://support.auth0.com/center/s/article/How-to-Use-the-Password-Hashes-Export-from-Auth0
- Staff confirmation "brief period" upgrade (2026): https://community.auth0.com/t/need-help-exporting-password-hashes/198638
- Pricing: https://auth0.com/pricing · Cancel/downgrade: https://auth0.com/docs/support/downgrade-or-cancel-subscriptions
- Bulk export (free): https://auth0.com/docs/manage-users/user-migration/bulk-user-exports
- ROPG: https://auth0.com/docs/get-started/authentication-and-authorization-flow/resource-owner-password-flow · attack-protection interplay: https://auth0.com/docs/get-started/authentication-and-authorization-flow/avoid-common-issues-with-resource-owner-password-flow-and-attack-protection
- Tenant deletion: https://auth0.com/docs/support/manage-subscriptions/delete-or-reset-tenant
- Symfony 8 passwords/migrate_from: https://symfony.com/doc/8.0/security/passwords.html · custom authenticator: https://symfony.com/doc/8.0/security/custom_authenticator.html · login link: https://symfony.com/doc/8.0/security/login_link.html
- verify-email-bundle v1.18.0 / reset-password-bundle v1.24.0 Symfony 8 releases (GitHub); scheb/2fa v8.6.1; knpuniversity/oauth2-client-bundle v2.20.0 (Packagist metadata, 2026-07-11)
