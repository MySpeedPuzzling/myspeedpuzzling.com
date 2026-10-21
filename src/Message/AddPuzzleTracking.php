<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
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
        // What the browser read from the finish photo before it shrank it (BrowserImageSummary) - stored when the photo
        // that arrived has no EXIF of its own (docs/features/upload-image-metadata.md)
        public null|UploadedImageMetadata $finishedPuzzlesPhotoBrowserMetadata = null,
    ) {
    }
}
