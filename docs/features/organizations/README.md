# Organizations and drafts

The design of record for **organizations** (a new, optional level above series and one-time events) and **drafts**
(every event item can be prepared privately and published when it is ready), plus three small extras ("Who can enter",
"When it happens", "Add several dates") and the tools to restructure existing data (move an edition, move a round,
turn a series into an organization). Jan approved the proposal on 2026-10-08 with nothing cut. The build contract is
[implementation-plan.md](implementation-plan.md).

It builds on the events page ([../events-page/README.md](../events-page/README.md)) and the series, edition and event
pages ([../events-page/detail-pages.md](../events-page/detail-pages.md)): the same parts (`templates/event_parts/`), the
same occurrences read model (`GetEventOccurrences`) and the same dating rule (`OccurrenceDates`).

**This repository is public.** Every organiser, event and venue name in this document, the code, the fixtures and the
tests is made up ("Riverbend Jigsaw Association", "Lantern Brewing Puzzle Night", "Harbor Puzzle Club").

## Goal

A state jigsaw association put **all** its events into one series: a monthly online contest and two casual bar nights
at two breweries. Asked what they need, the organiser answered:

1. **The association is the main thing.** A page for the association suits them. Most of their events are open to
   residents of their state only.
2. **Every monthly contest stands alone.** They keep rankings internally but deliberately do not publish them (newcomers
   should not feel scared) - so no season ranking.
3. **The bar nights are casual.** No results are kept. They happen on the first Monday / last Tuesday of the month, now
   and then moved by the venue.
4. **"What does follow mean?" - and "we keep growing".** Another bar competition starts this month; other state
   associations grow the same way. Everything must scale without an admin approving every new night.

The same confusion is all over production: a series is used both for **who runs it** and for **what repeats**, and
events of one association that belong together are not grouped at all. An organization separates the two: the
organization is who runs it, a series is one recurring format, an edition is one date.

```
Organization  (an association, a club, a shop or brand, a venue...)   NEW: own page, team, follow, approval, draft
 |- Series     (one recurring format)                                  unchanged (+ organization, when it happens, who can enter)
 |    `- Edition (one date or one contest, with rounds and results)    unchanged (+ who can enter)
 `- One-time event                                                     unchanged (+ organization, who can enter)
```

Everything is optional: a series or event without an organization behaves exactly as today, and nobody has to use
drafts.

## Decisions

### Jan's contract and the orchestrator's decisions (final)

