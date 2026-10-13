# Phase review: ADD

- **Scope:** commits
  - `698b1d1d` (ADD-01 + ADD-02):
    - `Player::$lastAddMode` + `rememberAddMode()` and migration `Version20261013024414`;
    - the profile row (`GetPlayerProfile` / `PlayerProfile`);
    - the optional `rememberAddMode` on `AddPuzzleSolvingTime` / `AddPuzzleTracking` / `AddPuzzleToCollection` and their handlers;
    - `PuzzleAddController::initialMode()` and the mode rendered on a refused POST;
    - the "Add a time" links with `mode=speed_puzzling`;
    - the tools rows in `templates/puzzle_add.html.twig`, `puzzle_add_form_controller.js` and `multiscan/_entry_button.html.twig`;
    - the registry `add_event` secondary, and the tests.
  - `f559a2cb` (ADD-04):
    - `templates/stopwatch.html.twig` (bar-free while running, "Leave");
    - `stopwatch_controller.js` (`html[data-stopwatch-running]`);
    - the critical CSS rules and `_stopwatch.scss`;
    - `StopwatchPageChromeTest`, `BarFreePagesTest` and `NavLayoutCssTest`.
- **Checked against:**
  - `requirements.md`: Conventions and ADD-01 / ADD-02 / ADD-04 (Done when / Verify).
  - `implementation-plan.md`: §3 (incl. §3.4 / §3.5, budgets, translations), the ADD sections, NAV-08, §5 / §6 rows and decisions 42, 43, 44.
  - `README.md`: D7 and §4.6 / §4.10 (D1–D22 not reopened).
  - `CLAUDE.md`:
    - Messenger handlers, and repositories that never flush;
    - the rolled-back-handler rule;
    - first-try / duplicate-result rules (`time_id` / `new_puzzle_id` kept on a 422);
    - Turbo form rules;
    - generated additive migrations;
    - secret puzzles (`hide_until` through `SecretPuzzleAccess`);
    - translations in 6 locales.
- **Method:**
  - Read both diffs and the current state of every file they touch, plus the code they rely on:
    - `PuzzleAddController` end to end (every early return, the `HandlerFailedException` catch, `answerResend`) and `PuzzleAddFormType::applyDynamicRules`;
    - the three handlers in full (every throw before / after the new call);
    - `StopwatchController` (the redirect to a running stopwatch, the secret-puzzle masking);
    - `SecretPuzzleAccess` (`hide_until`);
    - `base.html.twig` blocks;
    - the critical CSS;
    - `bottom_nav_controller.js`;
    - Turbo 8.0.23's `PageRenderer` (only `lang` / `dir` are copied onto `<html>`);
    - messenger routing (`AwardXpForSolvingTime`, `RecalculateBadgesForPlayer` are async).
  - Listed every `path('puzzle_add' …)` / `url('puzzle_add' …)` / redirect in `templates/` and `src/` and followed each through `initialMode()`.
  - Translations: parsed all 6 `messages.*.yml` before and after each commit with a script.
    - 7 keys were added (5 `puzzle_add.tools.*` + `stopwatch.leave`, `stopwatch.leave_label`), each in all 6 locales. None was removed or changed.
    - No value equals English.
  - Looked at the agents' screenshots under `var/navigation-delivery/ADD-02/` and `ADD-04/` (360 speed + running, non-member collection, running cs 320, paused-then-resumed).
  - No tests, builds or containers were run.
- **Result:** 0 blocker · 1 major · 3 minor.

## Findings

### F1 · major · `templates/puzzle_detail.html.twig:383`, `templates/puzzle/_detail_actions_menu.html.twig:17`, `templates/_puzzle_item.html.twig:96`, `templates/_puzzle_library_item.html.twig:285`, `templates/puzzle_picker/_card.html.twig:170`, `templates/puzzle/_summary.html.twig:120`, `templates/emails/content_digest_miss_you.html.twig:20`, `src/Controller/LegacyAddTimeRedirectController.php:32` (all read by `src/Controller/PuzzleAddController.php:633-656`)

**Problem.** Links that promise a solve or a time now open the Add page in Collection when that was the player's last mode. Before ADD-01 every link without `?mode=` opened in Speed puzzling. Now `initialMode()` falls through to `lastAddMode` for any link that carries no `mode` / event / `team` parameter. Decision 42 converted five "Add a time" links (plus the homepage), but these were missed:
- **"Add solving"** (`add_solving`):
  - the puzzle page's primary button and its ⋯ item;
  - the puzzle list item menu;
  - the collection and unsolved list items (`show_solving_actions`);
  - the puzzle picker card.
  - All link `/puzzle-add/{id}` with no mode.
