# Events page

The design of record for the redesigned `/en/events` (route `events`, all 6 locales). Approved by Jan on 2026-10-08.
The proposal with the working prototype (real data, guest/player/admin, phone/desktop):
https://claude.ai/artifact/KfoHM99HN7LQGd3D6iQ4aD. The build contract is [implementation-plan.md](implementation-plan.md).

The series, edition and one-time event pages reuse this page's parts (date leaf, agenda row, tags, when label, archive
line, follow star, ⋯ menu, place - moved to `templates/event_parts/` and `assets/styles/_event-parts.scss`): see
[detail-pages.md](detail-pages.md). A change to a shared part changes those pages too - and the organization page,
which is built from the same parts.

**Organizations and drafts** (PR #252, [../organizations/README.md](../organizations/README.md)) add a level above
series and one-time events and let every item be prepared as a draft. On this page: organization names in the search,
"by …" on series lines, organizations as a third follow target, organizations in "You organize", Publish / Unpublish
and the restructuring tools in the ⋯ menu, a "Who can enter" tag - and **drafts appear nowhere, admins included**.

## Goal

The old page was 149 full cards in one list (13 upcoming, 136 "Past events"), 448 KB of HTML, with the filters, the
WJPC link and "My competitions" cards above the first event. Visitors said it is hard to understand, bloated and not
compact. The new page is a compact **agenda of dated occurrences**: one-time events and series editions together,
grouped by month, filterable by country in one tap, with your own events on top. Series get a directory, a calendar
is one tap away, and past events move into a year archive.

## Usage baseline (production traces, 7 days up to 2026-10-08, bots excluded by user agent)

- 1,004 page views from 404 IPs. By locale: en 692, de 234, cs 38, fr 22, ja 10, es 8.
- 256 filter changes (country, time period, online) from 115 IPs - about 28 % of visitors filter.
- 48 calendar toggles from 32 IPs (about 8 %), then 64 day picks from 16 IPs and 69 month changes (38 back from 9
  IPs, 31 forward from 10).

After launch we compare `?view=calendar` loads and chip taps with this. Chip taps no longer reach the server (the page
filters in the browser), so they are counted as analytics events (implementation plan, "Measuring"). An IP is not a
person, and a calendar toggle counted opening and closing alike.

Data on 2026-10-07: 134 one-time events, 15 series, 80 editions; 21 upcoming dates (10 one-time, 11 editions) in 8
countries and online; 15 ongoing online events; 1 in-person event without a date; 4 series without editions.

## Decisions (Jan, 2026-10-08 - final)

| Topic | Decision |
|---|---|
| Default scope | **Everywhere, for everyone, always.** No remembered or personal default. Your country is the first chip (from the profile); guests get a country guessed in the browser, offered as a chip and never applied. |
| Favourites | **Yes, in phase 1, series included.** Signed-in players star a one-time event or a whole series. "Your events" lists events you are going to, followed events and the next edition of every followed series, by date, each marked Going or Following. |
| Calendar on mobile | **Keep it and make it better**: a List \| Calendar switch, a compact month grid with the month's rows below it. |
| Organiser tools | **In phase 1.** One "You organize" entry point in the page header for anyone who created or maintains something, one ⋯ menu on each row they can manage, the approval status always visible. Admins see unapproved items in the list with Approve and Reject. |
| A map | **Not now** (recommendation). 21 upcoming dates, 11 online: a map shows a few dots and needs a map library and tiles. Reconsider at 40+ upcoming in-person events. |

Decisions taken while planning (2026-10-08, can be revisited): dates are shown in the event's own time zone (see
"Dates"); the ⋯ menu's results item opens the event's results overview (`competition_results_overview`), because the
results desk is per round; the country sheet groups by the regions the site already uses (`sell_swap_list.settings.region.*`)
instead of the prototype's continents; country names are localised (Symfony Intl), falling back to the English name.

## Page anatomy (list view, top to bottom)

1. **Header.** Title "Events", a summary line ("21 upcoming dates in 8 countries and online · 15 series"),
   "+ Add event" and, for anyone who created or maintains an event, series or organization, a labelled **"You
   organize (n)"** button to the `organized_events` page. Only public items are counted in the summary.
