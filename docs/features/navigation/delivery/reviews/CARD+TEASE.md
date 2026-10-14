# Phase review: CARD + TEASE

- **Scope:** three commits on `feature/navigation-redesign` (delivery worktree), reviewed at `ae657dac`. "Before" is `f443ae48`.
  - `9cd99e0e` CARD-01a + CARD-01c + CARD-03: the trigger (`assets/player_card.js`), the lazy layer (`assets/player_card_layer.js`), the rules (`assets/player_card_rules.js`), `PlayerActionsResolver` / `PlayerActions`, `GetPlayerCard` with the latest puzzles, the card templates, the `PlayerCardContext` value objects.
  - `58a4503c` CARD-04 + CARD-02 + CARD-01b: `GetPlayerCardContext` and the `positionsOf()` / `soloBestCte()` / `groupBestCte()` extractions, the own card's actions, the triggers on every surface, the registry's `player_card` entry and the regenerated `docs/navigation.md`.
  - `ae657dac` CARD-05 + CARD-06 + CARD-07 + TEASE-01: the canaries, `PlayerCardQueryBudgetTest`, the card beacons (`player_card_measure.js`, `nav_instrumentation.js`, `NavClickController`), the revert rule (`NavigationRegistry::PLAYER_CARD_DIRECT`), and the teaser scope (stand-in removed, `data-members-placement`, `MembersPlacement`, `members_modal_controller.js`, the `StartFreeTrialController` placement line).
- **Checked against:**
  - `requirements.md`: the Conventions, plus the Done when / Verify of CARD-01a, 01b, 01c, CARD-02…07 and TEASE-01.
  - `implementation-plan.md`: all of §3 (in particular §3.2, §3.4, §3.5, §3.7, §3.9, §3.10), the CARD and TEASE sections, the ownership and tests-to-change rows, and decisions 12 (R12), 21, 58 and 73.
  - `README.md`: §4.8, §6, D12 and D13. D1–D22 are not reopened.
  - `delivery/state.md`: the CARD rows and the decisions of 2026-10-13 (CARD-01a, CARD-02). Behaviour logged there as decided is not a finding.
  - `CLAUDE.md`: blocklist, private profiles, suspicious times, secret puzzles, drafts, the XP gate, ranking opt-out, `ResetInterface`, guest caching, budgets only going down, Turbo rules, translations, logging levels.
- **Method:**
  - **Trees.** Ran `git archive` of `ae657dac` and `f443ae48` into the scratchpad and read every changed source file there, plus the three diffs in full. Also read `layer_history.js` (open / requestClose / popstate / turbo:click), `HiddenPlayers`, `GetPublishedRoundResults` (people / teams / hide-after-ranking), `TimesLeaderboardSql::board()`, `RecentActivity`, `row_link_controller.js`, `topbar_scroll.js` and `pull_to_refresh.js`.
  - **Privacy.** Traced every statement of `GetPlayerCardContext` (puzzle, feed / notification, round, board) and `GetPlayerCard`'s LATERAL against the surface it explains:
    - blocked players are excluded inside the ranked sets (`sqlExclude` keeps its `IS NULL OR` guard for the CASE column);
    - `sqlIsPrivate` / private players are left off global boards for everybody;
    - suspicious times count only for the people in them;
    - `hide_until` and `hide_image_until` are respected, and the round's reveal is checked;
    - `IsCompetitionPubliclyVisible` is applied;
    - the XP gate and AP sign-in rule hold;
    - `hidesTeam()` matches the round page's hide-after-ranking rule;
    - the 404 bodies and headers match;
    - guest frame vs page caching is correct;
    - the beacon payloads carry the kind, input, outcome, ms, placement and the page path only.
    I found no leak.
  - **Parity.** Card rank vs `standing()`, `PuzzleTimes`, `window()` and the round page: the same CTEs and the same `RANK()` / `OfficialResultsRanking`. Checked `PlayerActionsResolver` against the removed `PlayerHeader` code line by line.
  - **Layer.** Read the open / swap / close / leave / cache paths, the measurement state machine and the prefetch cache against layer_history's popstate and Turbo 8 click handling (the card's `preventDefault()` on the document makes `LinkClickObserver` and row-link skip the click).
  - **Tests.** Ran `git diff f443ae48 ae657dac -- tests`. One file was deleted: `players_card_controller.js`, which the plan says to remove. Two test methods were renamed, with stronger assertions (the D13 change). Every other removed assertion is replaced with the same intent (new markup or a new selector). Nothing was weakened.
  - **Translations.** Parsed the six `messages.*.yml` before and after with PyYAML (`python3.14 -I`):
    - 20 keys added and 1 removed (`players.card.profile`, unused);
    - every key is present in all 6 locales, and no other key changed;
    - no value equals English (MSP Rating titles differ by the pieces word);
    - placeholders match;
    - pipe plurals: 3 forms in cs, 2 in de/es/fr/en, 1 in ja;
    - tone: de Sie, fr vous (with a space before the colon), es tú, cs ty.
  - **Evidence.** Browser evidence came from `state.md` and the logs: `checks/C-CARD-*.log`, `C-TEASE-01.log`, `C-NAV-11.log` and `cbatch3/`. I read `scripts/checks/NAV-11.py` (focus 10). I ran no tests, builds, docker or browser.
