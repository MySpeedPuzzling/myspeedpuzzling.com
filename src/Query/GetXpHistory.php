<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpEntryLine;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Paginated XP ledger for the audit page — every entry with reason, amount and solve link.
 */
readonly class GetXpHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<XpEntryLine>
     */
    public function forPlayer(string $playerId, int $limit, int $offset): array
    {
        $sql = <<<SQL
SELECT reason, amount, earned_at, solving_time_id, badge_id
FROM xp_entry
WHERE player_id = :playerId
ORDER BY earned_at DESC, created_at DESC, id DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{reason: string, amount: int, earned_at: string, solving_time_id: null|string, badge_id: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, [
                'playerId' => $playerId,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new XpEntryLine(
                reason: XpReason::from($row['reason']),
                amount: $row['amount'],
                earnedAt: new DateTimeImmutable($row['earned_at']),
                solvingTimeId: $row['solving_time_id'],
                badgeId: $row['badge_id'],
            );
        }

        return $lines;
    }

    public function countForPlayer(string $playerId): int
    {
        $sql = <<<SQL
SELECT COUNT(*)
FROM xp_entry
WHERE player_id = :playerId
SQL;

        $value = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
