# Auth0 Migration — Step-by-Step Implementation Plan

Companion to [README.md](README.md) (analysis & decisions) and [communication-plan.md](communication-plan.md).

**Rollout model (D14, 2026-07-23):** two user-visible stages. Stage A flips *registrations* to native and freezes Auth0 signups → the paid hash export then covers 100% of Auth0 users. Stage B flips *login* ~1 week later. Phases 0 and 2 run in parallel starting now; Phase 1 triggers the day Stage A ships; Stage B is gated on the export being imported (hard decision gate at Stage A + 14d).

**Target calendar** (start 2026-07-23):

| Milestone | Target |
|---|---|
| Phase 0 prep + Phase 2 build | Jul 23 → ~Jul 29 |
| **Stage A** deploy + announcement + **pay Auth0 + open ticket** | ~Jul 29–31 |
| Hash export delivered (no SLA — estimate) | ~Aug 4–8 |
| **Stage B** cutover (login flips, modal live) | ~Aug 6–12 |
| Hard gate if export still missing | Aug 14 (trickle-primary decision with Jan) |
| Straggler nudge | Stage B + 2w (~Aug 26) |
| Transition exit review | w/c Sep 1 |
| Phase 6 decommission (tenant deleted) | ~Sep 8–15 |

Ownership: **[Jan]** = needs the Auth0 dashboard / billing / final say. **[build]** = Claude/code work. Unmarked = either.

---

## Phase 0 — Preparation & data (free, this week, parallel with build)

- [ ] **0.1 [Jan]** Run a free Bulk User Export from Auth0 (`POST /api/v2/jobs/users-exports` or the User Import/Export extension): NDJSON, fields `user_id`, `email`, `email_verified`, `name`, `created_at`, `last_login`, `logins_count`, `identities`. Store outside the repo (contains PII). *(Alternative: create a short-lived Management API token with `read:users` and hand it over — then this and 0.3 run without you.)*
- [ ] **0.2 [Jan]** Check real MAU in the Auth0 dashboard → confirms the $35 (≤500 MAU) Essentials tier.
- [ ] **0.3 [build]** Reconciliation script (one-off, scratchpad — not committed): join export against `player` on `user_id`. Expect and resolve:
  - 7 duplicate-email player pairs → the row whose `user_id` is absent from the export is a deleted Auth0 account; it gets no `user_account` (cannot log in today either). Verify each pair manually.
  - 3 players with `user_id` but `email IS NULL` → backfill email from export.
  - Players in export missing locally / locally missing from export → list and decide per case.
- [ ] **0.4** Email deliverability (D12): confirm seznam.cz SMTP daily limits, SPF/DKIM/DMARC alignment. The announcement goes to **all ~10k users with an email** as an operational/service email (not marketing — no newsletter-consent dependency), batched through Messenger with a rate limiter over 1–2 days. If seznam limits are tight, fall back to listmonk for the bulk send and keep SMTP for transactional (reset/verify/login-link) only.
- [ ] **0.5 [Jan]** ROPG smoke test against the tenant (free): enable Password grant on a (test) application, set tenant Default Directory to `Username-Password-Authentication`, `curl POST /oauth/token` with a test account. Confirms the trickle branch and the export-delay contingency actually work before we depend on them.
- [ ] **0.6 [Jan]** Locate the **Disable Sign Ups** toggle (Dashboard → Authentication → Database → Username-Password-Authentication) — flipped on Stage A day, so know where it is.

## Phase 1 — Paid hash export (~$35, triggered the day Stage A ships)

Sequencing matters: signups are frozen at Stage A, so a single export generated *after* that covers every Auth0 user — no snapshot gap (D14).

