# Phase review: ADD-03 + INB

- **Scope:** these commits; the other commits in `c2d8fc5c..HEAD` only touch `delivery/state.md`.
  - `b7a8aca0` ADD-03: running-stopwatch pill state on the profile row, pill partials, `stopwatch_pill_controller`, `msp:stopwatch-last`, `add_entry_url()`.
  - `b7e1796d` INB-02: `GetInboxBadge`, `InboxBadgeParts`, `InboxBadge` memo, `inbox_badge` stream and `assets/inbox_badge.js`, partial index, budget pins.
  - `09ac25cf` INB-04a: `favorites_seen_at`, `MarkFavoritesSeen`, `newSince`, `favorites_seen_controller`.
  - `b6bb3d58` INB-01: the Inbox header.
  - `5a7a8a21` INB-04b: Favorites | Everyone, `FavoritesFeed`, "N new".
  - `3e6edeb0` INB-03: `NotifyWhenPuzzleSolved` deleted, `sqlListed()`.
  - `732e1453` INB-05: `NavigationPointers`, the dismissed-hints subselect, the Inbox pointer.
- **Checked against:**
  - `requirements.md`: Conventions, and the Done when / Verify of ADD-03 and INB-01…05.
  - `README.md`: D3, D4, D7, §3, §4.4 and §7.
  - `implementation-plan.md`:
    - §3.1–3.10;
    - §4 sections ADD-03 and INB-01…05;
    - §5 (the profile-row and notifications rows);
    - §6 (NotificationsControllerTest, GetNotifications/GetConversationsTest, NotifyWhenPuzzleSolvedTest, PrivateProfileViewersControllerTest, every exact and AtMost pin);
    - decisions 45–52 and 90;
    - risks 5, 6, 17 and 20.
  - `CLAUDE.md`: handlers/repositories, `ClockInterface`, `ResetInterface`, `HiddenPlayers`, `PrivateProfileAccess`, custom-index rules, Turbo, translations, public-repo data.
- **Method:**
  - Read the seven diffs and the code they rely on:
    - `GetNotifications::forPlayer()` (every UNION branch);
    - `GetConversations` (list, requests, ignored);
    - `DeletePlayerHandler::anonymizeConversations()`;
    - `ApprovePuzzleMergeRequestHandler` (source-puzzle clearing + notification);
    - `NotifyWhenPuzzlingTeamRenamed`, `BlockUserHandler`;
    - `Stopwatch` / `Lap` / `LapsArrayDoctrineType`;
    - `SecretPuzzleAccess`, `page_ticker.js`, `app.js` (restore → reload);
    - Turbo 8 `StreamElement` behaviour for unknown actions / missing targets;
    - `ux-live-component` 3.4 `LiveComponentSubscriber::isLiveComponentRequest()` (CSRF via `X-Requested-With` preflight).
  - Compared old vs new assertions in every changed test file.
  - Checked the 16 new translation keys in 6 locales with a YAML parse: all present, none equal to English.
  - `curl` of the guest Hub on `127.0.0.1:18080` returned `Cache-Control: max-age=0, public, s-maxage=60`, and no `favorites-seen` / `hub-feed-new` / `ra-new` markup.
  - Nothing was run: no phpunit, ParaTest, phpstan or docker.
- **Result:** 1 blocker · 1 major · 4 minor · 3 questions.

## Findings

### F1 · blocker · `src/Controller/NotificationsController.php:52-60` with `src/Services/HiddenPlayers.php:157` (`sqlNotificationNotHiddenFor`) and `src/Query/GetNotifications.php:417` · resolved — one rule for count and list (the shared fragment hides a hidden `actor_player_id` for every type; the merge branch LEFT-joins the puzzle and falls back to `source_puzzle_name`, linking the survivor; rating LEFT-joins the seller with `seller_name`/`buyer_name`; the solving-time branch LEFT-joins the brand) and the page reads `InboxBadge::forViewer()` first, marks every unread row when For you > 0 or a loaded row is unread, then `forYouMarkedRead()` (same statements: badge + list + UPDATE); parity over every type in `GetInboxBadgeTest`, the reproduction in `NotificationsControllerTest`
**Problem.** The For you part counts unread rows that the For you list can never show. The notifications page no longer clears them, so a phantom badge never goes away.
- Before INB-02, the page marked everything read whenever the count was > 0.
- Now it dispatches `MarkNotificationsAsRead` only when one of the **loaded** rows is unread (decision 48).
- Any unread row that is counted but not listed therefore stays unread on every page:
  - For you shows "1";
  - the Inbox link opens For you;
  - nothing on it is new;
  - this lasts until some other, listed notification arrives and the page is opened again.

