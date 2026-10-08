# Series, edition and event pages

The design of record for the redesigned **series page** (`competition_series_detail`), **edition page**
(`edition_detail`) and **one-time event page** (`event_detail`), all 6 locales. Approved by Jan on 2026-10-08 with the
decisions below. They are rebuilt on the parts of the events page ([README.md](README.md), PR #249) and on its sessions
rule (`OccurrenceDates::sessions()`). The build contract is [detail-pages-plan.md](detail-pages-plan.md).

The proposal with a working mock-up (three pages, phone and desktop) was reviewed as an artifact; its examples used
production data. **This repository is public: every name in this document, the code, the fixtures and the tests is
made up** (the fixtures' "Moonlight Sprint League", "Harbor Jigsaw Nights", "Lakeside Clock Marathon" …).

## Goal

The events page speaks one visual language: date leaves, status tags, a star to follow, one ⋯ menu for organisers.
The three detail pages still use the old cards, Bootstrap badges and a dd.mm. date format. Visitors open them to ask
"when is the next one, and can I join?" and, afterwards, "where are the results?". Today:

- a series page lists every edition as a tall card (a series with 14 past editions is 14 cards with buttons), nothing
  stands out, and an edition that holds a round a month shows as one card "17.06.2026 · 6 rounds" under Past while its
  next round is weeks away;
- an edition page is a header with up to four buttons, then each round as a block of large puzzle cards;
- a one-time event page is a header, a "Results by round" button row, then one grid of puzzle cards.

The redesign puts **the next date first**, shows **sessions, not storage** (six monthly rounds of one edition and six
editions of one series both read as six dates), **folds the past into years** and uses the events page's parts, so a
visitor who learned the events page reads these pages without a second look - and we keep one implementation.

## Jan's decisions (2026-10-08, final)

| Topic | Decision |
|---|---|
| Time zones | Every page shows times in the **competition's own time zone, with the zone named** ("18:45 New York Time"). **Online events only:** when the visitor's zone differs, a second time computed in the browser follows, also naming its zone ("00:45 next day, Central European Time (yours)") - the browser can be wrong (travel, VPN, a misconfigured device), so the zone is always named. **In-person events never get a second time.** The server HTML is the same for everyone. |
| Format chips | **Not now.** A series that runs several formats under one name gets no chips; that waits for an organiser's answer about a possible "Organisation" concept. Nothing of "Organisation" is built. |
| Scope | **All three pages in one change**: the one-time event page is redesigned together with the edition and series pages; they share the header and the rounds timeline. |

Decisions taken while planning (can be revisited, listed again under "Conflicts and open questions"): sections move
below the agenda/timeline; round puzzles become compact items inside their round; the event page's "Results by round"
row is replaced by a Results link on each round; English times follow the events page's en-GB rule (24 h).

## One family of parts

Everything below is built from the events page's parts, moved to shared names (`templates/event_parts/`,
`assets/styles/_event-parts.scss`; the `ev-` class prefix stays):

| Part | Used on |
|---|---|
| Date leaf (weekday band, day, month; coral in person, blue online, grey past or TBA) | rows, the Next card, every round of the timeline |
| Agenda row (leaf · name · line under it · place · tags · when · ⋯) | the series page's upcoming list |
| Tags (Registration states, Going, N going, Results, Runs until, Waiting for approval) | rows, the Next card, archive lines |
| When label ("Live" with the pulsing `.live-dot`, "Today", "Tomorrow", "This weekend", "In 13 days") | rows, the Next card, the next round |
| Archive line (date · name · Results · place) | the series page's past, by year |
| Follow star (stateless CSRF, flips in place) | in a labelled variant in every page header |
| ⋯ button + the lazily loaded menu (`event_manage_menu`) | page headers, series rows |
| Place (`[flag] City, Country` / "Online") | header facts, rows, lines |

`OccurrenceDates` stays the one dating rule: the series page lists the occurrences of `GetEventOccurrences` (filtered
to the series), the timeline groups rounds into the same sessions, and the JSON-LD dates come from it.

## The shared header

```
Events › Harbor Jigsaw Nights                              (breadcrumb: links only, the H1 is the current page)
[logo]  Session 3                                          (H1 = the organiser's name, unchanged)
        [Online] · 5 Dec 2026 · Recurring · 3 rounds       (facts line)
        [I'm going] [☆ Follow series] [Registration ↗] [Website ↗] [⋯]
        The organiser's description, plain text, line breaks kept.
```

- **Breadcrumb**: series page "Events"; edition page "Events › {series}"; event page "Events". When the URL carries a
  valid `?return=` the existing back button (`_return_back_button.html.twig`) shows above it, as today.
- **Logo**: the event's own logo, else (edition) the series logo; series page: the series logo. 56 px (64 px from
  992 px), contained, decorative (`alt=""`, the H1 follows). No logo, no tile - nothing is invented.
