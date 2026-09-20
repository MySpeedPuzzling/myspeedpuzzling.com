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
| — | **Archive** ("hide this pair/team from my shortcuts") is out of scope — tracked in the plan's TODO list. |

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

**Pair** — one list (for a pair, the team *is* the person), ordered by pair score, counts are pair counts.
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
  (locked chip, avatar, guest icon).
- **Identity line**: `Pair with Anna · 15 times together` → `Team "Family" · 42 times` → `New team — first time
  together`. Makes exact-set self-explanatory and tells which leaderboard the time lands in.
- **Ordering** everywhere: recency-weighted count — each shared time contributes `1 / (1 + age_days / 60)`.

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

## Rules that touch existing features

- **Blocklist**: every new query returning other players embeds `HiddenPlayers::sqlExclude()` /
  `sqlExcludeTeam()` and is registered in `BlocklistQueryCoverageTest`; new pages go into `BlocklistCanaryTest`.
- **Private profiles**: `PrivateProfileAccess::sqlIsPrivate()` instead of raw `is_private`; new pages go into
  `PrivateProfileCanaryTest`, queries into `PrivateProfileQueryCoverageTest`.
- **Group time editing**: any member edit re-resolves the team; the group is still assembled around the tracker.
- **Player deletion**: the deleted member becomes a guest *inside the team* (member row converted, key
  recomputed, merge on collision) — the same primitive the later guest tools use.
- **Puzzle merges**: times move with their `team_id`; nothing to do.
