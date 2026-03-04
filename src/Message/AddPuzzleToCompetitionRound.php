<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleToCompetitionRound
{
    public function __construct(
        public UuidInterface $roundPuzzleId,
        public string $roundId,
        public string $userId,
        public string $brand,
        public string $puzzle,
        public null|int $piecesCount,
        public null|UploadedFile $puzzlePhoto,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
        public bool $hideUntilRoundStarts,
    ) {
    }
}
