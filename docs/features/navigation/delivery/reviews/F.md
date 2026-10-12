# Phase review — F (Foundation: GEST-09, NAV-14 foundation, F-01…F-05, LAUNCH-00)

- **Reviewer:** fresh phase reviewer (did not write the code), 2026-10-12
- **Scope:** `git diff 816784ec..d4d406a7`, four commits:
  - `89de282a` GEST-09 + NAV-14 foundation
  - `a31db772` F-01, F-02, LAUNCH-00
  - `6c50dca4` F-03
  - `d4d406a7` F-04, F-05
- **Against:**
  - `requirements.md` for these IDs: every "Done when" and every "Verify", read literally. NAV-14 covers the foundation only. F-03's overlap checks and F-04's Results and Menu-search rows are verify-after.
  - `README.md`: D1–D22, §3, §4.2, §4.3, §4.12, §4.13, §4.14, §5 and §8.
  - `implementation-plan.md`: §3 rules, the per-ID sections and §8 decisions.
  - `CLAUDE.md`.
- **Method:** the code, the diffs and the tests were read, and the repo was searched with grep. **No tests or docker commands were run**, because a phase gate was running on the shared test DB. Every claim about specificity or behaviour below comes from reading the source.

## Findings

### F1 · major · `assets/styles/_nav-tokens.scss:96-99` × `templates/live_results/live_results.html.twig:139, 159, 240, 312, 330` · status: resolved — `_nav-tokens.scss`: the rule leaves out `$nav-own-size-controls` (Bootstrap -lg, `.auth-code-input`, `.lr-find-input`, `.lr-time-input`), and a second rule of the same specificity keeps inherited sizes for controls without `.form-control`/`.form-select` (`max(1rem, 16px, 1em)`; it absorbs the old tom-select/flatpickr rule). Static guard `tests/Navigation/TouchFontSizeTest.php`: every SCSS rule and every template inline `<style>` rule above 16 px on a text-entry control must name a listed class, and the list holds no stale class (mutation recorded: dropping the two `lr-` classes fails). Browser: `checks/F-04.py` now compares every control's size with and without the rule's CSSOM block on 15 pages, including live results (referee), check-in, the participants sheet, add editions, the sign-in code screen, sign in, events, puzzlers, compare and the Hub. Referee fields are pinned at 22/32 px and the code field at 28 px. 45/45 PASS. The mutation run caught 22→16 and 32→16.
**Problem.** The F-04 touch rule shrinks the referees' live-results entry fields. The rule's own comment (`_nav-tokens.scss:90-93`) claims that nothing shrinks: "Controls sized above 16 px by their own rule are left out … -lg (live results, check-in …)".

On the live-results page, only the add-participant fields carry `form-control-lg` (lines 374-393). The three fields a referee types in all day do not:
- `<input type="search" class="form-control lr-find-input">` (l. 240)
- the time field `<input type="text" class="form-control lr-time-input">` (l. 312)
- the pieces field (l. 330), same classes as the time field

Their sizes come from the page's inline `<style>`: `.lr-find-input { … font-size: 1.375rem }` (l. 139) and `.lr-time-input { … font-size: 2rem }` (l. 159). Both rules have specificity (0,1,0).

Under `(pointer: coarse)`, the new rule `:is(input:not([type=checkbox], …), select, textarea, .ts-control input):not(.form-control-lg, .form-select-lg, .input-group-lg > *, .auth-code-input) { font-size: max(1rem, 16px) }` applies. Its specificity is (0,2,1): the `:is()` counts as (0,1,1) and the `:not()` as (0,1,0). It wins, so on every referee phone:
- the big time and pieces digits drop from 32 px to 16 px;
- the find field drops from 22 px to 16 px.

The page is bar-free and not in F-04's WebDriver list (add form, catalogue, edit profile), so nothing caught it. The F-04 re-runs planned for PROF-05a and MENU-03 will not catch it either.

I ran a cross-check: every SCSS rule above 16 px, and every inline-template rule, against the classes used on `<input|select|textarea>`. Only these two live-results classes are affected. `.auth-code-input` and the `-lg` tom-select are handled correctly.

**Fix.**
1. Add `.lr-find-input, .lr-time-input` to the rule's `:not()` list, and correct the comment. Alternatively, mark the fields `form-control-lg` and keep their own sizes.
2. Add a guard so this cannot happen silently again. Two options:
   - a static test that collects every SCSS rule and every inline template `<style>` rule setting `font-size` above 16 px, maps their classes to text-entry controls in templates, and requires each one in the exclusion list;
   - or, cheaper: add the live-results entry (find and time views) and the check-in page to `checks/F-04.py`, asserting a computed size at least as large as without `pointer: coarse`.

