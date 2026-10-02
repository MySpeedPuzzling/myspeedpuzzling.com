# Live activity feed (Hub + Recent activity) — analysis

Status: **built 2026-10-02** (smart polling, ring, ticking labels; Mercure deliberately not used). Jan's decisions are in
§11, what was built and how it was verified in §12.

**Idea.** Make the Hub and the global Recent activity page feel live:
- a small ring fills up until the next automatic refresh;
- the "3 seconds ago" labels count up every second instead of standing still.

**Questions this answers:**
- How often do we refresh today, and what does it cost?
- AJAX or Mercure?
- How do browsers behave with this: PWAs, forgotten tabs, phones?
- How can JavaScript print exactly what Twig `|ago` prints, in all 6 locales?

**Short answer: keep AJAX (the Live Component re-render), but use our own scheduler that knows
whether anybody is looking, instead of `data-poll`.**
- Refresh every 60 s, and only while the page is visible.
- That alone removes ~90 % of today's refresh requests, which come from tabs nobody is looking at.
  People who actually look get data twice as fresh, and the total load stays about the same.
- The ring and the ticking labels share one 1-per-second JS tick and cost practically nothing.

**Mercure is not needed here.** It has room for 1000× our traffic, but this feed is personal:
- Every visitor sees a different list (blocklist, private profiles, own rows).
- So a push could only say "something changed, fetch your copy".
- With a new result every ~43 s, that is no cheaper than polling and needs more code.
- It is an option for later, as a "refresh early" nudge (§4.3).

---

## 1. What runs today (measured on production)

### 1.1 The feed component

- `RecentActivity` Live Component (`src/Component/RecentActivity.php`,
  `templates/components/RecentActivity.html.twig`). Its root has
  `data-poll="delay(120000)|$render"`, so **every instance** re-renders every 120 s:
  - Hub, all activity: `limit 20, showLimit 4`
  - Hub, favourites tab: `loading="lazy"`, polls once it has loaded
  - `/recent-activity`: `limit 100`
  - homepage: `limit 5`, lazy
  - player profile: `limit 20, showLimit 3`
- **What one re-render does:**
  - `POST /{locale}/_components/RecentActivity`
  - a full server render: one `GetRecentActivity` query, plus `GetPlayerBestSoloTimes` for a
    signed-in viewer
  - an idiomorph DOM morph in the browser
- **The library's polling is a plain `window.setInterval`** (vendor `live_controller.js`,
  `PollingDirector`):
  - It ignores whether the page is visible.
  - It restarts after every render.
  - An open tab polls for as long as the browser lets its timers run.
- The time label `{{ item.trackedAt|ago }}` (`.lb-time-date`) does not change between refreshes.

### 1.2 Production numbers

Sources: Tempo (100 % of Traefik spans), the database, Mercure's own log.

| What | Number |
|---|---|
| Feed polls, Thu 12:00–24:00 UTC | **7,281** from 289 clients (IP + browser) |
| … from clients seen in ≥ 3 of those 12 hours | **6,599 (91 %)**, 70 clients |
| … from clients seen in ≥ 6 of the 12 hours | 4,608 (63 %), 26 clients |
| Busiest client | 351 polls in 12 h: one tab polling all night (Windows Chrome in Australia, 22:00–10:00 local time) |
| Share of polls from phones | 5.6 % |
| Polls among all MSP requests | 2–4.5 % (10-min samples: 46–140 polls vs 2.0k–3.9k requests) |
| Poll latency at the edge (16–17 UTC, 635 polls/h) | p50 59 ms · p90 180 ms · p99 327 ms |
| Page views that hour | Hub ~94/h · `/recent-activity` ~3/h |
| New results (`tracked_at`, last 14 days) | 1,136/day; hourly average 20 (04 UTC) to 83 (18 UTC); busiest single hour 159 |
| Gap between two consecutive new results (7 days) | median **43 s** · p90 3.1 min · p99 8.5 min |

What this means:

1. **Server cost is not the problem.** ~0.2 requests/s at 60 ms each is nothing for the web
   service. We can afford fresher data for the people who are actually looking.
2. **Almost all of today's polling is wasted** on desktop tabs that stay open for hours. Desktop
   browsers keep running a 120 s timer in a hidden tab; phones freeze them (§5). Two things fix it:
   stop while the page is hidden, and stop when nobody has touched the page for a while.
3. **There is almost always something new.** With a new result every ~43 s, a 60 s refresh
   usually brings one. A cheap "has anything changed?" request before rendering would rarely save
   anything, so it is not worth building.

### 1.3 Mercure today

