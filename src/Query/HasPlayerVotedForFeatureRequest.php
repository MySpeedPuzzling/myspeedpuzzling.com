<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class HasPlayerVotedForFeatureRequest
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function __invoke(string $playerId, string $featureRequestId): bool
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM feature_request_vote
WHERE voter_id = :playerId AND feature_request_id = :featureRequestId
SQL;

        $result = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'featureRequestId' => $featureRequestId,
        ])->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }
}
