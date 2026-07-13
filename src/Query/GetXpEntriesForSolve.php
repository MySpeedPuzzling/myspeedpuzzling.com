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
     * One participant's receipt for one solve — team members each have their own lines.
     *
     * @return list<XpEntryLine>
     */
    public function forPlayerAndSolvingTime(string $playerId, string $solvingTimeId): array
    {
        $sql = <<<SQL
SELECT reason, amount, earned_at, solving_time_id, badge_id
FROM xp_entry
WHERE solving_time_id = :solvingTimeId
  AND player_id = :playerId
ORDER BY created_at, id
SQL;

        /** @var list<array{reason: string, amount: int, earned_at: string, solving_time_id: null|string, badge_id: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['solvingTimeId' => $solvingTimeId, 'playerId' => $playerId])
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
     * Net XP the solve is currently worth to ONE player — shown in the
     * delete-confirmation warning ("you will lose N XP earned by this solve").
     */
    public function totalForPlayerAndSolvingTime(string $playerId, string $solvingTimeId): int
    {
        $sql = <<<SQL
SELECT COALESCE(SUM(amount), 0)
FROM xp_entry
WHERE solving_time_id = :solvingTimeId
  AND player_id = :playerId
SQL;

        $value = $this->database
            ->executeQuery($sql, ['solvingTimeId' => $solvingTimeId, 'playerId' => $playerId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
