<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerSkillResult;

readonly final class GetPlayerSkill
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<PlayerSkillResult>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ps.player_id,
    ps.pieces_count,
    ps.skill_score,
    ps.skill_tier,
    ps.skill_percentile,
    ps.confidence,
    ps.qualifying_puzzles_count
FROM player_skill ps
WHERE ps.player_id = :playerId
ORDER BY ps.pieces_count ASC
SQL;

        /** @var list<array{player_id: string, pieces_count: int|string, skill_score: float|string, skill_tier: int|string, skill_percentile: float|string, confidence: string, qualifying_puzzles_count: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
        ])->fetchAllAssociative();

        return array_map(
            static fn (array $row): PlayerSkillResult => PlayerSkillResult::fromDatabaseRow($row),
            $rows,
        );
    }

    public function byPlayerIdAndPiecesCount(string $playerId, int $piecesCount): null|PlayerSkillResult
    {
        $query = <<<SQL
SELECT
    ps.player_id,
    ps.pieces_count,
    ps.skill_score,
    ps.skill_tier,
    ps.skill_percentile,
    ps.confidence,
    ps.qualifying_puzzles_count
FROM player_skill ps
WHERE ps.player_id = :playerId
    AND ps.pieces_count = :piecesCount
SQL;

        /** @var array{player_id: string, pieces_count: int|string, skill_score: float|string, skill_tier: int|string, skill_percentile: float|string, confidence: string, qualifying_puzzles_count: int|string}|false $row */
        $row = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return PlayerSkillResult::fromDatabaseRow($row);
    }
}