- **Production:**
  - a separate `dunglas/mercure:v2.11.3` container behind Traefik (`/.well-known/mercure`, served
    directly over HTTP/2 + HTTP/3, `anonymous` allowed);
  - at 09:56 UTC it used **31 MiB of RAM and 0.13 % CPU, with 99 open TCP connections**.
- **Every signed-in page view already opens an EventSource:**
  - `templates/base.html.twig` → `mercure_hub_controller.js`;
  - topics: `/unread-count/{id}`, `/conversations/{id}`, plus per-page topics from
    `MercureTopicCollector`;
  - Turbo replaces the body on navigation, so the connection is reopened on every page: 11.3k
    subscribe requests in 12 h, 579 different players overnight;
  - guests never connect.
- **Every connection ends after ~595 s, and the browser reconnects.** That is Mercure's
  `write_timeout` default of 600 s; the heartbeat default is 40 s.
  - Traefik's `readTimeout` (60 s by default in v3) does not cut SSE streams. Only 70 of 6.5k
    connections ended between 55 and 65 s, and Go's server code confirms it (§5.6).
- **Capacity:** the Mercure FAQ quotes 40,000 concurrent connections on an EC2 t3.micro with the
  open-source hub. **Capacity is not a reason against Mercure**; we use ~0.25 % of that.

### 1.4 How `|ago` works (`src/Services/RelativeTimeFormatter.php`)

- **Logic:**
  - It computes `$now->diff($from)` and takes the largest non-zero unit of `y, m, d, h, i, s`.
  - It **cuts off, never rounds**: 59 min 59 s is "59 minutes ago".
  - 0 s gives `diff.empty` ("now").
- **PHP runs in UTC**, so daylight-saving changes never matter.
  - Below 28 days, the days, hours, minutes and seconds are simply the elapsed seconds divided
    down (`floor(s/86400)` and so on).
  - From 28 days on, months come from calendar arithmetic.
- **The strings are hand-written** in the translation domain `time`, using Symfony's interval
  syntax. Examples:
  - cs `před hodinou|[2, Inf]před %count% hodinami`
  - cs `{1}včera|{2}předevčírem|[3, Inf]před %count% dny`
  - de `vor einer Stunde|vor %count% Stunden`
  - ja `%count%分前`
- **The browser's built-in `Intl.RelativeTimeFormat` gives different wording** ("před 1 hodinou",
  "vor 1 Stunde"), so we cannot use it: a label would change wording the moment JavaScript took
  over. The JS has to use our own strings and Symfony's rules for picking a plural form (§7).

---

## 2. The deciding constraint: everybody's feed is different

`GetRecentActivity::latest()` returns a different list for every viewer:

- **Blocklist (`HiddenPlayers`):** a blocker must never see the player they blocked. The blocked
  player must not be able to tell, so the filter runs on the server, per viewer.
- **Private profiles (`PrivateProfileAccess`):** a private player is visible only to viewers on
  their allow list.
- **Own rows:** they are highlighted (`table-active-player`) and carry the edit/delete menu. The
  "My time" line shows the viewer's own best.

**So one rendered HTML cannot be sent to everybody.**
- A Turbo Stream on a public topic would show blocked and private players, breaking the
  blocklist's safety promise.
- A push message can only be an empty nudge ("the feed changed"). Each browser then fetches its
  own copy, exactly as a poll does.

## 3. Options compared

| | A. Smart polling (**recommended**) | B. Mercure nudge, then fetch | C. Mercure sends the HTML | D. WebSocket |
|---|---|---|---|---|
| Delay before a new row appears | up to 60 s | ~1–3 s | ~1 s | ~1 s |
| Server renders | visible tabs × 1 per minute | visible tabs × every nudge (or a cap): **at least as many as polling**, because results arrive every ~43 s | 1 per event | – |
| Personal feed (blocklist, private) | ✅ | ✅ each browser fetches its own | ❌ **shows hidden players** | – |
| Guests | ✅ | need a new anonymous SSE connection (the base layout connects signed-in users only) | | |
| New server code | none | publish from every handler that changes the feed (solved, edited, deleted, merges, blocks…), asynchronously after commit | | new infrastructure |
| When it breaks | one missed refresh, fixed by the next | the feed silently stops (iOS resume, Turbo reconnect gap, hub restart), so A is needed as a fallback anyway | | |
| Fits the ring idea | ✅ the ring *is* the schedule | ❌ no schedule; a "● Live" dot instead | | |

- **C is out:** it shows players who must stay hidden.
- **D is out:** new infrastructure for traffic that only flows server → browser, which SSE covers.
- **B costs little but gains nothing measurable.** It does not reduce load, because results arrive
  faster than any sensible refresh interval. It adds a second code path that still needs A as its
  fallback. Its only gain is a few seconds of delay instead of up to 60.

