<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeNoticeNotFound;
use SpeedPuzzling\Web\Message\ReplySuspiciousTimeIsCorrect;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewReactions;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "The time is correct" (docs/features/suspicious-time-review.md, "Where they see it"): the reply goes to the
 * moderators' "Player replied" tab, once per mark - only a person told about the mark may send it. The time stays
 * marked until a moderator answers.
 */
#[AsMessageHandler]
readonly final class ReplySuspiciousTimeIsCorrectHandler
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
    public function __invoke(ReplySuspiciousTimeIsCorrect $message): bool
    {
        $notice = $this->noticeRepository->getCurrentOf($message->caseId, $message->playerId);

        if ($notice->response === SuspiciousTimeResponse::SaysCorrect) {
            return false;
        }

        $text = $message->text !== null ? mb_substr(trim($message->text), 0, ReplySuspiciousTimeIsCorrect::MAX_TEXT_LENGTH) : null;

        $notice->respond(SuspiciousTimeResponse::SaysCorrect, $text, $this->clock->now());
        $this->resultReviewReactions->recordFor($message->playerId);

        return true;
    }
}
