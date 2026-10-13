<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\GenerateOnlineTokens;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePersonalLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A personal link for every participant of the event who is going and has none yet - the people added after online
 * timing was turned on (docs/features/online-competitions/README.md §5.4). Links already given stay as they are. An
 * event without a round using online timing gets no links: they would open nothing.
 */
#[AsMessageHandler]
readonly final class GenerateOnlineTokensHandler
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private OnlinePersonalLinks $onlinePersonalLinks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many participants got a personal link
     */
    public function __invoke(GenerateOnlineTokens $message): int
    {
        if ($this->onlineRoundRepository->ofCompetition($message->competitionId) === []) {
            return 0;
        }

        return $this->onlinePersonalLinks->giveMissing(strtolower($message->competitionId), $this->clock->now());
    }
}
