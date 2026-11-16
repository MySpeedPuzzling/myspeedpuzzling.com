<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Unlisted;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\UnlistedOwnership;
use SpeedPuzzling\Web\Entity\UnlistedPuzzle;
use SpeedPuzzling\Web\Entity\UnlistedResult;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultWriter;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAdder;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedOwnershipKind;

/**
 * A row of an "Add" whose puzzle the player keeps as an **unlisted puzzle**
 * (docs/features/data-import/unlisted-puzzles.md "Adding"): what the row says goes into the unlisted tables - the
 * player's own unlisted puzzle (one per puzzle key), a result of it, an owned / wish mark - and nowhere else. No row
 * of `puzzle`, `puzzle_solving_time`, `collection_item` or `wish_list_item` is written here, no event is recorded,
 * nothing is queued: no XP, no statistics, no achievements, no notification, no feed entry, no time verification.
 *
 * Called by ImportRowAdder inside the row's transaction, under the player's import lock: it persists and never
 * flushes, and everything that can refuse the row is checked before anything is created. takeBack() is the other
 * way, for UndoImportRowHandler.
 *
 * Never twice: a row makes one result (unique `import_row_id`); the same data in another import (the same source
 * key) is "already yours" - whether it is an unlisted result or became a real one since; a result the unlisted puzzle
 * already has with the same day, time and solo/pair/team is "already yours" too; one owned and one wish mark per
 * unlisted puzzle.
 */