- **The puzzle summary's "No solve times yet – log yours and be the first on the leaderboard"**: also `/puzzle-add/{id}`. It opens in Relax too, which never reaches a leaderboard.
- **The "miss you" digest e-mail's "Add a time" button**: `url('puzzle_add')` with no mode. This is the exact case decision 42 names.
- **The old `/en/add-time/{id}` URLs** (bookmarks, old links): they 301 to `puzzle_add` without a mode.

A common path hits this:
1. The getting-started step "add to library" links `?mode=collection`, and its save remembers Collection.
2. The player solves a puzzle and taps "Add solving" on its page.
3. The form opens as "Puzzle to Collection", with the puzzle chosen and no time fields.
4. If they do not notice, Save adds the puzzle to the collection (often already there: "save went through") and no time is recorded.

On a collection page, "Add solving" even opens Collection for a puzzle that is already in that collection.

D7 wants the last mode for the Add *tab* on a puzzle page (also `/puzzle-add/{id}`). These are page CTAs that name a solve.

**Reproduction.**
1. As any player, save once from `/en/puzzle-add?mode=collection` (or run `UPDATE player SET last_add_mode = 'collection'` in a test).
2. Open `/en/puzzle/<id>` and follow "Add solving".
3. `input[name="form-mode"][checked]` has the value `collection`, and the time card is hidden after Stimulus connects.
4. The same happens from `/en/collection/…` → ⋯ → "Add solving", and from the summary's "log yours" link.

**Fix.**
- "log yours", the e-mail button and the legacy redirect: pass `mode=speed_puzzling`. For the e-mail, `url('puzzle_add', {_locale: locale, mode: 'speed_puzzling'})`; for the redirect, `$params['mode'] = 'speed_puzzling'`.
- "Add solving" (5 places) names a solve, which may be Relax: give these links the rule `?team` already has, "last mode, never Collection". For example, a `solve=1` flag that `initialMode()` treats like `team`, so the Add tab keeps D7's plain last mode.
  - The simpler alternative is `mode=speed_puzzling` (today's behaviour). It drops a Relax player's preference, so it is the owner's call.
- Add rows to `PuzzleAddLastModeTest`: last mode Collection → the puzzle page's "Add solving" href opens Speed (or Relax when that was last), the summary link and the e-mail open Speed, and `/en/add-time/{id}` redirects with the mode.

### F2 · minor · `assets/controllers/stopwatch_controller.js:92-126` (`start()`), `:318` (`_updateButtons()`), `:81-89` (`disconnect()`)

**Problem.** Leaving the stopwatch page while a Start is still pending hides the phone tab bar on every later page until a full reload.
- `start()` sets `data-stopwatch-running` at the tap, then `await`s the start request.
- If the player taps "Leave", the logo, ☰ or any top-bar item meanwhile (the page was rendered not started, so all of them are there), the Turbo visit disconnects the controller and `disconnect()` removes the attribute.
- When the request then answers, the detached controller runs `this._updateButtons()`, which calls `document.documentElement.toggleAttribute(RUNNING_ATTRIBUTE, true)` on the **new** page.
- Turbo keeps `<html>` attributes across visits (only `lang` / `dir` are copied), and `stopwatch_controller.js` is the only writer (`NavLayoutCssTest`). So `.bottom-nav` stays `display: none` with `--bottom-nav-height: 0` on every following Turbo visit.

The same path also:
- starts a `requestAnimationFrame` loop on detached targets that never ends;
- takes a screen wake lock on the next page;
- runs `history.replaceState(null, '', location.href)` on the next page's history entry.

Those three were there before; the tab-bar loss is new with ADD-04. The window is one start round trip, which is seconds on a slow mobile connection.

**Reproduction.**
1. At 360 with DevTools "Slow 3G", open `/en/stopwatch`.
2. Tap Start, then tap Leave before the answer arrives.
3. The Add page renders with its tab bar, which disappears when the start answer lands. `document.documentElement.hasAttribute('data-stopwatch-running')` is `true`, and it stays true after navigating on with the top bar.

**Fix.**
- Keep a `this._connected` flag: true in `connect()`, false in `disconnect()`.
- In `start()`, after the `await`s, if it is false, call only `rememberStopwatchState(data.stopwatchId, 'running', 0)` (the stopwatch did start, so the pill should know) and return before `_updateButtons()`, `_startRaf()`, `_requestWakeLock()` and `replaceState`.
- Alternatively, guard the attribute write itself in `_updateButtons()` with `this.element.isConnected`.
- Add a row to `checks/ADD-04.py`: delay `/api/stopwatch/start` (CDP `Fetch` or a slow route), tap Start then Leave, and assert no `data-stopwatch-running` and a visible `.bottom-nav` 3 s later.

### F3 · minor · `templates/_solving_time_form.html.twig:281-436` (section targets), `assets/controllers/puzzle_add_form_controller.js:32-33` (`connect()` → `updateVisibility()`), compare `templates/puzzle_add.html.twig:72,85`

**Problem.** For Relax and Collection players, the Add page's server HTML is the Speed form.
- Only the new tools rows are rendered for the initial mode.
- The form's sections carry no mode classes:
  - `timeAndDateSection`, `timeSection`, `firstAttemptSection`, `unboxedSection`, `groupSection`, `competitionSection` and `commonSection` are always visible;
  - `collectionSection` is always `d-none`;
  - the date label never has `required`.
- `puzzle_add_form_controller.connect()` fixes this only once the deferred `app` bundle has run.

This was true for `?mode=collection` / `?mode=relax` links before. ADD-01 makes it the default for every Add of every player whose last mode is Relax or Collection: the Add tab, + Add and the footer.
- **On a full page load** (cold start, PWA launch, reload, the e-mail link), the first frames can show the Collection tools row above a Speed form: time card, co-puzzlers, event, comment/photo. Then about a screen of form collapses into the collection card, which is a layout shift on a page NAV-07 otherwise keeps still.
- **When the bundle fails** (the asset-failure path in `base.html.twig` exists for that), a Collection player gets a Speed-looking form whose hidden mode field says `collection`.
- Turbo visits are not affected: Stimulus connects before the paint.

**Reproduction.**
1. Set `last_add_mode = 'collection'` and request `/en/puzzle-add` with the test client.
2. `[data-puzzle-add-form-target="collectionSection"]` has `d-none`, `[data-puzzle-add-form-target="timeAndDateSection"]` has none, and `[data-add-tools="collection"]` is shown.
3. In Chrome with 6× CPU throttling, a hard reload shows the Speed cards before the switch.

**Fix.** Render each target's `d-none` (and the date label's `required`) from `initial_mode` in `_solving_time_form.html.twig`, with the same truth table as `updateVisibility()`.
- The add page passes `initial_mode` already. The two edit templates pass theirs (Speed / Relax), so their output is unchanged.
- `connect()` then changes nothing on first render.
- Add a `PuzzleAddLastModeTest` assertion: last mode Collection → `collectionSection` shown and `timeAndDateSection` hidden in the HTML.