2. **Toolbar** (sticky under the sticky site header; on phones it slides away while scrolling down and comes back on
   the first scroll up). One search field; then **List | Calendar**; then chips: Everywhere (count), your country
   (signed in: profile; guest: browser guess), Online (count), up to 6 countries by number of upcoming dates, and
   "All countries ▾" which opens the **country sheet**. On desktop the countries move to a side column and the toolbar
   keeps Everywhere, your country and Online.
3. **Your events** (signed in, scope Everywhere, no search): a horizontal strip of cards, ordered by date (undated
   last), each marked **Going** or **Following**. Going wins when both apply.
4. **Agenda.** **"Live"** (a pulsing red dot, then the word; the dot is `aria-hidden`, static under
   `prefers-reduced-motion` - the shared `.live-dot` of the live event strip) first when something is live, then one
   block per month under a sticky month header
   that carries the year (and the country when one is selected), then **"Date to be announced"** at the end.
5. **Series directory.** All public series: "In person", "Online", then **"Ongoing"**: online one-time events without
   dates, and occurrences without rounds spanning more than a month while they run (place and "Runs until …"). Its
   head links the organizations directory ("Organizations →", route `organizations`). Each
   line: name, "by {organization}" (plain text, publicly visible organizations only), place or Online, edition count
   (sessions of one edition count once), next date ("Next: Tue 12 Nov"),
   "Live" (with the dot), "Ongoing", last date ("Last: 3 Mar") or "No dates yet". Sorted: series with a next date by that date, then by last date
   (newest first), then by name.
6. **Archive.** One chip per year with past occurrences. The newest year is open with its 5 latest lines and
   "Show all 2026 (58)". Other years open in place from the index; their chips are real links to `events_archive`.
   In a country or Online view the archive becomes "Past · Czechia": every year of that scope, under year headers.
7. **Footer card.** "Organising a puzzle event? Add it to the calendar…" with the organiser guide (`for_organizers`).

Desktop (≥ 992 px): two columns. The side column holds a **Countries** card (every country with upcoming dates, with
counts, and "More countries…") and, in the list view, a **mini calendar** whose day highlights that day's rows.

### Agenda row anatomy

`[date leaf] [name / edition name / place / tags] [when · ☆ · ⋯]`

- **Date leaf**: weekday band (`SAT–SUN`), day (`10–11`), month (`OCT`, `OCT–NOV` across months). Band colour: coral
  in person, blue online, grey past or no date ("TBA"). The year lives in the month header. Long-running occurrences
  (over 14 days) show only the first day in the leaf and a "Runs until 7 Dec 2027" tag.
- **Name**: the event name; for an edition the series name, then the edition name on its own line - left out when it
  equals the series name (case-insensitive, as `CompetitionReference::displayName()`). The whole row opens its page.
- **Place**: `[flag] City, Country`. The city is cut with an ellipsis when too long; the country is always shown in
  full; a location that already contains the country name shows the country only. The flag (`fi fi-xx`) is never the
  only sign of the country. Online occurrences say "Online".
