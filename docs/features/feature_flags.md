# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

> **Operational note on flags exposed as Twig globals.** A flag registered in `config/packages/twig.php` is resolved on **nearly every page render**, not only where it is used - an unresolvable env var there breaks every page, not one. The image always carries the defaults from the committed `.env` (`.dockerignore` excludes only `.env.*`; production resolves them from `/app/.env` unless the box overrides them). Keep every such flag defined in `.env`.

## Retired: `FREE_TRIAL_ENABLED` (removed 2026-09-21)

The free trial (`docs/features/free-trial/README.md`) shipped dark behind it for a few hours and was then rolled out to everyone - Jan's call. Nothing reads it; a box `.env` that still sets it is harmless. There is no kill switch: pausing the offer means a code change (`PlayerProfile::canStartFreeTrial()` is the one door).

## Retired: Auth0 migration flags (removed 2026-09-18, Phase 6)

`NATIVE_REGISTRATION_ENABLED`, `NATIVE_LOGIN_ENABLED`, `AUTH0_TRICKLE_LOGIN_ENABLED`, `AUTH0_FALLBACK_LOGIN_ENABLED` and `SIGN_IN_CHANGES_NOTICE_ENABLED` were deleted with the Auth0 stack (`docs/features/auth-migration/implementation-plan.md` Phase 6). Native registration, login and password reset are unconditional; the trickle gateway, the `/login/auth0` fallback, the `/login` footnote and the "sign-in is moving" explainer page (its URLs now 301 to `/login`) are gone. A box `.env` that still sets any of them is harmless - nothing reads them.

## Pairs & teams picker rollout (`PAIRS_TEAMS_PICKER_PUBLIC`)

- **Feature:** the Solo / Pair / Team picker of the add/edit time form (`docs/features/pairs-and-teams/README.md`)
- **Flag:** env var `PAIRS_TEAMS_PICKER_PUBLIC` → parameter `pairsTeamsPickerPublic` (`config/services.php`), read through `CoPuzzlerPicker::isEnabled()` and exposed as Twig global `pairs_teams_picker_public`. Twig global — keep it resolvable in `.env` (see the operational note at the top).
- **Default:** **ON** since launch (2026-09-20, Jan's call: full rollout, fix forward). It is the kill switch now: `0` sends everybody but admins (`is_granted('ADMIN_ACCESS')`) back to the old co-puzzler rows. Only the *form UI* is gated: teams are resolved for every group time, the "Pairs & teams" page, team pages and filters are live for everyone.
- **Gated files:**
  - `src/Services/CoPuzzlerPicker.php` — `isEnabled()`, the single decision point
  - `src/Controller/PuzzleAddController.php`, `src/Controller/EditTimeController.php` — pass `copuzzler_picker_enabled`; the old rows' favorites query only runs while the old UI renders
  - `templates/_solving_time_form.html.twig` — picker vs. old rows (`_group_puzzler_input.html.twig` + `add_copuzzler_controller.js`)
- **Remove when:** the picker has been live without trouble for a couple of weeks — then delete `templates/_group_puzzler_input.html.twig`, `assets/controllers/add_copuzzler_controller.js`, the `favorite_players` template variable of both controllers, `forms.choose_from_favorites` / `puzzle_add.teamplayer` / `puzzle_add.add_puzzler` / `puzzle_add.group_puzzling` / `puzzle_add.player_code_info` translations, and the flag itself

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
