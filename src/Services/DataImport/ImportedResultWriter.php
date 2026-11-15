<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultKnownBy;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * The import's own writer of results (docs/features/data-import/writer.md) - beside AddPuzzleSolvingTimeHandler and
 * AddPuzzleTrackingHandler, which it never calls and which never call it: the add form is not touched for the
 * import's sake (README D4). It makes the result those handlers would make of the same input, step by step in their
 * order, with the differences the doc lists and nothing else - ImportWritePathParityTest saves the same input both
 * ways and compares. **A rule changed in the add handlers is changed here too**, or that test turns red.
 *
 * Called inside a handler's transaction (stage 6b's ApplyImportRow): it persists and never flushes. Everything that
 * can refuse the row is checked before anything is created, so a refused row leaves nothing in the entity manager.
 *
 * What the add form does per result and an import does once per batch is not done here - the outcome names it and
 * ImportedResultsFollowUp does it.
 */
readonly final class ImportedResultWriter
{
    /** PuzzleAddFormData::$comment's limit */
    public const int COMMENT_MAX_LENGTH = 500;

    /** AddPuzzleSolvingTimeHandler's limit: pieces a minute per person */
    private const int TOO_FAST_PPM = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportWriteLock $lock,
        private DataImportRowRepository $rowRepository,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private PuzzlersGrouping $puzzlersGrouping,
        private FirstTryAssessor $firstTryAssessor,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private WishListItemRepository $wishListItemRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function write(ImportedResult $result): ImportedResultOutcome
    {
        // First: another delivery of the row, or the same data in another import, waits here until this one commits
        $this->lock->lockUntilCommit($result->playerId);

        $player = $this->playerRepository->get($result->playerId);
        $row = $this->rowRepository->find($result->importRowId)
            ?? throw new LogicException(sprintf('Import row %s does not exist.', $result->importRowId));

        if ($row->import->isOwnedBy($player->id->toString()) === false) {
            throw new LogicException(sprintf('Import row %s is not of player %s.', $result->importRowId, $result->playerId));
        }

        // Never twice, the writer's part: a result this row made, then a result a row with the same key made. Only a
        // result that still exists counts - one that was deleted or undone since may be added again (README "Never twice")
        if ($row->solvingTimeId !== null && $this->puzzleSolvingTimeRepository->findById($row->solvingTimeId) !== null) {
            return ImportedResultOutcome::alreadyAdded($result->importRowId, $row->solvingTimeId->toString(), ImportedResultKnownBy::Row);
        }

        foreach ($this->rowRepository->solvingTimeIdsOfSourceKey($player->id->toString(), $result->sourceKey, $result->importRowId) as $knownTimeId) {
            if ($this->puzzleSolvingTimeRepository->findById(Uuid::fromString($knownTimeId)) !== null) {
                return ImportedResultOutcome::alreadyAdded($result->importRowId, $knownTimeId, ImportedResultKnownBy::SourceKey);
            }
        }

        try {
            $puzzle = $this->puzzleRepository->get($result->puzzleId);
        } catch (PuzzleNotFound) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::PuzzleNotFound);
        }

        // A secret competition puzzle takes nothing personal before its reveal - from anybody (SecretPuzzleAccess)
        try {
            $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $player->id->toString());
        } catch (PuzzleNotRevealedYet) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::PuzzleNotRevealed);
        }

        try {
            $group = $this->puzzlersGrouping->assembleGroup($player, $result->groupPlayers);
        } catch (CanNotAssembleEmptyGroup) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::EmptyGroup);
        }

        $finishedAt = $result->finishedAt();
        $puzzlersCount = $group !== null ? count($group->puzzlers) : 1;

        // The same assessor as the form's, with no resolution: a first try somebody of the result already holds is
        // not moved by an import - the row is refused and the player chooses (README D15)
        if ($result->isFirstTry()) {
            $firstTry = $this->firstTryAssessor->assess(new FirstTryEntry(
                actorPlayerId: $player->id->toString(),
                puzzleId: $result->puzzleId,
                memberPlayerIds: FirstTryEntry::memberIdsOf($player->id->toString(), $group),
                solvedAt: $finishedAt,
            ));

            if ($firstTry->blocks(FirstTryResolution::None)) {
                return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::FirstTryTaken, $firstTry);
            }
        }

        if (new SolvingTime($result->seconds)->calculatePpm($puzzle->piecesCount, $puzzlersCount) >= self::TOO_FAST_PPM) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::TooFast);
        }

        if ($result->comment !== null && mb_strlen($result->comment) > self::COMMENT_MAX_LENGTH) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::CommentTooLong);
        }

        $solvingTime = new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $result->seconds,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $result->trackedAt(),
            verified: false,
            team: $group,
            finishedAt: $finishedAt,
            comment: $result->comment,
            finishedPuzzlePhoto: null,
            firstAttempt: $result->isFirstTry(),
            unboxed: false,
            // Resolved last, once nothing above can refuse the result any more - the team is created outside the
            // unit of work
            puzzlingTeam: $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $player->id->toString()),
            createdVia: SolvingTimeSource::SpreadsheetImport,
        );

        // The constructor records PuzzleSolved. Its five handlers do per result what an import does once per batch,
        // or not at all (writer.md "Reactions") - so the event is taken off here, before any flush can dispatch it.
        // Anything else the entity may record one day stays
        foreach ($solvingTime->popEvents() as $event) {
            if ($event instanceof PuzzleSolved === false) {
                $solvingTime->recordThat($event);
            }
        }

        $this->entityManager->persist($solvingTime);
        $row->resultCreated($solvingTime->id);

        return ImportedResultOutcome::added(
            importRowId: $result->importRowId,
            solvingTimeId: $solvingTime->id->toString(),
            puzzleId: $puzzle->id->toString(),
            memberPlayerIds: $solvingTime->memberPlayerIds(),
            predictionPending: $solvingTime->isPredictionPending(),
            removedWishListItem: $this->removeFromWishList($player->id->toString(), $puzzle->id->toString()),
        );
    }

    /**
     * What RemoveFromWishListWhenPuzzleSolved does for a typed result - the player's own setting, honoured here
     * because the event never reaches that handler. Told to the caller, whose log lets Undo put the item back.
     *
     * @return null|array{id: string, playerId: string, puzzleId: string, addedAt: \DateTimeImmutable}
     */
    private function removeFromWishList(string $playerId, string $puzzleId): null|array
    {
        $item = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag($playerId, $puzzleId);

        if ($item === null) {
            return null;
        }

        $this->wishListItemRepository->delete($item);

        return [
            'id' => $item->id->toString(),
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'addedAt' => $item->addedAt,
        ];
    }
}
