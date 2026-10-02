<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecordResultReviewVisit;
use SpeedPuzzling\Web\Repository\ResultReviewContactRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The review page was opened from a "Your results" e-mail (`?from=rc-<contactId>`) - the first visit counts, and
 * only the player's own e-mail: a forwarded link or somebody else's id records nothing.
 */
#[AsMessageHandler]
readonly final class RecordResultReviewVisitHandler
{
    public function __construct(
        private ResultReviewContactRepository $contactRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordResultReviewVisit $message): void
    {
        $contact = $this->contactRepository->find($message->contactId);

        if ($contact === null || $contact->player->id->toString() !== strtolower($message->playerId)) {
            return;
        }

        $contact->pageVisited($this->clock->now());
    }
}