- **Tags**: Waiting for approval (admins only, never a draft) · ✓ Going · Recurring (editions) · Who can enter (the
  organiser's text - an edition's own, else its series' - with a visually hidden "Who can enter:" before it) ·
  registration state · Results (past) · Runs until … · "41 going".
- **When**: "Live" (with the pulsing dot, long-running ones too), "Tomorrow", "This weekend" (a Fri–Sun of the current Monday-first week, so never next week's), "In 16 days"
  (up to 30 days; coral when ≤ 14).
- **☆** follows the event (an edition's star follows its series). Guests get "Sign in to follow events and series."
  under the row. No star on past rows, nor on rows waiting for approval (admins only).
- **⋯** only on rows the viewer can manage (and on every row for admins).

**Month roll-up**: several upcoming editions of one series in the same month are **one row**: the series name,
"3 sessions" and a date chip per edition (each chip opens its edition page; the name opens the series page). The leaf
shows the first date. Sessions of one edition (rounds on separate days, see "Dates") roll up the same way, a chip
per session linking `#round-<id>` on the edition page. Live editions are never rolled up (they are under Live).

### Registration and results tags

- Registration managed on MySpeedPuzzling (`registration_managed`): the real state from `RegistrationAvailability`
  (Open, Closed, "Opens 12 Oct") and capacity against spots taken (`CompetitionParticipantGoing`): "Full · waitlist".
- Only an external registration link: "Registration" - it never claims registration is open.
- Results (past only): a results link, published official results (`GetPublishedRoundResults::sqlShowsOfficialResults()`),
  or round results (non-suspicious solving times linked to a round).

### Dates

`OccurrenceDates` is the one rule - the events page, the archive, `GetCompetitionSlugsForSitemap::archiveYears()` and
"You organize" all date through `OccurrenceDates::sessions()`, fed by one shared rounds join (`OccurrenceRounds`, a
JSON list of every round inside the same single statement).

- A round's day is its start in the **event's own zone** (`RoundTimezone::resolve()`: the round's zone, else the
  event's or the series' country) - an evening round in Toronto is that evening's date, not the next UTC day. The
  guest HTML is the same for everybody, so it cannot use the viewer's zone.
- **Sessions.** Round days at most 2 days apart are one session (a Friday-Sunday championship stays one, even
  without a Saturday round), and so is every round inside a declared span (`date_from`..`date_to`) of at most 7 days.
  Two or more sessions - a monthly online competition inside one edition or one-time event - make **one dated
  occurrence per session**: start = its first round's day, end = its last round's day, its own status;
  `date_from`/`date_to` are not used. The row keeps the series/edition naming and adds the round's name when the
  session has a single round; it links `#round-<first round id>` (edition page: the round blocks; event page: an empty
  anchor per round above its puzzles). Index entries are positional, so every session has its own `id`; `cm` names
  the competition. (Seen in production, 2026-10: an edition with a round a month was one span listed as live for
  months.)
- **One session** (the common case) or no rounds - one-time events and editions alike: dated by the first round's
  day, else `date_from` (else `date_to`); the end is the later of `date_to` and the last round's day.
- **A span over 31 days its rounds do not define is never live** - no rounds, or `date_to` more than 31 days after
  the last round (one opening round of a 14-month event): while it runs it is *ongoing*. It counts as "upcoming" in
  the summary, the chips and the country counts (something is on there), but is not an upcoming *date* in the month
  headers. "You organize" shows it as Live.

**"Today" is today in the occurrence's own zone** - the zone its days are in (`OccurrenceDates::zone()`: its first
round's zone, else the event's or the series' country zone, else the assumed one - exactly the zone that dates its
rounds). `status()` takes the request's instant, never a pre-computed day: a 6:45 pm Eastern contest (23:45 UTC in
winter) stays live after UTC midnight, an Auckland event is live while the UTC date still says yesterday. The "Tomorrow",
"This weekend", "In 3 days" labels and the calendar's "Today" cell count from the server's UTC date, from the
controller's clock.

**Written in the page's language.** Every date on the events pages comes from an ICU skeleton (`yMMMM` month headers,
`MMMd` / `yMMMd` days, `MMMEd` next editions, `E` / `MMM` leaf parts), never a hand-written pattern:
`EventsPageDates` (Twig `|events_date('yMMMd')`, `events_date_range(from, to, 'MMMd')`) on the server,
`formatDate()` / `formatDayRange()` of `assets/events_index.js` in the browser - "October 2026", "Oktober 2026",
"říjen 2026", "2026年10月". English pages use en-GB ("12 Oct 2026"). A range writes what both days share once, on the
side the locale puts it ("10–11 Oct", "10.–11. 10.", "2025年10月10日–11日"). `EventsIndexScriptTest` pins that both
sides write the same text in all 6 locales.

