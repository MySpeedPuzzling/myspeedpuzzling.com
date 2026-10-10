# Karma

Credit for the people who keep the puzzle database right: **1 karma per accepted contribution**, public, free for
everyone, nothing to spend, no XP (decisions: `docs/features/xp-levels/launch-2026-10.md` §Karma, Jan 2026-10-10).
Launches together with XP behind the same switch (`XpFeatureGate` / `xp_system_visible()`) - before the launch only
admins see the karma surfaces; the review queues and admin pages show it always.

## The rule

1 karma each - no caps, no weights, no square roots:

| Contribution | Who | Source |
|---|---|---|
| An approved change request (an EAN linked by a scan approves itself - counts too) | proposer | `puzzle_change_request.status = 'approved'`, dated `reviewed_at` |
| An approved merge request | reporter | `puzzle_merge_request.status = 'approved'` |
| An approved puzzle with an EAN | whoever added the **earliest** copy | `puzzle` + `puzzle_merge_audit`, dated `added_at` |
| A moderator decision: change request approve/reject, merge request approve/reject, puzzle approval | decider | `puzzle_moderation_decision`, `source = 'admin_ui'` |
| A moderator's direct edit (`puzzle_edited`) | editor | same |
| A moderator deciding their own proposal or puzzle | - | **1 in total**: the proposal when it counts (approved), else the decision |

Nothing for: pending, rejected or **outdated** requests (outdated = something else did it, nobody accepted it), brands
(added, approved, merged), events, the application's own closings (`source = automatic`, no decider), internal-API
decisions. **Admins (`player.is_admin`) have no karma at all** - the internal API files its proposals in the admin
reviewer's name. Declined work never subtracts; there is no negative karma.

"Has an EAN" = the stored `ean` holds at least 8 digits in a row (`KarmaDerivation::HAS_EAN`) - catalogue numbers and
placeholders typed into the EAN field do not count.

### Duplicates: the earliest add keeps the credit

When puzzles are merged, only the person who added the earliest copy keeps the karma; the later copies are listed in
the adder's history in grey as "Merged into a puzzle added earlier - not counted" (`KarmaKind::DuplicatePuzzle`), so a
drop is explained. Merged-away puzzles are known from `puzzle_merge_audit.snapshot_before->mergedPuzzles[]`
(`addedByUserId`, `addedAt`, since 2026-09-16); the puzzle they live on in now is `puzzle_redirect` (kept one hop deep,
so merges of merges need no recursion), the audit's survivor until the merge's deletion wrote the redirect. Before the
audit existed the survivor's own adder keeps the credit (~90-100 historic credits sit with the "wrong" person and
cannot be corrected - small next to ~23,000). An unknown `added_at` counts as earliest. Change requests moved by a
merge (`merged_from_puzzle_id`) keep their credit and link to the survivor.

## Derivation, no ledger

