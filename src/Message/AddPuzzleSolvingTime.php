<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleSolvingTime
{
    public function __construct(
        public UuidInterface $timeId,
        public string $userId,
        public string $puzzleId,
        public null|string $competitionId,
        public string $time,
        public null|string $comment,
        public null|UploadedFile $finishedPuzzlesPhoto,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $roundId = null,
    ) {
    }
}
