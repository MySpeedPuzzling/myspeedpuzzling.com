<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

readonly final class GetPlayerVoteCountThisMonth
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $playerId): int
    {
        $now = $this->clock->now();
        $monthStart = $now->modify('first day of this month midnight');

        $query = <<<SQL
SELECT COUNT(*)
FROM feature_request_vote fv
JOIN feature_request fr ON fv.feature_request_id = fr.id
WHERE fv.voter_id = :playerId AND fv.voted_at >= :monthStart AND fr.author_id != fv.voter_id
SQL;

        $result = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'monthStart' => $monthStart->format('Y-m-d H:i:s'),
        ])->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