- **Result:** 0 blocker · 1 major · 4 minor · 3 questions.

## Findings

### F1 · major · `assets/player_card_layer.js:385-405, 446-450` (closeUi → focusAnchor), `:306-338` (prefetch), `:703-706` (onFrameLoad); `assets/player_card.js:151-157` (focusin → prefetch)
**Problem.** Closing the card fetches the same card again in the background.
- `closeUi('back' | 'close')` returns focus to the name with `anchor.focus()`. That fires `focusin` on the document, and `player_card.js` treats any `focusin` on a trigger as "prefetch this card".
- `prefetch(url)` skips the request only when `this.cache` holds a fresh entry. The cache is filled only by a prefetch: a card loaded through the frame's `src` (`load()`, followed by `onFrameLoad`) is never stored.
- iOS Safari, and Safari and Firefox on macOS, do not focus a link on tap or click. No prefetch happens there before the open, so the open goes through `src`. Every close (×, Esc, scrim, swipe, Back, the same name again) then fires a second `GET /en/puzzler-card/<id>?context=…` with `Turbo-Frame: player-card`.
- In Chrome a prefetch fills the cache, but any card left open longer than 20 s fetches again on close.
- Scenario: an iPhone on `/en/ladder/solo/500-pieces` taps a name, reads the card, and swipes it away. The result is two card requests: each costs 3–6 statements (`PlayerCardQueryBudgetTest`), and each runs the Times-board `BestTimes` aggregate (116–165 ms per run on the clone, `state.md` CARD-02 EXPLAIN).

**Why it matters.**
- README §4.8 and CARD-06 say "≤ 4 statements per open". In practice an open costs two requests (≤ 8) on iOS, the largest phone audience. The waste is the most expensive context there is: `docs/TODO.md` already flags the Times-board cost per open.
- `PlayerCardQueryBudgetTest` measures one request, and the node harness fakes the layer, so nothing can see it.

**Fix.** Make the layer ignore the prefetch its own focus return causes.
- In `closeUi()`, remember `{url, at}` of the card being closed. `prefetch(url)` then returns `Promise.resolve(null)` for that URL within `PREFETCH_TTL_MS`; a deliberate reopen still loads normally.
- Optionally, also store the initial frame response in the cache, so a reopen within 20 s is as instant as a prefetched open. Take it from `turbo:before-fetch-response`, `fetchResponse.responseHTML` → `frameHtmlOf()`: the raw server HTML, never the live `innerHTML`, which Stimulus has touched.
- Never write an attribute on the trigger (Live).

**Test.** Add a WebDriver row to `checks/CARD-01a.py` at 360:
1. Open the card with `trigger.click()` from JS (no hover, no focus, as iOS does).
2. Wait for `turbo:frame-load`, press ×, wait 1 s.
3. Count `performance.getEntriesByType('resource')` whose `name` contains `/puzzler-card/`: it must be exactly 1.

Today it is 2.

### F2 · minor · `assets/player_card_layer.js:370-382` (onLayerClose), `:620-640` (onKeydown handles Esc only); `templates/base.html.twig:272`
**Problem.** Back with the members modal open over the card closes the card underneath and leaves the modal on screen.
- A non-member on an Android phone opens a card and taps the locked tier chip. `#membersExclusiveModal` opens above the sheet (decision 73).
- They press the system Back. The popstate reaches `layer_history`, whose top is still `card`, so the card slides away under the modal.
- The plain Bootstrap modal takes no part in layer history, so it stays open over the page. The next Back leaves the page while the modal is still showing.
- Esc was handled deliberately: "Esc with the members modal open closes only the modal", decisions log CARD-01a. Back was not.

