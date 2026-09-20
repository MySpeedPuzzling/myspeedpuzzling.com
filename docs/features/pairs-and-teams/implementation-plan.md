# Pairs & teams — implementation plan

Design, decisions and UX spec: [`README.md`](README.md). This file is the build order.

> **Status 2026-09-20: phases 1–4 are built.** Where the build deviates from the text below, `README.md` is
> right: the column is `puzzle_solving_time.puzzling_team_id` (Doctrine-managed index, no custom one), members
> carry a single `member_key`, the composition class is `Value\TeamComposition`, team creation is one CTE
> statement, the rename form is a plain PRG form on the manage page (no modal), the puzzle-page and profile
> filters work in PHP on rows already loaded (zero queries), and `GetCoPuzzlers` also feeds the manage page.
> **Shipped 2026-09-20 (d94965fa):** deployed, production backfilled (70,952 group times → 6,661 pairs + 4,978 teams in
> 35 s, 0 left, 0 head-count mismatches), picker public, all 6 locales translated. **Open:** phases 5–6 and the TODO
> list at the bottom.

Six phases, each shippable on its own. Phase 1 is invisible and carries all the data risk; phases 2–4 are
the visible feature; 5–6 are follow-ups. Every phase ends with the full gate: `phpstan`, `cs-fix`,
`phpunit --testsuite "Project Test Suite"`, `doctrine:schema:validate`, `cache:warmup`.

Conventions that apply throughout: single-action controllers, state changes only through Messenger handlers,
repositories are plain `readonly` classes that never flush, `Uuid::uuid7()`, `ClockInterface` (SQL gets `:now`
as a parameter, never `now()`), English only until the final translation pass.

---

## Phase 1 — Schema, resolver, dual-write, backfill (no UI)

### 1.1 Entities + migration

- `src/Entity/PuzzlingTeam.php` — `id`, `compositionKey` (string 40, unique), `size` (smallint), `name`
  (nullable, ≤ 50), `createdAt`, `preparedBy` (nullable Player, `onDelete: SET NULL`), `namedBy` (nullable
  Player, `SET NULL`), `namedAt`. Methods: `rename(Player $by, null|string $name, DateTimeImmutable $at)`,
  `isPair()`.
- `src/Entity/PuzzlingTeamMember.php` — `team` (`CASCADE`), `player` (nullable, **`onDelete: RESTRICT`** — a
  player row must never vanish under a composition key; `DeletePlayerHandler` converts members first, 1.5),
  `guestName`, `guestKey`, `position`. Index `(player_id)`; unique `(team_id, player_id)`, `(team_id, guest_key)`.
- `PuzzleSolvingTime::$puzzlingTeam` — nullable `ManyToOne`, `onDelete: RESTRICT` (results outlive nothing —
  a team with times is undeletable, D6). Set in the constructor, `modify()`, `transferOwnership()`,
  `replaceTeam()` — every place that sets `$team` takes the resolved team alongside it, so the two can't drift.
- Migration **generated** (`doctrine:migrations:diff` against a scratch DB built from committed migrations —
  the dev DB has branch drift). Then add by hand, per the custom-index rules:
  `CREATE INDEX custom_pst_team_id ON puzzle_solving_time (team_id, finished_at) WHERE team_id IS NOT NULL`
  → mirror nothing in `tests/bootstrap.php` (no extension needed), register in `docs/database-indexes.md`.
  Plain `CREATE INDEX` is fine: partial over ~71k rows, sub-second lock.

### 1.2 Composition key

- `src/Value/TeamCompositionKey.php` — `fromPuzzlers(array<Puzzler>): self`; member key = player uuid or
  `g:` + `GuestName::normalise()` (trim, collapse whitespace, `mb_strtolower`, NFKD + strip combining marks).
  Sort, join with `|`, sha1. **The only implementation** — backfill goes through it too.
- Unit tests: order independence · `" Grandma "` = `grandma` = `GRANDMA` · `Žofie` = `zofie` · guest vs
  registered player with the same display name differ · duplicate members collapse.

### 1.3 Resolver (find-or-create, race-safe)

- `src/Services/PuzzlingTeamResolver.php` — `resolve(PuzzlersGroup): PuzzlingTeam`.
  1. `SELECT id FROM puzzling_team WHERE composition_key = :key` → `getReference()`.
  2. Miss → DBAL `INSERT INTO puzzling_team … ON CONFLICT (composition_key) DO NOTHING`; when a row was
     inserted, insert the member rows in the same statement batch; re-select; `getReference()`.
  Runs inside the handler's `doctrine_transaction`; on a concurrent create the second transaction waits on the
  unique index, inserts nothing, and reads the winner's row (READ COMMITTED). No retry logic, no failed message.
  Implements `ResetInterface` only if it grows a per-request cache (start without one).
