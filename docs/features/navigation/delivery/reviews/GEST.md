# Phase review: GEST (GEST-03 … GEST-07)

- **Scope:** two commits on `feature/navigation-redesign` (delivery worktree), reviewed at `a9e80e3e`. A main merge came after them; main's online-competition code is out of scope except where it meets these items. "Before" is `c61d05bb`.
  - `b4c53f2c` GEST-03 + GEST-04:
    - `assets/tab_reselect.js` and `assets/controllers/tab_gestures_controller.js`;
    - `live_refresh_controller.js` (`msp:feed-refresh`);
    - `assets/styles/_press-states.scss` and the empty `touchstart` listener in `app.js`;
    - the `data-press` markers, the per-component `:active` rules that were removed, and the pending pill.
  - `eb2f52e6` GEST-05 + GEST-06 + GEST-07:
    - `assets/styles/_touch.scss`;
    - `assets/pull_to_refresh.js` and `pwa_lifecycle_controller.js`;
    - the standalone-only overscroll rule in `app.scss`, and `data-ptr-ignore`;
    - `NavigationMotionRulesTest` and `TouchActionRulesTest`.
- **Checked against:**
  - `requirements.md`: the Conventions, and the Done when / Verify of GEST-03…07.
  - `README.md`: §4.13 G3–G8 ("Identical everywhere"), §4.2 (the current tab while the Menu is open), §4.3 (the 768–1023 px places and + Add), D7 and D22. D1–D22 are not reopened.
  - `implementation-plan.md`:
    - all of §3, in particular §3.2, §3.4 item 6 and §3.5 items 2 and 4;
    - §4 GEST-03…07, and GEST-11 (the markers and the ripple colour);
    - decisions 58 D-5 / D-6 and 59.
  - `delivery/state.md`: these are logged decisions, not findings:
    - the GEST-03/04 rows: Add's start page includes `/puzzle-add/{id}`; inside the native app a tap always navigates; Chromium applies `:active` to a touch only at the tap;
    - decision 69: `_press-states` has no reduced-motion block.
  - `CLAUDE.md`:
    - Turbo: the snapshot cache is off, and restoration visits are redirected in `app.js`;
    - Live Component gotchas;
    - ResetInterface: nothing new on the server;
    - guest caching: none of the new markup depends on the visitor;
    - translations: no new keys.
- **Method:**
  - **Trees.** Ran `git archive` of `a9e80e3e` and `c61d05bb` into the scratchpad, and read both diffs in full and every touched file. I also read:
    - `layer_history.js` (`current()`, `isOpen()`);
    - `menu_layer_controller.js` (`handleTabBarClick`);
    - `player_card_layer.js` (outside clicks);
    - `puzzles_section.js`, `PlacesBuilder`, `AddEntryTwigExtension`, `NavigationPlaceMap` (the Add routes) and the `PuzzleAddController` routes;
    - `topbar_controller.js`, `_nav-bars.scss`, `_nav-topbar.scss` and the critical CSS;
    - `assets/head/standalone.js` and `platform.js`;
    - Bootstrap 5.2.3's `_tables.scss`.
  - **Tap the active tab.** Traced `decide()` branch by branch against the plan's order and every place's start page.
  - **Pending pill.** Traced every way the pill goes back: `pointercancel`, a move over 10 px, `contextmenu`, a release without a click, a refused or prevented visit, a fetch error, `pageshow` and `connect`.
  - **Feed refresh.** Read `onFeedRefresh` against the ticker, ring and backoff state machine.
  - **Pressed layer.** Checked `_press-states` specificity and paint order against each marked target's own background rules.
  - **touch-action.** Read every `touch-action` in `assets/`, plus the third-party CSS that ships to pages (lightgallery, tom-select, flatpickr).
  - **Pull-to-refresh.** Traced the pull guards with one finger and with two.
  - **Motion test.** Checked its coverage against every SCSS file the branch touched.
  - **Tests.** Ran `git diff c61d05bb a9e80e3e -- tests`. No file was deleted. Outside main's online tests, the only removed lines are:
    - one docblock line;
    - the pull test's inline process code, which moved into a `harness()` helper.

    Nothing was weakened.
  - **Focus 4.** `msp:test-standalone` is read only when `navigator.webdriver === true` (`assets/head/standalone.js`), so a visitor who sets localStorage cannot reach it. No finding.
  - **Evidence.** Browser evidence came from `state.md`, `checks/C-GEST-03…07.log` and `scripts/checks/GEST-06.py` (its two-finger row). I ran no tests, builds, docker or browser.
