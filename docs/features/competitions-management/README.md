# Competitions Management

Community-driven competition and event management. Any logged-in player can submit a competition; it becomes publicly visible after admin approval. Maintainers (the creator + named co-maintainers) can then manage rounds, assign puzzles, plan table layouts, and run a live stopwatch during the event.

## Competition Lifecycle

### 1. Submission

Any authenticated player can submit a new competition with:
- **Required:** name, location
- **Optional:** shortcut (e.g. "WJPC"), description, website/registration/results links, country, date range, online flag, recurring flag, logo image
- **Maintainers:** other players who should have edit access (searchable autocomplete)

A URL slug is auto-generated from the name (with a random suffix if collisions exist). The competition is stored with `approvedAt = null` (pending state) and is **not visible** in the public listing.

### 2. Admin Review (Approve or Reject)

Admins see all pending competitions in a dedicated approval queue (`/admin/competition-approvals`).

- **Approve:** Sets `approvedAt`, makes the competition publicly visible. The creator receives an email notification with a link to the public event page.
- **Reject:** Admin must provide a reason. Sets `rejectedAt` and `rejectionReason`. The creator receives an email notification with the rejection reason. Rejected competitions are removed from the approval queue and remain invisible in public listings. The rejection reason is displayed on the edit page.

An admin notification email is sent automatically when a new competition is submitted, linking to the approval queue.

### 3. Editing

Maintainers and admins can edit all competition fields. While unapproved, a warning banner is shown on the edit page. If rejected, a danger banner with the rejection reason is shown instead. The edit page provides navigation to round management and participant management.

Changing the name regenerates the slug. Maintainer lists are fully replaced on each save (clear + re-add).

### 4. Public Listing

The events page shows four sections:
- **Live** — one-time events where today's date falls within the event date range
- **Upcoming** — one-time events starting in the future
- **Recurring** — all approved recurring events (sorted alphabetically)
- **Past** — one-time events that have ended

Recurring events are excluded from Live/Upcoming/Past sections. All sections only show approved competitions. External links (website, registration, results) automatically get `utm_source=myspeedpuzzling` appended. Online and recurring badges are displayed on event cards.

Each competition also appears in "My Competitions" for its creator/maintainers regardless of approval status.

## Access Control

| Action | Who |
|--------|-----|
| Browse public events listing | Everyone |
| Submit a new competition | Any authenticated player |
| Edit competition & manage rounds/tables/stopwatch | Admin, original creator, or named maintainer |
| View public stopwatch page | Everyone (no auth required) |
| Approve or reject a competition | Admin only |

Access is enforced via a `CompetitionEditVoter` that checks whether the player is admin, the creator, or in the maintainers list. All management controllers use this same voter, including round-level controllers (which resolve the competition from the round).

## Event Types

**Online and offline are never combined** — a competition is either fully online or fully offline. Users must create separate competitions for each format.

### Standalone Competitions (One-Time Events)

One-time events (`Competition` with `series_id = NULL`) represent individual competitions:
- **Offline events** (e.g. WJPC 2024): Have location, dateFrom/dateTo, table layouts, multiple rounds
- **Online events** (e.g. Online Challenge 2024): No location, have dateFrom/dateTo, typically single round

### Competition Series (Recurring Events)

Recurring events use a **`CompetitionSeries` entity** that groups multiple editions. Each edition is a full `Competition` with its own participants, rounds, and metadata.

```
CompetitionSeries ("Euro Jigsaw Jam")
├── name, slug, description, logo, website link
├── isOnline, location, country
├── maintainers, approval workflow
└── Competition ("EJJ #68")          ← edition = full Competition
     ├── name, dateFrom/dateTo
     ├── registrationLink, resultsLink  ← per-edition
     ├── series_id (FK → CompetitionSeries)
     ├── CompetitionRound              ← usually just 1 for online
     └── CompetitionParticipant        ← edition-scoped
```

**Key design**: Participants always belong to a `Competition`, whether it's a standalone event or a series edition. Zero behavioral branching in participant handlers/queries.

**Creating a series:**
1. User submits a new event with "recurring event series" checked
2. A `CompetitionSeries` is created (pending approval)
3. From the series management page, organizer adds editions
4. Each edition auto-creates a `Competition` + `CompetitionRound` in one step

**Adding an edition (streamlined form):**
- Name (e.g. "EJJ #68 — March 2026")
- Date & time, time limit
- Registration link, results link (optional)

**Public series page** (`/en/series/{slug}`):
- Series header with name, description, logo, website link, badges
- Upcoming editions table: name, date, time limit, puzzle count, participant count, registration link
- Past editions table: same columns with results link
- Each edition row links to an edition detail page

