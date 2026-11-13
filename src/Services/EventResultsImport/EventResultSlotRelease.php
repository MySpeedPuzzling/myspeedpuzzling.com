<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultClaimLog;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultClaimLogRepository;
use SpeedPuzzling\Web\Services\PuzzlingTeamMemberConversion;
use SpeedPuzzling\Web\Value\EventResultsImport\EventResultClaimRefusal;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A player's spot of an imported event result becomes anonymous again (docs/features/event-results-import/README.md
 * "Gates") - the one way for "Remove from my profile", "Not on our team" and a deleted account: a new anonymous slot in
 * the player's place, the tracker moves to the next claimer of the result or to nobody, and the log says why. The result
 * itself stays - it is the event's.
 */
readonly final class EventResultSlotRelease
{
    public function __construct(
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private PlayerRepository $playerRepository,
        private ResultClaimLogRepository $resultClaimLogRepository,
        private PuzzlingTeamMemberConversion $puzzlingTeamMemberConversion,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EventResultClaimRefused
     */
    public function release(PuzzleSolvingTime $time, Player $player, ResultClaimAction $action, null|Player $by, null|string $reason = null): void
    {
        if ($time->isImportedFromEvent() === false) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::NotImported);
        }

        $playerId = $player->id->toString();
        $membersBefore = $time->memberPlayerIds();

        if (in_array($playerId, $membersBefore, true) === false) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::NotAClaimer);
        }

        $slot = TeamComposition::ANONYMOUS_PREFIX . Uuid::uuid7()->toString();

        $this->puzzlingTeamMemberConversion->playerToAnonymousSlot($time->id->toString(), $playerId, $slot);
        // The people and the pair/team changed in SQL - the entity reads them again before its own change
        $this->solvingTimeRepository->reload($time);
        $time->slotReleasedBy($player, $time->isTrackedBy($player) ? $this->nextTracker($time, $playerId) : null);

        $this->resultClaimLogRepository->save(new ResultClaimLog(
            id: Uuid::uuid7(),
            solvingTimeId: $time->id,
            slotKey: $slot,
            playerId: $player->id,
            playerName: $player->name ?? $player->code,
            action: $action,
            byPlayerId: $by?->id,
            reason: $reason,
            at: $this->clock->now(),
        ));

        // A deleted account has nothing left to recalculate - the deletion takes its achievements and XP along
        if ($action !== ResultClaimAction::AccountDeleted) {
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($playerId));
            $this->messageBus->dispatch(new RecalculateXpChainForSolve($time->id->toString(), $membersBefore));
        }
    }

    /**
     * The next claimer in the order of the result's people, or nobody.
     */
    private function nextTracker(PuzzleSolvingTime $time, string $leavingPlayerId): null|Player
    {
        foreach ($time->team->puzzlers ?? [] as $puzzler) {
            if ($puzzler->playerId === null || $puzzler->playerId === $leavingPlayerId) {
                continue;
            }

            try {
                return $this->playerRepository->get($puzzler->playerId);
            } catch (PlayerNotFound) {
                continue;
            }
        }

        return null;
    }
}
