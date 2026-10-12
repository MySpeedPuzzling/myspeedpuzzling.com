# Phase review: Layers

- **Scope:** commits `34a1361d` (GEST-01: `assets/layer_history.js`; the dynamic modal, live results and achievement sheet moved onto it; Turbo pinned to 8.0.23 with a contract test), `2f8235c0` (GEST-13: in-page confirmations), `b01dbdc3` + `f836d43c` (GEST-02: bottom sheets).
- **Checked against:**
  - `requirements.md`: Conventions (incl. the reload-and-history probe) and the Done when / Verify of GEST-01, GEST-02 and GEST-13.
  - `README.md` §4.13: G1, G2, G13 and hotwired/turbo#637.
  - `README.md` §4.14: no history entry inside the app.
  - `implementation-plan.md`: §3.4 layer checklist, the Layers section (GEST-01/02/13) and decisions 58, 60, 92–94, 96, 102.
  - `CLAUDE.md`: Turbo rules and translations.
- **Method:**
  - Read the four diffs, the new modules, the controllers, templates and SCSS they touch, and the harness case lists.
  - Checked the Turbo internals the helpers rely on against `node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js` (8.0.23): `History`, `onPopState`, `historyPoppedWithEmptyState`, `FormSubmission#start` / `config.forms.confirm`, `FormLinkClickObserver`, `Navigator#proposeVisit` and the frame interception rules.
  - Re-ran the GEST-13 greps (confirm / alert / prompt) by hand and walked every `data-turbo-confirm` site.
  - No tests, builds or docker commands were run (the gate is using the shared test DB).
- **Result:** 0 blocker · 1 major · 8 minor.

## Findings

### F1 · major · `assets/layer_history.js:675-699` (`onTurboVisit`), `:716-761` (`onDocumentClick`), `:451-458` (native branch of `open`) · resolved — `releaseTop()`: a visit, a non-Turbo link (at `pagehide`) and a page render that took the layer's element (`turbo:render`) release the top layer with or without an entry; the web push path tells an entry-less previous layer (`swap`)
**Problem.** Inside the native app, a layer that is left by a page visit stays registered as the top layer.
- In the app, `open()` takes the `'none'` path, so `top = layer` with `entry: false`.
- `onTurboVisit` and `onDocumentClick` only release `top` when `holdsEntry()`. In the app `holdsEntry()` is always false, so leaving a page while a dynamic-modal layer is open never clears `top`.
- The harness case `'native app'` (`tests/layer-history-harness.mjs:711`) never leaves the page, so this path is untested.

**Reproduction.** In the Hotwire Native app (one shared WebView, so module state survives visits):
1. Open the result detail sheet, which has `data-modal-history`.
2. Tap the puzzle or player link in it (`data-turbo-frame="_top"`). A Turbo visit runs, and `top` still points at the old `'modal'` layer, with `onClose` bound to the disconnected controller.
3. On the next screen, open any dynamic modal and tap ×.
   - `handleHide` sees `layers.isOpen('modal')` = true and calls `preventDefault()` (`dynamic_modal_controller.js:391-399`).
   - `requestClose` then finishes the stale layer: it calls the old controller's `closeUi()` on a detached element.
   - The visible modal stays open. The second tap closes it.

The same stale state causes three more problems:
- A swipe-down on a sheet does nothing and springs back after 1 s (`bottom_sheet_controller.js:269`).
- Esc is consumed.
- `isOpen()` reports true, which GEST-03, the pull guard and CARD rely on.

This is a regression for the existing app (README §4.14: "a layer closes without history.back()" must still close).

**Same root cause on the web (latent).** A full-page form inside a layer that answers 422 or 500 re-renders the page without `turbo:visit`. `top`, the paused Turbo history and the layer entry all survive, and the next Back "does nothing". No history layer holds a page form today; the Help sheet's form is a frame form.

**Fix.**
- In `onTurboVisit`, release `top` whatever `entry` is: resume only when it held an entry, and always call `onClose({reason:'leave'})`. Do the same in `onDocumentClick`.
- In `open()` on the web, call `onClose('swap')` for an entry-less previous `top` instead of dropping it silently (`:478-487`).
- On `turbo:render`, release a `top` whose `element` is no longer connected.
- Add harness cases "native: leave from a layer → `isOpen()` false, next open/close works" and "native: swipe close", and add the GEST-02 native row (plan §NAV-14: "GEST-02 closes via GEST-01").