- **Result:** 0 blocker · 2 major · 7 minor · 3 questions.

## Findings

### F1 · major · `templates/navigation/_topbar.html.twig:85`; `assets/controllers/tab_gestures_controller.js:9-14, 88`
**Problem.** The top bar's "+ Add" throws away a half-filled add form.
- It is shown at every width from 768 px. The rail hides Add, and the 768–1023 px bar has no tab bar, so + Add is the only Add there.
- Its href is `add_entry_url()`:
  - on `/en/puzzle-add` that is the same URL;
  - on `/en/puzzle-add/{id}` it is the bare form.
- `tab_gestures_controller.js` accepts only links in `#bottom-nav` and `#menu-slot`. A click on + Add is therefore an ordinary Turbo visit: the form is fetched again and renders empty, and the add form has no unsaved-input guard.
- Scenario at 1280 px:
  1. From a puzzle page, the player clicks + Add.
  2. They type the time and a comment and attach a photo.
  3. They click the coral "+ Add", which reads as "add this".
  4. The page loads the bare form with nothing in it.

**Why it matters.**
- README §4.13 G3: "Add never reloads (a half-filled form survives)", and "Identical everywhere: … tapping the active tab (G3)".
- On tablets and desktops, + Add is the only Add in the chrome (D7), so G3's Add rule is not met on those widths.
- The Done when only passes because it is checked at 360, where the tab bar's Add is the control.

**Fix.** Give + Add the Add tab's decision:
- In `onClick`, also accept `header.site-header a.site-header-add`, with `tabPlace = 'add'` taken from its `data-nav` place segment (`topbar/add/add`). No new attribute is needed.
- `currentPlace` stays the bar's `aria-current` tab. On the add form (and on `/puzzle-add/{id}`), `decide()` then returns `scroll-top` or `none`, and the link never fires.

**Test.** Add a row to `checks/GEST-03.py`, run at 1280 and at 820:
1. Open `/en/puzzle-add/<id>`, type into the comment field and arm the probe.
2. Click `.site-header-add`.
3. Expect the value unchanged, the URL unchanged and the probe holding.

Today the bare form loads empty.

### F2 · major · `assets/styles/_press-states.scss:43-45, 74-80`; `assets/styles/_nav-bars.scss:114, 140-143`
**Problem.** A tap on the Add tab shows no visible answer.
- The tab's pressed layer is an inset shadow of `--nav-pressed-pill` (`rgba(254, 105, 106, .1)`) on `.nav-tab-icon`. Over the coral tile `#ec4f52` it composites to about `#ee5254`. That is at most 3 levels per channel (contrast ratio ≈ 1.01), which nobody can see.
- `.is-pending` is excluded for Add (`:not(.nav-tab--add)`), so the pending pill draws nothing on it either.
- The only change is the current tab dropping its pill (`.is-leaving`). On a page that belongs to no place (the FAQ), nothing changes at all until Turbo answers.
- Android is no better:
  - Chromium applies `:active` only at the end of the tap (state.md);
  - GEST-11's ripple will use the same colour (plan GEST-11: "ripple colour `--nav-pressed-pill`"), so it will be invisible on the tile as well.
- The guest card's coral Sign in (`.menu-guest-btn--primary`, `data-press`) gets the same coral-on-coral layer.

**Why it matters.** README §4.13 G4 says "Every tap answers within 100 ms", and GEST-11's Done when relies on "the state layer shows everywhere". Add is the bar's main action.

**Fix.** Give the coral targets a darker layer. Add stays "never styled selected":

```scss
.nav-tab--add[data-press]:is(:active, .is-pending) .nav-tab-icon,
.menu-guest-btn--primary[data-press]:active {
    --nav-press-layer: rgba(43, 52, 69, .16);
}
```

- The + Add's own pressed `#b02a31` would work as well.
- Including `.is-pending` gives Android the answer at pointerdown.
- GEST-11 then takes this per-target colour for its ripple.

**Test.**
- `PressStatesCssTest`:
  - a pressed rule exists for the Add tile;
  - its composite over `#ec4f52` differs from `#ec4f52` by a contrast ratio of at least 1.2 (computed in the test; the colour above gives about 1.23).

  Today no such rule exists, and the generic layer gives about 1.01.
- `checks/GEST-04.py` at 360: after a scripted pointerdown on Add, within 100 ms the screenshot pixel at the tile's centre differs from its resting colour.

