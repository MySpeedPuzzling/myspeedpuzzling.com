# Pairs & teams

**Status: LIVE since 2026-09-20** (phases 1–4 of [`implementation-plan.md`](implementation-plan.md), history backfilled on production, all 6 locales). The add-form picker is public; `PAIRS_TEAMS_PICKER_PUBLIC=0` is the kill switch back to the old rows (`docs/features/feature_flags.md`).

Turns the people a time was solved with into a first-class thing: every pair / team result belongs to a
`puzzling_team` row, the add-time form gets a fast picker built on it, players get a "Pairs & teams" page,
and results can be filtered by pair / team.

## Why

- The co-puzzler UI is a stack of rows, each a text input + a native `<select>` that lists **favorites only**,
  unsorted, unsearchable. Anyone else is typed by hand as `#code`; guests are retyped (and mistyped) every time.
- Groups are frequent and repetitive, yet the site has no memory of them.

Production, 2026-09-20: 70,865 group times of 517k (14 %) · ~11,600 distinct compositions (6,663 pairs,
4,972 teams, max 11 people) · **65 % of compositions contain a guest without an account** · ~60 % of
compositions were used once · per tracker: median 2 compositions, p95 15, max 163 (645 group times).
So: guests are the main case, one-offs dominate the long tail, and per-player volumes are small enough
that everything per-player is computed live off an index (no counters, no materialisation, no cron).

## Decisions (settled with Jan)

| # | Decision |
|---|---|
| D1 | **A team is the exact set of people.** Same people = same team, member order irrelevant; different people = different team. No rosters. A team never changes members — editing a time's members moves the time to another team. |
| D2 | **Pair** = me + 1, **team** = me + 2 or more. One entity, category derived from size. The word is "Pair" everywhere (the puzzle-page tab "Duo" is renamed). |
| D3 | The name is **optional**, for pairs and teams alike. Unnamed is the normal case and must be fully convenient. |
| D4 | The name is **shared and public** free text, **max 50 characters**; any *registered* member may set / change / clear it; the other registered members are notified. Team pages and names follow the private-profile and blocklist rules. **No report button and no admin rename for now** — `named_by` / `named_at` record who set it; an abusive name is fixed by SQL if it ever happens. |
| D5 | **All history is converted**: every existing group time gets its (unnamed) team. |
| D6 | **A team with results cannot be deleted** — only named. Only a team with zero times (prepared ahead, never used) can be deleted. Deleting never touches results. |
| D7 | Free for everyone: creating, picking, naming, editing, the manage page, filters. **Members-only: stats and charts** on the team page. |
| D8 | The add form uses a **Solo · Pair · Team switch**, Solo pre-selected, one line at 320 px. It is a shortcut, never a constraint, and **always switchable** — a mis-tap must be recoverable with one tap and lose nothing. |
| D9 | Choosing co-puzzlers **must never disturb the add form**: no submit, no re-render, no navigation, no lost field values. |
| D10 | Performance is a requirement: a solo add-form render costs **zero** additional queries; existing pages do not get slower. |
| D11 | **Archive** (added 2026-09-21): one member keeps a pair/team out of *their own* shortcuts (picker + top of the manage page; for a pair the person too). Nothing else changes - results, team page, name, other members - and puzzling with the same people again brings it back on its own, inside the resolver's lookup (no extra query). It is the answer to "how do I delete this team" for a team that has results. |
| D12 | **Guests** (added 2026-09-21): a guest's name can be fixed (typo teams merge), and a guest can become a registered player - **only with that player's consent** (`GuestLinkRequest`: asked → notification → page → accept/decline). Results never land in somebody's history without their yes; a player who blocks the asker is never asked, and the asker cannot tell. |

### Why exact-set and not rosters (D1)

A roster ("Family" = 4 people, any subset counts) cannot be derived from history, is ambiguous when two
rosters fit one time, mixes head-counts in one ranking, and needs rules for members joining / leaving.
Exact-set has none of these problems and converts history without a single judgement call. Its cost —
"Family without Eva" is another team — is softened in the UI: the picker's identity line shows what the
current selection *is*, and the team page lists related teams (sub/supersets). A read-only "combined view"
can be layered on exact-set later; the reverse is impossible.

## Model

```
puzzling_team          id · composition_key (unique) · size · name NULL · created_at
                       · prepared_by_id NULL (set when created ahead on the manage page)
                       · named_by_id NULL · named_at NULL
puzzling_team_member   team_id · member_key · player_id NULL (RESTRICT) · guest_name NULL · position
                       index (player_id) · unique (team_id, member_key)
puzzle_solving_time    + puzzling_team_id NULL → puzzling_team (RESTRICT, indexed)
notification           + target_puzzling_team_id NULL (CASCADE) - PuzzlingTeamRenamed
```

- **`composition_key`** = sha1 of the sorted member keys joined by `|`. Member key = player uuid, or
  `g:` + normalised guest name (trim, collapse whitespace, lowercase, strip diacritics); two guests of the
  same name in one group are two people (`g:jana`, `g:jana#2`). One PHP implementation
  (`Value\TeamComposition`), used by live writes *and* the backfill. The picker's JS mirrors the guest
  normalisation only to match suggestions - the server is always the authority.
- The key is global, not per tracker: it always contains ≥ 1 registered id, so two families' "Eva" only
  collide when the registered members are identical too.
- **The JSON `team` column stays** as the display snapshot — the ~15 read queries built on it are untouched.
  `puzzling_team_id` is additive. Queries that selected `team ->> 'team_id'` (never populated in production)
  now select the column under the same `team_id` alias, so every result DTO carries the team id.
- **Resolution** (`PuzzlingTeamResolver`): lookup by key; a new team and its members are created in **one**
  `INSERT … ON CONFLICT DO NOTHING` statement outside the unit of work - two members saving the first time
  of a brand new team at once cannot fail each other. Cost per group time: 1 query (existing team) or 2 (new).
- Relax trackings are `puzzle_solving_time` rows too (`seconds_to_solve IS NULL`) — covered by the same column.
- Guest typos ("Grandma" / "Granma") make two teams. The picker suggests past guest names, which stops new
  drift; merging old ones is the later "rename guest / link guest to account" tooling (plan, phase 6).

## The add-form picker (UX spec)

```
┌──────────┬──────────┬──────────┐      320 px → 3 × 96 px, icon over label,
│    ◯     │   ◯◯     │   ◯◯◯    │      `ci-user` glyphs as on the puzzle-page tabs
│   Solo   │   Pair   │   Team   │      role=radiogroup, Solo checked server-side
└──────────┴──────────┴──────────┘
```

**Pair** — one list (for a pair, the team *is* the person), ordered by the times as a pair (see **Ordering**), counts are pair counts.
One tap → card collapses to `◉◉ Pair with Anna · 15 times together — Change`.

**Team** — top teams (3+ people, one-offs never shown, "All ▾" expands in place), then people, then search.
After the first pick the team row narrows to teams containing *everyone selected*, phrased as what a tap adds
(`+ Grandma → Family · 42×`) — so a tap is always additive, never "replace?".

```
│ (◉ Anna ×) (◉ Petr ×)                             │
│ Team of 3 · 8 times together                      │ ← identity line, aria-live
│ COMPLETE A TEAM  [+ Grandma → Family · 42×]       │
│ PEOPLE  (👤 Grandma) (◉ Lena) (◉ Tom)             │
│ 🔍 Search a player or add a guest…                │
│ Name this team (optional)                         │ ← only when the team is unnamed
```

- **Search** is the one TomSelect control, single "add one" mode: answers typing only (the people worth offering
  unasked are the chips above it), local data first, remote all-player search from 2 characters, last option always `Add "…" as guest (no account)`. Chips are own markup
  (locked chip, avatar, guest icon). Local data = everybody the suggestions know (co-puzzlers incl. guests, favorites),
  added the moment they arrive - a guest is in no remote search, so this is the only way to type one beyond the
  visible row. They are listed before anybody from the remote search, in the order offered above; name, code and
  `#CODE` all match. `refreshThrottle: 0`: with TomSelect's default 300 ms a quick "Sarah⏎" met a closed dropdown and
  Enter added the typed text as a guest.
- **Identity line**: `Pair with Anna · 15 times together` → `Team "Family" · 42 times` → `New team — first time
  together`. Makes exact-set self-explanatory and tells which leaderboard the time lands in.
