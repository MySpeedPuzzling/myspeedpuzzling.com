<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PublishCompetitionStandingHandler
{
    public function __construct(
        private CompetitionStandingRepository $standingRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionStandingNotFound also for a standing of another event
     */
    public function __invoke(PublishCompetitionStanding $message): void
    {
        $standing = $this->standingRepository->get($message->standingId);

        if ($standing->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionStandingNotFound();
        }

        $standing->publish($this->clock->now());
    }
}