## 4. Recommended design: smart polling

### 4.1 Scheduler: a `live-refresh` Stimulus controller around the ring and the component(s)

It replaces `data-poll` (the library's polling plugin) and calls `getComponent(el).render()`.

- **When to refresh:**
  - **Every 60 s** on the Hub (both tabs) and `/recent-activity`.
  - Homepage and profile: **no auto-refresh** (decided, §11).
    - The homepage is mostly guests arriving, and it is an indexable page (layout-shift score, §9).
    - A single player's feed rarely changes.
    - Both pages still get the ticking labels.
- **When to stop:**
  - **Page hidden:** cancel the timer.
  - **Visible again:** if the last refresh is older than the interval, refresh now; otherwise
    continue with the time that was left.
  - **Nobody has touched the page for ~30 min** (pointer, key, scroll, touch): stop and show the
    ring as paused, "tap to refresh". This catches tabs that are visible but unwatched (second
    monitor, empty desk), which the browser cannot report.
- **Coming back:**
  - A page restored with the back/forward buttons (`pageshow` with `event.persisted`) refreshes
    now if a refresh is due.
  - The same on Chromium's `resume` event: Android freezes hidden pages after about a minute (§5).
- **Errors:**
  - Offline (`navigator.onLine === false`) or a failed render: wait longer each time
    (60 → 120 → 300 s) and show the ring as paused.
  - Never retry in a tight loop.
- **Don't refresh while someone is using the list:** if a row's actions menu is open, or focus is
  inside the component, try again one tick later. Otherwise the morph closes the menu under the
  user's finger; today that happens once every 2 minutes.
- **±5 s jitter,** so tabs restored together (after a deploy reload or a browser restart) do not
  stay in step.
- **A render triggered by something else also counts** (lazy load, an action): listen to
  `render:finished` and restart the countdown.
- **After a deploy, nothing changes:** an old tab still reloads on its next refresh through
  `StaleLiveComponentPageSubscriber`, now only once someone looks at it.

### 4.2 Updating the list

- **Give every row a stable `id`** (`activity-{{ item.id }}`).
  - With ids, idiomorph **inserts** the new row and moves the others down.
  - Without ids it matches rows by position and rewrites every row, including all 20
    `<img src>`.
  - The ids also allow a short background-colour highlight on new rows, which does not move
    anything on the page.
- **"Show more" bug, to confirm in the prototype:**
  - The button is removed by JS.
  - Live Component's `ExternalMutationTracker` remembers class changes on elements that still
    exist, but not removed elements, so the next refresh adds the button back.
  - Revealed rows stay revealed, because the removed `d-none` class is remembered.
  - **Fix:** toggle one class on the wrapper (`is-expanded`, which is remembered) and let CSS
    reveal the rows and hide the button.

### 4.3 Optional later: Mercure as an early nudge (only if 60 s feels slow)

- **How it would work:**
  - Signed-in visitors already have an SSE connection. Add a public topic `/recent-activity` to the
    Hub's topics (`MercureTopicCollector::addTopic()`); that costs no extra connection.
  - Publish an empty nudge from the solved / edited / deleted handlers, asynchronously, after
    commit.
  - On a nudge, the browser refreshes now: at most once every 15 s, and only while visible.
  - The ring stays and shows the longest you will wait; a nudge simply ends the wait early.
  - Guests stay on plain polling.
- **What it costs:** the publish code, plus making the browser connection survive real life. Per
  §5 it would need to:
  - reopen the EventSource every time the page becomes visible again (on iOS 18 it can look open
    while it is dead);
  - reconnect by itself after a non-200 answer (the browser gives up for good after one);
  - close it on `pagehide`, so Chrome can still keep the page in its back/forward cache.

## 5. Browsers, phones, PWAs, forgotten tabs

Research summary. Where a documentation page and the browser's source code disagreed, the source
code won. Anything not verified is marked.

### 5.1 Timers in hidden tabs

- **Chrome, desktop:**
  - Hidden tabs: timers wake at most once per second.
  - "Intensive throttling" then aligns repeating timers to whole minutes. It starts once the page
    has been silent for 30 s ([Chrome 88 post](https://developer.chrome.com/blog/timer-throttling-in-chrome-88)).
    The docs say it starts after 5 min hidden; the current source says 60 s for pages that had
    already rendered ([features.h](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/platform/scheduler/common/features.h)).
  - **A 60–120 s poll keeps firing for hours**, at most a minute late. That is exactly what
    production shows.
  - Energy Saver freezes a tab only when it is on (unplugged or low battery), the tab has been
    hidden for over 5 min, and it uses a lot of CPU
    ([post](https://developer.chrome.com/blog/freezing-on-energy-saver)). A poll does not count.
  - Memory Saver may discard old tabs, which reload when reopened.
- **Chrome, Android:** hidden, silent pages are **frozen after 1 minute**: no timers, no event
  handlers ([features.cc](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/common/features.cc)).
  This is why phones hardly poll.
- **Firefox:** desktop timers wake at least once per second, with a time budget on top. Android
  wakes them at most every 15 min and may unload the tab
  ([MDN](https://developer.mozilla.org/en-US/docs/Web/API/Window/setTimeout)).
- **Safari, macOS:** hidden-page timers align to 1 s
  ([DOMTimer.h](https://github.com/WebKit/WebKit/blob/main/Source/WebCore/page/DOMTimer.h)).
- **iOS (Safari and installed PWA):** JavaScript is suspended seconds after the app goes to the
  background. No timers run at all.

**Conclusion:** we cannot rely on the browser to throttle desktop tabs. Stopping on
`visibilitychange` is our job.

### 5.2 Phones and the installed PWA (`site.webmanifest` is `display: standalone`)

- **Android:** a locked screen or a switch to another app makes the page hidden, and it is frozen
  about a minute later. Work queued while frozen runs on resume. The OS may also kill the process,
  which means a reload.
- **iOS:** everything stops. An open SSE connection drops after ~20 s in the background.
  - iOS 17 fired `error` on return, and the browser reconnected by itself.
  - **iOS 18 regression:** no `error`, and `readyState` stays OPEN on a dead connection
    ([Apple forum](https://developer.apple.com/forums/thread/765183)). Whether iOS 26 fixed it is
    unverified.
  - The SSE heartbeat (`:` comment lines) never reaches JavaScript, so it cannot be used to detect
    the dead connection.
- **What this means for polling:** nothing, as long as the scheduler refreshes on
  `visibilitychange` → visible when a refresh is due. For any SSE: reopen the connection on visible.

### 5.3 Visibility, back/forward cache, the Page Lifecycle

- **Use `document.visibilityState`, not focus/blur.** Focus is wrong in split-screen setups.
  Going hidden is the last event that reliably fires; phones never fire `unload`
  ([MDN](https://developer.mozilla.org/en-US/docs/Web/API/Document/visibilitychange_event)).
- **iOS gaps:** opening the App Switcher fires nothing
  ([WebKit 206213](https://bugs.webkit.org/show_bug.cgi?id=206213)), so the page counts as visible
  until the user really switches away. Harmless for us. Installed PWAs have fired visibility events
  since iOS 12.
- **Page Lifecycle:** `freeze`/`resume` exist in Chromium only. All major browsers have a
  back/forward cache, so handle `pageshow` with `event.persisted`.
- **An open EventSource and Chrome's back/forward cache:** a request that never finishes, as an SSE
  stream doesn't, gets the page evicted from the cache
  ([resource_loader.cc](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/platform/loader/fetch/resource_loader.cc)).
  Safari cancels the stream on entry and reconnects on restore. A polling page with no request in
  flight stays eligible.

### 5.4 Animation cost

- **Chromium runs only some animations off the main thread:** opacity, transform, filter,
  background-color and clip-path
  ([compositor_animations.cc](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/core/animation/compositor_animations.cc)).
  - **`stroke-dashoffset` and an `@property` conic-gradient both repaint on the main thread.**
  - **`steps(n)` does not reduce frames in Chromium:** an animation that cannot run off the main
    thread asks for an update every frame, whatever its easing
    ([animation.cc](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/core/animation/animation.cc)).
- **What this means for the ring:** no CSS/Web Animations animation. JS sets the progress once per
  second from the tick that already exists for the labels. That is exactly one style update and
  one paint of a 24 px box per second, and nothing in between.
- **A smooth alternative:** two half-circles turned with `transform: rotate`. It runs off the main
  thread, but still produces a frame every vsync.
- **A hidden tab** renders nothing and does not run `requestAnimationFrame`. This was confirmed on
  the local Hub: in a hidden window, `requestAnimationFrame` never fired.

### 5.5 Text that ticks every second

- **Layout shift (CLS)** counts only elements whose start position moves. Chrome ignores moves
  under 3 px and also tracks text nodes. Shifts within 500 ms of a tap or keypress are excluded,
  and the score is the worst window of up to 5 s
  ([layout_shift_tracker.cc](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/core/layout/layout_shift_tracker.cc),
  [web.dev](https://web.dev/articles/cls)).
  - **Risk:** auto table layout widening a column ("9 seconds" → "10 seconds"), and right-aligned
    cells (ours is right-aligned).
  - **Fix:** tabular digits, and a reserved `min-width` in `ch` (§7.3).
- **Responsiveness (INP):** writing 20–100 text nodes once a second is far below the 50 ms
  long-task threshold. Write only when the string changes, and never measure layout inside the loop.
- **Accessibility:**
  - Never put `aria-live` on the ticking labels.
  - Use `<time datetime>`.
  - Optionally, announce new rows through one polite status region ("2 new results").
  - WCAG 2.2.2 (Pause, Stop, Hide) asks for a way to pause, or control the frequency of, content
    that updates by itself ([W3C](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html)).
    That applies to the feed today as well.

### 5.6 SSE notes (only relevant for §4.3)

- **Connection limit:** the 6-connections-per-site limit applies only to HTTP/1.1. We serve
  HTTP/2 and HTTP/3, where many streams share one connection.
- **Browser reconnect delay:** 3 s in Chrome and Safari, 5 s in Firefox.
- **A non-200 answer closes the EventSource for good:** `CLOSED`, no further reconnect. For
  example, a 502 while the Mercure container restarts
  ([WHATWG](https://html.spec.whatwg.org/multipage/server-sent-events.html)).
- **Traefik v3's `readTimeout` does not cut SSE GET streams.** Go drops the read deadline once the
  request has been read; with HTTP/2 it only covers the request body.

## 6. Load and cost: expected outcome

- **Today, 16–17 UTC:** 635 polls/h, ~90 % of them from tabs open for hours.
- **Proposed:** only visible Hub tabs refresh, 60 times an hour.
  - ~94 Hub views/h, assuming ~5 min visible each → ~8 tabs visible at once → **~500 polls/h**,
    plus a few on `/recent-activity`.
  - Overall about **the same or fewer requests than today**.
  - Everybody actually looking gets data **twice as fresh**; forgotten tabs cost nothing.
- **Idle cap:** a visible tab nobody touches stops after 30 min, so the number stays bounded even
  if the Hub becomes popular.
- **Browser, while visible:** one wake-up per second, one tiny paint, a few text compares. Similar
  to a clock on the page.
- **Browser, while hidden:** nothing.
- **Mercure:** unchanged.

### 6.1 Scale check: a competition with ~10,000 people watching

Jan's concern: no load data exists for a big event, and 10k visitors at once is plausible.

**PHP: the cost of every "each browser fetches its own copy" design grows with viewers.**
- Smart polling at 60 s: 10,000 / 60 ≈ **170 renders/s**. At ~60 ms each that keeps about 10 CPU
  cores busy, on a 16-core box shared with other apps.
- A Mercure nudge followed by a fetch is worse: up to 10,000 renders within seconds of every nudge.
- So, at that scale, polling only holds if the guest version of the feed is cached (it is
  identical for all guests in one locale).

**The way that does not grow with viewers: push the content, rendered once per locale.**
- **Per new result:** the server renders the new row **as a guest would see it**, once per locale
  (6 renders), and publishes it to `/recent-activity/{locale}`. Mercure does the fan-out.
- **Why showing it to everybody is safe:** guests already see everyone (blocklist and private
  profiles apply to signed-in viewers only). So the guest version reveals nothing that is not
  public already.
- **Personal parts:**
  - The browser marks the viewer's own rows by player id.
  - "My time" appears on the next full fetch.
  - **Viewers whose feed differs from a guest's** (anyone with a block or an allow-list entry;
    `HiddenPlayers` already knows when the list is empty) **ignore the pushed rows and fetch their
    own copy** with jitter. They are few, so this stays cheap.
  - Never send a viewer their list of hidden ids: an admin block must stay invisible to the
    blocker.
- **Disconnecting inactive tabs:**
  - Close the connection after ~60 s hidden, or after 30 min with no interaction.
  - Reopen when visible again, asking Mercure to replay the missed events (`Last-Event-ID`, from
    its Bolt history). If too much was missed, fetch the list once instead.
  - Always recreate the connection on iOS resume (§5.2).
  - Close on `pagehide` (back/forward cache).
  - After errors, reconnect with backoff and jitter. Otherwise a Mercure restart turns 10k
    browsers into a reconnect storm within 3 s.

**What lily has (probed 2026-10-02, read-only):**

| | Now | At 10k streams |
|---|---|---|
| Host | 16 cores, 125 GB RAM (103 GB available), 1 Gbit NIC; load avg 19 at the time of the probe (shared box) | – |
| Mercure container | `mem_limit 256m`, 31 MiB used | **raise the limit**: memory per connection was not measured, assume 10–25 KB → 100–250 MB |
| Traefik (shared by all lily apps) | 271 MiB of its 1 GiB limit | every stream is held here too; the memory headroom has to be measured |
| Open files (Traefik, Mercure) | 1,048,576 | fine |
| conntrack | 1,048,576 max, ~1k used | fine |
| Traefik → Mercure local ports | 32768–60999 = **~28k** | a hard ceiling of ~28k concurrent streams per Mercure container |
| Stream compression | **none** (no `Content-Encoding` on SSE) | no per-connection compressor memory, but full bytes on the wire |
| CrowdSec bouncer | `stream` mode (local decision cache) | reconnects do not hit the CrowdSec API |
| Rate limit | 100 req/s average, burst 200, **per IP** | a venue Wi-Fi puts hundreds of people behind one IP. Page loads alone could hit this, whatever the transport. Allow the venue IP before an event |

**Bandwidth:**
- One rendered row is ~2–3 KB of HTML. 10k subscribers × 20 results/min (the end of a round)
  ≈ 10 MB/s ≈ **80 Mbit/s** of the 1 Gbit NIC.
- A compact JSON row (~0.4 KB) would bring that to ~15 Mbit/s. The cost is a row template that
  JavaScript renders, i.e. a second copy of the Twig row.

**Conclusion:**
- **Now** (~10 tabs visible at once): smart polling. Build the browser side so the source of a
  refresh can be swapped: timer, nudge, or pushed row.
- **Before a big event:** render once and push, as above, with polling kept as the fallback.
  Precondition: **a load test** (Mercure ships a Gatling scenario) through the real Traefik path.
  It has to measure memory per stream in Mercure and Traefik, how long one message takes to reach
  every browser, and how bad a reconnect storm gets. Every figure above marked as assumed is an
  estimate until then.
- **Live round results during events** are the likelier place for this crowd. They would use the
  same pipeline: a public, render-once payload.

## 7. Ticking "… ago" labels, worded exactly like Twig `|ago`

### 7.1 Markup

```twig
<time class="lb-time-date" datetime="{{ item.trackedAt|date('c') }}">{{ item.trackedAt|ago }}</time>
```

The server's text stays the source of truth. JS only replaces it with an identical, newer string;
without JS, nothing changes.

### 7.2 One formatter, one tick per page

- **`assets/relative_time.js` ports two things (~60 lines):**
  1. `RelativeTimeFormatter::formatDiff()` for less than 28 days: seconds, minutes, hours and days
     by division, cut off. 0 or negative → "now".
  2. Symfony's plural-form choice (`TranslatorTrait::trans()`): explicit intervals (`{1}`,
     `[2, Inf]`, `]a,b[`) plus Symfony's plural index for cs, en, de, fr, es and ja.
- **From 28 days on, JS leaves the server text alone.** Porting month arithmetic is not worth it;
  those labels change once a month.
- **The strings come from a data attribute** (house rule for Stimulus texts):
  - the raw entries `diff.ago.second|minute|hour|day` and `diff.empty`, once per page, never per row;
  - e.g. a Twig function `relative_time_messages()` reading
    `$translator->getCatalogue($locale)->get($id, 'time')`.
- **Never trust the visitor's clock.** The stale-document detector exists because phones run
  minutes off.
  - Every render carries the server time (`data-…-server-now-value="{{ 'now'|date('U') }}"`).
  - JS keeps `offset = serverNow − Date.now()` and recomputes it on every refresh.
  - A result "from the future" because of a wrong clock shows "now".
- **One tick for the whole page,** not one timer per row:
  - It wakes when the next label (or the ring) would change: every second while something is under
    a minute old or the ring is counting, otherwise at the next minute boundary.
  - It writes `textContent` only when the string differs.
  - It stops while the page is hidden and catches up immediately when it is visible again.
  - Cost: a few dozen string compares per tick, well under a millisecond even for
    `/recent-activity`'s 100 rows.
- **Change text only, never attributes.**
  - Live Component's `ExternalMutationTracker` ignores text nodes (it only tracks `Element`s), so
    the next refresh replaces our text with the server's fresh text.
  - A changed attribute (`title`, `class`) *would* be remembered and laid back over the fresh
    server value after every refresh.
- **Parity test:**
  - A PHPUnit test runs the JS under node (same pattern as `ServiceWorkerBehaviourTest`; node is in
    the base image).
  - It checks 6 locales × counts {0, 1, 2, 3, 4, 5, 11, 21, 22, 59} × every unit for exactly the
    same output as `RelativeTimeFormatter`.
  - A changed `time.*.xliff` string or PHP rule then fails in CI, not in production.
- **Rejected:**
  - `Intl.RelativeTimeFormat`: different wording (§1.4).
  - `symfony/ux-translator`: a whole bundle and a build-time translation dump for five strings; we
    prefer small in-house code.

### 7.3 Layout

- **The problem:**
  - `td.ps-time` has `width: 1%; white-space: nowrap`, so the column is as wide as its widest
    label, and its text is right-aligned.
  - "59 seconds ago" → "1 minute ago" can resize the column and rewrap the puzzle name next to it.
- **Fix:** tabular digits plus a reserved `min-width` (in `ch`, per breakpoint) on `.lb-time-date`.
- **Verify** in the prototype with a `PerformanceObserver('layout-shift')`. It has to run in a
  window that is actually in front; a hidden window produces no frames and so no entries.

## 8. The status line and its ring

Jan's call after seeing the first version: a short text with the ring at its end, right-aligned above the table,
instead of a bare ring next to the tabs. The ring fills smoothly instead of once a second.

- **What and where:** "Auto-update in 42 seconds ◯" at 11 px (`.6875rem`), grey text, ring in the
  brand colour (`--cz-primary`).
  - It sits in the 1.5rem gap above the table (`.ps-wrapper.mt-4`): absolutely positioned at the
    bottom of `.live-refresh-anchor`, so it adds no height.
  - The whole line is a 24 px tall button (WCAG 2.5.8 target size).
  - Partial: `templates/_live_refresh_status.html.twig`.
- **The text** changes once a second; the tick wakes exactly when the whole seconds left change.
  - Running: "Auto-update in N seconds".
  - While the refresh is on its way: "Updating…".
  - Paused: "Auto-update paused" ("… while you were away" when idle).
  - After a failure: "Update failed, retrying in N seconds".
  - On a guest's favourites tab there is nothing to count down, so the line is hidden.
- **Plural forms in the browser:** `browser_translation()` (`BrowserTranslationTwigExtension`)
  hands over the raw message plus the locale of the catalogue that defines it.
  `assets/translation_choice.js` (Symfony's choice rules, shared with the labels) then picks the
  form exactly like PHP does, including a key that exists only in English on a Czech page, which
  falls back to English rules. Pinned by `RelativeTimeParityTest::testCountdownTextReadsLikePhpInEveryLocale`.
- **The smooth ring:** two half-circles, each clipped by its half, turned with `transform: rotate()`
  by two Web Animations that the controller starts for every refresh cycle (`currentTime` = time
  since the last refresh).
  - Only `transform` animates, so the browser runs it on the compositor with no main-thread paint
    per frame. Animating `stroke-dashoffset` or a conic-gradient would repaint every frame (§5.4).
  - It still produces frames while it runs. Paused, idle, failed or hidden means no animation at all.
  - **Reduced motion** (`prefers-reduced-motion`): the same animations with `steps(n)`, one step per
    second.
- **It is the pause / resume button** (WCAG 2.2.2, decided in §11):
  - While counting, a click pauses (`aria-pressed="true"`, both animations cancelled).
  - A click on a paused, idle or failed line resumes and refreshes at once.
  - `aria-label` "Pause automatic updates" stays the same; the tooltip (`title`) names the state.
    The ring is `aria-hidden`.

## 9. Risks

**A new row at the top pushes the list down with no user input.**
- That counts toward CLS. CrUX also scores the site as a whole, so even the noindex Hub feeds the
  site-wide score.
- From the Hub's measured layout (rows 107 px, list starting at 302 px, 1280×857 window):
  **~0.02 per insert on desktop.** Estimated **~0.06 on a phone** (not measured).
- The same already happens today, every 2 min.
- **Fix:** measure in the prototype. If it is too high, either:
  - show a "N new results" pill that inserts on tap (taps are excluded from CLS, and nothing jumps
    while someone reads), or
  - insert only while the top of the list is off-screen.

**Other risks:**

| Risk | Mitigation |
|---|---|
| Labels change width every second (CLS) | Reserved width (§7.3) |
| A refresh closes an open menu | Wait one tick (§4.1) |
| JS and Twig wording drift apart | Parity test (§7.2) |
| Visitor's clock is wrong | Offset from server time (§7.2) |
| A phone tab or PWA resumed hours later shows old rows | `visibilitychange` / `pageshow` / `resume` → refresh at once if due; the tick recalculates |
| A deploy changes component signatures | Existing reload, now only for tabs someone looks at |
| More requests than today | Not expected (§6); at most visible tabs × 1 per minute, capped by the idle stop |

## 10. Side findings (outside this feature)

1. **The site-wide Mercure connection (`mercure_hub_controller.js`) has no recovery logic.** Turbo
   recreates it on every navigation, which hides most of it. But on a page that stays open:
   - **iOS 18 + resume:** the connection can look open while it is dead (§5.2), so unread counts
     and chat updates stop until the next navigation.
   - **Any non-200 answer** (e.g. Mercure restarting behind Traefik) closes it for good.
   - **The open stream gets every signed-in page evicted from Chrome's back/forward cache**, which
     makes Back slower for every signed-in visitor.

   **Fix:**
   - close the connection on `pagehide`;
   - recreate it on `pageshow` and on visible-after-hidden;
   - reconnect with backoff when it reports CLOSED.
2. **The homepage and player profile feeds poll every 2 minutes.** This is part of the 91 % above.
   Stopped by this feature (§11).
3. **Traefik HTTP/3 timeout advisory: confirmed and fixed 2026-10-02.** The vendor record is
   GHSA-7ghq-v6jf-g56c / CVE-2026-88012; CVE-2026-88878 is a duplicate. lily's Traefik went from
   v3.7.5 to v3.7.13, digest-pinned, with a ~5 s blip. That also closed four high-severity 3.7.x
   advisories. Recorded as D73 in the lily.srv repo.

## 11. Decisions (Jan, 2026-10-02)

1. **Interval: 60 s.**
2. **Auto-refresh on the Hub and the global Recent activity page only.** Homepage and profile do not
   refresh; their labels still count up.
3. **New rows appear by themselves**, no "N new results" pill.
4. **Stop after 30 min without interaction**, and resume on the next interaction.
5. **WCAG 2.2.2:** the ring pauses and resumes (§8).
6. **No Mercure** for now.

## 12. What was built (2026-10-02)

- `assets/controllers/live_refresh_controller.js`: the scheduler and the ring (§4.1, §8). It sits on
  a wrapper around the ring and the component(s): the Hub's left column, the Recent activity page.
- `assets/page_ticker.js`: the one page-wide timer. It sleeps while the page is hidden and wakes on
  `visibilitychange`, `pageshow` and `resume`.
- `assets/relative_time.js`: `|ago` + Symfony's plural choice, for < 28 days.
- `assets/controllers/relative_time_controller.js`: on the component root, counts the labels up.
- `templates/_live_refresh_status.html.twig`: the status line and ring (§8);
  `assets/styles/live_refresh.scss`, imported from `app.js`.
- `assets/translation_choice.js` + `BrowserTranslationTwigExtension` (`browser_translation()`):
  plural forms in the browser, chosen like PHP's (§8).
- `templates/components/RecentActivity.html.twig`:
  - no `data-poll`;
  - row ids `activity-{all|favorites|player}-{id}`;
  - `<time datetime data-relative-time>`;
  - server time, locale and `relative_time_messages()` on the root;
  - "Show more" toggles `.ra-list.is-expanded` and rows past `showLimit` carry `ra-extra`.
- `RelativeTimeFormatter::browserMessages()` + Twig `relative_time_messages()`.
- **Tests:**
  - `RelativeTimeParityTest` (node): 6 locales × 2 reference dates × 40 durations equal `|ago`, plus
    the < 28-day cut-off and a slow-clock "now";
  - Hub / Recent activity / profile markup tests (ring only where it refreshes, no `data-poll`
    anywhere, labels with `datetime`, unique row ids).
- **Stuck-request workaround:** Live Component never clears a request whose `fetch` rejected, so the
  component would ignore every later render on that page. The controller clears exactly that request
  and backs off (follow-up in `docs/TODO.md`).

**Checked in a real browser** (Selenium Chrome of the dev stack):

| Check | Result |
|---|---|
| Ring (second version) | two compositor animations; the right half 25 % in after ~7 s, the left delayed by half the cycle; `steps(30)` under reduced motion |
| Status text | "Auto-update in 52 seconds" → "… 50 seconds" over 2 s; fills exactly the 27 px gap between tabs and table |
| New result | inserted at the top by id, the old first row moved to second; 20 rows, still 4 visible |
| Label of a new row | "1 second ago" → "4 seconds ago", one step per second |
| Layout shift of an insert (desktop) | **0.0194**: the §9 estimate, an existing behaviour that is now once a minute |
| Layout shift of a label changing width | 0.00025: negligible, no width reserved |
| "Show more", then a refresh | still 20 rows shown, button stays hidden |
| Pause | `aria-pressed="true"`, "Updates paused" |
| Resume | refreshes at once |
| Idle stop (shortened to 3 s for the test) | no refresh for 9 s at an 8 s interval; a key press resumes and refreshes |
| Hidden tab, 25 s at an 8 s interval | **0 refreshes**; one refresh 136 ms after coming back |
| Phone width | tab line continues under the ring, no sideways scroll |
