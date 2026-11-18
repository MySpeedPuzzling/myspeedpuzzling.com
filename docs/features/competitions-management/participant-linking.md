# Participant linking: connecting competition participants to MySpeedPuzzling accounts

Status: **design of record** (2026-11-17, architect pass) - nothing below is built yet. Implementation agents are pointed
at sections by number. Builds on [participants.md](participants.md) (self-join, "I'm going", import rules),
[participant-import-preview.md](participant-import-preview.md), [participants-spreadsheet.md](participants-spreadsheet.md)
(the People tab, `ApplyParticipantSheetChanges`), [registration.md](registration.md),
[competitor-pages.md](competitor-pages.md) (QR tags, competitor page, time proposals) and
[../online-competitions/README.md](../online-competitions/README.md) §5.4 (personal links).

Jan's request (2026-11-17, verbatim):

> one more usecase we should optimize are the participants and connecting "participant" and myspeedpuzzling account -
> the organizers will usually import list of participants and it can either contain myspeedpuzzling player id or will
> not and the users have to connect themselves before the competition (or even during it)

Words used here: a **participant** is a `competition_participant` row (the organiser's record of a person at one event
or edition); a **link** is `competition_participant.player_id` set (code and older docs say "connect"); a **claim** is a
player saying "this participant is me" without proof; a **claim link** is a personal secret URL the organiser hands to
one person; a **request** is a claim waiting for the organiser.

## §0a Review amendments (binding - they override the sections below where they differ)

Independent design review 2026-11-17 (2 blockers, 7 should-fix, cuts). The orchestrator's decisions:

| # | Amendment | Affects |
|---|---|---|
| R1 | **Approving a claim / dispute releases the claimant's other rows first.** Approve = one atomic group: `CompetitionSelfJoin::releaseOtherParticipants()` rules (own self-joined row soft-deleted unless it holds official data, organiser's row only unlinked; `releases_own_registration` honoured) then link. Never refused with `player_linked_elsewhere` because the claimant tapped "I'm going" before (`SheetPlanRun.php:500-503, :762`). Test: claimant who already has their own row. | §5.3, L2 |
| R2 | **Suggestions never pre-tick a name-based candidate.** Tier 1 "Joined by themselves" is a name match too (profile names are free text), so nothing is pre-ticked except tier 2 "Linked at another date" with a unique candidate and a matching country. The dialog shows #code, country and the date the person joined. | D9, §7.2, L5 |
| R3 | **Late links that are proof may propose.** `TimeProposalOwnership` refuses a link made after the round's first stopwatch start only when `connected_via` is `claim` or NULL (old rows); `claim_link`, `claim_approved`, `organiser`, `import`, `online_link`, `self_join` made during the event may propose ("connect even during it"). | §10.1, L2 |
| R4 | **Claim tokens never reach logs/Sentry**: `/claim/<token>` and `/<locale>/claim/<token>` added to `OnlineTokenRedactor` (+ its Sentry scrubber, `RedactOnlineTokensProcessor`), pinned by a test. | §6.5, L4a |
| R5 | **A `#code` that changed owner is refused** (`player_code_change`: a code that belonged to another player → `code_changed_owner`); the preview warns when the file's name and the profile's name keys differ ("name differs"). | §7.3, L1b |
| R6 | **An unlink clears the claim token only when the link was made with it** (`connected_via = claim_link`); otherwise the printed slip keeps working. "New link" rotates on demand. | D5, L1a/L4a |
| R7 | **The tier-1 merge is refused when the self-joined row is registered/paid/waitlisted on a managed event** (or holds entries/official data) - shown as "Merge by hand" with the reason. | §5.3, L5 |
| R8 | **Invitations (L4b) are their own e-mail type with complaint → suppression**, 1 per person per 24 h + one-click refusal (the 3-total / 1,000-per-day counters are cut). L4b ships last. | D15, §5.5 |
| R9 | D3's "online" = the series-aware rule (`GetEventAttendance::mayBeOnline`), not `Competition::isOnline`. Competitor page "This is me": shown whenever the viewer is signed in and not on that tag; the handler decides the switch (no extra statement). | D3, §6.4 |
| R10 | **Cuts**: `found_via` / `ClaimSource` (requests keep no source), `claim_token_created_at`, `ParticipantLinkSuperseded` (a superseded request reads as the neutral `ParticipantLinkDeclined`), suggestion tier 4 "Name only", the person editor's "Profile history" UI (the log is kept and written), `claim_invitation_count` + caps. | §3, §7, §8.4, §9.2 |
| R11 | **D14 narrowed**: the join GET never *links* on an event whose policy is `approval` (preselects like managed events); open-policy events keep today's one-tap GET (no new taps on the 5 GET entry points). | D14, §6.2, L3 |
| R12 | NITs: no `ParticipantListedYou` when the import only matched a row the player self-joined or a re-import changed nothing; `?participant=` uses the same going/active filter; `connect()` keeps a default method argument (tests call it with two); `ParticipantLinkCoverageTest` scans `src/` only; the `player` name-key index is built in the migration with a note (plain `CREATE INDEX`, ~seconds on prod). | various |
| R13 | **Packages re-cut**: L1a foundation (`ParticipantLinks`, `connected_via`, log, call sites, account deletion, R6) → L1b import player references (R5) → L2 policy + requests + approvals (R1, R3, R9) → L4a claim links + slips + CSV + desk QR (R4) → L3 find yourself (R11) → L5 suggestions (R2, R7) → L4b e-mail invitations (R8). Jan's §13 answers by the orchestrator: (1) as designed, (2) yes with R12, (3) build L4b last with R8, (4) yes, signed-in rows only. |  §12 |


---

## §1 What exists today (and the gaps)

### §1.1 The participant row

`src/Entity/CompetitionParticipant.php`:
- `player` + `connectedAt` (`:25-31`), written only by `connect()` / `disconnect()` (`:109-131`); `isConnectedTo()` keeps
  the moment on a re-pick (`:117-124`, needed by time proposals).
- `externalId`, `remoteId` (legacy), `source` (`ParticipantSource`: self_joined / imported / manual, `:104-105`),
  `deletedAt` (soft delete), registration columns, `organizerNote` (private).
- `onlineToken` (`:66-73`, 128-bit base58, `src/Value/OnlineToken.php`) + `onlineLinkConfirmedAt` (`:79-88`): the online
  personal link and "the connected player showed the link is theirs"; `connect()` clears the confirmation (`:113-114`).
- **No e-mail field.** No record of *how* or *by whom* a row was linked (only `connectedAt`). No log.

### §1.2 Item 1 - import with ids

- Column `msp_player_id` (`ParticipantImportField::PlayerId`, `src/Value/ParticipantImportField.php:22`), header aliases
  `msp player id` / `msp id` (`src/Value/ColumnMapping.php:47-48`); the header `player` means **Name** (`:27`).
- `PlanBuilder` accepts **only a UUID** (`src/Services/ParticipantImport/Plan/PlanBuilder.php:252-261`: `invalid_player_id`,
  `unknown_player_id`); existence read by `SiteSnapshotReader` (`:164-176`, `SELECT id FROM player WHERE id IN`). No
  `#code`, no profile URL.
- Matching priority 1 is the player id (`PlanBuilder::findMatch()` `:1302-1310`). A matched participant already linked to
  another player keeps it (`player_id_kept`, `:342-344`); a player linked to another active participant is never linked
  again (`canConnect()` → `player_linked_elsewhere`, `:1398-1413`, rule in `ParticipantRules::playerLinkedTo()`). The
  **same id twice in a file**: the first row links, the second gets `player_linked_elsewhere` - a guess (first wins).
- An empty cell never clears a link, in both modes (import-preview D14); full sync never unlinks.
- The preview row shows a field diff (`msp_player_id` old → new as UUIDs) - no name, no `#CODE`.
- `docs/TODO.md` "Participant import": "A connected player the file wants to change … is refused … offer a way to change
  it on purpose". Jan's opt-in rule (`participants.md:216`): ids only for players who opted in themselves.

**Gaps:** no code/URL input; no readable "linked to #CODE - Name" in the preview; duplicate id in the file is resolved by
order; the preview cannot say a kept link was made by the player themselves (nothing records it).

### §1.3 Item 2 - import without ids

- Nothing suggests anything. The only name matching is the player's own "I'm going":
  `GetCompetitionParticipants::findNotConnectedParticipantMatchingName()` (`src/Query/GetCompetitionParticipants.php:332`,
  `immutable_unaccent` + lower + whitespace, country compatible, exactly one).
- A self-joined row and an imported row of the same person stay two rows (sheet "Not built": "merging a self-joined row
  into an imported one", `participants-spreadsheet.md` §13).
- The sheet's profile search (`src/Controller/ParticipantsSheet/ParticipantsSheetPlayerSearchController.php`) finds
  anybody incl. players the organiser blocked and private players by exact code (O9, `participants-spreadsheet.md:796`).

**Gap:** the organiser links 800 people one by one or not at all.

### §1.4 Item 3 - self-connect

- "I'm going" → `join_competition` (`src/Controller/JoinCompetitionController.php`). **Unmanaged events: a GET writes** -
  a unique name match is connected on the GET (`:115-125`), and with nobody unlinked the GET self-joins (`:121`). Managed
  events never write on GET and only preselect the match (`:237-240`).
- Picker = every unlinked going participant (`getNotConnectedParticipants()`, `:211`) - TomSelect of names.
- `JoinCompetitionHandler` (`src/MessageHandler/JoinCompetitionHandler.php:67-89`): a pick connects at once if the row
  is unlinked; releases the player's other rows (`CompetitionSelfJoin::releaseOtherParticipants()`,
  `src/Services/CompetitionSelfJoin.php:91-109`).
- Event page: `_event_attendance.html.twig` - "I'm going" / "You're going ✓" + Change + Leave; state from
  `GetEventAttendance` (one statement for a signed-in viewer, `src/Query/GetEventAttendance.php:62-105`; it already reads
  the personal link in the same statement, `:107-142`). No "Is this you?".
- Online events: the personal link connects a signed-in player when unambiguous (`OnlineConnectRules`,
  `src/Services/OnlineRounds/OnlineConnectRules.php:13-58`; `CompeteConnectController` `:76-101` dispatches
  `ConnectCompetitionParticipant` + `ConfirmOnlineLink`) - the only token-based link today.
- Name tags: the person tag's QR is `live_results_scan` with the **participant id** (`src/Services/NameTagQrCode.php`
  `url()`) - not a secret. A non-organiser's scan lands on the competitor page (pages on) or the event page
  (`src/Services/CompetitionTagLanding.php:81-102`). The competitor page has no "This is me".
- The public participants list (`src/Component/CompetitionParticipants.php`) lists unlinked names, no action.

**Gaps:** finding yourself means opening the picker; nothing on the event page, the list, the competitor page or a
scanned tag; the organiser cannot hand a person a link that proves it is them (except on online events); no overview of
who is linked.

### §1.5 Item 4 - abuse

- Anybody signed in can claim any unlinked participant, at once, any time (§1.4). The organiser learns nothing (TODO
  "Managed registration": notify maintainers about "a listed name picked on a managed event").
