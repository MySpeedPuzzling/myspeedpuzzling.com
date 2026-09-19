# Player blocklist

A player can stop seeing another player — everywhere, not just in messages. It exists because a
member asked for it while being stalked in real life, so **the blocked side must never be told, and
must never be able to tell from an error or a message**.

## The model: one row, one direction

`user_block` (entity `UserBlock`) means exactly one thing: **the `blocker` must not see the
`blocked` player**. It predates this feature (it was the messaging block) and every earlier row is
now a site-wide block.

| `source` | Who created it | Shown in the blocker's settings | Blocker can remove it |
|---|---|---|---|
| `self` | the blocker, from a profile's menu | yes | yes |
| `admin` | an admin, by SQL, to protect the *blocked* player | **no** | **no** (`UnblockUserHandler` answers as if there were no block) |

So the two halves of "we no longer see each other" are two independent rows:

- *I stop seeing you* — self-service: `(blocker = me, blocked = you, source = self)`.
- *You stop seeing me* — admin only: `(blocker = you, blocked = me, source = admin)`.

`note` is free text for the admin's audit trail (who asked, why, ticket). Account deletion removes
blocks in both directions (`DeletePlayerHandler::deleteUserBlocks()`).

### Imposing a block as an admin (runbook)

There is deliberately no UI. On the box:

```sql
INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source, note)
VALUES (gen_random_uuid(), '<player who must stop seeing>', '<protected player>', NOW(), 'admin',
        'Requested by <protected player> on 2026-09-19 via e-mail, stalking. -- JM')
ON CONFLICT (blocker_id, blocked_id) DO UPDATE SET source = 'admin', note = EXCLUDED.note;
```

The `ON CONFLICT` arm matters: if the blocker had blocked the protected player themselves, the row
turns into an admin block and silently leaves their settings list. To lift it, `DELETE` the row.

**What it cannot do.** Filtering needs a signed-in viewer. Signed out — or on a second account —
the blocked-from player sees what any guest sees, and can notice the difference. Guest pages are
shared-cached and cannot depend on who is looking. For a real safety case, pair the admin block
with a private profile (`player.is_private`) and advise a new display name/player code.

## Read side: `HiddenPlayers`

`SpeedPuzzling\Web\Services\HiddenPlayers` holds the ids the current viewer must not see. It is
request-scoped (`ResetInterface`), resolved lazily from the security token:

