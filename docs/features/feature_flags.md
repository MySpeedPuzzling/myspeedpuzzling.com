# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

> **Operational note on flags exposed as Twig globals.** A flag registered in `config/packages/twig.php` is resolved on **nearly every page render**, not only where it is used - an unresolvable env var there breaks every page, not one. The image always carries the defaults from the committed `.env` (`.dockerignore` excludes only `.env.*`; production resolves them from `/app/.env` unless the box overrides them). Keep every such flag defined in `.env`.

## Retired: Auth0 migration flags (removed 2026-09-18, Phase 6)

`NATIVE_REGISTRATION_ENABLED`, `NATIVE_LOGIN_ENABLED`, `AUTH0_TRICKLE_LOGIN_ENABLED`, `AUTH0_FALLBACK_LOGIN_ENABLED` and `SIGN_IN_CHANGES_NOTICE_ENABLED` were deleted with the Auth0 stack (`docs/features/auth-migration/implementation-plan.md` Phase 6). Native registration, login and password reset are unconditional; the trickle gateway, the `/login/auth0` fallback, the `/login` footnote and the "sign-in is moving" explainer page (its URLs now 301 to `/login`) are gone. A box `.env` that still sets any of them is harmless - nothing reads them.

## Social Login — per-provider flags (`SOCIAL_LOGIN_GOOGLE_ENABLED`, `SOCIAL_LOGIN_FACEBOOK_ENABLED`, `SOCIAL_LOGIN_APPLE_ENABLED`)

- **Feature:** Google/Apple/Facebook sign-in (auth hardening PR 2, `docs/features/auth-hardening/README.md`)
- **Flag:** env vars → parameters `socialLoginGoogleEnabled`/`socialLoginFacebookEnabled`/`socialLoginAppleEnabled` (`config/services.php`), read through the `SocialLoginSettings` service and exposed as Twig globals `social_login_*_enabled` (`config/packages/twig.php`). Twig globals — keep them resolvable in `.env` (see the operational note at the top).
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
