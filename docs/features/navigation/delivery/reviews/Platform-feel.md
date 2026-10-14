# Phase review: Platform feel (GEST-10, GEST-11, GEST-12, GEST-16, GEST-17, GEST-18, GEST-19)

- **Scope:** two commits on `feature/navigation-redesign` (delivery worktree), read at HEAD `e318f698e`. The later commits (GEST review fixes `c48d3cdf` / `765527e1`, the main merge, LAUNCH) are context only.
  - `7e5a34db`, Platform feel part 1:
    - GEST-10: `assets/haptics.js`, its call sites, `signal()` for `assets/online/beeps.js`, the Settings › Preferences Vibration switch (`haptics_setting_controller.js`, `_haptics.scss`, 6 locales), `switch` on every `form-switch` checkbox, and `templates/form/msp_theme.html.twig`;
    - GEST-18: `assets/tab_long_press.js` and the long-press part of `tab_gestures_controller.js`, including the cherry-pick's follow()/unfollow() resolution;
    - GEST-11: `assets/press_feedback.js`, `_press-feedback.scss` and the single `--nav-press-ink`.
  - `e318f698e`, Platform feel part 2:
    - GEST-12 / GEST-17: `assets/view_transition_gate.js`, `_view-transitions.scss`, the always-rendered meta in `base.html.twig`, `data-morph-source` on the three card templates, `img.puzzle-hero`;
    - GEST-19: `assets/pull_indicator.js`, `_pull-to-refresh.scss`, `pwa_lifecycle_controller.js`;
    - GEST-16: `hub.html.twig`, `feed_swipe_controller.js`, `assets/feed_swipe.js`, `_hub-feeds.scss`, `live_refresh_controller.js`;
    - `MostActiveSoloPlayers` keeping the placeholder's shape.
- **Checked against:**
  - `requirements.md`: the Conventions, and the Done when / Verify of GEST-10, 11, 12, 16, 17, 18 and 19 (plus GEST-04/06 where these items lean on them).
  - `README.md`: §4.13 (G8, G10–G17, the per-platform table, "Identical everywhere", "Don't") and §4.14 (b)/(c). D1–D22 are not reopened.
  - `implementation-plan.md`: §3.2, §3.4, §3.5 (items 2, 4, 5, 8), §3.6, §3.7, §3.10 and §3.11; the sections GEST-10…19; decisions 61, 62, 63, 67 and 68; DC4 / DC10.
  - `delivery/state.md`: the rows of these items and the Decisions log. These are logged decisions, not findings:
    - `beeps.js` goes through `signal()`, outside the haptic rules;
    - the 450 ms ripple cover is the one exception to G8;
    - the add form keeps its outer `form-switch` wrapper;
    - one press ink, `rgba(0,0,0,.16)`, in `_press-states.scss`;
    - the ripple stays on inside the app (plan GEST-11 says so, and decision 62);
    - the numbered empty slots in MostActiveSoloPlayers.
  - `CLAUDE.md`:
    - the Turbo rules (snapshot cache off; restore visits sent to `location.href` in `app.js`);
    - translations in 6 locales;
    - no new per-page statements;
    - the Live Components gotchas.
- **Method:**
  - Read both diffs in full and every touched file at HEAD. Also read:
    - Turbo 8.0.23's `ViewTransitioner`, `PageSnapshot.prefersViewTransitions`, `Visit.loadResponse` / `renderPageSnapshot` / `fail()`, and the head merge;
    - Bootstrap 5.2.3's `Tab.show()` / `_activate()` and its delegated `addEventListener(…, isDelegated)`;
    - `layer_history.js` (turbo:click, release at turbo:visit) and `global_search_controller.js` (`onLayerClose` 'leave');
    - `topbar_controller.js`, `visit_scroll.js`, `app.js` (the restore redirect), `platform.js` and `head/platform_os.js`;
    - `LazyImageTwigExtension`, `_lazy-images.scss`, the full-bleed table rules in `app.scss`, `RecentActivity.html.twig`;
    - `EditTimeController` (what a refused frame submission answers), and `tabs_controller.js` / `favorites_seen_controller.js`.
  - Traced every path of the window listeners in `tab_gestures_controller.js`: pointerup with and without a click, pointercancel, contextmenu, a refused visit, disconnect and before-cache.
  - Ran no tests, builds or browser. The browser evidence is `checks/C-GEST-1{0,1,2,6,7,8,9}.log` plus the three PNGs under `var/navigation-delivery/GEST-16/`, which I viewed. Those PNGs show F2.
