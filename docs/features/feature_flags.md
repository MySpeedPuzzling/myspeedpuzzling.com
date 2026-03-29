# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

## Puzzle Insights (`ADMIN_ACCESS`)

**Purpose:** Hide all puzzle insights UI from regular users so we can deploy to production and iterate on real data before public launch. Backend computation runs normally — only template rendering is gated.

**Condition:** `{% if is_granted('ADMIN_ACCESS') %}`

**Remove when:** All puzzle insights TODO items are resolved and we're ready for public release.

### Gated locations

| File | Line context | What it hides |
|------|-------------|---------------|
| `templates/puzzle_detail.html.twig` | Wraps `_difficulty_section.html.twig` include | Difficulty card on puzzle detail page |
| `templates/player_profile.html.twig` | Wraps Skill Profile + ELO Rating cards | Both insights cards on player profile |
| `templates/added_time_recap.html.twig` | Wraps `_solve_analysis.html.twig` include | Post-solve analysis recap |
| `templates/_puzzle_item.html.twig` | Wraps difficulty icon before piece count | Difficulty icon on puzzle list items |
| `templates/puzzles.html.twig` | Combined with membership check | Difficulty filter dropdown on puzzle search |
| `templates/_puzzle_search_results.html.twig` | Combined with membership check | "Easiest first" / "Hardest first" sort options |
| `templates/components/PlayerHeader.html.twig` | Wraps skill tier icon before player name | Rank icon next to player name in breadcrumb |

### Notes

- MSP-ELO ladder page (`templates/msp_elo_ladder/`) is **NOT gated** — there are no links to it yet, so it's effectively hidden
- Methodology page (`templates/methodology/`) is **NOT gated** — same reason, no links
- The `_puzzle_item.html.twig` flag also gates the locked icon for non-members — when the flag is removed, non-members will see the locked difficulty icon triggering the membership modal
- The `puzzles.html.twig` and `_puzzle_search_results.html.twig` flags additionally require active membership, so even after removing the admin flag, these features remain members-only
