<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetPlayerIdsForSitemap
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Public (non-private) player profiles with a name for route player_profile.
     *
     * @return array<string>
     */
    public function allPublic(): array
    {
        $query = <<<SQL
SELECT id
FROM player
WHERE is_private = false
    AND name IS NOT NULL
    AND name != ''
ORDER BY id
SQL;

        /** @var array<string> $playerIds */
        $playerIds = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $playerIds;
    }
}
