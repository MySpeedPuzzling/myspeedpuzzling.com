# Phase review: NAV bars

- **Scope:** commits
  - `6aeebde9` (NAV-01 + NAV-02: `#bottom-nav`, `_places.html.twig`, `PlacesBuilder` / `PlaceLink` / `SignInLink`, the label-step table `_nav-label-steps.scss`, `bottom_nav_controller.js`);
  - `be7b5c7c` (NAV-03 + NAV-10: `_topbar.html.twig`, `topbar_controller.js` / `topbar_scroll.js`, the search view in `global_search_controller.js`, `assets/head/standalone.js`, the critical CSS of the bar, `header_height_controller.js` deleted, `HomepageController`);
  - `b2979962` (NAV-04 + NAV-05: the desktop tiers, Ctrl/Cmd+K, the long placeholder, `_account_menu.html.twig`);
  - `d6e67ca0` (NAV-08 + NAV-09: `nav_chrome_off` / `body.no-nav-chrome`, `_auth_header.html.twig`, the keyboard rule, `hide_bottom_bar` = phone tab bar only);
  - `37385adb` + `7ff9d3d6` (NAV-13: `--nav-left` tiers, the rail, the docked column, the overlay, `assets/head/nav_layout.js`, `assets/navigation/nav_layout.js`, `menu_links.js`, the `layout` beacon);
  - where the bars touch them: `f894428d` (stopwatch pill), `aff8cc50` (MENU + Help review fixes), `34ea0d9b` + `55233667` (HELP-02…05).
- **Checked against:**
  - `requirements.md`: Conventions and the Done when / Verify of NAV-01, NAV-02, NAV-03, NAV-04, NAV-05, NAV-08, NAV-09, NAV-10, NAV-13 (NAV-07 / NAV-11 / NAV-14 only where these items already build their parts).
  - `README.md` D1, D2, D5, D14, D15, D16, §4.1, §4.2, §4.3, §4.10, §4.12 "Menu data", §4.14, §5. D1–D22 not reopened.
  - `implementation-plan.md`: §3 (chrome selector rule §3.2, `data-nav` §3.3, layer checklist §3.4, native-ready checklist §3.5, translations §3.6, budgets §3.7, guest caching §3.10, head order and critical CSS §3.11), the NAV sections of §4 and decisions 17–31, 46, 87, 95, 101.
  - `delivery/state.md` decisions log (logo kept in the search view, 4 px between tools, `body.global-search-shown` alias, sticky followers only while stuck, `header_height_controller.js` deleted early, long placeholder only where it fits, de "Feedback", NAV-05 `data-nav` ids, `hide_bottom_bar` = phone tab bar only) - judged on their merits.
  - `CLAUDE.md`: `ResetInterface` in worker mode, no new SQL per page view for chrome, guest HTML shared-cacheable, Turbo rules, translations in 6 locales, accessibility.
- **Method:**
  - Read the six diffs and the current state of every file they touch, plus the code they call:
    - `base.html.twig` (head order, `<body>` classes, the `header` / `nav_chrome` / `nav_layers` blocks), `_critical_css.html.twig` line by line against `_nav-tokens.scss`, `_nav-bars.scss`, `_nav-topbar.scss`, `_nav-layout.scss`, `_nav-menu.scss` and every JS constant that repeats a length or a media query;
    - `MenuBuilder::count()` / `MenuView::hasQueueBacklog()`, `NavigationTwigExtension` (the `_nav_location` memo), `AddEntryTwigExtension`, `AnonymousCacheHeadersSubscriber`, `compact_bar_controller.js`, `layer_history.js` usage, `menu_counts.js`, `inbox/_link.html.twig`, `_stopwatch_pill.html.twig`, the three bar-free templates and `_auth_layout.html.twig`.
  - Checked the stylesheet is a plain render-blocking `<link>` (`encore_entry_link_tags('app')`), so critical CSS and the full stylesheet apply together at first paint; looked for full-stylesheet rules that override a critical box (none found).
  - Grepped every writer of `<html>` attributes (`data-topbar`, `data-search-open`, `data-nav-layout`, `data-nav-collapsed`) and every `body.global-search-shown` / `html[data-search-open]` style hook.
  - Translations: parsed all 6 `messages.*.yml` before and after each commit with a script.
    - 22 keys added (4 + 8 + 8 + 0 + 2), none removed, every one in all 6 locales.
    - Values equal to English: `nav.bar.menu` cs/fr ("Menu", allow-listed) and `nav.bar.feedback` de ("Feedback", logged decision).
    - Read every new value for tone (no de du/dein, fr tu/ton, es usted) and checked that each visible label is inside its accessible name in all 6 locales (Feedback ⊂ Help & feedback, Inbox title ⊂ every `inbox.badge.name.*`, "Menu" label = name).
  - Budget tests touched by the commits (`DetailPagesQueryBudgetTest`, `OrganizationPagesQueryBudgetTest`, `MyActivityControllerTest`, `ApiUsageControllerTest`): comment or selector changes only, no pin moved.
  - No tests, builds or docker commands were run.