**Public edition detail page** (`/en/edition/{competitionId}`):
- Edition header with link back to series
- Puzzle grid (from the edition's round)
- Participants component (competition-scoped)

**Events listing:**
- Standalone competitions appear in Live/Upcoming/Past sections
- Series appear in a dedicated "Recurring" section as single cards
- Editions (competitions with `series_id`) are excluded from Live/Upcoming/Past

## Round Management

A competition has multiple **rounds**, each with:
- **Name** and **start time**
- **Minutes limit** — the time limit for solving (drives the stopwatch countdown)
- **Badge colors** — optional background/text hex colors for visual distinction in round lists

Rounds are displayed sorted by start time. Each round can be edited or deleted. The round list shows action buttons for: Puzzles, Tables (only for in-person events), Stopwatch, Edit, Delete.

## Puzzle Assignment

Puzzles are assigned to rounds via a `CompetitionRoundPuzzle` join. When adding a puzzle to a round:

- **Existing puzzle:** select by UUID
- **New puzzle on the fly:** provide name, piece count, manufacturer (existing or new), optional photo, EAN, identification number. The new puzzle is created with `approved = false`

### Hide Until Round Starts

Each puzzle assignment has a `hideUntilRoundStarts` flag. When enabled, the puzzle's own `hideUntil` field is set to the round's start time, making it invisible in the general puzzle database until the round begins. This prevents spoiling which puzzles will be used.

## Table Layout System

For **in-person events only**, organizers can plan the physical seating layout. The hierarchy is:

```
Round
  -> Table Rows  (e.g. "Row 1", "Row 2")
       -> Tables  (e.g. "Table 1", "Table 2", numbered globally)
            -> Spots  (individual seats, assignable to players)
```

### Generation

A form lets the organizer specify rows count (1-20), tables per row (1-20), and spots per table (1-10). Generating a layout **replaces the entire existing layout** for that round (destructive, no confirmation).

### Manual Editing

A Symfony Live Component provides real-time inline editing:
- Add/remove rows, tables, spots
- Assign a player to a spot via inline search (min 2 characters, up to 10 results)
- Assign a manual name (for participants not registered on the platform)
- Clear spot assignments
- Player and manual name are mutually exclusive on a spot

### Print View

A standalone, minimal HTML page (no base layout, print-optimized CSS) showing the full table grid. Empty spots show a blank line for handwriting. Opens in a new browser tab.

## Round Stopwatch

A real-time countdown/count-up timer for running competition rounds live.

### Server State

Each round tracks `stopwatchStartedAt` (UTC timestamp) and `stopwatchStatus` (`null` / `running` / `stopped`):

- **Not started** (`null`): only "Start" available
- **Running**: only "Stop" available
- **Stopped**: "Start" (resume) and "Reset" available

### Real-Time Sync via Mercure

Every state change (start, stop, reset) publishes an SSE event on topic `/round-stopwatch/{roundId}`. All connected browsers receive the update instantly.

### Client-Side Timer

A Stimulus controller handles the display:
- Computes server/client clock offset on page load for accurate timing
- Uses `requestAnimationFrame` for smooth `HH:MM:SS` rendering
- When elapsed time reaches the round's `minutesLimit`, the display shows "Time's up" (client-side only, no server event)
- Subscribes to Mercure SSE for real-time start/stop/reset events

### Two Views

- **Public view** (`/en/round-stopwatch/{roundId}`): large timer display, accessible to everyone — useful for projecting at events
- **Management view** (`/en/manage-round-stopwatch/{roundId}`): shows status + control buttons, requires edit permission

## Participant Management

`CompetitionParticipant` always belongs to a `Competition`. This means:
- **For standalone events**: participants belong to the competition, assigned to rounds via `CompetitionParticipantRound`
- **For series editions**: participants belong to the edition (which IS a Competition), completely independent from other editions

This eliminates all behavioral branching — the same participant handlers, queries, and components work for both standalone events and series editions.

## Email Notifications

Three email notifications are sent during the competition lifecycle:

1. **New submission (to admin):** When a player submits a new competition, an email is sent to `jan.mikes@myspeedpuzzling.com` with the event name, location, submitter name, and a link to the admin approval queue.
2. **Approved (to creator):** When an admin approves a competition, the creator receives an email with a link to their public event page. Sent in the creator's locale.
3. **Rejected (to creator):** When an admin rejects a competition, the creator receives an email with the rejection reason. Sent in the creator's locale.

All emails use the `transactional` mailer transport and follow the standard Inky email template structure.

## Key Business Rules

1. **Unapproved competitions are invisible** in public listings but accessible to their maintainers
2. **Table layout is only for in-person events** — the tables button is hidden when `isOnline = true`
3. **Layout generation is destructive** — it wipes the entire existing layout before creating a new grid
4. **`times_up` is client-only** — the server does not track when time expires; it's purely a display state
5. **New puzzles created via round assignment need separate approval** — they are created with `approved = false`
6. **Removing a puzzle from a round does not clear the puzzle's `hideUntil` field**
7. **External links get automatic UTM tracking** — `utm_source=myspeedpuzzling` is appended
8. **Rejected competitions are excluded from the approval queue** — they no longer appear as "pending"
9. **Email notifications require creator to have an email** — if the creator has no email on their profile, no notification is sent (no error)
10. **Series get their own listing section** — `CompetitionSeries` appear in a dedicated "Recurring" section; editions are excluded from Live/Upcoming/Past
11. **Online and offline are mutually exclusive** — one competition cannot be both; users create separate events
12. **Series editions don't need individual approval** — the series approval controls visibility for all editions
13. **Series maintainers manage all editions** — `IsCompetitionMaintainer` checks series maintainers for edition-level operations
14. **Each edition is a full Competition** — has its own participants, rounds, registration/results links