Two verified kinds of such rows:
1. **An approved merge whose reported puzzle was not kept.**
   - `ApprovePuzzleMergeRequestHandler:270-292` calls `clearSourcePuzzleReference()` (`source_puzzle_id` = NULL) when the reported puzzle is merged away.
   - In the same transaction it creates a `PuzzleMergeRequestApproved` notification for the reporter.
   - The list branch joins `INNER JOIN puzzle source_puzzle ON pmr.source_puzzle_id = source_puzzle.id` (`GetNotifications.php:417`), so that row is never listed.
   - The badge has no such join and counts it.
   - This is an ordinary moderation outcome (the survivor is often the other puzzle), and unread rows of this kind already on production get stuck at deploy.
2. **Rows whose only player is an actor the viewer blocks**: `PuzzlingTeamRenamed`, `GuestLinkRequested` and `GuestLinkAccepted`.
   - The list hides them with `$actorNotHidden` (`GetNotifications.php:844`, `:928`).
   - The shared fragment hides only through `target_solving_time_id` (COALESCE actor) and `target_conversation_id`. A row with neither passes.
   - The handlers skip blockers when they create the row, so this happens whenever the block comes after the notification. That includes an admin block added after a harassment report: `BlockUserHandler` leaves notifications alone.

`GetInboxBadgeTest::testForYouAgreesWithTheListOfTheSameViewer` covers group edits and lending only, so neither case is caught.

**Reproduction.**
- Case 1:
  1. As PLAYER_A, file a merge request from puzzle X ("duplicate of Y").
  2. A moderator approves it with Y as the survivor.
  3. As PLAYER_A, open `/en/faq`: `.inbox-badge[data-inbox-part="for_you"]` = 1.
  4. Open `/en/notifications`: no unread row, no `MarkNotificationsAsRead`.
  5. `/en/faq` again: still 1, indefinitely.
- Case 2:
  1. B renames a pair team of A and B, so A has an unread `PuzzlingTeamRenamed` with actor B.
  2. A blocks B.
  3. Steps 3–5 as above.

**Fix.**
1. Make the page's decision independent of what the list can render.
   - Read `InboxBadge::forViewer()` in the controller first. It is the statement the layout runs anyway.
   - Dispatch the mark when `forYou > 0` or a loaded row is unread, then `InboxBadge::reset()`.
   - That costs +1 statement only when something was unread: 3 then, as before INB-02, and 2 otherwise. "Never a total of 0" still holds.
2. Align the two sets:
   - In `sqlNotificationNotHiddenFor()` also exclude `notification.actor_player_id` among the hidden ids for every row, as the list's actor branches do.
   - Make the merge branch `LEFT JOIN` the source puzzle and fall back to `pmr.source_puzzle_name`, which the entity stores for exactly this. That also fixes those notifications never being shown at all.
3. Extend the parity test with both rows: a merge with the source merged away, and a team rename by a blocked actor.

### F2 · major · `src/Query/GetInboxBadge.php:79-88` (pending_requests) and `:62-77` (unread_conversations) vs `src/Query/GetConversations.php:95-96, :185` · resolved — `initiator_id IS NOT NULL` (+ `recipient_id IS NOT NULL` for conversations) in the badge and in `findPlayersWithPendingRequestsToNotify()`; tests for an anonymised initiator and a deleted partner on either side
**Problem.** A message request from an account that was later deleted is counted under Messages forever, but listed nowhere.
- `DeletePlayerHandler::anonymizeConversations()` sets `conversation.initiator_id` to NULL and leaves `status = 'pending'`.
- The badge counts `recipient_id = :p AND status = 'pending'`. The `NOT EXISTS` on `ub.blocked_id = c.initiator_id` is true for NULL.
- `pendingRequestsForPlayer()` (`JOIN player ip ON c.initiator_id = ip.id`) and the Requests sub-tab's `pending_count` drop it.
- The recipient therefore sees Messages "1". When nothing else is unread, the Inbox link targets `/en/messages?tab=requests`, which shows "No pending requests". There is nothing to accept or ignore, so it can never clear.
- Typical trigger: an admin deletes a spam account that sent requests to many players. All of them get the permanent badge.
- Header pending requests are new in INB-02, so this case is new.
- The same mismatch exists for unread **accepted** conversations with a deleted partner: `forPlayer()` inner-joins both players. That one was inherited from the old count, but it now also steers the Inbox link's target.

