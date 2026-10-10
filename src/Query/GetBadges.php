<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

readonly class GetBadges
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Every earned badge row, all tiers included — the badge evaluator needs the complete
     * set to decide what is still missing (the display query below collapses to the highest
     * tier per type, which would make re-evaluations re-insert lower tiers).
     *
     * @return list<BadgeResult>
     */
    public function allEarnedTiers(string $playerId): array
    {
        $sql = <<<SQL
SELECT id, type, tier, earned_at, revealed_at
FROM badge
WHERE player_id = :playerId
ORDER BY type ASC, tier ASC NULLS LAST
SQL;

        /** @var list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return $this->hydrate($rows);
    }

    /**
     * @return list<BadgeResult>
     */
    public function forPlayer(string $playerId): array
    {
        $sql = <<<SQL
SELECT DISTINCT ON (type) id, type, tier, earned_at, revealed_at
FROM badge
WHERE player_id = :playerId
ORDER BY type ASC, tier DESC NULLS LAST
SQL;

        /** @var list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return $this->hydrate($rows);
    }

    /**
     * Highest earned tier per type that the owner has not flipped yet — the
     * membership-activation reveal page shows these in sequence.
     *
     * @return list<BadgeResult>
     */
    public function unrevealedForPlayer(string $playerId): array
    {
        $sql = <<<SQL
SELECT DISTINCT ON (type) id, type, tier, earned_at, revealed_at
FROM badge
WHERE player_id = :playerId
ORDER BY type ASC, tier DESC NULLS LAST
SQL;

        /** @var list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_values(array_filter(
            $this->hydrate($rows),
            static fn (BadgeResult $badge): bool => $badge->isRevealed() === false,
        ));
    }

    /**
     * How many achievement tiers the player earned with this solve - for the post-solve teaser of a free player
     * ("You earned an achievement with this time. Become a member to find out which."), who must never learn which.
     * One statement, no hydration.
     *
     * "With this solve" = earned since the solve was logged and before the player logged their next one: the badge
     * evaluation runs asynchronously right after the save (RecalculateBadgesForPlayer), so its tiers carry a later
     * earned_at than the solve's tracked_at. 0 until the evaluation has run.
     */
    public function countTiersEarnedWithSolve(string $playerId, string $solvingTimeId): int
    {
        $value = $this->database
            ->executeQuery(
                'SELECT COUNT(*) FROM badge b ' . self::EARNED_WITH_SOLVE,
                ['playerId' => $playerId, 'solvingTimeId' => $solvingTimeId],
            )
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The achievements earned with this solve (see countTiersEarnedWithSolve()), the highest new tier per type - for
     * a member's post-solve reveal.
     *
     * @return list<BadgeResult>
     */
    public function earnedWithSolve(string $playerId, string $solvingTimeId): array
    {
        $sql = 'SELECT DISTINCT ON (b.type) b.id, b.type, b.tier, b.earned_at, b.revealed_at FROM badge b '
            . self::EARNED_WITH_SOLVE
            . ' ORDER BY b.type ASC, b.tier DESC NULLS LAST';

        /** @var list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId, 'solvingTimeId' => $solvingTimeId])
            ->fetchAllAssociative();

        return $this->hydrate($rows);
    }

    private const string EARNED_WITH_SOLVE = <<<SQL
JOIN puzzle_solving_time solve ON solve.id = :solvingTimeId
WHERE b.player_id = :playerId
  AND b.earned_at >= solve.tracked_at
  AND NOT EXISTS (
    SELECT 1
    FROM puzzle_solving_time later
    WHERE later.player_id = :playerId
      AND later.id != solve.id
      AND later.tracked_at > solve.tracked_at
      AND later.tracked_at <= b.earned_at
  )
SQL;

    /**
     * @param list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows
     * @return list<BadgeResult>
     */
    private function hydrate(array $rows): array
    {
        $badges = [];

        foreach ($rows as $row) {
            // Unknown values in the database (e.g. badge types that were
            // removed or not implemented yet) must not break player profiles
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

        return $badges;
    }
}
