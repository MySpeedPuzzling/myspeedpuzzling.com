# Navigation redesign — launch watch list (LAUNCH-05)

What to measure for 14 days after the launch (Google Search Console: 8 weeks), where each number comes from, how it is compared and when it raises an alarm. One row per [README](../README.md) §8 metric (§3), the exact queries and report paths (§4), the decisions the numbers feed (§5) and the same-day routine for the one hard alarm (§6).

- **Read-only everywhere.** SQL runs as `SELECT` on production Postgres; nothing here writes.
- **Numbers never go into git** (the repo is public): record them in the git-ignored `var/navigation-research/launch-watch/` (the research folder the README names), one file per metric with the table of §2.4.
- Placeholders: `<D>` = launch day (§2.1), `<D-28>`, `<D+14>` … = days relative to it, as `YYYY-MM-DD`.
- In the tables, `\|` inside a regex is a plain `|` (Markdown escaping); the query blocks are copied as they are.

## 1. Before the baseline starts

| # | Step | Why | State 2026-10-14 |
|---|---|---|---|
| P1 | Deploy P0-03 to production alone - the branch `nav/p0-03-deploy` (`90002e923` + `f5255201d` on origin/main `7d4a0fd52`; decision 2026-10-15: `62c99662` alone no longer applies cleanly and its load and back/forward-cache lines say direction `unknown`) - **≥ 28 days before `<D>`** (the plan's minimum is 14) | every Loki row needs its baseline; with only 14 days the Loki rows lose the holiday check (§2.2) | not on `origin/main` |
| P2 | GA4: register event-scoped custom dimensions `nav_id`, `nav_surface`, `nav_place`, `nav_item`, `nav_layout` (Admin › Data display › Custom definitions) | the `nav_click` parameters are only reportable from the day they are registered - never retroactively | owner |
| P3 | GA4: confirm Enhanced measurement › Page views › "Page changes based on browser history events" is on (Admin › Data streams › web stream) and write the answer into the watch folder | Turbo visits are history changes: without it GA4 sees only full page loads, and every GA4 row below means something else | to confirm |
| P4 | GA4: confirm the property's reporting time zone is Europe/Prague (Admin › Property details) | GA4 dates must be Prague days like every other source | to confirm |
| P5 | GA4: open Reports › Engagement › Pages and screens filtered by `/ja/` and note whether Japanese paths show as characters or percent-encoded | decides which form of the regexes of §4.3 to use (both listed) | to confirm |
| P6 | Search Console: submit the puzzle child sitemaps `sitemap-puzzles-<n>.xml` (and `sitemap-players-<n>.xml`) individually | the Pages report filters by a submitted sitemap only; children of `sitemap.xml` cannot be filtered | owner |
| P7 | Start the daily snapshot of every Loki and Tempo query at `<D-28>` | Loki keeps 30 days, Tempo 14 days: by `<D+13>` neither holds the baseline any more | owner |
| P8 | Start the weekly feedback tagging (§4.7) at `<D-28>` | the findability share needs its own baseline | owner |

## 2. Method

### 2.1 The launch day
`<D>` = the **first full Prague day on which the new chrome served every page in production**: the deploy day + 1. The deploy day itself mixes old and new chrome (shared-cached guest HTML, open tabs) and is left out of every window, together with its weekday in the baseline.

`NavigationLaunch::DATE` (the "What moved" modal and the pointers) is **the deploy day itself**, set in the deploy that ships the chrome, and the deploy runs in the quietest morning hours (Tuesday or Wednesday 05:00-07:00 Prague). The rule reads `DATE` as Prague midnight: every account created before it knew the old navigation and gets the modal and the pointers from the moment the new chrome is live, and an account registered on the deploy day after the deploy - one that never saw the old navigation - gets neither. Only the few accounts registered in the early hours before the deploy miss the modal. With `DATE` = `<D>` the old players would use the new chrome for the rest of the deploy day with no modal and no pointers, and that day's new accounts would get "What moved" the next day.

So **`DATE` and `<D>` differ on purpose**: `DATE` = the deploy day (the safeguards), `<D>` = the deploy day + 1 (the measurement). Note both in the watch folder; the modal's own numbers (Q7) start at `DATE`. If the chrome ships before the date is set (the merge may - `docs/features/feature_flags.md`), `DATE` is the day of the deploy that sets it, again early in the morning, and the accounts created in between get "What moved" too; `<D>` stays the chrome's deploy day + 1.

### 2.2 Windows and ratios
- **Launch** L = `<D>` … `<D+13>` (14 days) · **baseline** B = `<D-14>` … `<D-1>` · **holiday check** H = `<D-28>` … `<D-15>`. Day i of each (i = 0 … 13) falls on the same weekday: L_i = `<D+i>`, B_i = `<D-14+i>`, H_i = `<D-28+i>`.
- **Holiday check** per day: h_i = B_i / H_i. When |h_i − 1| > 0.15, the baseline day is unusual (a public holiday in a main locale, a big event, an outage, a bot wave): find the reason (Q5 actives, Q6 events, the deploy log) and use H_i instead of B_i for that day (B'_i); otherwise B'_i = B_i. When every h_i lies above (or below) 1 by a similar amount, that is the season, not a holiday: keep B and read the ratios with the trend in mind. Calendar dates to look at around a late-autumn launch: the DST switch on 2026-10-25 (that Prague day has 25 hours), 28 Oct and 17 Nov (Czech public holidays), US Thanksgiving (26 Nov 2026), championship weekends.
- **Per weekday:** r_i = L_i / B'_i. **7-day sums:** week 1 = Σ L_0…6 / Σ B'_0…6, week 2 = Σ L_7…13 / Σ B'_7…13.
- Rows without a baseline (new events such as `menu_open`, `card_open`, `search_zero`) are read against an absolute rule instead.

### 2.3 Days, sessions and bots
- **Days are Prague calendar days** (midnight to midnight, `Europe/Prague`). Postgres stores UTC: `(column AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague'`. Loki and Tempo: count by the hour and add the hours up per Prague day (24 h, 23 h or 25 h) - hours are whole in both offsets, so the days stay exact. `player_activity_day` uses UTC days: only its 7-day sums are compared.
- **Sessions:** GA4 sessions for GA4 rows (GA loads after the visitor's first interaction and skips bots - the same before and after). For Loki rows, **tab sessions** = `viewport` lines (one per browser tab session, sessionStorage), split by `layout` (phone/desktop). A ratio takes numerator and denominator from the same source; the one exception (searches per session: Tempo ÷ GA4) is read only as a before/after change.
- **Bots:** Loki rows filter `webdriver = false`; this catches automated browsers that admit it, not every bot. A sudden jump of tab sessions with `pointer = none` is a bot wave: note the day.
- **Known shifts caused by the launch itself** (not alarms): Inbox opens fall (favorites' times left the bell, D4); feedback volume rises (one tap from every page); with instant Back a Back no longer reloads the page, so GA4 counts a history page view instead of a fresh page load (P3); trial starts from `offer_modal` fall for 14 days after `DATE` - "What moved" is an announcement modal, so the free-trial offer modal waits out the 14-day pause behind it for every account created before `DATE` (decision 57, `ResolveAnnouncementModal::COOLDOWN_DAYS`), and accounts that turn 7 days old around the launch get the offer up to two weeks later (read row #12 without them).

### 2.4 Recording table (one per metric, local only)

| i | day | weekday | H_i | B_i | h_i | B'_i | L_i | r_i | note |
|---|---|---|---|---|---|---|---|---|---|
| 0 | `<D>` | | | | | | | | |
| … | | | | | | | | | |
| 13 | `<D+13>` | | | | | | | | |
| | week 1 | | | | | | | | |
| | week 2 | | | | | | | | |

## 3. Watch list (README §8)

| # | Metric | Source | Query / report | Comparison | Alarm → action |
|---|---|---|---|---|---|
| 1 | **Times logged per day** | Postgres `puzzle_solving_time.tracked_at` | Q1 (+ Q5, Q6 to read it) | r_i per weekday + 7-day sums | **r_i < 0.90 on 3 Prague days in a row → fix immediately (§6)**; week sum < 0.95 → look the same day |
| 2 | Add-form opens per session | GA4 page views of the add form ÷ sessions; Loki Add clicks (L3) | G1, L3 | r_i + 7-day sums | r_i < 0.90 on 3 days or week < 0.90 → read with #1, check the Add tab and the last-mode rule |
| 3 | Searches per session | Tempo `GlobalSearch` Live requests (search bursts) ÷ GA4 sessions | T1, G0 | 7-day sums | week < 0.90 → check the top-bar field and the Menu search (the launch should raise it) |
| 4 | Reach of Library / Leaderboards / Events / Puzzlers | GA4 share of sessions with a page view of the section | G2 (four regexes) | 7-day sums per section | a section's week < 0.85 → check its entries (chips, Menu rows, Community landing) |
| 5 | Feedback volume and findability share | Postgres `email_audit_log` (`email_type = 'feedback'`) + weekly hand tags; feature requests | Q3, Q4, §4.7, L12, L13 (context) | weekly vs the 4 weekly baselines (`<D-28>` … `<D-1>`) | volume: none (expected up). Findability share of a launch week above the highest baseline week **and** ≥ 3 messages naming the same destination → fix its placement within the week (synonym, row description, pointer) |
| 6 | Inbox opens | GA4 views of notifications + messages ÷ sessions; Loki Inbox clicks | G3, L3, T4 | r_i + 7-day sums; split For you / Messages | Messages week < 0.90 → check the badge parts and the link's target tab (INB-02). For you falls by design: read it next to Home › Favorites views (T4) |
| 7 | Player card opens and continuation | Loki `card_open`, `card_outcome`, `card_bypass` | L8 | absolute: the revert rule | a context × input cell with ≥ 300 opens over ≥ 14 days, ≥ 70 % `continue` within 30 s and < 5 % other use → switch it to direct links (§5) |
| 8 | Menu opens and row clicks per group; a missed ☰ | Loki `menu_open`; `click` lines with surface `menu`; feedback tags; zero-result searches and Where did it go? lookups | L4, L5, L3, L6, L13, §4.7 | opens per phone tab session: context only - they are expected to fall (the old ☰ and account menu mostly led to Home, Puzzles, Profile and the bell, all on the bars now); rows per group: shares, no baseline. The micro-label is decided from signals of a **missed ☰** for the **Menu-only entries** (on no bar - not in `NavigationRegistry::ON_BARS`): (1) `cant_find` tags naming one (§4.7); (2) zero-result searches and Where did it go? lookups for one (L6, L13); (3) its Menu row clicks per phone tab session vs the old menus' clicks for the same entries per phone tab session (L5 vs L3) | in week 2 (3) < 0.8 × the old clicks **and** (1) above its highest baseline week or (2) ≥ 5 for one entry → the ☰ may be missed: decide the "Menu" micro-label (§5). Fewer ☰ opens alone is the design working, never a reason |
| 9 | Zero-result page searches | Loki `search_zero` | L6 | weekly list of queries | the same query ≥ 5 times in a week → add a synonym (`nav.syn.*`, 6 locales, `;`-separated) or an entry |
| 10 | Desktop docked vs folded share | Loki `viewport` (`dock`) + `layout` | L7 | absolute: D16 | **> 50 % of desktop tab sessions at ≥ 1440 px start folded in `<D+7>` … `<D+13>` → the rail becomes the default** (owner decision, §5) |
| 11 | Membership page visits | GA4 views of the membership page ÷ sessions; Loki membership clicks | G4, L3 | r_i + 7-day sums | week < 0.85 → check the account menu, the Menu's You card and Account row |
| 12 | Trial starts | Postgres `membership.trial_started_at` (+ `trial_source`); Loki `trial_start` lines | Q2, L11 | 7-day sums (small numbers: no per-day alarm) of the trials with `trial_source <> 'offer_modal'` during L - the offer modal waits 14 days behind "What moved" (§2.3); its own column from `<D+14>` | week < 0.80 → look at the members modal opens and their placements (L11) |
| 13 | Checkout starts | Stripe Checkout Sessions (mode `subscription`); Tempo 303s of `buy_membership` | S1, T2 | 7-day sums | week < 0.80 → check the membership page path (#11) and the checkout redirect |
| 14 | Back restores by kind and `ms_to_load` | Loki `history` lines before; Loki `back` lines from GEST-08 (the launch build sends no `history` line) | L9 | share of Back/Forward lines that end in a page load: before vs after (all directions, then Backs only); `ms_to_load` per kind after | by `<D+3>` the reload share on browse pages is not clearly lower → check `INSTANT_BACK` / `INSTANT_BACK_IOS`; snapshot median `ms_to_load` > 100 ms → look; a Sentry issue on a restored page → set `INSTANT_BACK = false` (one constant, `assets/instant_back.js`) |
| 15 | `dialog_suppressed` from iPhones | Loki `dialog_suppressed` (sent only on `data-os="ios"`) | L10 | absolute | any line on a page outside `/admin` → a native dialog is left on a player page: fix it (GEST-13) |
| 16 | Search Console: indexed puzzle pages, crawl stats per template (8 weeks) | GSC Pages + Crawl stats; Tempo Googlebot spans per template | C1, C2, T3 | weekly, 8 weeks after vs the 4 weeks before | indexed puzzle pages −5 % week over week → look; crawl requests −20 % or average response time +30 % → look |
| 17 | `nav_click` counts | Loki `click` lines (record) + GA4 `nav_click` (cross-check) | L2, G5 | clicks per tab session by surface; destinations through the old → new mapping (L3) | a surface with 0 lines for a whole Prague day → instrumentation broken, fix; GA4 and Loki trends diverging → check P2/P3 |
| 18 | `members_modal` placements (plan) | Loki `members_modal` + `trial_start` | L11 | absolute, per placement | none - feeds the teaser decisions (README §6) |
| 19 | Results / profile URL patterns incl. `?page=N` (plan) | GSC Pages + URL Inspection; Tempo Googlebot spans | C3, T3 | weekly, 8 weeks | Googlebot requests to Results with `page=` growing week over week, or above 3 × the requests to Results base URLs → add the one-line `rel="nofollow"` on "Show more" (PROF-05b risk) |

## 4. Queries and reports

### 4.1 Postgres (read-only)

Window bounds as UTC timestamps (`tracked_at` and the other `DATETIME_IMMUTABLE` columns are UTC without a zone):

```sql
-- every query below: WHERE <col> >= :from AND <col> < :to
-- :from = (TIMESTAMP '<D-28>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
-- :to   = (TIMESTAMP '<D+14>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
```

**Q1 Times logged per Prague day** (#1)
```sql
SELECT ((pst.tracked_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day,
       count(*)                                                   AS times,
       count(*) FILTER (WHERE pst.seconds_to_solve IS NULL)       AS without_time,
       count(*) FILTER (WHERE pst.puzzling_type <> 'solo')        AS pairs_teams,
       count(*) FILTER (WHERE pst.competition_id IS NOT NULL
                           OR pst.competition_series_id IS NOT NULL) AS at_events
FROM puzzle_solving_time pst
WHERE pst.tracked_at >= (TIMESTAMP '<D-28>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
  AND pst.tracked_at <  (TIMESTAMP '<D+14>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
GROUP BY 1
ORDER BY 1;
```
`tracked_at` is when the result was saved (an edit keeps it). Every source counts (web, API, imports); removed duplicates disappear from older days too, so re-run the whole window at once instead of mixing numbers taken on different days.

**Q2 Trial starts** (#12)
```sql
SELECT ((m.trial_started_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day,
       count(*)                                                    AS trials,
       count(*) FILTER (WHERE m.trial_source = 'membership_page')  AS from_membership_page,
       count(*) FILTER (WHERE m.trial_source = 'members_modal')    AS from_members_modal,
       count(*) FILTER (WHERE m.trial_source = 'offer_modal')      AS from_offer_modal
FROM membership m
WHERE m.trial_started_at >= (TIMESTAMP '<D-28>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
  AND m.trial_started_at <  (TIMESTAMP '<D+14>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
GROUP BY 1
ORDER BY 1;
```

**Q3 Feedback messages** (#5) - the Help sheet's form (and the old feedback page) e-mails the team through `CollectUserFeedbackHandler` (template `emails/feedback.html.twig` → `email_type = 'feedback'`). The audit writes one row per sending attempt (a failover attempt is a second row), so only `sent` rows count.
```sql
SELECT ((e.sent_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day,
       count(*) AS feedback_messages
FROM email_audit_log e
WHERE e.email_type = 'feedback'
  AND e.status = 'sent'
  AND e.sent_at >= (TIMESTAMP '<D-28>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
  AND e.sent_at <  (TIMESTAMP '<D+14>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
GROUP BY 1
ORDER BY 1;
```
`email_audit_log` keeps 90 days by default (`CleanupEmailAuditLogsCommand`) - enough for the 42-day window.

**Q4 Feature requests filed** (#5, context)
```sql
SELECT ((fr.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day,
       count(*) AS feature_requests
FROM feature_request fr
WHERE fr.created_at >= (TIMESTAMP '<D-28>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
  AND fr.created_at <  (TIMESTAMP '<D+14>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
GROUP BY 1
ORDER BY 1;
```

**Q5 Signed-in active players** (normaliser for #1, 7-day sums only - UTC days)
```sql
SELECT pad.day, count(*) AS active_players
FROM player_activity_day pad
WHERE pad.day BETWEEN DATE '<D-28>' AND DATE '<D+13>'
GROUP BY pad.day
ORDER BY pad.day;
```
Times per active player (Σ Q1 ÷ Σ Q5 per week) tells a traffic drop from a navigation drop; it never delays §6.

**Q6 Event days in the windows** (holiday check)
```sql
SELECT ((cr.starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day,
       count(DISTINCT cr.competition_id) AS events_with_rounds,
       count(*)                          AS rounds
FROM competition_round cr
JOIN competition c ON c.id = cr.competition_id
WHERE c.rejected_at IS NULL
  AND c.is_draft = false
  AND cr.starts_at >= (TIMESTAMP '<D-28>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
  AND cr.starts_at <  (TIMESTAMP '<D+14>' AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'UTC'
GROUP BY 1
ORDER BY 1;
```
Q1's `at_events` column shows the same in results.

**Q7 Launch safeguards** (context, not a §8 metric; the enum values of `AnnouncementModal::NavigationWhatMoved` and `HintType::NavigationLaunchPointer` / `NavigationInboxPointer`, LAUNCH-01/03 and INB-05)
```sql
SELECT ((i.displayed_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day,
       count(*) AS what_moved_shown, count(i.seen_at) AS what_moved_seen_reported
FROM player_modal_impression i
WHERE i.modal = 'navigation_what_moved'
GROUP BY 1 ORDER BY 1;

SELECT dh.type, ((dh.dismissed_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Prague')::date AS day, count(*) AS dismissed
FROM dismissed_hint dh
WHERE dh.type IN ('navigation_launch_pointer', 'navigation_inbox_pointer')
GROUP BY 1, 2 ORDER BY 2, 1;
```

### 4.2 Loki — the `navigation` channel

`NavClickController` writes one info line per beacon on Monolog channel `navigation` (own stderr handler, JSON formatter, never Sentry). The line is Monolog's JSON: `{"message":"Navigation click","context":{…},"level":200,"level_name":"INFO","channel":"navigation",…}`; LogQL's `json` stage turns `context.kind` into the label `context_kind`. Fields as really logged:

| `context_kind` | Message | Fields (`context_*`) | Since |
|---|---|---|---|
| `click` | Navigation click | `nav` (`<surface>/<place>/<item>`), `surface`, `place`, `item` | P0-03 |
| `viewport` | Navigation viewport | `bucket` (`lt768`, `768_1023`, `1024_1279`, `1280_1439`, `1440_1919`, `gte1920`), `pointer` (`coarse`/`fine`/`none`), `standalone`, `dock` (`docked`/`rail`/`none`, the state the tab session started in - NAV-13) | P0-03 (`dock` NAV-13) |
| `history` | Navigation history | `via` (`turbo_restore`/`load`/`bfcache`), `direction` (`back`/`forward`/`unknown`), `ua_visual_transition` | P0-03 - the baseline; the launch build sends `back` instead (L9) |
| `menu_open` | Navigation menu open | `surface` (`panel` phone / `overlay` desktop < 1280 px), `place` (the page's place) | MENU-02b, NAV-13 |
| `search_zero` | Navigation search without results | `surface` (`menu`/`topbar`; `wdig` = the filter of Where did it go? left nothing), `query` (folded, ≤ 40 characters) | MENU-03, SRCH-01 (`wdig`: LAUNCH review Q3) |
| `layout` | Navigation layout | `state` (`docked`/`rail` - the ☰ switched it), `bucket` | NAV-13 |
| `card_open`, `card_bypass` | Navigation card open / bypass | `context` (`none`/`puzzle`/`feed`/`notification`/`round`/`leaderboard`), `input` (`mouse`/`touch`/`pen`/`keyboard`) | CARD-07 |
| `card_outcome` | Navigation card outcome | `context`, `input`, `outcome` (`continue`/`deep`/`puzzle`/`action`/`bounce`/`close`), `ms` | CARD-07 |
| `members_modal` | Navigation members modal | `placement` (`card.tier`, `profile.tier`, `none`) | TEASE-01 |
| `dialog_suppressed` | Navigation dialog suppressed | `after_layer_back`, `after_restore` | GEST-13 |
| `trial_start` | Free trial started from a members teaser | `placement`, `source` - **no** `page`, `layout`, `webdriver` (written by `StartFreeTrialController`) | TEASE-01 |
| `back` | Navigation back | `restore` (`snapshot`/`reload`/`bfcache`/`bfcache-reload`), `ms_to_load` (whole ms, null when unknown or over 10 minutes), `direction` (`back`/`forward`/`unknown`), `ua_visual_transition` (`true`/`false`, null when the browser does not say) | GEST-08 |

Every beacon line also carries `context_page` (path only, secret segments masked as `{token}`), `context_layout` (`phone`/`desktop`) and `context_webdriver`. Never export `page`-level breakdowns beyond the watch folder: paths hold player ids.

**How to run.** Each query below is a metric query over `[1h]`: run it as a range query (Loki HTTP API `/loki/api/v1/query_range`, `step=3600`, start `<D-28>T00:00` and end `<D+14>T00:00` in Europe/Prague, or Grafana Explore with the same step) and add the hourly values up per Prague day. Run it daily from `<D-28>` (retention 30 days, P7). The stream selector `{app="myspeedpuzzling"}` is the production web container's - confirm it on the box (§7).

**L1 Tab sessions** (denominator of every Loki ratio)
```logql
sum by (context_layout) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="viewport" [1h]))
```

**L2 Clicks per surface** (#17)
```logql
sum by (context_surface) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="click" [1h]))
```
Surfaces before the launch: `topbar`, `admin_key`, `account`, `hamburger`, `toolbar`, `navbar`, `footer`. After: `tabbar`, `topbar`, `menu`, `account`, `help`, `page`, `chips`, `community`, `card`, `inbox`, `footer`.

**L3 One destination, old and new chrome** (#2, #6, #8, #11, #17) - the same query with the regex of the row:
```logql
sum(count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="click" | context_nav=~"<regex>" [1h]))
```

| Destination | Before (P0-03 on the old chrome) | After |
|---|---|---|
| Menu opened | `hamburger/toggle/open\|account/toggle/open` | `menu_open` lines (L4); the ☰ itself is `topbar/none/menu` |
| Inbox | `toolbar/tools/(notifications\|messages)` | `topbar/none/inbox\|menu/inbox/inbox` |
| Add | `toolbar/tools/add_time\|footer/track/add_time` | `tabbar/places/add\|topbar/add/add\|menu/add/add\|footer/track/add_time` |
| Running stopwatch | — | `topbar/add/stopwatch_pill\|menu/add/stopwatch` |
| Membership | `topbar/links/membership\|footer/myspeedpuzzling/membership` | `account/links/membership\|menu/you/membership\|menu/account/membership\|footer/myspeedpuzzling/membership` |
| Feedback opened | `topbar/links/feedback\|footer/myspeedpuzzling/feedback` | `topbar/none/help\|page/[a-z_]+/help_line\|menu/help/send_feedback\|footer/myspeedpuzzling/feedback` (sent: `help/help/send_feedback`) |
| Library | `account/links/puzzle_library` | `menu/library/library\|chips/puzzles/library` (+ the Puzzles tab, which opens My library by default) |
| Leaderboards | `hamburger/places/ladder\|footer/discover/ladder` | `menu/community/leaderboards\|chips/community/leaderboards\|community/community/leaderboards\|footer/discover/ladder` |
| Events | `hamburger/places/events\|footer/discover/events` | `menu/community/events\|chips/community/events\|community/community/events\|footer/discover/events` |
| Puzzlers | `hamburger/places/players\|footer/discover/players` | `menu/community/puzzlers\|chips/community/puzzlers\|community/community/puzzlers\|footer/discover/players` |
| Home | `hamburger/places/hub\|navbar/logo/home` | `tabbar/places/home\|topbar/home/logo\|menu/home/home` |
| Profile | `account/links/my_profile` | `tabbar/places/profile\|menu/you/profile\|account/links/view_profile` |

The footer ids did not change at the launch (FOOT-01), so footer clicks compare directly. Complete lists: old - `git show 62c99662:templates/base.html.twig` and `:templates/_footer.html.twig`; new - Menu rows are `menu/<group>/<entry id>` from [`docs/navigation.md`](../../../navigation.md), chips `chips/<place>/<section>`, the community landing `community/community/<item>`. Search has no comparable click (the field is focused, not clicked): use T1.

**L4 Menu opens** (#8) - phone panel and desktop overlay; the docked column has no "open".
```logql
sum by (context_surface, context_place) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="menu_open" [1h]))
```
Opens per phone tab session = `surface="panel"` ÷ L1 `phone`; before = L3 "Menu opened" (phone layout) ÷ L1 `phone`.

**L5 Menu row clicks per group** (#8) - a Menu row's `place` is its group (`you`, `inbox`, `roles`, `puzzling`, `library`, `community`, `puzzles`, `add`, `account`, `help`, `about`, `logout`, `home`); `none` = the panel's × and logo.
```logql
sum by (context_place, context_layout) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="click" | context_surface="menu" | context_place!="none" [1h]))
```
Per row: the same with `sum by (context_item)`, weekly. There is no separate `menu_row` line: the row id is the click's `item`, its position is fixed per surface in `docs/navigation.md` (the order never changes per player); `layout` tells the phone panel (`phone`) from the desktop column/overlay (`desktop`).

**L6 Zero-result page searches** (#9) - `surface="wdig"` is the filter of Where did it go? (old names, not page searches): read it in L13
```logql
sum by (context_surface) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="search_zero" [1h]))
```
Weekly list (instant query at the end of each week):
```logql
topk(30, sum by (context_query) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="search_zero" [7d])))
```

**L7 Desktop docked vs folded** (#10)
```logql
sum by (context_dock, context_bucket) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="viewport" | context_layout="desktop" [1h]))
```
Folded share = tab sessions with `dock="rail"` ÷ tab sessions with `dock` in (`docked`, `rail`), for the buckets `1440_1919` and `gte1920` (docked is the default there); report 1280–1439 (`rail` default, `docked` = chosen) beside it. Switches: `sum by (context_state, context_bucket) (count_over_time(… | context_kind="layout" [1h]))`. The log has no player id, so this is a share of tab sessions, not of players - the closest reading of D16 the data allows.

**L8 Player card** (#7) - instant queries over `[14d]` at `<D+14>` (and monthly after):
```logql
sum by (context_context, context_input) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="card_open" [14d]))

sum by (context_context, context_input, context_outcome) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="card_outcome" [14d]))

sum by (context_context, context_input) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="card_outcome" | context_outcome="continue" | context_ms!="" | context_ms <= 30000 | __error__="" [14d]))
```
Revert rule (`NavigationRegistry::PLAYER_CARD_REVERT_RULE`): per cell ≥ 300 `card_open` over ≥ 14 days; of its `card_outcome` lines (the base of both shares - an open without an outcome counts in neither) ≥ 70 % `continue` within 30 s and < 5 % `deep` + `puzzle` + `action`. `card_bypass` counts the same way (`context_kind="card_bypass"`).

**L9 Back and Forward** (#14). One line per Back or Forward on both sides of the launch, a different kind on each:

- **Before (the baseline, P0-03):** `history` lines - `via="turbo_restore"` (every Back after a Turbo visit: Turbo's restore, turned into a full load that is not counted again), `load` (a page the browser loaded by a traverse), `bfcache` (a back/forward cache restore; the old code reloaded every one of them, no second line).
- **After (the launch build, GEST-08):** `back` lines only - `assets/app.js` starts the P0-03 instrumentation with `history: false`, so no `history` line is sent for any traversal, the ones Instant Back does not handle included: `assets/back_measurement.js` sends them all as `back` lines - `restore="snapshot"` (Turbo's snapshot), `reload` (Instant Back's full load **and** the browser's own back/forward load - one kind, they cannot be told apart), `bfcache` (kept), `bfcache-reload`. A `history` line after `<D>` comes only from a tab still running a script from before the deploy (until it loads a page again); it still counts as one Back.
- **Fields:** `restore`, `ms_to_load`, `direction`, `ua_visual_transition` (§4.2). `ms_to_load`: snapshot = from the Back to the snapshot's `turbo:load`; `reload` = from the Back to the next document's `turbo:load` (the browser's own load: from that document's navigation start); `bfcache` = the browser's restoration entry (0 without one); `bfcache-reload` = from the restore to the reloaded document's `turbo:load`.
- **Direction:** the `back` line carries `back`/`forward` on every path (snapshot, reload, bfcache, bfcache-reload; after a plain Turbo visit and after a layer's replace visit; with or without the Navigation API) - `unknown` only without the Navigation API for a page the browser loaded or kept itself (older iPhones). Measured on dev (Chromium, 2026-10-15): the P0-03 commit `62c99662` (the one P1 deploys) reads the direction of a `load` line from `navigation.activation.entry`, whose index is -1 once Turbo replaced that entry at start - its `load` lines read `unknown`, unless P1 takes `activationDirection(activation, currentIndex)` from `assets/nav_instrumentation.js` of the delivery branch along; its `turbo_restore` and `bfcache` lines carry the direction. Check the baseline's `unknown` share in the first query below before comparing Backs only.

Baseline (and the stragglers after `<D>`):
```logql
sum by (context_via, context_direction) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="history" [1h]))
```
After `<D>`:
```logql
sum by (context_restore, context_direction, context_layout) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="back" [1h]))

quantile_over_time(0.5, {app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="back" | unwrap context_ms_to_load | __error__="" [1d]) by (context_restore)
```
(also 0.75). Backs across the launch - one series per kind, the same query on both sides:
```logql
sum by (context_kind, context_layout) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind=~"history|back" | context_direction="back" [1h]))
```
Backs before = `history` lines with `direction="back"`; Backs after = `back` lines with `direction="back"` + any `history` lines with `direction="back"` still sent. Compare the share that ends in a page load - before: `turbo_restore` + `load`; after: `reload` + `bfcache-reload` - by `layout`, first over all directions (every line, both sides complete), then Backs only (the baseline's Backs miss its `unknown` `load` lines, so their page-load share reads low). `ms_to_load` has no baseline.

**L10 Native dialogs refused on iPhones** (#15)
```logql
sum by (context_page, context_after_layer_back, context_after_restore) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="dialog_suppressed" [1d]))
```

**L11 Members modal and trials from teasers** (#12, #18)
```logql
sum by (context_placement) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="members_modal" [1h]))

sum by (context_placement, context_source) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_kind="trial_start" [1h]))
```
(`trial_start` has no `webdriver` field - no filter on it.)

**L12 Help sheet use** (#5, context) - opens and sent messages from the bars and the end-of-page line:
```logql
sum by (context_nav) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="click" | context_nav=~"topbar/none/help|page/[a-z_]+/help_line|menu/help/send_feedback|help/help/send_feedback|topbar/links/feedback|footer/myspeedpuzzling/feedback" [1h]))
```

**L13 Where did it go? lookups** (#5, #8, #9; LAUNCH review Q3) - which old items players look up: a click on an old name is `page/none/wdig_<entry>` (the entry whose page it opens), on its new place `page/none/wdig_now_<entry>` (the new home's Menu row); a filter that left nothing is a `search_zero` line with `surface="wdig"`. Weekly lists (instant queries at the end of each week):
```logql
topk(30, sum by (context_item) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="click" | context_nav=~"page/none/wdig_.*" [7d])))

topk(30, sum by (context_query) (count_over_time({app="myspeedpuzzling"} |= `"channel":"navigation"` | json | context_webdriver="false" | context_kind="search_zero" | context_surface="wdig" [7d])))
```
`wdig_now_*` items are the "Now" links (the player went on to the Menu row). No baseline: the page is new at the launch. An item looked up often in week 1 and still in week 2 is a placement to explain better (synonym, row description, a "You said, we changed" note); feed the entries into row #8's missed-☰ signals when they are Menu-only.

### 4.3 GA4

**Regexes** (Explorations › filter "Page path and screen class" **matches regex**, a full match; the query string is not part of the path). Czech pages have no locale prefix. If GA4 shows Japanese paths percent-encoded (P5), replace the Japanese segment with its encoded form from the table below.

| Id | Destination (route) | Regex |
|---|---|---|
| R-add | Add form (`puzzle_add`, with or without a puzzle) | `/(pridat-puzzle\|en/puzzle-add\|es/agregar-puzzle\|fr/ajouter-puzzle\|de/puzzle-hinzufuegen\|ja/パズル追加)(/[^/]+)?` |
| R-membership | Membership page (`membership`) | `/(clenstvi/\|en/membership\|es/membresia\|fr/adhesion\|de/mitgliedschaft\|ja/メンバーシップ)` |
| R-library | Library (`puzzle_library`) | `/(knihovna-puzzli\|en/puzzle-library\|es/biblioteca-puzzles\|fr/bibliotheque-puzzles\|de/puzzle-bibliothek\|ja/パズルライブラリ)/[^/]+` |
| R-leaderboards | Every board (`ladder` + variants, level/AP/karma boards) and MSP Rating | `/((zebricek\|en/ladder\|es/escalera\|fr/classement\|de/rangliste\|ja/リーダーボード)(/.*)?\|((en\|es\|fr\|de\|ja)/)?msp-rating(/.*)?)` |
| R-events | Events agenda + year archive (`events`, `events_archive`) | `/(eventy\|en/events\|es/eventos\|fr/evenements\|de/veranstaltungen\|ja/イベント)(/(archiv\|archive\|archivo\|archives\|アーカイブ)/[0-9]{4})?` |
| R-puzzlers | Players page, directory, its sub-pages (`players`, `players_directory`) | `/(puzzleri\|en/puzzlers\|es/jugadores\|fr/joueurs\|de/puzzler\|ja/プレイヤー)(/.*)?` |
| R-inbox-foryou | Notifications (`notifications`) | `/(notifikace\|en/notifications\|es/notificaciones\|fr/notifications\|de/benachrichtigungen\|ja/通知)` |
| R-inbox-messages | Conversations list (`conversations_list`) | `/(cs\|en\|es\|fr\|de\|ja)/messages` |

Percent-encoded Japanese segments: パズル追加 `%E3%83%91%E3%82%BA%E3%83%AB%E8%BF%BD%E5%8A%A0` · メンバーシップ `%E3%83%A1%E3%83%B3%E3%83%90%E3%83%BC%E3%82%B7%E3%83%83%E3%83%97` · パズルライブラリ `%E3%83%91%E3%82%BA%E3%83%AB%E3%83%A9%E3%82%A4%E3%83%96%E3%83%A9%E3%83%AA` · リーダーボード `%E3%83%AA%E3%83%BC%E3%83%80%E3%83%BC%E3%83%9C%E3%83%BC%E3%83%89` · イベント `%E3%82%A4%E3%83%99%E3%83%B3%E3%83%88` · アーカイブ `%E3%82%A2%E3%83%BC%E3%82%AB%E3%82%A4%E3%83%96` · プレイヤー `%E3%83%97%E3%83%AC%E3%82%A4%E3%83%A4%E3%83%BC` · 通知 `%E9%80%9A%E7%9F%A5`. A regex GA4 refuses for its length is split into two conditions joined with "or".

The routes did not change at the launch (README §10.6: no route removed or renamed), so each regex measures the same pages before and after.

- **G0 Sessions per day** (denominator): Explore › Free form · Rows: Date · Values: Sessions · no filter.
- **G1 Add-form opens per session** (#2): the same exploration · Values: Views · Filter: R-add → Views ÷ G0.
- **G2 Reach** (#4): Explore › Free form · Rows: Date · Values: Sessions · Segment (session segment): "Page path and screen class matches regex R-…" - one segment per section (R-library, R-leaderboards, R-events, R-puzzlers) → segment sessions ÷ G0.
- **G3 Inbox opens** (#6): Views with R-inbox-foryou and R-inbox-messages, each ÷ G0; reach as G2 with both.
- **G4 Membership page visits** (#11): Views with R-membership ÷ G0.
- **G5 `nav_click`** (#17, from P2 on): Explore › Free form · Rows: Date, `nav_surface` · Values: Event count · Filter: Event name exactly `nav_click`. GA4 only counts clicks once GA has loaded; Loki (L2) is the count of record.

### 4.4 Tempo (Traefik spans, 100 %, 14 days)

Tempo's search API (`/api/search`, TraceQL, windows of ≤ 1 h, `limit=20000`, `spss=1`; its metrics API does not work for this) on the production box. Count per hour, add up per Prague day, run daily from `<D-28>` (P7).

- **T1 Search bursts** (#3) - the page search is a Live component; each typed character (150 ms debounce) is one request, from the top bar's and the Menu's instance alike:
  ```traceql
  { span.url.path =~ "/(cs|en|es|fr|de|ja)/_components/GlobalSearch.*" } | select(span.client.address)
  ```
  A **search burst** = one client address with ≥ 1 request in a 10-minute bucket. Searches per session = bursts ÷ G0. The client address is a proxy (shared networks merge people) - the same before and after.
- **T2 Checkout redirects** (#13, cross-check) - `buy_membership` answers 303 with the Stripe Checkout URL (302 = sent elsewhere):
  ```traceql
  { span.url.path =~ "/(koupit-clenstvi|en/buy-membership|es/comprar-membresia|fr/acheter-adhesion|de/mitgliedschaft-kaufen|ja/メンバーシップ購入)(/.*)?" && span.http.response.status_code = 303 }
  ```
- **T3 Googlebot per template** (#16, #19) - weekly, one query per template regex (puzzle pages `/((en|es|fr|de)/)?puzzle/[^/]+` and `/ja/パズル/…`, player profiles `…/(profil-hrace|player-profile|perfil-jugador|profil-joueur|spieler-profil|プレイヤー-プロフィール)/…`, player Results `…/(vysledky-hrace|player-results|resultados-jugador|resultats-joueur|spieler-ergebnisse|プレイヤー結果)/…`, the Community landing, `help`, `menu`, Where did it go?, `/go/…` (`nav_go`), the one-tap action pages (`post_action_confirm`), `nav-search.json`):
  ```traceql
  { span.user_agent.original =~ ".*Googlebot.*" && span.url.path =~ "<template regex>" }
  ```
  `?page=N` on Results needs the span's query-string attribute (§7).
- **T4 Home › Favorites seen** (#6, context, after the launch only) - the Favorites pane marks itself seen once per Hub visit:
  ```traceql
  { span.url.path =~ ".*/_components/RecentActivity/markFavoritesSeen" }
  ```

### 4.5 Stripe

**S1 Checkout starts** (#13) - Checkout Sessions created per Prague day, `mode = subscription` (`MembershipManagement::getMembershipPaymentUrl()`). On the production box with the app's `STRIPE_API_KEY`, never printed:
```sh
curl -sG https://api.stripe.com/v1/checkout/sessions -u "$STRIPE_API_KEY:" \
  -d limit=100 \
  -d "created[gte]=<epoch of <D-28> 00:00 Europe/Prague>" \
  -d "created[lt]=<epoch of <D+14> 00:00 Europe/Prague>" \
  ${STARTING_AFTER:+-d starting_after=$STARTING_AFTER} \
| jq -r '.data[] | select(.mode == "subscription") | [.id, .created, .status] | @tsv'
# repeat with STARTING_AFTER=<last id> while .has_more; then per Prague day:
#   TZ=Europe/Prague date -d @<created> +%F      (status complete = paid; open/expired = started only)
```

### 4.6 Google Search Console (8 weeks after `<D>`, weekly; the 4 weeks before as baseline)

Property `sc-domain:myspeedpuzzling.com`.
- **C1 Indexed puzzle pages** (#16): Indexing › Pages › sitemap filter = each `sitemap-puzzles-<n>.xml` (P6) → "Indexed", summed. Until P6 is processed: Performance › Search results › Pages, filter Page contains `/puzzle/` → number of pages with impressions (a proxy), plus clicks and impressions of those pages.
- **C2 Crawl stats** (#16): Settings › Crawl stats (`/search-console/settings/crawl-stats`, 90 days kept): total crawl requests per day, average response time, by response, by file type (HTML), by purpose (refresh / discovery). Per template: T3.
- **C3 Results and profile URL patterns incl. `?page=N`** (#19): Indexing › Pages → "Excluded by 'noindex' tag" and "Alternate page with proper canonical tag" - examples containing a Results path and `?page=`; URL Inspection on the same three URLs every week: a big profile's Results base URL (indexable when the player has more results than the Overview shows), its `?page=2` (`noindex, follow`, self canonical) and a filtered variant (`noindex, follow`, canonical = the list); plus the player profile itself.

### 4.7 Feedback tagging (#5)

Weekly, every message of Q3 (read in the team's feedback inbox - subject "MySpeedPuzzling Feedback", the page it was sent from is in the mail), one tag each:
- `cant_find` - asks where something is, or says something is missing that still exists;
- `nav_other` - about the navigation, but not about finding something (likes, dislikes, layout);
- `other`.

Record per week: all, `cant_find`, `nav_other`, and for `cant_find` the destination named (its registry entry id). Findability share = `cant_find` ÷ all. Never copy a message into the watch folder - tags and entry ids only. The same destinations feed the "You said, we changed" notes (`announcement.md` §2).

## 5. Decisions the watch feeds

| When | Decision | Data | Where it is changed |
|---|---|---|---|
| `<D+14>` | Rail as the desktop default (D16) when > 50 % fold | L7 | the default of the docked state (NAV-13) |
| `<D+14>`, then monthly | Player card → direct links for a context × input cell | L8 | `NavigationRegistry::PLAYER_CARD_DIRECT` (numbers in the commit message; `docs/TODO.md` item) |
| after the device checks | Instant Back on iPhones | L9 (iOS restores), L10 | `INSTANT_BACK_IOS` in `assets/instant_back.js` |
| `<D+14>` | A "Menu" micro-label under ☰ (README §9) | row #8: the missed-☰ signals for Menu-only entries (§4.7 `cant_find`, L6 + L13, L5 vs L3) - never the ☰ opens alone (L4) | `docs/TODO.md` item |
| weekly | New search synonyms or entries | L6, §4.7 | `nav.syn.*` (6 locales, `;`-separated), `NavigationRegistry` |
| quarterly | Menu group order (README §7: re-decided only from measured data) | L5 | `MenuGroup::order()` |
| 8 weeks | SEO follow-ups (`rel="nofollow"` on Show more, crawl) | C1–C3, T3 | PROF-05b fallback |

## 6. Fix-immediately routine (alarm of #1)

Three Prague days in a row with r_i < 0.90 for times logged:
1. **Same day:** re-run Q1 for the whole window; read Q5 (fewer players, or fewer times per player?) and Q6 (did an event inflate the baseline day?). A drop that only follows fewer active players is still looked at, but starts at the traffic sources.
2. **The Add path:** Add clicks (L3), add-form opens (G1), the Add page's last-mode rule (ADD-01), Sentry issues on `puzzle_add` and the `TurboDriveFormResponseSubscriber` warning (a form answered 200), the running-stopwatch pill (L3), first-try/duplicate refusals that keep players from saving.
3. **Feedback of the day** (Q3 + the inbox) for words like "add", "save", "stopwatch".
4. **Fix forward** the same day. There is no switch-back (D18); reverting the merge is the last resort (README §10.6: migrations additive, no route removed).
5. Note the cause and the fix in the watch folder; tell players in the next "You said, we changed" note if they were affected.

## 7. Not confirmed from the code - confirm during the baseline

- **Loki stream selector** `{app="myspeedpuzzling"}` and that the stored line is still Monolog's JSON (the line filter `"channel":"navigation"` and the `json` stage rely on it; if the log pipeline promotes `channel` to a label, the line filter can become a label matcher).
- **The baseline's Back direction:** P0-03's `history` `via="load"` lines read `direction="unknown"` when `62c99662` is deployed as it is (measured on dev in Chromium, L9); the deploy branch `nav/p0-03-deploy` carries the fix (`f5255201d`: `activationDirection()` falls back to the current entry, as in the delivery branch) - still confirm the share with L9's baseline query before #14 compares Backs only. (GEST-08's `back` line and its fields are built and checked - L9; the launch build sends no `history` line.)
- **`menu_row`** (README §4.12) was built as `click` lines `menu/<group>/<entry id>`: no position field (positions are fixed per surface in `docs/navigation.md`), and the desktop column and overlay share their data-nav (`layout` = `desktop` for both; overlay clicks follow a `menu_open` with `surface="overlay"`).
- **Searches** have no event of their own; T1 counts Live requests. If Tempo turns out too heavy to query every day, a `search` beacon (first query typed into a search field per page view) on the same channel would be a small follow-up.
- **GA4 settings** P2–P5 (custom dimensions, history page views, time zone, Japanese path form), and whether GA4 accepts the longer regexes in one condition.
- **Tempo:** the span attribute holding the query string (needed for `?page=N` in T3), and whether span paths hold Japanese characters decoded or percent-encoded.
- **Stripe:** the `created` filter on the Checkout Sessions list (otherwise page back from the newest and stop at `<D-28>`).
- **`email_audit_log` retention:** 90 days is the cleanup command's default argument; check the production cron line passes no shorter value.
- **P0-03 deploy date** (P1): the baseline's first usable day for every Loki row.
