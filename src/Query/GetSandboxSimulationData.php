<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Sandbox\SandboxPeople;
use SpeedPuzzling\Web\Value\SandboxPlayer;
use SpeedPuzzling\Web\Value\SandboxPuzzle;

/**
 * What the sandbox's simulation works with (docs/features/sandbox/README.md "Data"): its generated players (never a
 * person who signed up on the sandbox - only the `test-user+N@myspeedpuzzling.com` accounts of the seed) and a pool
 * of catalogue puzzles worth solving.
 */
readonly final class GetSandboxSimulationData
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<SandboxPlayer>
     */
    public function players(): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT p.id, p.user_id, p.code, p.registered_at
FROM player p
JOIN user_account ua ON ua.user_id = p.user_id
WHERE ua.email LIKE :emailLike
  AND ua.email <> :freePlayer
ORDER BY p.registered_at, p.id
SQL, [
            'emailLike' => SandboxPeople::EMAIL_LIKE,
            'freePlayer' => SandboxPeople::FREE_PLAYER_EMAIL,
        ]);

        return array_map(
            static function (array $row): SandboxPlayer {
                /** @var array{id: string, user_id: string, code: string, registered_at: string} $row */
                return new SandboxPlayer(
                    id: $row['id'],
                    userId: $row['user_id'],
                    code: $row['code'],
                    registeredAt: new DateTimeImmutable($row['registered_at']),
                );
            },
            $rows,
        );
    }

    /**
     * Approved puzzles with a picture in the common piece counts - the ones people actually log. A stable sample
     * (by id), so the seed and the cron draw from the same pool.
     *
     * @return list<SandboxPuzzle>
     */
    public function puzzlePool(int $limit): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT p.id, p.pieces_count
FROM puzzle p
WHERE p.approved = true
  AND p.image IS NOT NULL
  AND p.hide_until IS NULL
  AND p.hide_image_until IS NULL
  AND p.pieces_count IN (100, 200, 300, 500, 1000, 1500)
ORDER BY md5(p.id::text)
LIMIT :limit
SQL, ['limit' => $limit]);

        return array_map(
            static function (array $row): SandboxPuzzle {
                /** @var array{id: string, pieces_count: int|string} $row */
                return new SandboxPuzzle(id: $row['id'], piecesCount: (int) $row['pieces_count']);
            },
            $rows,
        );
    }

    /**
     * How many times the player logged each puzzle so far
     *
     * @return array<string, int>
     */
    public function solvedCounts(string $playerId): array
    {
        $rows = $this->database->fetchAllKeyValue(<<<SQL
SELECT pst.puzzle_id, COUNT(*)
FROM puzzle_solving_time pst
WHERE pst.player_id = :playerId
GROUP BY pst.puzzle_id
SQL, ['playerId' => $playerId]);

        $counts = [];

        foreach ($rows as $puzzleId => $count) {
            $counts[(string) $puzzleId] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }
}
