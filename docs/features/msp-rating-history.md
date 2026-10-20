# MSP Rating history

Feature request "MSP Rating History Graph" (91 votes, issue #279): players want to see how their rating **and** their
place on the ladder evolve. Proposal with the production numbers and mockups:
https://claude.ai/artifact/LVPnXob5LAzebfaqGJ6GQp. Jan's calls (2026-10-20): a rolling 7-day window only, everything
free (guests included), ladder movement + "This week" + rating card + profile in one release, a lost place in quiet grey.

## The data

`player_rating_snapshot` holds one row per player × piece count × UTC day since 2026-03-30 (the day's last values,
written by the 15-minute batch, `PuzzleIntelligenceRecalculator::recordRatingSnapshots()`). Only 500 pieces carries a
rating (`elo_rating`), ~1,160 rated rows a day out of ~4,200.

- **The stored place (`elo_rank`) follows the ladder's rule since this release**: `RANK()` over the players on the
  ladder (`GetPlayerRatingRanking::sqlOnStoredLadder()`: not private, not opted out), NULL off the ladder. It used to
  count ties as the worse place, count opted-out players and store 0 for an unrated row - a few places off the ladder.
  `myspeedpuzzling:rerank-rating-snapshots [--from=Y-m-d]` ranks the stored days again (one message per day, its own
  transaction, only changed rows written; **run once after the deploy**, before the next weekly digest - the digest's
  "places gained" compares two stored places). Past days are ranked with *today's* privacy / opt-out settings - nothing
  else is known about the past. On a copy of production the whole run took ~2 minutes (205 days, < 1 s each): 180k
  places written and 2.95M rows' stale `0` cleared (every unrated row had one).
- A stored place never closes up the viewer's blocks (the batch has no viewer). Everything a viewer compares with
  their ladder - the movement, the header chip - therefore ranks the stored **ratings** of that day at read time with
  the ladder's own pool (`sqlOnLadder()`), filters included.
- Index `custom_rating_snapshot_day` (`docs/database-indexes.md`) serves "one stored day of the ladder".

## Compared with when

`MspRatingTimeline::moveReferenceDay()`: the last stored day at least 7 days before today (UTC days), but **never before
the latest formula change** - a formula moves everybody at once, so a week across it would show the formula, not the
player (2026-10-18 moved the average by −15 points). The days the formula changed are listed in
`MspRatingTimeline::FORMULA_CHANGES` - **a new rating formula adds a line there**. A points change never spans one
("–"); the charts mark them with a dashed line. Every label says "since <day>".

Most places drop every week without anybody doing anything wrong (2026-10-20: 893 down, 210 up, 16 new; median 8
places) - newcomers enter above, results older than 3 months decay, others' new times shift percentiles. Hence the
quiet grey for a lost place and green only for a gain.

## Surfaces

- **Ladder rows** (`GetPlayerRatingRanking::window(now:)`, `RatingMove` on `PlayerRatingEntry`): "▲ 3" / "▼ 2" /
  "New" under the place (an unchanged place shows nothing but a screen-reader sentence); the skill tier is the icon
  next to the name like on the other boards; a tap anywhere on the row opens the rating card (`leaderboards/_rating_move.html.twig`), the points change after the rating, the viewer's own
  move on the position line (`_rating_move_line.html.twig`). Follows the country / favourites filters and the viewer's
  blocks. One statement as before (two CTEs more).
- **"This week on the ladder"** (`MspRating:ThisWeek` above the Live ladder): the biggest climber (green "▲ 25 places ·
  now #324"; only with a rating entry solved after the compared day - nobody "climbs" because others faded), the
  best-placed new entry, the most active ("12 rated 500-piece puzzles": distinct 500-piece puzzles in their rating
  solved since the compared day - Jan found "best place ever" meaningless and asked for one title colour for every
  tile; "biggest rating gain" was rejected as the climber's twin: the same person on 21 of 47 days, measured
  2026-10-20, while a "top 100 mover" was the climber on 3 of 47). `GetRatingLadderThisWeek` returns 5
  candidates per tile for everybody, cached 15 minutes (`rating_ladder_this_week_cache`); `RatingLadderThisWeek`
  leaves out the viewer's hidden players and gives one player one tile. A player who goes private, opts out or deletes
  the account can stay on a tile until the cache expires (≤ 15 minutes).
- **Rating card** (`msp_rating_card`, `/en/msp-rating/card/{playerId}`): a tap on a ladder name or a "This week" tile
  opens it in the puzzler card's popover / sheet (the card's name links to the profile) (`players_card_controller.js` on a wrapper outside the Live root;
  `<turbo-frame id="player-card">`). Place + move, rating + points change, best place + date, rating and place day by day
  (`RatingHistoryChartFactory`: two Chart.js lines, place reversed; tooltips/ticks via `comparison-chart`, dashed lines
  via `leaderboard-chart`'s vertical markers at day index + 0.5 - formula changes are **not** marked for now
  (`RatingHistoryChartFactory::markers()`, Jan 2026-10-20: "Formula update" meant nothing to players), the
  season start will be), Compare + Profile. Only for a player on the ladder -
  a global ranking, so private players never (the allow list does not apply), opted-out / blocked / unrated = the
  neutral "not available". Noindex page twin, robots.txt Disallow. 3 statements (`RatingStoryBuilder`).
- **Profile**: the header's MSP chip carries the move ("#312 ▲ 6"; `GetPlayerProfile::byId()` one more scalar,
  `sqlPlaceOnStoredDayOf()`, ~0.5 ms), and below the progress cards a lazy frame (`msp-rating-history`, the card
  controller) shows the same story for a player on the ladder.

## Seasons (groundwork only)

Today's ladder is "Season 0". Under the ladder's title: "Season 0 · Season 1 starts on 1 January 2027" until that day
(`MspRatingTimeline::NEXT_SEASON_STARTS_ON`, one constant). The charts mark the season start once it is in range.
Season 1's own formula and rules (only times after the start, quarterly seasons proposed) are not built - details go
out in the newsletter.

## Performance (2026-10-20)

Production without the index (read-only EXPLAIN ANALYZE): ladder 156 ms, header 122 ms, strip 282 ms - each found
the day by scanning the whole 800 MB table. On a copy of production with `custom_rating_snapshot_day` (13 MB, 0.17 s
to build): ladder 263 → 4.9 ms, header 159 → 0.5 ms, card history 0.7 ms, strip 401 → 160 ms (cached).

## Not built

- "Why did my place move" (new results vs fading vs newcomers above) - needs past portfolio entries, only today's are
  stored (`player_rating_entry`).
- Player moments for ladder milestones (top 100, best place) on the Players page, rating lines on Compare.
- Season 1 itself.
