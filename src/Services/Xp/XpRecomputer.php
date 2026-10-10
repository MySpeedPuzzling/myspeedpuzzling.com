<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Progression\PlayerProgressionLock;
use SpeedPuzzling\Web\Value\XpEntryDraft;

/**
 * Deterministic full-ledger rebuild for one player: wipes every ledger entry (settlements and the audit history of
 * deleted solves included), replays the complete solve history (XpHistoryReplay - the same replay the live wiring
 * uses) and restores Player totals. Every entry comes from a solve - achievements grant no XP, so a rebuild also drops
 * achievement entries written before that decision.
 *
 * Running it twice in a row yields an identical ledger — difficulty tiers and speed medians
 * are read as of the run ("tier at backfill run time, settled immediately, frozen forever").
 * Suspicious solves earn no XP, mirroring every other derived system (badges, statistics,
 * intelligence).
 */
readonly final class XpRecomputer
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private XpLedger $xpLedger,
        private XpHistoryReplay $xpHistoryReplay,
        private PlayerProgressionLock $playerProgressionLock,
    ) {
    }

    public function recomputeForPlayer(string $playerId): void
    {
        $this->playerProgressionLock->lock([$playerId]);

        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return;
        }

        // The doctrine_transaction middleware makes wipe + rebuild atomic
        $this->xpLedger->wipe($player);

        $drafts = [];

        foreach ($this->xpHistoryReplay->replay($playerId, $this->xpHistoryReplay->loadHistory($playerId)) as $solveDrafts) {
            foreach ($solveDrafts as $draft) {
                $drafts[] = $draft;
            }
        }

        /** @var list<XpEntryDraft> $drafts */
        $this->xpLedger->append($player, $drafts);
    }
}
