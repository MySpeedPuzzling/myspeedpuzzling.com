# Phase review: MENU + Help sheet

- **Scope:** commits
  - `bc3c21c2` (MENU-01: the Menu from the registry, `NavigationViewer*`, `MenuBuilder`, `menu_counts`, `OrganizerSql`, `LegacyChromeSwitch`);
  - `f376e8fb` (HELP-01b: the Help sheet, `FeedbackController` / `HelpResponseBuilder`, the own scrim in `dynamic_modal_controller.js`, `feedback_draft.js`);
  - `813880db` (MENU-02b: the phone panel `<dialog id="menu-layer">`);
  - `f2253b51` (MENU-03: `nav-search.json`, `nav_go`, the two `GlobalSearch` instances, `nav_search.js`);
  - the merge `b368b6d3` of origin/main `a679d100`, and `f894428d` (the stopwatch pill reads millisecond laps).
- **Checked against:**
  - `requirements.md`: Conventions and the Done when / Verify of MENU-01, MENU-02a, MENU-02b, MENU-03 and HELP-01b (SRCH-01 where MENU-03 already builds it).
  - `README.md` §4.5 (Help & feedback) and §4.12 (the Menu: rows, Menu data, search-only entries, Search, Phone panel). D1–D22 not reopened.
  - `implementation-plan.md`: §3 (cross-cutting rules, chrome selector rule, layer checklist, native-ready checklist, translations, budgets, guest caching, privacy), the MENU + Help sheet sections, §7 (Help sheet transport) and decisions 3, 14, 29–39.
  - `CLAUDE.md`: single-action controllers, Messenger, `ResetInterface` in worker mode, Turbo form rules, translations in 6 locales, privacy rules.
- **Method:**
  - Read the six diffs and the current state of every file they touch (`git diff d6ef99d6..HEAD`), plus the code they call:
    - `layer_history.js`: open / requestClose / Esc / `LAYER_CLOSED_EVENT` / the same-page rule;
    - the registry, `RouteTarget` and the voters;
    - `AccountSetPasswordController`, `ChangeAccountPasswordController` and `ChangeAccountPasswordHandler`;
    - `barcode_scanner_controller.js`;
    - `AnonymousCacheHeadersSubscriber` and `SlidingLoginCookiesSubscriber`;
    - the Dockerfile and `.docker/on-startup.sh`, to see when the container is compiled (at image build, so one build id per deploy).
  - Merge: compared both parents and the merge base for the four conflicted files. Checked that main's global-search hooks (`body.global-search-shown`, `barcode-scan:close`) still fire from the rewritten controller.
  - Translations: parsed all 6 `messages.*.yml` before and after each commit with a script.
    - 170 keys were added or changed (55 + 6 + 0 + 109), plus 2 `validators.*` keys. Every one exists in all 6 locales, and none was removed.
    - Values equal to English: only de "Community", de/es/fr "Puzzles" and `nav.search.path_separator` (" › ").
    - Tone greps (de du/dein, fr tu/ton/ta, es usted) found nothing, and neither did a rival/suspicious grep in all six languages (incl. verdächtig, podezřel, sospech, suspect, 不審, ライバル).
  - No tests, builds or docker commands were run (the phase gate was using the shared test DB).
- **Result:** 0 blocker · 3 major · 10 minor.

## Findings

### F1 · major · `assets/navigation/nav_search.js:147` (`search()`), `src/Navigation/NavigationSearchIndex.php:121` (`privateJson()`), `src/Navigation/NavigationViewer.php:82-93` (`pageAudience()`), `tests/NavigationSearchScriptTest.php:333-334`
**Problem.** Moderators, time verifiers and admins never find their own role entries in the page search: Puzzle change requests, Merge requests, Puzzle approvals, Time verification, and every admin page. Each piece does what it says, but they do not fit together:
- `privateJson()` gives each private entry its role visibility as audience: `a` = `moderator`, `time_verifier` or `admin`.
- `pageAudience()` leaves the role audiences out of `<body data-viewer-audience>` on purpose (`isPageFlag()` = not a role).
- `search()` keeps an entry only when `audiences.has(entry.a)`, and `nav_search_controller.js:150` builds `audiences` from `data-viewer-audience` alone. So every entry from `#nav-search-private` is dropped on every real page.
- `NavigationSearchScriptTest` hides it. Its admin cases pass `[...MEMBER_ORGANISER, 'moderator', 'time_verifier', 'admin']` as audiences, which no page ever renders. The test crawls the admin's `/en/faq` for `#nav-search-private`, but not for that page's `data-viewer-audience`. The WebDriver check (`checks/MENU-03.py`) runs guest and member only.

