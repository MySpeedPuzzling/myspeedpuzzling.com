<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\FeatureRequestVoter;

readonly final class GetFeatureRequestVoters
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<FeatureRequestVoter>
     */
    public function excludingPlayer(string $featureRequestId, string $excludedPlayerId): array
    {
        $query = <<<SQL
SELECT DISTINCT ON (fv.voter_id)
    fv.voter_id AS player_id,
    p.email AS email,
    p.locale AS locale
FROM feature_request_vote fv
JOIN player p ON fv.voter_id = p.id
WHERE fv.feature_request_id = :featureRequestId
    AND fv.voter_id != :excludedPlayerId
    AND p.email IS NOT NULL
ORDER BY fv.voter_id, fv.voted_at DESC
SQL;

        /** @var list<array{player_id: string, email: string, locale: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'featureRequestId' => $featureRequestId,
            'excludedPlayerId' => $excludedPlayerId,
        ]);

        return array_map(
            static fn(array $row): FeatureRequestVoter => new FeatureRequestVoter(
                playerId: $row['player_id'],
                email: $row['email'],
                locale: $row['locale'],
            ),
            $rows,
        );
    }
}