- **Result:** 0 blocker · 2 major · 4 minor · 2 questions.

## Findings

### F1 · major · `assets/controllers/feed_swipe_controller.js:62-68, 184-194, 197-230, 233-247`
**Problem.** Without `scrollend`, the feed scroller settles in the middle of a swipe.
- When `'onscrollend' in window` is false, `connect()` falls back to an IntersectionObserver at 0.6. This is the fallback the plan added for browsers without `scrollend` (Safari before that event ships, and older iOS versions in any case).
- When the incoming pane reaches 60 %, `paneInView()` → `showTab()` → `Tab.show()`. In Bootstrap 5.2.3, `_activate()` runs the tab link's `complete()` synchronously, because the link has no `.fade` (`_queueCallback(complete, element, false)`). So `shown.bs.tab` reaches `tabShown()` inside `showTab()`.
- `tabShown()` checks `scrollingTo`, which is null because there was no tap, but it never checks `showingItself`. It therefore calls `settle()` while the finger is still on the scroller:
  - `settledIndex()` rounds 0.6 to the new pane;
  - `applyStates()` makes the old pane `.is-resting` (height 0, overflow hidden) and `inert`, although 40 % of it is still on screen;
  - `fitHeight()` drops the scroller's fixed height, so everything below jumps to the new pane's height;
  - `feed-swipe:shown` fires;
  - `tabsScrollTop()` may `window.scrollTo()` up to the tabs while the finger moves sideways.
- On the next scroll event, `scrolled()` sees `scrollLeft` ≠ the new pane's start and calls `wake()`. The old pane gets its content back, clipped to the new pane's height. Once the scroll has rested for 150 ms, `settle()` runs again and rests it once more.
- **Scenario** on an iPhone without `scrollend`:
  1. On the Hub, scroll down to the third Favorites row and drag left.
  2. At 60 %, the visible strip of Favorites goes blank and the page glides up to the tabs during the drag.
  3. The strip comes back on the next move. A finger that pauses at 65 % keeps it blank.
  4. A drag to 65 % and back ends on Favorites, but shows the same flicker on the way.

**Why it matters.**
- GEST-16 Done when: "horizontal swipes switch the feeds with snap, tabs follow, … heights follow the visible feed". README G14: the tab follows the *settled* pane, with the IntersectionObserver as the fallback for selecting the tab, not for resting, inerting or scrolling.
- No test can see this:
  - `checks/GEST-16.py` runs only in Chromium, which has `scrollend`, so the fallback path never ran;
  - `FeedSwipeScriptTest::testTheControllerAndTheLiveFeed` only greps the controller's text;
  - the node harness covers only the maths in `feed_swipe.js`.

**Fix.**
- In `tabShown()`, return when `this.showingItself`: the controller's own selection never settles.
- Keep `paneInView()` to selecting the tab (aria-selected and `.active`, as planned). Resting, `inert`, the height, `feed-swipe:shown` and the scroll back up wait for `settle()` from the idle timer.

**Test.** WebDriver with Pixel emulation, with `onscrollend` removed before navigation (`delete window.onscrollend; delete Window.prototype.onscrollend`, so that `hasScrollEnd` is false):
1. Drag with CDP touch to 65 % of the pitch and hold for 300 ms.
2. Expect pane 1 not `.is-resting`, `scrollY` unchanged and the scroller's height unchanged.
3. Release and expect pane 2 selected, pane 1 resting and inert, as today.

Today pane 1 is `.is-resting` while the finger holds it.

### F2 · major · `assets/styles/_hub-feeds.scss:60-96, 128-140`; `assets/styles/app.scss:317-345`; `templates/components/RecentActivity.html.twig:21, 149`
**Problem.** The phone scroller clips the feeds' full-bleed tables.
- Below 576 px, `.custom-table-wrapper` runs edge to edge: `margin-left/right: calc(50% - 50vw)`, with 15 px on the outer cells (`app.scss:317-345`).
- Both feeds render their table as `.custom-table-wrapper.ps-wrapper` (`RecentActivity.html.twig:21, 149`), inside `.hub-feeds`. Below 768 px that is now `overflow-x: auto; overflow-y: hidden`. A scroll container clips both axes, and overflow on the start side cannot even be scrolled to.
- The effect:
  - the table's left 15–16 px is cut at the scroller's left edge, and that is exactly the outer cells' padding;
  - its right 15 px falls into the column gap, out of view;
  - thumbnails and times sit flush on the cut edges;
  - the rows stop 15 px short of the screen;
  - the side shadow is gone.