- Time proposals refuse a link newer than the round's first stopwatch start (`TimeProposalOwnership`,
  `src/Services/CompetitionToolkit/TimeProposalOwnership.php:18-24, 81-86`) - a claim the evening before is not refused.
- A link also gives: the profile's identity next to the organiser's name on public round results / standings /
  competitor pages / displays (`OfficialEntrantsView`), `OfficialResultPublished` notifications, the competitor page's
  "own" view (confirmed time before publishing), the "Competitor" achievement (`src/Query/AchievementSqlRules.php:61-83`
  counts `competition_participant` rows), registration e-mails with payment instructions (`CompetitionRegistrationMailer`).
  A row claimed from the list never hands out the online personal link (`onlineLinkConfirmedAt`) - already safe.
- Organisers may link anybody without consent (`participants.md:152`); the player's only recourse is "Disconnect".
- The event-results claim feature (`docs/features/event-results-import/README.md` "Gates") has the patterns worth reusing:
  an append-only log without foreign keys (`src/Entity/ResultClaimLog.php`), "Not on our team" by the organiser, a
  player blocked after repeated removals, neutral `not_available` wording.

### §1.6 Item 5 - privacy

- Sheet identity follows the viewer's blocks and `PrivateProfileAccess` (O9). Tags print `#CODE` only for public profiles.
- Account deletion: `DeletePlayerHandler` sets `competition_participant.player_id = NULL` (`:552`) but leaves
  `connected_at` / `online_link_confirmed_at`, and the deleted player's **self-joined rows keep their profile name** and
  then appear in the "I'm going" picker as unclaimed names anybody can take - a bug this design fixes (§11).

### §1.7 Item 6 - organiser overview

- The sheet's People tab shows the profile column (link / unlink by search) and badges "Joined by themselves"; no
  "Not linked" filter, no counts, no actions to reach unlinked people. The toolkit hub
  (`templates/competition_toolkit/hub.html.twig:31-35`) has five cards, none about participants. The online links page
  (`OnlineLinksController`) is the model for copy link / CSV / QR sheet.

---

## §2 Decisions

Defaults marked **(default)**. "Untouched event" = an organiser who changes nothing.

