<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\RoundPuzzleOwnership;

readonly final class MayKeepRoundPuzzleHiddenEverywhere
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byId(string $roundPuzzleId): bool
    {
        if (Uuid::isValid($roundPuzzleId) === false) {
            return false;
        }

        $mayKeep = RoundPuzzleOwnership::sqlMayKeepHiddenEverywhere('crp', 'p', 'cr');

        return $this->database->fetchOne(
            <<<SQL
SELECT 1
FROM competition_round_puzzle crp
INNER JOIN puzzle p ON p.id = crp.puzzle_id
INNER JOIN competition_round cr ON cr.id = crp.round_id
WHERE crp.id = :roundPuzzleId
    AND {$mayKeep}
SQL,
            ['roundPuzzleId' => $roundPuzzleId],
        ) !== false;
    }
}
