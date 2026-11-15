<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Unlisted;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\UnlistedOwnership;
use SpeedPuzzling\Web\Entity\UnlistedPairing;
use SpeedPuzzling\Web\Entity\UnlistedResult;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\MessageHandler\FollowUpImportBatchHandler;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\ImportPuzzleDecisionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultsFollowUp;
use SpeedPuzzling\Web\Services\DataImport\ImportResultQuestions;
use SpeedPuzzling\Web\Services\DataImport\ImportNeverTwice;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAdder;
use SpeedPuzzling\Web\Services\DataImport\ImportWriteLock;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedKeptReason;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedOwnershipKind;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingState;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The steps of "It is this catalogue puzzle" and of its Undo (docs/features/data-import/unlisted-puzzles.md
 * "Pairing") - **the one place where something unlisted becomes public**, and it goes the import's own way:
 *
 * - an unlisted result becomes a real result **only through ImportResultQuestions** - the very code an "Add" asks
 *   its questions with and then calls ImportedResultWriter from, not a copy: never twice against what the player has
 *   (the row's own result, the same data from another import, the same day + time + kind on the profile), a similar
 *   result, somebody else's pair/team result, people that do not fit, the time verification's judgement, "the file
 *   says it is on MySpeedPuzzling", "these are two" told to the duplicate job. Nothing is waved through here:
 *   **whatever would be a question stays unlisted**, with the reason;
 * - **what a pairing makes public counts in the day's guard** like what an "Add" makes public: once today's
 *   results are used up, the rest waits for tomorrow;
 * - a result known to its month or year becomes a result of that precision; an undated one stays unlisted;
 * - a mark becomes an item of the player's system collection / wish list under the engine's rules; what was there
 *   before is never touched.
 *
 * Called inside a handler's transaction (ApplyUnlistedPairingStep - one subject, one transaction), under the
 * player's import lock: it persists and never flushes. Every step reads its subject after the lock and acts only on
 * what the pairing still owns, so a step run twice does nothing the second time.
 */
readonly final class UnlistedPairer
{
    /** CollectionItem::$comment */
    private const int COLLECTION_COMMENT_MAX_LENGTH = 500;

    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
        private DataImportRowRepository $rowRepository,
        private ImportResultQuestions $questions,
        private ImportedResultsFollowUp $followUp,
        private ImportNeverTwice $neverTwice,
        private GetImportPuzzleCandidates $candidates,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private CollectionItemRepository $collectionItemRepository,
        private WishListItemRepository $wishListItemRepository,
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private ImportPuzzleDecisionRepository $decisionRepository,
        private EntityManagerInterface $entityManager,
        private ImportWriteLock $lock,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool false: **today's guard on results from spreadsheets is used up** - the result was left as it is
     *         and waits for tomorrow (the pairing stays at work); true: the step is done
     */
    public function result(string $pairingId, string $resultId): bool
    {
        $pairing = $this->lockedPairing($pairingId);
        $result = $this->unlistedRepository->findResult($resultId);

        if ($pairing === null || $result === null || $result->pairingId?->equals($pairing->id) !== true || $result->keptReason !== null) {
            return true;
        }

        $now = $this->clock->now();
        $pairing->workedOn($now);

        if ($pairing->state === UnlistedPairingState::Running && $result->isUnlisted()) {
            // What a pairing makes public counts like what an "Add" makes public: at most so many results a day
            // (README Q4). Asked under the player's lock, of the results as they are now
            if ($this->resultsLeftToday($pairing->player->id->toString(), $now) <= 0) {
                $pairing->waitsForTomorrow($now);

                return false;
            }

            $pairing->goesOn();
            $this->convertResult($pairing, $result, $now);
        }

        if ($pairing->state === UnlistedPairingState::Undoing && $result->isUnlisted() === false) {
            $this->takeBackResult($pairing, $result, $now);
        }

        return true;
    }

    /**
     * How many results the player's imports may still make public today (UTC, as AddImportRowsHandler counts).
     */
    private function resultsLeftToday(string $playerId, DateTimeImmutable $now): int
    {
        $startOfDay = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        return DataImportLimits::MAX_RESULTS_ADDED_PER_DAY - $this->rowRepository->countResultsAddedOrQueuedSince($playerId, $startOfDay);
    }

    public function ownership(string $pairingId, string $ownershipId): void
    {
        $pairing = $this->lockedPairing($pairingId);
        $ownership = $this->unlistedRepository->findOwnership($ownershipId);

        if ($pairing === null || $ownership === null || $ownership->pairingId?->equals($pairing->id) !== true || $ownership->keptReason !== null) {
            return;
        }

        $now = $this->clock->now();
        $pairing->workedOn($now);

        if ($pairing->state === UnlistedPairingState::Running && $ownership->isUnlisted()) {
            $this->convertOwnership($pairing, $ownership, $now);
        }

        if ($pairing->state === UnlistedPairingState::Undoing && $ownership->isUnlisted() === false) {
            $this->takeBackOwnership($pairing, $ownership);
        }
    }

    /**
     * A step threw: its subject is left where it is, with the reason - never tried again by this pairing.
     */
    public function failed(string $pairingId, string $step, string $subjectId): void
    {
        $pairing = $this->lockedPairing($pairingId);
        $subject = $step === 'result' ? $this->unlistedRepository->findResult($subjectId) : $this->unlistedRepository->findOwnership($subjectId);

        if ($pairing === null || $subject === null || $subject->pairingId?->equals($pairing->id) !== true) {
            return;
        }

        $pairing->workedOn($this->clock->now());
        $subject->kept(UnlistedKeptReason::Error->value);
    }

    /**
     * Once nothing of the pairing waits any more: what is owed for the results it made (statistics, XP, achievements,
     * predictions - ImportedResultsFollowUp, as a batch of an import owes it) and its numbers; for an Undo the
     * puzzle is unlisted again and the remembered decision with it.
     *
     * @return bool false: something still waits - not finished
     */
    public function finish(string $pairingId): bool
    {
        $pairing = $this->lockedPairing($pairingId);

        if ($pairing === null) {
            return true;
        }

        $undoing = $pairing->state === UnlistedPairingState::Undoing;

        if ($this->unlistedRepository->pendingOfPairing($pairingId, $undoing, 1) !== []) {
            return false;
        }

        $now = $this->clock->now();
        $unlistedPuzzle = $pairing->unlistedPuzzle;
        $results = $unlistedPuzzle === null ? [] : $this->unlistedRepository->resultsOf($unlistedPuzzle);
        $ownerships = $unlistedPuzzle === null ? [] : $this->unlistedRepository->ownershipsOf($unlistedPuzzle);

        if ($undoing) {
            $pairing->undone($now);
            // "Not this puzzle": the daily look does not suggest what the player just took back (and tell them again)
            $unlistedPuzzle?->pairingTakenBack($pairing->puzzleId);

            if ($pairing->resultsTakenBack > 0) {
                $this->messageBus->dispatch(new RecalculateBadgesForPlayer($pairing->player->id->toString()));
            }

            $somethingStillReal = false;

            foreach ([...$results, ...$ownerships] as $subject) {
                $somethingStillReal = $somethingStillReal || $subject->isUnlisted() === false;
            }

            // Everything is unlisted again: so is the puzzle, and the next file's rows of it
            if ($somethingStillReal === false && $unlistedPuzzle !== null) {
                $unlistedPuzzle->unpaired();
                $remembered = $this->decisionRepository->ofPlayerByKeys($pairing->player, [$unlistedPuzzle->puzzleKey])[$unlistedPuzzle->puzzleKey] ?? null;

                if ($remembered !== null && $remembered->choice === ImportPuzzleChoice::Catalogue && $remembered->puzzle?->id->equals($pairing->puzzleId) === true) {
                    $remembered->decide(ImportPuzzleChoice::Unlisted, null, $now);
                }
            }

            return true;
        }

        $counts = ['resultsAdded' => 0, 'resultsAlreadyYours' => 0, 'resultsKept' => 0, 'itemsAdded' => 0, 'itemsAlreadyYours' => 0];
        $outcomes = [];

        foreach ($results as $result) {
            if ($result->pairingId?->equals($pairing->id) !== true) {
                continue;
            }

            if ($result->isUnlisted()) {
                $counts['resultsKept']++;

                continue;
            }

            $counts[$result->convertedCreated ? 'resultsAdded' : 'resultsAlreadyYours']++;
            $solvingTime = $result->convertedCreated && $result->convertedSolvingTimeId !== null
                ? $this->entityManager->find(PuzzleSolvingTime::class, $result->convertedSolvingTimeId)
                : null;

            if ($solvingTime !== null) {
                $outcomes[] = ImportedResultOutcome::added(
                    importRowId: $result->importRowId->toString(),
                    solvingTimeId: $solvingTime->id->toString(),
                    puzzleId: $solvingTime->puzzle->id->toString(),
                    memberPlayerIds: $solvingTime->memberPlayerIds(),
                    predictionPending: $solvingTime->isPredictionPending(),
                    removedWishListItem: null,
                );
            }
        }

        foreach ($ownerships as $ownership) {
            if ($ownership->pairingId?->equals($pairing->id) === true && $ownership->isUnlisted() === false) {
                $counts[$ownership->convertedCreated ? 'itemsAdded' : 'itemsAlreadyYours']++;
            }
        }

        // The XP awards on the import's own queue, like a batch's
        $owed = $this->followUp->forResults($outcomes, FollowUpImportBatchHandler::XP_TRANSPORT);
        $this->followUp->forPeople($owed['memberPlayerIds'], $owed['playersAwaitingPredictions']);
        $pairing->finished($counts, $now);

        return true;
    }

    private function convertResult(UnlistedPairing $pairing, UnlistedResult $result, DateTimeImmutable $now): void
    {
        $player = $pairing->player;
        $playerId = $player->id->toString();
        $puzzle = $this->puzzleOf($pairing, $now);

        if ($puzzle instanceof Puzzle === false) {
            $result->kept($puzzle);

            return;
        }

        // A result known to its month or year becomes a result of that precision (date-precision.md); only one
        // without any date has nothing to be written with - the code kept its name
        $dated = $result->dated();

        if ($dated === null) {
            $result->kept(UnlistedKeptReason::NeedsExactDay->value);

            return;
        }

        $row = $this->rowRepository->find($result->importRowId->toString());

        if ($row === null || $row->import->isOwnedBy($playerId) === false) {
            // The row the writer links the result to is gone: nothing is written without it
            $result->kept(ImportRowRefusal::NotReady->value);

            return;
        }

        // Every question an "Add" asks before it writes, and the writer - the import's own code, not a copy of it
        // (ImportResultQuestions): what would be a question to the player keeps the result unlisted, with the reason
        $asked = $this->questions->ask(
            row: $row,
            player: $player,
            puzzle: $puzzle,
            sourceKey: $result->sourceKey,
            day: $dated['day'],
            seconds: $result->secondsToSolve !== null && $result->secondsToSolve > 0 ? $result->secondsToSolve : null,
            type: $result->puzzlingType,
            people: ImportRowAdder::people($result->people),
            firstTry: $result->firstAttempt,
            comment: $result->comment,
            position: $result->rowNumber,
            saidAlreadyOnProfile: $result->saidAlreadyOnProfile,
            countedOnItsOwn: $result->countedOnItsOwn,
            precision: $dated['precision'],
        );

        if ($asked['refused'] !== null) {
            $result->kept($asked['refused']);

            return;
        }

        if ($asked['alreadyTimeId'] === null) {
            $result->kept(UnlistedKeptReason::Error->value);

            return;
        }

        if ($asked['made'] === null) {
            // The player's already: nothing was added. A result of the profile no row spoke for is this row's now
            $result->converted(Uuid::fromString($asked['alreadyTimeId']), false, null, $now);

            if ($asked['alreadyHow'] === 'profile') {
                $row->unlistedResultIsOnProfile(Uuid::fromString($asked['alreadyTimeId']));
            }

            return;
        }

        $made = ['result' => $asked['made']];

        if ($asked['removedWishListItem'] !== null) {
            $made['removedWishListItems'] = [self::removedWish($asked['removedWishListItem']['id'], $asked['removedWishListItem']['puzzleId'], $asked['removedWishListItem']['addedAt'])];
        }

        $result->converted(Uuid::fromString($asked['alreadyTimeId']), true, $made, $now);
    }

    private function takeBackResult(UnlistedPairing $pairing, UnlistedResult $result, DateTimeImmutable $now): void
    {
        $player = $pairing->player;
        $row = $this->rowRepository->find($result->importRowId->toString());

        if ($result->convertedCreated && $result->convertedSolvingTimeId !== null) {
            $solvingTime = $this->entityManager->find(PuzzleSolvingTime::class, $result->convertedSolvingTimeId);

            if ($solvingTime !== null) {
                if ($this->isChangedSince($solvingTime, $result->made['result'] ?? null)) {
                    // Changed since the pairing: the real result stays as it is, and so does this
                    $result->kept(UnlistedKeptReason::EditedSince->value);
                    $pairing->resultTakenBack(false, $now);

                    return;
                }

                $this->deleteResult($solvingTime, $player);
                $pairing->resultTakenBack(true, $now);
            }

            $this->restoreWishListItems($result->made['removedWishListItems'] ?? null, $player);
        }

        $result->unlistedAgain();

        if ($row !== null && $row->import->isOwnedBy($player->id->toString())) {
            $row->unlistedResultIsUnlistedAgain();
        }
    }

    private function convertOwnership(UnlistedPairing $pairing, UnlistedOwnership $ownership, DateTimeImmutable $now): void
    {
        $player = $pairing->player;
        $playerId = $player->id->toString();
        $puzzle = $this->puzzleOf($pairing, $now);

        if ($puzzle instanceof Puzzle === false) {
            $ownership->kept($puzzle);

            return;
        }

        if ($ownership->kind === UnlistedOwnershipKind::WishList) {
            if (
                $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle) !== null
                || $this->collectionItemRepository->findByPlayerAndPuzzle($playerId, $puzzle->id->toString()) !== []
                || $this->neverTwice->hasSolved($player, $puzzle)
            ) {
                // On the list already - or owned or solved: nothing to wish for
                $ownership->converted(null, false, null, $now);

                return;
            }

            $wish = new WishListItem(Uuid::uuid7(), $player, $puzzle, false, $now);
            $this->wishListItemRepository->save($wish);
            $ownership->converted($wish->id, true, null, $now);

            return;
        }

        // The system collection has no uniqueness in the database - the check holds under the player's lock
        $existing = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle(null, $player, $puzzle);

        if ($existing !== null) {
            $ownership->converted($existing->id, false, null, $now);

            return;
        }

        if ($ownership->comment !== null && mb_strlen($ownership->comment) > self::COLLECTION_COMMENT_MAX_LENGTH) {
            $ownership->kept(ImportRowRefusal::CommentTooLong->value);

            return;
        }

        $item = new CollectionItem(
            id: Uuid::uuid7(),
            collection: null,
            player: $player,
            puzzle: $puzzle,
            comment: $ownership->comment,
            addedAt: $now,
        );

        // As ImportRowAdder: the event's one reaction removes a wish list item behind Undo's back, so it is taken off
        // and the player's setting is honoured here, where it is written down
        foreach ($item->popEvents() as $event) {
            if ($event instanceof PuzzleAddedToCollection === false) {
                $item->recordThat($event);
            }
        }

        $this->collectionItemRepository->save($item);
        $made = null;
        $wish = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag($playerId, $puzzle->id->toString());

        if ($wish !== null) {
            $made = ['removedWishListItems' => [self::removedWish($wish->id->toString(), $puzzle->id->toString(), $wish->addedAt)]];
            $this->wishListItemRepository->delete($wish);
        }

        $ownership->converted($item->id, true, $made, $now);
    }

    private function takeBackOwnership(UnlistedPairing $pairing, UnlistedOwnership $ownership): void
    {
        $player = $pairing->player;

        if ($ownership->convertedCreated && $ownership->convertedItemId !== null) {
            if ($ownership->kind === UnlistedOwnershipKind::WishList) {
                $wish = $this->entityManager->find(WishListItem::class, $ownership->convertedItemId);

                if ($wish !== null && $wish->player->id->equals($player->id)) {
                    $this->wishListItemRepository->delete($wish);
                }
            } else {
                $item = $this->entityManager->find(CollectionItem::class, $ownership->convertedItemId);

                if ($item !== null && $item->player->id->equals($player->id)) {
                    if ($item->collection !== null) {
                        // Moved to another collection since: the player placed it - kept
                        $ownership->kept(UnlistedKeptReason::MovedSince->value);

                        return;
                    }

                    $this->collectionItemRepository->delete($item);
                }
            }

            $this->restoreWishListItems($ownership->made['removedWishListItems'] ?? null, $player);
        }

        $ownership->unlistedAgain();
    }

    /**
     * The pairing, read under the player's import lock: no other row or step of the player is written until this
     * one is committed.
     */
    private function lockedPairing(string $pairingId): null|UnlistedPairing
    {
        $pairing = $this->unlistedRepository->findPairing($pairingId);

        if ($pairing === null) {
            return null;
        }

        $this->lock->lockUntilCommit($pairing->player->id->toString());
        $this->entityManager->refresh($pairing);

        return $pairing->isWorking() ? $pairing : null;
    }

    /**
     * The catalogue puzzle of the pairing as it may be written to now - or why not: still a puzzle the player may
     * choose (the candidates' own rule: not hidden, not waiting for approval) and not a secret one before its reveal.
     */
    private function puzzleOf(UnlistedPairing $pairing, DateTimeImmutable $now): Puzzle|string
    {
        $playerId = $pairing->player->id->toString();
        $puzzleId = $pairing->puzzleId->toString();
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);

        // Merged into another puzzle between the player's tap and this step: the survivor is the puzzle now
        if ($puzzle === null) {
            $puzzleId = $this->puzzleRedirectRepository->findByOldPuzzleId($puzzleId)?->survivorPuzzleId->toString() ?? $puzzleId;
            $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        }

        if ($puzzle === null || isset($this->candidates->choosable([$puzzleId], $playerId, $now)[$puzzleId]) === false) {
            return ImportRowRefusal::PuzzleNotAvailable->value;
        }

        try {
            $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $playerId);
        } catch (PuzzleNotRevealedYet) {
            return ImportedResultRefusal::PuzzleNotRevealed->value;
        }

        return $puzzle;
    }

    /**
     * As UndoImportRowHandler: whether somebody changed the result since it was written - its time, day, people, or
     * its puzzle, unless the puzzle it was written on was merged into the one it is on now.
     */
    private function isChangedSince(PuzzleSolvingTime $solvingTime, mixed $written): bool
    {
        $now = ImportRowAdder::resultFacts($solvingTime);

        if (is_array($written) === false) {
            return true;
        }

        $writtenPuzzleId = $written['puzzleId'] ?? null;

        if (is_string($writtenPuzzleId) && $writtenPuzzleId !== $now['puzzleId']) {
            $mergedInto = $this->puzzleRedirectRepository->findByOldPuzzleId($writtenPuzzleId)?->survivorPuzzleId->toString();

            if ($mergedInto !== $now['puzzleId']) {
                return true;
            }
        }

        return ($written['seconds'] ?? null) !== $now['seconds']
            || ($written['day'] ?? null) !== $now['day']
            || ($written['teamId'] ?? null) !== $now['teamId'];
    }

    /**
     * As UndoImportRowHandler: the one delete of a result (graveyard, tombstones, the puzzle's statistics by its
     * event) and what the normal delete does around it. The owner's achievements once, when the Undo is through.
     */
    private function deleteResult(PuzzleSolvingTime $solvingTime, Player $owner): void
    {
        $puzzleId = $solvingTime->puzzle->id->toString();
        $solvingTimeId = $solvingTime->id->toString();
        $memberPlayerIds = $solvingTime->memberPlayerIds();

        $this->solvingTimeRepository->delete($solvingTime, SolvingTimeDeletedVia::ImportUndo);

        foreach ($memberPlayerIds as $memberPlayerId) {
            if ($memberPlayerId !== $owner->id->toString()) {
                $this->messageBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
            }
        }

        $this->messageBus->dispatch(new CompensateXpForDeletedSolve($solvingTimeId, $puzzleId, $memberPlayerIds));
    }

    private function restoreWishListItems(mixed $removed, Player $player): void
    {
        foreach (is_array($removed) ? $removed : [] as $item) {
            if (is_array($item) === false || is_string($item['id'] ?? null) === false || is_string($item['puzzleId'] ?? null) === false) {
                continue;
            }

            $puzzle = $this->entityManager->find(Puzzle::class, $item['puzzleId']);

            // The puzzle is gone, or it is on the list again (one item per player and puzzle)
            if ($puzzle === null || $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle) !== null) {
                continue;
            }

            $addedAt = is_string($item['addedAt'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $item['addedAt']) : false;
            $id = Uuid::isValid($item['id']) && $this->entityManager->find(WishListItem::class, $item['id']) === null
                ? Uuid::fromString($item['id'])
                : Uuid::uuid7();

            $this->wishListItemRepository->save(new WishListItem($id, $player, $puzzle, true, $addedAt === false ? $this->clock->now() : $addedAt));
        }
    }

    /**
     * @return array{id: string, puzzleId: string, addedAt: string}
     */
    private static function removedWish(string $id, string $puzzleId, DateTimeImmutable $addedAt): array
    {
        return ['id' => $id, 'puzzleId' => $puzzleId, 'addedAt' => $addedAt->format('Y-m-d H:i:s')];
    }
}
