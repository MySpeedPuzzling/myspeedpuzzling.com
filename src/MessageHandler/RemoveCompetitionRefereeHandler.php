<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemoveCompetitionReferee;
use SpeedPuzzling\Web\Repository\CompetitionRefereeRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveCompetitionRefereeHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private CompetitionRefereeRepository $refereeRepository,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function __invoke(RemoveCompetitionReferee $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        $referee = $this->refereeRepository->findOne($competition, $player);

        if ($referee !== null) {
            $this->refereeRepository->delete($referee);
        }
    }
}