MENU-03's Done when is not met for these roles ("every Menu destination … is found by its name and its synonyms … each viewer sees only entries they may open"). It is not a leak: the entries are rendered only for those viewers and simply never shown.

**Reproduction.**
1. Sign in as an admin and open any page at 360.
2. Open the Menu and type `vouchers` (or `time verification`).
3. `#nav-search-private` is on the page with `{"id":"admin_vouchers", …, "a":"admin", …}`, and `<body data-viewer-audience>` is `everyone signed_in …` without `admin`. "Pages & settings" shows nothing.

**Fix.** The server already filtered these entries for the viewer, so the browser must not filter them again:
- In `prepareIndex()`, mark the entries that come from `privateEntries` (e.g. `own: true`).
- In `search()`, use `const visible = entry.own === true || audiences.has(entry.a);`.
- In `results()`, read `$crawler->filter('body')->attr('data-viewer-audience')` next to `#nav-search-private` and pass that list as the admin cases' audiences. The test then fails today and passes after the fix.

### F2 · major · `assets/controllers/nav_search_controller.js:27-49` (`load()`), `:116-122` (`handleFocus`), `:135-147` (`update()`)
**Problem.** When `nav-search.json` cannot be loaded and the field holds text, the tab either freezes or floods the network.
- `update()` calls `handleFocus()` whenever the list has no data. `handleFocus()` calls `load(url).then(() => this.update())`.
- **A non-2xx answer** (500 when `cache.app` fails, 502/503 during a deploy, 429, a 403 ban page): `load()` keeps the map entry with `data: null` and an already-resolved promise.
  - Every `update()` chains one more microtask onto it, and that microtask calls `update()` again, forever.
  - The tab never returns to the event loop: it hangs.
