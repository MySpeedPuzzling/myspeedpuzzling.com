<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\HintType;

readonly final class IsHintDismissed
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function __invoke(string $playerId, HintType $type): bool
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM dismissed_hint
WHERE player_id = :playerId AND type = :type
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'type' => $type->value,
            ])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }
}