readonly final class UnlistedRowWriter
{
    /** CollectionItem::$comment - what the mark's comment has to fit into once the puzzle is paired */
    private const int OWNED_COMMENT_MAX_LENGTH = 500;

    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
        private DataImportRowRepository $rowRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param bool $resultIsAnotherRows the row is the same result as another row of the file, which was added: its
     *        result part is that row's
     *
     * @return array{refused: null|string, made: null|array<string, mixed>, alreadyHow: null|string, alreadyTimeId: null|string}
     *         `made`: `{puzzleId, puzzleCreated, resultId, ownershipId}` - ids only; null: nothing was made
     */
    public function write(DataImportRow $row, DataImportPuzzle $importPuzzle, Player $player, bool $resultIsAnotherRows, DateTimeImmutable $now): array
    {
        $parsed = $row->parsed ?? [];
        $hasResult = in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true);
        $isOwned = in_array($row->kind, [DataImportRowKind::Owned, DataImportRowKind::Both], true);
        $isWish = $row->kind === DataImportRowKind::WishList;
        $comment = self::text($parsed['comment'] ?? null);
        $said = $hasResult ? self::resultSaid($row) : null;

        if ($hasResult && $said === null) {
            return self::answer(refused: ImportRowRefusal::NotReady->value);
        }

        // The limits of what the row becomes when its puzzle is paired one day - said now, not then
        if ($comment !== null && mb_strlen($comment) > ($hasResult ? ImportedResultWriter::COMMENT_MAX_LENGTH : self::OWNED_COMMENT_MAX_LENGTH)) {
            return self::answer(refused: ImportRowRefusal::CommentTooLong->value);
        }

        $unlistedPuzzle = $this->unlistedRepository->ofPlayerByKey($player, $importPuzzle->puzzleKey);
        $alreadyHow = $resultIsAnotherRows ? 'equal_row' : null;
        $alreadyTimeId = null;
        $newResult = false;

        // --- The result ---
        if ($said !== null && $resultIsAnotherRows === false) {
            $known = $this->knownResult($row, $player, $unlistedPuzzle, $said);

            if ($known === null) {
                $newResult = true;
            } else {
                $alreadyHow = $known['how'];
                $alreadyTimeId = $known['timeId'];
            }
        }

        // --- The marks ---
        $newMark = null;

        if ($isOwned) {
            if ($unlistedPuzzle !== null && $this->unlistedRepository->ownershipOf($unlistedPuzzle, UnlistedOwnershipKind::Owned) !== null) {
                $alreadyHow ??= 'owned';
            } else {
                $newMark = UnlistedOwnershipKind::Owned;
            }
        }

        if ($isWish) {
            // On the list already - or owned or solved: nothing to wish for (as the engine's rule for a catalogue puzzle)
            if (
                $unlistedPuzzle !== null
                && (
                    $this->unlistedRepository->ownershipOf($unlistedPuzzle, UnlistedOwnershipKind::WishList) !== null
                    || $this->unlistedRepository->ownershipOf($unlistedPuzzle, UnlistedOwnershipKind::Owned) !== null
                    || $this->unlistedRepository->resultsOf($unlistedPuzzle) !== []
                )
            ) {
                $alreadyHow = 'owned';
            } else {
                $newMark = UnlistedOwnershipKind::WishList;
            }
        }

        if ($newResult === false && $newMark === null) {
            return self::answer(how: $alreadyHow ?? 'owned', timeId: $alreadyTimeId);
        }

        // --- Nothing can refuse the row any more ---
        $puzzleCreated = $unlistedPuzzle === null;
        $pictureUrl = self::text($parsed['pictureUrl'] ?? null);

        if ($unlistedPuzzle === null) {
            $unlistedPuzzle = new UnlistedPuzzle(
                Uuid::uuid7(),
                $player,
                $importPuzzle->puzzleKey,
                $importPuzzle->name,
                $now,
                $row->import->id,
            );
            $this->unlistedRepository->save($unlistedPuzzle);
        }

        // A row of the same key that says more - a barcode, a brand - updates the puzzle, never doubles it
        $unlistedPuzzle->learnedFromRow($importPuzzle->brandText, $importPuzzle->piecesCount, $importPuzzle->eans, $importPuzzle->brandCodes, $pictureUrl);

        $made = [
            'puzzleId' => $unlistedPuzzle->id->toString(),
            'puzzleCreated' => $puzzleCreated,
            'resultId' => null,
            'ownershipId' => null,
        ];

        if ($newResult) {
            $result = new UnlistedResult(
                id: Uuid::uuid7(),
                unlistedPuzzle: $unlistedPuzzle,
                player: $player,
                secondsToSolve: $said['seconds'],
                solvedOn: $said['solvedOn'],
                datePrecision: $said['precision'],
                puzzlingType: $said['type'],
                people: ImportRowAdder::people($parsed['people'] ?? null),
                firstAttempt: is_bool($parsed['firstTry'] ?? null) ? $parsed['firstTry'] : null,
                comment: $comment,
                importRowId: $row->id,
                sourceKey: $said['sourceKey'],
                rowNumber: max(0, $row->rowNumber),
                createdAt: $now,
                saidAlreadyOnProfile: ($parsed['alreadyOnMsp'] ?? null) === true,
                countedOnItsOwn: $row->addAsSeparate,
            );
            $this->unlistedRepository->saveResult($result);
            $made['resultId'] = $result->id->toString();
        }

        if ($newMark !== null) {
            $ownership = new UnlistedOwnership(
                id: Uuid::uuid7(),
                unlistedPuzzle: $unlistedPuzzle,
                player: $player,
                kind: $newMark,
                // A row that is also a result gave its comment to the result
                comment: $hasResult ? null : $comment,
                collectionLabel: self::text($parsed['collection'] ?? null),
                importRowId: $row->id,
                createdAt: $now,
            );
            $this->unlistedRepository->saveOwnership($ownership);
            $made['ownershipId'] = $ownership->id->toString();
        }

        return self::answer(made: $made, how: $newResult ? null : $alreadyHow, timeId: $newResult ? null : $alreadyTimeId);
    }

    /**
     * Undo of a row that made unlisted things: its unlisted result and its mark are removed, and the unlisted puzzle
     * with them when nothing hangs on it any more and it is not paired. **Not when its puzzle was paired since and
     * the result or the mark became a real one** - then everything of the row stays; the pairing's own Undo comes
     * first.
     *
     * @return bool false: kept - something of the row is a real result / item now
     */
    public function takeBack(DataImportRow $row, Player $player): bool
    {
        $made = $row->made['unlisted'] ?? null;

        if (is_array($made) === false) {
            return true;
        }

        $result = is_string($made['resultId'] ?? null) ? $this->unlistedRepository->findResult($made['resultId']) : null;
        $ownership = is_string($made['ownershipId'] ?? null) ? $this->unlistedRepository->findOwnership($made['ownershipId']) : null;

        // Somebody else's ids in a row's record would be ours to answer for - never followed
        if ($result !== null && $result->player->id->equals($player->id) === false) {
            $result = null;
        }

        if ($ownership !== null && $ownership->player->id->equals($player->id) === false) {
            $ownership = null;
        }

        if (($result !== null && $result->isUnlisted() === false) || ($ownership !== null && $ownership->isUnlisted() === false)) {
            return false;
        }

        $unlistedPuzzle = $result !== null ? $result->unlistedPuzzle : $ownership?->unlistedPuzzle;

        if ($result !== null) {
            $this->unlistedRepository->deleteResult($result);
        }

        if ($ownership !== null) {
            $this->unlistedRepository->deleteOwnership($ownership);
        }

        if ($unlistedPuzzle !== null && $unlistedPuzzle->pairedPuzzleId === null && $this->unlistedRepository->holdsNothing($unlistedPuzzle)) {
            $this->unlistedRepository->delete($unlistedPuzzle);
        }

        return true;
    }

    /**
     * What the row says of its result - **as it says it**: a day known to the month or the year only stays that.
     *
     * @return null|array{solvedOn: null|string, precision: null|string, seconds: null|int, type: PuzzlingType, sourceKey: string}
     */
    private static function resultSaid(DataImportRow $row): null|array
    {
        $parsed = $row->parsed ?? [];
        $keys = is_array($parsed['keys'] ?? null) ? $parsed['keys'] : [];
        $sourceKey = is_string($keys['result'] ?? null) ? $keys['result'] : $row->sourceKey;
        $type = is_string($parsed['type'] ?? null) ? PuzzlingType::tryFrom($parsed['type']) : null;

        if ($sourceKey === null || $sourceKey === '' || $type === null) {
            return null;
        }

        $solvedOn = is_string($parsed['date'] ?? null) && preg_match('/^\d{4}(-\d{2}){0,2}$/', $parsed['date']) === 1 ? $parsed['date'] : null;
        $precision = $solvedOn !== null && is_string($parsed['datePrecision'] ?? null) && in_array($parsed['datePrecision'], ['day', 'month', 'year'], true)
            ? $parsed['datePrecision']
            : null;

        return [
            'solvedOn' => $precision === null ? null : $solvedOn,
            'precision' => $precision,
            'seconds' => is_int($parsed['seconds'] ?? null) && $parsed['seconds'] > 0 ? $parsed['seconds'] : null,
            'type' => $type,
            'sourceKey' => $sourceKey,
        ];
    }

    /**
     * @param array{solvedOn: null|string, precision: null|string, seconds: null|int, type: PuzzlingType, sourceKey: string} $said
     *
     * @return null|array{how: string, timeId: null|string}
     */
    private function knownResult(DataImportRow $row, Player $player, null|UnlistedPuzzle $unlistedPuzzle, array $said): null|array
    {
        // The row's own unlisted result is still there
        if ($this->unlistedRepository->resultOfRow($row->id) !== null) {
            return ['how' => 'this_row', 'timeId' => null];
        }

        // The same data in another import of the player made a real result that exists - asked of the results only
        // whether the id is there
        foreach ($this->rowRepository->solvingTimeIdsOfSourceKey($player->id->toString(), $said['sourceKey'], $row->id->toString()) as $knownTimeId) {
            if ($this->entityManager->find(PuzzleSolvingTime::class, $knownTimeId) !== null) {
                return ['how' => 'earlier_import', 'timeId' => $knownTimeId];
            }
        }

        // … or an unlisted result
        if ($this->unlistedRepository->resultOfSourceKey($player, $said['sourceKey']) !== null) {
            return ['how' => 'earlier_import', 'timeId' => null];
        }

        // The unlisted puzzle already has a result that says the same - unless the player said "these are two"
        if (
            $unlistedPuzzle !== null
            && $row->addAsSeparate === false
            && $this->unlistedRepository->equalResultOf($unlistedPuzzle, $said['solvedOn'], $said['seconds'], $said['type']) !== null
        ) {
            return ['how' => 'earlier_import', 'timeId' => null];
        }

        return null;
    }

    /**
     * @param null|array<string, mixed> $made
     *
     * @return array{refused: null|string, made: null|array<string, mixed>, alreadyHow: null|string, alreadyTimeId: null|string}
     */
    private static function answer(null|string $refused = null, null|array $made = null, null|string $how = null, null|string $timeId = null): array
    {
        return ['refused' => $refused, 'made' => $made, 'alreadyHow' => $how, 'alreadyTimeId' => $timeId];
    }

    private static function text(mixed $value): null|string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
