# Suspicious times

`puzzle_solving_time.suspicious` marks a result whose time cannot be trusted - typically far too fast for the
puzzle. Nothing in the app sets it: an admin flags a time by SQL (the add/edit forms already refuse a time faster
than 100 pieces per minute, `SuspiciousPpm`). The badge reads "Verification needed" (`badge.suspicious`).

## The rule

**A suspicious result is neither counted nor timed anywhere** - it exists only in the player's own results.

- Not counted: "Completed N×" (`puzzle_statistics`, the Hub's most solved puzzles), brand and pieces pages, global
  and per-player totals (results, pieces, time spent), profile statistics, the activity calendar, most active
  players, the Players page (its precomputed stats and moments), a pair's/team's stats and "times together".
- Not timed: leaderboards, ladders, rankings and ranks, round results, MSP rating, skill tiers, difficulty,
  predictions, fastest / average / median / slowest times, competition participants' 500-piece stats, profile
  charts, personal bests, stopwatch milestones, comparison, Puzzle Picker, the viewer's own "My time" next to
  puzzles, the best solo time in the library export.
- Still the player's: their solved / unsolved status (solved lists, "My list" filters, wishlist hints), first-try
  integrity, duplicate detection, admin and moderation tools (a round with a suspicious result still has results).

## Where it is shown

Only in somebody's own results - on pages with the badge:

- the player's profile - results table and its "Recent activity" feed;
- the pair's / team's page (listed, never one of its best times);
- the result detail - for the subject, and for admins and moderators (`SuspiciousResultsVoter`); anybody else gets
  a 404 when that is all the subject has on the puzzle (`docs/features/puzzle-result-detail.md`);
- the player's own data: the results export (without a rank) and the API's results of a player (`/me/results`, `/players/{id}/results`, `solves`).

The shared feeds (Hub "Latest" and "Favorites", `/recent-activity`) leave it out.

## Flagging a time

```sql
UPDATE puzzle_solving_time SET suspicious = true WHERE id = '<time id>';
```

Insights and the Players page pick it up within 15 minutes (batch crons). `puzzle_statistics` (counts and times on
the puzzle page, search, the Hub) is recomputed only when a time of the puzzle changes, so after flagging run:

```bash
php bin/console myspeedpuzzling:recalculate-puzzle-statistics
```

## Guard

`tests/SuspiciousTimeQueryCoverageTest.php` fails for any file in `src/` whose code (comments aside) reads
`puzzle_solving_time` without a word about suspicious results - add `suspicious = false`, or list the file there with
the reason it needs none (own results, write side, duplicates, first tries, admin, background jobs).
