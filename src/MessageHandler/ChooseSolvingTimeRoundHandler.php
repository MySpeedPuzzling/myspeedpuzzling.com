<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\RoundIsNoCandidateOfSolvingTime;
use SpeedPuzzling\Web\Message\ChooseSolvingTimeRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The player's pick of the round for their time (shared-puzzles-and-divisions.md §2.4 row 16): checked by the one rule
 * (SolvingTimeRoundResolver - a named round wins only while it is a candidate) and stored as chosen, so neither an edit
 * nor the reconciler moves it while the round still holds the puzzle (A4).
 */
#[AsMessageHandler]
readonly final class ChooseSolvingTimeRoundHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private SolvingTimeRoundResolver $roundResolver,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws CompetitionRoundNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws RoundIsNoCandidateOfSolvingTime
     */
    public function __invoke(ChooseSolvingTimeRound $message): void
    {
        $time = $this->puzzleSolvingTimeRepository->get($message->timeId);
        $player = $this->playerRepository->get($message->playerId);

        // The tracker or a registered member of the pair/team (A9) - never an imported event result
        if ($time->canBeModifiedBy($player) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($time->keepsItsRound()) {
            throw new RoundIsNoCandidateOfSolvingTime();
        }

        $resolution = $this->roundResolver->resolve($time, $round);

        if ($resolution->chosen === false || $resolution->round?->id->equals($round->id) !== true) {
            throw new RoundIsNoCandidateOfSolvingTime();
        }

        $time->chooseCompetitionRound($round);
    }
}
