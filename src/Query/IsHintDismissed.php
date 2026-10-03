<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Several hints of one page in one query: which of them the player dismissed.
     *
     * @return list<HintType>
     */
    public function dismissedAmong(string $playerId, HintType ...$types): array
    {
        if ($types === []) {
            return [];
        }

        $query = <<<SQL
SELECT type
FROM dismissed_hint
WHERE player_id = :playerId AND type IN (:types)
SQL;

        /** @var list<string> $dismissed */
        $dismissed = $this->database
            ->executeQuery(
                $query,
                [
                    'playerId' => $playerId,
                    'types' => array_map(static fn (HintType $type): string => $type->value, array_values($types)),
                ],
                ['types' => ArrayParameterType::STRING],
            )
            ->fetchFirstColumn();

        return array_values(array_filter(
            $types,
            static fn (HintType $type): bool => in_array($type->value, $dismissed, true),
        ));
    }
}
