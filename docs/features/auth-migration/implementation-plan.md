# Auth0 Migration — Step-by-Step Implementation Plan

Companion to [README.md](README.md) (analysis & decisions) and [communication-plan.md](communication-plan.md).

Phases 0–2 can run in parallel. Phase 4 (cutover) requires 1 (hash export received) and 2 (build) complete, and 3 (comms) at least 2 weeks in flight.

---

## Phase 0 — Preparation & data (free, start immediately)

- [ ] **0.1** Run a free Bulk User Export from Auth0 (`POST /api/v2/jobs/users-exports` or the User Import/Export extension): NDJSON, fields `user_id`, `email`, `email_verified`, `name`, `created_at`, `last_login`, `logins_count`, `identities`. Store outside the repo (contains PII).
- [ ] **0.2** Check real MAU in the Auth0 dashboard → confirms the $35 (≤500 MAU) Essentials tier.
- [ ] **0.3** Reconciliation script (one-off, scratchpad — not committed): join export against `player` on `user_id`. Expect and resolve:
  - 7 duplicate-email player pairs → the row whose `user_id` is absent from the export is a deleted Auth0 account; it gets no `user_account` (cannot log in today either). Verify each pair manually.
  - 3 players with `user_id` but `email IS NULL` → backfill email from export.
  - Players in export missing locally / locally missing from export → list and decide per case.
- [ ] **0.4** Email deliverability (decision D12): confirm seznam.cz SMTP daily limits, SPF/DKIM/DMARC alignment for the sending domain. If limits are tight for a ~10k-recipient announcement, use listmonk for the bulk announcement and keep SMTP for transactional only.
- [ ] **0.5** Verify ROPG works against the tenant (free): enable Password grant on a test application, set tenant Default Directory to the database connection, `curl POST /oauth/token` with a test account. Document the connection name.

## Phase 1 — Paid hash export (~$35, calendar-bound, low effort)

