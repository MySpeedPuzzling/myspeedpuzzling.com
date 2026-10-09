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
        public null|string $shortcut,
        public null|string $description,
        public null|string $link,
        public bool $isOnline,
        public null|string $location,
        public null|string $locationCountryCode,
        public null|UploadedFile $logo,
        public array $maintainerIds,
        // An explicitly chosen slug (validated, unique among series) - null generates it from the name
        public null|string $slug = null,
        // docs/features/organizations/README.md - as AddCompetition::$organizationId
        public null|string $organizationId = null,
        // "Who can enter" (shown by editions without their own) and "When it happens"
        public null|string $eligibility = null,
        public null|string $schedule = null,
        public bool $isDraft = false,
        // The e-mail asking an admin to review the new series - pointless when an admin creates it
        public bool $notifyAdmin = true,
    ) {
    }
}
