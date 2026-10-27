<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CursorRows;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;

/**
 * What the candidate model (prediction model v4, README "Step 8", "The candidate model") knows about each qualifying
 * first attempt besides its time - one bit mask per attempt of a FirstAttemptSet, loaded in one statement. Read only by
 * the candidate: the shown model (v3) and the qualifying rule never look at them.
 *
 * - UNFLAGGED: not marked as a first try (the qualifying rule took the player's eldest solve - 17 % of first attempts,
 *   biased fast: some are not first tries);
 * - AFTER_GROUP: the player had solved the puzzle in a pair or team before (4.4 %, biased fast - they know it);
 * - COMMENTED: the result carries a comment (an interruption, missing pieces - residual SD 0.17 vs 0.13);
 * - COMPETITION: linked to an event - an edition or one-time event (competition_id), or a series pick still at series
 *   level (competition_series_id) - steadier: SD 0.10.
 */
final readonly class AttemptFlags
{
    public const int UNFLAGGED = 1;
    public const int AFTER_GROUP = 2;
    public const int COMMENTED = 4;
    public const int COMPETITION = 8;

    /**
     * The flags SQL of one qualifying first attempt `pst` (a puzzle_solving_time row) with flagJoin() - shared by the
     * batch's load and the reconstructor's statement, so both read the same rule. "After a group solve" compares solve
     * days (a pair/team result on the same day as the solo one does not count).
     */
    public const string FLAG_COLUMNS = "
        pst.first_attempt,
        (pst.comment IS NOT NULL AND btrim(pst.comment) <> '') AS commented,
        (pst.competition_id IS NOT NULL OR pst.competition_series_id IS NOT NULL) AS competition,
        COALESCE(first_group.solved_at < COALESCE(pst.finished_at, pst.tracked_at), false) AS after_group
    ";

    /**
     * Every registered player's first pair/team solve of a puzzle, set-based (a correlated EXISTS per first attempt took
     * minutes on the production copy, this ~2 s); $puzzleCondition narrows it (`g.puzzle_id IN (…)`).
     */
    public static function flagJoin(string $puzzleCondition = ''): string
    {
        $condition = $puzzleCondition !== '' ? 'AND ' . $puzzleCondition : '';

        return "
            LEFT JOIN (
                SELECT m.player_id, g.puzzle_id, MIN(COALESCE(g.finished_at, g.tracked_at)) AS solved_at
                FROM puzzle_solving_time g
                JOIN puzzling_team_member m ON m.team_id = g.puzzling_team_id
                WHERE g.puzzling_type <> 'solo' AND g.suspicious = false AND m.player_id IS NOT NULL {$condition}
                GROUP BY m.player_id, g.puzzle_id
            ) first_group ON first_group.player_id = pst.player_id AND first_group.puzzle_id = pst.puzzle_id
        ";
    }

    /**
     * @param list<int> $flags by attempt of the set
     */
    private function __construct(
        public array $flags,
    ) {
    }

    /**
     * In the set's own order (the pace order: player, piece count, solve moment, id) and merged with it - no id map - and
     * through a cursor (CursorRows), so the 300k rows never sit in memory at once. The two statements read at different
     * moments: a row the set does not have (a time added meanwhile) is skipped, an attempt without a row (removed or
     * changed meanwhile) gets no flags.
     */
    public static function load(Connection $connection, PlayerBaselineCalculator $baselineCalculator, FirstAttemptSet $set): self
    {
        [$qualifying, $params, $types] = $baselineCalculator->qualifyingFirstAttemptsQuery();
        $flags = [];
        $count = $set->count();

        $rows = CursorRows::iterate($connection, '
            SELECT q.id, q.player_id, q.pieces_count, EXTRACT(EPOCH FROM q.solve_date) AS solved_ts, EXTRACT(EPOCH FROM q.tracked_at) AS tracked_ts,
                ' . self::FLAG_COLUMNS . '
            FROM (' . $qualifying . ') q
            JOIN puzzle_solving_time pst ON pst.id = q.id
            ' . self::flagJoin() . '
            ORDER BY q.player_id, q.pieces_count, q.solve_date, q.tracked_at, q.id
        ', $params, $types);

        foreach ($rows as $row) {
            /** @var array{id: string, player_id: string, pieces_count: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, first_attempt: bool, commented: bool, competition: bool, after_group: bool} $row */
            $key = [$row['player_id'], (int) $row['pieces_count'], (float) $row['solved_ts'], (float) $row['tracked_ts'], $row['id']];

            // The set's attempts ordered before this row have no row (any more)
            while (($i = count($flags)) < $count && [$set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i], $set->idAt($i)] < $key) {
                $flags[] = 0;
            }

            if ($i < $count && $set->idAt($i) === $row['id']) {
                $flags[] = self::mask((bool) $row['first_attempt'], (bool) $row['after_group'], (bool) $row['commented'], (bool) $row['competition']);
            }
        }

        while (count($flags) < $count) {
            $flags[] = 0;
        }

        return new self($flags);
    }

    /**
     * @param list<int> $flags
     */
    public static function fromList(array $flags): self
    {
        return new self($flags);
    }

    public static function mask(bool $firstAttempt, bool $afterGroup, bool $commented, bool $competition): int
    {
        return ($firstAttempt ? 0 : self::UNFLAGGED)
            | ($afterGroup ? self::AFTER_GROUP : 0)
            | ($commented ? self::COMMENTED : 0)
            | ($competition ? self::COMPETITION : 0);
    }

    public function of(int $i): int
    {
        return $this->flags[$i] ?? 0;
    }
}