| # | Decision | Why |
|---|---|---|
| D1 | **One link path.** Every link and unlink goes through one service `ParticipantLinks` (§4) that records *how* (`connected_via`) and writes an append-only log row. `connect()`/`disconnect()` are called nowhere else (coverage test). | Today 6 call sites (§4.1) each decide alone; abuse handling, notifications and the import's "linked by the player" need the method and the history. |
| D2 | **Three kinds of link**: *proof* (claim link token, online personal link, organiser action incl. import and suggestions, own self-join) - connects at once under every policy; *claim* (list pick, name match, "This is me" anywhere) - follows the event's claim policy (D3); *dispute* (a claim on a row linked to somebody else) - always a request. | The token is the organiser's proof; a name is not. |
| D3 | **Claim policy per event**, `competition.participant_claims`: `open` (claims link at once - today's behaviour) / `approval` (claims wait for the organiser). **NULL = automatic (default)**: `approval` while time proposals are effective (`competitor_pages AND time_proposals AND NOT is_online`), else `open`. Shown as a three-way radio on a new hub card (§7.5); no new question anywhere else. | Smart forms: the only events where a pre-start claim buys a cheat (proposing someone else's time) are those with proposals on; the organiser who switched proposals on already opted into anti-cheat. Casual events keep one-tap claiming. **Default change:** events with time proposals on (toolkit, a handful) - claims now wait. |
| D4 | **A pending request is not a link.** Requests live in their own table (§3.3); `player_id` stays NULL until approved, so no reader changes and a pending claim unlocks nothing (§2 D12). | Every identity reader joins `player_id`; a "provisional link" would touch ~40 queries. |
| D5 | **Claim links**: a personal secret per participant, `claim_token` (the `OnlineToken` format, 128 bits base58), URL `/claim/{token}` (+ `/{_locale}/claim/{token}`). Made lazily for unlinked people when the organiser copies / exports / prints / e-mails (the `OnlineLinkList` precedent). Connects at once under every policy. **Every unlink clears the token** (a used link can never link a second person); "New link" rotates it. On an event with online timing the overview hands out the **online personal link** instead (it already links and confirms, §5.6). | One token per person, revocable, never reusable by somebody else (item 5). |
| D6 | **The printed name tag never carries the claim.** Its QR is the public participant id worn on a lanyard. Claim links are printed on separate **claim slips** (new print kind `claims`, unlinked people only) handed out with the badge, shown on the check-in desk as a QR on the organiser's phone, sent by e-mail or copied. A *scan* of the person tag by a signed-in non-organiser offers "Is this you?" as a **claim** (policy applies). | Anybody can photograph a badge; a claim slip is handed to one person. Works in every QR mode (`entrant` / `table` / `round_entry` / none). |
| D7 | **Import accepts a player reference**: UUID, `#code` / `code`, or a profile URL (any host path ending in the player UUID). Resolved to a player id in `SiteSnapshotReader`. The same player on two rows of one file links **neither** row (`player_in_file_twice`, both rows reported) - never a guess. Other import rules unchanged: a file never replaces or clears a link (`player_id_kept`, now saying when the player linked themselves), full sync never unlinks. | Item 1; organisers collect the `#code` at registration, not UUIDs. |
| D8 | **Import with ids = the organiser's statement** (linked at once, `connected_via = import`) - the player gets a notification "You are on the participant list of X - Not me?" (D11). Jan's opt-in rule stays in the help text: put a code in the file only when the player gave it to you. | Consent is the organiser's responsibility; the player keeps a one-tap way out. |
| D9 | **Suggestions, never auto-links.** For unlinked participants the sheet offers candidates (§8.4) in four tiers: *Joined by themselves* (a self-joined row of the same person → merge), *Linked at another date* (same series/organization, same name key), *Name and country*, *Name only*. Accepting = a sheet `player` change (three-way, undoable). Bulk dialog pre-ticks only the first two tiers and only unique candidates. | Item 2; the two pre-ticked tiers are the player's own acts, not name guesses. |
| D10 | **Suggestion privacy**: a candidate is shown only if the organiser may see the profile (`PrivateProfileAccess`) and has not blocked it (`HiddenPlayers` for the viewer), except the *Joined by themselves* tier (the row is already in the sheet). E-mail addresses are **never** matched against accounts and nothing ever says whether an address has an account. | Item 5. |
| D11 | **"Not me" / "Not this person"**: the player can unlink any row (existing "Leave"/"Disconnect" + "Not me" from the notification); the organiser's unlink in the sheet gets a "Not this person" choice. Both are remembered as a **refused pair** (participant × player) in the log: that player can no longer claim that row, is never suggested for it, and the import does not link it (`link_refused_before`); the organiser can still link explicitly in the sheet. **Three refusals of one player's claims in one event** (declined requests + "Not this person") end their self-claims there (tokens still work). | Item 4 - the event-results claim pattern (`REMOVALS_UNTIL_BLOCKED`) scoped to one event. |
| D12 | **What a link unlocks** (pending unlocks nothing): table in §10.1. No new power is added to a link by this design. | Item 4. |
| D13 | **Races are decided under `CompetitionParticipantsLock`**: first link wins; an organiser link or another approval **supersedes** pending requests on that row (and the linked player's other pending request in the event); a claim on a linked row becomes a dispute request. | Item 4 "race with the organiser". |
| D14 | **The join flow's GET never writes** (unmanaged events too): a unique name match is preselected, "I'm going" on the event page becomes a POST button. | Default change for everybody - a prefetch or a double-opened tab no longer links or joins; one tap stays one tap from the event page. |
| D15 | **Participant e-mail** `competition_participant.email` (organiser's private data, like the note): import column, sheet column; used only for claim invitations. Erased 60 days after the event's last day by a daily cron. Invitations: same text for everybody (no account hint), at most one per person per 24 h and 3 in total, suppression list honoured, one-click "no more invitations for this event". | Item 3 "send each unlinked person their claim link"; item 5. |
| D16 | **Organiser notifications** only for requests (approval policy and disputes): one in-app notification per organiser per event while unread ("People are waiting for you to confirm…"). No notification per open-policy claim; the hub card and sheet filter show "Linked by themselves (N)". | Noise vs. signal: 800 claims must not be 800 notifications. |
| D17 | **No feature flag**, everything additive; an untouched event without time proposals behaves as today except D14 (GET), the "Is this you?" card (new surface, a shortcut to today's flow) and the D8 notification after an organiser link. | Jan, 2026-11-17. |

---

## §3 Data model

One generated migration (`doctrine:migrations:diff`), additive, plus one custom index (§3.5).

### §3.1 `competition_participant` - new columns

| Column | Type | Meaning |
|---|---|---|
| `connected_via` | `varchar(20)` NULL, enum `ParticipantLinkMethod` | How the current link was made; NULL = unlinked or linked before this feature. Set by `ParticipantLinks` only; cleared on unlink. |
| `claim_token` | `varchar(32)` NULL, **unique** | Claim link secret (`OnlineToken::generate()`). NULL = none yet / cleared by an unlink. |
| `claim_token_created_at` | `timestamp` NULL | For "New link" and the CSV. |
| `email` | `varchar(254)` NULL | Organiser's address for the person (private). Canonicalised like `UserAccount::canonicalizeEmail()`. |
| `claim_invited_at` | `timestamp` NULL | Last invitation sent. |
| `claim_invitation_count` | `smallint` default 0 | Invitations sent (cap 3). |
| `claim_invitations_refused_at` | `timestamp` NULL | One-click "no more invitations for this event". |

`ParticipantLinkMethod` (`src/Value`): `self_join` (own new row), `claim` (open policy, from a name), `claim_approved`
(request approved), `claim_link` (claim token), `online_link` (compete_connect), `organiser` (sheet, accepted suggestion,
approved dispute), `import`.

Entity methods (all `#[Immutable(PRIVATE_WRITE_SCOPE)]` columns, written through named methods):
- `connect(Player, DateTimeImmutable, ParticipantLinkMethod)` - the existing method gains the method argument; keeps
  clearing `onlineLinkConfirmedAt`.
- `disconnect()` - also clears `connected_via` and `claim_token` (D5).
- `assignClaimToken(string $token, DateTimeImmutable)`, `updateEmail(?string)`, `recordClaimInvitation(DateTimeImmutable)`,
  `refuseClaimInvitations(DateTimeImmutable)`, `mayBeInvited(DateTimeImmutable $now): bool` (email set, unlinked, active,
  not refused, count < 3, last > 24 h ago).

### §3.2 `competition` - new column

`participant_claims varchar(20) NULL` enum `ParticipantClaimPolicy` (`open`, `approval`). `Competition::
participantClaimPolicy(): ParticipantClaimPolicy` resolves NULL (D3, reads `competitorPageSettings()` and `isOnline`).
`Competition::changeParticipantClaimPolicy(?ParticipantClaimPolicy)`. **Duplicate copies it** (event setting, like the
competitor-page settings - `DuplicateCompetitionHandler`).

### §3.3 `participant_link_request` (new entity `ParticipantLinkRequest`)

| Column | Notes |
|---|---|
| `id` uuid | `Uuid::uuid7()` |
| `competition_id` FK → competition, CASCADE | |
| `participant_id` FK → competition_participant, CASCADE | |
| `player_id` FK → player, CASCADE | the claimant (account deletion removes their requests) |
| `status` enum `LinkRequestStatus` | `pending` / `approved` / `declined` / `withdrawn` / `superseded` |
| `kind` enum | `claim` (unlinked row, approval policy) / `dispute` (row linked to another account) |
| `found_via` enum `ClaimSource` | `list` / `name_match` / `participant_list` / `competitor_page` / `tag_scan` / `claim_link` (dispute from a used token) |
| `player_name`, `player_code` | snapshot shown to the organiser (D10 note below) |
| `releases_own_registration` bool | managed events: the player's explicit yes to giving up their own (paid) registration, captured at the claim (`join_competition` `release_paid`) |
| `created_at`, `decided_at` NULL, `decided_by_player_id` NULL (FK SET NULL) | |

Indexes: (`competition_id`, `status`), (`participant_id`, `status`), (`player_id`, `competition_id`). "One pending request
per player per event" is enforced by the handler under the event lock (a new claim supersedes the player's older one).

**Identity snapshot:** the claim screen says "The organisers will see your name and #code to confirm it's you" -
the claimant shows themselves to the organiser on purpose, so the request list reads the snapshot instead of the masked
profile. It is the one documented exemption in `PrivateProfileQueryCoverageTest` (reason: consented disclosure to that
event's organisers only). Blocks: the organiser's own blocks do not hide a request on their own event (blocklist rule 7,
organiser tooling) - otherwise a blocked player's legitimate claim could never be decided.

### §3.4 `participant_link_log` (new entity `ParticipantLinkLog`, append-only, no foreign keys)

Like `result_claim_log`: copied ids and names outlive the participant, the player and the event.

`id`, `competition_id`, `participant_id`, `participant_name` (copy), `player_id`, `player_name` (copy; erased to `''` on
account deletion), `action` (`ParticipantLinkAction`: `linked`, `unlinked`, `requested`, `approved`, `declined`,
`withdrawn`, `superseded`, `not_me`, `not_this_person`, `account_deleted`, `invitation_sent`), `method`
(`ParticipantLinkMethod`, null for non-link actions), `by_player_id` (null = system), `at`. Indexes
(`competition_id`, `participant_id`), (`player_id`, `action`).

**Refused pairs** = log rows with `action IN (declined, not_me, not_this_person)` for (participant, player), read by
`GetRefusedParticipantLinks::forCompetition()` (one statement) and `::count(competitionId, playerId)` for D11's limit of 3.
Kept forever (small); a decline is not undone by time. The organiser's explicit sheet link ignores refusals.

### §3.5 Index for name keys

`custom_player_name_key` (btree) on `player (ParticipantNameKey SQL expression of name)` - the SQL twin of
`ParticipantNameKey::of()` (`src/Services/ParticipantImport/Plan/ParticipantNameKey.php`: lower, whitespace collapsed,
diacritics via `immutable_unaccent()`, `’ ‘ ʼ ´ \`` → `'`, `‐ – —` → `-`). New `ParticipantNameKey::sql(string $column)`
with a parity test (PHP vs SQL over the import's name-key cases). Created in the migration by hand, mirrored in
`tests/bootstrap.php` if needed, registered in `docs/database-indexes.md` (`.claude/rules/database.md`). The existing
`findNotConnectedParticipantMatchingName()` switches to the same expression (today's simpler folding misses curly
apostrophes).

### §3.6 Foreign-key coverage

New FKs to `competition`, `competition_participant`, `player` need lines in `ConvertCompetitionForeignKeyCoverageTest`
(convert-to-series: requests follow the event row - nothing to move, the edition keeps them) and `DeleteCompetition`
(cascade). Nothing references `puzzle`.

---

## §4 The one link path - `ParticipantLinks`

`src/Services/ParticipantLinking/ParticipantLinks.php` (readonly, persist only, called by handlers under
`CompetitionParticipantsLock`):

```php
link(CompetitionParticipant $p, Player $player, ParticipantLinkMethod $via, ?Player $by, DateTimeImmutable $now): void
unlink(CompetitionParticipant $p, ParticipantLinkAction $why /* unlinked|not_me|not_this_person|account_deleted */, ?Player $by, DateTimeImmutable $now): void
request(CompetitionParticipant $p, Player $player, RequestKind $kind, ClaimSource $via, bool $releasesOwnRegistration, DateTimeImmutable $now): ParticipantLinkRequest
decide(ParticipantLinkRequest $r, bool $approve, Player $by, DateTimeImmutable $now): void
participantRemoved(CompetitionParticipant $p, DateTimeImmutable $now): void   // supersedes its pending requests
```

`link()`:
1. `$p->connect($player, $now, $via)` unless `$p->isConnectedTo($player)` (keeps `connectedAt` - P1F).
2. Log `linked` + method.
3. Pending requests on `$p` → `superseded` (claimants notified, §9.2), the player's own pending request in the event
   (another row) → `superseded` silently, a request by `$player` for `$p` → `approved`.
4. Method `organiser` / `import` and `$by` ≠ the player → notification `ParticipantListedYou` (§9.2), only while the event
   is publicly visible (a draft's links are not announced - P18 pattern).
5. Records a "sheet changed" signal for the Mercure push after commit (§5.8).

`unlink()`: `$p->disconnect()` (clears token + method), log the action. Releasing the player's *other* rows on a switch
stays `CompetitionSelfJoin::releaseOtherParticipants()`, which calls `unlink()` / soft-delete.

### §4.1 Call sites moved onto it

| Today | Method |
|---|---|
| `CompetitionSelfJoin::takeOwnRow()` (`:51, :69`) | `self_join` |
| `CompetitionSelfJoin::releaseOtherParticipants()` (`:106`), `LeaveCompetitionHandler` (`:41`) | `unlink(unlinked / not_me)` |
| `JoinCompetitionHandler` pick branch (`:84-86`) | via `ParticipantClaims` (§5.1) |
| `ConnectCompetitionParticipantHandler` (`:61-79`) | `online_link` (its only dispatcher is `compete_connect`) |
| `ParticipantImportApplier` (`:155, :161`) | sheet changes: `organiser` / `unlink(unlinked or not_this_person)`; import: `import` |
| `DeletePlayerHandler` (`:552`, raw SQL) | set-based equivalent, §11 |

Guard: `ParticipantLinkCoverageTest` - `->connect(` / `->disconnect()` on a `CompetitionParticipant` only inside
`ParticipantLinks` (token scan of `src/`, like `SolvingTimeDeletionCoverageTest`).

---

## §5 Messages and handlers

Every message below that writes a participant, a request or a token implements `SerializedByLock` with
`CompetitionParticipantsLock::key($competitionId)` (`SerializedByLockMessagesTest`). Handlers validate everything before
the first change (rolled-back-handler rule, `participants.md` "Step 2").

### §5.1 Claims - `ParticipantClaims` service + messages

`ParticipantClaims::claim(Competition, Player, CompetitionParticipant, ClaimSource, bool $releasesOwnRegistration):
ClaimOutcome` - the one policy decision, used by:

- `JoinCompetition` (existing, `participantId` set = list pick / name match / "This is me" on the list). Its pick branch
  (`JoinCompetitionHandler:67-89`) is replaced by `ParticipantClaims::claim()`. The message gains `?ClaimSource $foundVia`
  (default `list`).
- `ClaimParticipant(competitionId, playerId, participantId, foundVia)` - from the competitor page and the tag-scan card
  (same handler body; a separate message keeps the managed-registration flow of `JoinCompetition` out of it).

Outcomes (`ClaimOutcome` enum, the controller turns them into a flash / page state):

| Situation (checked in this order) | Outcome |
|---|---|
| Event not publicly visible | refused (`CompetitionNotFound`, as today) |
| Participant removed / of another event | refused `not_available` |
| Already linked to this player | `already_yours` (nothing changes, moment kept) |
| Refused pair (D11) or the player's 3-refusal limit | `ask_organiser` (neutral: "Please ask the organiser to link you") |
| Linked to another player | dispute request → `dispute_sent` |
| Policy `open` | release the player's other rows, `link(claim)` → `linked` |
| Policy `approval` | `request(claim)` (supersedes the player's older request) → `requested` |

Managed registration: a pick that releases the player's own registration still needs `release_paid` (existing
`JoinCompetitionController:169-178`); under `approval` the release happens at approval (`releases_own_registration`).

`WithdrawParticipantLinkRequest(competitionId, playerId)` - the claimant takes their pending request back.

### §5.2 Claim links

- `ClaimParticipantByToken(token, playerId, bool $switch)` → handler: token → participant (unknown/cleared → `unknown`),
  event publicly visible, participant active. Then: linked to this player → `already_yours`; linked to another →
  `belongs_to_other` (the page offers "Tell the organiser" = `ClaimParticipant(foundVia: claim_link)` → dispute);
  the player is another active participant of the event and `$switch === false` → `is_other_participant` (page offers
  the switch); else release other rows, `link(claim_link)`, and `CompetitionSelfJoin::handOwnLink()` (the organiser's
  token is the same proof as the online link: `confirmOnlineLink` + `giveOwnLink` on online events). **Never consults the
  claim policy or refused pairs** (the organiser handed it).
- `GenerateParticipantClaimTokens(competitionId, ?list<participantId>)` - every active unlinked participant without a
  token (or the listed ones); returns the count. Dispatched when the organiser copies a link, downloads the CSV, opens the
  claim-slip print page or sends invitations (precedent: `OnlineLinkList` gives missing online links when its page opens).
- `RegenerateParticipantClaimToken(competitionId, participantId)` - "New link".

### §5.3 Organiser decisions - through the sheet

The sheet stays the one write path for organisers (`ApplyParticipantSheetChanges`, `participants-spreadsheet.md` §6):
- **Approve** = the existing `player` change `{participant, from: null, to: claimantId}` - `ParticipantLinks::link()`
  marks the matching request approved (§4 step 3) with method `claim_approved` when such a request exists (else
  `organiser`). A dispute's approve = `{from: currentPlayerId, to: claimantId}` (the row's current link is unlinked with
  `not_this_person`).
- **Decline** = new `SheetChangeOp::DeclineLinkRequest` `{request, from: 'pending'}` → `decide(false)`.
- **Not this person** = the `player` change to `null` with `notThem: true` → `unlink(not_this_person)`.
- **Accept suggestion** = `player` change; *Joined by themselves* = a group `[remove(selfJoinedRow), player(imported,
  null → playerId)]` in this order (one atomic group; offered only when the self-joined row has no round entry and no
  official data - `OfficialResultsGuard`).
- Bulk approve / accept = one group per person (a refusal of one does not stop the rest; refused ones are listed -
  existing bulk pattern).

### §5.4 Settings

`ChangeParticipantClaimPolicy(competitionId, ?policy)` - the competition row only (like `ChangeCompetitorPageSettings`,
no participants lock; listed on `SerializedByLockMessagesTest`'s allow-list with that reason). Switching to `open` does
not approve pending requests (the organiser decides them; a claimant who claims again is linked at once).

### §5.5 E-mail

- `SendParticipantClaimInvitations(competitionId, list<participantId>, byPlayerId)` (lock): generates missing tokens,
  keeps the people who `mayBeInvited()` and are not suppressed (`EmailSuppressionRepository`), caps 1,000 per event per
  day, records `recordClaimInvitation()` + log `invitation_sent`, then dispatches `SendParticipantClaimInvitation(id)`
  per person (routed **async**, `doctrine_transaction` as usual). Returns sent / skipped-by-reason counts.
- `SendParticipantClaimInvitation(participantId)` (async, no participant write - reads and sends): re-checks unlinked +
  e-mail unchanged + not suppressed, renders §9.1, sends. A failure is logged by the transport as usual.
- `RefuseParticipantClaimInvitations(participantId)` (lock) - the signed one-click unsubscribe.
- Cron `myspeedpuzzling:erase-participant-emails` (daily, lily crontab row - added by the implementer per
  `feedback_handle_lily_crons_myself`) → `EraseParticipantEmails` (lock per event, bulk SQL with a comment: a genuine
  bulk over rows not loaded one by one - CLAUDE.md exception) for events whose last day is > 60 days ago.

### §5.6 Online events

No new message. The overview (§7) shows the **personal link** (`OnlineLinkUrl`) instead of a claim link when a round of
the event uses online timing; `compete_connect` keeps `OnlineConnectRules`, now through `ParticipantLinks` (`online_link`).
A claim token made before online timing was switched on keeps working (§5.2 hands the online link too).

### §5.7 Account deletion

`DeletePlayerHandler` (raw SQL section, `:546-560`), §11.

### §5.8 Live updates

Every handler above that changes a link or a request publishes the sheet's private topic `/competition-participants/{id}`
(`participants_sheet.changed` + version, O12) after the commit - the `AfterResponse` pattern of the save/propose
controllers. Closes the TODO "self join/leave do not publish the sheet's live topic" for link changes.

---

## §6 Player-side pages

All texts in 6 locales; neutral wording (`feedback_neutral_wording`); every POST answers 303 (Turbo rule); stateless
forms carry CSRF ids per event.

### §6.1 Event page - "Is this you?" card

Where: `_event_attendance.html.twig` (event + edition pages, "Taking part" `#taking-part` and the header's link to it).
When: signed-in viewer, not going, has a profile name, event publicly visible, exactly **one** active unlinked
participant with the viewer's name key and a compatible country, not a refused pair, the player under the 3-refusal limit.
Data: **in `GetEventAttendance`'s existing statement** (+0 statements; `DetailPagesQueryBudgetTest` unchanged) - columns
`match_participant_id`, `match_participant_name`, `match_participant_country`, `pending_request_participant_name`.

Phone wireframe (in words): a bordered card replacing the "I'm going" button row -
"**Is this you?**" / flag + "Jan Mikeš" (the organiser's spelling) / small "On the organiser's participant list" /
two full-width 44 px buttons stacked: [Yes, that's me] (POST `join_competition` `participant_id` + `found_via=name_match`)
and [No - I'm going as myself] (POST `self_join=1`, today's "Join as"), then a link "Find my name on the list" (the
picker page). On a managed event the card sits above the registration card and posts the same picker form (CSRF
`REGISTER_CSRF_PREFIX`).

States after it:
- `open`: linked → "You're going ✓" (as today) + flash "Linked to Jan Mikeš on the participant list".
- `approval`: card turns into "**Waiting for the organiser** to confirm you are Jan Mikeš" + [Take back] (POST
  `withdraw_participant_link_request`). The viewer is not going yet (D4).
- several matches: no card; the button reads "I'm going" and the picker opens with a hint "2 people on the list are
  called Jan Mikeš - pick yours".

### §6.2 The join flow (`join_competition`)

- GET never writes (D14): an unmanaged event with a unique match shows the picker with it preselected (exactly the
  managed path, `JoinCompetitionController:237-240`); with nobody unlinked the page is a one-button confirm "I'm going".
  The event page's "I'm going" is a POST button (`self_join=1`) so the usual path stays one tap.
- Picker labels: under `approval` the submit reads "Ask the organiser to link me" and the page says once "The organiser
  confirms who is who at this event."; the privacy line of §3.3 shows under it.
- Outcomes from §5.1 as flashes: `requested` → "Sent - the organiser will confirm it."; `dispute_sent` → "This name is
  linked to another profile. We asked the organiser to check it."; `ask_organiser` → "Please ask the organiser to link
  you."; `already_yours` → nothing.

### §6.3 Public participants list - "This is me"

`CompetitionParticipants` component: for a signed-in viewer **not going** (the event page already knows -
`EventAttendance`, no new statement), each unlinked name gets a small "This is me" link → the join flow with that
participant preselected (`?participant=<id>`, GET, no write). Not shown to visitors (they would only hit the login).

### §6.4 Competitor page and tag scans

- Competitor page (`competitor_page`, person `p` and line-up `g` kinds): for a signed-in viewer who is not linked to
  anybody of the event, each **unlinked** person in the header gets "This is me" → a small confirm sheet → POST
  `claim_participant` (`ClaimParticipant`, `found_via=competitor_page` or `tag_scan` when the request came through the
  scan landing - a `?via=tag` parameter the landing adds). JSON `competitor_state` unchanged (the button is server HTML).
  Guests: nothing (no "sign in to claim" - a guest on a stranger's tag is the common case).
- Scan landing with competitor pages **off** (`CompetitionTagLanding::publicLanding()`): a signed-in non-organiser whose
  scanned **person** tag is unlinked, while the viewer is not going, lands on the event page with
  `?participant=<id>#taking-part`; the event page then shows the §6.1 card **for that participant** (name match not
  required - the scan is the signal; policy applies). One statement only in that case (the landing already loads the
  competition). The golden `live-results-scan-matrix.txt` gains the signed-in-player rows on purpose; the "off" matrix
  for guests, organisers and referees stays byte-identical.
- Line-up / table / entry tags: no claim (a table is not a person).

### §6.5 Claim link page

Routes `participant_claim` `/{_locale}/claim/{token}` and `participant_claim_link` `/claim/{token}` (locale-less, picks
the language like `compete_link` - `PreferredLocale`), `participant_claim_confirm` POST `/{_locale}/claim/{token}`.
`Cache-Control: private, no-store`, `Referrer-Policy: same-origin`, `X-Robots-Tag: noindex, nofollow`, limiter
`participant_claim_page` 30/min per IP (unknown tokens remembered like `OnlineAccessLight` - cheap 404s).

GET (never writes) by state:

| State | Shows |
|---|---|
| unknown / cleared / removed participant | "This link is not valid any more. Ask the organiser for a new one." (one text for all three) |
| event not publicly visible | the same text for everybody off the event's team (no name, no event - DraftCanary); the team sees "Works once the event is published" |
| guest | event name + date, "**Jan Mikeš** - is this you?", [Sign in] [Create an account] with `?return=` (validated `ReturnUrl`) - nothing about whether an account exists |
| signed in, unlinked row | same + [Yes, link my profile] |
| already linked to the viewer | "You're linked ✓" + [Open the event] |
| linked to another account | "This name is already linked to another profile." + [It's me - tell the organiser] (dispute) |
| viewer is another participant here | "You are on this list as Jana M. Switch to Jan Mikeš?" + [Switch] (POST `switch=1`) |

POST → `ClaimParticipantByToken` → 303 to the event page (`#taking-part`) with a flash; the online-event case lands on
"Open my competition page" (§5.6).

### §6.6 "Not me" and pending state

- Notification `ParticipantListedYou` (§9.2) → its page action "Not me" = POST `leave_competition` with `not_me=1` for
  that participant → `LeaveCompetition` (+ `?string $notMeParticipantId`) → `unlink(not_me)`. The event page's existing
  "Disconnect" keeps its meaning (`unlinked`, no refusal) - "Not me" is only offered where the organiser linked.
- Pending requests are listed on the event page card (§6.1) only - no new profile surface.

---

## §7 Organiser-side pages

All behind `COMPETITION_EDIT` (`CompetitionEditVoter`); organiser tooling rules (O9) for identities.

### §7.1 Sheet - People tab

State (`GetParticipantsSheetState`) per person gains: `linkedVia` (method), `linkedAt`, `hasClaimLink` (bool - the token
itself is never in the state), `email`, `invitedAt`, `invitationCount`, `invitationsRefused`, and per event
`claimPolicy` (resolved + stored), `requests: [{id, participantId, kind, playerName, playerCode, foundVia, createdAt,
currentPlayer (dispute: O9-masked)}]` (pending only), `counts: {linked, notLinked, linkedByThemselves, requests}`.
The state statement count grows by one (requests); `ParticipantsSheet*BudgetTest` pins the new number.

UI (desktop; phones = the same in the person editor and list cards):
- **Profile column** shows the method as a small grey suffix: "· by themselves", "· claim link", "· approved", "· import"
  (none for `organiser` / NULL). A pending request shows a yellow chip "Asks: Jan M. #jan" with ✓ / ✗ (Approve /
  Decline) and "2 ask" when several.
- **Filters** (with counts): "Not linked (N)", "Asks to be linked (N)", "Linked by themselves (N)", "Suggestions (N)"
  (§7.2, lazy).
- **Columns menu**: "E-mail" (private, editable; `SheetChangeField::Email`, validated, refused when another active
  participant of the event has it? - no: families share addresses; allowed), "Invited" (date · count).
- **Row ⋯ menu** (unlinked rows): Copy claim link · Show claim QR (dialog with the SVG from §7.4's service, for the desk)
  · Send invitation (needs an e-mail; disabled with the reason when `mayBeInvited()` is false) · New link. Linked rows:
  Unlink · **Not this person** (confirm text: "Jan M. will not be able to link this name again by themselves").
- **Bulk bar** (selection): Approve N requests · Send invitations to N (confirmation: "N e-mails go out; M skipped:
  no e-mail / invited in the last 24 h / said no / 3 sent") · Download claim links (CSV of the selection).
- **Request decisions** are sheet changes (§5.3): undo works (undo of Approve = unlink; undo of Decline is refused -
  the claimant was told; say so).
- **Person editor** gains a "Profile history" section: the log rows of the person (`GetParticipantLinkHistory`, fetched
  when the section opens - one statement): "Linked by themselves (claim link) · 3 Nov 14:02", "Not this person: Pat S.
  · by Kim (organiser)".

### §7.2 Sheet - suggestions

"Suggestions (N)" filter → `participants_sheet_link_suggestions` (GET JSON, `OfficialResultsApi` rules like the player
search, `private, no-store`) → one statement (§8.4). A dialog lists per person: name, country, tier badge, candidate
(avatar, MSP name, #CODE, country, signals: "follows this event", "joined by themselves", "linked at <edition>") and a
checkbox (pre-ticked: tiers 1-2 with a unique candidate, D9). [Link N people] sends one group per ticked person. Several
candidates for one person = radio choices, none preselected. After an import the summary flash adds "37 people may
already have a profile - Review suggestions" linking `?tab=people&filter=suggestions`.

### §7.3 Import preview and export

- Mapping: `ParticipantImportField::PlayerId` relabelled "MySpeedPuzzling player (#code, id or profile link)"; new
  aliases `msp`, `msp code`, `player code`, `code`, `profile`, `msp profile`, `myspeedpuzzling`, `msp player`
  (`player` stays Name). New field `Email` with aliases `email`, `e-mail`, `mail`.
- Planner/`ParticipantImportRowData::$playerId` keeps the raw cell; `PlayerReference::parse(string): ?PlayerReference`
  (`src/Value`): UUID; `#?[a-z0-9._-]{2,}` → code; URL → the last path segment when it is a UUID (any language path of
  `player_profile`). `SiteSnapshotReader` resolves ids and codes in one statement
  (`SELECT id, code, name, is_private FROM player WHERE id IN (:ids) OR code IN (:codes)`); unknown →
  `unknown_player_reference` ("No MySpeedPuzzling player #xyz - the row is imported without a profile").
- New row messages: `player_in_file_twice` (both rows, D7), `link_refused_before` (D11), `player_id_kept` gains the
  method: "…stays linked to the profile they linked themselves" when `connected_via ∈ {self_join, claim, claim_approved,
  claim_link, online_link}`.
- Preview row "Profile" cell: "→ #CODE · Name" (O9-masked: a private profile shows "→ the profile #code from your file"
  - the organiser typed it), kept: "#CODE · Name (linked by themselves)". The fingerprint already covers the file's
  existing players; the state version (`GetParticipantImportStateVersion`) adds `email` and `connected_via`.
- E-mail cells: set when the cell is filled (empty never clears - D14 of the import); invalid → `invalid_email` message,
  row imported without it.
- Export: new columns `msp_code` (written for public profiles only - the id column stays the round-trip key) and `email`.
  The template gains both headers. Help panel text: "Put a player's #code in the file only when they gave it to you."

### §7.4 Claim slips, CSV, check-in

- Print: `competition_name_tags?kind=claims` (`CompetitionTagPrintKind::Claims`, layout `tags` only): unlinked active
  people (waitlisted included with a marker), sorted by name; each slip: event name, the participant's name, a QR of the
  locale-less `participant_claim_link` URL (drawn by `NameTagQrCode::claimUrl()` + the existing cached SVG writer),
  the URL in text, "Scan to link your MySpeedPuzzling profile. Keep this slip to yourself." Opening the page dispatches
  `GenerateParticipantClaimTokens` first (§5.2). The default name-tag page stays byte-identical
  (`CompetitionNameTagsGoldenTest`); the toolkit print page lists the kind in every QR mode.
- CSV `participants_claim_links_csv` (GET, `no-store`): name, country, external id, e-mail, claim link - for organisers
  who mail-merge in their own tool. Generates missing tokens first.
- Check-in (`CompetitionCheckIn` component, managed events): an unlinked row gets "Not linked · Show QR" (the same dialog
  as the sheet's) - the desk is where people are, during the event.

### §7.5 Toolkit hub card "Participants & profiles"

New partial `templates/competition_toolkit/_card_participant_links.html.twig` (first card - it matters before the event):
- Counters: "412 of 812 linked · 3 waiting for you · 37 suggestions" (`GetParticipantLinkSummary`, one statement; the
  suggestion count is the cheap tier-1/2 count, the full list stays lazy) - each a link to the sheet's filter.
- Claim policy radios (D3): "Automatic - now: *Ask me first*, because competitors can propose their times" / "Link at
  once - anybody signed in can pick a name from your list" / "Ask me first - you confirm every pick; personal claim links
  still link at once". POSTs back to the hub like the other cards (redirect on success, 422 on refusal).
- Actions: "Download claim links (CSV)", "Print claim slips", "Send invitations to everybody not linked with an e-mail
  (N)" (confirmation with the skip counts).
- Online event: the claim-link actions are replaced by "Personal links" (`online_links`).

The sheet's setup checklist gains a row "Help people link their profiles" once the list has ≥ 1 unlinked person and the
event is ≤ 14 days away (links to the card).

---

## §8 Queries and read-side guarantees

### §8.1 New / changed queries (`src/Query/`)

| Query | Statements | Guards |
|---|---|---|
| `GetEventAttendance` (+ match + pending columns) | +0 | organiser's names only - no player identity; DraftCanary unchanged (visible events only) |
| `GetCompetitionParticipants::findNotConnectedParticipantMatchingName()` | same | switched to `ParticipantNameKey::sql()` + refused pairs |
| `GetParticipantLinkSummary` (hub) | 1 | counts only |
| `GetParticipantLinkSuggestions` (§8.4) | 1 | `HiddenPlayers::sqlExclude()` for the viewer, `PrivateProfileAccess::sqlIsPublic()` - `BlocklistQueryCoverageTest` / `PrivateProfileQueryCoverageTest` |
| `GetParticipantLinkRequests` (sheet state) | 1 | snapshot identity - coverage exemption with reason (§3.3) |
| `GetParticipantLinkHistory` | 1 | organiser-only, names copied in the log (exemption: organiser tooling, ORGANISER list) |
| `GetRefusedParticipantLinks` | 1 | - |
| `GetParticipantClaimPage` (token → participant + event) | 1 | `IsCompetitionPubliclyVisible`; no player identity |
| `GetCompetitionClaimSlips` / claim CSV | 1 each | names as the organiser recorded them (tag rules) |

### §8.2 Canaries and budgets (every new surface)

- `DraftCanaryTest`: `participant_claim` / `participant_claim_link` for a draft's token, the event-page card with
  `?participant=`.
- `BlocklistCanaryTest`: the suggestions endpoint (a player the organiser blocked is not suggested) and the competitor
  page "This is me" (unchanged masking: a hidden person's header is already 404 / masked).
- `PrivateProfileCanaryTest`: suggestions (a private player not offered), the import preview's profile cell, the sheet
  request chip (snapshot shown, documented exemption).
- `DetailPagesQueryBudgetTest`, `CompetitorPageQueryBudgetTest` (guest unchanged; signed-in +0 - the viewer's links are
  already read), sheet budget (+1), hub budget (+1 for the card).
- Goldens: `CompetitionNameTagsGoldenTest` unchanged; `live-results-scan-matrix.txt` changes only in the signed-in
  player rows (§6.4); `live-results-tag-matrix-*` unchanged.

### §8.3 The "Is this you?" match (SQL sketch, inside `GetEventAttendance`)

```sql
LEFT JOIN LATERAL (
  SELECT cp.id, cp.name, cp.country
  FROM competition_participant cp, player me
  WHERE me.id = :playerId AND me.name IS NOT NULL
    AND cp.competition_id = :competitionId AND cp.player_id IS NULL AND {going('cp')}
    AND {nameKey('cp.name')} = {nameKey('me.name')}
    AND (cp.country IS NULL OR me.country IS NULL OR cp.country = me.country)
    AND NOT EXISTS (SELECT 1 FROM participant_link_log l WHERE l.participant_id = cp.id AND l.player_id = :playerId
                    AND l.action IN ('declined','not_me','not_this_person'))
  LIMIT 2
) ...   -- PHP keeps it only when exactly one row came back (array_agg / count window)
```
With `?participant=` (tag scan) the same lateral filters by that id instead of the name key.

### §8.4 Suggestions (one statement)

For the event's active unlinked participants `u` (≤ a few thousand), UNION ALL of candidate rows `(u.id, player_id,
tier, signal)`:
1. **Joined by themselves**: active `self_joined` row `s` of the same event, `nameKey(s.name) = nameKey(u.name)` or the
   linked player's name key equal; country compatible.
2. **Linked at another date**: `competition_participant o` of another competition with the same `series_id` or the same
   organization (through series), `o.player_id IS NOT NULL`, `nameKey(o.name) = nameKey(u.name)`.
3. **Name and country**: `player p` with `nameKey(p.name) = nameKey(u.name)` (index §3.5) and `p.country = u.country`.
4. **Name only**: the same with a country missing on either side.
Then: drop players already linked to an active participant of this event, refused pairs, the viewer's hidden players,
profiles private to the viewer (tiers 2-4); keep for each participant the best tier per player; signals as booleans
(`followed_competition` of the event / its series / organization; a solving time linked to the series). Tiers 3-4 only
when the name key has ≤ 3 players site-wide (common names produce no suggestion). Measured target: < 150 ms for an
800-person event on production data (verify with `EXPLAIN ANALYZE` on lily - read-only).

---

## §9 E-mails and notifications

### §9.1 Claim invitation e-mail (`emails/participant_claim_invitation.html.twig`)

Transactional template rules (`docs/features/transactional-emails.md`: slim `_header`, preheader key < 90 chars, plain
text part). Locale: the participant's country → a site locale when one fits (CZ/SK → cs, DE/AT/LI → de, ES + Spanish-
speaking Americas → es, FR/BE/LU/MC → fr, JP → ja), else en; the link is locale-less. Subject "Link your profile to
%event%". Body: "%organisation or event% lists **%participantName%** as a participant of **%event%** (%date%, %place%).
Link your MySpeedPuzzling profile to see your results and use the event's tools." [Link my profile] (the claim link) ·
"Not you, or no account yet? The link lets you sign in or create one - or just ignore this e-mail." · footer: "You get
this because the organiser of %event% added your address. [Don't send me more invitations for this event]" (signed
one-click, RFC 8058 header too). No reply-to (organiser addresses stay private). Same body whether an account exists
(D10). `X-Transport: transactional`, `email_type = participant_claim_invitation` (EmailAuditSubscriber).

### §9.2 In-app notifications (`NotificationType`, `GetNotifications` UNION branches read `FROM page notification`)

New target column `target_participant_link_request_id` (CASCADE) and `target_competition_participant_id` (CASCADE).

| Type | To | Target | Text / action |
|---|---|---|---|
| `ParticipantLinkRequested` | each organiser (creator + maintainers + series/organization team - the people `COMPETITION_EDIT` grants, admins excluded) - **only when they have no unread one for this event** | request | "People are waiting for you to confirm their participant link at %event%" → sheet `?filter=requests` |
| `ParticipantLinkApproved` | claimant | request | "You're linked to %name% at %event%" |
| `ParticipantLinkDeclined` | claimant | request | "The organiser of %event% did not confirm %name% as you" (no reason, no who) |
| `ParticipantLinkSuperseded` | claimant of a superseded request | request | "%name% at %event% was linked to another profile. If it is you, ask the organiser." |
| `ParticipantListedYou` | the player linked by `organiser` / `import` | participant | "You're on the participant list of %event% as %name%" + [Not me] (§6.6) |

No e-mail for these (in-app; the existing notification digest carries them if the player has it on).

---

## §10 Abuse and what a link gives

### §10.1 What a link unlocks (D12)

| Unlocks | Linked | Pending request | Notes |
|---|---|---|---|
| "You're going", event page attendance, participant counts | yes | no ("Waiting for the organiser") | |
| Profile identity on public round results, standings, bracket, competitor pages, displays, analytics studio | yes (privacy rules) | no | |
| Competitor page "own" view (confirmed time before publishing) | yes | no | |
| Time proposals | yes, if linked before the round's first stopwatch start (`TimeProposalOwnership`) | no | approval by default on such events (D3) |
| `OfficialResultPublished` notification, "Add to my profile" prefill | yes | no | the result is never added automatically |
| "Competitor" achievement (`AchievementSqlRules::COMPETITOR_EVENTS`) | yes | no | |
| Registration e-mails (managed events) | yes | no | |
| Online personal link on the event page | only `self_join` / `claim_link` / `online_link` (confirmed) | no | unchanged rule |
| XP / karma | no change | no | |

### §10.2 Abuse matrix

| Actor | Action | Policy | Outcome |
|---|---|---|---|
| Cheater (signed in) | picks a friend's / rival's unlinked name before the start | approval (proposals on) | request; the organiser sees name + #code + "found via list"; declined → refused pair, 3 → no more claims |
| Cheater | same | open (no proposals) | linked; gains identity display only; sheet shows "· by themselves"; the real person's own claim → dispute → organiser "Not this person" (refused pair) and approve the real one |
| Cheater | claims after the start, proposes | any | link (open) refused for proposals (`linked_after_start`, unchanged) |
| Cheater | photographs a name tag, scans it | any | a claim (D6), same as picking the name - never a proof |
| Cheater | guesses / brute-forces claim tokens | - | 128-bit tokens, per-IP limiter, unknown-token memo; impossible |
| Someone who got a forwarded claim link | opens it first | any | linked (`claim_link`); the real person's link shows "already linked" → dispute → organiser approves the real one (old link `not_this_person`, token cleared, new one on demand) |
| Impersonator | changes their profile name to the victim's to trigger "Is this you?" | open | linked like any open claim (same remedy); approval: request with their real #code visible |
| Organiser | links a stranger (import / sheet) | any | stranger gets `ParticipantListedYou` → "Not me" → unlinked + refused pair (import cannot re-link it) |
| Organiser | links someone with a wrong `#code` | any | as above; the preview showed "→ #CODE · Name" before confirming |
| Two players | claim the same row at once | open | the lock serialises: first linked, second → dispute request |
| Player | claims while the organiser links another person to the row | approval | organiser's link supersedes the request (§4); claimant notified |
| Player already linked as another participant | claims a second row | open | switch (today's release rules: own self-joined row soft-deleted unless it holds official data, organiser's row only unlinked) |
| Player | spams claims | any | one pending request per event; limiter `participant_claim` 10 per 10 min per player; 3 refusals per event ends self-claims |
| Organiser | sends invitations repeatedly | - | per person ≤ 1 / 24 h, ≤ 3 total; per event ≤ 1,000 / day; suppression list; one-click refusal |
| Anybody | uses invitations to learn whether an address has an account | - | nothing in the mail, the claim page or the sheet depends on it (D10) |
| Private player | appears in suggestions | - | never (tiers 2-4); in a request only by their own consented claim |

---

## §11 Edge cases

- **Removed participant** (soft delete, sheet/import/full sync): `participantRemoved()` supersedes its pending requests
  (system, no notification - the claimant sees the card disappear); its token stops working (claim page "not valid");
  restore does not revive requests. A removed self-joined row stays the player's "I left" record (unchanged).
- **Event deleted / converted to series**: requests cascade; log rows stay (no FKs); convert keeps everything on the
  edition (`ConvertCompetitionForeignKeyCoverageTest` lines).
- **Duplicate event**: copies `participant_claims`; never participants, tokens, e-mails, requests.
- **Draft event**: claims refused (P17); tokens can be prepared, the claim page hides the event from anybody off its team;
  `ParticipantListedYou` is not sent for links made while it is a draft (no catch-up on publish - documented).
- **Merged players**: no player-merge feature exists today; if one is built, it moves `competition_participant.player_id`,
  requests and log `player_id` (add to its coverage).
- **Account deletion** (`DeletePlayerHandler`, raw SQL bulk with a comment, CLAUDE.md exception): `player_id`,
  `connected_at`, `connected_via`, `online_link_confirmed_at`, `claim_token` = NULL for its rows; its **self-joined rows
  without official data are soft-deleted** (today they stay with the profile name and show up as unclaimed in the picker -
  bug); with official data the row stays (organiser's record, unlinked); requests cascade; log: one `account_deleted` row
  per linked participant (`gen_uuid_v7()`, `SqlCreatedIdsCoverageTest`) and `player_name = ''` on all its rows.
- **Pairs / teams**: links are per person; a line-up's competitor page offers "This is me" per unlinked member; team
  results follow their members as today. No per-team claim code.
- **Online competitions**: personal link = claim link (§5.6); the picker claim on an online event never hands the link
  (unchanged); approval policy is automatic `open` there (proposals are never effective online), the organiser may pick
  `approval`.
- **A player already linked to another participant of the same event**: open claim = switch; approval = switch at
  approval; token = explicit "Switch" (§6.5); import/sheet = `player_linked_elsewhere` (unchanged).
- **Managed registration**: a claim keeps the row's registration status (the organiser holds that spot); the player's
  own registration is released only with `release_paid` consent, at link time (approval: at approval). Waitlisted rows
  can be claimed.
- **Series editions**: everything is per edition (competition row); suggestions use the series' other editions (tier 2).
- **Re-import of an export after self-links**: the export carries `msp_player_id` of everybody linked - re-importing
  changes nothing (same players). The known side effect "self-joined become `imported`" stays (TODO).
- **Same person on the list twice** (two rows, same name key): no "Is this you?" (two matches), suggestions show both
  rows with the same candidate unticked.
- **Player renames their profile** after linking: nothing changes (the participant name is the organiser's).
- **Time zones**: "≤ 14 days away" and "60 days after the last day" in the event's zone (`event_time`).

---

## §12 Implementation packages

Each ships to production on its own, in this order. Every package: phpstan, cs-fix, the touched tests locally, full
suite on CI (`feedback_slow_tests_use_ci`), `doctrine:schema:validate`, 6 locales (`missing-translations` skill), the
area rule entries (`.claude/rules/features/events.md` "Participants spreadsheet" / a new "Participant linking" bullet;
competitor page bullet in `competition-toolkit.md`), `docs/TODO.md` leftovers, and this doc's "As built" section.

### L1 - Foundation + import with player references

- **Scope**: §3.1 `connected_via`, §3.4 log, `ParticipantLinkMethod`/`ParticipantLinkAction`, `ParticipantLinks`
  (`link`/`unlink` only) with every call site of §4.1 moved, `ParticipantLinkCoverageTest`; account deletion fixes (§11);
  `ParticipantNameKey::sql()` + parity test + `custom_player_name_key`; import `PlayerReference` (§7.3 without e-mail),
  `player_in_file_twice`, `player_id_kept` wording by method, preview "→ #CODE · Name", export `msp_code`; sheet profile
  cell suffix "· by themselves / · import"; Mercure push on link changes from join/leave/compete_connect (§5.8).
- **Hot files**: `CompetitionParticipant.php`, `CompetitionSelfJoin.php`, `JoinCompetitionHandler.php`,
  `LeaveCompetitionHandler.php`, `ConnectCompetitionParticipantHandler.php`, `ParticipantImportApplier.php`,
  `PlanBuilder.php`, `SiteSnapshotReader.php`, `ColumnMapping.php`, `ParticipantImportField.php`,
  `GetParticipantImportStateVersion.php`, `CompetitionParticipantExporter.php`, `DeletePlayerHandler.php`,
  `GetParticipantsSheetState.php`, `assets/participants_sheet/views/people_view.js`, `templates/participant_import/*`.
- **Tests**: planner cases (uuid / `#code` / `code` / URL / unknown / twice / kept-by-self), applier writes method + log,
  `ParticipantLinkCoverageTest`, `DeletePlayerHandlerTest` (self-joined soft-deleted, columns cleared, log erased),
  name-key parity, `CompetitionParticipantImporterTest` + round-trip unchanged, `SerializedByLockMessagesTest` green,
  `PrivateProfileCanaryTest` (preview cell).
- **Browser walk**: import a CSV with `#codes`, a URL, a typo and one code twice → preview texts → confirm → sheet shows
  the links with suffixes; delete a test account linked to a self-joined row → the picker no longer offers its name.
- **Depends on**: nothing.

### L2 - Claim policy, requests, refusals, notifications

- **Scope**: §3.2, §3.3, `ParticipantClaims` (§5.1) used by `JoinCompetition` + new `ClaimParticipant`,
  `WithdrawParticipantLinkRequest`, sheet ops (§5.3: approve via `player`, `DeclineLinkRequest`, `notThem`),
  refused pairs + 3-refusal limit, `ChangeParticipantClaimPolicy` + hub card with the policy radios and counters
  (§7.5 without claim-link actions), sheet filters "Not linked / Asks / Linked by themselves", request chips, person
  editor history (§7.1), notifications §9.2 (all five) incl. "Not me" (§6.6), join flow texts (§6.2 for `approval`),
  event-page pending state (§6.1 states only, not the match card), limiter `participant_claim`.
- **Hot files**: `Competition.php`, `JoinCompetitionHandler.php`, `JoinCompetitionController.php`,
  `LeaveCompetition*`, `GetEventAttendance.php`, `_event_attendance.html.twig`, `join_competition.html.twig`,
  sheet `SheetChangeOp`/`SheetChangesParser`/`SheetPlanRun`/`ParticipantImportApplier`, `sheet_changes.js`,
  `people_view.js`, `person_editor.js`, `CompetitionToolkitController.php`, hub templates, `NotificationType`,
  `Notification.php`, `GetNotifications.php`, `DuplicateCompetitionHandler.php`.
- **Tests**: policy resolution (NULL + proposals on/off/online), every §5.1 row, races (organiser link supersedes; two
  claims), approve/decline/undo via sheet (three-way `from`), refused pair blocks claim + import (`link_refused_before`),
  limit of 3, notifications (collapse rule), `TimeProposalOwnership` unchanged (a pending claim cannot propose),
  `DuplicateCompetitionHandlerTest` copies the setting, `SerializedByLockMessagesTest`, hub card test (redirect / 422),
  `DetailPagesQueryBudgetTest` unchanged, Blocklist/PrivateProfile canaries (request chip exemption).
- **Browser walk**: event with proposals on → second account picks a name → "Waiting" card → organiser's notification →
  sheet chip → Approve → claimant notified, competitor page shows Propose; Decline path; "Not me" from an import link.
- **Depends on**: L1.

### L3 - Find yourself fast

- **Scope**: §6.1 "Is this you?" card (in-statement match), D14 (GET never writes; POST "I'm going"), §6.3 list
  "This is me", §6.4 competitor page "This is me" + tag-scan landing `?participant=` (pages off) / `?via=tag`.
- **Hot files**: `GetEventAttendance.php`, `EventAttendance` result, `_event_attendance.html.twig`,
  `JoinCompetitionController.php`, `CompetitionParticipants.php` + template, `GetCompetitorPage.php` /
  `templates/competitor_page/*`, `CompetitionTagLanding.php`, scan matrix golden.
- **Tests**: card shown/hidden matrix (two matches, other country, refused pair, going, guest, managed), GET never
  writes (functional), `JoinCompetitionControllerTest` updated, `DetailPagesQueryBudgetTest` (+0),
  `CompetitorPageQueryBudgetTest` (guest unchanged), `LiveResultsScanRedirectMatrixTest` golden (signed-in rows only),
  `DraftCanaryTest` (`?participant=` on a draft).
- **Browser walk** (phone width 375 px + desktop): profile name = a listed name → event page card → Yes (open) / Waiting
  (approval); scan a printed person tag with the phone signed in (pages on and off); "This is me" in the participants
  list; Turbo: every POST lands with a flash, back button sane.
- **Depends on**: L2 (the claim service and the policy).

### L4 - Personal claim links and invitations

- **Scope**: §3.1 token + e-mail columns, §5.2, §5.5, §6.5 claim page, §7.1 row actions / bulk invitations / e-mail
  column, §7.3 e-mail import + export column, §7.4 claim slips + CSV + check-in QR, hub card actions, §9.1 e-mail,
  erase cron (+ lily crontab row), limiter `participant_claim_page`, online-event substitution (§5.6).
- **Hot files**: `CompetitionParticipant.php`, new `ParticipantClaim*Controller`s, `NameTagQrCode.php`,
  `CompetitionTagPrintKind`, `CompetitionTagSheet.php`, `CompetitionCheckIn.php`, import field/mapping/planner/applier,
  exporter, `config/packages/rate_limiter.php`, `templates/emails/*`, `translations/emails.*`.
- **Tests**: token page states (all rows of §6.5) incl. draft and removed, switch path, dispute from a used link,
  unlink clears token, online event hands the online link, invitation caps / suppression / refusal / same text for
  account and no account (snapshot of the rendered mail for both), erase cron, `SerializedByLockMessagesTest`,
  `DraftCanaryTest`, `CompetitionNameTagsGoldenTest` unchanged, preheader length test.
- **Browser walk**: copy a link → open in a private window → create an account → linked; print claim slips and scan
  one with a phone; send an invitation to a Mailpit address → render at phone width (header, dark mode) → click →
  linked; one-click refusal; check-in "Show QR" on a phone.
- **Depends on**: L1 (L2 for the dispute path; ship after L2).

### L5 - Suggestions and the overview

- **Scope**: §8.4 `GetParticipantLinkSuggestions`, §7.2 dialog + bulk accept (incl. *Joined by themselves* merge group),
  "Suggestions (N)" filter and hub counter, import summary link, setup-checklist row.
- **Hot files**: new query + `ParticipantsSheetLinkSuggestionsController`, `people_view.js` (dialog),
  `GetParticipantLinkSummary.php`, import result flash, sheet checklist template.
- **Tests**: every tier, exclusions (linked elsewhere, refused, hidden, private, common names > 3), pre-tick rule,
  merge refused with entries/official data, `BlocklistCanaryTest` + `PrivateProfileCanaryTest` surfaces, coverage
  tests, a statement-count pin, `EXPLAIN` plan sanity on a seeded 1,000-person event.
- **Browser walk**: import a list without ids into an event where test players follow / self-joined / were linked at a
  previous edition → Suggestions (N) → dialog → Link → sheet updates live in a second organiser's tab; undo.
- **Depends on**: L1 (L2 for refused pairs).

---

## §13 For Jan

1. **D3 default** - automatic approval only where time proposals are on. Alternative: also on managed-registration
   events (paid spots). Recommended as designed (casual organisers would get a queue they never asked for).
2. **D8** - import/sheet links notify the player with "Not me". Fine for WJPC-sized lists (one notification each)?
3. **D15** - storing participants' e-mail addresses (organiser-provided, erased 60 days after the event) and MSP sending
   the invitations from our domain to people without accounts. The CSV of claim links alone (organisers mail-merge
   themselves) would avoid both; recommended to build both, invitations last (L4).
4. The scan-matrix golden changes for signed-in players on events with competitor pages off (§6.4) - a deliberate
   change of a frozen golden (paths stay frozen).

---

## As built

### L1a - foundation (2026-11-17)

- `ParticipantLinkMethod` / `ParticipantLinkAction` (`src/Value/`), `competition_participant.connected_via`,
  append-only `participant_link_log` (`ParticipantLinkLog`, no FKs, `ParticipantLinkLogRepository` persist only).
- `ParticipantLinks` (`src/Services/ParticipantLinking/`): `link()` (no-op when already linked to the player - moment and
  method kept; a row linked to somebody else is unlinked first and both are logged), `rejoin()` (a left self-joined row
  revived by "I'm going" = a new link, moment now), `unlink()`. Seam for L2's §4 steps 3-4 marked in `link()`.
- Call sites: `CompetitionSelfJoin` (`self_join`, release = `unlinked`), `JoinCompetitionHandler` pick (`claim` until
  L2's `ParticipantClaims`), `LeaveCompetitionHandler` (`unlinked` - not `not_me`: leaving is no refused pair),
  `ConnectCompetitionParticipantHandler` (`online_link`, its release of the player's other row = `unlinked`),
  `ParticipantImportApplier` (`import` for imports with `by` = null, `organiser` + the acting organiser for the sheet).
  Guard `ParticipantLinkCoverageTest` (token scan of `src/` + SQL writers of the link columns).
- `connect()` keeps a default method `organiser` for tests/fixtures (R12): the method that keeps what a link may do
  today under R3. `disconnect()` clears `connected_via` (R6: L4a reads the method before clearing the token).
- Account deletion (§11): `ParticipantLinksOfDeletedAccount::release()` called by `DeletePlayerHandler` - log names
  erased, one `account_deleted` row per row of the account (`gen_uuid_v7()`), self-joined rows without official data
  soft-deleted (`OfficialResultsGuard::sqlParticipantHoldsOfficialData()`), link columns cleared on every row.
- Sheet: `linkedVia` in the person state (same statement), grey suffix in the People grid's profile cell and the person
  editor ("· by themselves" for `self_join`/`claim`, "· import", "· personal link"; nothing for `organiser`/NULL).
- §5.8: join / leave / compete_connect call `ParticipantsSheetLiveUpdates::changedAfterResponse()` (AfterResponse, the
  version read after the commit).

### L1b - import with player references (2026-11-17)

- `PlayerReference::parse()` (`src/Value/`): UUID; `#code` / bare code with the chooser's rule `[a-zA-Z0-9]{3,8}`
  (`PlayerCodeFormData`), compared lower-cased (a few old codes are stored upper-case); a URL or path whose last segment
  is a UUID (any language of `player_profile` - the route takes ids only, so a URL ending in a code is no reference).
- `SiteSnapshotReader::read()` takes the cells as written and resolves ids + codes in one `UNION ALL` statement over
  `player` and `player_code_change`. **Decision (R5):** a code resolves to the one player who holds or ever held it; more
  than one player ever = `code_changed_owner`; a player's own former code (nobody else had it) is theirs. The
  resolutions are in `SiteSnapshot::$references` and in the plan's fingerprint; the state version adds `connected_via`.
- `PlanBuilder`: D7 `player_in_file_twice` on every row of the player when the rows are of different people (rows of
  one person = one name key and at most one participant id - they add up as before); `linking.player_id_kept_self`
  when the kept link is `ParticipantLinkMethod::isThePlayersOwn()`; `linking.name_differs` (R5, name keys by
  `ParticipantNameKey::of()`) - never for a profile the organiser may not see (it would answer "is this the private
  player's name?").
- Preview "Profile" line (`ParticipantImportRowProfile`): "→ #CODE · Name" / "#CODE · Name (linked by themselves)";
  masked (`PrivateProfileAccess::sqlIsPrivate()` in the reader + `HiddenPlayers`, the sheet's O9) = "the profile <cell>
  from your file" / "a profile you cannot see". The `msp_player_id` UUID diff line is replaced by it.
  `PrivateProfileCanaryTest::testTheImportPreviewNamesHerLinkedProfileForTheFriendOnly`.
- Import links keep `connected_via = import` + log (L1a's applier, a `code` header test added).
- Mapping: `msp_player_id` relabelled, aliases §7.3; new field `msp_code` (`ParticipantImportField::PlayerCode`, header
  `msp code`) read where the player column is empty. Export: `msp_code` after `msp_player_id` (public profiles only,
  `#CODE`); the template's player column is `msp_code`. Help: "Put a player's #code in the file only when they gave it
  to you." Not done here: the `email` column, `link_refused_before` (L2), the D8 notification (L2).


### L2a - claim policy, requests, organiser decisions, refusals (2026-11-17)

- `competition.participant_claims` (`ParticipantClaimPolicy` open / approval, NULL = automatic): one rule
  `ParticipantClaimPolicy::resolve()` - `approval` while competitor pages + time proposals are on at an event that is not
  online by the series-aware rule (an edition by its series' flag, R9). Read by `Competition::participantClaimPolicy()`
  and, without the entity, `GetParticipantClaimPolicy` (1 statement). `ChangeParticipantClaimPolicy` touches the
  competition row only (no lock; the SerializedByLock scanner does not see it as a participant writer, so it needs no
  allow-list entry - like `ChangeCompetitorPageSettings`). Duplicate copies it.
- `participant_link_request` (`ParticipantLinkRequest`, §3.3 without `found_via` - R10; claimant name + #code snapshot,
  `releases_own_registration`), written only by `ParticipantLinks` (`request()`, `decline()`, `withdraw()`,
  `participantRemoved()`, and `link()`'s §4 step 3: the player's request for the row approved - an organiser's link of a
  claimant is `claim_approved`, a dispute's old link is unlinked as `not_this_person`; other requests on the row and the
  player's request for another row superseded). Log actions `requested` / `declined` / `withdrawn` / `superseded`.
- `ParticipantClaims::claim()` = the §5.1 table (+ `release_not_confirmed`: the player's own paid registration needs
  the `release_paid` yes, also under `approval`) - used by `JoinCompetition`'s pick (returns the `ClaimOutcome`; a
  removed / other event's participant is `not_available` and a linked one a dispute - no more 404 /
  `CompetitionParticipantAlreadyConnectedToDifferentPlayer`) and the new `ClaimParticipant` (no UI yet - L3).
  Refused pairs + the limit of 3: `GetRefusedParticipantLinks` (1 statement); the import reads them in its participants
  statement (`link_refused_before`, +0).
- Sheet (§5.3, R1): approve = the `player` change to the claimant; when the claimant is on the list already **and has a
  pending request for that row**, `SheetPlanRun` lets go of their other rows in the same group (own self-joined row
  without a result soft-deleted - not made the organiser's; other rows unlinked); a paid own row without the claimant's
  yes is refused `claimant_registration_paid`. Without a request the old `player_linked_elsewhere` stays. Decline =
  `declineLinkRequest {request, from: "pending"}` (three-way on the status; never undone - the client marks the step
  `irreversible`, its undo says so). "Not this person" = `player → null` with `notThem: true` (`unlink(not_this_person)`).
  A removed participant's pending requests are superseded (sheet, import full sync, leave, self-join release).
- R3: `TimeProposalOwnership` refuses a link made after the round's first start only for `claim` / NULL.
- R11: the join GET links a name match only under `open`; under `approval` the match is preselected, the page says
  "The organiser confirms who is who" and the submit asks the organiser (+ the privacy line). Flashes per outcome;
  limiter `participant_claim` (10 / 10 min per player) on picks. Event page: "Waiting for the organiser to confirm you
  are %name%" + Take back (`withdraw_participant_link_request`, CSRF per event) from `GetEventAttendance`'s statement
  (+0); the header button reads "Waiting for the organiser".
- Sheet state: `competition.claimPolicy` / `claimPolicyChosen`, `requests` (GetParticipantLinkRequests, +1 statement,
  the snapshot - the player table is not read, so no coverage-test exemption was needed), `counts`; the version hashes
  the policy columns, requests (id, status) and `connected_via`. People filters "Not linked / Asks to be linked / Linked
  by themselves", the yellow request chip (✓ / ✗, "N ask" opens the person editor), Approve / Decline + "Not this
  person" in the profile cell's options and the person editor, the chip on phone cards; grey suffix "· approved".
- Hub card "Participants & profiles" (first): counters to the sheet filters (`GetParticipantLinkSummary`, 1 statement)
  and the policy radios (redirect / 422).
- Review fixes: the join page's name match skips refused pairs and a player at 3 refusals gets the page with a note
  (never a redirect loop); "Not this person" is never undone either (the refused pair stays - linking again by hand
  is the way back); an import / sheet change set reads the event's pending requests once (`ParticipantLinks::
  inBatch()`, `ResetInterface`) instead of 2 statements per link; the paid-registration consent counts only while the
  event manages registration; a removal supersedes the person's requests in the plan too (a decline in the same set
  is a conflict); the sheet shows R1's release of the claimant's other rows at once (`Working::releaseClaimant()`).
- Not built here: notifications and "Not me" (L2b), the "Is this you?" card and the "This is me" surfaces (L3).

### L2b - notifications and "Not me" (2026-11-18)

- `NotificationType` `ParticipantLinkRequested` / `ParticipantLinkApproved` / `ParticipantLinkDeclined` (target
  `notification.target_participant_link_request_id`) and `ParticipantListedYou` (target
  `target_competition_participant_id`), both FK CASCADE with partial `custom_notification_fk_*` indexes
  (`Version20261118000200`, hand-written under no-Docker mode). In-app only, no e-mail. Written by
  `ParticipantLinkNotifications`, called from `ParticipantLinks` only, in the transaction of the change (persist only):
  - `request()` (a new request, claim or dispute) → every organiser (`GetCompetitionOrganisers`: creator + maintainers of
    the event, its series and its organization - admins only when on that team; never the claimant) **without an unread
    one of this event** (`NotificationRepository::hasUnreadParticipantLinkRequested()`). Re-asking the same row creates
    nothing.
  - `link()`: the claimant's request approved by somebody else → Approved; other pending requests on the row superseded →
    Declined (R10, the same neutral text); the player's own request for another row and `request()`'s superseding stay
    silent, so does `participantRemoved()` (§11). A link `organiser` / `import` that approves no request, by somebody
    else than the player, while `Competition::isPubliclyVisible()` → ListedYou. An unchanged link returns before all of
    it (R12: a re-import, the import naming the player of their own row).
  - `decline()` → Declined (no reason, no who).
- `GetNotifications`: one UNION branch (`participant_link_*` columns, NULL in the others); the event's name + slugs, the
  participant's name (the organiser's record - nobody else is named, nothing to hide or mask). The row: organisers →
  "Review" to `participants_sheet?tab=people&filter=asks` (the L2a filter is `asks`, not `requests`); players → the
  event page; ListedYou → "Not me" while the organiser's link of the reader stands (`participant_link_not_me`: still
  linked to the reader, not removed, not self-joined, `connected_via` organiser / import / NULL).
- "Not me" = POST `leave_competition` with `not_me=1`, `participant`, its own CSRF token
  (`LeaveCompetitionController::CSRF_NOT_ME_PREFIX` + participant id) → `LeaveCompetition(…, notMeParticipantId)` (under
  the participants lock as before) → `unlink(not_me)` of that row only (a refused pair: claims → `ask_organiser`, the
  import → `link_refused_before`); the partner listing closes only when no other row links the player. Refused (handler
  returns false, flash "nothing changed") for another event's participant, somebody else's, the player's own self-joined
  row or a link the player made themselves. 303 + flash. The event page's Disconnect / Leave are unchanged (`unlinked`).
- The sheet's decline confirmation says the player is told.

### L4a - personal claim links, claim slips, CSV, desk QR (2026-11-18)

- `competition_participant.claim_token` (varchar(32) unique, `OnlineToken` format; migration hand-written under
  no-Docker mode). `CompetitionParticipant::disconnect()` clears it only when `connected_via` was `claim_link` (R6) -
  every unlink through `ParticipantLinks` gets that; account deletion's bulk SQL does the same (`CASE`).
- Messages (all `SerializedByLock`, participants lock): `GenerateParticipantClaimTokens(competitionId, ?ids)` (active,
  unlinked, without a token - waitlist included), `RegenerateParticipantClaimToken` ("New link"; linked →
  `ParticipantAlreadyLinked`), `ClaimParticipantByToken(competitionId, token, playerId, switch)` → `ClaimLinkOutcome`
  (`not_valid` / `already_yours` / `belongs_to_other` / `is_other_participant` / `linked`). The message carries the
  competition the page read (the lock needs it); the handler reads the token again under the lock. Never consults the
  policy or refused pairs; links `claim_link`, releases the player's other rows (CompetitionSelfJoin) and
  `handOwnLink()` (the online personal link on events with online timing, §5.6).
- Claim page (`ParticipantClaimController`, `participant_claim` / `participant_claim_link` / `participant_claim_confirm`,
  `templates/participant_claim/page.html.twig`): every §6.5 state; `GetParticipantClaimPage` (1 statement, embeds
  `IsCompetitionPubliclyVisible`, reads all the viewer's other rows - one name, `bool_or` paid - no player identity).
  Limits (`ClaimPageLimit`, page and POST): `participant_claim_page_player` 30/min per signed-in player,
  `participant_claim_page` 120/min per IP for guests (a venue's Wi-Fi is one address); unknown tokens remembered 10 s
  (`ClaimTokenMemo`, removed rows are not - a restore must work at once). The dispute = `ClaimParticipant` (limiter
  `participant_claim` per player). A switch or dispute that lets go of the viewer's own paid registration on a managed
  event needs `release_paid`: the switch page says it (hidden field), the dispute form asks (checkbox); a registration
  paid after the page was shown → `ReleaseNotConfirmed`, back to the page. `no_profile` state for an account without a
  player. The claim slips page sends `Referrer-Policy: same-origin`.
- R4: `OnlineTokenRedactor` redacts `/claim/<token>` and `/<locale>/claim/<token>` (also `\/` and `%2F`) and the
  current token of `participant_claim*` routes - the Sentry scrubber and the Monolog processor use it.
- Organiser: sheet row ⋯ "Copy claim link / Show claim QR / New claim link" for unlinked rows (desktop grid;
  `assets/participants_sheet/claim_links.js`), on an event with online timing "Copy personal link" instead; JSON
  `participants_sheet_claim_link` (OfficialResultsApi rules; 409 `already_linked` / `online_timing`). CSV
  `participants_claim_links_csv` (name, country, external id, link - online timing → `online_links_csv`). Print kind
  `claims` (`CompetitionTagPrintKind::Claims`, tags layout only, waitlist marked, QR of the locale-less link; online
  timing → points to the personal links' QR sheet). Check-in "Not linked · Show QR" (`claim_qr` Stimulus controller,
  `CompetitionCheckIn::$claimLinks`). Hub card: "Print claim slips" + "Download claim links (CSV)", or "Personal links"
  (`GetParticipantLinkSummary::usesOnlineTiming`, +0 statements). `NameTagQrCode::claimUrl()`, `ParticipantClaimLinkList`
  (generate missing, read again - the OnlineLinkList pattern), `GetParticipantClaimLinks` (1 statement).
- Not here: e-mail invitations and the `email` column (L4b), bulk "Download claim links" of a selection, the phone
  list's/person editor's claim actions (the desktop row menu only), `hasClaimLink` in the sheet state.

### L3 - find yourself fast (2026-11-18)

- **"Is this you?" card** (§6.1): `GetEventAttendance::forEvent(…, withClaimMatch: true, focusParticipantId:)` from the event
  and edition pages adds one lateral `claim_match` to its existing statement (+0): active unlinked going participants
  whose name key equals the viewer's (`GetCompetitionParticipants::sqlNameKey()` - the join page's own SQL key, so the card
  and the join page always agree; no `ParticipantNameKey::sql()` twin was needed) with a compatible country, no refused
  pair, the player under `REFUSALS_UNTIL_NO_MORE_CLAIMS`; counted, the card only for exactly one. Only of a publicly
  visible event, for a signed-in viewer not going and without a pending request (`EventAttendance::$claimCandidate`,
  `ParticipantClaimCandidate` - the organiser's record only). `_event_claim_card.html.twig` replaces "I'm going" (managed:
  above the registration card, the picker form with `REGISTER_CSRF_PREFIX`, the release notice / `release_paid` box of the
  join page for a self-joined waitlisted viewer): [Yes, that's me] = the join POST `participant_id` (open → linked,
  approval → the waiting state), [No - I'm going as myself] = `self_join` (managed: only while open and not full - the
  registration card offers the waitlist), "Find my name on the list" = `join_competition?participant=<id>`. The header's
  "I'm going" links to `#taking-part` while the card shows.
- **Several matches**: no card; the join page says "N people on the list are called X - pick yours."
  (`matchNotConnectedParticipantsByName()` - id + count in one statement, replacing the old `LIMIT 2` one).
- **R11 kept**: the open-policy GET still links a unique name match; no new GET entry point links.
- **`?participant=` on the join page** (§6.3, R12): pre-selected only when active, going, unlinked and of this event
  (`GetCompetitionParticipants::isPickable()`, 1 statement only when the parameter is there), else ignored; never links on
  the GET, whatever the policy.
- **Participants list "This is me"** (§6.3): `CompetitionParticipants::$viewerMayClaim` (the page passes it from
  `EventAttendance`, no statement) → a link per unlinked name to the join page with it pre-selected.
- **Competitor page "This is me"** (§6.4, R9): `CompetitorPageView::$claimable` from the entries statement (+0) - the
  header's unlinked people of a person (`p`) or line-up (`g`) tag for a signed-in viewer who is not on it (never a guest,
  a table, an entry tag or a draft); `<details>` confirm → POST `claim_participant`
  (`ClaimParticipantController`, `/en/claim-participant/{cid}/{pid}`, CSRF `participant-claim-<cid>`, limiter
  `participant_claim`) → `ClaimParticipant` (the handler decides: linked / requested / dispute / ask the organiser / the
  switch from the viewer's own row) → 303 + flash back to that competitor page (a paid own registration → the join page
  with the name pre-selected). JSON `competitor_state` unchanged. `found_via` / `?via=tag` cut (R10).
- **Scan landing, competitor pages off** (§6.4): `CompetitionTagLanding` sends a signed-in scanner who enters no results,
  of a person tag nobody is linked to, who is not going (`GetScanClaimTarget`, 1 statement only then), to the event page
  `?participant=<id>#taking-part` → the card for that person (name not required, same going filter). The golden
  `live-results-scan-matrix.txt` is unchanged: its puzzler is going at the Results Cup (Hugo) - `ScanClaimLandingTest`
  pins the new branch.
