# Prompt for the implementing agent

Copy-paste everything below the line into a fresh Claude Code session in this repo.

---

Implement the **auth hardening** feature planned in `docs/features/auth-hardening/README.md`. Read that document fully first — it contains the confirmed scope, the schema designs, the wiring points (with **exact, verified class names** in the "Reality check — implementation map" section — use those, do not invent parallel structures), and the per-provider gotchas. Then read `CLAUDE.md` and follow every project rule in it.

## What you are building

Two workstreams, delivered as **two separate PRs in this order**:

1. **PR 1 — Auth audit trail**: `auth_audit_log` table capturing all auth events (login success/failure, logout, sign-in-link requested/used, password reset requested/completed, password changed, email change requested/verified, registration, Auth0-fallback use) with IP + user agent, a retention prune command, a user-facing `/account/recent-activity` page showing the logged-in user their last 50 events, and the decided GDPR fix: `DeletePlayerHandler` must also remove the `UserAccount` row.
2. **PR 2 — Social login**: Google, Apple, and Facebook sign-in via `league/oauth2-client` provider libraries (plain libraries — the project forbids third-party Symfony bundles). **The data model and linking rules are already settled** in `docs/features/auth-migration/README.md` §Auth-method extensibility (decision D13) — read that section and implement it verbatim: `oauth_identity` table, per-provider authenticators, the five account-linking rules, the ≥1-sign-in-method invariant, "Connected sign-in methods" settings section (list/link/unlink/set-password). Per-provider feature flags **default OFF** (code ships dark; credentials arrive later via Infisical).

## Hard constraints (from CLAUDE.md — violations will be rejected in review)

- All state changes go through Symfony Messenger messages + handlers; controllers only dispatch. Repositories never `flush()`.
- Single-action controllers (`__invoke`), one class per route.
- `Uuid::uuid7()` for new ids, `ClockInterface` instead of `new \DateTimeImmutable()`.
- Generate migrations with `docker compose exec web php bin/console doctrine:migrations:diff` — never write them by hand, and **never run** `doctrine:migrations:migrate` yourself.
- All PHP commands via `docker compose exec web ...`. Do not rebuild JS assets manually (`js-watch` container does it).
- New UI texts in English only, via translation files (`translations/messages.en.yml`); Stimulus texts via data-attributes if any.
- When logging exceptions: `'exception' => $e`, never the message string.
- Update `docs/features/feature_flags.md` for every new flag, and add a short feature pointer in `CLAUDE.md`'s features list.
- After changing PHP code run ALL gates: `docker compose exec web composer run phpstan`, `composer run cs-fix`, `vendor/bin/phpunit --testsuite "Project Test Suite"` (NOT `--exclude-group panther` — outdated), `php bin/console doctrine:schema:validate`, `php bin/console cache:warmup`.
- Test handlers and services directly, not console commands.

## Critical implementation notes (details in the README — do not skip it)

- Audit writes must **never break login**: dispatch sites wrap in try/catch + error log, mirroring `src/EventSubscriber/EmailAuditSubscriber.php`. Extend the existing `src/EventSubscriber/AuthenticationAuditSubscriber.php` (keep its Monolog lines and `last_login_at` write — that flush is a documented exception).
- The `RecordAuthAuditEvent` message must stay **unrouted** (sync) — like `ImportAuth0User`.
- Never store secrets/passwords in the audit `metadata` jsonb.
- Apple: `response_mode=form_post` means the callback is a **cross-site POST** — SameSite=Lax session cookies are absent, so validate OAuth `state` via Symfony cache (TTL 10 min, delete on use), NOT the session. Callback route accepts POST, excluded from CSRF. Name/email arrive only on the user's first authorization — capture then.
- Account linking follows the five settled rules exactly (auto-link only on provider-verified email; unverified → "sign in with your password and connect {provider} from settings"; explicit linking from the settings connect buttons needs NO email match — a different-email provider account links fine, future logins resolve by (provider, provider_user_id)). Rule 4 (no match) must NOT create the account silently in the callback: show the interstitial confirmation page described in the plan's "Linking vs merging" section. Enforce the invariant `password IS NOT NULL OR ≥1 oauth_identity` in both the unlink and remove-password handlers. Login errors stay generic — never reveal which methods an account has. Set-password for social-only accounts reuses the existing `SetAccountPassword` message (shown only when password is null); account merging is out of scope.
- Social accounts are native `msp|<uuid7>` accounts with null password — no new identity namespace.
- Order the provider work: Google first (plain OIDC + PKCE), Facebook second, Apple last (hardest).
- Mock provider HTTP in tests (the league providers accept an injected HTTP client). No Panther tests.

## Working style

- Branch + PR per workstream (base `main`). Run all quality gates green before opening each PR.
- If the plan and the codebase disagree (renamed file, moved config), trust the codebase and note the deviation in the PR description.
- Do not do any provider-console setup, do not touch Infisical or production — env vars stay empty defaults in repo `.env`, flags stay `0`.
- Do not run `doctrine:migrations:migrate` — say in the PR description that migrations are pending.