### F2 · major · `src/Navigation/NavigationPlaceMap.php:62-63`, `src/Navigation/NavigationPlaceResolver.php:63`, `src/Navigation/NavigationRegistry.php:625` · status: resolved — `CollectionDetailController` sets `NavigationPlaceResolver::PAGE_OWNER_ATTRIBUTE`. `NavigationTwigExtension::navLocation()` passes it to `resolve(…, $pageOwnerPlayerId)`, and the URL's `playerId` wins. `collection_detail` is now `puzzles/library|community` and is listed in `NavigationPlaceMap::OWNER_FROM_CONTROLLER`. `menuRowId()` skips viewer-target rows on any own/other route that is not the viewer's (activeOn included); pages without an owner, such as `badge_reveals`, keep their row. `NavigationPlaceResolverTest` gained own, other, guest and unknown-owner rows plus menu rows (`collection_detail`, `edit_system_collection`, `legacy_player_collections`, `badge_reveals`), a structural test that every own/other route has `{playerId}` or a listed controller, and an end-to-end test of the real collection page through the Twig function. No other own/other route lacks `{playerId}`: the remaining `collection*` routes act on your own collections.
**Problem.** `collection_detail` is classified wrongly for everyone except the owner.

The route is `/en/collection/{collectionId}`, served by `CollectionDetailController`. That controller loads the collection's owner (l. 63) and shows any non-private collection to anybody (visibility check l. 66-68). So it is a public page of somebody's custom collection, reached from that player's Library.

The map puts it in the block "library modals and actions (the URL carries no owner; they act on your own library)" as a fixed `'puzzles/library'`. README §3 says:
- your own library and its list pages → Puzzles;
- other players' pages → Community.

As a result, anybody viewing another player's public collection, guests included, resolves to place Puzzles with section `library`. That gives the "My library" chip and the Puzzles tab.

The Menu row is wrong too. The registry's `collection` row lists `collection_detail` in `activeOn`. `menuRowId()` checks ownership only when `$entry->target->route === $route` (l. 63), never for `activeOn` routes. So "Your puzzles › Collection" also gets `aria-current` on somebody else's collection.

The own/other table in `NavigationPlaceResolverTest` covers the `system_collection_detail` list routes but not `collection_detail`, so the test stays green.

**Fix.**
- Give the resolver an owner for routes whose URL lacks `playerId`. For example, `CollectionDetailController` sets a request attribute such as `_nav_owner_player_id`, and `NavigationTwigExtension::navLocation()` passes `$params['playerId'] ?? <that attribute>` to `resolve()`.
- Map `collection_detail` to `puzzles/library|community`.
- In `menuRowId()`, apply the same "is it the viewer's page" check to `activeOn` matches of entries whose target `needsViewer()`.
- Add own, other and guest rows for `collection_detail` to `provideOwnAndOtherPages()` and `provideMenuRows()`. The other and guest rows should expect Community and no row.

### F3 · major · `src/Navigation/NavigationRegistry.php:536-597` (`achievements`, `your_week`; also `level_xp`, `karma`), `src/Navigation/NavVisibility.php:21-26` · status: resolved — New case `NavVisibility::XpPlayer` (`xp_player`, signed in ∧ XP visible), now used by `achievements` and `your_week`. Every case states its full conjunction: `XpParticipant` and `KarmaHolder` imply signed in ∧ XP visible. `includesGuests()` is true only for Everyone, GuestOnly and XpVisible. `NavigationRegistryTest::testPersonalEntriesNeverIncludeGuests` requires an audience without guests for every entry that targets the viewer, every role row, every row of You/Inbox/Roles/Puzzling/Library/Account/LogOut and every search-only entry under one. Its one explicit exception is `achievements_list`, the public badges overview. Mutation recorded: `your_week` back on `xp_visible` fails. `docs/navigation.md` was regenerated with an Audiences table, and `NavigationRegistryDocTest` is green.
**Problem.** Two personal "Your puzzling" rows have an audience that includes guests.

- `achievements` targets `RouteTarget::viewer('player_achievements')`, a page of the viewer's own.
- `your_week` targets `my_activity`, the viewer's own week.
- Both have `visibility: NavVisibility::XpVisible`.

