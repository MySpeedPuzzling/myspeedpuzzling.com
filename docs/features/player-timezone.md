# The player's time zone

`player.timezone` - the player's IANA time zone (`Europe/Prague`), null while not known. Built 2026-10-18 as a
prerequisite: solve dates are stored without a time and `tracked_at` is UTC, so a personal "Insights" page (logging time
of day), digest e-mails, streak days and event times need the player's zone to speak local time.

## Rules

- **Only listed zones are stored**: `Value\TimezoneIdentifier` is the one rule - an identifier in
  `\DateTimeZone::listIdentifiers()`. A browser's old name (Chrome reports the CLDR `Asia/Calcutta`, `Europe/Kiev`) is
  stored as the listed zone it links to (`Asia/Kolkata`, `Europe/Kyiv`, through ICU's equivalent ids); anything else is
  ignored, never stored. `Player::changeTimezone()` refuses an unlisted value.
- **Written only by handlers**: `RegisterUser` / `RegisterWithOauthIdentity` (sign-up), `EditProfile` (the settings -
  null clears it, an unknown value leaves it as it was; the message has no default for it, so every form sending
  `EditProfile` must carry the field) and `DetectPlayerTimezone`.
- **Detection never overwrites a choice**: `DetectPlayerTimezone` → `Player::fillDetectedTimezone()` fills an empty
  value only.
- **No writes on GET**: a signed-in player without a zone is never set silently on a page view. The settings field and
  the profile panel ask instead.
- **Private**: only `GetPlayerProfile::byUserId()` (the signed-in player's own row) selects it - `byId()`, public pages
  and API v1 never see it. The puzzle-library export lists it in `about` (own data).

## Where it is detected

The browser's `Intl.DateTimeFormat().resolvedOptions().timeZone` (`assets/browser_timezone.js`), filled into a hidden
field by `timezone_detect_controller.js` (lazy):

| Path | Field |
|---|---|
| Native registration (`/register`) | `registration_form[timezone]` (`RegistrationFormType`, `HiddenType`) |
| Social sign-up (Google, Microsoft, Apple, Facebook) - the rule-4 confirmation `/register/social`, both its normal and its Apple "Hide My Email" form | `timezone` (`_timezone_detect_input.html.twig`) |
| Safety net: the welcome screen `/welcome` (every sign-up lands there), rendered only while the account has no zone | `POST /detect-timezone` (`DetectTimezoneController`) → `DetectPlayerTimezone` |

Social sign-up never creates an account straight from the OAuth callback (it always passes the confirmation), so the
two forms cover every sign-up path. The legacy `RegisterUserToPlay` (a pre-2026 account without a player) is left out.

## Settings

"Time zone" on the edit-profile form (`EditProfileFormType`, shared with `finish_profile`, where it sits under "Add
more" so that form never clears it): a select grouped by region, names from ICU in the page's language
(`Services\TimezoneChoices`: `Timezones::getName()`, UTC through `IntlTimeZone`), sorted by the city in brackets,
searchable through TomSelect (`timezone_select_controller.js`, lazy, partial `_timezone_field.html.twig`).

- **Anchor**: the select's id is `timezone` (`EditProfileFormType::TIMEZONE_ANCHOR`) - link to
  `path('edit_profile') ~ '#timezone'`. The page lands below the sticky header (`scroll-margin-top` on the field) and
  the controller scrolls to it again and focuses it once TomSelect has replaced the select.
- **Detected, not saved**: while nothing is saved, the controller preselects the browser's zone and shows "Detected from
  your browser - it is saved once you save your profile." Nothing is stored until the player presses Save.

## "Complete your profile" panel

`<twig:ProfileCompletion />` on the player's **own** profile: the missing details, each a link anchored to its field.
Today one item - the time zone (`edit_profile#timezone`). Dismissed for good with `HintType::ProfileCompletion`
(`dismiss-hint`).

One ask at a time, top to bottom:

1. the newcomer "Getting started" card (it already asks for name + country) - while it is shown, nothing else;
2. "Add your country" (`Players:CountryNudge`, with its own inline form) - while it asks, the panel waits;
3. "Complete your profile".

The country is therefore not repeated in the panel: it is asked by the nudge, and a country the player waved away is
not asked for again. Cost: no query while nothing is missing (the owner's row is already loaded), one
(`IsHintDismissed::dismissedAmong()`) otherwise.

## How to use it in a feature

`tracked_at` and every other stored timestamp are UTC. Convert with the player's zone:

```php
$zone = new \DateTimeZone($profile->timezone ?? $countryMainZone ?? 'UTC');
$local = $trackedAt->setTimezone($zone);
```

Fall back to the main zone of the player's country, then to UTC. There is no country → zone helper yet: add one next
to `CountryCode` when the first feature needs it (`\DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'CZ')`
gives the candidates; a country spanning several zones needs a chosen main one). Never guess from the request (the
server sees no zone), and never store a converted time. In SQL, `tracked_at AT TIME ZONE 'UTC' AT TIME ZONE :zone`
(the column is `timestamp without time zone` holding UTC).

Translations: `player_timezone.*` in all 6 locales.