### F3 · minor · `assets/tab_reselect.js:51-64`; `src/Navigation/NavigationPlaceMap.php:97-113`
**Problem.** The Add tab throws away the other half-filled add forms of the Add place.
- `finish_stopwatch` (`/en/save-stopwatch/{id}`) renders the same add form: same `PuzzleAddController`, same `puzzle_add.html.twig`, pre-filled with the stopwatch's time.
- `edit_time` is an Add page too (decision 40).
- `isStartPage()` knows only the tab's path and `/puzzle-add/{id}`. On these forms the Add tab is therefore "a deeper page", and the tap navigates to the bare form.
- Scenario:
  1. A player stops the stopwatch.
  2. On the save form they pick the puzzle and type a comment.
  3. They tap Add.
  4. The bare form loads. The stopwatch is still there, but the puzzle choice, comment and photo are gone.

**Why it matters.** state.md gives `/puzzle-add/{id}` its refinement because it is "the form a tap must never throw away", and the same reason holds here. README G3: "Add never reloads (a half-filled form survives)".

**Fix.** Count a page that holds the time form as Add's start page:
- Read a `formShown` fact from `document.querySelector('main form[data-controller~="first-try-check"]')`. Every full-page solving-time form carries that controller (`_solving_time_form.html.twig`), so no new markup is needed.
- Make `isStartPage('add', …)` true when the fact is set.
- That covers `puzzle_add`, `finish_stopwatch` and the edit page. The recap, the stopwatch and multiscan still navigate.

**Test.**
- Harness rows:
  - `decide({...ADD, currentPath: '/en/save-stopwatch/s1', formShown: true, scrollY: 0})` → `none`;
  - the same scrolled → `scroll-top`;
  - without `formShown` → `navigate`.
- `checks/GEST-03.py`: on the save-stopwatch form with typed input, tap Add → the value is unchanged and the probe holds.

Today the harness has no such fact, and the tap navigates.

### F4 · minor · `assets/tab_reselect.js:51-64`; `assets/controllers/tab_gestures_controller.js:133-153`; `assets/puzzles_section.js:128-135`
**Problem.** A Puzzles section root counts as a start page only while localStorage works.
- The server already knows which page is a start page. On the four section start pages, `PlacesBuilder` gives the Puzzles tab `aria-current="page"`, but it renders the default href (My library, or All puzzles for guests).
- `puzzles_section.js` then points the href at the section used last. That is this page only if `recordSection()` succeeded.
- When storage is blocked or throws, `localStorageOf()` returns null and the href keeps its default. This happens with Safari's "Block all cookies", or Firefox with the site's data blocked.
- `decide()` compares paths. So on Marketplace, What next? or (for a player) All puzzles, the tap counts as a deeper page and navigates to My library, instead of gliding to the top.

**Why it matters.** Plan GEST-03 and decision 58 D-5 say every Puzzles section root is a start page. The server's `aria-current="page"` is the exact start-page signal, and it needs no storage.

**Fix.** Either:
- in `facts()`, pass `startPage: true` when the bar's tab for that place has `aria-current="page"`, and make `isStartPage()` return true for it (the path rule stays for everything else); or
- have `applyPuzzlesSection()` point the links at `own ?? readSection(storage)`. That is what its doc comment already promises: "its links keep leading here".

**Test.**
- Harness: `decide({tabPlace: 'puzzles', currentPlace: 'puzzles', tabPath: '/en/my-library/p1', currentPath: '/en/marketplace', startPage: true, scrollY: 800})` → `scroll-top`.
- WebDriver:
  1. Register a script before navigation that makes `Storage.prototype.getItem` / `setItem` throw.
  2. Open Marketplace and scroll to 800.
  3. Tap Puzzles.
  4. Expect `scrollY` 0 and the URL unchanged.

Today it navigates to My library.

### F5 · minor · `assets/controllers/tab_gestures_controller.js:9-14, 46-63, 88, 157-170`
**Problem.** The 768–1023 px top bar's places (Home · Puzzles · Community) get neither GEST-03 nor the pending pill, although they are drawn like the tab bar, pill included.
- The controller sits on `#bottom-nav`, which is `display: none` at those widths, and accepts only links inside it or inside `#menu-slot`.
- On an iPad in portrait (820 px):
  - Home on a scrolled Hub is a same-URL Turbo visit: a reload, not a glide, and no feed refresh at the top;
  - after a tap on Community, the pill stays where it was until the page answers.
- `PressStatesCssTest` already counts these links as tabs (`'tabs of the 768-1023 px top bar'`, `data-press="fixed"`).

**Why it matters.** README G3 says "Identical everywhere", and G4 says "the tab pill moves on tap". The plan's list (`#bottom-nav`, the rail, the dock rows) left this surface out.