- **Web (`UserAccount`)** — no query of its own. `GetPlayerProfile::byUserId()`, which every page
  already runs for `logged_user`, carries the ids as a `json_agg` subquery
  (`PlayerProfile::$hiddenPlayerIds`, filled for the signed-in player's own profile only).
  `HiddenPlayers` reaches `RetrieveLoggedUserProfile` through a service closure, because the
  dependency is circular (`RetrieveLoggedUserProfile → GetPlayerProfile → HiddenPlayers`).
- **API (`ApiUser`, PAT / OAuth2)** — one index-only lookup per request, on first use. The API
  query budgets in `tests/Controller/Api` include it.

- **No viewer → nothing hidden**: guests, cron, async consumers.
- **`/admin` and `/internal-api` → nothing hidden**: moderation sees everyone.

### Performance contract

Almost every viewer hides nobody. For them `sqlExclude()` / `sqlExcludeTeam()` return `''`, so
**the SQL text and plan of every query are byte-identical to what they were without the feature**.
A viewer with blocks gets a constant `NOT IN ('…'::uuid, …)` list (ids are validated uuids from our
own table, inlined so the ~50 embedding queries need no extra bound parameter) — a filter on rows
the query already reads, no join, no subquery.

### Rules for queries (`src/Query`)

There is no chokepoint on the read side — every query is hand-written SQL — so each query that
returns *other players' identity* embeds the fragment itself:

```php
$notHidden = $this->hiddenPlayers->sqlExclude('player.id');
// … WHERE … {$notHidden}
```

1. **Filter inside the ranked set.** Where a rank / position / total is computed (`RANK()`,
   `COUNT(*) OVER`, count subqueries), the fragment goes into the *same* subquery or CTE the window
   runs over, so positions close up instead of showing a gap.
2. **Pair/team times: `sqlExcludeTeam('pst.team')`**. A time is dropped when a hidden player is
   among its `puzzlers` — unless the viewer took part too (their own history stays whole). Do not
   combine it with `sqlExclude('pst.player_id')` on group queries: the tracker is always a member,
   and the combination would defeat the took-part exception.
3. **LEFT JOINed player columns are fine** — the fragment lets `NULL` through.
4. **The column must be a uuid expression**; cast JSON text first.
5. **Aggregates are out of scope** (solved counts, medians, difficulty, "N players"). They carry no
   identity, several are precomputed tables, and per-viewer variants would cost what the contract
   above forbids. Known, accepted leak: a count can be one higher than the rows shown.
6. **Bilateral history stays readable**: lending/borrowing, completed sales, pending ratings and
   an open conversation keep showing a counterparty the viewer later blocked. (The *public* review
   list on a profile, `GetTransactionRatings::forPlayer()`, does hide a blocked reviewer.)
7. **Organiser/admin tooling is not filtered** (participant management, table layout, maintainers
   picker — `SearchPlayers::fulltext(includeHidden: true)`): blocking someone must not make them
   unassignable at an event you run.

### Profiles

`GetPlayerProfile::byId()` throws `PlayerNotFound` for a hidden player. Every profile page,
sub-page (statistics, collections, library, compare…) and `/api/v1/players/{id}/*` resource
resolves its subject through it, so all of them answer the 404 a deleted player gets.

## Write side

Nothing is created *about* a hidden player *for* the viewer who hides them:

- `NotifyWhenPuzzleSolved` skips subscribers who block the solver (or any registered group member).
- `NotifyWhenGroupSolvingTimeEdited` skips members who block the editor.
- Both may run async, where there is no viewer, so they ask `GetUserBlocks::blockersOf()` rather
  than `HiddenPlayers`. Notifications that already exist are filtered on read
  (`GetNotifications`, list and unread badge alike).
- Messaging was already covered (`StartConversationHandler`, `NotifyOnChatMessageSent`, unread
  digest).

Blocking also drops the blocked player from the blocker's favourites. The *blocked* player's
favourites are never touched — that would be a tell — they are filtered on read instead.

## UI

- **Block**: the last, divider-separated item of the profile header menu
  (`components/PlayerHeader.html.twig`) → confirmation modal → `POST /en/block-user/{playerId}`.
  After blocking from a profile the visitor lands on their own profile (the blocked one is a 404
  for them from now on).
- **Blocked players**: a section of edit-profile listing `source = self` rows with *Unblock*;
  `/en/blocked-users` remains as the standalone list linked from messaging.
- Copy promises only what a self-service block does (*you* stop seeing *them*), and points people
  who need the other direction to support.

## Tests

- `tests/Services/HiddenPlayersTest.php` — viewer resolution, fragments, admin-area bypass.
- `tests/TestingViewer.php` — signs a fixture player in for a kernel test (queries hide nobody
  without a viewer). **Fixture trap:** `UserBlockFixture` has PLAYER_REGULAR block PLAYER_PRIVATE,
  so a test that needs REGULAR to see PRIVATE must use another viewer or delete the block.
- `tests/BlocklistCanaryTest.php` — signs in as a blocker and crawls the main player-listing pages
  asserting the blocked player's id/code never appears, and that a guest still sees them. **Add
  every new page that lists players to it.**
- `tests/BlocklistQueryCoverageTest.php` — every `src/Query` class that joins `player` must use
  `HiddenPlayers` or be listed (with a reason) in the test's allowlist.
- Handler tests for the admin-block guard and the notification gates.
