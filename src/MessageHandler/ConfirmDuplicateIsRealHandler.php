<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Message\ConfirmDuplicateIsReal;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Both are real" - closes the case for this person only; others in a pair/team decide for themselves.
 */
#[AsMessageHandler]
readonly final class ConfirmDuplicateIsRealHandler
{
    public function __construct(
        private ResultDuplicateCaseRepository $caseRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DuplicateCaseNotFound
     * @throws DuplicateCaseChanged
     */
    public function __invoke(ConfirmDuplicateIsReal $message): void
    {
        $case = $this->caseRepository->get($message->caseId);

        if ($case->player->id->toString() !== strtolower($message->playerId)) {
            throw new DuplicateCaseNotFound();
        }

        if ($case->isOpen() === false) {
            throw new DuplicateCaseChanged();
        }

        $case->confirmBothReal($this->clock->now(), $message->via);
    }
}