- [ ] **1.1 [Jan]** Upgrade tenant to **B2C Essentials, monthly billing**: Dashboard → Settings → Billing → upgrade. (~$35/mo at ≤500 MAU; keep it active until the file is delivered **and validated**.)
- [ ] **1.2 [Jan]** Immediately open the support ticket at support.auth0.com → "I have a question regarding my Auth0 account". Suggested text:
  > Subject: Password hashes export request
  >
  > We are migrating our application to first-party authentication and request an export of password hashes for tenant `speedpuzzling.eu.auth0.com` (EU), database connection `Username-Password-Authentication`. All users are database-connection identities; there are no social or passwordless users. Please include: `_id`/`user_id`, `email`, `email_verified`, `passwordHash`, `password_set_date`, `connection`. NDJSON preferred. We upgraded to Essentials for this purpose. Signups on the connection are disabled, so a one-time export is sufficient.
- [ ] **1.3** On delivery, validate: line count vs the fresh bulk export; every `passwordHash` matches `^\$2b\$10\$`; spot-check one known test account with `password_verify()`.
- [ ] **1.4** Store the file encrypted, restricted access (bcrypt hashes + emails = sensitive PII). Delete all copies after Phase 6.
- [ ] **1.5 [Jan]** Downgrade tenant back to Free (self-service; do **not** delete the tenant — it backs the trickle fallback and rollback until Phase 6).

## Phase 2 — Build native auth ([build], feature branch; deployable dark — nothing user-visible until the Stage A/B flag flips)

### 2a. Data model

- [x] `UserAccount` entity per README spec (`user_id` unique, `email` unique lower-indexed, nullable `password`, `email_verified_at`, `legacy_auth0` flag, timestamps). Generated migration (never hand-written, per project rules).
- [x] `reset_password_request` table — own split-token implementation (decision 2026-07-24: `symfonycasts/reset-password-bundle` dropped, its repository contract flushes inside repositories and fights the CQRS rules). Entity + `PasswordResetToken` value object + `RequestPasswordReset`/`ResetPassword` handlers + `ValidatePasswordResetToken` service, generated migration, handler tests. UI/email = 2c.
- [x] Future-proofing guardrails only, no social tables now (README §Auth-method extensibility): provider-agnostic `user_id` (`msp|<uuid7>`), password on `user_account`, unique email.
- [x] Import command: `myspeedpuzzling:import-auth0-users <bulk-export.ndjson> <hash-export.ndjson>` — parses/joins, dispatches `ImportAuth0User` messages (batch); handler upserts `UserAccount` keyed on `user_id` (never email), bcrypt hash as-is, `email_verified_at` from flag, `legacy_auth0 = true`, backfills `player.email`/`player.name` where NULL. Idempotent — re-runnable with fresh exports. Tests test the handler directly.

### 2b. Security plumbing

- [x] `config/packages/security.php`: `password_hashers` for `UserAccount` → `algorithm: 'argon2id'`, `migrate_from: ['bcrypt']`. *(Pulled forward into the 2a slice — the native `ResetPasswordHandler` needs the hasher.)*
- [ ] `UserAccountProvider`: `loadUserByIdentifier()` resolves the **`user_id` string** (session refresh + remember-me + login-link path). Implements `PasswordUpgraderInterface` — `upgradePassword()` persists + flushes (documented exception to the no-flush rule, D10).
- [ ] `LoginFormAuthenticator extends AbstractLoginFormAuthenticator` — the **single** password authenticator:
  - `UserBadge($email, loader-by-email)` — badge identifier is the email, user identifier stays `user_id`.
  - `password !== null` → `PasswordCredentials` (automatic verify + `migrate_from` rehash).
  - `password === null && legacy_auth0` → `CustomCredentials` → `Auth0TrickleGateway::verify(email, plain)` (ROPG `password-realm`, `realm=<connection>`, `auth0-forwarded-for` with real client IP); on success hash locally + persist (Auth0 consulted at most once per user). Behind an interface + feature flag. Handle 401 `password_leaked` distinctly (message: reset required).
  - Badges: `CsrfTokenBadge('authenticate')` (stateless — already in `config/packages/csrf.php`), `RememberMeBadge`, `PasswordUpgradeBadge`.
