<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Entity\SuspiciousTimeConfirmation;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeConfirmationRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultKnownBy;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultStatus;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * One queued row of an "Add" becomes what it says (docs/features/data-import/adding.md "A row, step by step"): a
 * result through ImportedResultWriter - never any other way -, a puzzle in the player's collection, a puzzle on their
 * wish list; or it turns out to be the player's already; or a rule refuses it and it waits again with the reason.
 *
 * Called inside a handler's transaction (ApplyImportRowHandler - one row, one transaction): it persists and never
 * flushes. **Everything that can refuse the row is checked before anything is created**, and a row is all or nothing:
 * a row that is a result and an owned puzzle whose result is refused adds no collection item either.
 *
 * Its first statement is the player's import lock (ImportWriteLock - the writer's own): no other row of the player is
 * written until this one is committed, so what "never twice" reads here is what holds when the row is written. Only a
 * row that is still queued in the batch is touched - a second delivery, a second worker or a second batch finds
 * nothing to do.
 */
readonly final class ImportRowAdder
{
    /** CollectionItem::$comment */
    private const int COLLECTION_COMMENT_MAX_LENGTH = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportWriteLock $lock,
        private DataImportRowRepository $rowRepository,
        private ImportedResultWriter $writer,
        private ImportNeverTwice $neverTwice,
        private GetImportPuzzleCandidates $candidates,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private CollectionItemRepository $collectionItemRepository,
        private WishListItemRepository $wishListItemRepository,
        private ResultDuplicatePreventionRepository $duplicatePreventionRepository,
        private ImportRowTimeCheck $timeCheck,
        private SuspiciousTimeConfirmationRepository $confirmationRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The first pass of a batch (README "Never twice", step 2 - the exact ones first): a row whose result the player
     * already has - the same day, time and solo/pair/team on the decided puzzle, not spoken for by another row - is
     * settled as "already yours" and speaks for that result. Everything else stays queued for add(). Done for all
     * rows before any is added, so "is there a result left that may be this row written differently?" does not
     * depend on the order of the rows.
     *
     * @return bool whether the row was settled
     */
    public function settle(string $rowId, string $batchId, string $playerId): bool
    {
        $this->lock->lockUntilCommit($playerId);
        $row = $this->queuedRow($rowId, $batchId, $playerId);

        if ($row === null || in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true) === false) {
            return false;
        }

        // A row that stands for another row's result is settled with that row, when it is added
        if ($row->sameAsRow !== null && $row->addAsSeparate === false) {
            return false;
        }

        $puzzle = $this->decidedPuzzle($row);
        $said = $this->resultSaid($row);

        if ($puzzle === null || $said === null) {
            return false;
        }

        // The row's own result, or the same data from another import: the writer's part of "never twice" - add() asks
        if ($this->knownResult($row, $playerId, $said['sourceKey']) !== null) {
            return false;
        }

        $equal = ImportNeverTwice::equal(
            $this->neverTwice->freeResultsOnProfile($row->import->player, $puzzle),
            $said['day'],
            $said['seconds'],
            $said['type'],
            $row->alreadyYoursSolvingTimeId?->toString(),
        );

        if ($equal === null || $row->kind === DataImportRowKind::Both) {
            // A row that is also an owned puzzle is finished by add(), which finds the same result again
            return false;
        }

        $row->alreadyYours('profile', Uuid::fromString($equal));

        return true;
    }

    /**
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     *         status: `added`, `already_yours`, `refused` (code = why), `skipped` (not queued in this batch any more);
     *         customValuesOf: the rows that were added here and carry values of the player's own fields (this row,
     *         and the row it stands behind when that one was added on the way)
     */
    public function add(string $rowId, string $batchId, string $playerId): array
    {
        $this->lock->lockUntilCommit($playerId);
        $row = $this->queuedRow($rowId, $batchId, $playerId);

        if ($row === null) {
            return self::outcome('skipped');
        }

        $player = $row->import->player;
        $importPuzzle = $row->importPuzzleId === null ? null : $this->entityManager->find(DataImportPuzzle::class, $row->importPuzzleId);
        $hasResult = in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true);
        $isOwned = in_array($row->kind, [DataImportRowKind::Owned, DataImportRowKind::Both], true);
        $isWish = $row->kind === DataImportRowKind::WishList;

        // What was ready when the batch was made may not be any more: the decision taken back, the sheet parsed again
        if ($importPuzzle !== null && $importPuzzle->choice === ImportPuzzleChoice::Unlisted) {
            return $this->refuse($row, ImportRowRefusal::UnlistedNotYet->value);
        }

        $puzzle = $this->decidedPuzzle($row);

        if ($puzzle === null || $row->problems !== null || ($hasResult || $isOwned || $isWish) === false) {
            return $this->refuse($row, ImportRowRefusal::NotReady->value);
        }

        $now = $this->clock->now();

        // Still a puzzle the player may choose (hidden, secret, not theirs to see): the candidates' own rule. A puzzle
        // merged since the decision is the survivor already - the decision moves with the merge (puzzleMergedInto())
        if (isset($this->candidates->choosable([$puzzle->id->toString()], $playerId, $now)[$puzzle->id->toString()]) === false) {
            return $this->refuse($row, ImportRowRefusal::PuzzleNotAvailable->value);
        }

        try {
            $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $playerId);
        } catch (PuzzleNotRevealedYet) {
            return $this->refuse($row, ImportedResultRefusal::PuzzleNotRevealed->value);
        }

        $comment = self::text($row->parsed['comment'] ?? null);

        if ($hasResult === false && $comment !== null && mb_strlen($comment) > self::COLLECTION_COMMENT_MAX_LENGTH) {
            return $this->refuse($row, ImportRowRefusal::CommentTooLong->value);
        }

        $made = [];
        $alreadyHow = null;
        $alreadyTimeId = null;
        $removedWishListItems = [];
        $customValuesOf = [];

        // --- The result ---
        if ($hasResult) {
            $said = $this->resultSaid($row);

            if ($said === null) {
                return $this->refuse($row, ImportRowRefusal::NeedsExactDay->value);
            }

            $result = $this->resultOf($row, $batchId, $player, $puzzle, $said, $comment);

            if ($result['refused'] !== null) {
                return $this->refuse($row, $result['refused'], $result['customValuesOf']);
            }

            $customValuesOf = $result['customValuesOf'];

            if ($result['made'] !== null) {
                $made['result'] = $result['made'];
                $removedWishListItems = $result['removedWishListItems'];
            } else {
                $alreadyHow = $result['alreadyHow'];
                $alreadyTimeId = $result['alreadyTimeId'];
            }
        }

        // --- The puzzle in the collection ---
        $collectionItemId = null;

        if ($isOwned) {
            // The system collection has no uniqueness in the database - the check holds under the player's lock
            $existing = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle(null, $player, $puzzle);

            if ($existing === null) {
                $item = new CollectionItem(
                    id: Uuid::uuid7(),
                    collection: null,
                    player: $player,
                    puzzle: $puzzle,
                    // A row that is also a result gave its comment to the result
                    comment: $hasResult ? null : $comment,
                    addedAt: $now,
                );

                // The constructor records PuzzleAddedToCollection; its one reaction removes a wish list item behind
                // Undo's back (RemoveFromWishListWhenAddedToCollection) - so the event is taken off and the player's
                // setting is honoured here, where it is written down (README D18). Anything else stays
                foreach ($item->popEvents() as $event) {
                    if ($event instanceof PuzzleAddedToCollection === false) {
                        $item->recordThat($event);
                    }
                }

                $this->collectionItemRepository->save($item);
                $collectionItemId = $item->id;
                $made['collection'] = ['collectionId' => null];

                $wish = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag($playerId, $puzzle->id->toString());

                if ($wish !== null && self::isRemoved($removedWishListItems, $wish->id->toString()) === false) {
                    $removedWishListItems[] = self::removedWish($wish->id->toString(), $puzzle->id->toString(), $wish->addedAt, 'collection');
                    $this->wishListItemRepository->delete($wish);
                }
            } else {
                $alreadyHow ??= 'owned';
            }
        }

        // --- The puzzle on the wish list ---
        $wishListItemId = null;

        if ($isWish) {
            if (
                $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle) !== null
                || $this->collectionItemRepository->findByPlayerAndPuzzle($playerId, $puzzle->id->toString()) !== []
                || $this->neverTwice->hasSolved($player, $puzzle)
            ) {
                // On the list already - or owned or solved: nothing to wish for
                $alreadyHow = 'owned';
            } else {
                $wish = new WishListItem(Uuid::uuid7(), $player, $puzzle, false, $now);
                $this->wishListItemRepository->save($wish);
                $wishListItemId = $wish->id;
                $made['wishList'] = true;
            }
        }

        if ($removedWishListItems !== []) {
            $made['removedWishListItems'] = $removedWishListItems;
        }

        if ($made === []) {
            $row->alreadyYours($alreadyHow ?? 'owned', $alreadyTimeId === null ? null : Uuid::fromString($alreadyTimeId));

            return self::outcome('already_yours', customValuesOf: $customValuesOf);
        }

        // The row's result was the player's already (only its puzzle was added): the row keeps speaking for that result
        $row->added(
            $now,
            $collectionItemId,
            $wishListItemId,
            $made,
            isset($made['result']) ? null : $alreadyHow,
            isset($made['result']) || $alreadyTimeId === null ? null : Uuid::fromString($alreadyTimeId),
        );

        // Values of the player's own fields - and the words that stood beside a time, which become private notes
        foreach (['custom', 'notes'] as $carried) {
            if (is_array($row->parsed[$carried] ?? null) && $row->parsed[$carried] !== []) {
                $customValuesOf[] = $row->id->toString();

                break;
            }
        }

        return self::outcome('added', customValuesOf: $customValuesOf);
    }

    /**
     * The result part of a row: already the player's (how, which result), refused (why), or made by the writer.
     *
     * @param array{day: string, seconds: null|int, type: PuzzlingType, sourceKey: string} $said
     *
     * @return array{refused: null|string, made: null|array<string, mixed>, alreadyHow: null|string, alreadyTimeId: null|string, removedWishListItems: list<array<string, mixed>>, customValuesOf: list<string>}
     */
    private function resultOf(DataImportRow $row, string $batchId, Player $player, Puzzle $puzzle, array $said, null|string $comment): array
    {
        $playerId = $player->id->toString();

        // Equal rows are one result unless the player says two (README D6): the row that stands for them is added
        // (first, if its turn has not come yet) and this one is that result
        if ($row->sameAsRow !== null && $row->addAsSeparate === false) {
            $standsFor = $this->standingRow($row);

            $customValuesOf = [];

            if ($standsFor->isQueuedIn($batchId)) {
                $customValuesOf = $this->add($standsFor->id->toString(), $batchId, $playerId)['customValuesOf'];
            }

            $timeId = match ($standsFor->state) {
                DataImportRowState::Added => $standsFor->solvingTimeId,
                DataImportRowState::AlreadyYours => $standsFor->alreadyYoursSolvingTimeId,
                default => null,
            };

            if ($timeId === null) {
                return self::answer(refused: ImportRowRefusal::EqualRowNotAdded->value, customValuesOf: $customValuesOf);
            }

            return self::answer(how: 'equal_row', timeId: $timeId->toString(), customValuesOf: $customValuesOf);
        }

        // The writer's part of "never twice", asked first so that the questions below are only asked of a row that
        // would really be written: this row's result is still there, or a row with the same key made one
        $known = $this->knownResult($row, $playerId, $said['sourceKey']);

        if ($known !== null) {
            return self::answer(how: $known['how'], timeId: $known['id']);
        }

        $free = $this->neverTwice->freeResultsOnProfile($player, $puzzle);
        $equal = ImportNeverTwice::equal($free, $said['day'], $said['seconds'], $said['type'], $row->alreadyYoursSolvingTimeId?->toString());

        if ($equal !== null) {
            return self::answer(how: 'profile', timeId: $equal);
        }

        $people = self::people($row->parsed['people'] ?? null);

        // What the writer would build must be what the row says it is: a "pair" without a partner named would be
        // written as a solo result, on the solo leaderboard
        if (PuzzlingType::fromPuzzlersCount(count($people) + 1) !== $said['type']) {
            return self::answer(refused: ImportRowRefusal::PeopleDoNotFitType->value);
        }

        if (
            $said['type'] !== PuzzlingType::Solo
            && $said['seconds'] !== null
            && $row->isWavedThrough(ImportRowRefusal::SomebodyElsesResult->value, $puzzle->id->toString()) === false
            && $this->neverTwice->somebodyElseHasIt($player, $puzzle, $said['day'], $said['seconds'])
        ) {
            return self::answer(refused: ImportRowRefusal::SomebodyElsesResult->value);
        }

        if (
            $row->isWavedThrough(ImportRowRefusal::SimilarResultExists->value, $puzzle->id->toString()) === false
            && ImportNeverTwice::similar($free, $said['day'], $said['seconds']) !== null
        ) {
            return self::answer(refused: ImportRowRefusal::SimilarResultExists->value);
        }

        // The time verification's own judgement, as the add form asks it: an unusual time needs the player's "it is
        // right" (README "Time verification") - and with it the result is added and the answer stored for the scan
        $unusual = $said['seconds'] === null
            ? null
            : $this->timeCheck->unusual($playerId, $puzzle->id->toString(), $said['seconds'], $said['type'], count($people) + 1, $said['day']);

        if ($unusual !== null && $row->isWavedThrough(ImportRowRefusal::UnusualTime->value, $puzzle->id->toString()) === false) {
            return self::answer(refused: ImportRowRefusal::UnusualTime->value);
        }

        // A hint, not a rule: the file says the result is on MySpeedPuzzling and none was found - listed, not added
        if (($row->parsed['alreadyOnMsp'] ?? null) === true && $row->isWavedThrough(ImportRowRefusal::SaidAlreadyOnProfile->value, $puzzle->id->toString()) === false) {
            return self::answer(refused: ImportRowRefusal::SaidAlreadyOnProfile->value);
        }

        $outcome = $this->writer->write(new ImportedResult(
            importRowId: $row->id->toString(),
            sourceKey: $said['sourceKey'],
            playerId: $playerId,
            puzzleId: $puzzle->id->toString(),
            seconds: $said['seconds'],
            solvedDay: $said['day'],
            timezone: ImportedResult::zoneOf($player),
            firstTry: is_bool($row->parsed['firstTry'] ?? null) ? $row->parsed['firstTry'] : null,
            groupPlayers: $people,
            comment: $comment,
            position: max(0, $row->rowNumber),
        ));

        if ($outcome->status === ImportedResultStatus::Refused) {
            return self::answer(refused: $outcome->refusal->value ?? ImportRowRefusal::NotReady->value);
        }

        if ($outcome->status === ImportedResultStatus::AlreadyAdded) {
            return self::answer(
                how: $outcome->knownBy === ImportedResultKnownBy::Row ? 'this_row' : 'earlier_import',
                timeId: $outcome->solvingTimeId,
            );
        }

        $solvingTime = $this->scheduledResult((string) $outcome->solvingTimeId);

        // Two equal rows the player confirmed as two: the form's "it's another solve, save it" - the daily duplicate
        // job takes such a result as confirmed real (writer.md "What 6b calls", step 5)
        if ($row->addAsSeparate) {
            $this->duplicatePreventionRepository->save(new ResultDuplicatePrevention(
                Uuid::uuid7(),
                $player,
                DuplicatePreventionKind::SavedAnyway,
                $solvingTime->id,
                $puzzle->id,
                $this->clock->now(),
                SolvingTimeSource::SpreadsheetImport,
            ));
        }

        // "It is right": stored the way the form's answer is, so the scan tells the moderator the player was asked
        if ($unusual !== null) {
            $this->confirmationRepository->save(new SuspiciousTimeConfirmation(
                id: Uuid::uuid7(),
                time: $solvingTime,
                player: $player,
                expectedSeconds: $unusual->expectedSeconds ?? 0,
                confirmedAt: $this->clock->now(),
            ));
        }

        $removed = [];

        if ($outcome->removedWishListItem !== null) {
            $removed[] = self::removedWish($outcome->removedWishListItem['id'], $outcome->removedWishListItem['puzzleId'], $outcome->removedWishListItem['addedAt'], 'solved');
        }

        return self::answer(made: self::resultFacts($solvingTime), removed: $removed);
    }

    /**
     * @param null|array<string, mixed> $made
     * @param list<array<string, mixed>> $removed
     * @param list<string> $customValuesOf
     *
     * @return array{refused: null|string, made: null|array<string, mixed>, alreadyHow: null|string, alreadyTimeId: null|string, removedWishListItems: list<array<string, mixed>>, customValuesOf: list<string>}
     */
    private static function answer(
        null|string $refused = null,
        null|array $made = null,
        null|string $how = null,
        null|string $timeId = null,
        array $removed = [],
        array $customValuesOf = [],
    ): array {
        return [
            'refused' => $refused,
            'made' => $made,
            'alreadyHow' => $how,
            'alreadyTimeId' => $timeId,
            'removedWishListItems' => $removed,
            'customValuesOf' => $customValuesOf,
        ];
    }

    /**
     * What an Undo compares a result with to tell whether somebody changed it since the import: the puzzle, the time,
     * the day and the people (a pair/team is the exact set of people - other people, another team).
     *
     * @return array{puzzleId: string, seconds: null|int, day: null|string, teamId: null|string}
     */
    public static function resultFacts(PuzzleSolvingTime $solvingTime): array
    {
        return [
            'puzzleId' => $solvingTime->puzzle->id->toString(),
            'seconds' => $solvingTime->secondsToSolve,
            'day' => $solvingTime->finishedAt?->format('Y-m-d'),
            'teamId' => $solvingTime->puzzlingTeam?->id->toString(),
        ];
    }

    private function queuedRow(string $rowId, string $batchId, string $playerId): null|DataImportRow
    {
        $row = $this->rowRepository->find($rowId);

        if ($row === null) {
            return null;
        }

        if ($row->import->isOwnedBy(strtolower($playerId)) === false) {
            throw new LogicException(sprintf('Import row %s is not of player %s.', $rowId, $playerId));
        }

        return $row->isQueuedIn($batchId) ? $row : null;
    }

    /**
     * The catalogue puzzle the row's distinct puzzle is decided to be - as the decision stands now.
     */
    private function decidedPuzzle(DataImportRow $row): null|Puzzle
    {
        if ($row->importPuzzleId === null) {
            return null;
        }

        $importPuzzle = $this->entityManager->find(DataImportPuzzle::class, $row->importPuzzleId);

        if ($importPuzzle === null || $importPuzzle->choice !== ImportPuzzleChoice::Catalogue) {
            return null;
        }

        return $importPuzzle->puzzle;
    }

    /**
     * What the row says of its result - only a result with an exact day (README D13: a date we do not know is never
     * stored as a day).
     *
     * @return null|array{day: string, seconds: null|int, type: PuzzlingType, sourceKey: string}
     */
    private function resultSaid(DataImportRow $row): null|array
    {
        $parsed = $row->parsed ?? [];
        $day = $parsed['date'] ?? null;
        $keys = is_array($parsed['keys'] ?? null) ? $parsed['keys'] : [];
        $sourceKey = is_string($keys['result'] ?? null) ? $keys['result'] : $row->sourceKey;
        $type = is_string($parsed['type'] ?? null) ? PuzzlingType::tryFrom($parsed['type']) : null;

        if (($parsed['datePrecision'] ?? null) !== 'day' || is_string($day) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        if ($sourceKey === null || $sourceKey === '' || $type === null) {
            return null;
        }

        $seconds = is_int($parsed['seconds'] ?? null) && $parsed['seconds'] > 0 ? $parsed['seconds'] : null;

        return ['day' => $day, 'seconds' => $seconds, 'type' => $type, 'sourceKey' => $sourceKey];
    }

    /**
     * The writer's own two questions (ImportedResultWriter, "never twice" steps 2 and 3), asked before the profile is
     * looked at: the result this row made that still exists, else a result a row with the same key made - in any of
     * the player's imports. The writer asks them again when it is called.
     *
     * @return null|array{id: string, how: string}
     */
    private function knownResult(DataImportRow $row, string $playerId, string $sourceKey): null|array
    {
        $linked = $this->rowRepository->linkedSolvingTimeId($row->id->toString());

        if ($linked !== null && $this->resultExists($linked)) {
            return ['id' => $linked, 'how' => 'this_row'];
        }

        foreach ($this->rowRepository->solvingTimeIdsOfSourceKey($playerId, $sourceKey, $row->id->toString()) as $known) {
            if ($this->resultExists($known)) {
                return ['id' => $known, 'how' => 'earlier_import'];
            }
        }

        return null;
    }

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
     * The result the writer just persisted - not in the database before the flush.
     */
    private function scheduledResult(string $solvingTimeId): PuzzleSolvingTime
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PuzzleSolvingTime && $entity->id->toString() === $solvingTimeId) {
                return $entity;
            }
        }

        return $this->entityManager->find(PuzzleSolvingTime::class, $solvingTimeId)
            ?? throw new LogicException(sprintf('The result %s the writer added is not there.', $solvingTimeId));
    }

    /**
     * The row that stands for a group of equal rows: at most two steps away (an equal row of its own sheet, then the
     * same row on an earlier sheet).
     */
    private function standingRow(DataImportRow $row): DataImportRow
    {
        $standing = $row;

        for ($step = 0; $step < 4 && $standing->sameAsRow !== null && $standing->addAsSeparate === false; $step++) {
            $standing = $standing->sameAsRow;
        }

        return $standing;
    }

    /**
     * @param list<string> $customValuesOf
     *
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     */
    private function refuse(DataImportRow $row, string $code, array $customValuesOf = []): array
    {
        $row->refused($code);

        return self::outcome('refused', $code, $customValuesOf);
    }

    /**
     * @param list<string> $customValuesOf
     *
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     */
    private static function outcome(string $status, null|string $code = null, array $customValuesOf = []): array
    {
        return ['status' => $status, 'code' => $code, 'customValuesOf' => $customValuesOf];
    }

    /**
     * The other people of a row - **guests, every one of them** (README D12): an import never puts a result on
     * another registered player's profile. The writer reads an entry that starts with `#` as a player's code, like
     * the add form does; no entry leaves here with one, so that door is not reachable from an import - a `#CODE` in a
     * file is the guest's name without the sign (what the form makes of a code nobody has). The existing consent flow
     * (GuestLinkRequest) links a guest to a player later.
     *
     * @return list<string> one guest's name per entry
     */
    public static function people(mixed $people): array
    {
        if (is_array($people) === false) {
            return [];
        }

        $named = [];

        foreach ($people as $person) {
            $name = is_string($person) ? trim($person, "# \t\n\r\0\x0B") : '';

            if ($name !== '') {
                $named[] = $name;
            }
        }

        return array_values(array_unique($named));
    }

    private static function text(mixed $value): null|string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param list<array<string, mixed>> $removed
     */
    private static function isRemoved(array $removed, string $wishListItemId): bool
    {
        foreach ($removed as $item) {
            if (($item['id'] ?? null) === $wishListItemId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A wish list item the row removed by the player's own setting - all Undo needs to put it back.
     *
     * @return array{id: string, puzzleId: string, addedAt: string, removeOnCollectionAdd: bool, why: string}
     */
    private static function removedWish(string $id, string $puzzleId, DateTimeImmutable $addedAt, string $why): array
    {
        return [
            'id' => $id,
            'puzzleId' => $puzzleId,
            'addedAt' => $addedAt->format('Y-m-d H:i:s'),
            // Only an item with the flag is ever removed (findByPlayerIdAndPuzzleIdWithRemoveFlag)
            'removeOnCollectionAdd' => true,
            'why' => $why,
        ];
    }
}
