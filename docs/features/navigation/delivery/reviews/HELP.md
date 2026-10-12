# Phase review: HELP (HELP-02 … HELP-05)

- **Scope:** commits
  - `34ea0d9b` (HELP-02…05): `HelpSheetBuilder::items()`, `HelpSheetItem`, `HelpSheetItemKind`, `HelpResponseBuilder`, `HelpPageContextFactory` (raw `" < > \`` and space refused), `NavigationTwigExtension::languageSwitchUrls(context)`, the registry's Help sheet positions and the `suggest_correction` entry, `FeedbackController` (`NO_PAGE`), `FeedbackFormType` (view transformer), `templates/help/*`, the `page_help_line` block in `base.html.twig` and the five templates that empty it, `dynamic_modal_controller.js` (HELP-05), `assets/head/standalone.js`, 7 `help.*` keys × 6 locales, and the tests;
  - `31c687d4` (drops the Help sheet's scoped `.standalone-only` rule);
  - `be7b5c7c` (NAV-03) only where it touches the Help sheet: the top bar's `?` opener and the shared `standalone.js`.
- **Checked against:**
  - `requirements.md`: Conventions, and the Done when / Verify of HELP-02, HELP-03, HELP-04 and HELP-05.
  - `README.md` §4.1 (installed-app state, `standalone-only`), §4.5 (order, sheet transport, fix, end-of-page line), §4.10 (pages without bars) and §4.14. D1–D22 not reopened.
  - `implementation-plan.md`: §3 (cross-cutting rules, `data-nav`, layer checklist, translations, budgets, guest caching, privacy), the HELP-01a/01b/02/03/04/05 sections, §7 (Help sheet transport) and decision 56.
  - `var/navigation-delivery/HELP-05/repro.md` (the grey-screen reproduction and its six recommended steps).
  - `CLAUDE.md`: Turbo form rules, single-action controllers, `ResetInterface`, translations in 6 locales, guest caching, `ReturnUrl::tryFrom()`.
- **Method:**
  - Read `git diff 421d8fd5..34ea0d9b` and `git show 31c687d4`, plus the current state of every file they touch.
  - Read the code they call:
    - `layer_history.js`: `requestClose` (double-close guard), `visitFromLayer`, `closeThen` and `onDocumentClick` (`defaultPrevented`);
    - Turbo 8.0.23's `FetchRequest.receive`, `FormSubmission.requestPreventedHandlingResponse` and `FrameController.formSubmissionFailedWithResponse`;
    - `LoginEntryPoint` and `LoginFormAuthenticator` (`return`);
    - `ProposeChangesController` (frame and full-page answers);
    - `CachedXpLaunchReadiness`, `AnonymousCacheHeadersSubscriber` and `NavEntry::isInPageSearch()`;
    - the prod error templates, and `config/packages/prod/routing.php` (`strict_requirements: null`).
  - Bootstrap interplay:
    - Listed every `modal-frame` partial (34) and every one that opens another Bootstrap modal: `collections/modal`, `collections/move_modal` and `sell-swap/modal` (`data-bs-toggle` + `data-bs-dismiss`).
    - Traced the `sweepOrphans()` timeline for those three buttons against Bootstrap's show/hide order, with and without reduced motion and with a held history entry.
    - Grepped for every stream that targets `modal-frame`: only `_modal_close_stream.html.twig` does.
  - Translations: parsed all 6 `messages.*.yml` with a duplicate-key-checking YAML loader.
    - All 7 keys exist in every locale, and there are no duplicate keys or mappings.
    - Tone: de "Sie", fr "vous", es "tú", cs informal like the other `help.*` keys.
    - No value equals English. fr uses plain spaces before "?", as the rest of its catalogue does.
  - No tests, builds or docker commands were run (instructions: the browser checks and the gate share the machine).
- **Result:** 0 blocker · 0 major · 5 minor · 5 questions.

## Findings

### F1 · minor · `assets/controllers/dynamic_modal_controller.js:277-284` (released branch of `handleBeforeFetch`), `templates/help/_content.html.twig:88-95` (correction row)
**Problem.** After a send, the Help sheet is released: no scrim, `aria-modal` removed, `.is-released { pointer-events: none }`, and the page scrolls. The sent state keeps every other item, including the new "Suggest a correction to this puzzle" row (`FeedbackControllerTest` asserts it is there). For signed-in players that row stays in the frame (`layer_frame('modal-frame')`), but the reclaim added by review F5 runs only for a trigger **outside** the modal.

So the puzzle's proposal form (`puzzle-report/modal.html.twig`, a large form with photo upload) loads into a released modal:
- `open()` resets the layout (that content has no `data-modal-size`), so on phones it becomes a centred dialog with no scrim;
- it is not `aria-modal`, and the page behind scrolls and takes taps;
- an outside tap does nothing to the dialog (`handleModalClick` skips while `released`) but reaches the page behind. A page link there leaves, and the typed proposal is lost.

**Reproduction.**
1. Signed in, at 360, open a puzzle page and open Help from `?` or the end-of-page line.
2. Send feedback. The sheet shows "Thanks" and is released.
3. Tap "Suggest a correction to this puzzle". The proposal form opens with no scrim, `#dynamic-modal-container` keeps `.is-released`, `aria-modal` is absent, and the page scrolls behind it.

**Fix.** In the released branch, also reclaim for a GET navigation inside the frame:
- `method` is already computed above that branch. Use `const outside = trigger !== null && !this.element.contains(trigger); if (outside || method === 'GET') { if (outside) { this.trigger = trigger; } this.reclaim(); }`. An in-sheet link keeps the original opener as the focus target.
- The only in-frame GET link of the sheet is the correction. The form POST ("Send another") stays released.
- Add a row to `checks/HELP-05.py`: "send → Suggest a correction → scrim back, `aria-modal="true"`".

### F2 · minor · `assets/controllers/dynamic_modal_controller.js:352` (the fallback acts on `response.redirected` only), with Turbo `FrameController.formSubmissionFailedWithResponse` and `templates/bundles/TwigBundle/Exception/error500.html.twig:1`
**Problem.** A 5xx answer to the feedback POST still closes the sheet silently:
- Turbo loads a failed frame-form response into the frame.
- Prod error pages extend `base.html.twig`, whose `modal-frame` is empty, so `handleMutation` closes the modal.
- No message, no toast. The draft survives in sessionStorage, but the player is not told the send failed and will likely believe it went through.

repro.md lists this case next to (d) ("the same path applies to a prod 500"). The fix covers only the redirect.

Mail is async in prod (`SendEmailMessage` → `async`), so the trigger is rare: a database or Redis failure, or a deploy hiccup. Severity: minor.

**Reproduction.** With prod error pages (`APP_DEBUG=0`), make `FeedbackController` throw, e.g. a temporary exception in `pageUrlForMail()` on a scratch checkout. Send from the sheet: it closes, and nothing says it failed.

**Fix.** Treat "a form naming a fallback page, answered by a server error" like the redirect: `if (fallbackUrl !== null && response !== null && (response.redirected || response.serverError))`. Then either:
- `leaveFor(fallbackUrl)`: the help page, signed in, with the box. See F3 for the draft; or
- `event.preventDefault()` and keep the sheet with a one-line inline error. That needs a new key in 6 locales, passed as a data attribute.

### F3 · minor · `templates/help/page.html.twig:18` (no `help-sheet` controller on the full page), `assets/controllers/help_sheet_controller.js:31-34`
**Problem.** HELP-05 (d) sends a send whose session ended to the help **full page**, "draft kept". The draft is kept only in sessionStorage. The full page's root is `data-help-sheet` without `data-controller="help-sheet"`, so nothing restores it there:
- the guest page → "Sign in to send feedback" → back on the full page with an **empty** box. From the player's side the message is gone.
- If they retype and send there (PRG), the old draft is never cleared. `clearDraft()` runs only when the sheet's sent state connects.
- The next time they open the sheet anywhere, the already-sent text comes back, which invites a duplicate send.

**Reproduction.**
1. Signed in, open the sheet, type "abc" and sign out in another tab.
2. Send: the page becomes the help page (guest).
3. Sign in: the help page's box is empty.
4. Send "abc" there, then open `?` on any page: "abc" is in the field again.

**Fix.**
- Put `data-controller="help-sheet"` + `data-help-sheet-player-value` on the full page's root. `connect()` already copes without a `.modal` host: it restores into an empty field and does no release outside the sent state.
- Clear the draft after a successful full-page send. For example, `FeedbackController` adds a one-shot query flag or flash the page reads (`data-help-sheet-sent-on-page`), or the controller clears on `submit` of the full-page form (a 422 re-renders the typed text anyway).

### F4 · minor · `src/Twig/HelpTwigExtension.php:38-47` (`help_path()` = the server's request URI)
**Problem.** Several pages change their URL in the browser after the first render:
- Live Components with URL props: `PuzzleSearch`, `MarketplaceListing`, `Comparison`, `Players/Search`, `MspRatingLadder`, `FeatureRequestList`;
- `history.replaceState` users: `events_page_controller.js`, `comparison_page_controller.js`, `events_calendar_controller.js`.

Every Help opener (`?`, the end-of-page line, the Menu row) keeps `?url=` of the URL **as rendered**. On those pages:
- the feedback box shows, and the mail reports, `/en/puzzle` while the player is looking at `/en/puzzle?search=…&pieces=500`. The filter that showed the problem is exactly what the team needs;
- the sheet's Language row switches to the unfiltered page.

The Menu's language row has the same staleness (decision 36), but HELP-02's "the feedback box attaches the current page" is about the feedback.

**Reproduction.** On `/en/puzzle`, type a search: the address bar gains `?search=…`. Open Help: the attached path is `/en/puzzle`.

**Fix.** Rewrite the `url` parameter at click time:
- `dynamic_modal_controller.handleDocumentClick` is already a capture listener that runs before Turbo's link handler.
- For an `<a data-modal-scrim="own">` whose `href` carries `url=`, set `url = location.pathname + location.search`.
- Guest HTML is unchanged: the rewrite happens only in the browser, at the tap.

### F5 · minor · `templates/help/_content.html.twig:39` (`form_start(feedback_form …)`), `:76-77` (`id="help-language-label"`), `templates/navigation/_topbar.html.twig:51`
**Problem.** The `?` tool (and the Menu's Send feedback row) is on the help page too, where `help_path()` is bare. Opening the sheet there puts a second copy of `_content.html.twig` into the modal, so the ids are duplicated:
- `feedback_form_message`, `feedback_form_url` and `feedback_form__token`;
- `help-language-label`.

The sheet's `<label for="feedback_form_message">` and its `aria-labelledby` point to the page's elements behind the modal, which come first in DOM order. Tapping the sheet's label focuses the hidden page textarea, and screen readers name the sheet's field from the wrong element.

**Reproduction.** Signed in, open `/en/help` and tap `?`: `document.querySelectorAll('#feedback_form_message').length === 2`.

**Fix.** Pick one of:
- On `help` itself, render the openers as plain page links: `help_opener_attrs()` returns `data-turbo-frame="_top"` when the main route is `help`. The page already is the Help.
- Or give the frame copy its own ids: a named form `feedback_sheet` in `HelpResponseBuilder::feedbackForm()` for frame requests (`FeedbackController` must then read either name), and `help-language-label{{ in_frame ? '-sheet' }}`.

## Questions

**Q1 (HELP-02 "first").** The requirement says puzzle pages put "Suggest a correction to this puzzle" *first*. The implementation follows README §4.5's list order and the plan: the feedback box, then the correction.
- On a puzzle page a player who wants to fix the puzzle sees the big feedback field first.
- Is "first" meant as "first of the rows" (as built) or "above the feedback box"?

**Q2 (tokens in `?url=`).** These token pages extend `base.html.twig`, not the auth layout:
- `/delete-account/{token}` (owner, 60 min);
- `/en/email-preferences/{token}`;
- `/en/newsletter/confirm/{token}`;
- the activity-digest unsubscribe.

Their end-of-page line (and `?`, as the old top bar link already did) carries the full URL, so a feedback sent from them mails the token to the team. Should these templates empty `page_help_line`, or should `help_path()` keep only the route's path there? The sign-in link and password-reset pages use `_auth_layout`, so they are fine.

**Q3 (empty stream after the spinner).** A Turbo Stream answer that holds no `<turbo-stream>` element, arriving after a spinner-opened modal reached `shown`, now leaves an empty open modal: before this change it was an endless spinner.
- `settleAfterStream` runs only from `turbo:before-stream-render`.
- The 3 s timer (`:344`) only resets the flag.

Should that timer call `this.settleAfterStream()`? It is one line, and it closes the modal only when it is empty and idle. No emitter of such a stream was found, so this is defence only.

**Q4 (members-only buttons during the opening animation).** `handleTransitionClick` consumes any `[data-bs-dismiss="modal"]` click while the modal is still opening (`stopImmediatePropagation`). The "members only" buttons of `collections/modal`, `collections/move_modal` and `sell-swap/modal` also carry `data-bs-toggle="modal" data-bs-target="#membersExclusiveModal"`.
- A tap in the first ~450 ms closes the dynamic modal but no longer opens the members modal.
- Accepted? (The window is short. The alternative is to let a dismiss that also toggles pass through.)

**Q5 (end-of-page line in the native app).** The line sits in `<main>`, outside `is_web()`, so the app shows it too and opens `help` as a screen (`layer_frame()` → `_top`). README §4.10 lists the native app among bar-free contexts, but the line is not a bar. Is it intended in the app?

## Checked and fine

**`?url=` safety (HELP-02/03).**
- **Validation.** `HelpPageContextFactory` accepts only a `ReturnUrl` path or a same-host absolute URL, matched by the router as GET.
- **What reaches markup.** Only `path` (validated), the route name and its own path variables leave the factory.
- **Links built from it:**
  - Language row: `generate()` with the matched route and the context query.
  - Reload: the validated path, Twig-escaped. It starts with one `/`, has no `\` and no scheme.
  - Fallback URL: `path('help', …)`.
  - Sign-in: `path('login', {return: …})`, and the return is re-validated by `LoginFormAuthenticator` (`ReturnUrl::tryFrom`).
  - Correction: `puzzleId` is a validated UUID.
  - No open redirect, no attribute injection.
- **The new character refusal is only defence in depth.** One nit: browsers do not percent-encode a backtick in a query string. A hand-typed URL with `` ` `` therefore loses its page context, and the mail says "unverified: …". Harmless.
- **Query keys overriding route parameters** in `languageSwitchUrls(context)` is the old switch's rule (decision 36). Prod `strict_requirements: null` means a forged value yields a link, never an exception. No `{_format}`-style path variables exist, so `_`-prefixed variables left out of the context can never make `generate()` throw.

**Guest caching and privacy (HELP-03/04).**
- **The guest full page** is `public`, sets no cookie, and has no form or token (tested).
- **The guest and member frames** are `private, no-store` with `Vary: Turbo-Frame`.
- **Nothing per visitor:**
  - the end-of-page line depends only on the main request URI;
  - `nav_location()` for guests uses no player;
  - the XP gate is global (Redis-cached `CachedXpLaunchReadiness`, admins only when signed in).
- **`feedback`** keeps `IS_AUTHENTICATED_REMEMBERED`, and a guest POST → login (tested).
- **The fallback attribute** is rendered for signed-in players only.

**Budgets.**
- The line costs `help_path()` plus the memoised `nav_location()`: no statement.
- The sheet's items come from the registry and the cached XP gate.
- `EmptySignedInEndpoint` (the help frame) stays {user refresh, profile row}.
- No new service holds state: `HelpSheetItem` and the kind enum are readonly value types.

**HELP-02 rendering.**
- **Order** = README §4.5 / plan.
- **One `data-help-item` per row**, and `data-nav` on every link (`help/help/<id>`, `help/help/language_<locale>`, `help/help/sign_in`).
- **`suggest_correction`:**
  - it is not in the page search (`isInPageSearch()` needs a Menu row or search-only), so `nav-search.json` never generates it without a `puzzleId`, and `nav_go` answers 404;
  - `docs/navigation.md` is regenerated (132 entries).
- **Reload:**
  - `help-sheet#reload` calls `preventDefault()` before `layer_history`'s document click, which checks `defaultPrevented`;
  - `closeThen` falls back to calling at once without a layer;
  - `.standalone-only` stays hidden outside the installed app through NAV-03's unconditional critical rule (`31c687d4` is safe). In the native app `standalone.js` returns early, so it is hidden there too.
  - `_head_flags.html.twig` includes `standalone.js` once.
- **`locale-preference#save`** writes localStorage only, and the language links are `_top` replace visits (rule (d)).
- **`FeedbackFormType`'s view transformer** is the right fix: with no view transformers, `Form::viewToNorm('')` gives `null`, which the `string` property refuses.

**HELP-04.**
- **Placement:** the block is the last child of `<main>`, after `full_content`.
- **Emptied by:** `_auth_layout` (every sign-in, link and reset page, token pages included), `round_stopwatch`, `live_results`, `participants_sheet` and `help/page`.
- **Guard:** `PageHelpLineTest` fails for any base template that empties `header` but not the line.
- **Not covered here:**
  - the running stopwatch is ADD-04's (plan);
  - the print views do not extend base.
- **Link attributes:** `rel="nofollow"`, `data-modal-scrim="own"`, `data-nav="page/<place>/help_line"`; `NavInstrumentationMarkupTest` has a row for it.

**HELP-05 in the shared modal path (the other partials).**
- **`isOpen()` from `show.bs.modal`:**
  - Esc, ×, `data-bs-dismiss`, an outside tap and an emptied frame during the fade-in queue a close;
  - `close()` with a held entry goes through `requestClose`, which ignores a second request while one is pending, so `handleMutation` + `settleAfterStream` + `handleShown` can never step back twice;
  - the spinner is never started for a submit made while the modal opens.
- **Streams:**
  - only `_modal_close_stream.html.twig` targets `modal-frame`, so `pendingOpen = false` on a stream answer cannot suppress a modal a stream meant to open;
  - the external-form path is null-safe (`handleBeforeFetch(null)`).
- **`sweepOrphans()`:**
  - it runs only when no `.modal.show` exists;
  - for the three "members only" buttons, `#membersExclusiveModal` gets `.show` (~150 ms, ~5 ms under reduced motion) before the dynamic modal's `hidden` (~450 ms / ~10 ms, later still with a history entry), so its backdrop is never swept;
  - nothing else in the app writes `body.style.overflow`, and there is no offcanvas.
- **`leaving`:**
  - it is reset on `hidden` and on every new fetch;
  - the container is not `data-turbo-permanent`, so a finished visit always brings a fresh controller.
- **(d):**
  - it applies only to forms inside the frame whose content names a fallback;
  - `preventDefault()` on `turbo:before-fetch-response` makes Turbo record the result without rendering it;
  - `leaveFor()` is a replace visit through the layer helper;
  - the guest frame has no fallback.
- **422** re-renders in place (not redirected, not a stream). **Offline** keeps the form (`handleFetchError` with content).
