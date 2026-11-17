<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RegenerateCompetitionDisplayToken;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RegenerateCompetitionDisplayTokenHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RegenerateCompetitionDisplayToken $message): void
    {
        $this->displayRepository->get($message->displayId)
            ->regenerateToken(OnlineToken::generate(), $this->clock->now());
    }
}
