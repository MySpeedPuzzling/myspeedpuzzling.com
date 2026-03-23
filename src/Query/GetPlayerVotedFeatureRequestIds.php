<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetPlayerVotedFeatureRequestIds
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, true>
     */
    public function __invoke(string $playerId): array
    {
        $query = <<<SQL
SELECT feature_request_id FROM feature_request_vote WHERE voter_id = :playerId
SQL;

        /** @var array<string> $ids */
        $ids = $this->database->executeQuery($query, [
            'playerId' => $playerId,
        ])->fetchFirstColumn();

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = true;
        }

        return $map;
    }
}