- Validate-before-mutate: the resolver is called **after** all handler validation (the rolled-back-handler
  leak: `doctrine_transaction` doesn't clear the EM).

### 1.4 Dual-write

- `PuzzlersGrouping::assembleGroup()` stays a pure value assembler. The three handlers call the resolver
  right after it: `AddPuzzleSolvingTimeHandler`, `AddPuzzleTrackingHandler`, `EditPuzzleSolvingTimeHandler`
  (re-resolve when the group changed; group → solo sets `null`).
- Emptied teams are **not** garbage-collected inline (FK ordering vs. flush); list queries show a team only
  when it has times or is prepared/named, so an empty unnamed row is invisible. A sweep is in the TODO list.
- The 12 occurrences of `team ->> 'team_id' AS team_id` in `src/Query` → `<alias>.team_id AS team_id`.
  Mechanical; result DTOs already carry `teamId`. Fixtures' `team-001` string → a real fixture team.

### 1.5 Player deletion

- `src/Services/PuzzlingTeamMemberConversion.php` — `toGuest(playerId, guestName)`: for each team of the
  player: convert the member row (DBAL), recompute `composition_key`; **on key collision merge**: repoint
  `puzzle_solving_time.team_id` to the surviving team, carry the name over when the survivor is unnamed,
  delete the duplicate. All DBAL, executes immediately, no flush-order dependency.
- `DeletePlayerHandler` calls it before removing the player (the member FK is `RESTRICT`, so forgetting it
  fails loudly in tests instead of corrupting keys). The handler has uncommitted edits in the working tree
  from the private-profile work — rebase on whatever lands first.

### 1.6 Backfill

- `BackfillPuzzlingTeams` message + handler; `myspeedpuzzling:backfill-puzzling-teams [--batch=1000]` only
  dispatches in a loop until the handler reports 0 rows. Handler: `SELECT … WHERE team IS NOT NULL AND
  team_id IS NULL ORDER BY id LIMIT :batch`, hydrate the JSON through `PuzzlersGroupDoctrineType`, resolve,
  `UPDATE … SET team_id`. Idempotent, restartable, ~71k rows → minutes. `created_at` of a backfilled team =
  earliest time of the batch that created it (good enough; not displayed as a fact).
- Rollout: deploy (dual-write live) → run the command on the box → verify
  `SELECT count(*) FROM puzzle_solving_time WHERE team IS NOT NULL AND team_id IS NULL` = 0 and
  team counts ≈ 6.7k pairs / 5.0k teams → rerun once after a day. Manual command, no cron.

### 1.7 Tests

- `TeamCompositionKeyTest` (1.2) · `PuzzlingTeamResolverTest`: same set → same team, other order → same team,
  new set → team + member rows with positions, second resolve creates nothing.
- Handler tests (existing files extended): add pair / team / with guest sets the team; solo sets none; edit
  adding a member moves the time, old team keeps its other times; edit to solo clears; non-tracker edit
  resolves around the tracker; **failed validation creates no team**.
- `DeletePlayerHandlerTest`: member becomes guest, key recomputed; collision merges and carries the name.
- `BackfillPuzzlingTeamsHandlerTest`: mixed member orders land in one team, idempotent rerun, partial-progress
  resume. (Service tested, not the console command.)
- Query-count guard: add-time POST budget +2 at most (lookup, or insert + lookup).

---

## Phase 2 — The picker

Ship behind an admin-only flag `PAIRS_TEAMS_PICKER` (document in `docs/features/feature_flags.md`) for a day
of real-phone testing in production, then flip. Old partial + `add_copuzzler_controller.js` are deleted when
the flag goes.

### 2.1 Read side

- `src/Query/GetCoPuzzlers.php` → `forPlayer(string $playerId): CoPuzzlerSuggestions`
  - teams: `puzzling_team_member me → puzzling_team t → LATERAL (count(*), max(COALESCE(finished_at,
    tracked_at)), sum(1.0 / (1 + age_days / 60.0)) FROM puzzle_solving_time WHERE team_id = t.id)`; only
    teams with times or prepared/named; members joined to `player` for name, code, country, avatar.
  - people: aggregated from the same rows in PHP (pair count, overall count, last together, score) + past
    guest names; then favorites not yet puzzled with (`GetFavoritePlayers`).
  - `HiddenPlayers` + `PrivateProfileAccess::sqlIsPrivate()`; registered in both coverage tests.
  - Budget: ≤ 3 queries, target < 10 ms for the p95 player, verify with `EXPLAIN ANALYZE` on the box for the
    163-team outlier before merging.
- `src/Results/CoPuzzlerSuggestions.php`, `TeamSuggestion`, `PersonSuggestion`.
- `src/Controller/MyCoPuzzlersController.php` — `GET /{_locale}/my-co-puzzlers.json`,
  `IS_AUTHENTICATED_REMEMBERED`, `Cache-Control: private, no-store`. Avatar URLs via `ImageThumbnailTwigExtension`.
- `PlayerSearchAutocompleteController`: `?value=code` makes `value` = `#CODE` and adds plain `name`, `code`,
  `avatar`, `country` fields (chips are own markup, not the HTML blob). Default behaviour unchanged for the
  moderators / maintainers pickers.

### 2.2 Form

- `templates/_copuzzler_picker.html.twig` replaces the group section of `_solving_time_form.html.twig`:
  the radiogroup switch (server-rendered state: solo / pair / team from `filled_group_players`), the card,
  hidden `group_players[]` inputs for current members, a `<script type="application/json">` with display data
  of the *current* members (edit form / 422 — chips never render as a bare `#code`), and the `team_name` input.
- `assets/controllers/copuzzler_picker_controller.js` — state machine per README §"Always switchable":
  `mode`, per-mode stash, `render()` from state, `syncInputs()` as the only writer of the hidden inputs.
  TomSelect (dynamic import, like flatpickr) only for the search box: `maxItems: 1`, `create: true` with the
  guest wording, `load()` → player search, on select → add chip + `clear()`. `keydown.enter` → `preventDefault`.
  Values: strings via `data-*` attributes from translations (no hardcoded English in JS).
- `ppm_validator_controller.js` keeps counting `input[name="group_players[]"]` — verify its target still wraps
  the hidden inputs.
- SCSS: `assets/styles/_copuzzler-picker.scss`; switch = 3 equal flex segments, icon over label, min-height
  56 px, tap targets ≥ 44 px; checked at 320 px in all 6 locales.
- Dev gotcha: a new Stimulus controller needs the PWA service worker cleared + hard reload; template changes
  need a `web` restart (worker mode).

### 2.3 Write side

- `AddPuzzleSolvingTime`, `AddPuzzleTracking`, `EditPuzzleSolvingTime` get `null|string $teamName`.
  Handlers: after resolving, **name only an unnamed team**; a non-empty `teamName` for an already named team
  is ignored (renaming lives on the manage page). Validation (1–50 characters after trim, `mb_strlen`; empty = no name) in the controller/form
  so a bad name re-renders with 422, not a handler failure.
- `PuzzleAddController`, `EditTimeController`, stopwatch finish flow: read `team_name`; `?team=<id>` deep link
  pre-fills `filled_group_players` from the team **only when the viewer is a member**.
- API: untouched in this phase (`group_players` semantics unchanged).

### 2.4 Tests

- `GetCoPuzzlersTest`: recent beats old-but-frequent · pair count vs overall count · counts include times
  tracked by another member · one-offs excluded from top teams · guest names aggregated by normalised key ·
  blocked player excluded from people · private member masked unless allow-listed · self never listed.
- `MyCoPuzzlersControllerTest`: auth required, payload shape, query budget (`QueryCountAssertions`).
- **Solo add-form render: query count identical to today** (the D10 guard).
- Handler tests: `teamName` names a new team / an existing unnamed team / is ignored for a named team /
  nothing is created when validation fails elsewhere.
- Panther (`tests/Panther/CoPuzzlerPicker/`; keep `--force-prefers-reduced-motion`):
  1. *Form is never disturbed*: fill time, date, comment; open Pair; pick; switch to Team; add via remote
     search; add a guest; press Enter in the search → form not submitted, every other field unchanged.
  2. *422 round trip*: error elsewhere → chips, identity line and typed name restored → fix → correct team stored.
  3. *Always switchable*: Team(3) → Pair → Team restores chips + name; → Solo → Team restores; Pair → Team
     carries the partner; hidden inputs equal the visible selection after every switch.
  4. *Mis-taps*: removed chip reappears first in people; Undo after a team tap.
  5. *320 px*: switch on one line (`scrollWidth <= clientWidth`, equal segment tops), in `en` and `de`.
  6. *Edit as non-tracker member*: tracker chip locked; Solo shows the warning line.
  7. *15 cap*: overflowing suggestions disabled.

---

## Phase 3 — "Pairs & teams" page

- `GetMyPuzzlingTeams` (list with counts / last / score, one-offs flagged, split pairs / teams; paginated) ·
  `PairsAndTeamsController` (`/en/pairs-and-teams`, localized paths like the other profile pages, linked from
  the profile menu) · `templates/pairs_and_teams.html.twig`.
- Messages + handlers: `RenamePuzzlingTeam` (member-only; records `TeamRenamed` event) ·
  `PreparePuzzlingTeam` (members + optional name → resolver; sets `preparedBy`) · `DeletePuzzlingTeam`
  (member-only; **refuses when any time exists** — `CanNotDeleteTeamWithResults`; checks inside the handler).
- Exceptions use `WithHttpStatus` / extend `NotFoundHttpException` so controllers don't catch.
- Rename UI: inline form in a `modal-frame` — **explicit `action:`**, gate the stream on the `Turbo-Frame`
  header, 422 on invalid (the Turbo gotchas in CLAUDE.md). Full-page fallback redirects (never answers 200).
- Notification `NotificationType::PuzzlingTeamRenamed`: async handler notifies the other registered members,
  skips blockers (`GetUserBlocks::blockersOf()`), dedups an unread one from the same actor; actor in
  `notification.actor_player_id`. It targets a team, not a time → own branch in `GetNotifications` + twig
  `elseif` (same trap as the moderator notifications); new nullable `notification.puzzling_team_id` (`CASCADE`).
- Tests: handlers (member may rename, outsider 403, guest-name match gives no rights, clear name, delete
  refused with results / allowed when empty, prepare resolves to an existing team instead of duplicating) ·
  query (split, ordering, one-off flag) · controller (auth, budget) · canaries (blocklist + private profile) ·
  `TurboDriveFormResponse` guard covers the rename form.

## Phase 4 — Filters + team page

- `GetPlayerSolvedPuzzles::duoByPlayerId()` / `teamByPlayerId()` (+ counts) take `null|string $teamId` →
  `AND puzzle_solving_time.team_id = :teamId`; `SolvedPuzzlesDetailController` + profile read `?team=`; the
  "with…" select lists that player's top teams (`GetPlayerTeams::publicFor($playerId)` — viewer-aware masking).
- `PuzzleTimes` live component: `#[LiveProp] bool $onlyMyTeams` → `team_id IN (SELECT team_id FROM
  puzzling_team_member WHERE player_id = :viewer)`; rename tab label Duo → Pair (translations, 6 locales).
- Group rows everywhere link the member list / name to `/en/teams/{id}` — name needs one `LEFT JOIN
  puzzling_team` in `GetPuzzleSolvers`, `GetPlayerSolvedPuzzles`, `GetRecentActivity`, `GetFastestGroups`
  (PK join; assert the page budgets don't move by more than 0 queries).
- `TeamDetailController` + `GetPuzzlingTeamDetail` (members, times, related teams = sub/supersets via
  `puzzling_team_member`); 404 on the same rule that hides the times (`sqlExcludeTeam`, viewer-took-part
  exception); `noindex` for unnamed teams. Stats / charts block gated on `activeMembership`.
- Tests: filter queries · live component prop · team page visibility matrix (guest / member / blocked /
  private member / allow-listed) · canaries · budgets.

## Phase 5 — API (optional, after the web is settled)

`teamId` + `teamName` on solving-time DTOs (camelCase props, snake_case JSON by converter), `GET
/api/v1/me/teams`, `team_name` on `POST/PUT /me/solving-times`. Membership gates as on the web; update the
OpenAPI assertions + `docs/features/api/README.md`.

## Phase 6 — Guest tools

On the manage page: **rename guest** and **link guest to account** — both are `PuzzlingTeamMemberConversion`
(1.5) run the other way, plus rewriting the JSON snapshot of the affected times. Linking needs the target
player's consent (notification + accept) — design before building.

## Final pass

Translations for all 6 locales (`missing-translations` skill) — the add form is core UI · `CLAUDE.md` feature
entry · `docs/features/group-time-editing.md` cross-reference · `.claude/fixtures.md` (fixture teams) ·
remove the flag + old controller/partial.

---

## TODO / parked

- [ ] **Archive** — per-member "hide this pair/team from my shortcuts" (`puzzling_team_archive`: team, player,
      archived_at). Hides from picker + top of the manage page for that member only; never touches results,
      other members, visibility; archiving a pair also drops the person from *People* suggestions (still
      searchable); **auto-unarchive when the same set is used again**. One `NOT EXISTS` in `GetCoPuzzlers` /
      `GetMyPuzzlingTeams`. Parked 2026-09-20: score decay already sinks stale teams; revisit if people ask
      "how do I delete this team".
- [ ] Sweep of empty unnamed, unprepared teams (left behind by edits) — manual command, only if the table
      shows real clutter.
- [ ] "Combined view" on the team page (include times with fewer of us) — read-only aggregation over subsets.
- [ ] Move profile membership tests from the GIN containment (`team @> …`) to `team_id IN (my teams)` — a
      speed-up of existing pages, measure first.
- [ ] Name moderation — decided 2026-09-20: none for now (no report button, no admin rename; `named_by` /
      `named_at` are the trail, SQL is the remedy). Revisit only if abuse actually shows up.