- **Result:** 0 blocker · 2 major · 4 minor.

## Findings

### F1 · major · `assets/controllers/topbar_controller.js:52-62` (`disconnect()`), `assets/styles/_nav-topbar.scss:30-50`, `templates/navigation/_auth_header.html.twig:13`
**Problem.** `html[data-topbar="hidden"]` outlives the page that set it.
- `topbar_controller` writes `data-topbar` / `data-topbar-scrolled` on `<html>`, which Turbo keeps across visits. Only the next page's `connect()` resets it. `disconnect()` leaves both attributes as they are (compare `compact_bar_controller.js` `disconnect()`, which removes its `<html>` property for exactly this reason: "the next one may have no bar").
- Pages without the controller keep the stale state. The auth pages render `header.site-header.site-header--auth` (no `data-controller="topbar"`), and under the phone query `:root[data-topbar="hidden"] .site-header { transform: translateY(-100%) }` applies to it.
- Result: the sign-in / sign-up header (logo + language switch, NAV-08) is translated out of view, leaving a blank 52 px band. Nothing brings it back on that page: there is no controller to listen to scroll.
- The path is common. None of the 42 template lines that build a `login` / `register` URL carries `data-turbo="false"` (one is a form action). The guest Profile tab (`SignInLink`), the desktop Sign in and the guest Menu card are plain Turbo links too. A guest who scrolled a page before tapping them is exactly the bar-hidden case.
- Bar-free pages inherit `--topbar-h: 0` / `--topbar-shift: 52px` the same way. Nothing there follows the bar today, so only the auth header shows it.

**Reproduction.**
1. As a guest at 390, open a puzzle page and scroll 400 px down: the top bar hides (`html[data-topbar="hidden"]`).
2. Tap the Profile tab in the bottom bar (still on screen): a Turbo visit to `/en/login?return=…`.
3. The auth header is not visible: `getComputedStyle(document.querySelector('.site-header--auth')).transform` is `matrix(1, 0, 0, 1, 0, -52)`, and `<html>` still has `data-topbar="hidden"`.

**Fix.**
- Reset before the new body is shown: in `topbar_controller`, listen to `turbo:before-render` and set `data-topbar="shown"` and remove `data-topbar-scrolled` there. In `disconnect()`, also remove both attributes, so a page without the controller starts neutral.
- Optionally scope the hide rules to the managed bar: `:root[data-topbar="hidden"] .site-header[data-controller~="topbar"]`.
- Add a WebDriver row to `checks/NAV-08.py`: scroll a phone page until the bar hides, click a Turbo link to `/en/login`, and assert the auth header's rect top is 0.

### F2 · major · `assets/controllers/global_search_controller.js:124-130` (`handleFocus`), `:144-156` (`handleKeydown`), `:367-389` (`openView`); `assets/styles/app.scss:1443-1459`; `templates/navigation/_critical_css.html.twig:118`
**Problem.** On desktop, focusing the always-visible search field turns the page into a modal search, and keyboard users cannot get out of it.
- `handleFocus` → `openView()` sets `html[data-search-open]` on every focus of the bar's field, desktop included.
- Two rules then apply at every width:
  - `app.scss` `html[data-search-open] { overflow: hidden }` locks page scrolling. With classic scrollbars (Windows, Linux) it also removes the root scrollbar, so the whole page jumps sideways by the scrollbar's width on each focus and each close.
  - Critical CSS line 118 shows `.global-search-overlay`: a 60 % black scrim over the content. The docked column and the rail (z 1029) sit above the scrim (z 1014), so they stay bright beside dimmed content.
  - With an empty query the dropdown under the field is empty, so a first click shows only the scrim.
