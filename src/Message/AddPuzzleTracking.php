<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
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
    ) {
    }
}