### F4 · minor · `translations/messages.*.yml` `puzzle_add.tools.scan_boxes` (e.g. `messages.cs.yml:446`) vs `nav.row.multiscan` (`messages.cs.yml:9144`), `templates/puzzle_add.html.twig:88-93`

**Problem.** "Scan several boxes" now has two keys.
- One is the registry row's label (`nav.row.multiscan`: the Menu, the page search and the "Where did it go?" rows).
- The other is the new `puzzle_add.tools.scan_boxes` on the Add page.
- They already differ in Czech: "Naskenovat víc krabic" in the Menu, "Naskenovat více krabic" on the Add page.

The same commit takes the other destination's name from its registry label ("Add Event" = `competition.add_event`, `add_event`'s label). Plan ADD-02's key list has no `scan_boxes` key. Two keys for one destination will keep drifting with each translation pass.

**Reproduction.** In cs, open the Menu (Add group) and the Add page in Collection mode: the two wordings differ.

**Fix.**
- Pass `label: 'nav.row.multiscan'` to the entry button and delete `puzzle_add.tools.scan_boxes` in the 6 locales.
- Keep `scan_boxes_title` (the tooltip has no registry twin).
- Update `PuzzleAddToolsTest` only if it reads the key (it reads the English text, which stays the same).

## Questions

**Q1 ("Use the stopwatch" and the chosen puzzle).** The link uses `active_puzzle` only, which is the puzzle in the URL (`/puzzle-add/{id}`). The commit message says "with the chosen puzzle".
- A puzzle picked in the form on `/en/puzzle-add` gives `/en/stopwatch` without it.
- So does a refused POST, even though the controller knows `$chosenPuzzleId`.

Is the URL's puzzle intended? If not:
- server side, use `$chosenPuzzleId` when it is a visible UUID;
- client side, rewrite the href when the puzzle field changes.

