<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * The achievement tiers one player earned with one solve - the recap's achievement block (members) and the
 * "you earned an achievement with this time" line (free players, who never learn which).
 *
 * A badge row does not point at the solve that earned it: saving a time queues RecalculateBadgesForPlayer for every
 * registered member, so the tiers it earned are the ones stamped shortly after the time was saved (`tracked_at` is the
 * moment of the save). The window is short on purpose - a later run of the badge cron must not be credited to an old
 * time. Highest tier per type: gap-filled lower tiers land in the same run. The time row only dates the save, so a
 * suspicious result is read like any other (the badge conditions leave suspicious results out themselves).
 */
readonly class GetAchievementsEarnedWithSolve
{
    public const string WINDOW = '10 minutes';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Best tier first.
     *
     * @return list<BadgeResult>
     */
    public function forPlayerAndSolvingTime(string $playerId, string $solvingTimeId): array
    {
        $window = self::WINDOW;

        $sql = <<<SQL
SELECT DISTINCT ON (b.type) b.id, b.type, b.tier, b.earned_at, b.revealed_at
FROM puzzle_solving_time pst
JOIN badge b ON b.player_id = :playerId
    AND b.earned_at >= pst.tracked_at
    AND b.earned_at < pst.tracked_at + INTERVAL '{$window}'
WHERE pst.id = :solvingTimeId
ORDER BY b.type ASC, b.tier DESC NULLS LAST
SQL;

        /** @var list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId, 'solvingTimeId' => $solvingTimeId])
            ->fetchAllAssociative();

        $badges = [];

        foreach ($rows as $row) {
            // A type removed from the code must not break the recap
            $type = BadgeType::tryFrom($row['type']);

            if ($type === null) {
                continue;
            }

            $badges[] = new BadgeResult(
                type: $type,
                tier: $row['tier'] === null ? null : BadgeTier::from((int) $row['tier']),
                earnedAt: new DateTimeImmutable($row['earned_at']),
                id: $row['id'],
                revealedAt: $row['revealed_at'] === null ? null : new DateTimeImmutable($row['revealed_at']),
            );
        }

        // Best first: a single-tier achievement counts like Gold (its points sit between Gold and Platinum)
        usort($badges, static fn (BadgeResult $a, BadgeResult $b): int => self::rank($b) <=> self::rank($a));

        return $badges;
    }

    private static function rank(BadgeResult $badge): int
    {
        return $badge->tier === null ? BadgeTier::Gold->value : $badge->tier->value;
    }
}
