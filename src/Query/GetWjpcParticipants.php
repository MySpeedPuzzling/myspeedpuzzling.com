<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetWjpcParticipants
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function mappingForPairing(): array
    {
        $query = <<<SQL
SELECT name, id
FROM wjpc_participant
SQL;
        $results = [];

        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            /**
             * @var array{name: string, id: string} $row
             */

            $results[$row['name']] = $row['id'];
        }

        return $results;
    }
}
