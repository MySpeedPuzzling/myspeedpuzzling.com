<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Message\UnpublishCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnpublishCompetitionStandingHandler
{
    public function __construct(
        private CompetitionStandingRepository $standingRepository,
    ) {
    }

    /**
     * @throws CompetitionStandingNotFound also for a standing of another event
     */
    public function __invoke(UnpublishCompetitionStanding $message): void
    {
        $standing = $this->standingRepository->get($message->standingId);

        if ($standing->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionStandingNotFound();
        }

        $standing->unpublish();
    }
}
