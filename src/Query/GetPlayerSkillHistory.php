<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerSkillHistoryPoint;

readonly final class GetPlayerSkillHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<PlayerSkillHistoryPoint>
     */
    public function byPlayerId(string $playerId, int $piecesCount): array
    {
        $query = <<<SQL
SELECT
    psh.month,
    psh.baseline_seconds,
    psh.skill_tier,
    psh.skill_percentile
FROM player_skill_history psh
WHERE psh.player_id = :playerId
    AND psh.pieces_count = :piecesCount
ORDER BY psh.month ASC
SQL;

        /** @var list<array{month: string, baseline_seconds: int|string, skill_tier: null|int|string, skill_percentile: null|float|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ])->fetchAllAssociative();

        return array_map(
            static fn (array $row): PlayerSkillHistoryPoint => PlayerSkillHistoryPoint::fromDatabaseRow($row),
            $rows,
        );
    }
}
