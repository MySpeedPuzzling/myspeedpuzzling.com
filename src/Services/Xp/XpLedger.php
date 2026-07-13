<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\XpEntry;
use SpeedPuzzling\Web\Repository\XpEntryRepository;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpLevelChange;

/**
 * Persistence orchestrator for the XP ledger: writes entries and keeps the denormalized
 * Player.xpTotal / Player.level in sync within the same transaction (flush is owned by
 * the messenger doctrine_transaction middleware — nothing here flushes).
 *
 * Invariant: Player.xpTotal always equals SUM(xp_entry.amount) for that player.
 */
readonly final class XpLedger
{
    public function __construct(
        private XpEntryRepository $xpEntryRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<XpEntryDraft> $drafts
     */
    public function append(Player $player, array $drafts): XpLevelChange
    {
        $now = $this->clock->now();
        $delta = 0;

        foreach ($drafts as $draft) {
            $this->xpEntryRepository->save(new XpEntry(
                id: Uuid::uuid7(),
                playerId: $player->id,
                amount: $draft->amount,
                reason: $draft->reason,
                inWeeklyDelta: $draft->inWeeklyDelta,
                earnedAt: $draft->earnedAt,
                createdAt: $now,
                solvingTimeId: $draft->solvingTimeId,
                badgeId: $draft->badgeId,
            ));

            $delta += $draft->amount;
        }

        $previousXpTotal = $player->xpTotal;
        $previousLevel = $player->level;

        $newXpTotal = $previousXpTotal + $delta;
        $newLevel = LevelTable::levelForXp($newXpTotal);

        if ($delta !== 0) {
            $player->updateExperience($newXpTotal, $newLevel);
        }

        return new XpLevelChange(
            previousXpTotal: $previousXpTotal,
            newXpTotal: $newXpTotal,
            previousLevel: $previousLevel,
            newLevel: $newLevel,
        );
    }
}