- `var/navigation-delivery/GEST-16/pixel-360-hub-at-load.png` and `-swiped.png` show it. Compare the feed with the "Most active SOLO players" table right below it, which still runs edge to edge. The Home page now has two table widths.
- INB-04b's "new since your last visit" bar is `left: 0` on the row's first cell, 3 px wide (`_hub-feeds.scss:128-140`). That is inside the clipped 15 px, so on phones under 576 px the bar is never visible. Only the faint tint is left.
- Between 576 and 767 px the tables are not full-bleed, but their 10 px shadow is still clipped on the sides and the bottom.

**Why it matters.**
- The Hub is Home: every member's and guest's first page on a phone, with both feeds affected.
- It breaks the project rule that tables of results run edge to edge on phones (the `app.scss` comment).
- It hides INB-04b's highlight, which is an already verified behaviour.

**Fix.** Make the scroller the full-bleed element, not its content:
- Below 576 px, give `.hub-feeds` `margin-inline: calc(50% - 50vw)`.
- Give each `> .tab-pane` `padding-inline` equal to the page's side padding, from the same variable the container uses.
- The tables' own `calc(50% - 50vw)`, measured from the pane's content box, then reaches exactly the pane's (screen's) edges. Alerts and the "Show more" row keep their inset.
- `paneLeft()`, `pitch()` and the snap need no change: panes stay 100 % of the scroller.

