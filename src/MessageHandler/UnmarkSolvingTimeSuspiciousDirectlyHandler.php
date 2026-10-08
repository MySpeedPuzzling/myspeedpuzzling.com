<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SolvingTimeNotSuspicious;
use SpeedPuzzling\Web\Message\UnmarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeTrust;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Looks fine" on a time without the queue (docs/features/suspicious-time-review.md, "Internal API"): what the queue's
 * TrustSolvingTime does (SuspiciousTimeTrust) - a flagged time is unmarked and the player's open replies answered, a
 * pending case trusted. A flag set by SQL that the scan has not given a case yet gets one first (origin manual, as the
 * scan's reconciliation would), so the unmark is logged like any other.
 */
#[AsMessageHandler]
readonly final class UnmarkSolvingTimeSuspiciousDirectlyHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private SuspiciousTimeCaseRepository $suspiciousTimeCaseRepository,
        private PlayerRepository $playerRepository,
        private SuspiciousTimeTrust $suspiciousTimeTrust,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws SolvingTimeNotSuspicious
     */
    public function __invoke(UnmarkSolvingTimeSuspiciousDirectly $message): void
    {
        $time = $this->puzzleSolvingTimeRepository->get($message->timeId);
        $moderator = $this->playerRepository->get($message->decidedById);

        // Under the case's row lock, like every decision: the scan or an edit holding it has written by then
        $case = $this->suspiciousTimeCaseRepository->lockByTimes([$time->id->toString()])[$time->id->toString()] ?? null;

        if ($case === null && $time->suspicious) {
            $case = SuspiciousTimeCase::flaggedOutsideTheApp(Uuid::uuid7(), $time, SuspicionFingerprint::ofTime($time), $this->clock->now());
            $this->suspiciousTimeCaseRepository->save($case);
        }

        if ($case === null || ($time->suspicious === false && $case->isPending() === false && $case->isMarked() === false)) {
            throw new SolvingTimeNotSuspicious();
        }

        $this->suspiciousTimeTrust->trust($case, $moderator, $message->note);
    }
}