**Reproduction.**
1. PLAYER_WITH_STRIPE starts a request to PLAYER_REGULAR, as in fixture `CONVERSATION_PENDING`.
2. Dispatch `DeletePlayer` for PLAYER_WITH_STRIPE.
3. As PLAYER_REGULAR (after reading the accepted conversation), `/en/faq`:
   - the Messages part is 1;
   - the Inbox link is `/en/messages?tab=requests`;
   - that page lists no request, with no sub-tab badge.

**Fix.**
- Count only what the lists can show:
  - `AND c.initiator_id IS NOT NULL` in pending_requests;
  - `AND c.initiator_id IS NOT NULL AND c.recipient_id IS NOT NULL` in unread_conversations.
- Or join `player` exactly as the list queries do.
- Add a `GetInboxBadgeTest` case: a request whose initiator was anonymised is not counted.
- `GetPlayersWithUnreadMessages::findPlayersWithPendingRequestsToNotify()` has the same ghost. It is outside this phase but worth the same line.

### F3 · minor · `src/Value/FavoritesSeen.php:10-12`, `src/Entity/Player.php` (`markFavoritesSeen` docblock) · resolved — behaviour kept, both docblocks corrected (the first visit marks at once, the interval only spares writes; highlights on Back are GEST-15's instant Back)
**Problem.** Both docblocks promise more than the code does:
- "moves at most once per MARK_EVERY_SECONDS - so coming back a minute later still shows what was new a minute ago";
- "what was new stays new for a quick return to the Hub".

The first Hub visit after more than 10 minutes marks `favorites_seen_at = now`, synchronously, through the Live action. Any later render reads that value as `newSince`, so the rows that were new a minute ago are no longer new. The 10-minute rule only saves writes on the second and later returns.

In practice, tapping a highlighted row and pressing Back loses every highlight and the "N new" count. Back is a full reload (`app.js` turns restore visits into `location.href`).

**Reproduction.**
1. Set `favorites_seen_at` to yesterday; the favorites have 3 times tracked today.
2. Open `/en/hub`: "3 new", 3 `tr.ra-new`, and the action fires.
3. Open a row's puzzle, then Back: no `.hub-feed-new` and no `ra-new`.

**Fix.** Pick one and pin it in a test:
- **Keep the behaviour.** Correct both docblocks. This meets requirement INB-04a as written.
- **Keep the promise.** Keep the previous value while the last mark is < 10 minutes old. Either add a nullable `favorites_previous_seen_at`, which the profile row returns as `newSince` within the window, or have the controller hold the rendered `newSince` in `sessionStorage` for 10 minutes and re-send it.

### F4 · minor · `src/Query/GetRecentActivity.php:334-335` (CTE `LIMIT`) with `:393` (`sqlIsPublic`) and `templates/hub.html.twig` (`new_count >= feed_limit`) · resolved — `sqlIsPublic('player')` moved into the CTE (player joined there), so the 20 rows are 20 shown rows; "20+ new" pinned in `RecentActivityNewSinceTest` (clone: 1.0-2.5 ms as before, one viewer 19 → 20 rows)
**Problem.** "20+ new" assumes the feed loads 20 rows, but it can load fewer:
- `ofPlayerFavorites()` limits the CTE to 20 solving times first.
- It then drops rows of players private to the viewer in the outer `WHERE`.

A viewer who follows a private player not on her allow list therefore gets, for example, 17 rows. When all of them are new, the tab says "17 new" instead of "20+ new", and the cap is unreachable. "N new" stays consistent with the highlighted rows (the private-profile canary pins that); only the "+" is lost.

