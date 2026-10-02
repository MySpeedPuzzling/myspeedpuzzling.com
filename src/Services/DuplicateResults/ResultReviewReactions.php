<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\ResultReviewContactRepository;

/**
 * The player resolved something on the review page (a copy kept, "both are real", an Undo, a first-try decision) -
 * credited to their latest "Your results" e-mail, once. A player who reacted may get e-mails about new cases later
 * (docs/features/duplicate-results.md, "Contact rules"); one who did not gets only removal notices.
 *
 * Called from the deciding handlers, inside their transaction.
 */
readonly final class ResultReviewReactions
{
    public function __construct(
        private ResultReviewContactRepository $contactRepository,
        private ClockInterface $clock,
    ) {
    }

    public function recordFor(string $playerId): void
    {
        $this->contactRepository->findLatestSentOf($playerId)?->reacted($this->clock->now());
    }
}
