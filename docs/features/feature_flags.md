# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

> **Operational note on the two native-auth flags (since 2c-II).** `NATIVE_REGISTRATION_ENABLED` and `NATIVE_LOGIN_ENABLED` are exposed as Twig globals (`config/packages/twig.php`), so they are now resolved on **nearly every page render** instead of only when `LoginController` is instantiated. That widens the blast radius of an unresolvable env var from "`/login` breaks" to "every page breaks". They are committed in `.env` with `=0` and `.dockerignore` excludes only `.env.*`, so the image always carries a default — verified on production 2026-07-25: the container sets none of them in its environment and Symfony resolves them from `/app/.env`. Keep them resolvable; do not remove them from `.env` when the production overrides are added.

## Native Registration (`NATIVE_REGISTRATION_ENABLED`)

- **Feature:** Auth0 → native auth migration, Stage A (issue #147, `docs/features/auth-migration/`)
- **Flag:** env var `NATIVE_REGISTRATION_ENABLED` → container parameter `nativeRegistrationEnabled` (`config/services.php`)
- **Default:** **ON in the repo `.env` since 2026-07-30 (Stage A)** — the flip ships with the deploy itself, per Jan's call; Auth0 signups are frozen tenant-side ("Disable Sign Ups"). The test env deliberately inherits this baseline (tests face what users face, per Jan 2026-07-30); the flag-OFF side is covered by explicit per-test overrides (`OverridesFeatureFlagEnv`). Rollback without a revert: set `NATIVE_REGISTRATION_ENABLED=0` in the box `.env` — real env beats the image file.
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
  - ~~`templates/_sign_in_changes_notice.html.twig` + `templates/base.html.twig`~~ (added 2d, 2026-07-30; **removed 2026-08-19**) — the site-wide strip used to switch from the "coming" to the "changed" wording on this flag. The strip is gone; the notice is now a footnote on `templates/login.html.twig`, which is only reached with this flag ON, so it always carries the "changed" wording and no longer branches
- **Not gated on purpose:** the magic sign-in link (`/login-link`, `/login-link/check`, `/set-password`) is live from Stage A per D6 — it is the rescue for window-A native registrants who log out while `/login` still points at Auth0. The native change-password/change-email cards on profile settings are likewise not flag-gated: `templates/edit-profile.html.twig` branches on whether the session holds a `UserAccount`, so a native account gets the native cards from the day it exists and a legacy Auth0 session keeps the #161 reset-email button until the Stage B import
- **Remove when:** Phase 6 decommission

## Sign-in Migration Notice (`SIGN_IN_CHANGES_NOTICE_ENABLED`)

- **Feature:** announcement of the Auth0 → native sign-in migration (issue #147) — the notice pointing at the explainer page. Until 2026-08-19 it was a dismissable site-wide strip above the navbar (`_sign_in_changes_notice.html.twig`, localStorage dismissal, inline `<head>` hide script, `sign_in_changes_notice_controller.js`, `tests/Panther/SignInChangesNoticeTest.php`); all of that is gone. It is now a quiet `fs-xs text-muted` footnote under the card on the sign-in page, reusing the same `sign_in_changes.notice.text_changed` / `text_extra_changed` / `link` keys (the "coming" `text`/`text_extra` and `dismiss` keys were deleted)
- **Flag:** env var `SIGN_IN_CHANGES_NOTICE_ENABLED` → Twig global `sign_in_changes_notice_enabled` (`config/packages/twig.php`)
- **Default:** **ON**, unlike every other flag here. It is announcement copy rather than a feature: it went live ahead of Stage A so nobody meets the change unwarned. The switch exists to retire the notice, not to hold it back.
- **Gated files:**
  - `templates/login.html.twig` — the footnote under the sign-in card
- **Not gated:** the explainer page itself (`/en/sign-in-is-moving` and its five locale paths) stays reachable either way — emails and support replies link to it.
- **Remove when:** the footnote comes down — latest with the Auth0 stack in Phase 6 (the page stays as long as dormant players keep returning)

## Auth0 Trickle Login (`AUTH0_TRICKLE_LOGIN_ENABLED`)

- **Feature:** Auth0 → native auth migration — ROPG fallback branch inside the login authenticator (decision D4): imported `legacy_auth0` accounts without a local password hash verify against Auth0 once, then the password is hashed locally
- **Flag:** env var `AUTH0_TRICKLE_LOGIN_ENABLED` → bind `$auth0TrickleLoginEnabled` (`config/services.php`)
- **Default:** OFF in dev/prod, ON in `.env.test` (tests stub the verifier with `PredictableTrickleVerifier`, no real Auth0 calls). Flipped ON in production at Stage B for the transition window. Independent of `NATIVE_LOGIN_ENABLED` so trickle can be disabled operationally (e.g. Auth0 Attack Protection misfiring) without turning off native login.
- **Gated files:**
  - `src/Security/LoginFormAuthenticator.php` — trickle credential branch
- **Remove when:** Phase 6 decommission (delete `Auth0TrickleGateway` + this flag)

## Auth0 Fallback Login (`AUTH0_FALLBACK_LOGIN_ENABLED`)

- **Feature:** Auth0 → native auth migration — transition-window escape hatch after the Stage B flip (2026-07-30): `/login/auth0` starts the old hosted Universal Login redirect, and the native login page + failure helper carry a subdued link to it. Exists for the one failure native login cannot absorb: a password changed through Auth0 after the hash export was cut (stale local hash — the new password only works on Auth0).
- **Flag:** env var `AUTH0_FALLBACK_LOGIN_ENABLED` → parameter `auth0FallbackLoginEnabled` + bind `$auth0FallbackLoginEnabled` + Twig global `auth0_fallback_login_enabled` (`config/services.php`, `config/packages/twig.php`). Same Twig-global blast radius as the two NATIVE_* flags (see the operational note at the top) — keep it resolvable in `.env`.
- **Default:** ON (shipped ON with the Stage B flip — it is the safety net, not the feature)
- **Gated files:**
  - `src/Controller/Auth0FallbackLoginController.php` — 404s when OFF
  - `templates/login.html.twig` — tertiary "old Auth0 page" link
  - `templates/_login_failure_helper.html.twig` — "changed your password recently?" line
- **Remove when:** Phase 6 decommission (the hosted flow it links to dies with the Auth0 stack; flip OFF earlier if usage hits zero)

## Social Login — per-provider flags (`SOCIAL_LOGIN_GOOGLE_ENABLED`, `SOCIAL_LOGIN_FACEBOOK_ENABLED`, `SOCIAL_LOGIN_APPLE_ENABLED`)

- **Feature:** Google/Apple/Facebook sign-in (auth hardening PR 2, `docs/features/auth-hardening/README.md`)
- **Flag:** env vars → parameters `socialLoginGoogleEnabled`/`socialLoginFacebookEnabled`/`socialLoginAppleEnabled` (`config/services.php`), read through the `SocialLoginSettings` service and exposed as Twig globals `social_login_*_enabled` (`config/packages/twig.php`). Same Twig-global blast radius as the NATIVE_* flags — keep them resolvable in `.env`.
- **Default:** OFF (code ships dark; credentials empty in the repo). Each flips independently via Infisical once its provider console setup (Google Cloud / Meta developers / Apple Developer) is done.
- **Gated files:**
  - `src/Security/{Google,Facebook,Apple}LoginAuthenticator.php` — `supports()` refuses callbacks for a disabled provider (via `SocialLoginSettings`)
  - `src/Controller/SocialLoginStartController.php`, `SocialConnectController.php`, `SocialLoginCallbackController.php` — 404 for a disabled provider
  - `templates/_social_login_buttons.html.twig` — per-provider button rendering on `/login` + `/register`
  - `templates/edit-profile.html.twig` — per-provider connect buttons; the whole "Connected sign-in methods" card hides when no provider is enabled. Unlink is deliberately NOT flag-gated (`UnlinkSocialIdentityController`) — a linked identity must stay removable after its provider is switched off
- **Remove when:** never (operational kill switches per provider), unless a provider is retired

## Social Login — admin-only rollout (`SOCIAL_LOGIN_ADMIN_ONLY`)

- **Feature:** staged rollout of social login (auth hardening PR 2)
- **Flag:** env var `SOCIAL_LOGIN_ADMIN_ONLY` → parameter `socialLoginAdminOnly`, read through `SocialLoginSettings` + Twig global `social_login_admin_only`
- **Default:** **ON** — even with a provider enabled, social login stays invisible to the public until flipped to `0` after end-to-end verification in production. While ON:
  - `/login` and `/register` render **no social buttons for anyone** (`templates/_social_login_buttons.html.twig`) — those pages must stay uniform for every visitor; admins test via the direct `/login/social/{provider}` URLs
  - the callback denies non-admin accounts with a generic failure (`SocialLoginAdminOnlyGuard`, used by `SocialAccountResolver` and the link/unlink handlers — admin = `player.isAdmin`, same source as `AdminAccessVoter`)
  - rule-4 registration is disabled entirely (`RegisterWithOauthIdentityHandler` throws, the resolver never parks a profile, `SocialRegisterConfirmController` 404s)
  - the edit-profile "Connected sign-in methods" card renders only for `is_granted('ADMIN_ACCESS')`
- **Remove when:** social login is verified publicly live and stable (~a few weeks after public launch)

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers
