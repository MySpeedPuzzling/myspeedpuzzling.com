<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class GetBadges
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<BadgeType>
     */
    public function forPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT type
FROM badge
WHERE player_id = :playerId
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): BadgeType {
            /** @var array{
             *     type: string,
             * } $row
             */

            return BadgeType::from($row['type']);
        }, $data);
    }
}