- **Facts line**: `Online` tag or the place (`event_parts/_place.html.twig`); the dates (one day, a range, "Date not
  set", or "Runs until …" for a long span); "Live" with the dot while a session runs; editions: "Recurring"; the series
  page: how often ("about twice a month", derived from the dates - see "Series page"); event/edition with 2+ rounds:
  "N rounds".
- **Actions** (wrap on phones, 44 px targets, in this order):
  1. **I'm going** - not past, registration not managed: "I'm going!" (`join_competition`), or "✓ You're going!"
     linking down to "Taking part". Managed registration: **Registration** links down to the registration card
     (`#registration`). Past: **Add my time** when `can_add_time`.
  2. **Follow** - the labelled star: an edition follows its series ("Follow series" / "Following series"), a one-time
     event itself ("Follow" / "Following", not when past), a series itself. Only on publicly visible pages. Guests get
     the sign-in note, as on the events page.
  3. **Registration ↗** (external link, registration not managed), **Website ↗**, **Results ↗** (external results link,
     past only) - `utm_source=myspeedpuzzling` like every external link.
  4. **⋯** - only for viewers the voters allow (`COMPETITION_EDIT` on the event/edition, `COMPETITION_SERIES_EDIT` on
     the series): the events page's menu (Edit, Rounds & puzzles, Participants, Results, Page content, Delete; series:
     Manage series, Add edition, Page content, Delete series; admins: Approve/Reject while pending). It returns to this
     page; **Delete** returns to the parent (events page, or the series page for an edition).
- **Description** below, as today (`nl2br`, `data-event-description` kept).
- **Facts strip** (phones) / **side column** (from 992 px): the same facts as a strip under the header on phones and as
  a card in a right column on desktop, so the agenda starts right under the header.

## Series page

```
header (Follow series · Website · ⋯ Manage series / Add edition / Page content)
facts strip: 21 editions · since May 2025 · 7 upcoming dates · next Tue 13 Oct
┌ Next ─────────────────────────────────────────────┐
│ [TUE 13 OCT] Individual 500                        │
│              Online · 20:00 Toronto Time           │
│              (+ 02:00 next day, … (yours))         │
│              In 5 days                             │
│ [I'm going]  [Registration ↗]   tags: Registration │
└────────────────────────────────────────────────────┘
Upcoming · 7          (month headers, sticky; rows like the events page; the Next one is not repeated)
Ongoing               (long spans running now: "Runs until 7 Dec 2027")
Date not set          (editions without a date and without rounds, last - never hidden)
page sections         (the series' own, unchanged)
Past · 14             year chips [2026 · 5] [2025 · 9]; newest year open, 5 lines + "Show all 2026 (5)"
```

- **One list of sessions.** Every dated occurrence of the series (`GetEventOccurrences::forSeries()`): editions, and
  every session of an edition whose rounds fall on separate days. A monthly contest stored as six editions and one
  stored as six rounds of one edition look the same. Rows on this page carry no "Recurring" tag (it is the series
  page), no per-row star (every row would follow the same series - the header star does), and the ⋯ of the edition
  for organisers.
