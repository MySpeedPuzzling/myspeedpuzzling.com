# Participants spreadsheet (research + design, not built)

Status: **plan - the sheet itself is not built.** Design approved 2026-10-07 (Jan picked every recommended option - §11).
The results and qualification part (§11b) was decided the same day ("best of both worlds") and **built in PR #136**
together with live entry, seating and the referee role - see §0. The sheet (stages 0b-2) remains a plan and builds on
what PR #136 shipped.
Clickable proposal: https://claude.ai/artifact/T2nZjZJJ2SwpYFbnjujmMk (private). Extends [participants.md](participants.md)
(participants, organiser UI, Excel import/export) and [participant-import-preview.md](participant-import-preview.md)
(the file pipeline from PR #245). Examples use invented people only.

## TL;DR

- **Build it in-house** (plain `<table role="grid">` + Stimulus, ~15 KB). If time matters more than accessibility and
  phones, Tabulator 6.6 is the only library worth a one-day spike. Handsontable is ruled out by its licence, and AG Grid
  Community lacks paste, range selection and fill (they are Enterprise). The other libraries are too heavy, read-only,
  missing ARIA or unmaintained (§3).
- **Layout (C), with a twist:** a **People** tab (one row per person, solo rounds as checkbox columns) plus **one tab
  per pair/team round in which each row is one pair/team** (`Name | Member 1 | Member 2 | … | 2/2`). Entering 60 pairs
  becomes "type a name, Tab, type a name, Enter", and a pasted block of `Team | member | member | member` from the
  organiser's registration sheet fills a whole round at once. That is the MVP (§4, §5).
- **Saving:** autosave in small **changesets** of field-level changes, each carrying the value it was based on
  (`from` → `to`, a three-way check per cell, never a whole-row overwrite like the bug fixed 2026-10-06). Changesets go
  through **one message**, which turns them into the import's existing `ParticipantImportOperations`, applies them with
  the existing `ParticipantImportApplier`, and shares the import's rules (results guard, connect rule, team sizes)
  under the same per-event lock. A paste or bulk action is previewed first with a dry run of the same message (§6).
- **Phone:** not a spreadsheet. A people list that opens a full-screen editor per person (with previous/next), and per
  round a list of pair/team cards with member chips and a "Without a pair" tray (§8).
- **Team size is not stored anywhere today.** Stage 1 adds one expected size per team round and a stable team number
  per round (§11 D5, D7). Wrong sizes stay warnings ("3/2", "1/2"), never blocking.

---

## 0. Since this plan: what PR #136 built (2026-10-07)

The facts in §1 describe main *before* PR #136. What it changed, and what the sheet must reuse instead of building:

- **Official results exist** and live on the round entries, as R1 proposed: `HasOfficialResult` on
  `CompetitionParticipantRound` (solo) and `CompetitionTeam` (pairs/teams) - a time, pieces placed or did not start,
  entered by/at, the qualified mark (`qualified_at`) and a **table number** per round (`table_number`). One entry per
  person per round is a unique index now. Design of record: [official-results.md](official-results.md).
- **One write path for them**: `RecordRoundResults` (changes `{entry | newEntry, field: result | table_number |
  qualified, from, to}`, three-way per field, idempotent by stored client change ids, under the event lock) - the sheet's
  result/table/qualified cells must send exactly these changes (not a new `op: "result"` changeset), so the live entry,
  the results desk and the sheet stay one model. Seating in bulk: `AssignTableNumbers` (with `from`).
- **Stage 0a is done in substance**: every write to an event's participants, round entries and teams takes
  `CompetitionParticipantsLock::key()` (`SerializedByLock`), guarded by `SerializedByLockMessagesTest`. The key kept the
  import's string (`participant-import-<id>`) instead of the planned rename; `UpdateWjpcPlayerId` is deliberately left
  unlocked (it waits on an external server). The Live participant editor now sends only the rounds toggled in an edit
  (a diff), not the whole list.
- **Qualification (R3)** is built as manual Qualified marks on the results desk (helpers "Top N" and "Best of each
  country" only pre-select) + **Advance the qualified** (`AdvanceQualified`: one or more source rounds → one or more
  target rounds, single / balanced by seed / by source, dry-run plan with a `planHash` that refuses a changed plan).
- **Table numbers vs D7 "Pair 12"**: entrants now carry a table number per round, shown everywhere organisers work and
  used by referees to find entrants. A separate stored per-round team number (D7) would sit next to "Table 12" - when
  the sheet is built, decide whether the table number already is the number organisers need.
- **Live entry** on phones (find by table/name/#code or by scanning a name tag's QR, "Finished now" from the round
  stopwatch, an offline outbox) and a results-only **referee** role: [live-results.md](live-results.md).
- **Time parsing** (`assets/official_results_time.js`, shared by every tool): `1:23:45`, `58:12`, and digits only read
  right-aligned as h:mm:ss (`5812` = 0:58:12) - not "minutes" as §11b's rules suggested.

## 1. What exists (facts that limit the design)

### Data model

| Entity | Relevant facts |
|---|---|
| `CompetitionParticipant` | `name` (required), `country` (stores the `CountryCode` enum *name*), `player` (nullable, `connect()`/`disconnect()`), `source` (`self_joined` / `imported` / `manual`), `externalId`, `remoteId` (legacy WJPF, written only by a console command), `deletedAt` (soft delete), `connectedAt`. All fields are written only through entity methods. **No unique constraint** on (competition, player), (competition, external id) or (competition, name). |
| `CompetitionParticipantRound` | participant × round, nullable `team`. **No unique (participant, round)** (before PR #136 - now a unique index, see §0). Removing a person from a round = deleting the row. |
| `CompetitionTeam` | belongs to **one round**, `name` nullable (max 255, `cleanName()` collapses whitespace, empty → null). **Names may repeat within a round on purpose** (no unique index). A person has a separate team per pair/team round. |
| `CompetitionRound` | `category` = `RoundCategory`: **`solo` / `duo` / `team` only** - "Team Relay" is a `team` round. **No team size anywhere**: "duo = 2" is a convention; the import's `PlanBuilder::warnAboutTeamSizes()` guesses a team round's "usual size" from the most common size. |

### What references what (and therefore what an edit may do)

Nothing outside participant management references a participant, a round entry or a team:

- results (`puzzle_solving_time`) point at the **round** and the **competition**; the round is *derived* from
  competition + puzzle + solo/duo/team (see [round-results.md](round-results.md)). Results relate to a participant
  only through `participant.player_id` and, for pair/team results, `puzzling_team_member`;
- the table layout (`table_spot`) holds a `Player` or a free-text name, never a participant;
- the stopwatch and round results do not touch participants. The legacy `WjpcParticipant` is a separate table.

So for the sheet:

| Edit | Effect / limit |
|---|---|
| Rename a person, change country / external id | free |
| Link / unlink an MSP profile | the import's `canConnect` rule: a player may be linked to one active participant of the event only. The Live form does not check this today. |
| Put someone in a round | new entry |
| Take someone out of a round | deletes the entry. **Refused when the linked player has a result in that round** (import rule D11; the Live form has no such guard today) |
| Move to another pair/team | one `team_id` update, same round only |
| Rename a team | `competition_team.name` only. An older exported file then reads as a "different team" (`docs/TODO.md`) |
| Delete / merge a team | unassign every entry incl. soft-deleted members, then delete (the PR #244 order) |
| Remove a person from the event | **soft delete only**. A self-joined row is made the organiser's first (`markAsImported()`, import rule D10). **Refused when the player has a result in a round of the event** (D11) |
| Delete a round | not the sheet's job (refused with results; `DeleteCompetitionRoundHandler`) |

Teams are read only by the management page (`GetRoundTeams`), the import and the export. **No public page shows
pairs/teams today**, so a half-built pair saved by autosave is visible to nobody but the organisers (relevant to §6 and
D8).

### Write paths today

- **Live component** `ManageCompetitionParticipants`: one participant at a time. `EditCompetitionParticipant` writes
  **every field** (name, country, external id, player, rounds). No version check, no lock, no results guard, no connect
  rule. The Wisconsin 2026 bug (2026-10-06, stale form state overwriting other people) is the cost of whole-row writes.
- **`manage_round_teams`**: per round, create teams by name (one per line), and per person a `<select>` that POSTs
  `AssignParticipantToTeam` (303 back, full page reload per assignment). Rename/delete per team. No sizes, no
  keyboard flow, an inline script instead of a Stimulus controller.
- **Import** (PR #245): upload → mapping → preview → confirm. `ParticipantImportPlanner` reads a `SiteSnapshot`,
  `PlanBuilder` matches rows (participant id → MSP id → external id → name + country → name key) and produces
  `ParticipantImportOperations`. `ApplyParticipantImport` is `SerializedByLock` (key `participant-import-<id>`), checks
  a fingerprint (rows + mode + **event state version**, `GetParticipantImportStateVersion`), and
  `ParticipantImportApplier` writes it all in one transaction. Teams are identified by **round + name**, so a file
  cannot address an unnamed team, and two teams with the same name are ambiguous. That is the deepest reason a file is
  a poor tool for pairs.
- **Self-join** (`JoinCompetitionHandler` / `LeaveCompetitionHandler`): creates or restores a `self_joined`
  participant without rounds, or connects the player to a row from the list and releases their other rows.
  **None of these take a lock.**

### Authorisation

`CompetitionEditVoter::COMPETITION_EDIT` (admin, event creator, maintainers, series creator, series maintainers) is
used by every participant, team and import controller. The sheet uses it too, on every request.

### Reusable concurrency pieces

- `SerializedByLock` + `LockUntilCommittedMiddleware` (Postgres lock held until commit).
- `GetParticipantImportStateVersion` - one-statement hash of the event's participants, entries, teams and rounds.
- `PuzzleRecordVersion` (per-record version + 422 "changed meanwhile") - the model for per-record checks.
- Client-generated UUIDv7 for idempotent creates (the add-time form's `time_id` / `SolvingTimeAlreadySaved`).
- Gotcha (memory "rolled-back handler leaks"): validate everything before mutating any entity.

### Pages without the site chrome

`round_stopwatch.html.twig` extends `base` and empties `{% block header %}` / `{% block footer %}` (and overrides
`full_content`); `print_round_tables.html.twig` is a standalone document. No grid library is installed today.

---

## 2. UX research - what to borrow

Primary sources unless marked. Unverified points are in §12.

| Tool | How it handles the things we need | Borrow |
|---|---|---|
| **Google Sheets** ([shortcuts](https://support.google.com/docs/answer/181110), [dropdowns](https://support.google.com/docs/answer/186103), [data validation](https://support.google.com/docs/answer/139705), [freeze on mobile](https://support.google.com/docs/answer/9060449?co=GENIE.Platform%3DAndroid)) | Typing replaces the cell; Enter moves down, Tab right. Ctrl+D / Ctrl+R fill; Ctrl+Space / Shift+Space select a column / row. Dropdowns as **chips**; invalid input either rejected or "show a warning". Multiple selection in dropdown chips does not work on phones (documented). Frozen header/column on phones too. | chips, warn instead of reject, Ctrl+D, frozen header + first column |
| **Excel** ([shortcuts](https://support.microsoft.com/en-us/office/keyboard-shortcuts-in-excel-1798d9d5-842a-42b8-9c99-9b7213f0040f), [data validation](https://support.microsoft.com/en-us/office/apply-data-validation-to-cells-29fecbcc-d1b9-42c1-9d76-eff3ce5f7249), [more on validation](https://support.microsoft.com/en-us/office/more-on-data-validation-f38dee73-9900-4ca6-9301-8a5f6e1f0c4c), [Android](https://support.microsoft.com/en-us/office/excel-for-android-touch-guide-aef977da-6adf-4724-b054-8ca4bb1d7afb) / [iPhone](https://support.microsoft.com/en-us/excel/excel-for-iphone-touch-guide) touch guides) | Navigation vs edit mode; F2 edits, Esc cancels; **Ctrl+Enter writes the entry into every selected cell**; Alt+↓ opens a cell's dropdown. Validation alerts: Stop / Warning / Information, plus an input hint when the cell is focused. **Paste and fill skip validation silently** (documented). Phone: tap selects, double-tap or the formula bar edits. | Ctrl+Enter, input hint, severities. **Avoid:** validation that paste bypasses |
| **Airtable** ([grid](https://support.airtable.com/docs/airtable-grid-view), [shortcuts](https://support.airtable.com/docs/airtable-keyboard-shortcuts), [grouping](https://support.airtable.com/docs/grouping-records-in-airtable), [linked records](https://support.airtable.com/articles/3370222027-linking-records-in-airtable), [paste](https://support.airtable.com/articles/4087973664-adding-duplicating-and-deleting-airtable-records), [mobile](https://support.airtable.com/articles/9609457174-airtable-desktop-and-mobile-feature-differences), [combining fields](https://support.airtable.com/articles/6594085437-combining-field-values-in-airtable)) | Enter/F2 edits; **Space opens the whole record**; Shift+Enter inserts a row. Linked records = **tokens** with a search picker. Paste of one value onto a range fills it; pasting past the end asks "expand the table?". **Group by** with a count per group header, and **dragging a record into another group rewrites the grouped field**. Same-name records: make the primary field unique with a formula. Phone: the expanded record is the editor. | Space = open person, group headers with counts, drag-to-regroup (with a keyboard alternative), tokens for people, disambiguated team labels |
| **Notion** ([tables](https://www.notion.com/help/tables), [views](https://www.notion.com/help/views-filters-and-sorts)) | Checkbox column → bulk "Edit property"; side/center peek of a row with next/previous (Ctrl+J/K). Phone: a toolbar above the keyboard. | bulk action bar for selected rows |
| **Smartsheet** ([card view](https://help.smartsheet.com/articles/2302238-using-card-view-to-visualize-your-project), [mobile card view](https://help.smartsheet.com/articles/2478856-mobile-card-view), [hierarchy](https://help.smartsheet.com/articles/504734-hierarchy-indenting-outdenting-rows)) | Card lanes from a dropdown column; **dragging a card to another lane changes that field**; phone = long-press drag or a lane index; parent rows show their child count. | lanes per team as an optional later view on phones |
| **Baserow** ([shortcuts](https://baserow.io/user-docs/baserow-keyboard-shortcuts), [paste](https://baserow.io/user-docs/paste-data-into-baserow-table), [row panel](https://baserow.io/user-docs/enlarging-rows)) | Paste from Excel creates rows **without asking**, drops extra columns, matches link fields by text, and **leaves type-mismatched cells empty, silently**. | paste matching names to records. **Avoid:** silent drops |
| **NocoDB** ([shortcuts](https://docs.nocodb.com/getting-started/keyboard-shortcuts), [expand record](https://nocodb.com/docs/product-docs/records/expand-record)) | Space expands the record; side panel on desktop, **full screen on phones** with Alt+←/→ previous/next. | the phone editor with previous/next |
| **RunSignup** ([groups & teams](https://info.runsignup.com/2026/04/23/groups-teams/), [manage a participant's group](https://help.runsignup.com/support/solutions/articles/17000063223-manage-a-participant-s-group)) | Team types with **min/max team size**; the director moves people **one at a time** (search → Manage → Group/Team → join/create). | size rules on the round. **Avoid:** a multi-screen flow per person (= `manage_round_teams` today) |
| **Race Roster** (event guide PDF only, [example](https://bicyclenetwork.com.au/wp-content/uploads/2025/06/Teams-Registration-Guide-United-Energy-Around-the-Bay-2025-1.pdf)) | Captain creates the team, invite link / join code, team actions (members, export, remove). | maybe later: "copy invite link" for self-service pairs |
| **Eventbrite** ([edit attendee](https://www.eventbrite.com/help/en-us/articles/544246/how-to-edit-attendee-information/)) | Per-order editing, no teams; the phone app is check-in only. | nothing for teams |
| **Challonge** ([participants](https://kb.challonge.com/en/article/participant-management-1m6ooqe/)) | **Bulk add: one per line** (`Name, Username`), aimed at organisers with an Excel list; seeding by drag handle *or* a number field. | "paste names, one per line"; a keyboard alternative next to every drag |
| **start.gg** ([bulk add](https://help.start.gg/article/bulk-adding-attendees), [caps](https://help.start.gg/article/registration-caps)) | Bulk add matches each typed name to an account, marks the rest "creating new player", then asks to confirm; re-running creates no duplicates; capped at 50, singles only; team size fixed per event. | **match-then-confirm** for pasted names; idempotent re-runs |

**Accessibility references.** [WAI-ARIA APG grid](https://www.w3.org/WAI/ARIA/apg/patterns/grid/): navigation mode
(arrows, Home/End, Ctrl+Home/End, PgUp/PgDn) vs edit mode (Enter/F2/typing starts, Esc cancels); `aria-selected`,
`aria-readonly`, `aria-rowcount`/`aria-rowindex`; roving tabindex. WCAG 2.2:
[2.5.8 target size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) (24×24 px or spacing),
[2.4.11 focus not obscured](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html) (sticky
header/column → `scroll-padding`, [C43](https://www.w3.org/WAI/WCAG22/Techniques/css/C43)),
[3.3.1 error identification](https://www.w3.org/WAI/WCAG22/Understanding/error-identification.html) (errors in text,
not colour alone).

**Mobile references.** [NN/g mobile tables](https://www.nngroup.com/articles/mobile-tables/) (2017: sticky header,
locked first column, visible horizontal scroll cue, choose columns, no forced rotation) and
[NN/g data tables](https://www.nngroup.com/articles/data-tables/) (find, compare, view/edit **one row**, act; inline
row editing only works for narrow tables). [Material bottom sheets](https://m2.material.io/components/sheets-bottom)
(drag up to full screen, side sheet on large screens).

**Nobody does these two well, and both are the organiser's actual pain:**

1. **A group of the wrong size.** No tool I checked documents a marker like "3/2". RunSignup enforces min/max at
   sign-up time, and Airtable only counts records per group. We design it ourselves (§5).
2. **"Put these people into a new team" as one action.** No tool has it. Every tool makes you create the group first
   and then assign each person. Our team-as-row tab makes this the normal way of entering data.

**Anti-patterns to avoid:** validation that paste or fill skips; silently dropped values; blocking a pair while it is
half-filled; one person at a time through several screens; drag as the *only* way; teams told apart by name alone;
colour-only errors; double-tap on a phone that replaces a cell's content.

---

## 3. Libraries

Checked 2026-10-04/07: npm, GitHub, vendor licence and pricing pages, and Context7 docs. Each package was installed
and bundled with esbuild in a **throwaway directory outside the repo** (the session scratchpad, `grid-proto/`, since
deleted). Sizes are minified + gzipped JS only, without CSS. **No browser, screen-reader or real-phone test was done**
(that is the spike in stage 0).

| Library | Licence | Version / date | gz JS | Vanilla | Touch | a11y | Custom editor | Paste / copy | Fill | Undo | Virtual | i18n / RTL |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Jspreadsheet CE | MIT (Pro paid) | 5.0.4 / 2025-08 | 132 KB | yes (+jSuites) | claims yes | **no ARIA at all** in the bundle | dropdown + autocomplete | yes / yes | yes | yes | lazy | jSuites / ? |
| **Tabulator** | MIT | 6.6.1 / 2026-09-30 | 105 KB (58 KB with only the needed modules) | yes | scroll ok, range/fill on touch weak | grid roles, open issue [#4489](https://github.com/tabulator-tables/tabulator/issues/4489) | yes; `list` editor with remote autocomplete + free text | yes (naive TSV parser) / yes | **yes, since 6.6.0 (2026-09-29)** | yes | yes | own `langs` / `textDirection` |
| AG Grid Community | MIT | 36.2.0 / 2026-09-16 | 224–319 KB | yes | documented | best documented | yes; rich select = **Enterprise** | **Enterprise** | **Enterprise** (range selection) | yes | yes | locale pack / yes |
| Handsontable | **proprietary** - free key for personal use or evaluation outside production only | 18.1.1 / 2026-09-15 | 207–368 KB | yes | listed | strong (NVDA/JAWS/VoiceOver tested) | autocomplete | yes / yes | yes | yes | yes | 24 languages incl. ours / yes |
| RevoGrid | MIT core, Pro $199–499/seat/yr | 4.28.3 / 2026-10-05 | 106 KB | yes (web component) | partial | being fixed (2026-08/10) | free select plugin; checkbox/dropdown/rich editors **Pro** | yes / yes | basic (smart fill Pro) | **Pro** | yes | ? / yes |
| Univer | Apache-2.0 (Pro paid) | 1.0.3 / 2026-09-29 | **≈ 2.8 MB** (React 18 inside) | no | ? | canvas → none | plugins | yes | yes | yes | yes | yes |
| Luckysheet | MIT | 2.1.13 / 2021 | - | jQuery | - | - | - | - | - | - | - | **archived** |
| x-spreadsheet | MIT | 1.1.9 / 2021 | 35 KB | yes | poor (canvas) | none | limited | yes | yes | yes | canvas | limited |
| TanStack table-core | MIT | 9.2.6 / 2026-10-04 | 27 KB | yes | renders nothing | ours | ours | ours | ours | ours | separate pkg | ours |
| Grid.js | MIT | 6.2.0 / 2024-03 | 17 KB | yes (Preact) | ok | `role=grid` | **no editing** | - | - | - | no | yes |
| **In-house** (Stimulus) | ours | - | ≈ 10–20 KB (estimate) | yes | we design it | APG grid, by construction | reuse TomSelect / co-puzzler picker endpoints | our parser | Ctrl+D / Ctrl+Enter | ours | not needed | our 6 locales |

Notes:

- **Handsontable** is technically the best fit, but its `LICENSE.txt` limits the free key to "strictly personal or
  solely for evaluation… outside the production environment". Without a valid key it shows a modal that cannot be
  closed. Current price: "contact sales" (not verified).
- **AG Grid Community**: in the installed 36.2.0, `ClipboardModule`, `CellSelectionModule` and `RichSelectModule` are
  not in Community, so paste, range selection, fill and a typeahead editor cost $999/developer. Community alone gives
  nothing over in-house.
- **Tabulator** is actively maintained and has a Bootstrap 5 theme. It can take over a server-rendered `<table>`. Its
  range paste parser only splits on `\n` / `\t` (no quoted cells, no `\r\n`, no trailing newline), but can be
  replaced. Collapsed columns on narrow screens cannot be edited. Its document-level `mouseup` listener must be removed
  with `table.destroy()` in the Stimulus `disconnect()`.
- **Jspreadsheet CE** has every feature, but no ARIA attribute in the shipped code, and its free version is released
  less often than the paid one.
- **Univer / Luckysheet / x-spreadsheet** draw to a canvas: screen readers get nothing, and they are far too big (or
  dead) for 10 fixed columns.

### Recommendation: in-house

1. **The data is small and fixed.** 1,000 rows × ~10 columns = ~10,000 plain-text cells and **one** floating editor. No
   virtual scrolling is needed (`content-visibility: auto` on row groups). Rendering every row also keeps the
   browser's Ctrl+F and screen readers working, both of which virtualised grids break.
2. **Phones and accessibility are where every library is weakest**, and both are requirements. The phone view is not a
   grid at all (§8), so a library would cover only half of the UI.
3. **The hard part is ours anyway:** a team-as-row model, member typeahead over the event's participants, paste that
   matches names, and per-cell conflict states. With any library we would fight its data model.
4. No licence question, our translation catalogues, our pickers, about 15 KB loaded on that page only.

**What in-house costs:** APG keyboard model, range selection (Shift+arrows, mouse drag), a TSV parser (quotes,
embedded tabs/line breaks, `\r\n`, trailing newline, Excel/Sheets/Numbers quirks), copy as TSV + HTML, Ctrl+D /
Ctrl+Enter, an undo stack, editors (text, country, team/member typeahead, in-round toggle), IME composition while
editing (Japanese input), Safari focus quirks. Rough estimate: 1,200–2,000 lines of JS plus node tests, 2–4 weeks
including the server side (unverified).

**Fallback: Tabulator 6.6** (modules only, ~58 KB), if stage 0 shows in-house is too slow to build. Risks: a week-old
fill handle, open accessibility bugs, weak range editing on touch. We would still build the phone view ourselves.

---

## 4. Layout options

Examples below: an event with rounds **Solo**, **Pair**, **Team**, **Relay** (a team round).

### (A) One table, a column pair per round

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│ ← Event   Participants · Example Open 2026        231 people   ● Saved      [Export] [?]        │
├──────────────────┬────┬──────────────┬──────┬───────────────┬──────────────────┬────────────────┤
│ Name           ▲ │ 🏳 │ MSP profile  │ Solo │ Pair          │ Team             │ Relay          │
├──────────────────┼────┼──────────────┼──────┼───────────────┼──────────────────┼────────────────┤
│ Alex Doe         │ US │ @alexdoe ↗   │  ☑   │ Pinecones ·2/2│ Jigsaw Jays ·4/4 │ —              │
│ Robin Sampler    │ US │              │  ☑   │ Pinecones ·2/2│ Jigsaw Jays ·4/4 │ Relay Rockets  │
│ Sam Placeholder  │ CA │ @samp ↗      │  ☐   │ (no pair) ⚠1/2│ —                │ —              │
│ Kim Example      │ US │              │  ☑   │ Corners ⚠3/2  │ Edge Pieces ·4/4 │ Relay Rockets  │
│ …                │    │              │      │               │                  │                │
└──────────────────┴────┴──────────────┴──────┴───────────────┴──────────────────┴────────────────┘
```

- Pros: one place for everything, like the organiser's own spreadsheet; Ctrl+D down a round column is fast.
- Cons: **a pair is spread over two rows that are usually far apart** (sorted by name), so checking who is with whom
  means reading labels. Team cells are labels, and unnamed or same-named teams are hard to address. The table gets wide
  with 4+ rounds and is unreadable at 375 px.

### (B) Tabs per round, pairs/teams as grouped rows

```
[ Solo ] [ Pair ] [ Team ] [ Relay ]
Pair · 58 pairs · ⚠ 2 incomplete · ⚠ 1 too many · 5 without a pair            [+ New pair]
┌────────────────────────────────────────────────────────────────┐
│ ▾ Pinecones                                        2/2         │
│     Alex Doe              US                                    │
│     Robin Sampler         US                                    │
│ ▾ Corners                                         ⚠ 3/2 too many│
│     Kim Example           US                                    │
│     …                                                           │
│ ▾ Without a pair (5)                                            │
│     Sam Placeholder  [team: type to search or "new pair…"   ▾] │
└────────────────────────────────────────────────────────────────┘
```

- Pros: you see who is with whom; sizes per group; the Airtable "group by" model.
- Cons: still one row per *person*: making a pair = two edits on two rows. People not in the round are hidden. Name,
  country and profile are elsewhere.

### (C) Hybrid: a **People** tab + one tab per pair/team round, **one row per pair/team** (recommended)

**People** (everything about a person; solo rounds are checkboxes; pair/team rounds show a read-only label that jumps
to the round tab):

```
[ People 231 ] [ Pair 58 ⚠3 ] [ Team 31 ] [ Relay 12 ⚠1 ]                   ● Saved   [Export] [?]
Search… [            ]  Filter: [All ▾]  (Not in any round · Joined by themselves · Removed)  [+ Person]
┌──┬──────────────────┬────┬──────────────┬──────┬───────────────────┬───────────────────┬──────────┐
│☐ │ Name           ▲ │ 🏳 │ MSP profile  │ Solo │ Pair              │ Team              │ Relay    │
├──┼──────────────────┼────┼──────────────┼──────┼───────────────────┼───────────────────┼──────────┤
│☐ │ Alex Doe         │ US │ @alexdoe ↗   │  ☑   │ Pinecones ↗       │ Jigsaw Jays ↗     │ —        │
│☐ │ Robin Sampler    │ US │              │  ☑   │ Pinecones ↗       │ Jigsaw Jays ↗     │ Rockets ↗│
│☐ │ Sam Placeholder  │ CA │ @samp ↗      │  ☐   │ ⚠ no pair yet     │ —                 │ —        │
│☐ │ Lee New  ●joined │ GB │ @leenew ↗    │  ☐   │ —                 │ —                 │ —        │
└──┴──────────────────┴────┴──────────────┴──────┴───────────────────┴───────────────────┴──────────┘
 2 selected:  [Solo: in] [Solo: out]  [Make a pair ▾] [Make a team ▾]  [Remove from event]
```

**A pair/team round tab** (each row is one pair/team; member cells are a typeahead over the event's people; columns
= the round's expected size, plus one empty "+" column so a 5th member can be typed in):

```
[ People 231 ] [ Pair 58 ⚠3 ] [ Team 31 ] [ Relay 12 ⚠1 ]                              ● Saved
Pair · 58 pairs · 116 people · ⚠ 2 incomplete · ⚠ 1 too many · 5 in the round without a pair
┌────┬──────────────────┬──────────────────┬──────────────────┬───────┬──────────────────────────────┐
│ #  │ Pair name        │ Member 1         │ Member 2         │ +     │ Size                         │
├────┼──────────────────┼──────────────────┼──────────────────┼───────┼──────────────────────────────┤
│  1 │ Pinecones        │ Alex Doe         │ Robin Sampler    │       │ 2/2                          │
│  2 │ Corners          │ Kim Example      │ Pat Sample       │ Jo Do │ ⚠ 3/2 - a pair has 2 people  │
│  3 │ (no name)        │ Chris Test       │                  │       │ ⚠ 1/2 - incomplete           │
│  4 │ Corners          │ Max Demo         │ Ola Fictive      │       │ 2/2 · same name as #2        │
│  … │                  │                  │                  │       │                              │
│ +  │ type a name…     │ type a person…   │                  │       │                              │
├────┴──────────────────┴──────────────────┴──────────────────┴───────┴──────────────────────────────┤
│ In the round, without a pair (5):  [Sam Placeholder] [Dana Mock] [Eli Stub] …    (tap → put into…) │
│ Paste a list: "Pair name ⇥ Member ⇥ Member" from your sheet - Ctrl+V on any row                    │
└────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

**Why (C) with team-as-row:**

- **Entering pairs is the pain, and here a pair is one row.** Type a name, Tab, type a name, Enter: the pair exists,
  both people are in the round, done. The organiser never "creates a team, then assigns members".
- **Paste matches the organiser's data.** Registration sheets have a row per pair/team, or a "partner" column that
  becomes the same shape. Paste `name ⇥ member ⇥ member` into the round tab → match-then-confirm (§6) → the whole round
  is filled in one step.
- **Wrong sizes are visible where they are fixed**: the row is short or long, with the reason in text.
- **Same-name teams are told apart by their members** on the same row, and by the row number `#`.
- **People** stays a normal one-row-per-person sheet for names, countries, profiles and solo rounds, and replaces the
  Live component's one-at-a-time editing.
- On phones the same two views become a people list and team cards (§8). (A) has no good phone form.

Solo rounds get no tab of their own (a checkbox column in People is all they need). A round tab's `#` is the team's
stored number in that round (D7), so it survives renames and deletions.

---

## 5. Teams in detail

| Action | People tab | Round tab |
|---|---|---|
| **Create** | select rows → "Make a pair/team ▾" (choose the round) | type into the last empty row (name and/or a member); or paste rows |
| **Add a member** | - | type the person into a free member cell (typeahead over active people of the event, also people not yet in the round → puts them in) |
| **Move a member** | - | type the person into another team's cell: the cell shows "moves from #12 Pinecones" before Enter; their old cell empties. Or select the cell, Ctrl+X, then Ctrl+V into the other team. |
| **Remove from team, keep in round** | - | clear the member cell → the person goes to the "without a pair" tray |
| **Take out of the round** | uncheck/clear the round cell | tray → "Not in this round", or a row action |
| **Rename** | - | edit the name cell (empty = unnamed) |
| **Split** | - | select member cells → "Move to a new pair/team" |
| **Merge** | - | move members across; the emptied team goes (below) |
| **Delete** | - | row action "Delete pair/team" = members go to the tray, team deleted (PR #244 order) |

- **An emptied team** (all member cells cleared) is deleted when it has **no name**. A **named** empty team stays as a
  pre-created team (teams created in advance on `manage_round_teams` are a feature) and is shown as "0/4 - empty"
  until deleted explicitly.
- **A new person** typed into a member cell who is not on the list yet: the typeahead's last option is
  "+ Add "Jo Do" as a new participant" (never automatic: a typo must not create a person).
- **A person in two teams of the same round** cannot exist by construction (typing them elsewhere moves them).
  Existing data could hold it (no unique index before PR #136 - now one row per person and round, see §0): shown as
  "⚠ also in #7"; fixed by clearing one cell.

**Wrong size.** The expected size is 2 for `duo`. For `team` it is the round's expected size (D5), pre-filled from the
import's "usual size" heuristic (the most common size in the round, at least 2). Display: a size cell with text +
icon, never colour alone (WCAG 3.3.1):

| State | Size cell | Counted in the tab header |
|---|---|---|
| complete | `2/2` | - |
| incomplete | `⚠ 1/2 - incomplete` | "⚠ 2 incomplete" (click = filter) |
| too many | `⚠ 3/2 - a pair has 2 people` | "⚠ 1 too many" |
| empty named | `0/4 - empty` | - |

Sizes **never block** a save: teams pass through wrong sizes on their way to right ones (anti-pattern: blocking
half-filled pairs). The tab badge `Pair 58 ⚠3` keeps the problems visible everywhere.

**Same name in a round.** Allowed (as today). Shown as "same name as #2" in the size column. Wherever a team is
*picked* (People tab "Make a pair ▾ → existing", phone picker), it is labelled `Corners · #2 · Kim Example, Pat Sample`,
so the choice is never made by name alone. Paste: a team name that matches **one** existing team of the round → that
team; **several** → ask in the paste preview ("2 pairs are called Corners - which one, or a new pair?"); none → new
team. This mirrors import rule D16, but the sheet can *ask* where a file can only warn.

---

## 6. Saving model

### Options

| | Autosave per cell (a message per edit) | Draft + confirm through the import pipeline | **Changesets + shared operations (recommended)** |
|---|---|---|---|
| Feels like | Sheets / Airtable | a file upload | Sheets / Airtable |
| Validation | per message, likely duplicated from the import | shared, all of it | **shared rules**, extracted from `PlanBuilder` |
| Teams | by id | by **name** (rule D16): cannot address an unnamed team; two same-named teams are ambiguous | by id (`t:<id>`) or a client key for new ones |
| Stale check | none, or per row | whole-event fingerprint: **any self-join while editing invalidates the whole draft** | **per cell** (`from` value), the rest of the event may change freely |
| Lost work on a crash | none | the whole draft | at most the changesets still pending in the browser |
| Removals | ad hoc | full-sync rules (thresholds, D10, D11) | the same rules |
| Requests | hundreds of tiny ones | one big one | debounced batches, one in flight |

The import pipeline is the right **engine**, but the wrong **protocol** for a sheet. A file has no ids for unnamed
teams, empty cells never clear anything (D14), and its fingerprint is all-or-nothing. A sheet knows exactly which cell
changed, and from what.

### Recommended: field-level changesets, applied by the import's applier

```jsonc
// POST /en/manage-competition/{id}/participants-sheet/changes   (stateless CSRF header)
{
  "changesetId": "0192…",                       // UUIDv7 - a resend answers the stored result (idempotent)
  "groups": [                                   // each group atomic; groups independent of each other
    { "changes": [
      { "op": "field",  "participant": "<id>", "field": "country", "from": "US", "to": "CA" }
    ]},
    { "changes": [                              // "type a pair into an empty row" = one group
      { "op": "newTeam", "key": "<uuidv7>", "round": "<id>", "name": "Pinecones" },
      { "op": "place", "participant": "<id>", "round": "<id>", "from": "out",       "to": "team:<uuidv7>" },
      { "op": "place", "participant": "<id>", "round": "<id>", "from": "in",        "to": "team:<uuidv7>" }
    ]},
    { "changes": [
      { "op": "renameTeam", "team": "<id>", "from": "Corners", "to": "Corner Pieces" },
      { "op": "newParticipant", "key": "<uuidv7>", "name": "Jo Do", "country": null },
      { "op": "player", "participant": "<id>", "from": null, "to": "<player id>" },
      { "op": "remove", "participant": "<id>" }, { "op": "restore", "participant": "<id>" }
    ]}
  ]
}
```

- A person's place in a round is **one value**: `out` | `in` (no team) | `team:<id or new key>`. That covers "in the
  round?", "which team" and moves in one change, with one `from` to compare.
- **Three-way check per change.** Current value == `from` → apply. Current value == `to` → already done (ok). Anything
  else → **conflict**: the group is not applied, and the response carries the current value.
- **Rules are refusals per group**, with a text reason: the results guard (D11) on `out` and `remove`, the connect
  rule (a profile linked elsewhere in the event), a blank name, max lengths, a team of another round, a team of
  another event. Warnings (sizes, same names, probably the same person - the import's `ParticipantNameKey`) never
  refuse; they come back for display.
- **Implementation seam.** A `SheetChangesPlanner` turns the accepted groups into `ParticipantImportOperations` (it
  already has new participants, new teams `n:`/`t:` keys, new entries, entry team moves, deleted entries, deleted
  teams, soft delete / restore / connect). `ParticipantImportApplier` writes them. Gaps to add: **team rename**,
  **disconnect a player**, clearing a country / external id (the import never clears, D14), and **client-supplied ids**
  for new rows (the applier generates ids today, and idempotency needs the client's UUIDv7). The rules
  (`canConnect`, the results guard, team sizes, name keys) move out of `PlanBuilder` into small shared services both
  planners call, so a file and a sheet can never disagree.
- **One message** `ApplyParticipantSheetChanges` (`SerializedByLock`), the handler validating everything before the
  first entity changes (rolled-back-handler gotcha). It answers per group: `applied` / `conflict` (current value) /
  `refused` (reason), plus warnings and the new event state version.
- **Bigger actions get a preview, not a pipeline:** a paste of more than ~10 rows, any paste into a round tab, a bulk
  removal, and "Make a team" from People call the same endpoint with `dryRun: true`. The organiser sees **"12 new
  pairs, 3 moves, 1 new person, 2 names not found (pick or create), 1 ambiguous team"** in a dialog, using the import
  preview's wording, and confirms. That is start.gg's match-then-confirm, with no file and no stash.
- **When it saves:** a group is sent after the editor commits (Enter/Tab/blur), debounced ~800 ms to batch fast
  typing, **one request in flight**, queue in order. Status in the top bar: `● Saved` / `Saving…` /
  `3 changes not saved - retrying` (offline: kept in memory, retried with backoff; `beforeunload` and
  `turbo:before-visit` warn while anything is pending).

### Concurrency

- **One lock for every participant write of an event.** Rename the import's key to `competition-participants-<id>` and
  make the sheet, the import, the Live component, the team controllers and `Join`/`LeaveCompetition` all take it.
  Today only the import does. This is worth doing even without the sheet.
- **Other organisers / self-joiners meanwhile.** The response's state version differs from what the browser expected
  → the browser fetches the event's rows as compact JSON (1,000 rows ≈ 60–100 KB, ≈ 15 KB gzipped) and merges: rows
  without local pending changes are replaced, and pending ones keep their local value (a real conflict surfaces on
  save). While the tab is visible and someone interacted recently, it also checks `GET …/version` every 60 s (the
  live feed's rules; no Mercure needed). New self-joined people appear with a "● joined" badge; rows changed by others
  flash once and are announced in the live region ("3 people changed by another organiser").
- **A conflict in a cell:** the cell shows "Changed meanwhile to *Corners*. [Keep mine] [Use theirs]". Keep mine
  resends with the new `from`.
- Creating the same pair twice (two organisers) is possible and harmless: both appear, one shows "same name as #…" or
  someone's "moves from…", and they are fixed in the sheet.

### Undo

- A **client-side stack of applied groups**: Ctrl+Z / Ctrl+Shift+Z (also Ctrl+Y), plus buttons in the top bar. Undo
  sends the inverse group (`from` ↔ `to`) as a new changeset, so it is checked like any edit. If someone changed the
  cell meanwhile, the undo is refused with "Can't undo - Kim's pair was changed by someone else".
- A paste, a bulk action or "type a pair" is **one** undo step. The stack lives for the page view; a reload loses it
  (said in the help). A server-side history ("what changed today, by whom, restore") is a later stage (D11).

### Validation errors

- **Client first**, same rules where they are pure (blank name, lengths, known country, team of this round): an invalid
  edit never leaves the browser. The cell stays in edit mode with the message under it (`aria-invalid` +
  `aria-describedby`), Esc reverts.
- **Server refusals** (rules needing data: results guard, profile linked elsewhere) put the cell back to its saved
  value with a ⚠ marker and the reason in text, listed in a "Problems" panel that jumps to each cell.
- **Paste**: invalid cells are never silently dropped (Baserow) or silently accepted (Excel). They are listed in the
  paste preview, with the rest applied only on confirm.

### Deletes

- "Remove from event" (row action / bulk) = **soft delete**. Self-joined rows are made the organiser's first (D10),
  and players with a result in the event are refused (D11). Removed rows disappear from the default view: filter
  "Removed" shows them struck through with **Restore**. A removal of more than 25 % of the people (≥ 10) asks for the
  typed number, as in full sync.
- Never a hard delete from the sheet. Round entries are deleted when someone is taken out of a round (with the results
  guard); teams when emptied and unnamed, or explicitly.

---

## 7. Keyboard and editing model (desktop)

APG grid, Sheets/Excel conventions:

| Keys | Action |
|---|---|
| arrows, Home/End, Ctrl+Home/End, PgUp/PgDn | move (navigation mode) |
| Tab / Shift+Tab | next / previous cell; at the end of a team row, wraps to the next row's first member |
| typing | starts editing and replaces the content |
| Enter / F2 | edit keeping the content; Enter commits and moves down; Shift+Enter commits and moves up |
| Esc | cancel the edit |
| Space | toggle a checkbox cell; on a name cell, open the person's editor panel (Airtable/NocoDB) |
| Alt+↓ | open the typeahead/list of a cell |
| Shift+arrows, mouse drag | range selection; Shift+Space = row, Ctrl+Space = column |
| Ctrl+C / Ctrl+X / Ctrl+V | copy (TSV + HTML), cut (moves, for member cells), paste (TSV, match-then-confirm when big) |
| Ctrl+D / Ctrl+Enter | fill down / fill the selection (country, solo in/out) |
| Delete / Backspace | clear (member cell → tray; round cell → out, with guard) |
| Ctrl+Z / Ctrl+Shift+Z, Ctrl+Y | undo / redo |
| Ctrl+F | the browser's find works (all rows are in the DOM); our search box filters |

The tab key leaves the grid only from the last cell. Focus is a roving tabindex. One floating `<input>` editor at
16 px (no iOS zoom), with IME composition respected (no commit while `isComposing`).

---

## 8. Mobile: what "spreadsheet on a phone" should mean

Nobody edits 1,000 rows on a phone. Typical phone jobs at the venue: **fix one person** (spelling, link a profile,
swap someone into a pair after a no-show), **check a round** (who has no pair), and **build the last few pairs**.
Below ~768 px the same data is shown as lists. Keyboard grid navigation is desktop-only.

```
People (375 px)                         Person editor (full screen, sheet)
┌───────────────────────────────┐       ┌───────────────────────────────┐
│ ← Example Open   ● Saved   ⋯  │       │ ✕  Robin Sampler      ‹  ›    │
│ [People] [Pair⚠3] [Team] [Re…]│       │ Name     [Robin Sampler     ] │
│ 🔍 Search…            [Filter]│       │ Country  [🇺🇸 United States ▾] │
├───────────────────────────────┤       │ Profile  [Search player…    ] │
│ Alex Doe            🇺🇸   ›    │       │ ───── Rounds ─────             │
│ Solo · Pair: Pinecones · Team │       │ Solo   [ In ●○ ]               │
├───────────────────────────────┤       │ Pair   [ In ●○ ] Pinecones  ▾  │
│ Robin Sampler       🇺🇸   ›    │       │        with Alex Doe           │
│ Solo · Pair: Pinecones · Relay│       │ Team   [ In ●○ ] Jigsaw Jays ▾ │
├───────────────────────────────┤       │ Relay  [ ○● Out ]              │
│ Sam Placeholder     🇨🇦   ›    │       │                                │
│ ⚠ in Pair, no pair yet        │       │ [ Remove from event ]          │
└───────────────────────────────┘       └───────────────────────────────┘

Round tab (375 px)
┌───────────────────────────────┐
│ Pair · 58 · ⚠2 incomplete ⚠1  │
│ Without a pair (5)          ▾ │
│ [Sam Placeholder] [Dana Mock] │  ← tap a chip: "Pair with… / New pair / Not in this round"
├───────────────────────────────┤
│ #1 Pinecones             2/2  │
│ [Alex Doe ✕] [Robin Sampler ✕]│
├───────────────────────────────┤
│ #3 (no name)       ⚠ 1/2      │
│ [Chris Test ✕] [+ Add partner]│  ← typeahead sheet
├───────────────────────────────┤
│ [+ New pair]                  │
└───────────────────────────────┘
```

- **The person editor** is a full-height `<dialog>`: previous/next arrows (NocoDB), every change saved like a cell
  edit (same changesets), sticky title. On desktop the same editor opens as a side panel (Space on a row).
- **Pair/team cards** with member chips, `✕` targets ≥ 24 px with spacing (2.5.8; we aim for 44 px rows), and a
  typeahead sheet for "+ Add partner". Moving a person is "tap chip → Move to…", never drag-only. Drag between cards
  (Smartsheet lanes) can come later as an extra.
- The "Without a pair" tray is the phone's fastest pairing tool: tap Sam → "Pair with…" → pick Dana → done.
- A wide table on a phone (if someone wants it: a "Table" toggle) keeps the name column sticky with a visible
  horizontal scroll cue, and is read-only plus tap → editor.
- Paste on phones is out of scope.

---

## 9. Page, performance and constraints

- **Route and page.** A maintainer route next to the existing ones (e.g. `/en/manage-competition/{id}/participants-sheet`,
  `COMPETITION_EDIT` on every request), linked from the event's management page and the organiser checklist.
- **Full screen.** Extend `base.html.twig` and empty the `header` / `footer` blocks (the `round_stopwatch` way). A slim
  own top bar (← back to the event, event name, tabs with counts, save status, undo/redo, Export, help). The grid is
  the only scroll container (sticky header row and first column, `scroll-padding-top/left` so the focused cell is
  never hidden, 2.4.11). The rest of the site (flash messages, modals, the service worker) stays as is.
- **Rendering.** Twig renders the frame, translated texts and a JSON payload (`<script type="application/json">`), and
  the Stimulus controller renders the rows. One renderer serves the first paint and refreshes. The grid's JS module is
  loaded with a dynamic `import()` (as flatpickr is), so `app.js` does not grow. Rows are grouped in `<tbody>` chunks
  of ~50 with `content-visibility: auto`, and there is no virtualisation (all 10,000 cells stay in the DOM:
  screen readers, Ctrl+F). Measure on a mid-range Android with 1,000 invented rows before choosing between this and
  server-rendered rows.
- **Not a Live Component.** Morphing 10,000 cells per change is too slow, and the Wisconsin bug came from Live
  component form state. The sheet is plain Stimulus + JSON endpoints. Fetches are not form submissions, so the
  "POST never answers 200" Turbo rule does not apply. Statuses: 200 with per-group results (conflicts and refusals
  are results, not errors), 422 only for a malformed changeset, 403/404 as usual. Turbo's snapshot
  cache is disabled site-wide. `disconnect()` tears down document listeners.
- **Queries.** Page load = the import's `SiteSnapshot` reads (a constant number of statements for any event size), plus
  the results guard. A save = constant in the changeset size (bulk loads of the referenced participants, entries and
  teams). Pinned by a query-budget test like `PlayersPageQueryBudgetTest`.
- **FrankenPHP worker mode.** Endpoints are stateless. Any per-request cache in a new service implements
  `ResetInterface`. The lock lives in Postgres (shared across containers in blue-green).
- **CSRF.** A stateless CSRF token in a header (the `comparison_add` pattern), and `Referrer-Policy: same-origin` on
  the page (no-referrer breaks stateless CSRF).
- **Translations.** Every text in `messages.en.yml` while building, then all 6 locales before the PR. JS texts via
  `browser_translation()` / data attributes, plural forms through `assets/translation_choice.js`. Country names from
  the existing country select data.
- **Accessibility (WCAG 2.2 AA where reasonable).** `role="grid"`, `aria-rowcount`/`aria-colcount` and
  `aria-rowindex`, `aria-selected`, `aria-readonly` on label cells, `aria-invalid` + described errors. A polite live
  region for "Saved", "Pasted 120 cells, 3 need attention", "3 people changed by another organiser", and conflicts.
  Every action is reachable without dragging. Visible focus. Sizes and problems in text. The phone view uses native
  controls (buttons, dialog, combobox).
- **Privacy.** Organisers already see and link any player (participants.md). The profile typeahead has its own search
  (`participants_sheet_player_search`, the event's organisers only): organiser tooling, so players the organiser blocked
  are found too - blocking must not make anybody unassignable (player-blocklist.md rule 7); a private player is found
  by their exact code only, as everywhere. The export of the sheet = the existing export. No personal data leaves the
  event's maintainers.
- **Tests.** Handler tests for each op, conflicts, refusals (results guard, connect rule), idempotent resend,
  lock key; a parity test that a sheet change and the equivalent import produce the same operations. Node tests (like
  `RelativeTimeParityTest`) for the TSV parser, the changeset builder, undo inversion and the keyboard state machine.
  Panther is not in CI, so a manual checklist covers the browser parts (Safari, iOS, NVDA/VoiceOver).

---

## 10. Staged plan

**Stage 0a - one lock for every participant write (1–2 days, own PR, D13).** Rename the import's lock key to
`competition-participants-<id>` and make the Live editor, the team controllers and `Join`/`LeaveCompetition` take it.

**Stage 0b - spike (1–2 days, throwaway, outside the repo).** In-house prototype of the round tab with 1,000 invented
rows: keyboard model, one editor, a paste from Excel (Windows + macOS) and Google Sheets, an iPhone and a mid-range
Android, VoiceOver/NVDA smoke test. The same checklist on Tabulator 6.6 if in doubt. Outcome: in-house confirmed (or
Tabulator), and the rendering approach measured.

**Stage 1 - MVP: pairs and teams fast (≈ 2–3 weeks).**
- Server: shared rules extracted from `PlanBuilder`; `SheetChangesPlanner` → `ParticipantImportOperations` (+ rename,
  disconnect, client ids); `ApplyParticipantSheetChanges` with `dryRun`; the shared lock key for every participant
  write (from 0a); read endpoint (JSON) + version endpoint.
- Data: `CompetitionRound` expected team size for `team` rounds (pairs fixed at 2; round form field, pre-filled from the
  most common size) and a stable per-round `CompetitionTeam` number (assigned on creation, never reused) - D5, D7.
  Generated migrations; backfill numbers in creation order.
- Page: full-screen frame, **round tabs only** (team-as-row): type a pair/team, member typeahead (incl. "+ new
  participant"), moves, rename, delete, the "Without a pair" tray, size and same-name markers, tab problem counts,
  **paste with match-then-confirm**, undo/redo, save status, conflict cells.
- Phone: round tab as cards + tray (no People tab yet: the existing participants page stays for single-person edits).
- `manage_round_teams` links to the sheet (kept until stage 2).
- 6 locales, tests above.
- Pilot: ask the Wisconsin organiser to try it on their next event (D14).

**Stage 2 - People tab (≈ 1–2 weeks).** One row per person: name, country, profile link, solo round checkboxes,
read-only team labels → round tab. Add people (type into the last row, paste names one per line), remove/restore,
filters (not in any round, joined by themselves, removed), Ctrl+D / Ctrl+Enter, bulk bar ("Solo: in/out", "Make a pair/
team", remove), a Columns menu (external id, source, joined date hidden by default - D10). Phone: people list +
full-screen person editor with previous/next. Then retire the Live component's editing and `manage_round_teams`
(redirect to the sheet - D12).

**Results and qualification** (§11b, if R7 = A): becomes stage 2 and the People tab moves to stage 3.

**Stage 3 - polish.** Periodic refresh while visible + "changed by another organiser" highlights, desktop side-panel
editor (Space), "Problems" panel, copy as TSV + HTML, "Make a team" from a range of rows, export of the current
view.

**Later / optional.** Server-side change history with "restore" (D11), drag between team cards, "suggest MSP profiles" for unlinked people (exact name match, confirm
each), a "copy invite link" for self-service pairs, Mercure if two organisers live-editing becomes common.

---

## 11. Decisions (Jan, 2026-10-07)

Picked on the clickable proposal; every pick is the recommended option, no notes.

| # | Question | Decision |
|---|---|---|
| D1 | Layout | **(C)** People tab + one tab per pair/team round, **one row per pair/team** (§4) |
| D2 | Grid | **In-house** (plain table + Stimulus), confirmed by the stage 0b spike; Tabulator only if the spike fails (§3) |
| D3 | Saving | **Autosave** field-level changesets with `from` values, through the import's operations + applier; previews (dry run) for pastes and bulk actions (§6) |
| D4 | Phone | **Lists**: people list + full-screen person editor (previous/next), round tabs as pair/team cards + "without a pair" tray. No grid on phones (§8) |
| D5 | Team size | **One expected size** per `team` round (pairs fixed at 2), set on the round form, pre-filled from the most common size. Still a warning, never a block |
| D6 | Relay | **A team round**, members without an order |
| D7 | Team numbers | **Stored per-round number** on `CompetitionTeam`, assigned on creation, never reused ("Pair 12") - shown in the sheet, pickers and wherever teams are listed |
| D8 | Public teams | ~~Organisers only~~ - **superseded by R4** (published results show pair/team names) |
| D9 | Unknown pasted names | **Offered as "add as a new participant"**, ticked per name in the preview, one confirm |
| D10 | People columns | **Columns menu**: name, country, profile, rounds by default; external id, source, joined date hidden |
| D11 | History | **Session undo** now; a change log with restore later, when organisers ask |
| D12 | Old pages | **Retire after stage 2**: the Live participant editor and `manage_round_teams` redirect to the sheet |
| D13 | Lock | **Ship first, on its own** (stage 0a): one lock key for every participant write of an event |
| D14 | Pilot | **Ask the Wisconsin organiser** to try stage 1 on their next event (we never copy their data) |

---

## 11b. Results and qualification (decided 2026-10-07 - built in PR #136, see §0)

Jan, after D1–D14: *"in the management tool we must be able to mark the participant/pair/team as qualified and add a
time for them somehow easily."* Shown on the clickable proposal (Solo tab, Time / Pieces placed / Qualified / Rank
columns, "Qualify top N", "Publish results", paste of a results column).

**Facts.** A time today is a `PuzzleSolvingTime`, which always belongs to a `Player` - a participant without an MSP
profile cannot have one. `puzzle_solving_time.qualified` exists but was never written (0 rows). The round page
(`GetRoundResults`) is built only from times players added themselves (joined on `player_id`), earliest per
player/group, without position numbers. Nothing records a qualification or an organiser-entered result.

**Decided 2026-10-07** - Jan: *"it is not A or B, it must cover the scenarios we have"* (managing participants
before the event, entering results live during it, marking who qualified between rounds), plus: every in-person entry
gets a table number per round (optional but highly recommended, auto-assigned fastest = 1 from earlier rounds or
MySpeedPuzzling times), and qualification is always manual (rules differ per competition, e.g. the best of each country;
an automatic rule would also race with results being entered). As built:

- R1 ✓ (storage as proposed, + table number), R3 ✓ (as marks + "Advance the qualified", see §0), R4 ✓, R5 ✓ (the
  players' own times stay below the official ranks, folded), R6 ✓ (did not start is shown to organisers only).
- R2 changed: on the first publish (and for later results) linked players get an in-app notification to the round page,
  where "Add to my profile" opens the normal add-time form pre-filled - nothing is created automatically.
- R7 ✓ in effect: results were built first (live entry + results desk), before any sheet.

**The proposal as it was (kept for the record):**

- **R1 - official results live on the event:** one result per round entry - on `CompetitionParticipantRound` for solo
  rounds, on `CompetitionTeam` for pair/team rounds (the team-as-row of D1 is exactly that row): `seconds` or
  `pieces_placed` or `did_not_start`, `qualified`, entered by / at. Works for participants without a profile; players'
  profiles stay theirs. (Alternatives: times created on linked players' profiles on their behalf - impossible for
  unlinked people, touches first tries, duplicates, private profiles; or both.)
- **R2 - linked players are offered their result:** after publishing, a notification "Your official result: 1:23:45"
  opens the add-time form pre-filled, so first-try, duplicate and privacy checks run as usual. (Alternatives:
  automatic copy to the profile; never.)
- **R3 - qualified = a mark + an explicit "Add the qualified to round …":** marked by hand or "Qualify top N" from the
  computed ranks; one action copies the qualified - pairs/teams with the same members - into the chosen round. Nothing
  moves by itself; unmarking never removes anyone from a round. (Alternatives: mark only; a round setting that moves
  them automatically.)
- **R4 - "Publish results" per round** (replaces D8): only organisers see results until published; publishing shows
  official ranks, times, qualified marks and pair/team names on the round page and sends the R2 notifications.
  Unpublish possible. (Alternatives: public as soon as entered; never public.)
- **R5 - round page:** a published round shows the official results with ranks; an unpublished one keeps today's
  players'-times list. (Alternatives: both lists; players' times only.)
- **R6 - a result is a time, pieces placed, or did not start;** rank = times ascending, then pieces placed descending,
  ties share a rank, no-shows unranked. Disqualified later if needed. (Alternatives: time only; + DQ and a note.)
- **R7 - results before the People tab:** stage 2 = results + qualification (+ a Solo tab), stage 3 = People tab -
  results are impossible today, one-at-a-time people editing is slow but works.

**Rules that follow:**

- Results are entered in the round tabs: Time accepts `1:23:45`, `58:12` or minutes; Enter goes down the column;
  invalid input stays in the cell with the reason; pieces placed must be below the round's piece count (a finished
  puzzle gets a time). A time and pieces placed exclude each other. A round with several puzzles has one total
  result (open detail).
- Paste a results column (`name ⇥ time`) into a round tab: names matched to people **in that round** only, unknown or
  not-in-round names skipped and listed, existing results shown as "replaces …"; one confirm, one undo step.
- Guards: a pair/team with a result can't be deleted (clear the result first) and is never auto-deleted when emptied;
  changing its members says the result now belongs to the new line-up; a person with a result can't be taken out of
  the round or removed from the event (the import's results guard, extended to official results).
- Same changesets as everything else (`op: "result"`, `from` → `to`), same lock, same per-cell conflicts.
- Later: results from the round stopwatch, disqualified status, history of result changes beyond "entered by / at".

## 12. Could not verify

- Google Sheets: how invalid data looks (red triangle, hover) and the phone editing bar come from third-party guides.
  The "reject vs warn" default appears to differ between dropdowns and general validation rules.
- Airtable Ctrl+D as fill down (the shortcut page came back garbled). Notion drag between groups. Apple HIG pages
  (they would not render without JS).
- Race Roster team features come from an organiser's PDF, not Race Roster's help. Team roster editing on Challonge /
  start.gg is not documented.
- Handsontable's current price. Whether AG Grid Enterprise needs a deployment licence for a public site.
- Real touch, screen-reader and paste behaviour of every library (no browser test, which is what stage 0 is for). JS
  sizes from esbuild, not Webpack Encore.
- The in-house effort estimate.

## Client architecture (as built)

Stream C of the delivery (the client core + the basic People grid). Streams D (round tabs) and E (People extras, person
editor, phone list) build on these APIs. Everything is plain ES modules under `assets/participants_sheet/` (no bundler-only
import - they run as native modules in a browser and under node), plus the lazy Stimulus controller
`assets/controllers/participants_sheet_controller.js`. Pure modules are pinned by
`tests/ParticipantsSheetCoreScriptsTest.php` → `tests/participants-sheet-core-harness.mjs` → the suites in
`tests/participants-sheet-core/` (node:assert; `echo '[{"suite":"queue"}]' | node tests/participants-sheet-core-harness.mjs`).
The DOM suites (`grid`, `people`, `controller`) run the real grid, People view and Stimulus controller in jsdom (a dev
dependency in `package-lock.json`, `dom.mjs`); `perf` pins bulk actions to one rebuild and one re-render.

### Modules

| Module | Responsibility |
|---|---|
| `tsv.js` | Clipboard: `parseClipboardText` (tabs, CRLF/LF/CR, one trailing line end, RFC 4180 quoting only where a producer had to, BOM), `parseClipboardHtml` (fallback), `rowsFromClipboard(text, html)`, `toTsv` / `toHtmlTable` (copy), `readBoolean` (TRUE/FALSE, 1/0, x, yes/no in the site's languages → true/false/null), `trimCell`. From the stage 0b spike. |
| `grid_keys.js` | `nextAction(state, keyEvent)` - the APG grid keyboard model as a pure state machine (navigation vs edit, Tab wrap, IME, AltGr, non-Latin layouts). From the spike. |
| `sheet_model.js` | `SheetModel` - the state JSON (§4.2) as indexes, the derived facts, the optimistic overlay, merges; `SheetMarks` (cell markers); `Working` (the copy-on-write state the overlay and inverses run on); cleaning helpers identical to the server's (`cleanName`, `cleanTeamName`, `cleanOptionalText`, `nameKey` = ParticipantNameKey). |
| `sheet_changes.js` | Builders of **actions** for every op of §3.1 and the composite actions; client checks with the server's reason codes; exact inverses. |
| `sheet_save_queue.js` | `SheetSaveQueue` - the one FIFO to the server, the version protocol, retries, problems. |
| `sheet_undo.js` | `SheetUndo` - per page view. |
| `sheet_live.js` | `SheetLive` - the Mercure stream (`OfficialResultsEvents` with the state's token), version polling, catch-up. |
| `sheet_grid.js` | `SheetGrid` - the generic DOM grid views configure. `escapeHtml`, `markerHtml`. |
| `preview_dialog.js` | `PreviewDialog` - the generic match-then-confirm `<dialog>`. |
| `views/people_view.js` | The People grid (desktop) - the first real view, extended by stream E. |

### Data flow

1. A view builds an **action** with a `sheet_changes.js` builder and calls `context.act(action)`.
2. `act()` announces client refusals (`action.errors`, in the server's words - `errorText()`) and marks them on their
   cells for 8 s, shows every group at once inside one `model.batch()` (`model.applyLocalMany(groups)` - one rebuild, one
   re-render for a bulk action of 1,000 groups), queues them (`queue.enqueueGroups`), queues results changes
   (`action.results` → `queue.results(roundId).set(...)` + `enqueueResults`) and records one undo step.
3. The queue sends after ~800 ms (one request in flight). Per answered group (all of an answer in one `model.batch()`,
   `confirmMany` / `revertMany`, each group settled on its own - a view throwing never leaves the others pending):
   applied/unchanged → folded into the base with the server's `deletedTeams` (a pair/team the answer created that ends
   it unnamed and empty is dropped - the server never created it), markers cleared; conflict/refused → reverted + a
   **problem** (with the server's translated message - always shown as it is - and `current`) + a marker on the cell.
   A changeset refused as a whole (400) answers every group as refused (`outcome` events); a 409 `changed_meanwhile` is
   kept and sent again like a busy server. `versionBefore === model.version` (and not a
   replay) → `model.version = versionAfter`; otherwise the state is fetched (through the same FIFO, so a fetch never races
   our own save) and merged. Saves that create what the browser cannot know (new round entries' ids, `source` after a
   removal, registration after a restore, a new person, a profile linked or unlinked) fetch the state 1.5 s after things
   got quiet.
4. Every model change emits a **delta** `{people: Set, teams: Set, rounds: Set, rows: bool, all: bool}` (a diff of what
   views read - unchanged records keep their identity, so the diff is cheap); the controller hands it to the mounted
   view's `update(delta)` and re-renders the tab counts. Marker changes emit deltas the same way.
5. Live: `participants_sheet.changed` with an unknown version → fetch, and "another organiser changed the sheet" is said
   (`onForeignChange`) - never for the echo of one of our own saves (on its way, adopted, or late: `queue.isOwnVersion()`);
   `official_results.entries` → `model.mergeEntries`
   (an unknown ref → fetch); `.refresh` → fetch; `.round` → `model.updateRound`. Plus `GET urls.version` every 30 s while
   visible and idle, a fetch when the tab returns after 10 s, when the browser is online again, and when the stream
   reopens.

### The model (`SheetModel`)

Reading (base + pending, i.e. what the organiser sees): `rounds()`, `round(id)`, `people({includeRemoved})` (state order -
by name - with people added on the page at the end), `person(id)`, `team(id)`, `teamsOf(roundId)` (state order, new ones
last), `place(personId, roundId)`, `placeValue(personId, roundId)` → `out` | `in` | `team:<id>`, `placesOf(personId)`,
`placeById(entryId)`, `peopleIn(roundId)`, `membersOf(teamId)` (active people), `trayOf(roundId)` (in the round without a
pair/team), `expectedSize(roundId)` (2 for pairs, the stored size of a team round else `usualTeamSize()` = the most common,
the smaller on a tie, ≥ 2; null for solo), `isNamesOnly(roundId)` (O7), `sizeStatus(teamId)` → `{count, expected, status:
complete | incomplete | too_many | empty | names_only}`, `sameNameTeams(roundId)` → Map teamId → other ids,
`problems(roundId)` → `{incomplete, tooMany, withoutTeam, sameName, total}` (total = the tab badge; same names are
informational), `peopleInNoRound()`, `duplicateNames()` / `peopleNamed(name)` (ParticipantNameKey fold),
`teamLabel(teamId)` → `{name, table, members}` (O1 - the view formats `Corners · Table 2 · Kim Example, Pat Sample`),
`entryRef(personId, roundId)` → `participant_round:<id>` / `team:<id>` / null for an entry not saved yet,
`holdsDataInRound()` / `holdsDataInEvent()` (the results guard on what the page knows), `isRemoved()`, `isWaitlisted()`.
Derived values are cached per change.

Writing: `applyLocal(groupId, changes)` / `applyLocalMany([{id, changes}])`, `confirm(groupId, deletedTeams)` /
`confirmMany([{groupId, deletedTeams}])`, `revert(groupId)` / `revertMany(groupIds)` (the `Many` forms: one rebuild, one
delta), `batch(fn)` (every delta `fn` causes - model and markers - told once, merged), `replaceState(state)` (a fetched
state; pending groups replayed on it; a newer live result is never replaced by an older state), `mergeEntries(entries)`
→ `{unknown, delta}` (an update about an older result than the page holds changes nothing - nor its table number or
qualified mark), `updateRound(overview)`, `scratch()`, `subscribe(listener)` (a listener that throws is logged, the
others still run). A linked or unlinked profile keeps `playerResultRounds` (the own-time guard) until the next state;
a profile picked from the search with `hidden: true` shows as "Linked to a MySpeedPuzzling profile" (O9). `Working`
(the scratch state) records writes between `begin()` and `rollback()` / `commit()` - client checks of a group run on it
without copying the state. `model.version` = the known sheet
version, `model.resultsGeneration` counts merged result updates. Places created on the page have `local: true` and an id
`local:…` until the next state.

**Markers** (`model.marks`, a `SheetMarks`; `setMany([{key, mark, entities}])` re-renders once): key → `{state: saving |
waiting | conflict | refused | warning, message, groupId, problemId, transient?}`. Keys: `person:<id>:<name|country|externalId|note|player|removed>`, `place:<personId>:<roundId>`,
`team:<teamId>:<name|delete>`, `round:<roundId>:teamSize`, `result:<ref>:<result|table_number|qualified>`. Views ask
`context.markerFor(key)` → `{state, text, title}` and put it in a cell's `marker` (the grid draws an icon **and** a
word). Server warnings mark `person:<id>:name` / `team:<id>:name` for 20 s and are announced.

### Actions (`sheet_changes.js`)

An action = **one undo step**: `{label: {key, …}, groups: [{id, changes}], inverse: [{id, inverseOf, changes}], errors:
[{reason, change}], results?: [{roundId, ref, field, from, to}], inverseResults?}`. A group is atomic on the server; an
action holds several groups when its parts are independent (a bulk "Solo: in" of 40 rows = 40 groups). Every `from` is
what the model shows (the organiser's own pending value included - a second edit chains on the first) - **or what an
editor showed when it opened** (`options.from`, below). `errors` are client refusals (codes of §3.1: `name_blank`,
`name_too_long`, `invalid_country` (with `countries`), `note_too_long`, `external_id_too_long`, `external_id_taken`,
`team_name_too_long`, `participant_removed`, `has_result_in_round` (own data only - the person's solo entry or own
time), `has_result_in_event`, `team_has_result` (a pair/team holding a result left without a going member - not removed,
not waitlisted - by the group as a whole; `cause: emptied | waitlisted_only`), `player_linked_elsewhere`,
`not_a_team_round`, `team_of_another_round`, `invalid_team_size`, `too_many_changes` (> 500 changes in a group), …);
those groups never leave the browser. `refusalDetails(error, model)` → `{key, params}` words one like the server
(`participants_sheet_server.reason.<key>`, the cause variants `has_result_in_round_own_time`, `team_has_result_emptied`,
… and `%name%`/`%round%`/`%team%`/… from the page) - views show it through `context.errorText(error)`. Inverses are computed change by change on a scratch state: a deleted pair is
created again **with the same id** and its active members put back; a pair the server will delete automatically when a
group empties it is created again first; a new person's undo is `remove`, its redo `restore`.

Builders (each `(model, …, options)` with `options = {newId?, countries?, label?, from?}`). **`from`** = what the
organiser saw when the edit started (an editor's `seen`): sent as the change's `from` instead of the model's value
(a live change that arrived while the cell was being edited comes back as a conflict, never silently reverted); the
client checks still run on the model; a value equal to it is no change (no group, no undo step). One value, or for
builders over several people a `Map` / function personId → value (`fromFor()`); `setFields` takes it per item.
`setField(personId, field, value)`, `setFields(field, [{personId, value, from?}])`, `linkProfile(personId, player|null)`
(`player` = `{id, name, code, avatar, country, profileUrl, visible?}` - shown at once via the `_player` hint, stripped
from the wire), `addPerson({name, country?,
externalId?, id?})` (+ `personId`), `removePeople(ids)`, `restorePeople(ids)`, `setPlace(personId, roundId, to)`,
`setInRound(ids, roundId, bool)`, `newTeamRow(roundId, {id?, name, members: [personId | {name, country}]})` (+ `teamId`;
"type a pair into the new row" - one group: new people, the team, every member placed from wherever they are),
`putInTeam(roundId, teamId, personId | {name, country})` (move / add, + `personId`), `clearMember(roundId, personId)`
(→ the tray), `renameTeam(teamId, name)`, `deleteTeam(teamId)` (its table number comes back on undo: `inverseResults`
`table_number` null → the old number, `inverseOf` the deleting group - sent after the group re-creating the pair),
`setTeamSize(roundId, size|null)`, `resultsAction(changes)` (RecordRoundResults fields, undone by the swapped change).
Lower level: `buildAction(model, [[changes], …])`, `checkGroup(changes, working, {countries, now})` (the checks of one
group incl. the group rules; the Working is left as it was), `combine(label, …actions)` (independent actions as one step
- groups, results and both inverses), `invertGroups(groups, model)`, `checkChange(change, state)`, `refusalDetails(error,
state)`, `changeTarget(change, model)` (marker key + entities), `wireChange` / `wireGroups`, `isEmpty(action)`.

Undo labels (`label.key`) map to `action_<key>` texts (core); a new key needs its text there.

### The save queue (`SheetSaveQueue`)

Items: `sheet` (groups → `urls.changes`, `changesetId` kept across retries - frozen once sent, later groups go into a new
changeset; limits 1,000 groups / 5,000 changes), `results` (a round's queued cells of a `PendingChanges` - the results
desk's module - → `urls.record`; a cell is stamped with the sheet items queued before it and a results request takes
only cells queued before the next sheet item still waiting - a result never overtakes the group creating its pair), `tables` (`enqueueTables(roundId, [{entry, from, number}])` → `urls.tables`, resolves
`{kind: ok, entries}` | `{kind: refused, problems}` - a refusal also fetches the state), `preview` (`preview(groups)` → the
dry run answer, after everything queued before it, never retried; still waiting behind a retried save after 15 s it
answers `{kind: offline | timeout}`), `state` (`refetch()`, coalesced, resolves to the answer's kind). Debounce 800 ms
after the last edit (results and sheet groups) - a dry run, a fetch or "send now" sends what was queued before it at
once, later edits wait for the debounce again; one request in flight; offline / 5xx / busy / 409 `changed_meanwhile`
kept and retried after 2, 5, 10, 20, 30 s (typing does not shorten the backoff), at once on `online()`; `auth`,
`forbidden`, `gone` stop sending until `retryNow()` (dry runs and fetches answer at once meanwhile).

For results: `queue.results(roundId)` is the round's `PendingChanges` (`set(ref, field, to, seen)`, `value(ref, field,
serverValue)` for what a cell shows, `get(ref, field)` for its status) - D's result cells read it; `context.act()` with
`action.results` fills it.

Problems: `problems()` → `[{id, kind: sheet|results, status: conflict|refused, reason, message, current, change | ref+field,
group (with `origin` = the tab it was made on and `label`), target: {key, people, teams, rounds}}]`; `keepMineAction(id)`
(the controller's Keep mine: the sheet group again with `from` = the current value of its conflicting changes, as an
action `{label, groups, inverse}` the controller performs - an undo step of its own; results: the desk's keep mine, sent
at once, null), `keepMine(id)` (the same, performed by the queue), `dismiss(id)` (use theirs / OK - a conflict fetches the
state), `retryProblem(id)` (a refused results cell). Status: `status()` → `{state: saved | saving | waiting | offline |
attention | auth | forbidden | gone, waiting, attention, offline}` (`offline` also while problems need the organiser - the
pill says "1 needs you · offline", the offline banner shows). `isOwnVersion(version)` - a version one of our saves
produced.
Events (`subscribe`): `status`, `outcome` (every answered group - the undo stack and views listen), `warnings`, `problems`,
`state` (a fetch answered, with `kind`), `results`, `gone`. `installLeaveGuards({window, document, confirm, message})`
(beforeunload + turbo:before-visit while `hasUnsaved()` - unsent changes or undecided conflicts).

### Undo (`SheetUndo`)

`record(action)`, `outcome(groupId, status)` (→ `'undo'` / `'redo'` when an undo/redo group was refused: the controller
says "Can't undo - somebody changed it meanwhile"; `'undo_unsaved'` / `'redo_unsaved'` when what it took back was itself
never saved: "That change was not saved - nothing to undo"), `undo(model)` / `redo(model)` → an action with `kind` and
`skipped` (place changes of people removed from the event meanwhile, left out of the undo and named - e.g. a deleted
pair's members are put back only if still active) (performed by `act()`, which hands it back with `done()`),
`canUndo/canRedo`, `peekUndo/peekRedo`. Only forward groups that went through (or are still on their way) are undone,
results changes tied to a group (`inverseOf`) only with it; a step with nothing left is skipped. "Keep mine" is a step
of its own. Limit 100 steps.

### The grid (`SheetGrid`)

`new SheetGrid({container, label, columns, rows, cell, …callbacks})` - views get it through `context.createGrid(options)`
(texts, announce and undo/redo pre-wired). Native `<table role="grid">`, every row in the DOM, no content-visibility.

Columns: `{key, label, kind: text | list | checkbox | readonly | action, width (px - every column should have one: the
table then gets a fixed width and the browser never measures 10,000 cells), headerHtml?, space?: 'panel', autoHighlight?
(list: the first suggestion highlighted, default true), commitOnBlur? (list: false = Tab, arrows and a blur never take
an option - only Enter or a click), className?}`. Options flagged `action: true` (Open the profile, Unlink) are never
taken by Tab, arrows or a blur in any column. The first column is the sticky row header.

`cell(rowKey, colKey)` → `{text, html?, checked?, label? (checkbox name), readonly?, marker?, className?, copy?}` - keep
the markup small (every element costs layout time: a 400 × 18 sheet is 7,000+ cells; plain text needs no wrapper).

Callbacks: `seenValue(row, col)` - what the cell shows as a change compares it, read when an editor OPENS (typing,
Enter/F2, a double click, Alt+↓); `commit(row, col, {text, option}, {fill, cells, seen})` (`seen` = that value; undefined
for fills and pastes - no editor) → `{error}` keeps the editor open with the reason (`aria-invalid` + described),
`{focus: {row, col} | (move) => {row, col}}` overrides where the focus goes next;
`suggest(row, col, query)` → options (array or `{options, hint}`, sync or a Promise; option = `{value, label, html?, detail?,
create?, className?, …anything the view needs back}`); `toggle(cells, value|null)`; `clear(cells)`; `paste(anchor, rows,
selectedCells)` (rows already parsed by tsv.js); `fill('down' | 'selection', {rows, cols}, active)`; `cut(cells)` (default
clear); `activate(row, col)` (action cells: Enter, double click); `openPanel(row)` (Space on a `space: 'panel'` column);
`editValue(row, col)` (Enter/F2 start text); `rowLabel(row)` (editor/checkbox names); `rowClass(row)`.

Methods: `setRows(keys)` (keyed: rows reused, created, removed, moved - cells not re-rendered), `updateRows(keys)` /
`updateCell(row, col)` (cell-local: only cells whose markup changed are touched; a checkbox always shows the cell's state -
a click the view refused snaps back; a focused checkbox keeps its element; the cell being edited is never re-rendered),
`editorNotice({text, actions: [{label, run}]} | null)` (a note next to the open editor - polite live region, in the
editor's `aria-describedby`; Tab from the editor reaches its buttons, Esc goes back), `acknowledgeSeen(value)` /
`commitWithSeen(value)` (Keep mine: the edit goes over the value now seen), `editState()` → `{row, col, text, seen, …}` /
`resumeEdit(state)` (a rebuilt grid goes on with an edit), `commitOpenEdit()` (like a blur), `focusCell(row, col)`,
`focusActive()`, `isEditing()`, `fitHeight()`, `destroy({keepEdit?})` (an open edit is committed like a blur unless
`keepEdit`); `grid.rows`, `grid.columns`, `grid.active`, `grid.stats` (`renderMs`, `lastCommitMs`, `lastCellMs`).
Every cell carries `aria-selected` (`false` unless selected - APG).

Behaviour: roving tabindex (a checkbox cell focuses its checkbox - its name carries the checked state); ranges by
Shift+arrows, mouse drag, Shift+Space (row), Ctrl+Space or a header click (column), Ctrl+A; one floating 16 px editor
(IME-safe; Enter/Tab/arrows commit and move per grid_keys); `list` columns are a combobox + listbox (arrows, Enter/Tab pick,
Esc closes the list, a second Esc cancels); Ctrl/Cmd+C/X/V through a hidden textarea (WebKit sends no clipboard event to
a table cell); a blur commits (a refused value is dropped and its reason announced); the scroller's height fits the
viewport, sticky header + first column with scroll padding (2.4.11).

### The view interface

A view module is `assets/participants_sheet/views/<name>.js` with `export default function (context) → view`. The
controller picks it by name (`VIEW_MODULES` in the controller) and loads it with a dynamic import (a separate chunk):

| Tab | Desktop | Phone (< 768 px) | Missing module |
|---|---|---|---|
| People | `people_view` (C, E extends) | `people_list_view` (E) | the People grid |
| solo round | `solo_round_view` (D) | `round_cards_view` (D) | a summary placeholder |
| duo / team round | `team_round_view` (D) | `round_cards_view` (D) | a summary placeholder |
| person editor (Space on a name) | `person_editor` (E): `export default (context) → {open(personId), update?(delta), destroy()}` | same | nothing happens |

`context`: `root` (the view's element), `kind` (`people` | `round`), `round` (round tabs), `phone`, `model`, `queue`, `undo`,
`texts` (`{core, round, people}`, each `{t(key, params), tc(key, count, params), has(key)}` over `_texts_core|round|people`),
`countries` (code → label), `countryCodes` (Set), `locale`, `urls`, `csrfToken`, `act(action, {origin?, quiet?})` → `{performed,
errors}`, `announce(text)` (the polite live region), `switchTab(tabId, focus)` (`focus` = `{personId?, teamId?, col?}` handed
to the new view's `focus()`), `openPersonEditor(personId)`, `createGrid(options)` (the page knows the grid: its open edit
is saved before the page goes), `preview(options)` (an open `PreviewDialog`), `errorText(error)` (a client refusal in
the server's words with its parameters - use it for an action's `errors`), `reasonText(code, params?)`, `markerFor(key)`.

**The open editor and live changes** (review B1, the results desk's `openEditor()` lesson): a view gives the grid
`seenValue` for every editable column and passes `from: extra.seen` to the builder; in `update(delta)` it compares the
edited cell's value now with `grid.editState().seen` and shows `grid.editorNotice({text: "Changed meanwhile to X",
actions: [Keep mine → grid.commitWithSeen(now), Use theirs → grid.cancelEdit(true)]})` (or `null` when equal again).
Enter without a choice sends over what the editor opened with - the server answers with a conflict. A view rebuilding
its grid (`update` with `delta.all`, new columns) keeps the edit: `editState()` → `destroy({keepEdit: true})` → render →
`resumeEdit(state)`.

`view`: `render()`, `update(delta)` (re-render only what the delta names), `focus(target)`, `reveal(problem)` (jump to a
problem's cell - the problems panel's "Show"), `onOutcome?(event)`, `destroy()`.

The controller owns: tabs (People + every round in order, counts, problem badges, `?tab=` via `replaceState`, APG tab
keys), the status pill (click: problems / retry / reload), banners (signed out, forbidden, gone, offline), the problems
panel (Show, Keep mine, Use theirs, OK, Try again), undo/redo (targets + Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y outside text
inputs and outside dialogs; titles with ⌘ on a Mac), the setup checklist (`checklist` target - hidden once the event
has a round and a person), the keyboard help (`help` target or `?` outside the grid), the live region, the phone
breakpoint (a view is rebuilt only when another module shows the tab; the focus comes back), view modules (a module not
in the build falls back for good; one that failed to load - a chunk while offline - says so with "Try again" and is
tried again when back online, never remembered as missing), and the teardown in `disconnect()` (an open edit is saved
and sent first; listeners, timers, dialogs, the person editor let go). The undo/redo/help buttons are wired by the controller unless their markup
already calls `participants-sheet#undo` / `#redo` / `#showHelp`.

### The People grid (basic, `views/people_view.js`)

Name (sticky row header; badges "On the waitlist", "Joined by themselves"), Country (combobox over `countries`, typed
codes and names accepted), MySpeedPuzzling profile (O9: name + #CODE + a link when visible, else "Linked to a
MySpeedPuzzling profile"; a combobox over `urls.playerSearch` (`participants_sheet_player_search`, `hidden` players
included and shown as linked only) - Open / Unlink while nothing is typed; `autoHighlight: false`, `commitOnBlur: false`:
only Enter or a click links or unlinks), a checkbox column
per solo round, a read-only label per pair/team round (team name or "(no name)", "No pair yet", size when off; Enter or a
double click opens the round's tab at the person), the new-person row (type a name, Enter = the next name, Tab = the new
person's next cell). Delete clears (a name refuses), Ctrl+D / Ctrl+Enter fill country and solo columns, paste fills
names / countries / solo columns of existing rows (one value onto a selection fills it); more than 10 rows or anything
left out opens the preview with the server's dry run; rows below the list are listed, not added (adding by paste is E's).

Bulk actions (review minor 5, `perf` suite, node on a laptop): a bulk "in" over 400 people = 400 groups built, shown and
settled in ~5 ms (1,000: ~13 ms), one re-render to show them and one for the answer - before the batch APIs 360 ms and
2.4 s with a re-render per group.

### Measured (2026-10-08, standalone harness, headless Chromium 124, invented WJPC-sized event)

400 people × 15 rounds (18 columns, 7,236 cells, 100 pairs, 50 teams): render 190 ms at 1× CPU (layout ~95 ms of it),
760-820 ms at 4×; an edit = 6-8 ms from Enter to the re-rendered cell (the cell itself 0.3 ms). 1,000 people: render
500 ms, an edit 7.6 ms. Checked with real keys (CDP): arrows, Home/End, Ctrl+Home/End, PageDown, Tab wrap, leaving with
Ctrl+End + Tab, ranges, header click, the focused cell never under the sticky header/column, typing to edit, Enter/Esc,
an invalid name kept with `aria-invalid` + the reason, country and profile comboboxes, a checkbox cell, the new-person
row, Delete, Ctrl+D, undo/redo through the server, a real Ctrl+V of an Excel CRLF block, a 12-row paste through the
preview + dry run, Ctrl+C as TSV, a conflict + Keep mine, a live version change keeping the focus, offline + back online,
an IME composition, the tabs keyboard, the help dialog, `disconnect()` leaving no listener, timer or request; no
horizontal page scroll at 1280 and 375 px.
