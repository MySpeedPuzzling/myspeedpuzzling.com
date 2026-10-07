# Managed Registration

> **Status: implemented** (port of PR #136, 2026-10). The source of truth is the code; this document says why it is the
> way it is.

Without it, registration is external: `Competition.registrationLink` is a button and "I'm going" is an unlimited,
informal RSVP. Managed registration lets an organiser cap the participants, track who paid, run a waitlist and check
people in on the day - for a standalone event or one edition of a series (an edition is a `Competition`, settings are
per edition).

**Opt-in, per event.** Nothing changes for an event until its organiser switches management on: same page, same
"I'm going", same statements (pinned by `CompetitionRegistrationControllerTest`). **MySpeedPuzzling never processes
payments** - permanently: no Stripe for events, no payouts, no refunds. It only records the organiser's "paid".

## Settings - their own page and message

`manage_competition_registration` (`/en/manage-event-registration/{id}`, maintainers only via `CompetitionEditVoter`,
`noindex`), linked from the event edit page (button row) and from the participants page while managed. Message
`ChangeCompetitionRegistrationSettings` - deliberately **not** part of `EditCompetition`: the edit form, the edition form,
convert-to-series and the internal API PATCH (`UpdateCompetitionController`) dispatch `EditCompetition` and must never
switch management off or wipe the settings.

| Field (`competition`) | Notes |
|---|---|
| `registration_managed` | the switch; `false` = everything as before |
| `capacity` | null = no limit; 1-100 000 |
| `registration_opens_at`, `registration_closes_at` | instants (UTC), both optional, closes after opens |
| `registration_timezone` | the zone the window was typed in - typed like a round's start (`RoundTimezone::toInstant()`, a time skipped or repeated by daylight saving is refused), pre-selected from the event's rounds, else its/its series' country; the page shows the window again as typed, the event page with `zoned_datetime()` |
| `entry_fee_text` | free text, public, ≤ 255 |
| `payment_instructions` | free text, ≤ 2000, shown only to registered players and in their e-mail |

The page says before saving: how many people already said "I'm going" (they hold a spot when management goes on), and
how many are on the waitlist (they become going when it goes off - see below). The external `registrationLink` is
**never changed**: while managed, the read models hide it (`CompetitionEvent`, series edition cards - event page button,
JSON-LD `offers`, events list, API v1), switching off brings it back.

## Participant rows

`competition_participant` gains `registration_status` (`reserved`/`paid`/`waitlisted`, NULL = none), `registered_at`,
`paid_at`, `checked_in_at`, `organizer_note` (≤ 255, maintainers only). Cancelling is not a status - it is the existing
soft delete (self-joined rows) or disconnect (the organiser's rows), exactly as "Leave" works today.

**A row without a status holds a spot** (it reads as reserved): rows of the organiser's list, imports, and every
"I'm going" made before management went on. No backfill is written.

## One "is going" rule

`CompetitionParticipantGoing::sql($alias)` = not deleted **and not waitlisted**. Every reader of "who is going" embeds it:
`GetEventAttendance`, `GetCompetitionParticipants` (public list - the waitlist is not listed, its size is on the card),
`GetMarketplaceEvents::sqlPlayerGoing()` (marketplace F1/F2, "bringing"), `GetEventsWithSellersGoing`,
`GetCompetitionSeries` (edition counts), `GetPlayersDirectory`, `GetSuggestedPlayers`, `CountCompetitionRegistrations`.
Events without management have no status on any row, so for them the rule is "not deleted" - as before. Switching
management **off** promotes the waitlist (`ChangeCompetitionRegistrationSettingsHandler`), so a waitlisted row never
outlives management and nobody is left invisible. Statuses, payments and check-ins stay on the rows.

Organiser tooling (`GetCompetitionParticipantsForManagement`, `GetRoundTeams`), the import fingerprint and the
connections of the join flow (`getPlayerConnections`, `isPlayerSelfJoined`) read every row on purpose.

## Registering

`JoinCompetition` (one message for "I'm going" and registration, `SerializedByLock` - see Concurrency):

- **Picking your name from the organiser's list** (`participantId`) is not a new spot: always allowed (window,
  capacity and visibility do not apply - the organiser holds that spot), the row keeps its status, no e-mail. So people
  on the list can connect - and later claim results - long after registration closed.
- **A new spot** (joining yourself, again after cancelling, "not on the list"): only while the event is publicly
  visible (`IsCompetitionPubliclyVisible` - an unapproved event never collects registrations or sends MSP-branded
  payment instructions) and the window is open (`RegistrationNotOpen` with `RegistrationAvailability`
  `not_yet_open`/`closed`/`not_public`). Reserved under the capacity, **waitlisted** when full; a registration made again
  after cancelling starts fresh (restored row, not paid, not checked in, end of the queue).
- Everything is decided **before** anything changes (main's rolled-back-handler rule): the window, the visibility and
  the count come before "not on the list" lets go of the organiser's row.

Join page (`JoinCompetitionController`): an event without management runs main's flow untouched (name auto-match and
direct join on GET, `afterJoin()` F1/F2). A managed event **never registers on a GET** - not even for a name found on the
list (it is only pre-selected). The page shows the organiser's fee (labelled as theirs, "MySpeedPuzzling does not
process payments"), what happens (reserved, or the waitlist with the position) and the window; the registration is the
confirmation form's POST with a CSRF token per event (`competition-register-<id>`). After it: reserved = main's
`afterJoin()` (marketplace picker or flash); waitlisted = a flash with the position, back to the event page, no
marketplace step (not going). Every way out goes through `CompetitionDetailUrl`.

## Public card

`GetEventAttendance::forEvent()` - for a managed event **one statement** (for visitors too): spots taken, waitlist size,
the viewer's row (status, waitlist position FIFO by `registered_at, id`, self-joined or the organiser's), names left on
the list. Non-managed: main's statement for a signed-in player, none for a visitor. `_event_attendance.html.twig` renders
`_event_registration_card.html.twig` instead of the buttons when `attendance.registration` is set - the event and
edition templates are untouched. States: Register / Join the waitlist / opens {zoned date} / closed / opens once
approved (+ "Find your name" while the list has free names); registered: reserved (+ payment instructions), paid,
waitlisted #n; Change (main's rule), Cancel registration (own row) / Leave the waitlist / Leave (organiser's row - only
disconnects, as today).

