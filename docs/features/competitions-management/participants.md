# Participant Management

Community-driven participant management for competitions. Replaces the admin-only import system (`/admin/import-competition-puzzlers`) with a self-service system accessible to both organizers (maintainers) and players.

## Overview

Participants can enter a competition two ways:

| Source | Created by | Has `player` link? | Has `name`? |
|--------|-----------|---------------------|-------------|
| **Self-join** ("I'm going") | The player themselves | Yes (immediately) | From player profile |
| **Import / manual add** | Organizer | Maybe (if `msp_player_id` provided) | From import data / form |

`CompetitionParticipant` always belongs to a `Competition` — same entity for standalone events and series editions. Zero behavioral branching.

## Entity Changes

### CompetitionParticipant — New Fields

| Field | Type | Purpose |
|-------|------|---------|
| `externalId` | `?string` | Organizer's reference ID from their external system (informational only) |
| `deletedAt` | `?DateTimeImmutable` | Soft delete timestamp. `NULL` = active |
| `source` | `ParticipantSource` | How the participant was created |

### ParticipantSource Enum

```php
enum ParticipantSource: string
{
    case SelfJoined = 'self_joined';  // Player clicked "I'm going"
    case Imported = 'imported';        // From Excel import
    case Manual = 'manual';            // Organizer added via UI
}
```

### Existing Fields (unchanged)

- `id` (UuidInterface) — primary key
- `name` (string) — participant name (typically full official name from organizer's list)
- `country` (?string) — 2-letter country code
- `competition` (Competition) — owning competition
- `player` (?Player) — linked MSP player profile
- `connectedAt` (?DateTimeImmutable) — when player was linked
- `remoteId` (?string) — legacy field, kept for backward compat

### Two-Name Concept

Participant `name` and MSP player display name are **separate concepts**:
- **Participant name** — the official name used by the competition organizer (e.g. "Jan Mikeš" in the registration system). Set during import or manual add. For self-joined participants, initialized from the player's profile name.
- **MSP player name** — the user's display name on MySpeedPuzzling (e.g. "Honza M." or a nickname). Comes from the linked `Player` entity.

Both can be displayed in the UI. The connected participants table already shows both (player name as primary, participant name as secondary). This distinction matters because organizers often use legal/official names while players may use nicknames on MSP.

### Soft Delete Behavior

- `deletedAt = NULL` → active participant, visible everywhere
- `deletedAt = timestamp` → hidden from public views, hidden from chart, hidden from export by default
- Organizer management UI shows deleted participants (with strikethrough) when "Show deleted" filter is active
- Deleted participants can be restored (set `deletedAt = NULL`)
- Self-joined players who "leave" an event → soft delete their participant record. They can re-join later — the existing soft-deleted record is restored (`deletedAt` cleared), not duplicated.

## Unified "I'm Going" + Pairing Flow

Replaces both the old "Connect my profile" flow (`CompetitionConnectionController`) and adds "I'm going" self-join. One button, one flow, adaptive behavior.

### Button on Public Event Page

| User state | Button shown |
|------------|-------------|
| Not logged in | "I'm going" → redirects to login, then back |
| Logged in, not joined/connected | "I'm going" |
| Logged in, already joined/connected (self-joined) | "You're going ✓" + "Change" link + "Leave" option (soft deletes) |
| Logged in, already connected (to imported/manual participant) | "You're going ✓" + "Change" link + "Disconnect" option (unlinks, keeps participant record) |

**"Change" link** navigates to the pairing screen where the user can switch which participant they're linked to (pick a different name from the organizer's list). Useful when a user accidentally connected to the wrong participant.

### Flow After Clicking "I'm Going"

**Step 1 — Check for unconnected participants:**

If there are imported/manual participants not yet linked to any player:

```
┌─────────────────────────────────────────────────────┐
│  I'm going to "WJPC 2026"                          │
│                                                      │
│  The organizer has uploaded a participant list.      │
│  Find your name to connect your profile:             │
│                                                      │
│  [Search your name...              ▼] ← Tom Select   │
│                                                      │
│  [Connect & confirm I'm going]                       │
│                                                      │
│  ─────── or ───────                                  │
│                                                      │
│  Can't find yourself on the list?                    │
│                                                      │
│  [Join as {playerName} from {playerCountry}]         │
└─────────────────────────────────────────────────────┘
```

The picker offers **only unclaimed participants** (`player IS NULL`, not soft-deleted) — never names already connected to someone, self-joined MSP names or private profiles (`GetCompetitionParticipants::getNotConnectedParticipants()`). Submitting it without a name does nothing; "Join as" posts its own `self_join=1` so an empty picker can never fall through to a self-join.

If the player's MSP name matches **exactly one** unclaimed participant (ignoring case, accents and extra whitespace; a different country never matches — `findNotConnectedParticipantMatchingName()`):

→ Skip the picker, connect to that participant. Clicking "I'm going" is the opt-in — nobody is ever connected to an organizer's row without it.

If there are NO unconnected participants (empty list or all already paired):

→ Skip the picker, immediately create self-join participant from player profile. Redirect back with success flash.

**Step 2 — Result (`JoinCompetitionHandler`):**

- **Picked from list** → validates first (same competition, not soft-deleted, not connected to another player), only then releases the player's other rows and connects the selected one. Validation must precede any change: a rolled-back handler leaves its changed entities in the entity manager, and the next flush in the same request (e.g. `PlayerActivitySubscriber` on `kernel.terminate`) would still write them — that is how nameless orphan rows appeared on WJPC 2026.
- **"Not on the list"** → create new `CompetitionParticipant` with `source=self_joined`, `player` linked immediately, `name` and `country` from player profile. **Idempotent**: a player who already has an active self-joined row gets no second one; one connected to an organizer's row is disconnected from it first.

**Releasing rows:** whenever the player switches, their self-joined row is soft-deleted (it would otherwise stay as an unclaimed row with their MSP name that anyone could pick) and an imported/manual row is only disconnected.

**Re-connecting:** A player who is already connected can visit this flow again to change their connection (pick a different participant from the list). The "Change" link is hidden when nobody is left on the list to switch to.

### Leaving / Disconnecting

The button label and behavior depend on how the participant was created:

Leaving handles **every** active row of the player in the competition, not just one.

- **Self-joined participant** → button says **"Leave"** → soft deletes the participant record (`deletedAt` set). Player can re-join later.
- **Imported/manual participant** → button says **"Disconnect"** → unlinks player from participant (`player=NULL`, `connectedAt=NULL`), does NOT soft delete. The organizer's imported record stays intact. Player can reconnect later.

Clear wording is important — "Disconnect" communicates that the participant record stays, you're just unlinking your profile.

## Organizer Participant Management UI

Accessible from the competition edit/management page. Uses `CompetitionEditVoter` for access control (maintainer or admin).

### Route

`/en/manage-competition-participants/{competitionId}` (+ localized variants)

### Organizer Checklist

Below the import/export section, a setup checklist guides organizers through remaining steps:

- Rounds configured (with count and link to round management)
- Participants imported

The checklist is **hidden entirely** when both items are done (rounds > 0 and participants > 0). This avoids unnecessary clutter once setup is complete.

### Layout