- `body.global-search-shown` also hides the collection selection bar, the comparison launcher and the homepage language banner (`_collection-selection.scss:71`, `_comparison-entry.scss:46`, `_homepage.scss:314`).
- Leaving the search closes it in only two ways:
  - a click outside (`handleOutsideClick`);
  - Esc, but only while the focus is inside the instance (`handleKeydown` is bound on `this.element`).
- There is no `focusout` handling. The desktop Tab order (skip link → ☰ → logo → **search** → … → avatar → column rows → main) passes through the field. A keyboard user who tabs past it keeps the scrim and the scroll lock while moving through the bar, the column and the page, and Esc does nothing there. The only way out is Shift+Tab back into the field, then Esc.
- README §4.3 / decision 22 describe a dropdown under the field. Nothing in the README, the plan or the decisions log asks for a page-wide scrim or a scroll lock on desktop. Those come from the old popup search, which opened only from an explicit icon.

**Reproduction.**
1. Desktop 1440, any page, mouse: click the search field. The page dims and the wheel no longer scrolls; on Windows the content shifts by about 15 px.
2. Keyboard: Tab from the logo into the field, then keep tabbing to + Add, Feedback and the column rows. The scrim and `html[data-search-open]` stay. Esc on a column row does nothing.

**Fix.**
- Close the desktop dropdown when the focus leaves it. On the instance root, handle `focusout`: when `this.shown && !this.layered` and `event.relatedTarget` is outside `this.element` and not inside a `.modal` (the locked-scan members modal), call `closeView('close')` without the blur.
- Keep the scroll lock and the scrim on phones only: wrap `html[data-search-open] { overflow: hidden }` and the critical `.global-search-overlay` rule in the phone query. If a desktop scrim is wanted after all, add `scrollbar-gutter: stable` on `<html>`, so the page does not jump.
- Add a desktop row to `checks/NAV-04.py`: focus the field, press Tab three times, and assert that `html[data-search-open]` is gone and the page scrolls.

### F3 · minor · `assets/topbar_scroll.js:67-88` (`nextTopbarState`), `assets/controllers/topbar_controller.js:75-78`
**Problem.** The bar reappears after every fling to the bottom of a page on iPhones.
- `nextTopbarState()` clamps `scrollY` at 0, which handles the top rubber band, but not at the maximum.
- iOS Safari reports `scrollY` above `scrollHeight - innerHeight` while the page bounces at its end. The bounce back reads as a scroll up of more than 8 px, so the bar slides in at the end of every long page.
- The brief lists the iOS rubber band as an edge case. Android does not overscroll `scrollY`.

**Reproduction.** Device only (QA-06): on an iPhone, fling a long page to its end. The bar comes back while the page settles. In the node harness: feed `y = max + 40`, then `y = max`, with the bar hidden, and the result is `hidden: false`.

**Fix.**
- Pass the maximum in and clamp both ends: `const max = document.documentElement.scrollHeight - window.innerHeight` in `update()`, and `current = Math.min(Math.max(0, y), max)` in `nextTopbarState()`.
- Add the harness case above.

### F4 · minor · `templates/navigation/_critical_css.html.twig:70`
**Problem.** Without `:has()` (Safari < 15.4, Firefox < 121, Chrome < 105), every phone page reserves the tab bar's height, including pages with no tab bar:
- the auth pages;
- the bar-free pages (participants sheet, round stopwatch, live results entry);
- the multiscan member page (`.bottom-nav--tabbar-hidden`).

`body` gets 56 px + inset of bottom padding, and every fixed bottom element (`nav-bottom`) floats that far above the edge. On multiscan, its action bar hangs 56 px up. The comment documents the fallback, but these pages already tell the server they have no tab bar.

**Reproduction.** In a browser without `:has()` (or with that rule forced), open `/en/login` or the multiscan member page at 360: there is a blank strip at the bottom, and multiscan's action bar sits above it.

**Fix.** The server already knows the page has no tab bar:
- add `body.no-nav-chrome{--bottom-nav-height:0px}`;
- render a body class for `hide_bottom_bar` (e.g. `no-tab-bar`) with the same rule.

The `:has()` path is unchanged by this (the body value only repeats the 0 it computes).

