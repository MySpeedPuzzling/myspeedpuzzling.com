<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddCompetitionSeries
{
    /**
     * @param array<string> $maintainerIds
     */
    public function __construct(
        public UuidInterface $seriesId,
        public string $playerId,
        public string $name,
        public null|string $description,
        public null|string $link,
        public bool $isOnline,
        public null|string $location,
        public null|string $locationCountryCode,
        public null|UploadedFile $logo,
        public array $maintainerIds,
    ) {
    }
}
