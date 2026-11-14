<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\CustomValues;

/**
 * THE reader of players' own values on results (docs/features/custom-fields.md) - the only one, and only ever for
 * the player who is signed in: every statement here is bound to the viewer's id, so a pair partner, another player
 * or a guest can not be handed somebody's values by a caller's mistake. Never joined into a result list, the result
 * detail's query or the API - CustomValuesCoverageTest keeps it that way.
 */
readonly final class GetCustomValues
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The viewer's own values on the given results, keyed by solving time id - results without any are left out.
     *
     * @param array<string> $solvingTimeIds
     * @return array<string, CustomValues>
     */
    public function forViewer(array $solvingTimeIds, string $viewerPlayerId): array
    {
        $solvingTimeIds = array_values(array_filter($solvingTimeIds, Uuid::isValid(...)));

        if ($solvingTimeIds === [] || Uuid::isValid($viewerPlayerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT solving_time_id, custom_values
FROM solving_time_custom_values
WHERE player_id = :viewerPlayerId
    AND solving_time_id IN (:solvingTimeIds)
SQL;

        /** @var list<array{solving_time_id: string, custom_values: string}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['viewerPlayerId' => $viewerPlayerId, 'solvingTimeIds' => $solvingTimeIds],
            ['solvingTimeIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $values = [];

        foreach ($rows as $row) {
            $values[$row['solving_time_id']] = CustomValues::fromStored($row['custom_values']);
        }

        return $values;
    }
}
