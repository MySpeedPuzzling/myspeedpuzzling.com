# Phase review: Instant Back (GEST-14, GEST-15, GEST-08, PROF-05c snapshot path, NAV-14 native Back rows)

- **Scope:** commit `20c322fa5` on `feature/navigation-redesign` (parent `c8545b6c5`), read at that HEAD in the delivery worktree:
  `assets/instant_back.js`, `post_action.js` + `post_action_controller.js`, `back_measurement.js`, `fonts_settled.js`, the
  changed controllers (`pwa_lifecycle`, `mercure_hub`, `event_follow`, `datepicker`, `global_search`, `menu_layer`,
  `player_results`, `show_more_recent_activity`, `compact_bar`, `bottom_nav`, `live_refresh`), `layer_history.js`,
  `confirm_dialog.js`, `nav_instrumentation.js`, `nav_layout.js`, the two SCSS changes; `Value\PostAction`,
  `PostActionParameter`, `PostActionConfirmController`, `PostActionTwigExtension`, `InstantBackTwigExtension`, the
  `clears_snapshots` controllers, `NavClickController`, registry/place map; `base.html.twig`, every converted template and
  the 19 opt-ins; the 6 translation files; every changed test.
- **Checked against:** README §4.13 G9 + §12 "Instant Back as built", §4.14; requirements GEST-14, GEST-15, GEST-08,
  PROF-05c, NAV-14; implementation plan GEST-14/15/08 sections, decisions 64, 65, 66, 92, 99, 102; `delivery/state.md`
  rows and decisions log; CLAUDE.md "Turbo Configuration"; `docs/features/feature_flags.md`; the owner's binding rule of
  2026-10-14 (no SEO effect, never a worse experience) with its two lenses.
- **Method:**
  - Read the full diff and the touched files; traced Turbo 8.0.23's `cacheSnapshot` / `loadCachedSnapshot` /
    `followRedirect` / `LinkClickObserver` in `node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js` against the
    module's ordering assumptions.
  - PHPUnit (locked runner): `TurboCacheControlOptInTest`, `InstantBackScriptTest`, `PostActionScriptTest`,
    `PostActionConfirmControllerTest`, `BackMeasurementScriptTest`, `TurboContractScriptTest` - **OK, 133 tests, 1315
    assertions**.
  - Lane W checks (browser lock): `GEST-14` 14/0, `GEST-08` 8/0, `NAV-14` 10/0.
  - Own throwaway WebDriver probes on lane W, `ibreview/s1…s6` in the session scratchpad (Pixel 360, the clone's member
    account; every write undone, re-read after): S1 Favorite toggle + Back, S2 sign-out in another tab / this tab + Back,
    S3 post_action links after a Live re-render, S4b the events ⋯ menu left open + Back (host and opener injected - no
    clone account organises an event; the controller and page are the real ones), S5 guest "Sign in to follow" note +
    Back, S6 snapshot clone cost at 4× CPU throttling.
  - Guest HTML/headers: curl of 10 opted-in pages on nav-web (status, Cache-Control, Vary, X-Robots-Tag, Set-Cookie,
    markup grep) + the Twig whitespace of `base.html.twig` worked through by hand against `c8545b6c5`.
  - Every removed test assertion compared with its replacement (`git show 20c322fa5 -- tests/`).
- **Result:** **2 blocker · 1 major · 10 minor · 4 questions.**

## Findings

### F1 · blocker · `assets/instant_back.js:1189-1196` (onClick) with `:1096-1102` (onBeforeCache) and `:199-201`; `templates/components/PlayerHeader.html.twig:131,135`, `player/_header_actions_menu.html.twig:46,50`
**Rule.** Owner's rule / decision 65: Back never shows the state before a write. A `[data-clears-snapshots]` GET link
notes the write on `turbo:click` - **before** Turbo caches the page being left. The page left is then cached with its
pre-write DOM at `cachedAt` > `msp:last-write`, so `restoreDecision()` accepts it.

