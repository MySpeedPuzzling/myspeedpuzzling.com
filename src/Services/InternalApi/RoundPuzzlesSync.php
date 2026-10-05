<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminPuzzles;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Results\AdminRoundPuzzle;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Makes a round's puzzles exactly the given list through the messages the round management uses - a puzzle no longer
 * listed is removed (RemovePuzzleFromCompetitionRound), a new one attached (AddPuzzleToCompetitionRound), the others
 * stay as they are (their "hide until the round starts" setting too).
 *
 * Everything is checked before anything changes: unknown puzzles (404) and the round invariant - a puzzle is in only
 * one round per category of a competition (409, docs/features/competitions-management/round-results.md). Each message
 * is its own transaction and reconciles the round's results after it (CompetitionRoundsChanged).
 */
readonly final class RoundPuzzlesSync
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private GetAdminCompetitions $getAdminCompetitions,
        private GetAdminPuzzles $getAdminPuzzles,
        private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    /**
     * Every puzzle exists (404 naming the unknown ones) and none of `$puzzleIdsToAttach` is in another round of the
     * category in the competition (409) - checked before anything changes, also before a new round is created.
     *
     * @param list<string> $puzzleIds
     * @param list<string> $puzzleIdsToAttach
     *
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public function assertCanAttach(
        string $competitionId,
        RoundCategory $category,
        null|string $roundId,
        array $puzzleIds,
        array $puzzleIdsToAttach,
    ): void {
        $unknownPuzzleIds = array_values(array_diff($puzzleIds, array_keys($this->getAdminPuzzles->byIds($puzzleIds))));

        if ($unknownPuzzleIds !== []) {
            throw new NotFoundHttpException(sprintf('Unknown puzzle ids: %s.', implode(', ', $unknownPuzzleIds)));
        }

        $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
            competitionId: $competitionId,
            puzzleIds: $puzzleIdsToAttach,
            category: $category,
            exceptRoundId: $roundId,
        );

        if ($conflictingRound !== null) {
            throw new ConflictHttpException(sprintf(
                'A puzzle can be in only one %s round of a competition - one of them is already in round "%s". Nothing was changed.',
                $category->value,
                $conflictingRound,
            ));
        }
    }

    /**
     * @param list<string> $puzzleIds distinct, lower case
     *
     * @throws CompetitionRoundNotFound
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public function sync(string $roundId, array $puzzleIds): void
    {
        $round = $this->getAdminCompetitions->round($roundId);

        $currentPuzzleIds = array_map(static fn (AdminRoundPuzzle $roundPuzzle): string => $roundPuzzle->puzzle->puzzleId, $round->puzzles);
        $puzzleIdsToAdd = array_values(array_diff($puzzleIds, $currentPuzzleIds));

        $this->assertCanAttach($round->competitionId, RoundCategory::from($round->category), $round->roundId, $puzzleIds, $puzzleIdsToAdd);

        foreach ($round->puzzles as $roundPuzzle) {
            if (in_array($roundPuzzle->puzzle->puzzleId, $puzzleIds, true) === false) {
                $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($roundPuzzle->roundPuzzleId));
            }
        }

        foreach ($puzzleIdsToAdd as $puzzleId) {
            try {
                $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
                    roundPuzzleId: Uuid::uuid7(),
                    roundId: $round->roundId,
                    // Only read when the message creates a new puzzle - an existing one is attached here
                    userId: '',
                    brand: '',
                    puzzle: $puzzleId,
                    piecesCount: null,
                    puzzlePhoto: null,
                    eans: EanList::fromStored(null),
                    brandCodes: BrandCodeList::fromStored(null),
                    hideUntilRoundStarts: false,
                ));
            } catch (HandlerFailedException $exception) {
                // Checked above - only a concurrent change gets here
                if ($exception->getPrevious() instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                    throw new ConflictHttpException($exception->getPrevious()->getMessage(), $exception);
                }

                throw $exception;
            }
        }
    }
}
