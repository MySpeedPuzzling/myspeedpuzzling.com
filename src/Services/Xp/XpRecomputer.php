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
 * Running it twice in a row yields an identical ledger — a solve keeps the difficulty tier it earned with
 * (XpSolveTier, which the wipe leaves alone), only solves without one read today's tier; speed medians are read as of
 * the run.
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
        $earnedWithTier = [];
        $history = $this->xpHistoryReplay->loadHistory($playerId);
        $historyById = array_column($history, null, 'id');

        foreach ($this->xpHistoryReplay->replay($playerId, $history) as $solveId => $solveDrafts) {
            foreach ($solveDrafts as $draft) {
                $drafts[] = $draft;
            }

            if ($solveDrafts !== [] && isset($historyById[$solveId])) {
                $earnedWithTier[] = [
                    'solvingTimeId' => $solveId,
                    'puzzleId' => $historyById[$solveId]['puzzle_id'],
                    'difficultyTier' => $historyById[$solveId]['difficulty_tier'],
                ];
            }
        }

        /** @var list<XpEntryDraft> $drafts */
        $this->xpLedger->append($player, $drafts);
        $this->xpLedger->recordAwardedTiers($playerId, $earnedWithTier);
    }
}
