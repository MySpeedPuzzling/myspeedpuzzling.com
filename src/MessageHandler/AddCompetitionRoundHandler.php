<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Services\RoundResults\CompetitionRoundSlugGenerator;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

#[AsMessageHandler]
readonly final class AddCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private GetCompetitionRounds $getCompetitionRounds,
        private CompetitionRoundSlugGenerator $slugGenerator,
    ) {
    }

    public function __invoke(AddCompetitionRound $message): void
    {
        // Checked before anything is created - the entity's constructor checks the range too
        RoundPuzzleReveal::assertValidDelay($message->revealDelayMinutes);

        $competition = $this->competitionRepository->get($message->competitionId);

        $round = new CompetitionRound(
            id: $message->roundId,
            competition: $competition,
            name: $message->name,
            minutesLimit: $message->minutesLimit,
            startsAt: $message->startsAt,
            badgeBackgroundColor: $message->badgeBackgroundColor,
            badgeTextColor: $message->badgeTextColor,
            category: $message->category,
            slug: $this->slugGenerator->generate(
                $message->name,
                $this->getCompetitionRounds->slugsOfCompetition($message->competitionId),
            ),
            resultsLink: $message->resultsLink,
            timezone: $message->timezone,
            revealDelayMinutes: $message->revealDelayMinutes,
        );

        $this->competitionRoundRepository->save($round);
    }
}
