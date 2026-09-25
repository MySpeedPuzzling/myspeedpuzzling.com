# Community moderators

Trusted community members who help maintain the puzzle catalogue without being admins.

## What a moderator can do

| Area | Moderator | Admin |
|------|-----------|-------|
| Puzzle change requests — list, detail, approve, reject (`/admin/puzzle-change-requests*`) | yes | yes |
| Puzzle merge requests — list, detail, approve, reject (`/admin/puzzle-merge-requests*`) | yes | yes |
| Approving newly added puzzles, incl. brand approve / merge (`/admin/puzzle-approvals*`, [puzzle-approvals.md](puzzle-approvals.md)) | yes | yes |
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
- `config/packages/security.php` — `^/admin/puzzle-((change|merge)-requests|approvals)` requires
  `PUZZLE_MODERATION_ACCESS` and **must stay above** the `^/admin` → `ADMIN_ACCESS` rule (first match wins).
- The puzzle-review controllers (change, merge and approval queues) carry `#[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]`;
  every other admin controller stays on `ADMIN_ACCESS`.
- `PlayerProfile::$isModerator` (from `GetPlayerProfile`) is what the voter reads.
- The top-bar key menu shows for `PUZZLE_MODERATION_ACCESS`; the admin-only entries inside it are
  wrapped in `is_granted('ADMIN_ACCESS')`.

## Managing moderators

`/admin/moderators` (admins only, key menu → "Community Moderators"):

- **Add** — the same player picker as the event form's "maintainers" field (`AddModeratorsFormType`,
  TomSelect fed by `player_search_autocomplete`), several players at once → one `GrantModeratorRole` each.
- **List** — `GetModerators`: since when, how many change/merge requests they reviewed *since the role
  was granted*, and their last review — the numbers to look at before revoking someone.
- **Revoke** — `POST /admin/moderators/{playerId}/revoke` (CSRF) → `RevokeModeratorRole`. Takes effect
  on the moderator's next request; there is nothing cached to invalidate.

## Tests

`tests/Controller/Admin/ModeratorAccessTest.php` (reach / forbidden matrix, menu, appoint + revoke,
a moderator deciding a request), `tests/MessageHandler/GrantModeratorRoleHandlerTest.php`,
`tests/Query/GetModeratorsTest.php`.

## Telling the player

- **Granted** → in-app notification (`NotificationType::ModeratorRoleGranted`) **and** a welcome e-mail
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
- A public "badge of honor" for moderators is planned, not built.