### F5 · minor · `assets/controllers/global_search_controller.js:400-416` (`closeView`), `:419-431` (`closeSearchBar`)
**Problem.** Closing the desktop search drops the keyboard focus on `<body>`.
- Esc in the field: `closeView('close')` blurs the input (`reason !== 'cache'`). That is needed on phones (the keyboard goes away), but on desktop it throws the focus to `<body>`.
- In the 768–1023 px bar the field and its × are then `display: none`, so closing by Esc or by × leaves nothing focused. Focus should go back to the 🔍 toggle that opened it.
- WCAG 2.4.3: a closed popup returns the focus to its opener.

**Reproduction.**
1. At 1440, Tab into the search and press Esc: `document.activeElement === document.body`.
2. At 900, press Ctrl+K, type, press Esc (or Tab to × and press Enter): `activeElement` is `body` again.

**Fix.**
- Blur only when `this.layered` (phones).
- On desktop, keep the focus in the field when it stays shown (from 1024 px). When it hides (768–1023), focus the shown `[data-global-search-toggle]` of the header.

### F6 · minor · `templates/navigation/_auth_header.html.twig:19-25`, `assets/styles/_nav-topbar.scss` (`.site-header-languages`)
**Problem.** The auth header's language switch is a bare `<details>`: it does not close on Esc or on a click outside.
- Its list is absolutely positioned under the switch, over the sign-in form at the right edge. It stays open until the summary is tapped again. A `<details>` has no light dismiss, and no script adds one.
- The old header's switch was a Bootstrap dropdown, which closes on both.
- The `<details>` choice itself is sound (plan §3.2, works before any script).

**Reproduction.** At 360 on `/en/login`: open the language switch, then tap the e-mail field or press Esc. The list stays over the form.

**Fix.** Add a tiny shared helper (or a Stimulus controller on the `<details>`): on `keydown` Esc inside it, `open = false` and focus the summary; on a document click outside it, `open = false`. The NAV-05 account menu's Language `<details>` closes with its dropdown and needs nothing.

## Questions