**Q2 (offline queue vs. the bar-free render).**
- A Stop tapped offline is queued in `localStorage['stopwatch-queue']`. The server still says running.
- Re-opening the stopwatch page then renders it running (bar-free, ticking, Stop shown). `connect()` replays the pause but never applies it to the page.
- The pill side already knows (`rememberStopwatchState` before the request), so the pill and the page disagree.

The timer part is older than ADD-04, which adds bar-free on top. Worth a small fix: after a successful replay of this page's own stopwatch, set `statusValue` and `_updateButtons()`. Or a TODO?

**Q3 (`page/…` data-nav for page tools).**
- Plan §3.3 defines the `page` surface as the end-of-page line.
- ADD-02's tools (`page/add/use_stopwatch` …) and Leave (`page/add/stopwatch_leave`) use it for in-page controls.
- `NavClickController` has no surface allow-list, so they are logged.

Intended, so that P0-03 reports read "page = any in-page navigation"? If so, DOC-01 should say it.

**Q4 (Back to the running stopwatch outside Speed).**
- Leave from a running stopwatch without a puzzle lands on Add in the last mode.
- In Relax or Collection, "Back to your running stopwatch" is hidden (Speed tools only). The only way back is the top-bar pill (< 24 h), or Use the stopwatch after switching to Speed.

Should the back link sit outside the mode rows, while a stopwatch runs?

**Q5 (Czech tone of the scan tooltip).**
- `puzzle_add.tools.scan_boxes_title` is formal ("Naskenujte …"), matching `puzzle_add.*`.
- The same entry button's other titles (`multiscan.entry.*_title`) are informal ("Naskenuj …").

"Czech as surrounding keys" is met per namespace. The button flips tone between pages: fine?

## Checked and fine

**ADD-01 data and read side.**
- **Column and migration.** `Player::$lastAddMode` is a nullable `PuzzleAddMode` (`Types::STRING`, `enumType`) with `#[Immutable(PRIVATE_WRITE_SCOPE)]`, and it is written only by `rememberAddMode()`. The migration is generated, additive and nullable (`ADD last_add_mode VARCHAR(255) DEFAULT NULL`), so blue-green safe.
- **Profile row.** `player.last_add_mode` is in `GetPlayerProfile::byUserId()` only, and `PuzzleAddMode::tryFrom()` copes with unknown values. That is 0 new statements per page. `byId()` (other players) does not read it.
- **Save cost.** The UPDATE happens only on a change: the enum case is the same instance, so Doctrine sees no change. The budget test presets the mode so its relative save comparison stays as strict as before, and no pin moved.

**Precedence (`initialMode()`), path by path.**

