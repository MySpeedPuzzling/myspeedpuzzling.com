<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNameTooLong;
use SpeedPuzzling\Web\Message\CreateCompetitionTeams;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateCompetitionTeamsHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionTeamRepository $competitionTeamRepository,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound a round of another event
     * @throws CompetitionTeamNameTooLong
     */
    public function __invoke(CreateCompetitionTeams $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        // Every team is built before any is persisted - one name too long adds none of them
        $teams = array_map(
            static fn (null|string $name): CompetitionTeam => new CompetitionTeam(
                id: Uuid::uuid7(),
                round: $round,
                name: $name,
            ),
            $message->names !== [] ? $message->names : [null],
        );

        foreach ($teams as $team) {
            $this->competitionTeamRepository->save($team);
        }
    }
}
