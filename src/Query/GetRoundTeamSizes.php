<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * How many people each pair/team of a round has - only teams with at least one person on the event (removed people
 * left out). The round form pre-fills a team round's expected size with the most common of them
 * (ParticipantRules::usualTeamSize(), participants-spreadsheet.md D5). One statement.
 */
readonly final class GetRoundTeamSizes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<int>
     */
    public function ofRound(string $roundId): array
    {
        /** @var list<int|string> $sizes */
        $sizes = $this->database->fetchFirstColumn(
            <<<SQL
SELECT COUNT(*)
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
WHERE cpr.round_id = :roundId
    AND cpr.team_id IS NOT NULL
    AND cp.deleted_at IS NULL
GROUP BY cpr.team_id
SQL,
            ['roundId' => $roundId],
        );

        return array_map(intval(...), $sizes);
    }
}
