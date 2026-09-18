# Editing pair / team times

A pair or team result is one `puzzle_solving_time` row: `player_id` is whoever tracked it, the group lives in the `team` JSON. **Every registered member of the group may edit the row; only the tracker may delete it.**

## The rule

`PuzzleSolvingTime::canBeModifiedBy(Player)` — the tracker, or any puzzler in `team` whose `player_id` matches. Puzzlers stored by name only have no account and never match. The read side mirrors it with `isEditableBy(playerId)` on `SolvedPuzzleDetail`, `SolvedPuzzle`, `RecentActivityItem` and `PuzzleSolversGroup` (all via `Puzzler::listContainsPlayer()`); templates ask that method, never compare ids themselves.

Enforced in `EditPuzzleSolvingTimeHandler`, `EditTimeController` (403) and `UpdateSolvingTimeProcessor` (API). `DeletePuzzleSolvingTimeHandler` still compares against the tracker, and the delete controls are hidden from other members.

## Invariants of an edit by a non-tracker

- The group is **assembled around the tracker**, not around the editor (`assembleGroup($solvingTime->player, …)`): the tracker stays first puzzler and cannot be removed, `player_id` never changes.
- Access is checked against the group **as stored before the edit** — a member removing themselves finishes that edit and then loses access.
- An uploaded photo is stored under the tracker's folder.
- API `PUT` replaces the whole group: omitting `group_players` turns the row into the tracker's solo time (unchanged PUT semantics, but now reachable by a member too).

## Notification

Any edit of a group time records `GroupSolvingTimeEdited` (async). `NotifyWhenGroupSolvingTimeEdited` notifies every registered member **before or after** the edit except the editor (`NotificationType::GroupSolvingTimeEdited`), skipping members who still hold an unread one for the same time and editor.

The editor is stored in `notification.actor_player_id` (nullable, cascade). `GetNotifications` joins the "target player" as `COALESCE(actor_player_id, puzzle_solving_time.player_id)`, so for this type the target player is the editor.
