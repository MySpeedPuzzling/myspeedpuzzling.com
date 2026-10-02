<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Message\ConfirmDuplicateIsReal;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateCaseSetResolver;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewReactions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Both/All are real" on a set of copies - closes every open case of the set for this person only; others in a
 * pair/team decide for themselves.
 */
#[AsMessageHandler]
readonly final class ConfirmDuplicateIsRealHandler
{
    public function __construct(
        private DuplicateCaseSetResolver $setResolver,
        private ResultReviewReactions $resultReviewReactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DuplicateCaseNotFound
     * @throws DuplicateCaseChanged
     */
    public function __invoke(ConfirmDuplicateIsReal $message): void
    {
        ['cases' => $cases] = $this->setResolver->resolve($message->caseId, $message->playerId, $message->copyTimeIds);
        $now = $this->clock->now();

        foreach ($cases as $case) {
            $case->confirmBothReal($now, $message->via);
        }

        $this->resultReviewReactions->recordFor($cases[0]->player->id->toString());
    }
}
