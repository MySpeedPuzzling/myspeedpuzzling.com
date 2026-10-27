# Solving-time sync for API clients + the graveyard of deleted times

Shipped 2026-10-27. Trigger: Greg (Solventory, a Windows desktop app that creates solves through OAuth + PKCE) asked
how to check that the solves his app created still exist (mail "API questions", 2026-10-22). Jan's calls of
2026-10-27: deliver DELETE + lookup, keep every deleted time in a graveyard (disaster recovery - the database backups
go back 7 days only), 24 months, wiped on account deletion, 500 ids per lookup, "deleted since" for clients.

## Endpoints (public API v1)

| | scope | what |
|---|---|---|
| `DELETE /api/v1/me/solving-times/{timeId}` | PAT / `solving-times:write` | `DeletePuzzleSolvingTime` with `via = api` - the web's rules: only the tracker (a pair/team member → 403 `CanNotModifyOtherPlayersTime`), XP compensation + badges come with the handler. `204`; `404` = unknown or already gone (clients treat it as deleted, safe to retry) |
| `POST /api/v1/me/solving-times/lookup` | PAT / `results:read` | body `{"time_ids": [...]}` (1-500 UUIDs, de-duplicated, case-insensitive; else 422). Read-only - POST only because 500 ids do not fit a URL. Every id lands in exactly one list: `found` (`time_id`, current `puzzle_id` - changes on a puzzle merge, `type` solo/duo/team, `tracked_by_me`), `deleted` (`time_id`, `deleted_at` - from the tombstones), `missing` (unknown, somebody else's - never told whose -, or deleted before 2026-10-27) |
| `GET /api/v1/me/solving-times/deleted` | PAT / `results:read` | the owner's tombstones oldest first, 1000 a page: `count`, `deleted[]` (`time_id`, `deleted_at`), `next_cursor` (null on the last page). `since` (ISO 8601, inclusive) and `cursor` (opaque: base64url of `Y-m-d H:i:s|uuid`, keyset on `(deleted_at, solving_time_id)` - deletions share a second). Bad values = 422 |

Rate limit `api_solving_time_sync`: 60 requests per minute per token owner, shared by lookup + deleted (429 +
`Retry-After`, `SolvingTimeSyncRateLimit`); a 422 never consumes it. DELETE is not limited beyond the policy.

"The owner's times" = tracked by them or a pair/team result they are a registered member of - the same times their
`GET /me/results` lists show. Suspicious times included (own results).

Performance: the lookup is two statements whatever the number of ids - the times by primary key (`id = ANY(uuid[])`,
membership checked on the ≤ 500 rows found), then the owner's tombstones of the rest; the deleted list is served by the
`(player_id, deleted_at, solving_time_id)` index alone. `GET /me/results` stays the full list (every attempt of the
type, no pagination, `count` = total) - unchanged.

## Graveyard (`solving_time_graveyard`, entity `SolvingTimeGrave`) - never read

One row per deleted time: `solving_time_id`, `player_id` (tracker), `deleted_at`, `deleted_via`
(`SolvingTimeDeletedVia`: `web`, `api`, `duplicate_kept_copy`, `duplicate_auto_removal`) and `snapshot` (jsonb =
`RemovedResultSnapshot::of($time)->toArray()` - every column, the group JSON, photo path, prediction). Plain ids, no FKs:
a grave outlives the time, its puzzle and its players.

- **One writer:** `PuzzleSolvingTimeRepository::delete($time, $via)` buries the time and writes the tombstones in the
  deleting transaction. `SolvingTimeDeletionCoverageTest` fails on any other file that removes entities next to
  `PuzzleSolvingTime`, and on SQL deleting solving times.
- **Nothing in the app reads it.** The finished-puzzle photo is not deleted with a time, so the snapshot's path still
  points at the file.
- **Account deletion** (`DeletePlayerHandler`) erases the player's times **without** a grave and deletes their graves
  and tombstones (`SolvingTimeGraveyardRepository::eraseOfPlayer()`) - right to erasure. A grave of somebody else's
  pair/team time still names the deleted player inside its group snapshot (like the live time names them as a guest).
- **Retention:** daily `myspeedpuzzling:prune-solving-time-graveyard 24` (lily.srv cron, 03:55) - graves and
  tombstones older than 24 months.

### Restore runbook

```sql
-- what is there
SELECT id, solving_time_id, deleted_at, deleted_via, snapshot->>'puzzle_name'
FROM solving_time_graveyard WHERE player_id = '<player uuid>' ORDER BY deleted_at DESC;
```

The snapshot is the shape `UndoAutoRemovalHandler` restores from (`RemovedResultSnapshot::fromArray()` →
`PuzzleSolvingTime::restore()`): for more than a one-off, write a small command that does the same (the puzzle must
still exist - follow `puzzle_redirect` for a merged one - and the group's players). Restoring a time means removing its
tombstones too, else API clients keep reporting it deleted.

## Tombstones (`solving_time_tombstone`, entity `SolvingTimeTombstone`) - read by the API

Only `solving_time_id`, `player_id`, `deleted_at`: one row per registered member of the deleted time. Removed when the
time comes back (Undo of an automatic removal - `SolvingTimeGraveyardRepository::removeTombstonesOf()`; the grave
stays as history), on account deletion of that member and by the prune.
