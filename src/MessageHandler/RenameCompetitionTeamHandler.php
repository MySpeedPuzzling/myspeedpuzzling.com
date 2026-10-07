<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionTeamNameTooLong;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNotFound;
use SpeedPuzzling\Web\Message\RenameCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Two teams of one round may share a name - different groups really do (the page points it out).
 */
#[AsMessageHandler]
readonly final class RenameCompetitionTeamHandler
{
    public function __construct(
        private CompetitionTeamRepository $competitionTeamRepository,
    ) {
    }

    /**
     * @throws CompetitionTeamNotFound
     * @throws CompetitionTeamNameTooLong
     */
    public function __invoke(RenameCompetitionTeam $message): void
    {
        $team = $this->competitionTeamRepository->get($message->teamId);

        if ($team->round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionTeamNotFound();
        }

        $team->rename($message->name);
    }
}
