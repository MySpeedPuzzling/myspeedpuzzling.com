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
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantRules;
use SpeedPuzzling\Web\Value\RoundCategory;
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
        // Meaningful for team rounds only - a pair always has 2, a solo round none (expectedTeamSize()): whatever was
        // sent for another category is ignored
        $teamSize = $message->category === RoundCategory::Team ? $message->teamSize : null;

        // Checked before anything is created - the entity's constructor checks the range too
        RoundPuzzleReveal::assertValidDelay($message->revealDelayMinutes);
        if (ParticipantRules::isValidTeamSize($teamSize) === false) {
            throw new \InvalidArgumentException('A team size is 2 to 20 people.');
        }

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
            teamSize: $teamSize,
        );

        $this->competitionRoundRepository->save($round);
    }
}
