<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\GroupCoordination;

/**
 * The pair/team coordination constants as last fitted (group_coordination_constant), the seed for any bucket not
 * stored yet. Aggregates only - no player is named or counted individually.
 */
readonly final class GetGroupCoordinationConstants
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{first: float, repeat: float}> group size (2, 3, 4 = four or more) => constant
     */
    public function all(): array
    {
        $constants = GroupCoordination::SEEDS;

        /** @var list<array{group_size: int|string, first_attempt: bool|int|string, coordination: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT group_size, first_attempt, coordination FROM group_coordination_constant');

        foreach ($rows as $row) {
            $size = (int) $row['group_size'];

            if (isset($constants[$size])) {
                $first = $row['first_attempt'] === true || $row['first_attempt'] === 1 || $row['first_attempt'] === '1' || $row['first_attempt'] === 't';
                $constants[$size][$first ? 'first' : 'repeat'] = (float) $row['coordination'];
            }
        }

        return $constants;
    }
}
