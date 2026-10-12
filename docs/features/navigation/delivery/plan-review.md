# Navigation redesign — implementation plan review (P0-01)

- **Reviewed:** `docs/features/navigation/implementation-plan.md` (working tree, untracked; sha256 `d250cabd700f…`, 971 lines) against `requirements.md` (`8c960d2e98a1…`), `README.md` (`7947710b3114…`, D1–D22 binding) and `CLAUDE.md`. Code facts checked in the worktree at `524a3305` (`feature/navigation-redesign`); the uncommitted P0-03 files were read, not touched. Area drafts consulted in `var/navigation-delivery/plan-drafts/`.
- **Date:** 2026-10-12. **Reviewer:** fresh adversarial reviewer (wrote none of the plan or drafts).
- **Libraries as installed:** `@hotwired/turbo` 8.0.23 (package.json range `^8.0`), Bootstrap 5.2.3, symfony/ux-live-component and ux-turbo 3.4.0.
- **Result:** 28 findings — **1 blocker, 9 major, 18 minor**. P0-01 stays `in-progress` until every row below is `resolved` or `won't fix (reason)`. **Status 2026-10-12:** all 28 resolved in `implementation-plan.md` (its §10 maps each finding to the plan sections; new decisions 92–104), 0 won't fix.

## Summary

| # | Severity | Plan section / IDs | Finding | Status |
|---|---|---|---|---|
| F1 | blocker | §2 row 5, MENU-01, NAV-06, decision 34 | MENU-01's weight sub-check deferred to NAV-06 deadlocks NAV-06's precondition | resolved — §2 rows 5/7, P0-02 (b2), MENU-01 tests (`nav_omit_legacy_chrome` switch), NAV-06 tests, decision 34, risks 1/8 |
| F2 | major | NAV-11, NAV-08, INB-02 | verify-after lists miss items the Verify needs | resolved — §2 rows 4/6/9/12, INB-02 tests (+NAV-13), NAV-08 (verify-after ADD-04), NAV-11 (verify-after NAV-06, PROF-05a, CARD-01a), decision 101; orchestrator mirrors the Notes column in state.md |
| F3 | major | GEST-01 popstate, PROF-05c | a pop to another page while Results pauses Turbo is handled by nobody | resolved — GEST-01 Popstate (page owners) + harness case, PROF-05c Verify step, decision 93 |
| F4 | major | GEST-01 (d) | form replace-marking is undone at `turbo:submit-end`, before Turbo reads it | resolved — GEST-01 (d) markers restored at turbo:visit/turbo:load/next submit-start + harness order + POST-in-layer WebDriver case, CARD-01a tests, decision 94 |
| F5 | major | GEST-01, GEST-17, decisions 58 D-7 / 68 | Turbo internals used with an unpinned `^8.0`, no contract test, no fallback | resolved — GEST-01 Files (Turbo pinned 8.0.23, npm install in the worktree, lock committed) + "Turbo internals" (contract test, runtime fallbacks), §5 package.json row, DOC-01, decision 92, risk 19 |
| F6 | major | §7, HELP-01b, HELP-05, R6, risk 16 | own scrim for every dynamic modal breaks outside-click close; a narrower design meets both Verifys | resolved — §7 Scrim (Help-only own-scrim path, 34 partials unchanged), HELP-01a `help_opener_attrs()`, HELP-01b, HELP-05 (a)/(b) (root cause fixed in the general path if found there), F-03 Apply, decisions 6/18, risks 15/16 |
| F7 | minor | §7 `release()` | "Bootstrap's focus trap deactivated" is moot (the modal runs `focus: false`) | resolved — §7 Scrim (no focus-trap step, no Bootstrap private API), HELP-01b Files, decision 6 |
| F8 | major | GEST-15, decision 65, risk 18 | POST-form guard: scope undefined, list incomplete, UX cost, GET mutations leave stale snapshots | resolved — GEST-15 guard (recursive scope, base exclusions with reasons, three missing sites, `post_action` controller + `post_action_confirm` fallback, `data-clears-snapshots`) + tests + keys, decisions 65/99, risk 18 |
| F9 | major | F-03, NAV-03 (decisions 17, 20) | sub-bars follow the hiding top bar through `top` → layout shifts on every scroll reversal | resolved — F-03 Variables (static `--header-height`, `--topbar-shift` transform, scroll margins on `--topbar-h`) + tests, NAV-03 hide on scroll + shift check, decisions 17/20, risk 20 |
| F10 | major | NAV-02, ADD-03, NAV-07, GEST-16 | label cascade and pill run after first paint → shifts that fail NAV-07 "= 0" and GEST-16 | resolved — NAV-02 (mandatory pre-paint `@container` table, JS only raises), ADD-03 pill (tabular digits, format/icon/width fixed at render), NAV-07 tests (6 locales at 320/360), decision 95, risk 20 |
| F11 | major | MENU-01 tests | the 320 px "no row label truncated" check passes vacuously on the hidden slot | resolved — MENU-01 tests (menu page / open panel, visibility precondition + row count), NAV-02 tests, QA-01 checks |
| F12 | minor | §2 phases 1, 3, 4, 5, 14; §5 | parallel items share files the ownership table assigns to both | resolved — §2 hard rules + rows 1/4 (INB-01 → INB-03), F-01 (`BarAction` in Stream B), NAV-14, §5 rows (Send-feedback row MENU-01 only; app.js/app.scss snippets), decision 84 |
| F13 | minor | NAV-10 | "Deps: none (runs parallel)" but its Verify needs the bars | resolved — §2 row 6 (NAV-10 right after NAV-03), NAV-10 deps, decision 101 |
| F14 | minor | F-01, COM-01a, MENU-02a, §3.6 | nobody owns registry label keys before MENU-01 | resolved — F-01 tests/keys, COM-01a keys, MENU-02a creates `nav.menu.title`, decision 100 |
| F15 | minor | NAV-02, GEST-18 | label-step numbering is off by one against the requirement | resolved — NAV-02 (steps 1/2/3 as the requirement), GEST-18 contract (step 3), decision 95 |
| F16 | minor | GEST-13 | `alert()` sites and `beforeunload` prompts not addressed ("no native dialog reachable") | resolved — GEST-13 "Other native dialogs" (scanner alerts → in-page notice, `NativeDialogAllowlistTest`, `beforeunload` logged exception), decision 96 |
| F17 | minor | GEST-13 watchdog | `afterRestore` reads a GEST-15 export that does not exist until phase 15 | resolved — GEST-13 watchdog (`navigation.activation` fallback until GEST-15), decision 102 |
| F18 | minor | GEST-11, NAV-08/NAV-03, GEST-15, INB-02 | Verify details that cannot run as written in this environment | resolved — §3.8 (focus emulation, in-page timing), NAV-03/NAV-08/NAV-11, GEST-11 tests, GEST-15 staleness (`Date.now()` contract), INB-02 tests (probe-captured stream, transport not exercised) |
| F19 | minor | P0-02 b5, QA-03 | Lighthouse method in the plan differs from what P0-02 actually measured | resolved — P0-02 (b5) and QA-03 name `lighthouse-web.sh` + `lighthouse_runs.py 5` |
| F20 | minor | LAUNCH-00, GEST-14/15, DOC-01 | `docs/features/feature_flags.md` updated only at the end | resolved — LAUNCH-00, GEST-14, GEST-15 add their `feature_flags.md` rows in their commits; DOC-01 re-checks |
| F21 | minor | §3.1, MENU-01, INB-02 | ResetInterface reflection test does not reach the new memos outside `src/Navigation` | resolved — §3.1 (`EXTRA_CLASSES` list), decision 103 |
| F22 | minor | PROF-05a | "component tests stay green against the wrapper" contradicts the builder's deliberate changes | resolved — PROF-05a (wrapper calls the builder in `legacy` mode; builder tests pin the new semantics), §6 component-tests row, decision 97 |
| F23 | minor | MENU-01 (`OrganizerSql`), ADD-03 | `DraftVisibilityCoverageTest` will flag the new organiser EXISTS | resolved — MENU-01 tests (`NOT_FILTERED` + `self::OWN` for `OrganizerSql.php`, `GetPlayerProfile.php`), §6 row |
| F24 | minor | NAV-10, §6 `HubControllerTest:162-169` | the guest intent of `testHubStaysInTheNavigation` is dropped | resolved — NAV-10 tests, §6 HubControllerTest row (guest `nav-search.json` has `hub_guest`; LAUNCH-02 row resolves to `hub`) |
| F25 | minor | PROF-04 | Statistics gains statements with only an "optional" pin | resolved — PROF-04 tests (mandatory pin created in the PROF-04 commit — a pin raised later would break "only down"; pre-counts and moved parts recorded), decision 98 |
| F26 | minor | NAV-14 helpers, MENU-02a/02b | `NavChromeAssertions` forbids `#site-menu`, which the native `menu` page renders in `<main>` | resolved — §3.5, NAV-14 helpers, MENU-02a (positive `main #site-menu`), decision 103 |
| F27 | minor | GEST-01 (`live_results_controller.js`) | event-day tool's history rewritten without a behaviour check | resolved — GEST-01 tests (`checks/GEST-01-live-results.py`) |
| F28 | minor | header, P0-01, MENU-01 | stale path and methods cited as existing that do not | resolved — header, P0-01 Files (drafts in `var/navigation-delivery/plan-drafts/`), MENU-01 Menu data (3): n = existing `EventsViewerData::organizedCount()` (it exists, `src/Results/EventsViewerData.php:128`; only `PlayerReviewCounts::total()` was missing and is added with a test), decision 103 |

