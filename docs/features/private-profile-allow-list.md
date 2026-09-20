# Private profile allow list

Status: **built 2026-09-20** (phases 0-2 below). Migration `Version20260919220430` creates
`private_profile_viewer`. **Being added to a list triggers no notification - decided, not an
omission** (Jan, 2026-09-20). Not built: a "you see this because you are on their list" hint (D5).

A private ("hidden") puzzler picks friends who see them as if the profile were public: on the
profile and its sub-pages, on puzzle detail, in recent activity, in notifications. Everyone else
keeps seeing "Hidden Puzzler".

The one rule that shapes everything: **every mistake must fail towards hiding.** A friend who still
sees "Hidden Puzzler" somewhere is a bug report; a stranger who sees a name is an incident.

## How it works (as built)

- **Storage** - `PrivateProfileViewer` (`private_profile_viewer`): one row = *owner lets viewer see
  them*, always written by the owner. FK cascade + explicit delete in `DeletePlayerHandler`. Rows
  survive switching to public and mean nothing until the profile is private again. Cap 200/owner.
- **The only code that can unmask anybody: `Services\PrivateProfileAccess`.** Queries select
  `sqlIsPrivate('alias')` instead of the raw column (and `sqlIsPublic()` where a *personal* list
  dropped private players), so `isPrivate` on every result, in every template and in the API means
  **"hidden from this viewer"**. No template had to change. A query nobody converted keeps hiding.