`XpVisible` means `XpFeatureGate::isVisibleFor($viewer)`, which is `$viewer?->isAdmin === true || $this->xpLaunchReadiness->isReady()`. That is true for guests since the XP launch.

The registry therefore says guests see two Your-puzzling rows. That contradicts README §4.12 ("Hidden for guests: … Your puzzling …"), and a guest has no player id to build the `achievements` link with. It does not stop at the data:
- `docs/navigation.md` publishes `xp_visible` for these rows.
- MENU-03's `nav-search.json` tags each entry with this one audience value.
- The plan gives guests `everyone guest` + `xp` once XP is launched (§4 MENU-01 "Change"), so the client filter would show these entries to guests.

The audience model cannot say "signed in **and** XP visible", because `NavVisibility` is single-valued. The same ambiguity affects two more audiences:
- `XpParticipant` ("XP visible and the viewer did not opt out"): it is not stated whether a guest counts.
- `KarmaHolder`: README says Karma is behind the XP gate, but the case's comment does not mention it.

**Fix.**
- Make the audience express both conditions. Either add a case such as `XpPlayer` (signed-in ∧ XP visible) for `achievements` and `your_week`, or make `visibility` a list that must all hold.
- State in `NavVisibility` that `XpParticipant` and `KarmaHolder` imply signed-in and XP visible.
- Add a structural test: an entry whose target `needsViewer()`, or whose phone group is You, Inbox, Roles, Puzzling, Library, Account or LogOut, must have an audience that excludes guests.
- Regenerate `docs/navigation.md`.

### F4 · minor · `assets/styles/_dynamic-modal.scss:46-52` (decision 18) · status: resolved — `_dynamic-modal.scss`: one rule `.modal { height: calc(100% - var(--bottom-nav-height, 0px)) }` replaces the `#dynamic-modal-container` rule, so every Bootstrap modal box ends above the bar and its dialogs size by it. Today's value is 0, so nothing changes. `NavLayoutCssTest`: the provider row now checks `.modal`, and the new `testEveryModalBoxEndsAboveTheBottomBar` asserts that the dynamic container is a `.modal` and that no SCSS rule sizes a modal box (height/max-height/bottom/inset) without `--bottom-nav-height`. `checks/F-03.py` also opens `#membersExclusiveModal`: the simulated bar gives 0 overlaps. Its line-up cleanup was also fixed: it re-signed in an already signed-in session, which threw, and left two `comparison_subject` rows in the clone; those were removed through the UI and the count is back to 0.
**Problem.** Only the dynamic modal is shortened above the bottom bar.

- `.modal-backdrop` now ends at `--bottom-nav-height` for **every** Bootstrap modal.
- But only `#dynamic-modal-container` is shortened.
- About 20 other `.modal` elements keep Bootstrap's full-viewport box (`position: fixed; height: 100%`, z-index 1055) over the bar. Examples: `#membersExclusiveModal`, the announcement modals, `components/ConfirmModal`, the delete modals, `#puzzlePickerFilters`, `#blockPlayerModal` and `#comparison-add-sheet`.

Once NAV-01 adds the bar, it will look undimmed and usable under those modals, but every tap will land on the transparent `.modal`: it dismisses the modal or does nothing. That contradicts decision 18 ("bar usable under every sheet"). Today it is invisible, because the value is 0. F-03's overlap list (NAV-01) only opens a `modal-sheet-sm-down` dynamic modal, so it would not catch this.

**Fix.** Pick one:
- End every `.modal` at the bar on phones: one rule, e.g. `.modal { height: calc(100% - var(--bottom-nav-height, 0px)) }`.
- Keep the full-height backdrop for non-dynamic modals.

Either way, add one such modal (the members modal) to the F-03 / NAV-01 overlap check.

### F5 · minor · `tests/Navigation/NavigationRegistryTest.php:361-369` · status: resolved — `targets()` collects `a[@href]`, `form[@action]` (with its method) and `*[@formaction]` (method from `formmethod`, else from the owning form), and routes them through the router with that HTTP method (POST-only routes match). `method=dialog` and empty actions are skipped. For guests the test also asserts that a chrome POST form was found. Mutation recorded: removing the `newsletter_subscribe` entry fails for guests on `/en/faq` and `/en/hub`. Today's chrome forms (`newsletter_subscribe`, `newsletter_turn_on`) are registered.
**Problem.** The rendered guard does not check chrome forms.