**Date fields** (every `.date-picker`, `assets/datepicker_locale.js`, `DatePickerScriptTest`): the calendar starts on the
*visitor's* first day of the week (browser locale; Czech pages Monday) - a Monday-first grid once made an American
organiser's Monday bar night a Tuesday. The field shows the day with its weekday in the page's language ("Mon, 5 Oct
2026", skeleton `yMMMEd`); the submitted value keeps the form's own format.

| Status | Rule |
|---|---|
| live | start ≤ today ≤ end (end = last day, else start), except a long span its rounds do not define |
| upcoming | start > today |
| past | end < today |
| tba | one-time, in person, no date |
| ongoing | one-time, online, no date; or a long span its rounds do not define, start ≤ today ≤ end |
| date_not_set | edition without date and without rounds |

## Every kind of event

| Kind | Where it shows | Links to |
|---|---|---|
| One-time, in person, dated | Agenda by month, then the archive. Counts under its country. | `event_detail` |
| One-time, in person, no date | "Date to be announced" at the end of the agenda, counted per country (sheet: "date TBA"). | `event_detail` |
| One-time, online, dated | Agenda and archive, counts under Online. | `event_detail` |
| One-time, online, no date | "Ongoing" in the series directory. | `event_detail` |
| Any occurrence over more than 31 days its rounds do not define | Upcoming in the agenda until it starts, then "Ongoing" in the series directory (place, "Runs until …") - never Live, not in a month; a bar in the calendar; Past in the archive. | its own page |
| Rounds on separate days (a round a month) | One dated occurrence per **session**: its own days, status, row ("Season One · October 2026" when the session has one round), month roll-up, archive line, calendar dots and index entry. "Your events" shows only the next session not over. Going count, star and ⋯ belong to the competition. | its page `#round-<first round id>` |
| Series in person | Series directory "In person"; its editions count under the series' country. | `competition_series_detail` |
| Series online | Series directory "Online"; its editions count under Online, even when the series has a country. | `competition_series_detail` |
| Edition, dated | Agenda row: series name, edition name, place, "Recurring". | `edition_detail` |
| Edition, live | "Live". | `edition_detail` |
| Edition, long-running (> 14 days) | "Runs until …" tag; a bar under the calendar grid instead of a dot on every day. | `edition_detail` |
| Edition, date not set | Not in the agenda or calendar (the series page lists it last). Counted in the series' edition count. | series page |
| Several editions in one month | One row with a chip per date. | name: series; chip: edition |
| Past editions | Archive and country views: one line per series and year ("Harbor Jigsaw Nights · 5 editions in 2026" - editions, never sessions); a single edition stays its own line, and so do the sessions of one edition (or one-time event), from its first to its last day. The year chip and "Show all 2026 (N)" count events held (editions and one-time events, sessions once) - like the directory's edition counts; month headers count dates. Search lists matching sessions one by one. | series page (single: its page) |
| Waiting for approval | Only admins see it in the list ("Waiting for approval" tag, Approve/Reject in ⋯) - unless it is a draft too; its creator sees it under "You organize". Never counted. | its own page |
| Draft (a one-time event, an edition, a series and its editions) | **Nowhere** - not in the agenda, calendar, archive, search index, series directory, "Your events" or counts, also not for admins and not for its own team; the team finds it under "You organize" (Draft badge). | its own page (404 except for its team) |
| Under an organization | As without one; a series line says "by {organization}" and the search finds its items by the organization's name - both only while the organization is publicly visible. | its own page |
| Rejected | Nobody sees it in the list; its creator sees it under "You organize" with the reason. | - |
| Series without editions | Series directory, "No dates yet". | series page |

An occurrence without the slugs its route needs (`CompetitionReference::routeName()` is null) is listed without a link.
The full table with organizations, drafts and the organization page is in
[../organizations/README.md](../organizations/README.md#every-kind-of-event).

## Scope: country chips and the sheet

- **Online occurrences count under Online only**, never under a country - an online series whose country is Canada
  is not in the Canada view. This deliberately differs from the old filter, which matched the
  country column alone.
- Chips show only countries with upcoming dates (live + upcoming + long spans running now, in person, public), by
  count then name. The home
  chip is the one chip that may show 0: tapping it gives the honest empty state with the last past event and
  "+ Add event", plus the callout "Nothing planned in Czechia yet. Know about an event? Add it to the calendar."
- The **country sheet** (bottom sheet on phones, centred dialog on desktop) lists every country with at least one
  in-person event or edition, grouped by region, each with "2 upcoming · 1 date TBA · 14 past", and a search field.
- The guest guess uses `assets/country_guess.js` (as the Players page) and is offered only for a country in the sheet.

## Search

One field searches every event, edition and series, past included: name, series name, city, country (localised and
English), year, "online", and the name and short name of its organization (publicly visible organizations only - a
draft or pending organization's name finds nothing). **Every typed word must match**; `wjpc` and `ejpc` also match their full names. Text is
folded on the server with `SearchText::fold()` and the typed query in the browser with `foldSearchText()`
(`assets/search_fold.js`) - the same fold. Results: Upcoming (rows), Past (newest 30 lines, editions one by one, with
the year), Series, Ongoing. Search honours the selected scope.

## Calendar view (`?view=calendar`)

A month grid (Monday first) with dots - coral in person, blue online, grey past - "Today", ‹ ›. Days with something
are buttons; a day highlights its rows below the grid ("Saturday 10 October is highlighted · Show the whole month").
Multi-day occurrences mark every day; long-running ones get a bar under the grid ("from 3 Oct, until 7 Dec 2027")
instead. Below the grid: the month's rows (agenda rows for live/upcoming, archive lines for past). Desktop shows a
bigger grid with up to 2 names per cell and "+2 more". The calendar honours scope and search, works for every month
with data (past ones from the index), and keeps the month in the URL (`?view=calendar&month=2026-11`). An empty month
points to the side with matches ("Try the next month" / "Try an earlier month", plus "or Everywhere" in a scope), or
says other months have nothing either.

## Follow

A new table `followed_competition`: player, **exactly one** of competition (a one-time event), series or organization
(`organization_id`, added with organizations), `created_at`; unique per player and target; rows cascade with the
player, the competition, the series and the organization. Messages `FollowCompetition` / `UnfollowCompetition`
(target = a one-time event, a series or an organization - `FollowTarget`, `organization:<uuid>` in the forms). An
edition is never followed on its own - its star follows the series. Only publicly visible targets can be followed
(drafts never); unfollowing always works. Converting an event into a series (`ConvertCompetitionToSeriesHandler`)
moves its followers to the new series; turning a series into an organization moves them to the organization when it
is public at once, else keeps them and adds an organization follow per follower
([../organizations/README.md](../organizations/README.md#follow)). "I'm going" is unchanged and belongs to each
edition's own competition row.

**"Your events" and organizations**: a followed, publicly visible organization adds the next live-or-upcoming date of
each of its series and each of its one-time events that is not over - marked Following, one row per competition, Going
winning, deduplicated against what is followed directly (a series followed both ways is one row). No statement is
added - the viewer statement carries the organization follows.

The star is a stateless-CSRF POST form (like the comparison buttons): with JavaScript it is sent with `fetch` and
flips in place (every star of the same target on the page); without, it redirects back. Guests get the sign-in note.

## "You organize" (`organized_events`, `/{_locale}/you-organize`)

Every event and series the viewer created or maintains (and an edition they organise directly, when they do not
organise its series): name, kind, status badge - **Rejected** with "Reason: …", **Draft**, **Waiting for approval**,
Live, Upcoming, Past, Date not set - date/place or next edition and edition count, and its actions (the ⋯ items).
Delete and Unpublish are confirmed in place. The header button shows only when the list is not empty; its count and the
page use one rule.

**Organizations first**: the organizations the viewer created or is on the team of, each with its badge (Rejected,
Draft, Waiting for approval), "N series · N one-time events" and its actions, followed by its series and one-time
events (indented, the lines and actions above); then everything else. The count = organizations + the items not under
one of the viewer's own organizations. "+ Add organization" sits next to "+ Add event", with a link to the
organizations directory. Drafts are listed here (and only here, for their team) with Publish first; Unpublish is offered
on every published item without asking whether it would be refused (no statement per item) - a refusal comes back as
a flash naming the reasons. Details: [../organizations/README.md](../organizations/README.md#you-organize).

## The ⋯ menu

A popover on desktop, a bottom sheet on phones, loaded on demand (one Turbo Frame per page, route
`event_manage_menu`) so the page carries no forms or CSRF tokens for rows nobody opens. Items exist only for real
routes the viewer may use (voters `COMPETITION_EDIT`, `COMPETITION_DELETE`, `COMPETITION_SERIES_EDIT`,
`COMPETITION_SERIES_DELETE`, `ORGANIZATION_EDIT`, `ORGANIZATION_DELETE`, which read
`GetCompetitionPermissions::forPlayer()` once per request - the team of an organization has the creator's rights on
everything under it):

- Every item the viewer can edit: **Publish** first while it is a draft (`publish_competition`,
  `publish_competition_series`, `publish_organization`); **Unpublish…** before Delete once published, confirmed in
  place (`unpublish_*`) - when it would be refused (somebody joined, official results or linked solving times;
  `UnpublishBlockers`) the menu says "Can't go back to draft: …" instead. An edition of a draft series also gets
  **Publish series** (for the series' team).
- Event / edition: Edit event (`edit_competition`), Rounds & puzzles (`manage_competition_rounds`), Participants
  (`participants_sheet`), Results (`competition_results_overview`, when it has rounds), Page content
  (`manage_competition_page`), **Move to another series** (editions, for the people who can edit its series -
  `move_edition`), Delete (`delete_competition` / `delete_competition_edition`, owner only).
- Series: Manage series (`manage_competition_series`), Add edition (`add_edition`), **Add several dates**
  (`add_editions`), Page content (`manage_series_page`), **Turn into an organization** (no organization yet -
  `create_organization_from_series`), Delete series (`delete_competition_series`, owner only).
- Organization ("You organize" and its own page): Edit organization (`edit_organization`), Add event
  (`add_competition?organization=<id>`), Delete organization (`delete_organization`, its creator, only while it is
  empty).
- Waiting for approval, admins: Approve / Reject… (`admin_approve_competition`, `admin_reject_competition`, the
  `_series` and `_organization` routes) - never on a draft, which is submitted by publishing it; Reject asks for the
  reason in place. Every action returns to the events page.

The rounds page (`manage_competition_rounds`) adds **Move to another event or edition** per round
(`move_competition_round`). The three restructuring pages are described in
[../organizations/README.md](../organizations/README.md#restructuring-tools-and-old-urls).

## URL parameters and redirects

| Parameter | Meaning |
|---|---|
| `country=cz` | country scope (lowercase code) |
| `onlineOnly=1` | Online scope (kept from the old page) |
| `view=calendar` | calendar view |
| `month=2026-11` | calendar month (calendar view only) |
| `q=…` | search text |

Old parameters: `?timePeriod=past` → `events_archive` of the newest archive year; `?country=cz&timePeriod=past` →
`?country=cz` (the country view shows that country's past); any other `timePeriod` value is dropped;
`?showCalendar=1` → `?view=calendar`. Redirects are 301 and keep the other parameters, except the one to the newest archive year: 302, because that year changes. The browser keeps the URL in
step with the state (`history.replaceState`), and the server renders the same state on load (no flash).

## SEO

- `events` stays indexable on the bare URL only; any query string → `noindex, follow` (canonical and hreflang from
  `base.html.twig`, which already drop query strings).
- New route **`events_archive`**: `/en/events/archive/{year}` (`\d{4}`) and `/eventy/archiv/{year}`,
  `/es/eventos/archivo/{year}`, `/ja/イベント/アーカイブ/{year}`, `/fr/evenements/archives/{year}`,
  `/de/veranstaltungen/archiv/{year}`. Two segments after the prefix, so it never collides with `event_detail`.
  One indexable page per year with past occurrences, the same roll-up lines, links to every year; any other year 404s.
  Targets searches like "Czech jigsaw championship 2025".
- An **ItemList** JSON-LD (event and edition URLs) on the listing and on each archive page, through `json_ld`. Event
  rich results keep coming from the event pages' own Event JSON-LD.
- `sitemap-events.xml` keeps every event, series, edition and round page and adds the archive years. Event, edition
  and series pages are unchanged.

## What was removed

- The `EventsListing` Live Component (and its calendar Live actions), `_competition_event.html.twig`,
  `_competition_series_card.html.twig`, the `.events-calendar*` styles.
- The WJPC link above the list and `events.wjpc_hub_link` (6 locales) - the WJPC hub stays and the footer links it on
  every page.
- "My competitions" cards with edit/delete buttons - replaced by "You organize" and the ⋯ menus.
- Three outbound buttons per event (Info, Registration, Results) - they are on the event page.
- Event logos in the list - their sizes and quality vary too much. They stay on event pages and in "Your events".
- The ~250-country dropdown - replaced by chips and the sheet.

## Performance and privacy

A fixed number of statements per page view (occurrences, series, going counts; signed in: one viewer statement, plus
the permissions statement the voters already share), guarded by a query-budget test. The page ships a search and
calendar index of every occurrence and series (~230 entries, roughly 35 KB raw, under 10 KB gzipped). The page shows
no player identity - only counts ("41 going").

## Later (phase 2/3, tracked in docs/TODO.md)

- "3 sellers bringing puzzles" tag from [Marketplace at events](../marketplace/11-events.md); a "Live results" tag for
  events happening now.
- Notifications for followed series (new edition, registration opens); "3 of your favourite puzzlers are going".
- Data clean-up: duplicate and empty series, ongoing online events that are really series (`ConvertCompetitionToSeries`
  exists), annual championships entered as separate one-time events, undated duplicate editions. Moving editions and
  rounds and turning a series into an organization are possible since PR #252
  ([../organizations/README.md](../organizations/README.md#restructuring-tools-and-old-urls)); moving a one-time event
  into a series is not yet.