`Services\Karma\KarmaDerivation::itemsCte(scoped)` is the one place of the rule: a CTE `karma_items` (player_id, kind,
at, puzzle_id, puzzle_name, action, counted) built by UNION over the source tables. Scoped = only the players in
`:karmaPlayerIds` (a player's history, the recalculation after a decision); unscoped = everybody. Every reader joins
`player pl ON KarmaDerivation::ELIGIBLE_PLAYER` (admins out).

Traps it handles:
- **Backfilled scanned-EAN decisions.** The 2026-09-25 decision-log backfill turned every approved change request into a
  `change_request_approved` row, including EANs linked by a scan (approved by their own reporter) - they look like
  moderator decisions by ordinary players. A decision on one's own approved request is dropped, which covers them.
- **The window between a merge's commit and its deletion** (`DeleteMergedPuzzlesOnMergeApproved`): a puzzle named in an
  audit as merged never counts as its own puzzle, and the redirect falls back to the audit's survivor.
- **JIT.** Correlated `EXISTS` against the CTE and a recursive merge chain pushed the estimated cost over
  `jit_above_cost`: Postgres spent 610 ms compiling a 70 ms query. The self-decision checks are anti-joins and the
  chain is `puzzle_redirect` - keep it that way.

Readers:
- `Query\GetPlayerKarma` - `breakdown()` (counts per kind, = paging total) and `items()` (newest first, undated last).
- `Services\Karma\KarmaRecalculator` - stores the totals on `player.karma` (int, default 0, indexed) with one
  set-based `UPDATE … FROM (aggregate)` that only touches rows that change (a documented bulk operation - CLAUDE.md);
  `recalculateAll()` / `recalculateFor(ids)`. Idempotent.
- `Query\GetXpLeaderboard::karma()` - the public list (below).
- `Query\GetProposerTrackRecords` - the review queues (below).

## Keeping `player.karma` right

- `RecalculateKarma(playerIds)` (routed **async**) is dispatched by every handler deciding something karma counts, for
  everybody the decision concerns: `ApprovePuzzleChangeRequestHandler` (proposer, moderator, the puzzle's adder - an EAN
  may have come), `RejectPuzzleChangeRequestHandler`, `RejectPuzzleMergeRequestHandler` (proposer, moderator),
  `ApprovePuzzleMergeRequestHandler` (reporter, moderator, the adders of every merged puzzle), `ApprovePuzzleHandler`
  (adder, moderator), `EditPuzzleHandler` (editor, adder), `LinkEanToPuzzleHandler` (scanner, adder). Guarded by
  `KarmaWiringTest`.
- `myspeedpuzzling:recalculate-karma [--player=UUID]` - backfill and daily safety net; dispatches `RecalculateKarma(null)`
  with a `sync` transport stamp, so it runs in the command's process. Dev copy (41k puzzles, 2,952 holders): **137 ms**
  for the first fill, 74 ms for a re-run that changes nothing; a scoped run ~6 ms.
- What the handlers cannot see is healed by the daily run: a deleted player's requests (cascade), SQL changes.

lily.srv cron row (add to `apps/myspeedpuzzling/cron.d/myspeedpuzzling`):

```
41 3 * * * root lily-cron-run myspeedpuzzling recalculate-karma -- docker compose --file /srv/myspeedpuzzling/compose.yaml run --rm --no-deps messenger-consumer sentry-cli monitors run --schedule "41 3 * * *" --timezone "Europe/Prague" recalculate-karma -- bin/console myspeedpuzzling:recalculate-karma >> /var/log/lily/myspeedpuzzling-cron.log 2>&1
```

Launch: run the command once before the XP readiness marker flips (`docs/features/xp-levels/launch-runbook.md` §1b).

## Surfaces

| Where | What |
|---|---|
| `karma_history` (`/en/my/karma`, owner only, `KarmaHistoryController`) | "What did I get my karma for?": total, breakdown by kind, every item newest first (50 per page) linking its puzzle, merged-away copies in grey as not counted. PlayerHeader compact strip. 2 derivation statements (scoped) |
| `karma_explainer` (`/en/how-karma-works`, public) | The "For everyone" deep page of the "How it works" hub: what counts, duplicates, "declined work never takes karma away", "karma buys nothing and gives no XP"; signed in: own karma (from the profile row, no query) + history link |
| `xp_leaderboard?tab=karma` | The karma list: everybody free; filter by kind (All / New puzzles / Fixes & merges / Moderation = `KarmaGroup`) and period (all time / last 12 months = `KarmaPeriod`). Default = `player.karma` (~4 ms); a kind or period = one aggregate over the derivation (~110 ms on the dev copy). The viewer's own row comes in the same statement (pinned below, within the filters). Never private profiles (global rankings stay closed to them, allow list included), never admins, `HiddenPlayers` applied so places close up. Opt-outs of XP or rankings do not apply - karma is neither |
| Profile header | Teal chip "N karma" when > 0 (`karma/_karma_chip.html.twig`): own → history, others → the karma list. `karma` rides on the `GetPlayerProfile` row - no query |
| Review queues (`/admin/puzzle-change-requests`, `/admin/puzzle-merge-requests`, `/admin/puzzle-approvals`) | Next to each request: the proposer's karma and acceptance rate (`admin/_proposer_track_record.html.twig`), one statement per page. Rate = approved / (approved + rejected) change + merge requests, self-decided and outdated left out. Nobody decided yet = "First suggestion" (approval queue: "First contribution", only without karma), never a low number |
| Admin | Karma on `/admin/moderation/history/{playerId}` and a column on `/admin/moderators` |

## Tests

`tests/Services/Karma/KarmaDerivationTest.php` (every rule: outdated, EAN, earliest copy both ways, decisions and edits,
nobody's decisions, self-review = 1, scanned EAN + backfill row, admins, idempotence, history order),
`tests/MessageHandler/KarmaWiringTest.php`, `tests/Controller/KarmaPagesTest.php` (gate, owner, list privacy, filters,
statement budgets, queues).

## Not in v1

See `docs/TODO.md` §Karma: events, trusted contributors, moderators' accept/decline split for admins.
