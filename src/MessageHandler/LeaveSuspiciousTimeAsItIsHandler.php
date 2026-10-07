<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeNoticeNotFound;
use SpeedPuzzling\Web\Message\LeaveSuspiciousTimeAsItIs;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewReactions;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Leave it as it is" (docs/features/suspicious-time-review.md, "Where they see it"): the result stays marked and
 * nobody asks this person about it again. A reply "The time is correct" already sent stays as it was.
 */
#[AsMessageHandler]
readonly final class LeaveSuspiciousTimeAsItIsHandler
{
    public function __construct(
        private SuspiciousTimeNoticeRepository $noticeRepository,
        private ResultReviewReactions $resultReviewReactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SuspiciousTimeNoticeNotFound
     */
    public function __invoke(LeaveSuspiciousTimeAsItIs $message): void
    {
        $notice = $this->noticeRepository->getCurrentOf($message->caseId, $message->playerId);

        $notice->respond(SuspiciousTimeResponse::LeftAsIs, null, $this->clock->now());
        $this->resultReviewReactions->recordFor($message->playerId);
    }
}
