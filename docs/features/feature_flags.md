# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

## Native Registration (`NATIVE_REGISTRATION_ENABLED`)

- **Feature:** Auth0 → native auth migration, Stage A (issue #147, `docs/features/auth-migration/`)
- **Flag:** env var `NATIVE_REGISTRATION_ENABLED` → container parameter `nativeRegistrationEnabled` (`config/services.php`)
- **Default:** OFF everywhere. Flipped ON in production on Stage A day (native `/register` goes live, Auth0 signups frozen). Rollback = flip OFF.
- **Gated files:** none yet — the registration flow ships in the 2c build slice and must consume this parameter
- **Remove when:** Phase 6 decommission (~Sep 2026), together with the trickle gateway and Auth0 tenant

## Native Login (`NATIVE_LOGIN_ENABLED`)

- **Feature:** Auth0 → native auth migration, Stage B (issue #147)
- **Flag:** env var `NATIVE_LOGIN_ENABLED` → container parameter `nativeLoginEnabled` (`config/services.php`)
- **Default:** OFF everywhere. Flipped ON in production at Stage B cutover (native login page + entry point replace the Auth0 redirect). Rollback = flip OFF; Auth0 login resumes against the intact tenant.
- **Gated files:**
  - `src/Controller/LoginController.php` — `/login` renders the native form when ON, hands over to the Auth0 bundle controller when OFF
- **Not gated on purpose:** the magic sign-in link (`/login-link`, `/login-link/check`, `/set-password`) is live from Stage A per D6 — it is the rescue for window-A native registrants who log out while `/login` still points at Auth0
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