**Q1 (a slide-in after every visit, NAV-07).**
- With the bar hidden, a Turbo visit inserts the new header while `<html>` still says `data-topbar="hidden"`. Stimulus then sets `shown` in its connect microtask.
- If anything forces a style calculation in between (Turbo's `scrollTo` after the body swap does), the header gets a before-change style with the transform. The 280 ms show transition then plays on the new page.
- Not verified in a browser. NAV-07's "rects identical before/after each visit" would catch it, and F1's `turbo:before-render` reset removes it too.

**Q2 (rail labels at 200 % root font size, NAV-11).**
- In the rail, `bottom_nav_controller.fit()` still runs: the bar is visible there.
- At a 200 % root font the 22 px labels no longer fit the 84 px item, so it sets `data-label-step-override="3"`, and the rail shows only the current place's label. README §4.3 says the rail's labels are "always visible".
- Without the override they would be clipped by the rail's `overflow-x: hidden`. NAV-11's "at 200 % root font size no label clipped" has to pick one: hidden labels, or two-line labels in the rail.

**Q3 (`html[data-stopwatch-running]`).** The critical rule zeroes `--bottom-nav-height` under `:root[data-stopwatch-running]`, but nothing sets that attribute. Is it reserved for ADD-04 (the running-stopwatch page without bars)? If ADD-04 instead empties `nav_chrome` like the other bar-free pages, the selector is dead and should go.

**Q4 (the ☰ dot for screen readers).** `.nav-menu-dot` is `aria-hidden`, and the ☰'s name stays "Menu". A moderator using a screen reader does not learn that a queue holds items until the Menu is open. README §4.12 only asks for the dot. Should the name carry it ("Menu, items waiting"), as the Inbox name carries its count?

**Q5 (☰ on the `menu` page).** The `menu` page renders no `#menu-layer`, so no `menu-layer` controller listens for Menu links. Both ☰ there are plain links to the page itself, and a tap reloads it through Turbo. From 1280 px, the desktop ☰ does not dock or fold the column there either. Intended (the page is the Menu), or should the desktop ☰ still switch the stored layout there?

## Checked and fine

**Guest caching and privacy.**
- Every guest-visible part of the chrome reads only the URL:
  - the places' hrefs (`PlacesBuilder`);
  - `aria-current` (the `_nav_location` request memo);
  - Sign in = `SignInLink::href()` through `ReturnUrl::tryFrom()`, with a `/login` loop guard;
  - `help_path()` and `add_entry_url()` (`add_intro` for guests).
- `data-viewer-signed-in`, the Inbox link, the account menu, the ☰ dot and the avatar are rendered for signed-in players only.
- `data-os`, `is-standalone`, `data-nav-layout` and `data-nav-collapsed` are client-only, from head scripts. The collapsed value is validated (`^[a-z_ ]{0,80}$`) and only ever compared with `~=`.
- Worker mode:
  - `PlacesBuilder`, `NavigationPlacesTwigExtension` and `PlaceLink` are `readonly` with no memo;
  - `SignInLink` is static;
  - `MenuView::hasQueueBacklog()` and `NavLocation::isRootPage()` are pure;
  - the per-request memos stay on the `Request` (`_nav_location`) or in `ResetInterface` services (`MenuBuilder`, `AdminQueueCountsTwigExtension`).

**Per-page cost.**
- The top bar's `nav_menu()` hits `MenuBuilder`'s memo under the same `slot|row` key as the Menu slot: one assembly per page. Only the `menu` page builds a second (`page`) one, CPU only.
- The ☰ dot reads the row counts, which come from the memoised `admin_queue_counts()`, called only for moderators/admins (`MenuBuilder::count()`).
- Guests never build the Menu for the dot (`profile is not null and …`).
- `nav_places()` runs three times per page (bar, compact, the back link): array lookups and URL generation, no statement.
- No budget pin moved in these commits; only comments and one `main a[aria-current]` selector changed.

**Layout constants agree everywhere.**
- 52 / 64 px bar: critical CSS, `$nav-topbar-*-h`, `PHONE_TOPBAR_PX`. 56 px tab bar. 88 / 256 px.
- 480 px short viewport: CSS and `SHORT_VIEWPORT_PX`.
- 1024 / 1280 / 1440: critical CSS, `$nav-rail-media`, `$nav-dock-default-from`, `WIDE_QUERY`, `TIER_QUERIES`.
- The phone query is identical in `topbar_controller`, `global_search_controller`, `menu_links` and the SCSS mixins.
- `--nav-left`, `--topbar-h` and `--bottom-nav-height` are `@property`-registered and set only in critical CSS. `dockState()` reads the computed `--nav-left` (no JS write).
- `compact_bar_controller` uses the static `--header-height`.
- Safe areas:
  - the tab bar: the max-inset pattern plus side insets;
  - the top bar: side insets;
  - the search panel: side insets;
  - the panel header: the top inset.
- `apple-mobile-web-app-status-bar-style` is `default`, so no top inset is needed in standalone.

**Phone bars (NAV-01, NAV-02, NAV-03).**
- The tab bar is fixed and never hides on scroll. The Add tile is never selected, and Profile = the avatar (ring when current, person icon for guests).
- `aria-current` is "page" on the exact path and "true" deeper (decision 27).
- Label steps:
  - the container queries are generated from the committed table per `html[lang]` on the bar's content box;
  - the JS only raises (`readStep` / `chooseStep`), measures in one task, and no `ResizeObserver` loop is possible (fixed bar height);
  - no `text-overflow`, and every tab keeps its name in step 3.
- Top bar: DOM order, 4 px gaps and 44 × 44 targets are in critical CSS, and the logo never hides (the decision is sound: the README wins).
- The pill / 🔍 / back variants are decided from the markup, with `.site-header--stopwatch` as the server-side fallback for `:has()`.
- Hide on scroll:
  - passive scroll + rAF; never on desktop, below 480 px tall, in the app, with the search / Menu / dropdown open, or with the focus inside;
  - transform only while hidden, a fade under reduced motion;
  - sticky followers translate only while marked stuck (re-measured before each hide), and `STICKY_FOLLOWERS` is pinned to the SCSS.
- Listeners are removed on `disconnect()`.
- The search view:
  - it is a GEST-01 layer: one entry, Back / × / Esc close it, and a result or a tab is a replace visit (`onLayerClose('leave')`);
  - `disconnect()` takes `data-search-open` off `<html>` when the page leaves with the search open;
  - the 🔍 opens and focuses synchronously in the tap (iOS keyboard), and Turbo's window listener sees `defaultPrevented`;
  - the scan button stops on close.

**Desktop (NAV-04, NAV-05, NAV-13).**
- One 64 px row per tier; no place dropdowns.
- Ctrl/Cmd+K:
  - ignores Alt/Shift, other fields and `contenteditable`;
  - is not handled while a `.modal.show` or a `dialog[open]` is open;
  - opens the field first in the 768–1023 px bar.
- The long placeholder is measured with canvas in the input's font and re-applied after Live renders.
- + Add in white on `#d63c42` (4.58:1) with `add_entry_url()`.
- Account menu:
  - a Bootstrap dropdown with static display and `auto-close="outside"` (Tab out and Esc still close it);
  - the XP ring is behind the gate, the items are static, Log out is `data-turbo="false"`, `.dropdown-menu` appears only there, and the role links stay in the Menu.
- Rail = `#bottom-nav` restyled: Add hidden; a guest's Profile reads "Sign in" there (its name = the shown label); labels fit 84 px in all 6 locales at 100 %.
- Column:
  - `#site-menu` in its slot, its own scroller;
  - folded groups hidden before paint (critical CSS) and marked in `turbo:before-render` / the inline script;
  - own `scrollTop`, never `scrollIntoView`;
  - counts once per 5 minutes through the sessionStorage TTL, keyed per player.
- Overlay:
  - `showModal()` + `bindDialog()`, with its own `close` events counted out of the helper;
  - the first row is focused, and focus returns to the shown ☰ after the slide (or at once under reduced motion);
  - it closes when the window crosses a tier.
- The ☰'s ARIA from 1280 px: `aria-expanded` = docked, `aria-controls="site-menu"`, a fold/dock tooltip; below 1280 px: `aria-haspopup="dialog"`.
- `main`, the footer and `.legacy-chrome` are offset by `--nav-left`; `body.no-nav-chrome` = 0, `body.no-nav-column` = the rail; print = 0.
- The `layout` beacon carries the state + width bucket only, allow-listed server-side.

**Bar-free and auth (NAV-08), guests (NAV-09), Home (NAV-10).**
- The participants sheet, round stopwatch and live results entry empty `header`, `footer`, `nav_chrome` and `nav_layers` and set `nav_chrome_off`.
- The auth layout swaps in `_auth_header.html.twig`:
  - logo + a `<details>` language switch from `language_switch_urls()` (`?return=` kept and `?_locale=` added on `_auth_page` routes, validated at the point of use);
  - no input outside the form (the footer newsletter and the page help line are emptied too).
- The keyboard rule is CSS only, phones only, a fade under reduced motion; the room stays reserved (nothing moves under the finger).
- `hide_bottom_bar` = the phone tab bar only, the rail stays: the decision is sound (README §4.10 hides "the tab bar" because of the bottom action bar; the rail collides with nothing).
- Signed-in `/cs` … `/ja` → 302 to that locale's Hub; `/` keeps the profile-locale rule; guests 200 + public.

**Native app (NAV-14 parts).**
- The top bar, `nav_chrome` and `nav_layers` are wrapped in `is_web()`, and `body` gets `no-nav-chrome` in the app, so no bar controller connects there.
- The head scripts return early under `data-native-app`, and `menu_links` returns `page` there.
- `nav_layout.js` installs its document listeners in the app too, but each returns early or finds no slot. Its docblock says `installNavLayout()` "returns false inside the app", but it returns `true`, a comment-only nit.

**Accessibility.**
- Landmarks:
  - one banner (`body > header`; the old rows are a `<div>`);
  - the places `<nav>` (tab bar, rail or compact): only one is displayed at any width;
  - `#site-menu` is exposed only where it is shown.
- Accessible names contain the visible labels in all 6 locales.
- Every bar target is ≥ 44 × 44; column rows are 40 / 32 px and disclosure buttons ≥ 32 px.
- Focus styles use `--nav-focus`, and the current tab is marked in forced colours.
- Contrast: labels `#5f6b80` and the active ink `#c8323a` on white ≥ 4.5:1; the Add tile icon 3.62:1 (non-text).
- Reduced motion: the bar fade, the keyboard fade, no overlay slide, no caret rotation.

**Translations.** 22 new keys × 6 locales, by parse-compare; the tone holds, and the de "Feedback" / cs-fr "Menu" equal values are allow-listed or logged.
