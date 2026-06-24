# Player Comparison

Multi-player comparison of puzzle solving times. Replaces the old single-opponent
`compare_players` page (logged user vs one player, solo only) with a session-backed
"bucket" of players the user can compare in **solo**, **pairs** and **teams** modes,
with filtering, sorting, deltas and members-only charts.

## Why

Comparison is a high-engagement, high-intent moment. Turning it from a 1:1 dead-end
into a "league table among my friends" drives retention, gives well-timed membership
upsells, and is a puzzling-specific differentiator (no generic stats tool compares
pairs/teams). Comparison state lives in the session so the user can keep adding
players while browsing, then open the full comparison from a floating launcher.

## UX overview

- **Page**: `/compare-puzzlers/` (`comparison` route) renders the `PlayerComparison`
  live component. One shared control bar drives everything; a **Table ⇄ Charts**
  switch keeps it compact.
- **Bucket**: ordered list of subjects in the session. "You" is auto-added (and
  removable). Add players by name **or** `#code` (reuses `SearchPlayers::fulltext`).
- **Floating launcher** (`ComparisonBucketLauncher`): site-wide bottom-left FAB +
  badge + dropup panel, rendered in `base.html.twig` whenever the bucket is non-empty
  and you're not already on the comparison page.
- **Cards**: each puzzle is a card with a ranked mini-leaderboard of subjects
  (mobile-first). Each row shows the **fastest time + date** and the **first-try
  time + date**, plus a **delta** vs the fastest subject (switchable baseline via the
  "Compare against" select). Subjects who didn't solve a puzzle show `—`
  (union-with-gaps; an "only common" toggle restricts to puzzles everyone solved).
- **Modes**: solo / pairs / teams. In pairs/teams each subject defaults to *any*
  partner and can be narrowed by attaching required co-solvers ("+" on the chip).
- **Charts** (members): head-to-head wins, avg time by piece count, time per puzzle,
  and speed-by-difficulty (radar). They consume the same filtered view, so they
  live-update with the filters/mode. Blurred placeholder + upsell for non-members.

## Membership gating

- Non-members compare **2 subjects**; a 3rd+ (or charts, or sort-by-difficulty) is
  gated behind the shared `#membersExclusiveModal` (existing pattern). The component
  only builds the first 2 active subjects for non-members; extras show a locked chip.

## Data model

Solving times are matched per subject by **mode**:
- **solo**: `player_id = :id AND puzzling_type = 'solo'`.
- **pairs/teams**: `puzzling_type = 'duo'|'team'` AND the team JSON contains the base
  player **and every required co-solver**, via a single jsonb containment that hits
  the `custom_pst_team_puzzlers_gin` index:
  `(team::jsonb -> 'puzzlers') @> jsonb_build_array({player_id:…}, …)`.

Per puzzle the query aggregates the **fastest** row (min `seconds_to_solve`) and the
**first-try** row (`first_attempt = true`, earliest). `suspicious = false` and
non-null time are always required.

**Solved-together edge case**: in pairs/teams, when two subjects' fastest entry is the
same solving record (`fastestTimeId`), it's flagged "solved together" (people icon)
rather than presented as a head-to-head — handled in `ComparisonBuilder` by counting
shared `fastestTimeId`s within a puzzle.

## Key files

- Values: `ComparisonMode`, `ComparisonSubject`, `ComparisonFilter`.
- Queries: `GetPlayerComparisonResults` (per subject, per puzzle), `GetComparisonPlayers`
  (display info + privacy). Difficulty via existing `GetPuzzleDifficulty::forPuzzleList`.
- Results/DTOs: `ComparisonResultRow`, `ComparisonView`, `ComparisonPuzzleRow`,
  `ComparisonCell`, `ComparisonEntry`, `ComparisonSubjectView`, `ComparisonPlayer`.
- Services: `ComparisonBucket` (session, `RequestStack`), `ComparisonBuilder`
  (ranking/deltas/shared/filter/sort), `ComparisonChartBuilder` (4 chart types).
- Components: `PlayerComparison` (+ `templates/components/PlayerComparison.html.twig`,
  `_comparison_card.html.twig`), `ComparisonBucketLauncher` (+ its template).
- Controllers: `ComparisonController` (page; seeds self + optional `?players=` share
  links), `AddToComparisonController` (`comparison_add`, the profile "Compare" button).
- Styles: `assets/styles/components/_comparison.scss`.
- Translations: `comparison.*` (English only, per project convention).

## Notes

- Login required (the "you" subject needs an identity). Anonymous access is deferred.
- Shareable URLs: `/compare-puzzlers/?players=code1,code2` seeds the bucket then
  redirects to a clean URL; all filter/mode/view state is in `url:true` live props.
- The bucket is transient UI state, so `ComparisonBucket` writes the session directly
  (same precedent as `ReferralCodeInput`); it is **not** routed through Messenger.
- `time-chart` Stimulus controller is reused to format time axes/tooltips for the
  time-valued charts; the wins chart (counts) renders without it.