- **Ordering** everywhere (picker people + teams, Pairs & teams page): whoever the player puzzled with in the last
  `GetCoPuzzlers::RECENT_DAYS` (30) days first, latest first; everybody else by how often; the recency-weighted score
  (each shared time `1 / (1 + age_days / 60)`) only breaks ties. In Pair mode all three are the pair's own (last pair
  together, pair count, pair score) - a team last week does not make somebody your pair partner. The picker re-sorts
  in JS with the same window, handed over as `data-copuzzler-picker-recent-days-value`. Measured on production
  2026-10-05: the suggestions statement takes ~5 ms for the player with the most pairs/teams (161 teams, 428 rows),
  favorites ~1 ms for 97 of them; followers play no part.

### Never disturb the form (D9)

- Pure client state; **the hidden `group_players[]` inputs are the single source of truth** and the wire format
  is unchanged (`#CODE` or guest name) — handlers, API and `ppm_validator` keep working.
- Nothing is saved before the time is: the team name is one more field of the add form (`team_name`), applied
  by the handler in the same transaction.
- No Turbo frame, no Live Component, no modal route, no nested `<form>`. All controls `type="button"`.
  **Enter in the search never submits the add form.** Nothing in the card navigates ("Manage my teams" → new tab).
- Only two read-only `fetch` GETs (suggestions, remote search); failure degrades to a hint, typing `#code` and
  guests still work.
- Chips rebuild from the hidden inputs on `connect()` → 422 re-render, browser back and bfcache restore the
  group. Inputs are never `disabled` (the TomSelect restore race, fixed c6054122).

### One input = one person; nothing typed is lost (2026-10-08)

A player typed "Anna, Ben, Clara" and added it as one guest: the result was saved as a *pair* with a guest of
that name. Before that, she typed the names, tapped Save without adding them, and the time stayed solo without a
word - TomSelect empties its box when it loses focus, and the server never sees the mode, only the chips.

- **Commas split people everywhere.** `PuzzlersGrouping::splitInputs()` is the one reading of the co-puzzler
  inputs on the server (the group, the first-try rules, the pace check's head count, the picker's chips on a
  re-render); the picker splits the same way (`typedPeople()`): the search offers "Add 3 people: Anna, Ben,
  Clara", several people in Pair mode switch to Team, Undo removes them again.
- **A submit is stopped** (`guardSubmit()`, a capture listener on the surrounding form - the one place the picker
  touches the form) while text typed into the search was not added (remembered from TomSelect's `type` event, since
  the box is already empty by the time Save is tapped), or Pair/Team holds nobody. The card shows the typed text
  with "Add …" (a part matching a known player's name is that player) and "Clear the text"; the player saves again.
- Guests saved combined before this: `myspeedpuzzling:split-combined-guests` (dry run unless `--write`) moves their
  results to the pair/team they really are; the emptied team goes with `cleanup-empty-puzzling-teams`.

### Always switchable (D8)

Each mode keeps an in-memory stash (pair partner · team selection · typed name); the hidden inputs mirror the
*active* mode only. Switching = showing the other stash, never clearing. The highlighted option is always the
mode the player chose; what the group *is* (a team mode with one person is a pair) is what the identity line
says - and what the server re-derives from the head-count on the next render.

| Tap | Result |
|---|---|
| Solo → Pair / Team | Card opens with that mode's previous state, or empty suggestions |
| Pair (Anna) → Team | Anna carried over as first chip |
| Team (A, P, E) → Pair | Pair list opens with A, P, E first ("Which one?"); team selection stays stashed |
| → Team again | A, P, E and the typed name are back |
| anything → Solo | Card collapses, inputs emptied, stash kept — Pair / Team restores it |

The switch is the undo. Inside the card: a removed chip reappears first in the people row; a multi-add (team
tap) shows an inline "Undo" on the identity line. No confirm dialogs. Limit: after a 422 re-render only the
active (submitted) selection survives.

Edit form by a non-tracker member: the tracker is a locked chip "(tracked it)"; switching to Solo shows the
quiet line "You'll no longer be part of this time" (existing rule, see `group-time-editing.md`).

### Performance (D10)

Solo is pre-selected and the card closed → **no suggestion query on page render**. Suggestions load from
`GET /{_locale}/my-co-puzzlers.json` on the first Pair / Team tap (prefetch on `pointerenter` / `touchstart`);
the card opens instantly with skeleton chips. Edit form, 422 re-render and `?team=` deep link fetch on connect.

## Pages

- **Profile → "Pairs & teams"** (`/en/pairs-and-teams`): tabs Pairs / Teams; card = avatars, name or member
  names, count, last together; inline rename; "Add time" (`?team=<id>` deep link); "Results"; "New team"
  (same picker, optional name); one-offs collapsed under "N groups you puzzled with only once"; search when long.
- **Team page** (`/en/teams/{id}`): members, times, puzzles solved together, related teams; stats / charts
  members-only. Every group row on the site links here.
- **Filters**: profile solved-puzzles lists get a "with…" select (`?team=<id>`); puzzle detail pair / team tabs
  get a "My pairs / teams only" switch.
  - The "with…" select narrows the Pair and Team tabs only - the Solo tab keeps every solo result (a member's
    profile opened from a team page used to look as if they had no solo results); "Reset" clears it too.
  - "Only first tries" / "Only unboxed" (members) work on every tab of the profile and the puzzle leaderboard and
    stay on across tabs (2026-10-08, asked by two players). A pair/team result's own `first_attempt` flag is the
    filter - it means everybody's first try (`first-try-integrity.md`). A row (puzzle + exact people on a profile,
    the people on a leaderboard) is kept when one attempt matches, and that attempt leads and places it, as on Solo;
    with both switches on it must be one attempt that is both (`PuzzlesSorter::filterGroupedByAttempt()`,
    `groupPuzzlesByTeam()`).