`hrefs()` collects only `descendant-or-self::a[@href]` inside the chrome containers. Chrome `<form action>` and `[formaction]` targets are never mapped through the router. Today the footer posts to `newsletter_subscribe` and `newsletter_turn_on`. Those routes are in the registry only because they were added by hand (`notInMenuList()`, "Form action of the footer's …"), and nothing proves they stay registered. A new chrome form to an unregistered route would pass.

Everything else about link collection holds:
- containers outside `<main>` plus the new roots;
- relative hrefs fail loudly;
- only the page's own route in another locale is skipped;
- no hard-coded absolute internal URL exists in today's chrome.

**Fix.** In the same containers, also collect `descendant-or-self::form/@action` and `descendant-or-self::*[@formaction]/@formaction`, and route them like hrefs. The search form, if it becomes a bare input, needs no exception.

### F6 · minor · `src/Navigation/NavigationRegistry.php:35-54` (`all()`), `:55`, `:71`, `:84`, `:175` · status: resolved — The constructor builds the entries once, into readonly properties: the list plus indexes by id, by route (target, guests' target or activeOn) and by target route, all in registry order. `all()`, `byId()`, `byRoute()` and `byTargetRoute()` read them and `menuPath()` goes through `byId()`. There is no static memo and no per-request state; the class stays `readonly`, and `NavigationServicesResetTest` is green.
**Problem.** `all()` rebuilds all 126 `NavEntry` objects on every call. `byId()`, `byRoute()`, `byTargetRoute()` and the recursive `menuPath()` each call it again.

Today this costs about one call per request, from the resolver. The consumers planned next do lookups per row on every page view: the Menu builder, the Help sheet rows, LAUNCH-02, NAV-12 and the search index. That is roughly 70 rows × 126 constructions × Twig, on the chrome of every page.

**Fix.** Build the list once and index it by id, for example in a `private static ?array` memo. It is safe in worker mode: the entries are request-independent immutable data that never touch the translator, and `NavigationServicesResetTest` already skips static properties. The class stays `readonly`.

## Checked and fine

**GEST-09**
- `assets/head/platform_os.js` is ES5. It checks in the order of the requirement:
  1. the app markers, which equal `PlatformDetector`'s constants (test-enforced);
  2. UA-CH `platform`, authoritative when non-empty;
  3. `Android` in the user agent;
  4. `iPhone|iPad|iPod`, or `Macintosh` with `maxTouchPoints > 1`;
  5. `CSS.supports('-webkit-touch-callout','none')`;
  6. `other`.

  It is wrapped in try/catch and contains no per-page value. The inline text is identical on `/en/faq`, a Czech page and the member Hub.
- Head order: viewport → `_head_flags` → asset-failure → `msp-rendered-at` → critical CSS → stylesheet. Two tests assert it.
- About 1.2 KB, under the 1300 B cap.
- 27 node cases ⊇ the 14 of the Verify, including the broken-navigator case.
- Never read outside `platform.js`. Its only writer is the head script, and nothing sends it to the server.
- `GuestHtmlDeviceIndependenceTest`: byte-identical guest HTML for iPhone, Pixel and desktop, which stays `public` with no `Vary: User-Agent`.
- `HeadFlagsCoverageTest` covers every layout with its own `<html>`. The exemptions (print pages, name tags, asset-recovery) are plan decision 79.

**NAV-14 foundation**
- `PlatformDetector` accepts the Hotwire markers and the legacy ones, stays readonly without a memo, and has a static `forUserAgent()`.
- `native_app_attribute()` outputs only enum values. `layer_frame()` is applied to the 4 achievement openers.
- `platform.js` is pure, reads on every call, and treats junk values as web. `app.js` derives its globals from it. Its restore handler and the service worker step aside in the app.
- `_native.scss` also matches `html[data-native-app]`, so the rules hold before first paint.
- `AnonymousCacheHeadersSubscriber`:
  - it skips native user agents (all six markers tested);
  - phone and desktop browsers keep `public, s-maxage`;
  - the only explicitly `public` endpoints are JSON and do not branch on the platform.
- The edge-rule note in `cloudflare.md` is present.
- The functional tests use the exact user agent of the Verify, cover `/en/faq`, a puzzle page and the member Hub, plus the four layer routes that exist today.
- `symfony/ux-native` is absent.
- `assets/` is in the production image (`Dockerfile` `COPY ./assets` / `COPY . .`), so `source('@head_scripts/…')` resolves at runtime.