- **Cost: no query of its own.** The ids ride on the viewer's own profile
  (`GetPlayerProfile::byUserId()` -> `PlayerProfile::$revealedPrivatePlayerIds`, exactly like the
  blocklist's `hiddenPlayerIds`); an API token shares ONE lookup with the blocklist
  (`ApiViewerRelations`). For a viewer on nobody's list the fragment is the bare `p.is_private` -
  SQL text and plans byte-identical to before. All pre-existing query-budget tests pass unchanged.
- **No viewer = nobody revealed**: guests (shared-cached pages), cron, consumers,
  `client_credentials` tokens. Admins are not let in by being admins.
- **A block in either direction outranks the list** - inside the id subquery
  (`sqlRevealedIdsOf()`), so an SQL-imposed admin block needs no clean-up. `BlockUserHandler` also
  removes the blocked player from the blocker's list. `AllowPrivateProfileViewerHandler`
  deliberately does *not* refuse a blocked pair: an error would give an admin block away.
- **Global rankings stay closed to private players for everybody**, friends included (ladders, MSP
  rating, fastest, most active "drop" queries, most-favourited, per-country, sitemap). Nobody is
  ranked differently for different viewers. `PrivateProfileQueryCoverageTest` lists each with its reason.
- **Notifications**: `NotifyWhenPuzzleSolved` tells followers of a private solver only if they are
  on the solver's list (`GetPrivateProfileViewers::followersAllowedBy()`). `GetNotifications`
  masks the solver in SQL for the *reader*, so taking somebody off the list re-masks the
  notifications they already have. The editor of a shared time stays named to the group, by design
  (group-time-editing.md).
- **Shared cache**: `PrivateProfileRevealedCacheSubscriber` forces `private, no-store` on any
  response rendered for a viewer who is on somebody's list.
- **Share image** `/result-image/{timeId}` is public and cached in storage: it follows the player's
  *own* setting, never the viewer (separate `-hidden.png` path so a copy rendered while public is
  not served).
- **UI**: edit profile -> Visibility -> "Choose who can see me" -> `/en/who-can-see-my-profile`
  (`Controller/PrivateProfile/*`): player picker, one-click suggestions from players the owner
  follows, list with Remove. Adding is members-only like the private profile itself; removing never is.

### Baseline leaks closed on the way (phase 0)

- Player search (`/puzzlers`, global search, pickers): a private player is found **by exact player
  code only**, without name/avatar/country - that is how they are added to a pair/team time.
  Players on their list find them like anybody else.
- Most-favourited board and players-per-country: private players left out. A followed private
  player stays in one's favourites by code only.
- Profile sub-pages (library, collections, solved/unsolved, wishlist, lend/borrow, sell/swap,
  ratings) printed the raw name in `<title>`/`<h1>` -> Twig `profile_name(player)`.
- Share image drew the name (above). Solved-puzzle notifications showed a private *tracker's* avatar.

Left as is, deliberately (the player acts in public or bilaterally): marketplace seller / reserved
for, transaction ratings, feature-request author, conversations, lending counterparties, organiser
tooling, own data export.

### Tests

- `tests/PrivateProfileCanaryTest.php` - crawls pages as guest, member, admin and friend: name
  nowhere for the first three, visible for the friend, friend's response never cacheable;
  global rankings show her to nobody; revocation; direction; block precedence; search rules.
  **Add every new page that lists players.**
- `tests/Services/PrivateProfileAccessTest.php` - viewers, exact fragments, `reset()` and its
  registration with the services resetter (worker mode!), blocks, self-row.
- `tests/PrivateProfileQueryCoverageTest.php` - no raw `is_private` in `src/` without a listed reason.
- `tests/Controller/PrivateProfileViewersControllerTest.php` - add -> visible -> remove -> hidden
  over HTTP, 422, CSRF, members gate, notification mask / re-mask.
- Handler, notification fan-out and API (`PlayerProfileEndpointTest`) tests.
- Mutation-checked 2026-09-20: reveal-to-everyone (22 failures), `reset()` no-op (1), blocks
  ignored (2).

---

*Below: the original research and plan, kept for the reasoning. Where it differs from "as built"
above (notably `ViewerPlayerRelations`, the search rule, ratings), the section above wins.*

## 1. What "private" means today (research)

`player.is_private` (members-only setting, `EditPlayerVisibility`). There is no chokepoint — three
different mechanisms, spread over ~45 files:

| Mechanism | Where | Count |
|---|---|---|
| **Drop the row** in SQL (`is_private = false`, sometimes `OR id = :me`) | rankings, MSP ladder, fastest players/pairs/groups, most active, stopwatch milestones, sitemap, affiliate supporters, WJPF sync, intelligence recalculation (deletes `player_elo`) | ~12 queries |
| **Select `is_private`, mask in Twig** (`secret_puzzler_name` + incognito icon; DTO still carries the real name) | recent activity, puzzle times, ladder table, solvings list, notifications, round results, competition participants, most-active, profile + header | 8 DTOs, ~15 template conditions, each written by hand |
| **Check in PHP** | `PuzzlesSorter::filterOutPrivateProfiles()` (puzzle detail: drops solo private rows, keeps a group if any member is public or the viewer took part), 4 profile sub-controllers (redirect to profile), `NotifyWhenPuzzleSolved` (private notifies nobody), API V0/V1 providers, `PuzzleLibraryVisibility` | ~12 classes |

The masked profile page still shows `Hidden Puzzler #CODE` and the country flag.

### 1.1 Private players are **not** hidden everywhere today

The sweep found surfaces that show a private player's real name/avatar to anyone, because they
never look at `is_private`. This matters here: "only my friends can see me" is only as true as the
baseline.

Look like plain bugs:

- **Player search** — `SearchPlayers::fulltext()`: public `/puzzlers`, global search, autocomplete return name, avatar, country, code, profile link.
- **Most-favourited board** on `/puzzlers` (`GetFavoritePlayers::mostFavorite()`), and favourite lists (`forPlayerId()`).
- **Players per country** (`GetPlayersPerCountry`).
- **Share image** `/result-image/{timeId}` — public, unauthenticated, draws `playerName` into the PNG (`GetResultImage.php:141`).
- **Profile sub-pages** — library, collections, solved, unsolved, wishlist, lend/borrow, sell/swap detail and the five `private.html.twig` fallbacks print `player.playerName` raw in `<title>`/`<h1>`; only the header component masks. `sell_swap_list_detail` has no visibility gate at all.
- **Ratings modal** `/en/player/{id}/ratings` — a private reviewer appears with name + avatar; the route checks nothing.

Arguably deliberate (the player acts in public or bilaterally): marketplace seller + "reserved
for", feature-request author/comments (indexed), conversations + Mercure chat payload, lending
counterparties, organiser tooling (participant management, table sheets, Excel export), own data
export naming private teammates. **D1** decides which of these to close.

### 1.2 Neighbouring work: the blocklist (in flight, same day)

`docs/features/player-blocklist.md` — `HiddenPlayers` is the mirror image of this feature
(viewer hides others; here an owner reveals themselves to chosen viewers). This plan copies its
shape on purpose: request-scoped `ResetInterface` service, SQL fragment that is an empty/unchanged
string for almost every viewer, canary crawl test + query coverage test. It must land **after** the
blocklist is committed — both touch the same ~20 queries.

## 2. Design

### 2.1 Storage

New entity `PrivateProfileViewer`, table `private_profile_viewer`:

| column | |
|---|---|
| `id` | uuid7 |
| `owner_id` | FK player, `ON DELETE CASCADE` — the private player |
| `viewer_id` | FK player, `ON DELETE CASCADE` — who may see them |
| `added_at` | datetime immutable (from `ClockInterface`) |

`UNIQUE (owner_id, viewer_id)`, `INDEX (viewer_id)` (the hot lookup), `INDEX (owner_id)`.

Not `player.favorite_players`: that is "I follow you" — the wrong direction (anyone can follow a
private player; consent must come from the owner) — and a JSON array gives no FK, no cascade, no
timestamp. **One row, one direction, owner-granted**, same as `user_block`.

Rows survive switching back to public (dormant), so toggling private again restores the list.

### 2.2 Read side: one service decides, SQL carries the answer

`SpeedPuzzling\Web\Services\PrivateProfileAccess` (`ResetInterface`, lazy, one index-only query per
request):

```sql
SELECT owner_id FROM private_profile_viewer WHERE viewer_id = :me
```

- `revealedIds(): list<string>` — private players who let the current viewer in.
- `isRevealed(string $playerId): bool`
- `sqlIsPrivate(string $alias): string` — **the effective privacy expression**:
  - viewer has no grants → exactly `{$alias}.is_private` (SQL text and plan unchanged);
  - otherwise → `({$alias}.is_private AND {$alias}.id NOT IN ('…'::uuid, …))`.
- `allowedViewersOf(list<string> $ownerIds)` — explicit lookup for async handlers, which have no viewer.

Viewer resolution is `HiddenPlayers`' (web `UserAccount`, API `ApiUser`). **No viewer → empty set**:
guests, cron, consumers, OAuth2 `client_credentials`. Ids are validated uuids from our own table
before being inlined. `/admin` is untouched (it never masked).

Then every existing use is rewritten mechanically, and **no template changes**:

| Today | Becomes |
|---|---|
| `SELECT p.is_private` / `'is_private', p.is_private` | `SELECT {$access->sqlIsPrivate('p')} AS is_private` — DTO `isPrivate` now means *hidden from this viewer*; every Twig mask keeps working |
| PHP: `$profile->isPrivate && not own` | unchanged — `GetPlayerProfile` selects the effective value, so the 4 sub-controllers, `PlayerHeader`, `PuzzlesSorter`, API providers, `PuzzleLibraryVisibility` all follow |
| `WHERE is_private = false` in **personal** lists (recent activity feed, stopwatch "favourites' best") | `WHERE NOT {$effective}` |
| `WHERE is_private = false` in **global ranked/aggregate** lists | **unchanged — out of scope**, see 2.3 |

Why this is the safe shape: the default everywhere stays *masked*. A query nobody converted keeps
hiding. The only code that can ever unmask is one ~80-line service, and that is where the tests
concentrate.

Two places need the raw fact, not the effective one, and must say so explicitly
(`PlayerProfile::$isPrivateProfile` raw vs `$isPrivate` effective — or the reverse naming, **D6**):
the owner's visibility form (owner is never in their own list, so it happens to work — but make it
explicit) and SEO output on the profile (`robots noindex`, JSON-LD, `og:image` must follow the
**raw** flag so a friend's browser/share preview never emits indexable identity).

### 2.3 Scope

**Friends see the private player in:** profile + all sub-pages (statistics, calendar, favourites,
compare, library, collections, solved/unsolved, wishlist — per-section visibility still applies),
puzzle detail times (solo + group; positions are computed over the viewer's list, so no gaps),
recent activity (homepage, feeds, profile), solved-puzzle notifications, names inside pair/team
times, round results + competition participant lists, favourites/followers lists, API V1
`/players/{id}/*` for a user token belonging to a friend.

**Unchanged for everybody (private stays out):** global rankings, MSP rating ladder (`player_elo`
rows do not even exist), fastest players/pairs/groups, most active, sitemap, WJPF sync, affiliate
supporters, Puzzle Insights inputs. Per-viewer ranks would mean per-viewer positions and queries the
blocklist's performance contract already ruled out. The owner's "rankings need a public profile"
card stays true.

### 2.4 Notifications

`NotifyWhenPuzzleSolved` today: private solver → nobody. New: for each private solver, recipients =
followers ∩ `allowedViewersOf(solver)`; public solvers as before; one notification per recipient.
`GetNotifications` selects the effective expression (viewer = the reader), so the row unmasks only
for a friend and **re-masks the moment they are removed** — nothing identifying is stored in the
notification row. Blocklist gates still apply first.

### 2.5 Shared cache

`AnonymousCacheHeadersSubscriber` caches guest HTML by URL for 60 s, no `Vary`. Safe by
construction: a guest has no grants → byte-identical SQL → identical page. Belt and braces: when
`PrivateProfileAccess` resolved a **non-empty** set during a request, a response subscriber forces
`Cache-Control: private, no-store`. Test it (section 4).

`/result-image/{timeId}` is fetched by crawlers without cookies → always masked for private players
(name omitted), regardless of allow list. Fixing that is part of phase 0.

### 2.6 Performance contract

Same contract as the blocklist: **whoever has no grants pays nothing.**

| Viewer | Extra queries | Query text / plan |
|---|---|---|
| Guest, crawler, cron, consumer (the bulk of traffic, mostly served from the 60 s shared cache) | **0** — no viewer, the lookup never runs | byte-identical |
| Signed-in, nobody granted them anything (nearly everyone) | **1** index-only lookup on `private_profile_viewer (viewer_id)`, lazy — only on requests that reach a converted query, once per request | byte-identical (`p.is_private`) |
| Signed-in friend of N private players | the same 1 | `p.is_private AND p.id NOT IN (N constants)` |

- The friend's variant is a constant-list filter on a row the query **already reads** — no join, no
  subquery, no extra bound parameter. No index is lost: nothing indexes `is_private` today
  (checked `migrations/` + `docs/database-indexes.md`), it is always a residual filter.
- **Rejected:** `LEFT JOIN private_profile_viewer` / `NOT EXISTS (…)` inside each query. It would
  change the plan of ~30 hot queries for every visitor, including guests, to serve a handful of
  friends.
- The dropped-row lists that stay global (rankings, ladder, fastest, most active) are not touched
  at all, so the heaviest queries on the site keep their text, plans and any result reuse.
- Puzzle detail: `GetPuzzleSolvers` already returns private rows and filters in PHP
  (`filterOutPrivateProfiles`) — no SQL change in shape, the friend simply keeps a few more rows.
- N is bounded in practice (how many private members befriend one person); the per-owner cap of 200
  bounds the write side. If N ever exceeds ~100 for someone, switch that viewer's fragment to
  `<> ALL (:ids::uuid[])` — same plan, one parameter.
- Optional, only if the one lookup ever shows up: fold `EXISTS (SELECT 1 FROM
  private_profile_viewer WHERE viewer_id = player.id)` into the logged-user profile query that
  already runs on every signed-in request, and skip the lookup when false → 0 extra queries for
  almost all members.
- Notifications: `allowedViewersOf()` is one query per solved-puzzle event, async, only when a
  solver is private (today that path returns early).
- Forced `private, no-store` (2.5) applies only to responses for viewers with a non-empty set, which
  were never shared-cached anyway (they carry a session).

**Verification (phase 1, before the UI exists):** test 5 of section 4 pins the SQL text; on prod,
`EXPLAIN (ANALYZE, BUFFERS)` recent activity, puzzle solvers and notifications with a synthetic
20-id list vs. without; compare p95 of the homepage and puzzle detail for a day after the dark
deploy (empty table ⇒ any movement is a bug).

### 2.7 Interaction with the blocklist

Block wins, always, in both directions:
- viewer hides owner (`HiddenPlayers`) → 404/filtered before privacy is even evaluated;
- owner blocks X, or an admin block `(blocker = X, blocked = owner)` exists → `AllowPrivateProfileViewer`
  refuses X and `BlockUserHandler` deletes an existing grant. `revealedIds()` additionally subtracts
  `HiddenPlayers::ids()` so an SQL-inserted admin block needs no cleanup step.

### 2.8 Write side + UI

- Messages/handlers: `AllowPrivateProfileViewer(ownerId, viewerId)`, `RevokePrivateProfileViewer(ownerId, viewerId)`.
  Validate before mutating: not self, viewer exists, not blocked either way, owner has membership,
  cap 200 per owner, idempotent add. `DeletePlayerHandler` relies on the FK cascade (+ a test).
- Query `GetPrivateProfileViewers::ofOwner()` — settings list only.
- **Edit profile → Visibility card**: when "Private profile" is on, the card shows "Who can see
  me": the list with *Remove*, a player picker (`PlayerSearchAutocompleteController`, the one reused
  for maintainers/moderators), and one-click suggestions from *people I follow* / *my followers*.
  Single-action controllers, PRG (no 200 answers to form POSTs), English only, texts via translation keys.
- Copy states plainly what friends will see, and that global rankings stay hidden.
- Optional, **D4**: tell the friend ("X lets you see their private profile") — a target-less
  notification, which needs its own `GetNotifications` branch + Twig `elseif`.

## 3. Phases

0. **Close baseline leaks** chosen in D1 (separate commits, each with a test). Search, per-country,
   most-favourited, result image, sub-page titles, ratings modal at minimum.
1. **Dark read side**: entity + generated migration, `PrivateProfileAccess`, convert every
   `is_private` site per 2.2, cache subscriber, notifications handler, all tests of section 4 with
   fixture grants. With an empty table production behaviour is provably unchanged — deploy and
   watch.
2. **Write side + UI**, blocklist hooks, docs (`CLAUDE.md` entry, this file → reference doc),
   `.claude/fixtures.md`.
3. Optional: friend notification (D4), "visible to you because you're on their list" hint on the
   profile (D5).

## 4. Test plan

Fixtures: `PLAYER_PRIVATE` ("Jane Smith", already has solo times incl. a puzzle minimum, a team
time with `PLAYER_REGULAR`, a team time owned by someone else, collections, wishlist, a competition
participant). Rename her to a unique sentinel (e.g. `Zzyzx Privatova`) + give her an avatar, so a
raw string search over any response body is meaningful. Add: grant `PLAYER_PRIVATE →
PLAYER_WITH_FAVORITES`, who also follows her. `PLAYER_REGULAR` stays an **un-granted teammate**,
`PLAYER_WITH_STRIPE` an un-granted member, `PLAYER_ADMIN` un-granted.

1. **`PrivateProfileAccessTest`** — guest / owner / granted / un-granted / PAT user / OAuth2 user /
   `client_credentials` / cron (no request); fragment text is exactly `p.is_private` for an empty
   set; non-uuid rows never reach SQL; **`reset()` clears the set** (worker mode: a stale set would
   unmask for the *next visitor* — the single worst failure this feature can have); direction
   (grant A→B reveals A to B, never B to A); block subtraction.
2. **`PrivateProfileCanaryTest`** — the main guard. One URL list (homepage, recent activity,
   puzzle detail solo/duo/team of her puzzles, `/puzzlers` + search + autocomplete JSON, country
   page, ladders, round results, competition participants, her profile + every sub-page, ratings
   modal, notifications, favourites/followers pages, marketplace, API V1 `/players/{id}/*` +
   `/me/favorites|followers`, result image response). Crawled as **guest, un-granted user,
   un-granted member, un-granted teammate, admin outside `/admin`, friend, owner**. Asserts for the
   first five: sentinel name, avatar path and `player_profile` link with her id appear **nowhere**.
   For friend: appear where 2.3 says, absent where 2.3 says unchanged. For friend responses:
   `Cache-Control` is never `public`. *Add every new player-listing page to it.*
3. **Revocation** — grant → visible → revoke → the same requests are masked again, including an
   already-created notification.
4. **`PrivateProfileQueryCoverageTest`** — no `src/` file outside `PrivateProfileAccess` contains a
   raw `is_private` literal unless listed with a reason (the "unchanged" set of 2.3, the recalculator).
   New queries cannot silently bypass the service.
5. **Byte-identical SQL** — for a viewer with no grants, captured SQL of 3–4 hot queries equals the
   pre-feature text (protects the cache argument and performance).
6. Handler tests (self, blocked either way, non-member, cap, idempotency, cascade on delete),
   `NotifyWhenPuzzleSolved` (only granted followers; group with one private + one public member;
   blocker excluded), blocklist precedence.
7. Existing privacy tests (`GetCompetitionParticipantsPrivacyTest`, `GetPlayerRatingRankingPrivacyTest`, …) must pass untouched.

Manual before enabling the UI: two real browsers on the local stack (friend / stranger) over the
canary URL list, plus one guest request *right after* the friend's to the same URL.

## 5. Open decisions

- **D1** Which baseline leaks of 1.1 to close, and whether marketplace / feature requests /
  messaging should mask private players at all. *Recommendation: close the "plain bugs" list now;
  leave bilateral + marketplace as is and say so in the setting's help text.*
- **D2** Masked profile keeps showing `#CODE` + flag — intended? *Assumed yes.*
- **D3** Should a friend see the private player in global ladders? *Recommendation: no (2.3).*
- **D4** Notify the friend when added? *Recommendation: yes, phase 3 — otherwise the grant is invisible.*
- **D5** Does an un-granted **teammate** see the name on their shared time? Today: no. *Keep.*
- **D6** Naming of raw vs effective flag on `PlayerProfile`.
- **D7** Membership lapses while private: today `is_private` stays on; the list would too. *Keep.*