**Fix.** The same delegation as F1:
- In `onClick`, accept `header .site-header-places a.nav-tab[data-nav-place]`. The facts already read the bar's `aria-current`.
- Run the pointer handlers for `BAR_TAB` inside `.site-header-places` too, either with a listener on the header or by moving the pointer listeners to `document` and filtering by both containers.

**Test.** `checks/GEST-03.py` / `GEST-04.py` at 820 with touch:
- Hub scrolled to 800, tap the compact Home → `scrollY` 0, probe holds.
- Scripted pointerdown on the compact Community → `.is-pending` within 100 ms.

Today the first starts a visit and the second adds no class.

### F6 · minor · `assets/controllers/tab_gestures_controller.js:52-53, 186-209`
**Problem.** With a mouse (the rail, from 1024 px), the pending pill can get stuck.
- `pointermove` and `pointerup` are listened to on the bar only. Touch and pen are implicitly captured to the tab; a mouse is not.
- Scenario:
  1. Press on a rail tab.
  2. Drag quickly off the 88 px rail, so the first coalesced `pointermove` already lands outside it.
  3. Release on the page.
- The bar then sees no move over 10 px, no `pointerup` (so the 400 ms timer never starts), no `click` and no `pointercancel`.
- The pressed tab keeps `.is-pending` and the current one keeps `.is-leaving`, so the current place shows no pill. This lasts until the next pointerdown in the bar or the next visit.

**Why it matters.** The bar then says "you are elsewhere", which is wrong. The commit lists "a release without a click" as handled.

**Fix.** While a mark is pending, listen to `pointermove`, `pointerup` and `pointercancel` on `window` (capture): add them in `onPointerDown`, remove them in `revert()`. Do not use `setPointerCapture()`: it would send the click to the tab on a release outside it.

**Test.** `checks/GEST-04.py` at 1280, with CDP `Input.dispatchMouseEvent`:
1. Press on the rail's Community.
2. One `mouseMoved` to x = 600.
3. Release.
4. 500 ms later, expect no `.is-pending` and no `.is-leaving` in `#bottom-nav`.

Today both remain.

### F7 · minor · `assets/styles/_press-states.scss:28-41, 74-80`
**Problem.** The table rows' layer (`.row-link > *`) has two gaps.
- **It matches the class only.**
  - `row_link_controller.js` adds `.row-link` on connect.
  - `_dynamic-modal.scss:23-28` already records that "a Live Component re-render resets the class attribute to the server's". That is why the cursor rule also matches `[data-controller~="row-link"]`.
  - The press layer and the tap-highlight reset match the class alone.
  - Every table with row links is a Live Component: PuzzleTimes, LadderTable, and RecentActivity, which re-renders every 60 s and now also on `msp:feed-refresh`.
  - After a re-render, the rows lose the layer and the grey tap highlight comes back.
- **The own row hides it while hovered.**
  - Bootstrap 5.2 draws a cell's accent as `box-shadow: inset 0 0 0 9999px var(--cz-table-accent-bg)`. That shadow paints above `background-image`.
  - Hovered `.table-hover` rows take `--cz-table-hover-bg`, and `.table-active-player` sets it to an opaque `#f6e5fc` (`app.scss:346-356`).
  - So on the viewer's own row (the row they tap most) the layer is hidden whenever the row is hovered: always with a mouse, and from the tap on with touch.

**Why it matters.** G4 covers link rows. The C-GEST-04 check pressed a feed row right after load, and not the viewer's own row, so it could see neither case.

**Fix.**
- Add `[data-controller~="row-link"]` next to `.row-link` in the three selectors.
- Draw the cells' layer as a second inset shadow over the accent, instead of the gradient there:

  ```scss
  :is(.row-link, [data-controller~="row-link"]) > * {
      box-shadow: inset 0 0 0 9999px var(--nav-press-layer), inset 0 0 0 9999px var(--cz-table-accent-bg);
  }
  ```

  The fade on `--nav-press-layer` keeps working.

**Test.**
- `PressStatesCssTest`: the pressed, tap-highlight and layer rules also match `[data-controller~="row-link"]`.
- `checks/GEST-04.py` at 1280:
  1. On the Hub, dispatch `msp:feed-refresh` and wait for the render.
  2. Hold the mouse down on the viewer's own row.
  3. Expect the cell's screenshot pixel to differ from its hovered colour while held.

Today it does not.

