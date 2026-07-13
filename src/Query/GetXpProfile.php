<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\XpProfile;

readonly class GetXpProfile
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function byPlayerId(string $playerId): XpProfile
    {
        $sql = <<<SQL
SELECT id, xp_total, level, experience_system_opted_out
FROM player
WHERE id = :playerId
SQL;

        /** @var array{id: string, xp_total: int, level: int, experience_system_opted_out: bool}|false $row */
        $row = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAssociative();

        if ($row === false) {
            throw new PlayerNotFound();
        }

        return new XpProfile(
            playerId: $row['id'],
            xpTotal: $row['xp_total'],
            level: $row['level'],
            optedOut: $row['experience_system_opted_out'],
        );
    }
}