- [ ] **Window-A dual wiring** (Stage A → Stage B): `main` firewall runs `custom_authenticators: [LoginFormAuthenticator, 'auth0.authenticator']` with a **chain provider** (`user_account_provider` first, then `auth0_provider`) so both session user classes refresh. Entry point stays `Auth0EntryPoint` until Stage B. Verify: Auth0 authenticator failure returns null response (must not short-circuit the chain) and Auth0's `loadUserByIdentifier` doesn't greedily fabricate users — **explicit functional test before Stage A ships**.
- [ ] Stage B firewall state: entry point → `LoginFormAuthenticator`, add `login_link`, `remember_me` (signature-based, `secure: true`, `samesite: lax`, 30d), `login_throttling` (add `symfony/rate-limiter`), keep `logout` on `app_logout` (single-legged now).
- [ ] Feature flags `native_registration` (Stage A) + `native_login` (Stage B) — deploy ≠ flip, rollback = flag off. **Document both in `docs/features/feature_flags.md`** (project rule) incl. removal date (Phase 6).
- [ ] Routes: `login` stays `/login` (native page at Stage B — `base.html.twig` link untouched), add `register`, `password-reset/*`, `verify-email`, `login-link/*`. Delete bundle `callback` route at Stage B, keep until then.
- [ ] Post-login redirect: `TargetPathTrait` replaces `Auth0EntryPoint` + `Auth0RedirectSubscriber` at Stage B. **Must-test:** OAuth2 `/oauth2/authorize` deep-link → login → return round-trip (third-party API clients depend on it).
- [ ] **Anonymous-cacheability regression check** (README §constraint): no session start on anonymous GETs, login GET session-free, `AnonymousCacheHeadersSubscriber` behavior unchanged — verify in test env per the #164 method.

### 2c. User-facing flows (all new; **all 6 locales** en/cs/de/es/fr/ja per D17)

- [ ] Login page: email+password, prominent "Email me a sign-in link" secondary CTA, "Forgot password?", registration link, persistent migration microcopy (communication-plan).
- [ ] **Login-failure helper** (UX funnel §4): failed attempt reveals inline box — vault-search tip ("search your password manager for speedpuzzling or auth0") + one-click sign-in-link button pre-filled with the typed email. Same rendering regardless of account existence.
- [ ] **Cutover explainer modal** (D15, UX funnel §2): one-time auto-modal on the login page when `native_login` is on; localStorage-dismissed (works for anonymous); content per communication-plan. Respect the existing modal/Turbo patterns (`.claude/symfony-ux-hotwire-architecture-guide.md`).
- [ ] Site-wide dismissable banner (T-7d "coming" wording → Stage B "changed" wording → removed ~B+4w). Reuse the hint-dismissing pattern for logged-in users where it fits; localStorage for anonymous.
- [ ] Registration: form → `RegisterUser` command → handler creates `UserAccount` (`msp|<uuid7>`) + `Player` atomically; password `Compound` constraint (`NotBlank`, `Length(min: 12)`, `PasswordStrength`, `NotCompromisedPassword(skipOnError: true)`); verification email; programmatic login via `Security::login()`.
- [ ] Email verification flow (UI + email) on top of the native domain layer already built (decision 2026-07-24: no auth bundles — `verify-email-bundle` dropped like the reset bundle; stateless HMAC token via `EmailVerificationTokenSigner` binds `user_id` + email + 24h expiry, `VerifyEmail` handler is idempotent, anonymous-validation works since the token is self-contained, and a link dies when the address changes — `UserAccount::changeEmail()` also resets `email_verified_at` for the change-email flow below).
- [ ] Password reset flow (UI + email) on top of the native domain layer built in 2a (`RequestPasswordReset` returns the token to send, or null for unknown/throttled — respond identically in both cases for anti-enumeration; `ResetPassword` consumes the token and invalidates all other open requests). No bundle (decision 2026-07-24).
- [ ] Magic login link: `login_link` config + rate-limited "email me a link" endpoint; **live from Stage A** (rescues window-A native registrants who log out).
- [ ] **Post-magic-link password setup** (UX funnel §5): after link login on a `legacy_auth0` account, one-time skippable prompt — `autocomplete="new-password"` field + "Suggest strong password" button that fills the field (editable/copyable, NOT readonly). Skipping keeps the old password.
- [ ] Change password (native): current password + new password on profile settings — **replaces the #161 Auth0 flow at Stage B** (swap the edit-profile "Password" card action; remove `RequestPasswordChangeController`, `RequestPasswordChangeHandler`, `Services/Auth0DatabaseConnection`, `AUTH0_DB_CONNECTION` at Stage B; reuse/adjust the existing 7 translation keys ×6 locales).
- [ ] Change email with re-verification: minimal viable version.

