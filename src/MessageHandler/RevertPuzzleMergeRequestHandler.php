<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\BorrowRequestItem;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\ImportPuzzleDecision;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Entity\ListingRequestItem;
use SpeedPuzzling\Web\Entity\ListingRequestOffer;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Entity\Tag;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Message\RevertPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeAuditRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Undoes an approved merge from its puzzle_merge_audit row (docs/features/puzzle-approvals.md, "Reverting a merge"):
 *
 * - the merged puzzle exists again under its own id (old links work again: its redirect goes), as the snapshot before
 *   the merge holds it;
 * - every record the merge moved onto the survivor (by the ids in the audit's inventory) goes back, when it is still
 *   on the survivor - what was logged on the survivor after the merge stays there, nothing tells it apart;
 * - the survivor gets back each field the merge changed, unless somebody changed that field after the merge (then the
 *   newer value stays and the decision says so).
 *
 * Rows the merge dropped as duplicates (a second collection item of the same player…) are gone - the audit has their
 * ids only. Merges approved before the audit existed (2026-09-16) and merges of several puzzles at once (the
 * inventory does not say which puzzle a row came from) are refused - those are reverted by hand.
 */
#[AsMessageHandler]
readonly final class RevertPuzzleMergeRequestHandler
{
    public function __construct(
        private PuzzleMergeRequestRepository $puzzleMergeRequestRepository,
        private PuzzleMergeAuditRepository $puzzleMergeAuditRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private SecretPuzzleHides $secretPuzzleHides,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PuzzleMergeRequestNotFound
     * @throws PlayerNotFound
     * @throws InvalidPuzzleValues
     */
    public function __invoke(RevertPuzzleMergeRequest $message): void
    {
        $mergeRequest = $this->puzzleMergeRequestRepository->get($message->mergeRequestId);
        $admin = $this->playerRepository->get($message->adminId);

        if ($mergeRequest->status !== PuzzleReportStatus::Approved || $mergeRequest->survivorPuzzleId === null) {
            throw new InvalidPuzzleValues('Only an approved merge can be reverted.');
        }

        if (strtolower($mergeRequest->survivorPuzzleId->toString()) !== strtolower($message->survivorPuzzleId)) {
            throw new InvalidPuzzleValues('The puzzle this merge kept is not the one given.');
        }

        $audit = $this->puzzleMergeAuditRepository->findLatestForMergeRequest($mergeRequest->id)
            ?? throw new InvalidPuzzleValues('This merge was approved before merges were audited (2026-09-16) - nothing records what it changed, so it can only be reverted by hand.');

        $survivorBefore = self::arrayOf($audit->snapshotBefore['survivorPuzzle'] ?? null);
        $survivorAfter = self::arrayOf($audit->snapshotAfter['survivorPuzzle'] ?? null);
        $migrated = self::arrayOf($audit->snapshotBefore['migrated'] ?? null);
        $mergedSnapshots = array_values(array_filter(
            self::arrayOf($audit->snapshotBefore['mergedPuzzles'] ?? null),
            is_array(...),
        ));

        if (count($mergedSnapshots) !== 1) {
            throw new InvalidPuzzleValues('This merge joined several puzzles at once - its audit does not say which record came from which puzzle, so it can only be reverted by hand.');
        }

        /** @var array<string, mixed> $mergedSnapshot */
        $mergedSnapshot = $mergedSnapshots[0];
        $restoredId = self::stringOf($mergedSnapshot['id'] ?? null);

        if ($restoredId === null || Uuid::isValid($restoredId) === false || $survivorBefore === [] || $survivorAfter === []) {
            throw new InvalidPuzzleValues('The audit of this merge is incomplete - it can only be reverted by hand.');
        }

        // The survivor is locked like a merge locks it: an edit committing meanwhile waits until the revert commits
        $locked = [];

        foreach ($this->puzzleRepository->findByIdsForUpdate([$mergeRequest->survivorPuzzleId->toString(), $restoredId]) as $puzzle) {
            $locked[strtolower($puzzle->id->toString())] = $puzzle;
        }

        if (isset($locked[strtolower($restoredId)])) {
            throw new InvalidPuzzleValues('The merged puzzle exists - this merge was reverted already.');
        }

        $survivor = $locked[strtolower($mergeRequest->survivorPuzzleId->toString())]
            ?? throw new InvalidPuzzleValues('The puzzle this merge kept no longer exists (a later merge or a deletion) - revert that first.');

        $restored = $this->restorePuzzle($mergedSnapshot, $restoredId);
        $this->puzzleRepository->save($restored);

        $keptOnSurvivor = $this->restoreSurvivor($survivor, $survivorBefore, $survivorAfter);
        [$movedBack, $affectedPlayerIds] = $this->moveRecordsBack($migrated, $survivor, $restored);

        // Its old address shows the puzzle again instead of redirecting to the survivor
        $redirect = $this->puzzleRedirectRepository->findByOldPuzzleId($restoredId);

        if ($redirect !== null) {
            $this->puzzleRedirectRepository->delete($redirect);
        }

        $this->secretPuzzleHides->resync($survivor, $restored);

        $now = $this->clock->now();
        $mergeRequest->revert($admin, $now, [$restored->id->toString()]);

        $notRestored = [];

        foreach ($migrated as $kind => $records) {
            if (is_array($records) && is_array($records['droppedAsDuplicate'] ?? null) && $records['droppedAsDuplicate'] !== []) {
                $notRestored[(string) $kind] = count($records['droppedAsDuplicate']);
            }
        }

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::MergeRequestReverted,
            decidedBy: $admin,
            source: $message->decisionSource,
            puzzleId: $survivor->id,
            puzzleName: $survivor->name,
            mergeRequestId: $mergeRequest->id,
            note: $message->note,
            details: [
                'survivorPuzzleId' => $survivor->id->toString(),
                // The same key as an approved merge's details: the restored puzzle's history finds the revert by it
                'mergedPuzzleIds' => [$restored->id->toString()],
                'mergedPuzzleNames' => [$restored->name],
                'movedBack' => $movedBack,
                // Fields somebody changed on the survivor after the merge - the newer value stayed
                'keptOnSurvivor' => $keptOnSurvivor,
                // Rows the merge deleted as duplicates - not restorable
                'notRestored' => $notRestored,
            ],
        );

        // Results moved: whose first solve of which puzzle changes on both puzzles - the chain rebuild of one result
        // does not see the puzzle it left, a player's full rebuild does
        foreach ($affectedPlayerIds as $playerId) {
            $this->messageBus->dispatch(new RecalculateXpForPlayer($playerId));
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($playerId));
        }

        // The reporter's accepted report is not one any more, the merged puzzle's adder has their own puzzle again
        $this->messageBus->dispatch(RecalculateKarma::forPlayers(
            $mergeRequest->reporter,
            $mergeRequest->reviewedBy,
            $admin,
            $survivor->addedByUser,
            $restored->addedByUser,
        ));

        if ($mergeRequest->reporter !== null) {
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($mergeRequest->reporter->id->toString()));
        }

        $this->logger->info('Puzzle merge {mergeRequestId} reverted: {restoredPuzzleId} restored from {survivorPuzzleId}', [
            'mergeRequestId' => $mergeRequest->id->toString(),
            'survivorPuzzleId' => $survivor->id->toString(),
            'restoredPuzzleId' => $restored->id->toString(),
            'movedBack' => $movedBack,
            'keptOnSurvivor' => $keptOnSurvivor,
            'notRestored' => $notRestored,
        ]);
    }

    /**
     * @param array<mixed> $snapshot
     */
    private function restorePuzzle(array $snapshot, string $id): Puzzle
    {
        $manufacturerId = self::stringOf($snapshot['manufacturerId'] ?? null);
        $addedById = self::stringOf($snapshot['addedByUserId'] ?? null);
        $name = self::stringOf($snapshot['name'] ?? null) ?? '';
        $pieces = $snapshot['piecesCount'] ?? null;
        $ratio = $snapshot['imageRatio'] ?? null;

        return new Puzzle(
            id: Uuid::fromString($id),
            piecesCount: is_int($pieces) ? $pieces : 0,
            name: $name,
            approved: ($snapshot['approved'] ?? false) === true,
            image: self::stringOf($snapshot['image'] ?? null),
            imageRatio: is_int($ratio) || is_float($ratio) ? (float) $ratio : null,
            manufacturer: $manufacturerId !== null ? $this->entityManager->find(Manufacturer::class, $manufacturerId) : null,
            alternativeNames: self::namesOf($snapshot),
            addedByUser: $addedById !== null ? $this->entityManager->find(Player::class, $addedById) : null,
            addedAt: self::dateOf($snapshot['addedAt'] ?? null),
            brandCodes: BrandCodeList::fromStored(self::stringOf($snapshot['identificationNumber'] ?? null)),
            eans: EanList::fromStored(self::stringOf($snapshot['ean'] ?? null)),
            isAvailable: ($snapshot['isAvailable'] ?? false) === true,
            hideImageUntil: self::dateOf($snapshot['hideImageUntil'] ?? null),
            hideUntil: self::dateOf($snapshot['hideUntil'] ?? null),
            nameLanguage: self::stringOf($snapshot['nameLanguage'] ?? null),
        );
    }

    /**
     * Each field the merge changed goes back - unless it differs from what the merge left (somebody edited it since).
     *
     * @param array<mixed> $before
     * @param array<mixed> $after
     * @return list<string> The fields kept as they are now
     */
    private function restoreSurvivor(Puzzle $survivor, array $before, array $after): array
    {
        $kept = [];
        $current = [
            'name' => $survivor->name,
            'nameLanguage' => $survivor->nameLanguage,
            'alternativeNames' => $survivor->alternativeNames,
        ];

        $namesOf = static fn (array $snapshot): array => [
            self::stringOf($snapshot['name'] ?? null),
            self::stringOf($snapshot['nameLanguage'] ?? null),
            self::namesOf($snapshot)->toArray(),
        ];

        if ($namesOf($before) !== $namesOf($after)) {
            if ($namesOf($current) === $namesOf($after)) {
                $survivor->changeNames(
                    self::stringOf($before['name'] ?? null) ?? $survivor->name,
                    self::stringOf($before['nameLanguage'] ?? null),
                    self::namesOf($before),
                    $this->clock->now(),
                );
            } else {
                $kept[] = 'names';
            }
        }

        $piecesBefore = $before['piecesCount'] ?? null;

        if (is_int($piecesBefore) && $piecesBefore !== ($after['piecesCount'] ?? null)) {
            if ($survivor->piecesCount === ($after['piecesCount'] ?? null)) {
                $survivor->piecesCount = $piecesBefore;
            } else {
                $kept[] = 'piecesCount';
            }
        }

        $eansBefore = EanList::fromStored(self::stringOf($before['ean'] ?? null));
        $eansAfter = EanList::fromStored(self::stringOf($after['ean'] ?? null));
        $codesBefore = BrandCodeList::fromStored(self::stringOf($before['identificationNumber'] ?? null));
        $codesAfter = BrandCodeList::fromStored(self::stringOf($after['identificationNumber'] ?? null));
        $eans = $survivor->eans();
        $codes = $survivor->brandCodes();

        if ($eansBefore->equals($eansAfter) === false) {
            if ($eans->equals($eansAfter)) {
                $eans = $eansBefore;
            } else {
                $kept[] = 'ean';
            }
        }

        if ($codesBefore->equals($codesAfter) === false) {
            if ($codes->equals($codesAfter)) {
                $codes = $codesBefore;
            } else {
                $kept[] = 'identificationNumber';
            }
        }

        $survivor->updateProductIdentifiers($eans, $codes);

        $manufacturerBefore = self::stringOf($before['manufacturerId'] ?? null);
        $manufacturerAfter = self::stringOf($after['manufacturerId'] ?? null);

        if ($manufacturerBefore !== $manufacturerAfter) {
            $manufacturer = $manufacturerBefore !== null ? $this->entityManager->find(Manufacturer::class, $manufacturerBefore) : null;

            if ($survivor->manufacturer?->id->toString() === $manufacturerAfter && ($manufacturerBefore === null || $manufacturer !== null)) {
                $survivor->manufacturer = $manufacturer;
            } else {
                $kept[] = 'manufacturer';
            }
        }

        $imageBefore = self::stringOf($before['image'] ?? null);
        $imageAfter = self::stringOf($after['image'] ?? null);

        if ($imageBefore !== $imageAfter) {
            if ($survivor->image === $imageAfter && $imageBefore !== null) {
                $ratio = $before['imageRatio'] ?? null;
                // The file is still in the storage - a merge deletes no picture. What it carried when uploaded is not
                // in the audit
                $survivor->useImage($imageBefore, is_int($ratio) || is_float($ratio) ? (float) $ratio : null, null);
            } else {
                $kept[] = 'image';
            }
        }

        // The merge approved the survivor because the merged puzzle was approved - the restored puzzle carries that
        if (($before['approved'] ?? null) === false && ($after['approved'] ?? null) === true && $survivor->approved) {
            $survivor->approved = false;
            $survivor->approvedAt = null;
            $survivor->approvedBy = null;
        }

        return $kept;
    }

    /**
     * @param array<mixed> $migrated
     * @return array{0: array<string, int>, 1: list<string>} What moved back per kind, and the players whose results moved
     */
    private function moveRecordsBack(array $migrated, Puzzle $survivor, Puzzle $restored): array
    {
        $onSurvivor = static fn (null|Puzzle $puzzle): bool => $puzzle !== null && $puzzle->id->equals($survivor->id);
        $movedBack = [];
        $playerIds = [];

        $movedBack['solvingTimes'] = $this->each(PuzzleSolvingTime::class, self::movedIds($migrated, 'solvingTimes'), static function (PuzzleSolvingTime $time) use ($onSurvivor, $restored, &$playerIds): bool {
            if ($onSurvivor($time->puzzle) === false) {
                return false;
            }

            $time->returnAfterMergeRevert($restored);

            foreach ($time->memberPlayerIds() as $playerId) {
                $playerIds[$playerId] = true;
            }

            return true;
        });

        $movedBack['collectionItems'] = $this->each(CollectionItem::class, self::movedIds($migrated, 'collectionItems'), static function (CollectionItem $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzle = $restored;

            return true;
        });

        $movedBack['wishListItems'] = $this->each(WishListItem::class, self::movedIds($migrated, 'wishListItems'), static function (WishListItem $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzle = $restored;

            return true;
        });

        $movedBack['sellSwapListItems'] = $this->each(SellSwapListItem::class, self::movedIds($migrated, 'sellSwapListItems'), static function (SellSwapListItem $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzle = $restored;

            return true;
        });

        $movedBack['lentPuzzles'] = $this->each(LentPuzzle::class, self::movedIds($migrated, 'lentPuzzles'), static function (LentPuzzle $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzle = $restored;

            return true;
        });

        $movedBack['lentPuzzleTransfers'] = $this->each(LentPuzzleTransfer::class, self::movedIds($migrated, 'lentPuzzleTransfers'), static function (LentPuzzleTransfer $transfer) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($transfer->puzzle) === false) {
                return false;
            }

            $transfer->puzzleMergeReverted($restored);

            return true;
        });

        $movedBack['soldSwappedItems'] = $this->each(SoldSwappedItem::class, self::movedIds($migrated, 'soldSwappedItems'), static function (SoldSwappedItem $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzle = $restored;

            return true;
        });

        $movedBack['competitionRoundPuzzles'] = $this->each(CompetitionRoundPuzzle::class, self::movedIds($migrated, 'competitionRoundPuzzles'), static function (CompetitionRoundPuzzle $roundPuzzle) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($roundPuzzle->puzzle) === false) {
                return false;
            }

            $roundPuzzle->moveToPuzzle($restored);

            return true;
        });

        $movedBack['conversations'] = $this->each(Conversation::class, self::movedIds($migrated, 'conversations'), static function (Conversation $conversation) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($conversation->puzzle) === false) {
                return false;
            }

            $conversation->puzzle = $restored;

            return true;
        });

        $movedBack['stopwatches'] = $this->each(Stopwatch::class, self::movedIds($migrated, 'stopwatches'), static function (Stopwatch $stopwatch) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($stopwatch->puzzle) === false) {
                return false;
            }

            $stopwatch->puzzle = $restored;

            return true;
        });

        $movedBack['changeRequests'] = $this->each(PuzzleChangeRequest::class, self::movedIds($migrated, 'changeRequests'), static function (PuzzleChangeRequest $changeRequest) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($changeRequest->puzzle) === false) {
                return false;
            }

            $changeRequest->puzzleMergeReverted($restored);

            return true;
        });

        $movedBack['borrowRequestItems'] = $this->each(BorrowRequestItem::class, self::movedIds($migrated, 'borrowRequestItems'), static function (BorrowRequestItem $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzleMergedInto($restored);

            return true;
        });

        $movedBack['listingRequestItems'] = $this->each(ListingRequestItem::class, self::movedIds($migrated, 'listingRequestItems'), static function (ListingRequestItem $item) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($item->puzzle) === false) {
                return false;
            }

            $item->puzzleMergedInto($restored);

            return true;
        });

        $movedBack['listingRequestOffers'] = $this->each(ListingRequestOffer::class, self::movedIds($migrated, 'listingRequestOffers'), static function (ListingRequestOffer $offer) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($offer->puzzle) === false) {
                return false;
            }

            $offer->puzzleMergedInto($restored);

            return true;
        });

        $movedBack['importPuzzles'] = $this->each(DataImportPuzzle::class, self::movedIds($migrated, 'importPuzzles'), static function (DataImportPuzzle $importPuzzle) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($importPuzzle->puzzle) === false) {
                return false;
            }

            $importPuzzle->puzzleMergedInto($restored);

            return true;
        });

        $movedBack['importPuzzleDecisions'] = $this->each(ImportPuzzleDecision::class, self::movedIds($migrated, 'importPuzzleDecisions'), static function (ImportPuzzleDecision $decision) use ($onSurvivor, $restored): bool {
            if ($onSurvivor($decision->puzzle) === false) {
                return false;
            }

            $decision->puzzleMergedInto($restored);

            return true;
        });

        // The inventory lists the tags the merge added to the survivor - a tag both puzzles had stays on the survivor
        $movedBack['tags'] = $this->each(Tag::class, self::movedIds($migrated, 'tags'), static function (Tag $tag) use ($survivor, $restored): bool {
            $tag->puzzles->removeElement($survivor);

            if ($tag->puzzles->contains($restored) === false) {
                $tag->puzzles->add($restored);
            }

            return true;
        });

        return [array_filter($movedBack), array_keys($playerIds)];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<string> $ids
     * @param callable(T): bool $moveBack
     */
    private function each(string $class, array $ids, callable $moveBack): int
    {
        $moved = 0;

        foreach ($ids as $id) {
            $entity = Uuid::isValid($id) ? $this->entityManager->find($class, $id) : null;

            if ($entity !== null && $moveBack($entity)) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * The ids the merge moved of one kind - a plain list, or {moved, droppedAsDuplicate} (dropped ones are gone).
     * Kinds the merge did not move yet when it ran are missing from older audits.
     *
     * @param array<mixed> $migrated
     * @return list<string>
     */
    private static function movedIds(array $migrated, string $kind): array
    {
        $records = $migrated[$kind] ?? [];

        if (is_array($records) && array_key_exists('moved', $records)) {
            $records = $records['moved'];
        }

        return is_array($records) ? array_values(array_filter($records, is_string(...))) : [];
    }

    /**
     * Snapshots hold `alternativeNames` + `nameLanguage` since the names editor, older ones an `alternativeName` string.
     *
     * @param array<mixed> $snapshot
     */
    private static function namesOf(array $snapshot): PuzzleNames
    {
        if (is_array($snapshot['alternativeNames'] ?? null)) {
            return PuzzleNames::fromArray($snapshot['alternativeNames']);
        }

        $legacy = self::stringOf($snapshot['alternativeName'] ?? null);

        return $legacy !== null ? new PuzzleNames([new PuzzleName($legacy, null)]) : new PuzzleNames();
    }

    /**
     * @return array<mixed>
     */
    private static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function stringOf(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function dateOf(mixed $value): null|DateTimeImmutable
    {
        $value = self::stringOf($value);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $date !== false ? $date : null;
    }
}