### F8 · minor · `assets/controllers/pwa_lifecycle_controller.js:202-214, 226-238, 282-286`
**Problem.** If a second finger lands during a pull, the indicator stays on screen.
- `_handleTouchStart` calls `this._gesture.start(…, event.touches.length)` again. With two touches the gesture goes `idle`, but the indicator is not reset.
- The next `touchmove`s return at `state === 'idle'`, and `touchend` only calls `cancel()`.
- The disc stays where it was, still `is-pulling` and at full opacity (100 px of travel already gives opacity 1), until the next pull or a reload. Meanwhile the browser takes the pinch.
- The row in `checks/GEST-06.py` puts both fingers down together, so it never meets this case.
- The code is older than GEST-06, but GEST-06 keeps "one finger" as a guard and owns this file.

**Why it matters.** The installed app is left with a refresh disc stuck under the top bar, after a common gesture: a pull that turns into a pinch-zoom.

**Fix.** In `_handleTouchStart`, before `start()`, add `if (this._gesture.pulling) { this._resetIndicator(); }`.

**Test.** `checks/GEST-06.py`, with standalone forced:
1. `touchStart` with one point.
2. `touchMove` by +100 px.
3. `touchStart` with two points.
4. `touchEnd`.
5. 400 ms later, expect the indicator at opacity 0, no `is-pulling`, and no reload.

Today it stays at opacity 1.

### F9 · minor · `tests/TouchActionRulesTest.php:122-145`
**Problem.** The guard checks only `touch-action: none`.
- `pan-x`, `pan-y` and `pan-x pan-y` without `pinch-zoom` take pinch-zoom away just the same.
- A future `.some-list { touch-action: pan-y; }` would pass every test.
- Today all is well only because the one such rule is the known chart exception.

**Why it matters.** G5, and WCAG 1.4.4 and 1.4.10, rest on pinch-zoom working everywhere, and this test is the only thing that keeps it so.

**Fix.**
- Collect every `touch-action` value in `assets/styles`, in inline styles and in JS that is not `auto` or `manipulation` and does not contain `pinch-zoom`.
- Assert that this set equals `NONE_ALLOWED` plus `app.scss` `canvas` → `pan-y` (decision 59).

**Test.** Mutation check: add `.x { touch-action: pan-y; }` to `_inbox.scss`. The new assertion fails; today the suite stays green.

## Questions

### Q1 · The current tab while another layer is open
On phones the bottom bar stays usable under the player-card sheet, the Help sheet, and the achievement and result sheets: every `.modal` ends above the bar. With any of them open, the current place's tab acts on the page behind:
- on a scrolled start page, it glides the page behind the sheet to the top and calls the top bar back under the scrim, while the layer stays open;
- at the top of the Hub, it refreshes the feed behind the sheet;
- on Add, it does nothing.

Only the Menu has the rule "the current tab only closes it" (README §4.2, the plan's `close-menu`). The card's own outside-click rule lets the tab through (`OUTSIDE_LEAVES_IT`), because it expects a visit to follow.

**Recommendation.** Generalise `close-menu` to "a layer holds the history entry":
- `decide()` takes a `layerOpen` fact (`layers.current() !== null`).
- For the current place's tab it returns `close-layer`, which calls `layers.requestClose(layers.current())`.
- Other tabs keep navigating, and rule (d) replaces the layer's entry.
- The Menu row of the harness becomes one case of this. Add rows for the card, a modal and the search view.

### Q2 · Pull-to-refresh over a changed form
A pull's reload drops typed input. At the top of the installed app, the add form is now one 128 px drag from empty (it was about 256 px), for example a downward swipe made to dismiss the keyboard. The controller already has `_hasActiveFormInput()` for the 15-minute reload, but the pull does not use it.

**Recommendation.** Don't arm the pull while `_hasActiveFormInput()` is true, or settle back without reloading at the release. The Help sheet's "Reload page" row stays the explicit way to reload. This follows "Add never reloads (a half-filled form survives)".

### Q3 · Weight: the new `data-nav-place` attributes on every page
The Menu's place rows now carry `data-nav-place`: three new attributes (Home, Community, Profile), about 74 B on every page. Puzzles already had one for PUZ-01. The member `/en/faq` margin is 387 B, and GEST-12's meta and the rest of Platform feel are still to come.

**Recommendation.** Derive the place in the controller instead:
- The dock's place rows already carry `mr--place` and `data-nav="menu/<group>/<id>"`, with ids `home`, `puzzles_place`, `community_place` and `profile`.
- Use `DOCK_ROW = '#menu-slot a.mr--place'` and take the place as `id.replace(/_place$/, '')`.
- Keep `data-nav-place` on the Puzzles row, which `puzzles_section.js` reads.
