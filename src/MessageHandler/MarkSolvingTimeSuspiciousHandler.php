<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseNotFound;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspicious;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A moderator marked a pending case "Needs verification" (docs/features/suspicious-time-review.md, "Moderator
 * queue"): the time is flagged - PuzzleSolvingTime::markSuspicious() records the event the statistics, insights and
 * round results follow - and the case remembers which reasons the player reads. The player is told by the next
 * notice run, so a slip can still be undone before anybody hears of it.
 */
#[AsMessageHandler]
readonly final class MarkSolvingTimeSuspiciousHandler
{
    public function __construct(
        private SuspiciousTimeCaseRepository $suspiciousTimeCaseRepository,
        private PlayerRepository $playerRepository,
        private SuspiciousTimeDecisionRecorder $suspiciousTimeDecisionRecorder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SuspiciousTimeCaseNotFound
     * @throws PlayerNotFound
     * @throws SuspiciousTimeCaseChanged
     */
    public function __invoke(MarkSolvingTimeSuspicious $message): void
    {
        // Read under the case's row lock: the scan or an edit holding it has written by then
        $case = $this->suspiciousTimeCaseRepository->getForUpdate($message->caseId);
        $moderator = $this->playerRepository->get($message->decidedById);
        $time = $case->time;
        $fingerprint = SuspicionFingerprint::ofTime($time);

        // Decided meanwhile, or the time is another entry than the one the reasons (and the moderator) were about -
        // the next scan judges the new one
        if ($case->isPending() === false || $fingerprint !== $case->fingerprint || $fingerprint !== $message->seenFingerprint) {
            throw new SuspiciousTimeCaseChanged();
        }

        // Only the case's own reasons a player may read - a hand-made request cannot add any
        $reasonsShown = array_values(array_filter(
            $case->reasons(),
            static fn (SuspiciousTimeReason $reason): bool => $reason->code->isShownToPlayer()
                && in_array($reason->code->value, $message->reasonCodes, true),
        ));

        $time->markSuspicious();
        $case->mark($reasonsShown, $message->note, $moderator->id, $fingerprint, $this->clock->now());

        $this->suspiciousTimeDecisionRecorder->recordAboutTime(
            SuspiciousTimeDecisionKind::Marked,
            $time,
            $case,
            $moderator,
            $reasonsShown,
            $message->note,
        );
    }
}