**Reproduction.**
1. A viewer follows PLAYER_PRIVATE (not allowed) and public players.
2. The 20 most recent favorites' times include 3 of PLAYER_PRIVATE's solo times, all newer than `favorites_seen_at`.
3. The tab reads "17 new".

**Fix.** Move `sqlIsPublic('player')` into the CTE, next to `{$notHidden}`, so the window is filled with showable rows. The CTE then needs the `player` join. Alternatively, decide the "+" from "the query filled its window", not from the rendered count.

### F5 · minor · `assets/controllers/stopwatch_pill_controller.js:18-29` (`offsetFor`) and `assets/stopwatch_elapsed.js` (`stoppedAfterRender`) · resolved — eager `assets/document_receipt.js` (from `app.js`) files when each page's HTML arrived under its `msp-rendered-at` (full load: `timeOrigin + responseStart`; Turbo: `turbo:before-fetch-response` filed at `turbo:before-render`); `clockOffsetMs()` uses it when the render belongs to the page, else connect time; per-render memo kept; harness "connect 3 s after render"
**Problem.** The "server minus device" offset is measured when the first instance of a render connects. It therefore includes:
- the server's time between reading the profile row and sending the response;
- the network transfer;
- the download of the lazy `stopwatch_pill` chunk.

The pill then counts from `readAt` as if no time had passed. It stays behind the real elapsed time by that whole delay, for as long as the page lives. Measured that way, `stoppedAfterRender()` effectively compares with the connect time, not the render.

The stopwatch page itself shows the true time, so the two disagree. On a slow phone connection the gap is seconds, and more on the first load of the chunk.

**Reproduction.**
1. In the delivery Chromium, throttle to "Slow 3G" with the controller chunk uncached.
2. Open `/en/faq` with a running stopwatch (after NAV-03 places the pill).
3. Compare the pill with `/en/stopwatch/{id}` opened at the same moment. The pill is behind by the load time.

**Fix.** Measure the offset once per document, before lazy chunks load:
- on `turbo:load`, from `meta[name="msp-rendered-at"]`, which is rendered just after `readAt`;
- or from the server `Date` header the stale-document script already reads.

Key the map by that document, and add a harness case "connect 3 s after render → elapsed = render + 3 s".

### F6 · minor · `assets/controllers/stopwatch_pill_controller.js:102-111`; `assets/stopwatch_elapsed.js` (`LAST_STATE_KEY`) · resolved — `msp:stopwatch-last` is a map per stopwatch (10 newest, the old single record still read); a hidden pill keeps listening and shows again on a newer `running`, counting from the elapsed time the resume recorded; harness + `checks/ADD-03.py` rows
**Problem.** Two gaps in the client-side state:
- **A hidden pill never comes back.** `hideIfStopped()` hides the pill and calls `stop()`, which removes the `storage` / `msp:stopwatch-last` listeners. If the stopwatch is then resumed in another tab, the pill in this tab stays hidden although the stopwatch runs, until the next navigation.
- **One key holds one stopwatch.** Pausing A and then starting or deleting B (`deleteOther`) overwrites `{A, paused}`. A pill for A on a page that is reconnected later is then no longer hidden.

**Reproduction.**
1. Open tab 1 on `/en/stopwatch/{A}` (running) and tab 2 on any page with the pill.
2. Pause in tab 1: tab 2's pill hides.
3. Resume in tab 1: tab 2's pill stays hidden.

**Fix.**
- Keep listening after hiding, and unhide (restarting the ticker) when a newer `running` state for the same id arrives.
- Store a small map `{[id]: {status, at}}`, capped at a few entries, instead of one record. Add harness cases for both.

## Questions

- **Q1 · back/forward cache · noted for GEST-15.** Neither the pill nor the badge re-checks on `pageshow`:
  - `page_ticker.js` wakes the ticker, but Stimulus does not reconnect, so `hideIfStopped()` does not run;
  - `reapplyAfterRestore()` listens to `turbo:load` only.

  A page restored from the bfcache after a pause, or after a push it missed while frozen, keeps the stale state. Whether signed-in pages are bfcache-eligible (open Mercure `EventSource`, `Cache-Control`) is not verified here. If they are, add a `pageshow` (`persisted`) check to both.