**F-01**
- Every field of the requirement is present.
- Group orders match README §4.12 (phone) and §4.3 (dock), with the guest card after the places (decision 31).
- Row sets match the README groups. Results, Where did it go?, the Community place row and `add_intro` arrive with their routes, as planned.
- Counts per role match the README: guest ≈ 25 rows, member 56 once Results and Where did it go? exist.
- Roles: organiser +1, moderator +4, admin +14. They match the P0-02 `admin_key` sets exactly.
- R11: `hub_guest` is search-only and GuestOnly.
- R2: Where did it go? is not registered yet (LAUNCH-02a).
- Search-only list ⊇ README §4.12.
- The structural tests are literal: unique ids, existing routes and `activeOn`, dynamic parameters checked against path variables, exactly one placement kind per surface, `secondary` ≤ 2, en keys, no rival/suspicious synonyms, and anchors present on their pages.
- The rendered guard covers 6 roles × `/en/faq` and `/en/hub` (+ the help frame when it exists), plus the frozen baseline JSON. My one gap is F5.
- Note on evidence: the recorded mutation run (`wjpc`) quotes the baseline-JSON assertion. The rendered assertion would fail on the same footer link, but its failure is not quoted. Worth recording when the JSON is retired.
- The docs mirror is generated, `assertSame`-guarded, and contains no names or figures. Nothing under `docs/` is read by `src/`.

**F-02**
- Every route outside the excluded prefixes is matched by exactly one rule. Prefix families checked one by one against the route list (`brand_`, `pieces_`, `ladder_`, `event_`, `edition_`, `comparison_`, `manage_`, `round_`, `live_results`, `newsletter_`, `guide_`, …): none swallows a misfit.
- Every own/other route carries `playerId`.
- `edit_time` → Add.
- Explainers, help, legal, auth and Inbox → none.
- The memo lives on the request, so no service state is kept. The only defect is F2.

**F-03**
- Variables exist in critical CSS only. `--nav-left` is constant 0 until NAV-13, and no JS or template writes the layout lengths (test).
- `@property` registrations; `:has()` for the bottom bar; `data-native-app` zeroes every length.
- Sub-bars follow with `translate`, only under `:root[data-topbar]`, so nothing changes today. `.ev-month-header`'s pre-existing `top` transition is allow-listed.
- Every README §4.2 fixed bottom element and every §4.3 page-wide bar uses `nav-bottom`, `--bottom-nav-height`, `--nav-left` or `nav-center-x`. I recomputed each against today's values: they are identical at 0.
- Closed global search and its overlay are `display:none`, and the card layer `[hidden]` too.
- The three bar-free pages empty `nav_chrome` / `nav_layers` (asserted as the exact list).
- The z-index scale is documented.
- One visible nuance, inherent to `display:none`: the global search now closes without its 0.3 s fade, and opens without a fade in browsers lacking `@starting-style`. This is acceptable under README §4.2.

**F-04**
- Viewport without `maximum-scale` or `user-scalable`. `theme-color` and manifest are both `#ffffff`, with `?v=3`. Tested for guest and player.
- The touch rule covers tom-select through the (0,2,1) `:root :is(.ts-control, …)` rule with `1em` (a `-lg` tom-select keeps 20 px). It does not touch desktop.
- The only defect is F1.

**F-05**
- Literal "Marketplace" and "Messages" are gone from the footer, the header and both badge partials.
- cs `menu.events` = "Události".
- `menu.new_badge` is removed from all 6 catalogues, as single-line deletions: no re-dump and no diff noise. All 6 YAML files parse.
- Only `new_badge` is referenced.
- Interim note, already in plan risk: the Mercure-rendered `_unread_badge` now labels in the triggering request's locale until INB-02's numbers-only stream. This is acceptable only because INB-02 ships in the same release.

**LAUNCH-00**
- One `const null|string DATE = null`, read through a parameter into a readonly service with `ClockInterface`.
- Null means not launched, no pointers, and New badges shown.
- Boundaries tested: +14 and +30 days at Prague midnight, including the UTC-evening registration case.
- An account without a registration date counts as old; guests are excluded by the type.
- Invalid dates are refused. The Twig functions follow a replaced service. A `feature_flags.md` entry exists.

**Cross-cutting**
- `NavigationServicesResetTest` reflects over `src/Navigation/**` and `src/Twig/Navigation*` (+ `EXTRA_CLASSES`). Every class there is readonly or holds only readonly properties.
- No new controller, route or public endpoint. The dump command only writes a committed file.
- No user input reaches output: `native_app_attribute()` is `is_safe` over enum values only.
- No names, e-mails or business figures appear in the committed files (grep of the diff).