## Guests, archive, cleanup (2026-09-21)

- **`PuzzlingTeamMemberConversion`** is the one place that changes who a member *is*: `playerToGuest()` (account
  deletion), `renameGuest()`, `guestToPlayer()`. Each rewrites the member row, recomputes the composition key,
  **merges into the team that already has that key** (times move, the survivor inherits a name it lacks) and -
  for the two guest operations - rewrites the group snapshot of every affected result, so pages and the team
  agree. Scope is always "the pairs/teams the acting player is a member of": nobody edits another player's guests.
  One person is never in a team twice: a rename/link that would collapse two members of one team is skipped for
  that team.
- **Guests tab** (`/pairs-and-teams?show=guests`): rename, and "They have an account now" (player code) →
  `RequestGuestLink` → `GuestLinkRequested` notification → `/pairs-and-teams/guest-link/{id}` (GET only shows:
  who asks + the results it is about) → `AnswerGuestLink`. Asking again replaces the open question; only the
  asked player can open or answer it, once. The player is found **by name or by player code**
  (`guest_link_picker_controller.js`, added 2026-10-08 after a puzzler's feedback): TomSelect fills the same `code`
  field the plain input posts (no JS = type the code), started only when the section opens. Order = a name like
  the guest's (same name, then same first name, also searched on the server as soon as it opens), then favorites,
  then the other co-puzzlers (`my_co_puzzlers`, fetched once per page), then everybody from
  `player_search_autocomplete?format=co-puzzler`. Guests and the player themselves are never offered.
- **Cards show every member**: names on the manage page wrap (`text-break`), never `text-truncate` - a cut-off
  list hid who is in a big team.
- **Archive**: `puzzling_team_archive (team, player)`; `GetCoPuzzlers` flags `archived` per viewer,
  `MyCoPuzzlersController` drops archived teams (and an archived pair's person) from the picker payload, the
  manage page folds them under "Archived". `PuzzlingTeamResolver::resolve(..., usedByPlayerId:)` deletes the
  archive row inside the lookup statement.
- **Cleanup**: `myspeedpuzzling:cleanup-empty-puzzling-teams` removes teams with no result, no name, no preparer,
  older than a day. Manual; nothing depends on it.
- **API**: result rows carry `team_id` + `team_name` (nullable, read-only, appended). Nothing renamed.

## Rules that touch existing features

- **Blocklist**: every new query returning other players embeds `HiddenPlayers::sqlExclude()` /
  `sqlExcludeTeam()` and is registered in `BlocklistQueryCoverageTest`; new pages go into `BlocklistCanaryTest`.
- **Private profiles**: `PrivateProfileAccess::sqlIsPrivate()` instead of raw `is_private`; new pages go into
  `PrivateProfileCanaryTest`, queries into `PrivateProfileQueryCoverageTest`.
- **Group time editing**: any member edit re-resolves the team; the group is still assembled around the tracker.
- **Player deletion**: the deleted member becomes a guest *inside the team* (member row converted, key
  recomputed, merge on collision) — the same primitive the later guest tools use.
- **Puzzle merges**: times move with their `team_id`; nothing to do.
