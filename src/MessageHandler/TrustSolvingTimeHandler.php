<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseNotFound;
use SpeedPuzzling\Web\Message\TrustSolvingTime;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Looks fine" (docs/features/suspicious-time-review.md, "Moderator queue"): a pending case is trusted, a marked one
 * unmarked - the flag is cleared (PuzzleSolvingTime::clearSuspicion() records the event the statistics follow) and
 * every open reply of this mark ("The time is correct", an edit) gets the answer "Your time counts again" - an earlier
 * "Stays marked" is replaced by it and told again. This entry is never raised again; an edit that changes its
 * fingerprint lapses the trust.
 */
#[AsMessageHandler]
readonly final class TrustSolvingTimeHandler
{
    public function __construct(
        private SuspiciousTimeCaseRepository $suspiciousTimeCaseRepository,
        private SuspiciousTimeNoticeRepository $suspiciousTimeNoticeRepository,
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
    public function __invoke(TrustSolvingTime $message): void
    {
        // Read under the case's row lock: the scan or an edit holding it has written by then
        $case = $this->suspiciousTimeCaseRepository->getForUpdate($message->caseId);
        $moderator = $this->playerRepository->get($message->decidedById);
        $time = $case->time;
        $fingerprint = SuspicionFingerprint::ofTime($time);
        $now = $this->clock->now();

        // Another moderator or the scan decided meanwhile, or the player edited the time since the page was shown
        if (
            $case->status !== $message->seenStatus
            || in_array($case->status, [SuspiciousTimeCaseStatus::Pending, SuspiciousTimeCaseStatus::Marked], true) === false
            || $fingerprint !== $message->seenFingerprint
        ) {
            throw new SuspiciousTimeCaseChanged();
        }

        // A pending case's reasons are about the entry the scan raised - an edited time waits for the next scan
        if ($case->isPending() && $case->fingerprint !== $fingerprint) {
            throw new SuspiciousTimeCaseChanged();
        }

        $wasMarked = $case->isMarked();

        if ($wasMarked) {
            foreach ($this->suspiciousTimeNoticeRepository->findOfCase($case) as $notice) {
                if ($notice->isAbout($case) && ($notice->awaitsAnswer() || $notice->answer === SuspiciousTimeReplyAnswer::Kept)) {
                    $notice->answer(SuspiciousTimeReplyAnswer::Trusted, $message->note, $now);
                }
            }
        }

        $time->clearSuspicion();
        $case->trust($moderator->id, $fingerprint, $now);

        $this->suspiciousTimeDecisionRecorder->recordAboutTime(
            $wasMarked ? SuspiciousTimeDecisionKind::Unmarked : SuspiciousTimeDecisionKind::Trusted,
            $time,
            $case,
            $moderator,
            note: $message->note,
        );
    }
}
