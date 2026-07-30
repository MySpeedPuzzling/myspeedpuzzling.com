# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

> **Operational note on the two native-auth flags (since 2c-II).** `NATIVE_REGISTRATION_ENABLED` and `NATIVE_LOGIN_ENABLED` are exposed as Twig globals (`config/packages/twig.php`), so they are now resolved on **nearly every page render** instead of only when `LoginController` is instantiated. That widens the blast radius of an unresolvable env var from "`/login` breaks" to "every page breaks". They are committed in `.env` with `=0` and `.dockerignore` excludes only `.env.*`, so the image always carries a default — verified on production 2026-07-25: the container sets none of them in its environment and Symfony resolves them from `/app/.env`. Keep them resolvable; do not remove them from `.env` when the production overrides are added.

## Native Registration (`NATIVE_REGISTRATION_ENABLED`)

- **Feature:** Auth0 → native auth migration, Stage A (issue #147, `docs/features/auth-migration/`)
- **Flag:** env var `NATIVE_REGISTRATION_ENABLED` → container parameter `nativeRegistrationEnabled` (`config/services.php`)
- **Default:** **ON in the repo `.env` since 2026-07-30 (Stage A)** — the flip ships with the deploy itself, per Jan's call; Auth0 signups are frozen tenant-side ("Disable Sign Ups"). `.env.test` pins it OFF so the test baseline is unchanged (flag tests override per-test via `OverridesFeatureFlagEnv`). Rollback without a revert: set `NATIVE_REGISTRATION_ENABLED=0` in the box `.env` — real env beats the image file.
- **Gated files:**
  - `src/Controller/RegisterController.php` — `/register` renders the native form when ON, redirects to `/login` (the Auth0 hosted page, whose signup tab is frozen at Stage A) when OFF
  - `templates/base.html.twig` — the "Register" navbar tool for anonymous visitors. Load-bearing while `NATIVE_LOGIN_ENABLED` is still OFF: `/login` is the Auth0 redirect then, so this is the only signpost to the native form
  - `templates/login.html.twig` — the "Create an account" link under the sign-in form (only rendered when login is also native)
- **Also gated (added 2c-II):** `/password-reset` and `/password-reset/{token}` redirect to `/login` unless **either** this flag or `NATIVE_LOGIN_ENABLED` is ON. Before Stage A the `user_account` table is empty, so the page could only ever answer "if an account exists, a link is on its way" and then send nothing — a dead end made *silent* by the anti-enumeration uniformity the page needs everywhere else. Gated on the OR rather than on this flag alone so a rollback of registration cannot take password reset away from accounts that already exist.
- **Not gated on purpose:** the pages a native account needs once it exists — `/welcome`, `/verify-email`, `/resend-email-verification`, the native change-password and change-email cards — follow the account class in the session, not this flag. A window-A registrant must be able to use them the moment they have an account.
- **Remove when:** Phase 6 decommission (~Sep 2026), together with the trickle gateway and Auth0 tenant

## Native Login (`NATIVE_LOGIN_ENABLED`)

- **Feature:** Auth0 → native auth migration, Stage B (issue #147)
- **Flag:** env var `NATIVE_LOGIN_ENABLED` → container parameter `nativeLoginEnabled` (`config/services.php`)
- **Default:** OFF everywhere. Flipped ON in production at Stage B cutover (native login page + entry point replace the Auth0 redirect). Rollback = flip OFF; Auth0 login resumes against the intact tenant.
- **Gated files:**
  - `src/Controller/LoginController.php` — `/login` renders the native form when ON, hands over to the Auth0 bundle controller when OFF
  - `templates/login.html.twig` — reached only when ON; carries the cutover explainer modal (D15) and the "Create an account" link
  - `src/Controller/RegistrationWelcomeController.php` — while OFF, the welcome screen warns a fresh registrant that being signed out means falling back to the sign-in link; the warning retires when login goes native
  - `templates/_sign_in_changes_notice.html.twig` + `templates/base.html.twig` (added 2d, 2026-07-30) — the site-wide notice switches from the "coming" to the "changed" wording when this flag turns ON, and the localStorage dismissal key switches with it (`sign-in-changes-notice-dismissed` → `…-dismissed-changed`) so everyone sees the new wording once
- **Not gated on purpose:** the magic sign-in link (`/login-link`, `/login-link/check`, `/set-password`) is live from Stage A per D6 — it is the rescue for window-A native registrants who log out while `/login` still points at Auth0. The native change-password/change-email cards on profile settings are likewise not flag-gated: `templates/edit-profile.html.twig` branches on whether the session holds a `UserAccount`, so a native account gets the native cards from the day it exists and a legacy Auth0 session keeps the #161 reset-email button until the Stage B import
- **Remove when:** Phase 6 decommission

## Sign-in Migration Notice (`SIGN_IN_CHANGES_NOTICE_ENABLED`)

- **Feature:** advance announcement of the Auth0 → native sign-in migration (issue #147) — the site-wide notice pointing at the explainer page
- **Flag:** env var `SIGN_IN_CHANGES_NOTICE_ENABLED` → Twig global `sign_in_changes_notice_enabled` (`config/packages/twig.php`)
- **Default:** **ON**, unlike every other flag here. It is announcement copy rather than a feature: it went live ahead of Stage A so nobody meets the change unwarned. The switch exists to retire the notice (~4 weeks after Stage B), not to hold it back.
- **Gated files:**
  - `templates/base.html.twig` — the notice include and the inline dismissal script/style in `<head>`
- **Not gated:** the explainer page itself (`/en/sign-in-is-moving` and its five locale paths) stays reachable either way — emails and support replies link to it.
- **Remove when:** ~4 weeks after Stage B, when the banner comes down (the page stays as long as dormant players keep returning)

## Auth0 Trickle Login (`AUTH0_TRICKLE_LOGIN_ENABLED`)

- **Feature:** Auth0 → native auth migration — ROPG fallback branch inside the login authenticator (decision D4): imported `legacy_auth0` accounts without a local password hash verify against Auth0 once, then the password is hashed locally
- **Flag:** env var `AUTH0_TRICKLE_LOGIN_ENABLED` → bind `$auth0TrickleLoginEnabled` (`config/services.php`)
- **Default:** OFF in dev/prod, ON in `.env.test` (tests stub the verifier with `PredictableTrickleVerifier`, no real Auth0 calls). Flipped ON in production at Stage B for the transition window. Independent of `NATIVE_LOGIN_ENABLED` so trickle can be disabled operationally (e.g. Auth0 Attack Protection misfiring) without turning off native login.
- **Gated files:**
  - `src/Security/LoginFormAuthenticator.php` — trickle credential branch
- **Remove when:** Phase 6 decommission (delete `Auth0TrickleGateway` + this flag)

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers
