<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

readonly final class GetPuzzleRedirect
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function findSurvivorPuzzleId(string $oldPuzzleId): null|string
    {
        if (Uuid::isValid($oldPuzzleId) === false) {
            return null;
        }

        $query = <<<SQL
SELECT survivor_puzzle_id
FROM puzzle_redirect
WHERE old_puzzle_id = :oldPuzzleId
SQL;

        $survivorPuzzleId = $this->database->fetchOne($query, [
            'oldPuzzleId' => $oldPuzzleId,
        ]);

        if (is_string($survivorPuzzleId) === false) {
            return null;
        }

        return $survivorPuzzleId;
    }
}