**Why it matters.**
- GEST-01 / D22 say Back closes the top layer.
- This is the card's one teaser path (TEASE-01), and Back is the most common close on Android.
- The result is the opposite order: the hidden layer goes and the visible one stays, followed by an unexpected page exit.

**Fix (smallest).** In `onLayerClose` for `reason === 'back'`, also hide any open `.modal.show` that layer history does not own, i.e. `#membersExclusiveModal`, via `bootstrap.Modal.getInstance(el)?.hide()`. One Back then clears the stack the player sees, and the modal never outlives the card it was opened from.

The alternative is to give the members modal its own entry through `layers.open()`. That would close the card when the modal opens (a swap), which decision 73 does not want.

**Test.** `checks/TEASE-01.py` (non-member and guest, 360, Pixel UA):
1. Open the card and tap the lock.
2. Run `history.back()`.
3. Expect no `.modal.show`, no `dialog.player-card-layer[open]`, the probe holding and the index back to the value before opening.

Today `.modal.show` remains.

### F3 · minor · `src/Query/GetPlayerCardContext.php:364-401` (`round()`: the official branch returns before the linked-time fallback)
**Problem.** Once a round shows official results, a player without a ranked official entry gets no round block, even though the round page lists their time.
- `round_results/_official.html.twig` renders the unofficial list ("player times", `_player_times.html.twig`) in a `<details>` under the official table.
- Scenario: a puzzler who was not in the official entries, or whose entry has no result, linked their time to the round. They appear in that list, which carries `data-player-card-context="round:<id>"`.
- Their card opened from that list runs `round()`. `shows_official` is true and `$mine === null`, so it returns null; `linked_result` is never looked at.

**Why it matters.**
- Parity with the surface is the rule of CARD-02 (risk 12): the card says nothing about the very row it was opened from.
- The logged decision covers "unofficial lines only after the round started" and "official team entries with a hidden member show none", not this case.

**Fix.**
- In the official branch, when `$mine === null`, fall through to the `linked_result` path (same statement, the column is already selected) instead of returning null.
- Keep returning null for the hidden-team case (`hidesTeam()`), so that decision stands. To do that, distinguish "no entry of mine" from "my entry is hidden".

**Test.** `PlayerCardContextTest`: on `OfficialResultsFixture::ROUND_GROUP_A` (published), link a non-suspicious time of a player without an official entry to the round. Their card with `round:<id>` must show the `round_unofficial` line. Today it shows no `.players-card-context`.

### F4 · minor · `assets/player_card_layer.js:180, 249-259`; `templates/players/_card_unavailable.html.twig:5-10`
**Problem.** The card's dialog has no accessible name while it loads and whenever the card is unavailable.
- `ensureDialog()` sets `aria-labelledby="players-card-name"` once.
- That id exists only in a loaded card's `<h2>`. The loading state (spinner plus a hidden "Loading") has no element with it, and neither does `_card_unavailable.html.twig` (blocked, private, deleted).
- A screen reader user who opens a card on a slow connection, or a hidden player's card, enters an unnamed dialog. With the sheet that dialog is also `aria-modal="true"`.
- NAV-11's axe pass scanned only a loaded card (`wait … #players-card-name`), so `aria-dialog-name` could not trip.

**Why it matters.** NAV-11 / WCAG 4.1.2: a dialog needs a name. The unavailable card is exactly the case the privacy rules make common.

**Fix.**
- Give `_card_unavailable.html.twig` a visually hidden `<h2 id="players-card-name">` with the existing `players.card.title`. It stays the same for every reason, so the byte-equal canaries hold.
- In `showLoading()`, give the loading text element `id="players-card-name"`.
- Alternatively, set `aria-label` from the loading text while `aria-busy` and remove it on load.

**Test.**
- `PlayerCardControllerTest`: the 404 frame contains a non-empty `#players-card-name`.
- `checks/NAV-11.py`: an axe pass on an unavailable card (a blocked or nonexistent id) and right after the tap, before `turbo:frame-load`, shows no `aria-dialog-name` violation.