**Evidence (S1, confirmed).** Member, Pixel 360: catalogue → Turbo visit to the clone's busiest player's profile
(Favorite off) → tap Favorite (GET, redirects back to the same URL; Turbo's `followRedirect` replaces the new entry, the
old entry with the same URL stays below it) → `history.back()`:
`after_toggle: on`, `after_back: off`, `same_document_after_back: true` → the snapshot from before the tap, while the
server says "on". Before this commit that Back was a full load (fresh "on"). Turbo's restore reads the snapshot in
`loadCachedSnapshot()` *before* it caches the current page, so the fresh copy cached on the way back does not help.
The same holds for the header on Overview and Results, and for the ⋯ menu's Favorite in header and compact bar.

**Why no check saw it.** `checks/GEST-15.py:515-528` writes on the profile, then Backs until `/en/puzzle` and asserts
only the catalogue (a page left *before* the write). `tests/instant-back-harness.mjs:523` asserts the clear on
`turbo:click`, never the copy cached right after it.

**Fix.** In `instant_back.js`, a clears-snapshots click also marks a pending write; the next `turbo:render` (or
`turbo:load`) of that visit calls `noteWrite()` again - clearing the copy cached during the visit and moving
`msp:last-write` past it. (Optional, belt and braces: `data-turbo-action="replace"` on the toggles.) **Test:** extend
the GEST-15 write rows with "Back once after the write = never the pre-write state" (S1's steps), and the harness with
"clears-snapshots click → before-cache → render → Back = reload".

### F2 · blocker · `assets/controllers/event_manage_menu_controller.js:167-196` (layer on `<body>`, no `msp:before-cache`); `assets/instant_back.js:433-539` (sanitizeSnapshot); `templates/events.html.twig:90`, `competition_series_detail.html.twig:91`
**Rule.** GEST-15: a restored page has no open layer and no broken widget; owner's UX lens.

**Problem.** The organisers' / admins' ⋯ menu builds `.ev-menu-layer` (scrim + dialog + the menu frame with its POST
forms and in-place confirmations) on `<body>`. Nothing closes or removes it for the cache: it is not a
`layer_history` layer, the controller has no `msp:before-cache` listener, and `sanitizeSnapshot()` does not know it.
The menu's own links (Edit, View, Results … all `data-turbo-frame="_top"`) are exactly how organisers leave the page
with the menu open.

**Evidence (S4b, confirmed).** Events page, menu opened, page left by a `_top` link inside the open dialog, Back:
`after_back: {layers: 1, shown: 1}` - the restored page shows the open sheet (phone) with its scrim; its × and Escape do
nothing (`after_close_click` / `after_escape` still `shown: 1`): the restored controller has `layer = null` and never
registered the document listeners for that orphan. The page is stuck until a reload. Events agenda and series page
(both opted in) are affected.

**Fix.** `event_manage_menu_controller.js` listens to `msp:before-cache` → `close({restoreFocus: false})` and
`this.layer?.remove()`; `sanitizeSnapshot()` also removes `.ev-menu-layer` (net for the restored copy). Then sweep every
controller that can run on an opted-in page and adds DOM outside its element (see also F7) and pin the list in
`InstantBackScriptTest` (a module that appends to `document.body` must name its `msp:before-cache` teardown).
**Test:** GEST-15 layer row "⋯ menu open → leave by a menu link → Back = no `.ev-menu-layer` shown" (needs a clone
organiser account, or the S4b injection).

### F3 · major · `assets/instant_back.js:966-974` (viewer of the restore = the current page's meta); `templates/navigation/_account_menu.html.twig:40`, `navigation/_menu_row.html.twig:41` (logout `data-turbo="false"`)
**Rule.** GEST-15 "same viewer"; owner's UX lens (a Back after a sign-out must not show signed-in pages).

**Problem.** The snapshot check compares the copy's viewer with `pageViewer()` of the page being left - in the same
document that is always the old viewer. The stored viewer (`localStorage msp:viewer`, which the bfcache path does use)
is not consulted, and signing out is no write (a `data-turbo="false"` GET link: no `turbo:click`, no submit).

**Evidence (S2, confirmed).** Tab A: catalogue → own profile → ladder (Turbo). Tab B: `/logout` (`msp:viewer` becomes
`""`). Tab A: Back → `same_document: true`, `page_signed_in_after_back: true` - the signed-in own profile from the
snapshot. Before this commit: a full load as a guest. On a shared computer, signing out in one tab leaves up to 10
minutes of signed-in pages one Back away in every other tab. (Same-tab sign-out is fine: S2b reloads through the
bfcache verdict.)

**Fix.** In `onTurboVisit`, reload when `storedViewer() !== null && storedViewer() !== entry.viewer` (pass it into
`restoreDecision`); also `rememberWriteTime()` on a capture-phase click of the logout links (or any
`[data-clears-snapshots]` link, Turbo or not). Harness rows for both; a GEST-15 row with S2's steps.

### F4 · minor · `templates/components/Players/Suggestions.html.twig:45`
**Problem.** The Players page's "Suggested for you" Favorite is the same GET mutation as the header's toggle but has no
`data-clears-snapshots`, so nothing is cleared: Profile X (cached) → Players → Favorite X → Back ×2 = X's profile
from before the tap. (Not re-run in the browser; same mechanism as S1 minus even the click clear.) Also note for the
record: `mark_seen_controller.js` writes by `sendBeacon`, which the fetch watcher does not see - harmless today (it
fires on the non-opted guide page, whose Back is a document load), but it is a hole in "every write clears".
**Fix.** Add `{{ app.user is not null ? ' data-clears-snapshots' }}` there (F1's fix then covers it); have
`mark_seen_controller.js` call `clearSnapshots()` before the beacon; extend `testTheFavoriteTogglesClear…` to the
Players page.

### F5 · minor · live pages without `data-bfcache-reload`: `templates/compete/page.html.twig:50`, `online_rounds/{control,review,watch}.html.twig`, `official_results/competition_results_overview.html.twig`, `seating/round_seating.html.twig`
**Problem.** All extend `base.html.twig`, so the old unconditional `pageshow.persisted` reload covered them; GEST-14 now
keeps their back/forward-cache copy (≤ 10 min) unless marked. They are Mercure/heartbeat-driven live pages (round
clock, presence, review queue, seating) that only resync on `visibilitychange`. The owner's rule says live pages always
reload; the requirement's list predates the online rounds.
**Fix.** Add `data-bfcache-reload` to their root elements (one attribute each) and the GEST-14 row "Back to the compete
page from a full navigation = a document load"; or decide otherwise in Q3.

### F6 · minor · `assets/controllers/event_follow_controller.js:141`
**Problem.** `flip()` still sets `form.action` to `data-follow-url` / `data-unfollow-url` - the POST-only routes - on
what is now a GET form to `post_action_confirm`. After any follow/unfollow the star's form says
`<form method="get" action="/en/unfollow-event">`, and that is what the snapshot keeps. If the form submits without the
controller (a restored page tapped before the lazy controller connects, a controller load failure) Turbo GETs a
POST-only route (405).
**Fix.** Flip between the two confirmation-view URLs instead (render them as `data-follow-view` / `data-unfollow-view`)
or stop touching `form.action`; a node/functional assertion on the action after a flip.

### F7 · minor · `assets/controllers/event_follow_controller.js:175-190` (guest note)
**Problem.** A guest's "Sign in to follow…" note is appended into the row and kept in the snapshot; the restored
controller does not know it, so the next tap adds a second one instead of hiding it. **Evidence (S5):**
`notes_after_back: 1`, `notes_after_second_tap: 2` (before: a second tap hid it).
**Fix.** `removeNote()` on `msp:before-cache` (or `.ev-follow-note` in `sanitizeSnapshot()`); part of F2's sweep.

### F8 · minor · `src/Twig/PostActionTwigExtension.php:63-70` (`back` = `getRequestUri()`)
**Problem.** Rendered by a Live re-render, every post_action link's `back` is the component endpoint. **Evidence (S3):**
catalogue (PuzzleSearch) after `component.render()`: all 4 links `back=/en/_components/PuzzleSearch`; the
confirmation view's Cancel then leads there (`404` on nav-web). With a GET re-render `back` would also carry the whole
`?props=…` query (≤ ~1.5 KB per link) - extra bytes per card on every re-render. Only the no-JS / new-tab path breaks;
nothing for guests.
**Fix.** Skip `back` when the current request is a Live component request (`_live_component` attribute or path
`/_components/`), falling back to the Referer's path through `ReturnUrl::tryFrom()`; a test rendering a link inside a
Live re-render.

### F9 · minor · `src/Twig/PostActionTwigExtension.php:93-104`, `assets/controllers/post_action_controller.js`
**Rule.** Owner's accessibility lens (WCAG 4.1.2 name/role/value).
**Problem.** Fourteen actions that were `<button>`s (Remove from wish list, Publish, Add to comparison, Block, Return
…) are now plain `<a>`s: screen readers announce "link", and Space no longer activates them.
**Fix.** `role="button"` in `post_action_attributes()` and a Space `keydown` → `click()` in the controller (Enter
already works); assert `role="button"` in `PostActionConfirmControllerTest::testEveryActionLinkIsNofollow`.

### F10 · minor · `assets/instant_back.js:180-182` (`restoreDecision` → `leave` in the app) with the opt-in metas
**Problem.** NAV-14 says the restore handling steps aside in the app - but the opt-in meta is rendered for app requests
too, so inside the app Turbo now caches those pages and restores them from the snapshot **without** the 10-minute,
viewer or iOS rules (before: `no-cache` everywhere → every app restore fetched). No app exists yet, so nobody sees it
today; NAV-14's row checks only "not decided by instant_back.js, no full load".
**Fix.** Render `no-cache` when `is_native_app()` (app HTML is already per-UA and never shared-cacheable), or keep the
age/viewer/write checks in the `leave` branch and only skip the reload; see Q4.

### F11 · minor · snapshot clone cost (Turbo's `PageSnapshot.clone()` on every visit away from an opted-in page)
**Evidence (S6, `body.cloneNode(true)`, 4× CPU throttling):** Hub 7 ms, catalogue 8 ms, ladder 10 ms, events 5 ms,
directory 5 ms - but Results at `?page=6` (300 rows, ~9,000 nodes) **81 ms median, 156 ms max**, one long task in the
first moments of the next page (plus the cache work's teardown/sanitize on the same body). Before this commit no page
was cloned. Not measured by any check (GEST-15 (e) times only the restore).
**Fix.** Measure INP on the page after leaving a 300-row Results on the device check; if it shows, switch the live meta
to `no-cache` in `turbo:before-visit` when the list holds more than ~150 rows (PROF-05c's full-load path already
restores rows and scroll). See Q1.

### F12 · minor · `assets/styles/components/_lazy-images.scss:10`, `assets/styles/_user.scss:241` (`vertical-align: top`)
**Problem.** A site-wide layout change for guests and members on every page with thumbnails (`.lazy-img-wrapper`,
`.diff-corner-host` no longer sit on the text baseline) - outside the opted-in scope and not covered by "guest HTML
unchanged". It removes a real load-time shift, but no screenshot evidence shows that no row, card or inline thumbnail
moved visibly.
**Fix.** Before/after shots (QA-01 matrix: catalogue cards, leaderboards, recent activity, results rows, marketplace
cards at 360/1280) attached to the gate.

### F13 · minor · docs out of step with the code
- CLAUDE.md "Opted-in pages hold no POST form, `confirm(` or `data-turbo-confirm` in anything they include, embed or
  render": true of the templates only - lazily loaded frames (the ⋯ menu: approve/reject/publish/delete forms and
  `details.ev-confirm`; the modal frame's content when a page is left with it open) are in the live page and, per F2, in
  the snapshot. Say "in their templates" and name the runtime frames.
- CLAUDE.md "posts in one tap (fetch, the action's stateless CSRF …)": Publish uses the session tokens
  `publish_*_<id>`.
- CLAUDE.md "Snapshots are cleared after any write … log out is `data-turbo="false"`" reads as if sign-out were covered;
  across tabs it is not (F3). The bfcache sentence omits "another tab or document wrote since it was hidden" and the
  app / iOS cases.
- `NavigationRegistry.php:1439` / `docs/navigation.md:329` "Block and Publish ask there, in the modal frame" - Publish is
  one tap (`PostAction::confirmsInFrame()` = Block only). Decision 99 says "Block, Publish" ask in the frame; the
  deviation is not in the `state.md` decisions log (Q2).
- README §4.13 G9 still names `turbo:before-cache` for the cache work (§12 has `msp:before-cache`; noted by DOC-01).
- Plan decision 64 names "pages using `official_results_live.js`" for `data-bfcache-reload` - no such script exists;
  the live organiser pages are F5's.

## Questions

### Q1 · `INSTANT_BACK_IOS = true` before the device checks
Decision 66 is satisfied (GEST-13 verified), and the GEST-14 iOS row passes under an iPhone UA in Chromium. Nothing has
run on real Safari (QA-06 DC1/DC7 are open), and Safari keeps far more pages in its back/forward cache than Chrome.
**Recommendation:** keep `true` only if DC1/DC7 run before the launch; otherwise ship `false` and flip after them. Fold
F11's INP measurement into the same device session.

### Q2 · Publish stays one tap
Decision 99 put Publish in the frame view with Block; the build keeps the draft banner's Publish one tap, as the button
was. **Recommendation:** keep one tap (it is "exactly as before"), log the deviation in `state.md` and correct the
registry note (F13).

### Q3 · Which pages count as "live" for GEST-14
**Recommendation:** add the online round pages (compete, control room, review, watch), the results overview and the
seating page to the `data-bfcache-reload` list (F5) and to the CLAUDE.md sentence, so the rule reads "every page whose
state is pushed by Mercure or a heartbeat".

### Q4 · Turbo's cache inside the (future) app
**Recommendation:** render `turbo-cache-control: no-cache` for native user agents until the app project decides its own
restore policy (F10) - zero risk on the web, and app restores stay fresh fetches as before.

## Checked and fine

- **SEO, guest HTML and headers:** on nav-web, 10 opted-in pages as a guest (`/en/puzzle`, hub, recent activity,
  events, puzzlers, puzzlers/all, marketplace, ladder, msp-rating, brands): 200, `Cache-Control: max-age=0, public,
  s-maxage=60`, no Vary added, X-Robots-Tag as before (dev env), only the dev profiler cookies; `turbo-cache-control`
  `no-preview`; zero `msp-viewer`, `msp-clears-snapshots`, `data-clears-snapshots`, `data-post-action` or `/action/`
  occurrences. The `base.html.twig` whitespace control was worked through: for a guest the head renders
  `format-detection`, `turbo-cache-control`, `turbo-prefetch` on consecutive lines, byte-identical to `c8545b6c5` but the
  value (curl of `/en/faq` agrees). Flash containers' `data-turbo-temporary` renders only with a flash (session pages).
  No canonical/hreflang/robots/JSON-LD/og template was touched. `sitemap-static.xml` holds no `/action/` URL (static
  allow-list).
- **`post_action_confirm`:** `IS_AUTHENTICATED_REMEMBERED` (guest → 302 to sign-in, `curl`), `noindex, nofollow` meta +
  header, `Vary: Turbo-Frame` added not replaced, not public; only `PostAction` cases (unknown → 404), route parameters
  and fields shape-checked (`PostActionParameter`), unknown query keys dropped, `return`/`back` through
  `ReturnUrl::tryFrom()` (external → 404 / Cancel falls back to the homepage), target URL generated from the enum; the
  form re-posts the old route with its token (stateless ids by Origin, session ids per session) - no route, CSRF or
  open-redirect widening. Every country case name matches the `[a-z]{2,3}` shape. Registry + place map entries present.
- **Links:** every `post_action_attributes()` carries `rel="nofollow"` (test); all call sites sit behind sign-in, owner
  or edit-permission guards; the Favorite toggles get `data-clears-snapshots` only for signed-in viewers (test).
- **Translations:** the 4 `post_action.*` keys in all 6 locales, tone right (cs "Potvrď / Zkus", de "Sie", es
  "Confírmalo / Inténtalo", fr "Confirmez / Veuillez", ja polite); every label/title key a `PostAction` names exists in
  all 6 locales (checked by YAML, since the view test compares with `trans()` and would pass on a missing key);
  `blocklist.cancel` = `forms.cancel` in every locale.
- **Converted actions with JavaScript:** the same request as the old Turbo form (POST, same fields, same token, same
  `Accept`), a stream applied with `renderStreamMessage`, a redirect rendered from the fetched answer (replace when it
  is this page - the old form's rule; `shouldCacheSnapshot: false`), one request per key at a time, failures → toast +
  `reject` haptic instead of an error page. Publish keeps one tap; Block now loads its dialog from the server (one round
  trip before it shows - accepted, it was a static modal) and posts through Turbo instead of a full load, landing on
  the same `my_profile` redirect (Referer contains the blocked id). Without JavaScript / in a new tab: the view's
  one-button form, `data-turbo="false"` (`PostActionConfirmControllerTest`: every action, 6 locales, wishlist, compare,
  follow, country, block end to end).
- **Staleness rules that hold:** Turbo POST forms (`turbo:submit-end` success; Turbo's own POST visits never cache the
  page left), forms Turbo does not handle (capture `submit` → `posted` + `msp:last-write`), every same-origin non-GET
  `fetch` except `/-/` and plain Live re-renders (the wrapper runs after Turbo captured its own `nativeFetch`;
  `/en/_components/PuzzleSearch` POST re-renders are not writes - S3), event follow and post_action (in place, or no copy
  of the page left), GET-writing pages via the meta after their render (notifications marked read, conversation read,
  stopwatch, checkout success, Hub auto-dismiss), other tabs via `msp:last-write`. Same-tab sign-out is safe (S2b:
  the bfcache verdict sees the stored viewer and reloads).
- **Cache work ordering:** `turbo:before-cache` fires only for cacheable pages; the deferred cache work is queued before
  Turbo's `nextEventLoopTick`, so it lands in the clone; a detached old body is still cleaned (`teardownWidgets`,
  `sanitizeSnapshot`) while layer closing/`msp:before-cache` run only while it is connected; no listener left on
  `turbo:before-cache` but press-feedback hosts, the long-press bubble and the confirm dialog's cancel (harmless).
  `TurboContractScriptTest` pins the order, `snapshotCache.has`, `restorationData`, `currentVisit.{cancel, willRender}`
  and `Turbo.visit({response})`.
- **Restore mechanics:** 10-minute and same-viewer checks, ledger bounded to Turbo's 10, `holdRestorePosition()` /
  `putBackRestorePosition()` (contract-tested), `scroll-behavior: auto` released a frame after `turbo:load`, Hub feed
  pane re-scrolled to the selected tab on connect (`feed_swipe_controller.layout()`), expanded "Show more" kept,
  `live_refresh` backdated by the snapshot's age, Mercure handed over across visits and closed on `pagehide`, the
  leaderboard live region reused by id (no duplicates).
- **Measurement (GEST-08):** one line per Back per kind (pending marker across full loads, bfcache keep from the
  restoration entry, layer pops never counted), server whitelists kinds and bounds `ms_to_load`; P0-03's `history`
  lines switch off only when `back` lines take over.
- **Kill switches:** `INSTANT_BACK`, `INSTANT_BACK_IOS`, `KEEP_BFCACHE` defined once, each a one-line revert as
  documented in `feature_flags.md` (with `INSTANT_BACK = false` pages are still cloned but never restored).
- **Query budget:** no statement added (viewer marker from the security token, `clears_snapshots` from values the
  controllers already had, the view = the page chrome).
- **Tests not weakened:** every removed form assertion has a replacement on the link/GET form (route, fields JSON,
  token, `nofollow`, href to the view) plus an end-to-end submit through the view; the one relaxed selector
  (`.player-header a[data-turbo-frame="modal-frame"]:not([href^="/en/action/"])`) exists because Block now uses the
  frame, and its absence of Message links is still asserted. Panther test edits only follow the new markup.
