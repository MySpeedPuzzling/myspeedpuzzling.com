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

## Retired: Google/Apple social login flags + admin-only stage (removed 2026-09-29)

`SOCIAL_LOGIN_ADMIN_ONLY`, `SOCIAL_LOGIN_GOOGLE_ENABLED` and `SOCIAL_LOGIN_APPLE_ENABLED` were deleted at the public launch of Google + Apple sign-in (both verified end to end in production, Jan's call). There is no admin-only stage any more: the buttons render on `/login` + `/register` for everyone, rule-4 registration via the `/register/social` interstitial is on, and every signed-in player gets the "Connected sign-in methods" card. Google and Apple are now available **iff their credentials are configured** (`SocialLoginSettings::isAvailable()` for routes, `isShown()` for buttons — Google: `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET`; Apple: `APPLE_CLIENT_ID` + `APPLE_TEAM_ID` + `APPLE_KEY_ID` + `APPLE_PRIVATE_KEY`), so local dev and tests without credentials show no button and 404 the provider's routes. Emptying a provider's credentials is the kill switch. A box `.env` / Infisical that still sets the old flags is harmless - nothing reads them. Microsoft (shipped 2026-09-30) follows the same rule from day one - available iff `MICROSOFT_CLIENT_ID` + `MICROSOFT_CLIENT_SECRET` are set, no flag.

## Social Login — Facebook buttons shown (`SOCIAL_LOGIN_FACEBOOK_ENABLED`)

- **Feature:** Facebook sign-in (auth hardening PR 2, `docs/features/auth-hardening/README.md`)
- **Flag:** env var `SOCIAL_LOGIN_FACEBOOK_ENABLED` → parameter `socialLoginFacebookEnabled` (`config/services.php`), read only through `SocialLoginSettings::isShown()`. Since 2026-09-30 it controls **visibility only**: whether Facebook's buttons are offered. Facebook itself *works* iff `FACEBOOK_APP_ID` + `FACEBOOK_APP_SECRET` are set (`SocialLoginSettings::isAvailable()`, the same credentials rule as Google/Microsoft/Apple) - start, connect and callback routes work regardless of the flag, so the Meta app's admins/testers can test it in production **by direct URL**: sign in at `https://myspeedpuzzling.com/login/social/facebook`, connect a signed-in account at `https://myspeedpuzzling.com/account/social/facebook/connect`. Templates ask the Twig global `social_login` (the `SocialLoginSettings` service): `social_login.isProviderShown('facebook')`. Keep the flag resolvable in `.env` (see the operational note at the top).
- **Default:** OFF. While the Meta app is unpublished (development mode) only its admins/testers can sign in, so a public button would fail for everybody else.
- **Gated files** (visibility only):
  - `templates/_social_login_buttons.html.twig` — the Facebook button on `/login` + `/register`
  - `templates/edit-profile.html.twig` — the "Continue with Facebook" connect row for a player who has not connected Facebook. A player who already linked Facebook always sees their row with Disconnect, flag or not (the card also shows whenever the player has any linked identity). Unlink and the Apple server-to-server webhook are not gated by anything
  - Not gated by the flag (only by credentials, `isAvailable()`): `SocialLoginStartController`, `SocialConnectController`, `SocialLoginCallbackController`, `SocialLoginAuthenticator::supports()`
- **Remove when:** the Meta app is published (business verification / App Review done) and Facebook sign-in works for a non-admin account — then delete the flag and `isShown()`'s Facebook branch, so Facebook's buttons follow the credentials rule like Google and Apple

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers
