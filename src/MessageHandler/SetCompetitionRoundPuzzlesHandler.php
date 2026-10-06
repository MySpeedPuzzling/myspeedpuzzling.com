<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SetCompetitionRoundPuzzlesHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private PuzzleRepository $puzzleRepository,
        private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     * @throws PuzzleNotFound
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     */
    public function __invoke(SetCompetitionRoundPuzzles $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $currentPuzzleIds = [];

        foreach ($round->roundPuzzles as $roundPuzzle) {
            $currentPuzzleIds[] = $roundPuzzle->puzzle->id->toString();
        }

        $puzzlesToAttach = [];

        foreach (array_diff($message->puzzleIds, $currentPuzzleIds) as $puzzleId) {
            $puzzlesToAttach[] = $this->puzzleRepository->get($puzzleId);
        }

        // Like AddPuzzleToCompetitionRoundHandler, but for the whole list and before anything changes
        $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
            competitionId: $round->competition->id->toString(),
            puzzleIds: array_map(static fn (Puzzle $puzzle): string => $puzzle->id->toString(), $puzzlesToAttach),
            category: $round->category,
            exceptRoundId: $round->id->toString(),
        );

        if ($conflictingRound !== null) {
            throw new PuzzleAlreadyInCompetitionRoundCategory($conflictingRound);
        }

        foreach ($round->roundPuzzles as $roundPuzzle) {
            if (in_array($roundPuzzle->puzzle->id->toString(), $message->puzzleIds, true) === false) {
                $roundPuzzle->recordRemoval();
                $this->competitionRoundPuzzleRepository->delete($roundPuzzle);
            }
        }

        foreach ($puzzlesToAttach as $puzzle) {
            $this->competitionRoundPuzzleRepository->save(new CompetitionRoundPuzzle(
                id: Uuid::uuid7(),
                round: $round,
                puzzle: $puzzle,
            ));
        }
    }
}
