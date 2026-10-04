<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Approves a newly added puzzle with the moderator's corrections - locked per puzzle like every change of its record.
 */
readonly final class ApprovePuzzle implements SerializedByLock
{
    public function __construct(
        public string $puzzleId,
        public string $reviewerId,
        public string $name,
        // The main title's language when the box has no English title - null = English, or not known
        public null|string $nameLanguage,
        // Every other name as approved, in order
        public PuzzleNames $alternativeNames,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public PuzzleApprovalBrandChoice $brandChoice = PuzzleApprovalBrandChoice::Keep,
        // Target brand for UseExisting and MergeInto
        public null|string $targetManufacturerId = null,
        // A photo of the box the moderator uploads instead of the player's
        public null|UploadedFile $uploadedImage = null,
        // Why - kept in the puzzle's history
        public null|string $note = null,
        // The record the form was loaded with (PuzzleRecordVersion) - null checks nothing
        public null|string $recordVersion = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