- **Row text**: the edition's name and the session's round name ("Season One · Sprint 3"), else the edition name;
  under it the place (in person) and the start time in the event's zone; tags as on the events page.
- **Next card**: the first live session, else the first upcoming one. Leaf, "Next" eyebrow, title, place or Online and
  the time, the countdown (or the full date beyond 30 days), "I'm going" / "✓ You're going" / the registration state
  and "Registration ↗". It links the edition page (a session: `#round-<id>`).
- **Ongoing**: spans over 31 days their rounds do not define, while they run (the events page rule) - never "Live",
  never the Next card.
- **Date not set**: editions with neither a date nor a round, listed last with "Date not set", as today.
- **Past by year**: one archive line per past session (never rolled up - this page *is* the roll-up), newest first,
  with the **Results** tag per session. Year chips switch the year in place; the newest year shows 5 lines and
  "Show all 2026 (14)". Without JavaScript every year is shown under its own heading.
- **Facts**: editions (every edition, the undated ones too - the events page's directory count), since (the first
  dated session), "N upcoming dates" (live + upcoming sessions - dates, not editions), next (date, or "live now"), how often - **about every week /
  about twice a month / about once a month** from the median gap of the last 8 session starts (at least 3 sessions;
  otherwise nothing is said).
- **Side column** (from 992 px): "About" (editions, how often, since, next, website, place or Online) and, for a
  follower, "You get the next date of this series under “Your events” on the events page."
- **Page sections** (organiser-written) stay, below the upcoming agenda and above the past.
- **A series without editions**: header, "No editions yet.", sections. Organisers find "Add edition" in ⋯.
- **An unapproved or rejected series** is reachable at its URL as today (`noindex, nofollow`), without the follow star.

## Edition page and one-time event page

```
header (crumb with the series; I'm going · Follow series · Registration ↗ · Website ↗ · ⋯)
facts strip: next Wed 21 Oct · 18:45 New York Time · 5 rounds with results
Rounds · 6
  [ Show 4 earlier rounds ]                         (<details>; opens itself when a #round-<id> link points inside)
  [WED 16 SEP] Sprint 4  ·Solo· 60 min · 18:45 New York Time (+ 00:45 next day, … (yours))
               [thumb] Puzzle name · 500 pieces · Brand
               Results · Add my time
▶ [WED 21 OCT] Sprint 5  ·Solo· 60 min · 18:45 New York Time       In 13 days     (highlighted: the next round)
               Puzzles not announced yet
               Registration ↗
  [WED 18 NOV] Sprint 6 …
More puzzles of this event            (puzzles not in any round: tagged extras, or - no rounds - logged ones)
Taking part (#taking-part)            ("I'm going" with Change/Leave, or the registration card #registration; Add my time)
marketplace card                      (unchanged)
page sections                         (the edition's own, then its series'; unchanged content)
participants                          (the CompetitionParticipants component, unchanged)
```

### Rounds timeline (both pages, one partial)

- **One row per round**, in schedule order (`GetEditionRounds`: by start, ties by id), each with `id="round-<id>"` -
  the anchor the events page and the series page link sessions to. Today the edition page puts the id on its round
  block and the event page on empty spans; both become the row.
- **Date leaf**: the round's start day in its own zone (`RoundTimezone`), coral in person / blue online / grey once
  over.
- **Name** as the round badge in its colour (`RoundBadgeColor`, as everywhere a round appears), the category pill
  (Solo / Pair / Team, `data-round-category`), the time limit, and the **start time in the event's zone with the zone
  named** (`data-round-zone`); online events add the visitor's time in the browser (below).
- **Status**: *past* (start + time limit before now), *live* (running: "Live" with the dot), *next* (the first round not
  over: highlighted, with "Today", "Tomorrow", "This weekend" or "In 13 days"), *later*. A screen reader hears
  "Next round:" before the next one.
- **Folding**: with a next round and two or more past rounds before it, the past rounds except the latest fold behind
  "Show N earlier rounds" (a native `<details>`). A link to `#round-<id>` of a folded round opens it. An event that is
  over, or not started, folds nothing.
