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
     * @return list<BadgeType>
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

        $badges = [];

        foreach ($data as $row) {
            /** @var array{
             *     type: string,
             * } $row
             */

            // Unknown values in the database (e.g. badge types that were
            // removed or not implemented yet) must not break player profiles
            $badge = BadgeType::tryFrom($row['type']);

            if ($badge !== null) {
                $badges[] = $badge;
            }
        }

        return $badges;
    }
}
