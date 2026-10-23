<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Progression\PlayerProgressionLock;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Freezes the difficulty tier of every solve that earned XP before tiers were frozen (XpSolveTier), read back from
 * the amounts its ledger lines hold (XpHistoryReplay::inferAwardedTiers()). Idempotent: frozen solves are skipped.
 * Changes no XP - only what later replays read.
 */
readonly final class XpTierFreezer
{
    public function __construct(
        private Connection $database,
        private XpHistoryReplay $xpHistoryReplay,
        private XpLedger $xpLedger,
        private PlayerProgressionLock $playerProgressionLock,
    ) {
    }

    /**
     * @return int solves frozen
     */
    public function freezeForPlayer(string $playerId): int
    {
        $this->playerProgressionLock->lock([$playerId]);

        /** @var list<array{solving_time_id: string, base: int|string, difficulty: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT solving_time_id,
                    COALESCE(SUM(amount) FILTER (WHERE reason = :base), 0) AS base,
                    COALESCE(SUM(amount) FILTER (WHERE reason IN (:bonus, :settlement)), 0) AS difficulty
             FROM xp_entry
             WHERE player_id = :playerId AND solving_time_id IS NOT NULL
             GROUP BY solving_time_id
             HAVING COUNT(*) FILTER (WHERE reason = :base) > 0 AND COUNT(*) FILTER (WHERE reason = :compensation) = 0',
            [
                'playerId' => $playerId,
                'base' => XpReason::SolveBase->value,
                'bonus' => XpReason::SolveDifficultyBonus->value,
                'settlement' => XpReason::DifficultySettlement->value,
                'compensation' => XpReason::SolveCompensation->value,
            ],
        )->fetchAllAssociative();

        $earnedBySolve = [];

        foreach ($rows as $row) {
            $earnedBySolve[$row['solving_time_id']] = ['base' => (int) $row['base'], 'difficulty' => (int) $row['difficulty']];
        }

        $tiers = $this->xpHistoryReplay->inferAwardedTiers(
            $playerId,
            $this->xpHistoryReplay->loadHistory($playerId),
            $earnedBySolve,
        );

        $this->xpLedger->recordAwardedTiers($playerId, $tiers);

        return count($tiers);
    }
}