- [ ] **1.1** Upgrade tenant to **B2C Essentials, monthly billing** (Dashboard → Settings → Billing).
- [ ] **1.2** Immediately open the support ticket: support.auth0.com → "I have a question regarding my Auth0 account" → "I would like to obtain an export of my tenant password hashes."
- [ ] **1.3** On delivery, validate: line count vs bulk export; every `passwordHash` matches `^\$2b\$10\$`; spot-check one known test account with `password_verify()`.
- [ ] **1.4** Store the file encrypted, restricted access (it's sensitive PII — bcrypt hashes + emails). Delete all copies after Phase 6.
- [ ] **1.5** Downgrade tenant back to Free (self-service; do **not** delete the tenant yet — it backs the trickle fallback and rollback until Phase 6).

## Phase 2 — Build native auth (feature branch; deployable dark — nothing user-visible until the firewall switch in Phase 4)

### 2a. Data model

- [ ] `UserAccount` entity per README spec (`user_id` unique, `email` unique lower-indexed, nullable `password`, `email_verified_at`, `legacy_auth0` flag, timestamps). Generated migration (never hand-written, per project rules).
- [ ] `reset_password_request` table (bundle-generated migration via `make:reset-password` or manual entity following bundle docs).
- [ ] Import command: `myspeedpuzzling:import-auth0-users <bulk-export.ndjson> <hash-export.ndjson>` — console command parses/joins the two files and dispatches `ImportAuth0User` messages (batch); handler upserts `UserAccount` keyed on `user_id` (never email), sets bcrypt hash as-is, `email_verified_at` from flag, `legacy_auth0 = true`, and backfills `player.email`/`player.name` where NULL. Idempotent — safe to re-run with a fresh export at cutover. Tests test the handler directly.

### 2b. Security plumbing

- [ ] `config/packages/security.php`: `password_hashers` for `UserAccount` → `algorithm: 'argon2id'`, `migrate_from: ['bcrypt']`.
- [ ] `UserAccountProvider` (custom): `loadUserByIdentifier()` resolves the **`user_id` string** (session refresh + remember-me path). Implements `PasswordUpgraderInterface` — `upgradePassword()` persists + flushes (documented exception to the no-flush rule, decision D10).
- [ ] `LoginFormAuthenticator extends AbstractLoginFormAuthenticator` — the **single** password authenticator (Symfony has no authenticator fallback chain; first failure short-circuits):
  - `UserBadge($email, loader-by-email)` — badge identifier is the email, user identifier stays `user_id`.
  - `password !== null` → `PasswordCredentials` (automatic verify + `migrate_from` rehash via `PasswordUpgrader`).
  - `password === null && legacy_auth0` → `CustomCredentials` calling `Auth0TrickleGateway::verify(email, plain)` (ROPG `grant_type=http://auth0.com/oauth/grant-type/password-realm`, `realm=<connection>`, send `auth0-forwarded-for` with the real client IP); on success hash locally + persist, so Auth0 is consulted at most once per user. Gateway behind an interface + feature flag so Phase 5 removal is a config change. Handle 401 `password_leaked` distinctly (message: reset required).
  - Badges: `CsrfTokenBadge('authenticate')`, `RememberMeBadge`, `PasswordUpgradeBadge` where applicable.
- [ ] Firewall `main`: replace `auth0.authenticator`/provider/entry point with `custom_authenticators: [LoginFormAuthenticator]`, `entry_point: LoginFormAuthenticator`, `login_link`, `remember_me` (signature-based, `secure: true`, `samesite: lax`, lifetime 30d), `login_throttling` (add `symfony/rate-limiter`), keep `logout` on `app_logout`.
- [ ] Routes: keep names/paths `login` → `/login` (now a local page — `base.html.twig:738` keeps working untouched), add `register`, `password-reset/*`, `verify-email`, `login-link/*`. Delete bundle `callback` route. Keep `app_logout` (single-legged now).
- [ ] Post-login redirect: standard `TargetPathTrait` replaces `Auth0EntryPoint` + `Auth0RedirectSubscriber`. **Must-test:** OAuth2 `/oauth2/authorize` deep-link → login → return round-trip (third-party API clients depend on it).

### 2c. User-facing flows (all new — Auth0 hosted pages covered these; translations en + cs per project i18n rules)

- [ ] Login page: email+password, "Email me a sign-in link", "Forgot password?", registration link, and the migration microcopy (see communication-plan).
- [ ] Registration: form → `RegisterUser` command → handler creates `UserAccount` (`msp|<uuid7>`) + `Player` atomically; password `Compound` constraint (`NotBlank`, `Length(min: 12)`, `PasswordStrength`, `NotCompromisedPassword(skipOnError: true)`); dispatch verification email; programmatic login via `Security::login($user, LoginFormAuthenticator::class, 'main')`.
- [ ] Email verification: `symfonycasts/verify-email-bundle` (≥1.18), anonymous-validation mode (works cross-device); signature generated in the event handler that sends the email.
- [ ] Password reset: `symfonycasts/reset-password-bundle` (≥1.24); keep the fake-token anti-enumeration flow; after successful reset invalidate other reset requests (remember-me self-invalidates via signature).
- [ ] Magic login link: `login_link` firewall config + "email me a link" endpoint (rate-limited), `NotificationLoginLinkNotification` or custom mail through the existing mailer.
- [ ] Change password + change email (with re-verification) on the profile settings page — did not exist in-app before; minimal viable versions.

### 2d. Code sweep

- [ ] `RetrieveLoggedUserProfile`: swap `instanceof Auth0\Symfony\Models\User` → `instanceof UserAccount`; JIT `RegisterUserToPlay` dispatch becomes dead code for native users (registration now creates Player) but keep it as a safety net until Phase 6.
- [ ] `EventSubscriber/OAuth2AuthorizationSubscriber.php:117-135`: replace the Auth0-class branches with `UserAccount`.
- [ ] Sweep 46 `#[CurrentUser]` hints from `Auth0\Symfony\Models\User` → `UserAccount` (mechanical; 25 `UserInterface` hints untouched).
- [ ] Tests: rewrite `tests/TestingLogin.php` + `src/Controller/Test/TestLoginController.php` to fabricate `UserAccount` (firewall/authenticator name updated); fixtures keep `auth0|regular001` ids but gain `UserAccount` rows; add fixture coverage for `msp|`-style native accounts.
- [ ] New tests: authenticator branches (local hash, trickle success/failure/password_leaked, throttling), import handler (idempotency, dup emails, missing email backfill), reset/verify/login-link flows, Panther happy-path login + OAuth2 authorize round-trip.
- [ ] Audit logging: `LoginSuccessEvent`/`LoginFailureEvent`/`LogoutEvent` listeners → Monolog (+ Sentry with `'exception' => $e`); counters for `trickle_used`, `bcrypt_rehashed` (drives Phase 5 exit metric).
- [ ] Quality gates: phpstan, cs-fix, phpunit (excl. panther), schema:validate, cache:warmup.

## Phase 3 — Communication (starts ≥2 weeks before cutover)

See [communication-plan.md](communication-plan.md). Gate on: announcement email sent T-14d, reminder + banner live T-7d, FAQ page published, login-page microcopy translated.

## Phase 4 — Cutover (runbook, low-traffic window)

1. [ ] Freeze: no other deploys; announce in team chat.
2. [ ] Fresh **free** bulk user export from Auth0 (catches users registered/changed since Phase 1 snapshot — their stale hashes are covered by trickle).
3. [ ] Merge + deploy (blue-green as usual; migrations run on container boot).
4. [ ] Run `myspeedpuzzling:import-auth0-users` with the fresh bulk export + the Phase 1 hash export (idempotent upsert).
5. [ ] Force logout: `DELETE FROM sessions;` (PdoSessionHandler table) — one-time, pre-announced.
6. [ ] Smoke tests (production): login with test account (bcrypt → verify argon2id rehash in DB), wrong-password error, password reset end-to-end, login link end-to-end, new registration + verification email, OAuth2 authorize round-trip, PAT API call (must be unaffected), admin voter.
7. [ ] Watch for 48h: Sentry, login success/failure ratio, trickle-hit counter, support inbox.
8. [ ] Rollback (if fundamentally broken): redeploy previous image — Auth0 login resumes against the intact tenant. Native `user_account` rows keep; nothing destructive has happened.

## Phase 5 — Transition window (4–8 weeks)

- [ ] Weekly metric: `SELECT count(*) FILTER (WHERE last_login_at IS NOT NULL) ...` vs 90-day-active baseline (~3,300); trickle-hit counter trend (should decay to ~0).
- [ ] T+4w: nudge email to active-but-not-yet-logged-in players (communication-plan §straggler).
- [ ] Exit criteria: ≥90% of 90-day-active players migrated **and** trickle hits < 1/day for 2 consecutive weeks.

## Phase 6 — Decommission

- [ ] Remove the trickle gateway + feature flag (update `docs/features/feature_flags.md`).
- [ ] `composer remove auth0/symfony`; drop the VCS repository entry; reconsider `minimum-stability: dev`.
- [ ] Delete: `config/packages/auth0.php`, `Auth0EntryPoint`, `Auth0RedirectSubscriber`, `Auth0SdkResetListener`, `AUTH0_*` env vars (repo `.env` + production compose), bundle registration, `RegisterUserToPlay` JIT safety net (after confirming zero hits).
- [ ] Auth0 offboarding: final log export if wanted (free retention is 1 day), Dashboard → Settings → Advanced → Danger Zone → **Delete tenant** (irreversible; tenant name never reusable).
- [ ] Securely delete all export files (hash export especially).
- [ ] Update privacy policy: remove Auth0 as a processor.
- [ ] Post-migration report: final migration %, resets served, support volume, lessons.

## Phase 7 — Post-migration enhancements (backlog, optional)

Passkeys/WebAuthn · 2FA (`scheb/2fa-bundle` v8.6.1, Symfony 8 ready) · per-device session management/revocation UI · social login if ever demanded (`knpuniversity/oauth2-client-bundle` v2.20+).