### F2 · minor · `assets/confirm_dialog.js:400` vs `assets/layer_history.js:536-538` · resolved — `layer_history` dispatches `msp:layer-closed` (`LAYER_CLOSED_EVENT`) on every close; the confirm answers false on it
**Problem.** The comment in `confirm_dialog.js` says "a popstate … answers an open one false". That listener never runs when a layer close takes the pop:
- Both listeners are window-capture listeners.
- `layer_history` registers first (imported at `app.js:35`; the confirm dialog is installed at `app.js:48`).
- `layer_history` calls `stopImmediatePropagation()` on a layer's pop.

Inside a Bootstrap modal the `hide.bs.modal` listener covers this. For a confirm hosted in `<body>` over a non-Bootstrap layer it does not. Example: the live-results discard confirm opened from the sheet overlay, then an iOS edge-swipe Back. The view closes under a confirm that is still open and still actionable.

**Fix.** Pick one:
- `layer_history` exports an `onLayerPop` hook (or dispatches `msp:layer-closed`) that `confirm_dialog` subscribes to.
- `confirm_dialog` cancels on the layer's `onClose` path.

Add a node case "confirm open + layer Back → resolved false".

### F3 · minor · `assets/layer_history.js:353-364` (`restoreVisit`) with the guards at `live_results_controller.js:173`, `results_desk_controller.js:1384`, `round_seating_controller.js:328`, `participants_sheet/sheet_save_queue.js:1183` · resolved — `restoreVisit` starts the restore through `navigator.startVisit` (no `turbo:before-visit`, like Turbo's own popstate restore; falls back to `session.visit` with one warning); pinned in `TurboContractScriptTest`
**Problem.** `restoreVisit` (a layer pop to another URL, or decision 93) calls `session.visit(…, {action:'restore'})`. That goes through `Navigator#proposeVisit` and fires `turbo:before-visit`. A Turbo popstate restore does not.

On the live results page with a view open and unsent results, a long-press Back to an earlier page goes wrong:
- The URL has already changed.
- `guardLeave` prevents the restore, so the live-results DOM stays under the other page's URL.
- "Leave" then runs `leaveTo(url)` as an **advance** visit, which pushes a duplicate entry.

Before GEST-01 this case was a Turbo restore. `app.js` turned it into a reload, and `beforeunload` asked where the browser supports it.