- **A network error or a non-JSON body** (offline PWA, a captive portal's HTML page):
  - The `catch` deletes the entry, so each round starts a new `fetch`.
  - This is an unbounded request loop that burns CPU and battery until the field is cleared or the network comes back. Offline, the fetches fail immediately.

The trigger is a failure, so this is not a likely path. But the effect is a frozen page, on the search field of every page.

**Reproduction.**
1. Block `/en/nav-search.json` with a 503 (DevTools request blocking with a status), or go offline.
2. Focus the page search and type "pass" before the answer arrives (or type after it).
3. With the 503 the page stops responding. Offline, the Network panel fills with retries.

**Fix.**
- In `handleFocus`: `load(url).then((data) => { if (data) { this.update(); } })`.
- In `load()`: on a non-ok answer also `lists.delete(url)`, so the next focus retries, as the comment there promises.
- In `update()`: call `handleFocus()` only when there is no entry at all (`!entry`), never when a finished load left no data.
- Add a node case "a 503 list and a typed query → `update()` runs a bounded number of times".

### F3 · major · `templates/components/GlobalSearch.html.twig:27-36`, `assets/controllers/menu_layer_controller.js:158-204` (`closeUi` / `finishClose`), `assets/controllers/global_search_controller.js:73-79` (compare `:146-155`)
**Problem.** The camera keeps running after the Menu panel closes.
- Members get the scan button in the panel's GlobalSearch.
- Closing the panel (×, Esc, Back, the Help swap, or a visit's `leave` until the new page arrives) only clears the field (`handleLayerClosed`). Nothing dispatches `barcode-scan:close`.
- `barcode_scanner_controller` stops only on that event, on its own toggle and on `disconnect`. The shell is hidden but never removed, so the scanner does not disconnect until the page is left.
- The bar instance does stop it, in `closeSearchBar()`.

A live camera with no UI and no way to stop it except leaving the page is a privacy and battery problem.

**Reproduction.**
1. As a member at 360, open the Menu panel and tap the scan icon.
2. Close the panel with × without scanning.
3. The camera indicator stays on with nothing on screen, until the page is left or a search field gets focus.

**Fix.** In `menu_layer_controller.closeUi()`, dispatch `window.dispatchEvent(new CustomEvent('barcode-scan:close'))` for every reason. Alternatively, do it in `global_search_controller.handleLayerClosed` for `id === 'menu'`, including `leave`.

### F4 · minor · `src/Navigation/NavigationRegistry.php:1256-1258` (`change_password`, `set_password`), `src/Controller/ChangeAccountPasswordController.php:51`, `translations/messages.*.yml` `nav.syn.set_password`
**Problem.** This is the known wart. A signed-in viewer searching "password" gets two rows: "Password" (exact label, ranked first) and "Add a password" (its synonyms include "Password"). The public list cannot know whether the viewer has a password, so both are shown to everybody.

For one group it is worse than a duplicate:
- A player **with** a password who picks "Add a password" is fine: `AccountSetPasswordController:46-47` redirects to the change form.
- A **social-only** player (Google / Microsoft / Apple / Facebook, no password) gets "Password" first.
  - `ChangeAccountPasswordController` has no guard, so it shows a form asking for the current password.
  - `ChangeAccountPasswordHandler:53-54` refuses every submit ("That is not your current password.").
  - The only way out is the "Do not know your current password?" link.
  - Edit profile never shows them this button: `edit-profile.html.twig:191-210` picks one by `account_has_password`. The search is the first place that links them there.

**Severity: minor.** Nothing is lost and an escape link exists, but for social-only accounts the first result is a form that can never succeed. (With SRCH-01's "Sign in to …" switched on, guests get both rows too: `NavigationSearchScriptTest:202`.)

**Reproduction.** Sign in with a social-only account (`user_account.password` NULL) and search "password" in the Menu: both rows appear. Tap "Password", type anything and submit: "not your current password", every time.

**Fix (smallest).**
1. Mirror the existing guard at the top of `ChangeAccountPasswordController::__invoke()`: `if ($user->password === null) { return $this->redirectToRoute('account_set_password'); }`. Both rows then lead every viewer to the right page.
2. To show one row, drop the `set_password` search-only entry and add its words to `nav.syn.change_password` in 6 locales ("Add a password", "Create a password", "Set a password" and their translations).
   - "Set a password" stays findable. README §4.12 lists it as something search must find, not as a separate row.
   - Update `NavigationSearchScriptTest:202` and regenerate `docs/navigation.md`.

If the entry must stay, the alternative is a page audience for "has a password", read from the already-refreshed `UserAccount` (0 statements). That is more code for the same result: a new `NavVisibility` case, the registry test and the mirror.

### F5 · minor · `assets/controllers/dynamic_modal_controller.js:216-219` (early return in `handleBeforeFetch`), `:698-715` (`release` / `unrelease`)
**Problem.** After a send, the Help sheet is released: no scrim, `.modal { pointer-events: none }`, no `aria-modal`, and the page scrolls.
- The page behind it is usable again (that is HELP-05's point), so a `modal-frame` link on it can be clicked.
- `isOpen()` is still true, so `handleBeforeFetch` treats that link as navigation inside the open modal. The new content loads into the same released own-scrim instance.
- A result detail or edit-time modal then has no scrim and no `aria-modal`, and the page around it stays clickable.
- An outside tap does nothing, because `handleModalClick` skips while `released`.

Plan §7 says every other `modal-frame` partial keeps Bootstrap's backdrop and outside-tap close.

**Reproduction.**
1. Desktop, signed in, a puzzle page: open Help from the Menu and send a message. The "Thanks" sheet stays, released.
2. Click a time on the page (a `modal-frame` link to the result detail).
3. The result detail renders without a scrim. `#dynamic-modal-container` keeps `.is-released`, and `aria-modal` is absent.

**Fix.** In `handleBeforeFetch`, when `this.released` and `findTrigger()` is outside `this.element`, treat the fetch as a new open:
- either `unrelease()`, restore the scrim, `aria-modal="true"` and `body.modal-open`, and take the new trigger;
- or close the released sheet first and let the normal open path run.

Add a WebDriver row "send → click a page modal link → scrim back".

### F6 · minor · `templates/base.html.twig:583`, `templates/_footer.html.twig:52`, `tests/Navigation/HelpOpenerLinksTest.php:58-60`
**Problem.** The old top-bar and footer Feedback links still open the Help sheet in `modal-frame`, through `feedback?url=…`: GET `feedback` with the frame header answers the Help frame.
- They carry no `data-modal-scrim="own"`, so the sheet runs on Bootstrap's backdrop instance.
- In the sent state, `release()` returns at `!this.ownScrim`. The page stays dimmed and blocked until the sheet is closed: the HELP-05 symptom on these two openers.
- `HelpOpenerLinksTest` misses them because it only counts links whose path is a `help` path.
- The top-bar link goes with NAV-06, but the footer link stays until FOOT-01, several phases later.

**Reproduction.** Signed in, on any page: click the footer "Feedback" link and send a message. `.modal-backdrop` is still there after the send.

**Fix.**
- Render both links with `help_path()` + `help_opener_attrs()` now, or at least add `data-modal-scrim="own"`.
- Let `HelpOpenerLinksTest` also count links to the `feedback` paths that target `modal-frame`.

### F7 · minor · `assets/controllers/dynamic_modal_controller.js:490-495` (`handleHidden`), with `menu_layer_controller.js:158-204` (`closeUi('swap')`)
**Problem.** Menu → Send feedback is a layer swap: the panel closes at once and `#site-menu` moves back into the hidden `.menu-slot`. The modal's `trigger` is still that row: connected, but not rendered. When the Help sheet closes, `trigger.focus()` does nothing and focus lands on `<body>`.

HELP-01b's Verify says "Esc closes, focus returns". state.md notes this for NAV-03 ("return focus to the ☰"), but the code that has to change is the modal's close, which NAV-03 does not own.

**Reproduction.** At 360: open the Menu, tap Send feedback, press Esc. `document.activeElement === document.body`.

**Fix.**
- In `handleHidden`, when the trigger is connected but has no box (`getClientRects().length === 0`), fall back to `document.querySelector('[data-bar-action="menu"]')`. That is the same fallback `menu_layer_controller.focusOpener()` uses.
- Until the ☰ exists (NAV-03) that finds nothing, so the swap should also hand the panel's own opener to the modal as its trigger.

### F8 · minor · `assets/feedback_draft.js:10` (`FEEDBACK_DRAFT_KEY`), `assets/controllers/help_sheet_controller.js:28-36`
**Problem.** The feedback draft is kept per tab, not per player. Log out is a `data-turbo="false"` link in the same tab, and sessionStorage survives it. On a shared device, the next player who signs in and opens Help gets the previous player's unsent text in the field.

**Reproduction.**
1. Player A types a draft and closes the sheet.
2. Player A logs out, and player B signs in in the same tab.
3. Player B opens Send feedback: A's text is in the field.

**Fix.** Store `{player, text}` with the player id from the frame root (e.g. `data-help-sheet-player`), and restore only for the same player. `msp:menu-counts` already does this (`menu_counts.js` `cachedCounts()`).

### F9 · minor · `assets/stopwatch_elapsed.js:204-206` (`clockOffsetMs`), `templates/navigation/_stopwatch_elapsed.html.twig:13`, `templates/base.html.twig:282`
**Problem.** `f894428d` made the pill's `rendered` value fractional (`readAt->format('U.v')`). `msp-rendered-at` stays whole seconds (`'now'|date('U')`, read with `parseInt` in `document_receipt.js`). The receipt window "read up to 1 s after the meta" therefore shrinks to `1 − frac(T_meta)` seconds.

The profile row is first read after the head meta on pages whose controller does not load the profile, where the first read is in `<body>`. If such a page's meta falls in the last milliseconds of a second:
- the pill loses its receipt;
- it falls back to measuring the clock offset at connect;
- the transfer time and the lazy chunk's download count as clock difference, which is what ADD-03 review F5 had fixed.

This is rare (about gap / 1000 of those page loads), but systematic when it happens.

**Reproduction.** `clockOffsetMs(1001.05, {renderedAt: 1000, at: …}, …)` gives `sincePageRender = 1000 − 1001.05 = −1.05 < −1`, so the receipt is ignored. Before `f894428d` it was `1000 − 1001 = −1`, which was accepted. `StopwatchElapsedFormatTest::testTheOffsetIsMeasuredWhenTheHtmlArrived` uses integer `rendered` values only.

**Fix.**
- Compare the window with `receipt.renderedAt - Math.floor(renderedEpoch)`, and keep the fractional value for the offset itself.
- Add the harness case `rendered = 1_760_000_001.05`, `renderedAt = 1_760_000_000` → receipt used.

### F10 · minor · `assets/controllers/nav_search_controller.js:15-48` (`lists`, `load()`), `src/Controller/NavigationSearchIndexController.php:46-52`
**Problem.** During a blue-green rollout both deploys answer for a few seconds.
- A page rendered by the new deploy asks for `nav-search.json?v=<new>`. If the old one answers, it sends its own list with `max-age=60`: the controller's documented "another deploy's version, cached briefly".
- The browser keeps it far longer than 60 s. `lists` is a module-level `Map` keyed by URL, kept "for the whole tab (both search fields, every Turbo visit)".
- Until the next full page load, that tab searches the old list under the new URL. Entries the deploy added are missing, and `nav_go` ids or URLs the deploy changed lead to a 404.

**Reproduction.**
1. With both deploys up, load a page from the new one.
2. Focus the search while the old one answers the JSON: `v` in the body ≠ `v` in the URL.
3. Keep browsing with Turbo: every later search in that tab uses the old list.

**Fix.** In `load()`, when `data.v` differs from the URL's `v`, use the list for this search but do not keep it. Delete it from `lists` once used, or keep it with a 60 s expiry, so a later focus asks again.

### F11 · minor · `assets/controllers/nav_search_controller.js:273-290` (`handleClick`), `assets/navigation/menu_links.js:59-63`, `assets/controllers/menu_layer_controller.js:94-128`, `assets/controllers/global_search_controller.js:169`
**Problem.** On a phone, the Language result of the page's (bar) search does not lead to the language switch.
- The result links to `menu#nav-language`. `menu_links` matches it by path and opens the panel, ignoring the hash. The panel shows its top with `<details id="nav-language">` closed.
- The handling that opens the switch runs only for the Menu's own search (`surfaceValue === 'menu'`).
- The bar dropdown also stays open under the panel. The click was inside `.global-search`, so `handleOutsideClick` does not close it, and it is still open after the panel closes.

**Reproduction.** At 360: open the search from the header, type "language" and tap the result.

**Fix.**
- Pass the opener's hash to `open()`. When it names an element inside `#site-menu`, open that `<details>` and scroll to it, as the menu-surface handler does.
- Have `menu_layer.open()` close the bar search (remove `global-search-shown`).

### F12 · minor · `assets/controllers/nav_search_controller.js:158`, `:265-269`
**Problem.** "Show N more" rebuilds the whole list with `replaceChildren()`. The focused button is removed, so keyboard and screen-reader users land on `<body>`.

**Reproduction.** Type a query with more than 3 matches (more than 8 in the panel), Tab to "Show N more" and press Enter. `document.activeElement` is `body`.

**Fix.** After the `update()` that expands the list, focus the first newly shown row (index `limit`).

### F13 · minor · `assets/controllers/nav_search_controller.js:202` (`row()`), `assets/layer_history.js:777-790` (the same-page rule)
**Problem.** A search result that goes through `nav_go` to the page the panel was opened on leaves a dead history entry.
- `layer_history` closes the layer first (Back, then a replace visit) only when the link's own URL is the opened page. A `/en/go/<id>` URL never is.
- So the visit is a replace visit of the layer's entry, and the server redirects back to the same page. History becomes [A, A].
- The next Back "does nothing": Turbo restores the same URL, and `app.js` turns that into a reload.

**Reproduction.**
1. On your own Results page, open the Menu and search "my results".
2. Tap the result, then press Back once. The URL stays the same and the page reloads.

**Fix.** In `row()`, when `u` is a `nav_go` URL and the page's own Menu has that row (`#site-menu #menu-row-<id> a[href]`), use that row's href. It is already resolved for the viewer, so the existing same-page check applies.

## Questions

**Q1 (HELP-05).** Pull-to-refresh stays disarmed while the released "Thanks" sheet is on screen. `pwa_lifecycle_controller`'s `OVERLAY_SELECTOR` matches `.modal.show`, although the page behind is usable again. Is that intended? HELP-05 only says "usable without reload".

**Q2 (Czech tone of validators).** The Help form talks informally in Czech ("Co nám chceš sdělit?", "píšeš"), but its blank-field error is formal ("Napište prosím zprávu.", `validators.cs.yml` `feedback.message_required`).
- The key follows its file: all of `validators.cs.yml` is formal, which is what "Czech as surrounding keys" asks.
- The tone still flips inside one form. Which rule wins for validator messages shown in navigation forms?

**Q3 (stopwatch start, main-side).** Since `a679d100` the stopwatch page counts from the tap, but `StartStopwatchHandler` still starts the lap when the request arrives. Start has no `tappedMsAgo`, unlike resume.
- While it runs, the pill, another device or a reload counts from the server's later start.
- Only the page that pressed Start shows up to one request latency more.
- The saved time is unaffected: Stop sends `shownTotalMs`.

Worth a `tappedMsAgo` on start, mirroring resume? It is main's code, so a TODO rather than a fix here.

**Q4 (bfcache after leaving from the panel).** A `data-turbo="false"` or external row (Log out, an About link) followed from the open panel goes through `location.replace()` (layer rule (d)). If the browser restores the old document from its back/forward cache on Back, the panel would show again: open and released, with `<html>` still locked by the `:has(.menu-layer[open])` rule. There is no `pageshow` (`persisted`) cleanup. Not verified whether that document is eligible for the bfcache.

**Q5 (Esc on a result row).** Esc while focus is on a result row, not in the field, closes the panel even with a query typed. The "clear first" rule exists only on the input. Intended?

## Checked and fine

**`nav-search.json` (privacy, caching).**
- **Built from the registry and the translator only.** No viewer, session or request data is used. URLs are relative paths (no route sets `schemes` or `host`, so no Host header reaches the cached list).
- **The same answer for everybody.** It is `public, max-age=86400` with `NO_AUTO_CACHE_CONTROL`, and `AnonymousCacheHeadersSubscriber` returns early on that marker.
- **No session or cookie.** The stateless firewall pattern `^(…|/(cs|de|en|es|fr|ja)/nav-search\.json$|…)` matches only those six exact paths. It is `security: false`, so there is no token, and `SlidingLoginCookiesSubscriber` returns early. The signed-in request is asserted public with no `Set-Cookie`.
- **No role entries in the public list.** `isInPageSearch()` excludes every role entry; this is tested per locale (`a` never moderator/time_verifier/admin, no `/admin/`).
- **Private entries.** `#nav-search-private` is rendered only when `privateJson()` finds role entries the viewer `sees()`: `PUZZLE_MODERATION_ACCESS` / `REVIEW_SUSPICIOUS_TIMES` / `ADMIN_ACCESS`, null for guests. Its JSON uses `JSON_HEX_TAG | JSON_HEX_AMP`, so `|raw` inside the `<script>` cannot be closed early.
- **Versions.**
  - `?v=` = hash(format | `container.build_id` | locale | year). The container is compiled at image build, so all containers of one deploy share it.
  - Another deploy's version gets the current list with `max-age=60` (but see F10).
  - The `cache.app` key carries the version.
  - Debug uses file times, with no cache.
- **`inLocale()`.** It restores the router context's `_locale` in `finally`, and is called only inside a request, where `LocaleListener` always sets it.

**`nav_go`.**
- It is single-action and `IS_AUTHENTICATED_REMEMBERED`; a guest goes to login with `?return=`.
- Its `entryId` requirement is `[a-z0-9_]+`.
- It answers 404 for unknown ids, ids not in the page search, non-page kinds and entries the viewer does not `see()`.
- The redirect target is always `MenuBuilder::urlFor()` (the router plus the viewer's own id or country), never input, so it is not an open redirect.
- The parent fallback is re-checked with `sees()`.
- The response is `private, no-store`, and the statement list equals the empty signed-in endpoint's.

**Worker mode.**
- `MenuBuilder`, `NavigationViewerFactory` and `AdminQueueCountsTwigExtension` hold memos and implement `ResetInterface`; the last one is added to `NavigationServicesResetTest`.
- `NavigationSearchIndex`, `HelpResponseBuilder` and `NavigationTwigExtension` are `readonly`. The Twig memos (`_nav_location`, `_nav_search_url`) live on the request.

**Budgets.**
- The chrome adds no statement: the Menu reads the profile row (`organizes_events` = six index probes; EXPLAIN recorded) and the memoised `admin_queue_counts()`.
- No budget pin changed (`HubQueryBudgetTest`, `ChromeQueryBudgetTest`, `PlayerProfileQueryBudgetTest` untouched), and `GetAdminQueueCountsTest` is unchanged.
- `menu_counts` costs +1 for a player and +2 for an organiser over the empty endpoint, and is `private, no-store`.
- A pages-only query costs the two statements every query costs, and an empty one costs none.
- `MenuBuilder` has no N+1 risk: it runs no query.

**MENU-01 markup.**
- There is one `#site-menu` per page; the `menu` page turns the slot off.
- `queue-count-*` ids come from the memoised statement, once each.
- Hidden rows are not rendered: there is no lock markup.
- Headings follow the chrome rule (`p[role=heading][aria-level=2]`).
- Log out is `data-turbo="false"`.
- Guest cards and Sign in `?return=` are the same for every guest at a URL, so guest HTML stays shared.
- `language_switch_urls()` keeps the old switch's rules (decision 36).
- Trial days are guarded by `activeMembership` and `max(1, …)`.

**HELP-01b.**
- The controllers are single-action.
- Frame POST: invalid → 422 into the frame; valid → `CollectUserFeedback` + the sent state.
- Full-page POST: valid → flash + 302 to the validated page or `help`; invalid → 422.
- GET `feedback`: → 302 `help?url=`, or the frame for frame requests.
- The form has an explicit `action`, and CSRF is in place.
- `?url=`:
  - only a `ReturnUrl` path or an absolute URL on the request host survives;
  - it is router-matched as GET, with the router context's method restored in `finally`;
  - it is never echoed into an `href`;
  - the mail URL is rebuilt from the validated path.
- The frame is `private, no-store` + `Vary: Turbo-Frame`. The guest full page has no form or token and stays public.
- The own scrim is swapped through public Bootstrap API only, and the default instance comes back on `hidden`. The Menu drops `inert` before the modal shows.

**MENU-02b.**
- Inert is set on body children except the bottom bar, the toasts and the dynamic modal, and is removed on every exit path.
- `#site-menu` moves back on every close and is never copied.
- `show()` is used, never `showModal()`.
- Scroll is locked by `:root:has(> body > .menu-layer[open])`, and the panel scrolls with `overscroll-behavior: contain`.
- Pull-to-refresh stays off through `dialog[open]`.
- Tab cycles through the panel and `#bottom-nav`.
- `menu_links.js` uses the Conventions' phone query, leaves modified clicks, other buttons, `target` and `download` alone, and reads `isNativeApp()` from `platform.js`.
- Its paths cover all 6 locales.
- `turbo:before-cache` never fires with `turbo-cache-control: no-cache`, so `handleBeforeCache` is dead but harmless.

**MENU-03 client.**
- The two instances have different Live ids (no key / `key="menu"`), and the `_components` route keeps `/{_locale}`.
- Listeners are per instance and removed on disconnect.
- In the field, Esc clears first; `layer_history` respects `defaultPrevented`.
- The audience is read from `document.body` on each search, so it is fresh after Turbo visits.
- Matching:
  - every word must hit as a prefix or substring;
  - the tiers come in the plan's order, with ties in Menu order;
  - the kana and apostrophe layer runs on keys and query.
- The zero-result beacon needs ≥ 3 characters, a 1.5 s pause and empty server groups, and is capped at 5 per page view and 40 characters. The server logs only the folded query on the `navigation` channel at info.

**Merge `b368b6d3` and `f894428d`.**
- Both sides survived in:
  - `stopwatch_controller.js` `resume()`: main's `tappedAt` / `shownTotalMs` / queue bodies, plus the navigation side's `rememberStopwatchState`;
  - `app.scss`: the `display: none` closed state with main's z-index 1014/1015;
  - `_multiscan.scss`: comment only.
- `base.html.twig` correctly dropped main's critical-CSS z-index: the rule now lives in `navigation/_critical_css.html.twig` as `display: none`.
- Main's `body.global-search-shown` hooks (homepage banner, collection selection bar) still work in bar mode.
- `RunningStopwatch` reads both lap formats exactly as `LapsArrayDoctrineType` writes them, and cuts the total to whole seconds once.
  - PHP `intdiv(closedMs + nowMs − startMs, 1000)` and JS `Math.floor` get the same 3-decimal strings, so the first tick never jumps back.
  - `nextChangeAt()` takes the closed laps' fraction.
- Mixed whole-second and millisecond lap starts sort chronologically as text (a whole-second value is a prefix).
