# Community moderators

Trusted community members who help maintain the puzzle catalogue without being admins.

## What a moderator can do

| Area | Moderator | Admin |
|------|-----------|-------|
| Puzzle change requests — list (filters by source / tag), detail, approve, reject, the picture review grid (`/admin/puzzle-change-requests*`) | yes | yes |
| Puzzle merge requests — list, detail, approve, reject (`/admin/puzzle-merge-requests*`) | yes | yes |
| Approving newly added puzzles, incl. brand approve / merge (`/admin/puzzle-approvals*`, [puzzle-approvals.md](puzzle-approvals.md)) | yes | yes |
| Editing any puzzle directly + its history with "Restore this picture" (`/admin/puzzles/{id}/edit`, `/admin/puzzles/{id}/history*`, [puzzle-approvals.md](puzzle-approvals.md)) | yes | yes |
| "Go to admin" links on a puzzle's pending proposals | yes | yes |
| Everything else under `/admin` (vouchers, referrals, moderation, e-mail audit, OAuth2, competition approvals) | **no (403)** | yes |
| Appointing / removing moderators (`/admin/moderators`) | **no (403)** | yes |

A moderator's decisions are recorded exactly like an admin's: `reviewed_by_id` on the request, for merges the
`puzzle_merge_audit` row (merges are destructive — see `docs/features/internal-api.md`), and for **every** decision
a `puzzle_moderation_decision` row that survives the request, the puzzle and the player
([puzzle-approvals.md](puzzle-approvals.md#who-decided---puzzle_moderation_decision)).

## Data model

`player.moderator_since` (nullable timestamp). `NULL` = not a moderator; the value is when an admin
granted the role. No separate entity, no role table — same shape as `referral_program_joined_at`.
Granting twice keeps the original date; revoking sets it back to `NULL`.

`player.is_admin` is untouched by any of this and still has no UI (SQL only).

## Authorization

- `PuzzleModerationVoter` — attribute `PUZZLE_MODERATION_ACCESS`, granted to admins **and** moderators.
  The attribute names the *capability* (looking after the puzzle catalogue), not the role: when an area
  outside the catalogue is opened to moderators, give it its own attribute/voter instead of widening this one.
  Puzzle approvals (2026-09-25) are catalogue work and share it.
- `SuspiciousResultsVoter` — the first area outside the catalogue: `REVIEW_SUSPICIOUS_TIMES` (the time verification
  queue `/admin/time-verification`, [suspicious-time-review.md](suspicious-time-review.md#moderator-queue---admintime-verification)),
  admins **and** moderators. Its own rule `^/admin/time-verification`, above `^/admin` as well.
- `config/packages/security.php` — `^/admin/puzzle(s/|-((change|merge)-requests|approvals))` requires
  `PUZZLE_MODERATION_ACCESS` and **must stay above** the `^/admin` → `ADMIN_ACCESS` rule (first match wins).
- The puzzle-review controllers (change, merge and approval queues) carry `#[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]`;
  every other admin controller stays on `ADMIN_ACCESS`.
- `PlayerProfile::$isModerator` (from `GetPlayerProfile`) is what the voter reads.
- The top-bar key menu shows for `PUZZLE_MODERATION_ACCESS`; the admin-only entries inside it are
  wrapped in `is_granted('ADMIN_ACCESS')`.

## Becoming a moderator - invitation, guide, pledge (2026-10-20)

An admin no longer grants the role directly. Adding players on `/admin/moderators` **invites** them; the role comes
only when the player has read the moderator guide and agreed to its pledge. Jan: moderators must know what we ask of
them before they start, and we keep a record that they agreed.

1. **Invite** - `/admin/moderators` (admins only, key menu → "Community Moderators"): the same player picker as the
   event form's "maintainers" field (`AddModeratorsFormType`, TomSelect fed by `player_search_autocomplete`), several
   players at once → one `InviteModerator` each. `InviteModeratorHandler` stores a `moderator_invitation` row (player,
   invited by, when), an in-app notification (`ModeratorInvitationReceived`, target-less) and an e-mail
   (`emails/moderator_invitation.html.twig`, `moderator_invitation.*` in all 6 locales) - both lead to the guide.
   A moderator, or a player with an open invitation, is skipped (nobody is asked twice).
2. **Read** - the guide `moderator_guide` (`/en/moderator-guide`, `ModeratorGuideController`, English only like the
   review pages, `noindex`): why we need moderators, what we ask of them, every tool and where it is, how each review
   works with examples, wording towards players, what cannot be undone, where to ask. Readable by players with an open
   invitation, moderators and admins (key menu → "Moderator guide"); 403 for everybody else.
3. **Accept or decline** - an invited player gets the pledge at the end of the guide: a checkbox
   ("… leave it better than I found it", must be ticked - 422 otherwise) + "Accept and become a moderator"
   → `AcceptModeratorInvitation`: the invitation is marked accepted with **when** (`answered_at`) and **which guide**
   (`agreed_guide_version` = `ModeratorGuide::VERSION`), then `GrantModeratorRole` runs in the same transaction
   (`player.moderator_since`, `ModeratorRoleGranted` notification, welcome e-mail - now with a link back to the guide).
   "Not now" → `DeclineModeratorInvitation` (nobody is told; the admins see "Declined" and may ask again later).
4. **Withdraw** - an admin takes back an open invitation (`WithdrawModeratorInvitation`, CSRF); the player is not told,
   the guide simply stops offering the pledge.

The motto is **"Leave it better than you found it."** (`ModeratorGuide::MOTTO`). Bump `ModeratorGuide::VERSION` when
what we ask of moderators changes, not for wording - accepted invitations keep the version they agreed to.

`GrantModeratorRole` is now reached only through an accepted invitation (and from tests, which use it to set up a
moderator); the admin page never dispatches it.

### `moderator_invitation`

One row per invitation, kept after the answer - the history of who was asked, by whom, and what they said.
`accepted` null = no answer (pending, or withdrawn - `withdrawn_at`), true = accepted, false = declined.
`player_id` cascades with the player, `invited_by_id` is set null when the admin's player is deleted.

### The admin page

- **Invitations** - open ones first ("Waiting for an answer", Withdraw), then the declined and withdrawn ones
  (`GetModeratorInvitations::notAccepted()`, newest first, 50).
- **Moderators** - `GetModerators`: since when, **when they agreed to the guide** ("before invitations" for those
  appointed before 2026-10-20), how many change/merge requests they reviewed *since the role was granted*, their last
  review and karma - the numbers to look at before revoking someone.
- **Revoke** - `POST /admin/moderators/{playerId}/revoke` (CSRF) → `RevokeModeratorRole`. Takes effect
  on the moderator's next request; there is nothing cached to invalidate. Their accepted invitation stays as history;
  asking them back later is a new invitation.

## Badge of honour on the profile

Every player header (`components/PlayerHeader.html.twig`) shows a soft pill on the `#CODE` line, for everybody to see:
**"MySpeedPuzzling team"** for admins (`player.is_admin`), else **"Community moderator"** for moderators - one of them,
the team first. Not an achievement and gives nothing; it says the person looks after the community. Read from the
profile row (`PlayerProfile::$isAdmin` / `$isModerator`) - no query of its own; not shown on a private profile hidden
from the viewer. Style `.player-head-role` (`_progression-profile.scss`), labels `player_header.role_*` in all 6 locales.

## Tests

`tests/Controller/Admin/ModeratorAccessTest.php` (reach / forbidden matrix, menu, invite → accept → revoke,
a moderator deciding a request), `tests/MessageHandler/ModeratorInvitationHandlersTest.php` (invite, twice, accept,
decline, withdraw), `tests/Controller/ModeratorGuideControllerTest.php` (who may read it, the pledge, decline),
`tests/Controller/PlayerRoleBadgeTest.php`, `tests/MessageHandler/GrantModeratorRoleHandlerTest.php`,
`tests/Query/GetModeratorsTest.php`.

## Telling the player

- **Invited** → in-app notification (`NotificationType::ModeratorInvitationReceived`) + the invitation e-mail, both
  leading to the guide (above).
- **Granted** (= accepted) → in-app notification (`NotificationType::ModeratorRoleGranted`) **and** a welcome e-mail
  (`emails/moderator_role_granted.html.twig`, `moderator_role_granted.*` in `emails.en.yml`): what they
  can now do, links to both queues, and a word of care about merges being irreversible. Sent by
  `GrantModeratorRoleHandler`, only when the role is newly granted — granting twice welcomes once.
- **Revoked** → in-app notification only (`ModeratorRoleRevoked`). No e-mail on purpose: parting ways
  deserves a personal message from an admin, not a template.
- These notifications have **no target entity**. `GetNotifications` is a UNION with one branch per
  target FK, so they have their own branch selecting by `notification.type`; a new target-less type
  must be added to that `IN (...)` list or it is stored but never shown. `notifications.html.twig`
  ends in an `else` that renders a solving-time row, so the moderator branch must stay an `elseif`.

## Decisions

- A moderator may decide their **own** proposal — they are trusted (Jan, 2026-09-18).
- The badge of honour on the profile shows moderators **and** admins (Jan, 2026-10-20) - above.
- No moderator was appointed before invitations existed (Jan, 2026-10-20), so every moderator has an agreement; "before invitations" on the admin page is only a fallback.
