# Notifications

The bell in the top bar, its dropdown and the notifications page (`/en/notifications`, all 6 locales).

## What the reader sees

- **Bell**: the unread count (`GetNotifications::countUnreadForPlayer()`, an index-only scan of
  `custom_notification_unread` on every page). Guests get the plain bell, which leads to sign-in.
- **Dropdown**: a click on the bell opens the newest 8 (`NotificationsDropdownController::SIZE`) with **Show all** under
  them. `notifications_dropdown_controller.js` loads them into `<turbo-frame id="notifications-dropdown">` on the first
  open only, so a page view that never opens it costs nothing. The answer marks everything read, like opening the
  page; the bell's count then goes to 0 without a reload. A failed load leaves the spinner and is tried again on the
  next open. `notifications_dropdown` answers only its frame (anything else is redirected to the page), `private,
  no-store`. A middle click on the bell still opens the page.
- **Page**: 30 at a time (`NotificationsController::PAGE_SIZE`). **Show more** loads the next 30 into
  `<turbo-frame id="notifications-from-<offset>">` (`?offset=`), which carries the next "Show more" - no JavaScript of
  its own. The frames have `target="_top"`, so the rows' links open their pages; only the "Show more" link names its
  frame. The first page marks everything read; the later pages read only.
- Every row comes from one partial, `notifications/_rows.html.twig` - the page and the dropdown show the same thing.

## The query (`GetNotifications::forPlayer()`)

One UNION branch per kind of notification (solving time, lending, change/merge request, rating, conversation,
moderator role, official result, pair/team, guest link, partner finder ×2, borrow request). **The page is picked
first**: a `MATERIALIZED` CTE `page` takes the reader's notifications from `notification` alone - with the branches'
hidden-player rule for that table (`notHidden()`, also used by the badge) - ordered by `notified_at DESC, id DESC`,
`LIMIT`/`OFFSET`; every branch then joins only those rows, and the union is put back in the same order (`notified_at DESC, notification_id DESC` - `notified_at` alone ties within a second, and a tie must not push a row past the one extra row "Show more" asks for).

Why, measured on production on 2026-10-26 (4.55M notifications, a player holds up to 22k):

- Before, every branch read all of the reader's notifications and the outer `LIMIT` came last: the solving-time
  branch grouped all 22k rows of the heaviest readers before 200 were kept (296 ms on production, with statistics).
- A branch whose `target_*` column has **no statistics** - a new column, until the 4.5M-row table is analyzed again,
  which takes weeks of autoanalyze - was planned from the `actor_player_id` index and filtered all 4.55M rows: the
  partner finder branches (#286, 2026-10-22) and the borrow request branch (#288, 2026-10-26) cost ~0.8 s each, on
  every notifications page of every player (median player 2.3 s). `ANALYZE notification` (2026-10-26, by hand) fixed
  the plans; the page CTE makes them not depend on statistics again.
- With the page CTE, on production: heaviest reader 40 ms (page or dropdown), p90 reader (3.8k) 12 ms, median 4 ms.

A new branch reads `FROM page notification` like the others - never `FROM notification`.

A page can come back shorter than asked when a branch drops one of its rows (its target vanished between the two,
or a hidden player in a group). "Show more" is decided by asking one row more than the page.

## Retention (`myspeedpuzzling:prune-read-notifications`)

Daily cron. It deletes **read** "a puzzler you follow added a time" (`SubscribedPlayerAddedTime`) notifications older
than 90 days. That type is 99.7 % of the table: on 2026-10-26, 4,528,267 of 4,543,477 rows, 2.95M of them read and
older than 90 days. A feed item seen once is not looked for again, and the results stay on the puzzler's profile.

The cron keeps:

- **unread** notifications;
- **every other type**: lending, moderation, requests, results - about 18k rows in all.

It deletes in batches of 50k rows, each in its own transaction (`PruneReadNotifications`, one message per batch;
the command repeats until a batch comes back short). On a local copy with 4.55M rows a batch takes 0.3–2.5 s.
The first production run is ~60 batches, about 1–3 minutes. After that a day brings ~13k new rows (max 47k).

Numbers to come back to (2026-10-26):

- **Unread rows of inactive players**: 488k unread rows belong to 1,097 players not seen in 90 days. One active
  player has 18,201 unread and last read in 2024. Pruning old unread rows was left out on purpose.
- **Read delay**: half of all notifications get read within 7 days, 90 % within 27 days, and 33 % never.
