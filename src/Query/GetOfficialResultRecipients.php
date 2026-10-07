<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Who hears about a round's published official results (NotifyWhenOfficialRoundResultsPublished): every player linked
 * to an entry with a finished result - the person of a solo round, the members of a pair/team. People removed from
 * the event and unfinished or did-not-start results are left out. Background fan-out, no viewer.
 */
readonly final class GetOfficialResultRecipients
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string> player ids
     */
    public function forRound(string $roundId): array
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn(
            <<<SQL
SELECT DISTINCT cp.player_id
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN competition_team ct ON ct.id = cpr.team_id
WHERE cpr.round_id = :roundId
    AND cp.player_id IS NOT NULL
    AND (cpr.result_seconds IS NOT NULL OR ct.result_seconds IS NOT NULL)
SQL,
            ['roundId' => $roundId],
        );

        return $playerIds;
    }
}
