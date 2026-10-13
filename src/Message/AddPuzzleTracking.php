<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleTracking
{
    public function __construct(
        public UuidInterface $trackingId,
        public string $userId,
        public string $puzzleId,
        public null|string $comment,
        public null|UploadedFile $finishedPuzzlesPhoto,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        // Names the pair/team of $groupPlayers when it has no name yet
        public null|string $teamName = null,
        public null|SolvingTimeSource $createdVia = null,
        // Sent only by the add page (docs/features/navigation README §4.6, ADD-01) - see AddPuzzleSolvingTime
        public null|PuzzleAddMode $rememberAddMode = null,
    ) {
    }
}