### 2d. Code sweep

- [ ] `RetrieveLoggedUserProfile`: handle `UserAccount` (window A: both classes); JIT `RegisterUserToPlay` becomes dead code for native users but stays as safety net until Phase 6.
- [ ] `EventSubscriber/OAuth2AuthorizationSubscriber.php`: replace Auth0-class branches with `UserAccount` (window A: accept both).
- [ ] Sweep `Auth0\Symfony\Models\User` references — **50 files as of 2026-07-23** (was 46 on 07-11; #161 added `RequestPasswordChangeController` a.o.): `#[CurrentUser]` hints → `UserAccount`; 25 `UserInterface` hints untouched.
- [ ] Tests: rewrite `tests/TestingLogin.php` + `src/Controller/Test/TestLoginController.php` + `tests/Panther/AbstractPantherTestCase.php` to fabricate `UserAccount`; fixtures keep `auth0|regular001` ids but gain `UserAccount` rows; add `msp|`-style native fixtures.
- [ ] New tests: authenticator branches (local hash, trickle success/failure/`password_leaked`, throttling), window-A dual wiring (native register → session persists → browse; Auth0 test-login still works), import handler (idempotency, dup emails, email backfill), reset/verify/login-link flows, post-link password prompt, Panther login happy-path + OAuth2 authorize round-trip.
- [ ] Audit logging: `LoginSuccessEvent`/`LoginFailureEvent`/`LogoutEvent` listeners → Monolog (+ Sentry, always `'exception' => $e`); counters `trickle_used`, `bcrypt_rehashed`, `login_link_used`, `password_prompt_completed` (drive Phase 5 exit metrics).
- [ ] Quality gates: `composer run phpstan`, `composer run cs-fix`, `vendor/bin/phpunit --testsuite "Project Test Suite"` (Panther re-enabled since d029829e — run full suite incl. Panther before each stage flips), `doctrine:schema:validate`, `cache:warmup`.

## Phase 3 — Communication (compressed: announcement = Stage A day)

See [communication-plan.md](communication-plan.md). Gates: Stage A may not ship until announcement email + FAQ + banner are translated (6 locales) and ready to go out the same day. Stage B may not flip until the modal + login microcopy are live-tested.

## Phase 4A — Stage A runbook (~Jul 29–31)

1. [ ] Full test suite green incl. Panther; window-A dual-wiring functional test green.
2. [ ] Deploy (merge → GitHub Actions → lily webhook → blue-green as usual; migrations run on web boot). `native_registration` flag ON, `native_login` OFF.
3. [ ] **[Jan]** Auth0 dashboard: Database connection → **Disable Sign Ups** ON.
4. [ ] Smoke: native registration end-to-end (account + player + verification email + logged in), Auth0 login still works, register CTA points at native form.
5. [ ] Send announcement email (batched); banner ON; FAQ live; socials post.
6. [ ] **[Jan]** Pay Essentials + open the hash-export ticket (Phase 1.1–1.2) — same day.
7. [ ] Watch Sentry + registration funnel for 24h.

## Phase 4B — Stage B runbook (export imported; target ~Aug 6–12, low-traffic window)

1. [ ] Freeze other deploys; fresh **free** bulk user export (metadata/reconciliation delta).
2. [ ] Validate hash export (Phase 1.3); run import locally against a prod dump first, then on prod:
   `ssh lily.srv.thedevs.cz` → `cd /srv/myspeedpuzzling` → copy export files in → `docker compose exec web php bin/console myspeedpuzzling:import-auth0-users bulk.ndjson hashes.ndjson` → shred the copies.
3. [ ] Deploy with `native_login` ON (entry point + routes flip; modal + login microcopy live).
4. [ ] Force logout: `docker compose exec db psql -U speedpuzzling -d speedpuzzling -c 'DELETE FROM sessions;'` (one-time, pre-announced).
5. [ ] Smoke (production): login with legacy test account (bcrypt → verify argon2id rehash in DB), wrong-password → failure helper appears, sign-in link end-to-end + password prompt, password reset end-to-end, new registration, native change-password, OAuth2 authorize round-trip, PAT API call unaffected, admin voter, anonymous page still `public, s-maxage=60`.
6. [ ] Watch 48h: Sentry, login success/failure ratio, `trickle_used` counter, support inbox.
7. [ ] Rollback if fundamentally broken: `native_login` OFF (+ redeploy previous image if needed) — Auth0 login resumes against the intact tenant; imported rows keep, nothing destructive happened.

**Export-delay gate (Aug 14):** if the ticket hasn't delivered by Stage A + 14d, decide **with Jan** whether to run Stage B trickle-primary (every legacy first-login validates once via ROPG — requires 0.5 verified + Attack Protection allowances) or hold. The comms "on {date}" wording must carry a small buffer or say "week of".

## Phase 5 — Transition window (3–4 weeks after Stage B)

- [ ] Weekly: migrated count (`user_account.last_login_at IS NOT NULL` among `legacy_auth0`) vs 90-day-active baseline (~3,300); `trickle_used` trend (→ ~0); login-link usage; support ticket tags.
- [ ] B+2w: straggler nudge email (communication-plan §straggler) to active-in-6-months not-yet-migrated.
- [ ] Exit criteria: ≥90% of 90-day-active players migrated **and** trickle hits < 1/day for 2 consecutive weeks.

## Phase 6 — Decommission (~Sep 8–15)

- [ ] Remove trickle gateway + both feature flags (update `docs/features/feature_flags.md`).
- [ ] `composer remove auth0/symfony`; drop the VCS repository entry; reconsider `minimum-stability: dev`.
- [ ] Delete: `config/packages/auth0.php`, `Auth0EntryPoint`, `Auth0RedirectSubscriber`, `Auth0SdkResetListener`, remaining `AUTH0_*` env vars (repo `.env` + production compose), bundle registration, `RegisterUserToPlay` JIT safety net (after confirming zero hits), window-A dual wiring remnants.
- [ ] **[Jan]** Auth0 offboarding: final log export if wanted, Dashboard → Settings → Advanced → Danger Zone → **Delete tenant** (irreversible).
- [ ] Securely delete all export files (hash export especially — all copies, incl. on lily).
- [ ] Update privacy policy: remove Auth0 as a processor.
- [ ] Post-migration report: final migration %, trickle/link/reset counts, support volume, lessons.

## Phase 7 — Post-migration enhancements (backlog, optional)

Google/Facebook (maybe Apple) login per the settled `oauth_identity` design (README §Auth-method extensibility) · Passkeys/WebAuthn · 2FA (`scheb/2fa-bundle` v8.6.1) · per-device session management/revocation UI.
