<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\OnlineRoundSetup;

/**
 * The "Online timing" page's participant counts of a round (docs/features/online-competitions/): who is going (the one
 * rule, CompetitionParticipantGoing), entered in the round, with a personal link. One statement.
 */
readonly final class GetOnlineRoundSetup
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofRound(string $competitionId, string $roundId): OnlineRoundSetup
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($roundId) === false) {
            return new OnlineRoundSetup(going: 0, entered: 0, withLink: 0);
        }

        $going = CompetitionParticipantGoing::sql('cp');

        /** @var array{going: int|string, entered: int|string, with_link: int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    COUNT(*) AS going,
    COUNT(*) FILTER (WHERE EXISTS (
        SELECT 1
        FROM competition_participant_round cpr
        WHERE cpr.participant_id = cp.id
            AND cpr.round_id = :roundId
    )) AS entered,
    COUNT(*) FILTER (WHERE cp.online_token IS NOT NULL) AS with_link
FROM competition_participant cp
WHERE cp.competition_id = :competitionId
    AND {$going}
SQL,
            ['competitionId' => strtolower($competitionId), 'roundId' => strtolower($roundId)],
        );

        return new OnlineRoundSetup(
            going: (int) $row['going'],
            entered: (int) $row['entered'],
            withLink: (int) $row['with_link'],
        );
    }
}
