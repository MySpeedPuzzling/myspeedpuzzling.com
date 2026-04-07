<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditCompetitionSeries
{
    /**
     * @param array<string> $maintainerIds
     */
    public function __construct(
        public string $seriesId,
        public string $name,
        public null|string $shortcut,
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
