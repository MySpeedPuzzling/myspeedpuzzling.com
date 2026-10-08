<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditCompetition
{
    /**
     * @param array<string> $maintainerIds
     */
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $shortcut,
        public null|string $description,
        public null|string $link,
        public null|string $registrationLink,
        public null|string $resultsLink,
        public null|string $location,
        public null|string $locationCountryCode,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public bool $isOnline,
        public null|UploadedFile $logo,
        public array $maintainerIds,
        // "Who can enter" (docs/features/organizations/README.md) - an edition without one shows its series'
        public null|string $eligibility,
        // An explicitly chosen slug (validated unique) - null keeps the slug, also on a rename: published links and
        // search engines know the competition by it
        public null|string $slug = null,
    ) {
    }
}