**Test.** WebDriver with Pixel emulation at 360, on both panes:
- the feed's `.custom-table-wrapper` box is `[0, innerWidth]`;
- `document.elementFromPoint(2, <first row's centre>)` is inside that row;
- with a `.ra-new` row (fixtures from INB-04b's test), the pixel at x = 1 in that row is the badge colour.

Today `elementFromPoint` hits outside the table.

### F3 · minor · `assets/styles/_view-transitions.scss:18-37`; `assets/styles/_nav-topbar.scss:556-567`
**Problem.** The open phone search view rides on the top bar's capture.
- On phones the results panel is `position: fixed` *inside* `header.site-header`, from the bar down to the bottom bar. Decision 20 already keeps the bar free of transforms for that reason.
- While `html[data-vt]` is set, the header is `msp-topbar`. A named element's old image is the element and its descendants, so the open results panel is part of it. The one exception is the tapped thumbnail, which is captured on its own as `puzzle-hero`.
- Every group, image pair and old/new image has `animation: none`:
  - the old `msp-topbar` image (header plus panel, full height, opaque) stays above the root group for the whole transition;
  - the new header image is only 52 px tall, so it covers just the top of it.
- Scenarios:
  - **(a)** A morph from a phone search result, one of G15's three surfaces. The thumbnail flies out of a frozen results list. That list covers the incoming puzzle page for 300 ms and then vanishes in one frame, instead of "the page cross-fades in 150 ms".
  - **(b)** Android, with the search view open: a tab tap. The bottom bar stays usable, so this is a place fade. The results stay on top of the fading-in place for 300 ms.
- `checks/GEST-17.py` ("its result's thumbnail morphs") counts animations, and this lingering image has none. `checks/GEST-12.py` never opens the search view.

**Why it matters.**
- README G15: "root cross-fade 150 ms".
- G12: the place fades through with still bars, and a stale results list is not a still bar.

**Fix.** Don't name the bar while the search view is open:

```scss
html[data-vt]:not([data-search-open]) body > header.site-header {
    view-transition-name: msp-topbar;
}
```

- The old header, with its panel, is then part of the root. It fades out with the root on a place switch, and the new root's 150 ms fade covers it on a morph.
- The new header enters unanimated: `global_search_controller` disconnects during the render and takes `data-search-open` off `<html>`.

**Test.** In the search row of `checks/GEST-17.py`:
1. Pause every animation 100 ms into the transition (`document.getAnimations().forEach((a) => a.pause())`).
2. Take a screenshot.
3. Compare the region below the bar with the puzzle page after the load, or check that the computed height of `::view-transition-old(msp-topbar)` is no taller than the bar.

Add the same check to a GEST-12 row that taps Community while the search view is open.

### F4 · minor · `templates/edit-profile.html.twig:51-60`; `assets/styles/_haptics.scss:5-7`
**Problem.** The Vibration switch shows inside the Android app, where it does nothing.
- The head script sets `data-os="android"` for the app's markers (`assets/head/platform_os.js:13`), so `.os-android-only` shows the card in the Hotwire Native Android app.
- There `haptic()` never plays: `backend()` is `'none'` and `shouldPlay()` requires `!env.native`.
- The explainer still promises "Short vibrations when a time is saved…".

**Why it matters.**
- README §4.14 (c): haptics inside the app are "none … until that exists" (the bridge).
- A setting that changes nothing is misleading, and the card is the only Preferences entry.

**Fix.** Render the card only on the web: `{% if not is_native_app() %}`. App responses are never public, so this is allowed by §3.5 item 8. Alternatively, add `html[data-native-app] .os-android-only { display: none !important; }`. Bring it back when the bridge reads the same setting.

**Test.** A functional test: `/en/edit-profile` with the Hotwire Native Android user agent has no `#preferences`, while the web user agent still has it (`SwitchAttributeTest`).

### F5 · minor · `assets/haptics.js:50-80`; `assets/controllers/haptics_setting_controller.js:11`
**Problem.** When storage is refused, the switch cannot silence haptics, not even for the page.
- The controller's docblock promises that "a storage that refuses … leaves the switch working for this page only".
- Nothing keeps that page-only state:
  - `writeSetting()` returns false;
  - `haptic()` reads `readSetting(globalStorage())` on every call;
  - a null or throwing storage reads as `'on'`.
- Scenario: Chrome on Android with site data blocked, so the `localStorage` getter throws. The player turns Vibration off. The switch shows off, but the next scan or save still vibrates.

**Why it matters.** README G10: per device, default on, and the setting must be able to turn haptics off. The documented fallback does not exist.

**Fix.** Keep a module-level override in `haptics.js`:
- `writeSetting()` sets it whenever the write fails;
- `readSetting()` prefers it when storage is null or throws.

**Test.** A `haptics-harness.mjs` case: with a storage whose `setItem` throws, `writeSetting(false)` and then `haptic('confirm', browserEnv({…same storage…}))` plays nothing. Today it plays 20.

### F6 · minor · `assets/styles/_hub-feeds.scss:79-95`; `templates/hub.html.twig:83, 102`
**Problem.** On phones, the second feed is unreachable until the lazy controller runs.
- Before `.is-awake`, which pane shows is decided by the server's `.is-resting` (`:not(.is-awake) > .tab-pane.is-resting { display: none }`). `> .tab-pane { display: block }` keeps the other one shown.
- Bootstrap's tabs work before `feed_swipe_controller` loads, because they are in the main bundle. A tap on "Everyone" therefore:
  - moves `.active` and `aria-selected` to Everyone;
  - leaves Everyone's pane at `display: none`;
  - keeps Favorites, now inactive, on screen.
- Two ways to hit it:
  - on a cold first load the lazy chunk takes a moment, and the state is fixed only when `layout()` runs;
  - if the chunk fails, for example on a bad connection, the second feed cannot be shown at all on phones. Before GEST-16 it could, with plain Bootstrap.

**Why it matters.**
- Before this phase the tabs did not depend on a lazy chunk.
- The selected tab and the shown pane disagree. That is an accessibility mismatch: `aria-selected` names a panel that is hidden.

**Fix.** Before the controller wakes the scroller, follow Bootstrap's state: `.hub-feeds:not(.is-awake) > .tab-pane:not(.active) { display: none; }`. The server's default pane is the `.active` one, so the load stays shift-free. `.is-resting` matters only once awake.

**Test.** WebDriver with Pixel emulation, `feed_swipe` blocked with CDP `Network.setBlockedURLs`:
1. Tap "Everyone".
2. Expect `#recent-activity` displayed and `#recent-favorites-activity` not.

Today the reverse is true.

## Questions

### Q1 · `signal()` vibrates inside the app
The logged decision keeps the online round's alerts outside "the haptic rules and the Vibration setting". `signal()` also skips `backend()`: it calls `env.vibrate` whenever one exists, and the harness pins `native: true` → plays (`haptics-harness.mjs:217`).

That contradicts two things:
- README §4.14 (b)/(c) and NAV-14 (b) list `navigator.vibrate` among what steps aside in the app, and say "none inside the app until [the bridge] exists";
- the module's own comment ("It goes through the same backend in one place").

**Recommendation.**
- Let `signal()` require `backend(env) === 'web'`. On the web nothing changes for participants, and once a bridge exists the alerts reach it through the one switch.
- Otherwise, log that §4.14's app rule does not cover the round's alerts, and correct the comment.

### Q2 · A view transition on every Android visit
G12's mechanics keep the meta `same-origin` on Android for every visit. A content link or a form's redirect then runs a view transition that animates nothing. In Turbo 8.0.23 that is not free:
- `turbo:load` waits for `startViewTransition(...).finished`;
- the whole render, including `mergeHead()` waiting for a new stylesheet, runs inside the update callback, while the browser shows a frozen capture and the progress bar does not paint;
- the pages with their own Encore entries (`online_rounds`, `online_watch`, `compete`) are such visits. On a slow connection, an organiser's tap appears to do nothing until that CSS arrives, up to Chrome's 4 s timeout.

The plan lists the cost as DC4 (INP on a device).

**Recommendation.**
- Keep the default meta `none` on Android as well, and set `same-origin` only when the tap arms `place` or `morph`. The visible result is the same.
- Change GEST-12's Verify row "a content link → 1 call, 0 animations" to "0 calls".
- If you prefer the README's mechanics as they are, measure the cost first in DC4.

## Checked and fine

- **Haptics (GEST-10):**
  - `haptic()` follows README G10's rules in order: Android, not native, setting not off, visible, `hasBeenActive !== false`, API present. It never throws, the patterns match the table, and `toggleOff` is silent.
  - Every `vibrate` call goes through `haptics.js`: the grep test covers scripts, styles and comments.
  - Scanner, live results (saved, name tag, newly failed saves only), holo medal (with its reduced-motion guard), pull threshold and stopwatch start / resume / finish all play after the state change.
  - "Time saved" fires on `turbo:submit-end` success: the redirect of the full page, and the stream answer of the modal edit (`requestPreventedHandlingResponse` gives `success: response.succeeded`).
  - Refusals answer 422: `EditTimeController` renders with the `FormInterface`, and the full-page add form does the same.
  - Live components' 422s are not Turbo submissions.
  - Turning the Vibration switch on stores first (the element listener) and then plays 10. Turning it off is silent.
- **Real switches:**
  - all 25+ `form-switch` checkboxes, `person_editor.js` and the three Live filter blocks carry `switch`, and the stopwatch, XP-leaderboard and MSP-rating switches gained `role="switch"`;
  - the theme falls through to Bootstrap 5's blocks (`{% use %}` + `parent()`), and no template sets its own `form_theme`;
  - `OnlineRoundSettingsFormType`'s `checkbox-switch` rows become real switches as well;
  - `switch="switch"` is a valid boolean attribute;
  - Chromium screenshots with and without it are identical (`C-GEST-10.log`).
- **Translations:**
  - `edit_profile.preferences.*` exists in all 6 locales, and none equals English;
  - the tone matches the surrounding keys: cs "vašem" (as the rest of `edit_profile`), de Sie, fr vous, es tú.
- **Long press (GEST-18):**
  - The window listeners are added while `pending` or `pressed` and removed on every exit: pointerup without a pending mark, the no-click timer, a click with no visit (setTimeout 0), pointercancel, `turbo:fetch-request-error`, `pageshow`, disconnect, `before-cache` and the next pointerdown. Nothing leaks.
  - A long press on the current tab with a cut label drops its click before `decide()`, so there is no scroll-up or feed refresh.
  - A mouse never starts one, and its right click keeps the menu.
  - `contextmenu` is prevented only on the held tab.
  - The cut is measured before the pending mark.
  - The bubble is removed at `before-cache`, `aria-describedby` is set only while it shows, and the haptic plays once.
  - Desktop gets `title`, and phones and the app get none.
- **Ripple (GEST-11):**
  - Android only, never under reduced motion (JS and CSS), and never on `data-press="layer"`.
  - Delays are 0 on the fixed bars and 150 ms elsewhere, with cancel at more than 10 px or on pointercancel.
  - Hosts are removed on `animationend` with a fallback timer, on cancel and at `before-cache`; position and isolation are restored only when the last host goes.
  - No ripple target sits inside a Live component: the only `data-press` under `templates/components` is GlobalSearch's `layer` card, so no morph or external-mutation interplay.
  - It stays on inside the app, as plan GEST-11 says explicitly ("Stays on inside the native app"), and README §4.14 (b) does not list pressed feedback.
- **View transitions (GEST-12 / GEST-17):**
  - Turbo 8.0.23 reads the *live* meta in `renderPageSnapshot()` and checks reduced motion itself (`prefersViewTransitions`); the gate's reduced-motion check is a second guard.
  - A restore visit and a `popstate` with `hasUAVisualTransition` set `none`. A layer's Back never reaches the gate (`stopImmediatePropagation` in `layer_history`).
  - The view transition starts only after the response, so a slow page never freezes the old one while it loads.
  - The morph skip runs at `turbo:before-fetch-response` on `<html>` only (decision 68).
  - `turbo:load` fires after `finished`, so names and `data-vt` are cleared only once the animation is over.
  - The bar names are unique (`#bottom-nav`, `#menu-slot`, the one `body > header`), and the hero name is set only on the new body. The `checks/GEST-17.py` "second card after Back" row shows no duplicate name.
  - Turbo's own FrameRedirector prevents the `turbo:click` of frame-targeted links (Help, card) before the gate runs, so they never arm.
  - A visit cancelled by a leave guard leaves `data-vt` armed until the next click, submit, visit or load, which is harmless.
  - The meta sits before the asset-failure script, outside the `msp-rendered-at` pair (§3.11); browsers ignore it for full-page navigations.
  - Inside the app it is `none` (native harness row).
  - `MORPH_THUMBNAIL_SELECTOR = 'img.lazy-img, img'` does not "prefer" `.lazy-img` (`querySelector` returns document order). It is harmless today, because every morph card's first `<img>` is the box.
- **Pull indicator (GEST-19):**
  - The thresholds are GEST-06's (`pullFrame` uses `pastThreshold` / `pullOffset`).
  - No `<style>` is injected, and the motion test now reads `_pull-to-refresh.scss`.
  - iOS has no arrow element, a transparent background, opacity = progress and rotate(progress × 270°).
  - The GEST review's F8 reset on a second finger and its Q2 rule (only named form controls block a pull) are both kept.
  - The z-index uses the `$z-nav-column` token.
- **Feed swipe, the Chromium path (GEST-16):**
  - Bootstrap 5.2.3's delegated click handler runs in the capture phase (`addEventListener(type, fn, isDelegated)`), so `show.bs.tab` comes before `tabs#showTab` and the scroll starts there.
  - `settle()` re-entering through `shown.bs.tab` is harmless: nothing has changed on the second pass.
  - The hidden pane is `inert`, both below 768 px and from 768 px; `aria-selected` and roving `tabindex` come from Bootstrap.
  - Heights follow through the ResizeObserver, also after "Show more" (a class) and Live re-renders.
  - `live_refresh` "shown" never applies inside `[inert]`, and in the scroller it uses the observer.
  - The other pane's lazy Live feed does not load early, thanks to the 1rem gap.
  - Vertical drags are not hijacked (`overflow-y: hidden`, no JS gesture code).
  - From 768 px the tabs work as before.
  - Load CLS is 0 (`C-GEST-16.log`).
- **MostActiveSoloPlayers:**
  - the empty slots are `aria-hidden` and hold no data (a rank and a dash);
  - the kept button is `invisible`, `disabled`, `tabindex="-1"` and `aria-hidden`;
  - it renders correctly for all three periods (the test) and is used only on the Hub;
  - it is template-only, with no statement.
- **Project rules:**
  - no new SQL and no per-visitor guest HTML: `data-morph-source` is per puzzle, and `.is-resting` follows `favorites_first`, which is the same for every guest;
  - no controller or route changes;
  - the native-aware modules are listed in `PlatformFlagReadersTest`, with harness rows for `haptics.js`, `tab_long_press.js` and `view_transition_gate.js`.
- **Tests:**
  - every node harness imports the shipped module, not a copy;
  - nothing was weakened: the only changed assertion moved `top: var(--topbar-h, 0px)` to the partial, and the `LATER` motion list became `PARTIALS` with the injected `<style>` gone;
  - the ripple is pinned as the one motion over 400 ms.
