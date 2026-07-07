# Managed Registration

> **Status: PROPOSAL — not implemented.** This document is the design spec for in-platform registration management. The README describes only implemented behavior.

Today, registration is entirely external: `Competition.registrationLink` is a URL button and the "I'm going" flow is an unlimited, informal RSVP. Organizers have no way to cap participants, track who paid, run a waitlist, or manage event-day check-in.

This feature adds an opt-in **"Manage registrations on MySpeedPuzzling"** checkbox to the competition form. When enabled, the "I'm going" flow becomes a real registration flow with capacity, reservation vs. paid states, waitlist, and deadline — all reusing the existing `CompetitionParticipant` model, so it works identically for standalone events and series editions (each edition is a `Competition`, settings are per-edition).

## Entity Changes

### Competition — new fields

| Field | Type | Purpose |
|-------|------|---------|
| `registrationManaged` | `bool` (default `false`) | Master switch. When `false`, everything behaves exactly as today |
| `capacity` | `?int` | Max active registrations (reserved + paid). `NULL` = unlimited |
| `registrationOpensAt` | `?DateTimeImmutable` | Before this, button shows "Registration opens {date}" |
| `registrationClosesAt` | `?DateTimeImmutable` | After this, self-registration is closed (organizer can still add manually) |
| `entryFeeText` | `?string` | Free-text fee description, e.g. "$15 per team, $10 solo". Displayed publicly. Free-text on purpose — pricing structures vary (per team / per person / early bird) and MSP never processes payments |
| `paymentInstructions` | `?text` | Private-ish text shown only to registered participants and in the confirmation email, e.g. "Venmo @puzzle-mn within 7 days to confirm your spot" |

When `registrationManaged = true`, the external `registrationLink` field is hidden in the form (mutually exclusive — one source of truth for how to register).

### CompetitionParticipant — new fields

| Field | Type | Purpose |
|-------|------|---------|
| `registrationStatus` | `?RegistrationStatus` | `NULL` for non-managed competitions and legacy records |
| `registeredAt` | `?DateTimeImmutable` | When the registration was made |
| `paidAt` | `?DateTimeImmutable` | When the organizer marked them paid (audit trail) |
| `checkedInAt` | `?DateTimeImmutable` | Event-day check-in timestamp |
| `organizerNote` | `?string` | Private organizer note ("paid cash 3/2", "needs accessible seating"). Never shown publicly |

### RegistrationStatus enum

```php
enum RegistrationStatus: string
{
    case Reserved = 'reserved';     // Registered, spot held, payment not confirmed
    case Paid = 'paid';             // Organizer confirmed payment (or free event confirmation)
    case Waitlisted = 'waitlisted'; // Capacity full at registration time
}
```

Cancellation is NOT a status — it reuses the existing soft-delete mechanism (`deletedAt`). A cancelled paid participant remains visible under the "Show deleted" filter with their paid badge, so the organizer knows a refund conversation may be needed (refunds happen offline).

## Capacity Semantics

- **Active registration** = `deletedAt IS NULL` AND `registrationStatus IN (reserved, paid)`
- Self-registration when active count ≥ capacity → participant is created with `registrationStatus = waitlisted` (clearly communicated before confirming)
- **Organizer manual add / import always bypasses capacity** (organizer override). The management UI shows an over-capacity warning badge but never blocks
- Reducing capacity below the current active count changes nothing automatically — a warning banner appears in the management UI
- Waitlist ordering is FIFO by `registeredAt`

### Waitlist promotion — manual, with guidance

When a spot frees up (cancellation, capacity increase), nothing happens automatically. The management UI shows a hint banner: *"1 spot is free and 3 people are waiting — promote the next in line?"* with a one-click promote button (promotes the oldest waitlisted registration to `reserved` and emails the player). Manual promotion keeps the organizer in control (they may want to skip no-shows or promote a specific person via the row action).

## Public Event Page (when managed)

The registration card replaces the plain "I'm going" button:

```
┌────────────────────────────────────────────────┐
│  Registration                                   │
│  ▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░  23 / 30 spots taken       │
│  Entry fee: $15 per team                        │
│  Registration closes in 4 days (Mar 12, 2026)   │
│                                                 │
│  [ Register ]                                   │
└────────────────────────────────────────────────┘
```

Button states:

| State | Button / label |
|-------|----------------|
| Not open yet | Disabled, "Registration opens {date}" |
| Open, capacity available | "Register" |
| Open, full, waitlist | "Join waitlist" (+ "X people waiting") |
| Closed | Disabled, "Registration closed" |
| Registered, reserved | "You're registered — awaiting payment confirmation" + payment instructions + Cancel |
| Registered, paid | "You're registered ✓" + Cancel |
| Waitlisted | "You're on the waitlist (#3)" + Leave waitlist |