| Entry | Mode |
|---|---|
| `finish_stopwatch` (GET and POST) | Speed |
| `?mode=` | wins, incl. `speed_puzzling`; nonsense falls through |
| `?competition` / `?series` / `?official_entry` (event pages, round cards, `RoundsTimelineBuilder`, "Add to my profile") | Speed, even with last Relax / Collection |
| `?team` (pairs & teams) | the last mode, or Speed when the last is Collection |
| `/puzzle-add/{id}` (Add tab, recaps' "the same puzzle") | the last mode, with the puzzle |
| Nothing at all | Speed |

- Multiscan's full-form link and the collection / library / picker empty-state links pass `mode=collection`.
- The five "Add a time" links of decision 42, plus the homepage, pass `mode=speed_puzzling`.

**Refused POST.**
- The page renders `$data->mode` after `handleRequest()`. The switch, the hidden `<select>` and `data-puzzle-add-form-mode-value` agree (`renderedMode()` asserts all three).
- `time_id` / `new_puzzle_id` are kept on the 422.
- Nothing that runs before `handleRequest()` depends on the initial mode in a way the submit cannot correct:
  - the collection preset and `deepLinkPick` are overwritten or re-offered by `submitted_competition_pick`;
  - the official entry is re-read on POST.

**Handler order (rolled-back-handler rule).**
- **`AddPuzzleSolvingTimeHandler`.** `rememberAddMode()` comes after every throw: id reuse / resend, stopwatch owner / not started, secret puzzle, group, round visibility, recent twin, first try, PPM, the photo upload, team resolve, edition / round resolve, prediction and `finishStopwatch()`. After it come only `persist`/`save` and async dispatches.
- **`AddPuzzleTrackingHandler`.** It comes after the resend checks, the secret puzzle, the group and the photo.
- **`AddPuzzleToCollectionHandler`.** It comes after the puzzle, the secret puzzle and the collection owner, and also covers "already in the collection" (the save went through).
- **`RememberAddModeTest`.** It proves a refused first try leaves the *managed* `Player` untouched.
- **Flush and worker mode.** A flush-time failure closes the EM, and `kernel.reset` resets it between requests, so no dirty `Player` survives into the next request.
- **Callers.** Only `PuzzleAddController` passes the parameter (stopwatch save → Speed). These leave it out:
  - `CreateSolvingTimeProcessor` and `AddCollectionItemProcessor` (API);
  - `AddPuzzlesToCollectionHandler` (multiscan);
  - `Collections\AddPuzzleToCollectionController`;
  - the edit messages.
- **Resends.** `answerResend` writes nothing new (the first save remembered it).

**ADD-02.**
- **Server-rendered rows.** Each row is rendered for its initial mode (outer `d-none`, so Bootstrap's `d-flex` cannot win) and toggled in `updateVisibility()` behind `has…Target`, so the edit forms are unaffected.
- **No tools on two saves.** Neither the stopwatch save nor an official entry shows tools.
- **"Back to your running stopwatch"** comes from the profile row's fresh `runningStopwatch` (0 statements) and is generic text, so it reveals no secret puzzle.
- **"Scan several boxes".**
  - Members get `action=add_to_library`, plus `collection` when it is the system id or a UUID.
  - Non-members get the system collection and the existing lock icon (decision 43: nothing newly locked).
  - `nav` in the entry partial is optional, so the other six callers are unchanged.
- **"Other ways to add".** It sits below the form, outside it, and uses `add_competition` with its registry label. The registry `add_event` secondary is updated and `docs/navigation.md` is regenerated.
- **Instrumentation.** Every tool link has a valid `data-nav`.

**ADD-04 page.**
- **What the server hides.** `stopwatch_running` = status `running` (decided at render). It empties `header`, `nav_chrome`, `nav_layers`, `footer` and `page_help_line` and sets `body.no-nav-chrome`, so `--bottom-nav-height` and `--nav-left` are 0 and `scroll-padding-top` is 0.
- **What stays.** `#membersExclusiveModal`, the confirm dialog, `modal-frame` and the toast container live outside those blocks, so the non-member teaser button still works. `stopwatch*` are announcement-modal quiet routes.
- **Leave.**
  - It is a real `<a>`, first in the toolbar, at least 44 × 44, with `touch-action: manipulation` and a valid `data-nav`.
  - Its aria-label is only set while running; the long name starts with the visible word in all 6 locales (WCAG 2.5.3, tested).
  - **Target.** It goes to `puzzle_detail` only when `active_puzzle` is set. `StopwatchController` clears it for a puzzle `SecretPuzzleAccess::isHiddenFromViewer()` hides, which covers `hide_until` and round secrets. Organisers, who may see it, get the puzzle page. Otherwise it goes to `puzzle_add`. The secret case is tested (no name in the HTML).
  - **Native app.** Leave stays; there is no web chrome there.
  - **The rest of the page.** The standalone stopwatch keeps Start / Pause / Finish with its bars, and `/en/stopwatch` still redirects to a running one.

**ADD-04 client side.**
- **When the attribute is set and cleared.**
  - Start (at the tap; a failed start clears it through `_updateButtons()`);
  - Pause and Resume (synchronously, before their requests, so the offline queue does not matter);
  - Finish (a Turbo visit, so `disconnect()`);
  - Reset (`location.replace`, a full load);
  - `connect()` (matches the server render);
  - `disconnect()`.
- **Turbo cache and bfcache.** The snapshot cache is off (`turbo-cache-control: no-cache`), and restoration visits are reloads. A bfcache restore brings back the same page with its own state (GEST-14 adds `data-bfcache-reload` later).
- **CSS.**
  - It hides `.bottom-nav` only in the phone media query, and zeroes `--bottom-nav-height` with and without `:has()`.
  - Desktop places and the top bar are untouched.
  - The rail rule cannot override it on phones.
- **The bar when it comes back.** `bottom_nav_controller.fit()` skips while the bar is `display: none` and re-fits through its `ResizeObserver` when it returns.
- **Native app.** The attribute is harmless there: no `.bottom-nav`, and the height is already 0.

**Translations.**
- 7 keys × 6 locales, none equal to English.
- Tone: de "Sie" (Ihrer, Scannen Sie), fr "vous" (votre, Scannez), es "tú" (tu, Escanea), cs like `puzzle_add.*`. Inserted as text: parse-compare shows only additions.