- **Puzzles inside their round** as compact items: a 44 px thumbnail, the name (linking the puzzle), pieces · brand,
  the difficulty icon for members, the viewer's own badges (solved, in collection…). **Secret puzzles keep every rule**:
  the read model already drops a puzzle hidden entirely and nulls a hidden picture (`GetEditionRounds`,
  `RoundPuzzleReveal`, `hide_until` / `hide_image_until`). A hidden picture shows a neutral tile "Picture revealed when
  the round starts" and no link; an upcoming round without a visible puzzle says "Puzzles not announced yet" (never
  how many are secret).
- **Links per round**:
  - **Results** (or **Official results** when the organiser published them) - the round results page, only on a
    publicly visible page, for a round with a slug and something to show (`CountCompetitionResults::perRound()`).
  - **Add my time** - signed in, the page is publicly visible and the round has started: `puzzle_add` with
    `?competition=<id>`, and the puzzle pre-selected when the round has exactly one visible puzzle with its picture
    shown.
  - **Organiser tools** (Results desk · Live entry) for organisers, the existing
    `official_results/_organiser_round_links.html.twig`.
  - The external **Registration ↗** on the next round when the event has a registration link.
- **No rounds**: no timeline; the header carries the dates and the puzzles section lists the tagged puzzles, else the
  puzzles people logged times for (most logged first, at most 24 - as today, now on the edition page too).

### The rest of the page (kept, re-laid out)

- **More puzzles of this event**: the full puzzle cards (`_puzzle_item.html.twig`: solved count, your time, badges,
  the add/stopwatch/collection dropdown) for puzzles **not** shown in a round. With no rounds this is the only puzzle
  list ("Competition puzzles", or the "no puzzles yet" text on the event page).
- **Taking part** (`#taking-part`): `_event_attendance.html.twig` unchanged (I'm going / You're going + Change + Leave,
  or the registration card with `id="registration"`), plus "Add my time" on the event page as today.