| Topic | Decision |
|---|---|
| Name | **Organization** (American spelling, like "You organize"); entity `Organization`. |
| Hierarchy | Organization → series → editions, and organization → one-time events. An **edition never has its own organization** - it belongs to its series' (a handler refuses one). One organization per item; co-hosts come later. Deleting an organization leaves its items without one (`ON DELETE SET NULL`). |
| Fields | Name, short name, slug, logo, about, website, social links (icon from the host), country + region (free text), kind (association/federation, club, shop or brand, venue, community, other), team (maintainers), creator, approval like series, created at, draft. |
| Permissions | The organization's creator and team edit it, manage its team, create/edit/delete series and events under it and manage their editions, rounds and results, exactly like series maintainers do today. Admins can do everything. Implemented as more legs of the one statement in `GetCompetitionPermissions`. |
| Approval | Any signed-in player can create an organization; it waits for an admin like a series. The internal API creates approved ones. **A series or one-time event created under, or moved into, an approved organization by a member of its team (or an admin) needs no admin approval** - done explicitly in the handlers through one service, `OrganizationApprovalPolicy` (D2). |
| Organization page | `organization_detail`, `/en/organizations/{slug}`, all 6 locales: header, About, "Coming up", "What we run", "Past". **No public team list** (private profiles and blocks would come into play; the team sees itself on the edit page). Indexable when approved and published, in the sitemap. Unknown slug → 404. |
| Directory | `organizations`, `/en/organizations`: approved, published organizations with counts and the next date. Light. |
| Integration | "Organized by …" on series, edition and event pages; organization names in the events page search; the organization's name on series directory lines; "You organize" groups items under their organizations; the add/edit series and event forms get an "Organization" select. |
| Follow | A third target: an organization. "Your events" adds, per followed organization, the next date of each of its series and its upcoming one-time events, marked Following and deduplicated against what is followed directly. Only publicly visible organizations can be followed. |
| Drafts | A `draft` flag on one-time events, editions, series and organizations. "Save as draft" next to the normal submit of every add form. A draft is visible only to its creator, its team (including the series' and organization's teams above it) and admins - its page shows "Draft: only you and your team can see this page." with **Publish**; everyone else gets 404. Never indexed, never in any public list, picker, API, sitemap, follow, join or registration. |
| Publishing | Publish = the flag goes off. An item that still needs approval then enters the approval queue (and the admin e-mail is sent then). Drafts never appear in the approval queue or in any count. Back to draft ("Unpublish") only while nobody has joined and no result or solving time is linked; an organization can always go back. |
| Scope of a draft | A draft series hides its editions. A draft edition in a published series hides only itself. A draft organization hides only its own page, its directory entry and its "Organized by" links - its series and events keep their own state. |
| One visibility rule | `IsCompetitionPubliclyVisible::SQL_CONDITION` includes drafts; new `IsSeriesPubliclyVisible` and `IsOrganizationPubliclyVisible` constants for series and organization rows. Every reader converted or listed with a reason in a guard test; a canary test proves drafts appear on no public surface. |
| Who can enter / When it happens | `eligibility` (≤ 120 chars) on series, one-time events and editions (an edition shows its own, else its series'), shown as a tag on rows and on the pages, labelled **"Who can enter"**. `schedule` (≤ 160 chars) on series, shown on the series page and on the organization's cards, labelled **"When it happens"**. Free text, all optional. |
| Add several dates | On a series: a rule (every week on a weekday / the Nth weekday of the month / the last weekday of the month) and a count, or picked dates - up to 24 real editions in one step, each one day, named from an editable pattern with `{date}` written in the page's language. No stored recurrence: a venue reschedule is an edit of that one edition. |
| Restructuring tools | Messages + internal API + one small web page each (D13): **Move to another series** (an edition), **Move to another event or edition** (a round, with its puzzles, table layout and solving times), **Turn into an organization** (a series). Old URLs keep working through one redirect table (D6). |
| Feature flag | None - everything is opt-in by nature. |

### Decided while planning (can be revisited)

| # | Decision | Why |
|---|---|---|
| P1 | `IsCompetitionPubliclyVisible` also gets `SQL_APPROVED` (the approval part alone - today's rule) and `SQL_NOT_DRAFT`. Approval-state readers (approval queues, "pending" filters, organiser badges) use `SQL_APPROVED` explicitly. | "Not visible" stops meaning "waiting for approval" once drafts exist - an approved draft is not pending. |
| P2 | **Approving an organization approves its pending series and one-time events** (not the rejected ones) in the same step. | Only its team (or an admin) can put anything under an organization; the admin who trusts the organization should not approve each of its items again. Same spirit as D2. |
| P3 | The team of an organization has the creator's rights on everything under it, **including deleting** its series and events (the contract says "create/edit/delete"). Deleting the organization itself is for its creator and admins only, like a series. | Mirrors series owner vs maintainer. |
| P4 | An organization can be deleted **only when it is empty** (no series, no one-time events) - web and internal API alike (409 otherwise). Its follows and redirect rows go with it. | Nothing is detached by accident; moving items out is one select away. |
| P5 | A draft page answers with its own 404 (`DraftNotVisible`), which the redirect lookup ignores: a draft that took an old path shadows the redirect. | "The live page always wins" (D6) - the draft exists, it is just not public. |
| P6 | The series page and the organization page show their drafts and pending items **to their team** as rows tagged Draft / Waiting for approval - never to anyone else. | Preview: the organiser sees the page as it will look. |
| P7 | An edition of a draft series shows the banner "This series is a draft: only you and your team can see this page." with **Publish series**. | The edition itself may not be a draft. |
| P8 | Publishing a series does not touch its editions' own draft flags; unpublishing a series checks every edition (participants, results, times). | A draft edition stays a draft until its own Publish. |
| P9 | "You organize" lists organizations first, each with its series and events grouped under it; the header count is organizations + the items not under one of them. | The organization is the main thing (organiser answer 1). |
| P10 | Localised paths: cs `/organizace`, en `/en/organizations`, es `/es/organizaciones`, fr `/fr/organisations`, de `/de/organisationen`, ja `/ja/団体` (+ `/{slug}` for the page). Add/edit/manage pages follow the existing `add-event` / `edit-series` style. | As `events` / `event_detail`. |
| P11 | Organization slugs: generated from the name once, unique among organizations, kept on rename; the edit form's "URL" field changes it (no redirect) - the series rules. | One slug rule for the whole family. |
| P12 | An organization waiting for approval (or rejected) is reachable at its URL with `noindex, nofollow` and without the follow star - like a series today. Only a **draft** is 404. | Consistent with series; the contract asks 404 for drafts only. |
| P13 | Social links: at most 10, `http`/`https` only, each URL once, typed one per line. Platform from the host (D9); anything else gets a generic link icon (Bootstrap Icons, already loaded site-wide). | Organisers paste links; no per-platform fields. |
| P14 | Limits: name 1-120 characters, short name ≤ 30, region ≤ 120, about ≤ 5,000, website ≤ 255 (URL). | Same order as series fields. |
| P15 | "Add several dates" is its own page (`add_editions`), linked from "Add edition" and the series ⋯ menu. The rule is previewed with a GET (works without JavaScript), the dates come back as a checked list, then one POST creates them. | No JavaScript copy of the date rules; nothing is created before the organiser sees the dates. |
| P16 | Names of several dates use the ICU skeleton `yMMMMd` in the page's language ("Lantern Brewing Puzzle Night 5 October 2026"). | D15; readable, unambiguous for US organisers. |
| P17 | Joining (the "I'm going" page and message) requires a publicly visible event - pending and rejected ones too, not only drafts. | The join page of a non-public event showed its participant names to anyone with the id (found while planning). |
| P18 | Registration e-mails are not sent while an event is a draft. | An organiser can prepare participants on a draft; those people would get e-mails pointing at a page that answers 404. |
| P19 | The organization page's rows carry no per-row star (the series cards and the header have stars) and keep the "Recurring" tag. Its past is one line per occurrence, by year (the series page's past). | The proposal's sketch; no new roll-up rule. |
| P20 | Breadcrumbs gain the organization: series "Events › {organization}", edition "Events › {organization} › {series}", event "Events › {organization}" - only while the organization is publicly visible (its team sees it while it is a draft). | The organization is the main thing. |
| P21 | Moving an edition refreshes its place: a location or country equal to the old series' (copied when the edition was added) takes the new series'; values the organiser changed stay. | Editions copy the place of their series at creation. |
| P22 | A round's move keeps its wall-clock time zone (`saveDisplayedTimezone()` first) and refuses while its stopwatch runs. | A round without a saved zone would otherwise jump to the new event's country. |
| P23 | Moving a one-time event into a series (the "annual championship as separate events" pattern) is **not** part of this change - TODO. | Its URL, follows and approval change shape; it needs its own rules. |

## Data model

### `organization` (entity `Organization`)

| Field (column) | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | no | `Uuid::uuid7()` |
| `name` | varchar(255) | no | 1-120 characters (forms, API) |
| `shortName` (`short_name`) | varchar(255) | yes | ≤ 30 characters ("RJA") |
| `slug` | varchar(255) | no | unique; `CompetitionSlugGenerator::PATTERN` |
| `logo` | varchar(255) | yes | storage key `organizations/{id}-{timestamp}.{ext}` (listed in `GetStoredFileReferences`) |
| `about` | text | yes | plain text, ≤ 5,000 characters, shown with line breaks |
| `website` | varchar(255) | yes | URL |
| `socialLinks` (`social_links`) | jsonb | no, default `[]` | list of URLs, ≤ 10, http/https, unique - `SocialLinks` value |
| `countryCode` (`country_code`) | varchar(255) | yes | lower-case ISO 3166-1 alpha-2 (normalised like `Competition::$locationCountryCode`) |
| `region` | varchar(255) | yes | free text ≤ 120 ("Riverbend Valley", a state, a city) |
| `kind` | varchar(255) enum `OrganizationKind` | yes | `association`, `club`, `shop`, `venue`, `community`, `other` |
| `isDraft` (`is_draft`) | bool | no, default false | |
| `addedByPlayer` (`added_by_player_id`) | FK player | yes | the creator; nulled on account deletion |
| `approvedAt`, `approvedByPlayer`, `rejectedAt`, `rejectedByPlayer`, `rejectionReason` | as `CompetitionSeries` | yes | |
| `createdAt` | datetime_immutable | no | |
| `maintainers` | many-to-many player, table `organization_maintainer` | - | the team (≤ 10, like series) |

### New columns on existing tables

| Table | Column | Type | Null | Notes |
|---|---|---|---|---|
| `competition` | `organization_id` | uuid FK `organization` | yes | `ON DELETE SET NULL`; **one-time events only** - always NULL on an edition |
| `competition` | `is_draft` | bool | no, default false | existing rows stay published |
| `competition` | `eligibility` | varchar(120) | yes | "Who can enter" |
| `competition_series` | `organization_id` | uuid FK `organization` | yes | `ON DELETE SET NULL` |
| `competition_series` | `is_draft` | bool | no, default false | |
| `competition_series` | `eligibility` | varchar(120) | yes | inherited by editions without their own |
| `competition_series` | `schedule` | varchar(160) | yes | "When it happens" |
| `followed_competition` | `organization_id` | uuid FK `organization` | yes | `ON DELETE CASCADE`, unique per player; exactly one of competition / series / organization (named constructors, no DB check) |

### `event_url_redirect` (entity `EventUrlRedirect`, D6)

| Field (column) | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | no | |
| `seriesSlug` (`series_slug`) | varchar(255) | no, default `''` | `''` = not part of the path |
| `competitionSlug` (`competition_slug`) | varchar(255) | no, default `''` | |
| `roundSlug` (`round_slug`) | varchar(255) | no, default `''` | |
| `organization`, `series`, `competition`, `round` | FK | yes | **exactly one** set (named constructors), each `ON DELETE CASCADE` |
| `createdAt` | datetime_immutable | no | |

Unique (`series_slug`, `competition_slug`, `round_slug`). The key spells the old path: `('', 'x', '')` = `/events/x`,
`('s', '', '')` = `/series/s`, `('s', 'e', '')` = `/series/s/e`, `('s', 'e', 'r')` = the edition's round results,
`('', 'x', 'r')` = the event's round results. A new row for a path that has one already replaces its target.

## Visibility

Three constants, one per kind of row, each with a `check(id)` method:

| Constant | Aliases | Rule |
|---|---|---|
| `IsCompetitionPubliclyVisible::SQL_CONDITION` | `c` competition, `cs` its series (LEFT JOIN) | not rejected, not a draft; a one-time event approved; an edition's series approved, not rejected, not a draft |
| `IsCompetitionPubliclyVisible::SQL_APPROVED` | same | the approval part only (today's rule) |
| `IsCompetitionPubliclyVisible::SQL_NOT_DRAFT` | same | the competition and its series are not drafts |
| `IsSeriesPubliclyVisible::SQL_CONDITION` | `cs` | approved, not rejected, not a draft |
| `IsOrganizationPubliclyVisible::SQL_CONDITION` | `o` | approved, not rejected, not a draft |

**An organization's state never hides its series or events** (D4): a series under a pending or draft organization is
public when the series itself is. The organization's own page, directory entry, "Organized by" link, crumb and
follow star need the organization to be publicly visible.

| State | Organization | Series | Edition | One-time event |
|---|---|---|---|---|
| **Published** (approved, not draft) | page indexable, in the directory and sitemap, followable | public with its editions | public when its series is | public |
| **Draft** | page 404 except for its team and admins; no directory entry, no "Organized by" link; its items unaffected | 404 except for its team; its editions hidden too | 404 except for its team (series team included); the rest of the series unaffected | 404 except for its team |
| **Waiting for approval** (published) | page reachable, `noindex`, no star; in the approval queue | as today: reachable, `noindex`, in the queue | follows its series | as today: reachable, `noindex`, in the queue |
| **Waiting for approval + draft** | 404 except for its team; **not** in the queue | same | - | same |
| **Rejected** | reachable, `noindex`; its creator sees the reason under "You organize" | as today | as today | as today |

"Its team" = whoever `GetCompetitionPermissions` lets edit it: the creator and maintainers of the item, of its series
and of its organization - and admins.

### Approval

- An organization is created pending (web) or approved (internal API). Admins approve or reject it in the same queue
  as series (`/admin/competition-approvals`); the creator gets the existing "approved" / "rejected" e-mails.
- **Under an approved organization** (D2): when a series or one-time event is **created under** or **moved into** an
  approved, not rejected organization by its creator, one of its maintainers or an admin, and the item is pending
  (neither approved nor rejected), the handler approves it at once (`approvedBy` = the acting player). A rejected item
  stays rejected. An edition is never approved on its own. Draft or not does not matter: an approved draft is public
  the moment it is published. `OrganizationApprovalPolicy` is the one place of the rule; every handler that creates or
  moves an item calls it.
- **Approving an organization** approves its pending series and one-time events too (P2).
- **Drafts and the queue.** The approval queue, its admin-menu badge and the admin view of the events page list pending
  items that are **not** drafts. A pending draft is submitted by publishing it: the admin e-mail goes out then.
  Approving a draft is allowed (the internal API can; the policy does) - it stays hidden until published.

## Permissions

`GetCompetitionPermissions::forPlayer()` stays one statement per request; it gains these legs:

| Leg | Gives |
|---|---|
| organization created by the player | edit + delete the organization |
| organization the player maintains | edit the organization (its team included) |
| series whose organization the player created or maintains | edit + delete the series |
| one-time event whose organization the player created or maintains | edit + delete the event |
| edition of a series whose organization the player created or maintains | edit + delete the edition |

`CompetitionPermissions` gains `canEditOrganization()`, `canDeleteOrganization()` and `organizationIds()` (the
organizations the player is on the team of - the choices of the "Organization" select). Voters:
`ORGANIZATION_EDIT`, `ORGANIZATION_DELETE` (admins always). The existing `COMPETITION_EDIT`, `COMPETITION_DELETE`,
`COMPETITION_SERIES_EDIT`, `COMPETITION_SERIES_DELETE` include the new legs without any change of their own.

| Action | Who |
|---|---|
| Create an organization | any signed-in player (pending) |
| Edit it, manage its team, publish/unpublish it | its creator and team, admins |
| Delete it (only when empty) | its creator, admins |
| Create a series or one-time event under it | its team, admins (choosing it in the "Organization" select) |
| Move an item into it | someone who can edit the item **and** is on the organization's team (or an admin) |
| Move an item out of it | someone who can edit the item |
| Approve / reject it | admins |
| Move an edition | someone who can edit the edition and the target series |
| Move a round | someone who can edit both competitions |
| Turn a series into an organization | someone who can edit the series |
| See a draft | whoever can edit it |

## Pages

### Organization page (`organization_detail`)

```
[draft banner, team only]  Draft: only you and your team can see this page.  [Publish]
Events › Riverbend Jigsaw Association                         (crumbs)
[logo]  Riverbend Jigsaw Association                           (H1)
        Association · [us] Riverbend Valley, United States · RJA
        [☆ Follow]  [Website ↗]  [ig] [discord] …  [⋯]        (⋯ for the team: Edit, Add event, Publish/Unpublish, Delete; admins: Approve/Reject while pending)
About                                                          (plain text, line breaks kept)
Coming up                                                      (rows like the series page: leaf · name · place · tags (Who can enter, Recurring, registration…) · when · ⋯)
What we run                                                    (cards)
   Lantern Brewing Puzzle Night            ☆                   one card per series: name; place or Online · When it happens;
   Riverbend · First Monday, 7 pm                              Who can enter · N editions; "Next: Mon 2 Nov" (or "Last: …",
   21+ · 4 editions                                            "No dates yet"); a follow star per series
   Next: Mon 2 Nov
   One-time events: Riverbend Spring Open · Sat 12 Dec ☆      upcoming one-time events, one card each
Past                                                           year chips; newest year open, 5 lines + "Show all 2026 (N)"
```

- **Header**: logo (decorative), H1 = name, a facts line (kind, flag + region and country, short name), actions:
  the labelled follow star (only when publicly visible), Website ↗, one icon link per social link (its platform's
  name as the accessible name), ⋯ for the team.
- **Coming up**: every live and upcoming occurrence of the organization's series and one-time events from
  `GetEventOccurrences::forOrganization()` - the series page's sessions and month headers, Live first, then Ongoing.
  Rows link the occurrence; no per-row star (P19).
- **What we run**: the series cards (public ones; the team also sees drafts and pending ones, tagged), ordered by next
  date, then name; then the upcoming one-time events as cards with a star.
- **Past**: one archive line per past occurrence, newest first, by year (the series page's rule and markup).
- **Empty organization**: "Nothing planned yet." and, for the team, "Add event".
- **SEO**: title "{name} - puzzle events" (`organization_page.meta.title`), meta description from the about text or a
  generic line; `Organization` JSON-LD through `json_ld` (`name`, `alternateName` = short name, `url`, `logo` - the
  1200 px preset, `description`, `sameAs` = website + social links, `address` with `addressRegion` /
  `addressCountry`); indexable only while publicly visible, otherwise `noindex, nofollow`; in `sitemap-events.xml`.
- **Statements**: guest 4 (organization, occurrences, series rows, going counts - the last only when something is
  coming); signed in 10 (+ 4 site overhead, the viewer's follow rows, the permissions statement). Pinned by
  `OrganizationPagesQueryBudgetTest`; more series or editions add none.

### Directory (`organizations`)

One list of publicly visible organizations, alphabetically: logo, name, kind, flag + region, "3 series · 2 events",
"Next: Sat 12 Dec" (from the occurrences). "+ Add organization" for signed-in players. Linked from the series
directory head on the events page ("Organizations →") and from "You organize". Indexable, in `sitemap-static.xml`.
Two statements (organizations, their occurrences); signed in + 4.

### "Organized by", crumbs and the extras on the detail pages

The series, edition and event pages gain a **byline** under the H1 (each part only when it has something):
"Organized by **Riverbend Jigsaw Association**" (a link; publicly visible organizations only, or for its team) ·
"Who can enter: Residents of Riverbend Valley" · on the series page "When it happens: First Monday of the month, 7 pm".
Crumbs follow P20. No extra statement: the organization rides on the statement each page runs anyway.

### Events page

- The search index folds in the organization's name and short name (`x`), so "riverbend" finds its events.
- Series directory lines show "by Riverbend Jigsaw Association" (publicly visible organizations only).
- Rows get the **Who can enter** tag (the text, with a visually hidden "Who can enter:" before it).
- "Your events" adds followed organizations (below). Drafts are nowhere, also not in the admin view.
- Statement counts unchanged (guest 3, signed in 8, admin 9, archive 1): the organization comes from a join in the
  occurrences and series statements, the follows from the viewer statement.

### "You organize"

Organizations first (name, Draft / Waiting for approval / Rejected badge, "3 series · 2 events", their actions), each
followed by its series and one-time events (indented, the existing lines and actions); then everything else as today.
New badge **Draft** (before Waiting for approval; an item that is both shows Draft). The header button counts
organizations plus items not under one of them (P9). "+ Add organization" next to "+ Add event".

### The ⋯ menu

| Item | Items it appears on | Route |
|---|---|---|
| Publish / Unpublish | everything the viewer can edit (in the ⋯ menu Unpublish only while allowed - otherwise the reason; on "You organize" a refusal comes back as a message) | `publish_*` / `unpublish_*` |
| Edit organization, Add event, Delete organization (creator, empty only), Approve / Reject (admins, pending) | organizations | `edit_organization`, `add_competition?organization=`, `delete_organization`, `admin_approve_organization`, `admin_reject_organization` |
| Add several dates, Turn into an organization (no organization yet) | series | `add_editions`, `create_organization_from_series` |
| Move to another series | editions | `move_edition` |
| Move to another event or edition | each round on the rounds page | `move_competition_round` |

### Forms

- **Add event / add series** (`add_competition`), **edit event** (`edit_competition`), **edit series**
  (`edit_competition_series`): an **Organization** select - the organizations the player is on the team of (admins:
  all not rejected), "None" first; `?organization=<id>` pre-selects it. An edition's edit form shows its series'
  organization read-only. Changing it moves the item (`AssignEventToOrganization`). **Who can enter** (help: "Leave
  empty when anyone can enter. Shown as a tag, e.g. 'Residents of the state', '21+'."); series: **When it happens**
  (help: "e.g. 'Third Wednesday of the month, 6:45 pm' - each date is still its own edition.").
- **Add edition** (`add_edition`): Who can enter (help: "Leave empty to use the series' setting."), and a link "Add
  several dates" to `add_editions`.
- **Add organization** (`add_organization`) / **edit organization** (`edit_organization`): name, short name, kind,
  country, region, about, website, social links (one per line), logo, team (the maintainers picker of the event forms);
  edit adds the "URL" field.
- **Save as draft**: every add form (event, series, edition, several dates, organization) gets a secondary submit
  "Save as draft" next to the primary one. The primary keeps today's behaviour.

### Add several dates (`add_editions`)

| Field | Values |
|---|---|
| How | **Repeat** (a rule) or **Pick dates** |
| Rule | every week on a weekday; the 1st/2nd/3rd/4th weekday of the month; the last weekday of the month |
| Starting | a date (first match on or after it) |
| How many | 1-24 |
| Picked dates | up to 24 dates (one date field per line, "+ another") |
| Name | pattern, default "{series name} {date}"; `{date}` → the date in the page's language (`yMMMMd`); without `{date}` every name gets the date appended (editions need distinct slugs) |
| Who can enter | optional, for all of them |
| Draft | "Save as draft" creates them as drafts |

"Show the dates" (GET) lists the dates with their names, each with a checkbox (all checked); "Create N editions" (POST)
creates the checked ones through one message (`AddEditions`, max 24): each edition is dated `dateFrom = dateTo` = that
day, gets a slug unique in its series and the series' place, like `AddEdition`. A date already holding an edition of
the series is marked "already has an edition" and unchecked.

### Admin approval queue

`/admin/competition-approvals` gains an "Organizations" section above series (name, kind, region, creator, created,
website, Approve / Reject with reason). Drafts are not listed. The admin-menu badge counts pending organizations, series
and one-time events that are not drafts.

## Follow

`followed_competition.organization_id`: `FollowedCompetition::ofOrganization()`, `FollowTarget::organization()`
(`organization:<uuid>` in the forms), the same `event_follow` / `event_unfollow` routes and messages
(`FollowCompetition` / `UnfollowCompetition`). Only a publicly visible organization can be followed (otherwise
`FollowTargetNotAvailable`); unfollowing always works.

**"Your events"** (events page): for every followed organization that is publicly visible, the next live-or-upcoming
occurrence of each of its series and each of its one-time events that is not over - one row per competition, marked
Following, Going winning as before. A series followed directly and through its organization is one row. Organization
follows add no statement (the viewer statement carries them).

## Drafts

| | One-time event | Edition | Series | Organization |
|---|---|---|---|---|
| Start as a draft | "Save as draft" (add form), internal API `draft` | "Save as draft" (add edition, several dates), API | "Save as draft", API | "Save as draft", API |
| Hidden | itself | itself | itself and its editions | its page, directory entry, "Organized by" links, crumbs |
| Visible to | creator, maintainers, series/organization team, admins | the same | creator, maintainers, organization team, admins | creator, team, admins |
| Its page for others | 404 | 404 | 404 | 404 |
| Publish | `publish_competition` | `publish_competition` | `publish_competition_series` | `publish_organization` |
| Publishing a pending one | enters the queue (admin e-mail) | - (approved through its series) | enters the queue | enters the queue |
| Unpublish | no participants (not deleted), no official results, no linked solving times | the same | the same for every edition | always |

- The page of a draft renders for its team with the banner and `noindex, nofollow`; nothing that needs a public page
  (follow star, "I'm going", Add my time, Results links, page sections) shows on it - the existing "not public" rules.
- Publishing an event whose official results were published while it was not public tells the players then (as
  approving does).
- A draft cannot get participants by joining (P17), solving times (the pickers offer public events only; the API
  refuses a round of a non-public event), follows or marketplace marks.
- The organisers' tools work on drafts as on any event: rounds, puzzles, participants sheet, seating, results desk,
  page sections (their content shows once the page is public).

## Restructuring tools and old URLs

| Tool | Message | Web (`/{_locale}/…`) | Internal API |
|---|---|---|---|
| Move an edition to another series | `MoveEditionToSeries` | `move_edition` - `/move-edition/{competitionId}` | `POST /internal-api/competitions/{id}/move` |
| Move a round to another event or edition | `MoveRoundToCompetition` | `move_competition_round` - `/move-round/{roundId}` | `POST /internal-api/rounds/{id}/move` |
| Turn a series into an organization | `CreateOrganizationFromSeries` | `create_organization_from_series` - `/series-to-organization/{seriesId}` | `POST /internal-api/series/{id}/create-organization` |

**Moving an edition**: to another series both managed by the actor. Its slug must be free in the target series - else
the web form asks for a new one and the API answers 409 (or takes `slug`). Its place follows P21; its organization is
the target series'; its visibility follows the target series. Participants, rounds, results and times stay with it.
Writes redirect rows: the old edition path and each old round results path.

**Moving a round** (D7): to another one-time event or edition the actor manages. It moves the round row, its puzzles
(with their reveal settings), its table layout and every solving time linked to it (their `competition_id`; the round
link stays), then both competitions' round results are reconciled. A slug taken in the target gets `-2`, `-3`, …
Refused (409, nothing changes) when the target is the same competition; the round has participant entries or teams
(moving participants is a later step); a puzzle of the round is already in a round of the same category in the target
(the one-round-per-category invariant); its stopwatch is running. Writes a redirect row for the old round results path.

**Turning a series into an organization**: creates the organization from the series (name, logo, about = description,
website = link, country, maintainers; the series' creator becomes its creator), moves the series' followers to the
organization (a player following both keeps one row), attaches the series - optionally renamed and with a new slug.
Web: the organization waits for approval unless an admin does it; internal API: approved. With a new series slug, the
old series path redirects to the organization and every old edition and round results path to its page.

**Redirects** (D6): one exception subscriber, on a 404 of `event_detail`, `competition_series_detail`,
`edition_detail`, `event_round_results` or `edition_round_results` only, looks the path up and answers **301** to the
target's **current** URL (so chained moves keep working), keeping the query string. **What still 404s**: an explicit
slug change through the "URL" field or the API (unchanged rule), deleted items, a path a live item has taken since (the
live page wins - also a draft, P5), `#round-<id>` anchors of a moved round on its old event page (fragments never reach
the server), and anything not moved by these three tools.

## Internal API

Same patterns as the competitions endpoints (`InternalApiInput`, the web forms' validation, JSON errors, the audit log,
`INTERNAL_API_REVIEWER_PLAYER_ID` as the acting player). Full field tables in
[../internal-api.md](../internal-api.md) once built; the OpenAPI spec lists every endpoint.

| Method | Path | Purpose | Answer | 4xx |
|---|---|---|---|---|
| `GET` | `/internal-api/organizations?q=&status=&limit=&offset=` | list / search (`status`: all, approved, pending, rejected, draft) | `200` list | 400 |
| `GET` | `/internal-api/organizations/{idOrSlug}` | one organization: fields, state, team, its series and one-time events | `200` | 404 |
| `POST` | `/internal-api/organizations` | create - **approved**; `draft: true` keeps it a draft | `201` | 400, 409 slug |
| `PATCH` | `/internal-api/organizations/{id}` | change the fields sent (`draft` → publish/unpublish) | `200` | 400, 404, 409 slug |
| `POST` | `/internal-api/organizations/{id}/approve` | approve a pending one (and its pending items, P2) | `204` | 404, 409 |
| `DELETE` | `/internal-api/organizations/{id}` | delete an empty organization | `204` | 404, 409 not empty |
| `POST` | `/internal-api/organizations/{id}/maintainers` | add `{"playerId"}` to the team | `204` | 400, 404 |
| `DELETE` | `/internal-api/organizations/{id}/maintainers/{playerId}` | remove from the team | `204` | 404 |
| `POST` | `/internal-api/organizations/{id}/publish` · `/unpublish` | draft off / on | `204` | 404 |
| `GET` | `/internal-api/series?q=&status=&limit=&offset=` | list / search series | `200` list | 400 |
| `GET` | `/internal-api/series/{idOrSlug}` | one series with its editions | `200` | 404 |
| `POST` | `/internal-api/series` | create a series (`approve`, `organizationId`, `draft`, `slug`, `eligibility`, `schedule`) | `201` | 400, 409 |
| `PATCH` | `/internal-api/series/{id}` | change the fields sent | `200` | 400, 404, 409 |
| `POST` | `/internal-api/series/{id}/publish` · `/unpublish` | | `204` | 404, 409 cannot unpublish |
| `POST` | `/internal-api/series/{id}/editions` | create an edition (`name`, `dateFrom`, `dateTo`, links, `eligibility`, `draft`, `slug`) | `201` competition | 400, 404, 409 slug |
| `PUT` | `/internal-api/series/{id}/organization` · `/internal-api/competitions/{id}/organization` | assign `{"organizationId": "…" \| null}` | `200` | 400, 404, 409 edition |
| `POST` | `/internal-api/competitions/{id}/publish` · `/unpublish` | | `204` | 404, 409 cannot unpublish |
| `POST` | `/internal-api/competitions/{id}/move` | move an edition `{"seriesId", "slug"?}` | `200` competition | 400, 404, 409 |
| `POST` | `/internal-api/rounds/{id}/move` | move a round `{"competitionId"}` | `200` round | 404, 409 |
| `POST` | `/internal-api/series/{id}/create-organization` | turn the series into an organization | `201` organization | 400, 404, 409 |

Existing competition endpoints gain the fields `organizationId` (create; `PATCH` moves it), `eligibility` and
`draft` (create starts a draft; `PATCH` publishes / unpublishes), and the answers gain `organizationId`,
`eligibility`, `draft`; `status` stays the approval state (`approved`, `pending`, `rejected` - an approved draft is
`approved` with `draft: true`), and the list's `status` filter gains `draft`.

## Every kind of event

| Kind | Organization page | Series page | Edition / event page | Events page |
|---|---|---|---|---|
| Organization, published | its page | "Organized by" in the byline, crumb | byline, crumb | search finds its items by its name; series lines "by …" |
| Organization, draft | 404 (team: banner) | no "Organized by" (team: shown) | same | its name not searchable; series lines without it |
| Organization, pending / rejected | reachable, `noindex`, no star | no "Organized by" | same | same |
| Series under an organization | card in "What we run", its dates in Coming up / Past | as today + byline | as today + byline | as today |
| One-time event under an organization | card while upcoming, Coming up / Past | - | as today + byline | as today |
| Draft one-time event | team only, tagged Draft | - | 404 (team: banner) | nowhere (not even for admins) |
| Draft edition in a published series | team only, tagged | team only, tagged Draft | 404 (team: banner) | nowhere |
| Edition of a draft series | team only | 404 (team: banner) | 404 (team: "series is a draft" banner) | nowhere |
| Draft series | team only, card tagged Draft | 404 (team: banner) | its editions 404 | nowhere |
| Pending, published | team only, tagged Waiting for approval | as today | as today | admins: "Waiting for approval" |
| Item with "Who can enter" | tag on rows and cards | byline + rows | byline | tag on rows |
| Series with "When it happens" | on its card | byline | - | - |

Everything else in the events page's and the detail pages' "Every kind of event" tables is unchanged.

## Organiser patterns seen in production (outreach candidates)

Their data is not changed by this work; each is a candidate to talk to once organizations ship (`docs/TODO.md`).

1. **One series holding several formats**: a state association (monthly online contests + two bar nights) - the
   motivating case; an organiser with about 21 editions in three formats (individual, pairs/teams, a skills format).
2. **One organiser, two series of one brand**: a shop with an online and an in-person series.
3. **A duplicate series**: one city club with a series and a second "<name> 2026" series.
4. **Association events not grouped**: a German puzzle club (about 10 one-time events), a national association
   (nationals, three regionals, online), a national association in the UK, Nordic federations, a Spanish group, a
   Japanese brand.
5. **An annual championship entered as separate one-time events**: the world federation (2019-2026), an Australian
   national association (2022-2026) - needs organization → championship series → yearly edition, i.e. moving a
   one-time event into a series (P23, later).

## Later (tracked in `docs/TODO.md`)

- Co-hosts: several organizations on one event.
- Notifications for followed organizations (a new date, registration opens).
- A casual/competitive flag (an event without rounds already shows no results).
- Season rankings (the motivating organiser explicitly does not want them).
- Outreach to the organisers above; merging the duplicate series of other organisers.
- Moving participants (round entries, teams) with a round.
- Moving a one-time event into a series (P23).
- Page sections on organization pages; an organization's own event calendar export.

## Performance and privacy

| Page | Guest | Signed in | Notes |
|---|---|---|---|
| Organization page | 4 | 10 | constant in series, editions and rounds |
| Organizations directory | 2 | 6 | |
| Events page | 3 (unchanged) | 8 / admin 9 (unchanged) | archive 1 (unchanged) |
| Series / edition / event pages | unchanged | unchanged | `DetailPagesQueryBudgetTest` stays green as it is |
| "You organize" | - | + 1 (organizations) | constant in items |

A draft adds no statement for guests (404 before anything else is read); for a signed-in viewer the draft check uses the
permissions statement the page's ⋯ already runs. No page of this feature shows player identity: no public team list,
follow counts are not shown, the "Organization" select lists only the player's own organizations.