Live Component with inline editing, inspired by [Symfony UX inline edit demo](https://ux.symfony.com/demos/live-component/inline-edit).

```
┌──────────────────────────────────────────────────────────────────────┐
│ Participants for "WJPC 2026"           34 active · 2 deleted         │
│                                                                      │
│ [Import Excel]  [Export Excel]  [+ Add Participant]                  │
│                                                                      │
│ [Search...              ]              [☐ Show deleted]              │
│                                                                      │
│ ┌───────────┬─────┬──────────┬─────────────┬───────────┬──────────┐ │
│ │ Name      │ 🏳  │ Ext. ID  │ MSP Player  │ Rounds    │          │ │
│ ├───────────┼─────┼──────────┼─────────────┼───────────┼──────────┤ │
│ │ Jan M.    │ CZ  │ P-001    │ Jan Mikeš ↗ │ [R1] [R2] │ ✏️  🗑   │ │
│ │ Bob S.    │ US  │ —        │ —           │ [R1]      │ ✏️  🗑   │ │
│ │ Alice W.  │ GB  │ —        │ Alice W. ↗  │ [       ] │ ✏️  🗑   │ │
│ │                                            ↑ inline               │ │
│ │                                 Tom Select multiselect             │ │
│ │ ~~Deleted~~ │ ~~FR~~ │        │             │           │ ↩️       │ │
│ └───────────┴─────┴──────────┴─────────────┴───────────┴──────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

### Inline Editing

Each participant row can be edited inline (click ✏️ or click on the cell):

- **Name** — text input
- **Country** — Tom Select country autocomplete with flag icons, grouped by region (same `country-select` Stimulus controller used in Events listing, Marketplace, etc.). Wrapped in `data-live-ignore` to prevent TomSelect destruction on Live Component re-render.
- **External ID** — text input
- **MSP Player** — inline search (min 2 characters, up to 10 results), with "clear" option to disconnect
- **Rounds** — clickable round badges to toggle assignment. Updates `CompetitionParticipantRound` records.

Save/Cancel buttons appear inline when editing. Uses `#[LiveAction]` methods on the component.

**Organizer can link any MSP player** to any participant without the player's consent. This is intentional — organizers need full control over participant pairing for competition management. The player can later disconnect themselves via the public event page if they disagree.

### Add Participant

Opens an inline form at the top of the table:

| Field | Required? | Input type |
|-------|-----------|------------|
| Name | Yes | Text |
| Country | No | Tom Select country autocomplete (with flags, grouped by region) |
| External ID | No | Text |
| MSP Player | No | Inline search autocomplete |

Creates `CompetitionParticipant` with `source=manual`.

### Delete / Restore

- **Delete** (🗑) → sets `deletedAt`, row disappears unless "Show deleted" is checked
- **Restore** (↩️) → clears `deletedAt`, row becomes active again
- No hard delete through the UI

### Search

Client-side filtering by participant name. Filters the visible table rows.

## Excel Import

### Import Format

| Column | Required? | Description |
|--------|-----------|-------------|
| `name` | Yes | Participant name |
| `country` | No | 2-letter country code |
| `external_id` | No | Organizer's external system reference |
| `msp_player_id` | No | UUID of MSP player to link |
| `status` | No | `active` (default) or `deleted` for soft-delete |

**No `round_id` in import.** Round assignment is purely a management UI concern (inline Tom Select multiselect).

### Upsert Logic

Import is **always additive** — it never deletes participants not present in the file. Matching priority:

1. **`msp_player_id`** — if provided and a participant with that player already exists in this competition → update that participant
2. **`external_id`** — if provided and matches an existing participant's `externalId` in this competition → update
3. **Name + country match** — if a participant with the exact same name AND same country exists in this competition → update. If name matches but country differs (or multiple name matches exist), skip auto-matching and create new participant instead — report as warning so organizer can resolve manually in the UI
4. **Unique name match** — if exactly one participant with the exact same name exists (regardless of country) → update
5. **No match** → create new participant with `source=imported`

**Soft-deleted participants are included in upsert matching.** If a match hits a soft-deleted participant, it is restored (`deletedAt` cleared) and updated with the imported data. Active rows win over soft-deleted ones. **Exception:** a soft-deleted *self-joined* row is the player's own "I left" record — it is never matched, so an import can't sign a player up again.

Best practice for organizers: always use `external_id` or `msp_player_id` for reliable matching. Name-based matching is a convenience fallback.

### Validation

- `name` is required — rows without a name are skipped and reported as errors
- `country` is validated against `CountryCode` enum — invalid codes reported as errors, row still imported without country
- `msp_player_id` is validated: must be valid UUID format AND must exist in the `player` table. If UUID doesn't exist → reported as error, participant created without player link
- `status` must be `active` or `deleted` — invalid values default to `active`
- Duplicate detection within the same import file (same name appearing twice) → reported as warning

### Self-Join + Import Collision

When an organizer imports a file containing a `msp_player_id` that matches a self-joined participant's player:
→ **Merge automatically.** The existing self-join participant is updated with the imported data (`externalId`, etc.) and becomes `source=imported` — it is on the organizer's list now, so leaving disconnects instead of deleting it. No duplicate created.

Only put `msp_player_id` in a file for players who opted in themselves. WJPC 2026 (2026-09-17): the list scraped from worldjigsawpuzzle.org carried `msp_player_id` only for players who had already clicked "I'm going" **and** whose name matched the WJPF name — a WJPF e-mail pairing (`wjpf_identity`) alone is not consent to be listed as going.

**Name handling on merge:** Participant `name` and MSP player display name are separate fields (see Two-Name Concept). On merge, the **organizer's imported `name` overwrites** the existing participant `name` — the organizer is authoritative for official competition names. The player's MSP display name (from the `Player` entity) is unaffected and always available separately.

### Import UI

Part of the management page. Expandable `<details>` section ("Import / Export" button) with two columns:

- **Left:** File upload form (.xlsx) with Import button
- **Right:** Export buttons (export current participants, download template)

Below the columns, a **"How does import work?"** documentation panel explains:

1. **Merge behavior:** New names are added, existing participants (matched by name) are updated, participants not in the file are left untouched — nothing gets deleted unless `status` is set to `"deleted"`.
2. **Column reference table:** Each column (`name`, `country`, `external_id`, `msp_player_id`, `status`) with required/optional flag and description. The `status` column explicitly documents allowed values: `"active"` (default) or `"deleted"`.
3. **Recommended workflow:** Download template/export → edit → upload.

### Post-Import Feedback

After import, show a summary:

```
Import complete:
  ✅ Added: 12 new participants
  🔄 Updated: 5 existing participants
  🗑️ Soft-deleted: 2 participants (status=deleted in file)
  ⚠️ Warnings: 1 (duplicate name in file: "Jan Mikeš" on rows 3 and 15)
  ❌ Errors: 1 (row 7: msp_player_id "abc-123" is not a valid player)
```

## Excel Export

### Export Format

Always contains ALL participant data (active participants by default):

| Column | Description | Always populated? |
|--------|-------------|-------------------|
| `name` | Participant name | Yes |
| `country` | 2-letter code | If set |
| `external_id` | Organizer's reference | If set |
| `msp_player_id` | UUID of linked player | If connected |
| `status` | `active` or `deleted` | Yes |

Export is per-competition (per-edition for series). No cross-edition export. Downloadable as `.xlsx` from the management page.

Soft-deleted participants are **excluded** from export by default. The export represents the current active participant list — ready for round-trip re-import.

### Template Download

A downloadable `.xlsx` template with **header row only** (no example data). Same columns as the import format. Available from the import UI section.

## Secret/Private Player Handling

**Existing gap:** The `CompetitionParticipants` public component currently does NOT respect `player.isPrivate`. This must be fixed as part of this work.

### Rules

| Player type | Name visible? | Country flag? | Times/stats? | In chart? |
|-------------|--------------|---------------|-------------|-----------|
| Normal player | Yes | Yes | Yes | Yes |
| Private player (`isPrivate=true`) | Yes (name only) | Yes | **No** — show dash | **No** — excluded |
| Self-joined, no rounds | Yes | Yes | N/A (no solving data) | No |

Private players are shown in the participant list with their name and flag (confirming they're going), but:
- Average time, fastest time, solved count → all shown as "—"
- Excluded from the average times chart entirely
- This applies to both connected and self-joined participants whose player has `isPrivate=true`

## Access Control

| Action | Who |
|--------|-----|
| View participants on public page | Everyone |
| "I'm going" / self-join | Any authenticated player |
| Leave event | The player themselves |
| Manage participants (UI, import/export) | Competition maintainer or admin (`CompetitionEditVoter`) |
| Inline round assignment | Competition maintainer or admin |

## What Gets Removed

These routes and their controllers/templates are replaced by the new system:

- `admin_import_competition_puzzlers` — `/admin/import-competition-puzzlers`
- `admin_import_competition_puzzlers_upload` — `/admin/import-competition-puzzlers/{competitionId}`
- `competition_connection` — `/en/competition-connect/{slug}` (replaced by unified "I'm going" flow)

Related files to remove:
- `src/Controller/Admin/ImportCompetitionPuzzlersController.php`
- `src/Controller/Admin/ImportCompetitionPuzzlersUploadController.php`
- `src/Controller/CompetitionConnectionController.php`
- `templates/admin/import_competition_puzzlers.html.twig`
- `templates/admin/import_competition_puzzlers_upload.html.twig`
- `templates/competition_connection.html.twig`
- `src/FormType/CompetitionConnectionFormType.php`
- `src/FormData/CompetitionConnectionFormData.php`

Messages/handlers to refactor:
- `UpdateCompetitionParticipant` + handler → refactor into new import service
- `ConnectCompetitionParticipant` + handler → keep and extend for the unified flow
- `ImportCompetitionData` (skeleton) → remove

## Testing Strategy

### Excel Import Tests
- Store small `.xlsx` fixture files in `tests/fixtures/competition-participants/`
- **Parse test:** feed fixture → assert parsed rows match expected data
- **Upsert matching tests:** test all 4 priority levels (msp_player_id, external_id, name, new)
- **Validation test:** invalid country codes, non-existent player UUIDs, missing names
- **Status column test:** rows with `status=deleted` → verify `deletedAt` set
- **Idempotent test:** import same file twice → no duplicates, no changes on second run

### Excel Export Tests
- Create participants in DB → export → read `.xlsx` → assert cell values match
- **Round-trip test:** export → re-import → verify DB unchanged

### Self-Join Tests
- Player joins → verify `CompetitionParticipant` created with `source=self_joined`, `player` linked
- Player joins, then organizer imports with matching `msp_player_id` → verify merge, no duplicate
- Player leaves → verify soft delete
- Player re-joins → verify new record created (or existing restored)

### Pairing Tests
- Import participants → player pairs with one → verify connection
- Player re-pairs (switches to different participant) → verify old disconnected, new connected

### Privacy Tests
- Private player joins → verify name+flag shown but times hidden in component output
- Private player in chart data → verify excluded

### Management UI Tests
- Add participant via UI → verify created with `source=manual`
- Edit participant inline → verify fields updated
- Delete participant → verify soft deleted
- Restore participant → verify `deletedAt` cleared
- Round assignment via multiselect → verify `CompetitionParticipantRound` records

## Implementation Sequence

### Phase 1 — Entity & Migration
1. Add `externalId`, `deletedAt`, `source` fields to `CompetitionParticipant`
2. Create `ParticipantSource` enum
3. Generate and run migration
4. Backfill existing participants: `source=imported` for all existing records

### Phase 2 — Organizer Management UI
5. Create management Live Component with participant table
6. Implement inline editing (name, country, external_id, player search, round multiselect)
7. Implement add participant form
8. Implement soft delete / restore
9. Wire up route and navigation from competition edit page

### Phase 3 — Excel Import/Export
10. Implement export service (query participants → generate `.xlsx`)
11. Implement import service (parse `.xlsx` → upsert logic with matching priority)
12. Add import UI to management page with feedback summary
13. Add template download
14. Write import/export tests

### Phase 4 — Unified "I'm Going" Flow
15. Create new "I'm going" controller + template (adaptive: picker vs direct join)
16. Implement self-join command + handler
17. Implement "leave" action
18. Replace old connection button in `CompetitionParticipants` component
19. Remove old `CompetitionConnectionController` and related files

### Phase 5 — Privacy Fix & Cleanup
20. Fix `CompetitionParticipants` component to respect `player.isPrivate` (hide times, exclude from chart)
21. Remove admin import routes and controllers
22. Update `GetCompetitionParticipants` query to handle soft-deleted participants
23. Final test pass