**Fix.** Start the restore without the before-visit proposal:
- Use `session.navigator.startVisit(url, restorationIdentifier, {action:'restore', historyChanged:true})` (feature-detected, falling back to today's call).
- Or have `guardLeave` let a visit to `location.href` through, since that is a traversal that already happened.

Add a harness case.

### F4 · minor · `assets/layer_history.js:508-518`, `:410-425` · resolved — after a fallback settle the next popstate onto the same page within 2 s is swallowed and Turbo's index synced
**Problem.** If the `history.back()` of a close lands after `CLOSE_FALLBACK_MS` (400 ms; this can happen on a loaded phone, since Chrome's traversal is a browser-process round trip):
1. `settleClose` finishes the layer and restarts Turbo.
2. The late popstate then reaches Turbo's own listener (`top` is null and `owners` is empty).
3. Turbo starts a restore visit, and `app.js:60-71` turns it into a full reload of the same page.

The probe then fails: `timeOrigin` changes. This is rare, but it is a reload nobody asked for.

**Fix.** After a fallback settle, remember `{href, until: now + 2 s}`. Swallow (`stopImmediatePropagation`) the next popstate onto that same-URL page entry within the window, keeping Turbo's `currentIndex` in step. Add a harness case for a "late pop".

### F5 · minor · `assets/layer_history.js:553-561` · resolved — a pop onto a dead layer entry is always stopped, and the Back from it onto the same page's entry is swallowed (Turbo's index synced)
**Problem.** The sequence open a layer → Back (closes) → Forward → Back causes a full page reload. Here is why:
- On the Forward, `owners` is empty, so the pop onto the dead `{mspLayer}` entry falls through to Turbo.
- Turbo's `historyPoppedWithEmptyState` rewrites that entry into a fresh `{turbo}` entry (`currentIndex++`).
- The next Back is then a normal Turbo restore, which `app.js` reloads.

D-4 (not reopened) holds, but the probe after Forward + Back does not: the page reloads, and history now holds `[A, A′]`.

**Fix.** On a pop onto a dead layer entry of the current URL:
- `stopImmediatePropagation()` and leave the entry alone, or `replaceState` it with the page's noted turbo state.
- Treat the next pop between it and the page entry (same URL) as a no-op that is swallowed.

Add a "Forward then Back" harness case and a WebDriver line (the GEST-01 check already does Forward).

### F6 · minor · `assets/layer_history.js:716-761` · resolved for links and GET forms — same-origin links Turbo does not visit (its `unvisitableExtensions`) and `data-turbo="false"` GET forms are replaced like `data-turbo="false"` links, and the layer is released only at `pagehide` (a download leaves it open with its entry); `data-turbo="false"` **POST** forms: open — not reached (time box; none sits in a history layer today)
**Problem.** Rule (d) covers Turbo visits and `data-turbo="false"` / external **links**. Other non-Turbo navigations from inside a layer still leave `[A, A′, B]`:
- **`data-turbo="false"` forms.** This is already noted in state.md.
- **Same-origin links Turbo does not visit**, because `locationIsVisitable` rejects non-HTML extensions (`.pdf`, `.csv`). They return early at `:750`, and the browser pushes.
- **A `data-turbo="false"` link whose response is a download.** `location.replace()` never commits, but the layer has already been released and its entry rewritten into a Turbo entry. The UI stays open without an entry, and history keeps a duplicate.

None of the three history layers contain these today, so this is latent until the Help sheet, Menu and card ship.

**Fix.**
- In `onDocumentClick`, also treat a same-origin link that Turbo would not visit (mirror `locationIsVisitable`) as `location.replace`.
- Add a `submit` listener that does `location.replace` for GET forms, and for POST forms replaces the entry (`history.back()` first, then submit).
- Release the layer only once the navigation is actually committed (`pagehide`).
- Or record these cases as accepted exceptions in the plan, with the layer items asserting none exist.

### F7 · minor · `assets/controllers/bottom_sheet_controller.js:139-163` · resolved — `pointerReleased()` (sheet_drag.js): a mouse `pointermove` with no button held cancels the gesture
**Problem.** For a mouse on a narrow window (< 576 px; `active()` checks width only):
1. Press on the handle and release outside it before the 8 px slop. There is no implicit capture for a mouse, so no `pointerup` arrives.
2. The controller is left in `pending` with `pointerId` = 1. Further `pointerdown`s are ignored (`:140`).
3. Hover `pointermove`s with the same id feed `drag.move()`. Moving down 8 px from the stale start "drags" the sheet with no button pressed, and nothing releases it.

**Fix.** Pick one:
- In `onPointerMove`, cancel when `event.pointerType === 'mouse' && event.buttons === 0`.
- Or call `setPointerCapture` on `pointerdown`. That is harmless now that the handle's controls are excluded by `canStart`.

### F8 · minor · `assets/sheet_drag.js:218-220` with `:135-148` · resolved — the release thresholds measure the finger's travel (`SheetDrag#travel`): a 100 px pull closes, a flick needs > 24 px of travel
**Problem.** README G2 says "closes when pulled > 96 px … flicked … after > 24 px", but the decision is taken on `offset`, which is the finger's travel minus the 8 px slop. So:
- A 100 px pull springs back.
- A flick needs more than 32 px of finger travel.

The Verify's 120/60/40 px cases still pass. The threshold differs from the README (and from what the Help sheet and card re-runs will assume).

**Fix.** Pick one:
- Compare the finger's travel (`y - startY`) in `releaseDecision` while the sheet still trails it by the slop.
- Or state in README G2 and the plan that the thresholds apply to the sheet's offset.

### F9 · minor · `assets/layer_history.js:273-289` (`identity`), `:281-285` · resolved — `open()` records the Turbo identity of the entry a layer entry is pushed on; `identity()` restarts one above it when on a layer's entry
**Problem.** `noted` is taken at the **first** pause. When a page owner (Results) has already pushed entries and then a layer opens and is left by a visit:
- `identity()` compares `noted.href` (Results' URL at its first pause) with the current URL. After a sort change they differ, so it returns `null`.
- `turboHistory.start()` then reads `history.state.turbo` from the `{mspLayer}` entry, which has none, so `currentIndex = 0`.
- Even when the URLs match, `noted.currentIndex + 1` ignores the owner's pushed entries.

Turbo's index is then wrong for the rest of the session. The visit direction and GEST-15's restore logic read it.

**Fix.** When `open()` pushes a layer entry, record the current entry's `turbo` state (`restorationIdentifier`, `restorationIndex`) on the layer. `identity()` should use that base + 1 when it is on the layer's entry. Add a harness case "layer over page owner (after `pushPageState`) → leave by link → `currentIndex` = owner's index + 1".

## Checked and fine

**Turbo internals (decision 92).** Each internal the helpers use exists in the pinned 8.0.23 dist:
- `History.start()` reads `history.state.turbo.restorationIndex` and `replace()`s with a fresh uuid. The helper's identity restore afterwards keeps the id and the index.
- `onPopState` takes the `historyPoppedWithEmptyState` path for state without `turbo`.
- `changeHistory` runs at render, after `turbo:visit`, so the safety net's `currentVisit.action = 'replace'` is in time. That includes the preloaded-response path used by frame-missing, because `render()` waits a frame.
- Missing `currentIndex`/`replace` falls back to a plain `start()`, and missing `navigator.currentVisit` means no safety net. Each warns once.

**Popstate ordering.**
- `layer_history` installs its window-capture listener at import time. That is before Turbo's non-capture listener, `nav_instrumentation`'s capture listener (started in the `app.js` body) and the confirm dialog's.
- Layer closes are therefore invisible to Turbo, the Back logging and page handlers. Pops onto a page owner's own entry are passed through (`ownersEntry`).

**(b) and (c).**
- A swap is a `replaceState` with no `back()`.
- An open during a pending close waits for the pop, capped at 300 ms.
- `requestClose` is deduplicated, so Bootstrap's own Esc (`hide` prevented → `close()`) and the helper's document Esc produce exactly one `history.back()`. The dynamic modal's own Esc handler steps aside while it holds the entry.
- A state mismatch closes directly.

**(d) marking.**
- Links: `followedLinkToLocation` reads the link's action synchronously right after `turbo:click`.
- Forms:
  - They are marked at `turbo:submit-start`, which comes after the confirm.
  - Frame forms are recognised by the `Turbo-Frame` header that `FrameController#prepareRequest` sets before `start()`.
  - The marker survives `turbo:submit-end`: there is no submit-end listener, and it is restored at visit / load / the next click or submit.
- `startsPageVisit` mirrors `FrameController#shouldInterceptNavigation` and `FrameRedirector` (`_top`, a named enabled frame, the closest frame unless `target="_top"`). Frame links are never marked.
- A same-page link inside a layer closes the layer first and then runs a replace visit.
- `data-turbo="false"` and external links use `location.replace`. Modified clicks, `target≠_self` and `download` are left to the browser. Leaving `download` alone is a sensible reading of the plan's wording, because a download does not navigate.

**Dynamic modal rewrite.** The `modal-frame` partials without `data-modal-history` (all but the three below) behave as before:
- They close through Bootstrap, with no layer involved.
- A stream that empties the frame → `close()` → `closeUi()`.
- Frame-missing → `detail.visit` when no layer is open.
- The spinner, error and Try again paths are untouched.
- `handleHide` intercepts only while the modal holds the entry.

The three history contents (achievement, result detail, Help):
- They claim the entry at frame load. The claim happens at `show.bs.modal` only for a swap.
- They close through Back.
- `_top` links inside them are replace visits.
- The result detail's photo link (`target="_blank"`) is left alone.

**Live results.**
- Its own `pushState`/`stop()` and popstate code are gone.
- Esc steps back one level through a capture listener that prevents the helper's bubble Esc.
- Back closes a view or overlay, and releasing the view uses `requestClose`.
- The round switch goes through `visitFromLayer`.
- The leave guard is now async.

**GEST-13.**
- **Conversions.** All 16 sites are converted, and each of the 15 `data-turbo-confirm` attributes sits on a `<form>` or a submit `<button>` that Turbo submits. Each tag and its ancestors were walked: none is under `data-turbo="false"`, and none is a plain `<a>` (Turbo ignores `data-turbo-confirm` on links without `data-turbo-method`). The static guard checks the tag and the enclosing form, and three rendered-page tests check ancestors.
- **Live buttons.** They go through `confirm-action` (made eager, as recorded in state.md). `stopImmediatePropagation` stops the later Stimulus bindings, and the re-click carries a one-shot pass.
- **Controllers.** The 5 controller confirms and 4 leave guards are converted, with the event fields read before the `await`.
- **Allow-lists are honest.**
  - A broad grep finds only the 4 admin confirms and the 3 method definitions.
  - `alert(` appears only in `native_billing_controller.js`, which runs in the app only.
  - The scanner notice key is present on every `barcode-scanner` root.
- **The dialog itself.**
  - It is cloned from the template in `base.html.twig`; all converted pages extend base.
  - It is opened with `showModal()` and appended to the `.modal` host, not `.modal-dialog`, so `pointer-events` is fine. It sits in the top layer above sheets and bars.
  - Esc is taken at window capture before the helper, Bootstrap and page handlers. Inside the dynamic modal this means Esc closes only the dialog.
  - The scrim needs both press and release on the backdrop.
  - Focus goes to Cancel when `danger`, otherwise to the action, and returns to the trigger (falling back to a dropdown's toggle).
  - Messages are plain `textContent`.
- **Watchdog.**
  - It wraps `window.confirm` and reports a `false` within 50 ms only on `os() === 'ios'`, outside the app, once per page view (reset at `turbo:visit`).
  - `afterRestore` follows decision 102.
  - `NavClickController` logs `dialog_suppressed` at info on the nav channel.
- **Translations.** `confirm_dialog.{cancel,confirm}` and `barcode_scanner.no_camera` exist in all 6 locales with one top-level key each. German uses "Sie", French uses "vous" (implicit in "Veuillez"), Spanish uses "tú". No value equals the English one.
- **Guest HTML.** The template carries no per-visitor content, so guest HTML stays cacheable.

**GEST-02.**
- **Rules.** 8 px slop, |dx| ≤ 0.5·dy, flick speed over the last 100 ms, spring-back 200 ms, out 200 ms.
- **Where a drag starts.** `canStart` blocks scrollable or selected textareas, inputs, selects and contenteditable everywhere, controls on the handle, and content scrolled away from its top (walked up to the `.modal-body` scroller).
- **Input split.** The handle uses pointer events with `touch-action: none` (phones only). The body uses touch events with `preventDefault` only once dragging starts.
- **Holo medal.** Its tilt is mouse/pen only and the medal has `touch-action: pan-y`, so the slop comes first. The inline transform is kept until `hidden`.
- **Reduced motion.** No transition in JS or CSS.
- **Closing.** The 1 s spring-back fallback is in place, and closing goes through `bottom-sheet:dismiss` → GEST-01 (c).
- **Drafts.** sessionStorage access is wrapped in try/catch.
- **Scope.** The CSS is scoped to the phone media, so desktop modals are unchanged. Every listener is removed on disconnect.
- **SCSS.** The duplicate import is gone, and `_bottom-sheet` comes before `_dynamic-modal`.

**Leaks.** Nothing is left behind across Turbo visits:
- `layer_history` and `confirm_dialog` install once.
- The per-dialog keydown listener is removed on every answer path.
- `leaving` and the markers are cleared at `turbo:load`.
- The controllers clean up on disconnect.

The only state that outlives a visit is F1's `top` inside the app.

## Fixes (branch `nav/layers-fix`, 2026-10-12)

Every finding has a node-harness case that fails on `a9046ac9` and passes after the fix (the old modules run against the new harness):

| Finding | Harness case (test) | Before → after |
|---|---|---|
| F1 | `native leave`, `render took the layer`, `entry-less layer replaced` (`LayerHistoryScriptTest`) | after a visit `isOpen` true → false; render left the layer + Turbo paused → released, Turbo restarted; previous entry-less layer dropped silently → told `swap` |
| F2 | `a layer closed by Back resolves false` (`ConfirmDialogScriptTest`, real `createLayerHistory` in jsdom) | answer pending, dialog left → false, removed |
| F3 | `restore after a layer pop skips the leave guard` (`LayerHistoryScriptTest`); `startVisit` fact (`TurboContractScriptTest`, real Turbo 8.0.23) | guard asked, no visit → no guard, one restore visit |
| F4 | `late pop` | a restore visit of the same page (app.js reload) → none, Turbo's index synced |
| F5 | `forward then back`; `dead entry ignored` now expects `emptyPops: 0` | restore of the same page + rewritten entry → none |
| F6 | `non-Turbo links` (`unvisitable extension`, release at `pagehide`), `non-Turbo forms` | `.pdf` link left to the browser (push) → replace; GET form pushed → replace |
| F7 | `testAMouseMovingWithNoButtonHeldEndsTheGesture` (`SheetDragGestureTest`) | — (no rule) → `pointerReleased()` cancels |
| F8 | `a 100 px pull`, `just over 96 px`, `96 px exactly`, `a flick of 30 px`, `a flick that ends at 24 px` | 100 px / 97 px / 30 px flick sprang back → close |
| F9 | `layer over page owner left by a link` | Turbo index 0 → owner's index + 1 |

Browser: GEST-02 got a native-app part (`run_native`: the test sheet page rendered for the Hotwire Native UA - in the app the achievement and result sheets are native screens through `layer_frame()`; no history entry on open, swipe closes, and after a Turbo visit started while a sheet was open the next sheet closes on the first swipe and on the first tap on ×). GEST-01 and GEST-13 now also write their test pages into the worktree that `SITE` serves (as GEST-02 did).

Re-run on the fixed build (nav-web-c): GEST-01 52/52, GEST-02 52/52 (incl. the 6 native-app rows), GEST-13 49/49. The native-app rows fail on the `a9046ac9` build (the next sheet after a visit from an open sheet does not close on the first swipe), so they guard F1 in the browser too.