- **Q2 · pushes for request answers · resolved — accept, ignore, block and unblock dispatch `PushInboxBadge` for the acting player after the bus (numbers read after the commit); `MercureNotifierInboxBadgeTest`.** Only `NotifyOnChatMessageSent`, `MarkMessagesAsReadHandler` and `SystemMessageSender` push the badge.
  - Ignoring a request, accepting one whose message was already read, or blocking somebody changes the Messages part without a push.
  - The viewer's other open tabs keep the old number until their next page.
  - Before INB-02 requests were not in the header, so this staleness is new.

  Is "also after realtime pushes" (INB-02 Done when) meant to cover these? If so, push from `IgnoreConversationHandler` / `AcceptConversationHandler` / `BlockUserHandler` for the acting player.
- **Q3 · For you privacy canary · resolved — confirmed; one docblock line in the canary.** After INB-03, `PrivateProfileCanaryTest::testOnlyTheFriendSeesHerOnTheirInboxTab` ('inbox for you') asserts negatives only. Its seed row is a `SubscribedPlayerAddedTime`, which is no longer listed. As far as I can see, no listed For you row masks a private player any more: group edits name the editor on purpose, and the other types are bilateral. So nothing is lost. Confirm, and say so in the test's docblock, so the row is not read as a working canary.

## Checked and fine

**INB-02 parity and blocks**
- `unread_conversations` is the old `countUnreadForPlayer` SQL verbatim.
- Hidden ids:
  - `forViewer()` takes the profile row's `hidden_player_ids`;
  - `forPlayer()` takes `GetUserBlocks::blockedIdsOf()`, the same `user_block WHERE blocker_id` set, admin blocks included.