- **Marketplace card** (`_event_offers.html.twig`): unchanged, after Taking part.
- **Page sections**: unchanged content and rules (public pages only; an edition shows its own, then its series'), now
  after the marketplace card.
- **Participants** (`<twig:CompetitionParticipants>`): unchanged, last.
- **Side column** (from 992 px): edition - "Part of {series}"; both - the dates, the place or Online, the website, and
  for online events with rounds "Times are in New York Time. Your own time is shown next to each round."; in-person
  events with rounds "Times are in Czechia Time, where the event takes place."

## Times and time zones

- **Server** (`event_time()` Twig function, `EventTime` value): the instant in the event's zone, in the page's
  language (ICU skeleton `jm` via `EventsPageDates` - English pages en-GB, so "18:45"), followed by the zone's name
  (`ZonedDateTimeFormatter::timezoneName()`: "New York Time", "Czechia Time"; an assumed zone - no country anywhere -
  "Central European Time", as today). The zone is resolved once per round (`RoundTimezone::resolve()`), the same zone
  that dates the session.
- **Browser, online events only** (`event_local_time_controller.js`, lazy): for each `<time data-event-time
  datetime="…Z" data-event-zone="America/New_York">`, when the visitor's zone
  (`Intl.DateTimeFormat().resolvedOptions().timeZone`) gives a different wall time, it fills the empty
  `[data-local-time]` next to it: "00:45 next day, Central European Time (yours)" / "…previous day…" / "17:45,
  Central European Time (yours)". The zone name comes from `Intl` (`timeZoneName: 'longGeneric'`, falling back to the
  zone's id) in the page's language. Same wall time, an unknown zone or no `Intl` → nothing is added.
- **In-person events** render no `[data-local-time]` and no controller.
- Registration windows keep `zoned_datetime()` on the registration card (already zone-named).

## What is kept, removed, moved

| | |
|---|---|
| **Kept** | Titles, meta descriptions, robots rules, H1 = the organiser's name; description; Website/Registration/Results/Add my time; "I'm going" and the registration card; the marketplace card; page sections; participants; round badge colours and category pills; secret puzzle rules; `#round-<id>` anchors; the puzzle cards for puzzles outside rounds; legacy `/en/edition/{id}` redirects; the `?return=` back button. |
| **Moved** | Page sections: from right after the description to after the agenda (series) / after the marketplace card (event, edition). Round puzzles: from big cards to compact items in their round. "Date not set" editions: from the upcoming cards to their own group at the end of Upcoming. |
| **Removed** | `_series_edition_card.html.twig` and the series page's card grid; the event page's "Results by round" button row (each round has its Results link); the round pills inside the event page's puzzle cards (`puzzle_rounds` in `_puzzle_item.html.twig`) - the puzzle sits in its round now; `GetCompetitionPuzzles::roundPuzzleOverviews()` if nothing else uses it; the dd.mm. dates of `_event_date_range.html.twig` on these pages (the manage page keeps it); the Bootstrap "Online"/"Recurring" badges here. |
| **Fixed on the way** | The edition page linked round results pages of non-public editions (they answer 404) and of rounds without results - now only public pages and rounds with something to show, like the event page. |

## SEO

- **Titles, meta descriptions, H1, robots: unchanged** (`EventTitle`, `events.meta.*`, `series.meta.*`).
- **Event JSON-LD** (event and edition page): the same `Event` with the same fields. Its dates come from
  `OccurrenceDates` over all rounds: start = the first session's first day, end = the last session's last day - the
  same as today's `date_from`/`date_to` in the common case; an edition dated only by its rounds now gets its `Event`
  too (today it is left out). A competition with **2+ sessions** adds `subEvent`: one `Event` per session (name
  "{event} · {round or date}", start/end of the session, `url` = the page `#round-<first round id>`, the same
  attendance mode and location).
- **EventSeries JSON-LD** (series page): unchanged in meaning; `subEvent` is one item per dated **session** (today one
  per edition), with the edition's logo as `image`; undated editions stay out.
- Every value through `json_ld`. Sitemaps unchanged (the URLs are). No new routes, no redirects.

## Accessibility

- One H1; H2 per section (Next, Upcoming, Past, Rounds, Taking part, …); month and year headers H3.
- The timeline is an ordered list; the next round is announced ("Next round:"), the live one by its "Live" text (the
  dot is `aria-hidden`, static under `prefers-reduced-motion`). The leaf is `aria-hidden`; each row says its date in
  words for screen readers, as the events page rows do.
- The labelled follow button keeps its visible text as its name (`aria-pressed`, no `aria-label` override); icon-only
  buttons (⋯) have `aria-label`.
- Year chips are buttons with `aria-pressed` and `aria-controls`; without JavaScript every year shows. "Show N earlier
  rounds" is a native `<details>`.
- The visitor's time is plain text after the event's time, never instead of it.
- 44 px targets, visible focus (`ev-` focus ring), contrast of the events page tokens (≥ 4.5:1), checked at 320/360/390
  px in all 6 locales.
- **No theme colour that fails on white** inside `.ev-page` / `.ev-detail` (`_event-parts.scss`, scoped - the site's
  `$primary` stays): links, `btn-primary` / `btn-outline-primary` and `.text-primary` are `$ev-coral-ink` #c9393f
  (5.1:1 on white, 4.6:1 on the coral wash; `$primary` #fe696a is 2.8:1), success / danger / warning texts, pills and
  buttons (the puzzles' "Solved" / "For sale" pills, the registration card) and the participants table get the darker
  `$ev-go` / `$ev-danger-ink` / ink text. Round badges pick black or white by APCA (`RoundBadgeColor`).

## Every kind of event

| Kind | Series page | Edition / event page |
|---|---|---|
| One-time, with rounds | - | Timeline; Event JSON-LD; Results per round |
| One-time, without rounds | - | No timeline; header dates; "Competition puzzles" (tagged, else logged ones) or the "no puzzles yet" text |
| Multi-day championship (Fri–Sun rounds, in person) | - | One session; every round its own leaf; times in the event's zone, **no** second time; no `subEvent` |
| Edition with a round a month (online) | A row per session (Next card, upcoming months, past lines with Results per session) | Timeline: earlier rounds folded, the next highlighted with its countdown, second time per round; `subEvent` per session |
| Editions as separate competitions (one a month) | A row per edition | Each edition its own page (one round or none) |
| Undated edition (no date, no rounds) | "Date not set", last | "Date not set" in the header; no timeline; no Event JSON-LD |
| Long span without rounds (> 31 days) | "Ongoing" with "Runs until …" while it runs; Upcoming before, Past after | Header "Runs until …"; no timeline |
| Series without editions | Header, "No editions yet.", sections | - |
| Online | Times + visitor's time | Times + visitor's time, side note "Times are in …" |
| In person | Place, times in the event's zone | Place, times, side note "…where the event takes place" |
| Managed registration | Next card / rows: Open, Opens 12 Oct, Closed, Full · waitlist | Header "Registration" → the card `#registration` |
| External registration link | "Registration" tag, Next card "Registration ↗" | Header and next round "Registration ↗" |
| Official results published | Past line "Results" | Round link "Official results"; the title says Results (unchanged rule) |
| Secret puzzles | - | Dropped or picture hidden by the read model; "Puzzles not announced yet"; no per-puzzle "Add my time" while hidden |
| Not public (pending/rejected) | `noindex`; no star; organisers' ⋯ (admins: Approve/Reject) | `noindex`; no star, no Results links, no Add my time (as today) |

## Performance

A fixed number of statements per page, pinned by a query-budget test; adding editions, sessions or rounds adds none.

- **Series page**: the series (`GetCompetitionSeries::bySlug()`, which also says whether sections exist), the series'
  occurrences (`GetEventOccurrences::forSeries()`, one statement - rounds as JSON), the going counts of coming
  sessions (`GetEventGoingCounts`, none without any). Signed in: + the viewer's going/follow rows
  (`GetEventsViewerData`) + the permissions statement the voters share. **Guest 3** (today 4), **signed in 9** (today
  8; the site's signed-in overhead is 4). Sections: +1, only when the series has some.
- **Edition and event pages**: today's statements (event, rounds + their puzzles, visibility, puzzles, difficulty,
  statuses, attendance, offers, participants). The follow state rides on the attendance statement (an `EXISTS` on
  `followed_competition`); signed in + the permissions statement (header ⋯, organiser round links); the edition page
  + `CountCompetitionResults::perRound()` when it has a started round with a slug on a public page (the event page
  runs it already); the event page no longer runs `GetCompetitionPuzzles::roundPuzzleOverviews()` when it has rounds
  (their puzzles are in the timeline). Ceilings in the plan.

## As built (2026-10-08)

Where the build differs from the text above (details in the plan's "Foundation deviations" and "Answers"):

- **Zone names** are ICU's localised generic names on both sides ("22:00 Eastern Time", "Central European Time"), not
  "New York Time" / "Czechia Time"; hours are two-digit ("04:00"), 24 h in every locale.
- **No "how often"** fact on the series page (Answers 3) - facts are editions, since, coming, next.
- **JSON-LD** lives in two partials, `event_parts/_event_json_ld.html.twig` (event and edition page) and
  `event_parts/_series_json_ld.html.twig`. The `Event` is emitted for a publicly visible page whose timeline has a start
  (`RoundsTimeline::$start`/`$end`, date-only); with two or more sessions it adds a `subEvent` per session named
  "{title} · {the session's one round, else its dates}", `url` = the page `#round-<first round id>`, `endDate` only for a
  session of several days, the page's attendance mode and location. A series `subEvent` is a public dated session
  (`SeriesPageBuilder`), named "{edition} · {round}" for a session of several, with `endDate` and the attendance mode.
  Every other field is unchanged. Pinned by `DetailPagesJsonLdTest` (parses, sessions, no `subEvent` for a weekend
  championship, `</script>` in a name, nothing on non-public pages).
- **Page sections** sit between Upcoming and Past (series) and after Taking part and the marketplace card, before the
  participants (event, edition) - pinned by `PageSectionsOnPagesTest`, also documented in
  [public-page.md](../competitions-management/public-page.md).
- **Removed**: `_series_edition_card.html.twig`, the `puzzle_rounds` / `round_results_urls` pills of
  `_puzzle_item.html.twig`, `GetCompetitionPuzzles::roundPuzzleOverviews()` (the secret-puzzle rules of round puzzles are
  tested on `GetEditionRounds`), `.event-round-anchor` (the `.ev-round` row carries the scroll margin), and the keys
  `events.results_by_round`, `competition.online`, `competition.recurring` in all 6 locales. Kept for the manage pages:
  `_event_date_range.html.twig`, `GetCompetitionSeries::upcomingEditions()` / `pastEditions()`,
  `series.upcoming_editions` / `series.past_editions`.

## Not in this change

- Format chips / an "Organisation" level for series running several formats (Jan, waiting for organisers' answers).
- An organiser-only hint on long spans without rounds ("add a round per night"); BreadcrumbList JSON-LD on these pages;
  a live leaderboard link for spectators - tracked in `docs/TODO.md`.

## Conflicts and open questions

Resolved here (flag to Jan in the PR):

1. **Page sections move.** `public-page.md` puts them right after the description and `PageSectionsOnPagesTest`
   asserts that order. The proposal says "below the agenda" (series) and lists them after the marketplace card (edition,
   event). Decision: series - after Upcoming, before Past; edition/event - after the marketplace card, before the
   participants (the participants list is long and live; it stays last as today). The "zero statements without
   sections" rule is unchanged; `public-page.md` and the test are updated.
2. **"Keep everything" vs compact round puzzles.** A puzzle inside its round loses the big card's solved count and its
   dropdown (stopwatch, collection, wishlist) - one tap away on the puzzle page; "Add my time" is per round. Puzzles
   outside rounds keep the full card. If Jan wants the dropdown back on round puzzles, it is one include.
3. **The event page's "Results by round" row** duplicates the per-round Results links of the timeline - removed.
4. **Edition page round results links** pointed to 404 pages on non-public editions - fixed (public only, rounds with
   results only), costing the edition page the one `perRound()` statement the event page already pays.
5. **Results per session.** `EventOccurrence::hasResults` is per competition: every past session of a multi-session
   edition would show "Results" once one round has results. The shared rounds join (`OccurrenceRounds::SQL_JOIN`)
   gets a per-round `has_results` (an `EXISTS` on the `puzzle_solving_time.competition_round_id` index + the official
   results rule), so a session's Results tag is its own - the events page's session lines benefit too. Measured with
   `EXPLAIN ANALYZE` before merging; fallback: the per-round flag only in `forSeries()`.
6. **JSON-LD dating** moves from `date_from` to `OccurrenceDates` (equal in the common case) and editions dated only
   by rounds gain an `Event`. `subEvent` URLs carry `#round-<id>`.
7. **Breadcrumb vs the back button**: both - the breadcrumb always, the `?return=` button above it when present.
8. **Series page rows have no star** (they would all follow the same series); the header star does it.
9. **Signed-in series page +1 statement** (viewer rows + permissions vs today's none); guests save one.

Open questions for Jan:

1. **English time format.** The events page writes English dates in en-GB, so times read "18:45 New York Time"; Jan's
   example was "6:45 pm New York time". Keep en-GB (consistent, plan default), or switch English pages to 12-hour times?
2. **The visitor's zone name.** Browsers cannot name a zone by its place the way the server does ("New York Time");
   `Intl` gives "Central European Time" / "Eastern Time". Plan: that generic name in the page's language. Alternative:
   the city of the zone id ("Prague"), English only.
3. **"How often" on the series page** is derived (median gap of the last 8 dates). Keep, or drop it until organisers
   can state it themselves?
4. **Round puzzles' dropdown actions** (question 2 above): fine to drop?