## Organiser tools

Participants page (`ManageCompetitionParticipants`), **only while managed** (`registrationManaged`/`capacity` are
non-writable props from the page - no extra statement; counts come from the rows already loaded): counters, status
filter, status column with separate row actions (never part of the row's save) - Mark paid, Take back "paid", Give a
spot, Give a spot and mark paid - the first-in-line hint with one click when a spot is free, the private note in the
edit row (saved only while managed, `EditCompetitionParticipant::$changeOrganizerNote`). Every action looks the
participant up in the component's event first, `#[PostHydrate]` re-checks the maintainer, the handler checks the event
again.

Status rules (handlers, under the lock): mark paid twice = one e-mail; a waitlisted row is marked paid only together
with an explicit promotion (`MarkParticipantPaid::$promoteFromWaitlist`); promote only from the waitlist (again = no
change, no e-mail); take back "paid" only from paid; check-in only for rows holding a spot; every action refuses another
event's participant (404) and an event without management (409). Promotion is always manual - above the capacity too,
like adding someone by hand. Organiser-added participants are reserved even above the capacity.

Check-in (`/en/event-check-in/{id}`): maintainers only, 404 unless managed, `noindex` + `Cache-Control: private,
no-store`; everybody holding a spot (no waitlist), big tap targets, "Not paid" + Mark paid, progress.

## Import / export

The export of a **managed** event appends `registration_status`, `paid_at`, `checked_in_at` (event's zone) after
`participant_id`; every other export is byte-for-byte as before. The import knows the three columns and reads nothing
from them (no warning; "Not imported" on the preview) - statuses change on the site, never through a file, so an
exported sheet imported back changes nothing and never wipes a payment or a check-in. Status columns in the
spreadsheet's People tab: follow-up (participants-spreadsheet.md D10).

## Concurrency

`CompetitionParticipantsLock::key()` - the participant import's key - is taken by `ApplyParticipantImport`,
`JoinCompetition`, `LeaveCompetition`, `AddCompetitionParticipant`, `MarkParticipantPaid`, `UnmarkParticipantPaid`,
`PromoteParticipantFromWaitlist`, `CheckInParticipant`, `UndoParticipantCheckIn` and
`ChangeCompetitionRegistrationSettings`. The lock is held until commit (`LockUntilCommittedMiddleware`), so two
registrations for the last spot never both get it.

## E-mails

`CompetitionRegistrationMailer`: reserved, waitlisted (with the position), paid, promoted - to the player connected to
the row, in the player's language, address via `PlayerAccountEmail`, button to `CompetitionDetailUrl::absoluteOf()` in
that language (an edition's own page). Main's conventions (`email_document`, preheader, generated text part,
`X-Transport: transactional`). Everything the organiser typed is escaped (`competitionName|e` before the translation's
markup, fee and instructions autoescaped / `nl2br`), payment instructions are labelled as the organiser's with the
"does not process payments" line. Texts: `competition_registration.*` in `emails.*.yml`.

## Edge cases

- Management **on**: rows already going hold a spot (reserved), possibly above the capacity - the counter shows it.
- Management **off**: the waitlist becomes going; statuses stay for the next time.
- Capacity lowered below the spots taken: nothing changes; new registrations wait.
- Account deletion: the participant rows behave as today.

## Follow-ups (docs/TODO.md)

Statuses in the spreadsheet People tab, maintainer notifications (new registration, a paid registration cancelled), a
payment deadline / automatic release, verified e-mail before registering (capacity abuse), offline-tolerant check-in,
JSON-LD `offers` with availability, an internal "Register" button on series edition cards, the no-preview import message
(`ImportCompetitionParticipants`) under the same lock.
