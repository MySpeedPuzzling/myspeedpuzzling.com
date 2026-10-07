# Suspicious times

`puzzle_solving_time.suspicious` marks a result whose time cannot be trusted - far too fast or far too slow for the
player. It is set by a person: a moderator in the time verification queue (`docs/features/suspicious-time-review.md`),
or an admin by SQL (the add/edit forms also refuse a time faster than 100 pieces per minute, `SuspiciousPpm`). The badge
reads "Verification needed" (`badge.suspicious`); nothing a person reads says "suspicious".

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
- the result detail opened from such a row - for anybody who may see the player (hidden players stay a 404), never
  the best attempt nor part of the standing (`docs/features/puzzle-result-detail.md`);
- the player's own data: the results export (without a rank) and the API's results of a player (`/me/results`, `/players/{id}/results`, `solves`).

The shared feeds (Hub "Latest" and "Favorites", `/recent-activity`) leave it out.

## Flagging a time

The normal way is the **time verification queue** `/admin/time-verification` (admins and moderators,
`docs/features/suspicious-time-review.md`): a scan twice a day (`myspeedpuzzling:detect-suspicious-times`) raises
times far off the player's own times as cases - it never flags anything - and a moderator decides. "Needs
verification" sets the flag through `PuzzleSolvingTime::markSuspicious()`, so statistics, insights and round results
follow at once, and the player is told once (banner, "Review your results", the "Your results" e-mail).

A flag can still be set (or cleared) by SQL:

```sql
UPDATE puzzle_solving_time SET suspicious = true WHERE id = '<time id>';
```

The next scan reconciles it: the time gets a marked case (origin `manual`, no reasons shown), its puzzle's statistics
are recalculated and the player is told like after a mark in the queue; a flag cleared by SQL trusts the case. Until
that run, `puzzle_statistics` (counts and times on the puzzle page, search, the Hub) still shows the old state - run
`php bin/console myspeedpuzzling:recalculate-puzzle-statistics` if it cannot wait. Insights and the Players page pick
the flag up within 15 minutes (batch crons).

## Guard

`tests/SuspiciousTimeQueryCoverageTest.php` fails for any file in `src/` whose code (comments aside) reads
`puzzle_solving_time` without a word about suspicious results - add `suspicious = false`, or list the file there with
the reason it needs none (own results, write side, duplicates, first tries, admin, background jobs).
