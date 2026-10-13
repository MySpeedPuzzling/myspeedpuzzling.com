<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotUsed;
use SpeedPuzzling\Web\Message\GenerateOnlineTokens;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePersonalLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A personal link for every participant of the event who is going and has none yet - the people the organiser added
 * after online timing was turned on, given when the links page, its CSV or QR sheet opens
 * (docs/features/online-competitions/README.md §5.4). Links already given stay as they are. Refused for an event no
 * round of which uses online timing (OnlineTimingNotUsed): its links would open nothing.
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
     *
     * @throws OnlineTimingNotUsed
     */
    public function __invoke(GenerateOnlineTokens $message): int
    {
        if ($this->onlineRoundRepository->ofCompetition($message->competitionId) === []) {
            throw new OnlineTimingNotUsed();
        }

        return $this->onlinePersonalLinks->giveMissing(strtolower($message->competitionId), $this->clock->now());
    }
}
