<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddCompetition
{
    /**
     * @param array<string> $maintainerIds
     */
    public function __construct(
        public UuidInterface $competitionId,
        public string $playerId,
        public string $name,
        public null|string $shortcut,
        public null|string $description,
        public null|string $link,
        public null|string $registrationLink,
        public null|string $resultsLink,
        public string $location,
        public null|string $locationCountryCode,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public bool $isOnline,
        public bool $isRecurring,
        public null|UploadedFile $logo,
        public array $maintainerIds,
    ) {
    }
}
