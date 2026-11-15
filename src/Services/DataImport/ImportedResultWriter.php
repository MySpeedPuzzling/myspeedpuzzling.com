<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultKnownBy;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
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
        private SecretPuzzleAccess $secretPuzzleAccess,
        private FirstTryAssessor $firstTryAssessor,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private WishListItemRepository $wishListItemRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function write(ImportedResult $result): ImportedResultOutcome
    {
        // The lock below lives as long as the transaction around it - without one it would be given back at once
        // and protect nothing
        if ($this->entityManager->getConnection()->isTransactionActive() === false) {
            throw new LogicException('ImportedResultWriter writes inside a transaction - call it from a message handler.');
        }

        // First: another delivery of the row, or the same data in another import, waits here until this one commits
        $this->lock->lockUntilCommit($result->playerId);

        $player = $this->playerRepository->get($result->playerId);
        $row = $this->rowRepository->find($result->importRowId)
            ?? throw new LogicException(sprintf('Import row %s does not exist.', $result->importRowId));

        if ($row->import->isOwnedBy($player->id->toString()) === false) {
            throw new LogicException(sprintf('Import row %s is not of player %s.', $result->importRowId, $result->playerId));
        }

        // Never twice, the writer's part: a result this row made, then a result a row with the same key made. The
        // link is read from the database now, under the lock - the loaded row may be as old as the caller's first
        // look at it. Only a result that still exists counts: one that was deleted or undone since may be added
        // again (README "Never twice")
        $linkedTimeId = $this->rowRepository->linkedSolvingTimeId($result->importRowId);

        if ($linkedTimeId !== null && $this->resultExists($linkedTimeId)) {
            return ImportedResultOutcome::alreadyAdded($result->importRowId, $linkedTimeId, ImportedResultKnownBy::Row);
        }

        foreach ($this->rowRepository->solvingTimeIdsOfSourceKey($player->id->toString(), $result->sourceKey, $result->importRowId) as $knownTimeId) {
            if ($this->resultExists($knownTimeId)) {
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

        $group = $this->assembleGroup($player, $result->groupPlayers);

        if ($group === false) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::EmptyGroup);
        }

        $finishedAt = $result->finishedAt();
        $puzzlersCount = $group !== null ? count($group->puzzlers) : 1;

        // The form's assessor, with no resolution: a first try somebody of the result already holds is not moved by
        // an import - the row is refused and the player chooses (README D15). Assessed as the importing player by
        // id: a worker has nobody signed in, and what the outcome names must be what that player may see
        if ($result->isFirstTry()) {
            $firstTry = $this->firstTryAssessor->assessAsActor(new FirstTryEntry(
                actorPlayerId: $player->id->toString(),
                puzzleId: $result->puzzleId,
                memberPlayerIds: FirstTryEntry::memberIdsOf($player->id->toString(), $group),
                solvedAt: $finishedAt,
            ));

            if ($firstTry->blocks(FirstTryResolution::None)) {
                return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::FirstTryTaken, $firstTry);
            }
        }

        if ($result->seconds !== null && $result->seconds > ImportedResult::MAX_SECONDS) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::TimeTooLong);
        }

        if (new SolvingTime($result->seconds)->calculatePpm($puzzle->piecesCount, $puzzlersCount) >= self::TOO_FAST_PPM) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::TooFast);
        }

        if ($result->comment !== null && mb_strlen($result->comment) > self::COMMENT_MAX_LENGTH) {
            return ImportedResultOutcome::refused($result->importRowId, ImportedResultRefusal::CommentTooLong);
        }

        // Nothing can refuse the result any more. The row takes the new result's id first, in the database and only
        // if it still names what was read above: whoever loses that race wrote nothing yet
        $solvingTimeId = Uuid::uuid7();

        if ($this->rowRepository->linkSolvingTime($result->importRowId, $linkedTimeId, $solvingTimeId->toString()) === false) {
            $winner = $this->rowRepository->linkedSolvingTimeId($result->importRowId)
                ?? throw new LogicException(sprintf('Import row %s lost its link while it was written.', $result->importRowId));

            return ImportedResultOutcome::alreadyAdded($result->importRowId, $winner, ImportedResultKnownBy::Row);
        }

        $solvingTime = new PuzzleSolvingTime(
            id: $solvingTimeId,
            secondsToSolve: $result->seconds,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $this->freeMoment($player, $puzzle, $result->trackedAt($this->clock->now())),
            verified: false,
            team: $group,
            finishedAt: $finishedAt,
            comment: $result->comment,
            finishedPuzzlePhoto: null,
            firstAttempt: $result->isFirstTry(),
            unboxed: false,
            // Resolved last - the team is created outside the unit of work
            puzzlingTeam: $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $player->id->toString()),
            createdVia: SolvingTimeSource::SpreadsheetImport,
        );

        // What the file knows of the day: a month or a year is stored as that, never as a day (README D13)
        $solvingTime->solveDayKnownOnlyTo($result->precision);

        // The constructor records PuzzleSolved. Its five handlers do per result what an import does once per batch,
        // or not at all (writer.md "Reactions") - so the event is taken off here, before any flush can dispatch it.
        // Anything else the entity may record one day stays
        foreach ($solvingTime->popEvents() as $event) {
            if ($event instanceof PuzzleSolved === false) {
                $solvingTime->recordThat($event);
            }
        }

        $this->entityManager->persist($solvingTime);
        // The loaded row in step with what the database already says
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
     * PuzzlersGrouping::assembleGroup() with one difference: **an entry is one person**. The add form's guest box
     * splits what was typed at commas ("Anna, Ben" are two guests); the import has decided who the people of a row
     * are before it gets here, so "Smith, John" stays the one guest the file names. Everything else is the form's:
     * `#code` is a registered player (a code nobody has is a guest of that name), the player themself is left out
     * and stands first.
     *
     * @param list<string> $people
     *
     * @return null|false|PuzzlersGroup null = solo, false = people were named and all of them are the player
     */
    private function assembleGroup(Player $player, array $people): null|false|PuzzlersGroup
    {
        $people = array_values(array_unique(array_filter(array_map(trim(...), $people), static fn (string $person): bool => $person !== '')));

        if ($people === []) {
            return null;
        }

        $puzzlers = [];

        foreach ($people as $person) {
            $puzzler = $this->puzzlerOf($person);

            if ($puzzler->playerId !== $player->id->toString()) {
                $puzzlers[] = $puzzler;
            }
        }

        if ($puzzlers === []) {
            return false;
        }

        array_unshift($puzzlers, new Puzzler(
            playerId: $player->id->toString(),
            playerName: null,
            playerCode: null,
            playerCountry: null,
            isPrivate: $player->isPrivate,
        ));

        return new PuzzlersGroup(null, $puzzlers);
    }

    private function puzzlerOf(string $codeOrName): Puzzler
    {
        $isRegisteredPlayer = str_starts_with($codeOrName, '#');
        $codeOrName = trim($codeOrName, "\# \t\n\r\0");

        if ($isRegisteredPlayer) {
            try {
                $player = $this->playerRepository->getByCode($codeOrName);

                return new Puzzler(
                    playerId: $player->id->toString(),
                    playerName: null,
                    playerCode: $player->code,
                    playerCountry: CountryCode::fromCode($player->country),
                    isPrivate: $player->isPrivate,
                );
            } catch (PlayerNotFound) {
                // A guest of that name, as in the form
            }
        }

        return new Puzzler(
            playerId: null,
            playerName: $codeOrName,
            playerCode: null,
            playerCountry: null,
            isPrivate: false,
        );
    }

    /**
     * Whether the result is there: saved, or about to be by this unit of work (a row written earlier in the same
     * transaction - the database does not have it before the flush). Asked of the database, not of loaded objects:
     * an object can outlive its row.
     */
    private function resultExists(string $solvingTimeId): bool
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PuzzleSolvingTime && $entity->id->toString() === $solvingTimeId) {
                return true;
            }
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(time.id)')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.id = :id')
            ->setParameter('id', $solvingTimeId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The moment the result is "tracked at", moved back in steps until no result the player tracked of the same
     * puzzle stands within 10 seconds of it. Two results of one player and puzzle at (nearly) the same moment are
     * what a form sent twice looks like to the duplicate rules; two rows of a spreadsheet must never look like that
     * - whatever their positions, however the moment was capped at "now". Back, never forward: never into the future.
     */
    private function freeMoment(Player $player, Puzzle $puzzle, DateTimeImmutable $wanted): DateTimeImmutable
    {
        /** @var list<array{trackedAt: DateTimeImmutable}> $saved */
        $saved = $this->entityManager->createQueryBuilder()
            ->select('time.trackedAt')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.player = :player')
            ->andWhere('time.puzzle = :puzzle')
            ->andWhere('time.trackedAt BETWEEN :from AND :to')
            ->setParameter('player', $player)
            ->setParameter('puzzle', $puzzle)
            ->setParameter('from', $wanted->modify('-1 day'))
            ->setParameter('to', $wanted->modify('+1 hour'))
            ->getQuery()
            ->getArrayResult();

        $taken = array_map(static fn (array $time): int => $time['trackedAt']->getTimestamp(), $saved);

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PuzzleSolvingTime && $entity->player === $player && $entity->puzzle === $puzzle) {
                $taken[] = $entity->trackedAt->getTimestamp();
            }
        }

        $moment = $wanted;

        // A day has 7,854 steps - nobody has that many results of one puzzle around one noon
        for ($step = 0; $step < 7_854; $step++) {
            $isFree = true;

            foreach ($taken as $timestamp) {
                if (abs($timestamp - $moment->getTimestamp()) < ImportedResult::STEP_SECONDS) {
                    $isFree = false;

                    break;
                }
            }

            if ($isFree) {
                return $moment;
            }

            $moment = $moment->modify(sprintf('-%d seconds', ImportedResult::STEP_SECONDS));
        }

        return $moment;
    }

    /**
     * What RemoveFromWishListWhenPuzzleSolved does for a typed result - the player's own setting, honoured here
     * because the event never reaches that handler. Told to the caller, whose log lets Undo put the item back.
     *
     * @return null|array{id: string, playerId: string, puzzleId: string, addedAt: DateTimeImmutable}
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
