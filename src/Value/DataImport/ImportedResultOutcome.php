<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\FirstTryAssessment;

/**
 * What ImportedResultWriter did with one row (docs/features/data-import/writer.md "The outcome"): the answer for the
 * row, what the batch's log needs for Undo, and what is still owed for the result - the work the add form does per
 * result and an import does once per batch (ImportedResultsFollowUp).
 */
readonly final class ImportedResultOutcome
{
    /**
     * @param list<string> $memberPlayerIds Added: the registered people of the result, the player first
     * @param null|array{id: string, playerId: string, puzzleId: string, addedAt: DateTimeImmutable} $removedWishListItem
     *     Added: the player's wish list item the result removed ("remove when solved", README D18) - Undo puts it back
     */
    private function __construct(
        public ImportedResultStatus $status,
        public string $importRowId,
        public null|string $solvingTimeId = null,
        public null|ImportedResultKnownBy $knownBy = null,
        public null|ImportedResultRefusal $refusal = null,
        // Refused as FirstTryTaken: who holds the first try, for the row's choices
        public null|FirstTryAssessment $firstTry = null,
        public null|string $puzzleId = null,
        public array $memberPlayerIds = [],
        // Added: a solo result with a time waits for its reconstructed prediction
        public bool $predictionPending = false,
        public null|array $removedWishListItem = null,
    ) {
    }

    /**
     * @param list<string> $memberPlayerIds
     * @param null|array{id: string, playerId: string, puzzleId: string, addedAt: DateTimeImmutable} $removedWishListItem
     */
    public static function added(
        string $importRowId,
        string $solvingTimeId,
        string $puzzleId,
        array $memberPlayerIds,
        bool $predictionPending,
        null|array $removedWishListItem,
    ): self {
        return new self(
            ImportedResultStatus::Added,
            $importRowId,
            solvingTimeId: $solvingTimeId,
            puzzleId: $puzzleId,
            memberPlayerIds: $memberPlayerIds,
            predictionPending: $predictionPending,
            removedWishListItem: $removedWishListItem,
        );
    }

    public static function alreadyAdded(string $importRowId, string $solvingTimeId, ImportedResultKnownBy $knownBy): self
    {
        return new self(ImportedResultStatus::AlreadyAdded, $importRowId, solvingTimeId: $solvingTimeId, knownBy: $knownBy);
    }

    public static function refused(string $importRowId, ImportedResultRefusal $refusal, null|FirstTryAssessment $firstTry = null): self
    {
        return new self(ImportedResultStatus::Refused, $importRowId, refusal: $refusal, firstTry: $firstTry);
    }

    public function isAdded(): bool
    {
        return $this->status === ImportedResultStatus::Added;
    }
}
