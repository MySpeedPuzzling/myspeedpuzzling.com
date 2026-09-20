# Getting started: newcomer onboarding and the platform guide

User testing (2026-09-20): right after registering, people asked "what do I do now?". The
platform is big, the old welcome screen had one button (to an empty profile), and
registration asked for e-mail and password only - so a newcomer who logged a time
ranked as a bare `#CODE`.

## What the research said (and what it rules out)

- Tutorials shown up front are skipped and forgotten (NN/g, "Onboarding Tutorials vs.
  Contextual Help"). **So there is no tour, no overlay, no modal, no card deck.**
- Completion falls off a cliff after 4 steps (~72-74 % at 3-4 steps, ~34 % at 5, ~16 % at 7+;
  vendor benchmarks - directional). **The checklist stays at five: the account plus four things to do** -
  Jan's call (2026-09-20): owning a puzzle library matters more than the fourth-step cliff, and "five or fewer" is still inside the guidance.
- A list that starts at "1 of 5" is finished more often than one at "0 of 4" (endowed
  progress). **"Create your account" is a real, already ticked first step.**
- First value before profile setup; empty screens are where newcomers give up.
- People read 20-28 % of the words on a page. **A step's title and button must make
  sense on their own**; the sentence under them is optional reading.

## The pieces

| Piece | Where | Notes |
|---|---|---|
| Name at registration | `RegistrationFormType`, `RegisterUser::$name`, `RegisterUserHandler` | Optional, trimmed, blank = null. Social sign-up already carried the provider's name |
| Welcome screen | `/welcome`, `templates/registration_welcome.html.twig` | One question, three big answers (log a puzzle / stopwatch / finish profile), guide link, skip. Both native and social registration land here |
| Finish profile | route `finish_profile`, `FinishProfileController`, `templates/onboarding/finish_profile.html.twig` | Same `EditProfileFormType` + `EditProfile` message as "Edit profile"; shows name, country, photo, the rest folded under "Add more". Saves → Hub |
| "Getting started" card | Hub **and the player's own profile**, `templates/onboarding/_getting_started_card.html.twig` | See below |
| The guide | route `getting_started` (`/en/getting-started`, localized slugs), `templates/onboarding/getting_started.html.twig` | Public, indexable, all 6 locales. Linked from footer column 1, the user menu, the FAQ, the welcome screen, the Hub card |
| Empty statistics | `templates/components/PlayerStatistics.html.twig` | The owner's own empty statistics get a "Log your first puzzle" button |

Styles: `assets/styles/_onboarding.scss`. Mobile first: one column, every target ≥ 48 px.

## The "Getting started" card

Steps: create your account (ticked) → log your first puzzle → finish your profile → add a puzzle you own → add a favorite puzzler.
On the Hub it folds once the first puzzle is logged; on the player's own profile (`PlayerProfileController`) it is shown open while
something is left, and never in its "all set" state. "Hide" removes it from both.


- State is **derived, never stored**: `GetGettingStartedProgress::forPlayer()` →
  `GettingStartedProgress` (one query: has a solving time, is dismissed, is a newcomer;
  name + country and favorites come from the `PlayerProfile` already loaded).
  "Finish your profile" = name **and** country; a photo is encouraged, never required.
- Shown only to **newcomers** (registered within 14 days, `NEWCOMER_WINDOW`) who have not
  hidden it - existing players are never handed a beginner's list.
- Hiding = `HintType::GettingStartedChecklist` through the ordinary dismiss-hint flow
  (`docs/features/hint-dismissing.md`).
- All done → the Hub renders an "all set" state once and `HubController` dispatches
  `DismissHint` itself, so it never comes back.
- It is a `<details>` (no JavaScript, no layout shift): on the Hub **open until the first puzzle is logged, folded to one
  line afterwards** - visible while the core action is still ahead, out of the way once it is done. No cookie, no stored state.
- Only the *next* open step is highlighted.

## The guide's rules

- Six steps + a "When you are ready" tile grid. Order: **log puzzles** (the core) → profile →
  **puzzle library** (the collection of what you own - the picker, marketplace and lending all build on it) →
  statistics → compare → favorites.
- Each step: title, one sentence, *where to find it* (testers did not know where things
  live), one or two buttons, optional "More" disclosure.
- Layout: on a phone the step number sits **on the title line**, so text and buttons use the full width (a number column cost a fifth of the screen); from `lg` up the steps run in two columns and the tiles in three.
- **No screenshots** - they go stale and are in one language. Icons only.
- **All 6 locales**, unlike `/en/guides/*` (those are English-only SEO articles; this is product UI).
- Anchors `#track #profile #library #statistics #compare #favorites #more` are linked from
  elsewhere - `GettingStartedControllerTest` pins them.
- Signed in, **every step shows a tick** from the same `GettingStartedProgress`. Steps 1, 2, 3 and 6 are
  derived from data (a time, name + country, a collection item, a favorite). Statistics and Leaderboard leave
  no trace, so their buttons carry the `mark-seen` Stimulus controller: a `sendBeacon` POST to the ordinary
  `dismiss_hint` route with `HintType::GuideStatisticsSeen` / `GuideLeaderboardSeen` - reusing `dismissed_hint`
  as a "seen" flag, no new table. Only a click *from the guide* counts; reaching the page through the menu does not.
  That is the "repeatable" part: the guide always shows what is left, even after the Hub card is gone.
- Tile titles reuse existing menu translations so feature names never drift.

Translations live under `onboarding.*` (+ `auth.register.name`, `auth.register.name_hint`).

## Measuring it

Activation = share of registrations with at least one solving time within 7 days:

```sql
SELECT date_trunc('week', p.registered_at) AS week,
       count(*) AS registered,
       round(100.0 * count(*) FILTER (WHERE EXISTS (
           SELECT 1 FROM puzzle_solving_time t
           WHERE t.player_id = p.id AND t.tracked_at < p.registered_at + interval '7 days'
       )) / count(*), 1) AS activated_pct
FROM player p
WHERE p.registered_at > now() - interval '12 weeks'
GROUP BY 1 ORDER BY 1;
```

## Not built yet

- **First-time celebration**: after saving the very first time, a small "Nice!" moment with
  links to statistics / ladder / finish profile - the best place to introduce those features.
- Empty states on the profile page, collections and wishlist.
- A welcome e-mail with the same first steps (today only the verification mail goes out).
- "Edit profile": move the basic form to the top, fold the developer cards (tokens, applications).
- `membership.full_description` is out of date (no Insights, "coming soon" items that shipped).
