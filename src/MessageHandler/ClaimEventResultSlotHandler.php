<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultClaimLog;
use SpeedPuzzling\Web\Exceptions\EventResultClaimLimitReached;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Query\GetEventResultClaims;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultClaimLogRepository;
use SpeedPuzzling\Web\Results\FirstTryTime;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Services\PuzzlingTeamMemberConversion;
use SpeedPuzzling\Web\Value\EventResultsImport\EventResultClaimRefusal;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "This was my team" (docs/features/event-results-import/README.md "Claiming a spot"). Every gate is checked before
 * anything changes: an imported result, a spot left, one spot per person per round, not blocked from claiming, the
 * first try (FirstTryAssessor, like a save), the "same result" being the player's own result of the puzzle, the daily
 * limit. Then the first anonymous slot becomes the player, the first claimer tracks the result, the player's own copy
 * goes and the claim is logged; the other claimers are told (EventResultSlotClaimed).
 */
#[AsMessageHandler]
readonly final class ClaimEventResultSlotHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private PlayerRepository $playerRepository,
        private ResultClaimLogRepository $resultClaimLogRepository,
        private GetEventResultClaims $getEventResultClaims,
        private FirstTryAssessor $firstTryAssessor,
        private PuzzlingTeamMemberConversion $puzzlingTeamMemberConversion,
        private RateLimiterFactoryInterface $eventResultClaimLimiter,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws EventResultClaimRefused
     * @throws EventResultClaimLimitReached
     */
    public function __invoke(ClaimEventResultSlot $message): void
    {
        $time = $this->solvingTimeRepository->get($message->timeId);
        $player = $this->playerRepository->get($message->playerId);
        $playerId = $player->id->toString();

        if ($time->isImportedFromEvent() === false) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::NotImported);
        }

        if ($player->eventClaimsBlockedAt !== null) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::Blocked);
        }

        $sameResult = $this->sameResult($message, $time, $player);
        $round = $time->competitionRound;

        if (
            in_array($playerId, $time->memberPlayerIds(), true)
            || ($round !== null && $this->getEventResultClaims->hasSpotInRound($playerId, $round->id->toString(), $sameResult?->id->toString()))
        ) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::AlreadyInRound);
        }

        $slot = self::firstAnonymousSlot($time) ?? throw new EventResultClaimRefused(EventResultClaimRefusal::TeamFull);

        // Only a tag the claim can set is checked - a later claimer keeps it or takes it off (slotClaimedBy())
        if ($message->firstTry && ($time->player === null || $time->firstAttempt)) {
            $this->assertFirstTryPossible($time, $player, $sameResult);
        }

        if ($this->eventResultClaimLimiter->create($playerId)->consume()->isAccepted() === false) {
            throw new EventResultClaimLimitReached();
        }

        $membersBefore = $time->memberPlayerIds();

        if ($sameResult !== null) {
            $this->solvingTimeRepository->delete($sameResult, SolvingTimeDeletedVia::EventResultClaim);
        }

        $this->puzzlingTeamMemberConversion->anonymousSlotToPlayer($time->id->toString(), $slot, $playerId);
        // The people and the pair/team changed in SQL - the entity reads them again before its own change
        $this->solvingTimeRepository->reload($time);
        $time->slotClaimedBy($player, $message->firstTry);

        $this->resultClaimLogRepository->save(new ResultClaimLog(
            id: Uuid::uuid7(),
            solvingTimeId: $time->id,
            slotKey: $slot,
            playerId: $player->id,
            playerName: $player->name ?? $player->code,
            action: ResultClaimAction::Claimed,
            byPlayerId: $player->id,
            reason: null,
            at: $this->clock->now(),
        ));

        // Like after an edit of the group: the result is the player's now (achievements, XP chain of the puzzle)
        $this->messageBus->dispatch(new RecalculateBadgesForPlayer($playerId));
        $this->messageBus->dispatch(new RecalculateXpChainForSolve($time->id->toString(), $membersBefore));
    }

    /**
     * "Is this the same result?" - only the player's own result of this puzzle (theirs to delete).
     */
    private function sameResult(ClaimEventResultSlot $message, PuzzleSolvingTime $time, Player $player): null|PuzzleSolvingTime
    {
        if ($message->sameResultTimeId === null) {
            return null;
        }

        $sameResult = Uuid::isValid($message->sameResultTimeId)
            ? $this->solvingTimeRepository->findById(Uuid::fromString($message->sameResultTimeId))
            : null;

        if (
            $sameResult === null
            || $sameResult->id->equals($time->id)
            || $sameResult->canBeDeletedBy($player) === false
            || $sameResult->puzzle->id->equals($time->puzzle->id) === false
        ) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::SameResultInvalid);
        }

        return $sameResult;
    }

    /**
     * A pair's/team's result is a first try only if it is everybody's (docs/features/first-try-integrity.md): refused
     * when anybody of the result - the player included - already has another first try of the puzzle. The result the
     * claim deletes as the same one does not count.
     */
    private function assertFirstTryPossible(PuzzleSolvingTime $time, Player $player, null|PuzzleSolvingTime $sameResult): void
    {
        $assessment = $this->firstTryAssessor->assess(new FirstTryEntry(
            actorPlayerId: $player->id->toString(),
            puzzleId: $time->puzzle->id->toString(),
            memberPlayerIds: [...$time->memberPlayerIds(), $player->id->toString()],
            solvedAt: $time->finishedAt ?? $time->trackedAt,
            editedTimeId: $time->id->toString(),
        ));

        $holds = array_filter(
            $assessment->holds,
            static fn (FirstTryTime $hold): bool => $sameResult === null || $hold->timeId !== $sameResult->id->toString(),
        );

        if ($holds !== []) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::FirstTryTaken);
        }
    }

    /**
     * The spots are claimed in the order of the result's people.
     */
    private static function firstAnonymousSlot(PuzzleSolvingTime $time): null|string
    {
        foreach ($time->team->puzzlers ?? [] as $puzzler) {
            if ($puzzler->anonymousSlot !== null) {
                return $puzzler->anonymousSlot;
            }
        }

        return null;
    }
}