- The conversation parts read `user_block` for the player itself, as the lists do. The blocked side is not told anything (only the viewer's own blocks apply).
- `NotifyOnChatMessageSent` still pushes nothing to a recipient who blocks the sender.

**Pushes and memos**
- Pushes never touch the request-viewer `HiddenPlayers`. Ids are uuid-validated before inlining, and the team fragment's viewer is the notifications' owner.
- `InboxBadge` and `FavoritesFeed` implement `ResetInterface`. `InboxTwigExtension`, `AddEntryTwigExtension` and `FavoritesFeedTwigExtension` are stateless and listed in `NavigationServicesResetTest::EXTRA_CLASSES`.
- Instance `sqlExclude` / `sqlExcludeTeam` SQL is unchanged (ids were already valid and lower-case).

**Stream action and JS (INB-02)**
- `_badge_stream` carries numbers and `at` only (no request, URL or locale).
- `inbox_badge.js` matches `InboxBadgeParts`: total, `99+`, target, the `requests` tab, labelKind and labelCount.
- Labels come from `browser_translation()` pipe plurals, which `chooseTranslation` handles for cs (3 forms) and ja.
- `data-bar-count` is the total.
- The Inbox header tabs' badges update and their hrefs do not.
- Re-apply needs `at > msp-rendered-at`: both are server epoch seconds, and the meta is replaced by Turbo's head merge.

**Blue-green and the interim header**
- An old page receiving the new stream: Turbo 8 `#raise("unknown action")`, caught in `connectedCallback` → `console.error`. The badge refreshes on the next page, as risk-accepted in the plan.
- A new page receiving an old `replace` of `#unread-badge-container`: no target, so nothing happens.
- Interim header: the bell is the Inbox link (For you part), the Messages tool shows the Messages part, both are hidden at 0 (Bootstrap `[hidden]` wins; `.navbar-tool-label` sets no `display`), and the bell's `aria-label` names the count.

**Partial index**
- The predicate `read_at IS NULL AND type NOT IN ('SubscribedPlayerAddedTime', 'NewConversationRequest')` matches the query's inlined literals in the same order.
- The migration, `tests/bootstrap.php` and `docs/database-indexes.md` agree.
- `GetInboxBadgeTest` asserts `Index Only Scan using custom_notification_unread_for_you` with seqscan off.

**Budget pins**
- Every exact signed-in pin is −1 with an "INB-02" comment, and nothing was raised:
  - `DetailPagesQueryBudgetTest` (all rows + the 203-edition measure);
  - `OrganizationPagesQueryBudgetTest`, `OrganizedEventsQueryBudgetTest`;
  - `MyActivityControllerTest`, `ProfileProgressionTest`;
  - `PageSectionsOnPagesTest` signed-in rows (its `12 + 1` rows are guest), `OfficialRoundResultsPageTest`;
  - `PuzzleAddPageQueryBudgetTest`, `PuzzleDetailQueryBudgetTest`;
  - the P0-02 trio (`INBOX` 2 → 1).
- `EventsPageQueryBudgetTest` and `PlayersPageQueryBudgetTest:69` (AtMost) were lowered as well.
- Not touched and allowed to stay: `AchievementHoldersPageTest:241` (AtMost, though §6 lists it as exact), `EventOffersPickerControllerTest` (counts feature statements only), `PuzzlingTeamDetailControllerTest:76`, `ProgressBoardsWindowTest`, `ProgressionPagesTest:144,306`, `PlayerAchievementsPageTest:59`, `LastPieceRevealPagesTest:279` (all AtMost).

**INB-03 audit**
- `SubscribedPlayerAddedTime` appears only in the enum, `GetInboxBadge::NOT_FOR_YOU` and `GetNotifications::sqlListed()`.
- `getOldestUnreadNotifiedAtForPlayer()` has no production caller.
- No e-mail digest, API, export or admin reads notifications: the digests read `chat_message` / `conversation`.
- `DeletePlayerHandler` deletes the player's own rows. `PuzzleSolved` still has its other handlers.

**INB-04a/b**
- The Live action needs `X-Requested-With`, a forced preflight, so it is CSRF-safe.
- A guest gets a no-op. Another player cannot be marked: the id comes from the session profile.
- It is idempotent: `FavoritesSeen::isDue` runs in the action and in the entity, with no UPDATE when unchanged.
- `newSince` is a non-writable, checksummed LiveProp.
- The controller marks once per connect, on `shown.bs.tab`, `feed-swipe:shown` or shown-at-connect, never from `live_refresh`.
- The guest Hub is `public` and viewer-independent (curl).
- Blocked and private players are absent from both the count and the highlight (same rows, canaries).

**ADD-03**
- Laps are written from the clock as `Y-m-d H:i:s` and parsed back in the same default zone, as `LapsArrayDoctrineType` does.
- Exactly 24 h counts as stale (`>=`, D7 "< 24 h").
- A running stopwatch always has an open last lap (`start()` / `resume()`), so "no laps" is unreachable. If such a row existed, `ORDER BY … DESC` puts NULLs first and it would hide a valid one.
- `add_entry_url()`:
  - an unmatched-route 404 renders as a guest, consistent with the rest of the chrome;
  - on a `puzzle_detail` 404 with a malformed id it links `/en/puzzle-add/<id>`, which 404s cleanly (`secretRows()` and `byId()` validate the uuid). `Uuid::isValid()` before using the param would send it to the plain add page.

**INB-05**
- The window is `[DATE, DATE + 30 d)`, midnight Europe/Prague, with `registeredAt < DATE`.
- `LIKE 'navigation\_%'` is correctly escaped under `standard_conforming_strings`.
- `DismissHintController` accepts the new case through `HintType::tryFrom`. It has no CSRF; that is pre-existing, and the cookies are SameSite=Lax.

**Profile row**
- The three additions are in `byUserId()` only. `byId()` rows fall back to the defaults.
- The only caller is `RetrieveLoggedUserProfile`, and `PlayerProfile` is never a LiveProp.

**Translations**
- The 16 new keys are in all 6 locales and none equals English.
- de uses "Sie", fr "vous", es "tú".
- cs plurals have 3 forms, ja has one, and fr `new_count` follows the French < 2 rule.

**Tests**
- Moved intents are kept in their new homes:
  - the count/read/index tests are in `GetInboxBadgeTest`;
  - the bell test is now `testTheBadgeShowsForYou0AndTheMessagesPartOnThePage`;
  - the private-profile notification intent is in `PrivateProfileViewersControllerTest` on Hub › Favorites.
- `NotifyWhenPuzzleSolvedTest` is inverted per decision 49.
- `MarketplaceEventsBannerTest` excludes only the profile-row subselect.
- No test was deleted. Apart from Q3, no assertion was weakened.