### F5 · minor · `assets/player_card_layer.js:202-247` (loadFrame / load; frame listeners `:207-209`)
**Problem.** A card request that fails at the network level leaves the spinner showing indefinitely.
- On a phone that drops its connection, the prefetch's `.catch(() => null)` leads to `load()` (`src`). Turbo's frame fetch then fails with `turbo:fetch-request-error`, which the layer does not listen for.
- No `turbo:frame-load` and no `turbo:frame-missing` follow. The sheet keeps "Loading…" with `aria-busy`, no ×, and no swipe handle, because the bottom-sheet controller lives in the card's content.
- Only the scrim, Back or Esc get the player out.
- `player_card.js` has a fallback for the layer module failing to load (it navigates to the profile); the card fetch has none.

**Why it matters.** A tap on a name becomes a dead end on flaky mobile connections, and the open counts as a `bounce` or `close` in CARD-07's numbers.

**Fix.** Listen to `turbo:fetch-request-error` on the frame (next to the other three). On that event, do what the module-load failure does: `layers.visitFromLayer(this.anchor.href)`, the profile. The service worker's offline page answers that navigation when the phone is offline.

**Test.** `checks/CARD-01a.py` at 360:
1. After the page loads, run CDP `Network.emulateNetworkConditions {offline: true}`.
2. Tap a name.
3. Within 5 s, expect no `.player-card-box[aria-busy]` left, and the location to be the profile (or the offline page).

## Questions

### Q1 · NAV-11 Q3 "in-page jump" row (52 = 52, focus 10)
**Answer.** This is neither an off-by-one nor a layout jump from the card code.
- The row passes when `before.bar == 'hidden' and landed.bar == 'shown' and landed.target >= landed.barBottom - 0.5 and landed.target <= landed.barBottom + 4` (`NAV-11.py:806-807`). With target 52 and bar bottom 52 the comparison holds.
- The failing line in `checks/C-NAV-11.log:108` reads "from the hidden bar **(shown)**": the conjunct that failed is the precondition `before.bar == 'hidden'`.
- The check prepends a 40 px target to `<main>` while scrolled to 1200. Chrome's scroll anchoring then moves `scrollY` to about 1240. The next `scroll(s, 1200)` is a 40 px upward scroll, more than `SHOW_AFTER_PX` (8), so `topbar_scroll.js` shows the bar before the jump.
- Whether anchoring lands before the second WebDriver call decides pass or fail, which makes the row flaky.
- The card adds nothing to the Hub's flow: rows gained attributes only, and the `_leaderboard_player` avatar became a flex `<a>` of the same box.

**Recommendation.** Fix the check, not the product:
- After inserting the target, scroll further down (e.g. `scroll(s, 1600)`), or set `overflow-anchor: none` on `<html>` for the row.
- Assert `before.bar == 'hidden'` as its own precondition row.
- Print which conjunct failed.

### Q2 · `?placement=` on the members modal's membership links is read by nothing
`members_modal_controller.js` rewrites "See membership" / the CTA to `/en/membership?placement=card.tier`, but nothing reads that parameter:
- the membership page does not read it;
- its own free-trial form has no `placement` field (asserted absent in `StartFreeTrialControllerTest`);
- Stripe checkout does not receive it.

So the parameter is a dead query string: a trial started from the membership page after a teaser is not attributed, and the only placement signal is the modal's own trial button and the `members_modal` open beacon.

**Recommendation.** Either:
- drop the link rewrite (less surface), or
- pass it through: the membership page renders `placement` into its trial form's hidden field only when `MembersPlacement::tryFrom()` accepts it. `StartFreeTrialController` already logs it, nothing is stored, and the page is signed-in only on this path, so caching is unaffected.

I'd pass it through. It is what plan decision 12 intends ("the placement travels … as `?placement=`").

### Q3 · Revert-rule denominators when an outcome is lost
`card_outcome` is flushed on `pagehide`. On iOS a tab that is backgrounded and then discarded often never fires `pagehide`, so some opens never send an outcome.

The rule text (`PLAYER_CARD_REVERT_RULE`, `docs/TODO.md`) counts shares of `card_open` lines. Lost outcomes would then count as "did not continue", biasing every iOS cell against reverting.

Ending the measure on `visibilitychange: hidden` would break "first wins" for players who come back to the tab.

**Recommendation.** Keep the code as it is, and compute the shares over `card_outcome` lines, which carry context and input, with `card_open` used only for the ≥ 300 threshold. Say so in the rule text and in the TODO item.
