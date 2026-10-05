<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Locked per puzzle: the form sent again corrects the puzzle it added the first time (Puzzle::correctNewlyAdded()),
 * which must not overlap with its approval - nor with a second copy of the same submit creating it twice.
 */
readonly final class AddPuzzle implements SerializedByLock
{
    public function __construct(
        public UuidInterface $puzzleId,
        public string $userId,
        public string $puzzleName,
        public string $brand,
        public int $piecesCount,
        // Required: a new puzzle is never created without a photo of its box (PuzzleBoxPhoto)
        public UploadedFile $puzzlePhoto,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
        // Names of other boxes of the puzzle (docs/features/puzzle-names/) - the add form's "+ name in another language"
        public PuzzleNames $alternativeNames = new PuzzleNames(),
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId->toString());
    }
}
