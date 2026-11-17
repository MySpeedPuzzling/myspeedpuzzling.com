<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\RelayResultRound;

/**
 * Where a relay team's result is shown (docs/features/event-results-import/solo-and-relays.md §2): not on the puzzle's
 * result detail (IsRelayResult) but on its event's round, ranked by the relay's total. The result detail asks this only
 * once it found no result - every link into it that reaches a relay result (feeds, notifications, the claim screens'
 * fallback) leads to the round instead. Null for any other result, and for an event nobody may see.
 */
readonly final class GetRelayResultRound
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofTime(string $timeId): null|RelayResultRound
    {
        if (Uuid::isValid($timeId) === false) {
            return null;
        }

        $publiclyVisible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT
    c.slug AS competition_slug,
    c.name AS competition_name,
    round.slug AS round_slug,
    relay.seconds,
    (
        SELECT JSON_AGG(leg_puzzle.name ORDER BY leg.id)
        FROM puzzle_solving_time leg
            INNER JOIN puzzle leg_puzzle ON leg_puzzle.id = leg.puzzle_id
        WHERE leg.relay_result_id = pst.relay_result_id
    ) AS puzzle_names
FROM puzzle_solving_time pst
    INNER JOIN relay_result relay ON relay.id = pst.relay_result_id
    INNER JOIN competition c ON c.id = pst.competition_id
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN competition_round round ON round.id = pst.competition_round_id
WHERE pst.id = :timeId
    AND c.slug IS NOT NULL
    AND round.slug IS NOT NULL
    AND {$publiclyVisible}
SQL;

        /** @var false|array{competition_slug: string, competition_name: string, round_slug: string, seconds: int, puzzle_names: string} $row */
        $row = $this->database->fetchAssociative($query, ['timeId' => $timeId]);

        if ($row === false) {
            return null;
        }

        /** @var list<string> $puzzleNames */
        $puzzleNames = Json::decode($row['puzzle_names'], forceArrays: true);

        return new RelayResultRound(
            competitionSlug: $row['competition_slug'],
            competitionName: $row['competition_name'],
            roundSlug: $row['round_slug'],
            totalSeconds: $row['seconds'],
            puzzleNames: $puzzleNames,
        );
    }
}
