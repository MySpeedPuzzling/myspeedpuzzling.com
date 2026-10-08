<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadyMarkedSuspicious;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeNotices;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks a time "needs verification" without the queue (docs/features/suspicious-time-review.md, "Internal API"):
 * the same mark the queue's MarkSolvingTimeSuspicious makes - PuzzleSolvingTime::markSuspicious() records the event
 * the statistics, insights and round results follow, the decision log gets a `marked` row - on whatever case the time
 * has. A time the scan never raised gets a case of its own (origin moderator, no reasons). The scan's reasons are
 * shown only while they are about this very entry.
 *
 * The player is told by the next notice run - unless toldByHand: then the notices are recorded as sent right away.
 */
#[AsMessageHandler]
readonly final class MarkSolvingTimeSuspiciousDirectlyHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private SuspiciousTimeCaseRepository $suspiciousTimeCaseRepository,
        private PlayerRepository $playerRepository,
        private SuspiciousTimeDecisionRecorder $suspiciousTimeDecisionRecorder,
        private SuspiciousTimeNotices $suspiciousTimeNotices,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws SolvingTimeAlreadyMarkedSuspicious
     */
    public function __invoke(MarkSolvingTimeSuspiciousDirectly $message): void
    {
        $time = $this->puzzleSolvingTimeRepository->get($message->timeId);
        $moderator = $this->playerRepository->get($message->decidedById);
        $now = $this->clock->now();
        $fingerprint = SuspicionFingerprint::ofTime($time);

        // Under the case's row lock, like every decision: the scan or an edit holding it has written by then
        $case = $this->suspiciousTimeCaseRepository->lockByTimes([$time->id->toString()])[$time->id->toString()] ?? null;

        if ($case !== null && $case->isMarked() && $time->suspicious) {
            throw new SolvingTimeAlreadyMarkedSuspicious();
        }

        if ($case === null) {
            $case = SuspiciousTimeCase::reportedByHand(Uuid::uuid7(), $time, $fingerprint, $now);
            $this->suspiciousTimeCaseRepository->save($case);
        }

        // The scan's reasons describe the entry it checked - another entry (an edit since) shows none
        $reasonsShown = [];

        if ($case->fingerprint === $fingerprint) {
            $reasonsShown = array_values(array_filter(
                $case->reasons(),
                static fn (SuspiciousTimeReason $reason): bool => $reason->code->isShownToPlayer()
                    && ($message->reasonCodes === null || in_array($reason->code->value, $message->reasonCodes, true)),
            ));
        }

        $time->markSuspicious();
        $case->mark($reasonsShown, $message->note, $moderator->id, $fingerprint, $now);

        $this->suspiciousTimeDecisionRecorder->recordAboutTime(
            SuspiciousTimeDecisionKind::Marked,
            $time,
            $case,
            $moderator,
            $reasonsShown,
            $message->note,
        );

        if ($message->toldByHand) {
            $this->suspiciousTimeNotices->tellMembers($case, SuspiciousTimeNoticeVia::ManualEmail, $now);
        }
    }
}