## Findings

### F1 · blocker · §2 row 5, MENU-01, NAV-06, decision 34 — resolved — §2 rows 5/7, P0-02 (b2), MENU-01 tests (`nav_omit_legacy_chrome` switch), NAV-06 tests, decision 34, risks 1/8
**What is wrong.** The plan sets MENU-01 to `verify-after: NAV-06` for its chrome-weight sub-check (§2 row 5; §4 MENU-01 heading; decision 34). NAV-06's precondition (requirements NAV-06; plan §4 NAV-06 "Precondition: HELP-01b, INB-01, NAV-05, NAV-13, MENU-01, MENU-02b `verified`") requires MENU-01 to be `verified` before NAV-06 may run. Per the Conventions an item with a pending verify-after is `implemented (verify-after: …)`, not `verified`. MENU-01 can only become verified after NAV-06, and NAV-06 can only run after MENU-01 is verified: the delivery stops at phase 7.
**Fix.** Measure MENU-01's weight before NAV-06, comparing like with like: give the P0-02 `ChromeWeightProbe` a test-env-only switch, for example a Twig global `nav_delivery_omit_legacy` that is false everywhere except in the probe's kernel. It drops the R10 `.legacy-chrome` block and the old critical-CSS rules, so the probe measures exactly the chrome NAV-06 will ship (bars, Menu, shells, new critical CSS) against the P0-02 baseline. MENU-01 is then `verified` in phase 5/6. Re-run the probe without the switch at NAV-06 as a regression check, not as a precondition. Remove decision 34 and the `verify-after: NAV-06` on MENU-01 from §2/§4. If that is rejected, record an explicit decision that NAV-06's precondition treats MENU-01's weight clause as satisfied by the switch-based measurement. Never start NAV-06 with MENU-01 unverified.

### F2 · major · NAV-11, NAV-08, INB-02 (verify-after completeness) — resolved — §2 rows 4/6/9/12, INB-02 tests (+NAV-13), NAV-08 (verify-after ADD-04), NAV-11 (verify-after NAV-06, PROF-05a, CARD-01a), decision 101; orchestrator mirrors the Notes column in state.md
**What is wrong.** Hard rule 3 says no item is `verified` before every item its Verify needs. Three plan entries name fewer IDs than their Verify needs:
- **NAV-11** is `verify-after: NAV-06` only (§2 row 6, §4 NAV-11 heading). Its Verify runs axe on "Results" and "card open" (requirements NAV-11), which are built in phase 11 (PROF-05a) and phase 12 (CARD-01a). The plan's own Deps line lists PROF-05a and CARD-01a "for the full page list", yet phase 7 re-runs NAV-11 as if it could become verified there.
- **NAV-08**'s Verify includes "running stopwatch (initial render)". The plan's `BarFreePagesTest` says "(initial render, after ADD-04)", and the stopwatch page loses its chrome only in ADD-04 (phase 9). NAV-08 (phase 6) carries no verify-after.
- **INB-02**'s WebDriver part is `verify-after: NAV-03, NAV-04, MENU-01`. The Verify runs "at 1440 docked", which needs NAV-13. NAV-13 comes after NAV-04 inside phase 6, so the last-named-ID rule would re-run INB-02 before the docked column exists.

