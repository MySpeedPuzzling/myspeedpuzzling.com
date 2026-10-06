<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SetCompetitionRoundPuzzlesHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private PuzzleRepository $puzzleRepository,
        private GetCompetitionRounds $getCompetitionRounds,
        private SecretPuzzleHides $secretPuzzleHides,
        private SecretRevealPreview $secretRevealPreview,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     * @throws PuzzleNotFound
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     * @throws PuzzleHiddenByHand
     * @throws PuzzleIsStillSecret
     * @throws SecretPuzzlesWouldBeRevealed
     */
    public function __invoke(SetCompetitionRoundPuzzles $message): void
    {
        // Locks the round (its list of puzzles changes), then its secret puzzles - waits for every other change of
        // them, then reads fresh (SecretPuzzleHides)
        $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);

        $round = $this->competitionRoundRepository->get($message->roundId);
        $now = $this->clock->now();

        $currentPuzzleIds = [];

        foreach ($round->roundPuzzles as $roundPuzzle) {
            $currentPuzzleIds[] = $roundPuzzle->puzzle->id->toString();
        }

        $puzzlesToAttach = [];

        foreach (array_diff($message->puzzleIds, $currentPuzzleIds) as $puzzleId) {
            $puzzle = $this->puzzleRepository->get($puzzleId);

            // A new row is attached unhidden - a hidden puzzle would show on this event page. A placeholder hidden by
            // hand is no round's at all; a competition's secret puzzle is attached on the organiser's page, where its
            // reveal is chosen.
            if ($puzzle->isImageHiddenAt($now)) {
                if ($this->isPuzzleKeptSecret->byId($puzzle->id->toString()) === false) {
                    throw new PuzzleHiddenByHand();
                }

                throw new PuzzleIsStillSecret(
                    $puzzle->id->toString(),
                    sprintf('Puzzle %s is secret - it would be shown on this event page. Attach it on the round\'s page, where its reveal is chosen. Nothing was changed.', $puzzle->id->toString()),
                );
            }

            $puzzlesToAttach[] = $puzzle;
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

        $rowsToRemove = array_values(array_filter(
            $round->roundPuzzles->toArray(),
            static fn (CompetitionRoundPuzzle $roundPuzzle): bool => in_array($roundPuzzle->puzzle->id->toString(), $message->puzzleIds, true) === false,
        ));

        if ($message->refuseToReveal) {
            $revealed = $this->secretRevealPreview->byRemoving($rowsToRemove);

            if ($revealed !== []) {
                throw new SecretPuzzlesWouldBeRevealed($revealed);
            }
        }

        foreach ($rowsToRemove as $roundPuzzle) {
            $roundPuzzle->recordRemoval();
            $this->competitionRoundPuzzleRepository->delete($roundPuzzle);
        }

        foreach ($puzzlesToAttach as $puzzle) {
            $this->competitionRoundPuzzleRepository->save(new CompetitionRoundPuzzle(
                id: Uuid::uuid7(),
                round: $round,
                puzzle: $puzzle,
            ));
        }

        // The other rounds that keep a removed puzzle secret decide now; with none left its dates stay
        foreach ($rowsToRemove as $roundPuzzle) {
            if ($roundPuzzle->hideUntilRoundStarts) {
                $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
            }
        }
    }
}
