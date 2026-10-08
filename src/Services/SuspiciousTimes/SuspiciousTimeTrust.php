<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;

/**
 * "Looks fine" (docs/features/suspicious-time-review.md, "Moderator queue") - the queue's TrustSolvingTime and the
 * internal API's unmark-suspicious: a pending case is trusted, a marked one unmarked - the flag is cleared
 * (PuzzleSolvingTime::clearSuspicion() records the event the statistics follow) and every open reply of this mark
 * ("The time is correct", an edit) gets the answer "Your time counts again" - an earlier "Stays marked" is replaced
 * by it and told again. The entry is never raised again; an edit that changes its fingerprint lapses the trust.
 *
 * The caller holds the case's row lock and checked the case is the one the decision is about.
 */
readonly final class SuspiciousTimeTrust
{
    public function __construct(
        private SuspiciousTimeNoticeRepository $suspiciousTimeNoticeRepository,
        private SuspiciousTimeDecisionRecorder $suspiciousTimeDecisionRecorder,
        private ClockInterface $clock,
    ) {
    }

    public function trust(SuspiciousTimeCase $case, Player $decidedBy, null|string $note): void
    {
        $time = $case->time;
        $now = $this->clock->now();
        $wasMarked = $case->isMarked();

        if ($wasMarked) {
            foreach ($this->suspiciousTimeNoticeRepository->findOfCase($case) as $notice) {
                if ($notice->isAbout($case) && ($notice->awaitsAnswer() || $notice->answer === SuspiciousTimeReplyAnswer::Kept)) {
                    $notice->answer(SuspiciousTimeReplyAnswer::Trusted, $note, $now);
                }
            }
        }

        $time->clearSuspicion();
        $case->trust($decidedBy->id, SuspicionFingerprint::ofTime($time), $now);

        $this->suspiciousTimeDecisionRecorder->recordAboutTime(
            $wasMarked ? SuspiciousTimeDecisionKind::Unmarked : SuspiciousTimeDecisionKind::Trusted,
            $time,
            $case,
            $decidedBy,
            note: $note,
        );
    }
}
