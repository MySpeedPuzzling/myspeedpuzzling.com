<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseNotFound;
use SpeedPuzzling\Web\Message\KeepSolvingTimeSuspicious;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Keep it marked" on the "Player replied" tab (docs/features/suspicious-time-review.md, "Moderator queue"): the
 * time stays marked, every open reply of this mark - "The time is correct", or an edit that still looked off - is
 * answered "Stays marked" with the moderator's note (the review page and the next "Your results" e-mail show it), and
 * an edit is taken as reviewed.
 */
#[AsMessageHandler]
readonly final class KeepSolvingTimeSuspiciousHandler
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
    public function __invoke(KeepSolvingTimeSuspicious $message): void
    {
        // Read under the case's row lock: the scan or an edit holding it has written by then
        $case = $this->suspiciousTimeCaseRepository->getForUpdate($message->caseId);
        $moderator = $this->playerRepository->get($message->decidedById);
        $time = $case->time;
        $fingerprint = SuspicionFingerprint::ofTime($time);
        $now = $this->clock->now();

        if ($case->isMarked() === false || $fingerprint !== $message->seenFingerprint) {
            throw new SuspiciousTimeCaseChanged();
        }

        $openReplies = [];

        foreach ($this->suspiciousTimeNoticeRepository->findOfCase($case) as $notice) {
            if ($notice->isAbout($case) && $notice->awaitsAnswer()) {
                $openReplies[] = $notice;
            }
        }

        // Nothing waits for an answer any more - another moderator answered meanwhile
        if ($openReplies === [] && $case->playerEditedAt === null) {
            throw new SuspiciousTimeCaseChanged();
        }

        foreach ($openReplies as $notice) {
            $notice->answer(SuspiciousTimeReplyAnswer::Kept, $message->note, $now);
        }

        $case->keptAfterReply($fingerprint);

        $this->suspiciousTimeDecisionRecorder->recordAboutTime(
            SuspiciousTimeDecisionKind::KeptAfterReply,
            $time,
            $case,
            $moderator,
            $case->reasonsShown(),
            $message->note,
        );
    }
}
