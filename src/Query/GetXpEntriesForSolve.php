<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpEntryLine;
use SpeedPuzzling\Web\Value\XpReason;

readonly class GetXpEntriesForSolve
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<XpEntryLine>
     */
    public function forSolvingTime(string $solvingTimeId): array
    {
        $sql = <<<SQL
SELECT reason, amount, earned_at, solving_time_id, badge_id
FROM xp_entry
WHERE solving_time_id = :solvingTimeId
ORDER BY created_at, id
SQL;

        /** @var list<array{reason: string, amount: int, earned_at: string, solving_time_id: null|string, badge_id: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['solvingTimeId' => $solvingTimeId])
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

    /**
     * Net XP the solve is currently worth — shown in the delete-confirmation warning.
     */
    public function totalForSolvingTime(string $solvingTimeId): int
    {
        $sql = <<<SQL
SELECT COALESCE(SUM(amount), 0)
FROM xp_entry
WHERE solving_time_id = :solvingTimeId
SQL;

        $value = $this->database
            ->executeQuery($sql, ['solvingTimeId' => $solvingTimeId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
