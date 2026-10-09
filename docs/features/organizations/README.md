# Organizations and drafts

The design of record for **organizations** (a new, optional level above series and one-time events) and **drafts**
(every event item can be prepared privately and published when it is ready), plus three small extras ("Who can enter",
"When it happens", "Add several dates") and the tools to restructure existing data (move an edition, move a round,
turn a series into an organization). Jan approved the proposal on 2026-10-08 with nothing cut. The build contract is
[implementation-plan.md](implementation-plan.md); where the build differs from the text below, see
[As built](#as-built-2026-10-09) at the end.

It builds on the events page ([../events-page/README.md](../events-page/README.md)) and the series, edition and event
pages ([../events-page/detail-pages.md](../events-page/detail-pages.md)): the same parts (`templates/event_parts/`), the
same occurrences read model (`GetEventOccurrences`) and the same dating rule (`OccurrenceDates`).

**This repository is public.** Every organiser, event and venue name in this document, the code, the fixtures and the
tests is made up ("Riverbend Jigsaw Association", "Lantern Brewing Puzzle Night", "Harbor Puzzle Club").

## Goal

A regional association put three formats - a monthly online contest and two casual venue nights - into one series.
Asked what they need, the organiser answered:

1. **The association is the main thing.** A page for the association suits them. Some of their events are open to a
   limited group of people only.
2. **Every monthly contest stands alone.** No season ranking is wanted.
3. **The venue nights are casual.** No results are kept. Each happens on a fixed day of the month, now and then moved by
   the venue.
4. **"What does follow mean?" - and "we keep growing".** New venue nights keep starting; other associations grow the
   same way. Everything must scale without an admin approving every new night.

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
| Approval | Any signed-in player can create an organization; it waits for an admin like a series. The internal API (and an admin on the web) creates approved ones. **A series or one-time event created under, or moved into, an approved organization by a member of its team (or an admin) needs no admin approval** - done explicitly in the handlers through one service, `OrganizationApprovalPolicy` (D2). |
| Organization page | `organization_detail`, `/en/organizations/{slug}`, all 6 locales: header, About, "Coming up", "What we run", "Past". **No public team list** (private profiles and blocks would come into play; the team sees itself on the edit page). Indexable when approved and published, in the sitemap. Unknown slug → 404. |
| Directory | `organizations`, `/en/organizations`: approved, published organizations with counts and the next date. Light. (`noindex` and out of the sitemap while it lists none.) |
| Integration | "Organized by …" on series, edition and event pages; organization names in the events page search; the organization's name on series directory lines; "You organize" groups items under their organizations; the add/edit series and event forms get an "Organization" select. |
| Follow | A third target: an organization. "Your events" adds, per followed organization, the next date of each of its series and its upcoming one-time events, marked Following and deduplicated against what is followed directly. Only publicly visible organizations can be followed. |
| Drafts | A `draft` flag on one-time events, editions, series and organizations. "Save as draft" next to the normal submit of every add form. A draft is visible only to its creator, its team (including the series' and organization's teams above it) and admins - its page shows "Draft: only you and your team can see this page." with **Publish**; everyone else gets 404. Never indexed, never in any public list, picker, API, sitemap, follow, join or registration. |
| Publishing | Publish = the flag goes off. An item that still needs approval then enters the approval queue (and the admin e-mail is sent then - on its first publish only). Drafts never appear in the approval queue or in any count. Back to draft ("Unpublish") only while nobody has joined and no result or solving time is linked; an organization can always go back. |
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

- An organization is created pending (web), or approved (internal API, and an admin on the web). Admins approve or
  reject it in the same queue as series (`/admin/competition-approvals`); the creator gets the organization's own
  "approved" / "rejected" e-mails (`organization_approved` / `organization_rejected` in `translations/emails.*.yml`,
  the "approved" one linking the organization page in the creator's locale), and the admins an organization variant of
  the "submitted" e-mail.
- **Under an approved organization** (D2): when a series or one-time event is **created under** or **moved into** an
  approved, not rejected organization by its creator, one of its maintainers or an admin, and the item is pending
  (neither approved nor rejected), the handler approves it at once (`approvedBy` = the acting player). A rejected item
  stays rejected. An edition is never approved on its own. Draft or not does not matter: an approved draft is public
  the moment it is published. `OrganizationApprovalPolicy` is the one place of the rule; every handler that creates or
  moves an item calls it.
- **Approving an organization** approves its pending series and one-time events too (P2).
- **Drafts and the queue.** The approval queue, its admin-menu badge and the admin view of the events page list pending
  items that are **not** drafts. A pending draft is submitted by publishing it: the admin e-mail goes out on its
  **first** publish only - unpublishing and publishing it again e-mails nobody, and neither does anything an admin
  creates or publishes. Approving a draft is allowed (the internal API can; the policy does) - it stays hidden until
  published.

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
| Create an organization | any signed-in player (pending; an admin's is approved at once) |
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
Events › Organizations                                         (crumbs; the H1 is the organization)
[logo]  Riverbend Jigsaw Association                           (H1)
        Association · [us] Riverbend Valley, United States · RJA
        [☆ Follow]  [Website ↗]  [ig] [discord] …  [⋯]        (⋯ for the team: Edit, Add event, Publish/Unpublish, Delete; admins: Approve/Reject while pending)
About                                                          (plain text, line breaks kept)
Coming up                                                      (rows like the series page: leaf · name · place · tags (Who can enter, Recurring, registration…) · when · ⋯)
What we run                                                    (cards)
   Lantern Brewing Puzzle Night            ☆                   one card per series: name; place or Online · When it happens;
   Riverbend · Second Thursday, 7:30 pm                        Who can enter · N editions; "Next: Thu 12 Nov" (or "Last: …",
   18+ · 4 editions                                            "No dates yet"); a follow star per series
   Next: Thu 12 Nov
   One-time events: Riverbend Spring Open · Sat 12 Dec ☆      upcoming one-time events, one card each
Past                                                           year chips; newest year open, 5 lines + "Show all 2026 (N)"
```

- **Header**: crumbs "Events › Organizations", logo (decorative), H1 = name, a facts line (kind, flag + region and
  country, short name), actions: the labelled follow star (only when publicly visible), Website ↗, one icon link per
  social link (its platform's name as the accessible name, else its host; two links of one platform get distinct
  names), ⋯ for the team.
- **Coming up**: every live and upcoming occurrence of the organization's series and one-time events from
  `GetEventOccurrences::forOrganization()` - the series page's sessions and month headers: Live first, then by month,
  then Ongoing, then "Date not set" (editions with neither a date nor a round). Rows link the occurrence; no per-row
  star (P19).
- **What we run**: the series cards (public ones; the team also sees drafts and pending ones, tagged), ordered by next
  date, then name; then the upcoming one-time events as cards with a star.
- **Past**: one archive line per past occurrence, newest first, by year (the series page's rule and markup); for the
  team a draft's or pending item's line carries its Draft / Waiting for approval tag, like the upcoming rows (P6).
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
directory head on the events page ("Organizations →") and from "You organize". Indexable and in `sitemap-static.xml`
once it lists an organization - while it lists none it is `noindex` and left out of the sitemap. Two statements
(organizations, their occurrences); signed in + 4.

### "Organized by", crumbs and the extras on the detail pages

The series, edition and event pages gain a **byline** under the H1 (each part only when it has something):
"Organized by **Riverbend Jigsaw Association**" (a link; publicly visible organizations only - its team also sees it
while it is not, with one "Not public" tag) · "Who can enter: Residents of Riverbend Valley" (an edition's own, else its
series') · on the series page "When it happens: Second Thursday of the month, 7:30 pm". Crumbs follow P20 with the same rule as the
byline (public, or the viewer is on the organization's team). No extra statement: the organization rides on the
statement each page runs anyway; the voter is asked only for a non-public organization.

### Events page

- The search index folds in the organization's name and short name (`x`), so "riverbend" finds its events.
- Series directory lines show "by Riverbend Jigsaw Association" (publicly visible organizations only).
- Rows get the **Who can enter** tag (the text, with a visually hidden "Who can enter:" before it).
- "Your events" adds followed organizations (below). Drafts are nowhere, also not in the admin view.
- Statement counts unchanged (guest 3, signed in 8, admin 9, archive 1): the organization comes from a join in the
  occurrences and series statements, the follows from the viewer statement.

### "You organize"

Organizations first (name, Draft / Waiting for approval / Rejected badge, "3 series · 2 one-time events", their
actions), each followed by its series and one-time events (indented, the existing lines and actions); then everything
else as today. New badge **Draft** (order: Rejected, Draft, Waiting for approval - an item that is both a draft and
pending shows Draft). The header button counts organizations plus items not under one of the viewer's own
organizations (P9; an item under somebody else's organization counts on its own). "+ Add organization" next to
"+ Add event", and a link to the directory ("All organizations"). Unpublish is offered on every published item without
asking `UnpublishBlockers` (no statement per item); a refusal comes back as a flash naming the reasons.

### The ⋯ menu

| Item | Items it appears on | Route |
|---|---|---|
| Publish (first item) / Unpublish… (before Delete, confirmed in place) | everything the viewer can edit (in the ⋯ menu Unpublish only while allowed - otherwise "Can't go back to draft: …" with the reasons; on "You organize" a refusal comes back as a flash) | `publish_*` / `unpublish_*` |
| Publish series | an edition of a draft series, for the series' team | `publish_competition_series` |
| Edit organization, Add event, Delete organization (creator, empty only), Approve / Reject… (admins, pending, never a draft) | organizations | `edit_organization`, `add_competition?organization=`, `delete_organization`, `admin_approve_organization`, `admin_reject_organization` |
| Add several dates, Turn into an organization (no organization yet) | series | `add_editions`, `create_organization_from_series` |
| Move to another series | editions, for the people who can edit the edition's series (the target is another series they manage) | `move_edition` |
| Move to another event or edition | each round on the rounds page | `move_competition_round` |

Every POST carries a session CSRF token per item; a wrong token answers 403. Admins approve or reject only items that
wait for approval and are not drafts (a draft is submitted by publishing it).

### Forms

- **Add event / add series** (`add_competition`), **edit event** (`edit_competition`), **edit series**
  (`edit_competition_series`): an **Organization** select - the organizations the player is on the team of (admins:
  all not rejected) plus the item's current one, drafts and pending ones marked in the label, "None" first;
  `?organization=<id>` pre-selects it. An edition's edit form shows its series' organization read-only - only when that
  organization is publicly visible or the viewer is on its team. Changing it moves the item
  (`AssignEventToOrganization`, dispatched after the edit; the actor's right to the target organization is checked
  before anything is saved - a lost right is a form error, 422). **Who can enter** (help: "Leave empty when anyone can
  enter. Shown as a tag, …"); series: **When it happens** (help with an example - each date is still its own edition;
  on the add form shown only while "Recurring" is ticked, dropped otherwise).
- **Add edition** (`add_edition`): Who can enter (help: "Leave empty to use the series' setting."), and a link "Add
  several dates" to `add_editions`.
- **Add organization** (`add_organization`) / **edit organization** (`edit_organization`): name, short name, kind,
  country, region, about, website, social links (one per line, at most 10 after duplicates are dropped), logo, team (the
  maintainers picker of the event forms, at most 10 besides the creator); edit adds the "URL" field and lists the team
  (for the team only). A player's organization waits for approval ("Submit for approval"); an admin's is approved at
  once.
- **Save as draft**: every add form (event, series, edition, several dates, organization) gets a secondary submit
  "Save as draft" next to the primary one. The primary keeps today's behaviour.

### Add several dates (`add_editions`)

| Field | Values |
|---|---|
| How | **Repeat** (a rule) or **Pick dates** |
| Rule | every week on a weekday; the 1st/2nd/3rd/4th weekday of the month; the last weekday of the month |
| Starting | a date (first match on or after it) |
| How many | 1-24 |
| Picked dates | up to 24 days in one inline calendar (tap every day); without JavaScript typed as a list ("05.10.2026, 12.10.2026" - "5. 10. 2026" works too) |
| Name | pattern, default "{series name} {date}" (the series name is shortened when the default would not fit the name limit); `{date}` → the date in the page's language (`yMMMMd`); without `{date}` every name gets the date appended (editions need distinct slugs) |
| Who can enter | optional, for all of them |
| Draft | "Save as draft" creates them as drafts |

"Show the dates" (GET) lists the dates with their names, each with a checkbox (all checked); "Create N editions" (POST)
creates the checked ones through one message (`AddEditions`, max 24): each edition is dated `dateFrom = dateTo` = that
day, gets a slug unique in its series and the series' place, like `AddEdition`. A date already holding an edition of
the series is marked "already has an edition" and unchecked.

**Saved once.** Building the preview gives every date a UUIDv7 (a hidden field, kept on a 422 re-render), which becomes
that edition's id (`NewEdition::$competitionId`). `AddEditionsHandler` skips an id that exists already and never creates
a second edition on a day that has one, so a resent or double-clicked form adds nothing twice; two requests racing each
other end without a 500.

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

**Turning a series into an organization** (`CreateOrganizationFromSeries`) hands its followers on:

| The organization at the end of the step | The series' follows |
|---|---|
| publicly visible (approved, not a draft - internal API, an admin on the web) | **move** to the organization (a player following both keeps one row) |
| not public yet (waiting for approval) | **stay** on the series, and every follower also gets an organization follow (unless they follow it already) |

So nobody loses the series' next dates while the organization waits for an admin, and nobody follows a page they
cannot open.

## Drafts

| | One-time event | Edition | Series | Organization |
|---|---|---|---|---|
| Start as a draft | "Save as draft" (add form), internal API `draft` | "Save as draft" (add edition, several dates), API | "Save as draft", API | "Save as draft", API |
| Hidden | itself | itself | itself and its editions | its page, directory entry, "Organized by" links, crumbs |
| Visible to | creator, maintainers, series/organization team, admins | the same | creator, maintainers, organization team, admins | creator, team, admins |
| Its page for others | 404 | 404 | 404 | 404 |
| Publish | `publish_competition` | `publish_competition` | `publish_competition_series` | `publish_organization` |
| Publishing a pending one | enters the queue (admin e-mail on its first publish only) | - (approved through its series) | enters the queue (the same) | enters the queue (the same) |
| Unpublish | no participants (not deleted), no official results, no linked solving times | the same | the same for every edition | always |

- The page of a draft renders for its team with the banner and `noindex, nofollow`; nothing that needs a public page
  (follow star, "I'm going", Add my time, Results links, page sections) shows on it - the existing "not public" rules.
- Publishing says what happens next: "Published - everyone can see it now.", "Published - everyone can see it once an
  admin approves it." or, for an edition whose series is still a draft, "Published - visible once the series is
  published too." Unpublish says "Back to draft - only you and your team can see it." or why it cannot ("It cannot go
  back to draft: people have joined it, it has official results." - the reasons joined by a translated separator).
- Publishing an event whose official results were published while it was not public tells the players then (as
  approving does).
- A draft cannot get participants by joining (P17), solving times (the pickers offer public events only; the API
  refuses a round of a non-public event), follows or marketplace marks. Registration e-mails are not sent while an event
  is hidden as a draft (P18).
- Nothing tells a draft's name or URL to anyone outside its team: the shared round stopwatch page answers 404, a scanned
  name tag and "Leave" lead to the events page instead of the draft's page, the follow flash names only a public
  target (or one the player follows), and a competition tag that belongs to drafts only is left out of every tag list
  (a tag is named after its event).
- The organisers' tools work on drafts as on any event: rounds, puzzles, participants sheet, seating, results desk,
  page sections (their content shows once the page is public). Their "not public yet" lines say "This is a draft - …"
  instead of the approval wording (page sections, registration settings, results desk).

## Restructuring tools and old URLs

| Tool | Message | Web (`/{_locale}/…`) | Internal API |
|---|---|---|---|
| Move an edition to another series | `MoveEditionToSeries` | `move_edition` - `/move-edition/{competitionId}` | `POST /internal-api/competitions/{id}/move` |
| Move a round to another event or edition | `MoveRoundToCompetition` | `move_competition_round` - `/move-round/{roundId}` | `POST /internal-api/rounds/{id}/move` |
| Turn a series into an organization | `CreateOrganizationFromSeries` | `create_organization_from_series` - `/series-to-organization/{seriesId}` | `POST /internal-api/series/{id}/create-organization` |

**Moving an edition**: to another series both managed by the actor (the ⋯ item shows only for people who can edit the
edition's series). Its slug must be free in the target series - else the web form asks for a new one and the API
answers 409 (or takes `slug`). Its place follows P21; its organization is the target series'; its visibility follows
the target series. Participants, rounds, results and times stay with it. A **draft** target series takes only an
edition without participants, official results and linked solving times (`EditionNotMovableIntoDraft`, 409 - a draft
never holds those, their listings would show its name). Writes redirect rows: the old edition path and each old round
results path.

**Moving a round** (D7): to another one-time event or edition the actor manages. It moves the round row, its puzzles
(with their reveal settings), its table layout and every solving time that **belongs** to it - linked to it, or a time
of the old competition solved in the round's category on one of its puzzles that was not linked yet (the round results
rule, `SolvingTimeRoundResolver`; it gets the link) - their `competition_id` changes, the round link stays. Both
competitions' round results are reconciled afterwards. The round keeps its wall-clock zone (P22); a slug taken in the
target gets `-2`, `-3`, … Refused (409, nothing changes) when the target is the same competition; the round has
participant entries or teams (moving participants is a later step); a puzzle of the round is already in a round of the
same category in the target (the one-round-per-category invariant); its stopwatch is running; the target is hidden as a
draft and the round has solving times (`RoundNotMovableReason::DraftTargetWithResults` - an empty round may move into
a draft); the round was moved meanwhile (`RoundMovedMeanwhile`). The web page shows every refusal as a form error
(422). Both moves run under the source event's participants lock. Writes a redirect row for the old round results path.

**Turning a series into an organization**: creates the organization from the series (name, logo, about = description,
website = link, country, maintainers; the series' creator becomes its creator), hands the series' followers on (see
[Follow](#follow): moved when the organization is public at once, kept and copied while it waits for approval),
attaches the series - optionally renamed and with a new slug. Web: the organization waits for approval (the admin
e-mail) unless an admin does it; internal API: approved. The web form takes the region as typed (empty = none, the form
is prefilled from the series' location); only the internal API falls back to the series' location when `region` is
not sent. A pending series attached to an approved organization by its team or an admin is approved at once
(`OrganizationApprovalPolicy`). With a new series slug, the old series path redirects to the organization and every old
edition and round results path to its page. The page never links an organization the viewer cannot open.

**Redirects** (D6): one exception subscriber (`EventUrlRedirectSubscriber`), on a 404 of `event_detail`,
`competition_series_detail`, `edition_detail`, `event_round_results` or `edition_round_results` only, looks the path up
(`GetEventUrlRedirect`, one statement) and answers **301** to the target's **current** URL (so chained moves keep
working), keeping the query string. **What still 404s**: an explicit slug change through the "URL" field or the API
(unchanged rule); deleted items - the redirect rows cascade with their target, so **deleting an organization also
removes the redirect of the old series path** "Turn into an organization" wrote; a path a live item has taken since
(the live page wins - also a draft, P5); an old path whose target is now hidden as a draft (an edition moved into a
draft series, a round of a draft event, a draft organization) - a redirect never tells a draft's address, not even to
its team, who reach it from "You organize"; `#round-<id>` anchors of a moved round on its old event page (fragments
never reach the server); and anything not moved by these three tools.

## Internal API

Same patterns as the competitions endpoints (`InternalApiInput`, the web forms' validation, JSON errors, the audit log,
`INTERNAL_API_REVIEWER_PLAYER_ID` as the acting player). Full field tables, answers and refusals in
[../internal-api.md](../internal-api.md#organizations-series-and-drafts); the OpenAPI spec lists every endpoint.

| Method | Path | Purpose | Answer | 4xx |
|---|---|---|---|---|
| `GET` | `/internal-api/organizations?q=&status=&limit=&offset=` | list / search (`status`: all, approved, pending, rejected, draft) | `200` list | 400 |
| `GET` | `/internal-api/organizations/{idOrSlug}` | one organization: fields, state, team, its series and one-time events | `200` | 404 |
| `POST` | `/internal-api/organizations` | create - **approved**; `draft: true` keeps it a draft | `201` | 400, 409 slug |
| `PATCH` | `/internal-api/organizations/{id}` | change the fields sent (`draft` → publish/unpublish) | `200` | 400, 404, 409 slug |
| `POST` | `/internal-api/organizations/{id}/approve` | approve a pending one (and its pending items, P2) | `204` | 404, 409 |
| `DELETE` | `/internal-api/organizations/{id}` | delete an empty organization | `204` | 404, 409 not empty |
| `POST` | `/internal-api/organizations/{id}/maintainers` | add `{"playerId"}` to the team (at most 10 besides the creator) | `204` | 400, 404, 409 team full |
| `DELETE` | `/internal-api/organizations/{id}/maintainers/{playerId}` | remove from the team | `204` | 404 |
| `POST` | `/internal-api/organizations/{id}/publish` · `/unpublish` | draft off / on | `204` | 404 |
| `GET` | `/internal-api/series?q=&status=&limit=&offset=` | list / search series | `200` list | 400 |
| `GET` | `/internal-api/series/{idOrSlug}` | one series with its editions | `200` | 404 |
| `POST` | `/internal-api/series` | create a series (`approve`, `organizationId`, `draft`, `slug`, `eligibility`, `schedule`) | `201` | 400, 409 |
| `PATCH` | `/internal-api/series/{id}` | change the fields sent | `200` | 400, 404, 409 |
| `POST` | `/internal-api/series/{id}/publish` · `/unpublish` | | `204` | 404, 409 cannot unpublish |
| `POST` | `/internal-api/series/{id}/editions` | create an edition (`name`, `dateFrom`, `dateTo`, links, `eligibility`, `draft`, `slug`) | `201` competition | 400, 404, 409 slug |
| `PUT` | `/internal-api/series/{id}/organization` · `/internal-api/competitions/{id}/organization` | assign `{"organizationId": "…" \| null}` | `200` | 400, 403 not on its team, 404, 409 edition |
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
| Organization, draft | 404 (team: banner) | no "Organized by" (team: shown, tagged "Not public") | same | its name not searchable; series lines without it |
| Organization, pending / rejected | reachable, `noindex`, no star | no "Organized by" (team: shown, tagged "Not public") | same | same |
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

Patterns only - their data is not changed by this work; organisers matching them are candidates to talk to once
organizations ship (`docs/TODO.md`).

1. **One series holding several formats**: an association running a monthly online contest and venue nights in one
   series (the motivating case); an organiser running individual, pairs/teams and a skills format as editions of one
   series.
2. **One organiser, two series of one brand**: a shop with an online and an in-person series.
3. **A duplicate series**: a club with a series and a second "<name> <year>" series.
4. **Events of one organiser not grouped**: clubs and national associations whose championships, regionals and online
   events are separate one-time events; a brand running its own events.
5. **An annual championship entered as separate one-time events**: a world-level federation's and a national
   association's yearly championships - needs organization → championship series → yearly edition, i.e. moving a
   one-time event into a series (P23, later).

## Later (tracked in `docs/TODO.md`)

- Co-hosts: several organizations on one event.
- Notifications for followed organizations (a new date, registration opens).
- A casual/competitive flag (an event without rounds already shows no results).
- Season rankings (the motivating organiser does not want them - only on demand).
- Outreach to organisers matching the patterns above; merging the duplicate series of other organisers.
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
| "You organize" | - | + 1 (organizations) | constant in items (`OrganizedEventsQueryBudgetTest`: site overhead 4 + viewer and permissions 2 + one statement per kind of item listed) |

A draft adds no statement for guests (404 before anything else is read); for a signed-in viewer the draft check uses the
permissions statement the page's ⋯ already runs. No page of this feature shows player identity: no public team list,
follow counts are not shown, the "Organization" select lists only the player's own organizations.

## As built (2026-10-09)

Where the build differs from the text above or settles what it left open (details in the plan's "Foundation
deviations" and the workstream commits of PR #252). The sections above are already corrected; this list is the
summary for a reviewer.

**Foundation**

- Publishing an item that is not a draft changes nothing (no second admin e-mail). Publish, unpublish and delete answer
  a wrong CSRF token with 403; a second approve of an organization counts as done.
- Creators are never maintainer rows (the add/edit forms and the team endpoints skip the creator's id).
- `OrganizationApprovalPolicy` also tells the players about official results of an item that is public after the
  approval (as approving does) - nothing for a draft, publishing tells them.
- "Add several dates" slugs are unique through `CompetitionSlugGenerator::isTaken()` (the series' editions and the
  one-time events) and within the batch (`-2`, `-3`); a name that makes no slug falls back to `edition`.
- "You organize": badge order Rejected, Draft, Waiting for approval; the header count leaves out only items under one
  of the viewer's **own** organizations.
- `UnpublishCompetitionSeries` takes no event lock (a join racing it can leave a participant on a hidden edition - the
  organiser sees it and can publish again; accepted in the plan's risks).

**Organization pages (workstream A)**

- The header is the organization page's own markup with the detail pages' classes (not the shared
  `_detail_header.html.twig`); its crumbs are "Events › Organizations".
- From 992 px "What we run" is a side column beside About, Coming up and Past; phones get one column.
- "Coming up" ends with a "Date not set" group (editions with neither a date nor a round), like the series page.
- "Organized by" and the organization crumb show to the team while the organization is not public (draft, pending or
  rejected) with one "Not public" tag - not separate Draft / Waiting for approval marks.
- JSON-LD `organizer`: a series names its public organization (`Organization`, name + page) instead of the `Person`
  who added it; an edition names its public organization, else its series; a one-time event names one only under a
  public organization.
- The admin approval queue lists "Pending Organizations" above series; approve / reject carry a CSRF token.

**Drafts (workstream B)**

- Extra guards beyond the planned pages: the shared round stopwatch page (404), the live results name-tag scan and
  "Leave" (both lead to the events page instead of a draft's URL).
- A competition tag that belongs to drafts only is left out of every tag reader (`GetTags`).
- The follow flash names only a publicly visible target or one the player follows ("You no longer follow it."
  otherwise).

**Organiser surfaces and forms (workstream C)**

- Picked dates are one inline multi-day calendar (flatpickr `multiple`), typed as a list without JavaScript - not one
  field per line.
- "Move to another series" shows only for people who can edit the edition's series.
- Unpublish is confirmed in place (an inline confirmation, like Delete).
- An event or series that is public at once (created by its organization's team under an approved organization, or by
  an admin) says "Added - everyone can see it now."; admins' own items e-mail nobody.
- "When it happens" on the add form shows only while "Recurring" is ticked.

**Restructuring and the internal API (workstream D)**

- A moved round takes the results not linked to it yet that belong to it by the round results rule.
- A draft target refuses a moved edition carrying participants, official results or linked times, and a moved round
  carrying solving times.
- A competition `PATCH` validates the record only when it changes competition fields (a `PATCH` of only
  `organizationId` / `draft` validates nothing else).
- Editions created through the API need both dates (like the "Add edition" form).
- Deleting an organization deletes the redirect rows pointing at it - also the old series path "Turn into an
  organization" wrote (listed under "What still 404s").

**Decided after the code review (2026-10-09)**

| # | Behaviour |
|---|---|
| 1 | A redirect never leads to a target hidden as a draft (the old path answers 404, also to the team). |
| 2 | Turning a series into an organization moves the follows when the organization is public at the end, else keeps them and adds an organization follow per follower ([Follow](#follow)). |
| 4 | Organizations get their own e-mails: `organization_approved`, `organization_rejected` and an organization variant of the admins' "submitted" mail, in all 6 locales. |
| 5 | "Add several dates" is saved once: a UUIDv7 per previewed date is the edition's id; existing ids and days that have an edition are skipped; no 500 on a race. |
| 8 | The admins' "submitted" e-mail goes out only on the first publish of an item created as a draft; re-publishing, and anything an admin creates or publishes, e-mails nobody. An admin creating an organization on the web gets it approved at once. |
| 9 | Publishing an edition of a draft series says "Published - visible once the series is published too." |
| 11 | The team of an organization has at most 10 members besides its creator (form `Count(max: 10)`; `AddOrganizationMaintainer` refuses an 11th - `409` in the internal API); social links are counted after duplicates are dropped. |
| 13 | Past lines carry their tag for the team like the upcoming rows: Draft / Waiting for approval on the organization page, Draft on the series page. |
| 14 | An edition's edit form shows its series' organization only when it is public or the viewer is on its team. |
| 15 | Assigning an organization (web edit forms, internal API `PATCH`) checks the actor's right to the target organization before anything is dispatched; a `PATCH` of only `draft` needs no reviewer player. |
| 16 | "Add several dates": the default name pattern always fits the name limit; the button says "Create N editions"; typed dates accept "5. 10. 2026". |
| 18 | "Turn into an organization": the web form takes the region as typed; only the internal API falls back to the series' location. |
| 19 | The organizations directory is `noindex` and out of the sitemap while it lists no organization. |
| 20 | The reasons in "cannot go back to draft" are joined by a translated separator (`drafts_core.blocker_separator`). |
| 23 | An occurrence's "today" is the current day in the zone its days are in (the first round's zone, else the event's or series' country zone) - a round running across UTC midnight stays Live. See [../events-page/README.md](../events-page/README.md) "Dates". |