**Fix.** NAV-11 → `verify-after: NAV-06, PROF-05a, CARD-01a` (phase 12 re-run; phase 7 runs what exists). INB-02 → add NAV-13. NAV-08: move the running-stopwatch emptying of `header`/`nav_chrome`/`nav_layers`/`footer`/`page_help_line` into NAV-08 (README §4.10 lists the running stopwatch as a bar-free context). ADD-04 keeps the exit control and the client-side `data-stopwatch-running` toggle. Alternatively mark NAV-08 `verify-after: ADD-04`. Update `state.md`'s Notes column to match.

### F3 · major · GEST-01 popstate, PROF-05c — resolved — GEST-01 Popstate (page owners) + harness case, PROF-05c Verify step, decision 93
**What is wrong.** PROF-05c pauses Turbo's history for the whole life of the Results page: "On connect `pauseTurboHistory('results')`, on `turbo:before-visit`/disconnect `resumeTurboHistory('results')`". The helper's popstate handling covers pops "while a layer holds the entry", including "a pop to another URL → one `Turbo.visit(…, {action:'restore'})`" (§4 GEST-01 Popstate). The Results controller handles only "different `location.search`" and "same URL". A Back that leaves Results for an earlier same-document Turbo entry (Hub → Results by Turbo link → change sort → Back → Back) is handled by nobody. Turbo's `onPopState` is removed while stopped (turbo.es2017-esm.js `History.stop()`), so the URL becomes `/en/hub` while the Results DOM stays. Neither the PROF-05c Verify nor the GEST-15 script Backs past the first Results entry, so the bug ships green.
**Fix.** Generalise the "pop to another URL" rule in `layer_history.js` to page owners. The capture listener applies it whenever the paused set is non-empty and the popped entry is not one the owner pushed (`history.state?.mspPage !== owner`, or the pathname differs from the owner's page). Steps: release every page owner, restart Turbo (identity restore), then `Turbo.visit(location.href, {action: 'restore', historyChanged: true, restorationIdentifier: history.state?.turbo?.restorationIdentifier})`, so the instant-Back rule decides between snapshot and reload. Add a node harness case and a PROF-05c WebDriver step: Hub → Results by Turbo click → change sort → `history.back()` twice → Hub rendered at `/en/hub`, Results gone.

### F4 · major · GEST-01 (d) replace visits for forms — resolved — GEST-01 (d) markers restored at turbo:visit/turbo:load/next submit-start + harness order + POST-in-layer WebDriver case, CARD-01a tests, decision 94
**What is wrong.** The plan says `turbo:submit-start` marks forms with `data-turbo-action="replace"`, "restored at `turbo:submit-end`" (§4 GEST-01 (d)). In Turbo 8.0.23 the visit action of a successful submission is read after `turbo:submit-end`:
- `FetchRequest.perform()` dispatches `requestFinished` → `turbo:submit-end` in its `finally` (turbo.es2017-esm.js ~789-813, 1243-1252).
- `Navigator.formSubmissionSucceededWithResponse()` first `await`s `fetchResponse.responseHTML` and only then calls `#getActionForFormSubmission()` (~4867-4877).
- That call is not awaited by `receive()`.

So the attribute is restored before Turbo reads it, and the visit is `advance`. A concrete case: the card's Compare is a POST (CARD-03 "Compare posts `requestUri`") from inside the card layer, which would leave `[A, A′, B]`. That breaks GEST-01's Done when, and a fake-Turbo node harness cannot see it.
**Fix.** Restore form markers at `turbo:visit`, or `turbo:load`, or the next `turbo:submit-start`, never at `turbo:submit-end`. Model the real order in the harness: submit-end fires before the action is read. Add one WebDriver case with a POST form inside a layer: open the card, Compare, `history.back()` → the opener page, index = before opening. Links are fine (`getActionForLink` is read synchronously after `turbo:click`).

### F5 · major · Turbo internals (GEST-01 resume identity, `currentVisit.action` safety net, decision 68 timing) — resolved — GEST-01 Files (Turbo pinned 8.0.23, npm install in the worktree, lock committed) + "Turbo internals" (contract test, runtime fallbacks), §5 package.json row, DOC-01, decision 92, risk 19
**What is wrong.** Several contracts rest on undocumented Turbo internals:
- `session.history.{stop,start,replace,currentIndex,restorationIdentifier}`, with identity restore after `start()`. `start()` replaces the entry with a fresh uuid (dist ~4537-4542).
- `session.navigator.currentVisit.action`. This works only because `changeHistory()` runs at render (`PageView.renderPage`, ~5551-5560).
- The view-transition decision happens at render from the live head meta (`shouldTransitionTo`, ~5547; decision 68).

package.json pins `"@hotwired/turbo": "^8.0"`, so any `npm install`/update may move past 8.0.23. The plan has no contract test against the real Turbo module and no runtime fallback; the risk register only says "guarded internal".
**Fix.**
- Pin `@hotwired/turbo` to `8.0.23` exactly in package.json (same commit as GEST-01).
- Add `tests/turbo-contract-harness.mjs` + `TurboContractScriptTest.php`. It imports the real dist and asserts each property/method the helpers use exists, that `start()` mints a new restoration id (the reason for the identity restore), that `changeHistory` is called from render, and that `prefersViewTransitions` reads the live meta.
- Feature-detect at runtime: if `currentIndex`/`replace` are missing, fall back to plain `start()` (today's behaviour); if `navigator.currentVisit` is missing, skip the safety net; `console.warn` once each.
- DOC-01 states that a Turbo upgrade re-runs the GEST-01/GEST-12/GEST-15/GEST-17 checks.

### F6 · major · §7 scrim, HELP-01b, HELP-05, R6, risk 16 — resolved — §7 Scrim (Help-only own-scrim path, 34 partials unchanged), HELP-01a `help_opener_attrs()`, HELP-01b, HELP-05 (a)/(b) (root cause fixed in the general path if found there), F-03 Apply, decisions 6/18, risks 15/16
**What is wrong.** R6 makes the dynamic modal (34 `modal-frame` partials) run with Bootstrap `backdrop: false` and a controller-drawn scrim with "click = `requestClose`" (§7 "Scrim and HELP-05"). Three problems:
1. In Bootstrap 5.2.3 the outside-click close lives on the `.modal` element and fires only `if (this._config.backdrop)` (`node_modules/bootstrap/js/src/modal.js` ~228-240). `.modal` is `position: fixed; inset 0` at z-index 1055, above any scrim at ≤ 1050. So with `backdrop: false` the scrim never receives a click and nothing closes on an outside tap. That is a regression for every partial (result detail, edit time, achievements, conversations, …).
2. In `release()`, Bootstrap's `role="dialog" aria-modal="true"` stays on a sheet that is deliberately non-modal while the page is interactive.
3. The change is far wider than needed. Only the Help sheet's sent state needs to be non-blocking.

**Fix (narrower design that still meets HELP-05 and HELP-01b literally).** Keep Bootstrap's backdrop for every partial except Help:
- The help openers (all built from `help_path()`) carry `data-modal-scrim="own"`. The trigger is known in `handleBeforeFetch` (`findTrigger()`), before the spinner path calls `show()`.
- For such a trigger the controller re-creates the instance through public API (`this.modal.dispose(); this.modal = new Modal(el, {focus: false, backdrop: false})`) and draws its own scrim. Outside clicks are handled on the `.modal` element (`event.target === this.element` → `requestClose`), not on the scrim. The default instance is restored on `hidden.bs.modal`.
- The sent state's `release()`: hide the scrim, `.modal { pointer-events: none }` with `.modal-content { pointer-events: auto }`, remove `body.modal-open` and the inline overflow/padding, and remove `aria-modal`. Esc/×/swipe/Back still close through the helper.

HELP-05 then reads `.modal-backdrop === null` after send, a page link click works, and the sheet keeps the confirmation (HELP-01b). Decision 18 additionally needs `.modal-backdrop` (not only the container) to end at `--bottom-nav-height` on phones. If R6 stays global instead, the plan must add the `.modal` outside-click handler, the `aria-modal` removal and a WebDriver outside-tap check on three non-help partials.

### F7 · minor · §7 `release()` — Bootstrap focus trap — resolved — §7 Scrim (no focus-trap step, no Bootstrap private API), HELP-01b Files, decision 6
**What is wrong.** §7 says `release()` deactivates "Bootstrap's focus trap". The dynamic modal is created with `Modal.getOrCreateInstance(this.element, {focus: false})` (`assets/controllers/dynamic_modal_controller.js:82-84`). Bootstrap 5.2.3 activates `_focustrap` only `if (this._config.focus)` (modal.js:191), so no trap is ever active. The step is moot, and implementing it would mean calling the private `_focustrap`. This resolves review goal (c): the sent state does not depend on Bootstrap internals.
**Fix.** Delete the step and state "no Bootstrap private API is used". Note in HELP-01b that focus containment of the sheet is the controller's own job, as today.

### F8 · major · GEST-15 guard ("opted-in templates contain no `method="post"` form, `confirm(` or `data-turbo-confirm`"), decision 65 — resolved — GEST-15 guard (recursive scope, base exclusions with reasons, three missing sites, `post_action` controller + `post_action_confirm` fallback, `data-clears-snapshots`) + tests + keys, decisions 65/99, risk 18
**What is wrong.**
1. **Scope is undefined.** §4 GEST-15 tells the agent to "inventory the rendered HTML of each opted-in page … for `<form method="post">`", but the test is described as "opted-in templates and their includes". Rendered HTML of every opted-in page contains base-level POST forms the plan never mentions:
   - the guest newsletter form (`_footer_newsletter.html.twig:12`);
   - the "Turn it on" form for players who switched the newsletter off (`_footer.html.twig:180`);
   - the free-trial start form inside `#membersExclusiveModal` (`base.html.twig:932`, `membership/_free_trial_start_form.html.twig`);
   - announcement modals (`modals/announcements/_free_trial_offer.html.twig`).

   Followed literally, the inventory forces conversions far beyond GEST-15; followed loosely, the guard means something else than the inventory.
2. **The list is incomplete.** Besides the plan's examples (PlayerHeader compare/block, events follow stars, CountryNudge, marketplace card actions), the opted-in templates and their includes also hold:
   - Players "Suggested for you" compare forms (`components/Players/Suggestions.html.twig:57` → `comparison_entry/_subject_form.html.twig:18`);
   - the series page's draft-banner Publish forms (`event_parts/_draft_banner.html.twig:27,42`, included by `competition_series_detail.html.twig:34`);
   - the compare forms in `player/_header_actions_menu.html.twig:66,83`.
3. **UX cost.** Turning one-tap toggles (follow star, compare add) into "GET link → modal → button" adds a tap to frequent actions. README D12/D9 did not decide this.
4. **Staleness gap.** Decision 65 clears the Turbo cache only after successful non-GET Turbo submits. The header's Favorite is a GET mutation (`components/PlayerHeader.html.twig:130-134` → `add_player_to_favorite`/`remove_player_from_favorite`), and so is the card's Favorite. A Back within 10 minutes then restores the pre-toggle Overview/Results snapshot.

**Fix.**
- (1) Define the guard as: the opted-in page template plus every template/component it includes, embeds or renders, resolved recursively by the test. Exclude `base.html.twig` and its chrome partials, listed with reasons: the guest newsletter form has no CSRF and is idempotent; the members/announcement modals are closed by `turbo:before-cache`. Record this as a decision and make the inventory follow the same definition.
- (2) Have the test discover forms by recursion rather than from a hand list. Add the three missing sites to the plan.
- (3) Prefer one tap: an `<a href>` GET fallback (the confirmation page/`modal-frame`, for no-JS) plus a small `post_action` Stimulus controller that POSTs with fetch (stateless CSRF header) and applies the Turbo Stream or redirect. No `<form method="post">` is left in the template, and one tap is kept. The `modal-frame` form stays the no-JS path. Log the choice.
- (4) Clear `Turbo.cache` and the restore Map on `turbo:click` of links marked `data-clears-snapshots` (favorite toggles in the header and the card), and after `post_action` successes.

### F9 · major · F-03 / NAV-03 — sub-bars follow the hiding bar with `top` — resolved — F-03 Variables (static `--header-height`, `--topbar-shift` transform, scroll margins on `--topbar-h`) + tests, NAV-03 hide on scroll + shift check, decisions 17/20, risk 20
**What is wrong.** The plan sets `--topbar-h` to 0 under `html[data-topbar="hidden"]` and aliases `--header-height: var(--topbar-h)` (§4 F-03). "Sub-bars anchored at `var(--header-height)` transition `top` with the same timing (transform fallback if Galaxy A profiling shows jank)" (§4 NAV-03, decision 20). Many sticky/fixed bars sit at `top: var(--header-height)`:
- `_compact-bar.scss:19` (puzzle page and player pages);
- `_events-page.scss:78`;
- `_comparison.scss:1168`;
- `_achievements-shelf.scss:513`;
- `_event-parts.scss:24`.

Animating `top` of a stuck or fixed element moves it in the viewport without a discrete input, which Chromium reports as `layout-shift` (transforms are not). Every scroll-direction change on a phone would add field CLS on the puzzle pages, the SEO long tail. NAV-07 measures shifts only inside the header, tab bar, rail and column, so nothing in the plan would catch it.
**Fix.** Keep `--header-height` as the static bar height for layout and scroll margins. Move the sub-bars with `transform: translateY(calc(-1 * var(--topbar-shift)))`, where `--topbar-shift` is set by `html[data-topbar="hidden"]` in CSS only (same 200/280 ms timing, none under reduced motion). `--topbar-h` still drives `scroll-padding-top`. Add to NAV-03's WebDriver check: on a puzzle page with the compact bar shown, scroll down 400 px and up 20 px → no `layout-shift` entry whose sources lie outside `main` content, read from a buffered PerformanceObserver registered before navigation.

### F10 · major · NAV-02 label cascade and the ADD-03 pill run after first paint — resolved — NAV-02 (mandatory pre-paint `@container` table, JS only raises), ADD-03 pill (tabular digits, format/icon/width fixed at render), NAV-07 tests (6 locales at 320/360), decision 95, risk 20
**What is wrong.** NAV-02 picks the label step in JS "on connect, `document.fonts.ready` and ResizeObserver"; the pre-paint guess is "optional" (§4 NAV-02). Its own risk line admits a "label flash on first paint for locales needing step 1/2". Shrinking 11 → 10 px or hiding labels after paint moves label boxes inside the tab bar, which produces `layout-shift` entries:
- NAV-07 requires the sum of shifts with sources in the tab bar to be **0** (requirements NAV-07).
- GEST-16 requires Hub CLS at 360 ≤ the P0-02 value, which is 0.0000 (`baseline.md` §5).

fr at 360 and en/de/ja/fr at 320 hit this on every full load. The ADD-03 pill likewise drops its icon "when text > 5 chars" and its digits change width every second, which shifts the tools after it in the header.
**Fix.** Make the pre-paint decision mandatory. Give `.bottom-nav` `container-type: inline-size`, and use per-locale `@container` thresholds generated from measured label widths (a small committed table, re-measured by the NAV-02 check). JS only corrects (ja font variance) and must not change the step when CSS already chose it. Run NAV-07's check in all 6 locales at 320 and 360 (not only en). For the pill: `font-variant-numeric: tabular-nums`, a width reserved for the format chosen at render (the server knows the elapsed time, so `h:mm:ss` vs `m:ss` and the icon decision are fixed per render), and no icon toggling mid-tick.

### F11 · major · MENU-01 — vacuous 320 px label check — resolved — MENU-01 tests (menu page / open panel, visibility precondition + row count), NAV-02 tests, QA-01 checks
**What is wrong.** MENU-01's test reads "WebDriver 320 × 6 locales: every `.mr-l` `scrollWidth <= clientWidth`, no ellipsis". At 320 px the only Menu on the page in phase 5 is the slot copy, "hidden by CSS where there is no column" (README §4.12). Hidden rows report `0 <= 0`, so the check passes without measuring anything. The requirement forbids weakened Verifys.
**Fix.** Run the check on a rendered surface: the `menu` page (MENU-02a renders the partial in `<main>` once MENU-01 lands), and again in the open phone panel after MENU-02b. Assert first that every `.mr-l` is visible (`getClientRects().length > 0` and `clientWidth > 0`), and that the row count equals the role's expected count. Apply the same visibility precondition to NAV-02's and QA-01's label checks.

### F12 · minor · parallel items share files (§2, §5) — resolved — §2 hard rules + rows 1/4 (INB-01 → INB-03), F-01 (`BarAction` in Stream B), NAV-14, §5 rows (Send-feedback row MENU-01 only; app.js/app.scss snippets), decision 84
**What is wrong.** §5's rule is "parallel items never share a row", yet:
- Phase 4 `INB-01 ∥ INB-03`: both are listed on the row `templates/notifications.html.twig`, `src/Query/GetNotifications.php`. INB-03 changes `GetNotifications::forPlayer()`; INB-01 changes the template, controller and `NotificationsControllerTest`.
- Phase 5 `MENU-03 ∥ HELP-01b`: both on the row `templates/navigation/_menu*.html.twig`, `_menu_layer.html.twig` ("HELP-01b row").
- Phase 14 `GEST-11 ∥ GEST-12`: both add install lines to `assets/app.js` (row "GEST-10/11/12 installs (14)").
- Phase 3 `GEST-02 ∥ GEST-13` and phase 14 `GEST-11 ∥ GEST-12 ∥ GEST-16`: each adds a SCSS partial and so an `app.scss` import line (`_bottom-sheet`, the confirm dialog's styles (file not named in the plan); `_press-feedback`, `_view-transitions`, `_hub-feeds`). §5 only says "append-only", which still conflicts on cherry-pick.
- Phase 1: Stream A's NAV-14 creates `src/Navigation/BarAction.php`, while Stream B "owns `src/Navigation/*`".

**Fix.**
- Make INB-01 → INB-03 sequential.
- Move the Send-feedback row entirely into MENU-01 (which already renders `<a href="{{ help_path() }}" …>`) and drop "HELP-01b row" from §5.
- For `app.js`/`app.scss` lines, let parallel agents return import/install snippets that the orchestrator applies, like translation fragments.
- Create `BarAction` in Stream B (F-01, a 4-case enum) and consume it in Stream A after the merge, or list Stream A's write in §5.

### F13 · minor · NAV-10 dependencies — resolved — §2 row 6 (NAV-10 right after NAV-03), NAV-10 deps, decision 101
**What is wrong.** NAV-10 says "Deps: none (runs parallel)", but its Verify asserts "Home tab/logo href = `hub`" (requirements NAV-10), and the plan rewrites `HubControllerTest:162-169` to "signed-in Home tab + logo `a[href="/en/hub"]`". The Home tab and the new logo exist only after NAV-01 and NAV-03.
**Fix.** NAV-10 runs after NAV-03 (or is `implemented (verify-after: NAV-01, NAV-03)` for the tab/logo assertions, with the redirect tests verified at once).

### F14 · minor · registry label keys have no owner before MENU-01 — resolved — F-01 tests/keys, COM-01a keys, MENU-02a creates `nav.menu.title`, decision 100
**What is wrong.**
- F-01 (Stream B) "owns no translations" and adds "Keys: none … label keys arrive with MENU-01/MENU-03", yet its structural test requires "keys defined in 6 locales".
- COM-01a titles its entry cards with "registry label keys".
- MENU-02a "reuses `nav.menu.title`", which MENU-01 creates in phase 5.

The skeletons (phase 2) would render raw keys, or fail `NavigationRegistryTest` and the later `NavigationTranslationsTest`.
**Fix.** State that the structural test checks only non-null keys and that F-01 points entries at existing keys. Each skeleton returns the keys of its own registry entry and template in its fragment: COM-01a its card titles; MENU-02a creates `nav.menu.title`, which MENU-01 then reuses. List these keys under each skeleton.

### F15 · minor · NAV-02 step numbering — resolved — NAV-02 (steps 1/2/3 as the requirement), GEST-18 contract (step 3), decision 95
**What is wrong.** The plan's steps are `data-label-step` 0 (11 px) → 1 (10 px) → 2 (current label only) (§4 NAV-02). The requirement's expected outcome is "fr at 360 step 2; at 320 en/de/ja step 2 and fr step 3" (requirements NAV-02), which is 1-based: README §4.2 has fr at 360 = 10 px. Recorded values will "miss" the expectation, inviting a wrong CSS "fix". GEST-18's "Truncated = NAV-02 step 2" depends on the same numbering.
**Fix.** Use `data-label-step` 1/2/3 to match the requirement (update GEST-18's contract), or record both numbers with an explicit mapping in the check's output.

### F16 · minor · GEST-13 — native dialogs other than `confirm` — resolved — GEST-13 "Other native dialogs" (scanner alerts → in-page notice, `NativeDialogAllowlistTest`, `beforeunload` logged exception), decision 96
**What is wrong.** GEST-13's Done when is "no native dialog is reachable from non-admin pages", but the plan converts only `confirm`:
- `assets/controllers/barcode_scanner_controller.js:105,647` call `alert(…)` with hard-coded English text on player pages (scanner without camera).
- `beforeunload` prompts exist on live results, the results desk, the participants sheet and seating.
- `native_billing_controller.js` alerts run in the app only.

**Fix.** Convert the two scanner `alert()`s to an in-page notice (translated, 6 locales) in GEST-13, and add an `alert(`/`prompt(` allow-list test next to `NativeConfirmAllowlistTest` (allow `native_billing_controller.js`: app only). Record `beforeunload` as a logged exception: it is browser UI for a real unload, cannot be in-page, and iOS Safari does not show it.

### F17 · minor · GEST-13 watchdog `afterRestore` — resolved — GEST-13 watchdog (`navigation.activation` fallback until GEST-15), decision 102
**What is wrong.** In phase 3 the watchdog reports `afterRestore`, but the plan's source for it is `lastRestoreKind()`, exported by GEST-15's `instant_back.js` in phase 15 (§4 GEST-15).
**Fix.** Until GEST-15: `afterRestore = navigation.activation?.navigationType === 'traverse'` (a restore is a reload today, so a back/forward load is the restore). GEST-15 switches it to `lastRestoreKind()`. Add node cases for both.

### F18 · minor · Verify steps that need a runnable form here — resolved — §3.8 (focus emulation, in-page timing), NAV-03/NAV-08/NAV-11, GEST-11 tests, GEST-15 staleness (`Date.now()` contract), INB-02 tests (probe-captured stream, transport not exercised)
**What is wrong / fix.**
- **GEST-11** "`.msp-ripple` present ≤ 30 ms": a `goog/cdp/execute` `Input.dispatchTouchEvent` round trip takes longer than 30 ms. Measure in the page: stamp `performance.now()` in a capture `pointerdown` listener and in a `MutationObserver` for `.msp-ripple`, as GEST-04 does. State this in the plan.
- **NAV-08** (`:has(:focus)` hides the tab bar), **NAV-03** ("never hidden while focus is inside the header") and **NAV-11** (visible focus): in the Xvfb Selenium window `:focus`/`document.hasFocus()` can be false. Call CDP `Emulation.setFocusEmulationEnabled {enabled: true}` in `navlib` before focus-dependent checks, and assert `document.hasFocus()`.
- **GEST-15** negative case "`Date.now` shifted by +11 minutes": this works only if the restore Map's `cachedAt` and the age test use `Date.now()`, never `performance.now()`. Make it part of the module contract.
- **INB-02** "a pushed stream changes every `.inbox-badge`": the Selenium container cannot reach the Mercure hub's public URL. Inject via `Turbo.renderStreamMessage()` the exact stream produced by `MercureNotifierInboxBadgeTest` (written to `var/…` by the test), and record "transport unchanged, not exercised". The plan says "captured stream"; make the source explicit.

### F19 · minor · Lighthouse method (P0-02 b5 vs QA-03) — resolved — P0-02 (b5) and QA-03 name `lighthouse-web.sh` + `lighthouse_runs.py 5`
**What is wrong.** P0-02 b5 in the plan says `npx lighthouse` on a production Encore build, "fallback: Playwright image". The baseline was actually measured with host Chrome against `nav-web-lh` (`frankenphp php-server`, compression on) through `scripts/lighthouse-web.sh`. `baseline.md` §4 says `php -S` without compression scored about 20 points lower, and "QA-03 must use the same script". QA-03 only says "exactly as P0-02 b5".
**Fix.** Rewrite b5 and QA-03 to name `var/navigation-delivery/scripts/lighthouse-web.sh` + `lighthouse_runs.py 5` (frankenphp php-server, production build, host Chrome, same machine), so QA-03 cannot compare across methods.

### F20 · minor · feature flags documentation — resolved — LAUNCH-00, GEST-14, GEST-15 add their `feature_flags.md` rows in their commits; DOC-01 re-checks
**What is wrong.** `NavigationLaunch::DATE` (phase 1), `INSTANT_BACK`, `INSTANT_BACK_IOS` and `KEEP_BFCACHE` (phase 15) reach `docs/features/feature_flags.md` only in DOC-01 (phase 17). CLAUDE.md: "Always read and update this file when adding, modifying, or removing feature flags."
**Fix.** LAUNCH-00's commit adds the `DATE` row, and GEST-14/15's commits add the three constants with removal criteria. DOC-01 only re-checks them.

### F21 · minor · ResetInterface test scope — resolved — §3.1 (`EXTRA_CLASSES` list), decision 103
**What is wrong.** `NavigationServicesResetTest` reflects over `src/Navigation/` and `src/Twig/Navigation*` (§3.1). MENU-01 adds a per-request memo to `src/Twig/AdminQueueCountsTwigExtension.php` ("memoises per request (`ResetInterface`)"). Other new stateful-looking services live outside the scanned paths: `src/Services/PlayerResults/PlayerResultsBuilder.php`, `src/Services/Community/CommunityPreview.php`, `src/Twig/InboxTwigExtension.php`, `HelpTwigExtension`, `AddEntryTwigExtension`. A leak there cannot show under `php -S`.
**Fix.** Give the test an explicit additional class list (every class the delivery creates or gives a memo), or keep every memo inside `src/Navigation/`.

### F22 · minor · PROF-05a wrapper vs deliberate changes — resolved — PROF-05a (wrapper calls the builder in `legacy` mode; builder tests pin the new semantics), §6 component-tests row, decision 97
**What is wrong.** PROF-05a extracts the logic into `PlayerResultsBuilder` with two deliberate changes: By puzzle leads with the best attempt, and comparators end on a stable id. It also makes `PlayerSolvedPuzzles` "a thin wrapper over the builder", and claims "component tests stay green against the wrapper; only the two deliberate changes differ". If the wrapper uses the builder, the component tests that pin the old attempt choice or tie order fail. If they stay green, the wrapper does not use the new builder behaviour.
**Fix.** Pick one. Either the wrapper calls the builder with a `legacy` mode that keeps today's semantics for one release, so the component tests really stay green. Or the component tests are updated in the same commit with the intent change logged (D11: "date of the best time"), not presented as unchanged.

### F23 · minor · DraftVisibilityCoverageTest and the organiser EXISTS — resolved — MENU-01 tests (`NOT_FILTERED` + `self::OWN` for `OrganizerSql.php`, `GetPlayerProfile.php`), §6 row
**What is wrong.** MENU-01 adds `src/Query/OrganizerSql.php` and embeds its fragment in `GetPlayerProfile::byUserId()`. The fragment reads `competition`, `competition_series` and `organization` with drafts deliberately included. `tests/DraftVisibilityCoverageTest.php` fails on every file reading those tables "without a word about drafts" unless it is listed in `NOT_FILTERED` with a reason (the `OWN` reason fits). The plan does not mention it.
**Fix.** Add `OrganizerSql.php` and `GetPlayerProfile.php` to `NOT_FILTERED` with `self::OWN` in MENU-01's commit, and list the test under MENU-01's existing-test changes (§6).

### F24 · minor · guest intent of `HubControllerTest::testHubStaysInTheNavigation` — resolved — NAV-10 tests, §6 HubControllerTest row (guest `nav-search.json` has `hub_guest`; LAUNCH-02 row resolves to `hub`)
**What is wrong.** The test is a guest request (`/en/ladder`, asserts exactly one `a[href="/en/hub"]` in the navigation). The plan (§6, NAV-10) rewrites it to a signed-in assertion and makes the guest Hub search-only (R11). The guest-side intent, that a guest can still reach the Hub from the navigation, is not carried over in any form, which comes close to "deleted or weakened".
**Fix.** Keep a guest assertion with the new mechanics. Guest `/en/nav-search.json` contains `hub_guest` (route `hub`), and the guest "Where did it go?" mapping row for the old hamburger "Hub" link resolves to `hub`. Log the D5-driven change of the visible link.

### F25 · minor · PROF-04 Statistics budget — resolved — PROF-04 tests (mandatory pin created in the PROF-04 commit — a pin raised later would break "only down"; pre-counts and moved parts recorded), decision 98
**What is wrong.** PROF-04 moves the average-time chart and the whole `.profile-standing` block (rating/skill cards) onto Statistics. Statistics has no statement pin today (`tests/Controller/PlayerStatisticsControllerTest.php` asserts none), and the plan's pin is "Optional".
**Fix.** Make `PlayerStatisticsQueryBudgetTest` mandatory. Pin it at today's count before PROF-04 (own/other × guest/member) and at the measured count after, with a comment naming the parts moved from the profile. Check that the profile's drop (PROF-03) is at least the parts moved.

### F26 · minor · native `menu` page vs `NavChromeAssertions` — resolved — §3.5, NAV-14 helpers, MENU-02a (positive `main #site-menu`), decision 103
**What is wrong.** §3.5/NAV-14 put `#site-menu` among `WEB_CHROME_SELECTORS` that `assertNoWebChrome()` forbids for native user agents. MENU-02a/NAV-14 also require the native `menu` route to render "rows in `<main>`", and MENU-02a gives that partial the id `site-menu`. The shared helper would fail on the very page NAV-14 wants to be 200 with content.
**Fix.** Scope the selector to `#site-menu:not(main #site-menu)` (or assert absence outside `<main>`), and add the native `menu` row with a positive assertion that `main #site-menu` exists.

### F27 · minor · live results history rewrite unchecked — resolved — GEST-01 tests (`checks/GEST-01-live-results.py`)
**What is wrong.** GEST-01 replaces `live_results_controller.js`'s own `pushState({liveResults})` + `Turbo.session.history.stop()` (lines ~162-179, ~1274-1316) with `layers.open('live-results')`. It is used by referees on event day; only `LiveResultsScriptsTest` (pure parts) is named, and no behaviour check exists.
**Fix.** Add a `checks/GEST-01-live-results.py` WebDriver case on a fixture or clone competition: open a view over the find view → `history.back()` returns to find without leaving the page (probe); leaving with unsent outbox items still asks (in-page dialog after GEST-13); `Esc` closes the view.

### F28 · minor · stale references in the plan — resolved — header, P0-01 Files (drafts in `var/navigation-delivery/plan-drafts/`), MENU-01 Menu data (3): n = existing `EventsViewerData::organizedCount()` (it exists, `src/Results/EventsViewerData.php:128`; only `PlayerReviewCounts::total()` was missing and is added with a test), decision 103
**What is wrong.**
- The header and §1 say the drafts are "in `delivery/plan-drafts/`", and P0-01 says to move them. They already live in `var/navigation-delivery/plan-drafts/`, and `docs/features/navigation/delivery/` has no `plan-drafts`.
- MENU-01 cites `GetEventsViewerData::forPlayer()->organizedCount()` and `GetPlayerReviewCounts::forPlayer(…)->total()`. Neither method exists (`src/Results/EventsViewerData.php` offers `organizedCompetitionIds()` etc.; `src/Results/PlayerReviewCounts.php` has `none()`/`isEmpty()`). The meaning of "Events you organize (n)" (events only? series and organizations too? upcoming only?) is therefore undefined.

**Fix.** Correct the path. In MENU-01, define n (recommended: the same set "You organize" counts on `/en/you-organize`) and name the methods to add, with tests.

## Checked and found fine

- **P0-01 coverage script** (plan §4 P0-01 form) prints nothing. All 107 requirement IDs also have their own `###` section.
- **Hard phase rules:** the old chrome is removed in phase 7, right after the NAV phase. NAV-12 is re-run at every later gate. `base.html.twig` has one writer at a time. GEST-13 lands in the Layers phase before any new layer, and `INSTANT_BACK_IOS` is gated on it. Apart from F1, F2 and F13 the dependencies hold.
- **Every other verify-after** (F-03, F-04, NAV-14, MENU-02a, MENU-02b, HELP-01b, INB-01, ADD-03, GEST-02, PROF-05c) matches the requirements.
- **GEST-13 inventory** matches the code exactly:
  - 16 template sites in 11 files, plus 4 `/admin` hits in 3 files;
  - controller sites `stopwatch:168`, `round_seating:325/736`, `results_desk:1118/1379`, `live_results:167/1578/1588`, `sheet_save_queue:1178`, `participants_sheet_controller:160`;
  - method definitions `copy_link_controller:29`, `preview_dialog:210`, `sheet_model:1154`.
- **Spot-checked citations** are accurate at `524a3305`:
  - `base.html.twig:699-734` (`menu.new_badge`), `:774-789` (two badge counts), `:818-819` (literal "Marketplace");
  - `app.js` restore handler;
  - `pwa_lifecycle_controller.js:73-77, 236-257, 299-303`;
  - `app.scss:170-176` (standalone-only overscroll);
  - the `form-switch` count: 24 in 11 templates;
  - existing routes already use Japanese paths (`/ja/通知`), so the new ja paths are consistent.
- **Turbo behaviours the plan relies on**, verified in 8.0.23 (pinning aside, F5):
  - link marking in `turbo:click` is read synchronously afterwards;
  - `currentVisit.action` set at `turbo:visit` is honoured, because history changes at render;
  - the view-transition decision reads the live meta at render (decision 68 is right);
  - the card's bubble `click` listener runs before Turbo's per-click re-registered listener, so `preventDefault()` wins.
- **Budgets:**
  - INB-02's −1 matches P0-02's breakdown (toolbar badges = 2 statements).
  - The conversations count already counts accepted conversations only, so adding pending requests double-counts nothing, and the profile row already carries `hidden_player_ids`.
  - INB-04b keeps the Hub pin: `ofPlayerFavorites()` runs 2 statements today, 1 with ids from the profile row.
  - `menu_counts` stays ≤ 2: `GetEventsViewerData::forPlayer()` and `GetPlayerReviewCounts::forPlayer()` are one statement each.
  - COM-01's member ceiling of 6 is reachable: 3 chrome after INB-02 + 3 one-statement previews.
  - A second `GlobalSearch` with an empty query runs no statement.
- **Caching:** `AnonymousCacheHeadersSubscriber` already skips any request with a `Turbo-Frame` header, so the help frame can never become `public`. Native-UA handling is a clean early return. Guest chrome stays URL-derived.
- **Data:** the custom-index workflow (hand-written migration + `createCustomIndexes()` in `tests/bootstrap.php` + `docs/database-indexes.md`) matches CLAUDE.md, and the generated migrations (INB-04a, ADD-01) are additive and nullable.
- **CLAUDE.md digest (§3.1):** single-action controllers; writes through Messenger handlers (INB-01 mark-read, INB-04a, ADD-01); `ClockInterface` for freshness; `ReturnUrl` for `?return=`/`?url=`; Turbo form rules.
- **Environment safety:** the dev mailer goes to the local mail catcher (`.env` `smtp://mailer:1025`, no override in `.env.local`), so the HELP-01b/HELP-05 sends on the clone e-mail nobody.
- **README §4.10 (multiscan):** the multiscan action bar is "always visible" (`components/MultiscanTray.html.twig:165`), so hiding the bottom bar on the member multiscan page (decision 46) matches.
- **Privacy:** no business numbers or real names in the plan; the drafts with production figures stay git-ignored.