After registering, the confirmation view shows `paymentInstructions` prominently. The public participant list shows reserved + paid participants without distinguishing them (payment state is private between organizer and participant). Waitlisted participants are not listed publicly — only the count.

The existing pairing flow (pick your name from the organizer's imported list) is preserved: if unconnected imported participants exist, registration first offers the picker, then applies registration status to the connected participant.

## Organizer Management UI

Extends the existing `ManageCompetitionParticipants` Live Component (no new page):

- **New columns:** status badge (Reserved / Paid / Waitlisted, plus over-capacity and checked-in indicators), quick actions: "Mark paid" / "Unmark paid", "Promote" (waitlisted rows)
- **Counters header:** `18 paid · 5 reserved · 3 waitlisted · capacity 30`
- **Status filter** chips next to the existing search + "Show deleted" toggle
- **Organizer note** — editable inline, shown as a small icon with tooltip when set
- **Import/export** gain a `registration_status` column (values `reserved` / `paid` / `waitlisted`; blank = keep existing / default `reserved` on create when managed). `paid` in an import sets `paidAt`
- Registration settings themselves (capacity, dates, fee) live on the competition edit form under the "Manage registrations" checkbox (fields toggled by the existing `competition-form` Stimulus controller pattern)

### Check-in mode (event day)

A dedicated mobile-first view, `/en/event-check-in/{competitionId}` (same `CompetitionEditVoter`), built as a Live Component:

- Big search box, large tap targets, one row per active participant
- Tap → check in (`checkedInAt` stamped, row turns green). Undo available
- Payment state is loud: unpaid participants show a red "NOT PAID" chip so the organizer can collect at the door and mark paid on the spot
- Progress: "34 / 41 checked in"

## Messages & Handlers

All state changes via Messenger, per project convention:

- `RegisterForCompetition(competitionId, playerId, ?participantId)` — replaces `JoinCompetition` logic when managed: connects-or-creates participant, then assigns `reserved` or `waitlisted` per capacity. For non-managed competitions, `JoinCompetition` behavior is unchanged
- `CancelRegistration(competitionId, playerId)` — soft delete (reuses leave semantics)
- `MarkParticipantPaid(participantId)` / `UnmarkParticipantPaid(participantId)`
- `PromoteFromWaitlist(participantId)`
- `CheckInParticipant(participantId)` / `UndoParticipantCheckIn(participantId)`

Registration settings are edited through the existing `EditCompetition` message (new fields).

## Email Notifications

All transactional, in the player's locale:

1. **Registration confirmed (reserved):** event summary + entry fee + `paymentInstructions`
2. **Payment confirmed:** "Your spot for {event} is confirmed"
3. **Waitlisted:** position + explanation that they'll be emailed if promoted
4. **Promoted from waitlist:** "A spot opened up" + payment instructions
5. **Organizer digest (optional, later):** daily summary of new registrations

## Access Control

No new voters. Registration management uses `CompetitionEditVoter`; self-registration requires authentication; cancellation only by the participant's own player.

## Edge Cases

- **Toggling `registrationManaged` off** keeps all statuses in the DB but the UI stops showing/enforcing them. Toggling back on restores them
- **Self-joined participants from before the feature** have `registrationStatus = NULL`; a managed competition treats NULL as `reserved` for display, and a backfill migration sets `reserved` for active participants of competitions that enable management
- **Organizer connects a player to a paid imported participant** — the status travels with the participant record (statuses are on the participant, not the player)
- **Series:** settings are per-edition by design; a series-level "copy settings from previous edition" convenience can come later

## Explicitly Out of Scope

- **Payment processing — permanently.** MSP never handles competition money. Collecting entry fees is entirely the organizer's responsibility (cash, Venmo, bank transfer, their own ticketing) — MSP only *records* the organizer's confirmation via "Mark paid". This is a deliberate product boundary, not a phasing decision: no Stripe for competitions, no payouts, no refund handling, no financial liability
- **Per-round capacity:** capacity is competition-level. Physical per-round capacity is implicitly the table layout
- **Ticketing / QR codes**

## Testing Strategy

- Handler tests: register under/at/over capacity → reserved vs waitlisted; organizer add bypasses capacity; promote FIFO; mark paid sets `paidAt`; cancel soft-deletes and frees a spot; re-register restores soft-deleted record with fresh `reserved` status
- Deadline tests: register before `opensAt` / after `closesAt` rejected for self-service, allowed for organizer
- Import tests: `registration_status` column parsing, invalid values report errors
- Component tests: counters, filters, check-in stamping
- Email tests: each notification renders and sends only when the player has an email
