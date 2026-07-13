<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpHistoryEntry;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Paginated XP ledger for the audit page — every entry with reason, amount and a link
 * back to its solve or achievement. User-facing auditability is a locked requirement.
 */
readonly class GetXpHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<XpHistoryEntry>
     */
    public function forPlayer(string $playerId, int $limit, int $offset): array
    {
        $sql = <<<SQL
SELECT
    e.reason,
    e.amount,
    e.earned_at,
    e.solving_time_id,
    pst.puzzle_id,
    p.name AS puzzle_name,
    b.type AS badge_type,
    b.tier AS badge_tier
FROM xp_entry e
LEFT JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id
LEFT JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN badge b ON b.id = e.badge_id
WHERE e.player_id = :playerId
ORDER BY e.earned_at DESC, e.created_at DESC, e.id DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{reason: string, amount: int, earned_at: string, solving_time_id: null|string, puzzle_id: null|string, puzzle_name: null|string, badge_type: null|string, badge_tier: null|int}> $rows */
        $rows = $this->database
            ->executeQuery($sql, [
                'playerId' => $playerId,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new XpHistoryEntry(
                reason: XpReason::from($row['reason']),
                amount: $row['amount'],
                earnedAt: new DateTimeImmutable($row['earned_at']),
                solvingTimeId: $row['solving_time_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                badgeType: $row['badge_type'] === null ? null : BadgeType::tryFrom($row['badge_type']),
                badgeTier: $row['badge_tier'] === null ? null : BadgeTier::from($row['badge_tier']),
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
